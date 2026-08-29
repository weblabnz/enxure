<?php
// $mysqli-touching payment logic — recording/refunding payments, the public
// Stripe/PayPal payment/return/webhook routes, and the payment-related AJAX
// handlers. Pure Stripe/PayPal API mechanics (no $mysqli) stay in
// invoice_helpers.php; everything here needs the database.

function invoxaPaymentAccessOk($mysqli, array $settings): bool
{
    if (getenv('INVOXA_DEMO_MODE')) {
        return false;
    }
    $host = invoxaNormaliseDomain($_SERVER['HTTP_HOST'] ?? '');
    if ($host === '') {
        return false;
    }
    $license = trim($settings['license_key'] ?? '');
    $dot = strrpos($license, '.');
    if ($dot === false) {
        return false;
    }
    $payload = base64_decode(substr($license, 0, $dot), true);
    $signature = base64_decode(substr($license, $dot + 1), true);
    $publicKey = base64_decode(INVOXA_LICENSE_PUBLIC_KEY_B64, true);
    if ($payload === false || $signature === false || $publicKey === false
        || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
        || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
        || !sodium_crypto_sign_verify_detached($signature, $payload, $publicKey)) {
        return false;
    }
    $fields = explode('|', $payload);
    if (count($fields) !== 3 || $host !== invoxaNormaliseDomain($fields[1])) {
        return false;
    }
    $owner = $mysqli->query("SELECT email FROM invoxa_users ORDER BY id ASC LIMIT 1")->fetch_assoc();
    $ownerEmail = trim((string) ($owner['email'] ?? ''));
    return $ownerEmail !== '' && strcasecmp($ownerEmail, trim($fields[0])) === 0;
}

function invoxaHandlePublicPaymentRoutes($mysqli, array $settings, bool $licenseValid): void
{
    // ── Online Payments (Stripe / PayPal) — public routes ───────────────────────
    // Outside the $isAuth gate, same reasoning as the Client Portal above — a
    // client, or Stripe/PayPal itself, never has an admin session. Webhooks are
    // the only path that actually credits a payment (see recordInvoicePayment()
    // and its uniq_provider_ref idempotency key); the return-URL handlers below
    // also call it for an instant "Paid!" page, safely racing the webhook.
    $__businessName = $settings['business_name'] ?? 'Invoxa';
    
    if (isset($_GET['pay'])) {
        header('Content-Type: text/html; charset=utf-8');
        $invNum = (string) $_GET['pay'];
        $stmt = $mysqli->prepare("SELECT id, amount, currency, paid_amount, status FROM invoxa_invoices WHERE invoice_number = ? AND is_quote = 0");
        $stmt->bind_param("s", $invNum);
        $stmt->execute();
        $payInv = $stmt->get_result()->fetch_assoc();
        if (!$payInv || in_array($payInv['status'], ['void', 'draft'], true)) {
            http_response_code(404);
            echo invoxaSimplePage($__businessName, 'Invoice not found', 'This payment link is invalid. Contact ' . htmlspecialchars($__businessName) . ' if you think this is a mistake.');
            exit;
        }
        $remaining = round((float) $payInv['amount'] - (float) ($payInv['paid_amount'] ?? 0), 2);
        if ($payInv['status'] === 'paid' || $remaining <= 0) {
            echo invoxaSimplePage($__businessName, 'Already paid', 'This invoice is already paid in full. Thank you!');
            exit;
        }
        // Payment collection is a paid feature — re-checked here at the moment of
        // taking payment, not just when save_payment_settings first turned it on,
        // so a deactivated license genuinely stops collecting payments.
        if (!invoxaPaymentAccessOk($mysqli, $settings)) {
            echo invoxaSimplePage($__businessName, 'Online payment unavailable', 'Online payment isn\'t set up for this invoice yet. Please contact ' . htmlspecialchars($__businessName) . ' for payment instructions.');
            exit;
        }
        $stripeOn = ($settings['stripe_enabled'] ?? '0') === '1' && trim($settings['stripe_secret_key'] ?? '') !== '';
        $paypalOn = ($settings['paypal_enabled'] ?? '0') === '1' && trim($settings['paypal_client_id'] ?? '') !== '' && trim($settings['paypal_client_secret'] ?? '') !== '';
        if (!$stripeOn && !$paypalOn) {
            echo invoxaSimplePage($__businessName, 'Online payment unavailable', 'Online payment isn\'t set up for this invoice yet. Please contact ' . htmlspecialchars($__businessName) . ' for payment instructions.');
            exit;
        }
        $publicBase = invoxaPublicBaseUrl($settings);
        if ($publicBase === null) {
            echo invoxaSimplePage($__businessName, 'Payment temporarily unavailable', 'Something isn\'t configured correctly on our end. Please contact ' . htmlspecialchars($__businessName) . ' directly.');
            exit;
        }
        $currencyCode = invoxaResolveCurrency($payInv['currency'] ?? '', $settings);
        $description = 'Invoice ' . $invNum . ' — ' . $__businessName;
        $requested = in_array($_GET['gateway'] ?? '', ['stripe', 'paypal'], true) ? $_GET['gateway'] : null;
        $chosenGateway = null;
        if ($requested === 'stripe' && $stripeOn)
            $chosenGateway = 'stripe';
        elseif ($requested === 'paypal' && $paypalOn)
            $chosenGateway = 'paypal';
        elseif ($requested === null) {
            if ($stripeOn && !$paypalOn)
                $chosenGateway = 'stripe';
            elseif ($paypalOn && !$stripeOn)
                $chosenGateway = 'paypal';
        }
    
        if ($chosenGateway === 'stripe') {
            $result = stripeCreateCheckoutSession(
                $settings['stripe_secret_key'],
                $invNum,
                $remaining,
                $currencyCode,
                $description,
                $publicBase . '/?stripe_return=1&session_id={CHECKOUT_SESSION_ID}',
                $publicBase . '/?stripe_cancel=1&invoice=' . rawurlencode($invNum)
            );
            if (!$result['success']) {
                echo invoxaSimplePage($__businessName, 'Payment unavailable', 'Stripe couldn\'t start this payment right now: ' . htmlspecialchars($result['error']) . '. Please try again later or contact ' . htmlspecialchars($__businessName) . '.');
                exit;
            }
            header('Location: ' . $result['url']);
            exit;
        }
        if ($chosenGateway === 'paypal') {
            $env = $settings['paypal_environment'] ?? 'sandbox';
            $tokenResult = paypalGetAccessToken($settings['paypal_client_id'], $settings['paypal_client_secret'], $env);
            if (!$tokenResult['success']) {
                echo invoxaSimplePage($__businessName, 'Payment unavailable', 'PayPal couldn\'t start this payment right now. Please try again later or contact ' . htmlspecialchars($__businessName) . '.');
                exit;
            }
            $order = paypalCreateOrder(
                $tokenResult['access_token'],
                $env,
                $invNum,
                $remaining,
                $currencyCode,
                $description,
                $publicBase . '/?paypal_return=1&invoice=' . rawurlencode($invNum),
                $publicBase . '/?paypal_cancel=1&invoice=' . rawurlencode($invNum)
            );
            if (!$order['success']) {
                echo invoxaSimplePage($__businessName, 'Payment unavailable', 'PayPal couldn\'t start this payment right now: ' . htmlspecialchars($order['error']) . '. Please try again later or contact ' . htmlspecialchars($__businessName) . '.');
                exit;
            }
            header('Location: ' . $order['approve_url']);
            exit;
        }
    
        // Both gateways on and no explicit choice — let the payer pick.
        $chooserLinks = '';
        if ($stripeOn)
            $chooserLinks .= '<p style="margin-top:1rem;"><a href="?pay=' . rawurlencode($invNum) . '&gateway=stripe" style="display:inline-block;background:#4f7cff;color:#fff;text-decoration:none;padding:0.7rem 1.4rem;border-radius:8px;font-weight:600;">Pay with Card (Stripe)</a></p>';
        if ($paypalOn)
            $chooserLinks .= '<p style="margin-top:1rem;"><a href="?pay=' . rawurlencode($invNum) . '&gateway=paypal" style="display:inline-block;background:#ffc439;color:#111;text-decoration:none;padding:0.7rem 1.4rem;border-radius:8px;font-weight:600;">Pay with PayPal</a></p>';
        echo invoxaSimplePage($__businessName, 'Pay Invoice ' . htmlspecialchars($invNum), htmlspecialchars($currencyCode) . ' ' . number_format($remaining, 2) . ' due.' . $chooserLinks);
        exit;
    }
    
    if (isset($_GET['stripe_return'])) {
        header('Content-Type: text/html; charset=utf-8');
        $sessionId = (string) ($_GET['session_id'] ?? '');
        $stripeKey = trim($settings['stripe_secret_key'] ?? '');
        if ($sessionId === '' || $stripeKey === '') {
            echo invoxaSimplePage($__businessName, 'Something went wrong', 'We couldn\'t confirm this payment. If you were charged, contact ' . htmlspecialchars($__businessName) . ' and we\'ll sort it out.');
            exit;
        }
        $result = stripeRetrieveCheckoutSession($stripeKey, $sessionId);
        $session = $result['success'] ? $result['session'] : null;
        if ($session && ($session['payment_status'] ?? '') === 'paid') {
            $invNum = $session['client_reference_id'] ?? '';
            $invRow = $mysqli->query("SELECT id FROM invoxa_invoices WHERE invoice_number = '" . $mysqli->real_escape_string($invNum) . "'")->fetch_assoc();
            if ($invRow) {
                $amountPaid = stripeAmountFromMinorUnits((int) ($session['amount_total'] ?? 0), $session['currency'] ?? 'usd');
                recordInvoicePayment($mysqli, $settings, (int) $invRow['id'], $amountPaid, 'Paid via Stripe Checkout', 'stripe', $sessionId);
            }
            echo invoxaSimplePage($__businessName, 'Payment received', 'Thank you! Your payment for invoice ' . htmlspecialchars($invNum) . ' has been received.');
            exit;
        }
        // Not confirmed paid yet (e.g. a bank-debit payment method that settles
        // asynchronously) — the webhook will still credit it once Stripe confirms;
        // this is just what the payer sees right now.
        echo invoxaSimplePage($__businessName, 'Payment processing', 'Your payment is being processed. You\'ll receive a receipt once it\'s confirmed — no need to try again.');
        exit;
    }
    
    if (isset($_GET['stripe_cancel'])) {
        header('Content-Type: text/html; charset=utf-8');
        $invNum = (string) ($_GET['invoice'] ?? '');
        $retryLink = $invNum !== '' ? ' <a href="?pay=' . rawurlencode($invNum) . '">Try again</a>.' : '';
        echo invoxaSimplePage($__businessName, 'Payment cancelled', 'No charge was made.' . $retryLink);
        exit;
    }
    
    if (isset($_GET['paypal_return'])) {
        header('Content-Type: text/html; charset=utf-8');
        // PayPal appends 'token' (the order id) and 'PayerID' to whatever
        // return_url we gave it — this isn't a param we invented.
        $orderId = (string) ($_GET['token'] ?? '');
        $env = $settings['paypal_environment'] ?? 'sandbox';
        $clientId = trim($settings['paypal_client_id'] ?? '');
        $clientSecret = trim($settings['paypal_client_secret'] ?? '');
        if ($orderId === '' || $clientId === '' || $clientSecret === '') {
            echo invoxaSimplePage($__businessName, 'Something went wrong', 'We couldn\'t confirm this payment. If you were charged, contact ' . htmlspecialchars($__businessName) . ' and we\'ll sort it out.');
            exit;
        }
        $tokenResult = paypalGetAccessToken($clientId, $clientSecret, $env);
        $capture = $tokenResult['success'] ? paypalCaptureOrder($tokenResult['access_token'], $env, $orderId) : ['success' => false];
        if ($capture['success']) {
            $customId = $capture['custom_id'] ?? '';
            $invRow = $mysqli->query("SELECT id FROM invoxa_invoices WHERE invoice_number = '" . $mysqli->real_escape_string($customId) . "'")->fetch_assoc();
            if ($invRow) {
                recordInvoicePayment($mysqli, $settings, (int) $invRow['id'], $capture['amount'], 'Paid via PayPal', 'paypal', $capture['capture_id']);
            }
            echo invoxaSimplePage($__businessName, 'Payment received', 'Thank you! Your payment for invoice ' . htmlspecialchars($customId) . ' has been received.');
            exit;
        }
        echo invoxaSimplePage($__businessName, 'Payment not completed', 'PayPal didn\'t complete this payment. No charge was made — you can close this page and try again.');
        exit;
    }
    
    if (isset($_GET['paypal_cancel'])) {
        header('Content-Type: text/html; charset=utf-8');
        $invNum = (string) ($_GET['invoice'] ?? '');
        $retryLink = $invNum !== '' ? ' <a href="?pay=' . rawurlencode($invNum) . '">Try again</a>.' : '';
        echo invoxaSimplePage($__businessName, 'Payment cancelled', 'No charge was made.' . $retryLink);
        exit;
    }
    
    if (isset($_GET['webhook']) && $_GET['webhook'] === 'stripe') {
        // This is the authoritative path — see recordInvoicePayment()'s dedup key,
        // which lets this safely race the return-URL handler above for the same
        // session without double-crediting.
        header('Content-Type: application/json');
        $payload = file_get_contents('php://input');
        $sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
        $webhookSecret = trim($settings['stripe_webhook_secret'] ?? '');
        if ($webhookSecret === '' || !stripeVerifyWebhookSignature($payload, $sigHeader, $webhookSecret)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid signature']);
            exit;
        }
        $event = json_decode($payload, true);
        $type = $event['type'] ?? '';
        if (in_array($type, ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true)) {
            $session = $event['data']['object'] ?? [];
            if (($session['payment_status'] ?? '') === 'paid' && !empty($session['id'])) {
                $invNum = $session['client_reference_id'] ?? '';
                $invRow = $mysqli->query("SELECT id FROM invoxa_invoices WHERE invoice_number = '" . $mysqli->real_escape_string($invNum) . "'")->fetch_assoc();
                if ($invRow) {
                    $amountPaid = stripeAmountFromMinorUnits((int) ($session['amount_total'] ?? 0), $session['currency'] ?? 'usd');
                    recordInvoicePayment($mysqli, $settings, (int) $invRow['id'], $amountPaid, 'Paid via Stripe (webhook)', 'stripe', $session['id']);
                } else {
                    invoxaLogUnmatchedWebhook($mysqli, 'stripe', $type, $invNum);
                }
            }
        } elseif ($type === 'charge.refunded') {
            // The Charge (not the Checkout Session) is what a refund event carries —
            // client_reference_id lives on the Session, not the Charge, which is why
            // stripeCreateCheckoutSession() also stamps invoice_number onto the
            // PaymentIntent's metadata: Stripe copies that onto the Charge, so it's
            // readable here without an extra API call back to Stripe.
            $charge = $event['data']['object'] ?? [];
            $invNum = $charge['metadata']['invoice_number'] ?? '';
            $refundedAmount = stripeAmountFromMinorUnits((int) ($charge['amount_refunded'] ?? 0), $charge['currency'] ?? 'usd');
            $chargeId = $charge['id'] ?? '';
            if ($invNum !== '' && $chargeId !== '' && $refundedAmount > 0) {
                $invRow = $mysqli->query("SELECT id FROM invoxa_invoices WHERE invoice_number = '" . $mysqli->real_escape_string($invNum) . "'")->fetch_assoc();
                if ($invRow) {
                    // amount_refunded is cumulative (a second partial refund on the same
                    // charge reports the running total, not just the new increment), so
                    // the charge id alone isn't a safe idempotency key for repeat partial
                    // refunds — including the cumulative amount in the ref means a genuinely
                    // new (larger) refund gets its own ledger row instead of being
                    // mistaken for a duplicate of the first one.
                    recordInvoiceRefund($mysqli, $settings, (int) $invRow['id'], $refundedAmount, 'stripe', $chargeId . ':' . $charge['amount_refunded']);
                } else {
                    invoxaLogUnmatchedWebhook($mysqli, 'stripe', $type, $invNum);
                }
            }
        }
        http_response_code(200);
        echo json_encode(['received' => true]);
        exit;
    }
    
    if (isset($_GET['webhook']) && $_GET['webhook'] === 'paypal') {
        header('Content-Type: application/json');
        $payload = file_get_contents('php://input');
        $webhookId = trim($settings['paypal_webhook_id'] ?? '');
        $clientId = trim($settings['paypal_client_id'] ?? '');
        $clientSecret = trim($settings['paypal_client_secret'] ?? '');
        $env = $settings['paypal_environment'] ?? 'sandbox';
        if ($webhookId === '' || $clientId === '' || $clientSecret === '') {
            http_response_code(400);
            echo json_encode(['error' => 'PayPal webhook not configured']);
            exit;
        }
        $reqHeaders = [];
        foreach (getallheaders() as $k => $v) {
            $reqHeaders[strtolower($k)] = $v;
        }
        // Cheap local rejection before either outbound PayPal API call: PayPal
        // always sends these four headers on a genuine webhook delivery, so
        // anything missing one is either junk traffic or a malformed request that
        // could never verify anyway — no reason to spend an OAuth token fetch and
        // a verify-signature call finding that out the expensive way.
        foreach (['paypal-auth-algo', 'paypal-cert-url', 'paypal-transmission-id', 'paypal-transmission-sig', 'paypal-transmission-time'] as $requiredHeader) {
            if (empty($reqHeaders[$requiredHeader])) {
                http_response_code(400);
                echo json_encode(['error' => 'Missing required PayPal webhook headers']);
                exit;
            }
        }
        $tokenResult = paypalGetAccessToken($clientId, $clientSecret, $env);
        if (!$tokenResult['success'] || !paypalVerifyWebhookSignature($tokenResult['access_token'], $env, $reqHeaders, $payload, $webhookId)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid signature']);
            exit;
        }
        $event = json_decode($payload, true);
        $eventType = $event['event_type'] ?? '';
        if ($eventType === 'PAYMENT.CAPTURE.COMPLETED') {
            $resource = $event['resource'] ?? [];
            $captureId = $resource['id'] ?? '';
            $customId = $resource['custom_id'] ?? '';
            if ($captureId !== '' && $customId !== '') {
                $invRow = $mysqli->query("SELECT id FROM invoxa_invoices WHERE invoice_number = '" . $mysqli->real_escape_string($customId) . "'")->fetch_assoc();
                if ($invRow) {
                    $amountPaid = (float) ($resource['amount']['value'] ?? 0);
                    recordInvoicePayment($mysqli, $settings, (int) $invRow['id'], $amountPaid, 'Paid via PayPal (webhook)', 'paypal', $captureId);
                } else {
                    invoxaLogUnmatchedWebhook($mysqli, 'paypal', $eventType, $customId);
                }
            }
        } elseif ($eventType === 'PAYMENT.CAPTURE.REFUNDED') {
            // The refund resource doesn't reliably carry custom_id itself — but it
            // always links back ("up") to the capture it refunds, and that capture's
            // id is exactly what this app already stored as provider_ref on the
            // original payment row, so looking it up there (rather than trusting
            // custom_id propagation on the refund payload) is the more robust path.
            $resource = $event['resource'] ?? [];
            $refundId = $resource['id'] ?? '';
            $refundAmount = (float) ($resource['amount']['value'] ?? 0);
            $captureId = null;
            foreach ($resource['links'] ?? [] as $link) {
                if (($link['rel'] ?? '') === 'up' && preg_match('#/captures/([A-Za-z0-9\-]+)#', $link['href'] ?? '', $m)) {
                    $captureId = $m[1];
                    break;
                }
            }
            if ($refundId !== '' && $captureId !== null && $refundAmount > 0) {
                $origRow = $mysqli->query("SELECT invoice_id FROM invoxa_payments WHERE provider = 'paypal' AND provider_ref = '" . $mysqli->real_escape_string($captureId) . "'")->fetch_assoc();
                if ($origRow) {
                    recordInvoiceRefund($mysqli, $settings, (int) $origRow['invoice_id'], $refundAmount, 'paypal', $refundId);
                } else {
                    invoxaLogUnmatchedWebhook($mysqli, 'paypal', $eventType, $captureId);
                }
            }
        }
        http_response_code(200);
        echo json_encode(['received' => true]);
        exit;
    }
    
}

// Single source of truth for crediting a payment against an invoice — used by
// Mark Paid (including the Invoices tab's bulk-select action) and by the
// Stripe/PayPal return-URL handlers and webhooks below.
//
// $providerRef, when given, is the gateway's id for this charge (Stripe
// Checkout Session id, PayPal capture id) and combines with $provider as the
// ledger's idempotency key (see uniq_provider_ref on invoxa_payments) — a
// duplicate delivery is skipped rather than double-crediting. Manual
// payments never pass $providerRef, so they're never deduplicated against
// each other.
// Special-cased since ucfirst('api') would give 'Api' instead of 'API'.
function invoxaProviderLabel(string $provider): string
{
    return $provider === 'api' ? 'API' : ucfirst($provider);
}

function recordInvoicePayment($mysqli, array $settings, int $invoiceId, float $amount, string $note = '', string $provider = 'manual', ?string $providerRef = null): array
{
    if ($amount <= 0) {
        return ['success' => false, 'error' => 'Enter a payment amount greater than zero.', 'duplicate' => false];
    }
    if ($providerRef !== null) {
        $dupCheck = $mysqli->prepare("SELECT id FROM invoxa_payments WHERE provider = ? AND provider_ref = ?");
        $dupCheck->bind_param("ss", $provider, $providerRef);
        $dupCheck->execute();
        if ($dupCheck->get_result()->fetch_assoc()) {
            return ['success' => true, 'duplicate' => true];
        }
    }

    $invRow = $mysqli->query("SELECT amount, currency, invoice_number, status, client_name FROM invoxa_invoices WHERE id = " . (int) $invoiceId)->fetch_assoc();
    if (!$invRow) {
        return ['success' => false, 'error' => 'Invoice not found', 'duplicate' => false];
    }
    $invAmount = (float) $invRow['amount'];
    $invNum = $invRow['invoice_number'];
    $currentStatus = $invRow['status'];

    $paymentStmt = $mysqli->prepare("INSERT INTO invoxa_payments (invoice_id, amount, note, provider, provider_ref) VALUES (?, ?, ?, ?, ?)");
    $paymentStmt->bind_param("idsss", $invoiceId, $amount, $note, $provider, $providerRef);
    try {
        $paymentStmt->execute();
    } catch (mysqli_sql_exception $e) {
        // The dedup check above isn't atomic (a webhook and the return-URL
        // handler can race), so uniq_provider_ref is the real guard — turn its
        // rejection into an "already handled" response instead of a 500.
        if ($providerRef !== null && str_contains($e->getMessage(), 'uniq_provider_ref')) {
            return ['success' => true, 'duplicate' => true];
        }
        throw $e;
    }

    // paid_amount/paid_at stay a cached SUM()/latest-payment snapshot of the
    // ledger, since stats/export/dashboard queries read those columns directly.
    $totalPaid = (float) ($mysqli->query("SELECT COALESCE(SUM(amount), 0) as t FROM invoxa_payments WHERE invoice_id = " . (int) $invoiceId)->fetch_assoc()['t'] ?? 0);
    $isPartial = $totalPaid < $invAmount;
    $newStatus = $isPartial ? $currentStatus : 'paid';

    $stmt = $mysqli->prepare("UPDATE invoxa_invoices SET status = ?, paid_at = NOW(), paid_amount = ? WHERE id = ?");
    $stmt->bind_param("sdi", $newStatus, $totalPaid, $invoiceId);
    $stmt->execute();

    $sourceLabel = $provider === 'manual' ? '' : ' via ' . invoxaProviderLabel($provider);
    $actionType = $isPartial ? 'mark_partial_paid' : 'mark_paid';
    $notes = ($isPartial ? "Partial payment logged: $" : "Marked as paid: $") . number_format($amount, 2)
        . " (total paid to date: $" . number_format($totalPaid, 2) . " of $" . number_format($invAmount, 2) . ")"
        . $sourceLabel
        . ($note !== '' ? " — {$note}" : '');
    invoxaLogAction($mysqli, $invoiceId, $invNum, $actionType, $notes);

    $currencyCode = invoxaResolveCurrency($invRow['currency'] ?? '', $settings);
    notifyChannel($mysqli, $settings, 'notify_on_payment', ($isPartial ? "\xF0\x9F\x92\xB0 Partial payment received" : "\xE2\x9C\x85 Invoice paid in full") . " — {$invNum} ({$invRow['client_name']}){$sourceLabel}: {$currencyCode} " . number_format($amount, 2));

    return ['success' => true, 'duplicate' => false, 'is_partial' => $isPartial, 'total_paid' => $totalPaid, 'invoice_amount' => $invAmount, 'invoice_number' => $invNum];
}

// Reverses money out of the ledger when Stripe/PayPal reports a refund
// (a dashboard refund doesn't touch Invoxa on its own). Recorded as a
// negative-amount row in the same invoxa_payments ledger recordInvoicePayment()
// writes to, so every existing SUM(amount) read of paid_amount stays correct.
// Uses the same (provider, provider_ref) idempotency guarantee as payments.
function recordInvoiceRefund($mysqli, array $settings, int $invoiceId, float $refundAmount, string $provider, string $providerRef): array
{
    if ($refundAmount <= 0) {
        return ['success' => false, 'error' => 'Refund amount must be greater than zero.', 'duplicate' => false];
    }
    $dupCheck = $mysqli->prepare("SELECT id FROM invoxa_payments WHERE provider = ? AND provider_ref = ?");
    $dupCheck->bind_param("ss", $provider, $providerRef);
    $dupCheck->execute();
    if ($dupCheck->get_result()->fetch_assoc()) {
        return ['success' => true, 'duplicate' => true];
    }

    $invRow = $mysqli->query("SELECT amount, currency, invoice_number, status, client_name FROM invoxa_invoices WHERE id = " . (int) $invoiceId)->fetch_assoc();
    if (!$invRow) {
        return ['success' => false, 'error' => 'Invoice not found', 'duplicate' => false];
    }

    $note = 'Refund';
    $negAmount = -abs($refundAmount);
    $stmt = $mysqli->prepare("INSERT INTO invoxa_payments (invoice_id, amount, note, provider, provider_ref) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("idsss", $invoiceId, $negAmount, $note, $provider, $providerRef);
    try {
        $stmt->execute();
    } catch (mysqli_sql_exception $e) {
        if (str_contains($e->getMessage(), 'uniq_provider_ref')) {
            return ['success' => true, 'duplicate' => true];
        }
        throw $e;
    }

    $invAmount = (float) $invRow['amount'];
    $totalPaid = (float) ($mysqli->query("SELECT COALESCE(SUM(amount), 0) as t FROM invoxa_payments WHERE invoice_id = " . (int) $invoiceId)->fetch_assoc()['t'] ?? 0);
    // A refund only moves the total down: void stays void, otherwise the
    // invoice reopens to 'sent' unless the remaining total still covers it.
    $newStatus = $invRow['status'] === 'void' ? 'void' : (($totalPaid >= $invAmount && $totalPaid > 0) ? 'paid' : 'sent');

    $stmt = $mysqli->prepare("UPDATE invoxa_invoices SET status = ?, paid_amount = ? WHERE id = ?");
    $stmt->bind_param("sdi", $newStatus, $totalPaid, $invoiceId);
    $stmt->execute();

    $sourceLabel = ' via ' . invoxaProviderLabel($provider);
    $notes = "Refund issued: $" . number_format($refundAmount, 2) . " (total paid now: $" . number_format($totalPaid, 2) . " of $" . number_format($invAmount, 2) . ")" . $sourceLabel;
    invoxaLogAction($mysqli, $invoiceId, $invRow['invoice_number'], 'refund_issued', $notes);

    $currencyCode = invoxaResolveCurrency($invRow['currency'] ?? '', $settings);
    notifyChannel($mysqli, $settings, 'notify_on_refund', "\xE2\x86\xA9\xEF\xB8\x8F Refund issued — {$invRow['invoice_number']} ({$invRow['client_name']}){$sourceLabel}: {$currencyCode} " . number_format($refundAmount, 2));

    return ['success' => true, 'duplicate' => false, 'total_paid' => $totalPaid, 'invoice_number' => $invRow['invoice_number']];
}

function invoxaHandleMarkPaid($mysqli, array $settings): void
{
// $amount is this installment only, not a cumulative total — recorded as
// its own row in invoxa_payments so part-payments build a real history.
$id = (int) $_POST['id'];
$amount = (float) $_POST['amount'];
$note = trim($_POST['note'] ?? '');
$result = recordInvoicePayment($mysqli, $settings, $id, $amount, $note, 'manual');
if (!$result['success']) {
    echo json_encode(['success' => false, 'error' => $result['error']]);
    exit;
}
echo json_encode(['success' => true]);
exit;
}

function invoxaHandleGetInvoicePayments($mysqli): void
{
// Backs the "Payment History" list in the Mark Paid modal, so a new
// installment can be sized against what's already been paid.
$invoiceId = (int) ($_POST['invoice_id'] ?? 0);
$res = $mysqli->query("SELECT id, amount, note, paid_at FROM invoxa_payments WHERE invoice_id = $invoiceId ORDER BY paid_at ASC, id ASC");
$payments = [];
while ($r = $res->fetch_assoc())
    $payments[] = $r;
echo json_encode(['success' => true, 'payments' => $payments]);
exit;
}

function invoxaHandleMarkUnpaid($mysqli): void
{
$id = (int) $_POST['id'];
// Full reset, not just undoing the latest installment — clears the whole
// payment ledger ("Mark Unpaid" and "Clear Partial Payment" both call this).
$delStmt = $mysqli->prepare("DELETE FROM invoxa_payments WHERE invoice_id = ?");
$delStmt->bind_param("i", $id);
$delStmt->execute();
$stmt = $mysqli->prepare("UPDATE invoxa_invoices SET status = 'sent', paid_at = NULL, paid_amount = 0 WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$invNum = $mysqli->query("SELECT invoice_number FROM invoxa_invoices WHERE id = $id")->fetch_assoc()['invoice_number'] ?? '';
invoxaLogAction($mysqli, $id, $invNum, 'mark_unpaid', 'Marked as unpaid — payment history cleared');
echo json_encode(['success' => true]);
exit;
}

function invoxaHandleSavePaymentSettings($mysqli): void
{
$stripeEnabled = ($_POST['stripe_enabled'] ?? '0') === '1' ? '1' : '0';
$paypalEnabled = ($_POST['paypal_enabled'] ?? '0') === '1' ? '1' : '0';
$paypalEnv = ($_POST['paypal_environment'] ?? 'sandbox') === 'live' ? 'live' : 'sandbox';
$upsert = $mysqli->prepare("INSERT INTO invoxa_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
foreach ([
    'public_url' => rtrim(trim($_POST['public_url'] ?? ''), '/'),
    'stripe_enabled' => $stripeEnabled,
    'stripe_secret_key' => trim($_POST['stripe_secret_key'] ?? ''),
    'stripe_webhook_secret' => trim($_POST['stripe_webhook_secret'] ?? ''),
    'paypal_enabled' => $paypalEnabled,
    'paypal_environment' => $paypalEnv,
    'paypal_client_id' => trim($_POST['paypal_client_id'] ?? ''),
    'paypal_client_secret' => trim($_POST['paypal_client_secret'] ?? ''),
    'paypal_webhook_id' => trim($_POST['paypal_webhook_id'] ?? ''),
] as $key => $value) {
    $upsert->bind_param("ss", $key, $value);
    $upsert->execute();
}
echo json_encode(['success' => true]);
exit;
}

function invoxaHandleTestStripeConnection(): void
{
// Tests against whatever key is currently typed in the form, not the
// saved setting, so you don't have to Save blind first.
$key = trim($_POST['stripe_secret_key'] ?? '');
if ($key === '') {
    echo json_encode(['success' => false, 'error' => 'Enter a Secret Key first']);
    exit;
}
$res = httpApiRequest('https://api.stripe.com/v1/account', 'GET', ['Authorization' => 'Bearer ' . $key], null);
if (!$res['success']) {
    echo json_encode(['success' => false, 'error' => $res['body']['error']['message'] ?? 'Could not reach Stripe']);
    exit;
}
echo json_encode(['success' => true, 'account' => $res['body']['id'] ?? '']);
exit;
}

function invoxaHandleTestPaypalConnection(): void
{
$clientId = trim($_POST['paypal_client_id'] ?? '');
$clientSecret = trim($_POST['paypal_client_secret'] ?? '');
$env = ($_POST['paypal_environment'] ?? 'sandbox') === 'live' ? 'live' : 'sandbox';
if ($clientId === '' || $clientSecret === '') {
    echo json_encode(['success' => false, 'error' => 'Enter a Client ID and Client Secret first']);
    exit;
}
$result = paypalGetAccessToken($clientId, $clientSecret, $env);
echo json_encode($result);
exit;
}
