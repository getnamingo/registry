<?php

echo "[INFO] Starting cache cleanup...\n";

$cacheDir = realpath(__DIR__ . '/../cache');

if (!$cacheDir || !is_dir($cacheDir)) {
    echo "[ERROR] Cache directory not found. Aborting.\n";
    exit(1);
}

echo "[INFO] Using cache directory: {$cacheDir}\n";

$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($cacheDir, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
);

foreach ($files as $fileinfo) {
    // Check if the parent directory name is exactly two letters/numbers long
    if (preg_match('/^[a-zA-Z0-9]{2}$/', $fileinfo->getFilename()) || preg_match('/^[a-zA-Z0-9]{2}$/', basename(dirname($fileinfo->getPathname())))) {
        $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
        $todo($fileinfo->getRealPath());
    }
}

// After deleting files and subdirectories, delete the 2 letter/number directories themselves
$dirs = new DirectoryIterator($cacheDir);
foreach ($dirs as $dir) {
    if ($dir->isDir() && !$dir->isDot() && preg_match('/^[a-zA-Z0-9]{2}$/', $dir->getFilename())) {
        rmdir($dir->getRealPath());
    }
}

// Clear Slim route cache
$routeCacheFile = $cacheDir . '/routes.php';
if (file_exists($routeCacheFile)) {
    if (@unlink($routeCacheFile)) {
        echo "[INFO] Slim route cache file removed: routes.php\n";
    } else {
        echo "[WARN] Could not remove Slim route cache file: routes.php\n";
    }
}

$randomFiles = glob($cacheDir . '/*');
foreach ($randomFiles as $file) {
    if (is_file($file)) {
        unlink($file);
    }
}

echo "[INFO] Cache cleanup complete.\n";

// Try to restart PHP-FPM 8.3
echo "[INFO] Restarting PHP-FPM service (php8.3-fpm)...\n";
exec("sudo systemctl restart php8.3-fpm 2>&1", $restartOutput, $status);

if ($status === 0) {
    echo "[OK]   PHP-FPM restarted successfully.\n";
} else {
    echo "[WARN] Could not restart PHP-FPM automatically.\n";
    echo "[WARN] Please run manually: sudo systemctl restart php8.3-fpm\n";
    if (!empty($restartOutput)) {
        echo "[DEBUG] systemctl output:\n" . implode("\n", $restartOutput) . "\n";
    }
}

exit(0);