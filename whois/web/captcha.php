<?php
declare(strict_types=1);

use AltchaOrg\Altcha\Altcha;
use AltchaOrg\Altcha\Algorithm\Pbkdf2;
use AltchaOrg\Altcha\CreateChallengeOptions;

session_start();

$c = require_once __DIR__ . '/config.php';
require __DIR__ . '/vendor/autoload.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (($c['ignore_captcha'] ?? true) !== false) {
    http_response_code(404);
    echo json_encode(['error' => 'Captcha is disabled.']);
    exit;
}

$secret = (string) ($c['altcha_hmac_secret'] ?? '');

if ($secret === '') {
    http_response_code(500);
    echo json_encode(['error' => 'ALTCHA is not configured.']);
    exit;
}

$altcha = new Altcha($secret);
$challenge = $altcha->createChallenge(new CreateChallengeOptions(
    algorithm: new Pbkdf2(),
    cost: (int) ($c['altcha_cost'] ?? 10000),
    expiresAt: time() + 120,
));

$data = $challenge->toArray();
$_SESSION['altcha_signature'] = $data['signature'];

echo json_encode($data, JSON_UNESCAPED_SLASHES);