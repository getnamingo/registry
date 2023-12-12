<?php

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

use Swoole\Table;
use Swoole\Timer;
use Swoole\Coroutine\Server;
use Swoole\Coroutine\Server\Connection;

$table = new Table(1024);
$table->column('clid', Table::TYPE_STRING, 64);
$table->column('logged_in', Table::TYPE_INT, 1);
$table->create();

$permittedIPsTable = new Table(1024);
$permittedIPsTable->column('addr', Table::TYPE_STRING, 64);
$permittedIPsTable->create();

// Initialize the PDO connection pool
$pool = new Swoole\Database\PDOPool(
    (new Swoole\Database\PDOConfig())
        ->withDriver($c['db_type'])
        ->withHost($c['db_host'])
        ->withPort($c['db_port'])
        ->withDbName($c['db_database'])
        ->withUsername($c['db_username'])
        ->withPassword($c['db_password'])
        ->withCharset('utf8mb4')
);

Swoole\Runtime::enableCoroutine();
$server = new Server($c['epp_host'], $c['epp_port']);
$server->set([
    'enable_coroutine' => true,
    'log_file' => '/var/log/namingo/epp_application.log',
    'log_level' => SWOOLE_LOG_INFO,
    'worker_num' => swoole_cpu_num() * 4,
    'pid_file' => $c['epp_pid'],
    'tcp_user_timeout' => 10,
    'max_request' => 1000,
    'open_tcp_nodelay' => true,
    'max_conn' => 1024,
    'heartbeat_check_interval' => 60,
    'heartbeat_idle_time' => 120,
    'open_ssl' => true,
    'ssl_cert_file' => $c['ssl_cert'],
    'ssl_key_file' => $c['ssl_key'],
    'ssl_verify_peer' => false,
    'ssl_allow_self_signed' => false,
    'ssl_protocols' => SWOOLE_SSL_TLSv1_2 | SWOOLE_SSL_TLSv1_3,
    'ssl_ciphers' => 'ECDHE-RSA-AES256-GCM-SHA384:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-RSA-AES256-SHA384:ECDHE-RSA-AES128-SHA256:DHE-RSA-AES256-GCM-SHA384',
]);
$log->info('Namingo EPP server started');

$server->handle(function (Connection $conn) use ($table, $pool, $c, $log, $permittedIPsTable) {
    // Get the client information
    $clientInfo = $conn->exportSocket()->getpeername();
    $clientIP = $clientInfo['address'] ?? '';

    // Check if the IP is in the permitted list
    if (!$permittedIPsTable->exist($clientIP)) {
        $log->warning('Access denied. The IP address ' . $clientIP . ' is not authorized for this service.');
        $conn->close();
        return;
    }
    
    $log->info('new client from ' . $clientIP . ' connected');
    sendGreeting($conn);
  
    while (true) {
        try {
            // Get a PDO connection from the pool
            $pdo = $pool->get();
            $data = $conn->recv();
            $connId = spl_object_id($conn);

            if ($data === false || strlen($data) < 4) {
                sendEppError($conn, $pdo, 2000, 'Data reception error');
                break;
            }

            $length = unpack('N', substr($data, 0, 4))[1];
            $xmlData = substr($data, 4, $length - 4);

            // If you're using PHP < 8.0
            libxml_disable_entity_loader(true);
            libxml_use_internal_errors(true);

            $xml = simplexml_load_string($xmlData);
            $xml->registerXPathNamespace('e', 'urn:ietf:params:xml:ns:epp-1.0');
            $xml->registerXPathNamespace('xsi', 'http://www.w3.org/2001/XMLSchema-instance');
            $xml->registerXPathNamespace('domain', 'urn:ietf:params:xml:ns:domain-1.0');
            $xml->registerXPathNamespace('contact', 'urn:ietf:params:xml:ns:contact-1.0');
            $xml->registerXPathNamespace('host', 'urn:ietf:params:xml:ns:host-1.0');
            $xml->registerXPathNamespace('rgp', 'urn:ietf:params:xml:ns:rgp-1.0');
            $xml->registerXPathNamespace('secDNS', 'urn:ietf:params:xml:ns:secDNS-1.1');

            if ($xml === false) {
                sendEppError($conn, $pdo, 2001, 'Invalid XML');
                break;
            }
        
            if ($xml->getName() != 'epp') {
                continue;  // Skip this iteration if not an EPP command
            }

            switch (true) {
                case isset($xml->command->login):
                {
                    $clID = (string) $xml->command->login->clID;
                    $pw = (string) $xml->command->login->pw;
                    $clTRID = (string) $xml->command->clTRID;
                    $clid = getClid($pdo, $clID);
                    $xmlString = $xml->asXML();
                    $trans = createTransaction($pdo, $clid, $clTRID, $xmlString);

                    if (checkLogin($pdo, $clID, $pw)) {
                        if (isset($xml->command->login->newPW)) {
                            $newPW = (string) $xml->command->login->newPW;
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
                                sendEppError($conn, $pdo, 2400, 'Password could not be changed', $clTRID);
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
                    $clid = getClid($pdo, $clID);
                    $table->del($connId);
                    $clTRID = (string) $xml->command->clTRID;
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
                    updateTransaction($pdo, 'logout', null, null, 1500, 'Command completed successfully; ending session', $svTRID, $xml, $trans);
                    sendEppResponse($conn, $xml);
                    $conn->close();
                    break;
                }
                
                case isset($xml->hello):
                {
                    sendGreeting($conn);
                    break;
                }
                
                case isset($xml->command->poll):
                {
                    $data = $table->get($connId);
                    $clTRID = (string) $xml->command->clTRID;
                    $clid = getClid($pdo, $data['clid']);
                    $xmlString = $xml->asXML();
                    $trans = createTransaction($pdo, $clid, $clTRID, $xmlString);
                    if (!$data || $data['logged_in'] !== 1) {
                        sendEppError($conn, $pdo, 2202, 'Authorization error', $clTRID);
                        $conn->close();
                    }
                    processPoll($conn, $pdo, $xml, $data['clid'], $trans);
                    break;
                }
          
                case isset($xml->command->check) && isset($xml->command->check->children('urn:ietf:params:xml:ns:contact-1.0')->check):
                {
                    $data = $table->get($connId);
                    $clTRID = (string) $xml->command->clTRID;
                    $clid = getClid($pdo, $data['clid']);
                    $xmlString = $xml->asXML();
                    $trans = createTransaction($pdo, $clid, $clTRID, $xmlString);
                    if (!$data || $data['logged_in'] !== 1) {
                        sendEppError($conn, $pdo, 2202, 'Authorization error', $clTRID);
                        $conn->close();
                    }
                    processContactCheck($conn, $pdo, $xml, $trans);
                    break;
                }
          
                case isset($xml->command->create) && isset($xml->command->create->children('urn:ietf:params:xml:ns:contact-1.0')->create):
                {
                    $data = $table->get($connId);
                    $clTRID = (string) $xml->command->clTRID;
                    $clid = getClid($pdo, $data['clid']);
                    $xmlString = $xml->asXML();
                    $trans = createTransaction($pdo, $clid, $clTRID, $xmlString);
                    if (!$data || $data['logged_in'] !== 1) {
                        sendEppError($conn, $pdo, 2202, 'Authorization error', $clTRID);
                        $conn->close();
                    }
                    processContactCreate($conn, $pdo, $xml, $data['clid'], $c['db_type'], $trans);
                    break;
                }
          
                case isset($xml->command->info) && isset($xml->command->info->children('urn:ietf:params:xml:ns:contact-1.0')->info):
                {
                    $data = $table->get($connId);
                    $clTRID = (string) $xml->command->clTRID;
                    $clid = getClid($pdo, $data['clid']);
                    $xmlString = $xml->asXML();
                    $trans = createTransaction($pdo, $clid, $clTRID, $xmlString);
                    if (!$data || $data['logged_in'] !== 1) {
                        sendEppError($conn, $pdo, 2202, 'Authorization error', $clTRID);
                        $conn->close();
                    }
                    processContactInfo($conn, $pdo, $xml, $trans);
                    break;
                }
                
                case isset($xml->command->update) && isset($xml->command->update->children('urn:ietf:params:xml:ns:contact-1.0')->update):
                {
                    $data = $table->get($connId);
                    $clTRID = (string) $xml->command->clTRID;
                    $clid = getClid($pdo, $data['clid']);
                    $xmlString = $xml->asXML();
                    $trans = createTransaction($pdo, $clid, $clTRID, $xmlString);
                    if (!$data || $data['logged_in'] !== 1) {
                        sendEppError($conn, $pdo, 2202, 'Authorization error', $clTRID);
                        $conn->close();
                    }
                    processContactUpdate($conn, $pdo, $xml, $data['clid'], $c['db_type'], $trans);
                    break;
                }
                
                case isset($xml->command->delete) && isset($xml->command->delete->children('urn:ietf:params:xml:ns:contact-1.0')->delete):
                {
                    $data = $table->get($connId);
                    $clTRID = (string) $xml->command->clTRID;
                    $clid = getClid($pdo, $data['clid']);
                    $xmlString = $xml->asXML();
                    $trans = createTransaction($pdo, $clid, $clTRID, $xmlString);
                    if (!$data || $data['logged_in'] !== 1) {
                        sendEppError($conn, $pdo, 2202, 'Authorization error', $clTRID);
                        $conn->close();
                    }
                    processContactDelete($conn, $pdo, $xml, $data['clid'], $c['db_type'], $trans);
                    break;
                }
                
                case isset($xml->command->transfer) && isset($xml->command->transfer->children('urn:ietf:params:xml:ns:contact-1.0')->transfer):
                {
                    $data = $table->get($connId);
                    $clTRID = (string) $xml->command->clTRID;
                    $clid = getClid($pdo, $data['clid']);
                    $xmlString = $xml->asXML();
                    $trans = createTransaction($pdo, $clid, $clTRID, $xmlString);
                    if (!$data || $data['logged_in'] !== 1) {
                        sendEppError($conn, $pdo, 2202, 'Authorization error', $clTRID);
                        $conn->close();
                    }
                    processContactTransfer($conn, $pdo, $xml, $data['clid'], $c['db_type'], $trans);
                    break;
                }
            
                case isset($xml->command->check) && isset($xml->command->check->children('urn:ietf:params:xml:ns:domain-1.0')->check):
                {
                    $data = $table->get($connId);
                    $clTRID = (string) $xml->command->clTRID;
                    $clid = getClid($pdo, $data['clid']);
                    $xmlString = $xml->asXML();
                    $trans = createTransaction($pdo, $clid, $clTRID, $xmlString);
                    if (!$data || $data['logged_in'] !== 1) {
                        sendEppError($conn, $pdo, 2202, 'Authorization error', $clTRID);
                        $conn->close();
                    }
                    processDomainCheck($conn, $pdo, $xml, $trans);
                    break;
                }

                case isset($xml->command->info) && isset($xml->command->info->children('urn:ietf:params:xml:ns:domain-1.0')->info):
                {
                    $data = $table->get($connId);
                    $clTRID = (string) $xml->command->clTRID;
                    $clid = getClid($pdo, $data['clid']);
                    $xmlString = $xml->asXML();
                    $trans = createTransaction($pdo, $clid, $clTRID, $xmlString);
                    if (!$data || $data['logged_in'] !== 1) {
                        sendEppError($conn, $pdo, 2202, 'Authorization error', $clTRID);
                        $conn->close();
                    }
                    processDomainInfo($conn, $pdo, $xml, $trans);
                    break;
                }
                
                case isset($xml->command->update) && isset($xml->command->update->children('urn:ietf:params:xml:ns:domain-1.0')->update):
                {
                    $data = $table->get($connId);
                    $clTRID = (string) $xml->command->clTRID;
                    $clid = getClid($pdo, $data['clid']);
                    $xmlString = $xml->asXML();
                    $trans = createTransaction($pdo, $clid, $clTRID, $xmlString);
                    if (!$data || $data['logged_in'] !== 1) {
                        sendEppError($conn, $pdo, 2202, 'Authorization error', $clTRID);
                        $conn->close();
                    }
                    processDomainUpdate($conn, $pdo, $xml, $data['clid'], $c['db_type'], $trans);
                    break;
                }
                
                case isset($xml->command->create) && isset($xml->command->create->children('urn:ietf:params:xml:ns:domain-1.0')->create):
                {
                    $data = $table->get($connId);
                    $clTRID = (string) $xml->command->clTRID;
                    $clid = getClid($pdo, $data['clid']);
                    $xmlString = $xml->asXML();
                    $trans = createTransaction($pdo, $clid, $clTRID, $xmlString);
                    if (!$data || $data['logged_in'] !== 1) {
                        sendEppError($conn, $pdo, 2202, 'Authorization error', $clTRID);
                        $conn->close();
                    }
                    processDomainCreate($conn, $pdo, $xml, $data['clid'], $c['db_type'], $trans);
                    break;
                }
                
                case isset($xml->command->delete) && isset($xml->command->delete->children('urn:ietf:params:xml:ns:domain-1.0')->delete):
                {
                    $data = $table->get($connId);
                    $clTRID = (string) $xml->command->clTRID;
                    $clid = getClid($pdo, $data['clid']);
                    $xmlString = $xml->asXML();
                    $trans = createTransaction($pdo, $clid, $clTRID, $xmlString);
                    if (!$data || $data['logged_in'] !== 1) {
                        sendEppError($conn, $pdo, 2202, 'Authorization error', $clTRID);
                        $conn->close();
                    }
                    processDomainDelete($conn, $pdo, $xml, $data['clid'], $c['db_type'], $trans);
                    break;
                }
                
                case isset($xml->command->transfer) && isset($xml->command->transfer->children('urn:ietf:params:xml:ns:domain-1.0')->transfer):
                {
                    $data = $table->get($connId);
                    $clTRID = (string) $xml->command->clTRID;
                    $clid = getClid($pdo, $data['clid']);
                    $xmlString = $xml->asXML();
                    $trans = createTransaction($pdo, $clid, $clTRID, $xmlString);
                    if (!$data || $data['logged_in'] !== 1) {
                        sendEppError($conn, $pdo, 2202, 'Authorization error', $clTRID);
                        $conn->close();
                    }
                    processDomainTransfer($conn, $pdo, $xml, $data['clid'], $c['db_type'], $trans);
                    break;
                }
                
                case isset($xml->command->check) && isset($xml->command->check->children('urn:ietf:params:xml:ns:host-1.0')->check):
                {
                    $data = $table->get($connId);
                    $clTRID = (string) $xml->command->clTRID;
                    $clid = getClid($pdo, $data['clid']);
                    $xmlString = $xml->asXML();
                    $trans = createTransaction($pdo, $clid, $clTRID, $xmlString);
                    if (!$data || $data['logged_in'] !== 1) {
                        sendEppError($conn, $pdo, 2202, 'Authorization error', $clTRID);
                        $conn->close();
                    }
                    processHostCheck($conn, $pdo, $xml, $trans);
                    break;
                }
                
                case isset($xml->command->create) && isset($xml->command->create->children('urn:ietf:params:xml:ns:host-1.0')->create):
                {
                    $data = $table->get($connId);
                    $clTRID = (string) $xml->command->clTRID;
                    $clid = getClid($pdo, $data['clid']);
                    $xmlString = $xml->asXML();
                    $trans = createTransaction($pdo, $clid, $clTRID, $xmlString);
                    if (!$data || $data['logged_in'] !== 1) {
                        sendEppError($conn, $pdo, 2202, 'Authorization error', $clTRID);
                        $conn->close();
                    }
                    processHostCreate($conn, $pdo, $xml, $data['clid'], $c['db_type'], $trans);
                    break;
                }
                
                case isset($xml->command->info) && isset($xml->command->info->children('urn:ietf:params:xml:ns:host-1.0')->info):
                {
                    $data = $table->get($connId);
                    $clTRID = (string) $xml->command->clTRID;
                    $clid = getClid($pdo, $data['clid']);
                    $xmlString = $xml->asXML();
                    $trans = createTransaction($pdo, $clid, $clTRID, $xmlString);
                    if (!$data || $data['logged_in'] !== 1) {
                        sendEppError($conn, $pdo, 2202, 'Authorization error', $clTRID);
                        $conn->close();
                    }
                    processHostInfo($conn, $pdo, $xml, $trans);
                    break;
                }
                
                case isset($xml->command->update) && isset($xml->command->update->children('urn:ietf:params:xml:ns:host-1.0')->update):
                {
                    $data = $table->get($connId);
                    $clTRID = (string) $xml->command->clTRID;
                    $clid = getClid($pdo, $data['clid']);
                    $xmlString = $xml->asXML();
                    $trans = createTransaction($pdo, $clid, $clTRID, $xmlString);
                    if (!$data || $data['logged_in'] !== 1) {
                        sendEppError($conn, $pdo, 2202, 'Authorization error', $clTRID);
                        $conn->close();
                    }
                    processHostUpdate($conn, $pdo, $xml, $data['clid'], $c['db_type'], $trans);
                    break;
                }
                
                case isset($xml->command->delete) && isset($xml->command->delete->children('urn:ietf:params:xml:ns:host-1.0')->delete):
                {
                    $data = $table->get($connId);
                    $clTRID = (string) $xml->command->clTRID;
                    $clid = getClid($pdo, $data['clid']);
                    $xmlString = $xml->asXML();
                    $trans = createTransaction($pdo, $clid, $clTRID, $xmlString);
                    if (!$data || $data['logged_in'] !== 1) {
                        sendEppError($conn, $pdo, 2202, 'Authorization error', $clTRID);
                        $conn->close();
                    }
                    processHostDelete($conn, $pdo, $xml, $data['clid'], $c['db_type'], $trans);
                    break;
                }
                
                case isset($xml->command->info) && isset($xml->command->info->children('https://namingo.org/epp/funds-1.0')->info):
                {
                    $data = $table->get($connId);
                    $clTRID = (string) $xml->command->clTRID;
                    $clid = getClid($pdo, $data['clid']);
                    $xmlString = $xml->asXML();
                    $trans = createTransaction($pdo, $clid, $clTRID, $xmlString);
                    if (!$data || $data['logged_in'] !== 1) {
                        sendEppError($conn, $pdo, 2202, 'Authorization error', $clTRID);
                        $conn->close();
                    }
                    processFundsInfo($conn, $pdo, $xml, $data['clid'], $trans);
                    break;
                }
                
                case isset($xml->command->renew) && isset($xml->command->renew->children('urn:ietf:params:xml:ns:domain-1.0')->renew):
                {
                    $data = $table->get($connId);
                    $clTRID = (string) $xml->command->clTRID;
                    $clid = getClid($pdo, $data['clid']);
                    $xmlString = $xml->asXML();
                    $trans = createTransaction($pdo, $clid, $clTRID, $xmlString);
                    if (!$data || $data['logged_in'] !== 1) {
                        sendEppError($conn, $pdo, 2202, 'Authorization error', $clTRID);
                        $conn->close();
                    }
                    processDomainRenew($conn, $pdo, $xml, $data['clid'], $c['db_type'], $trans);
                    break;
                }
          
                default:
                {
                    sendEppError($conn, $pdo, 2000, 'Unrecognized command');
                    break;
                }
            }
        } catch (PDOException $e) {
            // Handle database exceptions
            $log->error('Database error: ' . $e->getMessage());
            sendEppError($conn, $pdo, 2500, 'Error connecting to the EPP database');
            $conn->close();
        } catch (Throwable $e) {
            // Catch any other exceptions or errors
            $log->error('Error: ' . $e->getMessage());
            sendEppError($conn, $pdo, 2500, 'General error');
            $conn->close();
        } finally {
            // Return the connection to the pool
            $pool->put($pdo);
        }
    }

    sendEppError($conn, $pdo, 2000, 'Unrecognized command');
    $log->info('client from ' . $clientIP . ' disconnected');
    $conn->close();
});

Swoole\Coroutine::create(function () use ($pool, $permittedIPsTable) {
    updatePermittedIPs($pool, $permittedIPsTable);
});

Swoole\Coroutine::create(function () use ($server) {
    $server->start();
});

// Set a timer to update permitted IPs every 5 minutes (300000 milliseconds)
Timer::tick(300000, function() use ($pool, $permittedIPsTable) {
    updatePermittedIPs($pool, $permittedIPsTable);
});