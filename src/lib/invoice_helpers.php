<?php
// Pure invoice/email helper functions — no $mysqli, no session, no globals.
// Functions that touch the database or PHPMailer state (processInvoice,
// generateInvoiceNumber, notifyChannel, sendOverdueReminders, etc.) live in
// invoxa.php instead.

// Prefers the configured Settings > Payments "Public URL" over the current
// request's Host header, since Pay Now links and Stripe/PayPal redirect URLs
// are often built by the cron container, whose internal hostname ("nginx")
// isn't reachable by a browser. Returns null when neither is available, so
// callers can omit the Pay Now button instead of emitting a dead link.
function invoxaPublicBaseUrl(array $settings): ?string
{
    $configured = trim($settings['public_url'] ?? '');
    if ($configured !== '') {
        return rtrim($configured, '/');
    }
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($host !== '' && $host !== 'nginx') {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        return $scheme . '://' . $host;
    }
    return null;
}

// Standalone message page shared by the Pay Now / payment return routes for
// non-happy-path cases (invoice not found, already paid, gateway error,
// cancelled). $message may contain pre-escaped HTML, not raw user input.
function invoxaSimplePage(string $title, string $heading, string $message): string
{
    return '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . htmlspecialchars($title) . '</title><meta name="robots" content="noindex, nofollow"><style>*{box-sizing:border-box;}body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Inter,Roboto,sans-serif;background:#0a0f1c;color:#f7f9fc;margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:2rem 1.25rem;}.box{max-width:440px;text-align:center;}h1{font-size:1.3rem;margin:0 0 0.75rem;}p{color:#90a0bb;font-size:0.92rem;line-height:1.5;}a{color:#4f7cff;}</style></head><body><div class="box"><h1>' . htmlspecialchars($heading) . '</h1><p>' . $message . '</p></div></body></html>';
}

// Inverse of stripeAmountToMinorUnits() — converts a Checkout Session/webhook's
// amount_total (always an integer in the currency's smallest unit) back into
// a normal decimal amount for recordInvoicePayment().
function stripeAmountFromMinorUnits(int $minorUnits, string $currencyCode): float
{
    $zeroDecimal = ['BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];
    if (in_array(strtoupper($currencyCode), $zeroDecimal, true)) {
        return (float) $minorUnits;
    }
    return round($minorUnits / 100, 2);
}

function invoiceWatermarkFingerprint(array $settings): string
{
    $key = trim($settings['license_key'] ?? '');
    if ($key === '') {
        return '';
    }
    return substr(hash('sha256', $key), 0, 10);
}

// Computes subtotal/discount/tax/total from line items and a single
// invoice-level discount % and tax % (not per line item). Discount is taken
// off the subtotal first, then tax applied to what's left. Mutates
// $lineItems in place only to normalize 'amount' to a formatted string.
function computeInvoiceTotals(array &$lineItems, float $discountPct, float $taxRate): array
{
    $discountPct = max(0, min(100, $discountPct));
    $taxRate = max(0, min(100, $taxRate));
    $subtotal = 0.0;
    foreach ($lineItems as &$li) {
        $raw = (float) ($li['amount'] ?? 0);
        $li['amount'] = number_format($raw, 2);
        $subtotal += $raw;
    }
    unset($li);
    $discount = $subtotal * $discountPct / 100;
    $net = $subtotal - $discount;
    $tax = $net * $taxRate / 100;
    return [
        'subtotal' => round($subtotal, 2),
        'discount_pct' => $discountPct,
        'discount' => round($discount, 2),
        'tax_rate' => $taxRate,
        'tax' => round($tax, 2),
        'total' => round($net + $tax, 2),
    ];
}

// Formats a percentage for display without a trailing ".00" (7.5% not 7.50%,
// but 10% not 10.).
function formatPct(float $pct): string
{
    return rtrim(rtrim(number_format($pct, 2), '0'), '.') . '%';
}

// Fixed whitelist of expense categories, used to validate save_expense/import
// input and to label the Category select and accounting export mapping (see
// buildAccountingJournal()).
function expenseCategories(): array
{
    return [
        'software' => 'Software & Subscriptions',
        'hosting' => 'Hosting & Infrastructure',
        'office' => 'Office Supplies',
        'travel' => 'Travel',
        'meals' => 'Meals & Entertainment',
        'professional' => 'Professional Services',
        'marketing' => 'Marketing & Advertising',
        'equipment' => 'Equipment',
        'taxes' => 'Taxes & Fees',
        'other' => 'Other',
    ];
}

// $template selects the layout CSS only; every other element (line items,
// summary rows, footer, watermark) is shared between templates. 'compact' is
// a terser layout for invoices with many line items; 'detailed' is the
// default.
function generateInvoiceHTML($recipient, $date, $dueDate, $invoiceNumber, $amount, $accountName, $accountNumber, $senderEmail, $lineItems = [], $brandColor = '#4a90e2', $footerText = '', $currencyCode = 'USD', $licenseFingerprint = '', $discountPct = 0.0, $taxRate = 0.0, $template = 'detailed', ?string $payUrl = null, bool $showPoweredBy = true)
{
    $linesHtml = "";
    foreach ($lineItems as $item) {
        $linesHtml .= "<tr><td>" . htmlspecialchars($item['code']) . "</td><td>" . htmlspecialchars($item['desc']) . "</td><td>" . htmlspecialchars($currencyCode) . " \${$item['amount']}</td></tr>";
    }

    // Subtotal/Discount/Tax rows only appear when actually used; a plain
    // invoice just shows line items and a Total row.
    $summaryRowsHtml = "";
    if ($discountPct > 0 || $taxRate > 0) {
        $subtotal = array_sum(array_map('floatval', array_column($lineItems, 'amount')));
        $discountAmt = $subtotal * $discountPct / 100;
        $taxAmt = ($subtotal - $discountAmt) * $taxRate / 100;
        $summaryRowsHtml .= "<tr class=\"summary-row\"><td colspan=\"2\">Subtotal</td><td>{$currencyCode} \$" . number_format($subtotal, 2) . "</td></tr>";
        if ($discountPct > 0) {
            $summaryRowsHtml .= "<tr class=\"summary-row\"><td colspan=\"2\">Discount (" . htmlspecialchars(formatPct($discountPct)) . ")</td><td>-{$currencyCode} \$" . number_format($discountAmt, 2) . "</td></tr>";
        }
        if ($taxRate > 0) {
            $summaryRowsHtml .= "<tr class=\"summary-row\"><td colspan=\"2\">Tax (" . htmlspecialchars(formatPct($taxRate)) . ")</td><td>{$currencyCode} \$" . number_format($taxAmt, 2) . "</td></tr>";
        }
    }

    $footerHtml = "";
    if ($footerText) {
        $footerHtml = "<p>" . nl2br(htmlspecialchars($footerText)) . "</p>";
    } else {
        $footerHtml = "<ul><li><strong>Account Name:</strong> " . htmlspecialchars($accountName) . "</li><li><strong>Account Number:</strong> " . htmlspecialchars($accountNumber) . "</li></ul>";
    }

    $recipient = htmlspecialchars($recipient);
    $date = htmlspecialchars($date);
    $dueDate = htmlspecialchars($dueDate);
    $invoiceNumber = htmlspecialchars($invoiceNumber);
    $senderEmail = htmlspecialchars($senderEmail);
    $currencyCode = htmlspecialchars($currencyCode);

    // Invisible watermark: an HTML comment plus a near-zero-contrast span
    // (matches the page background rather than being transparent, so it
    // still rasterizes into exported PDFs).
    $watermarkComment = $licenseFingerprint !== '' ? "<!-- lic:{$licenseFingerprint} -->" : '';
    $watermarkSpan = $licenseFingerprint !== '' ? "<span style=\"font-size:1px;color:#f9f9f8;user-select:none;\">{$licenseFingerprint}</span>" : '';

    // $payUrl is null when no gateway is enabled, or no Public URL is
    // configured to build one from (see invoxaPublicBaseUrl()) — omitted
    // rather than rendering a broken link.
    $payButtonHtml = $payUrl !== null
        ? "<p style=\"margin-top:16px;\"><a href=\"" . htmlspecialchars($payUrl) . "\" style=\"display:inline-block;background:{$brandColor};color:#fff;text-decoration:none;padding:10px 20px;border-radius:6px;font-weight:600;\">Pay Now</a></p>"
        : '';

    // Hidden only for a licensed install with the Settings > Branding toggle
    // on; shown otherwise.
    $poweredByHtml = $showPoweredBy
        ? "<p style=\"margin-top:24px;font-size:11px;color:#999;\">Powered by Invoxa — free, open source invoicing.</p>"
        : '';

    $style = $template === 'compact'
        ? "body {font-family: \"Segoe UI\", Tahoma, Geneva, Verdana, sans-serif; margin: 20px 40px; font-size: 13px; color: #333;} .header {display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; border-bottom: 1px solid {$brandColor}; padding-bottom: 6px;} .header h2 {margin: 0; font-size: 22px; font-weight: 700; color: #2c3e50; width: 80%; text-align: left;} .header img {height: 50px; width: 20%; object-fit: contain; margin-left: 10px;} .invoice-meta p, .footer p {margin: 2px 0; font-size: 13px; color: #555;} h3 {color: {$brandColor}; margin-top: 20px; font-weight: 600; font-size: 14px;} table {width: 100%; border-collapse: collapse; margin-top: 8px;} th, td {border: 1px solid #ddd; padding: 5px 8px; text-align: left; font-size: 13px;} th {background: {$brandColor}; color: #fff; font-weight: 600; text-transform: uppercase; font-size: 11px;} .summary-row td {background: #fff;} .total-row td {font-weight: 700; font-size: 14px; border-top: 2px solid {$brandColor};} .footer {margin-top: 20px; font-size: 12px; color: #555;} .footer h3 {margin-bottom: 4px; color: #2c3e50; font-size: 13px;} .footer ul {list-style: disc; margin-left: 16px; color: #555;}"
        : "body {font-family: \"Segoe UI\", Tahoma, Geneva, Verdana, sans-serif; margin: 40px 80px; font-size: 16px; color: #333; background: #f9f9f9;} .header {display: flex; align-items: center; justify-content: space-between; margin-bottom: 40px; border-bottom: 2px solid {$brandColor}; padding-bottom: 10px;} .header h2 {margin: 0; padding-top: 35px; font-size: 36px; font-weight: 700; color: #2c3e50; width: 80%; text-align: left;} .header img {height: 100px; width: 20%; object-fit: contain; margin-left: 20px;} .invoice-meta p, .footer p {margin: 5px 0; font-size: 16px; color: #555;} h3 {color: {$brandColor}; margin-top: 40px; font-weight: 600; letter-spacing: 0.03em;} table {width: 100%; border-collapse: collapse; margin-top: 15px; background: #fff; box-shadow: 0 0 10px #ddd;} th, td {border: 1px solid #ddd; padding: 12px 15px; text-align: left;} th {background: {$brandColor}; color: #fff; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em;} tr:nth-child(even) {background: #f4f9ff;} tr:hover {background: #dceffb;} .summary-row td {background: #fff;} .total-row td {font-weight: 700; font-size: 18px; border-top: 2px solid {$brandColor};} .footer {margin-top: 40px; font-size: 14px; color: #555;} .footer h3 {margin-bottom: 8px; color: #2c3e50;} .footer ul {list-style: disc; margin-left: 20px; color: #555;}";

    return <<<HTML
{$watermarkComment}<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8" /><meta name="viewport" content="width=device-width, initial-scale=1" /><title>Invoice</title>
<style>{$style}</style></head>
<body><div class="header"><h2>Invoice</h2><img src="cid:logo_cid" alt="Logo" /></div><div class="invoice-meta"><p><strong>Invoice To:</strong> {$recipient}</p><p><strong>Invoice Date:</strong> {$date}</p><p><strong>Invoice Due:</strong> {$dueDate}</p><p><strong>Invoice Number:</strong> {$invoiceNumber}</p><p><strong>Amount Due:</strong> {$currencyCode} \${$amount}</p>{$payButtonHtml}</div><h3>Invoice Details</h3><table><thead><tr><th>Code</th><th>Description</th><th>Amount</th></tr></thead><tbody>{$linesHtml}{$summaryRowsHtml}<tr class="total-row"><td colspan="2">Total</td><td>{$currencyCode} \${$amount}</td></tr></tbody></table><div class="footer"><h3>Payment Instructions</h3>{$footerHtml}<h3>For Any Inquiries</h3><p>Email: {$senderEmail}</p>{$poweredByHtml}</div>{$watermarkSpan}</body></html>
HTML;
}

// Renders invoice/quote HTML (from generateInvoiceHTML()) to a PDF byte
// string via dompdf (src/lib/dompdf), used for both the "Download PDF"
// button and email attachments. Embeds the logo as a base64 data: URI rather
// than a filesystem path, avoiding dompdf's local-file resolution (URL
// parsing, protocol allowlist, chroot validation).
function generateInvoicePdf(string $htmlContent): string
{
    require_once PDF_AUTOLOAD;

    // A freshly-generated invoice carries the cid: placeholder (for email
    // inlining); one reconstructed from the on-disk static file (Sync >
    // Import Missing) already has it swapped for the public INVOICES_URL
    // path. Handle both forms.
    $logoSrcCandidates = ['src="cid:logo_cid"', 'src="' . INVOICES_URL . LOGO_FILENAME . '"'];

    $logoPath = INVOICES_DIR . LOGO_FILENAME;
    $logoInfo = is_readable($logoPath) ? @getimagesize($logoPath) : false;
    if ($logoInfo !== false) {
        $logoData = 'data:' . $logoInfo['mime'] . ';base64,' . base64_encode(file_get_contents($logoPath));
        $html = str_replace($logoSrcCandidates, 'src="' . $logoData . '"', $htmlContent);
    } else {
        // No logo uploaded, or it's unreadable/corrupt — drop the <img> tag
        // rather than leave a dead reference that would render as literal alt text.
        $html = $htmlContent;
        foreach ($logoSrcCandidates as $src) {
            $html = preg_replace('/<img ' . preg_quote($src, '/') . '[^>]*>/', '', $html);
        }
    }

    $options = new Dompdf\Options();
    $options->setIsRemoteEnabled(false);
    $options->setIsPhpEnabled(false);

    $dompdf = new Dompdf\Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('a4', 'portrait');
    $dompdf->render();
    return $dompdf->output();
}

// Accepts a Y-m-d string from a form's optional due-date override; returns it
// only if it's actually a valid calendar date in that exact format, otherwise
// null so callers fall back to the client's default payment-terms calculation.
function validDateOverride(?string $str): ?string
{
    if (empty($str))
        return null;
    $d = DateTime::createFromFormat('Y-m-d', $str);
    return ($d && $d->format('Y-m-d') === $str) ? $str : null;
}

// Substitutes plain {token} placeholders in editable email templates (Settings >
// Email Templates) — deliberately not full templating (no conditionals/loops),
// just a straight key/value swap so client-facing text stays simple to edit.
function renderEmailTemplate(string $template, array $vars): string
{
    $replacements = [];
    foreach ($vars as $key => $value) {
        $replacements['{' . $key . '}'] = (string) $value;
    }
    return strtr($template, $replacements);
}

// Posts a message to a Telegram chat via the Bot API using a stream-context
// POST (avoids a cURL dependency). Returns success/error rather than
// throwing, so a bad/missing bot token never blocks the triggering action.
function sendTelegramNotification(string $botToken, string $chatId, string $message): array
{
    if ($botToken === '' || $chatId === '') {
        return ['success' => false, 'error' => 'Telegram bot token/chat ID not configured'];
    }
    $url = "https://api.telegram.org/bot{$botToken}/sendMessage";
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => json_encode(['chat_id' => $chatId, 'text' => $message]),
            'timeout' => 10,
            'ignore_errors' => true,
        ],
    ]);
    $result = @file_get_contents($url, false, $context);
    if ($result === false) {
        return ['success' => false, 'error' => 'Could not reach the Telegram API'];
    }
    $decoded = json_decode($result, true);
    if (!is_array($decoded) || empty($decoded['ok'])) {
        return ['success' => false, 'error' => $decoded['description'] ?? 'Telegram API rejected the message'];
    }
    return ['success' => true, 'error' => ''];
}

// Posts a message to a Slack channel via an Incoming Webhook
// (https://api.slack.com/messaging/webhooks). Same stream-context POST
// approach and error handling as sendTelegramNotification().
function sendSlackNotification(string $webhookUrl, string $message): array
{
    if ($webhookUrl === '') {
        return ['success' => false, 'error' => 'Slack webhook URL not configured'];
    }
    if (!preg_match('#^https://hooks\.slack\.com/services/#', $webhookUrl)) {
        return ['success' => false, 'error' => 'That doesn\'t look like a Slack webhook URL (should start with https://hooks.slack.com/services/)'];
    }
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => json_encode(['text' => $message]),
            'timeout' => 10,
            'ignore_errors' => true,
        ],
    ]);
    $result = @file_get_contents($webhookUrl, false, $context);
    if ($result === false) {
        return ['success' => false, 'error' => 'Could not reach Slack'];
    }
    if (trim($result) !== 'ok') {
        return ['success' => false, 'error' => trim($result) ?: 'Slack rejected the message'];
    }
    return ['success' => true, 'error' => ''];
}

// ── Payment gateways (Stripe / PayPal) ───────────────────────────────────────
// Raw REST calls via stream contexts rather than the official SDKs (which
// need Composer) or cURL (an extension this app otherwise avoids).

// Generic HTTPS JSON/form request. Returns the decoded body regardless of
// status code (ignore_errors) so callers can read e.g. Stripe/PayPal's own
// {"error": {...}} payload on a 4xx instead of just seeing a bare failure.
function httpApiRequest(string $url, string $method, array $headers, ?string $body): array
{
    $headerStr = '';
    foreach ($headers as $k => $v) {
        $headerStr .= "{$k}: {$v}\r\n";
    }
    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => $headerStr,
            'content' => $body ?? '',
            'timeout' => 20,
            'ignore_errors' => true,
        ],
    ]);
    $result = @file_get_contents($url, false, $context);
    $status = 0;
    if (isset($http_response_header[0]) && preg_match('#^HTTP/\S+\s+(\d+)#', $http_response_header[0], $m)) {
        $status = (int) $m[1];
    }
    if ($result === false) {
        return ['success' => false, 'status' => 0, 'body' => null, 'raw' => ''];
    }
    return ['success' => $status >= 200 && $status < 300, 'status' => $status, 'body' => json_decode($result, true), 'raw' => $result];
}

// Stripe wants amounts in the currency's smallest unit (cents for USD) except
// for a documented list of zero-decimal currencies, where the amount is used
// as-is. https://docs.stripe.com/currencies#zero-decimal
function stripeAmountToMinorUnits(float $amount, string $currencyCode): int
{
    $zeroDecimal = ['BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];
    if (in_array(strtoupper($currencyCode), $zeroDecimal, true)) {
        return (int) round($amount);
    }
    return (int) round($amount * 100);
}

// Creates a Stripe Checkout Session and returns its hosted payment page URL.
// https://docs.stripe.com/api/checkout/sessions/create — the bracketed keys
// below are Stripe's documented form-encoding for nested/array params.
function stripeCreateCheckoutSession(string $secretKey, string $invoiceNumber, float $amount, string $currencyCode, string $description, string $successUrl, string $cancelUrl): array
{
    $params = [
        'mode' => 'payment',
        'success_url' => $successUrl,
        'cancel_url' => $cancelUrl,
        'client_reference_id' => $invoiceNumber,
        'line_items[0][quantity]' => '1',
        'line_items[0][price_data][currency]' => strtolower($currencyCode),
        'line_items[0][price_data][unit_amount]' => (string) stripeAmountToMinorUnits($amount, $currencyCode),
        'line_items[0][price_data][product_data][name]' => $description,
        'metadata[invoice_number]' => $invoiceNumber,
        // Stripe copies PaymentIntent metadata onto the resulting Charge, which
        // is what a later charge.refunded webhook carries — lets
        // recordInvoiceRefund() find the right invoice on refund.
        'payment_intent_data[metadata][invoice_number]' => $invoiceNumber,
    ];
    $res = httpApiRequest('https://api.stripe.com/v1/checkout/sessions', 'POST', [
        'Authorization' => 'Bearer ' . $secretKey,
        'Content-Type' => 'application/x-www-form-urlencoded',
    ], http_build_query($params));
    if (!$res['success']) {
        return ['success' => false, 'error' => $res['body']['error']['message'] ?? 'Stripe API error (could not reach Stripe or bad response)'];
    }
    return ['success' => true, 'session_id' => $res['body']['id'], 'url' => $res['body']['url']];
}

// Used on the success return-URL for an immediate "Paid!" confirmation; the
// webhook remains the authoritative path for crediting the invoice (see
// recordInvoicePayment()'s idempotency key, which lets both race safely).
function stripeRetrieveCheckoutSession(string $secretKey, string $sessionId): array
{
    $res = httpApiRequest('https://api.stripe.com/v1/checkout/sessions/' . rawurlencode($sessionId), 'GET', [
        'Authorization' => 'Bearer ' . $secretKey,
    ], null);
    if (!$res['success']) {
        return ['success' => false, 'error' => $res['body']['error']['message'] ?? 'Stripe API error'];
    }
    return ['success' => true, 'session' => $res['body']];
}

// Verifies a Stripe webhook's Stripe-Signature header per Stripe's documented
// algorithm (HMAC-SHA256 over "{timestamp}.{raw body}", constant-time compare,
// reject stale timestamps to block replay). https://docs.stripe.com/webhooks/signatures
// — implemented locally rather than via stripe-php since that SDK needs
// Composer, which this app doesn't use anywhere else.
function stripeVerifyWebhookSignature(string $payload, string $sigHeader, string $webhookSecret, int $toleranceSeconds = 300): bool
{
    $parts = [];
    foreach (explode(',', $sigHeader) as $pair) {
        $kv = explode('=', $pair, 2);
        if (count($kv) === 2) {
            $parts[$kv[0]][] = $kv[1];
        }
    }
    $timestamp = isset($parts['t'][0]) ? (int) $parts['t'][0] : 0;
    $signatures = $parts['v1'] ?? [];
    if ($timestamp === 0 || empty($signatures) || abs(time() - $timestamp) > $toleranceSeconds) {
        return false;
    }
    $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $webhookSecret);
    foreach ($signatures as $sig) {
        if (hash_equals($expected, $sig)) {
            return true;
        }
    }
    return false;
}

function paypalApiBase(string $environment): string
{
    return $environment === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
}

// OAuth2 client_credentials grant — not cached; tokens are short-lived
// (~9hrs) and these calls are infrequent enough to just re-fetch each time.
// https://developer.paypal.com/api/rest/authentication/
function paypalGetAccessToken(string $clientId, string $clientSecret, string $environment): array
{
    $res = httpApiRequest(paypalApiBase($environment) . '/v1/oauth2/token', 'POST', [
        'Authorization' => 'Basic ' . base64_encode($clientId . ':' . $clientSecret),
        'Content-Type' => 'application/x-www-form-urlencoded',
    ], 'grant_type=client_credentials');
    if (!$res['success']) {
        return ['success' => false, 'error' => $res['body']['error_description'] ?? 'PayPal authentication failed — check the Client ID/Secret'];
    }
    return ['success' => true, 'access_token' => $res['body']['access_token']];
}

// Creates an Order (intent=CAPTURE) and returns the "approve" link to redirect
// the payer to. https://developer.paypal.com/docs/api/orders/v2/#orders_create
function paypalCreateOrder(string $accessToken, string $environment, string $invoiceNumber, float $amount, string $currencyCode, string $description, string $returnUrl, string $cancelUrl): array
{
    $body = json_encode([
        'intent' => 'CAPTURE',
        'purchase_units' => [[
            'reference_id' => $invoiceNumber,
            'custom_id' => $invoiceNumber,
            'description' => substr($description, 0, 127),
            'amount' => ['currency_code' => strtoupper($currencyCode), 'value' => number_format($amount, 2, '.', '')],
        ]],
        'application_context' => [
            'return_url' => $returnUrl,
            'cancel_url' => $cancelUrl,
            'user_action' => 'PAY_NOW',
        ],
    ]);
    $res = httpApiRequest(paypalApiBase($environment) . '/v2/checkout/orders', 'POST', [
        'Authorization' => 'Bearer ' . $accessToken,
        'Content-Type' => 'application/json',
    ], $body);
    if (!$res['success']) {
        return ['success' => false, 'error' => $res['body']['message'] ?? 'PayPal order creation failed'];
    }
    $approveUrl = null;
    foreach ($res['body']['links'] ?? [] as $link) {
        if (($link['rel'] ?? '') === 'approve') {
            $approveUrl = $link['href'];
            break;
        }
    }
    if (!$approveUrl) {
        return ['success' => false, 'error' => 'PayPal did not return an approval link'];
    }
    return ['success' => true, 'order_id' => $res['body']['id'], 'approve_url' => $approveUrl];
}

// Captures a previously-approved Order — PayPal does not auto-capture on
// approval. PayPal-Request-Id is PayPal's idempotency header, so a repeated
// return-URL hit (e.g. a page refresh) returns the original capture instead
// of charging twice.
// https://developer.paypal.com/docs/api/orders/v2/#orders_capture
function paypalCaptureOrder(string $accessToken, string $environment, string $orderId): array
{
    $res = httpApiRequest(paypalApiBase($environment) . '/v2/checkout/orders/' . rawurlencode($orderId) . '/capture', 'POST', [
        'Authorization' => 'Bearer ' . $accessToken,
        'Content-Type' => 'application/json',
        'PayPal-Request-Id' => $orderId,
    ], '{}');
    $capture = $res['body']['purchase_units'][0]['payments']['captures'][0] ?? null;
    if (!$res['success'] || !$capture || ($capture['status'] ?? '') !== 'COMPLETED') {
        return ['success' => false, 'error' => $res['body']['message'] ?? 'PayPal capture did not complete'];
    }
    return [
        'success' => true,
        'capture_id' => $capture['id'],
        'amount' => (float) ($capture['amount']['value'] ?? 0),
        'currency' => $capture['amount']['currency_code'] ?? '',
        'custom_id' => $capture['custom_id'] ?? ($res['body']['purchase_units'][0]['custom_id'] ?? null),
    ];
}

// Unlike Stripe, PayPal doesn't support local HMAC verification of webhooks —
// you send the received headers + body + your webhook id back to PayPal and it
// tells you whether the signature is genuine.
// https://developer.paypal.com/api/rest/webhooks/rest/#link-verifywebhooksignature
function paypalVerifyWebhookSignature(string $accessToken, string $environment, array $headers, string $rawBody, string $webhookId): bool
{
    $body = json_encode([
        'auth_algo' => $headers['paypal-auth-algo'] ?? '',
        'cert_url' => $headers['paypal-cert-url'] ?? '',
        'transmission_id' => $headers['paypal-transmission-id'] ?? '',
        'transmission_sig' => $headers['paypal-transmission-sig'] ?? '',
        'transmission_time' => $headers['paypal-transmission-time'] ?? '',
        'webhook_id' => $webhookId,
        'webhook_event' => json_decode($rawBody, true),
    ]);
    $res = httpApiRequest(paypalApiBase($environment) . '/v1/notifications/verify-webhook-signature', 'POST', [
        'Authorization' => 'Bearer ' . $accessToken,
        'Content-Type' => 'application/json',
    ], $body);
    return $res['success'] && (($res['body']['verification_status'] ?? '') === 'SUCCESS');
}
