<?php

// A client's running account statement — every invoice and payment in a
// chosen date range, plus the balance brought forward from everything
// before it, rendered onto one PDF via the same dompdf pipeline as invoices
// (generateInvoicePdf() — see its cid:logo_cid handling). Extension of the
// Tax Email machinery in lib/tax_email.php: same date-range validation
// (enxureValidTaxEmailDate) and the same "build bundle, then either stream
// it or email it" shape as enxureBuildTaxEmailZip()/enxureHandleSendTaxEmail().

function enxureBuildClientStatementRows($mysqli, string $clientKey, string $startStr, string $endStr): array
{
    $ck = $mysqli->real_escape_string($clientKey);

    // enxure_invoices.paid_amount/paid_at is the authoritative "how much has
    // this invoice had paid, and when" (see reconcile_payment_totals in
    // enxure.php, which treats it as the source of truth to reconcile
    // enxure_payments against) — enxure_payments itself is only a ledger of
    // individual installments, and demo-seeded/legacy/imported invoices can
    // carry a paid_amount with no matching enxure_payments rows at all.
    // Relying on enxure_payments alone (as an earlier version of this
    // function did) silently drops every such payment from the statement.
    $invoices = [];
    $res = $mysqli->query("SELECT id, invoice_number, invoice_date, amount, paid_amount, paid_at FROM enxure_invoices WHERE client_key = '$ck' AND is_quote = 0 AND status != 'void'");
    while ($r = $res->fetch_assoc()) {
        $invoices[$r['id']] = $r;
    }

    $loggedByInvoice = [];
    if (!empty($invoices)) {
        $ids = implode(',', array_keys($invoices));
        $res = $mysqli->query("SELECT invoice_id, amount, paid_at, note FROM enxure_payments WHERE invoice_id IN ($ids) ORDER BY paid_at ASC");
        while ($r = $res->fetch_assoc()) {
            $loggedByInvoice[$r['invoice_id']][] = $r;
        }
    }

    // One event per logged installment, plus — when the cached paid_amount
    // exceeds what's actually logged for that invoice — one synthetic event
    // for the untracked remainder, dated at the invoice's own paid_at since
    // that's the only date available for it.
    $paymentEvents = [];
    foreach ($invoices as $id => $inv) {
        $logged = $loggedByInvoice[$id] ?? [];
        foreach ($logged as $p) {
            $paymentEvents[] = ['date' => substr($p['paid_at'], 0, 10), 'invoice_number' => $inv['invoice_number'], 'amount' => (float) $p['amount'], 'note' => $p['note']];
        }
        $gap = round((float) ($inv['paid_amount'] ?? 0) - array_sum(array_column($logged, 'amount')), 2);
        if ($gap > 0.004 && !empty($inv['paid_at'])) {
            $paymentEvents[] = ['date' => substr($inv['paid_at'], 0, 10), 'invoice_number' => $inv['invoice_number'], 'amount' => $gap, 'note' => ''];
        }
    }

    $openingInvoiced = 0.0;
    $openingPaid = 0.0;
    $rows = [];
    foreach ($invoices as $inv) {
        $invDate = substr($inv['invoice_date'], 0, 10);
        if ($invDate < $startStr) {
            $openingInvoiced += (float) $inv['amount'];
        } elseif ($invDate <= $endStr) {
            $rows[] = ['date' => $invDate, 'description' => 'Invoice ' . $inv['invoice_number'], 'ref' => $inv['invoice_number'], 'invoiced' => (float) $inv['amount'], 'paid' => 0.0];
        }
    }
    foreach ($paymentEvents as $p) {
        if ($p['date'] < $startStr) {
            $openingPaid += $p['amount'];
        } elseif ($p['date'] <= $endStr) {
            $description = 'Payment received — invoice ' . $p['invoice_number'];
            if (trim((string) $p['note']) !== '') {
                $description .= ' (' . $p['note'] . ')';
            }
            $rows[] = ['date' => $p['date'], 'description' => $description, 'ref' => $p['invoice_number'], 'invoiced' => 0.0, 'paid' => $p['amount']];
        }
    }

    $openingBalance = $openingInvoiced - $openingPaid;
    usort($rows, fn($a, $b) => $a['date'] <=> $b['date']);

    $balance = $openingBalance;
    foreach ($rows as &$row) {
        $balance += $row['invoiced'] - $row['paid'];
        $row['balance'] = $balance;
    }
    unset($row);

    return ['opening_balance' => $openingBalance, 'closing_balance' => $balance, 'rows' => $rows];
}

function enxureRenderClientStatementHtml(array $client, array $settings, string $startStr, string $endStr, array $statement, string $currencyCode): string
{
    $brandColor = $settings['brand_color'] ?? '#4a90e2';
    $businessName = htmlspecialchars($settings['business_name'] ?? 'enXure');
    $footerText = trim($settings['footer_text'] ?? '');
    $clientName = htmlspecialchars($client['client_name']);
    $money = fn(float $n) => $currencyCode . ' $' . number_format($n, 2);

    $rowsHtml = '';
    foreach ($statement['rows'] as $row) {
        $rowsHtml .= '<tr><td>' . htmlspecialchars($row['date']) . '</td><td>' . htmlspecialchars($row['description']) . '</td>'
            . '<td>' . ($row['invoiced'] > 0 ? $money($row['invoiced']) : '') . '</td>'
            . '<td>' . ($row['paid'] > 0 ? $money($row['paid']) : '') . '</td>'
            . '<td>' . $money($row['balance']) . '</td></tr>';
    }
    if ($rowsHtml === '') {
        $rowsHtml = '<tr><td colspan="5" style="text-align:center;color:#888;">No activity in this period.</td></tr>';
    }

    $footerHtml = $footerText !== '' ? '<p>' . nl2br(htmlspecialchars($footerText)) . '</p>' : '';

    $style = "body {font-family: \"Segoe UI\", Tahoma, Geneva, Verdana, sans-serif; margin: 40px 80px; font-size: 15px; color: #333;} .header {display: flex; align-items: center; justify-content: space-between; margin-bottom: 30px; border-bottom: 2px solid {$brandColor}; padding-bottom: 10px;} .header h2 {margin: 0; font-size: 30px; font-weight: 700; color: #2c3e50;} .header img {height: 80px; max-width: 20%; object-fit: contain;} .meta p {margin: 3px 0; color: #555;} table {width: 100%; border-collapse: collapse; margin-top: 20px;} th, td {border: 1px solid #ddd; padding: 10px 12px; text-align: left;} th {background: {$brandColor}; color: #fff; font-weight: 600; text-transform: uppercase; font-size: 12px; letter-spacing: 0.04em;} td:nth-child(3), td:nth-child(4), td:nth-child(5), th:nth-child(3), th:nth-child(4), th:nth-child(5) {text-align: right;} .balance-row td {font-weight: 700; background: #f4f9ff;} .footer {margin-top: 30px; font-size: 13px; color: #555;}";

    $openingLabel = (new DateTime($startStr))->modify('-1 day')->format('Y-m-d');

    return <<<HTML
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8" /><title>Statement of Account</title>
<style>{$style}</style></head>
<body>
<div class="header"><h2>Statement of Account</h2><img src="cid:logo_cid" alt="Logo" /></div>
<div class="meta">
<p><strong>{$businessName}</strong></p>
<p><strong>Statement for:</strong> {$clientName}</p>
<p><strong>Period:</strong> {$startStr} to {$endStr}</p>
</div>
<table><thead><tr><th>Date</th><th>Description</th><th>Invoiced</th><th>Paid</th><th>Balance</th></tr></thead>
<tbody>
<tr class="balance-row"><td>{$openingLabel}</td><td>Balance brought forward</td><td></td><td></td><td>{$money($statement['opening_balance'])}</td></tr>
{$rowsHtml}
<tr class="balance-row"><td colspan="4">Balance due as of {$endStr}</td><td>{$money($statement['closing_balance'])}</td></tr>
</tbody></table>
<div class="footer">{$footerHtml}</div>
</body></html>
HTML;
}

function enxureHandlePreviewClientStatement($mysqli, array $settings): void
{
    $clientKey = trim($_POST['client_key'] ?? '');
    $client = $clientKey !== '' ? $mysqli->query("SELECT * FROM enxure_clients WHERE client_key = '" . $mysqli->real_escape_string($clientKey) . "'")->fetch_assoc() : null;
    if (!$client) {
        echo json_encode(['success' => false, 'error' => 'Select a client.']);
        exit;
    }
    $startStr = enxureValidTaxEmailDate($_POST['start_date'] ?? null) ?: date('Y-m-01');
    $endStr = enxureValidTaxEmailDate($_POST['end_date'] ?? null) ?: date('Y-m-d');
    if ($startStr > $endStr) {
        echo json_encode(['success' => false, 'error' => 'Start date must be on or before the end date.']);
        exit;
    }

    $statement = enxureBuildClientStatementRows($mysqli, $clientKey, $startStr, $endStr);
    echo json_encode([
        'success' => true,
        'client_name' => $client['client_name'],
        'client_email' => $client['email'],
        'currency' => enxureResolveCurrency($client['currency'] ?? '', $settings),
        'start_date' => $startStr,
        'end_date' => $endStr,
        'opening_balance' => $statement['opening_balance'],
        'closing_balance' => $statement['closing_balance'],
        'rows' => $statement['rows'],
    ]);
    exit;
}

function enxureHandleClientStatementPdfExport($mysqli, array $settings): void
{
    $clientKey = trim($_GET['client_key'] ?? '');
    $client = $clientKey !== '' ? $mysqli->query("SELECT * FROM enxure_clients WHERE client_key = '" . $mysqli->real_escape_string($clientKey) . "'")->fetch_assoc() : null;
    if (!$client) {
        http_response_code(404);
        exit('Client not found');
    }
    $startStr = enxureValidTaxEmailDate($_GET['start'] ?? null) ?: date('Y-m-01');
    $endStr = enxureValidTaxEmailDate($_GET['end'] ?? null) ?: date('Y-m-d');

    $statement = enxureBuildClientStatementRows($mysqli, $clientKey, $startStr, $endStr);
    $currencyCode = enxureResolveCurrency($client['currency'] ?? '', $settings);
    $html = enxureRenderClientStatementHtml($client, $settings, $startStr, $endStr, $statement, $currencyCode);
    try {
        $pdf = generateInvoicePdf($html);
    } catch (Throwable $e) {
        http_response_code(500);
        exit('Failed to generate PDF: ' . $e->getMessage());
    }
    $filename = 'Statement-' . preg_replace('/[^\w\-]/', '_', $client['client_name']) . '-' . $startStr . '_to_' . $endStr . '.pdf';
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit;
}

function enxureHandleSendClientStatement($mysqli, array $settings, string $emailPassword, int $currentUserId): void
{
    $clientKey = trim($_POST['client_key'] ?? '');
    $client = $clientKey !== '' ? $mysqli->query("SELECT * FROM enxure_clients WHERE client_key = '" . $mysqli->real_escape_string($clientKey) . "'")->fetch_assoc() : null;
    if (!$client) {
        echo json_encode(['success' => false, 'error' => 'Select a client.']);
        exit;
    }
    $to = trim($_POST['recipient_email'] ?? '');
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'error' => 'Enter a valid recipient email address.']);
        exit;
    }
    $startStr = enxureValidTaxEmailDate($_POST['start_date'] ?? null) ?: date('Y-m-01');
    $endStr = enxureValidTaxEmailDate($_POST['end_date'] ?? null) ?: date('Y-m-d');
    $message = trim($_POST['message'] ?? '');
    $fromName = $settings['business_name'] ?? (getenv('SMTP_FROM_NAME') ?: 'enXure');

    $statement = enxureBuildClientStatementRows($mysqli, $clientKey, $startStr, $endStr);
    $currencyCode = enxureResolveCurrency($client['currency'] ?? '', $settings);
    $html = enxureRenderClientStatementHtml($client, $settings, $startStr, $endStr, $statement, $currencyCode);
    try {
        $pdf = generateInvoicePdf($html);
    } catch (Throwable $e) {
        enxureLogAction($mysqli, null, '', 'client_statement_failed', "{$client['client_name']}: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }

    $senderEmail = null;
    if ($currentUserId > 0) {
        $senderRow = $mysqli->query("SELECT email FROM enxure_users WHERE id = " . $currentUserId)->fetch_assoc();
        if ($senderRow && filter_var($senderRow['email'], FILTER_VALIDATE_EMAIL) && strcasecmp($senderRow['email'], $to) !== 0) {
            $senderEmail = $senderRow['email'];
        }
    }

    require_once PHPMAILER_DIR . 'PHPMailer.php';
    require_once PHPMAILER_DIR . 'SMTP.php';
    require_once PHPMAILER_DIR . 'Exception.php';
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = getenv('SMTP_HOST') ?: '';
        $mail->Port = (int) (getenv('SMTP_PORT') ?: 587);
        $mail->SMTPAuth = trim((string) (getenv('SMTP_USER') ?: '')) !== '';
        $mail->Username = getenv('SMTP_USER') ?: '';
        $mail->Password = $emailPassword;
        $mail->SMTPSecure = match (strtolower(getenv('SMTP_ENCRYPTION') ?: 'tls')) {
            'ssl' => PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS,
            'none', '' => false,
            default => PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS,
        };
        $mail->CharSet = 'UTF-8';
        $mail->setFrom(getenv('SMTP_FROM_EMAIL') ?: '', $fromName);
        $mail->addAddress($to);
        if ($senderEmail !== null) {
            $mail->addBCC($senderEmail);
        }
        $mail->Subject = "Statement of Account - {$fromName} ({$startStr} to {$endStr})";
        $body = "Hi,\n\nAttached is the account statement for {$client['client_name']} covering {$startStr} to {$endStr}.\n\nBalance due: {$currencyCode} $" . number_format($statement['closing_balance'], 2);
        if ($message !== '') {
            $body .= "\n\n{$message}";
        }
        $body .= "\n\nThanks,\n{$fromName}";
        $mail->Body = $body;
        $mail->addStringAttachment($pdf, 'Statement_' . $startStr . '_to_' . $endStr . '.pdf', 'base64', 'application/pdf');
        $mail->send();
        enxureLogAction($mysqli, null, '', 'client_statement_sent', "Sent to {$to} for {$client['client_name']} ({$startStr} to {$endStr})");
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        enxureLogAction($mysqli, null, '', 'client_statement_failed', "To {$to}: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}
