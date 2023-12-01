<?php

$c = require_once 'config.php';
require_once 'helpers.php';

// Connect to the database
$dsn = "{$c['db_type']}:host={$c['db_host']};dbname={$c['db_database']};port={$c['db_port']}";
$logFilePath = '/var/log/namingo/send_invoice.log';
$log = setupLogger($logFilePath, 'Invoice_Generator');
$log->info('job started.');

try {
    $pdo = new PDO($dsn, $c['db_username'], $c['db_password']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    $log->error('DB Connection failed: ' . $e->getMessage());
}

$previous = date("Y-m", strtotime("first day of previous month"));

try {
    // Fetch registrars and their contact IDs where type is 'billing'
    $stmt = $pdo->prepare("
        SELECT r.id, r.name, rc.id AS billing_contact_id, rc.org as registrar_name, rc.email AS billing_email
        FROM registrar r
        LEFT JOIN registrar_contact rc ON r.id = rc.registrar_id AND rc.type = 'billing'
    ");
    $stmt->execute();
    $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($result as $row) {
        $startDate = $previous . "-01";
        $combinedStmt = $pdo->prepare("SELECT COUNT(id) AS trans, SUM(amount) AS total FROM statement WHERE registrar_id = :registrarId AND date BETWEEN :startDate AND LAST_DAY(:startDate)");
        $combinedStmt->bindParam(':registrarId', $row['id'], PDO::PARAM_INT);
        $combinedStmt->bindParam(':startDate', $startDate);
        $combinedStmt->execute();
        $combinedResult = $combinedStmt->fetch(PDO::FETCH_ASSOC);

        $transactionsCount = $combinedResult['trans'] ?? 0;
        $totalAmount = $combinedResult['total'] ?? '0';

        if ($transactionsCount > 0) {
            // Prepare and execute insert statement
            $insertStmt = $pdo->prepare("INSERT INTO invoices (registrar_id, billing_contact_id, issue_date, due_date, total_amount, payment_status, created_at) VALUES (:registrarId, :billingContactId, :issueDate, :dueDate, :totalAmount, :paymentStatus, :createdAt)");
            
            $currentDateTime = new DateTime(); // Current date and time
            $currentDateTimeFormatted = $currentDateTime->format('Y-m-d H:i:s.u'); // Format with microseconds

            $dueDateTime = (new DateTime())->modify('+30 days'); // Current date and time plus 30 days
            $dueDateTimeFormatted = $dueDateTime->format('Y-m-d H:i:s.u'); // Format with microseconds

            // Truncate microseconds to milliseconds
            $currentDateTimeMilliseconds = substr($currentDateTimeFormatted, 0, 23);
            $dueDateTimeMilliseconds = substr($dueDateTimeFormatted, 0, 23);

            $paymentStatus = 'unpaid';

            $insertStmt->bindParam(':registrarId', $row['id'], PDO::PARAM_INT);
            $insertStmt->bindParam(':billingContactId', $row['billing_contact_id'], PDO::PARAM_INT);
            $insertStmt->bindParam(':issueDate', $currentDateTimeMilliseconds);
            $insertStmt->bindParam(':dueDate', $dueDateTimeMilliseconds);
            $insertStmt->bindParam(':totalAmount', $totalAmount, PDO::PARAM_STR);
            $insertStmt->bindParam(':paymentStatus', $paymentStatus);
            $insertStmt->bindParam(':createdAt', $currentDateTimeMilliseconds);
            $insertStmt->execute();
            
            $invoiceNumber = $pdo->lastInsertId();
            $currentDateFormatted = date("Ymd"); // This will format the date as 'YYYYMMDD'
            $invoiceIdFormatted = "I" . $invoiceNumber . "-" . $currentDateFormatted;

            $issueDate = date("Y-m-d");
            $dueDate = date("Y-m-d", strtotime("+30 days"));

            // Prepare the email content
            $subject = "New Invoice Notification - " . $issueDate;
            $body = "Dear " . $row['registrar_name'] . ",\n\n" .
                    "We hope this message finds you well.\n\n" .
                    "We are writing to inform you that a new invoice has been generated for the period of " . $issueDate . ". The details of the invoice are as follows:\n\n" .
                    "- Invoice Number: " . $invoiceIdFormatted . "\n" .
                    "- Issue Date: " . $issueDate . "\n" .
                    "- Due Date: " . $dueDate . "\n" .
                    "- Total Amount: " . $totalAmount . "\n\n" .
                    "The invoice is available in your account for review and payment. Please ensure that the payment is made by the due date to avoid any late fees or service interruptions.\n\n" .
                    "Should you have any questions or require further assistance, please do not hesitate to contact us at [Your Contact Information].\n\n" .
                    "Thank you for your prompt attention to this matter.\n\n" .
                    "Warm regards,\n\n" .
                    "[Your Company Name]\n" .
                    "[Your Contact Details]";

            // Prepare the data array for the cURL request
            $data = [
                'type' => 'sendmail', 
                'subject' => $subject,
                'body' => $body,
                'toEmail' => $row['billing_email'],
            ];
            
            $url = 'http://127.0.0.1:8250';

            $options = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST  => 'POST',
                CURLOPT_POSTFIELDS     => json_encode($data),
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'Content-Length: ' . strlen(json_encode($data))
                ],
            ];

            $curl = curl_init($url);
            curl_setopt_array($curl, $options);

            $response = curl_exec($curl);

            if ($response === false) {
                throw new Exception(curl_error($curl), curl_errno($curl));
            }

            curl_close($curl);

            $log->info('job finished successfully.');
        }
    }
} catch (PDOException $e) {
    $log->error('Database error: ' . $e->getMessage());
} catch (Throwable $e) {
    $log->error('Error: ' . $e->getMessage());
}