<?php

$cacheDir = '/var/www/cp/cache';

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

// Clear Slim route cache if it exists
$routeCacheFile = $cacheDir . '/routes.php';
if (file_exists($routeCacheFile)) {
    unlink($routeCacheFile);
}