<?php

function formatPhoneNumber($phoneNumber) {
    return str_replace('-', '', $phoneNumber);
}

function formatCountryCode($countryCode) {
    return strtoupper($countryCode);
}

function generateRandomString($length = 16) {
    $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $charactersLength = strlen($characters);
    $randomString = '';
    for ($i = 0; $i < $length; $i++) {
        $randomString .= $characters[rand(0, $charactersLength - 1)];
    }
    return $randomString;
}

function formatTimestamp($timestamp) {
    try {
        $dt = new DateTime($timestamp);
    } catch (Exception $e) {
        $dt = DateTime::createFromFormat('Y-m-d', $timestamp);
        $dt->setTime(0, 0, 0);
    }
    return $dt->format('Y-m-d H:i:s'); // MySQL datetime format
}

function getTldId($pdo, $domainName) {
    $tld = strrchr($domainName, '.');
    $stmt = $pdo->prepare("SELECT id FROM domain_tld WHERE tld = ?");
    $stmt->execute([$tld]);
    $row = $stmt->fetch();
    return $row ? $row['id'] : null;
}

function getDomainIdByName($pdo, $domainName) {
    $stmt = $pdo->prepare("SELECT id FROM domain WHERE name = ?");
    $stmt->execute([$domainName]);
    $row = $stmt->fetch();
    return $row ? $row['id'] : null;
}

function getContactIdByROID($pdo, $roid) {
    $stmt = $pdo->prepare("SELECT id FROM contact WHERE roid = ?");
    $stmt->execute([$roid]);
    $row = $stmt->fetch();
    return $row ? $row['id'] : null;
}

function getHostIdByName($pdo, $hostName) {
    $stmt = $pdo->prepare("SELECT id FROM host WHERE name = ?");
    $stmt->execute([$hostName]);
    $row = $stmt->fetch();
    return $row ? $row['id'] : null;
}

function isEligibleForRenewal($status) {
    $pendingStatuses = ['domain_status_pending_purge', 'domain_status_pending_delete'];
    return in_array($status, $pendingStatuses);
}

function parseBool($value) {
    $value = strtolower($value);
    if ($value === 'true') return true;
    if ($value === 'false') return false;
    return null;
}