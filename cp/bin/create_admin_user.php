<?php
require __DIR__ . '/../vendor/autoload.php'; // Path to the Composer autoload file

// Load environment variables from .env file
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// Retrieve database connection details from environment variables
$dbDriver = $_ENV['DB_DRIVER'];
$dbHost = $_ENV['DB_HOST'];
$dbName = $_ENV['DB_DATABASE'];
$dbUser = $_ENV['DB_USERNAME'];
$dbPass = $_ENV['DB_PASSWORD'];

// Retrieve admin details from environment variables
$email = getenv('PANEL_EMAIL');
$newPW = getenv('PANEL_PASSWORD');
$username = getenv('PANEL_USERNAME') ?: 'admin';

if ($email === false || $email === '') {
    die("PANEL_EMAIL is required." . PHP_EOL);
}

if ($newPW === false || $newPW === '') {
    die("PANEL_PASSWORD is required." . PHP_EOL);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    die("Invalid admin email address." . PHP_EOL);
}

// Hash the password
$options = [
    'memory_cost' => 1024 * 128,
    'time_cost'   => 6,
    'threads'     => 4,
];
$hashedPassword = password_hash($newPW, PASSWORD_ARGON2ID, $options);

try {
    // Create PDO instance
    if ($dbDriver == 'mysql') {
        $dsn = "mysql:host=$dbHost;dbname=$dbName;charset=utf8";
        $pdo = new PDO($dsn, $dbUser, $dbPass);
    } elseif ($dbDriver == 'pgsql') {
        $dsn = "pgsql:host=$dbHost;dbname=$dbName";
        $pdo = new PDO($dsn, $dbUser, $dbPass);
    } elseif ($dbDriver == 'sqlite') {
        $dsn = "sqlite:/var/www/cp/registry.db";
        $pdo = new PDO($dsn);
    }

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // SQL query
    $sql = "INSERT INTO users (email, password, username, status, verified, resettable, roles_mask, registered, last_login, force_logout, tfa_secret, tfa_enabled, auth_method, backup_codes) 
            VALUES (:email, :password, :username, 0, 1, 1, 0, 1, NULL, 0, NULL, false, 'password', NULL)";

    // Prepare and execute SQL statement
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':email' => $email,
        ':password' => $hashedPassword,
        ':username' => $username
    ]);

    echo "Admin user created successfully." . PHP_EOL;
} catch (PDOException $e) {
    // Handle error
    die("Error: " . $e->getMessage());
}