<?php

//require 'vendor/autoload.php';
global $c;
$c = require_once 'config.php';
require_once 'EppWriter.php';
require_once 'helpers.php';
require_once 'epp-check.php';
require_once 'epp-info.php';
require_once 'epp-renew.php';

use Swoole\Coroutine\Server;
use Swoole\Coroutine\Server\Connection;
use Swoole\Table;

$table = new Table(1024);
$table->column('clid', Table::TYPE_STRING, 64);
$table->column('logged_in', Table::TYPE_INT, 1);
$table->create();

$dsn = "{$c['db_type']}:host={$c['db_host']};dbname={$c['db_database']};port={$c['db_port']}";
$db = new PDO($dsn, $c['db_username'], $c['db_password']);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

Swoole\Runtime::enableCoroutine();
$server = new Server($c['epp_host'], $c['epp_port']);
$server->set([
    'enable_coroutine' => true,
    'log_file' => '/var/log/epp/epp.log',
    'log_level' => SWOOLE_LOG_INFO,
    'worker_num' => swoole_cpu_num() * 4,
    'pid_file' => $c['epp_pid'],
    'tcp_user_timeout' => 10,
    'max_request' => 1000,
    'open_tcp_nodelay' => true,
    'max_conn' => 10000,
    'heartbeat_check_interval' => 60,
    'heartbeat_idle_time' => 120,
    'open_ssl' => true,
    'ssl_cert_file' => $c['ssl_cert'],
    'ssl_key_file' => $c['ssl_key'],
    'ssl_verify_peer' => false,
    'ssl_allow_self_signed' => false,
    'ssl_protocols' => SWOOLE_SSL_TLSv1_2 | SWOOLE_SSL_TLSv1_3,
]);

$server->handle(function (Connection $conn) use ($table, $db, $c) {
    echo "Client connected.\n";
    sendGreeting($conn);
  
    while (true) {
        $data = $conn->recv();
        $connId = spl_object_id($conn);

        if ($data === false || strlen($data) < 4) {
            sendEppError($conn, 2100, 'Data reception error');
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

        if ($xml === false) {
            sendEppError($conn, 2001, 'Invalid XML');
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

                if (checkLogin($db, $clID, $pw)) {
                    $table->set($connId, ['clid' => $clID, 'logged_in' => 1]);
                    $response = [
                        'command' => 'login',
                        'resultCode' => 1000,
                        'lang' => 'en-US',
                        'message' => 'Login successful',
                        'clTRID' => $clTRID,
                        'svTRID' => generateSvTRID(),
                    ];

                    $epp = new EPP\EppWriter();
                    $xml = $epp->epp_writer($response);
                    sendEppResponse($conn, $xml);
                } else {
                    sendEppError($conn, 2200, 'Authentication error');
                }
                break;
            }
      
            case isset($xml->command->logout):
            {
                $table->del($connId);
				$clTRID = (string) $xml->command->clTRID;
				
                $response = [
                    'command' => 'logout',
                    'resultCode' => 1500,
                    'lang' => 'en-US',
                    'clTRID' => $clTRID,
                    'svTRID' => generateSvTRID(),
                ];

                $epp = new EPP\EppWriter();
                $xml = $epp->epp_writer($response);
                sendEppResponse($conn, $xml);
                $conn->close();
                break;
            }
			
            case isset($xml->hello):
            {
                sendGreeting($conn);
                break;
            }
      
            case isset($xml->command->check) && isset($xml->command->check->children('urn:ietf:params:xml:ns:contact-1.0')->check):
            {
                $data = $table->get($connId);
                if (!$data || $data['logged_in'] !== 1) {
                    sendEppError($conn, 2202, 'Authorization error');
                    $conn->close();
                }
                processContactCheck($conn, $db, $xml);
                break;
            }
      
            case isset($xml->command->create) && isset($xml->command->create->children('urn:ietf:params:xml:ns:contact-1.0')->create):
            {
                $data = $table->get($connId);
                if (!$data || $data['logged_in'] !== 1) {
                    sendEppError($conn, 2202, 'Authorization error');
                    $conn->close();
                }
                processContactCreate($conn, $db, $xml);
                break;
            }
      
            case isset($xml->command->info) && isset($xml->command->info->children('urn:ietf:params:xml:ns:contact-1.0')->info):
            {
                $data = $table->get($connId);
                if (!$data || $data['logged_in'] !== 1) {
                    sendEppError($conn, 2202, 'Authorization error');
                    $conn->close();
                }
                processContactInfo($conn, $db, $xml);
                break;
            }
        
            case isset($xml->command->check) && isset($xml->command->check->children('urn:ietf:params:xml:ns:domain-1.0')->check):
            {
                $data = $table->get($connId);
                if (!$data || $data['logged_in'] !== 1) {
                    sendEppError($conn, 2202, 'Authorization error');
                    $conn->close();
                }
                processDomainCheck($conn, $db, $xml);
                break;
            }

            case isset($xml->command->info) && isset($xml->command->info->children('urn:ietf:params:xml:ns:domain-1.0')->info):
            {
                $data = $table->get($connId);
                if (!$data || $data['logged_in'] !== 1) {
                    sendEppError($conn, 2202, 'Authorization error');
                    $conn->close();
                }
                processDomainInfo($conn, $db, $xml);
                break;
            }
			
            case isset($xml->command->check) && isset($xml->command->check->children('urn:ietf:params:xml:ns:host-1.0')->check):
            {
                $data = $table->get($connId);
                if (!$data || $data['logged_in'] !== 1) {
                    sendEppError($conn, 2202, 'Authorization error');
                    $conn->close();
                }
                processHostCheck($conn, $db, $xml);
                break;
            }
			
            case isset($xml->command->info) && isset($xml->command->info->children('urn:ietf:params:xml:ns:host-1.0')->info):
            {
                $data = $table->get($connId);
                if (!$data || $data['logged_in'] !== 1) {
                    sendEppError($conn, 2202, 'Authorization error');
                    $conn->close();
                }
                processHostInfo($conn, $db, $xml);
                break;
            }
			
            case isset($xml->command->info) && isset($xml->command->info->children('https://namingo.org/epp/funds-1.0')->info):
            {
                $data = $table->get($connId);
                if (!$data || $data['logged_in'] !== 1) {
                    sendEppError($conn, 2202, 'Authorization error');
                    $conn->close();
                }
                processFundsInfo($conn, $db, $xml, $data['clid']);
                break;
            }
			
            case isset($xml->command->renew) && isset($xml->command->renew->children('urn:ietf:params:xml:ns:domain-1.0')->renew):
            {
                $data = $table->get($connId);
                if (!$data || $data['logged_in'] !== 1) {
                    sendEppError($conn, 2202, 'Authorization error');
                    $conn->close();
                }
                processDomainRenew($conn, $db, $xml, $data['clid'], $c['db_type']);
                break;
            }
      
            default:
            {
                sendEppError($conn, 2102, 'Unrecognized command');
                break;
            }
        }
    }

    sendEppError($conn, 2100, 'Unknown command');
    echo "Client disconnected.\n";
});

echo "Namingo EPP server started.\n";
Swoole\Coroutine::create(function () use ($server) {
    $server->start();
});

function processContactCreate($conn, $db, $xml) {
    if (!isset($xml->command->create->{'contact:create'})) {
        sendEppError($conn, 2005, 'Syntax error');
        return;
    }

    $contactCreate = $xml->command->create->{'contact:create'};
    $contactID = (string) $contactCreate->{'contact:id'};
    $postalInfo = $contactCreate->{'contact:postalInfo'};
    $email = (string) $contactCreate->{'contact:email'};
    $voice = (string) $contactCreate->{'contact:voice'};
    $fax = (string) $contactCreate->{'contact:fax'};
    $password = (string) $contactCreate->{'contact:authInfo'}->{'contact:pw'};

    $name = (string) $postalInfo->{'contact:name'};
    $org = (string) $postalInfo->{'contact:org'};
    $addr = $postalInfo->{'contact:addr'};
    $street = (string) $addr->{'contact:street'};
    $city = (string) $addr->{'contact:city'};
    $sp = (string) $addr->{'contact:sp'};
    $pc = (string) $addr->{'contact:pc'};
    $cc = (string) $addr->{'contact:cc'};

    try {
        $stmt = $db->prepare("INSERT INTO contacts (id, name, org, street, city, state_province, postal_code, country_code, email, voice, fax, password) VALUES (:id, :name, :org, :street, :city, :sp, :pc, :cc, :email, :voice, :fax, :password)");
        $stmt->execute([
            'id' => $contactID,
            'name' => $name,
            'org' => $org,
            'street' => $street,
            'city' => $city,
            'sp' => $sp,
            'pc' => $pc,
            'cc' => $cc,
            'email' => $email,
            'voice' => $voice,
            'fax' => $fax,
            'password' => password_hash($password, PASSWORD_DEFAULT)
        ]);

        $response = <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
  <response>
    <result code="1000">
      <msg>Contact created successfully</msg>
    </result>
  </response>
</epp>
XML;

        $length = strlen($response) + 4; // Total length including the 4-byte header
        $lengthData = pack('N', $length); // Pack the length into 4 bytes

        $conn->send($lengthData . $response);
    } catch (PDOException $e) {
        sendEppError($conn, 2400, 'Database error');
    }
}