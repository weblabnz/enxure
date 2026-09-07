<?php

function enxureHandlePreviewTaxEmail($mysqli, array $settings, int $currentUserId): void
{
    $now = new DateTime();
    $taxYearStart = getTaxYearStart((int) ($settings['tax_year_start_month'] ?? 1), $now);
    $defaultStart = $taxYearStart->format('Y-m-d');
    $defaultEnd = $now->format('Y-m-d');

    $startStr = enxureValidTaxEmailDate($_POST['start_date'] ?? null) ?: $defaultStart;
    $endStr = enxureValidTaxEmailDate($_POST['end_date'] ?? null) ?: $defaultEnd;
    $label = "$startStr to $endStr";

    $hideTestRes = $mysqli->query("SELECT setting_value FROM enxure_settings WHERE setting_key = 'hide_test'");
    $hideTest = ($hideTestRes && $hideTestRes->num_rows > 0) ? ($hideTestRes->fetch_assoc()['setting_value'] === '1') : true;
    $showTestOnlyRes = $mysqli->query("SELECT setting_value FROM enxure_settings WHERE setting_key = 'show_test_only'");
    $showTestOnly = ($showTestOnlyRes && $showTestOnlyRes->num_rows > 0) ? ($showTestOnlyRes->fetch_assoc()['setting_value'] === '1') : false;
    $tf = enxureTestViewFilter($hideTest, $showTestOnly);

    $invoices = [];
    $res = $mysqli->query("SELECT id, invoice_number, client_name, invoice_date, amount, currency, status FROM enxure_invoices WHERE is_quote = 0 AND status != 'void' AND invoice_date >= '$startStr' AND invoice_date <= '$endStr 23:59:59' $tf ORDER BY currency ASC, invoice_date ASC");
    while ($r = $res->fetch_assoc()) {
        $r['currency'] = enxureResolveCurrency($r['currency'], $settings);
        $r['amount'] = (float) $r['amount'];
        $invoices[] = $r;
    }

    $categories = expenseCategories();

    $expenses = [];
    $res = $mysqli->query("SELECT e.id, e.expense_date, e.vendor, e.category, e.amount, e.recurring_expense_id, COUNT(r.id) as receipt_count FROM enxure_expenses e LEFT JOIN enxure_expense_receipts r ON r.expense_id = e.id WHERE e.expense_date >= '$startStr' AND e.expense_date <= '$endStr' GROUP BY e.id ORDER BY e.expense_date ASC");
    while ($r = $res->fetch_assoc()) {
        $r['amount'] = (float) $r['amount'];
        $r['receipt_count'] = (int) $r['receipt_count'];
        $r['category_label'] = $categories[$r['category']] ?? ucfirst($r['category']);
        $expenses[] = $r;
    }

    $recurringExpenses = [];
    $res = $mysqli->query("SELECT id, vendor, category, amount, frequency, is_active FROM enxure_recurring_expenses ORDER BY vendor ASC, id ASC");
    while ($r = $res->fetch_assoc()) {
        $r['amount'] = (float) $r['amount'];
        $r['category_label'] = $categories[$r['category']] ?? ucfirst($r['category']);
        $recurringExpenses[] = $r;
    }

    $fromName = $settings['business_name'] ?? (getenv('SMTP_FROM_NAME') ?: 'enXure');
    $senderEmail = null;
    if ($currentUserId > 0) {
        $senderRow = $mysqli->query("SELECT email FROM enxure_users WHERE id = " . $currentUserId)->fetch_assoc();
        if ($senderRow && filter_var($senderRow['email'], FILTER_VALIDATE_EMAIL)) {
            $senderEmail = $senderRow['email'];
        }
    }
    echo json_encode(['success' => true, 'label' => $label, 'start_date' => $startStr, 'end_date' => $endStr, 'business_name' => $fromName, 'sender_email' => $senderEmail, 'invoices' => $invoices, 'expenses' => $expenses, 'recurring_expenses' => $recurringExpenses]);
    exit;
}

function enxureValidTaxEmailDate(?string $value): ?string
{
    if (!$value)
        return null;
    $d = DateTime::createFromFormat('Y-m-d', $value);
    return ($d && $d->format('Y-m-d') === $value) ? $value : null;
}

function enxureRecurringOccurrenceDates(string $frequency, string $startStr, string $endStr): array
{
    $step = match ($frequency) {
        'weekly' => '+1 week',
        'quarterly' => '+3 months',
        'annually' => '+1 year',
        default => '+1 month',
    };
    $end = new DateTime($endStr);
    $dates = [];
    $cursor = new DateTime($startStr);
    while ($cursor <= $end) {
        $dates[] = $cursor->format('Y-m-d');
        $cursor->modify($step);
    }
    return $dates;
}

function enxureBuildTaxEmailZip($mysqli, array $settings, array $invoiceIds, array $expenseIds, array $recurringExpenseIds, string $startStr, string $endStr, bool $includePdfs, bool $includeReceipts): array
{
    if (!class_exists('ZipArchive')) {
        throw new Exception("PHP's zip extension isn't available in this container.");
    }

    $tmpZip = tempnam(sys_get_temp_dir(), 'enxure_tax_email_');
    $zip = new ZipArchive();
    if ($zip->open($tmpZip, ZipArchive::OVERWRITE) !== true) {
        @unlink($tmpZip);
        throw new Exception('Failed to create the zip archive.');
    }

    $invoiceCount = 0;
    $invoiceTotalByCcy = [];
    if (!empty($invoiceIds)) {
        $ids = implode(',', array_map('intval', $invoiceIds));
        $csv = fopen('php://temp', 'r+');
        fputcsv($csv, ['Invoice Number', 'Client Name', 'Invoice Date', 'Amount', 'Currency', 'Status'], ',', '"', "\\");
        $res = $mysqli->query("SELECT invoice_number, client_name, invoice_date, amount, currency, status, html_content, is_quote FROM enxure_invoices WHERE id IN ($ids) ORDER BY invoice_date ASC");
        $usedNames = [];
        while ($r = $res->fetch_assoc()) {
            $ccy = enxureResolveCurrency($r['currency'], $settings);
            fputcsv($csv, [$r['invoice_number'], $r['client_name'], substr($r['invoice_date'], 0, 10), $r['amount'], $ccy, $r['status']], ',', '"', "\\");
            $invoiceCount++;
            $invoiceTotalByCcy[$ccy] = ($invoiceTotalByCcy[$ccy] ?? 0) + (float) $r['amount'];
            if ($includePdfs && !empty($r['html_content'])) {
                try {
                    $pdf = generateInvoicePdf($r['html_content']);
                    $prefix = $r['is_quote'] ? 'Quote' : 'Invoice';
                    $baseName = $prefix . '-' . preg_replace('/[^\w\-]/', '_', $r['invoice_number']);
                    $filename = 'Invoices/' . $baseName . '.pdf';
                    $suffix = 2;
                    while (isset($usedNames[$filename])) {
                        $filename = 'Invoices/' . $baseName . '-' . $suffix . '.pdf';
                        $suffix++;
                    }
                    $usedNames[$filename] = true;
                    $zip->addFromString($filename, $pdf);
                } catch (Throwable $e) {
                }
            }
        }
        rewind($csv);
        $zip->addFromString('invoices.csv', stream_get_contents($csv));
        fclose($csv);
    }

    $categories = expenseCategories();
    $expenseRows = [];
    $realExpenseCount = 0;

    if (!empty($expenseIds)) {
        $ids = implode(',', array_map('intval', $expenseIds));
        $res = $mysqli->query("SELECT id, expense_date, vendor, category, amount, description FROM enxure_expenses WHERE id IN ($ids) ORDER BY expense_date ASC");
        while ($r = $res->fetch_assoc()) {
            $categoryLabel = $categories[$r['category']] ?? ucfirst($r['category']);
            $date = substr($r['expense_date'], 0, 10);
            $expenseRows[] = [$date, $r['vendor'], $categoryLabel, (float) $r['amount'], $r['description']];
            $realExpenseCount++;

            if ($includeReceipts) {
                $receiptFolder = 'Receipts/' . preg_replace('/[^\w\-]/', '_', $r['id'] . '-' . $r['vendor']) . '/';
                $recRes = $mysqli->query("SELECT filename, stored_path FROM enxure_expense_receipts WHERE expense_id = " . (int) $r['id']);
                while ($rec = $recRes->fetch_assoc()) {
                    $diskPath = RECEIPTS_DIR . $rec['stored_path'];
                    if (is_readable($diskPath)) {
                        $zip->addFile($diskPath, $receiptFolder . $rec['filename']);
                    }
                }
            }
        }
    }

    $recurringCount = 0;
    if (!empty($recurringExpenseIds)) {
        $ids = implode(',', array_map('intval', $recurringExpenseIds));
        $csv = fopen('php://temp', 'r+');
        fputcsv($csv, ['Vendor', 'Category', 'Amount', 'Frequency', 'Active'], ',', '"', "\\");
        $res = $mysqli->query("SELECT vendor, category, amount, frequency, description, is_active FROM enxure_recurring_expenses WHERE id IN ($ids) ORDER BY vendor ASC");
        while ($r = $res->fetch_assoc()) {
            $categoryLabel = $categories[$r['category']] ?? ucfirst($r['category']);
            fputcsv($csv, [$r['vendor'], $categoryLabel, $r['amount'], $r['frequency'], $r['is_active'] ? 'Yes' : 'No'], ',', '"', "\\");
            $recurringCount++;

            foreach (enxureRecurringOccurrenceDates($r['frequency'], $startStr, $endStr) as $occurrenceDate) {
                $expenseRows[] = [$occurrenceDate, $r['vendor'], $categoryLabel, (float) $r['amount'], $r['description']];
            }
        }
        rewind($csv);
        $zip->addFromString('recurring_expenses.csv', stream_get_contents($csv));
        fclose($csv);
    }

    usort($expenseRows, fn($a, $b) => $a[0] <=> $b[0]);
    $expenseCount = count($expenseRows);
    $expenseTotal = array_sum(array_column($expenseRows, 3));
    if ($expenseCount > 0) {
        $csv = fopen('php://temp', 'r+');
        fputcsv($csv, ['Date', 'Vendor', 'Category', 'Amount', 'Description'], ',', '"', "\\");
        foreach ($expenseRows as $row) {
            fputcsv($csv, $row, ',', '"', "\\");
        }
        rewind($csv);
        $zip->addFromString('expenses.csv', stream_get_contents($csv));
        fclose($csv);
    }

    $zip->close();

    return [
        'path' => $tmpZip,
        'invoice_count' => $invoiceCount,
        'invoice_total_by_ccy' => $invoiceTotalByCcy,
        'expense_count' => $expenseCount,
        'expense_total' => $expenseTotal,
        'real_expense_count' => $realExpenseCount,
        'recurring_count' => $recurringCount,
    ];
}

function enxureTaxEmailSummaryLines(array $bundle, bool $includePdfs, bool $includeReceipts): array
{
    $lines = [];
    if ($bundle['invoice_count'] > 0) {
        $invoiceTotalsText = [];
        foreach ($bundle['invoice_total_by_ccy'] as $ccy => $total) {
            $invoiceTotalsText[] = number_format($total, 2) . ' ' . $ccy;
        }
        $lines[] = "{$bundle['invoice_count']} invoice(s) totalling " . implode(', ', $invoiceTotalsText);
        if ($includePdfs) {
            $lines[] = 'Invoice PDFs attached';
        }
    }
    if ($bundle['expense_count'] > 0) {
        $lines[] = "{$bundle['expense_count']} expense(s) totalling " . number_format($bundle['expense_total'], 2);
        if ($includeReceipts && $bundle['real_expense_count'] > 0) {
            $lines[] = 'Expense receipts attached';
        }
    }
    if ($bundle['recurring_count'] > 0) {
        $lines[] = "{$bundle['recurring_count']} recurring expense template(s) (schedule attached)";
    }
    return $lines;
}

function enxureTaxEmailSummaryText(array $bundle): string
{
    $lines = enxureTaxEmailSummaryLines($bundle, false, false);
    return implode('. ', $lines) . '.';
}

function enxureHandleSendTaxEmail($mysqli, array $settings, string $emailPassword, int $currentUserId): void
{
    $to = trim($_POST['recipient_email'] ?? '');
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'error' => 'Enter a valid recipient email address.']);
        exit;
    }
    $invoiceIds = json_decode($_POST['invoice_ids'] ?? '[]', true);
    $expenseIds = json_decode($_POST['expense_ids'] ?? '[]', true);
    $recurringExpenseIds = json_decode($_POST['recurring_expense_ids'] ?? '[]', true);
    if (!is_array($invoiceIds))
        $invoiceIds = [];
    if (!is_array($expenseIds))
        $expenseIds = [];
    if (!is_array($recurringExpenseIds))
        $recurringExpenseIds = [];
    if (empty($invoiceIds) && empty($expenseIds) && empty($recurringExpenseIds)) {
        echo json_encode(['success' => false, 'error' => 'Select at least one invoice, expense, or recurring expense to include.']);
        exit;
    }
    $recipientName = trim($_POST['recipient_name'] ?? '');
    $message = trim($_POST['message'] ?? '');
    $includePdfs = ($_POST['include_pdfs'] ?? '1') === '1';
    $includeReceipts = ($_POST['include_receipts'] ?? '1') === '1';

    $now = new DateTime();
    $taxYearStart = getTaxYearStart((int) ($settings['tax_year_start_month'] ?? 1), $now);
    $startStr = enxureValidTaxEmailDate($_POST['start_date'] ?? null) ?: $taxYearStart->format('Y-m-d');
    $endStr = enxureValidTaxEmailDate($_POST['end_date'] ?? null) ?: $now->format('Y-m-d');
    $label = "$startStr to $endStr";
    $fromName = $settings['business_name'] ?? (getenv('SMTP_FROM_NAME') ?: 'enXure');

    try {
        $bundle = enxureBuildTaxEmailZip($mysqli, $settings, $invoiceIds, $expenseIds, $recurringExpenseIds, $startStr, $endStr, $includePdfs, $includeReceipts);
    } catch (Throwable $e) {
        enxureLogAction($mysqli, null, '', 'tax_email_failed', "To {$to}: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }

    $summaryLines = enxureTaxEmailSummaryLines($bundle, $includePdfs, $includeReceipts);
    $summary = implode('. ', $summaryLines) . '.';

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
        $mail->Subject = "Tax Documents - {$fromName} ({$label})";
        $greeting = $recipientName !== '' ? $recipientName : 'there';
        $bulletList = implode("\n", array_map(fn($line) => "- {$line}", $summaryLines));
        $body = "Hi {$greeting},\n\nAttached are the tax documents for {$fromName}, {$label}.\n\nIncluded in this email:\n{$bulletList}";
        if ($message !== '') {
            $body .= "\n\n{$message}";
        }
        $body .= "\n\nThanks,\n{$fromName}";
        $mail->Body = $body;
        $mail->addAttachment($bundle['path'], 'Tax_Documents_' . $startStr . '_to_' . $endStr . '.zip');
        $mail->send();
        enxureLogAction($mysqli, null, '', 'tax_email_sent', "Sent to {$to} — {$summary}");
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        enxureLogAction($mysqli, null, '', 'tax_email_failed', "To {$to}: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    } finally {
        @unlink($bundle['path']);
    }
    exit;
}
