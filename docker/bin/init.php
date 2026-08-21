#!/usr/bin/env php
<?php

declare(strict_types=1);

function envOr(string $name, string $default = ''): string
{
    $value = getenv($name);
    return $value === false ? $default : $value;
}

function readSecretFile(string $envName): string
{
    $path = envOr($envName);
    if ($path === '' || !is_readable($path)) {
        throw new RuntimeException("Secret file from {$envName} is not readable");
    }

    $value = rtrim((string) file_get_contents($path), "\r\n");
    if ($value === '') {
        throw new RuntimeException("Secret file from {$envName} is empty");
    }

    return $value;
}

function hashPassword(#[\SensitiveParameter] string $password): string
{
    $hash = password_hash($password, PASSWORD_ARGON2ID, [
        'memory_cost' => 64 * 1024,
        'time_cost' => 3,
        'threads' => 1,
    ]);
    if ($hash === false) {
        throw new RuntimeException('Unable to hash a password');
    }

    return $hash;
}

$host = envOr('NAMINGO_DB_HOST', 'database');
$port = (int) envOr('NAMINGO_DB_PORT', '3306');
$database = envOr('NAMINGO_DB_NAME', 'registry');
$username = envOr('NAMINGO_DB_USER', 'namingo');
$password = readSecretFile('NAMINGO_DB_PASSWORD_FILE');
$adminEmail = envOr('NAMINGO_ADMIN_EMAIL');
$adminUsername = envOr('NAMINGO_ADMIN_USERNAME', 'admin');
$adminPassword = readSecretFile('NAMINGO_PANEL_PASSWORD_FILE');
$domain = strtolower(rtrim(envOr('NAMINGO_DOMAIN'), '.'));

if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
    throw new RuntimeException('NAMINGO_ADMIN_EMAIL is invalid');
}
if (!preg_match('/^[A-Za-z0-9_.-]{3,100}$/', $adminUsername)) {
    throw new RuntimeException('NAMINGO_ADMIN_USERNAME is invalid');
}

$dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";
$pdo = null;
$deadline = time() + 180;

do {
    try {
        $pdo = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->query('SELECT 1 FROM users LIMIT 1');
        break;
    } catch (Throwable $exception) {
        if (time() >= $deadline) {
            throw new RuntimeException(
                'Database schema did not become ready: ' . $exception->getMessage(),
                0,
                $exception
            );
        }
        fwrite(STDOUT, "Waiting for the Namingo database schema...\n");
        sleep(2);
    }
} while (true);

if (!$pdo instanceof PDO) {
    throw new RuntimeException('Unable to initialize the database connection');
}

$pdo->beginTransaction();
try {
    $statement = $pdo->prepare(
        'SELECT id, username, roles_mask FROM users WHERE email = :email LIMIT 1'
    );
    $statement->execute(['email' => $adminEmail]);
    $existingAdmin = $statement->fetch();

    if ($existingAdmin === false) {
        $statement = $pdo->prepare(
            "INSERT INTO users
                (email, password, username, status, verified, resettable,
                 roles_mask, registered, last_login, force_logout, tfa_secret,
                 tfa_enabled, auth_method, backup_codes)
             VALUES
                (:email, :password, :username, 0, 1, 1,
                 0, 1, NULL, 0, NULL, 0, 'password', NULL)"
        );
        $statement->execute([
            'email' => $adminEmail,
            'password' => hashPassword($adminPassword),
            'username' => $adminUsername,
        ]);
        fwrite(STDOUT, "Created the Namingo control-panel administrator.\n");
    } else {
        if ((int) $existingAdmin['roles_mask'] !== 0) {
            throw new RuntimeException(
                'The requested administrator email belongs to a non-administrator user'
            );
        }
        fwrite(STDOUT, "The Namingo control-panel administrator already exists.\n");
    }

    // The upstream seed data contains public demonstration credentials. Keep
    // the sample records for the first-steps workflow, but neutralize only the
    // untouched, exactly matching credentials before exposing the services.
    $disabledHash = hashPassword(bin2hex(random_bytes(32)));
    $statement = $pdo->prepare(
        'UPDATE users SET password = :disabled, status = 1, verified = 0, '
        . 'force_logout = force_logout + 1 '
        . 'WHERE (email = :email1 AND username = :username1 AND password = :password1) '
        . 'OR (email = :email2 AND username = :username2 AND password = :password2)'
    );
    $statement->execute([
        'disabled' => $disabledHash,
        'email1' => 'info@leonet.com',
        'username1' => 'leonet',
        'password1' => '$argon2id$v=19$m=2048,t=4,p=4$STNMRDZRblBBVmRMeFhpdg$DpPnVyIHXJag11Pdi4J7xFAdtnmWfiNCgAjkIOpVtYk',
        'email2' => 'info@nordregistrar.com',
        'username2' => 'nordregistrar',
        'password2' => '$argon2id$v=19$m=2048,t=4,p=4$STNMRDZRblBBVmRMeFhpdg$DpPnVyIHXJag11Pdi4J7xFAdtnmWfiNCgAjkIOpVtYk',
    ]);
    $disabledUsers = $statement->rowCount();

    $statement = $pdo->prepare(
        'UPDATE registrar SET pw = :disabled '
        . 'WHERE (clid = :clid1 AND email = :email1 AND pw = :password1) '
        . 'OR (clid = :clid2 AND email = :email2 AND pw = :password2)'
    );
    $statement->execute([
        'disabled' => $disabledHash,
        'clid1' => 'leonet',
        'email1' => 'info@leonet.ua',
        'password1' => '$argon2id$v=19$m=131072,t=6,p=4$M0ViOHhzTWFtQW5YSGZ2MA$g2pKb+PEYtfs4QwLmf2iUtPM4+7evuqYQFp6yqGZmQg',
        'clid2' => 'nordregistrar',
        'email2' => 'info@nordregistrar.com',
        'password2' => '$argon2id$v=19$m=131072,t=6,p=4$MU9Eei5UMjA0M2cxYjd3bg$2yBHTWVVY4xQlMGhnhol9MRbVyVQg8qkcZ6cpdeID1U',
    ]);
    $disabledRegistrars = $statement->rowCount();
    if ($disabledUsers > 0 || $disabledRegistrars > 0) {
        fwrite(
            STDOUT,
            "Neutralized untouched upstream demonstration credentials.\n"
        );
    }

    $settings = [
        'whois_server' => "whois.{$domain}",
        'rdap_server' => "https://rdap.{$domain}",
        'email' => $adminEmail,
    ];
    $statement = $pdo->prepare(
        'INSERT INTO settings (name, value) VALUES (:name, :value) '
        . 'ON DUPLICATE KEY UPDATE value = VALUES(value)'
    );
    foreach ($settings as $name => $value) {
        $statement->execute(['name' => $name, 'value' => $value]);
    }

    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $exception;
}

fwrite(STDOUT, "Namingo database initialization is complete.\n");
