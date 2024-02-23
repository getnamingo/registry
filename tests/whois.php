<?php
// Namingo WHOIS QA Testing Tool

class WhoisTest {
    private $whoisServer = "whois.example.com"; // Replace with actual WHOIS server

    public function runTests() {
        $this->testBasicQueries();
        $this->testEdgeCases();
        $this->testSecurityCases();
        $this->testResponseTime();
        $this->testDataAccuracy();
        $this->testInvalidQueries();
        $this->testIDNSupport();
        $this->testRateLimiting();
        $this->testComplianceWithStandards();
    }

    private function testBasicQueries() {
        echo "Running Basic Queries Test...\n";
        // List of domains to test
        $domains = ['test.test', 'example.test', 'example.org'];

        foreach ($domains as $domain) {
            $result = $this->queryWhoisServer($domain);
            if ($result && (strpos($result, 'NOT FOUND') !== false || strpos($result, 'Invalid TLD') === 0 || strpos($result, 'Domain Name:') === 0)) {
                echo "Test: $domain - PASS\n";
            } else {
                echo "Test: $domain - FAIL\n";
            }
        }
    }

    private function testEdgeCases() {
        echo "Running Edge Cases Test...\n";
        // Test non-existing domains, special characters, long domain names
        $edgeCaseDomains = ['thisdomaindoesnotexist123456.test', 'example-.test', str_repeat('a', 75) . '.test'];

        foreach ($edgeCaseDomains as $domain) {
            $result = $this->queryWhoisServer($domain);
            // In edge cases, we expect failures or specific responses
            if ($result && (strpos($result, 'NOT FOUND') !== false || strpos($result, 'Domain name invalid IDN characters') === 0 || strpos($result, 'domain name is too long') === 0)) {
                echo "Test: $domain - PASS\n";
            } else {
                echo "Test: $domain - FAIL\n";
            }
        }
    }

    private function testSecurityCases() {
        echo "Running Security Cases Test...\n";
        // Test with potential security risk inputs
        $securityTestDomains = [
            "; DROP TABLE domains;", // SQL Injection
            "\"><script>alert(1)</script>", // Basic XSS
            "' OR '1'='1", // SQL Injection Variant
            "| rm -rf /", // Command Injection
            str_repeat("A", 10000), // Buffer Overflow
            "<svg/onload=alert(1)>", // XSS with HTML5
            "<!--#exec cmd=\"/bin/echo 'Vulnerable'\" -->", // Server Side Includes (SSI) Injection
            "(|(uid=*)(cn=*))", // LDAP Injection
            "../etc/passwd", // Path Traversal
            "<foo><![CDATA[<]]><bar>]]>baz</bar>", // XML Injection
        ];

        foreach ($securityTestDomains as $domain) {
            $result = $this->queryWhoisServer($domain);
            if ($result && (strpos($result, 'domain name invalid format') !== false || strpos($result, 'domain name is too long') === 0)) {
                echo "Test: $domain - PASS\n";
            } else {
                echo "Test: $domain - FAIL\n";
            }
        }
    }
    
    private function testResponseTime() {
        echo "Testing Response Time...\n";
        $domains = ['example.test', 'test.test', 'example.org']; // Multiple domains for a broader test
        $testCount = 5; // Number of tests per domain
        $totalDuration = 0;

        foreach ($domains as $domain) {
            $domainDurations = [];

            for ($i = 0; $i < $testCount; $i++) {
                $startTime = microtime(true);
                $this->queryWhoisServer($domain);
                $endTime = microtime(true);
                $duration = $endTime - $startTime;
                $domainDurations[] = $duration;
                $totalDuration += $duration;
            }

            $averageDuration = array_sum($domainDurations) / count($domainDurations);
            $variance = $this->calculateVariance($domainDurations, $averageDuration);

            echo "Average response time for $domain: " . number_format($averageDuration, 3) . " seconds\n";
            echo "Variance in response time for $domain: " . number_format($variance, 3) . "\n";
        }

        $overallAverage = $totalDuration / ($testCount * count($domains));
        echo "Overall average response time: " . number_format($overallAverage, 3) . " seconds\n";
    }

    private function testDataAccuracy() {
        echo "Testing Data Accuracy...\n";
        $testDomain = 'test.test'; // Use a domain whose WHOIS info you know
        $expectedRegistrar = 'LeoNet LLC'; // Replace with known data
        $result = $this->queryWhoisServer($testDomain);
        if (strpos($result, $expectedRegistrar) !== false) {
            echo "Test: $testDomain - PASS\n";
        } else {
            echo "Test: $testDomain - FAIL\n";
        }
    }

    private function testInvalidQueries() {
        echo "Testing Handling of Invalid Queries...\n";
        $invalidDomains = ['this is not a domain', '', '1234567890', 'invalid_domain.com'];
        foreach ($invalidDomains as $domain) {
            $result = $this->queryWhoisServer($domain);
            if ($result && (strpos($result, 'domain name invalid format') !== false || strpos($result, 'please enter a domain name') === 0)) {
                echo "Test: $domain - PASS\n";
            } else {
                echo "Test: $domain - FAIL\n";
            }
        }
    }

    private function testIDNSupport() {
        echo "Testing International Domain Name (IDN) Support...\n";
        $idnDomain = 'xn--exmple-cua.com'; // Punycode for an IDN domain
        $result = $this->queryWhoisServer($idnDomain);
        if ($result) {
            echo "Test: $idnDomain - PASS\n";
        } else {
            echo "Test: $idnDomain - FAIL\n";
        }
    }
    
    private function testRateLimiting() {
        echo "Testing Rate Limiting...\n";
        $domain = 'example.com';
        $successCount = 0;
        $testCount = 10; // Number of requests to simulate rate limiting

        for ($i = 0; $i < $testCount; $i++) {
            if ($this->queryWhoisServer($domain)) {
                $successCount++;
            }
            sleep(1); // Sleep to avoid hitting the server too rapidly
        }

        if ($successCount < $testCount) {
            echo "Test: Rate limiting - PASS. Number of successful queries: $successCount\n";
        } else {
            echo "Test: Rate limiting - FAIL\n";
        }
    }

    private function testComplianceWithStandards() {
        echo "Testing Compliance with WHOIS Protocol Standards...\n";
        $domain = 'test.test';
        $result = $this->queryWhoisServer($domain);

        // Check for a standard response format (this will depend on the specific standard)
        if (strpos($result, 'Domain Name:') !== false && strpos($result, 'Registrar:') !== false) {
            echo "Test: $domain - PASS\n";
        } else {
            echo "Test: $domain - FAIL\n";
        }
    }
    
    private function queryWhoisServer($domain) {
        $server = $this->whoisServer; // WHOIS server
        $port = 43; // Standard WHOIS port

        // Open a connection to the WHOIS server
        $connection = fsockopen($server, $port);
        if (!$connection) {
            return false; // Connection failed
        }

        // Send the query
        fputs($connection, "$domain\r\n");

        // Read and store the response
        $response = '';
        while (!feof($connection)) {
            $response .= fgets($connection, 128);
        }

        // Close the connection
        fclose($connection);

        // Return the response
        return $response;
    }
    
    private function calculateVariance($durations, $mean) {
        $sumOfSquares = 0;
        foreach ($durations as $duration) {
            $sumOfSquares += pow(($duration - $mean), 2);
        }
        return $sumOfSquares / count($durations);
    }

}

$whoisTest = new WhoisTest();
$whoisTest->runTests();