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
            $sftpSettings['privateKey'],
            $sftpSettings['passphrase'],
            $sftpSettings['port'],
            $sftpSettings['useAgent'],
            $sftpSettings['timeout'],
            $sftpSettings['maxTries'],
            $sftpSettings['fingerprint']
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

// Current date and hour in the specified format
$currentDateHour = date('Ymd-H'); // Format: YYYYMMDD-HH

// Directory to check
$directory = $config['directory'];

// Load patterns from config
$patterns = array_map(function ($pattern) use ($currentDateHour) {
    return str_replace('{dateHour}', $currentDateHour, $pattern);
}, $config['patterns']);

// Scan directory for matching files
$files = scandir($directory);
$filesFound = false; // Flag to track if any files are found

foreach ($files as $file) {
    foreach ($patterns as $pattern) {
        if (preg_match("/$pattern/", $file)) {
            $filesFound = true;
            $localPath = $directory . $file;
            $remoteFileName = basename($file);
            uploadFile($filesystem, $localPath, $remoteFileName, $log, $config['upload']['retries'], $config['upload']['delay']);
        }
    }
}

// Log if no files were found
if (!$filesFound) {
    $log->info("No matching files found in directory: $directory for patterns: " . implode(', ', $patterns));
}