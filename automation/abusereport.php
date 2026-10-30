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

require __DIR__ . '/vendor/autoload.php';

$c = require_once 'config.php';
require_once 'helpers.php';

$logFilePath = '/var/log/namingo/abusereport.log';
$log = setupLogger($logFilePath, 'Abuse_Report');
$log->info('Job started.');

try {
    $dsn = "{$c['db_type']}:host={$c['db_host']};dbname={$c['db_database']};port={$c['db_port']}";
    $dbh = new PDO($dsn, $c['db_username'], $c['db_password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    $settingsStmt = $dbh->query("
        SELECT name, value FROM settings
        WHERE name IN ('email', 'phone', 'company_name')
    ");
    $settings = $settingsStmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $supportEmail = $settings['email'] ?? 'default-support@example.com';
    $supportPhoneNumber = $settings['phone'] ?? '+1.23456789';
    $registryName = $settings['company_name'] ?? 'Example Registry LLC';
} catch (PDOException $e) {
    $log->error('DB Connection failed: ' . $e->getMessage());
    exit(1);
}

function escapeReport($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Tickets are scoped by the registered domain's CURRENT TLD and sponsoring registrar,
// not by the ticket creator's panel account (which may be a registry administrator).
function getAbuseTickets(PDO $dbh, int $tldId, string $start, string $end, ?int $registrarId): array
{
    $query = "SELECT s.id, s.reported_domain, s.nature_of_abuse, s.status, s.priority,
                     s.date_of_incident, s.date_created, s.last_updated
              FROM support_tickets s
              JOIN domain d ON d.name = s.reported_domain
              WHERE s.category_id = 8 AND d.tldid = :tldId
                AND ((s.date_created >= :startDate AND s.date_created < :endDate)
                  OR (s.last_updated >= :startUpdated AND s.last_updated < :endUpdated))";
    $params = [':tldId' => $tldId, ':startDate' => $start, ':endDate' => $end,
        ':startUpdated' => $start, ':endUpdated' => $end];
    if ($registrarId !== null) {
        $query .= ' AND d.clid = :registrarId'
            . ' AND (d.trdate IS NULL OR s.date_created >= d.trdate)';
        $params[':registrarId'] = $registrarId;
    }
    $query .= ' ORDER BY s.date_created, s.id';

    $stmt = $dbh->prepare($query);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function countSponsoredDomains(PDO $dbh, int $tldId, ?int $registrarId): int
{
    $sql = 'SELECT COUNT(*) FROM domain WHERE tldid = :tldId';
    $params = [':tldId' => $tldId];
    if ($registrarId !== null) {
        $sql .= ' AND clid = :registrarId';
        $params[':registrarId'] = $registrarId;
    }
    $stmt = $dbh->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

function reportContent(array $tickets, string $uuid, string $start, string $end, int $domainCount): string
{
    $counts = array_count_values(array_map(
        static fn(array $ticket): string => (string)($ticket['status'] ?? 'Unknown'),
        $tickets
    ));
    $total = count($tickets);
    $rows = '<table width="100%" cellpadding="8" cellspacing="0" style="border-collapse:collapse; border:1px solid #d9e3de;">'
        . '<tr style="background:#edf4f0;"><td>Tickets created / updated</td><td><strong>' . $total . '</strong></td></tr>'
        . '<tr><td>Open / In progress</td><td>' . (($counts['Open'] ?? 0) + ($counts['In Progress'] ?? 0)) . '</td></tr>'
        . '<tr><td>Resolved / Closed</td><td>' . (($counts['Resolved'] ?? 0) + ($counts['Closed'] ?? 0)) . '</td></tr>'
        . '<tr><td>Currently sponsored domains (not scan count)</td><td>' . $domainCount . '</td></tr>'
        . '</table>';

    $types = [];
    foreach ($tickets as $ticket) {
        $type = trim((string)($ticket['nature_of_abuse'] ?? ''));
        $type = $type === '' ? 'Unclassified' : $type;
        $types[$type] = ($types[$type] ?? 0) + 1;
    }
    if ($types) {
        arsort($types);
        $rows .= '<h2>Abuse categories (ticket count)</h2><table width="100%" cellpadding="6" cellspacing="0">';
        foreach ($types as $type => $count) {
            $rows .= '<tr><td>' . escapeReport($type) . '</td><td>' . $count . '</td></tr>';
        }
        $rows .= '</table>';
    }

    $rows .= '<p style="font-size:12px;color:#65746b;">UTC period: ' . escapeReport($start) . ' to ' . escapeReport($end)
        . ' (end exclusive)<br>Report UUID: <strong>' . escapeReport($uuid) . '</strong></p>';

    if (!$tickets) {
        $rows .= '<p style="padding:14px;background:#f1f5f2;">No abuse tickets were created or updated during this reporting period.</p>';
    } else {
        $rows .= '<h2>Reported cases</h2>';
        foreach ($tickets as $ticket) {
            $rows .= '<table width="100%" cellpadding="7" cellspacing="0" style="margin-bottom:16px;border:1px solid #dae3dd;border-collapse:collapse;">';
            foreach ([
                'Ticket' => '#' . $ticket['id'],
                'Reported domain' => $ticket['reported_domain'],
                'Nature of abuse' => $ticket['nature_of_abuse'],
                'Status' => $ticket['status'],
                'Priority' => $ticket['priority'],
                'Incident date' => $ticket['date_of_incident'],
                'Reported at (UTC)' => $ticket['date_created'],
                'Last updated (UTC)' => $ticket['last_updated'],
            ] as $label => $value) {
                $rows .= '<tr><td style="width:34%;color:#596c60;border-bottom:1px solid #e5ebe7;">'
                    . escapeReport($label) . '</td><td style="border-bottom:1px solid #e5ebe7;word-break:break-word;">'
                    . escapeReport($value) . '</td></tr>';
            }
            $rows .= '</table>';
        }
    }

    $rows .= '<p style="font-size:12px;color:#65746b;">This report summarizes abuse tickets created or updated '
        . 'for currently registered domains. Ticket status is not proof of mitigation, and '
        . 'these figures do not establish the number of domains technically scanned. '
        . 'Maintain the separate technical-analysis evidence and action records required by ICANN Specification 11(3)(b).</p>';
    return $rows;
}

function generateReportHTML(
    string $content,
    string $scope,
    string $registryName,
    string $supportEmail,
    string $supportPhoneNumber
): string {
    return renderEmailTemplate('abusereport.html', [
        'registry_name' => $registryName,
        'report_scope' => $scope,
        'report_date' => gmdate('Y-m-d H:i:s') . ' UTC',
        'report_content' => $content,
        'support_email' => $supportEmail,
        'support_phone' => $supportPhoneNumber,
    ], ['report_content']);
}

function newReportUUID(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4),
        substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
}

// Keep untrusted ticket fields from being interpreted as Markdown formatting.
function pdfReportValue($value): string
{
    $text = trim(preg_replace('/\s+/u', ' ', (string)($value ?? '')) ?? '');
    if ($text === '') {
        return 'Not provided';
    }
    return str_replace(
        ['\\', '`', '*', '_', '[', ']', '#', '<', '>', '|'],
        ['\\\\', '\\`', '\\*', '\\_', '\\[', '\\]', '\\#', '\\<', '\\>', '\\|'],
        $text
    );
}

function saveReportPdf(
    string $folder, string $name, string $scope, string $uuid,
    array $tickets, string $start, string $end, int $domainCount
): string {
    if (!is_dir($folder) && !mkdir($folder, 0700, true) && !is_dir($folder)) {
        throw new RuntimeException('Cannot create Spec 11 report archive: ' . $folder);
    }

    // dragonofmercy/phppdf is MIT licensed and requires PHP 8.4+.
    // A bundled Unicode font covers international registrar names and ticket text.
    $doc = new \DragonOfMercy\PhpPdf\Document();
    $font = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';
    $bold = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';
    if (!is_readable($font) || !is_readable($bold)) {
        throw new RuntimeException('DejaVu Sans fonts are required for Spec 11 PDFs');
    }
    $doc->registerFontFamily('Report', regular: $font, bold: $bold);
    $doc->setDefaultFont(\DragonOfMercy\PhpPdf\Font::custom('Report'), 10);
    $doc->setMargins(new \DragonOfMercy\PhpPdf\PageMargins(top: 30, right: 18, bottom: 22, left: 18));
    $doc->setAutoPageBreak(true);
    $doc->metadata()->title('DNS Abuse Activity Report ' . $uuid)
        ->subject('TLD and registrar scoped abuse ticket activity')
        ->creator('Namingo Registry');

    $doc->setHeader(static function (\DragonOfMercy\PhpPdf\Page $page): void {
        $page->setFont(\DragonOfMercy\PhpPdf\Font::custom('Report')->bold(), 10);
        $page->setFillColor(\DragonOfMercy\PhpPdf\Color::rgb(43, 78, 60));
        $page->text(18, 17, 'NAMINGO  /  DNS ABUSE MONITORING');
        $page->setStrokeColor(\DragonOfMercy\PhpPdf\Color::rgb(119, 157, 135))
            ->setLineWidth(0.35);
        $page->line(18, 21, 192, 21)->stroke();
    });
    // A real per-page UUID stamp, rather than only an ID on the cover page.
    $doc->setFooter(static function (\DragonOfMercy\PhpPdf\Page $page, int $n, int $total) use ($uuid): void {
        $page->setFont(\DragonOfMercy\PhpPdf\Font::custom('Report'), 7);
        $page->setFillColor(\DragonOfMercy\PhpPdf\Color::rgb(100, 118, 108));
        $page->text(18, 284, 'Report UUID: ' . $uuid);
        $label = "Page {$n} / {$total}";
        $page->text(192 - $page->stringWidth($label), 284, $label);
    });

    $statuses = array_count_values(array_map(
        static fn(array $t): string => (string)($t['status'] ?? 'Unknown'), $tickets
    ));
    $types = [];
    foreach ($tickets as $ticket) {
        $type = trim((string)($ticket['nature_of_abuse'] ?? '')) ?: 'Unclassified';
        $types[$type] = ($types[$type] ?? 0) + 1;
    }
    arsort($types);

    $markdown = "# DNS Abuse Activity Report\n\n"
        . '**Scope:** ' . pdfReportValue($scope) . "\n\n"
        . '**UTC period:** ' . $start . ' to ' . $end . " (end exclusive)\n\n"
        . '**Report UUID:** ' . $uuid . "\n\n"
        . "## Activity at a glance\n\n"
        . '- Tickets created or updated: **' . count($tickets) . "**\n"
        . '- Open / in progress: **' . (($statuses['Open'] ?? 0) + ($statuses['In Progress'] ?? 0)) . "**\n"
        . '- Resolved / closed: **' . (($statuses['Resolved'] ?? 0) + ($statuses['Closed'] ?? 0)) . "**\n"
        . '- Currently sponsored domains (not scan count): **' . $domainCount . "**\n\n";

    if ($types) {
        $markdown .= "## Abuse categories\n\n";
        foreach ($types as $type => $count) {
            $markdown .= '- ' . pdfReportValue($type) . ': **' . $count . "**\n";
        }
        $markdown .= "\n";
    }

    $markdown .= "## Reported cases\n\n";
    if (!$tickets) {
        $markdown .= "No abuse tickets were created or updated during the reporting period.\n\n";
    }
    foreach ($tickets as $ticket) {
        $markdown .= '### Ticket #' . (int)$ticket['id'] . "\n\n";
        foreach ([
            'Reported domain' => $ticket['reported_domain'],
            'Nature of abuse' => $ticket['nature_of_abuse'],
            'Status' => $ticket['status'],
            'Priority' => $ticket['priority'],
            'Incident date' => $ticket['date_of_incident'],
            'Reported at (UTC)' => $ticket['date_created'],
            'Last updated (UTC)' => $ticket['last_updated'],
        ] as $label => $value) {
            $markdown .= '- **' . $label . ':** ' . pdfReportValue($value) . "\n";
        }
        $markdown .= "\n";
    }
    $markdown .= "## Interpretation and records\n\n"
        . 'This report summarizes tickets for currently registered domains. Ticket status is not '
        . 'proof of mitigation. The domain count does not establish the number of names technically '
        . 'scanned. Maintain separate technical-analysis evidence and mitigation-action records '
        . 'as required by ICANN Specification 11(3)(b).';

    $doc->addPage()->markdown($markdown, width: 174.0);
    $path = $folder . '/' . $name;
    $doc->save($path);
    chmod($path, 0600);
    if (file_get_contents($path, false, null, 0, 5) !== '%PDF-') {
        throw new RuntimeException('PDF generation failed: ' . $path);
    }
    return $path;
}

// Queue a mail with an archive-relative PDF attachment. The worker loads it
// from the same reporting directory (no binary data is placed in Redis).
function sendEmail(string $toEmail, string $subject, string $htmlContent, string $attachment): bool
{
    global $log, $c;
    $data = [
        'type' => 'sendmail',
        'toEmail' => $toEmail,
        'subject' => $subject,
        'body' => $htmlContent,
        'attachment' => $attachment,
    ];
    $body = json_encode($data, JSON_THROW_ON_ERROR);

    $curl = curl_init('http://127.0.0.1:8250');
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . ($c['msg_api_token'] ?? ''),
        ],
    ]);
    $response = curl_exec($curl);
    $httpCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    if ($response === false || $httpCode !== 202) {
        $log->error('Failed to queue abuse report to ' . $toEmail
            . ': ' . ($response === false ? curl_error($curl) : "HTTP $httpCode"));
        curl_close($curl);
        return false;
    }
    curl_close($curl);
    return true;
}

try {
    $utc = new DateTimeZone('UTC');
    $end = new DateTimeImmutable('today', $utc);
    $start = $end->modify('-1 day');
    $startDate = $start->format('Y-m-d H:i:s');
    $endDate = $end->format('Y-m-d H:i:s');
    $archiveBase = rtrim($c['reporting_path'] ?? '/opt/reporting', '/') . '/spec11/' . $start->format('Y-m-d');
    $tlds = $dbh->query('SELECT id, tld FROM domain_tld ORDER BY tld')->fetchAll();
    $registrars = $dbh->query('SELECT id, name, email FROM registrar ORDER BY id')->fetchAll();
    $admins = $dbh->query('SELECT email FROM users WHERE roles_mask = 0')->fetchAll(PDO::FETCH_COLUMN);
    $failures = 0;

    foreach ($tlds as $tld) {
        // A registry-level report and an independent report for each registrar.
        // Namingo has no registrar/TLD access map; TLDs are registry-wide.
        $scopes = [['id' => null, 'name' => 'Registry-wide', 'recipients' => $admins]];
        foreach ($registrars as $registrar) {
            $scopes[] = [
                'id' => (int)$registrar['id'],
                'name' => $registrar['name'],
                'recipients' => [$registrar['email']],
            ];
        }

        foreach ($scopes as $scope) {
            try {
                $tldName = (string)$tld['tld'];
                $scopeName = $tldName . ' | ' . $scope['name'];
                $tickets = getAbuseTickets($dbh, (int)$tld['id'], $startDate, $endDate, $scope['id']);
                $domainCount = countSponsoredDomains($dbh, (int)$tld['id'], $scope['id']);
                $uuid = newReportUUID();
                $content = reportContent($tickets, $uuid, $startDate, $endDate, $domainCount);
                $slug = preg_replace('/[^a-z0-9-]+/', '-', strtolower(ltrim($tldName, '.')));
                $fileName = 'spec11-' . $slug . '-' . ($scope['id'] === null ? 'registry' : 'registrar-' . $scope['id'])
                    . '-' . $uuid . '.pdf';
                $pdf = saveReportPdf($archiveBase, $fileName, $scopeName, $uuid, $tickets, $startDate, $endDate, $domainCount);
                $relativePath = 'spec11/' . $start->format('Y-m-d') . '/' . basename($pdf);
                $html = generateReportHTML($content, $scopeName, $registryName, $supportEmail, $supportPhoneNumber);
                $subject = "DNS Abuse Report | $tldName | " . $start->format('Y-m-d') . " | $uuid";

                foreach (array_unique($scope['recipients']) as $recipient) {
                    if (!sendEmail($recipient, $subject, $html, $relativePath)) {
                        $failures++;
                    } else {
                        $log->info("Queued Spec 11 report $uuid to $recipient ($scopeName)");
                    }
                }
            } catch (Throwable $e) {
                $failures++;
                $log->error('Report failed for ' . $tld['tld'] . ' / ' . $scope['name'] . ': ' . $e->getMessage());
            }
        }
    }

    if ($failures) {
        $log->error("Job finished with $failures failure(s).");
        exit(1);
    }
    $log->info('Job finished successfully.');
} catch (Throwable $e) {
    $log->error('Error: ' . $e->getMessage());
    exit(1);
}
