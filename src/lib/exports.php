<?php
// $mysqli-touching export/report generation — CSV/PDF/ZIP/IIF downloads and
// the accounting journal builder.

// Builds a simple double-entry General Journal from invoices, payments,
// and logged expenses — for handing to an accountant or importing into a
// bookkeeping tool. One row per side of each entry (Date, Account, Debit,
// Credit, Memo, Reference, Currency):
//   - Invoice issued:      Dr Accounts Receivable / Cr Sales Income
//   - Payment received:    Dr Cash & Bank / Cr Accounts Receivable
//   - Expense logged:      Dr <category> Expense / Cr Cash & Bank
// Fixed 4-account chart (plus one Expense account per expenseCategories()
// entry), not user-configurable — single-admin, no multi-entity
// bookkeeping. Every entry balances within its own currency: an
// other-currency invoice/payment posts to accounts suffixed " (CCY)" rather
// than blending into the default-currency Accounts Receivable/Sales Income/
// Cash & Bank balances (expenses have no currency field, so always post to
// the plain default-currency accounts).
function buildAccountingJournal($mysqli, array $settings, string $startDate, string $testFilter): array
{
    $categories = expenseCategories();
    $rows = [];
    $defaultCcy = invoxaResolveCurrency('', $settings);
    $ccyAccount = function (string $account, string $recordCurrency) use ($settings, $defaultCcy): string {
        $ccy = invoxaResolveCurrency($recordCurrency, $settings);
        return $ccy === $defaultCcy ? $account : "$account ($ccy)";
    };

    $res = $mysqli->query("SELECT invoice_number, client_name, invoice_date, amount, currency FROM invoxa_invoices WHERE is_quote = 0 AND status != 'void' AND invoice_date >= '$startDate' $testFilter ORDER BY invoice_date ASC");
    while ($r = $res->fetch_assoc()) {
        $date = substr($r['invoice_date'], 0, 10);
        $memo = "Invoice {$r['invoice_number']} — {$r['client_name']}";
        $amount = round((float) $r['amount'], 2);
        $ccy = invoxaResolveCurrency($r['currency'] ?? '', $settings);
        $rows[] = ['date' => $date, 'account' => $ccyAccount('Accounts Receivable', $r['currency']), 'debit' => $amount, 'credit' => 0, 'memo' => $memo, 'ref' => $r['invoice_number'], 'currency' => $ccy];
        $rows[] = ['date' => $date, 'account' => $ccyAccount('Sales Income', $r['currency']), 'debit' => 0, 'credit' => $amount, 'memo' => $memo, 'ref' => $r['invoice_number'], 'currency' => $ccy];
    }

    $res = $mysqli->query("SELECT invoice_number, client_name, paid_at, paid_amount, currency FROM invoxa_invoices WHERE is_quote = 0 AND status != 'void' AND paid_amount > 0 AND paid_at >= '$startDate' $testFilter ORDER BY paid_at ASC");
    while ($r = $res->fetch_assoc()) {
        $date = substr($r['paid_at'], 0, 10);
        $memo = "Payment received for invoice {$r['invoice_number']} — {$r['client_name']}";
        $amount = round((float) $r['paid_amount'], 2);
        $ccy = invoxaResolveCurrency($r['currency'] ?? '', $settings);
        $rows[] = ['date' => $date, 'account' => $ccyAccount('Cash & Bank', $r['currency']), 'debit' => $amount, 'credit' => 0, 'memo' => $memo, 'ref' => $r['invoice_number'], 'currency' => $ccy];
        $rows[] = ['date' => $date, 'account' => $ccyAccount('Accounts Receivable', $r['currency']), 'debit' => 0, 'credit' => $amount, 'memo' => $memo, 'ref' => $r['invoice_number'], 'currency' => $ccy];
    }

    $res = $mysqli->query("SELECT id, expense_date, vendor, category, amount FROM invoxa_expenses WHERE expense_date >= '$startDate' ORDER BY expense_date ASC");
    while ($r = $res->fetch_assoc()) {
        $date = substr($r['expense_date'], 0, 10);
        $account = ($categories[$r['category']] ?? ucfirst($r['category'])) . ' Expense';
        $memo = trim($r['vendor'] . ($r['vendor'] !== '' ? ' — ' : '') . 'Expense #' . $r['id']);
        $amount = round((float) $r['amount'], 2);
        $rows[] = ['date' => $date, 'account' => $account, 'debit' => $amount, 'credit' => 0, 'memo' => $memo, 'ref' => 'EXP-' . $r['id'], 'currency' => $defaultCcy];
        $rows[] = ['date' => $date, 'account' => 'Cash & Bank', 'debit' => 0, 'credit' => $amount, 'memo' => $memo, 'ref' => 'EXP-' . $r['id'], 'currency' => $defaultCcy];
    }

    usort($rows, fn($a, $b) => $a['date'] <=> $b['date']);
    return $rows;
}

function invoxaHandlePreviewAdhocPdf($mysqli, array $settings, bool $licenseValid): void
{
// Same as preview_adhoc but renders straight to PDF, for previewing an
// invoice that hasn't been saved yet (no invoxa_invoices row to look up
// via ?export=invoice_pdf&id=). Recomputes HTML server-side from trusted
// inputs rather than accepting client-rendered HTML.
$clientId = (int) $_POST['client_id'];
$client = $mysqli->query("SELECT * FROM invoxa_clients WHERE id=$clientId")->fetch_assoc();
if (!$client) {
    http_response_code(404);
    exit('Client not found');
}
$lineItems = json_decode($_POST['line_items'] ?? '[]', true);
if (empty($lineItems)) {
    http_response_code(400);
    exit('No line items provided');
}
$discountPct = (float) ($_POST['discount_pct'] ?? 0);
$taxRate = (float) ($_POST['tax_rate'] ?? 0);
$totals = computeInvoiceTotals($lineItems, $discountPct, $taxRate);
$amount = $totals['total'];
$date = date("Y-m-d");
$termsDays = (int) ($client['payment_terms_days'] ?? 21);
$dueDate = validDateOverride($_POST['due_date'] ?? null) ?: date("Y-m-d", strtotime("+{$termsDays} days"));
$invNum = generateInvoiceNumber($mysqli, $client['client_key'], $client['client_name'], $settings);
$brandColor = $settings['brand_color'] ?? '#4a90e2';
$footerText = $settings['footer_text'] ?? '';
$currencyCode = invoxaResolveCurrency($client['currency'] ?? '', $settings);
$html = generateInvoiceHTML($client['client_name'], $date, $dueDate, $invNum, number_format($amount, 2), $client['account_name'] ?: ($settings['default_account_name'] ?? ''), $client['account_number'] ?: ($settings['default_account_number'] ?? ''), getenv('SMTP_FROM_EMAIL') ?: '', $lineItems, $brandColor, $footerText, $currencyCode, invoiceWatermarkFingerprint($settings), $totals['discount_pct'], $totals['tax_rate'], $settings['invoice_template'] ?? 'detailed', null, !($licenseValid && ($settings['hide_powered_by'] ?? '0') === '1'), vatNumber: $settings['vat_number'] ?? '', recipientPhone: $client['phone'] ?? '', recipientAddress: $client['address'] ?? '', customTemplate: ($settings['invoice_template'] ?? 'detailed') === 'custom' ? ($settings['custom_invoice_template'] ?? '') : null, businessName: $settings['business_name'] ?? '');
try {
    $pdf = generateInvoicePdf($html);
} catch (Throwable $e) {
    http_response_code(500);
    exit('Failed to generate PDF: ' . $e->getMessage());
}
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="Invoice-' . preg_replace('/[^\w\-]/', '_', $invNum) . '.pdf"');
header('Content-Length: ' . strlen($pdf));
echo $pdf;
exit;
}

function invoxaHandleInvoicePdfExport($mysqli): void
{
    // Server-side PDF export (dompdf) for the "Download PDF" button — replaces
    // the old client-side html2pdf.js screenshot hack, which couldn't produce
    // anything attachable to an email.
    $id = (int) ($_GET['id'] ?? 0);
    $row = $mysqli->query("SELECT invoice_number, html_content, is_quote FROM invoxa_invoices WHERE id = $id")->fetch_assoc();
    if (!$row || empty($row['html_content'])) {
        http_response_code(404);
        exit('Invoice not found or has no stored content to render.');
    }
    try {
        $pdf = generateInvoicePdf($row['html_content']);
    } catch (Throwable $e) {
        http_response_code(500);
        exit('Failed to generate PDF: ' . $e->getMessage());
    }
    $prefix = $row['is_quote'] ? 'Quote' : 'Invoice';
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $prefix . '-' . preg_replace('/[^\w\-]/', '_', $row['invoice_number']) . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit;
}

function invoxaHandleExportRoutes($mysqli, array $settings): void
{
    $mysqli->query("CREATE TABLE IF NOT EXISTS invoxa_settings (setting_key VARCHAR(50) PRIMARY KEY, setting_value TEXT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    $hideTestRes = $mysqli->query("SELECT setting_value FROM invoxa_settings WHERE setting_key = 'hide_test'");
    $hideTest = ($hideTestRes && $hideTestRes->num_rows > 0) ? ($hideTestRes->fetch_assoc()['setting_value'] === '1') : true;
    $showTestOnlyRes = $mysqli->query("SELECT setting_value FROM invoxa_settings WHERE setting_key = 'show_test_only'");
    $showTestOnly = ($showTestOnlyRes && $showTestOnlyRes->num_rows > 0) ? ($showTestOnlyRes->fetch_assoc()['setting_value'] === '1') : false;
    $testFilter = invoxaTestViewFilter($hideTest, $showTestOnly);
    if ($_GET['export'] === 'invoices') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="invoices_export_' . date('Ymd') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Invoice Number', 'Client Name', 'Email', 'Invoice Date', 'Due Date', 'Amount', 'Currency', 'Status', 'Paid Amount', 'Paid Date'], ',', '"', "\\");
        $res = $mysqli->query("SELECT invoice_number, client_name, recipient_email, invoice_date, due_date, amount, currency, status, paid_amount, paid_at FROM invoxa_invoices WHERE 1 $testFilter ORDER BY invoice_date DESC");
        while ($r = $res->fetch_assoc()) {
            $r['currency'] = invoxaResolveCurrency($r['currency'], $settings);
            fputcsv($out, $r, ',', '"', "\\");
        }
        fclose($out);
        exit;
    }
    if ($_GET['export'] === 'invoices_pdf') {
        // Same scope/filter as the CSV export above, but bundles a rendered PDF
        // per invoice (dompdf, see generateInvoicePdf()) into one zip download —
        // the multi-invoice companion to the single "Download PDF" button.
        if (!class_exists('ZipArchive')) {
            // Requires the php container to be rebuilt (`docker compose build php`)
            // to pick up the zip extension — a plain restart won't add it.
            http_response_code(500);
            exit('PHP\'s zip extension isn\'t available in this container — rebuild the php service (docker compose build php) to pick up the Dockerfile change that adds it, then try again.');
        }
        $res = $mysqli->query("SELECT id, invoice_number, is_quote, html_content FROM invoxa_invoices WHERE html_content IS NOT NULL AND html_content != '' $testFilter ORDER BY invoice_date DESC");
        $tmpZip = tempnam(sys_get_temp_dir(), 'invoxa_pdf_export_');
        $zip = new ZipArchive();
        if ($zip->open($tmpZip, ZipArchive::OVERWRITE) !== true) {
            @unlink($tmpZip);
            http_response_code(500);
            exit('Failed to create the zip archive.');
        }
        $usedNames = [];
        $count = 0;
        while ($row = $res->fetch_assoc()) {
            try {
                $pdf = generateInvoicePdf($row['html_content']);
            } catch (Throwable $e) {
                continue;
            }
            $prefix = $row['is_quote'] ? 'Quote' : 'Invoice';
            $baseName = $prefix . '-' . preg_replace('/[^\w\-]/', '_', $row['invoice_number']);
            // invoice_number isn't unique across quotes vs invoices — de-dupe
            // filenames within this zip so one doesn't silently overwrite another.
            $filename = $baseName . '.pdf';
            $suffix = 2;
            while (isset($usedNames[$filename])) {
                $filename = $baseName . '-' . $suffix . '.pdf';
                $suffix++;
            }
            $usedNames[$filename] = true;
            $zip->addFromString($filename, $pdf);
            $count++;
        }
        $zip->close();
        if ($count === 0) {
            @unlink($tmpZip);
            http_response_code(404);
            exit('No invoices with stored content to export.');
        }
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="invoices_pdf_export_' . date('Ymd') . '.zip"');
        header('Content-Length: ' . filesize($tmpZip));
        readfile($tmpZip);
        @unlink($tmpZip);
        exit;
    }
    if ($_GET['export'] === 'tax_year') {
        // Tax year starts April 1st. If current month is before April, look back to previous April 1st.
        $now = new DateTime();
        $taxYearStart = getTaxYearStart((int) ($settings['tax_year_start_month'] ?? 1), $now);
        $startStr = $taxYearStart->format('Y-m-d');
        $taxYearLabel = $taxYearStart->format('Y') . '-' . $now->format('Y');
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="invoices_tax_year_' . $taxYearLabel . '_' . date('Ymd') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Invoice Number', 'Client Name', 'Invoice Date', 'Due Date', 'Amount', 'Currency', 'Status', 'Paid Amount', 'Paid Date'], ',', '"', "\\");
        $res = $mysqli->query("SELECT invoice_number, client_name, invoice_date, due_date, amount, currency, status, paid_amount, paid_at FROM invoxa_invoices WHERE is_quote = 0 AND status != 'void' AND invoice_date >= '$startStr' $testFilter ORDER BY invoice_date ASC");
        while ($r = $res->fetch_assoc()) {
            $r['currency'] = invoxaResolveCurrency($r['currency'], $settings);
            fputcsv($out, $r, ',', '"', "\\");
        }
        fclose($out);
        exit;
    }
    if ($_GET['export'] === 'tax_year_monthly') {
        // Monthly summary for the current tax year (April 1st to now)
        $now = new DateTime();
        $taxYearStart = getTaxYearStart((int) ($settings['tax_year_start_month'] ?? 1), $now);
        $startStr = $taxYearStart->format('Y-m-d');
        $taxYearLabel = $taxYearStart->format('Y') . '-' . $now->format('Y');
        $defaultCcy = invoxaResolveCurrency('', $settings);
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="invoices_monthly_summary_' . $taxYearLabel . '_' . date('Ymd') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Month', 'Currency', 'Total Invoiced', 'Total Paid', 'Outstanding', 'Payment Status', 'Expenses', 'Net Income'], ',', '"', "\\");
        // One row per month per currency — no exclusion, so an other-currency
        // month still shows up instead of being dropped from the summary.
        $res = $mysqli->query("
            SELECT
                DATE_FORMAT(invoice_date, '%Y-%m') as month,
                currency,
                SUM(amount) as total_invoiced,
                SUM(COALESCE(paid_amount, 0)) as total_paid,
                SUM(amount) - SUM(COALESCE(paid_amount, 0)) as outstanding,
                SUM(CASE WHEN status NOT IN ('paid') THEN 1 ELSE 0 END) as unpaid_count
            FROM invoxa_invoices
            WHERE is_quote = 0
              AND status != 'void'
              AND invoice_date >= '$startStr'
              $testFilter
            GROUP BY DATE_FORMAT(invoice_date, '%Y-%m'), currency
            ORDER BY month ASC
        ");
        $rowsByMonthCcy = [];
        while ($r = $res->fetch_assoc()) {
            $ccy = invoxaResolveCurrency($r['currency'], $settings);
            $key = $r['month'] . '|' . $ccy;
            if (!isset($rowsByMonthCcy[$key])) {
                $rowsByMonthCcy[$key] = ['month' => $r['month'], 'currency' => $ccy, 'total_invoiced' => 0.0, 'total_paid' => 0.0, 'outstanding' => 0.0, 'unpaid_count' => 0];
            }
            $rowsByMonthCcy[$key]['total_invoiced'] += (float) $r['total_invoiced'];
            $rowsByMonthCcy[$key]['total_paid'] += (float) $r['total_paid'];
            $rowsByMonthCcy[$key]['outstanding'] += (float) $r['outstanding'];
            $rowsByMonthCcy[$key]['unpaid_count'] += (int) $r['unpaid_count'];
        }
        // Expenses have no currency field, so they're only meaningful (and only
        // subtracted into Net Income) against the default-currency row for that month.
        $expensesByMonthCsv = [];
        $expResCsv = $mysqli->query("SELECT DATE_FORMAT(expense_date, '%Y-%m') as month, SUM(amount) as total FROM invoxa_expenses WHERE expense_date >= '$startStr' GROUP BY DATE_FORMAT(expense_date, '%Y-%m')");
        while ($er = $expResCsv->fetch_assoc())
            $expensesByMonthCsv[$er['month']] = (float) $er['total'];
        foreach ($rowsByMonthCcy as $r) {
            $dt = DateTime::createFromFormat('Y-m', $r['month']);
            $monthLabel = $dt ? $dt->format('F Y') : $r['month'];
            $outstanding = round($r['outstanding'], 2);
            if ($r['unpaid_count'] > 0 && $outstanding > 0) {
                $payStatus = 'Partial Paid';
            } elseif ($outstanding <= 0) {
                $payStatus = 'Paid';
            } else {
                $payStatus = 'Unpaid';
            }
            $isDefaultCcy = $r['currency'] === $defaultCcy;
            $monthExpensesCsv = $isDefaultCcy ? ($expensesByMonthCsv[$r['month']] ?? 0.0) : 0.0;
            fputcsv($out, [
                $monthLabel,
                $r['currency'],
                number_format($r['total_invoiced'], 2),
                number_format($r['total_paid'], 2),
                number_format($outstanding, 2),
                $payStatus,
                $isDefaultCcy ? number_format($monthExpensesCsv, 2) : '',
                $isDefaultCcy ? number_format($r['total_paid'] - $monthExpensesCsv, 2) : '',
            ], ',', '"', "\\");
        }
        fclose($out);
        exit;
    }
    if ($_GET['export'] === 'clients') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="clients_export_' . date('Ymd') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Client Name', 'Email', 'Phone', 'Address', 'Rate', 'Currency', 'Billing Frequency', 'Invoices', 'Total Billed', 'Total Paid', 'Outstanding'], ',', '"', "\\");
        $res = $mysqli->query("SELECT c.client_name, c.email, c.phone, c.address, c.monthly_rate, c.currency, c.billing_frequency, COUNT(i.id) as inv_count, SUM(i.amount) as total_billed, SUM(i.paid_amount) as total_paid FROM invoxa_clients c LEFT JOIN invoxa_invoices i ON c.client_key = i.client_key AND i.status NOT IN ('failed', 'void') WHERE 1 " . invoxaTestViewClientFilter($hideTest, $showTestOnly, 'AND', 'c.is_test') . " GROUP BY c.id ORDER BY c.client_name ASC");
        while ($r = $res->fetch_assoc()) {
            $r['currency'] = invoxaResolveCurrency($r['currency'], $settings);
            $r['outstanding'] = max(0, $r['total_billed'] - $r['total_paid']);
            fputcsv($out, $r, ',', '"', "\\");
        }
        fclose($out);
        exit;
    }
    if ($_GET['export'] === 'expenses') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="expenses_export_' . date('Ymd') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Date', 'Vendor', 'Category', 'Amount', 'Description'], ',', '"', "\\");
        $categories = expenseCategories();
        $res = $mysqli->query("SELECT * FROM invoxa_expenses ORDER BY expense_date ASC, id ASC");
        while ($r = $res->fetch_assoc()) {
            fputcsv($out, [
                substr($r['expense_date'], 0, 10),
                $r['vendor'],
                $categories[$r['category']] ?? ucfirst($r['category']),
                number_format((float) $r['amount'], 2),
                $r['description'],
            ], ',', '"', "\\");
        }
        fclose($out);
        exit;
    }
    if ($_GET['export'] === 'quotes') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="quotes_export_' . date('Ymd') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Quote Number', 'Client Name', 'Email', 'Quote Date', 'Amount', 'Currency', 'Expires'], ',', '"', "\\");
        $res = $mysqli->query("SELECT invoice_number, client_name, recipient_email, invoice_date, amount, currency, quote_expires_at FROM invoxa_invoices WHERE is_quote = 1 $testFilter ORDER BY invoice_date DESC");
        while ($r = $res->fetch_assoc()) {
            $r['currency'] = invoxaResolveCurrency($r['currency'], $settings);
            fputcsv($out, $r, ',', '"', "\\");
        }
        fclose($out);
        exit;
    }
    if ($_GET['export'] === 'accounting_journal') {
        $taxYearStart = getTaxYearStart((int) ($settings['tax_year_start_month'] ?? 1));
        $journal = buildAccountingJournal($mysqli, $settings, $taxYearStart->format('Y-m-d'), $testFilter);
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="accounting_journal_' . date('Ymd') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Date', 'Account', 'Debit', 'Credit', 'Memo', 'Reference', 'Currency'], ',', '"', "\\");
        foreach ($journal as $row) {
            fputcsv($out, [
                $row['date'],
                $row['account'],
                $row['debit'] > 0 ? number_format($row['debit'], 2) : '',
                $row['credit'] > 0 ? number_format($row['credit'], 2) : '',
                $row['memo'],
                $row['ref'],
                $row['currency'],
            ], ',', '"', "\\");
        }
        fclose($out);
        exit;
    }
    if ($_GET['export'] === 'accounting_iif') {
        // QuickBooks Desktop's General Journal import format — tab-delimited, one
        // TRNS (debit) + SPL (credit, negated) + ENDTRNS block per journal entry.
        // buildAccountingJournal() emits rows in adjacent debit/credit pairs (see
        // its docblock); relies on PHP 8's stable usort() to keep pairs adjacent
        // after the date sort, so it's safe to walk two at a time here.
        $taxYearStart = getTaxYearStart((int) ($settings['tax_year_start_month'] ?? 1));
        $journal = buildAccountingJournal($mysqli, $settings, $taxYearStart->format('Y-m-d'), $testFilter);
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="accounting_journal_' . date('Ymd') . '.iif"');
        $out = fopen('php://output', 'w');
        fwrite($out, "!TRNS\tTRNSTYPE\tDATE\tACCNT\tNAME\tAMOUNT\tDOCNUM\tMEMO\n");
        fwrite($out, "!SPL\tTRNSTYPE\tDATE\tACCNT\tNAME\tAMOUNT\tDOCNUM\tMEMO\n");
        fwrite($out, "!ENDTRNS\n");
        for ($i = 0; $i + 1 < count($journal); $i += 2) {
            $debitRow = $journal[$i]['debit'] > 0 ? $journal[$i] : $journal[$i + 1];
            $creditRow = $journal[$i]['debit'] > 0 ? $journal[$i + 1] : $journal[$i];
            $date = date('m/d/Y', strtotime($debitRow['date']));
            $amount = number_format($debitRow['debit'], 2, '.', '');
            $memo = str_replace("\t", ' ', $debitRow['memo']);
            $ref = $debitRow['ref'];
            fwrite($out, "TRNS\tGENERAL JOURNAL\t{$date}\t{$debitRow['account']}\t\t{$amount}\t{$ref}\t{$memo}\n");
            fwrite($out, "SPL\tGENERAL JOURNAL\t{$date}\t{$creditRow['account']}\t\t-{$amount}\t{$ref}\t{$memo}\n");
            fwrite($out, "ENDTRNS\n");
        }
        fclose($out);
        exit;
    }
}
