<?php

$savePath = "/path/to/save/files/";  // Adjust this path as needed.

$files = [
    'smdrl' => 'https://ry.marksdb.org/smdrl/smdrl-latest.csv',
    'surl' => 'https://test.ry.marksdb.org/dnl/surl-latest.csv',
    'dnl' => 'https://test.ry.marksdb.org/dnl/dnl-latest.csv',
    'tmch' => 'http://crl.icann.org/tmch.crl'
];

// Configure the username and password for each URL.
$credentials = [
    'smdrl' => ['user' => 'username1', 'pass' => 'password1'],
    'surl' => ['user' => 'username2', 'pass' => 'password2'],
    'dnl' => ['user' => 'username3', 'pass' => 'password3']
    // 'tmch' is not listed here since it doesn't require authentication.
];

foreach ($files as $key => $url) {
    $ch = curl_init($url);

    // Check if credentials exist for this URL and set them.
    if (isset($credentials[$key])) {
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, $credentials[$key]['user'] . ":" . $credentials[$key]['pass']);
    }

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $data = curl_exec($ch);

    if ($data === false) {
        echo "Failed to download $url. Error: " . curl_error($ch) . "\n";
    } else {
        $filePath = $savePath . basename($url);
        if (file_put_contents($filePath, $data)) {
            echo "Successfully downloaded $url to $filePath\n";
        } else {
            echo "Failed to save the downloaded file to $filePath\n";
        }
    }

    curl_close($ch);
}