<?php

namespace App\Helper;

use Aws\S3\S3Client;
use Google\Cloud\Storage\StorageClient;
use League\Flysystem\Adapter\Local;
use League\Flysystem\AwsS3v3\AwsS3Adapter;
use League\Flysystem\MountManager;
use League\Flysystem\WebDAV\WebDAVAdapter;
use League\Flysystem\Filesystem as FlysystemFilesystem;
use League\Flysystem\Sftp\SftpAdapter;
use Spatie\Dropbox\Client;
use Spatie\FlysystemDropbox\DropboxAdapter;
use League\Flysystem\Adapter\Ftp as Adapter;
use League\Flysystem\Cached\Storage\Memory as MemoryStore;
use League\Flysystem\Cached\CachedAdapter;
use Superbalist\Flysystem\GoogleStorage\GoogleStorageAdapter;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * Class Filesystem
 * @package App\Helper
 */
class Filesystem
{
    const FS_TYPE_LOCAL = 'local';
    const FS_TYPE_FTP = 'ftp';
    const FS_TYPE_SFTP = 'sftp';
    const FS_TYPE_WEBDAV = 'webdav';
    const FS_TYPE_DROPBOX = 'dropbox';
    const FS_TYPE_AWS_S3 = 'aws_s3';
    const FS_TYPE_GOOGLE_STORAGE = 'google_storage';

    /**
     * @var ParameterBagInterface
     */
    protected $params;

    /**
     * Filesystem constructor.
     * @param ParameterBagInterface $params
     */
    function __construct(ParameterBagInterface $params)
    {
        $this->params = $params;
    }

    /**
     * @return array
     */
    function getStorages() {
        $items = [];
        $storages = $this->params->get('e2ee_cloud')['storages'];

        foreach($storages as $key => $storage) {
            if($storage['active'] === false) {
                continue;
            }

            $items[$key] = $storage;
        }

        return $items;
    }

    /**
     * @return MountManager
     */
    function getMountManager() {
        $filesystems = [];

        $cacheStore = new MemoryStore();
        $storages = $this->getStorages();

        foreach($storages as $key => $storage) {
            $adapter = null;

            if(!isset($storage['type']) || !isset($storage['active']) || $storage['active'] === false) {
                continue;
            }

            if($storage['type'] === self::FS_TYPE_FTP) {
                $adapter = $this->getFtpAdapter($storage);
            } else if($storage['type'] === self::FS_TYPE_SFTP) {
                $adapter = $this->getSFtpAdapter($storage);
            } else if($storage['type'] === self::FS_TYPE_WEBDAV) {
                $adapter = $this->getWebDAVAdapter($storage);
            } else if($storage['type'] === self::FS_TYPE_DROPBOX) {
                $adapter = $this->getDropboxAdapter($storage);
            } else if($storage['type'] === self::FS_TYPE_AWS_S3) {
                $adapter = $this->getAWSS3Adapter($storage);
            } else if($storage['type'] === self::FS_TYPE_GOOGLE_STORAGE) {
                $adapter = $this->getGoogleStorageAdapter($storage);
            } else if($storage['type'] === self::FS_TYPE_LOCAL) {
                $adapter = new CachedAdapter(new Local($storage['path']), $cacheStore);
            }

            if($adapter) {
                $adapterCached = new CachedAdapter($adapter, $cacheStore);
                $filesystem = new FlysystemFilesystem($adapterCached);
                $filesystems[$key] = $filesystem;
            }
        }

        return new MountManager($filesystems);
    }

    /**
     * @param $path
     * @return array|bool
     */
    function parsePath($path) {
        $path = explode('://', $path);
        return count($path) === 2 ? [$path[0], $path[1]] : false;
    }

    /**
     * @param $config
     * @return null|DropboxAdapter
     */
    function getDropboxAdapter($config) {
        $adapter = null;
        if(isset($config['token'])) {
            $client = new Client($config['token']);
            $adapter = new DropboxAdapter($client);
        }
        return $adapter;
    }

    /**
     * @param $config
     * @return AwsS3Adapter
     */
    function getAWSS3Adapter($config) {
        $client = new S3Client([
            'credentials' => [
                'key'    => $config['key'],
                'secret' => $config['secret']
            ],
            'region' => $config['region'],
            'version' => 'latest',
        ]);

        return new AwsS3Adapter($client, $config['bucket']);
    }

    /**
     * @param $config
     * @return GoogleStorageAdapter
     */
    function getGoogleStorageAdapter($config) {
        $storageClient = new StorageClient([
            'projectId' => $config['projectId'],
            'keyFilePath' => $config['keyFilePath'],
        ]);

        $bucket = $storageClient->bucket($config['bucket']);

        return new GoogleStorageAdapter($storageClient, $bucket);
    }

    /**
     * @param $config
     * @return Adapter
     */
    function getFtpAdapter($config) {
        return new Adapter([
            'host' => $config['host'],
            'port' => intval($config['port']),
            'username' => $config['username'],
            'password' => $config['password'],
            'root' => $config['root'] ?? '/',
            'passive' => $config['passive'] ?? false,
            'ssl' => $config['ssl'] ?? false,
            'timeout' => 15,
        ]);
    }

    /**
     * @param $config
     * @return SftpAdapter
     */
    function getSFtpAdapter($config) {
        return new SftpAdapter([
            'host' => $config['host'],
            'port' => $config['port'] ? intval($config['port']) : 22,
            'username' => $config['username'],
            'password' => $config['password'],
            'root' => $config['root'] ?? '/',
            'timeout' => 15,
        ]);
    }

    /**
     * @param $config
     * @return WebDAVAdapter
     */
    function getWebDAVAdapter($config) {
        $settings = array(
            'baseUri' => $config['baseUri'],
            'userName' => $config['userName'] ?? null,
            'password' => $config['password'] ?? null,
            'proxy' => $config['proxy'] ?? null,
        );
        $client = new \Sabre\DAV\Client($settings);
        $adapter = new WebDAVAdapter($client);

        return $adapter;
    }

    /**
     * @param $temp_dir
     * @param $resumableIdentifier
     */
    function deleteChunks($temp_dir, $resumableIdentifier) {
        $chunks = glob($temp_dir . DIRECTORY_SEPARATOR . $resumableIdentifier . '*');

        foreach ($chunks as $chunk) {
            unlink($chunk);
        }
    }

    /**
     * @param $temp_dir
     * @param $resumableIdentifier
     * @param $resumableTotalChunks
     * @return bool|string
     */
    function createFileFromChunks($temp_dir, $resumableIdentifier, $resumableTotalChunks)
    {
        $chunks = glob($temp_dir . DIRECTORY_SEPARATOR . $resumableIdentifier . '.part*');

        if (count($chunks) === intval($resumableTotalChunks)) {

            set_time_limit(0);

            $saveTo = $temp_dir . DIRECTORY_SEPARATOR . $resumableIdentifier;

            if (($fp = fopen($saveTo, 'w')) !== false) {

                foreach($chunks as $chunk) {
                    fwrite($fp, file_get_contents($chunk));
                }

                fclose($fp);
            } else {
                error_log('Upload error: Cannot create the destination file.');
                return false;
            }

            return $saveTo;
        } else if(count($chunks) > intval($resumableTotalChunks)) {
            $this->deleteChunks($temp_dir, $resumableIdentifier);
        }
        return false;
    }
}
