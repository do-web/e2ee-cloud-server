<?php

namespace App\Controller;

use App\Helper\Filesystem;
use League\Flysystem\FileExistsException;
use League\Flysystem\FileNotFoundException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Class FilesystemController
 * @package App\Controller
 */
class FilesystemController extends AbstractController
{
    const FOLDER_FILE = '.e2ee';

    /**
     * @var Filesystem
     */
    public $fileSystemHelper;

    /**
     * @var ParameterBagInterface
     */
    public $params;

    /**
     * FilesystemController constructor.
     * @param Filesystem $fileSystemHelper
     * @param ParameterBagInterface $params
     */
    public function __construct(Filesystem $fileSystemHelper, ParameterBagInterface $params)
    {
        $this->params = $params;
        $this->fileSystemHelper = $fileSystemHelper;
    }

    /**
     * @Route(name="fs_list_storage", path="/filesystems", methods="GET")
     * @return JsonResponse
     */
    public function getStorages()
    {
        $storages = $this->fileSystemHelper->getStorages();
        $items = [];

        foreach ($storages as $key => $item) {
            $items[] = [
                'id' => $key,
                'type' => $item['type'],
                'name' => $item['name']
            ];
        }

        return $this->json($items);
    }

    /**
     * @Route(name="fs_search_index", path="/filesystems/{id}/search_index", methods="POST")
     * @param $id
     * @return JsonResponse
     */
    public function searchIndex($id)
    {
        if (($db = $this->getDatabase(false)) === null) {
            return $this->json(['success' => false]);
        }

        /**
         * file_path is plain text -> storage
         * file_name is the name on the filesystem and a hash of the content if it is a file
         * file_hash is a sha256 hash of the filename
         */
        $db->exec('CREATE TABLE IF NOT EXISTS search_index (file_path TEXT NOT NULL, file_name TEXT NOT NULL, file_hash varchar(64) NOT NULL, file_word_hash varchar(64), PRIMARY KEY (file_path, file_hash, file_word_hash))');

        /** @var Request $request */
        $request = $this->container->get('request_stack')->getCurrentRequest();
        $filePath = $this->getRequestPath($id);
        $filename = $request->get('filename');
        $fileHash = $request->get('hash');
        $fileWords = $request->get('words');

        foreach ($fileWords as $word) {
            $db->exec('INSERT OR IGNORE INTO search_index (file_path, file_name, file_hash, file_word_hash) VALUES ("' . \SQLite3::escapeString($filePath) . '","' . \SQLite3::escapeString($filename) . '","' . \SQLite3::escapeString($fileHash) . '","' . \SQLite3::escapeString($word) . '")');
        }

        return $this->json(['success' => true]);
    }

    /**
     * @Route(name="fs_search", path="/filesystems/search", methods="GET")
     * @return JsonResponse
     * @throws FileNotFoundException
     */
    public function search()
    {
        $results = null;
        $items = [];
        $manager = $this->fileSystemHelper->getMountManager();

        /** @var Request $request */
        $request = $this->container->get('request_stack')->getCurrentRequest();
        $terms = $request->get('term');

        if (($db = $this->getDatabase()) === null) {
            return $this->json(['success' => false]);
        }

        if (is_array($terms)) {
            foreach ($terms as &$term) {
                $term = \SQLite3::escapeString($term);
            }

            $results = $db->query('select file_path, file_name from search_index where file_word_hash in ("' . implode('","', $terms) . '")');
        }

        if ($results) {
            while ($item = $results->fetchArray(SQLITE3_ASSOC)) {

                $folderFile = $this->getFolderFile($item['file_path']);
                $additional = [
                    'filesystem' => explode(':', $item['file_path'])[0]
                ];

                $additional['basename'] = $item['file_name'];
                $additional['dirname'] = trim(substr($item['file_path'], stripos($item['file_path'], '://') + 3), '/');

                if (isset($folderFile[$item['file_name']])) {
                    $additional['encryptedName'] = $folderFile[$item['file_name']];
                }

                $items[$additional['basename']] = array_merge($additional, $manager->getMetadata($item['file_path'] . $item['file_name']));
            }
        }

        return $this->json(array_values($items));
    }

    /**
     * @param null $id
     * @return string
     */
    protected function getRequestPath($id = null)
    {
        /** @var Request $request */
        $request = $this->container->get('request_stack')->getCurrentRequest();
        $path = $request->get('path', null);
        return ($id ? $id . '://' : '') . ($path ? rtrim($path, '/') . '/' : '');
    }

    /**
     * @Route(name="fs_list", path="/filesystems/{id}/list", methods="GET")
     * @param $id
     * @return JsonResponse
     * @throws FileNotFoundException
     */
    public function list($id)
    {
        $manager = $this->fileSystemHelper->getMountManager();

        $path = $this->getRequestPath($id);
        $items = $manager->listContents($path);
        $folderFile = $this->getFolderFile($path);

        $excludedFiles = $this->params->get('e2ee_cloud')['excluded_files'];

        // exclude the e2ee storage file
        $excludedFiles[] = '.e2ee';

        $items = array_filter($items, function ($val) use ($excludedFiles, $folderFile) {
            foreach ($excludedFiles as $regex) {
                if (preg_match('/' . preg_quote($regex, '/') . '/', $val['basename'])) {
                    return false;
                }
            }
            return true;
        });

        foreach ($items as &$item) {
            if (isset($folderFile[$item['basename']])) {
                $item['encryptedName'] = $folderFile[$item['basename']];
            }
        }

        return $this->json(array_values($items));
    }

    /**
     * @param $pathFrom
     * @param $hashFrom
     * @return bool|JsonResponse
     */
    public function removeIndex($pathFrom, $hashFrom)
    {
        if (($db = $this->getDatabase()) === null) {
            return $this->json(['success' => false]);
        }

        $query = 'DELETE FROM search_index WHERE file_path = "' . \SQLite3::escapeString($pathFrom) . '" AND file_name = "' . \SQLite3::escapeString($hashFrom) . '"';

        return $db->exec($query);
    }

    /**
     * @param $pathFrom
     * @param $pathTo
     * @param $filename
     * @return JsonResponse
     */
    public function updateIndexPath($pathFrom, $pathTo, $filename)
    {
        if (($db = $this->getDatabase()) === null) {
            return $this->json(['success' => false]);
        }

        $query = 'UPDATE search_index SET file_path = REPLACE(file_path, "' . \SQLite3::escapeString($pathFrom) . '", "' . \SQLite3::escapeString($pathTo) . '") WHERE file_name = "' . $filename . '" AND file_path LIKE "' . \SQLite3::escapeString($pathFrom) . '%"';

        return $db->exec($query);
    }

    /**
     * @Route(name="fs_move", path="/filesystems/{id}/move", methods="GET")
     * @param $id
     * @return JsonResponse
     * @throws FileNotFoundException
     */
    public function move($id)
    {
        /** @var Request $request */
        $request = $this->container->get('request_stack')->getCurrentRequest();
        $manager = $this->fileSystemHelper->getMountManager();

        $from = $id . '://' . $request->get('from', '');
        $to = $id . '://' . $request->get('to', '');

        $dirnameFrom = substr($from, 0, strripos($from, '/') + 1);
        $dirnameTo = substr($to, 0, strripos($to, '/') + 1);
        $folderFileFrom = $this->getFolderFile($dirnameFrom);
        $folderFileTo = $this->getFolderFile($dirnameTo);
        $basenameFrom = basename($from);
        $basenameTo = basename($to);

        $this->updateIndexPath($dirnameFrom, $dirnameTo, basename($from));

        if (isset($folderFileFrom[$basenameFrom])) {

            $encFilename = $folderFileFrom[$basenameFrom];

            // remove item from old .e2ee file
            unset($folderFileFrom[$basenameFrom]);
            $this->writeFolderFile($dirnameFrom, $folderFileFrom);

            // add item to the new .e2ee file
            $folderFileTo[$basenameTo] = $encFilename;
            $this->writeFolderFile($dirnameTo, $folderFileTo);

            if ($from !== $dirnameTo . $basenameFrom) {
                $move = $manager->move($from, $to);
            } else {
                $move = true;
            }
        } else {
            $move = $manager->move($from, $to);
        }

        return $this->json(['success' => $move]);
    }

    /**
     * @Route(name="fs_rename", path="/filesystems/{id}/rename", methods="GET")
     * @param $id
     * @return JsonResponse
     * @throws FileNotFoundException
     */
    public function rename($id)
    {
        /** @var Request $request */
        $request = $this->container->get('request_stack')->getCurrentRequest();
        $manager = $this->fileSystemHelper->getMountManager();

        $from = $id . '://' . $request->get('from', '');
        $to = $id . '://' . $request->get('to', '');

        $dirnameFrom = substr($from, 0, strripos($from, '/') + 1);
        $dirnameTo = substr($to, 0, strripos($to, '/') + 1);
        $folderFileFrom = $this->getFolderFile($dirnameFrom);

        if (isset($folderFileFrom[basename($from)])) {

            unset($folderFileFrom[basename($from)]);
            $this->writeFolderFile($dirnameFrom, $folderFileFrom);
            $folderFileFrom[basename($from)] = basename($to);
            $this->writeFolderFile($dirnameTo, $folderFileFrom);

            if ($from !== $dirnameTo . basename($from)) {
                $move = $manager->move($from, $dirnameTo . basename($from));
            } else {
                $move = true;
            }
        } else {
            $move = $manager->move($from, $to);
        }

        return $this->json(['success' => $move]);
    }

    /**
     * @Route(name="fs_delete_file", path="/filesystems/{id}/delete/file", methods="GET")
     * @param $id
     * @return JsonResponse
     * @throws FileNotFoundException
     */
    public function deleteFile($id)
    {
        $manager = $this->fileSystemHelper->getMountManager();
        /** @var Request $request */
        $request = $this->container->get('request_stack')->getCurrentRequest();

        $storagePath = $this->getRequestPath($id);
        $hash = $request->get('hash');
        $file = $request->get('file');
        $fullPath = $storagePath . $file;
        $dirname = substr($fullPath, 0, strripos($fullPath, '/') + 1);

        $folderFile = $this->getFolderFile($storagePath);
        unset($folderFile[basename($file)]);
        $this->writeFolderFile($dirname, $folderFile);

        $this->removeFileFromIndex($storagePath, $hash);

        return $this->json(['success' => $manager->delete($fullPath)]);
    }

    /**
     * @param $path
     * @param $hash
     * @return bool|JsonResponse
     */
    public function removeFileFromIndex($path, $hash)
    {
        if (($db = $this->getDatabase()) === null) {
            return $this->json(['success' => false]);
        }

        return $db->exec('DELETE FROM search_index WHERE file_path = "' . \SQLite3::escapeString($path) . '" AND file_hash = "' . \SQLite3::escapeString($hash) . '"');
    }

    /**
     * @param $path
     * @param $filename
     * @return bool|JsonResponse
     */
    public function removeFolderFromIndex($path, $filename)
    {
        if (($db = $this->getDatabase()) === null) {
            return $this->json(['success' => false]);
        }

        return $db->exec('DELETE FROM search_index WHERE file_path = "' . \SQLite3::escapeString($path) . '" AND file_name = "' . \SQLite3::escapeString($filename) . '"');
    }

    /**
     * @Route(name="fs_pathinfo", path="/filesystems/{id}/pathinfo", methods="GET")
     * @param $id
     * @return JsonResponse
     * @throws FileNotFoundException
     */
    public function pathInfo($id)
    {
        $storages = $this->fileSystemHelper->getStorages();

        /** @var Request $request */
        $request = $this->container->get('request_stack')->getCurrentRequest();

        $path = $request->get('path', null);

        $breadcrumb = [];

        if ($path) {
            $pathParts = array_merge([''], explode('/', $path));
            $paths = [];

            foreach ($pathParts as $k => $part) {
                $paths[] = $part;
                if (isset($pathParts[$k + 1])) {
                    $path = trim(implode('/', $paths), '/');
                    $breadcrumb[$pathParts[$k + 1]] = [
                        'path' => $path,
                        'basename' => $pathParts[$k + 1],
                        'encryptedName' => $this->getFilename($id . '://' . $path, $pathParts[$k + 1]),
                    ];
                }
            }
        }

        return $this->json([
            'filesystem' => [
                'id' => $id,
                'type' => $storages[$id]['type'],
                'name' => $storages[$id]['name']
            ],
            'folders' => array_values($breadcrumb),
        ]);
    }

    /**
     * @param $path
     * @param $key
     * @return mixed
     * @throws FileNotFoundException
     */
    protected function getFilename($path, $key)
    {
        return $this->getFolderFile($path)[$key] ?? null;
    }

    /**
     * @param $removeFolderPathFromIndex
     * @return JsonResponse
     * @throws FileNotFoundException
     */
    protected function removeSearchIndexRecursive($removeFolderPathFromIndex)
    {
        if (($db = $this->getDatabase()) === null) {
            return $this->json(['success' => false]);
        }

        $manager = $this->fileSystemHelper->getMountManager();
        $items = $manager->listContents($removeFolderPathFromIndex, true);

        $filesInFolder = $this->getFolderFile($removeFolderPathFromIndex);

        // remove files in the folder root
        foreach($filesInFolder as $key => $enc) {
            $db->exec('DELETE FROM search_index WHERE file_name = "' . \SQLite3::escapeString($key) . '" AND file_path = "' . \SQLite3::escapeString($removeFolderPathFromIndex) . '"');
        }

        // recursive delete all files and folders from $removeFolderPathFromIndex
        foreach ($items as $item) {
            $path = $item['filesystem'] . '://' . $item['dirname'] . '/';
            $filesInFolder = $this->getFolderFile($path);

            foreach($filesInFolder as $key => $enc) {
                $db->exec('DELETE FROM search_index WHERE file_name = "' . \SQLite3::escapeString($key) . '" AND file_path = "' . \SQLite3::escapeString($path) . '"');
            }
        }
    }

    /**
     * @param bool $checkDbFileExists
     * @return \SQLite3|null
     */
    protected function getDatabase($checkDbFileExists = true) {
        if ($this->params->get('e2ee_cloud')['search']['active'] === false || ($checkDbFileExists && is_file($this->params->get('e2ee_cloud')['search']['sqlite_file']) === false)) {
            return null;
        }
        return new \SQLite3($this->params->get('e2ee_cloud')['search']['sqlite_file']);
    }

    /**
     * @Route(name="fs_delete_folder", path="/filesystems/{id}/delete/folder", methods="GET")
     * @param $id
     * @return JsonResponse
     * @throws FileNotFoundException
     */
    public function deleteFolder($id)
    {
        $manager = $this->fileSystemHelper->getMountManager();
        /** @var Request $request */
        $request = $this->container->get('request_stack')->getCurrentRequest();

        $path = $this->getRequestPath($id);
        $file = $request->get('file', '');
        $filePath = $path . $file;

        $this->removeSearchIndexRecursive($filePath . '/');

        if ($manager->deleteDir($filePath)) {

            $this->removeFolderFromIndex($path, $file);

            $dirname = substr($filePath, 0, strripos($filePath, '/') + 1);
            $folderFile = $this->getFolderFile($dirname);
            unset($folderFile[basename($filePath)]);
            $this->writeFolderFile($dirname, $folderFile);

            return $this->json(['success' => true]);
        }

        return $this->json(['success' => false]);
    }

    /**
     * @Route(name="fs_download", path="/filesystems/{id}/download", methods="GET")
     * @param $id
     * @return StreamedResponse
     * @throws FileNotFoundException
     */
    public function download($id)
    {
        $manager = $this->fileSystemHelper->getMountManager();
        /** @var Request $request */
        $request = $this->container->get('request_stack')->getCurrentRequest();

        $file = $id . '://' . $request->get('file', '');

        $name = basename($file);

        $stream = $manager->readStream($file);

        $response = new StreamedResponse();
        $response->setCallback(function () use ($stream) {
            while (ob_get_level() > 0) ob_end_flush(); // prevent memory leak
            fpassthru($stream);
            flush();
        });

        $response->headers->set('Content-Type', 'application/octet-stream');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $name . '"');
        $response->prepare($request);

        return $response;
    }

    /**
     * @param $path
     * @return array|mixed
     * @throws FileNotFoundException
     */
    protected function getFolderFile($path)
    {
        $manager = $this->fileSystemHelper->getMountManager();
        $folderFile = (substr($path, -2) === '//' ? $path : rtrim($path, '/') . '/') . self::FOLDER_FILE;

        if ($manager->has($folderFile)) {
            return json_decode($manager->read($folderFile), true);
        }
        return [];
    }

    /**
     * @param $path
     * @param $content
     * @return bool
     */
    protected function writeFolderFile($path, $content)
    {
        $manager = $this->fileSystemHelper->getMountManager();
        $folderFile = (substr($path, -2) === '//' ? $path : rtrim($path, '/') . '/') . self::FOLDER_FILE;
        return $manager->put($folderFile, json_encode($content));
    }

    /**
     * @Route(name="fs_create_folder", path="/filesystems/{id}/create_folder", methods="POST")
     * @param $id
     * @return JsonResponse
     * @throws FileNotFoundException
     */
    public function createFolder($id)
    {
        $manager = $this->fileSystemHelper->getMountManager();
        /** @var Request $request */
        $request = $this->container->get('request_stack')->getCurrentRequest();

        $path = $request->get('path', null);
        $path = $id . '://' . ($path ? $path . '/' : '');
        $encryptedFolder = $request->get('name');
        $folderName = uniqid();
        $folder = $path . $folderName;

        $result = $manager->createDir($folder);

        if ($result) {
            $folderFiles = $this->getFolderFile($path);
            $folderFiles[$folderName] = $encryptedFolder;
            $this->writeFolderFile($path, $folderFiles);
        }

        return $this->json(['success' => $manager->createDir($folder), 'folderName' => $folderName]);
    }

    /**
     * @Route(name="fs_create_file", path="/filesystems/{id}/create_file", methods="POST")
     * @param $id
     * @return JsonResponse
     * @throws FileExistsException
     */
    public function createFile($id)
    {
        $manager = $this->fileSystemHelper->getMountManager();
        /** @var Request $request */
        $request = $this->container->get('request_stack')->getCurrentRequest();
        $path = $request->get('path', null);
        $file = $id . '://' . ($path ? $path . '/' : '') . $request->get('name', 'new_file.txt');

        $exists = $manager->has($file);
        $response = false;

        if ($exists === false) {
            $response = $manager->write($file, $request->get('content', ''));
        }

        return $this->json(['success' => $response]);
    }

    /**
     * @Route(name="fs_load_file", path="/filesystems/{id}/edit_file", methods="GET")
     * @param $id
     * @return JsonResponse
     */
    public function loadFile($id)
    {
        $manager = $this->fileSystemHelper->getMountManager();
        /** @var Request $request */
        $request = $this->container->get('request_stack')->getCurrentRequest();
        $path = $request->get('path', null);

        $file = $id . '://' . $path;

        try {
            return $this->json($manager->read($file));
        } catch (\Exception $e) {
            return $this->json(['success' => false, 'error' => 'opening_file'], 400);
        }
    }

    /**
     * @Route(name="fs_save_file", path="/filesystems/{id}/edit_file", methods="POST")
     * @param $id
     * @return JsonResponse
     */
    public function saveFile($id)
    {
        $manager = $this->fileSystemHelper->getMountManager();
        /** @var Request $request */
        $request = $this->container->get('request_stack')->getCurrentRequest();
        $path = $request->get('path', null);
        $file = $id . '://' . $path;

        return $this->json(['success' => $manager->put($file, $request->get('content', ''))]);
    }

    /**
     * @Route(name="fs_upload", path="/filesystems/{id}/upload", methods="POST")
     * @param $id
     * @return JsonResponse
     * @throws FileNotFoundException
     */
    public function upload($id)
    {
        /**
         * @var Request $request
         */
        $request = $this->container->get('request_stack')->getCurrentRequest();

        $files = $request->files->all();
        $resumableIdentifier = md5($request->get('resumableIdentifier'));
        $resumableChunkNumber = $request->request->get('resumableChunkNumber');

        $temp_dir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR);

        if (count($files)) {

            /** @var UploadedFile $file */
            foreach ($files as $file) {

                $file->move(
                    $temp_dir,
                    $resumableIdentifier . '.part' . str_pad($resumableChunkNumber, 10, "0", STR_PAD_LEFT)
                );

                $this->processChunks($id);

                return $this->json(['success' => true]);
            }
        } else {
            return $this->json(['success' => false, 'error' => 'no_files'], 400);
        }

        return $this->json(['success' => false, 'error' => 'unknown_error'], 400);
    }

    /**
     * @param $id
     * @return JsonResponse
     * @throws FileNotFoundException
     */
    private function processChunks($id)
    {
        $manager = $this->fileSystemHelper->getMountManager();
        /** @var Request $request */
        $request = $this->container->get('request_stack')->getCurrentRequest();
        $resumableIdentifier = md5($request->get('resumableIdentifier'));
        $resumableFilename = $request->request->get('resumableFilename');
        $resumableTotalChunks = $request->request->get('resumableTotalChunks');

        $temp_dir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR);

        $uploadedFile = $this->fileSystemHelper->createFileFromChunks($temp_dir, $resumableIdentifier, $resumableTotalChunks);

        if ($uploadedFile !== false) {

            set_time_limit(0);

            $stream = fopen($uploadedFile, 'r+');
            try {
                $path = $this->getRequestPath($id);

                $resumableFilename = explode('-', $resumableFilename);
                $filename = $resumableFilename[0];
                $filenameEncrypted = $resumableFilename[1];

                $manager->writeStream($path . $filename, $stream);

                if (is_resource($stream)) {
                    fclose($stream);
                }

                $folderFiles = $this->getFolderFile($path);
                $folderFiles[$filename] = $filenameEncrypted;
                $this->writeFolderFile($path, $folderFiles);

                $this->fileSystemHelper->deleteChunks($temp_dir, $resumableIdentifier);

                return $this->json(['success' => true]);

            } catch (FileExistsException $e) {
                $this->fileSystemHelper->deleteChunks($temp_dir, $resumableIdentifier);
                return $this->json(['success' => false, 'error' => 'file_exists'], 400);
            }
        }
        return $this->json(['success' => true]);
    }

    /**
     * @Route(name="fs_upload_chunk_check", path="/filesystems/{id}/check", methods="POST")
     * @param $id
     * @return JsonResponse
     * @throws FileNotFoundException
     */
    public function uploadChunkCheck($id)
    {
        /**
         * @var Request $request
         */
        $request = $this->container->get('request_stack')->getCurrentRequest();

        $resumableIdentifier = md5($request->get('resumableIdentifier'));
        $resumableChunkNumber = $request->get('resumableChunkNumber');

        $temp_dir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR);

        $dest_file = $temp_dir . DIRECTORY_SEPARATOR . $resumableIdentifier . '.part' . str_pad($resumableChunkNumber, 10, "0", STR_PAD_LEFT);

        if (!file_exists($dest_file) || (file_exists($dest_file) && filesize($dest_file) === 0)) {
            return $this->json(['success' => false], 400);
        }

        return $this->processChunks($id);
    }
}
