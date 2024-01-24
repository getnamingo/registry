<?php
class EppClient {
    private $connection;
    private $server;
    private $port;
    private $sslCert;
    private $sslKey;

    public function __construct($server, $port, $sslCert = null, $sslKey = null) {
        $this->server = $server;
        $this->port = $port;
        $this->sslCert = $sslCert;
        $this->sslKey = $sslKey;
    }

    public function connect() {
        $contextOptions = [
            'ssl' => [
                'local_cert' => $this->sslCert,
                'local_pk' => $this->sslKey,
                'allow_self_signed' => true, // Set to false in production
                'verify_peer' => false, // Set to true in production
                'verify_peer_name' => false, // Set to true in production
            ],
        ];
        $context = stream_context_create($contextOptions);
        $this->connection = stream_socket_client("ssl://{$this->server}:{$this->port}", $errno, $errstr, 30, STREAM_CLIENT_CONNECT, $context);

        if (!$this->connection) {
            throw new Exception("Could not connect to EPP Server: $errstr ($errno)");
        }

    }
    
    public function generateUniqueClTRID() {
        $timeComponent = microtime(true);
        $randomComponent = bin2hex(random_bytes(8));
        return "clTRID-{$timeComponent}-{$randomComponent}";
    }

    public function sendRequest($xml) {
        $length = strlen($xml) + 4; // 4 bytes for the length field itself
        $lengthField = pack('N', $length); // 'N' for big-endian order
        fwrite($this->connection, $lengthField . $xml);

        // Read the response
        return $this->readResponse();
    }
    
    private function readResponse() {
        // Read the 4-byte length field
        $lengthField = fread($this->connection, 4);
        $unpacked = unpack('N', $lengthField);
        $length = reset($unpacked) - 4; // Subtract the 4 bytes of the length field

        // Read the message based on the length
        $response = '';
        while ($length > 0 && !feof($this->connection)) {
            $part = fread($this->connection, $length);
            $response .= $part;
            $length -= strlen($part);
        }

        return $response;
    }

    public function disconnect() {
        fclose($this->connection);
    }

    public function login($clientId, $password) {
        $xmlRequest = '<?xml version="1.0" encoding="UTF-8"?><epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><command><login><clID>'.$clientId.'</clID><pw><![CDATA['.$password.']]></pw></login><clTRID>login-'.$this->generateUniqueClTRID().'</clTRID></command></epp>';
        echo $this->sendRequest($xmlRequest);
    }

    public function logout() {
        $xmlRequest = '<?xml version="1.0" encoding="UTF-8" standalone="no"?><epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><command><logout/><clTRID>logout-'.$this->generateUniqueClTRID().'</clTRID></command></epp>';
        echo $this->sendRequest($xmlRequest);
    }
    
    public function testDomainCheck() {
        $xmlRequest = '<?xml version="1.0" encoding="UTF-8" standalone="no"?><epp xmlns="urn:ietf:params:xml:ns:epp-1.0" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
     xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd"><command><check><domain:check
       xmlns:domain="urn:ietf:params:xml:ns:domain-1.0" xsi:schemaLocation="urn:ietf:params:xml:ns:domain-1.0 domain-1.0.xsd"><domain:name>example.test</domain:name><domain:name>example.net</domain:name><domain:name>verylongdomainnamethatisunlikelytobevalidandcausesprocessingdelays.test</domain:name></domain:check></check><clTRID>domaincheck-'.$this->generateUniqueClTRID().'</clTRID></command></epp>';
        echo $this->sendRequest($xmlRequest);
    }

    public function testInvalidCommand() {
        $xmlRequest = '<?xml version="1.0" encoding="UTF-8" standalone="no"?><epp xmlns="urn:ietf:params:xml:ns:epp-1.0" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
     xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd"><command><invalidCommand/></command></epp>';
        echo $this->sendRequest($xmlRequest);
    }
    
    public function testInvalidExtension() {
        $xmlRequest = '<?xml version="1.0" encoding="UTF-8" standalone="no"?><epp xmlns="urn:ietf:params:xml:ns:epp-1.0" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
     xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd"><command><check><unsupported:check xmlns:unsupported="urn:ietf:params:xml:ns:unsupported-1.0"><unsupported:name>example.com</unsupported:name></unsupported:check></check></command></epp>';
        echo $this->sendRequest($xmlRequest);
    }
    
    public function testBadXml() {
        $xmlRequest = '<?xml version="1.0" encoding="UTF-8" standalone="no"?><epp xmlns="urn:ietf:params:xml:ns:epp-1.0" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
     xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd"><command><check><domain:check xmlns:domain="urn:ietf:params:xml:ns:domain-1.0"><domain:name>example.com</domain:name></domain:check></check</command></epp>';
        echo $this->sendRequest($xmlRequest);
    }
    
    public function testSqlInj() {
        $xmlRequest = "<?xml version=\"1.0\" encoding=\"UTF-8\" standalone=\"no\"?><epp xmlns=\"urn:ietf:params:xml:ns:epp-1.0\" xmlns:xsi=\"http://www.w3.org/2001/XMLSchema-instance\"
     xsi:schemaLocation=\"urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd\"><command><check><domain:check
       xmlns:domain=\"urn:ietf:params:xml:ns:domain-1.0\" xsi:schemaLocation=\"urn:ietf:params:xml:ns:domain-1.0 domain-1.0.xsd\"><domain:name>' OR '1'='1</domain:name></domain:check></check><clTRID>domaincheck-".$this->generateUniqueClTRID()."</clTRID></command></epp>";
        echo $this->sendRequest($xmlRequest);
    }
    
    public function testUnusuallyFormattedCommands() {
        $xmlRequest = "<epp>\n\n    <command>\n        <check>\n            <domain:check xmlns:domain=\"urn:ietf:params:xml:ns:domain-1.0\">\n                <domain:name>example.com</domain:name>\n            </domain:check>\n        </check>\n    </command>\n</epp>";
        echo $this->sendRequest($xmlRequest);
    }
    
    public function testBoundaryValues() {
        $longDomainName = str_repeat("a", 255) . ".com"; // Adjust the length as needed

        $xmlRequest = <<<XML
    <epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
        <command>
            <check>
                <domain:check xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">
                    <domain:name>{$longDomainName}</domain:name>
                </domain:check>
            </check>
        </command>
    </epp>
    XML;
        echo $this->sendRequest($xmlRequest);
    }

    public function testRepeatedLoginLogout() {
        for ($i = 0; $i < 10; $i++) { // Adjust the number of iterations as needed
            // Replace with actual login and logout XML requests
            $loginRequest = "<epp><command><login><clID>clientID</clID><pw>password</pw></login></command></epp>";
            $logoutRequest = "<epp><command><logout/></command></epp>";

            $this->sendRequest($loginRequest);
            $this->sendRequest($logoutRequest);
        }

        echo "Repeated Login and Logout Test Completed.\n";
    }
    
    public function testMalformedUnicodeCharacters() {
        echo "Running Malformed Unicode Characters Test...\n";

        // Example: Malformed Unicode characters in the domain name
        $malformedDomainName = "exämple.cöm"; // Contains unusual/malformed characters

        $xmlRequest = <<<XML
    <epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
        <command>
            <check>
                <domain:check xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">
                    <domain:name>{$malformedDomainName}</domain:name>
                </domain:check>
            </check>
        </command>
    </epp>
    XML;

        $response = $this->sendRequest($xmlRequest);
        echo "Response: " . $response . "\n";
    }

    public function testSimulatedNetworkInstability() {
        echo "Running Simulated Network Instability Test...\n";

        // Example: Introduce delays in sending requests
        for ($i = 0; $i < 5; $i++) {
            $xmlRequest = "<epp><command><check><domain:check xmlns:domain='urn:ietf:params:xml:ns:domain-1.0'><domain:name>example.com</domain:name></domain:check></check></command></epp>";
            
            // Introducing a delay
            sleep(rand(1, 5)); // Delay between 1 to 5 seconds

            $response = $this->sendRequest($xmlRequest);
            echo "Response: " . $response . "\n";
        }

        echo "Simulated Network Instability Test Completed.\n";
    }

    public function testUnexpectedProtocolVersion() {
        echo "Running Unexpected Protocol Version Test...\n";

        $xmlRequest = "<epp xmlns='urn:ietf:params:xml:ns:epp-2.0'><command><check><domain:check xmlns:domain='urn:ietf:params:xml:ns:domain-1.0'><domain:name>example.com</domain:name></domain:check></check></command></epp>";

        $response = $this->sendRequest($xmlRequest);
        echo "Response: " . $response . "\n";
    }

    public function testServerOverloadWithLongDuration() {
        echo "Running Server Overload with Long Duration Requests Test...\n";

        $startTime = time();
        $duration = 60; // Run the test for 60 seconds

        while (time() - $startTime < $duration) {
            $xmlRequest = "<epp><command><check><domain:check xmlns:domain='urn:ietf:params:xml:ns:domain-1.0'><domain:name>example.com</domain:name></domain:check></check></command></epp>";
            
            $response = $this->sendRequest($xmlRequest);
            // Optionally process the response
        }

        echo "Server Overload with Long Duration Requests Test Completed.\n";
    }

}

class EppTest {
    private $client;

    public function __construct() {
        // Initialize the EPP client with your server's details
        $this->client = new EppClient('epp.server.com', 700, 'cert.pem', 'key.pem');
    }

    public function runTests() {
        echo "Starting EPP Tests...\n";

        // Connect to the EPP server
        $this->client->connect();
        $this->client->login('clid', 'password');

        // Run various tests
        $this->client->testDomainCheck();
        $this->client->testInvalidCommand();
        //$this->client->testUnusuallyFormattedCommands();
        //$this->client->testInvalidExtension();
        //$this->client->testBadXml();
        //$this->client->testSqlInj();
        //$this->client->testBoundaryValues();
        //$this->client->testRepeatedLoginLogout();
        //$this->client->testMalformedUnicodeCharacters();
        //$this->client->testSimulatedNetworkInstability();
        //$this->client->testUnexpectedProtocolVersion();
        //$this->client->testServerOverloadWithLongDuration();
        
        // Disconnect from the server
        $this->client->logout();
        $this->client->disconnect();

        echo "EPP Tests Completed.\n";
    }

}

$test = new EppTest();
$test->runTests();