<?php
// Namingo DAS QA Testing Tool

$server = 'your_das_server_address';
$port = 1043;

/**
 * Sends a domain request to the DAS server and returns the response.
 */
function sendRequest($server, $port, $domain) {
    $timeout = 10;
    $errorNumber = 0;
    $errorMessage = '';

    // Open the socket
    $socket = @stream_socket_client("tcp://$server:$port", $errorNumber, $errorMessage, $timeout);

    if (!$socket) {
        echo "Error: $errorMessage ($errorNumber)\n";
        return null;
    }

    // Send the domain request
    fwrite($socket, $domain . "\r\n"); // Use carriage return and newline

    // Wait for the response
    $response = '';
    while (!feof($socket)) {
        $response .= fread($socket, 8192);
    }

    fclose($socket);

    return trim($response);
}

/**
 * Tests a domain request against expected response.
 */
function testDomainRequest($domain, $expected, $message) {
    global $server, $port;
    $response = sendRequest($server, $port, $domain);

    if ($response === $expected) {
        echo "PASS: $message\n";
    } else {
        echo "FAIL: $message (Expected: $expected, Got: $response)\n";
    }
}

// Basic Test Cases
testDomainRequest('test.test', '1', 'Valid domain name');
testDomainRequest('nonexistentdomain.test', '0', 'Non-existent domain');
testDomainRequest('hyphen-domain.test', '0', 'Hyphenated domain name');

// Edge Cases
testDomainRequest('', '2', 'Empty domain name');
testDomainRequest('12345.test', '0', 'Numerical domain name');
testDomainRequest('test', '2', 'Top-level domain only');
testDomainRequest('!@#$%^&*', '2', 'Special characters in domain');
testDomainRequest('a.test', '0', 'Single character domain name');
testDomainRequest('verylongdomainnametestingxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx.test', '2', 'Unusually long domain name');

// Security Cases
testDomainRequest('example.test; DROP TABLE domains;', '2', 'SQL Injection attempt');
testDomainRequest(str_repeat('A', 5000), '2', 'Buffer Overflow attempt');
testDomainRequest('<script>alert("XSS")</script>.test', '2', 'XSS Injection attempt');
testDomainRequest('`; ls -la`.test', '2', 'Command Injection attempt');
testDomainRequest('测试.test', '2', 'Unicode characters in domain');
testDomainRequest(' test .test', '2', 'Domain with leading whitespace');
testDomainRequest('test .test', '2', 'Domain with trailing whitespace');