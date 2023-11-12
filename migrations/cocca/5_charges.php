<?php

require_once 'helpers.php';

$inputFile = fopen('Domains.csv', 'r');
$outputFile = fopen('renewals.csv', 'w');

$fields = ['domain_name', 'txn_on', 'charge', 'renewal_years', 'client_charged', 'client_trid', 'autorenewal', 'vat_amount'];

// Write the header to the output file
fputcsv($outputFile, $fields);

$headers = fgetcsv($inputFile);

while (($row = fgetcsv($inputFile)) !== false) {
    $data = array_combine($headers, $row);

    $status = $data['status'];
    if (isEligibleForRenewal($status)) {
        $filteredRow = [
            'domain_name' => $data['name'] ?? '',
            'txn_on' => $data['charge_on'] ? date('c', strtotime($data['charge_on'])) : null,
            'charge' => floatval($data['charge'] ?? 0.0),
            'renewal_years' => intval($data['renewal_years'] ?? 0),
            'client_charged' => $data['new_client'] ?? '',
            'client_trid' => $data['client_trid'] ?? '',
            'autorenewal' => parseBool($data['autorenewal'] ?? ''),
            'vat_amount' => intval($data['vat_amount'] ?? 0),
        ];

        fputcsv($outputFile, $filteredRow);
    }
}

fclose($inputFile);
fclose($outputFile);