<?php

const ENXURE_XERO_SCOPES = 'offline_access accounting.invoices accounting.payments accounting.contacts accounting.settings.read';
const ENXURE_XERO_API = 'https://api.xero.com/api.xro/2.0/';
const ENXURE_XERO_BATCH_LIMIT = 40;

function enxureXeroCfg($mysqli): array
{
    $cfg = [];
    $res = $mysqli->query("SELECT setting_key, setting_value FROM enxure_settings WHERE setting_key LIKE 'xero\\_%'");
    while ($row = $res->fetch_assoc()) {
        $cfg[$row['setting_key']] = (string) $row['setting_value'];
    }
    return $cfg;
}

function enxureXeroSet($mysqli, array $values): void
{
    $stmt = $mysqli->prepare("INSERT INTO enxure_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    foreach ($values as $k => $v) {
        $v = (string) $v;
        $stmt->bind_param("ss", $k, $v);
        $stmt->execute();
    }
}

function enxureXeroActive($mysqli, array $settings): bool
{
    if (!enxureLicenseSignatureOk($mysqli, $settings)) {
        return false;
    }
    $cfg = enxureXeroCfg($mysqli);
    return ($cfg['xero_enabled'] ?? '0') === '1' && ($cfg['xero_tenant_id'] ?? '') !== '' && ($cfg['xero_refresh_token'] ?? '') !== '';
}

function enxureXeroRedirectUri(array $settings): ?string
{
    $base = enxurePublicBaseUrl($settings);
    return $base === null ? null : $base . '/?xero=callback';
}

function enxureXeroError(array $res): string
{
    $b = $res['body'] ?? null;
    if (is_array($b)) {
        foreach ($b['Elements'] ?? [] as $el) {
            foreach ($el['ValidationErrors'] ?? [] as $ve) {
                if (!empty($ve['Message'])) {
                    return $ve['Message'];
                }
            }
        }
        foreach (['Message', 'Detail', 'error_description', 'error', 'Title'] as $k) {
            if (!empty($b[$k]) && is_string($b[$k])) {
                return $b[$k];
            }
        }
    }
    return 'Xero request failed (HTTP ' . ($res['status'] ?? 0) . ')';
}

function enxureXeroTokenRequest($mysqli, array $form): array
{
    $cfg = enxureXeroCfg($mysqli);
    $res = httpApiRequest('https://identity.xero.com/connect/token', 'POST', [
        'Authorization' => 'Basic ' . base64_encode(($cfg['xero_client_id'] ?? '') . ':' . ($cfg['xero_client_secret'] ?? '')),
        'Content-Type' => 'application/x-www-form-urlencoded',
        'Accept' => 'application/json',
    ], http_build_query($form));
    if (!$res['success'] || empty($res['body']['access_token'])) {
        return ['success' => false, 'error' => enxureXeroError($res)];
    }
    enxureXeroSet($mysqli, [
        'xero_access_token' => $res['body']['access_token'],
        'xero_refresh_token' => $res['body']['refresh_token'] ?? ($cfg['xero_refresh_token'] ?? ''),
        'xero_token_expires' => time() + (int) ($res['body']['expires_in'] ?? 1800),
    ]);
    return ['success' => true];
}

function enxureXeroAccessToken($mysqli): ?string
{
    $cfg = enxureXeroCfg($mysqli);
    if (($cfg['xero_access_token'] ?? '') !== '' && (int) ($cfg['xero_token_expires'] ?? 0) > time() + 60) {
        return $cfg['xero_access_token'];
    }
    if (($cfg['xero_refresh_token'] ?? '') === '') {
        return null;
    }
    $r = enxureXeroTokenRequest($mysqli, ['grant_type' => 'refresh_token', 'refresh_token' => $cfg['xero_refresh_token']]);
    return $r['success'] ? (enxureXeroCfg($mysqli)['xero_access_token'] ?? null) : null;
}

function enxureXeroRequest($mysqli, string $method, string $path, ?array $body = null, array $query = [], array $extraHeaders = []): array
{
    $token = enxureXeroAccessToken($mysqli);
    if ($token === null) {
        return ['success' => false, 'status' => 0, 'body' => null, 'error' => 'Xero is not connected, or the connection has expired — reconnect under Settings > Xero.'];
    }
    $cfg = enxureXeroCfg($mysqli);
    $url = ENXURE_XERO_API . $path . ($query ? '?' . http_build_query($query) : '');
    $headers = array_merge([
        'Authorization' => 'Bearer ' . $token,
        'xero-tenant-id' => $cfg['xero_tenant_id'] ?? '',
        'Accept' => 'application/json',
        'Content-Type' => 'application/json',
    ], $extraHeaders);
    $res = httpApiRequest($url, $method, $headers, $body === null ? null : json_encode($body));
    if (!$res['success']) {
        $res['error'] = enxureXeroError($res);
    }
    return $res;
}

function enxureXeroAuthUrl($mysqli, array $settings): ?string
{
    $cfg = enxureXeroCfg($mysqli);
    $redirect = enxureXeroRedirectUri($settings);
    if ($redirect === null || ($cfg['xero_client_id'] ?? '') === '') {
        return null;
    }
    $state = bin2hex(random_bytes(16));
    $_SESSION['xero_oauth_state'] = $state;
    return 'https://login.xero.com/identity/connect/authorize?' . http_build_query([
        'response_type' => 'code',
        'client_id' => $cfg['xero_client_id'],
        'redirect_uri' => $redirect,
        'scope' => ENXURE_XERO_SCOPES,
        'state' => $state,
    ]);
}

function enxureHandleXeroRoute($mysqli, array $settings, bool $isAdmin): void
{
    if (!$isAdmin) {
        http_response_code(403);
        exit('Admin access required.');
    }
    $back = (enxurePublicBaseUrl($settings) ?? '') . '/';
    if (!enxureLicenseSignatureOk($mysqli, $settings)) {
        header('Location: ' . $back);
        exit;
    }
    if ($_GET['xero'] === 'connect') {
        $url = enxureXeroAuthUrl($mysqli, $settings);
        if ($url === null) {
            exit('Save a Client ID and Secret first, and make sure a Public URL is set (Settings > Payments).');
        }
        header('Location: ' . $url);
        exit;
    }
    if ($_GET['xero'] === 'callback') {
        $state = $_SESSION['xero_oauth_state'] ?? '';
        unset($_SESSION['xero_oauth_state']);
        if (isset($_GET['error']) || $state === '' || !hash_equals($state, (string) ($_GET['state'] ?? '')) || empty($_GET['code'])) {
            exit('Xero connection was cancelled or the response could not be verified. Close this tab and try Connect again.');
        }
        $redirect = enxureXeroRedirectUri($settings);
        $tok = enxureXeroTokenRequest($mysqli, ['grant_type' => 'authorization_code', 'code' => $_GET['code'], 'redirect_uri' => $redirect]);
        if (!$tok['success']) {
            exit('Xero rejected the connection: ' . htmlspecialchars($tok['error']));
        }
        $access = enxureXeroCfg($mysqli)['xero_access_token'];
        $conn = httpApiRequest('https://api.xero.com/connections', 'GET', ['Authorization' => 'Bearer ' . $access, 'Accept' => 'application/json'], null);
        $tenant = is_array($conn['body'] ?? null) ? ($conn['body'][0] ?? null) : null;
        if (!$tenant || empty($tenant['tenantId'])) {
            exit('Connected to Xero, but no organisation was authorised. Try again and pick an organisation.');
        }
        enxureXeroSet($mysqli, [
            'xero_tenant_id' => $tenant['tenantId'],
            'xero_tenant_name' => $tenant['tenantName'] ?? '',
            'xero_enabled' => '1',
        ]);
        enxureLogAction($mysqli, null, '', 'xero_connected', 'Connected to Xero organisation ' . ($tenant['tenantName'] ?? $tenant['tenantId']));
        header('Location: ' . $back);
        exit;
    }
}

function enxureXeroDate(?string $s): string
{
    $s = trim((string) $s);
    return preg_match('/^\d{4}-\d{2}-\d{2}/', $s) ? substr($s, 0, 10) : date('Y-m-d');
}

function enxureXeroEscape(string $s): string
{
    return str_replace('"', '', $s);
}

function enxureXeroEnsureContact($mysqli, array $client): array
{
    if (!empty($client['xero_contact_id'])) {
        return ['success' => true, 'id' => $client['xero_contact_id']];
    }
    $found = null;
    if (trim((string) $client['email']) !== '') {
        $r = enxureXeroRequest($mysqli, 'GET', 'Contacts', null, ['where' => 'EmailAddress=="' . enxureXeroEscape($client['email']) . '"']);
        $found = $r['body']['Contacts'][0]['ContactID'] ?? null;
    }
    if ($found === null) {
        $r = enxureXeroRequest($mysqli, 'GET', 'Contacts', null, ['where' => 'Name=="' . enxureXeroEscape($client['client_name']) . '"']);
        $found = $r['body']['Contacts'][0]['ContactID'] ?? null;
    }
    if ($found === null) {
        $r = enxureXeroRequest($mysqli, 'POST', 'Contacts', enxureXeroContactBody($client));
        if (!$r['success']) {
            return ['success' => false, 'error' => $r['error']];
        }
        $found = $r['body']['Contacts'][0]['ContactID'] ?? null;
    }
    if ($found === null) {
        return ['success' => false, 'error' => 'Xero did not return a contact id.'];
    }
    $stmt = $mysqli->prepare("UPDATE enxure_clients SET xero_contact_id = ? WHERE id = ?");
    $stmt->bind_param("si", $found, $client['id']);
    $stmt->execute();
    return ['success' => true, 'id' => $found];
}

function enxureXeroContactBody(array $client): array
{
    $body = ['Name' => $client['client_name'], 'IsCustomer' => true];
    if (trim((string) $client['email']) !== '') {
        $body['EmailAddress'] = $client['email'];
    }
    if (trim((string) ($client['contact_name'] ?? '')) !== '') {
        $parts = preg_split('/\s+/', trim($client['contact_name']), 2);
        $body['FirstName'] = $parts[0];
        if (isset($parts[1])) {
            $body['LastName'] = $parts[1];
        }
    }
    if (trim((string) ($client['phone'] ?? '')) !== '') {
        $body['Phones'] = [['PhoneType' => 'DEFAULT', 'PhoneNumber' => $client['phone']]];
    }
    if (trim((string) ($client['address'] ?? '')) !== '') {
        $body['Addresses'] = [['AddressType' => 'STREET', 'AddressLine1' => mb_substr(trim($client['address']), 0, 500)]];
    }
    return $body;
}

function enxureXeroPushClient($mysqli, array $settings, int $clientId): void
{
    if (!enxureXeroActive($mysqli, $settings)) {
        return;
    }
    $client = $mysqli->query("SELECT * FROM enxure_clients WHERE id = " . (int) $clientId)->fetch_assoc();
    if (!$client) {
        return;
    }
    if (empty($client['xero_contact_id'])) {
        enxureXeroEnsureContact($mysqli, $client);
        return;
    }
    $body = enxureXeroContactBody($client);
    $body['ContactID'] = $client['xero_contact_id'];
    enxureXeroRequest($mysqli, 'POST', 'Contacts/' . $client['xero_contact_id'], $body);
}

function enxureXeroInvoiceBody(array $inv, array $cfg, string $contactId): array
{
    $items = json_decode((string) $inv['line_items_json'], true);
    if (!is_array($items) || !$items) {
        $items = [['code' => '', 'desc' => 'Invoice ' . $inv['invoice_number'], 'amount' => $inv['amount']]];
        $discount = 0.0;
        $taxRate = 0.0;
    } else {
        $discount = (float) $inv['discount_pct'];
        $taxRate = (float) $inv['tax_rate'];
    }
    $account = trim($cfg['xero_sales_account'] ?? '') ?: '200';
    $taxType = $taxRate > 0 ? (trim($cfg['xero_tax_type'] ?? '') ?: 'OUTPUT') : 'NONE';
    $lines = [];
    foreach ($items as $li) {
        $amount = round((float) str_replace(',', '', (string) ($li['amount'] ?? 0)), 2);
        $desc = trim(((string) ($li['code'] ?? '')) . ' ' . ((string) ($li['desc'] ?? '')));
        $line = [
            'Description' => $desc !== '' ? $desc : 'Item',
            'Quantity' => 1,
            'UnitAmount' => $amount,
            'AccountCode' => $account,
            'TaxType' => $taxType,
        ];
        if ($discount > 0) {
            $line['DiscountRate'] = $discount;
        }
        if ($taxRate > 0) {
            $line['TaxAmount'] = round($amount * (1 - $discount / 100) * $taxRate / 100, 2);
        }
        $lines[] = $line;
    }
    $body = [
        'Type' => 'ACCREC',
        'Contact' => ['ContactID' => $contactId],
        'InvoiceNumber' => $inv['invoice_number'],
        'Date' => enxureXeroDate($inv['invoice_date']),
        'DueDate' => enxureXeroDate($inv['due_date']),
        'LineItemAmountTypes' => 'Exclusive',
        'LineItems' => $lines,
        'Status' => 'AUTHORISED',
    ];
    if (trim((string) $inv['currency']) !== '') {
        $body['CurrencyCode'] = strtoupper($inv['currency']);
    }
    if (trim((string) ($inv['client_reference'] ?? '')) !== '') {
        $body['Reference'] = $inv['client_reference'];
    }
    return $body;
}

function enxureXeroPushInvoice($mysqli, array $settings, int $invoiceId): array
{
    if (!enxureXeroActive($mysqli, $settings)) {
        return ['success' => false, 'skipped' => true];
    }
    $inv = $mysqli->query("SELECT * FROM enxure_invoices WHERE id = " . (int) $invoiceId)->fetch_assoc();
    if (!$inv || (int) $inv['is_quote'] === 1 || in_array($inv['status'], ['draft', 'void'], true)) {
        return ['success' => false, 'skipped' => true];
    }
    if (empty($inv['xero_invoice_id'])) {
        $stmt = $mysqli->prepare("SELECT * FROM enxure_clients WHERE client_key = ? LIMIT 1");
        $stmt->bind_param("s", $inv['client_key']);
        $stmt->execute();
        $client = $stmt->get_result()->fetch_assoc();
        if (!$client) {
            return ['success' => false, 'error' => 'Client not found for ' . $inv['invoice_number']];
        }
        $contact = enxureXeroEnsureContact($mysqli, $client);
        if (!$contact['success']) {
            return ['success' => false, 'error' => $contact['error']];
        }
        $r = enxureXeroRequest($mysqli, 'POST', 'Invoices', enxureXeroInvoiceBody($inv, enxureXeroCfg($mysqli), $contact['id']));
        $xid = $r['body']['Invoices'][0]['InvoiceID'] ?? null;
        if (!$r['success'] || $xid === null) {
            $dup = enxureXeroRequest($mysqli, 'GET', 'Invoices', null, ['where' => 'InvoiceNumber=="' . enxureXeroEscape($inv['invoice_number']) . '"']);
            $xid = $dup['body']['Invoices'][0]['InvoiceID'] ?? null;
            if ($xid === null) {
                return ['success' => false, 'error' => $inv['invoice_number'] . ': ' . ($r['error'] ?? 'Xero rejected the invoice')];
            }
        }
        $stmt = $mysqli->prepare("UPDATE enxure_invoices SET xero_invoice_id = ? WHERE id = ?");
        $stmt->bind_param("si", $xid, $invoiceId);
        $stmt->execute();
        enxureLogAction($mysqli, $invoiceId, $inv['invoice_number'], 'xero_pushed', 'Pushed to Xero');
        $inv['xero_invoice_id'] = $xid;
    }
    enxureXeroPushPayments($mysqli, $settings, $inv);
    return ['success' => true];
}

function enxureXeroPushPayments($mysqli, array $settings, array $inv): int
{
    $cfg = enxureXeroCfg($mysqli);
    $bank = trim($cfg['xero_bank_account'] ?? '');
    if ($bank === '' || empty($inv['xero_invoice_id'])) {
        return 0;
    }
    $rows = $mysqli->query("SELECT id, amount, paid_at, note FROM enxure_payments WHERE invoice_id = " . (int) $inv['id'] . " AND provider <> 'xero' AND xero_payment_id IS NULL AND amount > 0 ORDER BY id")->fetch_all(MYSQLI_ASSOC);
    $n = 0;
    foreach ($rows as $p) {
        $r = enxureXeroRequest($mysqli, 'PUT', 'Payments', [
            'Invoice' => ['InvoiceID' => $inv['xero_invoice_id']],
            'Account' => ['Code' => $bank],
            'Date' => enxureXeroDate($p['paid_at']),
            'Amount' => round((float) $p['amount'], 2),
            'Reference' => (string) $p['note'],
        ]);
        $pid = $r['body']['Payments'][0]['PaymentID'] ?? null;
        if (!$r['success'] || $pid === null) {
            enxureLogAction($mysqli, (int) $inv['id'], $inv['invoice_number'], 'xero_failed', 'Payment push failed: ' . ($r['error'] ?? 'unknown error'));
            continue;
        }
        $stmt = $mysqli->prepare("UPDATE enxure_payments SET xero_payment_id = ? WHERE id = ?");
        $stmt->bind_param("si", $pid, $p['id']);
        $stmt->execute();
        $n++;
    }
    return $n;
}

function enxureXeroVoidInvoice($mysqli, array $settings, int $invoiceId): void
{
    if (!enxureXeroActive($mysqli, $settings)) {
        return;
    }
    $inv = $mysqli->query("SELECT invoice_number, xero_invoice_id FROM enxure_invoices WHERE id = " . (int) $invoiceId)->fetch_assoc();
    if (!$inv || empty($inv['xero_invoice_id'])) {
        return;
    }
    $r = enxureXeroRequest($mysqli, 'POST', 'Invoices/' . $inv['xero_invoice_id'], ['InvoiceID' => $inv['xero_invoice_id'], 'Status' => 'VOIDED']);
    enxureLogAction($mysqli, $invoiceId, $inv['invoice_number'], $r['success'] ? 'xero_pushed' : 'xero_failed', $r['success'] ? 'Voided in Xero' : 'Void in Xero failed: ' . $r['error']);
}

function enxureXeroNewClientKey($mysqli, string $name): string
{
    $base = strtolower(substr(preg_replace('/[^a-zA-Z0-9]/', '', $name), 0, 3)) ?: substr(md5($name), 0, 3);
    $key = $base;
    $i = 2;
    while ($mysqli->query("SELECT 1 FROM enxure_clients WHERE client_key = '" . $mysqli->real_escape_string($key) . "'")->num_rows > 0) {
        $key = substr($base, 0, 2) . $i++;
        if ($i > 9) {
            $key = substr(md5($name . microtime()), 0, 6);
            break;
        }
    }
    return $key;
}

function enxureXeroPullContacts($mysqli, array $cfg): array
{
    $linked = 0;
    $created = 0;
    $headers = [];
    if (!empty($cfg['xero_contacts_since'])) {
        $headers['If-Modified-Since'] = gmdate('Y-m-d\TH:i:s', (int) $cfg['xero_contacts_since']);
    }
    $started = time();
    $import = ($cfg['xero_import_contacts'] ?? '1') === '1';
    for ($page = 1; $page <= 20; $page++) {
        $r = enxureXeroRequest($mysqli, 'GET', 'Contacts', null, ['where' => 'IsCustomer==true', 'page' => $page], $headers);
        if (!$r['success']) {
            return ['linked' => $linked, 'created' => $created, 'error' => $r['error']];
        }
        $contacts = $r['body']['Contacts'] ?? [];
        foreach ($contacts as $c) {
            if (($c['ContactStatus'] ?? 'ACTIVE') !== 'ACTIVE') {
                continue;
            }
            $cid = $c['ContactID'];
            $name = trim($c['Name'] ?? '');
            $email = trim($c['EmailAddress'] ?? '');
            $stmt = $mysqli->prepare("SELECT id FROM enxure_clients WHERE xero_contact_id = ? LIMIT 1");
            $stmt->bind_param("s", $cid);
            $stmt->execute();
            if ($stmt->get_result()->fetch_assoc()) {
                continue;
            }
            $stmt = $mysqli->prepare("SELECT id FROM enxure_clients WHERE xero_contact_id IS NULL AND ((? <> '' AND LOWER(email) = LOWER(?)) OR LOWER(client_name) = LOWER(?)) ORDER BY id LIMIT 1");
            $stmt->bind_param("sss", $email, $email, $name);
            $stmt->execute();
            $match = $stmt->get_result()->fetch_assoc();
            if ($match) {
                $up = $mysqli->prepare("UPDATE enxure_clients SET xero_contact_id = ? WHERE id = ?");
                $up->bind_param("si", $cid, $match['id']);
                $up->execute();
                $linked++;
                continue;
            }
            if (!$import || $name === '' || $email === '') {
                continue;
            }
            $phone = trim($c['Phones'][1]['PhoneNumber'] ?? ($c['Phones'][0]['PhoneNumber'] ?? ''));
            $addr = '';
            foreach ($c['Addresses'] ?? [] as $a) {
                if (($a['AddressType'] ?? '') === 'STREET' && trim($a['AddressLine1'] ?? '') !== '') {
                    $addr = implode(', ', array_filter([trim($a['AddressLine1']), trim($a['City'] ?? ''), trim($a['PostalCode'] ?? '')]));
                    break;
                }
            }
            $key = enxureXeroNewClientKey($mysqli, $name);
            $ins = $mysqli->prepare("INSERT INTO enxure_clients (client_key, client_name, email, phone, address, xero_contact_id, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)");
            $ins->bind_param("ssssss", $key, $name, $email, $phone, $addr, $cid);
            if ($ins->execute()) {
                enxureLogAction($mysqli, null, '', 'xero_pulled', "Client {$name} created from Xero contact");
                $created++;
            }
        }
        if (count($contacts) < 100) {
            break;
        }
    }
    enxureXeroSet($mysqli, ['xero_contacts_since' => $started]);
    return ['linked' => $linked, 'created' => $created];
}

function enxureXeroPullInvoices($mysqli, array $settings): array
{
    $paymentsAdded = 0;
    $voided = 0;
    $rows = $mysqli->query("SELECT id, invoice_number, status, xero_invoice_id FROM enxure_invoices WHERE xero_invoice_id IS NOT NULL AND is_quote = 0 AND status NOT IN ('paid', 'void')")->fetch_all(MYSQLI_ASSOC);
    foreach (array_chunk($rows, ENXURE_XERO_BATCH_LIMIT) as $chunk) {
        $byXero = [];
        foreach ($chunk as $row) {
            $byXero[strtolower($row['xero_invoice_id'])] = $row;
        }
        $r = enxureXeroRequest($mysqli, 'GET', 'Invoices', null, ['IDs' => implode(',', array_keys($byXero))]);
        if (!$r['success']) {
            return ['payments' => $paymentsAdded, 'voided' => $voided, 'error' => $r['error']];
        }
        foreach ($r['body']['Invoices'] ?? [] as $xi) {
            $local = $byXero[strtolower($xi['InvoiceID'] ?? '')] ?? null;
            if (!$local) {
                continue;
            }
            if (in_array($xi['Status'] ?? '', ['VOIDED', 'DELETED'], true)) {
                $stmt = $mysqli->prepare("UPDATE enxure_invoices SET status = 'void' WHERE id = ?");
                $stmt->bind_param("i", $local['id']);
                $stmt->execute();
                enxureLogAction($mysqli, (int) $local['id'], $local['invoice_number'], 'invoice_voided', 'Voided in Xero');
                $voided++;
                continue;
            }
            foreach ($xi['Payments'] ?? [] as $xp) {
                $pid = $xp['PaymentID'] ?? '';
                $amount = round((float) ($xp['Amount'] ?? 0), 2);
                if ($pid === '' || $amount <= 0) {
                    continue;
                }
                $stmt = $mysqli->prepare("SELECT id FROM enxure_payments WHERE xero_payment_id = ? OR (provider = 'xero' AND provider_ref = ?) LIMIT 1");
                $stmt->bind_param("ss", $pid, $pid);
                $stmt->execute();
                if ($stmt->get_result()->fetch_assoc()) {
                    continue;
                }
                $res = recordInvoicePayment($mysqli, $settings, (int) $local['id'], $amount, trim((string) ($xp['Reference'] ?? '')) ?: 'Recorded in Xero', 'xero', $pid);
                if (!empty($res['success']) && empty($res['duplicate'])) {
                    $paymentsAdded++;
                    $date = enxureXeroDate($xp['DateString'] ?? null) . ' 00:00:00';
                    $up = $mysqli->prepare("UPDATE enxure_payments SET paid_at = ?, xero_payment_id = ? WHERE provider = 'xero' AND provider_ref = ?");
                    $up->bind_param("sss", $date, $pid, $pid);
                    $up->execute();
                    $up = $mysqli->prepare("UPDATE enxure_invoices SET paid_at = ? WHERE id = ? AND status = 'paid'");
                    $up->bind_param("si", $date, $local['id']);
                    $up->execute();
                }
            }
        }
    }
    return ['payments' => $paymentsAdded, 'voided' => $voided];
}

function enxureXeroSync($mysqli, array $settings): array
{
    if (!enxureXeroActive($mysqli, $settings)) {
        return ['success' => false, 'error' => 'Xero is not connected, or this install has no valid license.'];
    }
    $cfg = enxureXeroCfg($mysqli);
    $errors = [];

    $contacts = enxureXeroPullContacts($mysqli, $cfg);
    if (!empty($contacts['error'])) {
        $errors[] = $contacts['error'];
    }

    $pushed = 0;
    $pending = $mysqli->query("SELECT id FROM enxure_invoices WHERE xero_invoice_id IS NULL AND is_quote = 0 AND status NOT IN ('draft', 'void') AND client_key IN (SELECT client_key FROM enxure_clients WHERE is_test = 0) ORDER BY invoice_date ASC LIMIT " . ENXURE_XERO_BATCH_LIMIT)->fetch_all(MYSQLI_ASSOC);
    foreach ($pending as $p) {
        $r = enxureXeroPushInvoice($mysqli, $settings, (int) $p['id']);
        if (!empty($r['success'])) {
            $pushed++;
        } elseif (!empty($r['error'])) {
            $errors[] = $r['error'];
            if (str_contains($r['error'], 'not connected')) {
                break;
            }
        }
    }

    $unsyncedPayments = 0;
    $paid = $mysqli->query("SELECT DISTINCT i.* FROM enxure_invoices i JOIN enxure_payments p ON p.invoice_id = i.id WHERE i.xero_invoice_id IS NOT NULL AND p.provider <> 'xero' AND p.xero_payment_id IS NULL AND p.amount > 0 LIMIT " . ENXURE_XERO_BATCH_LIMIT)->fetch_all(MYSQLI_ASSOC);
    foreach ($paid as $inv) {
        $unsyncedPayments += enxureXeroPushPayments($mysqli, $settings, $inv);
    }

    $pull = enxureXeroPullInvoices($mysqli, $settings);
    if (!empty($pull['error'])) {
        $errors[] = $pull['error'];
    }

    $remaining = (int) $mysqli->query("SELECT COUNT(*) c FROM enxure_invoices WHERE xero_invoice_id IS NULL AND is_quote = 0 AND status NOT IN ('draft', 'void') AND client_key IN (SELECT client_key FROM enxure_clients WHERE is_test = 0)")->fetch_assoc()['c'];
    enxureXeroSet($mysqli, ['xero_last_sync' => time(), 'xero_last_error' => $errors ? implode(' | ', array_slice($errors, 0, 3)) : '']);
    $summary = [
        'success' => !$errors,
        'invoices_pushed' => $pushed,
        'payments_pushed' => $unsyncedPayments,
        'payments_pulled' => $pull['payments'] ?? 0,
        'voided' => $pull['voided'] ?? 0,
        'contacts_linked' => $contacts['linked'] ?? 0,
        'contacts_created' => $contacts['created'] ?? 0,
        'invoices_remaining' => $remaining,
    ];
    if ($errors) {
        $summary['error'] = implode(' | ', array_slice($errors, 0, 3));
    }
    return $summary;
}

function enxureHandleSaveXeroSettings($mysqli): void
{
    $cfg = enxureXeroCfg($mysqli);
    $secret = trim($_POST['xero_client_secret'] ?? '');
    enxureXeroSet($mysqli, [
        'xero_client_id' => trim($_POST['xero_client_id'] ?? ''),
        'xero_client_secret' => $secret !== '' ? $secret : ($cfg['xero_client_secret'] ?? ''),
        'xero_sales_account' => trim($_POST['xero_sales_account'] ?? ''),
        'xero_bank_account' => trim($_POST['xero_bank_account'] ?? ''),
        'xero_tax_type' => trim($_POST['xero_tax_type'] ?? ''),
        'xero_import_contacts' => ($_POST['xero_import_contacts'] ?? '0') === '1' ? '1' : '0',
        'xero_enabled' => ($_POST['xero_enabled'] ?? '0') === '1' ? '1' : '0',
    ]);
    echo json_encode(['success' => true]);
    exit;
}

function enxureHandleXeroSync($mysqli, array $settings): void
{
    echo json_encode(enxureXeroSync($mysqli, $settings));
    exit;
}

function enxureHandleXeroDisconnect($mysqli): void
{
    $cfg = enxureXeroCfg($mysqli);
    enxureXeroSet($mysqli, [
        'xero_access_token' => '',
        'xero_refresh_token' => '',
        'xero_token_expires' => '0',
        'xero_tenant_id' => '',
        'xero_tenant_name' => '',
        'xero_enabled' => '0',
        'xero_contacts_since' => '0',
    ]);
    if (!empty($cfg['xero_tenant_id'])) {
        enxureLogAction($mysqli, null, '', 'xero_disconnected', 'Disconnected from Xero');
    }
    echo json_encode(['success' => true]);
    exit;
}

function enxureXeroTry(callable $fn)
{
    try {
        return $fn();
    } catch (Throwable $e) {
        return null;
    }
}
