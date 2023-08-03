<?php

require 'vendor/autoload.php';

use Swoole\Coroutine\Server;
use Swoole\Coroutine\Server\Connection;
use Swoole\Table;
use PDO;

$table = new Table(1024);
$table->column('logged_in', Table::TYPE_INT, 1);
$table->create();

$db = new PDO('mysql:host=localhost;dbname=epp', 'username', 'password');

$server = new Server('0.0.0.0', 700);

$server->handle(function (Connection $conn) use ($table, $db) {
    $data = $conn->recv();
    $xml = simplexml_load_string($data);

    if ($xml === false) {
        sendEppError($conn, 2001, 'Invalid XML');
        return;
    }

    $clID = (string) $xml->command->clTRID;
    $isLoggedIn = $table->get($clID, 'logged_in');

    // Parsing a login command
    if ($xml->getName() == 'epp' && isset($xml->command->login)) {
        $clID = (string) $xml->command->login->clID;
        $pw = (string) $xml->command->login->pw;

        if (checkLogin($db, $clID, $pw)) {
            $table->set($clID, ['logged_in' => 1]);
            $conn->send('Login success!');
        } else {
            sendEppError($conn, 2200, 'Authentication error');
        }
        return;
    }

    // Parsing a logout command
    if ($xml->getName() == 'epp' && isset($xml->command->logout)) {
        $table->del($clID);
        $conn->send('Logout success!');
        return;
    }

    if (!$isLoggedIn) {
        sendEppError($conn, 2202, 'Authorization error');
        return;
    }

    // Parsing a domain:check command
    if ($xml->getName() == 'epp' && isset($xml->command->{'check'}->{'domain:check'})) {
        processDomainCheck($conn, $db, $xml);
        return;
    }

    sendEppError($conn, 2100, 'Unknown command');
});

$server->start();

function processDomainCheck($conn, $db, $xml) {
    $domains = $xml->command->{'check'}->{'domain:check'}->children('domain', true);
    $response = '<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><response><result code="1000"><msg>Command completed successfully</msg></result><resData><domain:chkData xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">';

    foreach ($domains as $domain) {
        $domainName = (string) $domain;
        $availability = $db->query("SELECT availability FROM domains WHERE domain_name = '$domainName'")->fetchColumn();
        $availString = $availability ? 'available' : 'unavailable';
        $response .= "<domain:cd><domain:name avail=\"$availability\">$domainName</domain:name></domain:cd>";
    }

    $response .= '</domain:chkData></resData></response></epp>';
    $conn->send($response);
}

function checkLogin($db, $clID, $pw) {
    $stmt = $db->prepare("SELECT password FROM users WHERE username = :username");
    $stmt->execute(['username' => $clID]);
    $hashedPassword = $stmt->fetchColumn();

    return password_verify($pw, $hashedPassword);
}

function sendEppError($conn, $code, $msg) {
    $errorResponse = <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
  <response>
    <result code="$code">
      <msg>$msg</msg>
    </result>
  </response>
</epp>
XML;

    $conn->send($errorResponse);
}