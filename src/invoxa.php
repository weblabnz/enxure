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
define('APP_VERSION', '2.11.5');

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
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/svg+xml" href="assets/img/invoxa-mark.svg" />
    <link rel="alternate icon" href="assets/img/favicon.ico" />
    <link rel="apple-touch-icon" href="assets/img/apple-touch-icon.png" />
    <link rel="manifest" href="manifest.webmanifest" />
    <meta name="theme-color" content="#0a0f1c" />
    <title>Invoxa<?= INSTANCE_LABEL ? ' (' . htmlspecialchars(INSTANCE_LABEL) . ')' : '' ?></title>
    <script>
        const savedTheme = localStorage.getItem('invoxa_theme') || 'light';
        document.documentElement.setAttribute('data-theme', savedTheme);
    </script>
    <!--<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">-->
    <link rel="stylesheet" href="assets/css/all.min.css">
    <link href="assets/css/simple-datatables.css" rel="stylesheet" type="text/css">
    <script src="assets/js/simple-datatables.js" type="text/javascript"></script>
    <script src="assets/js/chart.js"></script>
    <script src="assets/js/cronstrue.min.js"></script>
    <style>
        :root {
            --bg-color: #0a0f1c;
            --surface: #131b2e;
            --surface-2: #1a2439;
            --surface-hover: #212d47;
            --text-primary: #f7f9fc;
            --text-secondary: #90a0bb;
            --accent: #4f7cff;
            --accent-hover: #3d63e0;
            --accent-soft: rgba(79, 124, 255, 0.12);
            --success: #22c55e;
            --warning: #f59e0b;
            --danger: #f5455c;
            --border: rgba(255, 255, 255, 0.08);
            --radius-sm: 8px;
            --radius-md: 12px;
            --radius-lg: 16px;
            --shadow-sm: 0 1px 2px rgba(0, 0, 0, 0.2);
            --shadow-md: 0 8px 24px -8px rgba(0, 0, 0, 0.45);
            --shadow-lg: 0 24px 48px -16px rgba(0, 0, 0, 0.55);
        }

        [data-theme="light"] {
            --bg-color: #f3f5fa;
            --surface: #ffffff;
            --surface-2: #f8f9fd;
            --surface-hover: #eef1f8;
            --text-primary: #0f172a;
            --text-secondary: #5c6b85;
            --accent: #3d63e0;
            --accent-hover: #2e4fc0;
            --accent-soft: rgba(61, 99, 224, 0.08);
            --success: #16a34a;
            --warning: #d97706;
            --danger: #dc2626;
            --border: rgba(15, 23, 42, 0.08);
            --shadow-sm: 0 1px 2px rgba(15, 23, 42, 0.04);
            --shadow-md: 0 8px 24px -8px rgba(15, 23, 42, 0.12);
            --shadow-lg: 0 24px 48px -16px rgba(15, 23, 42, 0.18);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        ::selection {
            background: var(--accent);
            color: white;
        }

        ::-webkit-scrollbar {
            width: 10px;
            height: 10px;
        }

        ::-webkit-scrollbar-track {
            background: transparent;
        }

        ::-webkit-scrollbar-thumb {
            background: var(--surface-hover);
            border-radius: 8px;
            border: 2px solid transparent;
            background-clip: padding-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Inter, Roboto, 'Helvetica Neue', Arial, sans-serif;
            background:
                radial-gradient(1100px 500px at 12% -10%, var(--accent-soft), transparent 60%),
                var(--bg-color);
            color: var(--text-primary);
            display: flex;
            height: 100vh;
            overflow: hidden;
            -webkit-font-smoothing: antialiased;
        }

        .sidebar {
            width: 280px;
            background: var(--surface);
            border-right: 1px solid var(--border);
            display: flex;
            flex-direction: column;
            padding: 1.5rem 0 0 0;
            flex-shrink: 0;
            overflow-y: auto;
        }

        .sidebar-header {
            padding: 0 1.5rem 1.5rem;
            border-bottom: 1px solid var(--border);
            margin-bottom: 1rem;
        }

        .sidebar-header h1 {
            font-size: 1.2rem;
            font-weight: 700;
            letter-spacing: -0.01em;
            display: flex;
            align-items: center;
            gap: 0.65rem;
        }

        .sidebar-header h1 img {
            border-radius: 9px;
            box-shadow: var(--shadow-sm);
        }

        .sidebar-header i {
            color: var(--accent);
        }

        .global-search-wrap {
            position: relative;
            margin: 0 1.5rem 1rem;
        }

        .global-search-wrap>i.fa-magnifying-glass {
            position: absolute;
            left: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-secondary);
            font-size: 0.8rem;
            pointer-events: none;
        }

        #globalSearchInput {
            width: 100%;
            padding: 0.55rem 3rem 0.55rem 2.1rem;
            background: var(--surface-2);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            color: var(--text-primary);
            font-family: inherit;
            font-size: 0.85rem;
        }

        #globalSearchInput:focus {
            outline: none;
            border-color: var(--accent);
        }

        .global-search-wrap kbd {
            position: absolute;
            right: 0.5rem;
            top: 50%;
            transform: translateY(-50%);
            font-size: 0.65rem;
            font-family: inherit;
            color: var(--text-secondary);
            background: var(--surface-hover);
            border: 1px solid var(--border);
            border-radius: 4px;
            padding: 0.1rem 0.35rem;
            pointer-events: none;
        }

        .global-search-results {
            display: none;
            position: fixed;
            max-height: 60vh;
            overflow-y: auto;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-lg);
            z-index: 1300;
        }

        .global-search-results.active {
            display: block;
        }

        .global-search-group-label {
            padding: 0.5rem 0.85rem 0.25rem;
            font-size: 0.68rem;
            font-weight: 700;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            color: var(--text-secondary);
        }

        .global-search-result {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            padding: 0.55rem 0.85rem;
            cursor: pointer;
            font-size: 0.85rem;
        }

        .global-search-result:hover {
            background: var(--surface-hover);
        }

        .global-search-empty {
            padding: 1rem 0.85rem;
            color: var(--text-secondary);
            font-size: 0.85rem;
            text-align: center;
        }

        .nav-section-label {
            padding: 0 1.5rem;
            font-size: 0.7rem;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: var(--text-secondary);
            opacity: 0.6;
            margin: 0.85rem 0 0.35rem;
        }

        .nav-item {
            position: relative;
            margin: 0.05rem 0.75rem;
            padding: 0.5rem 0.85rem;
            border-radius: var(--radius-sm);
            color: var(--text-secondary);
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.85rem;
            transition: background 0.15s ease, color 0.15s ease;
            font-weight: 500;
            font-size: 0.925rem;
        }

        .nav-item:hover {
            background: var(--surface-hover);
            color: var(--text-primary);
        }

        .nav-item.active {
            background: var(--accent-soft);
            color: var(--accent);
            font-weight: 600;
        }

        .nav-item.active::before,
        .nav-item.tool-item.active::before {
            content: "";
            position: absolute;
            left: -0.75rem;
            top: 0.2rem;
            bottom: 0.2rem;
            width: 3px;
            border-radius: 0 3px 3px 0;
            background: var(--accent);
        }

        .nav-item.tool-item {
            color: var(--text-secondary);
        }

        .nav-item.tool-item:hover {
            color: var(--text-primary);
            background: var(--surface-hover);
        }

        .nav-item.tool-item.active {
            background: var(--accent-soft);
            color: var(--accent);
            font-weight: 600;
        }

        .nav-item i {
            width: 18px;
            text-align: center;
            font-size: 0.9rem;
        }

        .user-panel {
            margin-top: auto;
            padding: 1.25rem;
            border-top: 1px solid var(--border);
        }

        .mid-panel {
            margin: 20px 0 20px 0px;
            border-top: 1px solid var(--border);
        }

        .logout-btn {
            width: 100%;
            background: transparent;
            border: 1px solid var(--border);
            color: var(--text-secondary);
            padding: 0.6rem;
            border-radius: var(--radius-sm);
            cursor: pointer;
            font-weight: 500;
            transition: 0.15s ease;
        }

        .logout-btn:hover {
            background: rgba(245, 69, 92, 0.1);
            color: var(--danger);
            border-color: rgba(245, 69, 92, 0.25);
        }

        /* .main no longer scrolls itself — h2.page-title is fixed and never
           scrolls, so it needs no background trick to hide content behind it;
           .section-scroll scrolls independently underneath. */
        .main {
            flex: 1;
            display: flex;
            flex-direction: column;
            padding: 2rem 2.5rem 0;
            overflow: hidden;
            background: var(--bg-color);
        }

        .section {
            display: none;
            flex-direction: column;
            flex: 1;
            min-height: 0;
        }

        .section.active {
            display: flex;
            animation: fadeIn 0.35s ease;
        }

        .section-scroll {
            flex: 1;
            overflow-y: auto;
            padding-bottom: 2rem;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(6px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        h2.page-title {
            font-size: 1.6rem;
            font-weight: 700;
            letter-spacing: -0.015em;
            margin-bottom: 1.5rem;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-shrink: 0;
        }

        .alert-strip {
            background: rgba(245, 69, 92, 0.1);
            border: 1px solid rgba(245, 69, 92, 0.2);
            color: var(--danger);
            padding: 1rem 1.5rem;
            border-radius: var(--radius-md);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
            gap: 1.25rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: linear-gradient(180deg, var(--surface-2), var(--surface));
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 1.4rem 1.5rem;
            box-shadow: var(--shadow-sm);
            transition: box-shadow 0.15s ease;
        }

        .stat-card:hover {
            box-shadow: var(--shadow-md);
        }

        .stat-title {
            color: var(--text-secondary);
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            font-weight: 600;
        }

        .stat-card-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 0.75rem;
            margin-bottom: 0.6rem;
        }

        .stat-icon {
            width: 34px;
            height: 34px;
            border-radius: 9px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.95rem;
            flex-shrink: 0;
            background: var(--accent-soft);
            color: var(--accent);
        }

        .stat-icon.success {
            background: color-mix(in srgb, var(--success) 15%, transparent);
            color: var(--success);
        }

        .stat-icon.warning {
            background: color-mix(in srgb, var(--warning) 15%, transparent);
            color: var(--warning);
        }

        .stat-value {
            font-size: 1.9rem;
            font-weight: 700;
            letter-spacing: -0.02em;
        }

        .empty-state {
            text-align: center;
            color: var(--text-secondary);
            padding: 2.5rem 1rem;
        }

        .empty-state i {
            display: block;
            font-size: 1.6rem;
            margin-bottom: 0.6rem;
            opacity: 0.5;
        }

        td.datatable-empty {
            text-align: center;
            color: var(--text-secondary);
            padding: 2.5rem 1rem !important;
        }

        td.datatable-empty::before {
            content: "\f01c";
            font-family: "Font Awesome 6 Free";
            font-weight: 900;
            display: block;
            font-size: 1.6rem;
            margin-bottom: 0.6rem;
            opacity: 0.5;
        }

        .table-refreshing {
            position: relative;
        }

        .table-refreshing tbody {
            opacity: 0.35;
            transition: opacity 0.15s ease;
            pointer-events: none;
        }

        .table-refreshing::after {
            content: "";
            position: absolute;
            top: 50%;
            left: 50%;
            width: 28px;
            height: 28px;
            margin: -14px 0 0 -14px;
            border: 3px solid var(--border);
            border-top-color: var(--accent);
            border-radius: 50%;
            animation: table-refresh-spin 0.7s linear infinite;
            z-index: 2;
        }

        @keyframes table-refresh-spin {
            to { transform: rotate(360deg); }
        }

        .client-avatar {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            color: #fff;
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.02em;
            flex-shrink: 0;
        }

        .charts-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 1.25rem;
            margin-bottom: 2rem;
        }

        @media (max-width: 1024px) {
            .charts-grid {
                grid-template-columns: 1fr;
            }
        }

        .client-form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0 1.25rem;
        }

        @media (max-width: 640px) {
            .client-form-grid {
                grid-template-columns: 1fr;
            }
        }

        .chart-container {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 1.5rem;
            margin-bottom: 2rem;
            height: 350px;
            box-shadow: var(--shadow-sm);
        }

        .card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            margin-bottom: 2rem;
            box-shadow: var(--shadow-sm);
            min-width: 0;
        }

        .card-header {
            padding: 1.1rem 1.5rem;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-body {
            padding: 1.5rem;
        }

        /* Mini nav (left) + one content pane at a time (right), same show/hide
           idea as the main sidebar nav nested one level deeper. Shared by
           Settings and Docs, hence generic .subnav-* names rather than
           .settings-*. Lives inside the tab's .section-scroll, so .subnav's
           sticky positioning is relative to .section-scroll, not .main. */
        .subnav-layout {
            display: flex;
            gap: 1.5rem;
            align-items: flex-start;
        }

        .subnav {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
            width: 220px;
            flex-shrink: 0;
            position: sticky;
            top: 0;
        }

        .subnav-item {
            display: flex;
            align-items: center;
            gap: 0.65rem;
            padding: 0.65rem 0.9rem;
            border-radius: var(--radius-lg);
            border: 1px solid transparent;
            background: none;
            color: var(--text-secondary);
            font-family: inherit;
            font-size: 0.9rem;
            font-weight: 500;
            text-align: left;
            cursor: pointer;
        }

        .subnav-item:hover {
            background: var(--surface-hover);
            color: var(--text-primary);
        }

        .subnav-item.active {
            background: var(--surface);
            border-color: var(--border);
            color: var(--text-primary);
        }

        .subnav-item i.fa-solid:first-child,
        .subnav-item i.fa-brands:first-child {
            width: 1.1rem;
            text-align: center;
            color: var(--accent);
        }

        .subnav-item.danger {
            color: var(--danger);
        }

        .subnav-item.danger i.fa-solid:first-child {
            color: var(--danger);
        }

        .subnav-item.danger:hover {
            background: rgba(239, 68, 68, 0.12);
            color: var(--danger);
        }

        .subnav-item.danger.active {
            border-color: var(--danger);
            color: var(--danger);
        }

        .subnav-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            margin-left: auto;
            flex-shrink: 0;
        }

        .subnav-dot.on {
            background: var(--success);
        }

        .subnav-dot.off {
            background: var(--text-secondary);
            opacity: 0.5;
        }

        .subnav-content {
            flex: 1;
            min-width: 0;
        }

        /* One card per row, full width — a pane with several cards (e.g.
           Billing) reads top-to-bottom instead of splitting into
           side-by-side columns. */
        .subnav-pane {
            display: none;
            flex-direction: column;
            gap: 1.5rem;
        }

        .subnav-pane.active {
            display: flex;
        }

        .nav-subnav-toggle {
            display: none;
            margin-left: auto;
            background: none;
            border: none;
            color: inherit;
            font-size: 0.75rem;
            padding: 0.25rem;
            cursor: pointer;
            flex-shrink: 0;
        }

        .nav-subnav-toggle i {
            transition: transform 0.15s ease;
        }

        .nav-subnav-toggle.expanded i {
            transform: rotate(180deg);
        }

        .nav-subnav-slot {
            display: none;
        }

        .toolbar-toggle {
            display: none;
            align-items: center;
            justify-content: space-between;
            gap: 0.5rem;
            width: 100%;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 0.6rem 0.9rem;
            font-family: inherit;
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--text-primary);
            cursor: pointer;
        }

        .toolbar-toggle i.toolbar-toggle-chevron {
            transition: transform 0.15s ease;
        }

        .toolbar-toggle.expanded i.toolbar-toggle-chevron {
            transform: rotate(180deg);
        }

        .toolbar-collapsible {
            display: contents;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            padding: 0.3rem 0.65rem;
            border-radius: 9999px;
            font-size: 0.68rem;
            font-weight: 700;
            letter-spacing: 0.02em;
            text-transform: uppercase;
        }

        .pill-btn {
            display: inline-flex;
            align-items: center;
            padding: 0.3rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.78rem;
            font-weight: 600;
            background: var(--surface-hover);
            color: var(--text-primary);
            border: 1px solid var(--border);
            cursor: pointer;
            width: auto;
            margin: 0;
        }

        .pill-btn:hover {
            background: var(--accent-soft);
            color: var(--accent);
            border-color: var(--accent);
        }

        .pill-btn.active {
            background: var(--accent);
            color: #fff;
            border-color: var(--accent);
            font-weight: 700;
        }

        .badge.sent {
            background: rgba(34, 197, 94, 0.12);
            color: var(--success);
            border: 1px solid rgba(34, 197, 94, 0.25);
        }

        .badge.paid {
            background: var(--accent-soft);
            color: var(--accent);
            border: 1px solid rgba(79, 124, 255, 0.25);
        }

        .badge.partial {
            background: rgba(245, 158, 11, 0.12);
            color: var(--warning);
            border: 1px solid rgba(245, 158, 11, 0.25);
        }

        .has-tooltip {
            position: relative;
            cursor: help;
            border-bottom: 1px dashed var(--text-secondary);
        }

        /* ::after tooltip suppressed — replaced by JS #globalTip below */
        .has-tooltip::after {
            display: none;
        }

        .badge.failed {
            background: rgba(245, 69, 92, 0.12);
            color: var(--danger);
            border: 1px solid rgba(245, 69, 92, 0.25);
        }

        .badge.overdue {
            background: rgba(245, 69, 92, 0.12);
            color: var(--danger);
            border: 1px solid rgba(245, 69, 92, 0.25);
            margin-left: 0.35rem;
        }

        .badge.test {
            background: rgba(245, 158, 11, 0.12);
            color: var(--warning);
            border: 1px solid rgba(245, 158, 11, 0.25);
        }

        .badge.void {
            background: var(--surface-hover);
            color: var(--text-secondary);
            border: 1px solid var(--border);
            text-decoration: line-through;
        }

        .btn {
            background: var(--surface-2);
            color: var(--text-primary);
            border: 1px solid var(--border);
            padding: 0.55rem 1.05rem;
            border-radius: var(--radius-sm);
            cursor: pointer;
            font-size: 0.875rem;
            font-weight: 600;
            transition: background 0.15s ease, border-color 0.15s ease, transform 0.1s ease, box-shadow 0.15s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn:hover {
            background: var(--surface-hover);
        }

        .btn:active {
            transform: translateY(1px);
        }

        .btn:disabled {
            opacity: 0.45;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .btn.primary {
            background: var(--accent);
            border-color: var(--accent);
            color: white;
            box-shadow: 0 4px 14px -4px rgba(79, 124, 255, 0.5);
        }

        .btn.primary:hover {
            background: var(--accent-hover);
            border-color: var(--accent-hover);
        }

        .btn.success {
            background: var(--success);
            border-color: var(--success);
            color: white;
        }

        .btn.danger {
            background: var(--danger);
            border-color: var(--danger);
            color: white;
        }

        .btn.small {
            padding: 0.3rem 0.6rem;
            font-size: 0.75rem;
        }

        .timeline {
            position: relative;
            padding-left: 2rem;
        }

        .timeline::before {
            content: '';
            position: absolute;
            left: 0.75rem;
            top: 0;
            bottom: 0;
            width: 2px;
            background: var(--border);
        }

        .timeline-item {
            position: relative;
            margin-bottom: 1.25rem;
        }

        .timeline-icon {
            position: absolute;
            left: -2rem;
            top: 50%;
            transform: translateY(-50%);
            width: 1.5rem;
            height: 1.5rem;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--surface);
            border: 2px solid var(--accent);
            font-size: 0.75rem;
            color: var(--text-primary);
            z-index: 1;
        }

        .timeline-content {
            background: var(--surface-2);
            padding: 0.75rem 1.25rem;
            border-radius: var(--radius-sm);
            border: 1px solid var(--border);
            display: flex;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 0.75rem 1.5rem;
        }

        .timeline-time {
            font-size: 0.75rem;
            color: var(--text-secondary);
            white-space: nowrap;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            color: var(--text-secondary);
            font-size: 0.85rem;
            font-weight: 600;
        }

        .form-control {
            width: 100%;
            padding: 0.7rem 0.85rem;
            background: var(--surface-2);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            color: var(--text-primary);
            font-family: inherit;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
        }

        .form-control option,
        select option {
            background-color: var(--bg-color);
            color: var(--text-primary);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 3px var(--accent-soft);
        }

        .form-control:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .li-amount::-webkit-outer-spin-button,
        .li-amount::-webkit-inner-spin-button {
            -webkit-appearance: none;
            margin: 0;
        }

        .li-amount {
            -moz-appearance: textfield;
            appearance: textfield;
        }

        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(5, 8, 16, 0.65);
            backdrop-filter: blur(6px);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }

        .modal-overlay.active {
            display: flex;
        }

        .modal {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            width: 90%;
            max-width: 600px;
            max-height: 75vh;
            display: flex;
            flex-direction: column;
            box-shadow: var(--shadow-lg);
        }

        .modal.large {
            max-width: 900px;
        }

        .modal-header {
            padding: 1.4rem 1.5rem;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-body {
            padding: 1.5rem;
            overflow-y: auto;
        }

        .modal-footer {
            padding: 1.25rem 1.5rem;
            border-top: 1px solid var(--border);
            display: flex;
            justify-content: flex-end;
            gap: 0.75rem;
        }

        .doc-content h1, .doc-content h2, .doc-content h3, .doc-content h4 {
            color: var(--text-primary);
            margin: 1.5rem 0 0.75rem;
            line-height: 1.3;
        }

        .doc-content h1:first-child, .doc-content h2:first-child {
            margin-top: 0;
        }

        .doc-content h1 { font-size: 1.4rem; }
        .doc-content h2 { font-size: 1.15rem; border-bottom: 1px solid var(--border); padding-bottom: 0.4rem; }
        .doc-content h3 { font-size: 1rem; }

        .doc-content p, .doc-content li {
            color: var(--text-secondary);
            line-height: 1.65;
            font-size: 0.9rem;
        }

        .doc-content p { margin: 0.75rem 0; }
        .doc-content ul, .doc-content ol { margin: 0.5rem 0 0.75rem; padding-left: 1.4rem; }
        .doc-content li { margin: 0.3rem 0; }
        .doc-content strong { color: var(--text-primary); }
        .doc-content a { color: var(--accent); text-decoration: none; }
        .doc-content a:hover { text-decoration: underline; }

        .doc-content code {
            background: var(--surface-hover);
            border: 1px solid var(--border);
            border-radius: 4px;
            padding: 0.1rem 0.4rem;
            font-size: 0.82rem;
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            color: var(--text-primary);
        }

        .doc-content pre {
            background: var(--surface-hover);
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            padding: 0.9rem 1rem;
            overflow-x: auto;
            margin: 0.75rem 0;
        }

        .doc-content pre code {
            background: none;
            border: none;
            padding: 0;
        }

        .doc-content table {
            width: 100%;
            border-collapse: collapse;
            margin: 0.75rem 0 1.25rem;
            font-size: 0.85rem;
        }

        .doc-content th, .doc-content td {
            border: 1px solid var(--border);
            padding: 0.5rem 0.75rem;
            text-align: left;
        }

        .doc-content th {
            background: var(--surface-hover);
            color: var(--text-primary);
        }

        .doc-content td { color: var(--text-secondary); }

        .doc-content img {
            max-width: 70%;
            height: auto;
            border-radius: var(--radius-sm);
            border: 1px solid var(--border);
        }

        .toast {
            position: fixed;
            bottom: 2rem;
            right: 2rem;
            background: var(--success);
            color: white;
            padding: 0.9rem 1.4rem;
            border-radius: var(--radius-md);
            font-weight: 500;
            transform: translateY(20px);
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
            z-index: 2000;
            box-shadow: var(--shadow-lg);
        }

        .toast.show {
            transform: translateY(0);
            opacity: 1;
            visibility: visible;
        }

        .toast.error {
            background: var(--danger);
        }

        .brand-wordmark {
            background: linear-gradient(135deg, var(--accent) 20%, #8b5cf6 80%);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            font-weight: 800;
            letter-spacing: -0.02em;
        }

        .welcome-flash-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(5, 8, 16, 0.45);
            opacity: 0;
            visibility: hidden;
            z-index: 2999;
            pointer-events: none;
            transition: opacity 0.45s ease, visibility 0.45s;
        }

        [data-theme="light"] .welcome-flash-backdrop {
            background: rgba(15, 23, 42, 0.25);
        }

        .welcome-flash-backdrop.show {
            opacity: 1;
            visibility: visible;
            pointer-events: auto;
            cursor: pointer;
        }

        .welcome-flash {
            position: fixed;
            top: 2.25rem;
            left: 50%;
            transform: translateX(-50%) translateY(-16px) scale(0.92);
            opacity: 0;
            visibility: hidden;
            display: flex;
            align-items: center;
            gap: 1.25rem;
            background: var(--surface);
            border: 1px solid var(--border);
            border-top: 3px solid var(--accent);
            border-radius: var(--radius-lg);
            padding: 1.4rem 2.2rem;
            box-shadow: var(--shadow-lg), 0 0 0 1px rgba(79, 124, 255, 0.08);
            z-index: 3000;
            pointer-events: none;
            transition: opacity 0.45s cubic-bezier(.34,1.56,.64,1), transform 0.45s cubic-bezier(.34,1.56,.64,1), visibility 0.45s;
        }

        .welcome-flash.show {
            opacity: 1;
            visibility: visible;
            transform: translateX(-50%) translateY(0) scale(1);
            pointer-events: auto;
            cursor: pointer;
        }

        .welcome-flash img {
            width: 56px;
            height: 56px;
            border-radius: 14px;
            box-shadow: 0 6px 18px -4px rgba(79, 124, 255, 0.55);
        }

        .welcome-flash-eyebrow {
            font-size: 0.7rem;
            font-weight: 800;
            letter-spacing: 0.12em;
            margin-bottom: 0.2rem;
        }

        .welcome-flash-title {
            font-weight: 700;
            font-size: 1.35rem;
            letter-spacing: -0.01em;
            color: var(--text-primary);
        }

        .welcome-flash-sub {
            font-size: 0.9rem;
            color: var(--text-secondary);
            margin-top: 0.3rem;
        }

        .datatable-wrapper.no-footer .datatable-container {
            border-bottom: 1px solid var(--border);
        }

        .datatable-table {
            border-collapse: collapse;
        }

        .datatable-table th,
        .datatable-table td {
            padding: 0.9rem 1rem;
            border-bottom: 1px solid var(--border);
        }

        .datatable-table > tbody > tr > td,
        .datatable-table > tbody > tr > th {
            vertical-align: middle;
        }

        .datatable-table th {
            background: var(--surface-2);
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            color: var(--text-secondary);
        }

        .datatable-table tbody tr {
            transition: background 0.12s ease;
        }

        .datatable-table tbody tr:hover {
            background: var(--surface-2);
        }

        .datatable-input,
        .datatable-selector {
            background: var(--surface-2);
            border: 1px solid var(--border);
            color: var(--text-primary);
            padding: 0.5rem;
            border-radius: var(--radius-sm);
        }

        .datatable-info,
        .datatable-pagination a {
            color: var(--text-secondary);
        }

        .datatable-container {
            overflow-x: auto;
        }

        .mobile-menu-btn {
            display: none;
            position: fixed;
            top: 1rem;
            right: 1rem;
            z-index: 1200;
            width: 42px;
            height: 42px;
            align-items: center;
            justify-content: center;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            color: var(--text-primary);
            font-size: 1.1rem;
            cursor: pointer;
            box-shadow: var(--shadow-md);
        }

        .mobile-brand-icon {
            display: none;
            position: fixed;
            top: 1rem;
            left: 1rem;
            z-index: 1200;
            width: 42px;
            height: 42px;
            align-items: center;
            justify-content: center;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-md);
        }

        .mobile-brand-icon img {
            width: 26px;
            height: 26px;
        }

        body.sidebar-open .mobile-brand-icon {
            display: none !important;
        }

        .sidebar-backdrop {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(5, 8, 16, 0.6);
            z-index: 1290;
        }

        .sidebar-backdrop.active {
            display: block;
        }

        .mobile-bottom-nav {
            display: none;
            position: fixed;
            left: 0;
            right: 0;
            bottom: 0;
            z-index: 1200;
            background: var(--surface);
            border-top: 1px solid var(--border);
            padding-bottom: env(safe-area-inset-bottom, 0);
            box-shadow: var(--shadow-md);
        }

        .mobile-bottom-nav-item {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 0.2rem;
            padding: 0.5rem 0.25rem;
            background: none;
            border: none;
            color: var(--text-secondary);
            font-family: inherit;
            font-size: 0.65rem;
            font-weight: 600;
            cursor: pointer;
        }

        .mobile-bottom-nav-item i {
            font-size: 1.15rem;
        }

        .mobile-bottom-nav-item.active {
            color: var(--accent);
        }

        @media (max-width: 860px) {
            .mobile-menu-btn {
                display: flex;
            }

            .mobile-brand-icon {
                display: flex;
            }

            .sidebar {
                position: fixed;
                top: 0;
                right: -300px;
                height: 100vh;
                z-index: 1300;
                transition: right 0.25s ease;
                box-shadow: none;
                border-right: none;
                border-left: 1px solid var(--border);
            }

            .sidebar.open {
                right: 0;
                box-shadow: 0 0 48px rgba(0, 0, 0, 0.55);
            }

            .main {
                padding: 1.25rem 1rem 0;
                padding-top: 4.5rem;
                padding-bottom: calc(4.25rem + env(safe-area-inset-bottom, 0));
            }

            .mobile-bottom-nav {
                display: flex;
            }

            .mobile-grid {
                grid-template-columns: 1fr !important;
            }

            .modal {
                max-width: 94vw !important;
            }

            h2.page-title {
                flex-wrap: wrap;
                row-gap: 0.5rem;
            }

            .global-search-wrap kbd {
                display: none;
            }

            .nav-subnav-toggle {
                display: flex;
                align-items: center;
                justify-content: center;
            }

            .nav-subnav-slot.expanded {
                display: block;
                padding: 0.25rem 0.75rem 0.5rem;
            }

            .subnav-layout {
                flex-direction: column;
                align-items: stretch;
            }

            .subnav-layout>.subnav {
                display: none;
            }

            .subnav-content {
                width: 100%;
            }

            .nav-subnav-slot .subnav {
                width: 100%;
                position: static;
            }

            .nav-subnav-slot .subnav-item {
                font-size: 0.85rem;
                padding: 0.55rem 0.75rem;
            }

            .toolbar-toggle {
                display: flex;
            }

            .toolbar-collapsible {
                display: none;
                width: 100%;
                margin-top: 0.75rem;
                background: var(--surface);
                border: 1px solid var(--border);
                border-radius: 8px;
                padding: 0 0.9rem;
            }

            .toolbar-collapsible.expanded {
                display: block;
            }

            .toolbar-collapsible>div {
                width: 100%;
                flex-wrap: wrap;
                background: none !important;
                border: none !important;
                border-radius: 0 !important;
                padding: 0.85rem 0 !important;
                border-bottom: 1px solid var(--border);
            }

            .toolbar-collapsible>div:last-child {
                border-bottom: none;
            }

            .toolbar-collapsible select {
                flex: 1 1 auto;
                min-width: 0 !important;
            }
        }
    </style>
</head>

<body>

    <div class="mobile-brand-icon"><img src="assets/img/invoxa-mark.svg" alt="Invoxa"></div>
    <button type="button" class="mobile-menu-btn" onclick="toggleSidebar()" aria-label="Toggle menu"><i
            class="fa-solid fa-bars"></i></button>
    <div id="sidebarBackdrop" class="sidebar-backdrop" onclick="toggleSidebar()"></div>

    <nav class="mobile-bottom-nav">
        <button type="button" class="mobile-bottom-nav-item" data-target="dashboard" onclick="nav('dashboard', true)"><i
                class="fa-solid fa-chart-pie"></i><span>Dashboard</span></button>
        <button type="button" class="mobile-bottom-nav-item" data-target="invoices" onclick="nav('invoices', true)"><i
                class="fa-solid fa-file-lines"></i><span>Invoices</span></button>
        <button type="button" class="mobile-bottom-nav-item" data-target="billing" onclick="nav('billing', true); resetAdhocMode();"><i
                class="fa-solid fa-circle-plus"></i><span>Add Invoice</span></button>
        <button type="button" class="mobile-bottom-nav-item" data-target="clients" onclick="nav('clients', true)"><i
                class="fa-solid fa-users"></i><span>Clients</span></button>
    </nav>

    <div class="sidebar">
        <div class="sidebar-header">
            <h1 id="sidebarBrandName"><img src="assets/img/invoxa-mark.svg" width="36" height="36" alt="">
                <img src="assets/img/invoxa-wordmark.svg" height="30" alt="Invoxa" style="width:auto;"></h1>
        </div>
        <div class="nav-section-label">Main Menu</div>

        <div class="nav-item" data-target="dashboard" onclick="nav('dashboard', true)"><i
                class="fa-solid fa-chart-pie"></i>
            Dashboard</div>
        <div class="nav-item" data-target="invoices" onclick="nav('invoices', true)"><i
                class="fa-solid fa-file-lines"></i>
            Invoices <span id="navInvoiceCountBadge" class="badge" title="Total invoices"
                style="margin-left:auto; background:var(--surface-hover); color:var(--text-primary);"><?= $invoice_count ?></span><span
                id="navUnpaidCountBadge" class="badge" title="Unpaid invoices"
                style="margin-left:0.3rem; background:var(--warning); color:white; <?= $unpaid_count > 0 ? '' : 'display:none;' ?>"><?= $unpaid_count ?></span>
        </div>
        <div class="nav-item" data-target="billing" onclick="nav('billing', true); resetAdhocMode();"><i
                class="fa-solid fa-money-check-dollar"></i> Ad Hoc Invoice</div>
        <div class="nav-item" data-target="quotes" onclick="nav('quotes', true)"><i class="fa-solid fa-file-pen"></i>
            Quotes
            <span id="navQuoteCountBadge" class="badge" title="Total quotes"
                style="margin-left:auto; background:<?= $quote_count > 0 ? 'var(--accent)' : 'var(--surface-hover)' ?>; color:<?= $quote_count > 0 ? 'white' : 'var(--text-primary)' ?>;"><?= $quote_count ?></span>
        </div>
        <div class="nav-item" data-target="expenses" onclick="nav('expenses', true)"><i
                class="fa-solid fa-receipt"></i> Expenses
            <span id="navExpenseCountBadge" class="badge" title="Total expenses"
                style="margin-left:auto; background:var(--surface-hover); color:var(--text-primary);"><?= count($expenses) ?></span>
        </div>
        <div class="nav-item" data-target="clients" onclick="nav('clients', true)"><i class="fa-solid fa-users"></i>
            Clients
            <span id="navClientCountBadge" class="badge" title="Total clients"
                style="margin-left:auto; background:var(--surface-hover); color:var(--text-primary);"><?= $client_count ?></span>
        </div>

        <div class="mid-panel">
        </div>

        <div class="nav-section-label">Data &amp; Tools</div>

        <div class="nav-item tool-item" data-target="stats" onclick="nav('stats', true)"><i
                class="fa-solid fa-chart-line"></i> Statistics
            <?php if (!$licenseValid): ?><i class="fa-solid fa-lock" title="Requires a license"
                    style="margin-left:auto; color:var(--text-secondary); font-size:0.8rem;"></i><?php endif; ?>
            <button type="button" class="nav-subnav-toggle" onclick="event.stopPropagation(); toggleNavSubnav('stats')"
                aria-label="Expand Statistics menu"><i class="fa-solid fa-chevron-down"></i></button>
        </div>
        <div class="nav-subnav-slot" data-for="stats"></div>
        <div class="nav-item tool-item" data-target="sync" onclick="nav('sync', true)"><i
                class="fa-solid fa-rotate"></i> Sync <span class="badge" title="Files needing sync"
                style="margin-left:auto; background:<?= (count($missingFiles) + count($missingDiskData)) > 0 ? 'var(--warning)' : 'var(--surface-hover)' ?>; color:<?= (count($missingFiles) + count($missingDiskData)) > 0 ? 'white' : 'var(--text-primary)' ?>;"><?= count($missingFiles) + count($missingDiskData) ?></span>
        </div>
        <div class="nav-item tool-item" data-target="audit" onclick="nav('audit', true)"><i
                class="fa-solid fa-clock-rotate-left"></i>
            Audit Log</div>
        <?php if ($isAdmin): ?>
        <div class="nav-item tool-item" data-target="backup" onclick="nav('backup', true)"><i
                class="fa-solid fa-database"></i> Data Management
            <button type="button" class="nav-subnav-toggle" onclick="event.stopPropagation(); toggleNavSubnav('backup')"
                aria-label="Expand Data Management menu"><i class="fa-solid fa-chevron-down"></i></button>
        </div>
        <div class="nav-subnav-slot" data-for="backup"></div>
        <?php endif; ?>
        <div class="nav-item tool-item" data-target="docs" onclick="nav('docs', true)"><i class="fa-solid fa-book"></i> Docs
            <button type="button" class="nav-subnav-toggle" onclick="event.stopPropagation(); toggleNavSubnav('docs')"
                aria-label="Expand Docs menu"><i class="fa-solid fa-chevron-down"></i></button>
        </div>
        <div class="nav-subnav-slot" data-for="docs"></div>
        <div class="nav-item tool-item" data-target="settings" onclick="nav('settings', true)"><i
                class="fa-solid fa-gear"></i> Settings
            <?php if (!$licenseValid): ?><span class="badge" title="Not licensed — see License in Settings"
                    style="margin-left:auto; background:var(--warning); color:white;">!</span><?php endif; ?>
            <button type="button" class="nav-subnav-toggle" onclick="event.stopPropagation(); toggleNavSubnav('settings')"
                aria-label="Expand Settings menu"><i class="fa-solid fa-chevron-down"></i></button>
        </div>
        <div class="nav-subnav-slot" data-for="settings"></div>
        <div style="margin-top:1.25rem; border-top:1px solid var(--border);"></div>
        <div style="padding-top:2rem;">
            <div class="global-search-wrap">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" id="globalSearchInput" placeholder="Search"
                    autocomplete="off" oninput="handleGlobalSearch()" onkeydown="handleGlobalSearchKeydown(event)"
                    onfocus="if (document.getElementById('globalSearchResults').innerHTML.trim() !== '') document.getElementById('globalSearchResults').classList.add('active')">
                <kbd>Ctrl K</kbd>
                <div id="globalSearchResults" class="global-search-results"></div>
            </div>
        </div>
        <div class="user-panel">
            <form method="POST"><input type="hidden" name="auth_action" value="logout"><button type="submit"
                    class="logout-btn"><i class="fa-solid fa-right-from-bracket"></i> Logout</button></form>
            <div style="display:flex; align-items:center; justify-content:center; gap:0.6rem; margin-top:1rem; font-size:0.75rem; color:var(--text-secondary);">
                <span style="cursor:pointer;" title="View changelog" onclick="nav('docs', true); navDocs('changelog');">
                    <span class="brand-wordmark">Invoxa</span> v<?= htmlspecialchars(APP_VERSION) ?></span>
                <a href="https://gitlab.com/weblabnz/invoxa" target="_blank" title="Source on GitLab"
                    style="color:var(--text-secondary);"><i class="fa-brands fa-gitlab"></i></a>
            </div>
        </div>
    </div>

    <div class="main">

        <!-- DASHBOARD -->
        <div id="sec-dashboard" class="section">
            <h2 class="page-title">Dashboard
                <div style="color:var(--text-secondary); font-size:0.9rem; font-weight:400;">
                    <i class="fa-solid fa-clock-rotate-left" style="margin-right:0.25rem;"></i>Next Auto-Run: <span
                        id="nextCronRunDashboard" style="color:var(--accent); font-weight:600;">Loading...</span>
                </div>
            </h2>
            <div class="section-scroll">
                <div id="dashboardStatsWrap">
                    <?= renderDashboardStats($settings, $failedInvoices, $overdueInvoices, $total_invoiced_by_ccy, $total_monthly_by_ccy, $total_paid_by_ccy, (int) $client_count) ?>
                </div>
                <div class="charts-grid">
                    <div class="card" style="margin-bottom:0;">
                        <div class="card-header">
                            <h3 style="margin:0; font-size:1rem;"><i class="fa-solid fa-chart-line"
                                    style="color:var(--accent); margin-right:0.5rem;"></i>Revenue Over Time (Cumulative)
                            </h3>
                            <div style="display:flex; gap:0.5rem; align-items:center;">
                                <button id="chartRange12" class="btn small primary" onclick="setChartRange('12')">Last 12
                                    Months</button>
                                <button id="chartRangeAll" class="btn small" onclick="setChartRange('all')">All
                                    Time</button>
                            </div>
                        </div>
                        <div class="card-body" style="padding:1rem;">
                            <div style="height:420px; position:relative;"><canvas id="revenueChart"></canvas></div>
                        </div>
                    </div>
                    <div class="card" style="margin-bottom:0; display:flex; flex-direction:column; height:100%;">
                        <div class="card-header">
                            <h3 style="margin:0; font-size:1rem;"><i class="fa-solid fa-chart-pie"
                                    style="color:var(--accent); margin-right:0.5rem;"></i>Client Share (All Time)</h3>
                        </div>
                        <div class="card-body"
                            style="padding:1rem; flex:1; display:flex; align-items:center; justify-content:center;">
                            <div style="height:320px; width:100%; position:relative;"><canvas id="pieChart"></canvas></div>
                        </div>
                    </div>
                </div>

                <div class="card" style="margin-bottom:2rem;">
                    <div class="card-header">
                        <h3 style="margin:0; font-size:1rem;"><i class="fa-solid fa-clock-rotate-left"
                                style="color:var(--accent); margin-right:0.5rem;"></i>Recent Activity</h3>
                    </div>
                    <div class="card-body" style="padding:0;">
                        <table class="datatable-table" style="width: 100%; border: none;">
                            <thead>
                                <tr>
                                    <th style="text-align:left;">Time</th>
                                    <th style="text-align:left;">Action</th>
                                    <th style="text-align:left;">Client</th>
                                </tr>
                            </thead>
                            <tbody id="activityTbody">
                                <?= renderActivityRows($actions) ?>
                            </tbody>
                        </table>
                        <div style="padding: 1rem; text-align: center; border-top: 1px solid var(--border);">
                            <button class="btn small" onclick="nav('audit')">View Full Audit Log</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- INVOICES -->
        <div id="sec-invoices" class="section">
            <h2 class="page-title">Invoices</h2>
            <!-- A sibling of .section-scroll, not a child inside it — stays fixed
                 while the table below scrolls, same reasoning as h2.page-title and
                 the Audit Log toolbar. -->
            <!-- Invoice toolbar: two separate action groups -->
            <div style="display: flex; flex-wrap: wrap; gap: 0.75rem; align-items: stretch; margin-bottom: 1.5rem;">

                <button type="button" class="toolbar-toggle" id="invoicesToolbarToggle" onclick="toggleToolbar('invoices')">
                    <span><i class="fa-solid fa-sliders"></i> Filters &amp; Export</span>
                    <i class="fa-solid fa-chevron-down toolbar-toggle-chevron"></i>
                </button>
                <div class="toolbar-collapsible" id="invoicesToolbarGroups">

                <!-- Group 1: Exports -->
                <div
                    style="display: flex; flex-direction: row; align-items: center; gap: 0.75rem; background: var(--surface); border: 1px solid var(--border); border-radius: 8px; padding: 0.5rem 0.9rem;">
                    <span
                        style="font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.06em; color: var(--text-secondary); font-weight: 600; white-space: nowrap; padding-right: 0.75rem; border-right: 1px solid var(--border);"><i
                            class="fa-solid fa-file-export" style="margin-right:0.3rem;"></i>Export</span>
                    <select id="invoiceExportType"
                        style="padding: 0.45rem 0.65rem; background: var(--surface-2); border: 1px solid var(--border); border-radius: 6px; color: var(--text-primary); font-family: inherit; font-size: 0.85rem; min-width: 190px;">
                        <option value="invoices" title="Export all invoices as CSV">All Invoices (CSV)</option>
                        <option value="invoices_pdf" title="Download a PDF of every invoice, zipped into one file">All
                            Invoices (PDF)</option>
                        <option value="tax_year"
                            title="Preview and export all invoices for the current tax year, ordered by date. Limited to the instance default currency (Settings > General) — invoices in another currency are excluded.">Tax
                            Year Invoices</option>
                        <option value="tax_year_monthly"
                            title="Preview and export a monthly summary for the current tax year, showing paid/partial paid status. Amounts are in the instance default currency (Settings > General) — invoices in another currency are excluded.">
                            Monthly Summary</option>
                        <option value="accounting_journal"
                            title="Double-entry General Journal (invoices, payments, expenses) for the current tax year, as a plain CSV any bookkeeping tool can import. Only includes invoices in the instance default currency (Settings > General) — a ledger can't mix currencies in one balance.">
                            Accounting Journal (CSV)</option>
                        <option value="accounting_iif"
                            title="Same General Journal as an .iif file for QuickBooks Desktop's File > Utilities > Import > IIF Files. Only includes invoices in the instance default currency (Settings > General).">
                            QuickBooks (IIF)</option>
                    </select>
                    <button class="btn" style="background: var(--surface-hover); white-space: nowrap;"
                        onclick="runInvoiceExport()"><i class="fa-solid fa-download"></i> Export</button>
                </div>

                <!-- Group 2: Status Filter -->
                <div
                    style="display: flex; flex-direction: row; align-items: center; gap: 0.75rem; background: var(--surface); border: 1px solid var(--border); border-radius: 8px; padding: 0.5rem 0.9rem;">
                    <span
                        style="font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.06em; color: var(--text-secondary); font-weight: 600; white-space: nowrap; padding-right: 0.75rem; border-right: 1px solid var(--border);"><i
                            class="fa-solid fa-filter" style="margin-right:0.3rem;"></i>Filter</span>
                    <select id="invoiceStatusFilter" onchange="filterInvoicesByStatus(this.value)"
                        style="padding: 0.45rem 0.65rem; background: var(--surface-2); border: 1px solid var(--border); border-radius: 6px; color: var(--text-primary); font-family: inherit; font-size: 0.85rem; min-width: 150px;">
                        <option value="">All Statuses</option>
                        <option value="overdue">Overdue</option>
                        <option value="sent">Sent</option>
                        <option value="pending">Pending</option>
                        <option value="partial">Partial</option>
                        <option value="paid">Paid</option>
                        <option value="failed">Failed</option>
                        <option value="void">Void</option>
                    </select>
                </div>

                <!-- Group 3: Saved Views -->
                <div
                    style="display: flex; flex-direction: row; align-items: center; gap: 0.75rem; background: var(--surface); border: 1px solid var(--border); border-radius: 8px; padding: 0.5rem 0.9rem;">
                    <span
                        style="font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.06em; color: var(--text-secondary); font-weight: 600; white-space: nowrap; padding-right: 0.75rem; border-right: 1px solid var(--border);"><i
                            class="fa-solid fa-bookmark" style="margin-right:0.3rem;"></i>Views</span>
                    <select id="invoicesViewSelect" onchange="applyFilterView('invoices', this.value)"
                        style="padding: 0.45rem 0.65rem; background: var(--surface-2); border: 1px solid var(--border); border-radius: 6px; color: var(--text-primary); font-family: inherit; font-size: 0.85rem; min-width: 150px;">
                        <option value="">Saved Views…</option>
                    </select>
                    <button type="button" class="btn small" title="Save the current search/filter as a view"
                        onclick="saveFilterView('invoices')"><i class="fa-solid fa-plus"></i></button>
                    <button type="button" class="btn small" title="Delete the selected view"
                        onclick="deleteFilterView('invoices')"><i class="fa-solid fa-trash"></i></button>
                </div>

                </div>
            </div>

            <!-- Bulk Actions — hidden until at least one row is checked; a sibling
                 of the toolbar above (not one of its flex items) so it always
                 falls on its own row, sized to its content rather than the full
                 row width. -->
            <div id="invoiceBulkBar" style="display:none; flex-direction: row; align-items: center; gap: 0.75rem; background: var(--accent-soft); border: 1px solid var(--accent); border-radius: 8px; padding: 0.5rem 0.9rem; width: fit-content; margin-bottom: 1.5rem;">
                <span id="invoiceBulkCount" style="font-size: 0.85rem; font-weight: 600; color: var(--accent); white-space: nowrap;"></span>
                <button type="button" class="btn small success" onclick="bulkMarkPaidInvoices()"><i class="fa-solid fa-check"></i> Mark Paid</button>
                <button type="button" class="btn small" onclick="bulkResendInvoiceEmails()"><i class="fa-solid fa-paper-plane"></i> Resend</button>
                <button type="button" class="btn small" onclick="bulkExportInvoicesCsv()"><i class="fa-solid fa-file-csv"></i> Export CSV</button>
                <button type="button" class="btn small danger" onclick="bulkDeleteInvoices()"><i class="fa-solid fa-trash"></i> Delete</button>
            </div>

            <div class="section-scroll">
            <div class="card">
                <table id="invoicesTable">
                    <thead>
                        <tr>
                            <th data-sortable="false" style="width:32px;"><input type="checkbox" id="invoicesSelectAll" onchange="toggleSelectAllInvoices(this)"></th>
                            <th style="width:110px;">Invoice #</th>
                            <th>Date</th>
                            <th>Due Date</th>
                            <th>Client</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th style="width:180px;">File</th>
                            <th data-sortable="false">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="invoicesTbody">
                        <?= renderInvoiceRows($invoices) ?>
                    </tbody>
                </table>
            </div>
            </div>
        </div>

        <!-- AD HOC INVOICE -->
        <div id="sec-billing" class="section">
            <h2 class="page-title" id="billingPageTitle">Ad Hoc Invoice</h2>
            <div class="section-scroll">
            <div class="card" style="max-width: 900px;">
                <div class="card-header">
                    <h3 style="margin:0; font-size: 1.1rem;" id="billingCardTitle">Create Adhoc Invoice (One-Off)</h3>
                </div>
                <div class="card-body">
                    <input type="hidden" id="isQuoteFlag" value="0">
                    <div class="form-group">
                        <label class="form-label">Client</label>
                        <select id="adhocClient" class="form-control" onchange="updateAdhocClientInfo()">
                            <option value="">-- Select Client --</option>
                            <?php foreach ($clients as $c): ?>
                                <option value="<?= $c['id'] ?>"
                                    data-outstanding="<?= round(max(0, ($c['total_billed'] ?? 0) - ($c['total_paid'] ?? 0)), 2) ?>"
                                    data-terms="<?= (int) ($c['payment_terms_days'] ?? 21) ?>"
                                    data-currency="<?= htmlspecialchars(invoxaResolveCurrency($c['currency'] ?? '', $settings)) ?>"><?= htmlspecialchars($c['client_name']) ?>
                                    (<?= htmlspecialchars($c['email']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                        <div id="adhocClientBalance" style="display:none; margin-top:0.4rem; font-size:0.8rem; color:var(--warning);"></div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Line Items</label>
                        <table style="width:100%; border-collapse:collapse; margin-bottom:0.5rem;">
                            <thead>
                                <tr style="font-size:0.8rem; color:var(--text-secondary);">
                                    <th style="padding:0 0.5rem 0.4rem 0; width:110px; text-align:left;">Code</th>
                                    <th style="padding:0 0.5rem 0.4rem 0; text-align:left;">Description</th>
                                    <th style="padding:0 0.5rem 0.4rem 0; width:110px; text-align:right;">Amount (<span id="adhocAmountCcy"><?= htmlspecialchars($settings['currency'] ?? 'USD') ?></span>)
                                    </th>
                                    <th style="width:32px;"></th>
                                </tr>
                            </thead>
                            <tbody id="lineItemsBody">
                                <tr class="line-item-row">
                                    <td style="padding:0 0.5rem 0.5rem 0;"><input type="text"
                                            class="form-control li-code" placeholder="WEB01" style="font-size:0.85rem;">
                                    </td>
                                    <td style="padding:0 0.5rem 0.5rem 0;"><input type="text"
                                            class="form-control li-desc" placeholder="e.g. Website setup fee"
                                            style="font-size:0.85rem;"></td>
                                    <td style="padding:0 0.5rem 0.5rem 0;"><input type="number"
                                            class="form-control li-amount" step="0.01" placeholder="0.00"
                                            style="font-size:0.85rem; text-align:right;"></td>
                                    <td style="padding:0 0 0.5rem 0;"></td>
                                </tr>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td colspan="2" style="text-align:right; padding:0.5rem 0.5rem 0 0; font-size:0.85rem; color:var(--text-secondary);">Subtotal</td>
                                    <td id="adhocSubtotal" style="text-align:right; padding:0.5rem 0.5rem 0 0;">0.00</td>
                                    <td></td>
                                </tr>
                                <tr>
                                    <td colspan="2" style="text-align:right; padding:0.5rem 0.5rem 0 0; font-size:0.85rem; color:var(--text-secondary);">Discount
                                        <input type="number" id="adhocDiscountPct" class="form-control" value="0" step="0.01" min="0" max="100"
                                            style="display:inline-block; width:60px; font-size:0.8rem; padding:0.2rem 0.4rem;"> %</td>
                                    <td id="adhocDiscountAmt" style="text-align:right; padding:0.5rem 0.5rem 0 0;">0.00</td>
                                    <td></td>
                                </tr>
                                <tr>
                                    <td colspan="2" style="text-align:right; padding:0.5rem 0.5rem 0 0; font-size:0.85rem; color:var(--text-secondary);">Tax
                                        <input type="number" id="adhocTaxRate" class="form-control" value="0" step="0.01" min="0" max="100"
                                            style="display:inline-block; width:60px; font-size:0.8rem; padding:0.2rem 0.4rem;"> %</td>
                                    <td id="adhocTaxAmt" style="text-align:right; padding:0.5rem 0.5rem 0 0;">0.00</td>
                                    <td></td>
                                </tr>
                                <tr>
                                    <td colspan="2" style="text-align:right; padding:0.5rem 0.5rem 0 0; font-size:0.85rem; color:var(--text-secondary);">Total</td>
                                    <td id="adhocRunningTotal" style="text-align:right; padding:0.5rem 0.5rem 0 0; font-weight:600;">0.00</td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                        <button type="button" class="btn small" onclick="addLineItem()" style="font-size:0.8rem;"><i
                                class="fa-solid fa-plus"></i> Add Row</button>
                    </div>
                    <div style="display:flex; gap:1rem; flex-wrap:wrap;">
                        <div class="form-group" style="flex:1; min-width:180px;">
                            <label class="form-label">Due Date <span style="font-weight:400; color:var(--text-secondary);">(optional)</span></label>
                            <input type="date" id="adhocDueDate" class="form-control">
                            <div id="adhocDueDateHint" style="margin-top:0.3rem; font-size:0.75rem; color:var(--text-secondary);"></div>
                        </div>
                        <div class="form-group" id="adhocQuoteExpiryGroup" style="display:none; flex:1; min-width:180px;">
                            <label class="form-label">Quote Expires <span style="font-weight:400; color:var(--text-secondary);">(optional)</span></label>
                            <input type="date" id="adhocQuoteExpiry" class="form-control">
                            <div style="margin-top:0.3rem; font-size:0.75rem; color:var(--text-secondary);">Shown to the client; leave blank for no expiry.</div>
                        </div>
                        <div class="form-group" style="flex:2; min-width:240px;">
                            <label class="form-label">Internal Note <span style="font-weight:400; color:var(--text-secondary);">(optional, not shown to client)</span></label>
                            <textarea id="adhocMemo" class="form-control" rows="1" placeholder="e.g. Approved by Jane on the phone"></textarea>
                        </div>
                    </div>
                    <div
                        style="display:flex; gap:0.75rem; flex-wrap:wrap; justify-content:flex-end; margin-top:1.75rem; padding-top:1.5rem; border-top:1px solid var(--border);">
                        <button class="btn" id="previewAdhocBtn" onclick="previewAdhocInvoice()"
                            style="padding:0.7rem 1.3rem;"><i class="fa-solid fa-eye"></i> Preview</button>
                        <button class="btn" id="saveQuoteBtn" onclick="sendAdhocInvoice(true)"
                            style="padding:0.7rem 1.3rem; background:rgba(139,92,246,0.2); border-color:rgba(139,92,246,0.4); color:#a78bfa;"><i
                                class="fa-solid fa-file-pen"></i> Save as Quote</button>
                        <button class="btn primary" id="sendAdhocBtn" onclick="sendAdhocInvoice(false)"
                            style="padding:0.7rem 1.5rem;"><i class="fa-solid fa-paper-plane"></i> Generate &amp;
                            Send</button>
                    </div>
                </div>
            </div>
            </div>
        </div>

        <!-- CLIENTS -->
        <div id="sec-clients" class="section">
            <h2 class="page-title">Clients</h2>
            <!-- Client toolbar: same group layout as the Invoices toolbar (a
                 sibling of .section-scroll, not a child inside it — stays fixed
                 while the table below scrolls). -->
            <div style="display: flex; flex-wrap: wrap; gap: 0.75rem; align-items: stretch; margin-bottom: 1.5rem;">

                <button type="button" class="toolbar-toggle" id="clientsToolbarToggle" onclick="toggleToolbar('clients')">
                    <span><i class="fa-solid fa-sliders"></i> Filters &amp; Export</span>
                    <i class="fa-solid fa-chevron-down toolbar-toggle-chevron"></i>
                </button>
                <div class="toolbar-collapsible" id="clientsToolbarGroups">

                <!-- Group 1: Export / Import -->
                <div
                    style="display: flex; flex-direction: row; align-items: center; gap: 0.75rem; background: var(--surface); border: 1px solid var(--border); border-radius: 8px; padding: 0.5rem 0.9rem;">
                    <span
                        style="font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.06em; color: var(--text-secondary); font-weight: 600; white-space: nowrap; padding-right: 0.75rem; border-right: 1px solid var(--border);"><i
                            class="fa-solid fa-file-export" style="margin-right:0.3rem;"></i>Export</span>
                    <button class="btn" style="background: var(--surface-hover); white-space: nowrap;"
                        onclick="window.location.href='?export=clients'"><i class="fa-solid fa-file-csv"></i> CSV</button>
                    <label class="btn" style="background: var(--surface-hover); cursor:pointer; margin:0; white-space: nowrap;"
                        title="CSV with a header row: Client Name, Email, Rate, Billing Frequency, Account Name, Account Number, Payment Terms Days, Phone, Address (Phone/Address are optional)">
                        <i class="fa-solid fa-file-import"></i> Import
                        <input type="file" id="importClientsFile" accept=".csv" style="display:none;"
                            onchange="importClientsCsv(this.files[0])"></label>
                </div>

                <!-- Group 2: Saved Views -->
                <div
                    style="display: flex; flex-direction: row; align-items: center; gap: 0.75rem; background: var(--surface); border: 1px solid var(--border); border-radius: 8px; padding: 0.5rem 0.9rem;">
                    <span
                        style="font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.06em; color: var(--text-secondary); font-weight: 600; white-space: nowrap; padding-right: 0.75rem; border-right: 1px solid var(--border);"><i
                            class="fa-solid fa-bookmark" style="margin-right:0.3rem;"></i>Views</span>
                    <select id="clientsViewSelect" onchange="applyFilterView('clients', this.value)"
                        style="padding: 0.45rem 0.65rem; background: var(--surface-2); border: 1px solid var(--border); border-radius: 6px; color: var(--text-primary); font-family: inherit; font-size: 0.85rem; min-width: 150px;">
                        <option value="">Saved Views…</option>
                    </select>
                    <button type="button" class="btn small" title="Save the current search as a view"
                        onclick="saveFilterView('clients')"><i class="fa-solid fa-plus"></i></button>
                    <button type="button" class="btn small" title="Delete the selected view"
                        onclick="deleteFilterView('clients')"><i class="fa-solid fa-trash"></i></button>
                </div>

                </div>

                <button class="btn primary" onclick="openClientModal()"><i class="fa-solid fa-plus"></i> Add
                    Client</button>

            </div>

            <!-- Bulk Actions — hidden until at least one row is checked; a sibling
                 of the toolbar above (not one of its flex items) so it always
                 falls on its own row, sized to its content rather than the full
                 row width. -->
            <div id="clientBulkBar" style="display:none; flex-direction: row; align-items: center; gap: 0.75rem; background: var(--accent-soft); border: 1px solid var(--accent); border-radius: 8px; padding: 0.5rem 0.9rem; width: fit-content; margin-bottom: 1.5rem;">
                <span id="clientBulkCount" style="font-size: 0.85rem; font-weight: 600; color: var(--accent); white-space: nowrap;"></span>
                <button type="button" class="btn small success" onclick="bulkSetClientFlag('is_active', 1, 'Marked active')"><i class="fa-solid fa-circle-check"></i> Active</button>
                <button type="button" class="btn small" onclick="bulkSetClientFlag('is_active', 0, 'Marked inactive')"><i class="fa-solid fa-circle-xmark"></i> Inactive</button>
                <button type="button" class="btn small" onclick="bulkSetClientFlag('is_test', 1, 'Marked as test')"><i class="fa-solid fa-flask"></i> Test</button>
                <button type="button" class="btn small" onclick="bulkSetClientFlag('is_test', 0, 'Unmarked as test')"><i class="fa-solid fa-flask-vial"></i> Unmark Test</button>
                <button type="button" class="btn small danger" onclick="bulkDeleteClients()"><i class="fa-solid fa-trash"></i> Delete</button>
            </div>

            <div class="section-scroll">
            <div class="card">
                <table id="clientsTable">
                    <thead>
                        <tr>
                            <th data-sortable="false" style="width:32px;"><input type="checkbox" id="clientsSelectAll" onchange="toggleSelectAllClients(this)"></th>
                            <th>Client Name</th>
                            <th>Email</th>
                            <th>Rate</th>
                            <th style="text-align:center;">Status</th>
                            <th>Invoices</th>
                            <th>Total Billed</th>
                            <th>Total Paid</th>
                            <th>Outstanding</th>
                            <th data-sortable="false">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="clientsTbody">
                        <?= renderClientRows($clients) ?>
                    </tbody>
                </table>
            </div>
            </div>
        </div>

        <!-- EXPENSES -->
        <div id="sec-expenses" class="section">
            <h2 class="page-title">Expenses</h2>
            <!-- Expense toolbar: same group layout as the Invoices/Clients toolbar. -->
            <div style="display: flex; flex-wrap: wrap; gap: 0.75rem; align-items: stretch; margin-bottom: 1.5rem;">

                <button type="button" class="toolbar-toggle" id="expensesToolbarToggle" onclick="toggleToolbar('expenses')">
                    <span><i class="fa-solid fa-sliders"></i> Filters &amp; Export</span>
                    <i class="fa-solid fa-chevron-down toolbar-toggle-chevron"></i>
                </button>
                <div class="toolbar-collapsible" id="expensesToolbarGroups">

                <!-- Group 1: Export -->
                <div
                    style="display: flex; flex-direction: row; align-items: center; gap: 0.75rem; background: var(--surface); border: 1px solid var(--border); border-radius: 8px; padding: 0.5rem 0.9rem;">
                    <span
                        style="font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.06em; color: var(--text-secondary); font-weight: 600; white-space: nowrap; padding-right: 0.75rem; border-right: 1px solid var(--border);"><i
                            class="fa-solid fa-file-export" style="margin-right:0.3rem;"></i>Export</span>
                    <button class="btn" style="background: var(--surface-hover); white-space: nowrap;"
                        onclick="window.location.href='?export=expenses'"><i class="fa-solid fa-file-csv"></i> CSV</button>
                </div>

                <!-- Total Expenses stat -->
                <div
                    style="display: flex; flex-direction: row; align-items: center; gap: 0.75rem; background: var(--surface); border: 1px solid var(--border); border-radius: 8px; padding: 0.5rem 0.9rem;">
                    <span
                        style="font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.06em; color: var(--text-secondary); font-weight: 600; white-space: nowrap; padding-right: 0.75rem; border-right: 1px solid var(--border);">Total
                        Expenses</span>
                    <span style="font-size:1.1rem; font-weight:700; color:var(--danger); white-space: nowrap;">
                        <?= htmlspecialchars($settings['currency'] ?? 'USD') ?> $<?= number_format($total_expenses, 2) ?>
                    </span>
                </div>

                </div>

                <button class="btn primary" onclick="openExpenseModal()"><i class="fa-solid fa-plus"></i> Add
                    Expense</button>

            </div>

            <!-- Bulk Actions — hidden until at least one row is checked; a sibling
                 of the toolbar above (not one of its flex items) so it always
                 falls on its own row, sized to its content rather than the full
                 row width. -->
            <div id="expenseBulkBar" style="display:none; flex-direction: row; align-items: center; gap: 0.75rem; background: var(--accent-soft); border: 1px solid var(--accent); border-radius: 8px; padding: 0.5rem 0.9rem; width: fit-content; margin-bottom: 1.5rem;">
                <span id="expenseBulkCount" style="font-size: 0.85rem; font-weight: 600; color: var(--accent); white-space: nowrap;"></span>
                <button type="button" class="btn small" onclick="bulkExportExpensesCsv()"><i class="fa-solid fa-file-csv"></i> Export CSV</button>
                <button type="button" class="btn small danger" onclick="bulkDeleteExpenses()"><i class="fa-solid fa-trash"></i> Delete</button>
            </div>

            <div class="section-scroll">
            <div class="card">
                <div class="card-header">
                    <h3 style="margin:0; font-size:1rem;"><i class="fa-solid fa-rotate" style="color:var(--accent); margin-right:0.5rem;"></i>Recurring Expenses
                        <?php if (!$licenseValid): ?><i class="fa-solid fa-lock" title="Requires a license" style="margin-left:0.5rem; color:var(--text-secondary); font-size:0.8rem;"></i><?php endif; ?>
                    </h3>
                    <button class="btn small primary" <?= $licenseValid ? '' : 'disabled title="Requires a license"' ?>
                        onclick="openRecurringExpenseModal()"><i class="fa-solid fa-plus"></i> Add Recurring Expense</button>
                </div>
                <div class="card-body" style="padding:0;">
                    <table id="recurringExpensesTable" class="datatable-table">
                        <thead>
                            <tr>
                                <th>Vendor</th>
                                <th>Category</th>
                                <th>Amount</th>
                                <th>Frequency</th>
                                <th>Status</th>
                                <th data-sortable="false">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="recurringExpensesTbody">
                            <?php if (empty($recurringExpenses)): ?>
                                <tr>
                                    <td colspan="6" class="empty-state"><i class="fa-solid fa-rotate"></i>No recurring expenses set up yet — add one for a bill that repeats on its own schedule (hosting, SaaS subscriptions, etc.) instead of re-entering it every period.</td>
                                </tr>
                            <?php else: ?>
                                <?= renderRecurringExpenseRows($recurringExpenses, $licenseValid) ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="card">
                <table id="expensesTable">
                    <thead>
                        <tr>
                            <th data-sortable="false" style="width:32px;"><input type="checkbox" id="expensesSelectAll" onchange="toggleSelectAllExpenses(this)"></th>
                            <th>Date</th>
                            <th>Vendor</th>
                            <th>Category</th>
                            <th>Amount</th>
                            <th>Description</th>
                            <th data-sortable="false">Receipt</th>
                            <th data-sortable="false">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="expensesTbody">
                        <?= renderExpenseRows($expenses) ?>
                    </tbody>
                </table>
            </div>
            </div>
        </div>

        <!-- QUOTES -->
        <div id="sec-quotes" class="section">
            <!-- The icon+label are wrapped in one span so they're a single flex
                 item — h2.page-title's justify-content: space-between would
                 otherwise treat the icon and the text as two separate items and
                 push them apart from each other. -->
            <h2 class="page-title"><span><i class="fa-solid fa-file-pen"
                        style="color:var(--accent); margin-right:0.5rem;"></i>Quotes &amp; Estimates</span></h2>
            <!-- Quote toolbar: same group layout as the Invoices/Clients/Expenses toolbar. -->
            <div style="display: flex; flex-wrap: wrap; gap: 0.75rem; align-items: stretch; margin-bottom: 1.5rem;">

                <button type="button" class="toolbar-toggle" id="quotesToolbarToggle" onclick="toggleToolbar('quotes')">
                    <span><i class="fa-solid fa-sliders"></i> Filters &amp; Export</span>
                    <i class="fa-solid fa-chevron-down toolbar-toggle-chevron"></i>
                </button>
                <div class="toolbar-collapsible" id="quotesToolbarGroups">

                <!-- Group 1: Export -->
                <div
                    style="display: flex; flex-direction: row; align-items: center; gap: 0.75rem; background: var(--surface); border: 1px solid var(--border); border-radius: 8px; padding: 0.5rem 0.9rem;">
                    <span
                        style="font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.06em; color: var(--text-secondary); font-weight: 600; white-space: nowrap; padding-right: 0.75rem; border-right: 1px solid var(--border);"><i
                            class="fa-solid fa-file-export" style="margin-right:0.3rem;"></i>Export</span>
                    <button class="btn" style="background: var(--surface-hover); white-space: nowrap;"
                        onclick="window.location.href='?export=quotes'"><i class="fa-solid fa-file-csv"></i> CSV</button>
                </div>

                </div>

                <button class="btn primary" onclick="openQuoteModal()"><i class="fa-solid fa-plus"></i> New
                    Quote</button>

            </div>

            <!-- Bulk Actions — hidden until at least one row is checked; a sibling
                 of the toolbar above (not one of its flex items) so it always
                 falls on its own row, sized to its content rather than the full
                 row width. -->
            <div id="quoteBulkBar" style="display:none; flex-direction: row; align-items: center; gap: 0.75rem; background: var(--accent-soft); border: 1px solid var(--accent); border-radius: 8px; padding: 0.5rem 0.9rem; width: fit-content; margin-bottom: 1.5rem;">
                <span id="quoteBulkCount" style="font-size: 0.85rem; font-weight: 600; color: var(--accent); white-space: nowrap;"></span>
                <button type="button" class="btn small success" onclick="bulkConvertQuotes()"><i class="fa-solid fa-file-invoice"></i> Convert to Invoice</button>
                <button type="button" class="btn small" onclick="bulkExportQuotesCsv()"><i class="fa-solid fa-file-csv"></i> Export CSV</button>
                <button type="button" class="btn small danger" onclick="bulkDeleteQuotes()"><i class="fa-solid fa-trash"></i> Delete</button>
            </div>

            <div class="section-scroll">
            <div class="card">
                <div class="card-body" style="padding:0;">
                    <table id="quotesTable">
                        <thead>
                            <tr>
                                <th data-sortable="false" style="width:32px;"><input type="checkbox" id="quotesSelectAll" onchange="toggleSelectAllQuotes(this)"></th>
                                <th>Quote #</th>
                                <th>Client</th>
                                <th>Date</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th>Expires</th>
                                <th data-sortable="false">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="quotesTbody">
                            <?php
                            $qRes = $mysqli->query("SELECT * FROM invoxa_invoices WHERE is_quote = 1 ORDER BY invoice_date DESC");
                            echo renderQuoteRows($qRes);
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
            </div>
        </div>

        <!-- STATS -->
        <div id="sec-stats" class="section">
            <?= renderStatsSection() ?>
        </div>

        <!-- AUDIT LOG -->
        <div id="sec-audit" class="section">
            <?= renderAuditSection($actions) ?>
        </div>

        <!-- SYNC -->
        <div id="sec-sync" class="section">
            <?= renderSyncSection($missingFiles, $knownClientFolders, $missingDiskData) ?>
        </div>

        <!-- DOCS -->
        <div id="sec-docs" class="section">
            <h2 class="page-title">Documentation</h2>
            <div class="section-scroll">
            <div class="subnav-layout">

                <?php
                // Two-level nav (category > page), plus a client-side search box that
                // filters by title and each page's rendered text (see filterDocsNav()
                // below) — every page's content is already in the DOM, just hidden.
                $__docCategories = [
                    'Getting Started' => ['readme' => 'Quick Start', 'install' => 'Installation Guide'],
                    'Features' => [
                        'overview' => 'Overview',
                        'feat-invoicing' => 'Invoicing & Quotes',
                        'feat-recurring' => 'Recurring Billing',
                        'feat-payments' => 'Payments',
                        'feat-clients' => 'Clients & Portal',
                        'feat-security' => 'Security',
                        'feat-api' => 'External API',
                        'feat-reporting' => 'Reporting',
                        'feat-data' => 'Data Management',
                        'feat-notifications' => 'Notifications',
                    ],
                    'Reference' => ['roadmap' => 'Roadmap', 'changelog' => 'Changelog', 'license' => 'License (AGPL-3.0)', 'source' => 'Source Code'],
                ];
                ?>
                <nav class="subnav" id="docsNav" style="min-width:220px;">
                    <div style="padding:0 0.25rem 0.75rem;">
                        <input type="text" id="docsSearchInput" class="form-control" placeholder="Search docs…"
                            oninput="filterDocsNav()" style="font-size:0.85rem;">
                    </div>
                    <?php foreach ($__docCategories as $__catName => $__catPages): ?>
                        <div class="docs-nav-category" data-category="<?= htmlspecialchars($__catName) ?>">
                            <div style="padding:0.5rem 0.75rem 0.25rem; font-size:0.72rem; font-weight:700; text-transform:uppercase; letter-spacing:0.05em; color:var(--text-secondary);">
                                <?= htmlspecialchars($__catName) ?></div>
                            <?php foreach ($__catPages as $__pageId => $__pageTitle): ?>
                                <button type="button" class="subnav-item docs-nav-page<?= $__pageId === 'readme' ? ' active' : '' ?>"
                                    data-docs-target="<?= htmlspecialchars($__pageId) ?>" data-title="<?= htmlspecialchars(strtolower($__pageTitle)) ?>"
                                    onclick="navDocs('<?= htmlspecialchars($__pageId) ?>')" style="padding-left:1.5rem;"><?= htmlspecialchars($__pageTitle) ?></button>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                    <div id="docsNoResults" style="display:none; padding:0.5rem 1rem; color:var(--text-secondary); font-size:0.85rem;">
                        No matching pages.</div>
                </nav>

                <div class="subnav-content">
                    <div class="subnav-pane active" id="docs-pane-readme">
                        <div class="card">
                            <div class="card-body doc-content">
                                <?php
                                $__readmeFile = DOCS_DIR . 'README.md';
                                echo is_file($__readmeFile) ? invoxaRenderMarkdown(file_get_contents($__readmeFile)) : '<p>Document not found.</p>';
                                ?>
                            </div>
                        </div>
                    </div>
                    <div class="subnav-pane" id="docs-pane-install">
                        <div class="card">
                            <div class="card-body doc-content">
                                <?php
                                $__installFile = DOCS_DIR . 'INSTALL.md';
                                echo is_file($__installFile) ? invoxaRenderMarkdown(file_get_contents($__installFile)) : '<p>Document not found.</p>';
                                ?>
                            </div>
                        </div>
                    </div>
                    <div class="subnav-pane" id="docs-pane-roadmap">
                        <div class="card">
                            <div class="card-body doc-content">
                                <h1>Roadmap</h1>
                                <p>What's coming next.</p>
                                <ul>
                                    <li><strong>Currency-grouped Stats &amp; accounting exports</strong> — Statistics and the Accounting Journal/QuickBooks (IIF) exports currently report on the instance default currency only, excluding other-currency invoices instead of grouping them in.</li>
                                    <li><strong>Code organization</strong> — invoxa.php's client, stats, exports, and payments logic moved into separate files in 2.11.3, and its auth/2FA/API-token logic, Settings, and Backup &amp; Restore followed in 2.11.4 (see Changelog). The page template is the only piece left in one file; that split is planned next.</li>
                                </ul>
                                <p>No fixed release dates yet, but work is underway. If that would help you, or you have your own idea, raise it on the GitLab repo (see <strong>Source Code</strong>).</p>
                            </div>
                        </div>
                    </div>

                    <div class="subnav-pane" id="docs-pane-changelog">
                        <div class="card">
                            <div class="card-body doc-content">
                                <?php
                                $__changelogFile = DOCS_DIR . 'CHANGELOG.md';
                                echo is_file($__changelogFile) ? invoxaRenderMarkdown(file_get_contents($__changelogFile)) : '<p>Document not found.</p>';
                                ?>
                            </div>
                        </div>
                    </div>

                    <div class="subnav-pane" id="docs-pane-license">
                        <div class="card">
                            <div class="card-body doc-content">
                                <h1>License</h1>
                                <p>Invoxa is free and open source software, licensed under the GNU Affero General
                                    Public License v3.0 (AGPL-3.0). You can self-host it, read every line of it, and
                                    modify your own copy — the full, unmodified license text is reproduced below
                                    exactly as it must be distributed. A paid license key is a separate, optional
                                    unlock for seven specific features (Stripe/PayPal payment collection, recurring
                                    billing automation, the Client Portal, the external API, Reporting &amp;
                                    Statistics, adding teammates beyond your own account, and removing the "Powered
                                    by Invoxa" credit) — see <strong>Security</strong> under Features for how that
                                    works.</p>
                                <?php
                                $__licenseFile = DOCS_DIR . 'LICENSE';
                                echo is_file($__licenseFile)
                                    ? '<pre style="white-space:pre-wrap; font-family:inherit; font-size:0.88rem; line-height:1.55; background:var(--bg); border:1px solid var(--border); border-radius:8px; padding:1rem 1.25rem;">' . htmlspecialchars(file_get_contents($__licenseFile)) . '</pre>'
                                    : '<p>Document not found.</p>';
                                ?>
                            </div>
                        </div>
                    </div>

                    <div class="subnav-pane" id="docs-pane-source">
                        <div class="card">
                            <div class="card-body doc-content">
                                <h1>Source Code</h1>
                                <p>Invoxa's source is public on GitLab: <a href="https://gitlab.com/weblabnz/invoxa"
                                        target="_blank">gitlab.com/weblabnz/invoxa</a>. Read the code, file an issue, or
                                    fork it for your own self-hosted copy — see <strong>License (AGPL-3.0)</strong> for
                                    what that license requires if you distribute a modified version.</p>
                            </div>
                        </div>
                    </div>

                    <div class="subnav-pane" id="docs-pane-overview">
                        <div class="card">
                            <div class="card-body doc-content">
                                <h1>What Invoxa Does</h1>
                                <p>A self-hosted invoicing and billing tool for one business — one or more
                                    accounts (Settings &gt; Users), each Admin or Member. Each topic under
                                    <strong>Features</strong> in the sidebar covers one part in more depth — this
                                    page is just the map.</p>
                                <ul>
                                    <li><strong>Invoicing &amp; Quotes</strong> — ad hoc invoices, line items,
                                        discount/tax, PDF generation, quotes.</li>
                                    <li><strong>Recurring Billing</strong> — per-client schedule, cron-driven, late
                                        fees, reminders.</li>
                                    <li><strong>Payments</strong> — the payment ledger, Stripe/PayPal, refunds, Pay
                                        Now links.</li>
                                    <li><strong>Clients &amp; Portal</strong> — client records, CRM notes, the
                                        Client Portal (quote acceptance included).</li>
                                    <li><strong>Security</strong> — 2FA, backup codes, login lockout.</li>
                                    <li><strong>External API</strong> — token-authenticated read/write API for other
                                        tools.</li>
                                    <li><strong>Reporting</strong> — dashboard, statistics tabs, Audit Log.</li>
                                    <li><strong>Data Management</strong> — backups, offsite push, demo data, Test
                                        Suite.</li>
                                    <li><strong>Notifications</strong> — Slack/Telegram alerts.</li>
                                </ul>
                                <p>New here? Start with <strong>Quick Start</strong> or the <strong>Installation
                                        Guide</strong> above.</p>
                            </div>
                        </div>
                    </div>

                    <div class="subnav-pane" id="docs-pane-feat-invoicing">
                        <div class="card">
                            <div class="card-body doc-content">
                                <h1>Invoicing &amp; Quotes</h1>
                                <h2>Building an Ad Hoc invoice</h2>
                                <p>From the Invoices tab, start a new invoice by picking a <strong>Client</strong>
                                    from the dropdown, then use <strong>Add Row</strong> to build up as many line
                                    items as the job needs — each row has its own code, description, and amount, and
                                    any row can be removed again before sending. Two invoice-level fields sit under
                                    the line items: <strong>Discount %</strong> and <strong>Tax %</strong>, both
                                    optional. As soon as either is non-zero, Invoxa switches on a live
                                    Subtotal/Discount/Tax/Total breakdown so the math is visible before you send
                                    anything — leave both at zero and the invoice just totals the line items
                                    directly, no breakdown shown.</p>
                                <p>Due date can be typed in manually, or left blank to fall back to the client's own
                                    <strong>Payment Terms (days)</strong> figure from their Client record, counted
                                    from the invoice date. There's also an <strong>Internal Note</strong> field —
                                    it's saved with the invoice for your own reference but is never shown to the
                                    client or included in the emailed/PDF version.</p>
                                <h2>Templates &amp; sending</h2>
                                <p>Which layout an invoice renders in — <strong>Detailed</strong> or
                                    <strong>Compact</strong> — is a single instance-wide choice under Settings &gt;
                                    Branding, not something picked per invoice. Sending an invoice emails the client
                                    the rendered HTML and attaches a server-generated PDF (built with dompdf); the
                                    "Download PDF" button on the invoice itself renders through the exact same code
                                    path, so what you download always matches what a client received. Every send —
                                    and every send failure — is written to the Audit Log with the invoice number and
                                    recipient. <strong>Resend Invoice Email</strong> re-sends that same stored
                                    HTML/PDF later (e.g. a client says they lost it) without touching the invoice
                                    number or regenerating anything.</p>
                                <h2>Quotes</h2>
                                <p>Quotes use the identical line-item builder as Ad Hoc invoices, but
                                    <strong>Save Quote</strong> stores it without emailing anything and without
                                    consuming a real invoice number — quotes get their own numbering, formatted as
                                    <code>Q&lt;CLIENTKEY&gt;001</code> (the client's key, then a per-client sequence),
                                    so a quote number is never mistakable for an invoice number. When the client
                                    accepts, convert the quote to a real invoice from the Quotes list — every line
                                    item, discount, and tax setting carries over, so nothing gets retyped, and only
                                    at that point does it consume an actual invoice number and become billable.</p>
                                <h2>Void</h2>
                                <p>A mistaken or cancelled invoice can be voided instead of deleted, from the invoice
                                    row's action menu. Voiding pulls it out of every outstanding, overdue, and
                                    revenue total instantly, but the record itself — line items, amount, send
                                    history — stays intact and visible, so nothing about what happened is lost from
                                    the Audit Log. Unvoid restores it to exactly where it left off (paid/unpaid
                                    status included) if it turns out it shouldn't have been voided.</p>
                            </div>
                        </div>
                    </div>

                    <div class="subnav-pane" id="docs-pane-feat-recurring">
                        <div class="card">
                            <div class="card-body doc-content">
                                <h1>Recurring Billing</h1>
                                <p><strong>Requires a license.</strong> Ad Hoc invoicing stays free either way — this
                                    page covers the automated side specifically: the cron-driven billing schedule,
                                    late fees, and payment reminders below.</p>
                                <p>Each client carries its own billing schedule on the Client form:
                                    <strong>Billing Frequency</strong> (weekly/monthly/quarterly/annually),
                                    <strong>Rate</strong> (per billing period, in your instance currency),
                                    <strong>Payment Terms (days)</strong>, plus optional <strong>Discount %</strong>
                                    and <strong>Tax Rate %</strong> (both default to 0, so recurring invoices behave
                                    exactly like a plain rate unless you explicitly set one). One cron job — configured
                                    once under Settings &gt; Billing, not per client — walks every
                                    active client on each run and bills whichever ones are actually due for their own
                                    frequency; a client billed weekly and one billed annually can happily share the
                                    same cron trigger.</p>
                                <h2>Double-billing guard</h2>
                                <p>Before generating an invoice for a client, Invoxa checks whether that client
                                    already has an invoice in the current period — the current week/month/quarter/
                                    year, matched against their own frequency — and skips them if one already exists.
                                    That's what makes a misconfigured cron schedule (say, hourly instead of monthly)
                                    a non-event instead of a billing disaster: the guard just keeps skipping the
                                    client until the next real period starts. If you genuinely need to re-run a
                                    missed cycle on purpose, a "bypass guard" toggle in the same settings panel lets
                                    one run ignore the check.</p>
                                <h2>Late fees</h2>
                                <p>Off by default. When turned on (Settings &gt; Billing &gt; Late
                                    Fees), three fields control it: <strong>Fee Type</strong> (Percentage of the
                                    overdue invoice, or a Flat amount), <strong>Fee Value</strong> (the percentage or
                                    currency amount, depending on the type chosen), and <strong>Grace Period</strong>
                                    — how many days overdue an invoice must be before the fee applies. A late fee is
                                    charged as its own proper billable invoice, referencing the original overdue
                                    invoice's number in its description — never just a note tacked onto the existing
                                    invoice — and each overdue invoice is only ever charged one late fee, no matter
                                    how many further cron runs pass while it stays unpaid.</p>
                                <h2>Payment reminders</h2>
                                <p>Also off by default, toggled independently of late fees in the same settings
                                    panel. Once active, every unpaid invoice automatically gets one reminder email as
                                    soon as it crosses <strong>7 days overdue</strong> — that threshold isn't
                                    configurable, but the email itself is: edit the <strong>Reminder Email
                                        Subject</strong> and <strong>Reminder Email Body</strong> under Settings &gt;
                                    Email, using the same token placeholders (client name, invoice number, due date,
                                    days overdue, amount) as the main invoice template. The reminder resends the
                                    original invoice's actual HTML alongside the reminder text, so a client chasing
                                    it up sees the real invoice again, not just a bare notice.</p>
                            </div>
                        </div>
                    </div>

                    <div class="subnav-pane" id="docs-pane-feat-payments">
                        <div class="card">
                            <div class="card-body doc-content">
                                <h1>Payments</h1>
                                <p>Marking invoices paid manually is free. <strong>Stripe/PayPal collection requires
                                        a license</strong> — the Stripe &amp; Refunds sections below.</p>
                                <h2>Marking an invoice paid manually</h2>
                                <p>Open <strong>Mark Paid</strong> on any invoice and the modal shows the
                                    <strong>Payment History</strong> for that invoice — every installment already
                                    recorded — above a <strong>This Payment</strong> amount field that defaults to
                                    the remaining balance, not the full invoice total, so a partial payment doesn't
                                    require doing subtraction by hand. An optional <strong>Note</strong> field
                                    records anything worth remembering about that specific installment (a check
                                    number, "paid via bank transfer", etc). Because every payment — manual or
                                    online — is its own ledger row rather than a single paid/unpaid flag, an invoice
                                    can be paid off across several installments over time with a full, honest
                                    history, while the invoice's own cached paid amount and status stay correct
                                    automatically as each row is added. To clear several invoices at once, select
                                    them with the checkbox column on the Invoices tab and use <strong>Mark
                                        Paid</strong> in the bulk action bar that appears.</p>
                                <h2>Stripe &amp; PayPal</h2>
                                <p>Both are configured under Settings &gt; Payments, and both are off until you add
                                    credentials there: Stripe needs a <strong>Secret Key</strong> and a
                                    <strong>Webhook Signing Secret</strong>; PayPal needs an
                                    <strong>Environment</strong> (Sandbox/Live), <strong>Client ID</strong>,
                                    <strong>Client Secret</strong>, and <strong>Webhook ID</strong>. A
                                    <strong>Test Connection</strong> button next to each gateway's fields confirms
                                    the credentials actually work before you rely on them. A
                                    <strong>Public URL</strong> field on the same settings tab matters specifically
                                    for Recurring Billing invoices, since those are emailed by a background cron job
                                    with no browser request to infer your domain from — without it, a cron-generated
                                    invoice's Pay Now link can't be built.</p>
                                <p>Once enabled, a "Pay Now" button appears on emailed invoices and on outstanding
                                    invoices in the Client Portal, using each provider's own standard hosted checkout
                                    (a Stripe Checkout Session, or a PayPal Order that's then captured). A payment is
                                    only ever credited to an invoice once its webhook arrives and its signature
                                    verifies — Stripe's is checked locally with HMAC-SHA256 against your signing
                                    secret, PayPal's is verified by calling PayPal's own verify-webhook-signature
                                    API. The page a client's browser lands on right after paying is only ever a
                                    faster-feeling confirmation screen; it is never itself trusted to mark anything
                                    paid, so a closed tab or a flaky redirect can't cause a missed payment.</p>
                                <h2>Refunds</h2>
                                <p>A refund issued from the Stripe or PayPal dashboard (not from inside Invoxa —
                                    there's no refund button here, by design) reopens the invoice and reduces its
                                    recorded paid amount, arriving through that same webhook path. It requires
                                    subscribing your existing webhook to one extra event per gateway —
                                    <code>charge.refunded</code> for Stripe, <code>PAYMENT.CAPTURE.REFUNDED</code>
                                    for PayPal — the exact webhook URLs and event names to add are shown right on
                                    Settings &gt; Payments next to each gateway's credentials.</p>
                            </div>
                        </div>
                    </div>

                    <div class="subnav-pane" id="docs-pane-feat-clients">
                        <div class="card">
                            <div class="card-body doc-content">
                                <h1>Clients &amp; Client Portal</h1>
                                <h2>The client record</h2>
                                <p>The Add/Edit Client form, in order: <strong>Client Name</strong> and
                                    <strong>Email Address</strong>; <strong>Rate</strong> (per billing period) and
                                    <strong>Currency</strong> — a 3-letter code (USD, EUR, GBP, etc.) for that
                                    client's invoices and quotes; leave it blank to use the instance default
                                    (Settings &gt; General). Each invoice/quote snapshots the client's currency at
                                    the moment it's created, so changing a client's currency later never rewrites
                                    their past invoices. There's no automatic exchange-rate conversion — amounts in
                                    a different currency are grouped separately rather than added together, and
                                    Statistics/accounting exports currently report on the instance default currency
                                    only (see Roadmap); <strong>Billing Frequency</strong>
                                    (weekly/monthly/quarterly/annually); <strong>Payment Terms (days)</strong>,
                                    which drives the default due date on that client's invoices when one isn't set
                                    manually; <strong>Discount %</strong> and <strong>Tax Rate %</strong>, both
                                    defaulting to 0 and applied automatically to that client's Recurring Billing
                                    invoices; <strong>Bank Account Name</strong> and <strong>Bank Account
                                        Number</strong>, shown on that client's invoices unless overridden elsewhere;
                                    and two checkboxes, <strong>Active</strong> (checked by default — an inactive
                                    client is skipped by Recurring Billing) and <strong>Is Test Client</strong>
                                    (used by Demo Data and the Test Suite to mark records that should never count
                                    toward real totals or reports). Bulk import and export both go through CSV, from
                                    the Clients tab.</p>
                                <h2>CRM notes &amp; the client drawer</h2>
                                <p>Opening a client's CRM notes slides out a drawer alongside a quick summary of that
                                    client's own activity — recent invoices and running totals — so you can check
                                    context before writing a note, rather than needing to leave the client and go
                                    look it up separately. Notes are free-text and purely internal; they're never
                                    shown to the client anywhere, including in the Client Portal.</p>
                                <h2>Client Portal</h2>
                                <p><strong>Requires a license</strong> to generate or regenerate a link; revoking one
                                    is always free.</p>
                                <p>From the Client Portal section of the same Add/Edit Client form, generate a
                                    token-gated link for that client — no login involved — that shows their own
                                    invoice list and paid/outstanding/overdue status. Pick an <strong>Expires</strong>
                                    value (30 days, 90 days — the default, 1 year, or Never) before generating.
                                    Nothing is emailed automatically when a link is created; you copy and share it
                                    yourself however you'd normally reach that client. Regenerating or revoking a
                                    link immediately invalidates the old token, so a link you've shared can be cut
                                    off at any time without affecting the client's other data.</p>
                                <p>Invoice status is still read-only, but any of that client's open quotes now show
                                    there too with an <strong>Accept Quote</strong> button — a confirmation step
                                    first, then it converts straight to a real invoice the same way your own Convert
                                    button does, and you get notified (see Settings &gt; Notifications) instead of
                                    having to check back. An expired quote (see quote expiry under Invoicing &amp;
                                    Quotes) shows as Expired instead and can't be accepted.</p>
                            </div>
                        </div>
                    </div>

                    <div class="subnav-pane" id="docs-pane-feat-security">
                        <div class="card">
                            <div class="card-body doc-content">
                                <h1>Security</h1>
                                <h2>Two-factor authentication</h2>
                                <p>From Settings &gt; Account, the Two-Factor Authentication card's
                                    <strong>Enable Two-Factor Authentication</strong> button generates a fresh TOTP
                                    secret and shows it on screen
                                    for you to add to any standard authenticator app (Invoxa implements TOTP and
                                    base32 itself — no external service is contacted). You confirm setup by entering
                                    the 6-digit code the app produces; from that point on, login requires the
                                    password followed by a fresh code. At the same moment setup is confirmed, ten
                                    single-use <strong>backup codes</strong> are generated and shown exactly once —
                                    save them somewhere safe, since Invoxa doesn't display them again. Each backup
                                    code can substitute for a TOTP code at login exactly once; once used, or once
                                    <strong>Regenerate Backup Codes</strong> is clicked, it's dead. Both
                                    <strong>Regenerate Backup Codes</strong> and <strong>Disable Two-Factor
                                        Authentication</strong> require re-entering your current password in the
                                    Current Password field on the same card, so a session left logged in on a shared
                                    machine can't be used to quietly turn 2FA off or invalidate someone's saved
                                    codes.</p>
                                <h2>Login lockout</h2>
                                <p>5 failed attempts locks the account for 15 minutes — a wrong password counts, and
                                    so does a wrong 2FA code or a wrong/already-used backup code, at either stage of
                                    login. The counter resets on a successful login. This is enforced server-side
                                    regardless of what the login form itself shows, so it can't be bypassed by
                                    retrying more carefully.</p>
                                <h2>Users &amp; roles</h2>
                                <p>Settings &gt; Users manages every account. <strong>Admin</strong> accounts have
                                    full access, including Settings and Data Management.
                                    <strong>Member</strong> accounts can use everything day-to-day — Dashboard,
                                    Invoices, Clients, Quotes, Expenses — plus their own Account tab (username,
                                    email, password, 2FA), but nothing else under Settings and nothing under Data
                                    Management. The account created at signup is always an admin; the last admin
                                    can't be demoted or deleted, so there's always at least one account able to
                                    manage the rest. Adding a second (or further) account requires a license —
                                    editing or removing an existing one stays free either way, the same pattern as
                                    API tokens and the Client Portal below.</p>
                                <h2>Invoxa is open source — licensing only unlocks seven extras</h2>
                                <p>Invoxa is free and open source (AGPL-3.0): client and invoice management, quotes,
                                    manual payments, backups, and 2FA all work fully with no license key at all — an
                                    unlicensed install is never locked out of its own account or its own data. A
                                    license is a paid, optional unlock for seven specific capabilities: Stripe/PayPal
                                    payment collection, recurring billing automation, the Client Portal, the
                                    external API, Reporting &amp; Statistics, adding teammates beyond your own
                                    account, and removing the "Powered by Invoxa" credit line from invoices and
                                    emails. Everything else in this Docs section works exactly the same whether or
                                    not you've added a key.</p>
                            </div>
                        </div>
                    </div>

                    <div class="subnav-pane" id="docs-pane-feat-api">
                        <div class="card">
                            <div class="card-body doc-content">
                                <h1>External API</h1>
                                <p>A small read/write API for scripts and other tools, entirely managed from
                                    Settings &gt; API Access — the same page shows a built-in guide with
                                    copy-pasteable <code>curl</code> examples for every endpoint below, filled in
                                    with your own instance URL, so there's nothing to look up elsewhere to get
                                    started.</p>
                                <h2>Authentication</h2>
                                <p>Every request is routed through <code>?apiv1=</code> (kept deliberately separate
                                    from the app's own internal <code>?api=</code> parameter used by its dashboard
                                    charts and tables) and authenticated with a bearer token in the
                                    <code>Authorization</code> header. A request with a missing, revoked, expired, or
                                    malformed token gets a JSON error body — <code>{"error": "..."}</code> — and an
                                    appropriate HTTP status, never a silent empty result.</p>
                                <h2>Endpoints (v1)</h2>
                                <ul>
                                    <li><code>invoices.list</code> — list invoices, filterable by status and by
                                        client_key, paginated.</li>
                                    <li><code>invoices.get</code> — fetch a single invoice by its invoice number.</li>
                                    <li><code>clients.list</code> — list clients, paginated.</li>
                                    <li><code>payments.record</code> — record a payment against an invoice by number,
                                        with an optional idempotency reference so a retried request from a script
                                        can't double-credit the same payment.</li>
                                </ul>
                                <h2>Token lifecycle</h2>
                                <p><strong>Requires a license</strong> to create or renew a token — once you have a
                                    working one, every endpoint above (including <code>payments.record</code>) is
                                    available through it; revoking or deleting a token stays free.</p>
                                <p>Create a token with a label (so you remember what it's for) and an optional
                                    expiry; the full token value is shown exactly once, at creation — there's no way
                                    to view it again afterward, only to issue a new one. <strong>Revoke</strong>
                                    cuts a token off immediately (any request using it starts failing right away)
                                    but leaves it listed with a revoked status, as an audit trail of what existed and
                                    when it stopped working — the same pattern GitHub and Stripe use for their own
                                    tokens. <strong>Renew</strong> extends an active token's expiry without changing
                                    its value, so scripts already using it keep working. <strong>Delete</strong> is
                                    a separate, explicit action from Revoke — it permanently removes an
                                    already-revoked or already-expired token from the list, for actually clearing
                                    old entries out rather than just deactivating them.</p>
                            </div>
                        </div>
                    </div>

                    <div class="subnav-pane" id="docs-pane-feat-reporting">
                        <div class="card">
                            <div class="card-body doc-content">
                                <h1>Reporting</h1>
                                <h2>Dashboard</h2>
                                <p>The Dashboard is the at-a-glance landing view: monthly recurring revenue,
                                    outstanding balance, overdue balance, and a feed of recent activity, plus charts
                                    for monthly revenue and the per-client breakdown behind it — enough to answer
                                    "how's the business doing right now" without drilling into Statistics.</p>
                                <h2>Statistics</h2>
                                <p><strong>Requires a license.</strong> The Dashboard above stays free either way.</p>
                                <p>If any client is set to a currency other than the instance default (Settings &gt; General), Statistics and the Tax &amp; Compliance exports below report on the default currency only — invoices/clients in another currency are excluded from these totals and charts rather than being added together (see Clients &amp; Client Portal, and Roadmap). The Dashboard's own headline totals, and the Invoices/Clients/Quotes tabs, don't have this limitation — they show every currency, grouped rather than blended.</p>
                                <p>Statistics is split into six focused tabs rather than one long scrolling page:
                                    <strong>Revenue</strong>, <strong>Forecasting</strong>, <strong>Clients</strong>,
                                    <strong>Tax &amp; Compliance</strong>, <strong>Activity</strong>, and
                                    <strong>System</strong>. Between them they cover reports like Accounts
                                    Receivable Aging, Quote Pipeline (how many quotes are open vs. converted vs.
                                    stale), voided-invoice totals, Client Growth &amp; Mix, a "Clients Needing
                                    Attention" list, Email Delivery Health (send success/failure rates), Most Active
                                    Clients by invoice count, and tax-year progress with a monthly breakdown — the
                                    Tax &amp; Compliance tab is also where the tax-year CSV exports live (full
                                    invoice list, and a monthly summary), using whatever tax year start month is set
                                    in Settings.</p>
                                <h2>Audit Log</h2>
                                <p>Every invoice send (and send failure), payment, refund, void/unvoid, and
                                    account-security event — 2FA enabled/disabled, API token created/revoked/deleted,
                                    a login lockout — is written here with a timestamp, making it the one place to
                                    answer "what actually happened, and when" on this instance. It also records when
                                    a Stripe or PayPal webhook arrives referencing an invoice number Invoxa doesn't
                                    recognize, rather than silently dropping it. Retention is configurable from Data
                                    Management (30, 180, or 365 days, or kept forever) — older entries are pruned
                                    automatically once a retention period is set, rather than growing the table
                                    indefinitely by default.</p>
                            </div>
                        </div>
                    </div>

                    <div class="subnav-pane" id="docs-pane-feat-data">
                        <div class="card">
                            <div class="card-body doc-content">
                                <h1>Data Management</h1>
                                <h2>Backup &amp; Restore</h2>
                                <p>The Backup panel starts with <strong>Select Tables to Export</strong> — every
                                    table is included by default, with a "Show all tables" toggle for reaching the
                                    less common ones, so a backup can be scoped down (e.g. clients and invoices only)
                                    instead of always being all-or-nothing. <strong>Create Backup</strong> writes a
                                    timestamped file you can download. To bring one back, pick a backup and run
                                    <strong>Test Restore (Dry Run)</strong> first — it shows exactly what would
                                    change (rows/tables created, dropped, inserted) without touching the database —
                                    then <strong>Restore Selected</strong> to actually apply it. A local retention
                                    setting prunes old backups down to a configured count automatically after each
                                    new one, and an optional Offsite Push panel can send new backups to a remote
                                    destination via rclone, with credentials kept out of the app itself and living on
                                    the cron container instead.</p>
                                <h2>Demo Data</h2>
                                <p>Seeds a handful of sample clients, invoices, and quotes spread across recent
                                    months, every one of them flagged with the client-level <strong>Is Test
                                        Client</strong> marker — a safe way to see charts, Statistics tabs, and the
                                    Dashboard filled in before committing any real data. Clear Dummy Data removes
                                    everything it seeded, and only what it seeded.</p>
                                <p>To preview it in isolation rather than mixed in with your own clients and
                                    invoices, turn on <strong>Show Only Test/Dummy Data</strong> under Settings &gt;
                                    General &gt; Preferences before (or after) seeding — every list, chart, and
                                    total across the app flips to showing only <code>is_test = 1</code> records
                                    while it's on, and it's empty if no dummy data has been seeded yet. Turn it back
                                    off to return straight to your normal view. It overrides the separate
                                    <strong>Hide Test Clients Globally</strong> toggle while active, so you don't
                                    need to touch that one to preview.</p>
                                <h2>Test Suite</h2>
                                <p>An itemized, in-app correctness check for the app itself: invoice math, TOTP,
                                    Stripe/PayPal amount conversion and webhook signature verification, and real
                                    database behavior like the payment ledger, the Recurring Billing double-billing
                                    guard, and email content/template substitution. Tests are grouped into named
                                    sections (Core Logic, Clients &amp; Invoices, Payments &amp; Refunds, Billing
                                    Cron, Email Content, Security), each with its own checkbox to select
                                    the whole section at once, and pill buttons above the table — an "All" pill,
                                    bold by default, or any single section — to isolate the table to just that
                                    slice and pre-select its rows. Run Selected only executes checked rows; an
                                    unchecked row keeps showing its last result rather than reverting to "Not run."
                                    Every check that touches the database creates its own disposable
                                    client/invoice and deletes it again immediately afterward, pass or fail — never a
                                    real client, never Demo Data's fixtures — and none of it ever sends a real email
                                    or calls the real Stripe/PayPal APIs.</p>
                                <h2>Data Repair</h2>
                                <p>A narrow, specific fix rather than a general-purpose repair tool: <strong>Reset
                                        paid_at to End-of-Month</strong> corrects historical <code>paid_at</code>
                                    dates that were bulk-set incorrectly (e.g. from an old import) by resetting every
                                    paid invoice's <code>paid_at</code> to the last day of its own invoice month.
                                    That's what the Payment Velocity figure under Statistics &gt; Revenue is computed
                                    from, so a batch of invoices with a wrong or missing paid date will visibly skew
                                    that number until this is run.</p>
                                <h2>Danger zone</h2>
                                <p><strong>Factory Reset</strong> wipes the instance back to a clean install —
                                    every client, invoice, quote, note, and setting, every generated invoice file,
                                    every stored backup, and every user account (not just yours), landing back on the
                                    signup screen exactly like a fresh install. It requires typing <code>RESET</code>
                                    exactly into a confirmation field (the button stays disabled until that matches)
                                    plus re-entering your current admin password — two independent confirmations
                                    specifically because there's no undo once it runs; take a backup first if
                                    there's any chance you'll want this data again.</p>
                            </div>
                        </div>
                    </div>

                    <div class="subnav-pane" id="docs-pane-feat-notifications">
                        <div class="card">
                            <div class="card-body doc-content">
                                <h1>Notifications</h1>
                                <p>Settings &gt; Notifications sends short alerts to Telegram, Slack, or a generic
                                    webhook — pick one channel; it isn't more than one at once. This path is
                                    deliberately independent of email delivery, so it keeps working even if SMTP is
                                    misconfigured or down, and is useful precisely because it's a second, separate
                                    way to notice something went wrong.</p>
                                <h2>Telegram</h2>
                                <p>Needs a <strong>Bot Token</strong> (create a bot via BotFather in Telegram to get
                                    one) and a <strong>Chat ID</strong> — the settings page includes a pointer to
                                    finding your chat ID via your browser, since it isn't something Telegram shows
                                    you directly in the app.</p>
                                <h2>Slack</h2>
                                <p>Needs only a <strong>Webhook URL</strong> — create an Incoming Webhook for a
                                    channel in your Slack workspace and paste its URL in.</p>
                                <h2>Generic Webhook</h2>
                                <p>For anything that isn't Slack or Telegram specifically — <a href="https://ntfy.sh"
                                        target="_blank" rel="noopener">ntfy</a>, a Discord webhook, or your own
                                    receiver. Needs a <strong>Webhook URL</strong> and a <strong>Payload Format</strong>
                                    matching what that receiver expects: plain text (ntfy and most shell-script
                                    receivers), <code>{"text": "..."}</code> (Slack-compatible, e.g. Mattermost), or
                                    <code>{"content": "..."}</code> (Discord). Unlike Telegram/Slack, success here just
                                    means the URL was reachable and didn't return an HTTP error — there's no single
                                    expected response body across every possible receiver.</p>
                                <h2>Events</h2>
                                <p>Two independently toggleable checkboxes control what triggers a message: notify
                                    when a payment is received (fires for both full and partial payments, and for
                                    refunds) and notify when an invoice becomes overdue (fires from the same cron
                                    trigger as Payment Reminders, regardless of whether the reminder email itself
                                    successfully sends). A <strong>Send Test Message</strong> button confirms the
                                    configured channel actually works before you rely on it.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            </div>
        </div>

        <?php require_once __DIR__ . '/lib/settings_page.php'; ?>

        <?php require_once __DIR__ . '/lib/backup_page.php'; ?>

        <!-- Modals -->
        <div id="clientModal" class="modal-overlay">
            <div class="modal large">
                <div class="modal-header">
                    <h2 id="clientModalTitle">Add Client</h2><button class="btn"
                        style="background:transparent; border:none; color:var(--text-primary);" onclick="closeModal('clientModal')"><i
                            class="fa-solid fa-xmark"></i></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="clientId">

                    <!-- Identity -->
                    <div class="client-form-grid">
                        <div class="form-group"><label class="form-label">Client Name</label><input type="text"
                                id="clientName" class="form-control"></div>
                        <div class="form-group"><label class="form-label">Email Address</label><input type="email"
                                id="clientEmail" class="form-control"></div>
                        <div class="form-group"><label class="form-label">Phone</label><input type="text"
                                id="clientPhone" class="form-control" placeholder="e.g. +1 555 123 4567"></div>
                        <div class="form-group" style="grid-column:1 / -1;"><label class="form-label">Address</label><textarea
                                id="clientAddress" class="form-control" rows="2" placeholder="Street, city, postal code, country"></textarea></div>
                    </div>

                    <!-- Billing terms -->
                    <div class="client-form-grid" style="margin-top:0.5rem; padding-top:1rem; border-top:1px solid var(--border);">
                        <div class="form-group"><label class="form-label">Rate (per billing period)</label><input type="number"
                                id="clientRate" class="form-control" step="0.01"></div>
                        <div class="form-group"><label class="form-label">Currency</label><input type="text"
                                id="clientCurrency" class="form-control" maxlength="3"
                                style="text-transform:uppercase; max-width:100px;"
                                placeholder="<?= htmlspecialchars($settings['currency'] ?? 'USD') ?>">
                            <p style="color:var(--text-secondary); font-size:0.8rem; margin-top:0.35rem;">3-letter
                                code for this client's invoices/quotes. Leave blank to use the instance default (<?= htmlspecialchars($settings['currency'] ?? 'USD') ?>).</p>
                        </div>
                        <div class="form-group"><label class="form-label">Billing Frequency</label>
                            <select id="clientBillingFrequency" class="form-control">
                                <option value="weekly">Weekly</option>
                                <option value="monthly" selected>Monthly</option>
                                <option value="quarterly">Quarterly</option>
                                <option value="annually">Annually</option>
                            </select>
                            <p style="color:var(--text-secondary); font-size:0.8rem; margin-top:0.35rem;">How often
                                Recurring Billing charges this client. Defaults to Monthly.</p>
                        </div>
                        <div class="form-group" style="grid-column:1 / -1;"><label class="form-label">Payment Terms (days)</label><input type="number"
                                id="clientPaymentTerms" class="form-control" step="1" min="1" placeholder="21" style="max-width:calc(50% - 0.625rem);">
                            <p style="color:var(--text-secondary); font-size:0.8rem; margin-top:0.35rem;">Days from
                                invoice date to due date, e.g. 15/30/45. Defaults to 21.</p>
                        </div>
                        <div class="form-group"><label class="form-label">Discount (%)</label><input type="number"
                                id="clientDiscountPct" class="form-control" step="0.01" min="0" max="100"
                                placeholder="0"></div>
                        <div class="form-group"><label class="form-label">Tax Rate (%)</label><input type="number"
                                id="clientTaxRate" class="form-control" step="0.01" min="0" max="100"
                                placeholder="0"></div>
                        <p style="grid-column:1 / -1; color:var(--text-secondary); font-size:0.8rem; margin:-0.5rem 0 0;">
                            Discount/Tax apply to this client's Recurring Billing invoices only. Both default to 0
                            when left blank.</p>
                    </div>

                    <!-- Bank details -->
                    <div class="client-form-grid" style="margin-top:0.5rem; padding-top:1rem; border-top:1px solid var(--border);">
                        <div class="form-group"><label class="form-label">Bank Account Name</label><input type="text"
                                id="clientAccName" class="form-control" placeholder="e.g. Jane Smith - Acme Web Co"></div>
                        <div class="form-group"><label class="form-label">Bank Account Number</label><input type="text"
                                id="clientAccNum" class="form-control" placeholder="e.g. 12-3456-7890123-00"></div>
                    </div>

                    <!-- Status -->
                    <div style="display:flex; align-items:center; gap:1.5rem; margin-top:0.5rem; padding-top:1rem; border-top:1px solid var(--border);">
                        <label style="display:flex; align-items:center; gap:0.5rem;"><input type="checkbox"
                                id="clientActive" checked> Active</label>
                        <label style="display:flex; align-items:center; gap:0.5rem;"><input type="checkbox"
                                id="clientTest"> Is Test Client</label>
                    </div>

                    <div id="clientPortalSection" style="margin-top:1.25rem; padding-top:1rem; border-top:1px solid var(--border); display:none;">
                        <label class="form-label">Client Portal <?php if (!$licenseValid): ?><i class="fa-solid fa-lock"
                                    title="Requires a license" style="color:var(--text-secondary); font-size:0.8rem; margin-left:0.35rem;"></i><?php endif; ?></label>
                        <p style="color:var(--text-secondary); font-size:0.8rem; margin-top:0;">A read-only link this
                            client can use to see their own invoices and payment status — no login required. You
                            share the link yourself (email, etc.); nothing is sent automatically.
                            <?php if (!$licenseValid): ?><strong>Generating or regenerating a link requires a
                                    license</strong> — revoking an existing one stays free.<?php endif; ?></p>
                        <div id="clientPortalNoLinkWrap" style="display:flex; gap:1.5rem; align-items:center; flex-wrap:wrap; margin-top:0.75rem;">
                            <select id="clientPortalExpiry" class="form-control" style="width:auto;" <?= $licenseValid ? '' : 'disabled' ?>>
                                <option value="never">Never</option>
                                <option value="30">30 days</option>
                                <option value="90" selected>90 days</option>
                                <option value="365">1 year</option>
                            </select>
                            <button class="btn" id="generatePortalLinkBtn" type="button" onclick="generatePortalLink()" style="width:auto;"
                                <?= $licenseValid ? '' : 'disabled title="Requires a license"' ?>><i
                                    class="fa-solid fa-link"></i> Generate Portal Link</button>
                        </div>
                        <div id="clientPortalLinkWrap" style="display:none;">
                            <div style="display:flex; gap:0.5rem;">
                                <input type="text" id="clientPortalUrl" class="form-control" readonly>
                                <button class="btn" type="button" onclick="copyPortalLink()" style="width:auto; white-space:nowrap;"><i
                                        class="fa-solid fa-copy"></i> Copy</button>
                            </div>
                            <p id="clientPortalExpiryNote" style="color:var(--text-secondary); font-size:0.8rem; margin:0.35rem 0 0;"></p>
                            <div style="display:flex; gap:0.5rem; margin-top:0.5rem; align-items:center;">
                                <select id="clientPortalRegenExpiry" class="form-control" style="width:auto;" <?= $licenseValid ? '' : 'disabled' ?>>
                                    <option value="never">Never expires</option>
                                    <option value="30">30 days</option>
                                    <option value="90" selected>90 days</option>
                                    <option value="365">1 year</option>
                                </select>
                                <button class="btn" type="button" onclick="generatePortalLink()" style="width:auto;"
                                    <?= $licenseValid ? '' : 'disabled title="Requires a license"' ?>><i
                                        class="fa-solid fa-rotate"></i> Regenerate</button>
                                <button class="btn danger" type="button" onclick="revokePortalLink()" style="width:auto;"><i
                                        class="fa-solid fa-ban"></i> Revoke</button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer"><button class="btn" onclick="closeModal('clientModal')">Cancel</button><button
                        class="btn primary" id="saveClientBtn" onclick="saveClient()"><i class="fa-solid fa-save"></i>
                        Save Client</button></div>
            </div>
        </div>

        <div id="expenseModal" class="modal-overlay">
            <div class="modal">
                <div class="modal-header">
                    <h2 id="expenseModalTitle">Add Expense</h2><button class="btn"
                        style="background:transparent; border:none; color:var(--text-primary);" onclick="closeModal('expenseModal')"><i
                            class="fa-solid fa-xmark"></i></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="expenseId">
                    <div class="form-group" style="width:50%;">
                        <label class="form-label">Date</label>
                        <div style="display:flex; align-items:center; gap:0.75rem;">
                            <input type="date" id="expenseDate" class="form-control" style="flex:1;"
                                oninput="document.getElementById('expenseDateIso').textContent = this.value">
                            <span id="expenseDateIso" style="font-size:0.8rem; color:var(--text-secondary); white-space:nowrap;"></span>
                        </div>
                    </div>
                    <div class="form-group"><label class="form-label">Vendor</label><input type="text"
                            id="expenseVendor" class="form-control" placeholder=""></div>
                    <div class="form-group"><label class="form-label">Category</label>
                        <select id="expenseCategory" class="form-control">
                            <?php foreach (expenseCategories() as $__catKey => $__catLabel): ?>
                                <option value="<?= htmlspecialchars($__catKey) ?>"><?= htmlspecialchars($__catLabel) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group"><label class="form-label">Amount (<?= htmlspecialchars($settings['currency'] ?? 'USD') ?>)</label>
                        <input type="number" id="expenseAmount" class="form-control" step="0.01" min="0"></div>
                    <div class="form-group"><label class="form-label">Description <span
                                style="font-weight:400; color:var(--text-secondary);">(optional)</span></label>
                        <textarea id="expenseDescription" class="form-control" rows="2"></textarea>
                    </div>
                    <div class="form-group"><label class="form-label">Invoice <span
                                style="font-weight:400; color:var(--text-secondary);">(optional — the vendor's bill, if you keep that separately from the receipt)</span></label>
                        <div id="expenseInvoiceFilesList" style="margin-bottom:0.5rem;"></div>
                        <input type="file" id="expenseInvoiceFiles" class="form-control" accept="image/*,.pdf" multiple
                            style="padding:0.5rem;">
                    </div>
                    <div class="form-group"><label class="form-label">Receipt <span
                                style="font-weight:400; color:var(--text-secondary);">(optional — proof of payment; an image here is scanned to prefill Vendor/Amount above)</span></label>
                        <div id="expenseReceiptsList" style="margin-bottom:0.5rem;"></div>
                        <input type="file" id="expenseReceiptFiles" class="form-control" accept="image/*,.pdf" multiple
                            style="padding:0.5rem;" onchange="handleExpenseReceiptFilesChange()">
                        <p id="expenseOcrStatus" style="display:none; color:var(--text-secondary); font-size:0.8rem; margin-top:0.35rem; margin-bottom:0;"></p>
                    </div>
                </div>
                <div class="modal-footer"><button class="btn" onclick="closeModal('expenseModal')">Cancel</button><button
                        class="btn primary" id="saveExpenseBtn" onclick="saveExpense()"><i class="fa-solid fa-save"></i>
                        Save Expense</button></div>
            </div>
        </div>

        <div id="recurringExpenseModal" class="modal-overlay">
            <div class="modal">
                <div class="modal-header">
                    <h2 id="recurringExpenseModalTitle">Add Recurring Expense</h2><button class="btn"
                        style="background:transparent; border:none; color:var(--text-primary);" onclick="closeModal('recurringExpenseModal')"><i
                            class="fa-solid fa-xmark"></i></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="recurringExpenseId">
                    <div class="form-group"><label class="form-label">Vendor</label><input type="text"
                            id="recurringExpenseVendor" class="form-control" placeholder=""></div>
                    <div class="form-group"><label class="form-label">Category</label>
                        <select id="recurringExpenseCategory" class="form-control">
                            <?php foreach (expenseCategories() as $__catKey => $__catLabel): ?>
                                <option value="<?= htmlspecialchars($__catKey) ?>"><?= htmlspecialchars($__catLabel) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div style="display:flex; gap:1rem;">
                        <div class="form-group" style="flex:1;"><label class="form-label">Amount (<?= htmlspecialchars($settings['currency'] ?? 'USD') ?>)</label>
                            <input type="number" id="recurringExpenseAmount" class="form-control" step="0.01" min="0"></div>
                        <div class="form-group" style="flex:1;"><label class="form-label">Frequency</label>
                            <select id="recurringExpenseFrequency" class="form-control">
                                <option value="weekly">Weekly</option>
                                <option value="monthly" selected>Monthly</option>
                                <option value="quarterly">Quarterly</option>
                                <option value="annually">Annually</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group"><label class="form-label">Description <span
                                style="font-weight:400; color:var(--text-secondary);">(optional)</span></label>
                        <textarea id="recurringExpenseDescription" class="form-control" rows="2"></textarea>
                    </div>
                    <p style="color:var(--text-secondary); font-size:0.8rem; margin:0;">Logged automatically as a new expense the next time recurring billing runs (Settings &gt; Billing, or the monthly cron), once per period on today's date — same guard against double-logging as recurring invoices.</p>
                </div>
                <div class="modal-footer"><button class="btn" onclick="closeModal('recurringExpenseModal')">Cancel</button><button
                        class="btn primary" id="saveRecurringExpenseBtn" onclick="saveRecurringExpense()"><i class="fa-solid fa-save"></i>
                        Save</button></div>
            </div>
        </div>

        <div id="viewModal" class="modal-overlay">
            <div class="modal large">
                <div class="modal-header">
                    <h2 id="viewModalTitle">Invoice</h2>
                    <div style="display:flex; gap:0.5rem; align-items:center;">
                        <button class="btn small" id="downloadPdfBtn" onclick="downloadInvoicePdf()"
                            style="font-size:0.8rem;" title="Download as PDF"><i
                                class="fa-solid fa-file-pdf"></i> Download PDF</button>
                        <button class="btn small" id="copyInvoiceLinkBtn" onclick="copyInvoiceLink()"
                            style="font-size:0.8rem;" title="Copy direct link to this invoice file"><i
                                class="fa-solid fa-link"></i> Copy Link</button>
                        <button class="btn small" id="attachmentsBtn" onclick="openAttachmentsModal()"
                            style="font-size:0.8rem;" title="Manage attachments (contracts, receipts)"><i
                                class="fa-solid fa-paperclip"></i> Attachments</button>
                        <button class="btn" style="background:transparent; border:none; color:var(--text-primary);"
                            onclick="closeModal('viewModal')"><i class="fa-solid fa-xmark"></i></button>
                    </div>
                </div>
                <div class="modal-body" style="padding: 0; overflow: hidden; position: relative;">
                    <iframe id="invoicePreview" style="width:100%; height:70vh; border:none; background:white;"></iframe>
                    <div id="invoiceMissingWarning"
                        style="display:none; height:70vh; align-items:center; justify-content:center; text-align:center; padding:2rem; box-sizing:border-box;">
                        <div>
                            <div style="font-size:2rem; margin-bottom:0.75rem; color:var(--warning);"><i
                                    class="fa-solid fa-triangle-exclamation"></i></div>
                            <h3 style="margin:0 0 0.5rem;">Invoice file not found</h3>
                            <p style="color:var(--text-secondary); max-width:420px; margin:0 auto 1rem;">The database
                                record exists, but its file is missing on disk — this instance's database and files
                                have drifted out of sync.</p>
                            <button class="btn primary" onclick="closeModal('viewModal'); nav('sync', true);"><i
                                    class="fa-solid fa-rotate"></i> Go to Sync</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div id="attachmentsModal" class="modal-overlay">
            <div class="modal">
                <div class="modal-header">
                    <h2 id="attachmentsModalTitle">Attachments</h2><button class="btn"
                        style="background:transparent; border:none; color:var(--text-primary);" onclick="closeModal('attachmentsModal')"><i
                            class="fa-solid fa-xmark"></i></button>
                </div>
                <div class="modal-body">
                    <p style="color:var(--text-secondary); font-size:0.85rem; margin-top:0;">Contracts, signed
                        receipts, or any other file worth keeping with this invoice. Stored on this server, not
                        emailed to the client.</p>
                    <div id="attachmentsList" style="margin-bottom:1rem;"></div>
                    <div style="display:flex; gap:0.5rem; align-items:center;">
                        <input type="file" id="attachmentFile" class="form-control" style="padding:0.5rem;">
                        <button class="btn primary" id="uploadAttachmentBtn" onclick="uploadAttachment()"
                            style="white-space:nowrap;"><i class="fa-solid fa-upload"></i> Upload</button>
                    </div>
                </div>
            </div>
        </div>

        <div id="restoreModal" class="modal-overlay">
            <div class="modal large">
                <div class="modal-header">
                    <h2 id="restoreModalTitle">Dry Run Summary</h2>
                    <button class="btn" style="background:transparent; border:none; color:var(--text-primary);"
                        onclick="closeModal('restoreModal')"><i class="fa-solid fa-xmark"></i></button>
                </div>
                <div class="modal-body" id="restoreModalBody" style="max-height:60vh; overflow-y:auto; padding: 1rem;">
                </div>
                <div class="modal-footer">
                    <button class="btn" onclick="closeModal('restoreModal')">Close</button>
                    <button class="btn" style="background:var(--danger); color:white; border:none;"
                        onclick="closeModal('restoreModal'); confirmRestore();"><i class="fa-solid fa-upload"></i>
                        Proceed to Restore</button>
                </div>
            </div>
        </div>

        <div id="paidModal" class="modal-overlay">
            <div class="modal">
                <div class="modal-header">
                    <h2>Mark as Paid</h2><button class="btn" style="background:transparent; border:none; color:var(--text-primary);"
                        onclick="closeModal('paidModal')"><i class="fa-solid fa-xmark"></i></button>
                </div>
                <div class="modal-body"><input type="hidden" id="paidInvoiceId">
                    <div class="form-group"><label class="form-label">Invoice Number</label><input type="text"
                            id="paidInvoiceNum" class="form-control" readonly></div>
                    <div id="paidHistoryWrap" style="display:none; margin-bottom:1rem;">
                        <label class="form-label">Payment History</label>
                        <div id="paidHistoryList" style="font-size:0.85rem; border:1px solid var(--border); border-radius:6px; padding:0.5rem 0.75rem;"></div>
                    </div>
                    <div class="form-group"><label class="form-label">This Payment (<span id="paidAmountCcy"><?= htmlspecialchars($settings['currency'] ?? 'USD') ?></span>)</label><input type="number"
                            step="0.01" min="0.01" id="paidAmount" class="form-control">
                        <p style="color:var(--text-secondary); font-size:0.8rem; margin-top:0.35rem;">Defaults to the
                            remaining balance. Enter a smaller amount to log a partial/installment payment — it's
                            added to this invoice's payment history, not overwritten.</p>
                    </div>
                    <div class="form-group"><label class="form-label">Note <span
                                style="font-weight:400; color:var(--text-secondary);">(optional)</span></label><input
                            type="text" id="paidNote" class="form-control" placeholder="e.g. bank transfer, deposit 1 of 3"></div>
                </div>
                <div class="modal-footer"><button class="btn" onclick="closeModal('paidModal')">Cancel</button><button
                        class="btn success" id="markPaidBtn" onclick="markPaid()"><i class="fa-solid fa-check"></i>
                        Confirm Payment</button></div>
            </div>
        </div>
        <div id="noteModal" class="modal-overlay">
            <div class="modal">
                <div class="modal-header">
                    <h2>Notes &mdash; <span id="noteInvoiceNum" style="font-weight:400; font-size:0.95em;"></span></h2>
                    <button class="btn" style="background:transparent; border:none; color:var(--text-primary);"
                        onclick="closeModal('noteModal')"><i class="fa-solid fa-xmark"></i></button>
                </div>
                <div class="modal-body"><input type="hidden" id="noteInvoiceId">
                    <div id="existingNotesList" style="margin-bottom:1.25rem;"></div>
                    <div class="form-group"><textarea id="noteText" class="form-control" rows="3"
                            placeholder="Type a new note..."></textarea></div>
                </div>
                <div class="modal-footer"><button class="btn" onclick="closeModal('noteModal')">Cancel</button><button
                        class="btn primary" id="addNoteBtn" onclick="addNote()"><i class="fa-solid fa-save"></i> Save
                        Note</button></div>
            </div>
        </div>

        <!-- Factory Reset Modal -->
        <div id="factoryResetModal" class="modal-overlay">
            <div class="modal">
                <div class="modal-header">
                    <h2 style="margin:0; font-size:1.15rem; color:var(--danger);"><i
                            class="fa-solid fa-triangle-exclamation"></i> Factory Reset</h2>
                    <button class="btn" style="background:transparent; border:none; color:var(--text-primary);"
                        onclick="closeModal('factoryResetModal')"><i class="fa-solid fa-xmark"></i></button>
                </div>
                <div class="modal-body">
                    <p style="color:var(--text-secondary); font-size:0.9rem; margin-top:0;">This permanently deletes
                        every client, invoice, quote, note, and setting, every generated invoice file, every stored
                        backup, and every user account — not just yours. There is no undo.</p>
                    <div class="form-group">
                        <label class="form-label" style="font-size:0.8rem; color:var(--text-secondary);">Type
                            <strong>RESET</strong> to confirm</label>
                        <input type="text" id="factoryResetConfirmText" class="form-control"
                            oninput="document.getElementById('factoryResetBtn').disabled = (this.value !== 'RESET')"
                            autocomplete="off">
                    </div>
                    <div class="form-group">
                        <label class="form-label" style="font-size:0.8rem; color:var(--text-secondary);">Current
                            password</label>
                        <input type="password" id="factoryResetPassword" class="form-control"
                            placeholder="Required to confirm it's really you">
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn" onclick="closeModal('factoryResetModal')">Cancel</button>
                    <button class="btn" id="factoryResetBtn" disabled
                        style="background:var(--danger); color:white; border:none;" onclick="doFactoryReset()"><i
                            class="fa-solid fa-bomb"></i> Erase Everything</button>
                </div>
            </div>
        </div>

        <!-- CSV Preview Modal -->
        <div id="csvPreviewModal" class="modal-overlay">
            <div class="modal large" style="max-width: 1000px; width: 95vw;">
                <div class="modal-header">
                    <div>
                        <h2 id="csvPreviewTitle" style="margin:0; font-size:1.15rem;">Export Preview</h2>
                        <p id="csvPreviewSubtitle"
                            style="margin:0.25rem 0 0; font-size:0.8rem; color:var(--text-secondary);"></p>
                    </div>
                    <button class="btn" style="background:transparent; border:none; color:var(--text-primary);"
                        onclick="closeModal('csvPreviewModal')"><i class="fa-solid fa-xmark"></i></button>
                </div>
                <div class="modal-body"
                    style="padding: 1.25rem; overflow-x: auto; overflow-y: auto; flex: 1 1 auto; min-height: 0;">
                    <!-- Summary cards -->
                    <div id="csvPreviewStats" class="mobile-grid"
                        style="display:grid; grid-template-columns:repeat(3,1fr); gap:0.75rem; margin-bottom:1.25rem;">
                    </div>
                    <!-- Loading state -->
                    <div id="csvPreviewLoading" style="text-align:center; padding:2rem; color:var(--text-secondary);">
                        <i class="fa-solid fa-spinner fa-spin" style="font-size:1.5rem; margin-bottom:0.5rem;"></i>
                        <p style="margin:0;">Loading preview data&hellip;</p>
                    </div>
                    <!-- Table -->
                    <div id="csvPreviewTableWrap" style="display:none;">
                        <table id="csvPreviewTable" style="width:100%; border-collapse:collapse; font-size:0.85rem;">
                            <thead id="csvPreviewHead"
                                style="position:sticky; top:0; background:var(--surface); z-index:2;"></thead>
                            <tbody id="csvPreviewBody"></tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer" style="justify-content:space-between; align-items:center;">
                    <span id="csvPreviewRowCount" style="color:var(--text-secondary); font-size:0.85rem;"></span>
                    <div style="display:flex; gap:0.75rem;">
                        <button class="btn" onclick="closeModal('csvPreviewModal')">Cancel</button>
                        <button id="csvPreviewCopyBtn" class="btn"
                            style="background:var(--surface-hover); white-space:nowrap;" onclick="_copyCsvToClipboard()"
                            disabled>
                            <i class="fa-solid fa-copy"></i> Copy
                        </button>
                        <a id="csvPreviewDownloadBtn" href="#" download
                            style="display:inline-flex; align-items:center; gap:0.4rem; padding:0.6rem 1rem; border-radius:6px; font-weight:600; font-size:0.9rem; color:white; text-decoration:none; transition:opacity 0.2s;"
                            onmouseover="this.style.opacity='0.85'" onmouseout="this.style.opacity='1'">
                            <i class="fa-solid fa-file-csv"></i> Download CSV
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <div id="toast" class="toast">Action completed successfully</div>

        <!-- Shown once, briefly, right after a login/signup redirect (?login=1) —
             see the justLoggedIn JS below. Not a toast (those are for action
             confirmations); this is a one-time greeting, so it gets its own
             element rather than reusing #toast. A light backdrop rides along
             with it (toggled by the same .show class) so the card doesn't get
             lost against whatever tab happens to be underneath. -->
        <div id="welcomeFlashBackdrop" class="welcome-flash-backdrop"></div>
        <div id="welcomeFlash" class="welcome-flash">
            <img src="assets/img/invoxa-mark.svg" alt="">
            <div>
                <div class="welcome-flash-eyebrow">
                    <span class="brand-wordmark">INVOXA</span>
                </div>
                <div class="welcome-flash-title">Welcome back, <?= htmlspecialchars($_SESSION['invoxa_username'] ?? 'there') ?></div>
                <div class="welcome-flash-sub"><?= htmlspecialchars($settings['business_name'] ?? 'Invoxa') ?> ·
                    signed in <?= htmlspecialchars(date('D, M j \a\t g:ia')) ?></div>
            </div>
        </div>

        <?php $__ev = $mysqli->query("SELECT email, email_verified_at FROM invoxa_users WHERE id = " . $currentUserId)->fetch_assoc(); ?>
        <div id="onboardingModal" class="modal-overlay">
            <div class="modal" style="max-width:440px; text-align:center;">
                <div class="modal-body" style="padding-top:2.5rem;">
                    <img src="assets/img/invoxa-mark.svg" width="48" height="48" alt=""
                        style="border-radius:12px; box-shadow:0 6px 18px -4px rgba(79,124,255,0.55); margin-bottom:1rem;">
                    <div style="margin-bottom:0.75rem;"><img src="assets/img/invoxa-wordmark.svg" height="26" alt="Invoxa"
                            style="width:auto;"></div>
                    <h2 style="margin:0 0 0.5rem; font-size:1.3rem;">Welcome to Invoxa</h2>
                    <p style="color:var(--text-secondary); font-size:0.9rem; margin:0 0 1.5rem;">Your account is set up.
                        Load a set of sample clients and invoices to explore the app right away, or start from a clean
                        slate — you'll find this again under Data Management &gt; Demo Data.</p>
                    <?php if ($__ev && empty($__ev['email_verified_at'])): ?>
                    <div style="background:var(--surface-hover); border-radius:10px; padding:0.85rem 1rem; margin-bottom:1.5rem; text-align:left;">
                        <p style="color:var(--text-secondary); font-size:0.82rem; margin:0 0 0.5rem;">We sent a
                            confirmation link to <strong><?= htmlspecialchars($__ev['email']) ?></strong> — click it so
                            account recovery can reach you if you ever forget your password.</p>
                        <button class="btn" id="resendVerifyBtn" style="width:auto; margin:0; padding:0.4rem 0.8rem; font-size:0.8rem;"
                            onclick="resendVerificationEmail()">Resend confirmation email</button>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer" style="justify-content:center; gap:0.75rem;">
                    <button class="btn" onclick="closeModal('onboardingModal')">Start from scratch</button>
                    <button class="btn primary"
                        onclick="closeModal('onboardingModal'); nav('backup', true); navBackup('demo');"><i
                            class="fa-solid fa-wand-magic-sparkles"></i> Load Demo Data</button>
                </div>
            </div>
        </div>

        <!-- CRM Slide-out Drawer -->
        <div id="crmDrawer"
            style="position:fixed; top:0; right:-440px; width:420px; height:100vh; background:var(--surface); border-left:1px solid var(--border); z-index:9999; transition:right 0.3s ease; display:flex; flex-direction:column; box-shadow:-8px 0 30px rgba(0,0,0,0.4);">
            <div
                style="padding:1.5rem; border-bottom:1px solid var(--border); display:flex; justify-content:space-between; align-items:center;">
                <h3 id="crmDrawerTitle" style="margin:0; font-size:1.1rem; color:var(--text-primary);"><i
                        class="fa-solid fa-user" style="color:var(--accent); margin-right:0.5rem;"></i>Client Details
                </h3>
                <button onclick="closeCrm()"
                    style="background:none; border:none; color:var(--text-secondary); cursor:pointer; font-size:1.2rem;"><i
                        class="fa-solid fa-xmark"></i></button>
            </div>
            <div id="crmDrawerBody" style="flex:1; overflow-y:auto; padding:1.5rem;">
                <div id="crmStats" class="mobile-grid" style="display:grid; grid-template-columns:1fr 1fr; gap:1rem; margin-bottom:1.5rem;">
                </div>
                <h4
                    style="color:var(--text-secondary); font-size:0.8rem; text-transform:uppercase; letter-spacing:0.05em; margin-bottom:0.75rem;">
                    Recent Invoices</h4>
                <div id="crmRecentInvoices" style="margin-bottom:1.5rem;"></div>
                <h4
                    style="color:var(--text-secondary); font-size:0.8rem; text-transform:uppercase; letter-spacing:0.05em; margin-bottom:0.75rem;">
                    Internal Notes</h4>
                <textarea id="crmNotes" class="form-control" rows="6"
                    placeholder="Private notes about this client..."></textarea>
                <button onclick="saveCrmNotes()" class="btn primary" style="margin-top:0.75rem; width:100%;"><i
                        class="fa-solid fa-save"></i> Save Notes</button>
            </div>
        </div>
        <div id="crmOverlay" onclick="closeCrm()"
            style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.4); z-index:9998;"></div>

        <script src="assets/js/simple-datatables.js"></script>
        <script>
            const APP_CURRENCY = <?= json_encode($settings['currency'] ?? 'USD') ?>;
            let chartInstance = null, pieChartInstance = null, chartAllData = null, chartRange = '12';
            const CLIENT_COLORS = ['#3b82f6', '#10b981', '#f59e0b', '#8b5cf6', '#ef4444', '#06b6d4', '#f97316', '#84cc16', '#a855f7', '#ec4899', '#14b8a6', '#f43f5e'];
            const justLoggedIn = new URLSearchParams(window.location.search).has('login');
            const justSignedUp = new URLSearchParams(window.location.search).has('welcome');
            const defaultLandingTab = localStorage.getItem('invoxa_default_tab') || 'dashboard';
            const storedTab = justLoggedIn ? defaultLandingTab : (localStorage.getItem('activeTab') || 'dashboard');
            if (justLoggedIn) {
                localStorage.setItem('activeTab', defaultLandingTab);
                history.replaceState(null, '', window.location.pathname);
                if (justSignedUp) {
                    document.getElementById('onboardingModal')?.classList.add('active');
                } else {
                    const flash = document.getElementById('welcomeFlash');
                    const flashBackdrop = document.getElementById('welcomeFlashBackdrop');
                    if (flash && localStorage.getItem('invoxa_show_welcome') !== '0') {
                        const dismiss = () => { flash.classList.remove('show'); flashBackdrop?.classList.remove('show'); };
                        requestAnimationFrame(() => requestAnimationFrame(() => {
                            flash.classList.add('show');
                            flashBackdrop?.classList.add('show');
                        }));
                        setTimeout(dismiss, 4200);
                        flash.addEventListener('click', dismiss);
                        flashBackdrop?.addEventListener('click', dismiss);
                    }
                }
            }
            const emailVerifyParam = new URLSearchParams(window.location.search).has('email_verified') ? 'ok' : (new URLSearchParams(window.location.search).has('email_verify_failed') ? 'failed' : null);
            if (emailVerifyParam) {
                history.replaceState(null, '', window.location.pathname);
                showToast(emailVerifyParam === 'ok' ? 'Email confirmed — account recovery will reach you at that address.' : 'That confirmation link is invalid or has expired.', emailVerifyParam === 'failed');
            }

            let __chartResizeTimeout;
            window.addEventListener('resize', () => {
                clearTimeout(__chartResizeTimeout);
                __chartResizeTimeout = setTimeout(() => {
                    Object.values(Chart.instances).forEach(c => c.resize());
                }, 150);
            });

            function toggleOtherTables(section, showAll) {
                const selector = section === 'stats' ? '.stat-table-item.other-table' : '.backup-table-item.other-table';
                document.querySelectorAll(selector).forEach(el => {
                    el.style.display = showAll ? 'flex' : 'none';
                    if (section === 'backup' && !showAll) {
                        const cb = el.querySelector('input[type="checkbox"]');
                        if (cb) cb.checked = false;
                    }
                });
            }

            function toggleSidebar() {
                document.querySelector('.sidebar').classList.toggle('open');
                document.getElementById('sidebarBackdrop').classList.toggle('active');
                document.body.classList.toggle('sidebar-open');
            }

            // ── Global quick search ───────────────────────────────────────
            // Jumps to a record by re-using the target tab's own DataTable search
            // box (see filterTableSearch) rather than fetching/rendering the full
            // row itself, so it stays in sync with whatever that tab already shows.
            let __globalSearchDebounce = null;
            function handleGlobalSearch() {
                clearTimeout(__globalSearchDebounce);
                const q = document.getElementById('globalSearchInput').value.trim();
                const resultsEl = document.getElementById('globalSearchResults');
                if (q.length < 2) {
                    resultsEl.classList.remove('active');
                    resultsEl.innerHTML = '';
                    return;
                }
                __globalSearchDebounce = setTimeout(async () => {
                    const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'global_search', q }) });
                    const json = await res.json();
                    if (json.success) renderGlobalSearchResults(json);
                }, 250);
            }
            function positionGlobalSearchResults() {
                const input = document.getElementById('globalSearchInput');
                const resultsEl = document.getElementById('globalSearchResults');
                const rect = input.getBoundingClientRect();
                resultsEl.style.left = rect.left + 'px';
                resultsEl.style.top = (rect.bottom + 6) + 'px';
                resultsEl.style.width = rect.width + 'px';
            }
            function _escHtml(s) {
                return (s || '').toString().replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
            }
            function renderGlobalSearchResults(json) {
                const resultsEl = document.getElementById('globalSearchResults');
                const groups = [];
                if (json.invoices.length) {
                    groups.push('<div class="global-search-group-label">Invoices &amp; Quotes</div>' + json.invoices.map(inv => `
                        <div class="global-search-result" data-jump="invoice" data-value="${_escHtml(inv.invoice_number)}" data-quote="${inv.is_quote}">
                            <span><strong>${_escHtml(inv.invoice_number)}</strong> — ${_escHtml(inv.client_name)}</span>
                            <span style="color:var(--text-secondary); font-size:0.8rem;">$${parseFloat(inv.amount).toFixed(2)}</span>
                        </div>
                    `).join(''));
                }
                if (json.clients.length) {
                    groups.push('<div class="global-search-group-label">Clients</div>' + json.clients.map(c => `
                        <div class="global-search-result" data-jump="client" data-value="${_escHtml(c.client_name)}">
                            <span><strong>${_escHtml(c.client_name)}</strong></span>
                            <span style="color:var(--text-secondary); font-size:0.8rem;">${_escHtml(c.email)}</span>
                        </div>
                    `).join(''));
                }
                if (json.expenses.length) {
                    groups.push('<div class="global-search-group-label">Expenses</div>' + json.expenses.map(e => `
                        <div class="global-search-result" data-jump="expense" data-value="${_escHtml(e.vendor)}">
                            <span><strong>${_escHtml(e.vendor)}</strong> — ${_escHtml((e.expense_date || '').substring(0, 10))}</span>
                            <span style="color:var(--text-secondary); font-size:0.8rem;">$${parseFloat(e.amount).toFixed(2)}</span>
                        </div>
                    `).join(''));
                }
                resultsEl.innerHTML = groups.length ? groups.join('') : '<div class="global-search-empty">No matches</div>';
                positionGlobalSearchResults();
                resultsEl.classList.add('active');
            }
            function closeGlobalSearch() {
                document.getElementById('globalSearchResults').classList.remove('active');
            }
            function filterTableSearch(which, value) {
                const wrapper = document.querySelector('#sec-' + which + ' .datatable-wrapper');
                const input = wrapper && wrapper.querySelector('input.datatable-input');
                if (!input) return;
                input.value = value;
                input.dispatchEvent(new Event('input', { bubbles: true }));
            }
            // nav(which, true) kicks off an async refreshTable() in the background
            // (destroys and recreates the DataTable, wiping any search box value set
            // before it lands) — waits for that tbody swap to actually happen before
            // filtering, instead of guessing at a fixed delay that could land either
            // side of it.
            const __tbodyIdsByTab = { invoices: 'invoicesTbody', clients: 'clientsTbody', quotes: 'quotesTbody', expenses: 'expensesTbody' };
            function waitForTableRefresh(which, maxWaitMs = 1500) {
                return new Promise(resolve => {
                    const tbody = document.getElementById(__tbodyIdsByTab[which]);
                    if (!tbody) return resolve();
                    let done = false;
                    const finish = () => { if (!done) { done = true; observer.disconnect(); resolve(); } };
                    const observer = new MutationObserver(finish);
                    observer.observe(tbody, { childList: true });
                    setTimeout(finish, maxWaitMs);
                });
            }
            document.getElementById('globalSearchResults').addEventListener('click', (e) => {
                const item = e.target.closest('.global-search-result');
                if (!item) return;
                const type = item.dataset.jump;
                const value = item.dataset.value;
                closeGlobalSearch();
                document.getElementById('globalSearchInput').value = '';
                if (type === 'invoice') {
                    const which = item.dataset.quote === '1' ? 'quotes' : 'invoices';
                    nav(which, true);
                    waitForTableRefresh(which).then(() => filterTableSearch(which, value));
                } else if (type === 'client') {
                    nav('clients', true);
                    waitForTableRefresh('clients').then(() => filterTableSearch('clients', value));
                } else if (type === 'expense') {
                    nav('expenses', true);
                    waitForTableRefresh('expenses').then(() => filterTableSearch('expenses', value));
                }
            });
            function handleGlobalSearchKeydown(event) {
                if (event.key === 'Escape') { closeGlobalSearch(); event.target.blur(); }
            }
            document.addEventListener('click', (e) => {
                const wrap = document.querySelector('.global-search-wrap');
                if (wrap && !wrap.contains(e.target)) closeGlobalSearch();
            });
            window.addEventListener('resize', () => {
                if (document.getElementById('globalSearchResults').classList.contains('active')) positionGlobalSearchResults();
            });
            document.addEventListener('keydown', (e) => {
                if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
                    e.preventDefault();
                    const input = document.getElementById('globalSearchInput');
                    input.focus();
                    input.select();
                }
            });

            function nav(section, fromClick = false) {
                if (fromClick) {
                    document.querySelector('.sidebar').classList.remove('open');
                    document.getElementById('sidebarBackdrop').classList.remove('active');
                    document.body.classList.remove('sidebar-open');
                    document.querySelectorAll('.modal-overlay.active').forEach(el => el.classList.remove('active'));
                }
                document.querySelectorAll('.nav-item').forEach(el => el.classList.remove('active'));
                document.querySelector('.nav-item[data-target="' + section + '"]').classList.add('active');
                document.querySelectorAll('.mobile-bottom-nav-item').forEach(el => el.classList.toggle('active', el.dataset.target === section));
                document.querySelectorAll('.section').forEach(el => el.classList.remove('active'));
                document.getElementById('sec-' + section).classList.add('active');
                localStorage.setItem('activeTab', section);
                // The automatic nav(storedTab) call at page load just draws the chart
                // from server-rendered data; an actual click triggers a full refresh below.
                if (section === 'dashboard' && !fromClick) initChart();
                if (section === 'backup') loadBackupList();
                // Re-fetch the tab's content in the background, but only on an actual
                // click — the page-load nav(storedTab) call already has fresh data.
                if (fromClick && (section === 'invoices' || section === 'clients' || section === 'quotes' || section === 'expenses')) refreshTable(section);
                if (fromClick && section === 'dashboard') refreshDashboard();
                if (fromClick && section === 'stats') refreshStatsSection();
                if (fromClick && section === 'sync') refreshSync();
                if (fromClick && section === 'audit') refreshAuditSection();
                // simple-datatables miscalculates column widths for any table built or
                // resized while its tab was hidden (display:none gives a zero-width
                // container). Firing resize right after the tab becomes visible makes
                // it recompute against the real size and self-heal the layout.
                requestAnimationFrame(() => window.dispatchEvent(new Event('resize')));
            }
            nav(storedTab);
            if (storedTab === 'dashboard') requestAnimationFrame(() => setTimeout(initChart, 50));
            if (storedTab === 'backup') loadBackupList();

            // Settings and Docs each get their own mini nav (mirrors the main sidebar
            // nav()/`.section` pattern, nested one level deeper).
            function navSettings(target) {
                document.querySelectorAll('#sec-settings .subnav-item').forEach(el => el.classList.toggle('active', el.dataset.settingsTarget === target));
                document.querySelectorAll('#sec-settings .subnav-pane').forEach(el => el.classList.toggle('active', el.id === 'settings-pane-' + target));
                localStorage.setItem('settingsSubTab', target);
            }
            const storedSettingsTab = localStorage.getItem('settingsSubTab');
            if (storedSettingsTab && document.getElementById('settings-pane-' + storedSettingsTab)) navSettings(storedSettingsTab);

            function navDocs(target) {
                document.querySelectorAll('#sec-docs .subnav-item').forEach(el => el.classList.toggle('active', el.dataset.docsTarget === target));
                document.querySelectorAll('#sec-docs .subnav-pane').forEach(el => el.classList.toggle('active', el.id === 'docs-pane-' + target));
                localStorage.setItem('docsSubTab', target);
            }
            const storedDocsTab = localStorage.getItem('docsSubTab');
            if (storedDocsTab && document.getElementById('docs-pane-' + storedDocsTab)) navDocs(storedDocsTab);

            // "Fuzzy" means word-order-independent AND matching, not typo-tolerance:
            // each search term must appear somewhere in the page's title+content,
            // in any order. Reads the already-rendered (hidden) page markup directly
            // rather than maintaining a separate search index.
            function filterDocsNav() {
                const terms = document.getElementById('docsSearchInput').value.trim().toLowerCase().split(/\s+/).filter(Boolean);
                let anyVisible = false;
                document.querySelectorAll('#docsNav .docs-nav-category').forEach(catEl => {
                    let catHasVisible = false;
                    catEl.querySelectorAll('.docs-nav-page').forEach(pageEl => {
                        const pageId = pageEl.dataset.docsTarget;
                        const title = pageEl.dataset.title || '';
                        const paneEl = document.getElementById('docs-pane-' + pageId);
                        const content = paneEl ? paneEl.textContent.toLowerCase() : '';
                        const haystack = title + ' ' + content;
                        const match = terms.length === 0 || terms.every(term => haystack.includes(term));
                        pageEl.style.display = match ? '' : 'none';
                        if (match) { catHasVisible = true; anyVisible = true; }
                    });
                    catEl.style.display = catHasVisible ? '' : 'none';
                });
                document.getElementById('docsNoResults').style.display = anyVisible ? 'none' : '';
            }

            function navBackup(target) {
                document.querySelectorAll('#sec-backup .subnav-item').forEach(el => el.classList.toggle('active', el.dataset.backupTarget === target));
                document.querySelectorAll('#sec-backup .subnav-pane').forEach(el => el.classList.toggle('active', el.id === 'backup-pane-' + target));
                localStorage.setItem('backupSubTab', target);
            }
            const storedBackupTab = localStorage.getItem('backupSubTab');
            if (storedBackupTab && document.getElementById('backup-pane-' + storedBackupTab)) navBackup(storedBackupTab);

            // Statistics rebuilds its whole tab body from scratch on every visit (see
            // refreshStatsSection() below), so this also gets called after each of
            // those refreshes, unlike Settings/Backup/Docs which only restore their
            // sub-tab once at page load.
            let __statsChartsInit = {};
            function initStatsChartsFor(target) {
                // Lazy, per-tab, and only once — a Chart.js canvas sizes itself to zero
                // while its pane is still display:none, so each chart is only created
                // the first time its tab actually becomes visible.
                if (__statsChartsInit[target] || typeof Chart === 'undefined') return;
                if (target === 'revenue') {
                    __statsChartsInit.revenue = true;
                    if (window.__revenueBreakdownData && document.getElementById('revenueBreakdownChart')) {
                        const d = window.__revenueBreakdownData;
                        new Chart(document.getElementById('revenueBreakdownChart').getContext('2d'), {
                            type: 'bar',
                            data: { labels: ['Invoiced', 'Paid', 'Outstanding'], datasets: [{ data: [d.invoiced, d.paid, d.outstanding], backgroundColor: ['#3b82f6', '#10b981', '#f59e0b'] }] },
                            options: { responsive: true, maintainAspectRatio: false, indexAxis: 'y', plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true } } }
                        });
                    }
                    if (window.__invoiceStatusData && document.getElementById('invoiceStatusChart')) {
                        const rows = window.__invoiceStatusData;
                        new Chart(document.getElementById('invoiceStatusChart').getContext('2d'), {
                            type: 'doughnut',
                            data: { labels: rows.map(r => r.label), datasets: [{ data: rows.map(r => r.amount), backgroundColor: rows.map(r => r.color) }] },
                            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'right' } }, cutout: '60%' }
                        });
                    }
                    if (window.__revenueTrendData && document.getElementById('revenueTrendChart')) {
                        const rows = window.__revenueTrendData;
                        new Chart(document.getElementById('revenueTrendChart').getContext('2d'), {
                            type: 'line',
                            data: {
                                labels: rows.map(r => r.month),
                                datasets: [
                                    { label: 'Invoiced', data: rows.map(r => r.total_invoiced), borderColor: '#3b82f6', backgroundColor: 'rgba(59,130,246,0.1)', tension: 0.3, fill: true },
                                    { label: 'Paid', data: rows.map(r => r.total_paid), borderColor: '#10b981', backgroundColor: 'rgba(16,185,129,0.1)', tension: 0.3, fill: true }
                                ]
                            },
                            options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true } } }
                        });
                    }
                }
                if (target === 'forecasting' && window.__arAgingData && document.getElementById('arAgingChart')) {
                    __statsChartsInit.forecasting = true;
                    const rows = window.__arAgingData;
                    new Chart(document.getElementById('arAgingChart').getContext('2d'), {
                        type: 'bar',
                        data: { labels: rows.map(r => r.label), datasets: [{ data: rows.map(r => r.amount), backgroundColor: rows.map(r => r.color) }] },
                        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
                    });
                }
                if (target === 'expenses') {
                    __statsChartsInit.expenses = true;
                    if (window.__expenseCategoryData && document.getElementById('expenseCategoryChart')) {
                        const rows = window.__expenseCategoryData;
                        new Chart(document.getElementById('expenseCategoryChart').getContext('2d'), {
                            type: 'doughnut',
                            data: { labels: rows.map(r => r.label), datasets: [{ data: rows.map(r => r.total), backgroundColor: ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#f97316', '#06b6d4', '#ec4899', '#84cc16', '#6b7280'] }] },
                            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'right' } }, cutout: '60%' }
                        });
                    }
                    if (window.__expenseTrendData && document.getElementById('expenseTrendChart')) {
                        const rows = window.__expenseTrendData;
                        new Chart(document.getElementById('expenseTrendChart').getContext('2d'), {
                            type: 'bar',
                            data: { labels: rows.map(r => r.month), datasets: [{ label: 'Expenses', data: rows.map(r => r.total), backgroundColor: '#ef4444' }] },
                            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
                        });
                    }
                }
                if (target === 'tax' && window.__taxMonthlyData && document.getElementById('taxMonthlyChart')) {
                    __statsChartsInit.tax = true;
                    const rows = window.__taxMonthlyData;
                    if (rows.length) {
                        new Chart(document.getElementById('taxMonthlyChart').getContext('2d'), {
                            type: 'bar',
                            data: {
                                labels: rows.map(r => r.month),
                                datasets: [
                                    { label: 'Invoiced', data: rows.map(r => r.total_invoiced), backgroundColor: '#3b82f6' },
                                    { label: 'Paid', data: rows.map(r => r.total_paid), backgroundColor: '#10b981' }
                                ]
                            },
                            options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true } } }
                        });
                    }
                }
                if (target === 'clients' && window.__topClientsData && document.getElementById('topClientsChart')) {
                    __statsChartsInit.clients = true;
                    const rows = window.__topClientsData;
                    new Chart(document.getElementById('topClientsChart').getContext('2d'), {
                        type: 'bar',
                        data: { labels: rows.map(r => r.name), datasets: [{ label: 'Paid Revenue', data: rows.map(r => r.revenue), backgroundColor: '#10b981' }] },
                        options: { responsive: true, maintainAspectRatio: false, indexAxis: 'y', plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true } } }
                    });
                }
                if (target === 'activity' && window.__activeClientsData && document.getElementById('activeClientsChart')) {
                    __statsChartsInit.activity = true;
                    const rows = window.__activeClientsData;
                    new Chart(document.getElementById('activeClientsChart').getContext('2d'), {
                        type: 'bar',
                        data: { labels: rows.map(r => r.name), datasets: [{ label: 'Invoices', data: rows.map(r => r.count), backgroundColor: '#3b82f6' }] },
                        options: { responsive: true, maintainAspectRatio: false, indexAxis: 'y', plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true, ticks: { precision: 0 } } } }
                    });
                }
                if (target === 'system') {
                    __statsChartsInit.system = true;
                    if (window.__emailHealthData && document.getElementById('emailHealthChart')) {
                        const d = window.__emailHealthData;
                        new Chart(document.getElementById('emailHealthChart').getContext('2d'), {
                            type: 'doughnut',
                            data: { labels: ['Sent', 'Failed'], datasets: [{ data: [d.sent, d.failed], backgroundColor: ['#10b981', '#ef4444'] }] },
                            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, cutout: '65%' }
                        });
                    }
                    if (window.__storageFootprintData && document.getElementById('storageFootprintChart')) {
                        const d = window.__storageFootprintData;
                        new Chart(document.getElementById('storageFootprintChart').getContext('2d'), {
                            type: 'bar',
                            data: { labels: [d.labels.db, d.labels.invoices, d.labels.backups], datasets: [{ data: [d.db, d.invoices, d.backups], backgroundColor: ['#3b82f6', '#10b981', '#f59e0b'] }] },
                            options: { responsive: true, maintainAspectRatio: false, indexAxis: 'y', plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true } } }
                        });
                    }
                }
            }
            function navStats(target) {
                document.querySelectorAll('#sec-stats .subnav-item').forEach(el => el.classList.toggle('active', el.dataset.statsTarget === target));
                document.querySelectorAll('#sec-stats .subnav-pane').forEach(el => el.classList.toggle('active', el.id === 'stats-pane-' + target));
                localStorage.setItem('statsSubTab', target);
                initStatsChartsFor(target);
            }
            const storedStatsTab = localStorage.getItem('statsSubTab');
            if (storedStatsTab && document.getElementById('stats-pane-' + storedStatsTab)) navStats(storedStatsTab);
            else initStatsChartsFor('revenue');

            function toggleToolbar(name) {
                const wrap = document.getElementById(name + 'ToolbarGroups');
                const btn = document.getElementById(name + 'ToolbarToggle');
                if (!wrap) return;
                const isExpanded = wrap.classList.toggle('expanded');
                if (btn) btn.classList.toggle('expanded', isExpanded);
            }

            const subnavSections = ['stats', 'docs', 'backup', 'settings'];
            const mobileMq = window.matchMedia('(max-width: 860px)');
            function placeSubnavs(isMobile) {
                subnavSections.forEach(name => {
                    const subnavEl = document.querySelector('#sec-' + name + ' .subnav, .nav-subnav-slot[data-for="' + name + '"] .subnav');
                    const slotEl = document.querySelector('.nav-subnav-slot[data-for="' + name + '"]');
                    const layoutEl = document.querySelector('#sec-' + name + ' .subnav-layout');
                    if (!subnavEl || !slotEl || !layoutEl) return;
                    if (isMobile) {
                        slotEl.replaceChildren(subnavEl);
                    } else {
                        layoutEl.insertBefore(subnavEl, layoutEl.firstChild);
                        slotEl.classList.remove('expanded');
                        const toggleEl = document.querySelector('.nav-item[data-target="' + name + '"] .nav-subnav-toggle');
                        if (toggleEl) toggleEl.classList.remove('expanded');
                    }
                });
            }
            placeSubnavs(mobileMq.matches);
            mobileMq.addEventListener('change', e => placeSubnavs(e.matches));

            function toggleNavSubnav(name) {
                const slotEl = document.querySelector('.nav-subnav-slot[data-for="' + name + '"]');
                const toggleEl = document.querySelector('.nav-item[data-target="' + name + '"] .nav-subnav-toggle');
                const isExpanded = slotEl.classList.toggle('expanded');
                if (toggleEl) toggleEl.classList.toggle('expanded', isExpanded);
            }

            subnavSections.forEach(name => {
                const slotEl = document.querySelector('.nav-subnav-slot[data-for="' + name + '"]');
                if (!slotEl) return;
                slotEl.addEventListener('click', e => {
                    if (e.target.closest('.subnav-item')) {
                        nav(name, true);
                        slotEl.classList.remove('expanded');
                        const toggleEl = document.querySelector('.nav-item[data-target="' + name + '"] .nav-subnav-toggle');
                        if (toggleEl) toggleEl.classList.remove('expanded');
                    }
                }, true);
            });

            // A function, not a cached value — re-reads localStorage on every table
            // (re)build so a changed Default Page Size setting applies on the next
            // tab visit instead of requiring a hard refresh.
            const tblEmptyMessages = {
                invoices: 'No invoices yet — create one to get started.',
                clients: 'No clients yet — add your first client to get started.',
                quotes: 'No quotes yet — save one as a quote instead of sending it.',
                expenses: 'No expenses logged yet.',
            };
            function getTblOpts(which) {
                const preferredPageSize = parseInt(localStorage.getItem('invoxa_table_page_size'), 10) || 12;
                return { searchable: true, fixedHeight: false, perPage: preferredPageSize, perPageSelect: [12, 30, 50, 99999], labels: { noRows: tblEmptyMessages[which] || 'No entries found' } };
            }
            const dataTables = {};
            if (document.getElementById("invoicesTable")) dataTables.invoices = new simpleDatatables.DataTable("#invoicesTable", getTblOpts('invoices'));
            if (document.getElementById("clientsTable")) dataTables.clients = new simpleDatatables.DataTable("#clientsTable", getTblOpts('clients'));
            if (document.getElementById("quotesTable")) dataTables.quotes = new simpleDatatables.DataTable("#quotesTable", getTblOpts('quotes'));
            if (document.getElementById("expensesTable")) dataTables.expenses = new simpleDatatables.DataTable("#expensesTable", getTblOpts('expenses'));
            setTimeout(() => { document.querySelectorAll('.datatable-selector option').forEach(opt => { if (opt.value == "99999") opt.textContent = "All"; }); }, 100);

            // Background refresh for the Invoices/Clients/Quotes tabs (see nav() above) —
            // fetches the tab's <tr> rows from ?api=table_html, swaps them in, and
            // reinitializes the DataTable plugin (destroy+recreate, same as first init).
            const tbodyIds = { invoices: 'invoicesTbody', clients: 'clientsTbody', quotes: 'quotesTbody', expenses: 'expensesTbody' };
            async function refreshTable(which) {
                const tbodyId = tbodyIds[which];
                if (!tbodyId) return;
                const cardEl = document.getElementById(tbodyId).closest('.card');
                if (cardEl) cardEl.classList.add('table-refreshing');
                try {
                    const res = await fetch('?api=table_html&which=' + which);
                    const html = await res.text();
                    if (dataTables[which]) dataTables[which].destroy();
                    document.getElementById(tbodyId).innerHTML = html;
                    dataTables[which] = new simpleDatatables.DataTable('#' + which + 'Table', getTblOpts(which));
                    document.querySelectorAll('#sec-' + which + ' .datatable-selector option').forEach(opt => { if (opt.value == "99999") opt.textContent = "All"; });
                } catch (e) {
                    // Silent by design — a failed background refresh leaves the existing,
                    // still-valid (if slightly stale) table in place rather than surfacing
                    // an error for a refresh the user didn't explicitly wait on.
                } finally {
                    if (cardEl) cardEl.classList.remove('table-refreshing');
                }
            }

            // Refreshes the alert strips, top stat cards, and Recent Activity list, and
            // forces the chart to refetch (bypassing initChart's cache via `force`).
            // The canvases themselves are left alone — renderChart() just redraws into
            // the existing ones, so no Chart.js instances need destroying/recreating.
            async function refreshDashboard() {
                try {
                    const [statsHtml, activityHtml] = await Promise.all([
                        fetch('?api=table_html&which=dashboard_stats').then(r => r.text()),
                        fetch('?api=table_html&which=activity').then(r => r.text()),
                    ]);
                    document.getElementById('dashboardStatsWrap').innerHTML = statsHtml;
                    document.getElementById('activityTbody').innerHTML = activityHtml;
                    initChart(true);
                } catch (e) {
                    // Silent by design, same reasoning as refreshTable() above.
                }
            }

            // Statistics and Sync tabs are read-only content with no DataTable-managed
            // tables, so refreshing just swaps the tab body's innerHTML wholesale.
            async function refreshStatsSection() {
                try {
                    const html = await fetch('?api=table_html&which=stats_section').then(r => r.text());
                    document.getElementById('sec-stats').innerHTML = html;
                    // Fresh canvases means old Chart.js instances are orphaned. The
                    // <script> tags in the fetched HTML don't execute via innerHTML, so
                    // window.__*Data isn't refreshed — charts re-created here show stale
                    // data, which is fine for a background poll no one is watching live.
                    __statsChartsInit = {};
                    // The fresh markup defaults to its first sub-tab — reapply the last-selected one.
                    const stored = localStorage.getItem('statsSubTab');
                    if (stored && document.getElementById('stats-pane-' + stored)) navStats(stored);
                    else initStatsChartsFor('revenue');
                    placeSubnavs(mobileMq.matches);
                } catch (e) {
                    // Silent by design, same reasoning as refreshTable() above.
                }
            }
            async function refreshSync() {
                try {
                    const html = await fetch('?api=table_html&which=sync_section').then(r => r.text());
                    document.getElementById('sec-sync').innerHTML = html;
                } catch (e) {
                    // Silent by design, same reasoning as refreshTable() above.
                }
            }
            async function refreshAuditSection() {
                try {
                    const html = await fetch('?api=table_html&which=audit_section').then(r => r.text());
                    document.getElementById('sec-audit').innerHTML = html;
                } catch (e) {
                    // Silent by design, same reasoning as refreshTable() above.
                }
            }
            // Client-side show/hide over the (max 200) rendered timeline items. data-search
            // is a pre-lowercased blob (client name + invoice # + type + notes) baked in
            // server-side per item; data-action-type backs the dropdown since "Overdue"
            // etc. aren't literal stored values, same as the Invoices status filter.
            function filterAuditLog() {
                const q = document.getElementById('auditSearchInput').value.trim().toLowerCase();
                const type = document.getElementById('auditTypeFilter').value;
                const items = document.querySelectorAll('#auditTimelineBody .timeline-item');
                let visible = 0;
                items.forEach(item => {
                    const show = (!type || item.dataset.actionType === type) && (!q || item.dataset.search.includes(q));
                    item.style.display = show ? '' : 'none';
                    if (show) visible++;
                });
                const noResults = document.getElementById('auditNoResults');
                if (noResults) noResults.style.display = visible === 0 ? '' : 'none';
            }

            function closeModal(id) { document.getElementById(id).classList.remove('active'); if (id === 'noteModal' && window._notePageNeedsReload) { window._notePageNeedsReload = false; window.location.reload(); } requestAnimationFrame(() => window.dispatchEvent(new Event('resize'))); }
            // Close any modal when clicking the backdrop (outside .modal-body)
            document.addEventListener('click', function (e) {
                if (e.target.classList.contains('modal-overlay') && e.target.classList.contains('active')) {
                    closeModal(e.target.id);
                }
            });
            function showToast(msg, isError = false) {
                const t = document.getElementById('toast');
                t.textContent = msg; t.className = 'toast show' + (isError ? ' error' : '');
                setTimeout(() => t.className = 'toast', 3000);
            }

            // Client CRUD
            function openClientModal(c = null) {
                document.getElementById('clientModalTitle').textContent = c ? 'Edit Client' : 'Add Client';
                document.getElementById('clientId').value = c ? c.id : '';
                document.getElementById('clientName').value = c ? c.client_name : '';
                document.getElementById('clientEmail').value = c ? c.email : '';
                document.getElementById('clientPhone').value = c ? (c.phone || '') : '';
                document.getElementById('clientAddress').value = c ? (c.address || '') : '';
                document.getElementById('clientRate').value = c ? c.monthly_rate : '0.00';
                document.getElementById('clientCurrency').value = c ? (c.currency || '') : '';
                document.getElementById('clientBillingFrequency').value = c ? c.billing_frequency : 'monthly';
                document.getElementById('clientPaymentTerms').value = c ? c.payment_terms_days : '21';
                document.getElementById('clientDiscountPct').value = c ? c.discount_pct : '0';
                document.getElementById('clientTaxRate').value = c ? c.tax_rate : '0';
                document.getElementById('clientAccName').value = c ? c.account_name : '';
                document.getElementById('clientAccNum').value = c ? c.account_number : '';
                document.getElementById('clientActive').checked = c ? c.is_active == 1 : true;
                document.getElementById('clientTest').checked = c ? c.is_test == 1 : false;
                // Portal section only makes sense once the client actually exists (a
                // token needs a client id to attach to) — hidden entirely on Add Client.
                document.getElementById('clientPortalSection').style.display = c ? '' : 'none';
                if (c && c.portal_token) {
                    document.getElementById('clientPortalUrl').value = window.location.origin + '/?portal=' + c.portal_token;
                    document.getElementById('clientPortalExpiryNote').textContent = c.portal_token_expires_at
                        ? 'Expires ' + new Date(c.portal_token_expires_at.replace(' ', 'T')).toLocaleDateString() : 'Never expires';
                    document.getElementById('clientPortalNoLinkWrap').style.display = 'none';
                    document.getElementById('clientPortalLinkWrap').style.display = '';
                } else {
                    document.getElementById('clientPortalNoLinkWrap').style.display = '';
                    document.getElementById('clientPortalLinkWrap').style.display = 'none';
                }
                document.getElementById('clientModal').classList.add('active');
            }
            async function generatePortalLink() {
                const id = document.getElementById('clientId').value;
                if (!id) return;
                const hasLink = document.getElementById('clientPortalLinkWrap').style.display !== 'none';
                const expiry = document.getElementById(hasLink ? 'clientPortalRegenExpiry' : 'clientPortalExpiry').value;
                const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'generate_portal_token', id, expiry }) });
                const json = await res.json();
                if (!json.success) return showToast(json.error || 'Failed to generate link', true);
                document.getElementById('clientPortalUrl').value = window.location.origin + '/?portal=' + json.token;
                const labels = { never: 'Never expires', '30': 'Expires in 30 days', '90': 'Expires in 90 days', '365': 'Expires in 1 year' };
                document.getElementById('clientPortalExpiryNote').textContent = labels[expiry] || '';
                document.getElementById('clientPortalNoLinkWrap').style.display = 'none';
                document.getElementById('clientPortalLinkWrap').style.display = '';
                showToast('Portal link generated!');
            }
            async function revokePortalLink() {
                const id = document.getElementById('clientId').value;
                if (!id) return;
                if (!confirm('Revoke this client\'s portal link? The old link will stop working immediately.')) return;
                const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'revoke_portal_token', id }) });
                const json = await res.json();
                if (!json.success) return showToast(json.error || 'Failed to revoke link', true);
                document.getElementById('clientPortalNoLinkWrap').style.display = '';
                document.getElementById('clientPortalLinkWrap').style.display = 'none';
                showToast('Portal link revoked.');
            }
            function copyPortalLink() {
                const input = document.getElementById('clientPortalUrl');
                input.select();
                navigator.clipboard ? navigator.clipboard.writeText(input.value).then(() => showToast('Link copied!')) : document.execCommand('copy');
            }
            async function saveClient() {
                const btn = document.getElementById('saveClientBtn'); btn.disabled = true;
                const data = new URLSearchParams({
                    action: 'save_client', id: document.getElementById('clientId').value, client_name: document.getElementById('clientName').value,
                    email: document.getElementById('clientEmail').value, phone: document.getElementById('clientPhone').value,
                    address: document.getElementById('clientAddress').value, monthly_rate: document.getElementById('clientRate').value,
                    currency: document.getElementById('clientCurrency').value,
                    billing_frequency: document.getElementById('clientBillingFrequency').value,
                    payment_terms_days: document.getElementById('clientPaymentTerms').value,
                    discount_pct: document.getElementById('clientDiscountPct').value || '0',
                    tax_rate: document.getElementById('clientTaxRate').value || '0',
                    account_name: document.getElementById('clientAccName').value, account_number: document.getElementById('clientAccNum').value,
                    is_active: document.getElementById('clientActive').checked ? 1 : 0, is_test: document.getElementById('clientTest').checked ? 1 : 0
                });
                const res = await fetch('', { method: 'POST', body: data }); const json = await res.json();
                if (json.success) { showToast('Client saved!'); setTimeout(() => window.location.reload(), 1000); } else { showToast(json.error, true); btn.disabled = false; }
            }
            async function deleteClient(id) {
                if (!confirm("Are you sure you want to delete this client?")) return;
                const data = new URLSearchParams({ action: 'delete_client', id: id });
                const res = await fetch('', { method: 'POST', body: data }); const json = await res.json();
                if (json.success) { showToast('Client deleted!'); setTimeout(() => window.location.reload(), 1000); } else showToast(json.error, true);
            }
            function openExpenseModal(e = null) {
                document.getElementById('expenseModalTitle').textContent = e ? 'Edit Expense' : 'Add Expense';
                document.getElementById('expenseId').value = e ? e.id : '';
                document.getElementById('expenseDate').value = e ? e.expense_date.substring(0, 10) : new Date().toISOString().substring(0, 10);
                document.getElementById('expenseDateIso').textContent = document.getElementById('expenseDate').value;
                document.getElementById('expenseVendor').value = e ? e.vendor : '';
                document.getElementById('expenseCategory').value = e ? e.category : 'other';
                document.getElementById('expenseAmount').value = e ? e.amount : '0.00';
                document.getElementById('expenseDescription').value = e ? (e.description || '') : '';
                document.getElementById('expenseInvoiceFiles').value = '';
                document.getElementById('expenseInvoiceFilesList').innerHTML = '';
                document.getElementById('expenseReceiptFiles').value = '';
                document.getElementById('expenseReceiptsList').innerHTML = '';
                document.getElementById('expenseOcrStatus').style.display = 'none';
                document.getElementById('expenseModal').classList.add('active');
                if (e && e.id) loadExpenseReceipts(e.id);
            }
            function _renderExpenseFileList(files, expenseId) {
                if (!files.length) return '';
                return files.map(r => {
                    const target = r.doc_type === 'invoice' ? 'receipt' : 'invoice';
                    return `
                    <div style="display:flex; align-items:center; justify-content:space-between; gap:0.75rem; padding:0.4rem 0; border-bottom:1px solid var(--border);">
                        <a href="${r.url}" target="_blank" style="color:var(--text-primary); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; font-size:0.85rem;"><i class="fa-solid fa-paperclip"></i> ${r.filename}</a>
                        <div style="display:flex; align-items:center; gap:0.5rem; white-space:nowrap;">
                            <span style="color:var(--text-secondary); font-size:0.75rem;">${_formatFileSize(r.file_size)}</span>
                            <button type="button" class="btn small" title="Move to ${target === 'invoice' ? 'Invoice' : 'Receipt'}" onclick="moveExpenseReceipt(${r.id}, ${expenseId}, '${target}')"><i class="fa-solid fa-right-left"></i></button>
                            <button type="button" class="btn small danger" onclick="deleteExpenseReceipt(${r.id}, ${expenseId})"><i class="fa-solid fa-trash"></i></button>
                        </div>
                    </div>
                `;
                }).join('');
            }
            async function loadExpenseReceipts(expenseId) {
                const invoiceList = document.getElementById('expenseInvoiceFilesList');
                const receiptList = document.getElementById('expenseReceiptsList');
                receiptList.innerHTML = '<p style="color:var(--text-secondary); font-size:0.85rem; margin:0;">Loading…</p>';
                const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'get_expense_receipts', expense_id: expenseId }) });
                const json = await res.json();
                if (!json.success) { invoiceList.innerHTML = ''; receiptList.innerHTML = ''; return; }
                invoiceList.innerHTML = _renderExpenseFileList(json.receipts.filter(r => r.doc_type === 'invoice'), expenseId);
                receiptList.innerHTML = _renderExpenseFileList(json.receipts.filter(r => r.doc_type !== 'invoice'), expenseId);
            }
            async function deleteExpenseReceipt(id, expenseId) {
                if (!confirm('Delete this receipt?')) return;
                const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'delete_expense_receipt', id: id }) });
                const json = await res.json();
                if (json.success) { showToast('Receipt deleted!'); await loadExpenseReceipts(expenseId); refreshTable('expenses'); }
                else showToast(json.error || 'Failed to delete', true);
            }
            async function moveExpenseReceipt(id, expenseId, docType) {
                const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'move_expense_receipt', id: id, doc_type: docType }) });
                const json = await res.json();
                if (json.success) { showToast(`Moved to ${docType === 'invoice' ? 'Invoice' : 'Receipt'}!`); await loadExpenseReceipts(expenseId); }
                else showToast(json.error || 'Failed to move', true);
            }
            async function handleExpenseReceiptFilesChange() {
                const files = Array.from(document.getElementById('expenseReceiptFiles').files).filter(f => /^image\//.test(f.type));
                const statusEl = document.getElementById('expenseOcrStatus');
                if (!files.length) { statusEl.style.display = 'none'; return; }
                const vendorField = document.getElementById('expenseVendor');
                const amountField = document.getElementById('expenseAmount');
                const vendorEmpty = vendorField.value.trim() === '';
                const amountEmpty = amountField.value.trim() === '' || parseFloat(amountField.value) === 0;
                if (!vendorEmpty && !amountEmpty) { statusEl.style.display = 'none'; return; }
                statusEl.textContent = 'Reading receipt' + (files.length > 1 ? 's' : '') + '…';
                statusEl.style.display = '';
                try {
                    const results = await Promise.all(files.map(async file => {
                        const formData = new FormData();
                        formData.append('action', 'ocr_expense_receipt');
                        formData.append('file', file);
                        const res = await fetch('', { method: 'POST', body: formData });
                        return res.json();
                    }));
                    // With more than one file attached (e.g. a vendor invoice plus the
                    // actual payment receipt), prefer whichever result found a line
                    // genuinely labeled TOTAL over one that just guessed the largest
                    // number — that's the one more likely to be the real receipt.
                    const usable = results.filter(r => r.success && (r.vendor || r.amount));
                    const best = usable.find(r => r.confident) || usable[0];
                    if (best) {
                        if (vendorEmpty && best.vendor) vendorField.value = best.vendor;
                        if (amountEmpty && best.amount) amountField.value = best.amount.toFixed(2);
                        statusEl.textContent = 'Prefilled from the receipt — double-check before saving.';
                    } else {
                        const firstError = results.find(r => !r.success && r.error);
                        statusEl.textContent = firstError ? firstError.error : "Couldn't read a vendor/amount from these receipts.";
                    }
                } catch (e) {
                    statusEl.style.display = 'none';
                }
            }
            async function saveExpense() {
                const btn = document.getElementById('saveExpenseBtn'); btn.disabled = true;
                const formData = new FormData();
                formData.append('action', 'save_expense');
                formData.append('id', document.getElementById('expenseId').value);
                formData.append('expense_date', document.getElementById('expenseDate').value);
                formData.append('vendor', document.getElementById('expenseVendor').value);
                formData.append('category', document.getElementById('expenseCategory').value);
                formData.append('amount', document.getElementById('expenseAmount').value);
                formData.append('description', document.getElementById('expenseDescription').value);
                const res = await fetch('', { method: 'POST', body: formData });
                const json = await res.json();
                if (!json.success) { showToast(json.error || 'Failed to save', true); btn.disabled = false; return; }
                const filesToUpload = [
                    ...Array.from(document.getElementById('expenseInvoiceFiles').files).map(file => ({ file, docType: 'invoice' })),
                    ...Array.from(document.getElementById('expenseReceiptFiles').files).map(file => ({ file, docType: 'receipt' })),
                ];
                for (const { file, docType } of filesToUpload) {
                    const rFormData = new FormData();
                    rFormData.append('action', 'upload_expense_receipt');
                    rFormData.append('expense_id', json.id);
                    rFormData.append('doc_type', docType);
                    rFormData.append('file', file);
                    await fetch('', { method: 'POST', body: rFormData });
                }
                showToast('Expense saved!');
                setTimeout(() => window.location.reload(), 1000);
            }
            async function deleteExpense(id) {
                if (!confirm("Are you sure you want to delete this expense?")) return;
                const data = new URLSearchParams({ action: 'delete_expense', id: id });
                const res = await fetch('', { method: 'POST', body: data }); const json = await res.json();
                if (json.success) { showToast('Expense deleted!'); setTimeout(() => window.location.reload(), 1000); } else showToast(json.error, true);
            }

            // ── Recurring expense templates ───────────────────────────────
            function openRecurringExpenseModal(re = null) {
                document.getElementById('recurringExpenseModalTitle').textContent = re ? 'Edit Recurring Expense' : 'Add Recurring Expense';
                document.getElementById('recurringExpenseId').value = re ? re.id : '';
                document.getElementById('recurringExpenseVendor').value = re ? re.vendor : '';
                document.getElementById('recurringExpenseCategory').value = re ? re.category : 'other';
                document.getElementById('recurringExpenseAmount').value = re ? re.amount : '0.00';
                document.getElementById('recurringExpenseFrequency').value = re ? re.frequency : 'monthly';
                document.getElementById('recurringExpenseDescription').value = re ? (re.description || '') : '';
                document.getElementById('recurringExpenseModal').classList.add('active');
            }
            async function saveRecurringExpense() {
                const btn = document.getElementById('saveRecurringExpenseBtn'); btn.disabled = true;
                const data = new URLSearchParams({
                    action: 'save_recurring_expense',
                    id: document.getElementById('recurringExpenseId').value,
                    vendor: document.getElementById('recurringExpenseVendor').value,
                    category: document.getElementById('recurringExpenseCategory').value,
                    amount: document.getElementById('recurringExpenseAmount').value,
                    frequency: document.getElementById('recurringExpenseFrequency').value,
                    description: document.getElementById('recurringExpenseDescription').value,
                });
                const res = await fetch('', { method: 'POST', body: data }); const json = await res.json();
                if (json.success) { showToast('Recurring expense saved!'); setTimeout(() => window.location.reload(), 1000); } else { showToast(json.error || 'Failed to save', true); btn.disabled = false; }
            }
            async function toggleRecurringExpenseActive(id, active) {
                const data = new URLSearchParams({ action: 'toggle_recurring_expense', id: id, is_active: active ? '1' : '0' });
                const res = await fetch('', { method: 'POST', body: data }); const json = await res.json();
                if (json.success) showToast(active ? 'Resumed!' : 'Paused!');
                else { showToast(json.error || 'Failed to update', true); setTimeout(() => window.location.reload(), 1000); }
            }
            async function deleteRecurringExpense(id) {
                if (!confirm('Delete this recurring expense? Past expenses it already logged are not affected.')) return;
                const data = new URLSearchParams({ action: 'delete_recurring_expense', id: id });
                const res = await fetch('', { method: 'POST', body: data }); const json = await res.json();
                if (json.success) { showToast('Recurring expense deleted!'); setTimeout(() => window.location.reload(), 1000); } else showToast(json.error, true);
            }
            async function importClientsCsv(file) {
                if (!file) return;
                const input = document.getElementById('importClientsFile');
                const fd = new FormData();
                fd.append('action', 'import_clients_csv');
                fd.append('clients_file', file);
                try {
                    const res = await fetch('', { method: 'POST', body: fd });
                    const json = await res.json();
                    if (json.success) {
                        let msg = `Imported ${json.imported} client(s)`;
                        if (json.skipped > 0) msg += `, skipped ${json.skipped}`;
                        showToast(msg + '!');
                        setTimeout(() => window.location.reload(), 1500);
                    } else {
                        showToast(json.error || 'Import failed', true);
                    }
                } catch (e) {
                    showToast('Import failed (network error)', true);
                } finally {
                    input.value = '';
                }
            }

            // Adhoc & Recurring Billing
            const LINE_ITEM_ROW_HTML = `
                    <td style="padding:0 0.5rem 0.5rem 0;"><input type="text" class="form-control li-code" placeholder="WEB01" style="font-size:0.85rem;"></td>
                    <td style="padding:0 0.5rem 0.5rem 0;"><input type="text" class="form-control li-desc" placeholder="Description" style="font-size:0.85rem;"></td>
                    <td style="padding:0 0.5rem 0.5rem 0;"><input type="number" class="form-control li-amount" step="0.01" placeholder="0.00" style="font-size:0.85rem; text-align:right;"></td>
                    <td style="padding:0 0 0.5rem 0;"><button type="button" class="btn small danger" onclick="this.closest('tr').remove()" style="padding:0.2rem 0.4rem;"><i class="fa-solid fa-xmark"></i></button></td>`;
            function addLineItem() {
                const tbody = document.getElementById('lineItemsBody');
                const tr = document.createElement('tr');
                tr.className = 'line-item-row';
                tr.innerHTML = LINE_ITEM_ROW_HTML;
                tbody.appendChild(tr);
            }
            function getLineItems() {
                const rows = document.querySelectorAll('#lineItemsBody .line-item-row');
                const items = [];
                for (const row of rows) {
                    const code = row.querySelector('.li-code').value.trim();
                    const desc = row.querySelector('.li-desc').value.trim();
                    const amount = parseFloat(row.querySelector('.li-amount').value);
                    if (!desc || isNaN(amount) || amount <= 0) continue;
                    items.push({ code: code || 'WEB01', desc, amount });
                }
                return items;
            }
            // One discount % and one tax % for the whole invoice, not per line item
            // — matches computeInvoiceTotals() server-side. Discount comes off the
            // line-item subtotal first, tax applies to what's left.
            function getInvoiceAdjustments() {
                return {
                    discount_pct: Math.min(100, Math.max(0, parseFloat(document.getElementById('adhocDiscountPct').value) || 0)),
                    tax_rate: Math.min(100, Math.max(0, parseFloat(document.getElementById('adhocTaxRate').value) || 0)),
                };
            }
            function resetLineItems() {
                const tbody = document.getElementById('lineItemsBody');
                tbody.innerHTML = `<tr class="line-item-row">${LINE_ITEM_ROW_HTML}</tr>`;
                document.getElementById('adhocDueDate').value = '';
                document.getElementById('adhocDueDateHint').textContent = '';
                document.getElementById('adhocMemo').value = '';
                document.getElementById('adhocDiscountPct').value = '0';
                document.getElementById('adhocTaxRate').value = '0';
                updateAdhocTotal();
            }
            // Recomputes the Subtotal/Discount/Tax/Total breakdown live as line
            // items or the discount/tax fields change, so there's an accurate total
            // before hitting Preview.
            function updateAdhocTotal() {
                const rows = document.querySelectorAll('#lineItemsBody .li-amount');
                let subtotal = 0;
                rows.forEach(el => { const v = parseFloat(el.value); if (!isNaN(v)) subtotal += v; });
                const { discount_pct, tax_rate } = getInvoiceAdjustments();
                const discountAmt = subtotal * discount_pct / 100;
                const net = subtotal - discountAmt;
                const taxAmt = net * tax_rate / 100;
                const total = net + taxAmt;
                const fmt = (n) => n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                document.getElementById('adhocSubtotal').textContent = fmt(subtotal);
                document.getElementById('adhocDiscountAmt').textContent = fmt(discountAmt);
                document.getElementById('adhocTaxAmt').textContent = fmt(taxAmt);
                document.getElementById('adhocRunningTotal').textContent = fmt(total);
            }
            document.getElementById('lineItemsBody').addEventListener('input', (e) => { if (e.target.classList.contains('li-amount')) updateAdhocTotal(); });
            document.getElementById('lineItemsBody').addEventListener('click', (e) => { if (e.target.closest('button')) setTimeout(updateAdhocTotal, 0); });
            document.getElementById('adhocDiscountPct').addEventListener('input', updateAdhocTotal);
            document.getElementById('adhocTaxRate').addEventListener('input', updateAdhocTotal);
            // Shows the selected client's current outstanding balance and hints at
            // what due date their default payment terms would produce, since the
            // Due Date field below is left blank (falls back to those terms) unless
            // explicitly overridden.
            function updateAdhocClientInfo() {
                const sel = document.getElementById('adhocClient');
                const opt = sel.options[sel.selectedIndex];
                const balanceEl = document.getElementById('adhocClientBalance');
                const hintEl = document.getElementById('adhocDueDateHint');
                if (!opt || !opt.value) {
                    balanceEl.style.display = 'none';
                    hintEl.textContent = '';
                    document.getElementById('adhocAmountCcy').textContent = APP_CURRENCY;
                    return;
                }
                const outstanding = parseFloat(opt.dataset.outstanding || '0');
                if (outstanding > 0) {
                    balanceEl.textContent = `Outstanding balance: ${outstanding.toLocaleString('en-US', { minimumFractionDigits: 2 })}`;
                    balanceEl.style.display = '';
                } else {
                    balanceEl.style.display = 'none';
                }
                const terms = parseInt(opt.dataset.terms || '21', 10);
                const defaultDue = new Date(Date.now() + terms * 86400000);
                hintEl.textContent = `Leave blank to use this client's terms (${terms} days — ${defaultDue.toLocaleDateString()})`;
                document.getElementById('adhocAmountCcy').textContent = opt.dataset.currency || APP_CURRENCY;
            }
            async function previewAdhocInvoice() {
                const cid = document.getElementById('adhocClient').value;
                const items = getLineItems();
                if (!cid) return showToast('Please select a client', true);
                if (!items.length) return showToast('Please add at least one line item with a description and amount', true);
                const btn = document.getElementById('previewAdhocBtn'); btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>...'; btn.disabled = true;
                const params = { action: 'preview_adhoc', client_id: cid, line_items: JSON.stringify(items), due_date: document.getElementById('adhocDueDate').value, ...getInvoiceAdjustments() };
                const data = new URLSearchParams(params);
                const res = await fetch('', { method: 'POST', body: data }); const json = await res.json();
                btn.innerHTML = '<i class="fa-solid fa-eye"></i> Preview'; btn.disabled = false;
                if (json.success) {
                    // Not saved yet, so there's no invoxa_invoices row for the PDF
                    // button's usual GET export — stash the same inputs so it can
                    // re-render straight to PDF via preview_adhoc_pdf instead.
                    _lastAdhocPreviewParams = params;
                    viewInvoice({ invoice_number: json.invoice_number, html_content: json.html });
                }
                else { showToast(json.error || 'Failed to preview', true); }
            }
            async function sendAdhocInvoice(isQuote = false) {
                const cid = document.getElementById('adhocClient').value;
                const items = getLineItems();
                if (!cid) return showToast('Please select a client', true);
                if (!items.length) return showToast('Please add at least one line item with a description and amount', true);
                const dueDate = document.getElementById('adhocDueDate').value;
                const memo = document.getElementById('adhocMemo').value;
                if (isQuote) {
                    const btn = document.getElementById('saveQuoteBtn'); btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving...'; btn.disabled = true;
                    const quoteExpiresAt = document.getElementById('adhocQuoteExpiry').value;
                    const data = new URLSearchParams({ action: 'save_quote', client_id: cid, line_items: JSON.stringify(items), due_date: dueDate, quote_expires_at: quoteExpiresAt, memo: memo, ...getInvoiceAdjustments() });
                    const res = await fetch('', { method: 'POST', body: data }); const json = await res.json();
                    if (json.success) { showToast(`Quote ${json.quoteNum} saved!`); setTimeout(() => window.location.reload(), 2000); }
                    else { showToast(json.error || 'Failed to save quote', true); btn.innerHTML = '<i class="fa-solid fa-file-pen"></i> Save as Quote'; btn.disabled = false; }
                } else {
                    const btn = document.getElementById('sendAdhocBtn'); btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Generating...'; btn.disabled = true;
                    const data = new URLSearchParams({ action: 'generate_adhoc', client_id: cid, line_items: JSON.stringify(items), due_date: dueDate, memo: memo, ...getInvoiceAdjustments() });
                    const res = await fetch('', { method: 'POST', body: data }); const json = await res.json();
                    if (json.success) { showToast(`Invoice ${json.invNum} sent!`); setTimeout(() => window.location.reload(), 2000); }
                    else { showToast(json.error || 'Failed to send', true); btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Generate & Send'; btn.disabled = false; }
                }
            }
            async function runRecurringBilling() {
                if (!confirm("This will instantly generate and email invoices to ALL active clients with a monthly rate. Proceed?")) return;
                const btn = document.getElementById('runRecurringBtn'); btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Processing...'; btn.disabled = true;
                const data = new URLSearchParams({ action: 'run_recurring' });
                const res = await fetch('', { method: 'POST', body: data }); const json = await res.json();
                if (json.success) { showToast(`Sent ${json.sent} invoices. Errors: ${json.errors}. Reminders sent: ${json.reminders_sent}. Late fees charged: ${json.late_fees_charged}. Recurring expenses logged: ${json.recurring_expenses_logged}.`); setTimeout(() => window.location.reload(), 2000); }
                else { showToast(json.error, true); btn.innerHTML = '<i class="fa-solid fa-bolt"></i> Run Monthly Billing'; btn.disabled = false; }
            }

            // Invoices / general
            // Single Export button for the invoiceExportType dropdown above — the two
            // tax-year options open their existing preview modal (which itself has a
            // download button once loaded); everything else is a direct file download.
            function runInvoiceExport() {
                const type = document.getElementById('invoiceExportType').value;
                if (type === 'tax_year') { openTaxYearPreview(); return; }
                if (type === 'tax_year_monthly') { openMonthlySummaryPreview(); return; }
                window.location.href = '?export=' + type;
            }
            function filterInvoicesByStatus(value) {
                filterTableSearch('invoices', value);
            }

            // Saved Filtered Views — a named preset is just the current contents of a
            // table's search box, stored per-browser in localStorage like other display
            // preferences (theme, default tab, page size). Not persisted server-side.
            const FILTER_VIEW_TABLES = {
                invoices: { viewSelectId: 'invoicesViewSelect', statusSelectId: 'invoiceStatusFilter', storageKey: 'invoxa_views_invoices' },
                clients: { viewSelectId: 'clientsViewSelect', statusSelectId: null, storageKey: 'invoxa_views_clients' },
            };
            function getFilterViews(table) {
                try { return JSON.parse(localStorage.getItem(FILTER_VIEW_TABLES[table].storageKey) || '[]'); } catch (e) { return []; }
            }
            function setFilterViews(table, views) {
                localStorage.setItem(FILTER_VIEW_TABLES[table].storageKey, JSON.stringify(views));
            }
            function populateFilterViewSelect(table) {
                const cfg = FILTER_VIEW_TABLES[table];
                const select = document.getElementById(cfg.viewSelectId);
                if (!select) return;
                const current = select.value;
                const views = getFilterViews(table);
                select.innerHTML = '<option value="">Saved Views…</option>' +
                    views.map(v => `<option value="${encodeURIComponent(v.name)}">${v.name.replace(/&/g, '&amp;').replace(/</g, '&lt;')}</option>`).join('');
                if (views.some(v => encodeURIComponent(v.name) === current)) select.value = current;
            }
            function tableSearchInput(table) {
                const wrapper = document.querySelector('#sec-' + table + ' .datatable-wrapper');
                return wrapper && wrapper.querySelector('input.datatable-input');
            }
            function saveFilterView(table) {
                const name = (prompt('Name this view:') || '').trim();
                if (!name) return;
                const input = tableSearchInput(table);
                const view = { name, search: input ? input.value : '' };
                const views = getFilterViews(table).filter(v => v.name !== name);
                views.push(view);
                setFilterViews(table, views);
                populateFilterViewSelect(table);
                document.getElementById(FILTER_VIEW_TABLES[table].viewSelectId).value = encodeURIComponent(name);
                showToast(`View "${name}" saved`);
            }
            function applyFilterView(table, encodedName) {
                if (!encodedName) return;
                const name = decodeURIComponent(encodedName);
                const view = getFilterViews(table).find(v => v.name === name);
                const input = tableSearchInput(table);
                if (!view || !input) return;
                input.value = view.search || '';
                input.dispatchEvent(new Event('input', { bubbles: true }));
                // Keep the Status dropdown (invoices only) in sync when the saved search
                // happens to be one of its option values, so it doesn't show a stale choice.
                const cfg = FILTER_VIEW_TABLES[table];
                if (cfg.statusSelectId) {
                    const statusEl = document.getElementById(cfg.statusSelectId);
                    if (statusEl) statusEl.value = [...statusEl.options].some(o => o.value === view.search) ? view.search : '';
                }
            }
            function deleteFilterView(table) {
                const select = document.getElementById(FILTER_VIEW_TABLES[table].viewSelectId);
                if (!select || !select.value) return showToast('Select a view to delete first', true);
                const name = decodeURIComponent(select.value);
                setFilterViews(table, getFilterViews(table).filter(v => v.name !== name));
                populateFilterViewSelect(table);
                showToast(`View "${name}" deleted`);
            }
            Object.keys(FILTER_VIEW_TABLES).forEach(populateFilterViewSelect);
            // file_path is stored in the DB as "invoices/<folder>/<file>.html", but the
            // actual served URL (see INVOICES_URL in invoxa.php) is under /invoxa-invoices/
            // — mirror that mapping here rather than using file_path as a URL directly.
            function invoiceFileUrl(filePath) {
                return '/invoxa-invoices/' + filePath.replace(/^invoices\//, '');
            }
            let _currentViewFilePath = null;
            let _currentViewInvoiceId = null;
            let _currentViewInvoiceNumber = null;
            // Set by previewAdhocInvoice() for an unsaved preview (no DB id/file yet),
            // so the PDF button can re-render from the same inputs. Cleared when a
            // real, saved invoice is opened so it can't be reused stale.
            let _lastAdhocPreviewParams = null;
            async function viewInvoice(inv) {
                document.getElementById('viewModalTitle').textContent = 'Invoice ' + inv.invoice_number;
                const iframe = document.getElementById('invoicePreview');
                const warning = document.getElementById('invoiceMissingWarning');
                _currentViewFilePath = inv.file_path || null;
                _currentViewInvoiceId = inv.id ?? null;
                _currentViewInvoiceNumber = inv.invoice_number ?? null;
                if (_currentViewInvoiceId) _lastAdhocPreviewParams = null;
                document.getElementById('copyInvoiceLinkBtn').style.display = inv.file_path ? '' : 'none';
                document.getElementById('downloadPdfBtn').style.display = (_currentViewInvoiceId || _lastAdhocPreviewParams) ? '' : 'none';
                // Attachments need a real invoice_id, so hidden for an unsaved adhoc
                // preview, same condition as the other buttons tied to a persisted row.
                document.getElementById('attachmentsBtn').style.display = _currentViewInvoiceId ? '' : 'none';
                warning.style.display = 'none';
                iframe.style.display = '';
                document.getElementById('viewModal').classList.add('active');
                if (inv.file_path) {
                    // Check the file actually exists before pointing the iframe at it — the
                    // DB record and disk file can drift apart (e.g. a restored backup whose
                    // files never came along), which would otherwise be a blank 404.
                    const url = invoiceFileUrl(inv.file_path);
                    try {
                        const check = await fetch(url, { method: 'HEAD' });
                        if (!check.ok) throw new Error('missing');
                        iframe.src = url;
                    } catch (e) {
                        iframe.style.display = 'none';
                        warning.style.display = 'flex';
                    }
                } else if (inv.html_content) {
                    // Fallback: write html_content, replacing the email cid: logo reference with the real URL
                    let html = inv.html_content;
                    html = html.replace(/src=["']cid:logo_cid["']/g, 'src="/invoxa-invoices/invoxa_logo.jpg"');
                    const doc = iframe.contentWindow.document;
                    doc.open(); doc.write(html); doc.close();
                } else {
                    iframe.style.display = 'none';
                    warning.style.display = 'flex';
                }
            }
            function copyInvoiceLink() {
                if (!_currentViewFilePath) { showToast('No direct link available for this invoice', true); return; }
                const url = window.location.origin + invoiceFileUrl(_currentViewFilePath);
                // navigator.clipboard only exists in secure contexts (HTTPS or localhost) —
                // plain HTTP throws before .catch() runs, so fall back to the older
                // execCommand('copy') approach, which works everywhere.
                if (navigator.clipboard && window.isSecureContext) {
                    navigator.clipboard.writeText(url)
                        .then(() => showToast('Link copied to clipboard'))
                        .catch(() => showToast('Failed to copy link', true));
                    return;
                }
                const ta = document.createElement('textarea');
                ta.value = url;
                ta.style.position = 'fixed';
                ta.style.left = '-9999px';
                document.body.appendChild(ta);
                ta.focus();
                ta.select();
                try {
                    document.execCommand('copy');
                    showToast('Link copied to clipboard');
                } catch (e) {
                    showToast('Failed to copy link — copy manually: ' + url, true);
                }
                document.body.removeChild(ta);
            }
            function _formatFileSize(bytes) {
                bytes = parseInt(bytes, 10) || 0;
                if (bytes < 1024) return bytes + ' B';
                if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
                return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
            }
            async function openAttachmentsModal() {
                if (!_currentViewInvoiceId) return;
                document.getElementById('attachmentsModalTitle').textContent = 'Attachments — Invoice ' + (_currentViewInvoiceNumber || '');
                document.getElementById('attachmentFile').value = '';
                document.getElementById('attachmentsList').innerHTML = '<p style="color:var(--text-secondary); font-size:0.85rem;">Loading…</p>';
                document.getElementById('attachmentsModal').classList.add('active');
                await loadAttachments();
            }
            async function loadAttachments() {
                const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'get_invoice_attachments', invoice_id: _currentViewInvoiceId }) });
                const json = await res.json();
                const list = document.getElementById('attachmentsList');
                if (!json.success || !json.attachments.length) {
                    list.innerHTML = '<p style="color:var(--text-secondary); font-size:0.85rem;">No attachments yet.</p>';
                    return;
                }
                list.innerHTML = json.attachments.map(a => `
                    <div style="display:flex; align-items:center; justify-content:space-between; gap:0.75rem; padding:0.5rem 0; border-bottom:1px solid var(--border);">
                        <a href="${a.url}" target="_blank" style="color:var(--text-primary); overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"><i class="fa-solid fa-paperclip"></i> ${a.filename}</a>
                        <div style="display:flex; align-items:center; gap:0.75rem; white-space:nowrap;">
                            <span style="color:var(--text-secondary); font-size:0.8rem;">${_formatFileSize(a.file_size)}</span>
                            <button class="btn small danger" onclick="deleteAttachment(${a.id})"><i class="fa-solid fa-trash"></i></button>
                        </div>
                    </div>
                `).join('');
            }
            async function uploadAttachment() {
                const file = document.getElementById('attachmentFile').files[0];
                if (!file) return showToast('Choose a file first', true);
                const btn = document.getElementById('uploadAttachmentBtn'); btn.disabled = true;
                const formData = new FormData();
                formData.append('action', 'upload_invoice_attachment');
                formData.append('invoice_id', _currentViewInvoiceId);
                formData.append('file', file);
                const res = await fetch('', { method: 'POST', body: formData });
                const json = await res.json();
                if (json.success) { showToast('Attachment uploaded!'); document.getElementById('attachmentFile').value = ''; await loadAttachments(); }
                else showToast(json.error || 'Upload failed', true);
                btn.disabled = false;
            }
            async function deleteAttachment(id) {
                if (!confirm('Delete this attachment?')) return;
                const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'delete_invoice_attachment', id: id }) });
                const json = await res.json();
                if (json.success) { showToast('Attachment deleted!'); await loadAttachments(); }
                else showToast(json.error || 'Failed to delete', true);
            }
            async function openMarkPaid(inv) {
                document.getElementById('paidInvoiceId').value = inv.id;
                document.getElementById('paidInvoiceNum').value = inv.invoice_number;
                const remaining = Math.max(0, parseFloat(inv.amount) - parseFloat(inv.paid_amount || 0));
                document.getElementById('paidAmount').value = remaining.toFixed(2);
                document.getElementById('paidAmountCcy').textContent = inv.currency || APP_CURRENCY;
                document.getElementById('paidNote').value = '';
                document.getElementById('paidHistoryWrap').style.display = 'none';
                document.getElementById('paidHistoryList').innerHTML = '';
                document.getElementById('paidModal').classList.add('active');
                await loadPaymentHistory(inv.id);
            }
            async function loadPaymentHistory(invoiceId) {
                const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'get_invoice_payments', invoice_id: invoiceId }) });
                const json = await res.json();
                if (!json.success || !json.payments.length) return;
                const wrap = document.getElementById('paidHistoryWrap');
                wrap.style.display = '';
                document.getElementById('paidHistoryList').innerHTML = json.payments.map(p => `
                    <div style="display:flex; justify-content:space-between; gap:0.75rem; padding:0.25rem 0;">
                        <span style="color:var(--text-secondary);">${new Date(p.paid_at).toLocaleDateString()}${p.note ? ' — ' + p.note.replace(/</g, '&lt;') : ''}</span>
                        <span>$${parseFloat(p.amount).toFixed(2)}</span>
                    </div>
                `).join('');
            }
            async function openNoteModal(id, num) {
                document.getElementById('noteInvoiceId').value = id;
                document.getElementById('noteInvoiceNum').textContent = num;
                document.getElementById('noteText').value = '';
                document.getElementById('existingNotesList').innerHTML = '<p style="color:var(--text-secondary); font-size:0.85rem;">Loading notes...</p>';
                document.getElementById('noteModal').classList.add('active');
                await renderNotesList(num);
            }
            async function renderNotesList(num) {
                const invNum = num || document.getElementById('noteInvoiceNum').textContent;
                const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'get_notes', invoice_number: invNum }) });
                const json = await res.json();
                const container = document.getElementById('existingNotesList');
                if (!json.success || json.notes.length === 0) {
                    container.innerHTML = '<p style="color:var(--text-secondary); font-size:0.85rem; font-style:italic;">No notes yet.</p>';
                    return;
                }
                container.innerHTML = json.notes.map(n => `
                    <div style="background:rgba(255,255,255,0.04); border:1px solid var(--border); border-radius:6px; padding:0.6rem 0.8rem; margin-bottom:0.5rem; display:flex; align-items:flex-start; gap:0.75rem;">
                        <div style="flex:1;">
                            <div style="font-size:0.75rem; color:var(--text-secondary); margin-bottom:0.25rem;">${n.performed_at}</div>
                            <div style="font-size:0.875rem; white-space:pre-wrap;">${n.notes.replace(/</g, '&lt;')}</div>
                        </div>
                        <button class="btn small danger" style="flex-shrink:0; padding:0.2rem 0.4rem;" onclick="deleteNote(${n.id}, '${invNum}')" title="Delete note"><i class="fa-solid fa-trash"></i></button>
                    </div>`).join('');
            }
            async function deleteNote(noteId, invNum) {
                const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'delete_note', note_id: noteId }) });
                const json = await res.json();
                if (json.success) {
                    await renderNotesList(invNum);
                    // Reload page in background to update note count badge
                    window._notePageNeedsReload = true;
                } else { showToast(json.error || 'Failed to delete', true); }
            }
            async function markPaid() { const btn = document.getElementById('markPaidBtn'); btn.disabled = true; const data = new URLSearchParams({ action: 'mark_paid', id: document.getElementById('paidInvoiceId').value, amount: document.getElementById('paidAmount').value, note: document.getElementById('paidNote').value }); const res = await fetch('', { method: 'POST', body: data }); const json = await res.json(); if (json.success) { showToast('Payment recorded!'); setTimeout(() => window.location.reload(), 1000); } else { showToast(json.error, true); btn.disabled = false; } }
            async function markUnpaid(id) { if (!confirm('Mark this invoice as unpaid?')) return; const data = new URLSearchParams({ action: 'mark_unpaid', id: id }); const res = await fetch('', { method: 'POST', body: data }); const json = await res.json(); if (json.success) { showToast('Marked as unpaid!'); setTimeout(() => window.location.reload(), 1000); } else { showToast(json.error, true); } }
            async function resendInvoiceEmail(id) {
                if (!confirm('Resend this invoice email to the client?')) return;
                const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'resend_invoice_email', id }) });
                const json = await res.json();
                if (json.success) { showToast('Invoice email resent!'); } else { showToast(json.error || 'Resend failed', true); }
            }
            async function voidInvoice(id, invNum) {
                const reason = prompt(`Void invoice ${invNum}? It stays on record but is excluded from outstanding/overdue totals. This can be undone.\n\nOptional reason:`);
                if (reason === null) return;
                const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'void_invoice', id, reason }) });
                const json = await res.json();
                if (json.success) { showToast('Invoice voided'); setTimeout(() => window.location.reload(), 1000); } else { showToast(json.error || 'Failed to void', true); }
            }
            async function unvoidInvoice(id) {
                if (!confirm('Restore this invoice from void?')) return;
                const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'unvoid_invoice', id }) });
                const json = await res.json();
                if (json.success) { showToast('Invoice restored'); setTimeout(() => window.location.reload(), 1000); } else { showToast(json.error || 'Failed to restore', true); }
            }
            async function fixPaidDates() {
                if (!confirm('This will reset paid_at to the last day of each invoice\'s month for ALL paid invoices. Continue?')) return;
                const btn = document.getElementById('fixPaidDatesBtn');
                btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Processing…';
                const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'fix_paid_dates' }) });
                const json = await res.json();
                btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-calendar-xmark"></i> Reset paid_at to End-of-Month';
                if (json.success) { showToast('Fixed ' + json.fixed + ' invoices. Reload to see updated Payment Velocity.'); }
                else { showToast('Error: ' + (json.error || 'Unknown'), true); }
            }
            async function addNote() { const btn = document.getElementById('addNoteBtn'); btn.disabled = true; const data = new URLSearchParams({ action: 'add_note', id: document.getElementById('noteInvoiceId').value, note: document.getElementById('noteText').value }); const res = await fetch('', { method: 'POST', body: data }); const json = await res.json(); if (json.success) { showToast('Note added!'); setTimeout(() => window.location.reload(), 1000); } else { showToast(json.error, true); btn.disabled = false; } }
            async function deleteInvoice(id) {
                if (!confirm('Are you sure you want to delete this invoice? This will remove it from the database and delete the HTML file. This action cannot be undone.')) return;
                const data = new URLSearchParams({ action: 'delete_invoice', id: id });
                const res = await fetch('', { method: 'POST', body: data }); const json = await res.json();
                if (json.success) { showToast('Invoice deleted!'); setTimeout(() => window.location.reload(), 1000); } else { showToast(json.error, true); }
            }

            // ── Invoice bulk actions ──────────────────────────────────────
            function toggleSelectAllInvoices(masterCb) {
                document.querySelectorAll('.invoice-select-cb').forEach(cb => { cb.checked = masterCb.checked; });
                updateInvoiceBulkBar();
            }
            function getSelectedInvoiceCbs() {
                return Array.from(document.querySelectorAll('.invoice-select-cb:checked'));
            }
            function updateInvoiceBulkBar() {
                const count = getSelectedInvoiceCbs().length;
                const bar = document.getElementById('invoiceBulkBar');
                bar.style.display = count > 0 ? 'flex' : 'none';
                document.getElementById('invoiceBulkCount').textContent = count + ' selected';
                const allCbs = document.querySelectorAll('.invoice-select-cb');
                document.getElementById('invoicesSelectAll').checked = count > 0 && count === allCbs.length;
            }
            async function bulkMarkPaidInvoices() {
                const cbs = getSelectedInvoiceCbs().filter(cb => cb.dataset.status !== 'paid' && cb.dataset.status !== 'void');
                if (!cbs.length) return showToast('No eligible invoices selected (already paid or void)', true);
                if (!confirm(`Mark ${cbs.length} invoice(s) as fully paid?`)) return;
                for (const cb of cbs) {
                    await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'mark_paid', id: cb.value, amount: cb.dataset.amount, note: 'Bulk mark paid' }) });
                }
                showToast(`Marked ${cbs.length} invoice(s) as paid!`);
                setTimeout(() => window.location.reload(), 1000);
            }
            async function bulkResendInvoiceEmails() {
                const cbs = getSelectedInvoiceCbs().filter(cb => cb.dataset.status !== 'void' && cb.dataset.status !== 'draft');
                if (!cbs.length) return showToast('No eligible invoices selected (void/draft can\'t be resent)', true);
                if (!confirm(`Resend ${cbs.length} invoice email(s)?`)) return;
                for (const cb of cbs) {
                    await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'resend_invoice_email', id: cb.value }) });
                }
                showToast(`Resent ${cbs.length} invoice email(s)!`);
            }
            async function bulkDeleteInvoices() {
                const cbs = getSelectedInvoiceCbs();
                if (!cbs.length) return;
                if (!confirm(`Delete ${cbs.length} invoice(s)? This removes them from the database and deletes their HTML files. This cannot be undone.`)) return;
                for (const cb of cbs) {
                    await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'delete_invoice', id: cb.value }) });
                }
                showToast(`Deleted ${cbs.length} invoice(s)!`);
                setTimeout(() => window.location.reload(), 1000);
            }
            function bulkExportInvoicesCsv() {
                const cbs = getSelectedInvoiceCbs();
                if (!cbs.length) return;
                const rows = [['Invoice #', 'Date', 'Due Date', 'Client', 'Amount', 'Status']];
                cbs.forEach(cb => {
                    const cells = cb.closest('tr').querySelectorAll('td');
                    rows.push([1, 2, 3, 4, 5, 6].map(i => (cells[i].innerText || '').trim()));
                });
                const csv = rows.map(r => r.map(v => '"' + v.replace(/"/g, '""') + '"').join(',')).join('\n');
                const blob = new Blob([csv], { type: 'text/csv' });
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url; a.download = 'invoices_selected.csv'; a.click();
                URL.revokeObjectURL(url);
            }

            // ── Client bulk actions ───────────────────────────────────────
            function toggleSelectAllClients(masterCb) {
                document.querySelectorAll('.client-select-cb').forEach(cb => { cb.checked = masterCb.checked; });
                updateClientBulkBar();
            }
            function getSelectedClientCbs() {
                return Array.from(document.querySelectorAll('.client-select-cb:checked'));
            }
            function updateClientBulkBar() {
                const count = getSelectedClientCbs().length;
                const bar = document.getElementById('clientBulkBar');
                bar.style.display = count > 0 ? 'flex' : 'none';
                document.getElementById('clientBulkCount').textContent = count + ' selected';
                const allCbs = document.querySelectorAll('.client-select-cb');
                document.getElementById('clientsSelectAll').checked = count > 0 && count === allCbs.length;
            }
            async function bulkSetClientFlag(field, value, label) {
                const cbs = getSelectedClientCbs();
                if (!cbs.length) return;
                if (!confirm(`${label}: ${cbs.length} client(s)?`)) return;
                for (const cb of cbs) {
                    await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'update_client_flags', id: cb.value, field: field, value: value }) });
                }
                showToast(`${label} for ${cbs.length} client(s)!`);
                setTimeout(() => window.location.reload(), 1000);
            }
            async function bulkDeleteClients() {
                const cbs = getSelectedClientCbs();
                if (!cbs.length) return;
                if (!confirm(`Delete ${cbs.length} client(s)? This cannot be undone.`)) return;
                for (const cb of cbs) {
                    await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'delete_client', id: cb.value }) });
                }
                showToast(`Deleted ${cbs.length} client(s)!`);
                setTimeout(() => window.location.reload(), 1000);
            }

            // ── Expense bulk actions ──────────────────────────────────────
            function toggleSelectAllExpenses(masterCb) {
                document.querySelectorAll('.expense-select-cb').forEach(cb => { cb.checked = masterCb.checked; });
                updateExpenseBulkBar();
            }
            function getSelectedExpenseCbs() {
                return Array.from(document.querySelectorAll('.expense-select-cb:checked'));
            }
            function updateExpenseBulkBar() {
                const count = getSelectedExpenseCbs().length;
                const bar = document.getElementById('expenseBulkBar');
                bar.style.display = count > 0 ? 'flex' : 'none';
                document.getElementById('expenseBulkCount').textContent = count + ' selected';
                const allCbs = document.querySelectorAll('.expense-select-cb');
                document.getElementById('expensesSelectAll').checked = count > 0 && count === allCbs.length;
            }
            async function bulkDeleteExpenses() {
                const cbs = getSelectedExpenseCbs();
                if (!cbs.length) return;
                if (!confirm(`Delete ${cbs.length} expense(s)? This cannot be undone.`)) return;
                for (const cb of cbs) {
                    await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'delete_expense', id: cb.value }) });
                }
                showToast(`Deleted ${cbs.length} expense(s)!`);
                setTimeout(() => window.location.reload(), 1000);
            }
            function bulkExportExpensesCsv() {
                const cbs = getSelectedExpenseCbs();
                if (!cbs.length) return;
                const rows = [['Date', 'Vendor', 'Category', 'Amount', 'Description']];
                cbs.forEach(cb => {
                    const cells = cb.closest('tr').querySelectorAll('td');
                    rows.push([1, 2, 3, 4, 5].map(i => (cells[i].innerText || '').trim()));
                });
                const csv = rows.map(r => r.map(v => '"' + v.replace(/"/g, '""') + '"').join(',')).join('\n');
                const blob = new Blob([csv], { type: 'text/csv' });
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url; a.download = 'expenses_selected.csv'; a.click();
                URL.revokeObjectURL(url);
            }

            // ── Quote bulk actions ────────────────────────────────────────
            function toggleSelectAllQuotes(masterCb) {
                document.querySelectorAll('.quote-select-cb').forEach(cb => { cb.checked = masterCb.checked; });
                updateQuoteBulkBar();
            }
            function getSelectedQuoteCbs() {
                return Array.from(document.querySelectorAll('.quote-select-cb:checked'));
            }
            function updateQuoteBulkBar() {
                const count = getSelectedQuoteCbs().length;
                const bar = document.getElementById('quoteBulkBar');
                bar.style.display = count > 0 ? 'flex' : 'none';
                document.getElementById('quoteBulkCount').textContent = count + ' selected';
                const allCbs = document.querySelectorAll('.quote-select-cb');
                document.getElementById('quotesSelectAll').checked = count > 0 && count === allCbs.length;
            }
            async function bulkConvertQuotes() {
                const cbs = getSelectedQuoteCbs();
                if (!cbs.length) return;
                const expiredCount = cbs.filter(cb => cb.dataset.expired === '1').length;
                const warning = expiredCount
                    ? `Convert ${cbs.length} quote(s) to invoices? ${expiredCount} of them have expired. This cannot be undone.`
                    : `Convert ${cbs.length} quote(s) to invoices? This cannot be undone.`;
                if (!confirm(warning)) return;
                let failed = 0;
                for (const cb of cbs) {
                    const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'convert_quote', id: cb.value }) });
                    const json = await res.json();
                    if (!json.success) failed++;
                }
                showToast(failed ? `Converted ${cbs.length - failed} quote(s), ${failed} failed` : `Converted ${cbs.length} quote(s)!`, failed > 0);
                setTimeout(() => window.location.reload(), 1000);
            }
            async function bulkDeleteQuotes() {
                const cbs = getSelectedQuoteCbs();
                if (!cbs.length) return;
                if (!confirm(`Delete ${cbs.length} quote(s)? This cannot be undone.`)) return;
                for (const cb of cbs) {
                    await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'delete_invoice', id: cb.value }) });
                }
                showToast(`Deleted ${cbs.length} quote(s)!`);
                setTimeout(() => window.location.reload(), 1000);
            }
            function bulkExportQuotesCsv() {
                const cbs = getSelectedQuoteCbs();
                if (!cbs.length) return;
                const rows = [['Quote #', 'Client', 'Date', 'Amount', 'Status', 'Expires']];
                cbs.forEach(cb => {
                    const cells = cb.closest('tr').querySelectorAll('td');
                    rows.push([1, 2, 3, 4, 5, 6].map(i => (cells[i].innerText || '').trim()));
                });
                const csv = rows.map(r => r.map(v => '"' + v.replace(/"/g, '""') + '"').join(',')).join('\n');
                const blob = new Blob([csv], { type: 'text/csv' });
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url; a.download = 'quotes_selected.csv'; a.click();
                URL.revokeObjectURL(url);
            }
            async function toggleTestClients(hide) {
                const data = new URLSearchParams({ action: 'toggle_test_clients', hide: hide ? '1' : '0' });
                try {
                    const res = await fetch('', { method: 'POST', body: data });
                    const json = await res.json();
                    if (!json.success) {
                        showToast(json.error || 'Failed to update — see Settings > License if this keeps happening', true);
                        document.getElementById('hideTestToggle').checked = !hide;
                        return;
                    }
                    window.location.reload();
                } catch (e) {
                    showToast('Failed to update (network error)', true);
                    document.getElementById('hideTestToggle').checked = !hide;
                }
            }
            async function toggleShowTestOnly(show) {
                const data = new URLSearchParams({ action: 'toggle_show_test_only', show: show ? '1' : '0' });
                try {
                    const res = await fetch('', { method: 'POST', body: data });
                    const json = await res.json();
                    if (!json.success) {
                        showToast(json.error || 'Failed to update — see Settings > License if this keeps happening', true);
                        document.getElementById('showTestOnlyToggle').checked = !show;
                        return;
                    }
                    window.location.reload();
                } catch (e) {
                    showToast('Failed to update (network error)', true);
                    document.getElementById('showTestOnlyToggle').checked = !show;
                }
            }
            function toggleTheme(isLight) {
                const theme = isLight ? 'light' : 'dark';
                document.documentElement.setAttribute('data-theme', theme);
                localStorage.setItem('invoxa_theme', theme);
                if (chartAllData) renderChart();
            }
            async function saveCron() {
                const btn = document.getElementById('saveCronBtn'); btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>'; btn.disabled = true;
                try {
                    const data = new URLSearchParams({ action: 'update_cron', cron: document.getElementById('cronInput').value });
                    const res = await fetch('', { method: 'POST', body: data }); const json = await res.json();
                    if (json.success) { showToast('Cron updated!'); updateCronHuman(); } else showToast(json.error, true);
                } catch (e) { showToast('Error: ' + e.message, true); }
                btn.innerHTML = '<i class="fa-solid fa-check"></i> Save'; btn.disabled = false;
            }

            function updateCronHuman() {
                const val = document.getElementById('cronInput').value.trim();
                const el = document.getElementById('cronHuman');
                const dashEl = document.getElementById('nextCronRunDashboard');
                const toggle = document.getElementById('cronEnabledToggle');
                const isEnabled = !toggle || toggle.checked;
                if (!val) {
                    if (el) el.textContent = '';
                    if (dashEl) dashEl.textContent = 'Not set';
                    return;
                }
                try {
                    const desc = window.cronstrue.toString(val);
                    const pausedPrefix = isEnabled ? '' : '<i class="fa-solid fa-pause"></i> Paused — would run: ';
                    if (el) {
                        el.innerHTML = isEnabled ? `<strong>Schedule:</strong> ${desc}` : `${pausedPrefix}${desc}`;
                        el.style.color = isEnabled ? "var(--success)" : "var(--text-secondary)";
                    }
                    if (dashEl) {
                        dashEl.textContent = isEnabled ? desc : 'Paused (' + desc + ')';
                    }
                } catch (e) {
                    if (el) {
                        el.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> Invalid cron expression';
                        el.style.color = "var(--danger)";
                    }
                    if (dashEl) dashEl.textContent = 'Invalid';
                }
            }
            async function toggleCronEnabled(enabled) {
                const toggle = document.getElementById('cronEnabledToggle');
                toggle.disabled = true;
                try {
                    const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'toggle_cron', enabled: enabled ? '1' : '0' }) });
                    const json = await res.json();
                    if (json.success) {
                        showToast(enabled ? 'Recurring billing enabled' : 'Recurring billing paused');
                        updateCronHuman();
                    } else {
                        showToast(json.error || 'Failed to update', true);
                        toggle.checked = !enabled;
                    }
                } catch (e) {
                    showToast('Failed to update (network error)', true);
                    toggle.checked = !enabled;
                }
                toggle.disabled = false;
            }
            async function toggleRecurringBypassGuard(enabled) {
                const toggle = document.getElementById('recurringBypassGuardToggle');
                toggle.disabled = true;
                try {
                    const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'toggle_recurring_bypass_guard', enabled: enabled ? '1' : '0' }) });
                    const json = await res.json();
                    if (json.success) {
                        showToast(enabled ? 'Double-billing guard bypassed — every run will re-bill active clients' : 'Double-billing guard restored');
                    } else {
                        showToast(json.error || 'Failed to update', true);
                        toggle.checked = !enabled;
                    }
                } catch (e) {
                    showToast('Failed to update (network error)', true);
                    toggle.checked = !enabled;
                }
                toggle.disabled = false;
            }
            async function saveAuditRetention() {
                const btn = document.getElementById('saveAuditRetentionBtn'); btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving...'; btn.disabled = true;
                const days = document.getElementById('auditRetentionSelect').value;
                const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'save_audit_retention', audit_log_retention_days: days }) });
                const json = await res.json();
                if (json.success) { showToast('Audit log retention saved!'); } else { showToast(json.error || 'Failed to save', true); }
                btn.innerHTML = '<i class="fa-solid fa-save"></i> Save'; btn.disabled = false;
            }
            async function saveBackupRetention() {
                const btn = document.getElementById('saveBackupRetentionBtn'); btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving...'; btn.disabled = true;
                const count = document.getElementById('localBackupRetentionCount').value;
                const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'save_backup_retention', local_backup_retention_count: count }) });
                const json = await res.json();
                if (json.success) { showToast('Backup retention saved!'); } else { showToast(json.error || 'Failed to save', true); }
                btn.innerHTML = '<i class="fa-solid fa-save"></i> Save'; btn.disabled = false;
            }
            async function saveOffsiteBackup() {
                const btn = document.getElementById('saveOffsiteBackupBtn'); btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving...'; btn.disabled = true;
                const data = new URLSearchParams({
                    action: 'save_offsite_backup',
                    offsite_backup_enabled: document.getElementById('offsiteBackupEnabled').checked ? '1' : '0',
                    offsite_remote_name: document.getElementById('offsiteRemoteName').value,
                    offsite_remote_path: document.getElementById('offsiteRemotePath').value,
                    offsite_retention_count: document.getElementById('offsiteRetentionCount').value,
                });
                const res = await fetch('', { method: 'POST', body: data });
                const json = await res.json();
                if (json.success) { showToast('Offsite push settings saved!'); } else { showToast(json.error || 'Failed to save', true); }
                btn.innerHTML = '<i class="fa-solid fa-save"></i> Save'; btn.disabled = false;
            }
            async function toggleReminders(enabled) {
                const toggle = document.getElementById('remindersEnabledToggle');
                toggle.disabled = true;
                try {
                    const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'toggle_reminders', enabled: enabled ? '1' : '0' }) });
                    const json = await res.json();
                    if (json.success) {
                        showToast(enabled ? 'Payment reminders enabled' : 'Payment reminders paused');
                    } else {
                        showToast(json.error || 'Failed to update', true);
                        toggle.checked = !enabled;
                    }
                } catch (e) {
                    showToast('Failed to update (network error)', true);
                    toggle.checked = !enabled;
                }
                toggle.disabled = false;
            }
            async function toggleLateFees(enabled) {
                const toggle = document.getElementById('lateFeesEnabledToggle');
                toggle.disabled = true;
                try {
                    const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'toggle_late_fees', enabled: enabled ? '1' : '0' }) });
                    const json = await res.json();
                    if (json.success) {
                        showToast(enabled ? 'Late fees enabled' : 'Late fees paused');
                    } else {
                        showToast(json.error || 'Failed to update', true);
                        toggle.checked = !enabled;
                    }
                } catch (e) {
                    showToast('Failed to update (network error)', true);
                    toggle.checked = !enabled;
                }
                toggle.disabled = false;
            }
            async function saveLateFeeSettings() {
                const btn = document.getElementById('saveLateFeeSettingsBtn'); btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving...'; btn.disabled = true;
                const form = document.getElementById('lateFeeSettingsForm');
                const data = new URLSearchParams(new FormData(form));
                data.append('action', 'save_late_fee_settings');
                const res = await fetch('', { method: 'POST', body: data });
                const json = await res.json();
                if (json.success) { showToast('Late fee settings saved!'); } else { showToast(json.error || 'Failed to save', true); }
                btn.innerHTML = '<i class="fa-solid fa-save"></i> Save Late Fee Settings'; btn.disabled = false;
            }
            function updateNotificationChannelUI() {
                const channel = document.getElementById('notificationChannel').value;
                document.getElementById('telegramFields').style.display = channel === 'telegram' ? '' : 'none';
                document.getElementById('slackFields').style.display = channel === 'slack' ? '' : 'none';
                document.getElementById('webhookFields').style.display = channel === 'webhook' ? '' : 'none';
            }
            updateNotificationChannelUI();
            function setAllNotifyEvents(checked) {
                document.querySelectorAll('#notificationSettingsForm .notify-event-cb').forEach(cb => { cb.checked = checked; });
            }
            async function saveNotificationSettings() {
                const btn = document.getElementById('saveNotificationSettingsBtn'); btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving...'; btn.disabled = true;
                const form = document.getElementById('notificationSettingsForm');
                const data = new URLSearchParams(new FormData(form));
                data.append('action', 'save_notification_settings');
                const res = await fetch('', { method: 'POST', body: data });
                const json = await res.json();
                if (json.success) { showToast('Notification settings saved!'); } else { showToast(json.error || 'Failed to save', true); }
                btn.innerHTML = '<i class="fa-solid fa-save"></i> Save Notification Settings'; btn.disabled = false;
            }
            async function sendTestNotification() {
                const btn = document.getElementById('sendTestNotificationBtn'); btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Sending...'; btn.disabled = true;
                const data = new URLSearchParams({
                    action: 'test_notification',
                    notification_channel: document.getElementById('notificationChannel').value,
                    telegram_bot_token: document.getElementById('telegramBotToken').value,
                    telegram_chat_id: document.getElementById('telegramChatId').value,
                    slack_webhook_url: document.getElementById('slackWebhookUrl').value,
                    webhook_url: document.getElementById('webhookUrl').value,
                    webhook_format: document.getElementById('webhookFormat').value,
                });
                const res = await fetch('', { method: 'POST', body: data });
                const json = await res.json();
                if (json.success) { showToast('Test message sent!'); } else { showToast(json.error || 'Failed to send', true); }
                btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Send Test Message'; btn.disabled = false;
            }
            async function savePaymentSettings() {
                const btn = document.getElementById('savePaymentSettingsBtn'); btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving...'; btn.disabled = true;
                const form = document.getElementById('paymentSettingsForm');
                const data = new URLSearchParams(new FormData(form));
                data.append('action', 'save_payment_settings');
                const res = await fetch('', { method: 'POST', body: data });
                const json = await res.json();
                if (json.success) { showToast('Payment settings saved!'); } else { showToast(json.error || 'Failed to save', true); }
                btn.innerHTML = '<i class="fa-solid fa-save"></i> Save Payment Settings'; btn.disabled = false;
            }
            async function testStripeConnection() {
                const btn = document.getElementById('testStripeBtn'); btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Testing...'; btn.disabled = true;
                const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'test_stripe_connection', stripe_secret_key: document.getElementById('stripeSecretKey').value }) });
                const json = await res.json();
                if (json.success) { showToast('Stripe connected — account: ' + (json.account || 'OK')); } else { showToast(json.error || 'Stripe connection failed', true); }
                btn.innerHTML = '<i class="fa-solid fa-plug"></i> Test Connection'; btn.disabled = false;
            }
            async function testPaypalConnection() {
                const btn = document.getElementById('testPaypalBtn'); btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Testing...'; btn.disabled = true;
                const data = new URLSearchParams({
                    action: 'test_paypal_connection',
                    paypal_environment: document.getElementById('paypalEnvironment').value,
                    paypal_client_id: document.getElementById('paypalClientId').value,
                    paypal_client_secret: document.getElementById('paypalClientSecret').value,
                });
                const res = await fetch('', { method: 'POST', body: data });
                const json = await res.json();
                if (json.success) { showToast('PayPal connected!'); } else { showToast(json.error || 'PayPal connection failed', true); }
                btn.innerHTML = '<i class="fa-solid fa-plug"></i> Test Connection'; btn.disabled = false;
            }
            async function createApiToken() {
                const label = document.getElementById('apiTokenLabel').value.trim();
                if (!label) return showToast('Give this token a label first', true);
                const expiry = document.getElementById('apiTokenExpiry').value;
                const btn = document.getElementById('createApiTokenBtn'); btn.disabled = true;
                const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'create_api_token', label, expiry }) });
                const json = await res.json();
                btn.disabled = false;
                if (!json.success) return showToast(json.error || 'Failed to create token', true);
                document.getElementById('apiTokenNewValue').value = json.token;
                document.getElementById('apiTokenNewWrap').style.display = '';
                document.getElementById('apiTokenLabel').value = '';
                showToast('Token created!');
                setTimeout(() => window.location.reload(), 4000);
            }
            function copyApiToken() {
                const input = document.getElementById('apiTokenNewValue');
                input.select();
                navigator.clipboard ? navigator.clipboard.writeText(input.value).then(() => showToast('Token copied!')) : document.execCommand('copy');
            }
            function copyApiExample(id) {
                const text = document.getElementById(id).textContent;
                navigator.clipboard ? navigator.clipboard.writeText(text).then(() => showToast('Copied!')) : (() => {
                    const range = document.createRange(); range.selectNode(document.getElementById(id));
                    window.getSelection().removeAllRanges(); window.getSelection().addRange(range);
                    document.execCommand('copy'); window.getSelection().removeAllRanges();
                })();
            }
            async function renewApiToken(id) {
                const select = document.getElementById('apiTokenRenewSelect' + id);
                const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'renew_api_token', id, expiry: select.value }) });
                const json = await res.json();
                if (json.success) { showToast('Token renewed!'); setTimeout(() => window.location.reload(), 800); }
                else showToast(json.error || 'Failed to renew token', true);
            }
            async function revokeApiToken(id) {
                if (!confirm('Revoke this API token? Anything using it will stop working immediately.')) return;
                const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'revoke_api_token', id }) });
                const json = await res.json();
                if (json.success) { showToast('Token revoked.'); setTimeout(() => window.location.reload(), 800); }
                else showToast(json.error || 'Failed to revoke token', true);
            }
            async function deleteApiToken(id) {
                if (!confirm('Permanently delete this token? This removes it from the list entirely — unlike Revoke, this can\'t be undone.')) return;
                const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'delete_api_token', id }) });
                const json = await res.json();
                if (json.success) { showToast('Token deleted.'); setTimeout(() => window.location.reload(), 800); }
                else showToast(json.error || 'Failed to delete token', true);
            }
            async function createUser() {
                const username = document.getElementById('newUserUsername').value.trim();
                const email = document.getElementById('newUserEmail').value.trim();
                const password = document.getElementById('newUserPassword').value;
                const role = document.getElementById('newUserRole').value;
                if (!username || !email || !password) return showToast('Username, email, and password are all required', true);
                const btn = document.getElementById('createUserBtn'); btn.disabled = true;
                const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'create_user', username, email, password, role }) });
                const json = await res.json();
                btn.disabled = false;
                if (!json.success) return showToast(json.error || 'Failed to create user', true);
                showToast('User created!');
                setTimeout(() => window.location.reload(), 800);
            }
            async function updateUserRole(id) {
                const role = document.getElementById('userRoleSelect' + id).value;
                const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'update_user', id, role }) });
                const json = await res.json();
                if (json.success) { showToast('User updated!'); setTimeout(() => window.location.reload(), 800); }
                else showToast(json.error || 'Failed to update user', true);
            }
            async function deleteUser(id) {
                if (!confirm('Delete this user account? This can\'t be undone.')) return;
                const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'delete_user', id }) });
                const json = await res.json();
                if (json.success) { showToast('User deleted.'); setTimeout(() => window.location.reload(), 800); }
                else showToast(json.error || 'Failed to delete user', true);
            }
            async function saveInvoiceNumbering() {
                const btn = document.getElementById('saveInvoiceNumberingBtn'); btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving...'; btn.disabled = true;
                const form = document.getElementById('invoiceNumberingForm');
                const data = new URLSearchParams(new FormData(form));
                data.append('action', 'save_invoice_numbering');
                const res = await fetch('', { method: 'POST', body: data });
                const json = await res.json();
                if (json.success) { showToast('Invoice numbering format saved!'); } else { showToast(json.error || 'Failed to save', true); }
                btn.innerHTML = '<i class="fa-solid fa-save"></i> Save Numbering Format'; btn.disabled = false;
            }
            document.getElementById('cronInput').addEventListener('input', updateCronHuman);
            // Init on load
            document.addEventListener('DOMContentLoaded', updateCronHuman);

            // Sidebar badge counts are baked in at initial render only, so background
            // changes (e.g. the cron container firing recurring billing) would leave
            // them stale without polling. Also runs on tab focus, so switching back
            // after stepping away feels current too.
            async function refreshNavCounts() {
                try {
                    const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'get_nav_counts' }) });
                    const json = await res.json();
                    if (!json.success) return;
                    document.getElementById('navInvoiceCountBadge').textContent = json.invoice_count;
                    const unpaidEl = document.getElementById('navUnpaidCountBadge');
                    unpaidEl.textContent = json.unpaid_count;
                    unpaidEl.style.display = json.unpaid_count > 0 ? '' : 'none';
                    document.getElementById('navQuoteCountBadge').textContent = json.quote_count;
                    document.getElementById('navClientCountBadge').textContent = json.client_count;
                    document.getElementById('navExpenseCountBadge').textContent = json.expense_count;
                } catch (e) { /* silent — next poll retries */ }
            }
            setInterval(refreshNavCounts, 60000);
            document.addEventListener('visibilitychange', () => { if (!document.hidden) refreshNavCounts(); });
            async function sendTestEmail() {
                const email = document.getElementById('testEmailInput').value;
                if (!email) return showToast('Enter an email', true);
                const btn = document.getElementById('sendTestEmailBtn'); btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Sending...'; btn.disabled = true;
                const data = new URLSearchParams({ action: 'test_email', email: email });
                const res = await fetch('', { method: 'POST', body: data }); const json = await res.json();
                if (json.success) { showToast(`Test email sent!`); btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Send Test Email'; btn.disabled = false; document.getElementById('testEmailInput').value = ''; }
                else { showToast(json.error || 'Failed to send', true); btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Send Test Email'; btn.disabled = false; }
            }

            const missingFiles = <?= json_encode(array_values($missingFiles)) ?>;
            async function syncFiles() {
                const btn = document.getElementById('syncBtn');
                btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Importing...';
                btn.disabled = true;
                const data = new URLSearchParams({ action: 'sync_missing', files: JSON.stringify(missingFiles) });
                const res = await fetch('', { method: 'POST', body: data });
                const json = await res.json();
                if (json.success) {
                    showToast(`Imported ${json.imported} files!`);
                    if (json.mismatches && json.mismatches.length > 0) {
                        alert("WARNING: The following files were skipped because their filename does not match the Invoice Number inside the file:\n\n" + json.mismatches.join("\n"));
                    }
                    setTimeout(() => window.location.reload(), json.mismatches && json.mismatches.length > 0 ? 3000 : 1500);
                } else {
                    showToast(json.error, true);
                    btn.innerHTML = '<i class="fa-solid fa-download"></i> Import All Missing';
                    btn.disabled = false;
                }
            }

            const missingDiskIds = <?= json_encode(array_column($missingDiskData, 'id')) ?>;
            async function restoreMissingFiles() {
                if (!confirm('This will rebuild the HTML files for all ' + missingDiskIds.length + ' missing invoices using the data saved in the database. Proceed?')) return;
                const btn = document.getElementById('restoreBtn'); btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Rebuilding...'; btn.disabled = true;
                const data = new URLSearchParams({ action: 'restore_missing', ids: JSON.stringify(missingDiskIds) });
                const res = await fetch('', { method: 'POST', body: data }); const json = await res.json();
                if (json.success) {
                    let msg = `Rebuilt ${json.restored} file${json.restored === 1 ? '' : 's'}.`;
                    if (json.no_content > 0) {
                        msg += ` ${json.no_content} had no stored content to rebuild from — likely historical records imported without an original invoice file. Their database records (client, amount, dates, paid status) are still intact for reporting; delete them below only if you don't need that history.`;
                    }
                    showToast(msg, json.restored === 0 && json.no_content > 0);
                    setTimeout(() => window.location.reload(), json.no_content > 0 ? 4000 : 1500);
                }
                else { showToast(json.error, true); btn.innerHTML = '<i class="fa-solid fa-file-export"></i> Rebuild HTML Files'; btn.disabled = false; }
            }
            async function deleteMissingDb() {
                if (!confirm('WARNING: This will permanently DELETE ' + missingDiskIds.length + ' invoice records from the database that do not have matching HTML files. This cannot be undone! Proceed?')) return;
                const btn = document.getElementById('delDbBtn'); btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Deleting...'; btn.disabled = true;
                const data = new URLSearchParams({ action: 'delete_missing_db', ids: JSON.stringify(missingDiskIds) });
                const res = await fetch('', { method: 'POST', body: data }); const json = await res.json();
                if (json.success) { showToast(`Deleted ${json.deleted} records!`); setTimeout(() => window.location.reload(), 1500); }
                else { showToast(json.error, true); btn.innerHTML = '<i class="fa-solid fa-trash"></i> Delete DB Entries'; btn.disabled = false; }
            }

            async function initChart(force = false) {
                if (chartAllData && !force) { renderChart(); return; }
                const res = await fetch('?api=chart');
                chartAllData = await res.json();
                renderChart();
            }

            function setChartRange(range) {
                chartRange = range;
                document.getElementById('chartRange12').className = 'btn small' + (range === '12' ? ' primary' : '');
                document.getElementById('chartRangeAll').className = 'btn small' + (range === 'all' ? ' primary' : '');
                renderChart();
            }

            function renderChart() {
                if (!chartAllData) return;
                const { clients, data: allData } = chartAllData;
                const displayData = chartRange === '12' ? allData.slice(-12) : allData;
                const labels = displayData.map(d => d.month);
                const clientKeys = Object.keys(clients);
                const datasets = [];
                clientKeys.forEach((ck, i) => {
                    datasets.push({
                        label: clients[ck],
                        data: displayData.map(d => d[ck] ?? 0),
                        borderColor: CLIENT_COLORS[i % CLIENT_COLORS.length],
                        backgroundColor: CLIENT_COLORS[i % CLIENT_COLORS.length] + '20',
                        borderWidth: 2, pointRadius: 2, pointHoverRadius: 5, tension: 0.3, fill: false
                    });
                });
                // Total line
                datasets.push({
                    label: 'Total (All Clients)',
                    data: displayData.map(d => d.total ?? 0),
                    borderColor: '#ffffff',
                    backgroundColor: 'rgba(255,255,255,0.05)',
                    borderWidth: 2.5, borderDash: [6, 3], pointRadius: 2, pointHoverRadius: 5, tension: 0.3, fill: false
                });
                if (chartInstance) chartInstance.destroy();
                chartInstance = new Chart(document.getElementById('revenueChart').getContext('2d'), {
                    type: 'line',
                    data: { labels, datasets },
                    options: {
                        responsive: true, maintainAspectRatio: false,
                        interaction: { mode: 'index', intersect: false },
                        plugins: {
                            legend: { display: true, labels: { color: '#94a3b8', usePointStyle: true, pointStyleWidth: 10, boxHeight: 6 } },
                            tooltip: { callbacks: { label: ctx => ` ${ctx.dataset.label}: ${APP_CURRENCY} $${ctx.raw.toLocaleString()}` } }
                        },
                        scales: {
                            y: { beginAtZero: true, grid: { color: 'rgba(255,255,255,0.05)' }, ticks: { color: '#94a3b8', callback: v => '$' + v.toLocaleString() } },
                            x: { grid: { display: false }, ticks: { color: '#94a3b8', maxRotation: 45 } }
                        }
                    }
                });

                const lastRow = allData.length > 0 ? allData[allData.length - 1] : null;
                const pieLabels = [];
                const pieValues = [];
                const pieBg = [];
                const pieBorder = [];

                if (lastRow) {
                    clientKeys.forEach((ck, i) => {
                        if (lastRow[ck] && lastRow[ck] > 0) {
                            pieLabels.push(clients[ck]);
                            pieValues.push(lastRow[ck]);
                            pieBg.push(CLIENT_COLORS[i % CLIENT_COLORS.length] + '80');
                            pieBorder.push(CLIENT_COLORS[i % CLIENT_COLORS.length]);
                        }
                    });
                }

                if (pieChartInstance) pieChartInstance.destroy();
                pieChartInstance = new Chart(document.getElementById('pieChart').getContext('2d'), {
                    type: 'doughnut',
                    data: { labels: pieLabels, datasets: [{ data: pieValues, backgroundColor: pieBg, borderColor: pieBorder, borderWidth: 1 }] },
                    options: {
                        responsive: true, maintainAspectRatio: false,
                        plugins: {
                            legend: { position: 'bottom', labels: { color: '#94a3b8', usePointStyle: true, padding: 20 } },
                            tooltip: { callbacks: { label: ctx => ` ${ctx.label}: ${APP_CURRENCY} $${ctx.raw.toLocaleString()}` } }
                        }
                    }
                });
            }

            // ── Brand Settings ─────────────────────────────────────────────
            async function saveProfile() {
                const newUsername = document.getElementById('newUsername').value.trim();
                const newEmail = document.getElementById('newEmail').value.trim();
                const currentPassword = document.getElementById('currentPassword').value;
                const newPassword = document.getElementById('newPassword').value;
                const confirmPassword = document.getElementById('confirmPassword').value;
                if (!currentPassword) return showToast('Current password is required', true);
                const btn = document.getElementById('saveProfileBtn'); btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving...'; btn.disabled = true;
                const data = new URLSearchParams({ action: 'update_profile', new_username: newUsername, new_email: newEmail, current_password: currentPassword, new_password: newPassword, confirm_password: confirmPassword });
                const res = await fetch('', { method: 'POST', body: data });
                const json = await res.json();
                btn.innerHTML = '<i class="fa-solid fa-save"></i> Save Profile'; btn.disabled = false;
                if (json.success) {
                    showToast('Profile saved! Logging out for changes to take effect...');
                    document.getElementById('currentPassword').value = '';
                    document.getElementById('newPassword').value = '';
                    document.getElementById('confirmPassword').value = '';
                    setTimeout(() => { document.querySelector('form [name="auth_action"]') && document.querySelector('form [name="auth_action"]').closest('form').submit(); }, 2000);
                } else { showToast(json.error || 'Failed to save', true); }
            }
            async function startTotpSetup() {
                const btn = document.getElementById('totpStartBtn'); btn.disabled = true;
                const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'totp_setup_init' }) });
                const json = await res.json();
                btn.disabled = false;
                if (!json.success) return showToast(json.error || 'Failed to start setup', true);
                document.getElementById('totpSecretDisplay').value = json.secret;
                document.getElementById('totpAccountLabel').textContent = json.account_label;
                document.getElementById('totpConfirmCode').value = '';
                document.getElementById('totpSetupWrap').style.display = '';
                btn.style.display = 'none';
            }
            function cancelTotpSetup() {
                document.getElementById('totpSetupWrap').style.display = 'none';
                document.getElementById('totpStartBtn').style.display = '';
            }
            async function confirmTotpSetup() {
                const btn = document.getElementById('totpConfirmBtn'); btn.disabled = true;
                const code = document.getElementById('totpConfirmCode').value;
                const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'totp_setup_confirm', code }) });
                const json = await res.json();
                btn.disabled = false;
                if (json.success) {
                    document.getElementById('totpSetupWrap').style.display = 'none';
                    document.getElementById('totpStartBtn').style.display = 'none';
                    document.getElementById('totpBackupCodesList').textContent = (json.backup_codes || []).join('\n');
                    document.getElementById('totpBackupCodesWrap').style.display = '';
                    showToast('Two-factor authentication enabled!');
                } else showToast(json.error || 'Invalid code', true);
            }
            async function regenerateBackupCodes() {
                if (!confirm('Regenerate backup codes? Any codes you saved previously will stop working.')) return;
                const password = document.getElementById('totpDisablePassword').value;
                const btn = document.getElementById('totpRegenBtn'); btn.disabled = true;
                const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'totp_regenerate_backup_codes', current_password: password }) });
                const json = await res.json();
                btn.disabled = false;
                if (json.success) {
                    document.getElementById('totpBackupCodesList').textContent = (json.backup_codes || []).join('\n');
                    document.getElementById('totpBackupCodesWrap').style.display = '';
                    document.getElementById('totpDisablePassword').value = '';
                    showToast('Backup codes regenerated!');
                } else showToast(json.error || 'Failed to regenerate', true);
            }
            async function disableTotp() {
                if (!confirm('Disable two-factor authentication for this account?')) return;
                const password = document.getElementById('totpDisablePassword').value;
                const btn = document.getElementById('totpDisableBtn'); btn.disabled = true;
                const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'totp_disable', current_password: password }) });
                const json = await res.json();
                btn.disabled = false;
                if (json.success) { showToast('Two-factor authentication disabled.'); setTimeout(() => window.location.reload(), 1000); }
                else showToast(json.error || 'Failed to disable', true);
            }
            async function saveLicenseKey() {
                const key = document.getElementById('licenseKey').value.trim();
                const btn = document.getElementById('saveLicenseBtn'); btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Activating...'; btn.disabled = true;
                const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'save_license_key', license_key: key }) });
                const json = await res.json();
                btn.innerHTML = '<i class="fa-solid fa-save"></i> Activate License'; btn.disabled = false;
                if (json.success && json.valid) {
                    showToast('License activated!');
                    setTimeout(() => window.location.reload(), 1000);
                } else if (json.success) {
                    const reasons = {
                        malformed: 'That license key doesn\'t look valid — check you copied the whole string with nothing missing.',
                        bad_signature: 'That license key failed verification — check you copied it exactly, with nothing missing or altered.',
                        no_profile_email: 'Your admin account has no email set. Add the email your license was issued to under Authentication, then try again.',
                        email_mismatch: 'This license was issued to a different email than your admin account\'s. Update your email under Authentication to match, or contact your seller for a new key.',
                        domain_mismatch: 'This license is issued for a different domain than the one you\'re accessing this instance on. Contact your seller for a new key if you\'ve moved domains.',
                    };
                    showToast(reasons[json.reason] || 'Saved, but this key is not valid for this domain/install.', true);
                    // The key was saved server-side even though it doesn't validate, so any
                    // previously-active license is now deactivated — reload to reflect that.
                    setTimeout(() => window.location.reload(), 1500);
                } else {
                    showToast(json.error || 'Failed to save', true);
                }
            }
            async function clearLicenseKey() {
                if (!confirm('Deactivate your license? The seven paid features (payment collection, recurring billing, Client Portal, external API, Reporting & Statistics, adding teammates, and Powered-by removal) will lock again until you activate a key.')) return;
                const btn = document.getElementById('clearLicenseBtn'); btn.disabled = true;
                const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'save_license_key', license_key: '' }) });
                const json = await res.json();
                if (json.success) {
                    document.getElementById('licenseKey').value = '';
                    showToast('License deactivated.');
                    setTimeout(() => window.location.reload(), 1000);
                } else {
                    btn.disabled = false;
                    showToast(json.error || 'Failed to deactivate', true);
                }
            }
            async function loadDefaultInvoiceTemplate() {
                const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'get_default_invoice_template' }) });
                const json = await res.json();
                if (json.success) { document.getElementById('customInvoiceTemplate').value = json.template; }
                else { showToast(json.error || 'Failed to load default template', true); }
            }
            async function previewInvoiceTemplate() {
                const template = document.getElementById('invoiceTemplate').value;
                const params = { action: 'preview_invoice_template', template };
                if (template === 'custom') params.custom_html = document.getElementById('customInvoiceTemplate').value;
                const res = await fetch('', { method: 'POST', body: new URLSearchParams(params) });
                const json = await res.json();
                if (json.success) { _lastAdhocPreviewParams = null; viewInvoice({ invoice_number: 'INV-SAMPLE-001 (preview)', html_content: json.html }); }
                else { showToast(json.error || 'Failed to render preview', true); }
            }
            async function saveInvoiceTemplate() {
                const btn = document.getElementById('saveInvoiceTemplateBtn'); btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving...'; btn.disabled = true;
                const form = document.getElementById('invoiceTemplateForm');
                const data = new URLSearchParams(new FormData(form));
                data.append('action', 'save_invoice_template');
                const res = await fetch('', { method: 'POST', body: data });
                const json = await res.json();
                if (json.success) { showToast('Invoice template saved!'); } else { showToast(json.error || 'Failed to save', true); }
                btn.innerHTML = '<i class="fa-solid fa-save"></i> Save Invoice Template'; btn.disabled = false;
            }
            async function saveBusinessIdentity() {
                const btn = document.getElementById('saveBusinessIdentityBtn'); btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving...'; btn.disabled = true;
                const form = document.getElementById('businessIdentityForm');
                const formData = new FormData(form);
                formData.append('action', 'save_business_identity');
                const res = await fetch('', { method: 'POST', body: formData });
                const json = await res.json();
                if (json.success) {
                    showToast('Business identity saved! This only affects invoices sent to your clients — the Invoxa app itself keeps its own identity.');
                } else { showToast(json.error || 'Failed to save', true); }
                btn.innerHTML = '<i class="fa-solid fa-save"></i> Save Business Identity'; btn.disabled = false;
            }
            async function saveInvoiceDefaults() {
                const btn = document.getElementById('saveInvoiceDefaultsBtn'); btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving...'; btn.disabled = true;
                const form = document.getElementById('invoiceDefaultsForm');
                const data = new URLSearchParams(new FormData(form));
                data.append('action', 'save_invoice_defaults');
                const res = await fetch('', { method: 'POST', body: data });
                const json = await res.json();
                if (json.success) { showToast('Invoice defaults saved!'); } else { showToast(json.error || 'Failed to save', true); }
                btn.innerHTML = '<i class="fa-solid fa-save"></i> Save Invoice Defaults'; btn.disabled = false;
            }
            async function savePaymentDetails() {
                const btn = document.getElementById('savePaymentDetailsBtn'); btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving...'; btn.disabled = true;
                const form = document.getElementById('paymentDetailsForm');
                const data = new URLSearchParams(new FormData(form));
                data.append('action', 'save_payment_details');
                const res = await fetch('', { method: 'POST', body: data });
                const json = await res.json();
                if (json.success) { showToast('Default payment details saved!'); } else { showToast(json.error || 'Failed to save', true); }
                btn.innerHTML = '<i class="fa-solid fa-save"></i> Save Payment Details'; btn.disabled = false;
            }
            async function saveEmailTemplates() {
                const btn = document.getElementById('saveEmailTemplatesBtn'); btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving...'; btn.disabled = true;
                const form = document.getElementById('emailTemplatesForm');
                const data = new URLSearchParams(new FormData(form));
                data.append('action', 'save_email_templates');
                const res = await fetch('', { method: 'POST', body: data });
                const json = await res.json();
                if (json.success) {
                    showToast('Email templates saved!');
                } else { showToast(json.error || 'Failed to save', true); }
                btn.innerHTML = '<i class="fa-solid fa-save"></i> Save Email Templates'; btn.disabled = false;
            }

            // ── PDF Download ───────────────────────────────────────────────
            // Server-side render (dompdf, see ?export=invoice_pdf in invoxa.php) —
            // a plain navigation rather than fetch/blob so the browser handles the
            // Content-Disposition download itself.
            async function downloadInvoicePdf() {
                if (_currentViewInvoiceId) {
                    window.location.href = '?export=invoice_pdf&id=' + encodeURIComponent(_currentViewInvoiceId);
                    return;
                }
                if (!_lastAdhocPreviewParams) { showToast('Nothing to download yet', true); return; }
                // Unsaved preview: no GET URL to navigate to, so fetch the PDF as a
                // blob and trigger the download via a throwaway link instead.
                const btn = document.getElementById('downloadPdfBtn'); const originalHtml = btn.innerHTML;
                btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>'; btn.disabled = true;
                try {
                    const data = new URLSearchParams({ ..._lastAdhocPreviewParams, action: 'preview_adhoc_pdf' });
                    const res = await fetch('', { method: 'POST', body: data });
                    if (!res.ok) throw new Error(await res.text());
                    const blob = await res.blob();
                    const url = URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url; a.download = 'Invoice-preview.pdf';
                    document.body.appendChild(a); a.click(); a.remove();
                    URL.revokeObjectURL(url);
                } catch (e) {
                    showToast('Failed to generate PDF', true);
                } finally {
                    btn.innerHTML = originalHtml; btn.disabled = false;
                }
            }

            // ── CRM Drawer ─────────────────────────────────────────────────
            let _crmClientId = null;
            function openCrm(c) {
                _crmClientId = c.id;
                document.getElementById('crmDrawerTitle').innerHTML = '<i class="fa-solid fa-user" style="color:var(--accent); margin-right:0.5rem;"></i>' + c.client_name;
                document.getElementById('crmNotes').value = '';
                document.getElementById('crmStats').innerHTML = '<div style="background:rgba(255,255,255,0.03);border:1px solid var(--border);border-radius:8px;padding:1rem;text-align:center;"><div style="color:var(--text-secondary);font-size:0.75rem;margin-bottom:0.25rem;">Loading...</div></div>';
                document.getElementById('crmRecentInvoices').innerHTML = '<div style="color:var(--text-secondary);font-size:0.85rem;">Loading...</div>';
                document.getElementById('crmDrawer').style.right = '0';
                document.getElementById('crmOverlay').style.display = 'block';
                fetchCrmData(c.id);
            }
            function closeCrm() {
                document.getElementById('crmDrawer').style.right = '-440px';
                document.getElementById('crmOverlay').style.display = 'none';
                _crmClientId = null;
            }
            async function fetchCrmData(clientId) {
                const data = new URLSearchParams({ action: 'get_crm_data', client_id: clientId });
                const res = await fetch('', { method: 'POST', body: data });
                const json = await res.json();
                if (!json.success) return;
                const s = json.stats;
                document.getElementById('crmStats').innerHTML = `
                    <div style="background:rgba(59,130,246,0.1);border:1px solid rgba(59,130,246,0.2);border-radius:8px;padding:1rem;text-align:center;">
                        <div style="color:var(--text-secondary);font-size:0.75rem;margin-bottom:0.25rem;">Total Billed</div>
                        <div style="color:var(--accent);font-weight:700;font-size:1.1rem;">$${parseFloat(s.total_billed || 0).toLocaleString('en-US', { minimumFractionDigits: 2 })}</div>
                    </div>
                    <div style="background:rgba(16,185,129,0.1);border:1px solid rgba(16,185,129,0.2);border-radius:8px;padding:1rem;text-align:center;">
                        <div style="color:var(--text-secondary);font-size:0.75rem;margin-bottom:0.25rem;">Total Paid</div>
                        <div style="color:var(--success);font-weight:700;font-size:1.1rem;">$${parseFloat(s.total_paid || 0).toLocaleString('en-US', { minimumFractionDigits: 2 })}</div>
                    </div>
                    <div style="background:rgba(245,158,11,0.1);border:1px solid rgba(245,158,11,0.2);border-radius:8px;padding:1rem;text-align:center;">
                        <div style="color:var(--text-secondary);font-size:0.75rem;margin-bottom:0.25rem;">Outstanding</div>
                        <div style="color:var(--warning);font-weight:700;font-size:1.1rem;">$${(parseFloat(s.total_billed || 0) - parseFloat(s.total_paid || 0)).toLocaleString('en-US', { minimumFractionDigits: 2 })}</div>
                    </div>
                    <div style="background:rgba(255,255,255,0.03);border:1px solid var(--border);border-radius:8px;padding:1rem;text-align:center;">
                        <div style="color:var(--text-secondary);font-size:0.75rem;margin-bottom:0.25rem;">Invoices</div>
                        <div style="color:var(--text-primary);font-weight:700;font-size:1.1rem;">${s.inv_count || 0}</div>
                    </div>`;
                const invHtml = (json.recent || []).map(i => `
                    <div style="display:flex;justify-content:space-between;align-items:center;padding:0.5rem 0.75rem;border-radius:6px;border:1px solid var(--border);margin-bottom:0.5rem;background:rgba(255,255,255,0.02);">
                        <div><strong style="font-size:0.85rem;">${i.invoice_number}</strong><div style="color:var(--text-secondary);font-size:0.75rem;">${i.invoice_date.substring(0, 10)}</div></div>
                        <div style="text-align:right;"><div style="font-weight:600;font-size:0.9rem;">$${parseFloat(i.amount).toLocaleString('en-US', { minimumFractionDigits: 2 })}</div><span class="badge ${i.status}" style="font-size:0.7rem;">${i.status}</span></div>
                    </div>`).join('');
                document.getElementById('crmRecentInvoices').innerHTML = invHtml || '<p style="color:var(--text-secondary);font-size:0.85rem;">No invoices yet.</p>';
                document.getElementById('crmNotes').value = json.crm_notes || '';
            }
            async function saveCrmNotes() {
                if (!_crmClientId) return;
                const notes = document.getElementById('crmNotes').value;
                const data = new URLSearchParams({ action: 'save_crm_notes', client_id: _crmClientId, notes: notes });
                const res = await fetch('', { method: 'POST', body: data });
                const json = await res.json();
                if (json.success) showToast('Notes saved!');
                else showToast(json.error || 'Failed to save', true);
            }

            // ── Quotes ─────────────────────────────────────────────────────
            function openQuoteModal() {
                nav('billing');
                document.getElementById('adhocClient').value = '';
                resetLineItems();
                document.getElementById('isQuoteFlag').value = '1';
                document.getElementById('billingPageTitle').textContent = 'New Quote';
                document.getElementById('billingCardTitle').textContent = 'Create a Quote / Estimate';
                document.getElementById('adhocQuoteExpiryGroup').style.display = '';
                const defaultExpiry = new Date();
                defaultExpiry.setDate(defaultExpiry.getDate() + 30);
                document.getElementById('adhocQuoteExpiry').value = defaultExpiry.toISOString().substring(0, 10);
                showToast('Fill in the form and click "Save as Quote" to create it.');
            }
            function resetAdhocMode() {
                document.getElementById('isQuoteFlag').value = '0';
                document.getElementById('billingPageTitle').textContent = 'Ad Hoc Invoice';
                document.getElementById('billingCardTitle').textContent = 'Create Adhoc Invoice (One-Off)';
                document.getElementById('adhocQuoteExpiryGroup').style.display = 'none';
                document.getElementById('adhocQuoteExpiry').value = '';
            }
            async function convertQuote(id, num, expired = false) {
                const warning = expired ? 'This quote has expired. Convert quote ' + num + ' to a final invoice anyway? This cannot be undone.' : 'Convert quote ' + num + ' to a final invoice? This cannot be undone.';
                if (!confirm(warning)) return;
                const data = new URLSearchParams({ action: 'convert_quote', id: id });
                const res = await fetch('', { method: 'POST', body: data });
                const json = await res.json();
                if (json.success) { showToast('Converted to invoice ' + json.invoice_number + '!'); setTimeout(() => window.location.reload(), 1500); }
                else showToast(json.error || 'Failed to convert', true);
            }
            // ── Backup & Restore ───────────────────────────────────────────
            async function backupDatabase() {
                const checkboxes = document.querySelectorAll('.backup-table-checkbox:checked');
                const selectedTables = Array.from(checkboxes).map(cb => cb.value).join(',');
                if (!selectedTables) {
                    showToast('Please select at least one table to backup.', true);
                    return;
                }
                const data = new URLSearchParams({ action: 'backup_db', tables: selectedTables });
                const res = await fetch('', { method: 'POST', body: data });
                const json = await res.json();
                if (json.success) {
                    showToast('Backup generated and saved to the backups folder!');
                } else {
                    showToast(json.error || 'Failed to generate backup', true);
                }
            }

            async function loadBackupList() {
                const sel = document.getElementById('restoreBackupSelect');
                sel.innerHTML = '<option>Loading...</option>';
                const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'list_backups' }) });
                const json = await res.json();
                if (!json.success || !json.backups.length) {
                    sel.innerHTML = '<option value="">No backups yet — create one above</option>';
                    return;
                }
                sel.innerHTML = json.backups.map(b => `<option value="${b.filename}">${b.filename} (${b.modified})</option>`).join('');
            }

            async function importBackup(file) {
                if (!file) return;
                const input = document.getElementById('importBackupFile');
                const fd = new FormData();
                fd.append('action', 'import_backup');
                fd.append('backup_file', file);
                try {
                    const res = await fetch('', { method: 'POST', body: fd });
                    const json = await res.json();
                    if (json.success) {
                        const note = json.remapped ? ' (remapped from an older weblab_ export)' : '';
                        showToast('Imported ' + json.filename + note + ' — select it above to restore.');
                        loadBackupList();
                    } else {
                        showToast(json.error || 'Import failed', true);
                    }
                } catch (e) {
                    showToast('Import failed (network error)', true);
                }
                input.value = '';
            }

            async function resendVerificationEmail(btnId = 'resendVerifyBtn') {
                const btn = document.getElementById(btnId);
                const originalText = btn.textContent;
                btn.disabled = true;
                btn.textContent = 'Sending…';
                try {
                    const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'resend_verification_email' }) });
                    const json = await res.json();
                    showToast(json.success ? 'Confirmation email sent' : (json.error || 'Failed to send'), !json.success);
                } catch (e) {
                    showToast('Failed to send (network error)', true);
                } finally {
                    btn.disabled = false;
                    btn.textContent = originalText;
                }
            }

            async function seedDemoData() {
                const btn = document.getElementById('seedDemoBtn');
                btn.disabled = true;
                btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Inserting…';
                try {
                    const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'seed_demo_data' }) });
                    const json = await res.json();
                    if (json.success) {
                        window.location.reload();
                    } else {
                        showToast(json.error || 'Failed to insert demo data', true);
                        btn.disabled = false;
                        btn.innerHTML = '<i class="fa-solid fa-wand-magic-sparkles"></i> Insert Dummy Data';
                    }
                } catch (e) {
                    showToast('Failed to insert demo data (network error)', true);
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fa-solid fa-wand-magic-sparkles"></i> Insert Dummy Data';
                }
            }

            async function clearDemoData() {
                const btn = document.getElementById('clearDemoBtn');
                btn.disabled = true;
                btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Clearing…';
                try {
                    const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'clear_demo_data' }) });
                    const json = await res.json();
                    if (json.success) {
                        window.location.reload();
                    } else {
                        showToast(json.error || 'Failed to clear demo data', true);
                        btn.disabled = false;
                        btn.innerHTML = '<i class="fa-solid fa-broom"></i> Clear Dummy Data';
                    }
                } catch (e) {
                    showToast('Failed to clear demo data (network error)', true);
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fa-solid fa-broom"></i> Clear Dummy Data';
                }
            }

            function selectAllTests(checked) {
                document.querySelectorAll('.test-suite-checkbox, .test-suite-group-checkbox').forEach(cb => cb.checked = checked);
                if (checked) resetTestVisibility(); // "Select All" also un-does any pill filter — checked-but-hidden would be confusing
            }
            function toggleTestGroup(groupCheckbox) {
                const group = groupCheckbox.dataset.group;
                document.querySelectorAll('.test-suite-row[data-group="' + CSS.escape(group) + '"] .test-suite-checkbox').forEach(cb => cb.checked = groupCheckbox.checked);
            }
            function resetTestVisibility() {
                document.querySelectorAll('#testSuiteList .test-suite-group-row, #testSuiteList .test-suite-row').forEach(row => row.style.display = '');
            }
            function setActiveTestPill(group) {
                document.querySelectorAll('.pill-btn').forEach(btn => btn.classList.toggle('active', btn.dataset.pillGroup === group));
            }
            // "All" pill — shows every section again and selects everything, the
            // inverse of isolating to one section below.
            function selectAllTestsPill() {
                selectAllTests(true);
                setActiveTestPill('__all__');
            }
            // Section pills — isolate to exactly one section: hides every other
            // section's rows entirely (not just unchecking them) and selects only this one.
            function selectTestGroupOnly(group) {
                document.querySelectorAll('#testSuiteList .test-suite-group-row').forEach(row => {
                    const cb = row.querySelector('.test-suite-group-checkbox');
                    const isMatch = cb.dataset.group === group;
                    row.style.display = isMatch ? '' : 'none';
                    cb.checked = isMatch;
                });
                document.querySelectorAll('#testSuiteList .test-suite-row').forEach(row => {
                    const isMatch = row.dataset.group === group;
                    row.style.display = isMatch ? '' : 'none';
                    row.querySelector('.test-suite-checkbox').checked = isMatch;
                });
                setActiveTestPill(group);
            }
            async function runTestSuite() {
                const rows = Array.from(document.querySelectorAll('.test-suite-row'));
                const selected = [];
                rows.forEach(row => {
                    const checked = row.querySelector('.test-suite-checkbox').checked;
                    // Only touch the status of rows actually being run — an unchecked row
                    // keeps its previous result (or "Not run"), so the column is never blank.
                    if (checked) {
                        row.querySelector('.test-suite-status').innerHTML = '<i class="fa-solid fa-spinner fa-spin" style="color:var(--text-secondary);"></i> Running…';
                        selected.push(row.dataset.testName);
                    }
                });
                if (selected.length === 0) return showToast('Select at least one test first', true);
                const btn = document.getElementById('runTestSuiteBtn');
                btn.disabled = true;
                btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Running…';
                document.getElementById('testSuiteSummary').innerHTML = '';
                try {
                    const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'run_test_suite', tests: JSON.stringify(selected) }) });
                    const json = await res.json();
                    if (!json.success) {
                        showToast(json.error || 'Test suite failed to run', true);
                        return;
                    }
                    // Results land inline in each row's own Status cell (not a separate
                    // list) so what you selected and what happened to it stay tied
                    // together at a glance.
                    const resultsByName = {};
                    json.results.forEach(r => { resultsByName[r.name] = r; });
                    rows.forEach(row => {
                        const r = resultsByName[row.dataset.testName];
                        if (!r) return; // not selected this run — leave its status as-is
                        const status = row.querySelector('.test-suite-status');
                        status.innerHTML = r.status === 'pass'
                            ? '<i class="fa-solid fa-check" style="color:var(--success);"></i> Passed'
                            : '<i class="fa-solid fa-xmark" style="color:var(--danger);"></i> <span style="color:var(--danger);">' + (r.message || 'Failed').replace(/</g, '&lt;') + '</span>';
                    });
                    const allPassed = json.failed === 0;
                    document.getElementById('testSuiteSummary').innerHTML =
                        '<span style="color:' + (allPassed ? 'var(--success)' : 'var(--danger)') + '; font-weight:600;">' +
                        (allPassed ? '<i class="fa-solid fa-circle-check"></i> ' : '<i class="fa-solid fa-circle-xmark"></i> ') +
                        json.passed + ' passed, ' + json.failed + ' failed</span>';
                    showToast(allPassed ? 'All selected tests passed!' : (json.failed + ' test(s) failed'), !allPassed);
                } catch (e) {
                    showToast('Failed to run test suite (network error)', true);
                } finally {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fa-solid fa-play"></i> Run Selected';
                }
            }

            function openFactoryReset() {
                document.getElementById('factoryResetConfirmText').value = '';
                document.getElementById('factoryResetPassword').value = '';
                document.getElementById('factoryResetBtn').disabled = true;
                document.getElementById('factoryResetModal').classList.add('active');
            }

            async function doFactoryReset() {
                const confirmText = document.getElementById('factoryResetConfirmText').value;
                const password = document.getElementById('factoryResetPassword').value;
                if (confirmText !== 'RESET') return;
                if (!password) {
                    showToast('Enter your current password', true);
                    return;
                }
                const btn = document.getElementById('factoryResetBtn');
                btn.disabled = true;
                btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Erasing…';
                try {
                    const res = await fetch('', {
                        method: 'POST',
                        body: new URLSearchParams({ action: 'factory_reset', confirm: confirmText, password })
                    });
                    const json = await res.json();
                    if (json.success) {
                        window.location.reload();
                    } else {
                        showToast(json.error || 'Factory reset failed', true);
                        btn.disabled = false;
                        btn.innerHTML = '<i class="fa-solid fa-bomb"></i> Erase Everything';
                    }
                } catch (e) {
                    showToast('Factory reset failed (network error)', true);
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fa-solid fa-bomb"></i> Erase Everything';
                }
            }

            async function testRestore() {
                try {
                    const sel = document.getElementById('restoreBackupSelect');
                    if (!sel.value) throw new Error('Select a backup first');

                    // Computed server-side (preview_restore) so the raw SQL dump never
                    // has to be transferred to or held in the browser.
                    const previewRes = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'preview_restore', filename: sel.value }) });
                    const previewJson = await previewRes.json();
                    if (!previewJson.success) throw new Error(previewJson.error);
                    const fileStats = previewJson.fileStats;

                    // Fetch DB stats
                    const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'get_db_stats' }) });
                    const json = await res.json();
                    if (!json.success) throw new Error(json.error);
                    const dbStats = json.stats;

                    // Build Terminal Output
                    let textOutput = `=================================================\n`;
                    textOutput += ` DRY RUN RESTORE & DATABASE COMPARISON\n`;
                    textOutput += `=================================================\n\n`;

                    const allTables = new Set([...Object.keys(fileStats), ...Object.keys(dbStats)]);
                    let totalDrop = 0, totalCreate = Object.keys(fileStats).length, totalInsert = 0;

                    for (const t of Array.from(allTables).sort()) {
                        const bCount = fileStats[t] !== undefined ? fileStats[t] : '-';
                        const dCount = dbStats[t] !== undefined ? dbStats[t] : '-';
                        let diff = '';
                        if (bCount !== '-' && dCount !== '-') {
                            const diffNum = bCount - dCount;
                            diff = diffNum > 0 ? `+${diffNum}` : (diffNum < 0 ? `${diffNum}` : '0');
                        } else if (bCount !== '-') {
                            diff = `New Table`;
                        } else {
                            diff = `Ignored (Preserved)`;
                        }
                        if (bCount !== '-') totalInsert += fileStats[t];

                        textOutput += `[TABLE] ${t.padEnd(25)} | Backup: ${String(bCount).padEnd(6)} | DB: ${String(dCount).padEnd(6)} | Diff: ${diff}\n`;
                    }

                    textOutput += `\n-------------------------------------------------\n`;
                    textOutput += ` SUMMARY: Creates: ${totalCreate} | Drops: ${totalDrop} | Inserts: ${totalInsert}\n`;
                    textOutput += `-------------------------------------------------\n`;

                    let html = `<div style="background:#0f172a; border:1px solid rgba(255,255,255,0.1); border-radius:6px; padding:1rem; width:100%; height:400px; overflow-y:auto; box-sizing:border-box;">
                        <pre style="color:#10b981; font-family:'Courier New', Courier, monospace; font-size:13px; margin:0; line-height:1.5;">${textOutput}</pre>
                    </div>`;

                    document.getElementById('restoreModalBody').innerHTML = html;
                    document.getElementById('restoreModalTitle').innerHTML = `Dry Run Summary <span style="font-size:0.9rem; font-weight:normal; color:var(--text-secondary); margin-left:1rem;">Creates: ${totalCreate} | Drops: ${totalDrop} | Inserts: ${totalInsert}</span>`;

                    document.getElementById('restoreModal').classList.add('active');
                } catch (e) {
                    showToast('Failed during dry run: ' + e.message, true);
                }
            }

            async function confirmRestore() {
                const sel = document.getElementById('restoreBackupSelect');
                if (!sel.value) {
                    showToast('Select a backup first', true);
                    return;
                }
                if (!confirm('Are you absolutely sure you want to restore "' + sel.value + '"? This will overwrite existing data and cannot be undone.')) {
                    return;
                }

                document.body.insertAdjacentHTML('beforeend', '<div id="restoreOverlay" style="position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(15,23,42,0.9);z-index:9999;display:flex;flex-direction:column;align-items:center;justify-content:center;color:white;backdrop-filter:blur(5px);"><i class="fa-solid fa-spinner fa-spin fa-3x" style="margin-bottom:1.5rem;color:var(--accent);"></i><h2 style="margin:0;font-weight:600;">Restoring...</h2><p style="color:var(--warning);margin-top:1rem;font-weight:600;"><i class="fa-solid fa-triangle-exclamation"></i> DO NOT REFRESH OR CLOSE THIS PAGE</p></div>');
                try {
                    const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'restore_db_backup', filename: sel.value, confirm: '1' }) });
                    const json = await res.json();
                    document.getElementById('restoreOverlay').remove();
                    if (!json.success) {
                        alert('Restore Error: ' + (json.error || 'Failed to restore backup'));
                        return;
                    }
                    alert('Database restored successfully from ' + sel.value + '.');
                    window.location.reload();
                } catch (e) {
                    const overlay = document.getElementById('restoreOverlay');
                    if (overlay) overlay.remove();
                    alert('Restore Error: Failed during restore process - ' + e.message);
                }
            }

            // ── Tax Year / Monthly Summary Preview Modals ─────────────────────────
            let _csvCurrentData = null; // { type: 'detail'|'monthly', rows, cols, keys }
            function _fmt(n) { return '$' + parseFloat(n || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }

            function _csvEscape(v) {
                const s = (v == null ? '' : String(v));
                return s.includes(',') || s.includes('"') || s.includes('\n') ? '"' + s.replace(/"/g, '""') + '"' : s;
            }

            function _copyCsvToClipboard() {
                if (!_csvCurrentData) return;
                const { cols, rows } = _csvCurrentData;
                const lines = [cols.map(_csvEscape).join(',')];
                for (const row of rows) lines.push(row.map(_csvEscape).join(','));
                const text = lines.join('\r\n');
                navigator.clipboard.writeText(text).then(() => {
                    const btn = document.getElementById('csvPreviewCopyBtn');
                    const orig = btn.innerHTML;
                    btn.innerHTML = '<i class="fa-solid fa-check"></i> Copied!';
                    btn.style.background = 'rgba(16,185,129,0.25)';
                    btn.style.color = '#10b981';
                    setTimeout(() => { btn.innerHTML = orig; btn.style.background = ''; btn.style.color = ''; }, 2000);
                }).catch(() => showToast('Clipboard access denied', true));
            }

            function _renderCsvStats(data) {
                const cards = [
                    { label: 'Total Invoiced', value: _fmt(data.total_invoiced), color: 'var(--accent)' },
                    { label: 'Total Paid', value: _fmt(data.total_paid), color: 'var(--success)' },
                    { label: 'Outstanding', value: _fmt(data.outstanding), color: data.outstanding > 0 ? 'var(--warning)' : 'var(--success)' },
                ];
                // Only present once expenses exist for the period — keeps the stat row
                // exactly as before for anyone who's never logged an expense.
                if (data.total_expenses !== undefined) {
                    cards.push({ label: 'Total Expenses', value: _fmt(data.total_expenses), color: 'var(--danger)' });
                    cards.push({ label: 'Net Income', value: _fmt(data.net_income), color: data.net_income >= 0 ? 'var(--success)' : 'var(--danger)' });
                }
                document.getElementById('csvPreviewStats').innerHTML = cards.map(c =>
                    `<div style="background:rgba(0,0,0,0.25); border:1px solid var(--border); border-radius:8px; padding:0.85rem 1rem;">
                        <div style="font-size:0.75rem; color:var(--text-secondary); margin-bottom:0.3rem; text-transform:uppercase; letter-spacing:0.04em;">${c.label}</div>
                        <div style="font-size:1.25rem; font-weight:700; color:${c.color};">${c.value}</div>
                     </div>`
                ).join('');
            }

            function _statusBadgeStyle(status) {
                if (!status) return '';
                const s = status.toLowerCase();
                if (s === 'paid') return 'background:rgba(16,185,129,0.2); color:#10b981; border-radius:4px; padding:1px 7px; font-size:0.8rem; font-weight:600; white-space:nowrap;';
                if (s === 'partial paid') return 'background:rgba(245,158,11,0.2); color:#f59e0b; border-radius:4px; padding:1px 7px; font-size:0.8rem; font-weight:600; white-space:nowrap;';
                if (s === 'unpaid' || s === 'sent' || s === 'pending') return 'background:rgba(239,68,68,0.2); color:#ef4444; border-radius:4px; padding:1px 7px; font-size:0.8rem; font-weight:600; white-space:nowrap;';
                return 'background:rgba(148,163,184,0.15); color:var(--text-secondary); border-radius:4px; padding:1px 7px; font-size:0.8rem;';
            }

            async function openTaxYearPreview() {
                // Show modal in loading state
                const modal = document.getElementById('csvPreviewModal');
                document.getElementById('csvPreviewTitle').textContent = 'Tax Year Invoice Export';
                document.getElementById('csvPreviewSubtitle').textContent = 'Loading…';
                document.getElementById('csvPreviewLoading').style.display = 'block';
                document.getElementById('csvPreviewTableWrap').style.display = 'none';
                document.getElementById('csvPreviewStats').innerHTML = '';
                document.getElementById('csvPreviewRowCount').textContent = '';
                _csvCurrentData = null;
                const copyBtn = document.getElementById('csvPreviewCopyBtn');
                copyBtn.disabled = true;
                const dlBtn = document.getElementById('csvPreviewDownloadBtn');
                dlBtn.href = '?export=tax_year';
                dlBtn.style.background = 'var(--accent)';
                modal.classList.add('active');

                try {
                    const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'preview_tax_year' }) });
                    const data = await res.json();
                    if (!data.success) { showToast(data.error || 'Failed to load preview', true); closeModal('csvPreviewModal'); return; }

                    document.getElementById('csvPreviewSubtitle').textContent = `Tax Year: ${data.label} (ordered by invoice date)`;
                    _renderCsvStats(data);

                    const cols = ['Invoice #', 'Client', 'Invoice Date', 'Due Date', 'Amount', 'Status', 'Paid Amount', 'Paid Date'];
                    const keys = ['invoice_number', 'client_name', 'invoice_date', 'due_date', 'amount', 'status', 'paid_amount', 'paid_at'];

                    // Store flat CSV rows for clipboard
                    _csvCurrentData = {
                        cols,
                        rows: data.rows.map(r => keys.map(k => r[k] ?? ''))
                    };

                    const thStyle = 'padding:0.55rem 0.75rem; text-align:left; border-bottom:2px solid var(--border); font-size:0.78rem; text-transform:uppercase; letter-spacing:0.04em; color:var(--text-secondary); background:var(--surface);';
                    const tdStyle = 'padding:0.5rem 0.75rem; border-bottom:1px solid rgba(255,255,255,0.05); vertical-align:middle;';

                    document.getElementById('csvPreviewHead').innerHTML = `<tr>${cols.map(c => `<th style="${thStyle}">${c}</th>`).join('')}</tr>`;
                    document.getElementById('csvPreviewBody').innerHTML = data.rows.map((r, i) => {
                        const bg = i % 2 === 0 ? '' : 'background:rgba(255,255,255,0.025);';
                        return `<tr style="${bg}">${keys.map(k => {
                            let val = r[k] ?? '';
                            if (k === 'amount' || k === 'paid_amount') val = val ? '$' + parseFloat(val).toFixed(2) : '';
                            if (k === 'status') return `<td style="${tdStyle}"><span style="${_statusBadgeStyle(val)}">${val}</span></td>`;
                            if (k === 'invoice_number') return `<td style="${tdStyle}; font-family:monospace; font-size:0.83rem;">${val}</td>`;
                            return `<td style="${tdStyle}">${val}</td>`;
                        }).join('')}</tr>`;
                    }).join('');

                    document.getElementById('csvPreviewRowCount').textContent = `${data.rows.length} invoice${data.rows.length !== 1 ? 's' : ''}`;
                    document.getElementById('csvPreviewLoading').style.display = 'none';
                    document.getElementById('csvPreviewTableWrap').style.display = 'block';
                    copyBtn.disabled = false;
                } catch (e) {
                    showToast('Failed to load preview: ' + e.message, true);
                    closeModal('csvPreviewModal');
                }
            }

            async function openMonthlySummaryPreview() {
                const modal = document.getElementById('csvPreviewModal');
                document.getElementById('csvPreviewTitle').textContent = 'Monthly Summary Export';
                document.getElementById('csvPreviewSubtitle').textContent = 'Loading…';
                document.getElementById('csvPreviewLoading').style.display = 'block';
                document.getElementById('csvPreviewTableWrap').style.display = 'none';
                document.getElementById('csvPreviewStats').innerHTML = '';
                document.getElementById('csvPreviewRowCount').textContent = '';
                _csvCurrentData = null;
                const copyBtn = document.getElementById('csvPreviewCopyBtn');
                copyBtn.disabled = true;
                const dlBtn = document.getElementById('csvPreviewDownloadBtn');
                dlBtn.href = '?export=tax_year_monthly';
                dlBtn.style.background = 'var(--accent)';
                modal.classList.add('active');

                try {
                    const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'preview_tax_year_monthly' }) });
                    const data = await res.json();
                    if (!data.success) { showToast(data.error || 'Failed to load preview', true); closeModal('csvPreviewModal'); return; }

                    document.getElementById('csvPreviewSubtitle').textContent = `Tax Year: ${data.label} — monthly totals`;
                    _renderCsvStats(data);

                    const cols = ['Month', 'Total Invoiced', 'Total Paid', 'Outstanding', 'Payment Status', 'Expenses', 'Net Income'];

                    // Store flat CSV rows for clipboard
                    _csvCurrentData = {
                        cols,
                        rows: data.rows.map(r => [
                            r.month_label,
                            parseFloat(r.total_invoiced).toFixed(2),
                            parseFloat(r.total_paid).toFixed(2),
                            parseFloat(r.outstanding).toFixed(2),
                            r.pay_status,
                            parseFloat(r.month_expenses).toFixed(2),
                            parseFloat(r.month_net_income).toFixed(2)
                        ])
                    };

                    const thStyle = 'padding:0.55rem 0.75rem; text-align:left; border-bottom:2px solid var(--border); font-size:0.78rem; text-transform:uppercase; letter-spacing:0.04em; color:var(--text-secondary); background:var(--surface);';
                    const tdStyle = 'padding:0.5rem 0.75rem; border-bottom:1px solid rgba(255,255,255,0.05); vertical-align:middle;';

                    document.getElementById('csvPreviewHead').innerHTML = `<tr>${cols.map(c => `<th style="${thStyle}">${c}</th>`).join('')}</tr>`;
                    document.getElementById('csvPreviewBody').innerHTML = data.rows.map((r, i) => {
                        const bg = i % 2 === 0 ? '' : 'background:rgba(255,255,255,0.025);';
                        return `<tr style="${bg}">
                            <td style="${tdStyle}; font-weight:600;">${r.month_label}</td>
                            <td style="${tdStyle}">${_fmt(r.total_invoiced)}</td>
                            <td style="${tdStyle}; color:var(--success);">${_fmt(r.total_paid)}</td>
                            <td style="${tdStyle}; color:${parseFloat(r.outstanding) > 0 ? 'var(--warning)' : 'var(--success)'}">${_fmt(r.outstanding)}</td>
                            <td style="${tdStyle}"><span style="${_statusBadgeStyle(r.pay_status)}">${r.pay_status}</span></td>
                            <td style="${tdStyle}; color:var(--danger);">${_fmt(r.month_expenses)}</td>
                            <td style="${tdStyle}; color:${parseFloat(r.month_net_income) >= 0 ? 'var(--success)' : 'var(--danger)'}">${_fmt(r.month_net_income)}</td>
                        </tr>`;
                    }).join('');

                    document.getElementById('csvPreviewRowCount').textContent = `${data.rows.length} month${data.rows.length !== 1 ? 's' : ''}`;
                    document.getElementById('csvPreviewLoading').style.display = 'none';
                    document.getElementById('csvPreviewTableWrap').style.display = 'block';
                    copyBtn.disabled = false;
                } catch (e) {
                    showToast('Failed to load preview: ' + e.message, true);
                    closeModal('csvPreviewModal');
                }
            }
            // ── Global fixed tooltip (avoids stacking-context clipping from transform animations) ──
            (function () {
                const tip = document.createElement('div');
                tip.id = 'globalTip';
                Object.assign(tip.style, {
                    position: 'fixed',
                    background: '#1e293b',
                    color: '#f1f5f9',
                    fontSize: '0.75rem',
                    fontWeight: '400',
                    whiteSpace: 'nowrap',
                    padding: '0.35rem 0.65rem',
                    borderRadius: '6px',
                    border: '1px solid rgba(255,255,255,0.1)',
                    pointerEvents: 'none',
                    opacity: '0',
                    transition: 'opacity 0.15s ease',
                    zIndex: '2147483647',
                    boxShadow: '0 4px 12px rgba(0,0,0,0.4)',
                });
                document.body.appendChild(tip);

                function positionTip(el) {
                    const r = el.getBoundingClientRect();
                    tip.textContent = el.getAttribute('data-tip');
                    tip.style.opacity = '0';
                    tip.style.display = 'block';
                    const tw = tip.offsetWidth;
                    const th = tip.offsetHeight;
                    let left = r.left + r.width / 2 - tw / 2;
                    let top = r.top - th - 6;
                    // Clamp to viewport
                    if (left < 6) left = 6;
                    if (left + tw > window.innerWidth - 6) left = window.innerWidth - tw - 6;
                    if (top < 6) top = r.bottom + 6; // flip below if not enough space above
                    tip.style.left = left + 'px';
                    tip.style.top = top + 'px';
                    tip.style.opacity = '1';
                }

                document.addEventListener('mouseover', function (e) {
                    const el = e.target.closest('.has-tooltip');
                    if (el) positionTip(el);
                });
                document.addEventListener('mouseout', function (e) {
                    const el = e.target.closest('.has-tooltip');
                    if (el) tip.style.opacity = '0';
                });
            })();
        </script>
</body>

</html>
