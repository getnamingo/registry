<?php

declare(strict_types=1);

function envValue(string $name, string $default = ''): string
{
    $value = getenv($name);
    return $value === false ? $default : $value;
}

function envBool(string $name, bool $default = false): bool
{
    $value = getenv($name);
    if ($value === false || trim($value) === '') {
        return $default;
    }

    $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    if ($parsed === null) {
        throw new RuntimeException("{$name} must be true or false");
    }

    return $parsed;
}

function envInt(string $name, int $default, int $minimum = 0): int
{
    $value = envValue($name, (string) $default);
    if (!preg_match('/^-?[0-9]+$/', $value)) {
        throw new RuntimeException("{$name} must be an integer");
    }

    $parsed = (int) $value;
    if ($parsed < $minimum) {
        throw new RuntimeException("{$name} must be at least {$minimum}");
    }

    return $parsed;
}

function secret(string $envName): string
{
    $path = envValue($envName);
    if ($path === '' || !is_readable($path)) {
        throw new RuntimeException("Secret file from {$envName} is not readable");
    }

    $value = rtrim((string) file_get_contents($path), "\r\n");
    if ($value === '') {
        throw new RuntimeException("Secret file from {$envName} is empty");
    }

    return $value;
}

function override(string $name): array
{
    $path = "/etc/namingo/overrides/{$name}.override.php";
    if (!is_file($path)) {
        return [];
    }

    $value = require $path;
    if (!is_array($value)) {
        throw new RuntimeException("{$path} must return an array");
    }

    return $value;
}

function writePhpConfig(string $path, array $config): void
{
    $body = "<?php\n\ndeclare(strict_types=1);\n\nreturn "
        . var_export($config, true)
        . ";\n";
    writeAtomic($path, $body, 0640);
}

function writeAtomic(string $path, string $contents, int $mode): void
{
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
        throw new RuntimeException("Unable to create {$directory}");
    }

    $temporary = tempnam($directory, '.namingo-');
    if ($temporary === false) {
        throw new RuntimeException("Unable to create a temporary file in {$directory}");
    }

    try {
        if (file_put_contents($temporary, $contents, LOCK_EX) === false) {
            throw new RuntimeException("Unable to write {$temporary}");
        }
        chmod($temporary, $mode);
        if (!rename($temporary, $path)) {
            throw new RuntimeException("Unable to publish {$path}");
        }
    } finally {
        if (is_file($temporary)) {
            unlink($temporary);
        }
    }
}

function dotenv(array $values): string
{
    $lines = [];
    foreach ($values as $key => $value) {
        if (!preg_match('/^[A-Z][A-Z0-9_]*$/', (string) $key)) {
            throw new RuntimeException("Invalid dotenv key {$key}");
        }
        $encoded = json_encode((string) $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            throw new RuntimeException("Unable to encode dotenv value {$key}");
        }
        $lines[] = "{$key}={$encoded}";
    }

    return implode("\n", $lines) . "\n";
}

$domain = strtolower(rtrim(envValue('NAMINGO_DOMAIN'), '.'));
if (
    $domain === ''
    || strlen($domain) > 253
    || !filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
) {
    throw new RuntimeException('NAMINGO_DOMAIN must be a valid base domain');
}

$dbHost = envValue('NAMINGO_DB_HOST', 'database');
$dbPort = envInt('NAMINGO_DB_PORT', 3306, 1);
$dbName = envValue('NAMINGO_DB_NAME', 'registry');
$dbUser = envValue('NAMINGO_DB_USER', 'namingo');
$dbPassword = secret('NAMINGO_DB_PASSWORD_FILE');
$msgToken = secret('NAMINGO_MSG_API_TOKEN_FILE');
$altchaSecret = secret('NAMINGO_ALTCHA_SECRET_FILE');
$adminEmail = envValue('NAMINGO_ADMIN_EMAIL', "admin@{$domain}");
$roid = envValue('NAMINGO_ROID', 'XX');
$rateLimit = envBool('NAMINGO_RATE_LIMIT', false);
$rateLimitRequests = envInt('NAMINGO_RATE_LIMIT_REQUESTS', 1000, 1);
$rateLimitPeriod = envInt('NAMINGO_RATE_LIMIT_PERIOD', 60, 1);
$messageMailer = strtolower(envValue('NAMINGO_MESSAGE_MAILER', 'phpmailer'));
if (!in_array($messageMailer, ['phpmailer', 'sendgrid', 'mailgun'], true)) {
    throw new RuntimeException(
        'NAMINGO_MESSAGE_MAILER must be phpmailer, sendgrid, or mailgun'
    );
}
$smsProvider = strtolower(envValue('NAMINGO_SMS_PROVIDER', 'twilio'));
if (!in_array($smsProvider, ['twilio', 'telesign', 'plivo', 'vonage', 'clickatell'], true)) {
    throw new RuntimeException('NAMINGO_SMS_PROVIDER is invalid');
}

$database = [
    'db_type' => 'mysql',
    'db_host' => $dbHost,
    'db_port' => $dbPort,
    'db_database' => $dbName,
    'db_username' => $dbUser,
    'db_password' => $dbPassword,
];

$epp = array_replace_recursive(
    require '/opt/registry/epp/config.php.dist',
    [
        'test_tlds' => envValue('NAMINGO_TEST_TLDS', '.test,.com.test'),
        'rately' => $rateLimit,
        'limit' => $rateLimitRequests,
        'period' => $rateLimitPeriod,
        'minimum_data' => envBool('NAMINGO_MINIMUM_DATA', false),
        'mandatory_client_ssl' => envBool('NAMINGO_EPP_REQUIRE_CLIENT_CERT', false),
    ],
    override('epp'),
    $database,
    [
        'epp_host' => '0.0.0.0',
        'epp_port' => 700,
        'epp_pid' => '/run/epp.pid',
        'ssl_cert' => '/run/namingo/tls/epp.crt',
        'ssl_key' => '/run/namingo/tls/epp.key',
    ]
);
writePhpConfig('/run/namingo/epp-config.php', $epp);

$rdapUrl = "https://rdap.{$domain}";
$rdap = array_replace_recursive(
    require '/opt/registry/rdap/config.php.dist',
    [
        'roid' => $roid,
        'minimum_data' => envBool('NAMINGO_MINIMUM_DATA', false),
        'limited_rdap' => envBool('NAMINGO_RDAP_LIMITED', false),
        'registry_url' => envValue('NAMINGO_REGISTRY_TERMS_URL', "https://cp.{$domain}/support/terms"),
        'rdap_url' => $rdapUrl,
        'rately' => $rateLimit,
        'limit' => $rateLimitRequests,
        'period' => $rateLimitPeriod,
    ],
    override('rdap'),
    $database
);
writePhpConfig('/run/namingo/rdap-config.php', $rdap);

$whois = array_replace_recursive(
    require '/opt/registry/whois/port43/config.php.dist',
    [
        'privacy' => envBool('NAMINGO_WHOIS_PRIVACY', false),
        'minimum_data' => envBool('NAMINGO_MINIMUM_DATA', false),
        'limited_whois' => envBool('NAMINGO_WHOIS_LIMITED', false),
        'roid' => $roid,
        'rately' => $rateLimit,
        'limit' => envInt('NAMINGO_WHOIS_RATE_LIMIT_REQUESTS', 25, 1),
        'period' => $rateLimitPeriod,
    ],
    override('whois'),
    $database,
    [
        'whois_ipv4' => '0.0.0.0',
        'whois_ipv6' => false,
    ]
);
writePhpConfig('/run/namingo/whois-config.php', $whois);

$das = array_replace_recursive(
    require '/opt/registry/das/config.php.dist',
    [
        'rately' => $rateLimit,
        'limit' => $rateLimitRequests,
        'period' => $rateLimitPeriod,
    ],
    override('das'),
    $database,
    [
        'das_ipv4' => '0.0.0.0',
        'das_ipv6' => false,
    ]
);
writePhpConfig('/run/namingo/das-config.php', $das);

$webWhois = array_replace_recursive(
    require '/opt/registry/whois/web/config.php.dist',
    [
        'whois_url' => '127.0.0.1',
        'rdap_url' => "rdap.{$domain}",
        'ignore_captcha' => !envBool('NAMINGO_WEB_WHOIS_CAPTCHA', false),
        'altcha_hmac_secret' => $altchaSecret,
        'registry_name' => envValue('NAMINGO_REGISTRY_NAME', 'Domain Registry'),
        'registry_url' => envValue('NAMINGO_REGISTRY_URL', "https://cp.{$domain}"),
    ],
    override('web-whois')
);
writePhpConfig('/run/namingo/web-whois-config.php', $webWhois);

$nameservers = array_values(array_filter(array_map(
    static fn (string $name): string => rtrim(trim($name), '.'),
    explode(',', envValue('NAMINGO_NAMESERVERS', "ns1.{$domain},ns2.{$domain}"))
)));
if (count($nameservers) < 2) {
    throw new RuntimeException('NAMINGO_NAMESERVERS must contain at least two comma-separated names');
}
$ns = [];
foreach ($nameservers as $index => $nameserver) {
    $ns['ns' . ($index + 1)] = $nameserver;
}

$automation = array_replace_recursive(
    require '/opt/registry/automation/config.php.dist',
    [
        'roid' => $roid,
        'ns' => $ns,
        'dns_server' => strtolower(envValue('NAMINGO_DNS_SERVER', 'bind')),
        'dns_soa' => rtrim(envValue('NAMINGO_DNS_SOA', "hostmaster.{$domain}"), '.'),
        'dns_serial' => envInt('NAMINGO_DNS_SERIAL', 1, 1),
        'dns_reload' => envBool('NAMINGO_DNS_RELOAD', false),
        'minimum_data' => envBool('NAMINGO_MINIMUM_DATA', false),
        'msg_api_token' => $msgToken,
        'mailer' => $messageMailer,
        'mailer_smtp_host' => envValue('MAIL_HOST'),
        'mailer_smtp_port' => envInt('MAIL_PORT', 587, 1),
        'mailer_smtp_username' => envValue('MAIL_USERNAME'),
        'mailer_smtp_password' => envValue('MAIL_PASSWORD'),
        'mailer_smtp_encryption' => envValue('MAIL_ENCRYPTION', 'tls'),
        'mailer_from' => envValue('MAIL_FROM_ADDRESS', $adminEmail),
        'mailer_from_name' => envValue('MAIL_FROM_NAME', 'Registry'),
        'mailer_api_key' => envValue('MAIL_API_KEY'),
        'mailer_domain' => $domain,
        'mailer_sms' => $smsProvider,
        'mailer_sms_account' => envValue('NAMINGO_SMS_ACCOUNT'),
        'mailer_sms_auth' => envValue('NAMINGO_SMS_AUTH'),
        'mailer_sms_from' => envValue('NAMINGO_SMS_FROM'),
        'admin_email' => $adminEmail,
        'iana_email' => envValue('NAMINGO_IANA_EMAIL', $adminEmail),
        'cron_accounting' => envBool('NAMINGO_CRON_ACCOUNTING', false),
        'cron_backup' => envBool('NAMINGO_CRON_BACKUP', false),
        'cron_backup_upload' => envBool('NAMINGO_CRON_BACKUP_UPLOAD', false),
        'cron_gtld_mode' => envBool('NAMINGO_CRON_GTLD_MODE', false),
        'cron_spec11' => envBool('NAMINGO_CRON_SPEC11', false),
        'cron_spec11_iq' => envBool('NAMINGO_CRON_SPEC11_IQ', false),
        'cron_exchange_rates' => envBool('NAMINGO_CRON_EXCHANGE_RATES', false),
        'cron_cds_scanner' => envBool('NAMINGO_CRON_CDS_SCANNER', false),
    ],
    override('automation'),
    $database,
    [
        'escrow_deposit_path' => '/opt/escrow',
        'reporting_path' => '/opt/reporting',
    ]
);
writePhpConfig('/run/namingo/automation-config.php', $automation);

$panel = [
    'APP_NAME' => envValue('NAMINGO_PANEL_NAME', 'Namingo Registry'),
    'APP_ENV' => envValue('NAMINGO_APP_ENV', 'public'),
    'APP_URL' => "https://cp.{$domain}",
    'APP_DOMAIN' => $domain,
    'APP_ROOT' => '/var/www/cp',
    'TIME_ZONE' => envValue('NAMINGO_TIMEZONE', 'UTC'),
    'MINIMUM_DATA' => envBool('NAMINGO_MINIMUM_DATA', false) ? 'true' : 'false',
    'LANG' => envValue('NAMINGO_LANGUAGE', 'en_US'),
    'UI_LANG' => envValue('NAMINGO_UI_LANGUAGE', 'us'),
    'DB_DRIVER' => 'mysql',
    'DB_HOST' => $dbHost,
    'DB_DATABASE' => $dbName,
    'DB_USERNAME' => $dbUser,
    'DB_PASSWORD' => $dbPassword,
    'DB_PORT' => (string) $dbPort,
    'MAIL_DRIVER' => envValue('MAIL_DRIVER', 'none'),
    'MAIL_HOST' => envValue('MAIL_HOST'),
    'MAIL_PORT' => envValue('MAIL_PORT', '587'),
    'MAIL_USERNAME' => envValue('MAIL_USERNAME'),
    'MAIL_PASSWORD' => envValue('MAIL_PASSWORD'),
    'MAIL_ENCRYPTION' => envValue('MAIL_ENCRYPTION', 'tls'),
    'MAIL_FROM_ADDRESS' => envValue('MAIL_FROM_ADDRESS', $adminEmail),
    'MAIL_TO_ADDRESS' => envValue('MAIL_TO_ADDRESS', $adminEmail),
    'MAIL_FROM_NAME' => envValue('MAIL_FROM_NAME', 'Registry'),
    'MAIL_API_KEY' => envValue('MAIL_API_KEY'),
    'MAIL_API_PROVIDER' => envValue('MAIL_API_PROVIDER', 'sendgrid'),
    'MSG_API_TOKEN' => $msgToken,
    'ENABLED_GATEWAYS' => envValue('ENABLED_GATEWAYS'),
    'STRIPE_SECRET_KEY' => envValue('STRIPE_SECRET_KEY'),
    'STRIPE_PUBLISHABLE_KEY' => envValue('STRIPE_PUBLISHABLE_KEY'),
    'REVOLUT_SANDBOX' => envValue('REVOLUT_SANDBOX', 'true'),
    'REVOLUT_SECRET_KEY' => envValue('REVOLUT_SECRET_KEY'),
    'REVOLUT_PUBLIC_KEY' => envValue('REVOLUT_PUBLIC_KEY'),
    'REVOLUT_API_VERSION' => envValue('REVOLUT_API_VERSION', '2024-09-01'),
    'REVOLUT_CARD_WEBHOOK_SECRET' => envValue('REVOLUT_CARD_WEBHOOK_SECRET'),
    'REVOLUT_PAY_WEBHOOK_SECRET' => envValue('REVOLUT_PAY_WEBHOOK_SECRET'),
    'LIQPAY_PUBLIC_KEY' => envValue('LIQPAY_PUBLIC_KEY'),
    'LIQPAY_PRIVATE_KEY' => envValue('LIQPAY_PRIVATE_KEY'),
    'PLATA_TOKEN' => envValue('PLATA_TOKEN'),
    'ADYEN_API_KEY' => envValue('ADYEN_API_KEY'),
    'ADYEN_MERCHANT_ID' => envValue('ADYEN_MERCHANT_ID'),
    'ADYEN_THEME_ID' => envValue('ADYEN_THEME_ID'),
    'ADYEN_BASE_URI' => envValue('ADYEN_BASE_URI', 'https://checkout-test.adyen.com/v70/'),
    'ADYEN_BASIC_AUTH_USER' => envValue('ADYEN_BASIC_AUTH_USER'),
    'ADYEN_BASIC_AUTH_PASS' => envValue('ADYEN_BASIC_AUTH_PASS'),
    'ADYEN_HMAC_KEY' => envValue('ADYEN_HMAC_KEY'),
    'NOW_API_KEY' => envValue('NOW_API_KEY'),
    'NICKY_API_KEY' => envValue('NICKY_API_KEY'),
    'SUMSUB_TOKEN' => envValue('SUMSUB_TOKEN'),
    'SUMSUB_KEY' => envValue('SUMSUB_KEY'),
    'TEST_TLDS' => envValue('NAMINGO_TEST_TLDS', '.test,.com.test'),
    'PASSWORD_EXPIRATION_SKIP_USERS' => envValue('PASSWORD_EXPIRATION_SKIP_USERS', 'admin,superadmin'),
];
writeAtomic('/run/namingo/panel.env', dotenv($panel), 0640);
