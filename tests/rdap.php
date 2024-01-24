<?php
// Namingo RDAP QA Testing Tool

// Configuration
$rdapServer = "https://example-rdap-server.com";

// Test Cases
$testCases = [
    // Basic Cases
    ["type" => "domain", "query" => "example.test", "expected" => "Not Found"],
    ["type" => "nameserver", "query" => "ns1.example.test", "expected" => "Not Found"],
    ["type" => "domain", "query" => "test.test", "expected" => "domain"],

    // Edge Cases
    ["type" => "domain", "query" => "", "expected" => "Not Found"],
    ["type" => "nameserver", "query" => "ns1.exa#mple.test", "expected" => "Nameserver invalid format"],
    ["type" => "domain", "query" => "verylongdomainnametestingxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx.test", "expected" => "Domain name is too long"],
    ["type" => "domain", "query" => "special!@#$.test", "expected" => "Domain name invalid format"],

    // Security Cases
    ["type" => "domain", "query" => "example.test; DROP TABLE users;", "expected" => "Domain name invalid format"],
    ["type" => "domain", "query" => "<script>alert('XSS')</script>", "expected" => "Domain name invalid format"],
    ["type" => "domain", "query" => "; ls -la", "expected" => "Domain name invalid format"],
    ["type" => "domain", "query" => str_repeat("A", 10000), "expected" => "Domain name is too long"],

    // Protocol and Compliance Cases
    ["type" => "invalidpath", "query" => "example.test", "expected" => "Endpoint not found"],

    // Response and Format Cases
    ["type" => "domain", "query" => "utf8testé.test", "expected" => "Domain name invalid IDN characters"],
];

// Function to send RDAP request
function sendRdapRequest($server, $type, $query) {
    $url = $server . "/" . $type . "/" . urlencode($query);
    $ch = curl_init($url);

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, 0);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    $response = curl_exec($ch);

    // Check for cURL errors
    if (curl_errno($ch)) {
        throw new Exception('cURL error: ' . curl_error($ch));
    }

    curl_close($ch);
    return $response;
}

// Function to validate response
function validateResponse($response, $expected) {
    $decodedResponse = json_decode($response, true);

    // Basic validation
    if (isset($decodedResponse['objectClassName']) && $decodedResponse['objectClassName'] === $expected) {
        return true;
    } else if (isset($decodedResponse['title']) && $decodedResponse['title'] === $expected) {
        return true;
    } else if (isset($decodedResponse['error']) && $decodedResponse['error'] === $expected) {
        return true;
    }

    return false;
}

// Main testing loop
foreach ($testCases as $testCase) {
    $response = sendRdapRequest($rdapServer, $testCase['type'], $testCase['query']);
    $isValid = validateResponse($response, $testCase['expected']);
    // Logging and Reporting
    echo "Test: " . $testCase['query'] . " - " . ($isValid ? "PASS" : "FAIL") . "\n";
}