<?php

$c = require_once 'config.php';
require_once 'helpers.php';

// Connect to the database
$dsn = "{$c['db_type']}:host={$c['db_host']};dbname={$c['db_database']}";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $c['db_username'], $c['db_password'], $options);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// Fetching all hosts
$hostsStmt = $pdo->query("SELECT id, name FROM host");
while ($host = $hostsStmt->fetch()) {
    $hostId = $host['id'];
    $hostName = $host['name'];

    // Get IPv4 address
    $ipv4 = gethostbyname($hostName);
    if ($ipv4 !== $hostName) {  // Check if a valid IPv4 address is returned
        $sql = "INSERT INTO host_addr (host_id, addr, ip) VALUES (?, ?, 'v4')";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$hostId, $ipv4]);
    }

    // Get IPv6 address
    $dnsRecords = dns_get_record($hostName, DNS_AAAA);
    foreach ($dnsRecords as $record) {
        if (isset($record['ipv6'])) {
            $ipv6 = $record['ipv6'];
            $sql = "INSERT INTO host_addr (host_id, addr, ip) VALUES (?, ?, 'v6')";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$hostId, $ipv6]);
        }
    }
}