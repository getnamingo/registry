<?php
require_once 'vendor/autoload.php';

use Badcow\DNS\Zone;
use Badcow\DNS\Rdata\Factory;
use Badcow\DNS\ResourceRecord;
use Badcow\DNS\Classes;
use Badcow\DNS\ZoneBuilder;
use Badcow\DNS\AlignedBuilder;

$c = require_once 'config.php';
require_once 'helpers.php';

$logFilePath = '/var/log/namingo/write_zone.log';
$log = setupLogger($logFilePath, 'Zone_Generator');
$log->info('job started.');

use Swoole\Coroutine;

// Initialize the PDO connection pool
$pool = new Swoole\Database\PDOPool((new Swoole\Database\PDOConfig())->withDriver($c['db_type'])->withHost($c['db_host'])->withPort($c['db_port'])->withDbName($c['db_database'])->withUsername($c['db_username'])->withPassword($c['db_password'])->withCharset('utf8mb4'));

Swoole\Runtime::enableCoroutine();

/**
 * How to use this 'not just ns'
 * Configure your 'setting' line 
 * setting section starts on line 1080
 * backup orignal -> or just rename 
 * write-zone.php -> write-zone.php.original
 * then rename/copy this to write-zone.php
 */

/**
 * Adds a specific DNS record to a zone.
 *
 * @param Zone $zone The DNS zone to add the record to.
 * @param string $cleanedTld The cleaned TLD of the domain.
 * @param string $recordType The type of DNS record to add (e.g. A, AAAA, CNAME, etc.).
 * @param array $recordData The data for the DNS record.
 * @throws Exception If the record type is not supported.
 * @return void
 */
function addSpecificRecords($zone, $cleanedTld, $recordType, $recordData)
{
    global $log;

    // Log that the function is being called
    $log->info('Zone_Generator.INFO: Adding ' . $recordType . ' record for domain: ' . $cleanedTld);

    try
    {
        switch ($recordType)
        {
            case 'A':
                $record = generateARecord($cleanedTld, $recordData['ipv4']);
            break;
            case 'A1':
                $record = generateA1Record($cleanedTld, $recordData['ipv4']);
            break;
            case 'AAAA':
                $record = generateAAAARecord($cleanedTld, $recordData['ipv6']);
            break;
            case 'AFSDB':
                $record = generateAFSDBRecord($cleanedTld, $recordData);
            break;
            case 'APL':
                $record = generateAPLRecord($cleanedTld, $recordData);
            break;
            case 'CAA':
                $record = generateCARecord($cleanedTld, $recordData);
            break;
            case 'CDNSKEY':
                $record = generateCDNSKEYRecord($cleanedTld, $recordData);
            break;
            case 'CDS':
                $record = generateCDSRecord($cleanedTld, $recordData);
            break;
            case 'CERT':
                $record = generateCERTRecord($cleanedTld, $recordData);
            break;
            case 'CNAME':
                $record = generateCNAMERecord($cleanedTld, $recordData);
            break;
            case 'CSYNC':
                $record = generateCSYNCRecord($cleanedTld, $recordData);
            break;
            case 'DHCID':
                $record = generateDHCIDRecord($cleanedTld, $recordData);
            break;
            case 'DLV':
                $record = generateDLVRecord($cleanedTld, $recordData);
            break;
            case 'DNAME':
                $record = generateDNAMERecord($cleanedTld, $recordData);
            break;
            case 'DNSKEY':
                $record = generateDNSKEYRecord($cleanedTld, $recordData);
            break;
            case 'DS':
                $record = generateDSRecord($cleanedTld, $recordData);
            break;
            case 'HIP':
                $record = generateHIPRecord($cleanedTld, $recordData);
            break;
            case 'IPSECKEY':
                $record = generateIPSECKEYRecord($cleanedTld, $recordData);
            break;
            case 'KEY':
                $record = generateKEYRecord($cleanedTld, $recordData);
            break;
            case 'KX':
                $record = generateKXRecord($cleanedTld, $recordData);
            break;
            case 'LOC':
                $record = generateLOCREcord($cleanedTld, $recordData);
            break;
            case 'MX':
                $record = generateMXRecord($cleanedTld, $recordData);
            break;
            case 'NAPTR':
                $record = generateNAPTRRecord($cleanedTld, $recordData);
            break;
            case 'NSEC':
                $record = generateNSECRecord($cleanedTld, $recordData);
            break;
            case 'NSEC3':
                $record = generateNSEC3Record($cleanedTld, $recordData);
            break;
            case 'NSEC3PARAM':
                $record = generateNSEC3PARAMRecord($cleanedTld, $recordData);
            break;
                // case 'OPENPGPKEY':
                // $record = generateOPENPGPKEYRecord($cleanedTld, $recordData); not supported by Badcow\DNS\Rdata\
                // break;  
            case 'PTR':
                $record = generatePTRRecord($cleanedTld, $recordData);
            break;
            case 'RP':
                $record = generateRPRecord($cleanedTld, $recordData);
            break;
            case 'RRSIG':
                $record = generateRRSIGRecord($cleanedTld, $recordData);
            break;
            case 'SIG':
                $record = generateSIGRecord($cleanedTld, $recordData);
            break;
                // case 'SMIMEA':
                // $record = generateSMIMEARecord($cleanedTld, $recordData); not supported by Badcow\DNS\Rdata\
                // break;   
            case 'SRV':
                $record = generateSRVRecord($cleanedTld, $recordData);
            break;
            case 'SSHFP':
                $record = generateSSHFPRecord($cleanedTld, $recordData);
            break;
            case 'TA':
                $record = generateTAREcord($cleanedTld, $recordData);
            break;
            case 'TKEY':
                $record = generateTKEYRecord($cleanedTld, $recordData);
            break;
            case 'TLSA':
                $record = generateTLSARecord($cleanedTld, $recordData);
            break;
            case 'TSIG':
                $record = generateTSIGRecord($cleanedTld, $recordData);
            break;
            case 'TXT': //generates TXT record output example.com 100 IN TXT "test txt record"
                $record = generateTXTRecord($cleanedTld, $recordData['text']);
            break;
            case 'TXTAcme': //genreates TXT record for acme challenge ()
                $record = generateTXTACMERecord($cleanedTld, $recordData['text']);
            break;
            case 'TXTDKIM': //generates TXT record for DKIM
                $record = generateTXTDKIMRecord($cleanedTld, $recordData['text']);
            break;
            case 'AG1': //generates A glue record
                $record = generateAG1Record($cleanedTld, $recordData['ipv4']);
            break;
            case 'AG2': //generates A glue record
                $record = generateAG2Record($cleanedTld, $recordData['ipv4']);
            break;
            case 'NSx': //generates extra NS record for tld
                $record = generateNSxRecord($cleanedTld, $recordData['nsx']);
            break;
            case 'NSx1': //generates extra NS record for tld
                $record = generateNSx1Record($cleanedTld, $recordData['nsx1']);
            break;
            case 'NSx2': //generates extra NS record for tld
                $record = generateNSx1Record($cleanedTld, $recordData['nsx2']);
            break;
            case 'NSx3': //generates extra NS record for tld
                $record = generateNSx1Record($cleanedTld, $recordData['nsx3']);
            break;
            case 'NSx4': //generates extra NS record for tld
                $record = generateNSx1Record($cleanedTld, $recordData['nsx4']);
            break;
            case 'NSx5': //generates extra NS record for tld
                $record = generateNSx1Record($cleanedTld, $recordData['nsx5']);
            break;
            case 'URI':
                $record = generateURIRecord($cleanedTld, $recordData);
            break;
            default:
                throw new Exception('Unsupported record type: ' . $recordType);
        }

        if ($record !== null)
        {
            $zone->addResourceRecord($record);
            $log->info('Zone_Generator.INFO: Added ' . $recordType . ' record for ' . $cleanedTld . ': ' . json_encode($recordData));
        }
        else
        {
            $log->warning('Zone_Generator.WARNING: Failed to add ' . $recordType . ' record for domain: ' . $cleanedTld);
        }
    }
    catch(Exception $e)
    {
        $log->error('Zone_Generator.ERROR: Failed to add ' . $recordType . ' record for domain: ' . $cleanedTld . '. Error: ' . $e->getMessage());
    }
}

/**
 * Generates an A record for a given host name and IP address.
 *
 * @param string $hostName The host name for the A record.
 * @param string $ipAddress The IP address for the A record.
 * @return ResourceRecord The generated A record.
 */
function generateARecord($hostName, $ipAddress)
{
    $record = new ResourceRecord();
    $record->setName($hostName . '.');
    $record->setClass(Classes::INTERNET);
    $record->setTtl(100);
    $record->setRdata(Factory::A($ipAddress));
    return $record;
}

/**
 * Generates an A1 record for a given host name and IP address.
 *
 * @param string $hostName The host name for the A1 record.
 * @param string $ipAddress The IP address for the A1 record.
 * @return ResourceRecord The generated A1 record.
 */
function generateA1Record($hostName, $ipAddress)
{
    $record = new ResourceRecord();
    $record->setName($hostName . '.');
    $record->setClass(Classes::INTERNET);
    $record->setTtl(100);
    $record->setRdata(Factory::A($ipAddress));
    return $record;
}

/**
 * Generates an AAAA record for a given host name and IPv6 address.
 *
 * @param string $hostName The host name for the AAAA record.
 * @param string $ipv6Address The IPv6 address for the AAAA record.
 * @return ResourceRecord The generated AAAA record.
 */
function generateAAAARecord($hostName, $ipv6Address)
{
    $record = new ResourceRecord();
    $record->setName($hostName . '.');
    $record->setClass(Classes::INTERNET);
    $record->setTtl(100);
    $record->setRdata(Factory::AAAA($ipv6Address));
    return $record;
}

/**
 * Generates an AFSDB record for a given host name and record data.
 *
 * @param string $hostName The host name for the AFSDB record.
 * @param mixed $recordData The data for the AFSDB record.
 * @return ResourceRecord The generated AFSDB record.
 */
function generateAFSDBRecord($hostName, $recordData)
{
    $record = new ResourceRecord();
    $record->setName($hostName . '.');
    $record->setClass(Classes::INTERNET);
    $record->setTtl(100);
    $record->setRdata(Factory::AFSDB($recordData));
    return $record;
}

/**
 * Generates an APL record for a given host name and record data.
 *
 * @param string $hostName The host name for the APL record.
 * @param mixed $recordData The record data for the APL record.
 * @return ResourceRecord The generated APL record.
 */
function generateAPLRecord($hostName, $recordData)
{
    $record = new ResourceRecord();
    $record->setName($hostName . '.');
    $record->setClass(Classes::INTERNET);
    $record->setTtl(100);
    $record->setRdata(Factory::APL($recordData));
    return $record;
}

/**
 * Generates a CAA record for a given host name and record data.
 *
 * @param string $hostName The host name for the CAA record.
 * @param mixed $recordData The data for the CAA record.
 * @return ResourceRecord The generated CAA record.
 */
function generateCAARecord($hostName, $recordData)
{
    $record = new ResourceRecord();
    $record->setName($hostName . '.');
    $record->setClass(Classes::INTERNET);
    $record->setTtl(100);
    $record->setRdata(Factory::CAA($recordData));
    return $record;
}

/**
 * Generates a CDNSKEY record for a given host name and record data.
 *
 * @param string $hostName The host name for the CDNSKEY record.
 * @param mixed $recordData The data for the CDNSKEY record.
 * @return ResourceRecord The generated CDNSKEY record.
 */
function generateCDNSKEYRecord($hostName, $recordData)
{
    $record = new ResourceRecord();
    $record->setName($hostName . '.');
    $record->setClass(Classes::INTERNET);
    $record->setTtl(100);
    $record->setRdata(Factory::CDNSKEY($recordData));
    return $record;
}

/**
 * Generates a CDS record for a given host name and record data.
 *
 * @param string $hostName The host name for the CDS record.
 * @param mixed $recordData The data for the CDS record.
 * @return ResourceRecord The generated CDS record.
 */
function generateCDSRecord($hostName, $recordData)
{
    $record = new ResourceRecord();
    $record->setName($hostName . '.');
    $record->setClass(Classes::INTERNET);
    $record->setTtl(100);
    $record->setRdata(Factory::CDS($recordData));
    return $record;
}

/**
 * Generates a CERT record for a given host name and record data.
 *
 * @param string $hostName The host name for the CERT record.
 * @param mixed $recordData The data for the CERT record.
 * @return ResourceRecord The generated CERT record.
 */
function generateCERTRecord($hostName, $recordData)
{
    $record = new ResourceRecord();
    $record->setName($hostName . '.');
    $record->setClass(Classes::INTERNET);
    $record->setTtl(100);
    $record->setRdata(Factory::CERT($recordData));
    return $record;
}

/**
 * Generates a CNAME record for a given host name and record data.
 *
 * @param string $hostName The host name for the CNAME record.
 * @param mixed $recordData The data for the CNAME record.
 * @return ResourceRecord The generated CNAME record.
 */
function generateCNAMERecord($hostName, $recordData)
{
    $record = new ResourceRecord();
    $record->setName($hostName . '.');
    $record->setClass(Classes::INTERNET);
    $record->setTtl(100);
    $record->setRdata(Factory::CNAME($recordData));
    return $record;
}

/**
 * Generates a CSYNC record for a given host name and record data.
 *
 * @param string $hostName The host name for the CSYNC record.
 * @param mixed $recordData The data for the CSYNC record.
 * @return ResourceRecord The generated CSYNC record.
 */
function generateCSYNCRecord($hostName, $recordData)
{
    $record = new ResourceRecord();
    $record->setName($hostName . '.');
    $record->setClass(Classes::INTERNET);
    $record->setTtl(100);
    $record->setRdata(Factory::CSYNC($recordData));
    return $record;
}

/**
 * Generates a DHCID record for a given host name and record data.
 *
 * @param string $hostName The host name for the DHCID record.
 * @param mixed $recordData The data for the DHCID record.
 * @return ResourceRecord The generated DHCID record.
 */
function generateDHCIDRecord($hostName, $recordData)
{
    $record = new ResourceRecord();
    $record->setName($hostName . '.');
    $record->setClass(Classes::INTERNET);
    $record->setTtl(100);
    $record->setRdata(Factory::DHCID($recordData));
    return $record;
}

/**
 * Generates a DLV record for a given host name and record data.
 *
 * @param string $hostName The host name for the DLV record.
 * @param mixed $recordData The data for the DLV record.
 * @return ResourceRecord The generated DLV record.
 */
function generateDLVRecord($hostName, $recordData)
{
    $record = new ResourceRecord();
    $record->setName($hostName . '.');
    $record->setClass(Classes::INTERNET);
    $record->setTtl(100);
    $record->setRdata(Factory::DLV($recordData));
    return $record;
}

/**
 * Generates a DNAME record for a given host name and record data.
 *
 * @param string $hostName The host name for the DNAME record.
 * @param mixed $recordData The data for the DNAME record.
 * @return ResourceRecord The generated DNAME record.
 */
function generateDNAMERecord($hostName, $recordData)
{
    $record = new ResourceRecord();
    $record->setName($hostName . '.');
    $record->setClass(Classes::INTERNET);
    $record->setTtl(100);
    $record->setRdata(Factory::DNAME($recordData));
    return $record;
}

/**
 * Generates a DNSKEY record for a given host name and record data.
 *
 * @param string $hostName The host name for the DNSKEY record.
 * @param mixed $recordData The data for the DNSKEY record.
 * @return ResourceRecord The generated DNSKEY record.
 */
function generateDNSKEYRecord($hostName, $recordData)
{
    $record = new ResourceRecord();
    $record->setName($hostName . '.');
    $record->setClass(Classes::INTERNET);
    $record->setTtl(100);
    $record->setRdata(Factory::DNSKEY($recordData));
    return $record;
}

/**
 * Generates a DS record for a given host name and record data.
 *
 * @param string $hostName The host name for the DS record.
 * @param mixed $recordData The data for the DS record.
 * @return ResourceRecord The generated DS record.
 */
function generateDSRecord($hostName, $recordData)
{
    $record = new ResourceRecord();
    $record->setName($hostName . '.');
    $record->setClass(Classes::INTERNET);
    $record->setTtl(100);
    $record->setRdata(Factory::DS($recordData));
    return $record;
}

/**
 * Generates a HIP record for a given host name and record data.
 *
 * @param string $hostName The host name for the HIP record.
 * @param mixed $recordData The data for the HIP record.
 * @return ResourceRecord The generated HIP record.
 */
function generateHIPRecord($hostName, $recordData)
{
    $record = new ResourceRecord();
    $record->setName($hostName . '.');
    $record->setClass(Classes::INTERNET);
    $record->setTtl(100);
    $record->setRdata(Factory::HIP($recordData));
    return $record;
}

/**
 * Generates an IPSECKEY record for a given host name and record data.
 *
 * @param string $hostName The host name for the IPSECKEY record.
 * @param mixed $recordData The data for the IPSECKEY record.
 * @return ResourceRecord The generated IPSECKEY record.
 */
function generateIPSECKEYRecord($hostName, $recordData)
{
    $record = new ResourceRecord();
    $record->setName($hostName . '.');
    $record->setClass(Classes::INTERNET);
    $record->setTtl(100);
    $record->setRdata(Factory::IPSECKEY($recordData));
    return $record;
}

/**
 * Generates a KEY record for a given host name and record data.
 *
 * @param string $hostName The host name for the KEY record.
 * @param mixed $recordData The data for the KEY record.
 * @return ResourceRecord The generated KEY record.
 */
function generateKEYRecord($hostName, $recordData)
{
    $record = new ResourceRecord();
    $record->setName($hostName . '.');
    $record->setClass(Classes::INTERNET);
    $record->setTtl(100);
    $record->setRdata(Factory::KEY($recordData));
    return $record;
}

/**
 * Generates a KX record for a given host name and record data.
 *
 * @param string $hostName The host name for the KX record.
 * @param mixed $recordData The data for the KX record.
 * @return ResourceRecord The generated KX record.
 */
function generateKXRecord($hostName, $recordData)
{
    $record = new ResourceRecord();
    $record->setName($hostName . '.');
    $record->setClass(Classes::INTERNET);
    $record->setTtl(100);
    $record->setRdata(Factory::KX($recordData));
    return $record;
}

/**
 * Generates a LOC record for a given host name and record data.
 *
 * @param string $hostName The host name for the LOC record.
 * @param mixed $recordData The data for the LOC record.
 * @return ResourceRecord The generated LOC record.
 */
function generateLOCRecord($hostName, $recordData)
{
    $record = new ResourceRecord();
    $record->setName($hostName . '.');
    $record->setClass(Classes::INTERNET);
    $record->setTtl(100);
    $record->setRdata(Factory::LOC($recordData));
    return $record;
}

/**
 * Generates an MX record for a given host name and record data.
 *
 * @param string $hostName The host name for the MX record.
 * @param array $recordData The data for the MX record. It should contain a 'priority' key and an 'exchange' key.
 * @throws \InvalidArgumentException If the 'priority' key is not an integer.
 * @return ResourceRecord The generated MX record.
 */
function generateMXRecord($hostName, $recordData)
{
    if (!isset($recordData['priority']) || !is_int($recordData['priority']))
    {
        throw new \InvalidArgumentException('Priority must be an integer');
    }

    $record = new ResourceRecord();
    $record->setName($hostName . '.');
    $record->setClass(Classes::INTERNET);
    $record->setTtl(100);
    $record->setRdata(Factory::MX((int)$recordData['priority'], $recordData['exchange']));
    return $record;
}

/**
 * Generates a NAPTR record for a given host name and record data.
 *
 * @param string $hostName The host name for the NAPTR record.
 * @param mixed $recordData The data for the NAPTR record.
 * @return ResourceRecord The generated NAPTR record.
 */
function generateNAPTRRecord($hostName, $recordData)
{
    $record = new ResourceRecord();
    $record->setName($hostName . '.');
    $record->setClass(Classes::INTERNET);
    $record->setTtl(100);
    $record->setRdata(Factory::NAPTR($recordData));
    return $record;
}

/**
 * Generates an NSEC record for a given host name and record data.
 *
 * @param string $hostName The host name for the NSEC record.
 * @param mixed $recordData The data for the NSEC record.
 * @return ResourceRecord The generated NSEC record.
 */
function generateNSECRecord($hostName, $recordData)
{
    $record = new ResourceRecord();
    $record->setName($hostName . '.');
    $record->setClass(Classes::INTERNET);
    $record->setTtl(100);
    $record->setRdata(Factory::NSEC($recordData));
    return $record;
}

/**
 * Generates an NSEC3 record for a given host name and record data.
 *
 * @param string $hostName The host name for the NSEC3 record.
 * @param mixed $recordData The data for the NSEC3 record.
 * @return ResourceRecord The generated NSEC3 record.
 */
function generateNSEC3Record($hostName, $recordData)
{
    $record = new ResourceRecord();
    $record->setName($hostName . '.');
    $record->setClass(Classes::INTERNET);
    $record->setTtl(100);
    $record->setRdata(Factory::NSEC3($recordData));
    return $record;
}

/**
 * Generates an NSEC3PARAM record for a given host name and record data.
 *
 * @param string $hostName The host name for the NSEC3PARAM record.
 * @param mixed $recordData The data for the NSEC3PARAM record.
 * @return ResourceRecord The generated NSEC3PARAM record.
 */
function generateNSEC3PARAMRecord($hostName, $recordData)
{
    $record = new ResourceRecord();
    $record->setName($hostName . '.');
    $record->setClass(Classes::INTERNET);
    $record->setTtl(100);
    $record->setRdata(Factory::NSEC3PARAM($recordData));
    return $record;
}

/**
 * Generates a PTR record for a given host name and record data.
 *
 * @param string $hostName The host name for the PTR record.
 * @param mixed $recordData The data for the PTR record.
 * @return ResourceRecord The generated PTR record.
 */
function generatePTRRecord($hostName, $recordData)
{
    $record = new ResourceRecord();
    $record->setName($hostName . '.');
    $record->setClass(Classes::INTERNET);
    $record->setTtl(100);
    $record->setRdata(Factory::PTR($recordData));
    return $record;
}

/**
 * Generates an RRSIG record for a given host name and record data.
 *
 * @param string $hostName The host name for the RRSIG record.
 * @param mixed $recordData The data for the RRSIG record.
 * @return ResourceRecord The generated RRSIG record.
 */
function generateRRSIGRecord($hostName, $recordData)
{
    $record = new ResourceRecord();
    $record->setName($hostName . '.');
    $record->setClass(Classes::INTERNET);
    $record->setTtl(100);
    $record->setRdata(Factory::RRSIG($recordData));
    return $record;
}

/**
 * Generates an RP record for a given host name and record data.
 *
 * @param string $hostName The host name for the RP record.
 * @param mixed $recordData The data for the RP record.
 * @return ResourceRecord The generated RP record.
 */
function generateRPRecord($hostName, $recordData)
{
    $record = new ResourceRecord();
    $record->setName($hostName . '.');
    $record->setClass(Classes::INTERNET);
    $record->setTtl(100);
    $record->setRdata(Factory::RP($recordData));
    return $record;
}

/**
 * Generates a SIG record for a given host name and record data.
 *
 * @param string $hostName The host name for the SIG record.
 * @param mixed $recordData The data for the SIG record.
 * @return ResourceRecord The generated SIG record.
 */
function generateSIGRecord($hostName, $recordData)
{
    $record = new ResourceRecord();
    $record->setName($hostName . '.');
    $record->setClass(Classes::INTERNET);
    $record->setTtl(100);
    $record->setRdata(Factory::SIG($recordData));
    return $record;
}

/**
 * Generates a SOA record for a given host name and record data.
 *
 * @param string $hostName The host name for the SOA record.
 * @param mixed $recordData The data for the SOA record.
 * @return ResourceRecord The generated SOA record.
 */
function generateSOARecord($hostName, $recordData)
{
    $record = new ResourceRecord();
    $record->setName($hostName . '.');
    $record->setClass(Classes::INTERNET);
    $record->setTtl(100);
    $record->setRdata(Factory::SOA($recordData));
    return $record;
}

/**
 * Generates an SPF record for a given host name and record data.
 *
 * @param string $hostName The host name for the SPF record.
 * @param mixed $recordData The data for the SPF record.
 * @return ResourceRecord The generated SPF record.
 */
function generateSPFRecord($hostName, $recordData)
{
    $record = new ResourceRecord();
    $record->setName($hostName . '.');
    $record->setClass(Classes::INTERNET);
    $record->setTtl(100);
    $record->setRdata(Factory::SPF($recordData));
    return $record;
}

/**
 * Generates a SRV record for a given host name and record data.
 *
 * @param string $hostName The host name for the SRV record.
 * @param mixed $recordData The data for the SRV record.
 * @return ResourceRecord The generated SRV record.
 */
function generateSRVRecord($hostName, $recordData)
{
    $record = new ResourceRecord();
    $record->setName($hostName . '.');
    $record->setClass(Classes::INTERNET);
    $record->setTtl(100);
    $record->setRdata(Factory::SRV($recordData));
    return $record;
}

/**
 * Generates an SSHFP record for a given host name and record data.
 *
 * @param string $hostName The host name for the SSHFP record.
 * @param mixed $recordData The data for the SSHFP record.
 * @return ResourceRecord The generated SSHFP record.
 */
function generateSSHFPRecord($hostName, $recordData)
{
    $record = new ResourceRecord();
    $record->setName($hostName . '.');
    $record->setClass(Classes::INTERNET);
    $record->setTtl(100);
    $record->setRdata(Factory::SSHFP($recordData));
    return $record;
}

/**
 * Generates a TA record for a given host name and record data.
 *
 * @param string $hostName The host name for the TA record.
 * @param mixed $recordData The data for the TA record.
 * @return ResourceRecord The generated TA record.
 */
function generateTARecord($hostName, $recordData)
{
    $record = new ResourceRecord();
    $record->setName($hostName . '.');
    $record->setClass(Classes::INTERNET);
    $record->setTtl(100);
    $record->setRdata(Factory::TA($recordData));
    return $record;
}

/**
 * Generates a TKEY record for a given host name and record data.
 *
 * @param string $hostName The host name for the TKEY record.
 * @param mixed $recordData The data for the TKEY record.
 * @return ResourceRecord The generated TKEY record.
 */
function generateTKEYRecord($hostName, $recordData)
{
    $record = new ResourceRecord();
    $record->setName($hostName . '.');
    $record->setClass(Classes::INTERNET);
    $record->setTtl(100);
    $record->setRdata(Factory::TKEY($recordData));
    return $record;
}

/**
 * Generates a TLSA record for a given host name and record data.
 *
 * @param string $hostName The host name for the TLSA record.
 * @param mixed $recordData The data for the TLSA record.
 * @return ResourceRecord The generated TLSA record.
 */
function generateTLSARecord($hostName, $recordData)
{
    $record = new ResourceRecord();
    $record->setName($hostName . '.');
    $record->setClass(Classes::INTERNET);
    $record->setTtl(100);
    $record->setRdata(Factory::TLSA($recordData));
    return $record;
}

/**
 * Generates a TSIG record for a given host name and record data.
 *
 * @param string $hostName The host name for the TSIG record.
 * @param mixed $recordData The data for the TSIG record.
 * @return ResourceRecord The generated TSIG record.
 */
function generateTSIGRecord($hostName, $recordData)
{
    $record = new ResourceRecord();
    $record->setName($hostName . '.');
    $record->setClass(Classes::INTERNET);
    $record->setTtl(100);
    $record->setRdata(Factory::TSIG($recordData));
    return $record;
}

/**
 * Generates a TXT record for a given host name and record data.
 *
 * @param string $hostName The host name for the TXT record.
 * @param mixed $recordData The data for the TXT record.
 * @return ResourceRecord The generated TXT record.
 */
function generateTXTRecord($hostName, $recordData)
{
    $record = new ResourceRecord();
    $record->setName($hostName . '.');
    $record->setClass(Classes::INTERNET);
    $record->setTtl(100);
    $record->setRdata(Factory::TXT($recordData));
    return $record;
}

/**
 * Generates a URI record for a given host name and record data.
 *
 * @param string $hostName The host name for the URI record.
 * @param mixed $recordData The data for the URI record.
 * @return ResourceRecord The generated URI record.
 */
function generateURIRecord($hostName, $recordData)
{
    $record = new ResourceRecord();
    $record->setName($hostName . '.');
    $record->setClass(Classes::INTERNET);
    $record->setTtl(100);
    $record->setRdata(Factory::URI($recordData));
    return $record;
}

/**
 * Generates a TXT record for ACME challenge for a given host name and record data.
 *
 * @param string $hostName The host name for the TXT ACME record.
 * @param mixed $recordData The data for the TXT ACME record.
 * @return ResourceRecord The generated TXT ACME record.
 */
function generateTXTACMERecord($hostName, $recordData)
{
    $record = new ResourceRecord();
    $record->setName('_acme-challenge.' . $hostName . '.');
    $record->setClass(Classes::INTERNET);
    $record->setTtl(100);
    $record->setRdata(Factory::TXT($recordData));
    return $record;
}

/**
 * Generates a TXT record for DKIM for a given host name and record data.
 *
 * @param string $hostName The host name for the TXT DKIM record.
 * @param mixed $recordData The data for the TXT DKIM record.
 * @return ResourceRecord The generated TXT DKIM record.
 * some mail providers may require TXT DKIM record be like this
 * mailexample._domainkey.example.com. 100 IN TXT "DKIM here"
 * 
 * 
 */
function generateTXTDKIMRecord($hostName, $recordData)
{
    $record = new ResourceRecord();
    $record->setName('mailexample._domainkey.' . $hostName . '.'); 
    $record->setClass(Classes::INTERNET);
    $record->setTtl(100);
    $record->setRdata(Factory::TXT($recordData));
    return $record;
}


/**
 * Generates an A record for the primary nameserver 'ns1.' with the given host name and IP address.
 *
 * @param string $hostName The host name for the A record.
 * @param string $ipAddress The IP address for the A record.
 * @return ResourceRecord The generated A record.
 */
function generateAG1Record($hostName, $ipAddress)
{
    $record = new ResourceRecord();
    $record->setName('ns1.' . $hostName . '.');
    $record->setClass(Classes::INTERNET);
    $record->setTtl(100);
    $record->setRdata(Factory::A($ipAddress));
    return $record;
}

/**
 * Generates an A record for the secondary nameserver 'ns2.' with the given host name and IP address. 
 * glue for ns2
 * @param string $hostName The host name for the A record.
 * @param string $ipAddress The IP address for the A record.
 * @return ResourceRecord The generated A record.
 */
function generateAG2Record($hostName, $ipAddress)
{
    $record = new ResourceRecord();
    $record->setName('ns2.' . $hostName . '.');
    $record->setClass(Classes::INTERNET);
    $record->setTtl(100);
    $record->setRdata(Factory::A($ipAddress));
    return $record;
}

/**
 * Generates an NS record for a given host name and record data.
 *
 * @param string $hostName The host name for the NS record.
 * @param mixed $recordData The data for the NS record.
 * @return ResourceRecord The generated NS record.
 */
function generateNSxRecord($hostName, $recordData) {
    $record = new ResourceRecord;
    $record->setName($hostName . '.');
    $record->setClass(Classes::INTERNET);
    $record->setRdata(Factory::Ns($recordData));
    return $record;
}

/**
 * Generates an NS record for a given host name and record data.
 *
 * @param string $hostName The host name for the NS record.
 * @param mixed $recordData The data for the NS record.
 * @return ResourceRecord The generated NS record.
 */
function generateNSx1Record($hostName, $recordData) {
    $record = new ResourceRecord;
    $record->setName($hostName . '.');
    $record->setClass(Classes::INTERNET);
    $record->setRdata(Factory::Ns($recordData));
    return $record;
}

/**
 * Generates an NS record for a given host name and record data.
 *
 * @param string $hostName The host name for the NS record.
 * @param mixed $recordData The data for the NS record.
 * @return ResourceRecord The generated NS record.
 */
function generateNSx2Record($hostName, $recordData) {
    $record = new ResourceRecord;
    $record->setName($hostName . '.');
    $record->setClass(Classes::INTERNET);
    $record->setRdata(Factory::Ns($recordData));
    return $record;
}

/**
 * Generates an NS record for a given host name and record data.
 *
 * @param string $hostName The host name for the NS record.
 * @param mixed $recordData The data for the NS record.
 * @return ResourceRecord The generated NS record.
 */
function generateNSx3Record($hostName, $recordData) {
    $record = new ResourceRecord;
    $record->setName($hostName . '.');
    $record->setClass(Classes::INTERNET);
    $record->setRdata(Factory::Ns($recordData));
    return $record;
}


// Creating first coroutine
Coroutine::create(function () use ($pool, $log, $c)
{
    try
    {
        $pdo = $pool->get();
        $sth = $pdo->prepare('SELECT id, tld FROM domain_tld');
        $sth->execute();
        $timestamp = time();

        while (list($id, $tld) = $sth->fetch(PDO::FETCH_NUM))
        {
            $tldRE = preg_quote($tld, '/');
            $cleanedTld = ltrim(strtolower($tld) , '.');
            $zone = new Zone($cleanedTld . '.');
            $zone->setDefaultTtl(100);

            /**
            * setting for custom records for tlds...
            * here'are example settings 
            * main registry domain is example.com
            * 
            * cp.example.com for control panel 
            * whois.example.com for whois 
            * rdap.example.com for rdap
            * epp.example.com for epp
            *
            * in config.php (for this example) there are to ns -> ns1.example.com and ns2.example.com 
            * example.com is added as tld in Control Panel
            * followging domains are registered and delegated to ns1.example.com and ns2.example.com in cp and added as tlds in Control Panel, and added as master zones in Bind:
            * ns1.example.com
            * ns2.example.com
            * cp.example.com 
            * whois.example.com
            * rdap.example.com
            * epp.example.com
            *
            *
            * the tlds setting could be specified as inline too (one line)
            * 
            * 
            */
            $tldRecords = [

            'example.com' => [
                'TXT' => ['text' => 'v=spf1 redirect=_spf.example.com'],  //spf txt record example
                'TXTDKIM' => ['text' => 'v=DKIM1; k=rsa; p=hereshouldbeactualdkim'],  //DKIM txt record example
                'MX' => ['priority' => 10, 'exchange' => 'mx.example.com'], //mx record example
                // 'AG1' => ['ipv4' => '0.0.0.0'],  //glue for ns1.example.com
               // 'AG2' => ['ipv4' => '0.0.0.1'],  //glue for ns2.example.com
            ],

            'ns1.example.com' => [
                'A' => ['ipv4' => '0.0.0.0'], //A record example
            /**
            * as the same bind serves parent example.com, there's no need for glue in parent example.com
            * but you can actually add glue in parent example.com too....
            * however even if you add glue in parent example.com
            * don't turn off this section
            *
            */
            ],

            'ns2.example.com' => [
                'A' => ['ipv4' => '0.0.0.1'], //A record example
            /**
            * as the same bind serves parent example.com, there's no need for glue in parent example.com
            * but you can actually add glue in parent example.com too....
            * however even if you add glue in parent example.com
            * don't turn off this section
            *
            */
            ],

            //inline example....
            'cp.example.com' => ['A' => ['ipv4' => '0.0.0.0'], 'A1' => ['ipv4' => '0.0.0.1'], 'TXTAcme' => ['text' => 'text-for-acme'], ],

            
            'whois.example.com' => [
                'A' => ['ipv4' => '0.0.0.0'], 
                'A1' => ['ipv4' => '0.0.0.1'], 
                ],

            'rdap.example.com' => [
                'A' => ['ipv4' => '0.0.0.0'], 
                'A1' => ['ipv4' => '0.0.0.1'], 
            ],

            'epp.example.com' => [
                'AAAA' => ['ipv6' => '::1'], //AAAA IPV6 record example
            ],


            /**
             *  for example
             * this domain/tld will be delegate to the ns as specified in config.php
             *  ns1.example.com
             *  ns2.example.com
             * 
             */
            'example.net' => [
                'A' => ['ipv4' => '0.0.0.3'], //A record example
            ],

            /**
             * and for this domain/tld
             * you wanted to add extra ns 
             * 
             *  so the following would be delegate to ns as specified in config.php 
             * ns1.example.com
             * ns2.example.com
             * 
             * and to addtional two
             * ns3.example.org
             * ns4.example.org
             * 
             */
            'example.org' => [
                'A' => ['ipv4' => '0.0.0.4'], //A record example
                'NSx' => ['nsx' => 'ns3.example.org'],
                'NSx1' => ['nsx1' => 'ns4.example.org'], 
            ],
            
            'ns3.example.org' => [
                'A' => ['ipv4' => '0.0.0.5'],
                'NSx' => ['nsx' => 'ns3.example.org'],
                'NSx1' => ['nsx1' => 'ns4.example.org'], 
            ],

            'ns4.example.org' => [
                'A' => ['ipv4' => '0.0.0.6'],
                'NSx' => ['nsx' => 'ns3.example.org'],
                'NSx1' => ['nsx1' => 'ns4.example.org'], 
            ],
               
            

            ];

/**
 * Iterates over a collection of records for different top-level domains (TLDs) and adds specific records to a zone if the TLD matches a certain condition.
 *
 * @param array $tldRecords A collection of records for different TLDs, where each key is a TLD and the value is another array of records.
 * @param string $cleanedTld The TLD to filter records by.
 * @param Zone $zone The zone to add records to.
 */
            foreach ($tldRecords as $tld => $records)
            {
                if ($cleanedTld == $tld)
                {
                    foreach ($records as $recordType => $recordData)
                    {
                        addSpecificRecords($zone, $cleanedTld, $recordType, $recordData);
                    }
                }
            }

            $soa = new ResourceRecord;
            $soa->setName('@');
            $soa->setClass(Classes::INTERNET);
            $soa->setRdata(Factory::Soa($c['ns']['ns1'] . '.', $c['dns_soa'] . '.', $timestamp, 900, 1800, 3600000, 3600));
            $zone->addResourceRecord($soa);
            // Add A and AAAA records
            foreach ($c['ns'] as $ns)
            {
                $nsRecord = new ResourceRecord;
                $nsRecord->setName($cleanedTld . '.');
                $nsRecord->setClass(Classes::INTERNET);
                $nsRecord->setRdata(Factory::Ns($ns . '.'));
                $zone->addResourceRecord($nsRecord);
            }

            // Fetch domains for this TLD
            $sthDomains = $pdo->prepare('SELECT DISTINCT domain.id, domain.name FROM domain WHERE tldid = :id AND (exdate > CURRENT_TIMESTAMP OR rgpstatus = \'pendingRestore\') ORDER BY domain.name');

            $domainIds = [];
            $sthDomains->execute([':id' => $id]);
            while ($row = $sthDomains->fetch(PDO::FETCH_ASSOC))
            {
                $domainIds[] = $row['id'];
            }

            $statuses = [];
            if (count($domainIds) > 0)
            {
                $placeholders = implode(',', array_fill(0, count($domainIds) , '?'));
                $sthStatus = $pdo->prepare("SELECT domain_id, id FROM domain_status WHERE domain_id IN ($placeholders) AND status LIKE '%Hold'");
                $sthStatus->execute($domainIds);
                while ($row = $sthStatus->fetch(PDO::FETCH_ASSOC))
                {
                    $statuses[$row['domain_id']] = $row['id'];
                }
            }

            $sthDomains->execute([':id' => $id]);

            while (list($did, $dname) = $sthDomains->fetch(PDO::FETCH_NUM))
            {
                if (isset($statuses[$did])) continue;

                $dname_clean = $dname;
                $dname_clean = ($dname_clean == "$tld.") ? '@' : $dname_clean;

                // NS records for the domain
                $sthNsRecords = $pdo->prepare('SELECT DISTINCT host.name FROM domain_host_map INNER JOIN host ON domain_host_map.host_id = host.id WHERE domain_host_map.domain_id = :did');
                $sthNsRecords->execute([':did' => $did]);
                while (list($hname) = $sthNsRecords->fetch(PDO::FETCH_NUM))
                {
                    $nsRecord = new ResourceRecord;
                    $nsRecord->setName($dname_clean . '.');
                    $nsRecord->setClass(Classes::INTERNET);
                    $nsRecord->setRdata(Factory::Ns($hname . '.'));
                    $zone->addResourceRecord($nsRecord);
                }

                // A/AAAA records for the domain
                $sthHostRecords = $pdo->prepare("SELECT host.name, host_addr.ip, host_addr.addr FROM host INNER JOIN host_addr ON host.id = host_addr.host_id WHERE host.domain_id = :did ORDER BY host.name");
                $sthHostRecords->execute([':did' => $did]);
                while (list($hname, $type, $addr) = $sthHostRecords->fetch(PDO::FETCH_NUM))
                {
                    $hname_clean = $hname;
                    $hname_clean = ($hname_clean == "$tld.") ? '@' : $hname_clean;
                    $record = new ResourceRecord;
                    $record->setName($hname_clean . '.');
                    $record->setClass(Classes::INTERNET);

                    if ($type == 'v4')
                    {
                        $record->setRdata(Factory::A($addr));
                    }
                    else
                    {
                        $record->setRdata(Factory::AAAA($addr));
                    }

                    $zone->addResourceRecord($record);
                }

                // DS records for the domain
                $sthDS = $pdo->prepare("SELECT keytag, alg, digesttype, digest FROM secdns WHERE domain_id = :did");
                $sthDS->execute([':did' => $did]);
                while (list($keytag, $alg, $digesttype, $digest) = $sthDS->fetch(PDO::FETCH_NUM))
                {
                    $dsRecord = new ResourceRecord;
                    $dsRecord->setName($dname_clean . '.');
                    $dsRecord->setClass(Classes::INTERNET);
                    $dsRecord->setRdata(Factory::Ds($keytag, $alg, hex2bin($digest) , $digesttype));
                    $zone->addResourceRecord($dsRecord);
                }
            }

            if (isset($c['zone_mode']) && $c['zone_mode'] === 'nice')
            {
                $builder = new AlignedBuilder();
            }
            else
            {
                $builder = new ZoneBuilder();
            }

            $log->info('Building zone for TLD: ' . $cleanedTld . ', Builder type: ' . ($c['zone_mode'] === 'nice' ? 'AlignedBuilder' : 'ZoneBuilder'));
            $completed_zone = $builder->build($zone);

            // Log a truncated version of the completed zone content
            //$maxLogLength = 10000; // Maximum length for the log entry
            //$logContent = substr($completed_zone, 0, $maxLogLength);
            //$log->info("Completed zone content (truncated): " . $logContent);
            

            if ($c['dns_server'] == 'bind')
            {
                $basePath = '/var/lib/bind';
            }
            elseif ($c['dns_server'] == 'nsd')
            {
                $basePath = '/etc/nsd';
            }
            elseif ($c['dns_server'] == 'knot')
            {
                $basePath = '/etc/knot';
            }
            else
            {
                // Default path
                $basePath = '/var/lib/bind';
            }

            file_put_contents("{$basePath}/{$cleanedTld}.zone", $completed_zone);

            if ($c['dns_server'] == 'opendnssec')
           {
            chown("{$basePath}/{$cleanedTld}.zone", 'opendnssec');
            chgrp("{$basePath}/{$cleanedTld}.zone", 'opendnssec');
           }

        }

        if ($c['dns_server'] == 'bind')
        {
            exec("rndc reload {$cleanedTld}.", $output, $return_var);
            if ($return_var != 0)
            {
                $log->error('Failed to reload BIND. ' . $return_var);
            }

            exec("rndc notify {$cleanedTld}.", $output, $return_var);
            if ($return_var != 0)
            {
                $log->error('Failed to notify secondary servers. ' . $return_var);
            }
        }
        elseif ($c['dns_server'] == 'nsd')
        {
            exec("nsd-control reload", $output, $return_var);
            if ($return_var != 0)
            {
                $log->error('Failed to reload NSD. ' . $return_var);
            }
        }
        elseif ($c['dns_server'] == 'knot')
        {
            exec("knotc reload", $output, $return_var);
            if ($return_var != 0)
            {
                $log->error('Failed to reload Knot DNS. ' . $return_var);
            }

            exec("knotc zone-notify {$cleanedTld}.", $output, $return_var);
            if ($return_var != 0)
            {
                $log->error('Failed to notify secondary servers. ' . $return_var);
            }
        }
        elseif ($c['dns_server'] == 'opendnssec')
        {
            //exec("ods-signer sign {$cleanedTld}");
            //exec("ods-signer sign -a"); 
                                                        //i'm using explicit bellow for some reason only explicit works for me :)
            exec("ods-signer sign example.com");
            exec("ods-signer sign ns1.example.com");
            exec("ods-signer sign ns2.example.com");
            exec("ods-signer sign cp.example.com");
            exec("ods-signer sign whois.example.com");
            exec("ods-signer sign rdap.example.com");
            exec("ods-signer sign epp.example.com");



            sleep(10);
           // copy("/var/lib/opendnssec/signed/{$cleanedTld}", "/var/lib/bind/{$cleanedTld}.zone.signed");
            copy("/var/lib/opendnssec/signed/example.com", "/var/lib/bind/example.com.zone.signed");
            copy("/var/lib/opendnssec/signed/ns1.example.com", "/var/lib/bind/ns1.example.com.zone.signed");
            copy("/var/lib/opendnssec/signed/ns2.example.com", "/var/lib/bind/ns2.example.com.zone.signed");
            copy("/var/lib/opendnssec/signed/cp.example.com", "/var/lib/bind/cp.example.com.zone.signed");
            copy("/var/lib/opendnssec/signed/whois.example.com", "/var/lib/bind/whois.example.com.zone.signed");
            copy("/var/lib/opendnssec/signed/rdap.example.com", "/var/lib/bind/rdap.example.com.zone.signed");
            copy("/var/lib/opendnssec/signed/epp.example.com", "/var/lib/bind/epp.example.com.zone.signed");


            //exec("rndc reload {$cleanedTld}.", $output, $return_var);
            exec("rndc reload example.com.", $output, $return_var);
            exec("rndc reload ns1.example.com.", $output, $return_var);
            exec("rndc reload ns2.example.com.", $output, $return_var);
            exec("rndc reload cp.example.com.", $output, $return_var);
            exec("rndc reload whois.example.com.", $output, $return_var);
            exec("rndc reload rdap.example.com.", $output, $return_var);
            exec("rndc reload epp.example.com.", $output, $return_var);

            if ($return_var != 0)
            {
                $log->error('Failed to reload BIND. ' . $return_var);
            }

           // exec("rndc notify {$cleanedTld}.", $output, $return_var);
            exec("rndc notify example.com.", $output, $return_var);
            exec("rndc notify ns1.example.com.", $output, $return_var);
            exec("rndc notify ns2.example.com.", $output, $return_var);
            exec("rndc notify cp.example.com.", $output, $return_var);
            exec("rndc notify whois.example.com.", $output, $return_var);
            exec("rndc notify rdap.example.com.", $output, $return_var);
            exec("rndc notify epp.example.com.", $output, $return_var);

 
            if ($return_var != 0)
            {
                $log->error('Failed to notify secondary servers. ' . $return_var);
            }
        }
        else
        {
            // Default
            exec("rndc reload {$cleanedTld}.", $output, $return_var);
            if ($return_var != 0)
            {
                $log->error('Failed to reload BIND. ' . $return_var);
            }

            exec("rndc notify {$cleanedTld}.", $output, $return_var);
            if ($return_var != 0)
            {
                $log->error('Failed to notify secondary servers. ' . $return_var);
            }
        }

        $log->info('job finished successfully.');
    }
    catch(PDOException $e)
    {
        $log->error('Database error: ' . $e->getMessage());
    }
    catch(Throwable $e)
    {
        $log->error('Error: ' . $e->getMessage());
    }
    finally
    {
        // Return the connection to the pool
        $pool->put($pdo);
    }
});
