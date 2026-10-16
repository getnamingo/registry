<?php

/*
 * Namingo Registry
 *
 * Copyright (c) 2023-2026 Taras Kondratyuk
 * Copyright (c) 2025-2026 Namingo contributors
 * Copyright (c) 2026 Terbora Ltd.
 *
 * Licensed under the MIT License.
 * See the LICENSE file distributed with this software for the full license text.
 *
 * SPDX-License-Identifier: MIT
 */

require __DIR__ . '/../cp/app/Lib/Dnssec.php';
require __DIR__ . '/../epp/src/epp-create.php';
require __DIR__ . '/../epp/src/epp-update.php';
require __DIR__ . '/../epp/src/EppWriter.php';

set_error_handler(static function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
function check($condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function rejects(callable $callback): void {
    try { $callback(); } catch (InvalidArgumentException $e) { return; }
    throw new RuntimeException('Invalid input was accepted');
}
check(file_get_contents(__DIR__ . '/../epp/src/dnssec.php') ===
    file_get_contents(__DIR__ . '/../cp/bootstrap/dnssec.php'), 'Deployed codecs differ');
$fixtures = json_decode(file_get_contents(__DIR__ . '/fixtures/dnssec.json'), true, 512, JSON_THROW_ON_ERROR)['records'];
foreach ($fixtures as $fixture) {
    foreach ([2, 4, 6] as $type) {
        $ds = dnssec_derive_ds('EXAMPLE.COM.', 257, 3, $fixture['algorithm'], chunk_split($fixture['public_key']), $type);
        check($ds['keytag'] === $fixture['key_tag'], 'Key tag mismatch');
        check($ds['digest'] === $fixture['digests'][$type], 'Independent digest vector mismatch');
        $form = ['dnskeyFlags' => '257', 'dnskeyProtocol' => '3', 'dnskeyAlg' => (string)$fixture['algorithm'],
            'dnskeyPubKey' => chunk_split($fixture['public_key']), 'dnskeyDigestType' => (string)$type];
        $record = App\Lib\Dnssec::fromForm($form, 'example.com');
        check($record == array_merge(['interface' => 'keyData'], $ds), 'CP DNSKEY derivation mismatch');
        $dsForm = ['dsKeyTag' => (string)$ds['keytag'], 'dsAlg' => (string)$ds['alg'],
            'dsDigestType' => (string)$type, 'dsDigest' => strtolower($ds['digest'])];
        $record = App\Lib\Dnssec::fromForm($dsForm, 'example.com');
        check($record['digest'] === $ds['digest'] && $record['interface'] === 'dsData', 'CP DS mismatch');
        $record = App\Lib\Dnssec::fromForm($dsForm + $form, 'example.com');
        check($record['pubkey'] === $fixture['public_key'], 'CP nested key mismatch');
        rejects(fn() => App\Lib\Dnssec::fromForm(array_replace($dsForm, ['dsDigest' => 'BAD']), 'example.com'));
        rejects(fn() => App\Lib\Dnssec::fromForm(array_replace($dsForm, ['dsDigestType' => '99']), 'example.com'));
        rejects(fn() => App\Lib\Dnssec::fromForm(array_replace($dsForm, ['dsKeyTag' => '-1']), 'example.com'));
        rejects(fn() => App\Lib\Dnssec::fromForm(array_replace($dsForm, ['dsAlg' => '18oops']), 'example.com'));
        rejects(fn() => App\Lib\Dnssec::fromForm(array_replace($dsForm + $form, ['dsKeyTag' => '1']), 'example.com'));
        check(App\Lib\Dnssec::fromForm(array_replace($dsForm, ['dsKeyTag' => '0']), 'example.com')['keytag'] === 0, 'Key tag zero rejected');
        check(App\Lib\Dnssec::fromForm(array_replace($dsForm, ['dsKeyTag' => '65535']), 'example.com')['keytag'] === 65535, 'Maximum key tag rejected');
    }
    rejects(fn() => dnssec_derive_ds('example.com', 257, 3, $fixture['algorithm'], base64_encode('short')));
}
check(App\Lib\Dnssec::fromForm(['dnskeyProtocol' => '3', 'dnskeyDigestType' => '2', 'dsKeyTag' => ''], 'example.com') === null, 'Blank form not ignored');
rejects(fn() => dnssec_derive_ds('example.com', 257, 3, 19, base64_encode('key')));
rejects(fn() => dnssec_derive_ds('example.com', 257, 3, 17, '???'));
check(dnssec_digest('abc', 6) === '66C7F0F462EEEDD9D1F2D46BDC10E4E24167C4875CF2F7A2297DA02B8F4BA8E0', 'SM3 known answer failed');

// Run the actual EPP command handlers with a small in-memory DB double.
// No extraction/eval of production code and no live registry credentials needed.
class EppFailure extends RuntimeException {}
function sendEppError($conn, $db, $code, $message, ...$unused) { throw new EppFailure($message, $code); }
function getClid(...$args) { return 1; }
function generateSvTRID() { return 'test-server'; }
function updateTransaction(...$args) {}
function sendEppResponse($conn, $xml) { $GLOBALS['lastResponse'] = $xml; }
function validate_label(...$args) { return null; }
function extractDomainAndTLD(...$args) { return ['domain' => 'example', 'tld' => 'com']; }
function getDomainPrice(...$args) { return ['price' => 10]; }
function isSecureAuthInfoTransferEnabled(...$args) { return false; }
function debitRegistrarBalance(...$args) { return true; }
function normalizeDatetime($value) { return $value; }

class MemoryDb {
    public array $records = [];
    public bool $created = false;
    public bool $transaction = false;
    public function prepare($sql) { return new MemoryStatement($this, $sql); }
    public function query($sql) { $stmt = $this->prepare($sql); $stmt->execute(); return $stmt; }
    public function exec($sql) { return 1; }
    public function lastInsertId(...$args) { return 1; }
    public function beginTransaction() { $this->transaction = true; }
    public function commit() { $this->transaction = false; }
    public function rollBack() { $this->transaction = false; }
    public function inTransaction() { return $this->transaction; }
}
class MemoryStatement {
    private array $params = [];
    public function __construct(private MemoryDb $db, private string $sql) {}
    public function bindParam($name, &$value, ...$args) { $this->params[$name] = $value; }
    public function closeCursor() {}
    public function execute($params = null) {
        $this->params = $params ?? $this->params;
        if (str_starts_with($this->sql, 'INSERT INTO secdns')) {
            $this->db->records[] = array_combine(array_map(fn($key) => ltrim($key, ':'), array_keys($this->params)), array_values($this->params));
        } elseif (str_starts_with($this->sql, 'DELETE FROM secdns')) {
            $this->db->records = array_values(array_filter($this->db->records, fn($r) => !$this->matches($r)));
        } elseif (str_starts_with($this->sql, 'INSERT INTO domain (')) {
            $this->db->created = true;
        }
        return true;
    }
    private function matches(array $row): bool {
        $names = ['domain_id'=>'domain_id','keyTag'=>'keytag','alg'=>'alg','digestType'=>'digesttype',
            'digest'=>'digest','flags'=>'flags','protocol'=>'protocol','algKeyData'=>'keydata_alg','pubKey'=>'pubkey'];
        foreach ($this->params as $key=>$value) {
            $name = $names[ltrim($key, ':')] ?? null;
            if ($name !== null && ($row[$name] ?? null) != $value) return false;
        }
        return true;
    }
    public function fetchColumn() {
        if (str_contains($this->sql, 'COUNT(*) FROM secdns')) return count(array_filter($this->db->records, fn($r) => $this->matches($r)));
        if (str_contains($this->sql, 'FROM domain_tld')) return 1;
        if (str_contains($this->sql, 'FROM reserved_domain_names')) return false;
        if (str_contains($this->sql, 'FROM domain_status') || str_contains($this->sql, 'FROM settings')) return false;
        if (str_contains($this->sql, 'SELECT id FROM domain WHERE')) return $this->db->created ? 1 : false;
        throw new RuntimeException('Unhandled query: ' . $this->sql);
    }
    public function fetch($mode = null) {
        if (str_contains($this->sql, 'FROM registrar')) return ['accountBalance' => 1000, 'creditLimit' => 0, 'currency' => 'EUR'];
        if (str_contains($this->sql, 'SELECT id,tldid,exdate,clid FROM domain')) return ['id'=>1,'tldid'=>1,'exdate'=>'2027-01-01 00:00:00','clid'=>1];
        if (str_contains($this->sql, 'SELECT crdate,exdate FROM domain')) return ['2026-01-01 00:00:00','2027-01-01 00:00:00'];
        throw new RuntimeException('Unhandled query: ' . $this->sql);
    }
}
function commandXml(string $operation, string $extension): SimpleXMLElement {
    $extra = $operation === 'create' ? '<domain:authInfo><domain:pw>Example123</domain:pw></domain:authInfo>' : '';
    $xml = new SimpleXMLElement('<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><command><'.$operation.'><domain:'.$operation.' xmlns:domain="urn:ietf:params:xml:ns:domain-1.0"><domain:name>example.com</domain:name>'.$extra.'</domain:'.$operation.'></'.$operation.'><extension><secDNS:'.$operation.' xmlns:secDNS="urn:ietf:params:xml:ns:secDNS-1.1">'.$extension.'</secDNS:'.$operation.'></extension><clTRID>test</clTRID></command></epp>');
    foreach (['domain'=>'domain-1.0','secDNS'=>'secDNS-1.1','rgp'=>'rgp-1.0','launch'=>'launch-1.0','fee'=>'epp:fee-1.0','allocationToken'=>'allocationToken-1.0'] as $prefix=>$name) {
        $xml->registerXPathNamespace($prefix, 'urn:ietf:params:xml:ns:'.$name);
    }
    return $xml;
}
function keyXml($fixture) { return '<secDNS:keyData><secDNS:flags>257</secDNS:flags><secDNS:protocol>3</secDNS:protocol><secDNS:alg>'.$fixture['algorithm'].'</secDNS:alg><secDNS:pubKey>'.chunk_split($fixture['public_key']).'</secDNS:pubKey></secDNS:keyData>'; }
function dsXml($fixture, $type, $nested = '') { return '<secDNS:dsData><secDNS:keyTag>'.$fixture['key_tag'].'</secDNS:keyTag><secDNS:alg>'.$fixture['algorithm'].'</secDNS:alg><secDNS:digestType>'.$type.'</secDNS:digestType><secDNS:digest>'.$fixture['digests'][$type].'</secDNS:digest>'.$nested.'</secDNS:dsData>'; }
function updateCommand($db, $payload, $type) {
    $GLOBALS['lastResponse'] = null;
    processDomainUpdate(null, $db, commandXml('update', $payload), 'test', 'mysql', 1, $type);
    check(str_contains($GLOBALS['lastResponse'] ?? '', 'code="1000"'), 'EPP update did not succeed');
}
foreach ($fixtures as $fixture) {
    foreach ([2, 4, 6] as $type) {
        foreach (['ds', 'key', 'nested'] as $format) {
            $payload = match ($format) {
                'ds' => dsXml($fixture, $type), 'key' => keyXml($fixture),
                'nested' => dsXml($fixture, $type, keyXml($fixture)),
            };
            $db = new MemoryDb();
            processDomainCreate(null, $db, commandXml('create', $payload), 'test', 'mysql', 1, true, 'hostObj', $type);
            check(count($db->records) === 1, 'EPP create failed to store DNSSEC');
            check($db->records[0]['digest'] === $fixture['digests'][$type], 'EPP create digest mismatch');
            $remove = $format === 'key' ? keyXml($fixture) : dsXml($fixture, $type);
            updateCommand($db, '<secDNS:rem>'.$remove.'</secDNS:rem>', $type);
            check(count($db->records) === 0, 'EPP removal failed');
            updateCommand($db, '<secDNS:add>'.$payload.'</secDNS:add>', $type);
            check(count($db->records) === 1, 'EPP add failed');
            check($db->records[0]['digest'] === $fixture['digests'][$type], 'EPP add digest mismatch');
            if ($format !== 'ds') check($db->records[0]['pubkey'] === $fixture['public_key'], 'EPP public key roundtrip failed');
        }
    }
}

// Existing algorithms retain their SHA-256 default and key format handling.
foreach ([8, 13, 14, 15, 16] as $algorithm) {
    $key = base64_encode(str_repeat("\x42", 64));
    $ds = dnssec_derive_ds('example.com', 257, 3, $algorithm, $key);
    check($ds['digesttype'] === 2 && strlen($ds['digest']) === 64, 'Default digest changed');
}
foreach ($fixtures as $fixture) {
    foreach ([2, 4, 6] as $type) {
        $secData = ['keyTag'=>$fixture['key_tag'], 'alg'=>$fixture['algorithm'],
            'digestType'=>$type, 'digest'=>$fixture['digests'][$type],
            'keyData'=>['flags'=>257,'protocol'=>3,'alg'=>$fixture['algorithm'],'pubKey'=>$fixture['public_key']]];
        $writer = new EPP\EppWriter();
        $response = $writer->epp_writer(['command'=>'info_domain','resultCode'=>1000,'lang'=>'en-US',
            'message'=>'Command completed successfully','name'=>'example.com','roid'=>'1-test',
            'status'=>'ok','contact'=>[],'clID'=>'test','authInfo'=>'invalid',
            'secDNS'=>[$secData], 'clTRID'=>'test','svTRID'=>'test-server']);
        $xml = new SimpleXMLElement($response);
        $xml->registerXPathNamespace('secDNS', 'urn:ietf:params:xml:ns:secDNS-1.1');
        check((string)$xml->xpath('//secDNS:digest')[0] === $fixture['digests'][$type], 'Info digest lost');
        check((string)$xml->xpath('//secDNS:pubKey')[0] === $fixture['public_key'], 'Info key truncated');
    }
    $db = new MemoryDb();
    foreach ([str_replace('<secDNS:alg>'.$fixture['algorithm'].'</secDNS:alg>', '<secDNS:alg>19</secDNS:alg>', dsXml($fixture, 2)),
        str_replace($fixture['digests'][2], 'ABC', dsXml($fixture, 2)),
        str_replace(chunk_split($fixture['public_key']), base64_encode('short'), keyXml($fixture))] as $invalid) {
        try {
            updateCommand($db, '<secDNS:add>'.$invalid.'</secDNS:add>', 2);
            throw new RuntimeException('Invalid EPP data was accepted');
        } catch (EppFailure $e) {
            check(in_array($e->getCode(), [2004, 2005]), 'Unexpected rejection code');
        }
        check($db->records === [], 'Rejected EPP data was stored');
    }
}
echo "DNSSEC codec, control-panel and EPP create/add/remove and info serialization tests passed.\n";
