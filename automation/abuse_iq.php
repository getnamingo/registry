<?php

require __DIR__ . '/vendor/autoload.php';

$c = require_once 'config.php';
require_once 'helpers.php';

// Connect to the database
$dsn = "{$c['db_type']}:host={$c['db_host']};dbname={$c['db_database']}";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];
$logFilePath = '/var/log/namingo/abuse_iq.log';
$log = setupLogger($logFilePath, 'Abuse_IQ');
$log->info('job started.');

if (empty($c['abuse_iq_api'])) {
    $log->error("Error: Missing configuration key \$c['abuse_iq_api'] — you need to add your iQ Abuse Manager API key in config.php");
    exit;
}

try {
    $pdo = new PDO($dsn, $c['db_username'], $c['db_password'], $options);
} catch (PDOException $e) {
    $log->error('DB Connection failed: ' . $e->getMessage());
}

// Call API
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => 'https://api.abusemanager.iq.global/api/v1/abusemanager/cases?verbose=0&limit=20&offset=0',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'X-API-KEY: ' . $c['abuse_iq_api'],
    ],
]);
$response = curl_exec($ch);
curl_close($ch);

// Decode response
$data = json_decode($response, true);
if (!isset($data['data']) || !is_array($data['data'])) {
    $log->error('No data found.');
    exit;
}

foreach ($data['data'] as $case) {
    $attr = $case['attributes'];

    if ($attr['sub_status'] !== 'new') continue;

    $domain = $attr['domain_name'];
    $abuseType = $attr['abuse_category'];

    // Check if already inserted
    $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM support_tickets WHERE reported_domain = ? AND nature_of_abuse = ?");
    $checkStmt->execute([$domain, $abuseType]);
    if ($checkStmt->fetchColumn() > 0) continue;

    $sql = "
        SELECT 
            COALESCE(ru.user_id, admin.id) AS user_id
        FROM domain d
        LEFT JOIN registrar_users ru ON d.clid = ru.registrar_id
        LEFT JOIN users admin ON admin.username = 'admin'
        WHERE d.name = ?
        LIMIT 1
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$domain]);
    $userData = $stmt->fetch();

    if (!$userData || empty($userData['user_id'])) {
        $log->error("No user ID could be resolved for domain: $domain");
        exit;
    }

    // Insert new support ticket
    $insertStmt = $pdo->prepare('INSERT INTO support_tickets (
        id, user_id, category_id, subject, message, status, priority,
        reported_domain, nature_of_abuse, evidence, relevant_urls,
        date_of_incident, date_created, last_updated
    ) VALUES (
        NULL, ?, 8, ?, ?, "Open", "High",
        ?, ?, ?, ?, ?, CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3)
    )');
    $insertStmt->execute([
        $userData['user_id'],
        "Abuse Report for $domain",
        "Abuse detected for domain $domain via $abuseType.",
        $domain,
        $abuseType,
        "Link to $abuseType",
        $attr['abuse_source'],
        $attr['create_date']
    ]);

    $log->info("created ticket for $domain");
}

$log->info('job finished successfully.');