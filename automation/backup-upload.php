<?php

require __DIR__ . '/vendor/autoload.php';

require_once 'helpers.php';

use League\Flysystem\Filesystem;
use League\Flysystem\PhpseclibV3\SftpConnectionProvider;
use League\Flysystem\PhpseclibV3\SftpAdapter;
use League\Flysystem\UnixVisibility\PortableVisibilityConverter;
use Spatie\FlysystemDropbox\DropboxAdapter;
use Hypweb\Flysystem\GoogleDrive\GoogleDriveAdapter;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use League\Flysystem\AdapterInterface;

$logFilePath = '/var/log/namingo/backup_upload.log';
$log = setupLogger($logFilePath, 'Backup_Upload');
$log->info('job started.');

// Storage type: 'sftp', 'dropbox', or 'google_drive'
$storageType = 'sftp'; // Set this to your preferred storage

// Setup the filesystem based on the storage type
switch ($storageType) {
    case 'sftp':
        $sftpProvider = new SftpConnectionProvider(
            'your_sftp_host', // host
            'your_username',  // username
            'your_password',  // password
            '/path/to/my/private_key', // private key
            'passphrase', // passphrase
            22, // port
            true, // use agent
            30, // timeout
            10, // max tries
            'fingerprint-string' // host fingerprint
            // connectivity checker (optional)
        );

        $visibilityConverter = PortableVisibilityConverter::fromArray([
            'file' => [
                'public' => 0640,
                'private' => 0604,
            ],
            'dir' => [
                'public' => 0740,
                'private' => 7604,
            ],
        ]);

        $adapter = new SftpAdapter($sftpProvider, '/upload', $visibilityConverter);
        break;
    case 'dropbox':
        $client = new \Spatie\Dropbox\Client('your_dropbox_access_token');
        $adapter = new DropboxAdapter($client);
        break;
    case 'google_drive':
        $client = new \Google\Client();
        $client->setClientId('your_client_id');
        $client->setClientSecret('your_client_secret');
        $client->refreshToken('your_refresh_token');
        $service = new \Google\Service\Drive($client);
        $adapter = new GoogleDriveAdapter($service, 'your_folder_id');
        break;
    default:
        $log->error("Invalid storage type");
        exit;
}

$filesystem = new Filesystem($adapter);

// Function to upload a file with try-catch for error handling
function uploadFile($filesystem, $localPath, $remotePath, $logger) {
    try {
        if (file_exists($localPath)) {
            $stream = fopen($localPath, 'r+');
            $filesystem->writeStream($remotePath, $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }
            $logger->info("Uploaded: $localPath to $remotePath");
        } else {
            $logger->warning("File not found: $localPath");
        }
    } catch (Exception $e) {
        $logger->error("Error uploading $localPath: " . $e->getMessage());
    }
}

// Current date and hour in the specified format
$currentDateHour = date('Ymd-H'); // Format: YYYYMMDD-HH

// Directory to check
$directory = '/srv/';

// Pattern to match files
$pattern = "/^database-$currentDateHour.*\.sql\.bz2$/";
$pattern2 = "/^files-$currentDateHour.*\.sql\.bz2$/";

// Scan directory for matching files
$files = scandir($directory);
foreach ($files as $file) {
    if (preg_match($pattern, $file) || preg_match($pattern2, $file)) {
        $localPath = $directory . $file;
        $remoteFileName = basename($file);
        uploadFile($filesystem, $localPath, $remoteFileName, $log);
    }
}
