# e2ee-cloud

Your open source, self-hosted, end-to-end encryption cloud software.

[Visit the website](https://e2ee-cloud.com/) 

### What is e2ee-cloud

------

e2ee-cloud is a open source, self-hosted, end-to-end encryption software.
You can upload and download files in your browser. All files and folders will be encrypted before upload and decrypted after download.

### Why e2ee-cloud?

------

* end-to-end encryption
* self-hosted
* search files over encrypted data
* multiple storage providers (like dropbox, aws etc.)
* Easy setup
* it's free and it's your privacy!

#### Requirements

------

* Server with min. PHP 7.1.3
* Mondern Browser -> e.g. Chrome, Firefox
* PHP sqlite extension (Optional, only if file search is enabled)

### Storages

------

e2ee-cloud supports multiple storages.

* Local filesystem
* SFTP
* FTP
* Dropbox
* AWS S3
* Google Cloud Storage
* WebDAV

### Installation 

------

Extract the files. Run `composer install` in the project root. Link the document root to the public dir.

Edit the yaml file `config/services.yaml`. Under `e2ee_cloud` you can setup your e2ee cloud configuration.

Setup basic auth account:

Edit the yaml file `config/packages/security.yaml`. Under provider `e2ee_cloud` you will find the admin user, here you can change the password. The default login is `admin:e2ee`.

To generate a bcrypt password run `php bin/console security:encode-password` in your project root.

### Using docker

------

Just run `docker-compose up` in the application folder.
Then you can access http://localhost/.

Enter the default access data:
User: admin
Password: e2ee

### How does it work?

------

Currently only basic auth are supported. After you logged in on the web app, you must enter your secret master password, the master password is not stored anywhere, only in your current browser session. You can drag & drop files for upload or click the upload button. Before the upload of your files, the browser will encrypt the file with a AES-GCM 256 bit encryption, then the files will be uploaded. The file content and the filename are saved encrypted on the server. If you download the file, the file will also be decrypted only in your browser after download.

### Information about the backend (server side)

------

The backend of e2ee-cloud use symfony flex and the flysystem php library. Note that the search index db can have a bigger size cause of the encryption.
The code is available on GitHub. 

### Information about the frontend (browser app)

------

The frontend is based on vue.js. Code coming soon on GitHub.

### Bug reporting

------

Please use GitHub to create an issue. All other bug reporting e.g. email will be ignored.

### Support me

------

[Become a backer or sponsor on Patreon.](https://www.patreon.com/doweb)

Or you can support me by sending me a BTC donation:
bc1qklp89zmynuj3zmvwm9t3a2ekmyg4aa2stm9ul9
