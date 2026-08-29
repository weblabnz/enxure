<?php
session_start();
date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'UTC');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysqli = new mysqli(
        getenv('DB_HOST') ?: 'db',
        getenv('DB_USER') ?: '',
        getenv('DB_PASSWORD') ?: '',
        getenv('DB_NAME') ?: 'invoxa',
        (int) (getenv('DB_PORT') ?: 3306)
    );
    $mysqli->set_charset('utf8mb4');
} catch (mysqli_sql_exception $e) {
    // 503 (not the default 200) so an HTTP monitor polling ?health actually
    // sees this as down instead of reading "200 OK" off a page of error text.
    http_response_code(503);
    die("Database connection failed: " . htmlspecialchars($e->getMessage()));
}

// ── Cron API Key ─────────────────────────────────────────────────────────────
// Unset/empty means the cron path can never authenticate — fails closed, no shared default.
define('CRON_SECRET', getenv('CRON_SECRET') ?: '');
define('INSTANCE_LABEL', getenv('INVOXA_INSTANCE_LABEL') ?: '');

// ── Paths / vendored libs ────────────────────────────────────────────────────
define('INVOICES_DIR', '/usr/share/nginx/html/invoxa-invoices/');
define('INVOICES_URL', '/invoxa-invoices/');
define('BACKUPS_DIR', '/usr/share/nginx/html/invoxa-backups/');
// Receipts and attachments share the invoices webroot (see INVOICES_DIR),
// each in its own subfolder keyed by invoice id.
define('RECEIPTS_DIR', INVOICES_DIR . 'receipts/');
define('RECEIPTS_URL', INVOICES_URL . 'receipts/');
define('ATTACHMENTS_DIR', INVOICES_DIR . 'attachments/');
define('ATTACHMENTS_URL', INVOICES_URL . 'attachments/');
define('PHPMAILER_DIR', __DIR__ . '/lib/phpmailer/');
define('PDF_AUTOLOAD', __DIR__ . '/lib/pdf_autoload.php');
define('LOGO_FILENAME', 'invoxa_logo.jpg');
define('CRONTAB_PATH', '/etc/invoxa-crontab/root');
define('DOCS_DIR', __DIR__ . '/docs/');
define('LICENSE_PURCHASE_URL', 'https://buy.polar.sh/polar_cl_l17jacgCGmUFH6VhRN4lg0UeZ70Uj2XBj3N7L1WXKw2');
// Bump alongside CHANGELOG.md's top entry — shown in the sidebar footer and
// linked to Docs > Changelog.
define('APP_VERSION', '2.11.13');

// Login lockout — wrong password and wrong TOTP/backup code share one
// counter (see invoxaRegisterFailedLogin()).
define('LOGIN_MAX_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_MINUTES', 15);
define('PASSWORD_MIN_LENGTH', 8);

// ── Email template defaults ──────────────────────────────────────────────────
// Used when the matching invoxa_settings key (Settings > Email Templates)
// hasn't been customized. Placeholders are plain {token} text, substituted
// by renderEmailTemplate() below.
define('DEFAULT_INVOICE_SUBJECT', '{business_name} - Invoice for {client_name}');
define('DEFAULT_REMINDER_SUBJECT', 'Payment Reminder: Invoice {invoice_number} is overdue');
define('DEFAULT_REMINDER_BODY', "Hi {client_name},\n\nThis is a reminder that invoice {invoice_number}, due {due_date}, is now {days_overdue} days overdue. The outstanding balance is {amount}.\n\nPlease arrange payment at your earliest convenience. If you've already paid, you can disregard this message.\n\nThanks,\n{business_name}");

require_once __DIR__ . '/lib/markdown.php';
require_once __DIR__ . '/lib/invoice_helpers.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/clients.php';
require_once __DIR__ . '/lib/stats.php';
require_once __DIR__ . '/lib/exports.php';
require_once __DIR__ . '/lib/payments.php';
require_once __DIR__ . '/lib/backup.php';
require_once __DIR__ . '/lib/settings.php';

// Rendered doc content for the in-app doc modal — available before the auth
// gate so it also works from the login/signup screen. Read-only, fixed
// whitelist, no user input in the file path.
if (isset($_GET['doc']) && in_array($_GET['doc'], ['readme', 'install'], true)) {
    header('Content-Type: text/html; charset=utf-8');
    $__docFile = DOCS_DIR . ($_GET['doc'] === 'readme' ? 'README.md' : 'INSTALL.md');
    echo is_file($__docFile) ? invoxaRenderMarkdown(file_get_contents($__docFile)) : '<p>Document not found.</p>';
    exit;
}

// Health check endpoint for external monitors — public, no auth, placed
// before the schema migrations so it stays fast. Exercises the full
// nginx -> php-fpm -> mysql path, catching a wedged PHP-FPM pool or an
// unresponsive database that a plain nginx check would miss.
if (isset($_GET['health'])) {
    header('Content-Type: application/json');
    $dbOk = $mysqli->ping();
    http_response_code($dbOk ? 200 : 503);
    echo json_encode(['status' => $dbOk ? 'ok' : 'error', 'db' => $dbOk ? 'ok' : 'error']);
    exit;
}


require_once __DIR__ . '/lib/auth_gate.php';
// ── Client Portal (public, token-gated) ──────────────────────────────────────
// Deliberately outside the $isAuth gate — the one page a client (not the
// admin) sees. Token is a random 48-char string (see generate_portal_token
// below), looked up via prepared statement. Shows this client's own
// non-draft invoices and paid/outstanding status (read-only), plus their own
// open quotes with an Accept Quote action (see convertQuoteToInvoice()) —
// everything scoped to this token's client_key, nothing else.
if (isset($_GET['portal'])) {
    header('Content-Type: text/html; charset=utf-8');
    $portalToken = (string) $_GET['portal'];
    $stmt = $mysqli->prepare("SELECT client_key, client_name, portal_token_expires_at FROM invoxa_clients WHERE portal_token = ?");
    $stmt->bind_param("s", $portalToken);
    $stmt->execute();
    $portalClient = $stmt->get_result()->fetch_assoc();
    if ($portalClient && !empty($portalClient['portal_token_expires_at']) && strtotime($portalClient['portal_token_expires_at']) < time()) {
        $portalClient = null; // expired — treated identically to "not found" below, no separate branch needed
    }
    $businessName = $settings['business_name'] ?? 'Invoxa';
    $portalStyle ='*{box-sizing:border-box;}body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Inter,Roboto,sans-serif;background:#0a0f1c;color:#f7f9fc;margin:0;padding:2rem 1.25rem;}.wrap{max-width:760px;margin:0 auto;}h1{font-size:1.4rem;margin:0 0 0.25rem;}h2{font-size:1.05rem;margin:2rem 0 0.75rem;}.sub{color:#90a0bb;font-size:0.9rem;margin:0 0 2rem;}table{width:100%;border-collapse:collapse;background:#131b2e;border-radius:12px;overflow:hidden;border:1px solid rgba(255,255,255,0.08);}th,td{padding:0.85rem 1rem;text-align:left;font-size:0.9rem;}th{background:rgba(255,255,255,0.04);color:#90a0bb;font-weight:600;text-transform:uppercase;font-size:0.75rem;letter-spacing:0.04em;}td{border-top:1px solid rgba(255,255,255,0.06);}.status{display:inline-block;padding:0.2rem 0.6rem;border-radius:999px;font-size:0.78rem;font-weight:600;}.status-paid{background:rgba(34,197,94,0.15);color:#4ade80;}.status-overdue{background:rgba(239,68,68,0.15);color:#f87171;}.status-outstanding{background:rgba(234,179,8,0.15);color:#facc15;}.status-void{background:rgba(148,163,184,0.15);color:#94a3b8;}.status-quote{background:rgba(139,92,246,0.15);color:#a78bfa;}.empty{color:#90a0bb;text-align:center;padding:3rem 1rem;}.pay-btn,.accept-btn{display:inline-block;background:#4f7cff;color:#fff;text-decoration:none;padding:0.4rem 0.85rem;border-radius:6px;font-size:0.82rem;font-weight:600;white-space:nowrap;border:none;font-family:inherit;cursor:pointer;}.pay-btn:hover,.accept-btn:hover{background:#3d63e0;}.confirm-box{background:#131b2e;border:1px solid rgba(255,255,255,0.08);border-radius:12px;padding:1.5rem;}.confirm-actions{display:flex;gap:0.75rem;margin-top:1.25rem;}.cancel-link{display:inline-flex;align-items:center;color:#90a0bb;text-decoration:none;font-size:0.9rem;padding:0.4rem 0.85rem;}';
    if (!$portalClient) {
        http_response_code(404);
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>' . htmlspecialchars($businessName) . '</title><style>' . $portalStyle . '</style></head><body><div class="wrap"><h1>Link not found</h1><p class="sub">This portal link is invalid or has been revoked. Contact ' . htmlspecialchars($businessName) . ' for a new one.</p></div></body></html>';
        exit;
    }
    // The Client Portal is a paid feature — re-checked here on every view, not just when
    // the link was generated, so a license that's since been deactivated genuinely takes
    // existing links offline instead of quietly continuing to serve them. Deliberately not
    // the same "Link not found" message — this is a temporary, provider-side condition, not
    // a broken/revoked link, and the client viewing it didn't do anything wrong.
    if (!$licenseValid) {
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>' . htmlspecialchars($businessName) . '</title><style>' . $portalStyle . '</style></head><body><div class="wrap"><h1>Portal temporarily unavailable</h1><p class="sub">Please contact ' . htmlspecialchars($businessName) . ' directly for your invoice status.</p></div></body></html>';
        exit;
    }
    // Accepting is a POST-only, confirm-page-first flow (see the accept_quote branch
    // below) specifically so a bare GET — an email/chat link preview crawler
    // prefetching the URL, for example — can never trigger it by itself.
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_accept_quote'])) {
        $quoteId = (int) $_POST['confirm_accept_quote'];
        $quoteRow = $mysqli->query("SELECT client_key FROM invoxa_invoices WHERE id = $quoteId AND is_quote = 1")->fetch_assoc();
        if (!$quoteRow || $quoteRow['client_key'] !== $portalClient['client_key']) {
            echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>' . htmlspecialchars($businessName) . '</title><style>' . $portalStyle . '</style></head><body><div class="wrap"><h1>Quote not found</h1><p class="sub">This quote is no longer available. <a href="?portal=' . htmlspecialchars($portalToken) . '" style="color:#4f7cff;">Back to your invoices</a></p></div></body></html>';
            exit;
        }
        $acceptResult = convertQuoteToInvoice($mysqli, $settings, $quoteId, 'client');
        if (!$acceptResult['success']) {
            echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>' . htmlspecialchars($businessName) . '</title><style>' . $portalStyle . '</style></head><body><div class="wrap"><h1>Couldn\'t accept this quote</h1><p class="sub">' . htmlspecialchars($acceptResult['error']) . ' <a href="?portal=' . htmlspecialchars($portalToken) . '" style="color:#4f7cff;">Back to your invoices</a></p></div></body></html>';
            exit;
        }
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . htmlspecialchars($businessName) . '</title><style>' . $portalStyle . '</style></head><body><div class="wrap"><h1>Quote accepted!</h1><p class="sub">It\'s now invoice ' . htmlspecialchars($acceptResult['invoice_number']) . ' — ' . htmlspecialchars($businessName) . ' has been notified. <a href="?portal=' . htmlspecialchars($portalToken) . '" style="color:#4f7cff;">Back to your invoices</a></p></div></body></html>';
        exit;
    }
    if (isset($_GET['accept_quote'])) {
        $quoteId = (int) $_GET['accept_quote'];
        $quoteRow = $mysqli->query("SELECT invoice_number, amount, currency, quote_expires_at, client_key FROM invoxa_invoices WHERE id = $quoteId AND is_quote = 1")->fetch_assoc();
        if (!$quoteRow || $quoteRow['client_key'] !== $portalClient['client_key']) {
            echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>' . htmlspecialchars($businessName) . '</title><style>' . $portalStyle . '</style></head><body><div class="wrap"><h1>Quote not found</h1><p class="sub">This quote is no longer available. <a href="?portal=' . htmlspecialchars($portalToken) . '" style="color:#4f7cff;">Back to your invoices</a></p></div></body></html>';
            exit;
        }
        $expired = !empty($quoteRow['quote_expires_at']) && $quoteRow['quote_expires_at'] < date('Y-m-d');
        if ($expired) {
            echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>' . htmlspecialchars($businessName) . '</title><style>' . $portalStyle . '</style></head><body><div class="wrap"><h1>This quote has expired</h1><p class="sub">Contact ' . htmlspecialchars($businessName) . ' for a new one. <a href="?portal=' . htmlspecialchars($portalToken) . '" style="color:#4f7cff;">Back to your invoices</a></p></div></body></html>';
            exit;
        }
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . htmlspecialchars($businessName) . '</title><style>' . $portalStyle . '</style></head><body><div class="wrap"><h1>Accept quote ' . htmlspecialchars($quoteRow['invoice_number']) . '?</h1><div class="confirm-box"><p style="margin:0; color:#90a0bb;">' . htmlspecialchars(invoxaResolveCurrency($quoteRow['currency'] ?? '', $settings)) . ' ' . number_format((float) $quoteRow['amount'], 2) . '. Accepting turns this into a real invoice — ' . htmlspecialchars($businessName) . ' will be notified right away.</p><form method="POST" class="confirm-actions"><input type="hidden" name="confirm_accept_quote" value="' . (int) $quoteId . '"><button type="submit" class="accept-btn">Accept Quote</button><a href="?portal=' . htmlspecialchars($portalToken) . '" class="cancel-link">Cancel</a></form></div></div></body></html>';
        exit;
    }
    $invRes = $mysqli->prepare("SELECT invoice_number, invoice_date, due_date, amount, currency, paid_amount, status FROM invoxa_invoices WHERE client_key = ? AND is_quote = 0 AND status != 'draft' ORDER BY invoice_date DESC");
    $invRes->bind_param("s", $portalClient['client_key']);
    $invRes->execute();
    $portalInvoices = $invRes->get_result();
    $paymentsOn = ($settings['stripe_enabled'] ?? '0') === '1' || ($settings['paypal_enabled'] ?? '0') === '1';
    $rowsHtml = '';
    $today = date('Y-m-d');
    while ($inv = $portalInvoices->fetch_assoc()) {
        $rowCcy = invoxaResolveCurrency($inv['currency'] ?? '', $settings);
        $paidAmt = (float) ($inv['paid_amount'] ?? 0);
        $amt = (float) $inv['amount'];
        $unpaid = !in_array($inv['status'], ['paid', 'void'], true) && $paidAmt < $amt;
        if ($inv['status'] === 'void') {
            $statusHtml = '<span class="status status-void">Void</span>';
        } elseif ($inv['status'] === 'paid') {
            $statusHtml = '<span class="status status-paid">Paid</span>';
        } elseif ($paidAmt > 0) {
            $statusHtml = '<span class="status status-outstanding">Partially Paid (' . htmlspecialchars($rowCcy) . ' ' . number_format($paidAmt, 2) . ' of ' . number_format($amt, 2) . ')</span>';
        } elseif (!empty($inv['due_date']) && $inv['due_date'] < $today) {
            $statusHtml = '<span class="status status-overdue">Overdue</span>';
        } else {
            $statusHtml = '<span class="status status-outstanding">Awaiting Payment</span>';
        }
        $payCell = ($paymentsOn && $unpaid)
            ? '<a href="?pay=' . rawurlencode($inv['invoice_number']) . '" class="pay-btn">Pay Now</a>'
            : '';
        $rowsHtml .= '<tr><td>' . htmlspecialchars($inv['invoice_number']) . '</td><td>' . htmlspecialchars(substr($inv['invoice_date'], 0, 10)) . '</td><td>' . htmlspecialchars($inv['due_date'] ?? '') . '</td><td>' . htmlspecialchars($rowCcy) . ' ' . number_format($amt, 2) . '</td><td>' . $statusHtml . '</td><td>' . $payCell . '</td></tr>';
    }
    $tableOrEmpty = $rowsHtml !== ''
        ? '<table><thead><tr><th>Invoice</th><th>Date</th><th>Due</th><th>Amount</th><th>Status</th><th></th></tr></thead><tbody>' . $rowsHtml . '</tbody></table>'
        : '<div class="empty">No invoices yet.</div>';

    $quoteRes = $mysqli->prepare("SELECT id, invoice_number, invoice_date, amount, currency, quote_expires_at FROM invoxa_invoices WHERE client_key = ? AND is_quote = 1 ORDER BY invoice_date DESC");
    $quoteRes->bind_param("s", $portalClient['client_key']);
    $quoteRes->execute();
    $portalQuotes = $quoteRes->get_result();
    $quoteRowsHtml = '';
    while ($q = $portalQuotes->fetch_assoc()) {
        $qExpired = !empty($q['quote_expires_at']) && $q['quote_expires_at'] < $today;
        $actionCell = $qExpired
            ? '<span class="status status-overdue">Expired</span>'
            : '<a href="?portal=' . rawurlencode($portalToken) . '&accept_quote=' . (int) $q['id'] . '" class="accept-btn">Accept Quote</a>';
        $expiresCell = !empty($q['quote_expires_at']) ? htmlspecialchars($q['quote_expires_at']) : '—';
        $quoteRowsHtml .= '<tr><td>' . htmlspecialchars($q['invoice_number']) . '</td><td>' . htmlspecialchars(substr($q['invoice_date'], 0, 10)) . '</td><td>' . htmlspecialchars(invoxaResolveCurrency($q['currency'] ?? '', $settings)) . ' ' . number_format((float) $q['amount'], 2) . '</td><td>' . $expiresCell . '</td><td>' . $actionCell . '</td></tr>';
    }
    $quotesSectionHtml = $quoteRowsHtml !== ''
        ? '<h2>Open Quotes</h2><table><thead><tr><th>Quote</th><th>Date</th><th>Amount</th><th>Valid Until</th><th></th></tr></thead><tbody>' . $quoteRowsHtml . '</tbody></table>'
        : '';

    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . htmlspecialchars($businessName) . ' — Invoices</title><meta name="robots" content="noindex, nofollow"><style>' . $portalStyle . '</style></head><body><div class="wrap"><h1>' . htmlspecialchars($businessName) . '</h1><p class="sub">Invoices for ' . htmlspecialchars($portalClient['client_name']) . '</p>' . $tableOrEmpty . $quotesSectionHtml . '</div></body></html>';
    exit;
}

invoxaHandlePublicPaymentRoutes($mysqli, $settings, $licenseValid);

require_once __DIR__ . '/lib/api_v1.php';

$emailPassword = getenv('SMTP_PASSWORD') ?: '';

// ── Invoice Generation Core ──────────────────────────────────────────────────
// A short, stable fingerprint of the active license, quietly embedded in
// every generated invoice (see generateInvoiceHTML()). Traces a leaked
// invoice back to the license it came from via a fingerprint-to-buyer
// lookup kept outside this app. Not shown in the UI, and not a substitute
// for the signature check — a deterrent only.
// (invoiceWatermarkFingerprint, computeInvoiceTotals, formatPct now live in
// lib/invoice_helpers.php — see the require_once near the top of this file)


// (expenseCategories now lives in lib/invoice_helpers.php)

// (generateInvoiceHTML, generateInvoicePdf, and buildAccountingJournal now live in lib/*.php)

function generateInvoiceNumber($mysqli, $clientKey, $clientName, array $settings = [])
{
    $invoiceDir = INVOICES_DIR . strtolower(str_replace(" ", "_", $clientName));
    if (!is_dir($invoiceDir)) {
        mkdir($invoiceDir, 0777, true);
    }
    $highestNumber = 0;
    foreach (glob("$invoiceDir/*.html") as $file) {
        if (preg_match('/(\d+)\.html$/', basename($file), $matches))
            $highestNumber = max($highestNumber, (int) $matches[1]);
    }
    // Looked up by client_key rather than an invoice_number prefix match, so
    // this works regardless of what invoice_number_template produces.
    $q = $mysqli->prepare("SELECT invoice_number FROM invoxa_invoices WHERE client_key = ? AND is_quote = 0");
    $q->bind_param("s", $clientKey);
    $q->execute();
    $res = $q->get_result();
    while ($row = $res->fetch_assoc()) {
        if (preg_match('/(\d+)$/', $row['invoice_number'], $m)) {
            $highestNumber = max($highestNumber, (int) $m[1]);
        }
    }
    $padding = (int) ($settings['invoice_number_padding'] ?? 3);
    if ($padding < 1)
        $padding = 3;
    $template = trim($settings['invoice_number_template'] ?? '') ?: '{key}{seq}';
    $seq = str_pad((string) ($highestNumber + 1), $padding, '0', STR_PAD_LEFT);
    return strtr($template, [
        '{key}' => strtoupper($clientKey),
        '{seq}' => $seq,
        '{year}' => date('Y'),
        '{month}' => date('m'),
    ]);
}

// (validDateOverride now lives in lib/invoice_helpers.php)

function processInvoice($mysqli, $client, $amount, $description, $emailPassword, $lineItems = null, $dueDateOverride = null, $memo = null, $discountPct = 0.0, $taxRate = 0.0)
{
    global $settings, $licenseValid;
    $showPoweredBy = !($licenseValid && ($settings['hide_powered_by'] ?? '0') === '1');
    $date = date("Y-m-d");
    $termsDays = (int) ($client['payment_terms_days'] ?? 21);
    $dueDate = $dueDateOverride ?: date("Y-m-d", strtotime("+{$termsDays} days"));
    $invNum = generateInvoiceNumber($mysqli, $client['client_key'], $client['client_name'], $settings);
    if ($lineItems === null) {
        $lineItems = [['code' => 'WEB01', 'desc' => $description, 'amount' => number_format($amount, 2)]];
    }

    $brandColor = $settings['brand_color'] ?? '#4a90e2';
    $footerText = $settings['footer_text'] ?? '';
    $currencyCode = invoxaResolveCurrency($client['currency'] ?? '', $settings);
    $fromName = $settings['business_name'] ?? (getenv('SMTP_FROM_NAME') ?: 'Invoxa');
    $fromEmail = getenv('SMTP_FROM_EMAIL') ?: '';
    $invoiceTemplate = $settings['invoice_template'] ?? 'detailed';

    // Pay Now only appears if a gateway is enabled AND a public URL is
    // configured (see invoxaPublicBaseUrl() — cron-triggered invoices have
    // no request context to infer one from).
    $payUrl = null;
    $publicBase = invoxaPublicBaseUrl($settings);
    if ($licenseValid && $publicBase !== null && (($settings['stripe_enabled'] ?? '0') === '1' || ($settings['paypal_enabled'] ?? '0') === '1')) {
        $payUrl = $publicBase . '/?pay=' . rawurlencode($invNum);
    }

    $htmlContent = generateInvoiceHTML(
        $client['client_name'],
        $date,
        $dueDate,
        $invNum,
        number_format($amount, 2),
        $client['account_name'] ?: ($settings['default_account_name'] ?? ''),
        $client['account_number'] ?: ($settings['default_account_number'] ?? ''),
        $fromEmail,
        $lineItems,
        $brandColor,
        $footerText,
        $currencyCode,
        invoiceWatermarkFingerprint($settings),
        $discountPct,
        $taxRate,
        $invoiceTemplate,
        $payUrl,
        $showPoweredBy,
        vatNumber: $settings['vat_number'] ?? '',
        recipientPhone: $client['phone'] ?? '',
        recipientAddress: $client['address'] ?? '',
        customTemplate: $invoiceTemplate === 'custom' ? ($settings['custom_invoice_template'] ?? '') : null,
        businessName: $fromName
    );

    $folderName = strtolower(str_replace(" ", "_", $client['client_name']));
    $invoiceDir = INVOICES_DIR . $folderName;
    if (!is_dir($invoiceDir))
        @mkdir($invoiceDir, 0777, true);
    $htmlFile = "$invoiceDir/$invNum.html";
    $htmlForFile = str_replace('src="cid:logo_cid"', 'src="' . INVOICES_URL . LOGO_FILENAME . '"', $htmlContent);
    @file_put_contents($htmlFile, $htmlForFile);
    $relPath = "invoices/$folderName/$invNum.html";

    require_once PHPMAILER_DIR . 'PHPMailer.php';
    require_once PHPMAILER_DIR . 'SMTP.php';
    require_once PHPMAILER_DIR . 'Exception.php';
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    $emailSent = false;
    $errorMsg = "";
    try {
        $mail->isSMTP();
        $mail->Host = getenv('SMTP_HOST') ?: '';
        $mail->Port = (int) (getenv('SMTP_PORT') ?: 587);
        $mail->SMTPAuth = true;
        $mail->Username = getenv('SMTP_USER') ?: '';
        $mail->Password = $emailPassword;
        $mail->SMTPSecure = match (strtolower(getenv('SMTP_ENCRYPTION') ?: 'tls')) {
            'ssl' => PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS,
            'none', '' => false,
            default => PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS,
        };
        $mail->CharSet = 'UTF-8';
        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($client['email'], $client['client_name']);
        $mail->Subject = renderEmailTemplate($settings['invoice_email_subject'] ?? DEFAULT_INVOICE_SUBJECT, [
            'business_name' => $fromName,
            'client_name' => $client['client_name'],
            'invoice_number' => $invNum,
            'amount' => $currencyCode . ' ' . number_format($amount, 2),
            'due_date' => $dueDate,
        ]);
        $mail->isHTML(true);
        $mail->Body = $htmlContent;
        $logoPath = INVOICES_DIR . LOGO_FILENAME;
        if (file_exists($logoPath)) {
            $mail->addEmbeddedImage($logoPath, 'logo_cid');
        }
        $mail->addStringAttachment($htmlContent, "Invoice-{$invNum}.html", 'base64', 'text/html');
        $mail->send();
        $emailSent = true;
    } catch (Exception $e) {
        $errorMsg = $e->getMessage();
    }

    $status = $emailSent ? 'sent' : 'failed';
    $stmt = $mysqli->prepare("INSERT INTO invoxa_invoices (invoice_number, client_key, client_name, recipient_email, invoice_date, due_date, amount, currency, status, html_content, file_path) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ssssssdssss", $invNum, $client['client_key'], $client['client_name'], $client['email'], $date, $dueDate, $amount, $currencyCode, $status, $htmlContent, $relPath);
    $stmt->execute();

    $actionType = $emailSent ? 'email_sent' : 'email_failed';
    $notes = $emailSent ? "Invoice generated and emailed to {$client['email']}" : "Send failed: " . $errorMsg;
    $iid = $stmt->insert_id;
    invoxaLogAction($mysqli, $iid, $invNum, $actionType, $notes);

    if (!$emailSent) {
        notifyChannel($mysqli, $settings, 'notify_on_email_failed', "\xE2\x9C\x89\xEF\xB8\x8F Invoice email failed to send — {$invNum} ({$client['client_name']}): {$errorMsg}");
    }

    if ($memo !== null && trim($memo) !== '') {
        invoxaLogAction($mysqli, $iid, $invNum, 'note_added', trim($memo));
    }

    return ['success' => $emailSent, 'invNum' => $invNum, 'error' => $errorMsg];
}

// (renderEmailTemplate, sendTelegramNotification, sendSlackNotification now
// live in lib/invoice_helpers.php)

// Sends to whichever channel is configured under Settings > Notifications
// (none/telegram/slack), after checking the per-event toggle. Never surfaces
// failures to the caller — a broken notification config must not block the
// invoice action itself.
function notifyChannel($mysqli, array $settings, string $eventToggleKey, string $message): void
{
    $channel = $settings['notification_channel'] ?? 'none';
    if ($channel === 'none')
        return;
    if (($settings[$eventToggleKey] ?? '1') !== '1')
        return;
    if ($channel === 'slack') {
        $result = sendSlackNotification($settings['slack_webhook_url'] ?? '', $message);
    } elseif ($channel === 'webhook') {
        $result = sendWebhookNotification($settings['webhook_url'] ?? '', $message, $settings['webhook_format'] ?? 'json_text');
    } else {
        $result = sendTelegramNotification($settings['telegram_bot_token'] ?? '', $settings['telegram_chat_id'] ?? '', $message);
    }
    if (!$result['success']) {
        $notes = ucfirst($channel) . ' notification failed: ' . $result['error'];
        invoxaLogAction($mysqli, null, '', 'notification_failed', $notes);
    }
}

// Turns a saved quote into a real, billable invoice — assigns the next real
// invoice number for that client, rewrites the stored HTML/file to match, and
// flips is_quote off. Shared by the admin's Convert button (convert_quote)
// and a client accepting their own quote from the Client Portal ($source
// distinguishes the two for the audit log and notification).
function convertQuoteToInvoice($mysqli, array $settings, int $quoteId, string $source = 'admin'): array
{
    $row = $mysqli->query("SELECT * FROM invoxa_invoices WHERE id = " . (int) $quoteId . " AND is_quote = 1")->fetch_assoc();
    if (!$row) {
        return ['success' => false, 'error' => 'Quote not found'];
    }
    if (!empty($row['quote_expires_at']) && $row['quote_expires_at'] < date('Y-m-d') && $source === 'client') {
        return ['success' => false, 'error' => 'This quote has expired — contact ' . ($settings['business_name'] ?? 'us') . ' for a new one.'];
    }
    $clientKey = $row['client_key'];
    $clientName = $row['client_name'];
    $folderName = strtolower(str_replace(' ', '_', $clientName));
    $invoiceDir = INVOICES_DIR . $folderName;
    if (!is_dir($invoiceDir))
        @mkdir($invoiceDir, 0777, true);
    $prefix = strtoupper($clientKey);
    $q2 = $mysqli->prepare("SELECT invoice_number FROM invoxa_invoices WHERE invoice_number LIKE CONCAT(?, '%') AND is_quote = 0");
    $q2->bind_param("s", $prefix);
    $q2->execute();
    $res2 = $q2->get_result();
    $highest = 0;
    while ($r2 = $res2->fetch_assoc()) {
        if (preg_match('/(\d+)$/', $r2['invoice_number'], $m))
            $highest = max($highest, (int) $m[1]);
    }
    $newNum = $prefix . str_pad($highest + 1, 3, '0', STR_PAD_LEFT);
    $htmlContent = str_replace($row['invoice_number'], $newNum, $row['html_content']);

    if ($row['file_path']) {
        $oldFullPath = INVOICES_DIR . preg_replace('#^invoices/#', '', $row['file_path']);
        if (file_exists($oldFullPath)) {
            @unlink($oldFullPath);
        }
    }

    $htmlFile = "$invoiceDir/$newNum.html";
    @file_put_contents($htmlFile, $htmlContent);
    $relPath = "invoices/$folderName/$newNum.html";
    $stmt = $mysqli->prepare("UPDATE invoxa_invoices SET is_quote = 0, invoice_number = ?, file_path = ?, html_content = ?, status = 'sent' WHERE id = ?");
    $stmt->bind_param("sssi", $newNum, $relPath, $htmlContent, $quoteId);
    $stmt->execute();

    $actionType = $source === 'client' ? 'quote_accepted' : 'quote_converted';
    $actionNotes = $source === 'client'
        ? "Quote {$row['invoice_number']} accepted by {$clientName} via the Client Portal, now invoice {$newNum}"
        : "Quote {$row['invoice_number']} converted to invoice {$newNum}";
    invoxaLogAction($mysqli, $quoteId, $newNum, $actionType, $actionNotes);

    if ($source === 'client') {
        notifyChannel($mysqli, $settings, 'notify_on_quote_accepted', "\xF0\x9F\x93\x9D Quote accepted — {$row['invoice_number']} ({$clientName}), now invoice {$newNum}");
    }

    return ['success' => true, 'invoice_number' => $newNum];
}

// Logs an audit-log entry when a webhook references an invoice Invoxa
// doesn't recognize (e.g. deleted after the checkout session was created).
// The webhook handlers still return 200 either way, but this leaves a trail.
function invoxaLogUnmatchedWebhook($mysqli, string $provider, string $eventType, string $reference): void
{
    global $settings;
    $notes = ucfirst($provider) . " webhook ({$eventType}) referenced an invoice/reference Invoxa doesn't recognize: '{$reference}'. Payment not credited.";
    invoxaLogAction($mysqli, null, '', 'webhook_unmatched', $notes);
    notifyChannel($mysqli, $settings, 'notify_on_webhook_unmatched', "\xE2\x9A\xA0\xEF\xB8\x8F " . ucfirst($provider) . " payment webhook didn't match any invoice ('{$reference}') — payment not credited.");
}

// Emails a one-time overdue reminder for every unpaid, non-quote invoice
// 7+ days past due, gated by 'reminders_enabled' (Settings > Payment
// Reminders) and run from the same cron trigger as recurring billing. The
// NOT EXISTS guard makes this idempotent per invoice — a failed send logs
// 'reminder_failed' instead, so it's retried on the next run.
function sendOverdueReminders($mysqli, array $settings, string $emailPassword): array
{
    $sent = 0;
    $errors = 0;
    $res = $mysqli->query(
        "SELECT i.* FROM invoxa_invoices i
         WHERE i.is_quote = 0
           AND i.status IN ('sent', 'pending')
           AND i.due_date IS NOT NULL
           AND i.due_date <= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
           AND NOT EXISTS (
               SELECT 1 FROM invoxa_actions a
               WHERE a.invoice_id = i.id AND a.action_type = 'reminder_sent'
           )"
    );

    $fromName = $settings['business_name'] ?? (getenv('SMTP_FROM_NAME') ?: 'Invoxa');
    $fromEmail = getenv('SMTP_FROM_EMAIL') ?: '';

    require_once PHPMAILER_DIR . 'PHPMailer.php';
    require_once PHPMAILER_DIR . 'SMTP.php';
    require_once PHPMAILER_DIR . 'Exception.php';

    while ($inv = $res->fetch_assoc()) {
        $currencyCode = invoxaResolveCurrency($inv['currency'] ?? '', $settings);
        $outstanding = (float) $inv['amount'] - (float) ($inv['paid_amount'] ?? 0);
        $daysOverdue = (int) floor((time() - strtotime($inv['due_date'])) / 86400);
        $vars = [
            'business_name' => $fromName,
            'client_name' => $inv['client_name'],
            'invoice_number' => $inv['invoice_number'],
            'amount' => $currencyCode . ' ' . number_format($outstanding, 2),
            'due_date' => date('Y-m-d', strtotime($inv['due_date'])),
            'days_overdue' => $daysOverdue,
        ];
        $subject = renderEmailTemplate($settings['reminder_email_subject'] ?? DEFAULT_REMINDER_SUBJECT, $vars);
        $body = renderEmailTemplate($settings['reminder_email_body'] ?? DEFAULT_REMINDER_BODY, $vars);

        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        $emailSent = false;
        $errorMsg = '';
        try {
            $mail->isSMTP();
            $mail->Host = getenv('SMTP_HOST') ?: '';
            $mail->Port = (int) (getenv('SMTP_PORT') ?: 587);
            $mail->SMTPAuth = true;
            $mail->Username = getenv('SMTP_USER') ?: '';
            $mail->Password = $emailPassword;
            $mail->SMTPSecure = match (strtolower(getenv('SMTP_ENCRYPTION') ?: 'tls')) {
                'ssl' => PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS,
                'none', '' => false,
                default => PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS,
            };
            $mail->CharSet = 'UTF-8';
            $mail->setFrom($fromEmail, $fromName);
            $mail->addAddress($inv['recipient_email'], $inv['client_name']);
            $mail->Subject = $subject;
            // Resends the original invoice HTML rather than a plain-text blurb.
            // Falls back to the plain-text template for rows with no stored
            // HTML (e.g. very old/imported invoices — see sync_missing).
            if (!empty($inv['html_content'])) {
                $mail->isHTML(true);
                $mail->Body = $inv['html_content'];
                $logoPath = INVOICES_DIR . LOGO_FILENAME;
                if (file_exists($logoPath)) {
                    $mail->addEmbeddedImage($logoPath, 'logo_cid');
                }
            } else {
                $mail->isHTML(false);
                $mail->Body = $body;
            }
            $mail->send();
            $emailSent = true;
        } catch (Exception $e) {
            $errorMsg = $e->getMessage();
        }

        $actionType = $emailSent ? 'reminder_sent' : 'reminder_failed';
        $notes = $emailSent
            ? "Overdue reminder emailed to {$inv['recipient_email']} ({$daysOverdue} days overdue)"
            : "Overdue reminder failed: " . $errorMsg;
        invoxaLogAction($mysqli, $inv['id'], $inv['invoice_number'], $actionType, $notes);

        // Fired regardless of whether the email itself sent — a broken SMTP
        // config shouldn't also silence the Telegram/Slack alert.
        notifyChannel($mysqli, $settings, 'notify_on_overdue', "\xE2\x9A\xA0\xEF\xB8\x8F Invoice {$inv['invoice_number']} ({$inv['client_name']}) is {$daysOverdue} days overdue — {$vars['amount']} outstanding");

        if ($emailSent)
            $sent++;
        else
            $errors++;
    }

    return ['sent' => $sent, 'errors' => $errors];
}

// Charges a one-time late fee for every unpaid, non-quote invoice
// $graceDays+ past due, gated by 'late_fee_enabled' (Settings > Billing >
// Late Fees) and off by default. Runs on the same cron trigger as recurring
// billing. Idempotent per invoice via the 'late_fee_charged' action logged
// against the original invoice's id. The fee is a real ad-hoc invoice (via
// processInvoice()) with its own number, HTML file, and email.
function applyLateFees($mysqli, array $settings, string $emailPassword): array
{
    $charged = 0;
    $errors = 0;
    $graceDays = (int) ($settings['late_fee_grace_days'] ?? 7);
    if ($graceDays < 0)
        $graceDays = 0;
    $feeType = ($settings['late_fee_type'] ?? 'percent') === 'flat' ? 'flat' : 'percent';
    $feeValue = (float) ($settings['late_fee_value'] ?? 0);
    if ($feeValue <= 0)
        return ['charged' => 0, 'errors' => 0];

    $stmt = $mysqli->prepare(
        "SELECT i.* FROM invoxa_invoices i
         WHERE i.is_quote = 0
           AND i.status IN ('sent', 'pending')
           AND i.due_date IS NOT NULL
           AND i.due_date <= DATE_SUB(CURDATE(), INTERVAL ? DAY)
           AND NOT EXISTS (
               SELECT 1 FROM invoxa_actions a
               WHERE a.invoice_id = i.id AND a.action_type = 'late_fee_charged'
           )"
    );
    $stmt->bind_param("i", $graceDays);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($inv = $res->fetch_assoc()) {
        $outstanding = (float) $inv['amount'] - (float) ($inv['paid_amount'] ?? 0);
        if ($outstanding <= 0)
            continue;
        $feeAmount = $feeType === 'flat' ? $feeValue : round($outstanding * $feeValue / 100, 2);
        if ($feeAmount <= 0)
            continue;

        $client = $mysqli->query("SELECT * FROM invoxa_clients WHERE client_key = '" . $mysqli->real_escape_string($inv['client_key']) . "'")->fetch_assoc();
        if (!$client) {
            $errors++;
            continue;
        }

        $feeLabel = $feeType === 'flat' ? 'flat fee' : "{$feeValue}%";
        $lineItems = [[
            'code' => 'LATE-FEE',
            'desc' => "Late fee ({$feeLabel}) for overdue invoice {$inv['invoice_number']}",
            'amount' => number_format($feeAmount, 2),
        ]];
        $result = processInvoice($mysqli, $client, $feeAmount, '', $emailPassword, $lineItems);

        // Logged as 'late_fee_charged' regardless of whether the email sent —
        // processInvoice() already created the invoice either way, so the fee
        // has genuinely been charged. Only a client lookup failure skips
        // logging, since that's worth retrying.
        $notes = $result['success']
            ? "Late fee invoice {$result['invNum']} generated for " . number_format($feeAmount, 2)
            : "Late fee invoice {$result['invNum']} generated for " . number_format($feeAmount, 2) . " but email failed: " . $result['error'];
        invoxaLogAction($mysqli, $inv['id'], $inv['invoice_number'], 'late_fee_charged', $notes);

        $currencyCode = invoxaResolveCurrency($client['currency'] ?? '', $settings);
        notifyChannel($mysqli, $settings, 'notify_on_late_fee', "\xE2\x9A\xA0\xEF\xB8\x8F Late fee charged — {$inv['invoice_number']} ({$client['client_name']}): {$currencyCode} " . number_format($feeAmount, 2));

        if ($result['success'])
            $charged++;
        else
            $errors++;
    }

    return ['charged' => $charged, 'errors' => $errors];
}

// Deletes Audit Log entries older than the configured retention window — off
// (keep forever) by default via 'audit_log_retention_days' being '0'. Runs
// on the same cron trigger as recurring billing, and always logs its own
// 'audit_log_pruned' action, even when nothing was deleted.
function pruneAuditActions($mysqli, array $settings): int
{
    $days = (int) ($settings['audit_log_retention_days'] ?? 0);
    if ($days <= 0)
        return 0;
    $stmt = $mysqli->prepare("DELETE FROM invoxa_actions WHERE performed_at < DATE_SUB(NOW(), INTERVAL ? DAY)");
    $stmt->bind_param("i", $days);
    $stmt->execute();
    $pruned = $stmt->affected_rows;

    // Only logged when the feature is on, to avoid a "pruned 0" entry every
    // cron cycle. Inserted after the delete so it can't be swept up by this run.
    $notes = "Removed {$pruned} audit log entr" . ($pruned === 1 ? 'y' : 'ies') . " older than {$days} days";
    invoxaLogAction($mysqli, null, '', 'audit_log_pruned', $notes);

    return $pruned;
}

// Shared by the initial page render and the ?api=table_html&which=invoices
// fragment endpoint, so the AJAX refresh can't drift from a full page load.
function renderInvoiceRows(array $invoices): string
{
    global $settings;
    ob_start();
    foreach ($invoices as $inv):
        $isOverdue = (!in_array($inv['status'], ['paid', 'void'], true) && strtotime($inv['due_date']) < time());
        $rowCcy = invoxaResolveCurrency($inv['currency'] ?? '', $settings);
        ?>
        <tr>
            <td><input type="checkbox" class="invoice-select-cb" value="<?= $inv['id'] ?>"
                    data-amount="<?= number_format(max(0, $inv['amount'] - $inv['paid_amount']), 2, '.', '') ?>"
                    data-status="<?= htmlspecialchars($inv['status']) ?>" onchange="updateInvoiceBulkBar()"></td>
            <td style="font-family: monospace;"><?= htmlspecialchars($inv['invoice_number']) ?></td>
            <td><?= htmlspecialchars(date('Y-m-d', strtotime($inv['invoice_date']))) ?></td>
            <td style="<?= $isOverdue ? 'color: var(--danger); font-weight: bold;' : '' ?>">
                <?= htmlspecialchars(date('Y-m-d', strtotime($inv['due_date']))) ?>
            </td>
            <td><?= htmlspecialchars($inv['client_name']) ?><?php if ($inv['is_test'])
                  echo ' <span class="badge test">Test</span>'; ?>
            </td>
            <td>
                <?php if ($inv['status'] !== 'paid' && $inv['paid_amount'] > 0): ?>
                    <div
                        style="font-size:0.75rem; color:var(--text-secondary); text-decoration:line-through;">
                        <?= htmlspecialchars($rowCcy) ?> $<?= number_format($inv['amount'], 2) ?></div>
                    <div style="color:var(--warning); font-weight:600;">
                        <?= htmlspecialchars($rowCcy) ?> $<?= number_format($inv['amount'] - $inv['paid_amount'], 2) ?></div>
                <?php else: ?>
                    <?= htmlspecialchars($rowCcy) ?> $<?= number_format($inv['amount'], 2) ?>
                <?php endif; ?>
            </td>
            <td>
                <?php if ($inv['status'] !== 'paid' && $inv['paid_amount'] > 0): ?>
                    <span class="badge partial">Partial</span>
                <?php else: ?>
                    <span
                        class="badge <?= htmlspecialchars($inv['status']) ?>"><?= htmlspecialchars($inv['status']) ?></span>
                <?php endif; ?>
                <?php if ($isOverdue): ?>
                    <span class="badge overdue">Overdue</span>
                <?php endif; ?>
            </td>
            <td>
                <?php if ($inv['file_path']): ?>
                    <a href="javascript:void(0)" title="Preview in-app — click Copy Link inside for the direct URL"
                        onclick="viewInvoice(<?= htmlspecialchars(json_encode($inv)) ?>)"
                        style="color: var(--accent); text-decoration: none; font-size: 0.85rem;"><i
                            class="fa-solid fa-link"></i>
                        <?= htmlspecialchars(basename($inv['file_path'])) ?></a>
                <?php else: ?>
                    <span style="color: var(--text-secondary); font-size: 0.85rem;">N/A</span>
                <?php endif; ?>
            </td>
            <td style="white-space: nowrap;">
                <button class="btn small"
                    onclick="viewInvoice(<?= htmlspecialchars(json_encode($inv)) ?>)"><i
                        class="fa-solid fa-eye"></i></button>
                <button class="btn small"
                    onclick="openNoteModal(<?= $inv['id'] ?>, '<?= htmlspecialchars($inv['invoice_number']) ?>')"
                    title="<?= $inv['note_count'] > 0 ? $inv['note_count'] . ' note(s) added' : 'Add note' ?>"
                    style="<?= $inv['note_count'] > 0 ? 'background:var(--accent); color:white; border:none;' : '' ?>">
                    <i
                        class="fa-solid <?= $inv['note_count'] > 0 ? 'fa-comment' : 'fa-comment' ?>"></i><?php if ($inv['note_count'] > 0): ?>
                        <span
                            style="font-size:0.7rem;"><?= $inv['note_count'] ?></span><?php endif; ?></button>
                <?php if ($inv['status'] === 'void'): ?>
                    <!-- No Mark Paid/Unpaid for a voided invoice — it's dead, not payable. -->
                <?php elseif ($inv['status'] !== 'paid'): ?>
                    <button class="btn small success"
                        onclick="openMarkPaid(<?= htmlspecialchars(json_encode($inv)) ?>)"
                        title="Mark Paid"><i class="fa-solid fa-check"></i></button>
                    <?php if ($inv['paid_amount'] > 0): ?>
                        <button class="btn small"
                            style="background: var(--warning); color: white; border: none;"
                            onclick="markUnpaid(<?= $inv['id'] ?>)" title="Clear Partial Payment"><i
                                class="fa-solid fa-rotate-left"></i></button>
                    <?php endif; ?>
                <?php else: ?>
                    <button class="btn small"
                        style="background: var(--warning); color: white; border: none;"
                        onclick="markUnpaid(<?= $inv['id'] ?>)" title="Mark Unpaid"><i
                            class="fa-solid fa-xmark"></i></button>
                <?php endif; ?>
                <?php if ($inv['status'] !== 'void' && $inv['status'] !== 'draft'): ?>
                    <button class="btn small" onclick="resendInvoiceEmail(<?= $inv['id'] ?>)"
                        title="Resend Invoice Email"><i class="fa-solid fa-paper-plane"></i></button>
                <?php endif; ?>
                <?php if ($inv['status'] === 'void'): ?>
                    <button class="btn small" onclick="unvoidInvoice(<?= $inv['id'] ?>)"
                        title="Restore from Void"><i class="fa-solid fa-rotate-left"></i></button>
                <?php elseif ($inv['status'] !== 'paid'): ?>
                    <button class="btn small" style="background: var(--surface-hover); color: var(--text-secondary);"
                        onclick="voidInvoice(<?= $inv['id'] ?>, '<?= htmlspecialchars(addslashes($inv['invoice_number'])) ?>')"
                        title="Void Invoice"><i class="fa-solid fa-ban"></i></button>
                <?php endif; ?>
                <button class="btn small" style="background: var(--danger); color: white; border: none;"
                    onclick="deleteInvoice(<?= $inv['id'] ?>)"><i
                        class="fa-solid fa-trash"></i></button>
            </td>
        </tr>
    <?php endforeach;
    return ob_get_clean();
}


// Same idea for the Quotes table — takes the mysqli result directly since the
// original inline block used a while() over the live query rather than an array.
function renderQuoteRows($qRes): string
{
    global $settings;
    ob_start();
    while ($q = $qRes->fetch_assoc()):
        $__quoteExpired = !empty($q['quote_expires_at']) && $q['quote_expires_at'] < date('Y-m-d');
        $rowCcy = invoxaResolveCurrency($q['currency'] ?? '', $settings);
        ?>
        <tr>
            <td><input type="checkbox" class="quote-select-cb" value="<?= $q['id'] ?>" data-expired="<?= $__quoteExpired ? '1' : '0' ?>" onchange="updateQuoteBulkBar()"></td>
            <td><strong><?= htmlspecialchars($q['invoice_number']) ?></strong></td>
            <td><?= htmlspecialchars($q['client_name']) ?></td>
            <td><?= htmlspecialchars(substr($q['invoice_date'], 0, 10)) ?></td>
            <td><?= htmlspecialchars($rowCcy) ?> $<?= number_format($q['amount'], 2) ?></td>
            <td><span class="badge"
                    style="background:rgba(139,92,246,0.15); color:#a78bfa;">Quote</span></td>
            <td>
                <?php if (empty($q['quote_expires_at'])): ?>
                    <span style="color:var(--text-secondary);">—</span>
                <?php elseif ($__quoteExpired): ?>
                    <span class="badge" style="background:rgba(245,69,92,0.15); color:var(--danger);"
                        title="Expired <?= htmlspecialchars($q['quote_expires_at']) ?>">Expired</span>
                <?php else: ?>
                    <?= htmlspecialchars($q['quote_expires_at']) ?>
                <?php endif; ?>
            </td>
            <td style="white-space:nowrap;">
                <button class="btn small" title="Preview"
                    onclick="viewInvoice(<?= htmlspecialchars(json_encode($q)) ?>)"><i
                        class="fa-solid fa-eye"></i></button>
                <button class="btn small success" title="Convert to Invoice"
                    onclick="convertQuote(<?= $q['id'] ?>,'<?= htmlspecialchars($q['invoice_number']) ?>',<?= $__quoteExpired ? 'true' : 'false' ?>)"><i
                        class="fa-solid fa-file-invoice"></i> Convert</button>
                <button class="btn small danger" onclick="deleteInvoice(<?= $q['id'] ?>)"><i
                        class="fa-solid fa-trash"></i></button>
            </td>
        </tr>
    <?php endwhile;
    return ob_get_clean();
}

// Same idea for the Expenses table (see sec-expenses / openExpenseModal()).
function renderExpenseRows(array $expenses): string
{
    $categories = expenseCategories();
    ob_start();
    foreach ($expenses as $e):
        ?>
        <tr>
            <td><input type="checkbox" class="expense-select-cb" value="<?= $e['id'] ?>" onchange="updateExpenseBulkBar()"></td>
            <td><?= htmlspecialchars(substr($e['expense_date'], 0, 10)) ?></td>
            <td><?= htmlspecialchars($e['vendor']) ?><?php if (!empty($e['recurring_expense_id'])): ?>
                    <i class="fa-solid fa-rotate" style="color:var(--text-secondary); font-size:0.75rem; margin-left:0.35rem;" title="Auto-logged from a recurring expense"></i>
                <?php endif; ?>
            </td>
            <td><?= htmlspecialchars($categories[$e['category']] ?? ucfirst($e['category'])) ?></td>
            <td>$<?= number_format($e['amount'], 2) ?></td>
            <td style="color:var(--text-secondary); max-width:260px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                <?= htmlspecialchars($e['description'] ?? '') ?></td>
            <td style="text-align:center;">
                <?php if ((int) $e['receipt_count'] > 0): ?>
                    <button type="button" class="btn small" title="<?= (int) $e['receipt_count'] ?> receipt<?= (int) $e['receipt_count'] === 1 ? '' : 's' ?>"
                        onclick="openExpenseModal(<?= htmlspecialchars(json_encode($e)) ?>)"><i class="fa-solid fa-paperclip"></i>
                        <?= (int) $e['receipt_count'] ?></button>
                <?php else: ?>
                    <span style="color:var(--text-secondary);">—</span>
                <?php endif; ?>
            </td>
            <td style="white-space:nowrap;">
                <button class="btn small" onclick="openExpenseModal(<?= htmlspecialchars(json_encode($e)) ?>)"><i
                        class="fa-solid fa-pen"></i></button>
                <button class="btn small danger" onclick="deleteExpense(<?= $e['id'] ?>)"><i
                        class="fa-solid fa-trash"></i></button>
            </td>
        </tr>
    <?php endforeach;
    return ob_get_clean();
}

// Recurring expense templates — the run_recurring cron action auto-logs one
// invoxa_expenses row per active template each period (see run_recurring
// above). Same idea as renderExpenseRows(), just for the template list.
function renderRecurringExpenseRows(array $recurringExpenses, bool $licenseValid): string
{
    $categories = expenseCategories();
    $freqLabels = ['weekly' => 'Weekly', 'monthly' => 'Monthly', 'quarterly' => 'Quarterly', 'annually' => 'Annually'];
    ob_start();
    foreach ($recurringExpenses as $re):
        ?>
        <tr style="<?= $re['is_active'] ? '' : 'opacity:0.55;' ?>">
            <td><?= htmlspecialchars($re['vendor']) ?></td>
            <td><?= htmlspecialchars($categories[$re['category']] ?? ucfirst($re['category'])) ?></td>
            <td>$<?= number_format($re['amount'], 2) ?></td>
            <td><?= htmlspecialchars($freqLabels[$re['frequency']] ?? ucfirst($re['frequency'])) ?></td>
            <td>
                <label style="display:inline-flex; align-items:center; gap:0.4rem; cursor:<?= $licenseValid ? 'pointer' : 'not-allowed' ?>;">
                    <input type="checkbox" <?= $re['is_active'] ? 'checked' : '' ?> <?= $licenseValid ? '' : 'disabled' ?>
                        onchange="toggleRecurringExpenseActive(<?= $re['id'] ?>, this.checked)">
                    <span style="font-size:0.8rem; color:var(--text-secondary);"><?= $re['is_active'] ? 'Active' : 'Paused' ?></span>
                </label>
            </td>
            <td style="white-space:nowrap;">
                <button class="btn small" <?= $licenseValid ? '' : 'disabled title="Requires a license"' ?>
                    onclick="openRecurringExpenseModal(<?= htmlspecialchars(json_encode($re)) ?>)"><i
                        class="fa-solid fa-pen"></i></button>
                <button class="btn small danger" onclick="deleteRecurringExpense(<?= $re['id'] ?>)"><i
                        class="fa-solid fa-trash"></i></button>
            </td>
        </tr>
    <?php endforeach;
    return ob_get_clean();
}


// Dashboard's Recent Activity list — just the row markup, same reasoning as
// the table row functions above.
function renderActivityRows(array $actions): string
{
    ob_start();
    foreach (array_slice($actions, 0, 5) as $a):
        ?>
        <tr>
            <td
                style="color:var(--text-secondary); font-size:0.875rem; border:none; border-bottom:1px solid var(--border);">
                <?= htmlspecialchars(date('M j, Y g:i A', strtotime($a['performed_at']))) ?>
            </td>
            <td style="border:none; border-bottom:1px solid var(--border);">
                <?= htmlspecialchars($a['action_type']) ?> -
                <?= htmlspecialchars($a['notes'] ?? '') ?>
            </td>
            <td style="border:none; border-bottom:1px solid var(--border);">
                <?= htmlspecialchars($a['client_name'] ?? '') ?: '<span style="color:var(--text-secondary)">System</span>' ?>
            </td>
        </tr>
    <?php endforeach;
    if (empty($actions)): ?>
        <tr>
            <td colspan="3"
                style="text-align:center; padding: 2rem; color:var(--text-secondary); border:none;">
                No recent activity</td>
        </tr>
    <?php endif;
    return ob_get_clean();
}


// The entire Filesystem Sync tab — same reasoning as renderStatsSection() above
// (no client-side state worth preserving across a refresh).

// ── AJAX Handlers ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    try {
        // Open-core: everything works without a license except seven paid
        // capabilities. Five are POST actions, gated here in one place; the
        // other two (Reporting & Statistics, hiding "Powered by Invoxa") are
        // checked at render time — see renderStatsSection() and
        // save_business_identity below.
        // - Stripe/PayPal payment collection (configuring it, not the webhook).
        // - Recurring billing automation (manual Ad Hoc invoicing and manual
        //   reminders stay free).
        // - The Client Portal (generate_portal_token; revoke stays free).
        // - The external API (create_api_token / renew_api_token; revoke/delete
        //   stay free).
        // - Recurring expense templates (same bucket as recurring billing
        //   automation; deleting a template stays free, same as the others above).
        // - Adding a teammate beyond the original account (create_user; editing
        //   or removing one — update_user/delete_user — stays free, same pattern
        //   as the others above).
        $__licensePaidActions = ['save_payment_settings', 'test_stripe_connection', 'test_paypal_connection', 'run_recurring', 'toggle_cron', 'update_cron', 'toggle_recurring_bypass_guard', 'toggle_late_fees', 'save_late_fee_settings', 'toggle_reminders', 'generate_portal_token', 'create_api_token', 'renew_api_token', 'save_recurring_expense', 'toggle_recurring_expense', 'create_user'];
        if (!$licenseValid && in_array($_POST['action'], $__licensePaidActions, true)) {
            echo json_encode(['success' => false, 'error' => 'This needs a license — add a key under Settings > License, or see Docs for what a license unlocks.']);
            exit;
        }
        // Everything a "member" account (Settings > Users) can't do — system
        // configuration, billing/API credentials, other users' accounts, and
        // Data Management. Members keep full access to day-to-day invoicing,
        // clients, quotes, and expenses, plus their own Account tab (personal
        // profile/password/2FA — see update_profile/totp_* below, deliberately
        // not on this list). $isCron requests bypass this the same way they
        // bypass the $isAuth gate above — a cron-triggered run has no user at
        // all, and CRON_SECRET is its own, separate authorization.
        $__adminOnlyActions = ['backup_db', 'clear_demo_data', 'create_api_token', 'create_user', 'delete_api_token', 'delete_missing_db', 'delete_single_db_entry', 'delete_untracked_file', 'factory_reset', 'fix_paid_dates', 'get_db_stats', 'import_backup', 'import_clients_csv', 'list_backups', 'preview_restore', 'renew_api_token', 'restore_db_backup', 'restore_missing', 'revoke_api_token', 'run_recurring', 'run_test_suite', 'save_audit_retention', 'save_backup_retention', 'save_business_identity', 'save_email_templates', 'save_invoice_defaults', 'save_invoice_numbering', 'save_invoice_template', 'save_late_fee_settings', 'save_license_key', 'save_notification_settings', 'save_offsite_backup', 'save_payment_details', 'save_payment_settings', 'seed_demo_data', 'sync_missing', 'test_email', 'test_notification', 'test_paypal_connection', 'test_stripe_connection', 'toggle_cron', 'toggle_late_fees', 'toggle_recurring_bypass_guard', 'toggle_reminders', 'toggle_show_test_only', 'toggle_test_clients', 'update_cron', 'update_user', 'delete_user'];
        if (!$isCron && !$isAdmin && in_array($_POST['action'], $__adminOnlyActions, true)) {
            echo json_encode(['success' => false, 'error' => 'This requires an admin account — see Settings > Users.']);
            exit;
        }
        if ($_POST['action'] === 'get_nav_counts') { invoxaHandleGetNavCounts($mysqli, $settings); }
        if ($_POST['action'] === 'global_search') {
            // A handful of results per category, not a full paginated search — this
            // is a "jump to that one record" quick-search, not a replacement for each
            // table's own search box.
            $q = trim($_POST['q'] ?? '');
            if (mb_strlen($q) < 2) {
                echo json_encode(['success' => true, 'invoices' => [], 'clients' => [], 'expenses' => []]);
                exit;
            }
            $like = '%' . $q . '%';
            $invStmt = $mysqli->prepare("SELECT id, invoice_number, client_name, amount, status, is_quote FROM invoxa_invoices WHERE invoice_number LIKE ? OR client_name LIKE ? ORDER BY invoice_date DESC LIMIT 6");
            $invStmt->bind_param("ss", $like, $like);
            $invStmt->execute();
            $invoices = $invStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $cliStmt = $mysqli->prepare("SELECT id, client_name, email FROM invoxa_clients WHERE client_name LIKE ? OR email LIKE ? ORDER BY client_name ASC LIMIT 6");
            $cliStmt->bind_param("ss", $like, $like);
            $cliStmt->execute();
            $searchClients = $cliStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $expStmt = $mysqli->prepare("SELECT id, expense_date, vendor, category, amount FROM invoxa_expenses WHERE vendor LIKE ? OR description LIKE ? ORDER BY expense_date DESC LIMIT 6");
            $expStmt->bind_param("ss", $like, $like);
            $expStmt->execute();
            $searchExpenses = $expStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            echo json_encode(['success' => true, 'invoices' => $invoices, 'clients' => $searchClients, 'expenses' => $searchExpenses]);
            exit;
        }
        if ($_POST['action'] === 'save_license_key') { invoxaHandleSaveLicenseKey($mysqli, $settings); }
        if ($_POST['action'] === 'save_client') { invoxaHandleSaveClient($mysqli); }
        if ($_POST['action'] === 'delete_client') { invoxaHandleDeleteClient($mysqli); }
        if ($_POST['action'] === 'update_client_flags') { invoxaHandleUpdateClientFlags($mysqli); }
        if ($_POST['action'] === 'generate_portal_token') { invoxaHandleGeneratePortalToken($mysqli); }
        if ($_POST['action'] === 'revoke_portal_token') { invoxaHandleRevokePortalToken($mysqli); }
        if ($_POST['action'] === 'save_expense') {
            $id = (int) ($_POST['id'] ?? 0);
            $date = validDateOverride($_POST['expense_date'] ?? null) ?: date('Y-m-d');
            $vendor = trim($_POST['vendor'] ?? '');
            $category = array_key_exists($_POST['category'] ?? '', expenseCategories()) ? $_POST['category'] : 'other';
            $amount = (float) ($_POST['amount'] ?? 0);
            $description = trim($_POST['description'] ?? '');

            if ($id > 0) {
                $stmt = $mysqli->prepare("UPDATE invoxa_expenses SET expense_date=?, vendor=?, category=?, amount=?, description=? WHERE id=?");
                $stmt->bind_param("sssdsi", $date, $vendor, $category, $amount, $description, $id);
                $stmt->execute();
            } else {
                $stmt = $mysqli->prepare("INSERT INTO invoxa_expenses (expense_date, vendor, category, amount, description) VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param("sssds", $date, $vendor, $category, $amount, $description);
                $stmt->execute();
                $id = $mysqli->insert_id;
            }
            echo json_encode(['success' => true, 'id' => $id]);
            exit;
        }
        if ($_POST['action'] === 'delete_expense') {
            $id = (int) ($_POST['id'] ?? 0);
            $row = $mysqli->query("SELECT receipt_path FROM invoxa_expenses WHERE id = " . $id)->fetch_assoc();
            if ($row && !empty($row['receipt_path'])) {
                @unlink(RECEIPTS_DIR . $row['receipt_path']);
            }
            $recRes = $mysqli->query("SELECT stored_path FROM invoxa_expense_receipts WHERE expense_id = $id");
            while ($recRow = $recRes->fetch_assoc())
                @unlink(RECEIPTS_DIR . $recRow['stored_path']);
            @rmdir(RECEIPTS_DIR . $id);
            $mysqli->query("DELETE FROM invoxa_expense_receipts WHERE expense_id = $id");
            $stmt = $mysqli->prepare("DELETE FROM invoxa_expenses WHERE id=?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            echo json_encode(['success' => true]);
            exit;
        }
        if ($_POST['action'] === 'get_expense_receipts') {
            $expenseId = (int) ($_POST['expense_id'] ?? 0);
            $res = $mysqli->query("SELECT id, filename, stored_path, file_size, doc_type, uploaded_at FROM invoxa_expense_receipts WHERE expense_id = $expenseId ORDER BY uploaded_at DESC");
            $receipts = [];
            while ($r = $res->fetch_assoc()) {
                $r['url'] = RECEIPTS_URL . implode('/', array_map('rawurlencode', explode('/', $r['stored_path'])));
                $receipts[] = $r;
            }
            echo json_encode(['success' => true, 'receipts' => $receipts]);
            exit;
        }
        if ($_POST['action'] === 'ocr_expense_receipt') {
            // Best-effort prefill only — never blocks or fails the actual
            // upload/save, since expense creation works fine without it.
            if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                echo json_encode(['success' => false, 'error' => 'No file uploaded, or the upload failed.']);
                exit;
            }
            $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
                echo json_encode(['success' => false, 'error' => 'OCR only works on image receipts, not PDFs.']);
                exit;
            }
            if (trim((string) shell_exec('command -v tesseract 2>/dev/null')) === '') {
                echo json_encode(['success' => false, 'error' => 'OCR is not available on this server (tesseract is not installed).']);
                exit;
            }
            $tmpPath = sys_get_temp_dir() . '/' . uniqid('ocr_', true) . '.' . $ext;
            if (!move_uploaded_file($_FILES['file']['tmp_name'], $tmpPath)) {
                echo json_encode(['success' => false, 'error' => 'Failed to read the uploaded file.']);
                exit;
            }
            $text = (string) shell_exec('tesseract ' . escapeshellarg($tmpPath) . ' stdout 2>/dev/null');
            @unlink($tmpPath);
            $parsed = parseReceiptOcrText($text);
            echo json_encode(['success' => true, 'vendor' => $parsed['vendor'], 'amount' => $parsed['amount'], 'confident' => $parsed['confident']]);
            exit;
        }
        if ($_POST['action'] === 'upload_expense_receipt') {
            $expenseId = (int) ($_POST['expense_id'] ?? 0);
            $expExists = $mysqli->query("SELECT id FROM invoxa_expenses WHERE id = $expenseId")->num_rows > 0;
            if (!$expExists) {
                echo json_encode(['success' => false, 'error' => 'Expense not found']);
                exit;
            }
            if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                echo json_encode(['success' => false, 'error' => 'No file uploaded, or the upload failed.']);
                exit;
            }
            $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'], true)) {
                echo json_encode(['success' => false, 'error' => 'Unsupported file type — receipts must be an image or PDF.']);
                exit;
            }
            $expenseDir = RECEIPTS_DIR . $expenseId;
            if (!is_dir($expenseDir))
                @mkdir($expenseDir, 0777, true);
            $origName = basename($_FILES['file']['name']);
            $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', $origName);
            $storedName = uniqid('rcpt_') . '_' . $safeName;
            if (!move_uploaded_file($_FILES['file']['tmp_name'], "$expenseDir/$storedName")) {
                echo json_encode(['success' => false, 'error' => 'Failed to save the uploaded file.']);
                exit;
            }
            $storedPath = "$expenseId/$storedName";
            $size = (int) $_FILES['file']['size'];
            $docType = ($_POST['doc_type'] ?? 'receipt') === 'invoice' ? 'invoice' : 'receipt';
            $stmt = $mysqli->prepare("INSERT INTO invoxa_expense_receipts (expense_id, filename, stored_path, file_size, doc_type) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("issis", $expenseId, $origName, $storedPath, $size, $docType);
            $stmt->execute();
            echo json_encode(['success' => true]);
            exit;
        }
        if ($_POST['action'] === 'delete_expense_receipt') {
            $id = (int) ($_POST['id'] ?? 0);
            $row = $mysqli->query("SELECT stored_path FROM invoxa_expense_receipts WHERE id = $id")->fetch_assoc();
            if ($row) {
                @unlink(RECEIPTS_DIR . $row['stored_path']);
                $stmt = $mysqli->prepare("DELETE FROM invoxa_expense_receipts WHERE id = ?");
                $stmt->bind_param("i", $id);
                $stmt->execute();
            }
            echo json_encode(['success' => true]);
            exit;
        }
        if ($_POST['action'] === 'move_expense_receipt') {
            // Re-tags an attachment between the modal's Invoice and Receipt slots
            // without touching the file on disk — for when the wrong one was picked
            // at upload time.
            $id = (int) ($_POST['id'] ?? 0);
            $docType = ($_POST['doc_type'] ?? '') === 'invoice' ? 'invoice' : 'receipt';
            $stmt = $mysqli->prepare("UPDATE invoxa_expense_receipts SET doc_type = ? WHERE id = ?");
            $stmt->bind_param("si", $docType, $id);
            $stmt->execute();
            echo json_encode(['success' => true]);
            exit;
        }
        if ($_POST['action'] === 'save_recurring_expense') {
            $id = (int) ($_POST['id'] ?? 0);
            $vendor = trim($_POST['vendor'] ?? '');
            $category = array_key_exists($_POST['category'] ?? '', expenseCategories()) ? $_POST['category'] : 'other';
            $amount = (float) ($_POST['amount'] ?? 0);
            $description = trim($_POST['description'] ?? '');
            $frequency = in_array($_POST['frequency'] ?? '', ['weekly', 'monthly', 'quarterly', 'annually'], true) ? $_POST['frequency'] : 'monthly';
            if ($id > 0) {
                $stmt = $mysqli->prepare("UPDATE invoxa_recurring_expenses SET vendor=?, category=?, amount=?, description=?, frequency=? WHERE id=?");
                $stmt->bind_param("sssdsi", $vendor, $category, $amount, $description, $frequency, $id);
            } else {
                $stmt = $mysqli->prepare("INSERT INTO invoxa_recurring_expenses (vendor, category, amount, description, frequency) VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param("sssds", $vendor, $category, $amount, $description, $frequency);
            }
            $stmt->execute();
            echo json_encode(['success' => true]);
            exit;
        }
        if ($_POST['action'] === 'toggle_recurring_expense') {
            $id = (int) ($_POST['id'] ?? 0);
            $active = ($_POST['is_active'] ?? '1') === '1' ? 1 : 0;
            $stmt = $mysqli->prepare("UPDATE invoxa_recurring_expenses SET is_active = ? WHERE id = ?");
            $stmt->bind_param("ii", $active, $id);
            $stmt->execute();
            echo json_encode(['success' => true]);
            exit;
        }
        if ($_POST['action'] === 'delete_recurring_expense') {
            $id = (int) ($_POST['id'] ?? 0);
            $stmt = $mysqli->prepare("DELETE FROM invoxa_recurring_expenses WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            echo json_encode(['success' => true]);
            exit;
        }
        if ($_POST['action'] === 'import_clients_csv') { invoxaHandleImportClientsCsv($mysqli); }
        if ($_POST['action'] === 'preview_adhoc') {
            $clientId = (int) $_POST['client_id'];
            $client = $mysqli->query("SELECT * FROM invoxa_clients WHERE id=$clientId")->fetch_assoc();
            if (!$client)
                throw new Exception("Client not found");
            $lineItems = json_decode($_POST['line_items'] ?? '[]', true);
            if (empty($lineItems))
                throw new Exception('No line items provided');
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
            echo json_encode(['success' => true, 'html' => $html, 'invoice_number' => $invNum]);
            exit;
        }
        if ($_POST['action'] === 'preview_adhoc_pdf') { invoxaHandlePreviewAdhocPdf($mysqli, $settings, $licenseValid); }
        if ($_POST['action'] === 'generate_adhoc') {
            $clientId = (int) $_POST['client_id'];
            $client = $mysqli->query("SELECT * FROM invoxa_clients WHERE id=$clientId")->fetch_assoc();
            if (!$client)
                throw new Exception("Client not found");
            $lineItems = json_decode($_POST['line_items'] ?? '[]', true);
            if (empty($lineItems))
                throw new Exception('No line items provided');
            $discountPct = (float) ($_POST['discount_pct'] ?? 0);
            $taxRate = (float) ($_POST['tax_rate'] ?? 0);
            $totals = computeInvoiceTotals($lineItems, $discountPct, $taxRate);
            $dueDateOverride = validDateOverride($_POST['due_date'] ?? null);
            $res = processInvoice($mysqli, $client, $totals['total'], '', $emailPassword, $lineItems, $dueDateOverride, $_POST['memo'] ?? null, $totals['discount_pct'], $totals['tax_rate']);
            echo json_encode($res);
            exit;
        }
        if ($_POST['action'] === 'save_quote') {
            $clientId = (int) $_POST['client_id'];
            $client = $mysqli->query("SELECT * FROM invoxa_clients WHERE id=$clientId")->fetch_assoc();
            if (!$client) {
                echo json_encode(['success' => false, 'error' => 'Client not found']);
                exit;
            }
            $lineItems = json_decode($_POST['line_items'] ?? '[]', true);
            if (empty($lineItems)) {
                echo json_encode(['success' => false, 'error' => 'No line items provided']);
                exit;
            }
            $discountPct = (float) ($_POST['discount_pct'] ?? 0);
            $taxRate = (float) ($_POST['tax_rate'] ?? 0);
            $totals = computeInvoiceTotals($lineItems, $discountPct, $taxRate);
            $amount = $totals['total'];
            $date = date('Y-m-d');
            $termsDays = (int) ($client['payment_terms_days'] ?? 21);
            $dueDate = validDateOverride($_POST['due_date'] ?? null) ?: date('Y-m-d', strtotime("+{$termsDays} days"));
            $quoteExpiresAt = validDateOverride($_POST['quote_expires_at'] ?? null);
            // Generate quote number: QUO-{CLIENT_KEY}-{seq}
            $prefix = 'Q' . strtoupper($client['client_key']);
            $qNum = $mysqli->query("SELECT COUNT(*) as c FROM invoxa_invoices WHERE invoice_number LIKE '$prefix%' AND is_quote = 1")->fetch_assoc()['c'] ?? 0;
            $quoteNum = $prefix . str_pad($qNum + 1, 3, '0', STR_PAD_LEFT);
            global $settings;
            $brandColor = $settings['brand_color'] ?? '#4a90e2';
            $footerText = $settings['footer_text'] ?? '';
            $currencyCode = invoxaResolveCurrency($client['currency'] ?? '', $settings);
            $htmlContent = generateInvoiceHTML(
                $client['client_name'],
                $date,
                $dueDate,
                $quoteNum,
                number_format($amount, 2),
                $client['account_name'] ?: ($settings['default_account_name'] ?? ''),
                $client['account_number'] ?: ($settings['default_account_number'] ?? ''),
                getenv('SMTP_FROM_EMAIL') ?: '',
                $lineItems,
                $brandColor,
                $footerText,
                $currencyCode,
                invoiceWatermarkFingerprint($settings),
                $totals['discount_pct'],
                $totals['tax_rate'],
                $settings['invoice_template'] ?? 'detailed',
                null,
                !($licenseValid && ($settings['hide_powered_by'] ?? '0') === '1'),
                vatNumber: $settings['vat_number'] ?? '',
                recipientPhone: $client['phone'] ?? '',
                recipientAddress: $client['address'] ?? '',
                customTemplate: ($settings['invoice_template'] ?? 'detailed') === 'custom' ? ($settings['custom_invoice_template'] ?? '') : null,
                businessName: $settings['business_name'] ?? '',
                documentType: 'Quote',
                quoteExpiresAt: $quoteExpiresAt
            );
            $folderName = strtolower(str_replace(' ', '_', $client['client_name']));
            $invoiceDir = INVOICES_DIR . $folderName;
            if (!is_dir($invoiceDir))
                @mkdir($invoiceDir, 0777, true);
            $htmlFile = "$invoiceDir/$quoteNum.html";
            @file_put_contents($htmlFile, $htmlContent);
            $relPath = "invoices/$folderName/$quoteNum.html";
            $stmt = $mysqli->prepare("INSERT INTO invoxa_invoices (invoice_number, client_key, client_name, recipient_email, invoice_date, due_date, amount, currency, status, html_content, file_path, is_quote, quote_expires_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?, ?, 1, ?)");
            $stmt->bind_param("ssssssdssss", $quoteNum, $client['client_key'], $client['client_name'], $client['email'], $date, $dueDate, $amount, $currencyCode, $htmlContent, $relPath, $quoteExpiresAt);
            $stmt->execute();
            $memo = trim($_POST['memo'] ?? '');
            if ($memo !== '') {
                $qid = $stmt->insert_id;
                invoxaLogAction($mysqli, $qid, $quoteNum, 'note_added', $memo);
            }
            echo json_encode(['success' => true, 'quoteNum' => $quoteNum]);
            exit;
        }
        if ($_POST['action'] === 'run_recurring') {
            $clients = $mysqli->query("SELECT * FROM invoxa_clients WHERE is_active=1 AND monthly_rate > 0");
            $sent = 0;
            $errors = 0;
            $skipped = 0;
            // One prepared statement per billing_frequency, each checking whether
            // this client was already billed in the current calendar period (not a
            // rolling N-day window).
            $alreadyBilledStmts = [
                'weekly' => $mysqli->prepare("SELECT COUNT(*) as c FROM invoxa_invoices WHERE client_key = ? AND is_quote = 0 AND YEARWEEK(invoice_date, 3) = YEARWEEK(CURDATE(), 3)"),
                'monthly' => $mysqli->prepare("SELECT COUNT(*) as c FROM invoxa_invoices WHERE client_key = ? AND is_quote = 0 AND MONTH(invoice_date) = MONTH(CURDATE()) AND YEAR(invoice_date) = YEAR(CURDATE())"),
                'quarterly' => $mysqli->prepare("SELECT COUNT(*) as c FROM invoxa_invoices WHERE client_key = ? AND is_quote = 0 AND QUARTER(invoice_date) = QUARTER(CURDATE()) AND YEAR(invoice_date) = YEAR(CURDATE())"),
                'annually' => $mysqli->prepare("SELECT COUNT(*) as c FROM invoxa_invoices WHERE client_key = ? AND is_quote = 0 AND YEAR(invoice_date) = YEAR(CURDATE())"),
            ];
            // Off by default. When on, skips the double-billing guard below entirely
            // (a client already billed this period gets billed again) — only useful
            // for testing recurring billing without deleting an existing test invoice.
            $bypassGuard = ($settings['recurring_bypass_guard'] ?? '0') === '1';
            while ($c = $clients->fetch_assoc()) {
                // Guards against double-billing a client if this action fires more than
                // once in the same billing period (e.g. a misconfigured cron schedule).
                if (!$bypassGuard) {
                    $alreadyBilledStmt = $alreadyBilledStmts[$c['billing_frequency'] ?? 'monthly'] ?? $alreadyBilledStmts['monthly'];
                    $alreadyBilledStmt->bind_param("s", $c['client_key']);
                    $alreadyBilledStmt->execute();
                    $alreadyBilled = (int) $alreadyBilledStmt->get_result()->fetch_assoc()['c'];
                    if ($alreadyBilled > 0) {
                        $skipped++;
                        continue;
                    }
                }
                // Recurring discount/tax live on the client (Settings > Billing has no
                // per-run override) — computeInvoiceTotals is the same
                // helper the adhoc/quote builders use, so a recurring invoice's
                // Subtotal/Discount/Tax/Total breakdown matches theirs exactly. Clients
                // saved before these columns existed have discount_pct/tax_rate = 0.00
                // (see the ALTER TABLE migration above), so this is a no-op for them.
                $recurLineItems = [['code' => 'WEB01', 'desc' => 'Website management', 'amount' => number_format((float) $c['monthly_rate'], 2)]];
                $recurTotals = computeInvoiceTotals($recurLineItems, (float) ($c['discount_pct'] ?? 0), (float) ($c['tax_rate'] ?? 0));
                $res = processInvoice($mysqli, $c, $recurTotals['total'], '', $emailPassword, $recurLineItems, null, null, $recurTotals['discount_pct'], $recurTotals['tax_rate']);
                if ($res['success'])
                    $sent++;
                else
                    $errors++;
            }
            $recurExpSent = 0;
            $recurExpErrors = 0;
            $recurExpSkipped = 0;
            // Same guard-against-double-logging idea as the invoice loop above, keyed
            // on recurring_expense_id rather than client_key.
            $recurExpAlreadyStmts = [
                'weekly' => $mysqli->prepare("SELECT COUNT(*) as c FROM invoxa_expenses WHERE recurring_expense_id = ? AND YEARWEEK(expense_date, 3) = YEARWEEK(CURDATE(), 3)"),
                'monthly' => $mysqli->prepare("SELECT COUNT(*) as c FROM invoxa_expenses WHERE recurring_expense_id = ? AND MONTH(expense_date) = MONTH(CURDATE()) AND YEAR(expense_date) = YEAR(CURDATE())"),
                'quarterly' => $mysqli->prepare("SELECT COUNT(*) as c FROM invoxa_expenses WHERE recurring_expense_id = ? AND QUARTER(expense_date) = QUARTER(CURDATE()) AND YEAR(expense_date) = YEAR(CURDATE())"),
                'annually' => $mysqli->prepare("SELECT COUNT(*) as c FROM invoxa_expenses WHERE recurring_expense_id = ? AND YEAR(expense_date) = YEAR(CURDATE())"),
            ];
            $recurExpInsertStmt = $mysqli->prepare("INSERT INTO invoxa_expenses (expense_date, vendor, category, amount, description, recurring_expense_id) VALUES (CURDATE(), ?, ?, ?, ?, ?)");
            $recurExpenses = $mysqli->query("SELECT * FROM invoxa_recurring_expenses WHERE is_active = 1");
            while ($re = $recurExpenses->fetch_assoc()) {
                if (!$bypassGuard) {
                    $alreadyStmt = $recurExpAlreadyStmts[$re['frequency'] ?? 'monthly'] ?? $recurExpAlreadyStmts['monthly'];
                    $alreadyStmt->bind_param("i", $re['id']);
                    $alreadyStmt->execute();
                    $already = (int) $alreadyStmt->get_result()->fetch_assoc()['c'];
                    if ($already > 0) {
                        $recurExpSkipped++;
                        continue;
                    }
                }
                $reAmount = (float) $re['amount'];
                $recurExpInsertStmt->bind_param("ssdsi", $re['vendor'], $re['category'], $reAmount, $re['description'], $re['id']);
                if ($recurExpInsertStmt->execute())
                    $recurExpSent++;
                else
                    $recurExpErrors++;
            }
            $remindersSent = 0;
            $reminderErrors = 0;
            // Reminders ride this same cron trigger rather than needing their own
            // crontab entry — see sendOverdueReminders()'s docblock.
            if (($settings['reminders_enabled'] ?? '0') === '1') {
                $reminderResult = sendOverdueReminders($mysqli, $settings, $emailPassword);
                $remindersSent = $reminderResult['sent'];
                $reminderErrors = $reminderResult['errors'];
            }
            $lateFeesCharged = 0;
            $lateFeeErrors = 0;
            // Off by default (see applyLateFees()) — installs that never touch this
            // setting see no change here.
            if (($settings['late_fee_enabled'] ?? '0') === '1') {
                $lateFeeResult = applyLateFees($mysqli, $settings, $emailPassword);
                $lateFeesCharged = $lateFeeResult['charged'];
                $lateFeeErrors = $lateFeeResult['errors'];
            }
            // Off by default (see pruneAuditActions()) — checked last so it doesn't
            // remove the actions this run just logged.
            $auditPruned = pruneAuditActions($mysqli, $settings);
            // Logs the run itself (cron-triggered or manual "Run Monthly Billing",
            // both hit this same action) — not just the per-invoice actions already
            // logged by processInvoice()/sendOverdueReminders()/applyLateFees().
            // Otherwise a run that skips every client leaves no trace that cron ran.
            $runNotes = "Sent {$sent}, skipped {$skipped}, errors {$errors}"
                . ($bypassGuard ? ' (double-billing guard bypassed)' : '')
                . ". Reminders sent {$remindersSent}, errors {$reminderErrors}."
                . " Late fees charged {$lateFeesCharged}, errors {$lateFeeErrors}."
                . " Recurring expenses logged {$recurExpSent}, skipped {$recurExpSkipped}, errors {$recurExpErrors}.";
            invoxaLogAction($mysqli, null, '', 'recurring_run', $runNotes);
            $totalRunErrors = $errors + $reminderErrors + $lateFeeErrors + $recurExpErrors;
            if ($totalRunErrors > 0) {
                notifyChannel($mysqli, $settings, 'notify_on_recurring_errors', "\xE2\x9A\xA0\xEF\xB8\x8F Recurring billing run had {$totalRunErrors} error" . ($totalRunErrors === 1 ? '' : 's') . " — {$runNotes}");
            }
            echo json_encode(['success' => true, 'sent' => $sent, 'errors' => $errors, 'skipped' => $skipped, 'reminders_sent' => $remindersSent, 'reminder_errors' => $reminderErrors, 'late_fees_charged' => $lateFeesCharged, 'late_fee_errors' => $lateFeeErrors, 'audit_log_pruned' => $auditPruned, 'recurring_expenses_logged' => $recurExpSent, 'recurring_expenses_skipped' => $recurExpSkipped, 'recurring_expenses_errors' => $recurExpErrors]);
            exit;
        }
        if ($_POST['action'] === 'mark_paid') { invoxaHandleMarkPaid($mysqli, $settings); }
        if ($_POST['action'] === 'get_invoice_payments') { invoxaHandleGetInvoicePayments($mysqli); }
        if ($_POST['action'] === 'mark_unpaid') { invoxaHandleMarkUnpaid($mysqli); }
        if ($_POST['action'] === 'void_invoice') {
            // Voiding (not deleting) keeps the record and audit trail intact while
            // excluding it from outstanding/overdue/revenue totals — see the
            // "status != 'void'" filters throughout the stats and export queries.
            $id = (int) ($_POST['id'] ?? 0);
            $reason = trim($_POST['reason'] ?? '');
            $invRow = $mysqli->query("SELECT invoice_number, status FROM invoxa_invoices WHERE id = $id")->fetch_assoc();
            if (!$invRow) {
                echo json_encode(['success' => false, 'error' => 'Invoice not found']);
                exit;
            }
            if ($invRow['status'] === 'paid') {
                echo json_encode(['success' => false, 'error' => 'A paid invoice can\'t be voided — mark it unpaid first if it was paid by mistake.']);
                exit;
            }
            $stmt = $mysqli->prepare("UPDATE invoxa_invoices SET status = 'void' WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $notes = 'Voided' . ($reason !== '' ? ": $reason" : '');
            invoxaLogAction($mysqli, $id, $invRow['invoice_number'], 'invoice_voided', $notes);
            notifyChannel($mysqli, $settings, 'notify_on_invoice_voided', "\xF0\x9F\x9A\xAB Invoice voided — {$invRow['invoice_number']}" . ($reason !== '' ? ": {$reason}" : ''));
            echo json_encode(['success' => true]);
            exit;
        }
        if ($_POST['action'] === 'unvoid_invoice') {
            $id = (int) ($_POST['id'] ?? 0);
            $invNum = $mysqli->query("SELECT invoice_number FROM invoxa_invoices WHERE id = $id")->fetch_assoc()['invoice_number'] ?? '';
            $stmt = $mysqli->prepare("UPDATE invoxa_invoices SET status = 'sent' WHERE id = ? AND status = 'void'");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            invoxaLogAction($mysqli, $id, $invNum, 'invoice_unvoided', 'Restored from void');
            echo json_encode(['success' => true]);
            exit;
        }
        if ($_POST['action'] === 'resend_invoice_email') {
            // Resends the original invoice email as-is — same stored HTML body,
            // logo, and PDF attachment; not a new invoice number, not a reminder.
            $id = (int) ($_POST['id'] ?? 0);
            $inv = $mysqli->query("SELECT * FROM invoxa_invoices WHERE id = $id")->fetch_assoc();
            if (!$inv || empty($inv['html_content'])) {
                echo json_encode(['success' => false, 'error' => 'Invoice not found or has no stored content to resend.']);
                exit;
            }
            require_once PHPMAILER_DIR . 'PHPMailer.php';
            require_once PHPMAILER_DIR . 'SMTP.php';
            require_once PHPMAILER_DIR . 'Exception.php';
            $fromName = $settings['business_name'] ?? (getenv('SMTP_FROM_NAME') ?: 'Invoxa');
            $fromEmail = getenv('SMTP_FROM_EMAIL') ?: '';
            $currencyCode = invoxaResolveCurrency($inv['currency'] ?? '', $settings);
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            $emailSent = false;
            $errorMsg = '';
            try {
                $mail->isSMTP();
                $mail->Host = getenv('SMTP_HOST') ?: '';
                $mail->Port = (int) (getenv('SMTP_PORT') ?: 587);
                $mail->SMTPAuth = true;
                $mail->Username = getenv('SMTP_USER') ?: '';
                $mail->Password = $emailPassword;
                $mail->SMTPSecure = match (strtolower(getenv('SMTP_ENCRYPTION') ?: 'tls')) {
                    'ssl' => PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS,
                    'none', '' => false,
                    default => PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS,
                };
                $mail->CharSet = 'UTF-8';
                $mail->setFrom($fromEmail, $fromName);
                $mail->addAddress($inv['recipient_email'], $inv['client_name']);
                $mail->Subject = renderEmailTemplate($settings['invoice_email_subject'] ?? DEFAULT_INVOICE_SUBJECT, [
                    'business_name' => $fromName,
                    'client_name' => $inv['client_name'],
                    'invoice_number' => $inv['invoice_number'],
                    'amount' => $currencyCode . ' ' . number_format((float) $inv['amount'], 2),
                    'due_date' => $inv['due_date'],
                ]);
                $mail->isHTML(true);
                $mail->Body = $inv['html_content'];
                $logoPath = INVOICES_DIR . LOGO_FILENAME;
                if (file_exists($logoPath)) {
                    $mail->addEmbeddedImage($logoPath, 'logo_cid');
                }
                $mail->addStringAttachment($inv['html_content'], "Invoice-{$inv['invoice_number']}.html", 'base64', 'text/html');
                $mail->send();
                $emailSent = true;
            } catch (Exception $e) {
                $errorMsg = $e->getMessage();
            }
            $actionType = $emailSent ? 'email_sent' : 'email_failed';
            $notes = $emailSent ? "Invoice resent to {$inv['recipient_email']}" : "Resend failed: " . $errorMsg;
            invoxaLogAction($mysqli, $id, $inv['invoice_number'], $actionType, $notes);
            // A successful resend clears a previously-failed status — it's been
            // sent now, same as if it had succeeded the first time.
            if ($emailSent && $inv['status'] === 'failed') {
                $mysqli->query("UPDATE invoxa_invoices SET status = 'sent' WHERE id = $id");
            }
            echo json_encode(['success' => $emailSent, 'error' => $errorMsg]);
            exit;
        }
        if ($_POST['action'] === 'fix_paid_dates') {
            // Set paid_at to the last day of the invoice's own month for all paid invoices
            $res = $mysqli->query("SELECT id, invoice_date FROM invoxa_invoices WHERE status = 'paid' AND paid_at IS NOT NULL AND is_quote = 0");
            $fixed = 0;
            $stmt = $mysqli->prepare("UPDATE invoxa_invoices SET paid_at = LAST_DAY(invoice_date) WHERE id = ?");
            while ($row = $res->fetch_assoc()) {
                $stmt->bind_param("i", $row['id']);
                $stmt->execute();
                $fixed++;
            }
            echo json_encode(['success' => true, 'fixed' => $fixed]);
            exit;
        }
        if ($_POST['action'] === 'add_note') {
            $id = (int) $_POST['id'];
            $note = $_POST['note'];
            $invNum = $mysqli->query("SELECT invoice_number FROM invoxa_invoices WHERE id = $id")->fetch_assoc()['invoice_number'] ?? '';
            invoxaLogAction($mysqli, $id, $invNum, 'note_added', $note);
            echo json_encode(['success' => true]);
            exit;
        }
        if ($_POST['action'] === 'get_notes') {
            $invNum = $mysqli->real_escape_string($_POST['invoice_number'] ?? '');
            $res = $mysqli->query("SELECT id, notes, performed_at FROM invoxa_actions WHERE invoice_number = '$invNum' AND action_type = 'note_added' ORDER BY performed_at ASC");
            $notes = [];
            while ($r = $res->fetch_assoc())
                $notes[] = $r;
            echo json_encode(['success' => true, 'notes' => $notes]);
            exit;
        }
        if ($_POST['action'] === 'delete_note') {
            $noteId = (int) ($_POST['note_id'] ?? 0);
            $mysqli->query("DELETE FROM invoxa_actions WHERE id = $noteId AND action_type = 'note_added'");
            echo json_encode(['success' => true]);
            exit;
        }
        if ($_POST['action'] === 'delete_invoice') {
            $id = (int) $_POST['id'];
            $inv = $mysqli->query("SELECT * FROM invoxa_invoices WHERE id = $id")->fetch_assoc();
            if ($inv) {
                if ($inv['file_path']) {
                    $fullPath = "/usr/share/nginx/html/invoxa-invoices/" . preg_replace('#^invoices/#', '', $inv['file_path']);
                    if (file_exists($fullPath))
                        @unlink($fullPath);
                }
                // Attachments live on disk under ATTACHMENTS_DIR/<invoice_id>/ (see
                // upload_invoice_attachment below) — remove them so deleting the
                // invoice doesn't leave the folder orphaned.
                $attRes = $mysqli->query("SELECT stored_path FROM invoxa_invoice_attachments WHERE invoice_id = $id");
                while ($attRow = $attRes->fetch_assoc())
                    @unlink(INVOICES_DIR . $attRow['stored_path']);
                @rmdir(ATTACHMENTS_DIR . $id);
                $mysqli->query("DELETE FROM invoxa_invoice_attachments WHERE invoice_id = $id");
                $mysqli->query("DELETE FROM invoxa_payments WHERE invoice_id = $id");
                $mysqli->query("DELETE FROM invoxa_actions WHERE invoice_id = $id");
                $mysqli->query("DELETE FROM invoxa_invoices WHERE id = $id");
            }
            echo json_encode(['success' => true]);
            exit;
        }
        if ($_POST['action'] === 'get_invoice_attachments') {
            $invoiceId = (int) ($_POST['invoice_id'] ?? 0);
            $res = $mysqli->query("SELECT id, filename, stored_path, file_size, uploaded_at FROM invoxa_invoice_attachments WHERE invoice_id = $invoiceId ORDER BY uploaded_at DESC");
            $attachments = [];
            while ($r = $res->fetch_assoc()) {
                $r['url'] = ATTACHMENTS_URL . $invoiceId . '/' . rawurlencode(basename($r['stored_path']));
                $attachments[] = $r;
            }
            echo json_encode(['success' => true, 'attachments' => $attachments]);
            exit;
        }
        if ($_POST['action'] === 'upload_invoice_attachment') {
            $invoiceId = (int) ($_POST['invoice_id'] ?? 0);
            $invExists = $mysqli->query("SELECT id FROM invoxa_invoices WHERE id = $invoiceId")->num_rows > 0;
            if (!$invExists) {
                echo json_encode(['success' => false, 'error' => 'Invoice not found']);
                exit;
            }
            if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                echo json_encode(['success' => false, 'error' => 'No file uploaded, or the upload failed.']);
                exit;
            }
            // No extension whitelist here (unlike receipt/logo uploads) — contracts
            // legitimately come as .docx, .pdf, .zip, etc. Served only as a download
            // link the logged-in admin clicks, never rendered inline or executed.
            $invoiceDir = ATTACHMENTS_DIR . $invoiceId;
            if (!is_dir($invoiceDir))
                @mkdir($invoiceDir, 0777, true);
            $origName = basename($_FILES['file']['name']);
            $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', $origName);
            $storedName = uniqid('att_') . '_' . $safeName;
            if (!move_uploaded_file($_FILES['file']['tmp_name'], "$invoiceDir/$storedName")) {
                echo json_encode(['success' => false, 'error' => 'Failed to save the uploaded file.']);
                exit;
            }
            $storedPath = "attachments/$invoiceId/$storedName";
            $size = (int) $_FILES['file']['size'];
            $stmt = $mysqli->prepare("INSERT INTO invoxa_invoice_attachments (invoice_id, filename, stored_path, file_size) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("issi", $invoiceId, $origName, $storedPath, $size);
            $stmt->execute();
            echo json_encode(['success' => true]);
            exit;
        }
        if ($_POST['action'] === 'delete_invoice_attachment') {
            $id = (int) ($_POST['id'] ?? 0);
            $row = $mysqli->query("SELECT stored_path FROM invoxa_invoice_attachments WHERE id = $id")->fetch_assoc();
            if ($row) {
                @unlink(INVOICES_DIR . $row['stored_path']);
                $stmt = $mysqli->prepare("DELETE FROM invoxa_invoice_attachments WHERE id = ?");
                $stmt->bind_param("i", $id);
                $stmt->execute();
            }
            echo json_encode(['success' => true]);
            exit;
        }
        if ($_POST['action'] === 'test_email') { invoxaHandleTestEmail($mysqli, $settings, $emailPassword); }
        if ($_POST['action'] === 'save_notification_settings') { invoxaHandleSaveNotificationSettings($mysqli); }
        if ($_POST['action'] === 'test_notification') { invoxaHandleTestNotification($mysqli, $settings); }
        if ($_POST['action'] === 'save_payment_settings') { invoxaHandleSavePaymentSettings($mysqli); }
        if ($_POST['action'] === 'test_stripe_connection') { invoxaHandleTestStripeConnection(); }
        if ($_POST['action'] === 'test_paypal_connection') { invoxaHandleTestPaypalConnection(); }
        if ($_POST['action'] === 'create_api_token') { invoxaHandleCreateApiToken($mysqli, $settings); }
        if ($_POST['action'] === 'renew_api_token') { invoxaHandleRenewApiToken($mysqli); }
        if ($_POST['action'] === 'revoke_api_token') { invoxaHandleRevokeApiToken($mysqli, $settings); }
        if ($_POST['action'] === 'delete_api_token') { invoxaHandleDeleteApiToken($mysqli); }
        if ($_POST['action'] === 'update_profile') { invoxaHandleUpdateProfile($mysqli, $currentUserId); }
        if ($_POST['action'] === 'totp_setup_init') { invoxaHandleTotpSetupInit($mysqli, $currentUserId); }
        if ($_POST['action'] === 'totp_setup_confirm') { invoxaHandleTotpSetupConfirm($mysqli, $currentUserId, $settings); }
        if ($_POST['action'] === 'totp_regenerate_backup_codes') { invoxaHandleTotpRegenerateBackupCodes($mysqli, $currentUserId, $settings); }
        if ($_POST['action'] === 'totp_disable') { invoxaHandleTotpDisable($mysqli, $currentUserId, $settings); }
        if ($_POST['action'] === 'create_user') { invoxaHandleCreateUser($mysqli, $settings); }
        if ($_POST['action'] === 'update_user') { invoxaHandleUpdateUser($mysqli, $settings); }
        if ($_POST['action'] === 'delete_user') { invoxaHandleDeleteUser($mysqli, $currentUserId, $settings); }
        if ($_POST['action'] === 'toggle_test_clients') { invoxaHandleToggleTestClients($mysqli); }
        if ($_POST['action'] === 'toggle_show_test_only') { invoxaHandleToggleShowTestOnly($mysqli); }
        if ($_POST['action'] === 'get_default_invoice_template') { invoxaHandleGetDefaultInvoiceTemplate(); }
        if ($_POST['action'] === 'preview_invoice_template') { invoxaHandlePreviewInvoiceTemplate($settings, $licenseValid); }
        if ($_POST['action'] === 'save_invoice_template') { invoxaHandleSaveInvoiceTemplate($mysqli); }
        if ($_POST['action'] === 'save_business_identity') { invoxaHandleSaveBusinessIdentity($mysqli, $licenseValid); }
        if ($_POST['action'] === 'save_invoice_defaults') { invoxaHandleSaveInvoiceDefaults($mysqli); }
        if ($_POST['action'] === 'save_payment_details') { invoxaHandleSavePaymentDetails($mysqli); }
        if ($_POST['action'] === 'save_email_templates') { invoxaHandleSaveEmailTemplates($mysqli); }
        if ($_POST['action'] === 'save_invoice_numbering') { invoxaHandleSaveInvoiceNumbering($mysqli); }
        if ($_POST['action'] === 'save_offsite_backup') { invoxaHandleSaveOffsiteBackup($mysqli); }
        if ($_POST['action'] === 'backup_db') { invoxaHandleBackupDb($mysqli, $settings); }
        if ($_POST['action'] === 'get_db_stats') { invoxaHandleGetDbStats($mysqli); }
        if ($_POST['action'] === 'list_backups') { invoxaHandleListBackups(); }
        if ($_POST['action'] === 'import_backup') { invoxaHandleImportBackup(); }
        if ($_POST['action'] === 'factory_reset') { invoxaHandleFactoryReset($mysqli, $currentUserId); }
        if ($_POST['action'] === 'resend_verification_email') { invoxaHandleResendVerificationEmail($mysqli, $currentUserId); }
        if ($_POST['action'] === 'seed_demo_data') { invoxaHandleSeedDemoData($mysqli, $settings); }
        if ($_POST['action'] === 'clear_demo_data') { invoxaHandleClearDemoData($mysqli); }
        if ($_POST['action'] === 'run_test_suite') { invoxaHandleRunTestSuite($mysqli, $settings); }
        if ($_POST['action'] === 'preview_restore') { invoxaHandlePreviewRestore(); }
        if ($_POST['action'] === 'restore_db_backup') { invoxaHandleRestoreDbBackup($mysqli); }
        if ($_POST['action'] === 'get_crm_data') { invoxaHandleGetCrmData($mysqli); }
        if ($_POST['action'] === 'save_crm_notes') { invoxaHandleSaveCrmNotes($mysqli); }
        if ($_POST['action'] === 'convert_quote') {
            $id = (int) ($_POST['id'] ?? 0);
            $result = convertQuoteToInvoice($mysqli, $settings, $id, 'admin');
            echo json_encode($result);
            exit;
        }
        if ($_POST['action'] === 'update_cron') {
            $newCron = trim($_POST['cron']);
            if (count(explode(' ', preg_replace('/\s+/', ' ', $newCron))) !== 5) {
                echo json_encode(['success' => false, 'error' => 'Invalid format. Must be 5 parts (e.g. "15 7 3 * *")']);
                exit;
            }
            $cronFile = CRONTAB_PATH;
            if (!file_exists($cronFile) || !is_writable($cronFile)) {
                echo json_encode(['success' => false, 'error' => 'Crontab file not writable. Check the crontab-data volume mount.']);
                exit;
            }
            // cron_key is filled in server-side from CRON_SECRET only — never accepted from the browser.
            $cronLine = $newCron . ' curl -s -S -X POST -d "action=run_recurring&cron_key=' . CRON_SECRET . '" http://nginx/invoxa.php >> /var/log/invoxa-cron.log 2>&1';
            $lines = file($cronFile, FILE_IGNORE_NEW_LINES);
            $found = false;
            foreach ($lines as &$line) {
                if (strpos($line, 'run_recurring') !== false) {
                    // Preserve the line's existing enabled/disabled ('#' prefix) state —
                    // editing the schedule shouldn't silently re-enable a paused one.
                    $wasDisabled = (bool) preg_match('/^\s*#/', $line);
                    $line = $wasDisabled ? '# ' . $cronLine : $cronLine;
                    $found = true;
                }
            }
            unset($line);
            if (!$found)
                $lines[] = $cronLine;
            file_put_contents($cronFile, implode("\n", $lines) . "\n");
            // busybox crond only reloads root's crontab when /etc/crontabs itself
            // changes mtime, not when this file's content does (see
            // cron/entrypoint.sh) — nudge it so the change takes effect on the
            // next ~60s poll instead of waiting for the hourly rescan.
            @touch(dirname($cronFile));
            echo json_encode(['success' => true]);
            exit;
        }
        if ($_POST['action'] === 'toggle_cron') {
            $enable = ($_POST['enabled'] ?? '1') === '1';
            $cronFile = CRONTAB_PATH;
            if (!file_exists($cronFile) || !is_writable($cronFile)) {
                echo json_encode(['success' => false, 'error' => 'Crontab file not writable. Check the crontab-data volume mount.']);
                exit;
            }
            $lines = file($cronFile, FILE_IGNORE_NEW_LINES);
            $found = false;
            foreach ($lines as &$line) {
                if (strpos($line, 'run_recurring') !== false) {
                    $stripped = ltrim($line, "# \t");
                    $line = $enable ? $stripped : '# ' . $stripped;
                    $found = true;
                }
            }
            unset($line);
            if (!$found) {
                echo json_encode(['success' => false, 'error' => 'No recurring billing schedule set yet — enter one and click Save first.']);
                exit;
            }
            file_put_contents($cronFile, implode("\n", $lines) . "\n");
            // See the matching @touch() in update_cron above — same reason.
            @touch(dirname($cronFile));
            echo json_encode(['success' => true, 'enabled' => $enable]);
            exit;
        }
        if ($_POST['action'] === 'toggle_recurring_bypass_guard') {
            $enable = ($_POST['enabled'] ?? '1') === '1';
            $val = $enable ? '1' : '0';
            $stmt = $mysqli->prepare("INSERT INTO invoxa_settings (setting_key, setting_value) VALUES ('recurring_bypass_guard', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
            $stmt->bind_param("s", $val);
            $stmt->execute();
            echo json_encode(['success' => true, 'enabled' => $enable]);
            exit;
        }
        if ($_POST['action'] === 'save_audit_retention') { invoxaHandleSaveAuditRetention($mysqli); }
        if ($_POST['action'] === 'save_backup_retention') { invoxaHandleSaveBackupRetention($mysqli); }
        if ($_POST['action'] === 'toggle_reminders') {
            $enable = ($_POST['enabled'] ?? '1') === '1';
            $val = $enable ? '1' : '0';
            $stmt = $mysqli->prepare("INSERT INTO invoxa_settings (setting_key, setting_value) VALUES ('reminders_enabled', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
            $stmt->bind_param("s", $val);
            $stmt->execute();
            echo json_encode(['success' => true, 'enabled' => $enable]);
            exit;
        }
        if ($_POST['action'] === 'toggle_late_fees') {
            $enable = ($_POST['enabled'] ?? '1') === '1';
            $val = $enable ? '1' : '0';
            $stmt = $mysqli->prepare("INSERT INTO invoxa_settings (setting_key, setting_value) VALUES ('late_fee_enabled', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
            $stmt->bind_param("s", $val);
            $stmt->execute();
            echo json_encode(['success' => true, 'enabled' => $enable]);
            exit;
        }
        if ($_POST['action'] === 'save_late_fee_settings') {
            $feeType = ($_POST['late_fee_type'] ?? 'percent') === 'flat' ? 'flat' : 'percent';
            $feeValue = (float) ($_POST['late_fee_value'] ?? 0);
            if ($feeValue < 0)
                $feeValue = 0;
            $graceDays = (int) ($_POST['late_fee_grace_days'] ?? 7);
            if ($graceDays < 0)
                $graceDays = 0;
            $upsert = $mysqli->prepare("INSERT INTO invoxa_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
            foreach ([
                'late_fee_type' => $feeType,
                'late_fee_value' => (string) $feeValue,
                'late_fee_grace_days' => (string) $graceDays,
            ] as $key => $value) {
                $upsert->bind_param("ss", $key, $value);
                $upsert->execute();
            }
            echo json_encode(['success' => true]);
            exit;
        }
        if ($_POST['action'] === 'sync_missing') { invoxaHandleSyncMissing($mysqli, $settings); }
        if ($_POST['action'] === 'restore_missing') { invoxaHandleRestoreMissing($mysqli); }
        if ($_POST['action'] === 'delete_missing_db') { invoxaHandleDeleteMissingDb($mysqli); }
        if ($_POST['action'] === 'delete_untracked_file') { invoxaHandleDeleteUntrackedFile(); }
        if ($_POST['action'] === 'delete_single_db_entry') { invoxaHandleDeleteSingleDbEntry($mysqli); }
        if ($_POST['action'] === 'preview_tax_year') { invoxaHandlePreviewTaxYear($mysqli, $settings); }
        if ($_POST['action'] === 'preview_tax_year_monthly') { invoxaHandlePreviewTaxYearMonthly($mysqli, $settings); }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

if (isset($_GET['api'])) { invoxaHandleStatsApiRoutes($mysqli, $settings); }

if (isset($_GET['export']) && $_GET['export'] === 'invoice_pdf') { invoxaHandleInvoicePdfExport($mysqli); }

if (isset($_GET['export'])) { invoxaHandleExportRoutes($mysqli, $settings); }

// ── Data Fetching ────────────────────────────────────────────────────────────
// $settings was already loaded before the auth gate (see above), so it's
// available here too — not reloaded.
$hideTest = isset($settings['hide_test']) ? ($settings['hide_test'] === '1') : true;
$showTestOnly = ($settings['show_test_only'] ?? '0') === '1';
$testFilter = invoxaTestViewFilter($hideTest, $showTestOnly);

$cronFile = CRONTAB_PATH;
$currentCron = '15 7 3 * *';
// A disabled schedule is stored as the same line with a leading '#' (standard
// cron comment syntax, so crond skips it) rather than a separate flag that
// could drift out of sync with the actual crontab.
$cronEnabled = true;
if (file_exists($cronFile)) {
    $lines = file($cronFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, 'run_recurring') !== false) {
            $cronEnabled = !(bool) preg_match('/^\s*#/', $line);
            $parts = explode(' curl ', ltrim($line, "# \t"));
            if (count($parts) > 1) {
                $currentCron = trim($parts[0]);
            }
        }
    }
}

$remindersEnabled = ($settings['reminders_enabled'] ?? '0') === '1';
$lateFeesEnabled = ($settings['late_fee_enabled'] ?? '0') === '1';
$recurringBypassGuard = ($settings['recurring_bypass_guard'] ?? '0') === '1';

$total_invoiced_by_ccy = [];
$res = $mysqli->query("SELECT currency, SUM(amount) as s FROM invoxa_invoices WHERE status NOT IN ('failed', 'void') $testFilter GROUP BY currency");
while ($r = $res->fetch_assoc()) {
    $ccy = invoxaResolveCurrency($r['currency'], $settings);
    $total_invoiced_by_ccy[$ccy] = ($total_invoiced_by_ccy[$ccy] ?? 0) + (float) $r['s'];
}
$total_paid_by_ccy = [];
$res = $mysqli->query("SELECT currency, SUM(paid_amount) as s FROM invoxa_invoices WHERE paid_amount > 0 $testFilter GROUP BY currency");
while ($r = $res->fetch_assoc()) {
    $ccy = invoxaResolveCurrency($r['currency'], $settings);
    $total_paid_by_ccy[$ccy] = ($total_paid_by_ccy[$ccy] ?? 0) + (float) $r['s'];
}
$total_monthly_by_ccy = [];
$res = $mysqli->query("SELECT currency, SUM(amount) as s FROM invoxa_invoices WHERE status NOT IN ('failed', 'void') AND MONTH(invoice_date) = MONTH(CURRENT_DATE()) AND YEAR(invoice_date) = YEAR(CURRENT_DATE()) $testFilter GROUP BY currency");
while ($r = $res->fetch_assoc()) {
    $ccy = invoxaResolveCurrency($r['currency'], $settings);
    $total_monthly_by_ccy[$ccy] = ($total_monthly_by_ccy[$ccy] ?? 0) + (float) $r['s'];
}
$total_invoiced = array_sum($total_invoiced_by_ccy);
$total_paid = array_sum($total_paid_by_ccy);
$total_monthly = array_sum($total_monthly_by_ccy);
$unpaid_count = $mysqli->query("SELECT COUNT(*) as c FROM invoxa_invoices WHERE status IN ('sent', 'pending') $testFilter")->fetch_assoc()['c'] ?? 0;
$client_count = $mysqli->query("SELECT COUNT(*) as c FROM invoxa_clients WHERE is_active = 1 " . invoxaTestViewClientFilter($hideTest, $showTestOnly))->fetch_assoc()['c'] ?? 0;
$quote_count = $mysqli->query("SELECT COUNT(*) as c FROM invoxa_invoices WHERE is_quote = 1")->fetch_assoc()['c'] ?? 0;
$invoice_count = $mysqli->query("SELECT COUNT(*) as c FROM invoxa_invoices WHERE is_quote = 0 $testFilter")->fetch_assoc()['c'] ?? 0;

$overdueInvoices = [];
$res = $mysqli->query("SELECT * FROM invoxa_invoices WHERE status IN ('sent', 'pending') AND due_date < CURRENT_DATE() $testFilter ORDER BY due_date ASC");
while ($r = $res->fetch_assoc())
    $overdueInvoices[] = $r;

$failedInvoices = [];
$res = $mysqli->query("SELECT * FROM invoxa_invoices WHERE status = 'failed' $testFilter ORDER BY invoice_date DESC");
while ($r = $res->fetch_assoc())
    $failedInvoices[] = $r;
$invoices = [];
$res = $mysqli->query("SELECT i.*, c.is_test, (SELECT COUNT(*) FROM invoxa_actions a WHERE a.invoice_number = i.invoice_number AND a.action_type = 'note_added') as note_count FROM invoxa_invoices i LEFT JOIN invoxa_clients c ON i.client_key = c.client_key " . invoxaTestViewClientFilter($hideTest, $showTestOnly, 'WHERE', 'c.is_test') . " ORDER BY i.invoice_date DESC");
while ($r = $res->fetch_assoc())
    $invoices[] = $r;
$clients = [];
$res = $mysqli->query("SELECT c.*, COUNT(i.id) as inv_count, SUM(i.amount) as total_billed, SUM(i.paid_amount) as total_paid FROM invoxa_clients c LEFT JOIN invoxa_invoices i ON c.client_key = i.client_key AND i.status NOT IN ('failed', 'void') " . invoxaTestViewClientFilter($hideTest, $showTestOnly, 'WHERE', 'c.is_test') . " GROUP BY c.id ORDER BY c.client_name ASC");
while ($r = $res->fetch_assoc())
    $clients[] = $r;

$expenses = [];
$res = $mysqli->query("SELECT e.*, COUNT(r.id) as receipt_count FROM invoxa_expenses e LEFT JOIN invoxa_expense_receipts r ON r.expense_id = e.id GROUP BY e.id ORDER BY e.expense_date DESC, e.id DESC");
while ($r = $res->fetch_assoc())
    $expenses[] = $r;
$total_expenses = $mysqli->query("SELECT SUM(amount) as s FROM invoxa_expenses")->fetch_assoc()['s'] ?? 0;

$recurringExpenses = [];
$res = $mysqli->query("SELECT * FROM invoxa_recurring_expenses ORDER BY vendor ASC, id ASC");
while ($r = $res->fetch_assoc())
    $recurringExpenses[] = $r;

$actions = [];
$res = $mysqli->query("SELECT a.*, i.client_name FROM invoxa_actions a LEFT JOIN invoxa_invoices i ON a.invoice_number = i.invoice_number ORDER BY a.performed_at DESC LIMIT 200");
while ($r = $res->fetch_assoc())
    $actions[] = $r;

$dbFiles = [];
$dbFileData = [];
$res = $mysqli->query("SELECT id, invoice_number, file_path, (html_content IS NOT NULL AND html_content != '') as has_content FROM invoxa_invoices WHERE file_path IS NOT NULL AND is_quote = 0");
while ($r = $res->fetch_assoc()) {
    // Normalise: strip any absolute prefix so we always compare relative paths like invoices/folder/file.html
    $fp = $r['file_path'];
    $fp = preg_replace('#^/usr/share/nginx/html/invoxa-invoices/#', 'invoices/', $fp);
    $fp = preg_replace('#^invoxa-invoices/#', 'invoices/', $fp);
    $fp = ltrim($fp, '/');
    // Ensure it starts with invoices/ (not just folder/file.html)
    if (!str_starts_with($fp, 'invoices/') && substr_count($fp, '/') >= 1) {
        $fp = 'invoices/' . $fp;
    }
    $r['file_path_normalised'] = $fp;
    $dbFiles[] = $fp;
    $dbFileData[$fp] = $r;
}
$diskFiles = [];
if (is_dir('/usr/share/nginx/html/invoxa-invoices')) {
    foreach (glob("/usr/share/nginx/html/invoxa-invoices/*/*.html") as $file) {
        $diskFiles[] = "invoices/" . basename(dirname($file)) . "/" . basename($file);
    }
}
$missingFiles = array_diff($diskFiles, $dbFiles);
$missingDiskFiles = array_diff($dbFiles, $diskFiles);
$missingDiskData = [];
foreach ($missingDiskFiles as $mf) {
    $missingDiskData[] = $dbFileData[$mf];
}
// Build a lookup of known client folders for the sync UI
$knownClientFolders = [];
foreach ($clients as $c) {
    $knownClientFolders[strtolower(str_replace(' ', '_', $c['client_name']))] = $c['client_name'];
}

// Compute Stats
$stats_all_time_revenue = 0;
$stats_outstanding_revenue = 0;
$stats_mrr = 0;
$stats_avg_invoice = 0;

$stats_default_ccy = invoxaResolveCurrency('', $settings);
$stats_default_ccy_esc = $mysqli->real_escape_string($stats_default_ccy);
$ccyFilterInv = "AND (currency = '' OR currency = '$stats_default_ccy_esc')";
$ccyFilterInvI = "AND (i.currency = '' OR i.currency = '$stats_default_ccy_esc')";
$ccyFilterClient = "AND (currency = '' OR currency = '$stats_default_ccy_esc')";
$stats_has_other_currency = ($mysqli->query("SELECT 1 FROM invoxa_invoices WHERE currency != '' AND currency != '$stats_default_ccy_esc' LIMIT 1")->num_rows ?? 0) > 0;

$res_rev = $mysqli->query("SELECT SUM(amount - COALESCE(paid_amount, 0)) as outstanding FROM invoxa_invoices WHERE status NOT IN ('paid', 'void') AND is_quote = 0 $ccyFilterInv $testFilter");
$stats_outstanding_revenue = $res_rev->fetch_assoc()['outstanding'] ?? 0;

$res_overdue = $mysqli->query("SELECT COUNT(*) as cnt FROM invoxa_invoices WHERE status NOT IN ('paid', 'void') AND due_date < CURDATE() AND is_quote = 0 $testFilter");
$stats_overdue_count = $res_overdue->fetch_assoc()['cnt'] ?? 0;

$res_paid = $mysqli->query("SELECT SUM(paid_amount) as paid, AVG(paid_amount) as avg_val FROM invoxa_invoices WHERE paid_amount > 0 AND is_quote = 0 $ccyFilterInv $testFilter");
$row_paid = $res_paid->fetch_assoc();
$stats_all_time_revenue = $row_paid['paid'] ?? 0;
$stats_avg_invoice = $row_paid['avg_val'] ?? 0;

$now = new DateTime();
$taxYearStart = getTaxYearStart((int) ($settings['tax_year_start_month'] ?? 1), $now);
$startStr = $taxYearStart->format('Y-m-d');
$taxYearLabel = $taxYearStart->format('Y-m-d') . " to " . $now->format('Y-m-d');

$res_ty = $mysqli->query("
    SELECT SUM(amount) as total_invoiced,
           SUM(COALESCE(paid_amount, 0)) as total_paid,
           SUM(amount) - SUM(COALESCE(paid_amount, 0)) as outstanding
    FROM invoxa_invoices
    WHERE is_quote = 0 AND status != 'void' AND invoice_date >= '$startStr' $ccyFilterInv $testFilter
");
$row_ty = $res_ty->fetch_assoc();
$stats_ty_invoiced = $row_ty['total_invoiced'] ?? 0;
$stats_ty_paid = $row_ty['total_paid'] ?? 0;
$stats_ty_outstanding = $row_ty['outstanding'] ?? 0;


$res_mrr = $mysqli->query("SELECT SUM(monthly_rate) as mrr FROM invoxa_clients WHERE is_active = 1 $ccyFilterClient " . invoxaTestViewClientFilter($hideTest, $showTestOnly));
$stats_mrr = $res_mrr->fetch_assoc()['mrr'] ?? 0;

$stats_12m_projected = ($stats_mrr * 12) + $stats_outstanding_revenue;

// Top clients
$top_clients = [];
$res_top = $mysqli->query("
    SELECT c.client_name, SUM(i.paid_amount) as total_revenue
    FROM invoxa_invoices i
    JOIN invoxa_clients c ON i.client_key = c.client_key
    WHERE i.paid_amount > 0 AND i.is_quote = 0 $ccyFilterInvI " . invoxaTestViewClientFilter($hideTest, $showTestOnly, 'AND', 'c.is_test') . "
    GROUP BY c.client_name
    ORDER BY total_revenue DESC
    LIMIT 5
");
if ($res_top) {
    while ($r = $res_top->fetch_assoc()) {
        $top_clients[] = $r;
    }
}

// Payment Velocity (last 3 months only)
$res_vel = $mysqli->query("
    SELECT AVG(DATEDIFF(paid_at, invoice_date)) as avg_days
    FROM invoxa_invoices
    WHERE status = 'paid' AND paid_at IS NOT NULL AND is_quote = 0
      AND paid_at >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH)
      $testFilter
");
$stats_avg_days = round($res_vel->fetch_assoc()['avg_days'] ?? 0, 1);

// Client Health
$res_health = $mysqli->query("SELECT SUM(is_active=1) as active, SUM(is_active=0) as inactive FROM invoxa_clients " . invoxaTestViewClientFilter($hideTest, $showTestOnly, 'WHERE'));
$row_health = $res_health->fetch_assoc();
$stats_active_clients = $row_health['active'] ?? 0;
$stats_inactive_clients = $row_health['inactive'] ?? 0;
$stats_client_ratio = ($stats_inactive_clients > 0) ? round($stats_active_clients / $stats_inactive_clients, 1) : '∞';

// Void Summary (all-time) — invoiced amount excluded from every other total
// via the void status (see computeInvoiceTotals()/status filters above).
$res_void = $mysqli->query("SELECT COUNT(*) as c, SUM(amount) as total FROM invoxa_invoices WHERE status = 'void' AND is_quote = 0 $ccyFilterInv $testFilter");
$row_void = $res_void->fetch_assoc();
$stats_void_count = (int) ($row_void['c'] ?? 0);
$stats_void_amount = $row_void['total'] ?? 0;

// Quote Pipeline — quotes still open (not yet converted, not voided). Once a
// quote converts, is_quote flips to 0 and it drops out of this count.
$res_pipeline = $mysqli->query("SELECT COUNT(*) as c, SUM(amount) as total FROM invoxa_invoices WHERE is_quote = 1 AND status != 'void' $ccyFilterInv $testFilter");
$row_pipeline = $res_pipeline->fetch_assoc();
$stats_quote_pipeline_count = (int) ($row_pipeline['c'] ?? 0);
$stats_quote_pipeline_value = $row_pipeline['total'] ?? 0;

// AR Aging — standard "how overdue is what's outstanding" breakdown, bucketed
// by days past due date. "Current" means not yet due.
$res_aging = $mysqli->query("
    SELECT
        SUM(CASE WHEN due_date >= CURDATE() THEN 1 ELSE 0 END) as c_current,
        SUM(CASE WHEN due_date >= CURDATE() THEN amount - COALESCE(paid_amount, 0) ELSE 0 END) as a_current,
        SUM(CASE WHEN due_date < CURDATE() AND due_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as c_1_30,
        SUM(CASE WHEN due_date < CURDATE() AND due_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN amount - COALESCE(paid_amount, 0) ELSE 0 END) as a_1_30,
        SUM(CASE WHEN due_date < DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND due_date >= DATE_SUB(CURDATE(), INTERVAL 60 DAY) THEN 1 ELSE 0 END) as c_31_60,
        SUM(CASE WHEN due_date < DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND due_date >= DATE_SUB(CURDATE(), INTERVAL 60 DAY) THEN amount - COALESCE(paid_amount, 0) ELSE 0 END) as a_31_60,
        SUM(CASE WHEN due_date < DATE_SUB(CURDATE(), INTERVAL 60 DAY) AND due_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY) THEN 1 ELSE 0 END) as c_61_90,
        SUM(CASE WHEN due_date < DATE_SUB(CURDATE(), INTERVAL 60 DAY) AND due_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY) THEN amount - COALESCE(paid_amount, 0) ELSE 0 END) as a_61_90,
        SUM(CASE WHEN due_date < DATE_SUB(CURDATE(), INTERVAL 90 DAY) THEN 1 ELSE 0 END) as c_90_plus,
        SUM(CASE WHEN due_date < DATE_SUB(CURDATE(), INTERVAL 90 DAY) THEN amount - COALESCE(paid_amount, 0) ELSE 0 END) as a_90_plus
    FROM invoxa_invoices
    WHERE is_quote = 0 AND status NOT IN ('paid', 'void') $ccyFilterInv $testFilter
");
$row_aging = $res_aging->fetch_assoc() ?: [];
$stats_aging = [
    ['label' => 'Current', 'count' => (int) ($row_aging['c_current'] ?? 0), 'amount' => $row_aging['a_current'] ?? 0, 'color' => '#10b981'],
    ['label' => '1-30 Days', 'count' => (int) ($row_aging['c_1_30'] ?? 0), 'amount' => $row_aging['a_1_30'] ?? 0, 'color' => '#f59e0b'],
    ['label' => '31-60 Days', 'count' => (int) ($row_aging['c_31_60'] ?? 0), 'amount' => $row_aging['a_31_60'] ?? 0, 'color' => '#f97316'],
    ['label' => '61-90 Days', 'count' => (int) ($row_aging['c_61_90'] ?? 0), 'amount' => $row_aging['a_61_90'] ?? 0, 'color' => '#ef4444'],
    ['label' => '90+ Days', 'count' => (int) ($row_aging['c_90_plus'] ?? 0), 'amount' => $row_aging['a_90_plus'] ?? 0, 'color' => '#b91c1c'],
];

// Client Growth & Mix
$stats_new_clients_month = $mysqli->query("SELECT COUNT(*) as c FROM invoxa_clients WHERE created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01') " . invoxaTestViewClientFilter($hideTest, $showTestOnly))->fetch_assoc()['c'] ?? 0;
$stats_billing_freq = [];
$res_freq = $mysqli->query("SELECT billing_frequency, COUNT(*) as c FROM invoxa_clients WHERE is_active = 1 " . invoxaTestViewClientFilter($hideTest, $showTestOnly) . " GROUP BY billing_frequency");
while ($r = $res_freq->fetch_assoc())
    $stats_billing_freq[$r['billing_frequency']] = (int) $r['c'];

// Clients Needing Attention — active clients with no invoice in 60+ days
// (or ever), a simple stand-in for a full CRM pipeline.
$clients_needing_attention = [];
$res_attn = $mysqli->query("
    SELECT c.client_name, MAX(i.invoice_date) as last_invoice
    FROM invoxa_clients c
    LEFT JOIN invoxa_invoices i ON c.client_key = i.client_key AND i.is_quote = 0
    WHERE c.is_active = 1 " . invoxaTestViewClientFilter($hideTest, $showTestOnly, 'AND', 'c.is_test') . "
    GROUP BY c.id
    HAVING last_invoice IS NULL OR last_invoice < DATE_SUB(NOW(), INTERVAL 60 DAY)
    ORDER BY last_invoice IS NOT NULL, last_invoice ASC
    LIMIT 8
");
if ($res_attn) {
    while ($r = $res_attn->fetch_assoc())
        $clients_needing_attention[] = $r;
}

// Email Delivery Health — how often outgoing invoice/reminder emails actually
// go out vs bounce/fail at send time (SMTP errors, bad addresses, etc.).
$res_email = $mysqli->query("SELECT
        SUM(CASE WHEN action_type = 'email_sent' THEN 1 ELSE 0 END) as sent,
        SUM(CASE WHEN action_type = 'email_failed' THEN 1 ELSE 0 END) as failed
    FROM invoxa_actions WHERE action_type IN ('email_sent', 'email_failed')");
$row_email = $res_email->fetch_assoc();
$stats_email_sent = (int) ($row_email['sent'] ?? 0);
$stats_email_failed = (int) ($row_email['failed'] ?? 0);
$stats_email_total = $stats_email_sent + $stats_email_failed;
$stats_email_success_rate = $stats_email_total > 0 ? round($stats_email_sent / $stats_email_total * 100, 1) : 100.0;

// Tax Year monthly breakdown — same query the "Monthly Summary" CSV export
// uses (see ?export=tax_year_monthly), surfaced inline here instead of only
// as a download.
$stats_ty_monthly = [];
$res_ty_monthly = $mysqli->query("
    SELECT DATE_FORMAT(invoice_date, '%Y-%m') as month,
           SUM(amount) as total_invoiced,
           SUM(COALESCE(paid_amount, 0)) as total_paid,
           SUM(amount) - SUM(COALESCE(paid_amount, 0)) as outstanding,
           SUM(CASE WHEN status NOT IN ('paid') THEN 1 ELSE 0 END) as unpaid_count
    FROM invoxa_invoices
    WHERE is_quote = 0 AND status != 'void' AND invoice_date >= '$startStr' $ccyFilterInv $testFilter
    GROUP BY DATE_FORMAT(invoice_date, '%Y-%m')
    ORDER BY month ASC
");
if ($res_ty_monthly) {
    while ($r = $res_ty_monthly->fetch_assoc())
        $stats_ty_monthly[] = $r;
}
// How far through the current tax year "today" is, for a simple progress bar.
$taxYearEnd = (clone $taxYearStart)->modify('+1 year')->modify('-1 second');
$stats_tax_year_days_total = max(1, $taxYearStart->diff($taxYearEnd)->days + 1);
$stats_tax_year_days_elapsed = min($stats_tax_year_days_total, $taxYearStart->diff($now)->days + 1);
$stats_tax_year_progress_pct = round($stats_tax_year_days_elapsed / $stats_tax_year_days_total * 100, 1);

// Activity — recurring billing / reminders / late fees, and invoice volume by
// client rather than by revenue (complements the Top 5 by Paid Revenue table).
$res_last_run = $mysqli->query("SELECT notes, performed_at FROM invoxa_actions WHERE action_type = 'recurring_run' ORDER BY performed_at DESC LIMIT 1");
$stats_last_recurring_run = $res_last_run ? $res_last_run->fetch_assoc() : null;

$stats_late_fees_charged = (int) ($mysqli->query("SELECT COUNT(*) as c FROM invoxa_actions WHERE action_type = 'late_fee_charged'")->fetch_assoc()['c'] ?? 0);

$res_reminders = $mysqli->query("SELECT
        SUM(CASE WHEN action_type = 'reminder_sent' THEN 1 ELSE 0 END) as sent,
        SUM(CASE WHEN action_type = 'reminder_failed' THEN 1 ELSE 0 END) as failed
    FROM invoxa_actions WHERE action_type IN ('reminder_sent', 'reminder_failed')");
$row_reminders = $res_reminders->fetch_assoc();
$stats_reminders_sent = (int) ($row_reminders['sent'] ?? 0);
$stats_reminders_failed = (int) ($row_reminders['failed'] ?? 0);

$most_active_clients = [];
$res_active = $mysqli->query("
    SELECT c.client_name, COUNT(i.id) as invoice_count
    FROM invoxa_invoices i
    JOIN invoxa_clients c ON i.client_key = c.client_key
    WHERE i.is_quote = 0 AND i.status != 'void' " . invoxaTestViewClientFilter($hideTest, $showTestOnly, 'AND', 'c.is_test') . "
    GROUP BY c.client_name
    ORDER BY invoice_count DESC
    LIMIT 5
");
if ($res_active) {
    while ($r = $res_active->fetch_assoc())
        $most_active_clients[] = $r;
}

$stats_invoice_status = [];
$statusLabels = ['paid' => 'Paid', 'sent' => 'Sent', 'pending' => 'Pending', 'draft' => 'Draft', 'failed' => 'Failed', 'void' => 'Void'];
$statusColors = ['paid' => '#10b981', 'sent' => '#3b82f6', 'pending' => '#f59e0b', 'draft' => '#94a3b8', 'failed' => '#ef4444', 'void' => '#6b7280'];
$res_status = $mysqli->query("SELECT status, COUNT(*) as c, SUM(amount) as total FROM invoxa_invoices WHERE is_quote = 0 $ccyFilterInv $testFilter GROUP BY status");
$statusCounts = [];
if ($res_status) {
    while ($r = $res_status->fetch_assoc())
        $statusCounts[$r['status']] = $r;
}
foreach ($statusLabels as $sKey => $sLabel) {
    if (!empty($statusCounts[$sKey])) {
        $stats_invoice_status[] = ['status' => $sKey, 'label' => $sLabel, 'count' => (int) $statusCounts[$sKey]['c'], 'amount' => (float) $statusCounts[$sKey]['total'], 'color' => $statusColors[$sKey]];
    }
}

$stats_revenue_trend = [];
$res_trend = $mysqli->query("
    SELECT DATE_FORMAT(invoice_date, '%Y-%m') as month,
           SUM(amount) as total_invoiced,
           SUM(COALESCE(paid_amount, 0)) as total_paid
    FROM invoxa_invoices
    WHERE is_quote = 0 AND status != 'void' AND invoice_date >= DATE_SUB(CURDATE(), INTERVAL 11 MONTH) $ccyFilterInv $testFilter
    GROUP BY DATE_FORMAT(invoice_date, '%Y-%m')
    ORDER BY month ASC
");
$trendByMonth = [];
if ($res_trend) {
    while ($r = $res_trend->fetch_assoc())
        $trendByMonth[$r['month']] = $r;
}
for ($m = 11; $m >= 0; $m--) {
    $monthKey = (new DateTime())->modify("-{$m} months")->format('Y-m');
    $row = $trendByMonth[$monthKey] ?? ['total_invoiced' => 0, 'total_paid' => 0];
    $stats_revenue_trend[] = ['month' => $monthKey, 'total_invoiced' => (float) $row['total_invoiced'], 'total_paid' => (float) $row['total_paid']];
}

$stats_expense_ty_total = (float) ($mysqli->query("SELECT SUM(amount) as t FROM invoxa_expenses WHERE expense_date >= '$startStr'")->fetch_assoc()['t'] ?? 0);
$stats_net_income_ty = $stats_ty_paid - $stats_expense_ty_total;

$expenseCatLabels = expenseCategories();
$stats_expense_categories = [];
$res_expcat = $mysqli->query("SELECT category, SUM(amount) as total FROM invoxa_expenses WHERE expense_date >= '$startStr' GROUP BY category ORDER BY total DESC");
if ($res_expcat) {
    while ($r = $res_expcat->fetch_assoc()) {
        $stats_expense_categories[] = ['category' => $r['category'], 'label' => $expenseCatLabels[$r['category']] ?? ucfirst($r['category']), 'total' => (float) $r['total']];
    }
}

$stats_expense_monthly = [];
$res_expmonthly = $mysqli->query("
    SELECT DATE_FORMAT(expense_date, '%Y-%m') as month, SUM(amount) as total
    FROM invoxa_expenses
    WHERE expense_date >= '$startStr'
    GROUP BY DATE_FORMAT(expense_date, '%Y-%m')
    ORDER BY month ASC
");
if ($res_expmonthly) {
    while ($r = $res_expmonthly->fetch_assoc())
        $stats_expense_monthly[] = ['month' => $r['month'], 'total' => (float) $r['total']];
}

// System Health
$stats_db_rows = 0;
$all_tables_info = [];
$tablesRes = $mysqli->query("SHOW TABLES");
if ($tablesRes) {
    while ($t = $tablesRes->fetch_row()) {
        $tName = $t[0];
        $count = $mysqli->query("SELECT COUNT(*) as c FROM `" . $tName . "`")->fetch_assoc()['c'] ?? 0;
        $stats_db_rows += $count;
        $all_tables_info[$tName] = $count;
    }
}

// Backup Health
$backup_dir = '/usr/share/nginx/html/invoxa-backups/';
$backup_count = 0;
$latest_backup = 'Never';
if (is_dir($backup_dir)) {
    $files = glob($backup_dir . 'backup_*.sql');
    if ($files) {
        $backup_count = count($files);
        usort($files, function ($a, $b) {
            return filemtime($b) - filemtime($a);
        });
        $latest_backup = date('M j, Y', filemtime($files[0]));
    }
}

$stats_db_size_bytes = (int) ($mysqli->query("SELECT SUM(data_length + index_length) as s FROM information_schema.TABLES WHERE table_schema = DATABASE()")->fetch_assoc()['s'] ?? 0);
$stats_invoices_dir_size_bytes = invoxaDirSize(INVOICES_DIR);
$stats_backups_dir_size_bytes = invoxaDirSize($backup_dir);

$stats_webhook_unmatched_total = (int) ($mysqli->query("SELECT COUNT(*) as c FROM invoxa_actions WHERE action_type = 'webhook_unmatched'")->fetch_assoc()['c'] ?? 0);
$stats_webhook_unmatched_30d = (int) ($mysqli->query("SELECT COUNT(*) as c FROM invoxa_actions WHERE action_type = 'webhook_unmatched' AND performed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetch_assoc()['c'] ?? 0);

$stats_php_version = PHP_VERSION;
$stats_mysql_version = $mysqli->server_info;

// Offsite push status — written by the offsite cron/rclone script after each
// push attempt, not by invoxa.php. Missing file just means it hasn't run yet.
$offsite_status = null;
$offsiteStatusFile = $backup_dir . '.offsite_status.json';
if (is_file($offsiteStatusFile)) {
    $offsite_status = json_decode((string) @file_get_contents($offsiteStatusFile), true) ?: null;
}

// Fragment endpoint for the background tab refresh — Invoices/Clients/Quotes
// return just <tr> row markup (see renderInvoiceRows/renderClientRows/
// renderQuoteRows above); dashboard_stats/activity/stats_section/
// sync_section/audit_section return a larger markup chunk for their tab.
// Uses the same render functions as the full page, so the two can't drift
// apart. Placed here rather than near $invoices/$clients because
// stats_section/sync_section/audit_section need data not computed until
// this point in the script.
if (isset($_GET['api']) && $_GET['api'] === 'table_html') {
    header('Content-Type: text/html; charset=utf-8');
    $which = $_GET['which'] ?? '';
    if ($which === 'invoices') {
        echo renderInvoiceRows($invoices);
    } elseif ($which === 'clients') {
        echo renderClientRows($clients);
    } elseif ($which === 'quotes') {
        $qRes = $mysqli->query("SELECT * FROM invoxa_invoices WHERE is_quote = 1 ORDER BY invoice_date DESC");
        echo renderQuoteRows($qRes);
    } elseif ($which === 'expenses') {
        echo renderExpenseRows($expenses);
    } elseif ($which === 'dashboard_stats') {
        echo renderDashboardStats($settings, $failedInvoices, $overdueInvoices, $total_invoiced_by_ccy, $total_monthly_by_ccy, $total_paid_by_ccy, (int) $client_count);
    } elseif ($which === 'activity') {
        echo renderActivityRows($actions);
    } elseif ($which === 'stats_section') {
        echo renderStatsSection();
    } elseif ($which === 'sync_section') {
        echo renderSyncSection($missingFiles, $knownClientFolders, $missingDiskData);
    } elseif ($which === 'audit_section') {
        echo renderAuditSection($actions);
    }
    exit;
}
?>
<?php require_once __DIR__ . '/lib/page_head.php'; ?>

<?php require_once __DIR__ . '/lib/page_nav.php'; ?>
    <div class="main">

        <?php require_once __DIR__ . '/lib/tab_dashboard.php'; ?>
        <?php require_once __DIR__ . '/lib/tab_invoices.php'; ?>
        <?php require_once __DIR__ . '/lib/tab_billing.php'; ?>
        <?php require_once __DIR__ . '/lib/tab_clients.php'; ?>
        <?php require_once __DIR__ . '/lib/tab_expenses.php'; ?>
        <?php require_once __DIR__ . '/lib/tab_quotes.php'; ?>
        <?php require_once __DIR__ . '/lib/tabs_misc.php'; ?>
        <?php require_once __DIR__ . '/lib/tab_docs.php'; ?>

        <?php require_once __DIR__ . '/lib/settings_page.php'; ?>

        <?php require_once __DIR__ . '/lib/backup_page.php'; ?>

        <?php require_once __DIR__ . '/lib/page_modals.php'; ?>

        <?php require_once __DIR__ . '/lib/page_script.php'; ?>
</body>

</html>
