<?php

require __DIR__ . '/vendor/autoload.php';
require_once 'helpers.php';

use League\Flysystem\Filesystem;
use League\Flysystem\Ftp\FtpAdapter;
use League\Flysystem\Ftp\FtpConnectionOptions;
use League\Flysystem\PhpseclibV3\SftpConnectionProvider;
use League\Flysystem\PhpseclibV3\SftpAdapter;
use League\Flysystem\UnixVisibility\PortableVisibilityConverter;
use Spatie\FlysystemDropbox\DropboxAdapter;
use Hypweb\Flysystem\GoogleDrive\GoogleDriveAdapter;
use Monolog\Logger;

// Setup logger
$logFilePath = '/var/log/namingo/backup_upload.log';
$log = setupLogger($logFilePath, 'Backup_Upload');
$log->info('job started.');

// Load configuration from JSON
$configPath = __DIR__ . '/backup-upload.json';
if (!file_exists($configPath)) {
    $log = setupLogger($logFilePath, 'Backup_Upload');
    $log->error("Configuration file not found: $configPath");
    exit(1);
}

$config = json_decode(file_get_contents($configPath), true);
if ($config === null) {
    $log->error("Invalid JSON format in configuration file: $configPath");
    exit(1);
}

// Get storage type from config
$storageType = $config['storageType'];

// Setup the filesystem based on the storage type
switch ($storageType) {
    case 'sftp':
        $sftpSettings = $config['sftp'];
        $sftpProvider = new SftpConnectionProvider(
            $sftpSettings['host'],
            $sftpSettings['username'],
            $sftpSettings['password'],
            $sftpSettings['privateKey'], // Set to null in config if not using SSH key
            $sftpSettings['passphrase'], // Set to null in config if not using SSH key
            $sftpSettings['port'],
            $sftpSettings['useAgent'], // Set to false in config if not using SSH key
            $sftpSettings['timeout'],
            $sftpSettings['maxTries'],
            $sftpSettings['fingerprint'] // Set to null in config if not using SSH key
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

        $adapter = new SftpAdapter($sftpProvider, $sftpSettings['basePath'], $visibilityConverter);
        break;
    case 'ftp':
        $ftpSettings = $config['ftp'];

        $connectionOptions = FtpConnectionOptions::fromArray([
            'host' => $ftpSettings['host'],
            'username' => $ftpSettings['username'],
            'password' => $ftpSettings['password'],
            'port' => $ftpSettings['port'] ?? 21,
            'root' => $ftpSettings['basePath'] ?? '/',
            'passive' => $ftpSettings['passive'] ?? true,
            'ssl' => $ftpSettings['ssl'] ?? false,
            'timeout' => $ftpSettings['timeout'] ?? 30,
        ]);

        $adapter = new FtpAdapter($connectionOptions);
        break;
    case 'dropbox':
        $dropboxSettings = $config['dropbox'];
        $client = new \Spatie\Dropbox\Client($dropboxSettings['accessToken']);
        $adapter = new DropboxAdapter($client);
        break;
    case 'google_drive':
        $googleDriveSettings = $config['googleDrive'];
        $client = new \Google\Client();
        $client->setClientId($googleDriveSettings['clientId']);
        $client->setClientSecret($googleDriveSettings['clientSecret']);
        $client->refreshToken($googleDriveSettings['refreshToken']);
        $service = new \Google\Service\Drive($client);
        $adapter = new GoogleDriveAdapter($service, $googleDriveSettings['folderId']);
        break;
    default:
        $log->error("Invalid storage type");
        exit;
}

$filesystem = new Filesystem($adapter);

// Function to upload a file with retry mechanism
function uploadFile($filesystem, $localPath, $remotePath, $logger, $retries, $delay) {
    $attempt = 0;
    while ($attempt < $retries) {
        try {
            $attempt++;
            if (file_exists($localPath)) {
                $stream = fopen($localPath, 'r+');
                $filesystem->writeStream($remotePath, $stream);
                if (is_resource($stream)) {
                    fclose($stream);
                }
                $logger->info("Uploaded: $localPath to $remotePath on attempt $attempt");
                return true; // Upload succeeded, exit function
            } else {
                $logger->warning("File not found: $localPath");
                return false; // File not found, no need to retry
            }
        } catch (Exception $e) {
            $logger->error("Error uploading $localPath on attempt $attempt: " . $e->getMessage());
            if ($attempt < $retries) {
                $logger->info("Retrying in $delay seconds...");
                sleep($delay); // Wait before retrying
            } else {
                $logger->error("All $retries attempts failed for $localPath");
                return false; // All attempts failed
            }
        }
    }
}

// Directory to check
$directory = "/srv/";

// Define backup types (prefixes)
$backupTypes = ['database', 'registry', 'panel'];

// Scan directory and filter matching files
$backupFiles = [];

$files = scandir($directory);
foreach ($files as $file) {
    foreach ($backupTypes as $type) {
        if (preg_match("/^{$type}-\d{8}-\d{4}\.sql\.bz2$/", $file)) {
            $backupFiles[$type][$file] = filemtime($directory . $file);
        }
    }
}

// Upload the latest file for each type
foreach ($backupFiles as $type => $files) {
    if (!empty($files)) {
        // Get the latest file by modification time
        arsort($files);
        $latestFile = array_key_first($files);
        
        // Upload file
        $localPath = $directory . $latestFile;
        $remoteFileName = basename($latestFile);
        uploadFile($filesystem, $localPath, $remoteFileName, $log, $config['upload']['retries'], $config['upload']['delay']);
    }
}

// Log if no files were found
if (!$filesFound) {
    $log->info("No matching files found in directory: $directory for patterns: " . implode(', ', $patterns));
}