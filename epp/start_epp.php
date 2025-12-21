<?php

use Swoole\Table;
use Swoole\Timer;
use Swoole\Coroutine\Server;
use Swoole\Coroutine\Server\Connection;
use Namingo\Rately\Rately;
use Selective\XmlDSig\PublicKeyStore;
use Selective\XmlDSig\CryptoVerifier;
use Selective\XmlDSig\XmlSignatureVerifier;

global $c;
$c = require_once 'config.php';
require_once 'src/EppWriter.php';
require_once 'src/helpers.php';
require_once 'src/epp-check.php';
require_once 'src/epp-info.php';
require_once 'src/epp-create.php';
require_once 'src/epp-update.php';
require_once 'src/epp-renew.php';
require_once 'src/epp-poll.php';
require_once 'src/epp-transfer.php';
require_once 'src/epp-delete.php';

$logFilePath = '/var/log/namingo/epp.log';
$log = setupLogger($logFilePath, 'EPP');

$table = new Table(1024);
$table->column('clid', Table::TYPE_STRING, 64);
$table->column('logged_in', Table::TYPE_INT, 1);
$table->create();

$permittedIPsTable = new Table(1024);
$permittedIPsTable->column('addr', Table::TYPE_STRING, 64);
$permittedIPsTable->create();

$eppExtensionsTable = new Swoole\Table(64); // adjust size as needed
$eppExtensionsTable->column('extension', Swoole\Table::TYPE_INT, 1); // Column name just for compliance
$eppExtensionsTable->create();
$data = json_decode(@file_get_contents('/opt/registry/epp/extensions.json'), true);
if (is_array($data)) {
    foreach ($data as $urn => $info) {
        if (!empty($info['enabled'])) {
            $eppExtensionsTable->set($urn, ['extension' => 1]);
        }
    }
} else {
    // fallback if file is missing or invalid
    $fallback = [
        'https://namingo.org/epp/funds-1.0',
        'https://namingo.org/epp/identica-1.0',
        'urn:ietf:params:xml:ns:secDNS-1.1',
        'urn:ietf:params:xml:ns:rgp-1.0',
        'urn:ietf:params:xml:ns:launch-1.0',
        'urn:ietf:params:xml:ns:idn-1.0',
        'urn:ietf:params:xml:ns:epp:fee-1.0',
        'urn:ietf:params:xml:ns:mark-1.0',
        'urn:ietf:params:xml:ns:allocationToken-1.0',
        'urn:ietf:params:xml:ns:epp:loginSec-1.0'
    ];
    foreach ($fallback as $urn) {
        $eppExtensionsTable->set($urn, ['extension' => 1]);
    }
}

// Initialize the PDO connection pool
$pool = new Swoole\Database\PDOPool(
    (new Swoole\Database\PDOConfig())
        ->withDriver($c['db_type'])
        ->withHost($c['db_host'])
        ->withPort($c['db_port'])
        ->withDbName($c['db_database'])
        ->withUsername($c['db_username'])
        ->withPassword($c['db_password'])
        ->withCharset('utf8mb4'), 16
);

Swoole\Runtime::enableCoroutine();
$server = new Server($c['epp_host'], $c['epp_port']);
$server->set([
    'enable_coroutine' => true,
    'log_file' => '/var/log/namingo/epp_application.log',
    'log_level' => SWOOLE_LOG_INFO,
    'worker_num' => swoole_cpu_num() * 4,
    'pid_file' => $c['epp_pid'],
    'max_request' => 1000,
    'max_conn' => 1024,
    'open_tcp_nodelay' => true,
    'open_tcp_keepalive' => true,
    'tcp_keepidle' => 30,
    'tcp_keepinterval' => 10,
    'tcp_keepcount' => 3,
    'tcp_defer_accept' => true,
    'tcp_fastopen' => true,
    'tcp_user_timeout' => 30000,
    'ssl_handshake_timeout' => 15,
    'heartbeat_check_interval' => 60,
    'heartbeat_idle_time' => 120,
    'buffer_output_size' => 2 * 1024 * 1024,
    'send_yield' => true,
    'open_ssl' => true,
    'ssl_client_cert_depth' => 1,
    'ssl_cert_file' => $c['ssl_cert'],
    'ssl_key_file' => $c['ssl_key'],
    'ssl_verify_peer' => false,
    'ssl_verify_depth' => 3,
    'ssl_client_cert_file' => '/etc/ssl/certs/ca-certificates.crt',
    'ssl_allow_self_signed' => false,
    'ssl_protocols' => SWOOLE_SSL_TLSv1_2 | SWOOLE_SSL_TLSv1_3,
    'ssl_ciphers' => 'ECDHE-RSA-AES256-GCM-SHA384:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-RSA-AES256-SHA384:ECDHE-RSA-AES128-SHA256:DHE-RSA-AES256-GCM-SHA384:ECDHE+AESGCM:ECDHE+AES256:ECDHE+AES128:DHE+AES256:DHE+AES128:!aNULL:!eNULL:!EXPORT:!DES:!RC4:!3DES:!MD5:!PSK',
]);

$rateLimiter = new Rately();
$log->info('Namingo EPP server started');
Swoole\Coroutine::create(function () use ($pool, $permittedIPsTable, $log) {
    updatePermittedIPs($pool, $permittedIPsTable);
    if (count($permittedIPsTable) === 0) {
        $log->warning('Permitted IPs table is empty after initial load; no EPP clients will be able to connect.');
    }
});

$server->handle(function (Connection $conn) use ($table, $eppExtensionsTable, $pool, $c, $log, $permittedIPsTable, $rateLimiter) {
    // Get the client information
    $clientInfo = $conn->exportSocket()->getpeername();
    $clientIP = isset($clientInfo['address']) ? (strpos($clientInfo['address'], '::ffff:') === 0 ? substr($clientInfo['address'], 7) : $clientInfo['address']) : '';
    if (isIPv6($clientIP)) {
        $clientIP = expandIPv6($clientIP);
    }

    // Check if the IP is in the permitted list
    if (!$permittedIPsTable->exist($clientIP)) {
        $allowed = false;
        foreach ($permittedIPsTable as $row) {
            if (strpos($row['addr'], '/') !== false && ipMatches($clientIP, $row['addr'])) {
                $allowed = true;
                break;
            }
        }
        if (!$allowed) {
            $log->warning('Access denied. The IP address ' . $clientIP . ' is not authorized for this service.');
            $conn->close();
            return;
        }
    }

    if (($c['rately'] == true) && ($rateLimiter->isRateLimited('epp', $clientIP, $c['limit'], $c['period']))) {
        $log->error('rate limit exceeded for ' . $clientIP);
        $conn->close();
        return;
    }

    $log->info('new client from ' . $clientIP . ' connected');
    sendGreeting($conn, $eppExtensionsTable);

    $buffer = '';
    $maxFrameLen = $c['epp_max_frame'] ?? (4 * 1024 * 1024); // 4 MB default

    while (true) {
        $chunk = $conn->recv();
        if ($chunk === '' || $chunk === false) {
            $conn->close();
            break;
        }
        $buffer .= $chunk;

        $frames = [];
        while (strlen($buffer) >= 4) {
            $len = unpack('N', substr($buffer, 0, 4))[1];

            if ($len < 5 || $len > $maxFrameLen) {
                $log->warning("Invalid EPP frame length $len from $clientIP");
                $conn->close();
                break 2;
            }

            if (strlen($buffer) < $len) {
                break; // wait for more bytes
            }

            $frames[] = substr($buffer, 4, $len - 4);
            $buffer   = substr($buffer, $len);
        }

        if (!$frames) {
            continue;
        }

        $pdo = null;
        try {
            $pdo = $pool->get();
            if (!$pdo) {
                $conn->close();
                break;
            }

            $connId = spl_object_id($conn);

            foreach ($frames as $xmlData) {
                libxml_use_internal_errors(true);

                $xml = simplexml_load_string($xmlData, 'SimpleXMLElement', LIBXML_NONET);
                if ($xml === false) {
                    sendEppError($conn, $pdo, 2001, 'Invalid XML syntax');
                    continue;
                }

                $xml->registerXPathNamespace('e', 'urn:ietf:params:xml:ns:epp-1.0');
                $xml->registerXPathNamespace('xsi', 'http://www.w3.org/2001/XMLSchema-instance');
                $xml->registerXPathNamespace('domain', 'urn:ietf:params:xml:ns:domain-1.0');
                $xml->registerXPathNamespace('contact', 'urn:ietf:params:xml:ns:contact-1.0');
                $xml->registerXPathNamespace('host', 'urn:ietf:params:xml:ns:host-1.0');
                $xml->registerXPathNamespace('rgp', 'urn:ietf:params:xml:ns:rgp-1.0');
                $xml->registerXPathNamespace('secDNS', 'urn:ietf:params:xml:ns:secDNS-1.1');
                $xml->registerXPathNamespace('launch', 'urn:ietf:params:xml:ns:launch-1.0');
                $xml->registerXPathNamespace('fee', 'urn:ietf:params:xml:ns:epp:fee-1.0');
                $xml->registerXPathNamespace('mark', 'urn:ietf:params:xml:ns:mark-1.0');
                $xml->registerXPathNamespace('allocationToken', 'urn:ietf:params:xml:ns:allocationToken-1.0');
                $xml->registerXPathNamespace('loginSec', 'urn:ietf:params:xml:ns:epp:loginSec-1.0');
                $xml->registerXPathNamespace('identica', 'https://namingo.org/epp/identica-1.0');

                if ($xml->getName() != 'epp') {
                    sendEppError($conn, $pdo, 2001, 'Root element must be <epp>');
                    continue;
                }

                switch (true) {
                    case isset($xml->command->login):
                    {
                        $clID = (string) $xml->command->login->clID;
                        $pw = (string) $xml->command->login->pw;
                        $clTRID = (string) $xml->command->clTRID;
                        $clid = getClid($pdo, $clID);
                        if (!$clid) {
                            sendEppError($conn, $pdo, 2201, 'Unknown client identifier', $clTRID);
                            break;
                        }
                        $loginSec = $xml->xpath('//e:extension/loginSec:loginSec')[0] ?? null;

                        $loginSecPw = null;
                        $loginSecNewPw = null;

                        if ($loginSec) {
                            if (isset($loginSec->pw)) {
                                $loginSecPw = (string) $loginSec->pw;
                            }
                            if (isset($loginSec->newPW)) {
                                $loginSecNewPw = (string) $loginSec->newPW;
                            }
                        }
                        
                        if ($pw === '[LOGIN-SECURITY]' && $loginSecPw) {
                            $pw = $loginSecPw;
                        }

                        if (!$loginSec && strlen($pw) > 16) {
                            sendEppError(
                                $conn,
                                $pdo,
                                2306,
                                'Password length exceeds 16 characters without loginSec extension. Use loginSec extension or a shorter password.',
                                $clTRID
                            );
                            break;
                        }

                        $xmlString = $xml->asXML();
                        $trans = createTransaction($pdo, $clid, $clTRID, $xmlString);

                        if (checkLogin($pdo, $clID, $pw)) {
                            if (isset($xml->command->login->newPW)) {
                                $newPW = (string) $xml->command->login->newPW;
                                if ($newPW === '[LOGIN-SECURITY]' && $loginSecNewPw) {
                                    $newPW = $loginSecNewPw;
                                }
                                if (!$loginSec && strlen($newPW) > 16) {
                                    sendEppError(
                                        $conn,
                                        $pdo,
                                        2306,
                                        'New password length exceeds 16 characters without loginSec extension. Use loginSec extension or a shorter password.',
                                        $clTRID
                                    );
                                    break;
                                }
                                $options = [
                                    'memory_cost' => 1024 * 128,
                                    'time_cost'   => 6,
                                    'threads'     => 4,
                                ];
                                $hashedPassword = password_hash($newPW, PASSWORD_ARGON2ID, $options);
                                try {
                                    $stmt = $pdo->prepare("UPDATE registrar SET pw = :newPW WHERE clid = :clID");
                                    $stmt->bindParam(':newPW', $hashedPassword);
                                    $stmt->bindParam(':clID', $clID);
                                    $stmt->execute();
                                } catch (PDOException $e) {
                                    $log->error('Password change DB error for ' . $clID . ': ' . $e->getMessage());
                                    sendEppError($conn, $pdo, 2400, 'Password could not be changed', $clTRID);
                                    break;
                                }

                                $svTRID = generateSvTRID();
                                $response = [
                                    'command' => 'login',
                                    'resultCode' => 1000,
                                    'lang' => 'en-US',
                                    'clTRID' => $clTRID,
                                    'svTRID' => $svTRID,
                                    'msg' => 'Password changed successfully. Session will be terminated'
                                ];

                                $epp = new EPP\EppWriter();
                                $xml = $epp->epp_writer($response);
                                updateTransaction($pdo, 'login', null, null, 1000, 'Password changed successfully. Session will be terminated', $svTRID, $xml, $trans);
                                sendEppResponse($conn, $xml);
                                $conn->close();
                                break;
                            }
                            
                            $table->set($connId, ['clid' => $clID, 'logged_in' => 1]);
                            $svTRID = generateSvTRID();
                            $response = [
                                'command' => 'login',
                                'resultCode' => 1000,
                                'lang' => 'en-US',
                                'clTRID' => $clTRID,
                                'svTRID' => $svTRID,
                            ];

                            $epp = new EPP\EppWriter();
                            $xml = $epp->epp_writer($response);
                            $log->info('registrar ' . $clID . ' logged in');
                            updateTransaction($pdo, 'login', null, null, 1000, 'Command completed successfully', $svTRID, $xml, $trans);
                            sendEppResponse($conn, $xml);
                        } else {
                            sendEppError($conn, $pdo, 2200, 'Authentication error', $clTRID);
                        }
                        break;
                    }

                    case isset($xml->command->logout):
                    {
                        $data = $table->get($connId);
                        $clTRID = (string) $xml->command->clTRID;
                        if (!$data || $data['logged_in'] !== 1) {
                            sendEppError($conn, $pdo, 2202, 'Authorization error', $clTRID);
                            $conn->close();
                            break;
                        }
                        $clID = $data['clid'];
                        $clid = getClid($pdo, $clID);
                        $table->del($connId);
                        $xmlString = $xml->asXML();
                        $trans = createTransaction($pdo, $clid, $clTRID, $xmlString);
                        $svTRID = generateSvTRID();
                        $response = [
                            'command' => 'logout',
                            'resultCode' => 1500,
                            'lang' => 'en-US',
                            'clTRID' => $clTRID,
                            'svTRID' => $svTRID,
                        ];

                        $epp = new EPP\EppWriter();
                        $xml = $epp->epp_writer($response);
                        $log->info('registrar ' . $clID . ' logged out');
                        updateTransaction($pdo, 'logout', null, null, 1500, 'Command completed successfully; ending session', $svTRID, $xml, $trans);
                        sendEppResponse($conn, $xml);
                        $conn->close();
                        break;
                    }
                    
                    case isset($xml->hello):
                    {
                        sendGreeting($conn, $eppExtensionsTable);
                        break;
                    }
                    
                    case isset($xml->command->poll):
                    {
                        $data = $table->get($connId);
                        $clTRID = (string) $xml->command->clTRID;
                        if (!$data || $data['logged_in'] !== 1) {
                            sendEppError($conn, $pdo, 2202, 'Authorization error', $clTRID);
                            $conn->close();
                            break;
                        }
                        $clid = getClid($pdo, $data['clid']);
                        $xmlString = $xml->asXML();
                        $trans = createTransaction($pdo, $clid, $clTRID, $xmlString);
                        processPoll($conn, $pdo, $xml, $data['clid'], $trans);
                        break;
                    }
              
                    case isset($xml->command->check) && isset($xml->command->check->children('urn:ietf:params:xml:ns:contact-1.0')->check):
                    {
                        $data = $table->get($connId);
                        $clTRID = (string) $xml->command->clTRID;
                        if (!$data || $data['logged_in'] !== 1) {
                            sendEppError($conn, $pdo, 2202, 'Authorization error', $clTRID);
                            $conn->close();
                            break;
                        }
                        $clid = getClid($pdo, $data['clid']);
                        $xmlString = $xml->asXML();
                        $trans = createTransaction($pdo, $clid, $clTRID, $xmlString);
                        if ($c['minimum_data']) {
                            sendEppError($conn, $pdo, 2101, 'Contact commands are not supported in minimum data mode', $clTRID);
                            $conn->close();
                            break;
                        }
                        processContactCheck($conn, $pdo, $xml, $trans);
                        break;
                    }
              
                    case isset($xml->command->create) && isset($xml->command->create->children('urn:ietf:params:xml:ns:contact-1.0')->create):
                    {
                        $data = $table->get($connId);
                        $clTRID = (string) $xml->command->clTRID;
                        if (!$data || $data['logged_in'] !== 1) {
                            sendEppError($conn, $pdo, 2202, 'Authorization error', $clTRID);
                            $conn->close();
                            break;
                        }
                        $clid = getClid($pdo, $data['clid']);
                        $xmlString = $xml->asXML();
                        $trans = createTransaction($pdo, $clid, $clTRID, $xmlString);
                        if ($c['minimum_data']) {
                            sendEppError($conn, $pdo, 2101, 'Contact commands are not supported in minimum data mode', $clTRID);
                            $conn->close();
                            break;
                        }
                        processContactCreate($conn, $pdo, $xml, $data['clid'], $c['db_type'], $trans);
                        break;
                    }
              
                    case isset($xml->command->info) && isset($xml->command->info->children('urn:ietf:params:xml:ns:contact-1.0')->info):
                    {
                        $data = $table->get($connId);
                        $clTRID = (string) $xml->command->clTRID;
                        if (!$data || $data['logged_in'] !== 1) {
                            sendEppError($conn, $pdo, 2202, 'Authorization error', $clTRID);
                            $conn->close();
                            break;
                        }
                        $clid = getClid($pdo, $data['clid']);
                        $xmlString = $xml->asXML();
                        $trans = createTransaction($pdo, $clid, $clTRID, $xmlString);
                        if ($c['minimum_data']) {
                            sendEppError($conn, $pdo, 2101, 'Contact commands are not supported in minimum data mode', $clTRID);
                            $conn->close();
                            break;
                        }
                        processContactInfo($conn, $pdo, $xml, $data['clid'], $trans);
                        break;
                    }
                    
                    case isset($xml->command->update) && isset($xml->command->update->children('urn:ietf:params:xml:ns:contact-1.0')->update):
                    {
                        $data = $table->get($connId);
                        $clTRID = (string) $xml->command->clTRID;
                        if (!$data || $data['logged_in'] !== 1) {
                            sendEppError($conn, $pdo, 2202, 'Authorization error', $clTRID);
                            $conn->close();
                            break;
                        }
                        $clid = getClid($pdo, $data['clid']);
                        $xmlString = $xml->asXML();
                        $trans = createTransaction($pdo, $clid, $clTRID, $xmlString);
                        if ($c['minimum_data']) {
                            sendEppError($conn, $pdo, 2101, 'Contact commands are not supported in minimum data mode', $clTRID);
                            $conn->close();
                            break;
                        }
                        processContactUpdate($conn, $pdo, $xml, $data['clid'], $c['db_type'], $trans);
                        break;
                    }
                    
                    case isset($xml->command->delete) && isset($xml->command->delete->children('urn:ietf:params:xml:ns:contact-1.0')->delete):
                    {
                        $data = $table->get($connId);
                        $clTRID = (string) $xml->command->clTRID;
                        if (!$data || $data['logged_in'] !== 1) {
                            sendEppError($conn, $pdo, 2202, 'Authorization error', $clTRID);
                            $conn->close();
                            break;
                        }
                        $clid = getClid($pdo, $data['clid']);
                        $xmlString = $xml->asXML();
                        $trans = createTransaction($pdo, $clid, $clTRID, $xmlString);
                        if ($c['minimum_data']) {
                            sendEppError($conn, $pdo, 2101, 'Contact commands are not supported in minimum data mode', $clTRID);
                            $conn->close();
                            break;
                        }
                        processContactDelete($conn, $pdo, $xml, $data['clid'], $c['db_type'], $trans);
                        break;
                    }
                    
                    case isset($xml->command->transfer) && isset($xml->command->transfer->children('urn:ietf:params:xml:ns:contact-1.0')->transfer):
                    {
                        $data = $table->get($connId);
                        $clTRID = (string) $xml->command->clTRID;
                        if (!$data || $data['logged_in'] !== 1) {
                            sendEppError($conn, $pdo, 2202, 'Authorization error', $clTRID);
                            $conn->close();
                            break;
                        }
                        $clid = getClid($pdo, $data['clid']);
                        $xmlString = $xml->asXML();
                        $trans = createTransaction($pdo, $clid, $clTRID, $xmlString);
                        if ($c['minimum_data']) {
                            sendEppError($conn, $pdo, 2101, 'Contact commands are not supported in minimum data mode', $clTRID);
                            $conn->close();
                            break;
                        }
                        processContactTransfer($conn, $pdo, $xml, $data['clid'], $c, $trans);
                        break;
                    }
                
                    case isset($xml->command->check) && isset($xml->command->check->children('urn:ietf:params:xml:ns:domain-1.0')->check):
                    {
                        $data = $table->get($connId);
                        $clTRID = (string) $xml->command->clTRID;
                        if (!$data || $data['logged_in'] !== 1) {
                            sendEppError($conn, $pdo, 2202, 'Authorization error', $clTRID);
                            $conn->close();
                            break;
                        }
                        $clid = getClid($pdo, $data['clid']);
                        $xmlString = $xml->asXML();
                        $trans = createTransaction($pdo, $clid, $clTRID, $xmlString);
                        processDomainCheck($conn, $pdo, $xml, $trans, $data['clid']);
                        break;
                    }

                    case isset($xml->command->info) && isset($xml->command->info->children('urn:ietf:params:xml:ns:domain-1.0')->info):
                    {
                        $data = $table->get($connId);
                        $clTRID = (string) $xml->command->clTRID;
                        if (!$data || $data['logged_in'] !== 1) {
                            sendEppError($conn, $pdo, 2202, 'Authorization error', $clTRID);
                            $conn->close();
                            break;
                        }
                        $clid = getClid($pdo, $data['clid']);
                        $xmlString = $xml->asXML();
                        $trans = createTransaction($pdo, $clid, $clTRID, $xmlString);
                        processDomainInfo($conn, $pdo, $xml, $clid, $trans);
                        break;
                    }
                    
                    case isset($xml->command->update) && isset($xml->command->update->children('urn:ietf:params:xml:ns:domain-1.0')->update):
                    {
                        $data = $table->get($connId);
                        $clTRID = (string) $xml->command->clTRID;
                        if (!$data || $data['logged_in'] !== 1) {
                            sendEppError($conn, $pdo, 2202, 'Authorization error', $clTRID);
                            $conn->close();
                            break;
                        }
                        $clid = getClid($pdo, $data['clid']);
                        $xmlString = $xml->asXML();
                        $trans = createTransaction($pdo, $clid, $clTRID, $xmlString);
                        processDomainUpdate($conn, $pdo, $xml, $data['clid'], $c['db_type'], $trans);
                        break;
                    }
                    
                    case isset($xml->command->create) && isset($xml->command->create->children('urn:ietf:params:xml:ns:domain-1.0')->create):
                    {
                        $data = $table->get($connId);
                        $clTRID = (string) $xml->command->clTRID;
                        if (!$data || $data['logged_in'] !== 1) {
                            sendEppError($conn, $pdo, 2202, 'Authorization error', $clTRID);
                            $conn->close();
                            break;
                        }
                        $clid = getClid($pdo, $data['clid']);
                        $xmlString = $xml->asXML();
                        $trans = createTransaction($pdo, $clid, $clTRID, $xmlString);
                        processDomainCreate($conn, $pdo, $xml, $data['clid'], $c['db_type'], $trans, $c['minimum_data']);
                        break;
                    }
                    
                    case isset($xml->command->delete) && isset($xml->command->delete->children('urn:ietf:params:xml:ns:domain-1.0')->delete):
                    {
                        $data = $table->get($connId);
                        $clTRID = (string) $xml->command->clTRID;
                        if (!$data || $data['logged_in'] !== 1) {
                            sendEppError($conn, $pdo, 2202, 'Authorization error', $clTRID);
                            $conn->close();
                            break;
                        }
                        $clid = getClid($pdo, $data['clid']);
                        $xmlString = $xml->asXML();
                        $trans = createTransaction($pdo, $clid, $clTRID, $xmlString);
                        processDomainDelete($conn, $pdo, $xml, $data['clid'], $c['db_type'], $trans);
                        break;
                    }
                    
                    case isset($xml->command->transfer) && isset($xml->command->transfer->children('urn:ietf:params:xml:ns:domain-1.0')->transfer):
                    {
                        $data = $table->get($connId);
                        $clTRID = (string) $xml->command->clTRID;
                        if (!$data || $data['logged_in'] !== 1) {
                            sendEppError($conn, $pdo, 2202, 'Authorization error', $clTRID);
                            $conn->close();
                            break;
                        }
                        $clid = getClid($pdo, $data['clid']);
                        $xmlString = $xml->asXML();
                        $trans = createTransaction($pdo, $clid, $clTRID, $xmlString);
                        processDomainTransfer($conn, $pdo, $xml, $data['clid'], $c, $trans);
                        break;
                    }
                    
                    case isset($xml->command->check) && isset($xml->command->check->children('urn:ietf:params:xml:ns:host-1.0')->check):
                    {
                        $data = $table->get($connId);
                        $clTRID = (string) $xml->command->clTRID;
                        if (!$data || $data['logged_in'] !== 1) {
                            sendEppError($conn, $pdo, 2202, 'Authorization error', $clTRID);
                            $conn->close();
                            break;
                        }
                        $clid = getClid($pdo, $data['clid']);
                        $xmlString = $xml->asXML();
                        $trans = createTransaction($pdo, $clid, $clTRID, $xmlString);
                        processHostCheck($conn, $pdo, $xml, $trans);
                        break;
                    }
                    
                    case isset($xml->command->create) && isset($xml->command->create->children('urn:ietf:params:xml:ns:host-1.0')->create):
                    {
                        $data = $table->get($connId);
                        $clTRID = (string) $xml->command->clTRID;
                        if (!$data || $data['logged_in'] !== 1) {
                            sendEppError($conn, $pdo, 2202, 'Authorization error', $clTRID);
                            $conn->close();
                            break;
                        }
                        $clid = getClid($pdo, $data['clid']);
                        $xmlString = $xml->asXML();
                        $trans = createTransaction($pdo, $clid, $clTRID, $xmlString);
                        processHostCreate($conn, $pdo, $xml, $data['clid'], $c['db_type'], $trans);
                        break;
                    }
                    
                    case isset($xml->command->info) && isset($xml->command->info->children('urn:ietf:params:xml:ns:host-1.0')->info):
                    {
                        $data = $table->get($connId);
                        $clTRID = (string) $xml->command->clTRID;
                        if (!$data || $data['logged_in'] !== 1) {
                            sendEppError($conn, $pdo, 2202, 'Authorization error', $clTRID);
                            $conn->close();
                            break;
                        }
                        $clid = getClid($pdo, $data['clid']);
                        $xmlString = $xml->asXML();
                        $trans = createTransaction($pdo, $clid, $clTRID, $xmlString);
                        processHostInfo($conn, $pdo, $xml, $trans);
                        break;
                    }
                    
                    case isset($xml->command->update) && isset($xml->command->update->children('urn:ietf:params:xml:ns:host-1.0')->update):
                    {
                        $data = $table->get($connId);
                        $clTRID = (string) $xml->command->clTRID;
                        if (!$data || $data['logged_in'] !== 1) {
                            sendEppError($conn, $pdo, 2202, 'Authorization error', $clTRID);
                            $conn->close();
                            break;
                        }
                        $clid = getClid($pdo, $data['clid']);
                        $xmlString = $xml->asXML();
                        $trans = createTransaction($pdo, $clid, $clTRID, $xmlString);
                        processHostUpdate($conn, $pdo, $xml, $data['clid'], $c['db_type'], $trans);
                        break;
                    }
                    
                    case isset($xml->command->delete) && isset($xml->command->delete->children('urn:ietf:params:xml:ns:host-1.0')->delete):
                    {
                        $data = $table->get($connId);
                        $clTRID = (string) $xml->command->clTRID;
                        if (!$data || $data['logged_in'] !== 1) {
                            sendEppError($conn, $pdo, 2202, 'Authorization error', $clTRID);
                            $conn->close();
                            break;
                        }
                        $clid = getClid($pdo, $data['clid']);
                        $xmlString = $xml->asXML();
                        $trans = createTransaction($pdo, $clid, $clTRID, $xmlString);
                        processHostDelete($conn, $pdo, $xml, $data['clid'], $c['db_type'], $trans);
                        break;
                    }
                    
                    case isset($xml->command->info) && isset($xml->command->info->children('https://namingo.org/epp/funds-1.0')->info):
                    {
                        $data = $table->get($connId);
                        $clTRID = (string) $xml->command->clTRID;
                        if (!$data || $data['logged_in'] !== 1) {
                            sendEppError($conn, $pdo, 2202, 'Authorization error', $clTRID);
                            $conn->close();
                            break;
                        }
                        $clid = getClid($pdo, $data['clid']);
                        $xmlString = $xml->asXML();
                        $trans = createTransaction($pdo, $clid, $clTRID, $xmlString);
                        processFundsInfo($conn, $pdo, $xml, $data['clid'], $trans);
                        break;
                    }
                    
                    case isset($xml->command->renew) && isset($xml->command->renew->children('urn:ietf:params:xml:ns:domain-1.0')->renew):
                    {
                        $data = $table->get($connId);
                        $clTRID = (string) $xml->command->clTRID;
                        if (!$data || $data['logged_in'] !== 1) {
                            sendEppError($conn, $pdo, 2202, 'Authorization error', $clTRID);
                            $conn->close();
                            break;
                        }
                        $clid = getClid($pdo, $data['clid']);
                        $xmlString = $xml->asXML();
                        $trans = createTransaction($pdo, $clid, $clTRID, $xmlString);
                        processDomainRenew($conn, $pdo, $xml, $data['clid'], $c['db_type'], $trans);
                        break;
                    }
              
                    default:
                    {
                        sendEppError($conn, $pdo, 2101, 'Unimplemented command');
                        break;
                    }
                }
            }
        } catch (PDOException $e) {
            $errorInfo = $e->errorInfo ?? [];
            $sqlState  = $errorInfo[0] ?? 'n/a';
            $driverCode = $errorInfo[1] ?? 'n/a';

            $log->alert('Database error: ' . $e->getMessage() .
                ' | code=' . $e->getCode() .
                ' | sqlstate=' . $sqlState .
                ' | driverCode=' . $driverCode .
                ' | file=' . $e->getFile() . ':' . $e->getLine());

            if (in_array((int)($e->errorInfo[1] ?? 0), [2002, 2003, 2006, 2013, 1047, 1053], true) || str_starts_with((string)($e->errorInfo[0] ?? $e->getCode()), '08')) {
                try {
                    $pdo = null;
                    $pdo = $pool->get();
                    if (!$pdo) {
                        throw new RuntimeException('PDOPool->get() returned null during reconnect');
                    }
                    $pdo->query('SELECT 1');
                    $log->info('Reconnected successfully to the DB');
                    sendEppError($conn, $pdo, 2400, 'Temporary DB error: please retry this command shortly');
                    $conn->close();
                    return;
                } catch (Throwable $e2) {
                    // If reconnect also fails, log and close
                    $log->error('Failed to reconnect to DB: ' . $e2->getMessage());
                    sendEppError($conn, null, 2500, 'Error connecting to the EPP database');
                    $conn->close();
                    $pdo = null;
                    break;
                }
            } else {
                $log->alert('Database error: ' . $e->getMessage());
                sendEppError($conn, $pdo, 2500, 'Internal database error');
                $conn->close();
                break;
            }
        } catch (Throwable $e) {
            // Catch any other exceptions or errors
            $log->error('General Error: ' . $e->getMessage());
            sendEppError($conn, $pdo instanceof PDO ? $pdo : null, 2500, 'General error');
            $conn->close();
            break;
        } finally {
            if ($pdo instanceof PDO) {
                $pool->put($pdo);
            }
        }
    }

    $log->info('client from ' . $clientIP . ' disconnected');
    $conn->close();
});

Swoole\Coroutine::create(function () use ($server) {
    $server->start();
});

Timer::tick(300000, function() use ($pool, $permittedIPsTable) {
    updatePermittedIPs($pool, $permittedIPsTable);
});