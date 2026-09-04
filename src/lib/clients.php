<?php
// $mysqli-touching client logic — CRUD, CSV import, portal tokens, the CRM
// drawer, and the Clients table renderer.

// Deterministic per-client avatar color so the same client always gets the
// same badge color across page loads, rather than a random one each render.
function clientAvatarColor(int $id): string
{
    $palette = ['#f43f5e', '#f59e0b', '#84cc16', '#10b981', '#06b6d4', '#3b82f6', '#8b5cf6', '#ec4899'];
    return $palette[$id % count($palette)];
}

function clientInitials(string $name): string
{
    $parts = array_filter(preg_split('/\s+/', trim($name)));
    if (count($parts) === 0) {
        return '?';
    }
    if (count($parts) === 1) {
        return strtoupper(substr(reset($parts), 0, 2));
    }
    return strtoupper(substr(reset($parts), 0, 1) . substr(end($parts), 0, 1));
}

// Same idea for the Clients table.
function renderClientRows(array $clients): string
{
    global $settings;
    ob_start();
    foreach ($clients as $c):
        $rowCcy = enxureResolveCurrency($c['currency'] ?? '', $settings);
        ?>
        <tr style="cursor:pointer;"
            onclick="openCrm(<?= htmlspecialchars(json_encode(['id' => $c['id'], 'client_name' => $c['client_name'], 'crm_notes' => $c['crm_notes'] ?? ''])) ?>)">
            <td onclick="event.stopPropagation()"><input type="checkbox" class="client-select-cb" value="<?= $c['id'] ?>" onchange="updateClientBulkBar()"></td>
            <td>
                <div style="display:flex; align-items:center; gap:0.6rem;">
                    <span class="client-avatar" style="background:<?= clientAvatarColor((int) $c['id']) ?>;"><?= htmlspecialchars(clientInitials($c['client_name'])) ?></span>
                    <strong><?= htmlspecialchars($c['client_name']) ?></strong>
                </div>
            </td>
            <td><?= htmlspecialchars($c['email']) ?></td>
            <td><?= htmlspecialchars($rowCcy) ?> $<?= number_format($c['monthly_rate'], 2) ?>
                <div style="color:var(--text-secondary); font-size:0.75rem; text-transform:capitalize;">
                    <?= htmlspecialchars($c['billing_frequency'] ?? 'monthly') ?></div>
            </td>
            <td style="white-space:nowrap; text-align:center;">
                <?php if ($c['is_active']): ?>
                    <i class="fa-solid fa-circle"
                        style="color: var(--success); font-size: 0.85rem; margin-right:4px;"
                        title="Active"></i>
                <?php else: ?>
                    <i class="fa-solid fa-circle"
                        style="color: var(--danger); font-size: 0.85rem; margin-right:4px;"
                        title="Inactive"></i>
                <?php endif; ?>
                <?php if ($c['is_test']): ?>
                    <i class="fa-solid fa-flask" style="color: var(--warning); font-size: 0.85rem;"
                        title="Test Client"></i>
                <?php endif; ?>
            </td>
            <td><?= $c['inv_count'] ?></td>
            <td><?= htmlspecialchars($rowCcy) ?> $<?= number_format($c['total_billed'] ?? 0, 2) ?></td>
            <td style="color: var(--success);"><?= htmlspecialchars($rowCcy) ?> $<?= number_format($c['total_paid'] ?? 0, 2) ?></td>
            <td
                style="color: <?= (($c['total_billed'] - $c['total_paid']) > 0) ? 'var(--warning)' : 'inherit' ?>">
                <?= htmlspecialchars($rowCcy) ?> $<?= number_format(max(0, $c['total_billed'] - $c['total_paid']), 2) ?></td>
            <td style="white-space: nowrap;">
                <button class="btn small"
                    onclick="event.stopPropagation(); openClientModal(<?= htmlspecialchars(json_encode($c)) ?>)"><i
                        class="fa-solid fa-pen"></i></button>
                <button class="btn small danger"
                    onclick="event.stopPropagation(); deleteClient(<?= $c['id'] ?>)"><i
                        class="fa-solid fa-trash"></i></button>
            </td>
        </tr>
    <?php endforeach;
    return ob_get_clean();
}

// Label + formatter for each editable enxure_clients column, in the order
// they should appear in a diff — used by enxureHandleSaveClient() to turn an
// old-row/new-values pair into the "Field: old → new" notes stored on the
// client_updated audit entry.
const ENXURE_CLIENT_DIFF_FIELDS = [
    'client_name' => ['Name', null],
    'email' => ['Email', null],
    'phone' => ['Phone', null],
    'address' => ['Address', null],
    'account_name' => ['Account name', null],
    'account_number' => ['Account number', null],
    'monthly_rate' => ['Monthly rate', 'money'],
    'payment_terms_days' => ['Payment terms (days)', null],
    'billing_frequency' => ['Billing frequency', null],
    'discount_pct' => ['Discount %', 'money'],
    'tax_rate' => ['Tax rate %', 'money'],
    'currency' => ['Currency', null],
    'is_active' => ['Active', 'bool'],
    'is_test' => ['Test client', 'bool'],
];

function enxureFormatClientDiffValue($value, ?string $kind): string
{
    if ($kind === 'money') {
        return number_format((float) $value, 2);
    }
    if ($kind === 'bool') {
        return ((int) $value) ? 'Yes' : 'No';
    }
    $value = trim((string) $value);
    return $value === '' ? '(empty)' : $value;
}

// Compares $old (a row fetched before the write) against $new (the
// post-write values, keyed the same way) and returns one "Field: a → b"
// string per changed column, skipping anything untouched.
function enxureClientFieldDiffs(array $old, array $new): array
{
    $diffs = [];
    foreach (ENXURE_CLIENT_DIFF_FIELDS as $field => [$label, $kind]) {
        $oldVal = $old[$field] ?? '';
        $newVal = $new[$field] ?? '';
        $changed = $kind === 'money'
            ? abs((float) $oldVal - (float) $newVal) > 0.001
            : (string) $oldVal !== (string) $newVal;
        if ($changed) {
            $diffs[] = $label . ': ' . enxureFormatClientDiffValue($oldVal, $kind) . ' → ' . enxureFormatClientDiffValue($newVal, $kind);
        }
    }
    return $diffs;
}

function enxureHandleSaveClient($mysqli): void
{
$id = (int) ($_POST['id'] ?? 0);
$name = trim($_POST['client_name'] ?? '');
if ($name === '') {
    throw new Exception('Client name is required.');
}
$key = strtolower(substr(preg_replace('/[^a-zA-Z0-9]/', '', $name), 0, 3));
if (!$key)
    $key = substr(md5(time()), 0, 3);
$email = $_POST['email'];
$phone = $_POST['phone'] ?? '';
$address = $_POST['address'] ?? '';
$aname = $_POST['account_name'];
$anum = $_POST['account_number'];
$rate = (float) $_POST['monthly_rate'];
$terms = (int) ($_POST['payment_terms_days'] ?? 21);
if ($terms < 1)
    $terms = 21;
$freq = in_array($_POST['billing_frequency'] ?? '', ['weekly', 'monthly', 'quarterly', 'annually'], true)
    ? $_POST['billing_frequency'] : 'monthly';
// Clamped 0-100, same as the adhoc invoice discount/tax inputs.
$discountPct = max(0, min(100, (float) ($_POST['discount_pct'] ?? 0)));
$taxRate = max(0, min(100, (float) ($_POST['tax_rate'] ?? 0)));
$currency = enxureNormalizeCurrencyCode($_POST['currency'] ?? '');
$act = (int) ($_POST['is_active'] ?? 0);
$test = (int) ($_POST['is_test'] ?? 0);
$newValues = ['client_name' => $name, 'email' => $email, 'phone' => $phone, 'address' => $address, 'account_name' => $aname, 'account_number' => $anum, 'monthly_rate' => $rate, 'payment_terms_days' => $terms, 'billing_frequency' => $freq, 'discount_pct' => $discountPct, 'tax_rate' => $taxRate, 'currency' => $currency, 'is_active' => $act, 'is_test' => $test];
if ($id > 0) {
    $oldRow = $mysqli->prepare("SELECT * FROM enxure_clients WHERE id = ?");
    $oldRow->bind_param("i", $id);
    $oldRow->execute();
    $oldRow = $oldRow->get_result()->fetch_assoc();
    $stmt = $mysqli->prepare("UPDATE enxure_clients SET client_name=?, email=?, phone=?, address=?, account_name=?, account_number=?, monthly_rate=?, payment_terms_days=?, billing_frequency=?, discount_pct=?, tax_rate=?, currency=?, is_active=?, is_test=? WHERE id=?");
    $stmt->bind_param("ssssssdisddsiii", $name, $email, $phone, $address, $aname, $anum, $rate, $terms, $freq, $discountPct, $taxRate, $currency, $act, $test, $id);
    $stmt->execute();
    if ($oldRow) {
        $diffs = enxureClientFieldDiffs($oldRow, $newValues);
        if ($diffs) {
            enxureLogAction($mysqli, null, '', 'client_updated', $name . ' — ' . implode('; ', $diffs));
        }
    }
} else {
    $stmt = $mysqli->prepare("INSERT INTO enxure_clients (client_name, email, phone, address, account_name, account_number, monthly_rate, payment_terms_days, billing_frequency, discount_pct, tax_rate, currency, is_active, is_test, client_key) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ssssssdisddsiis", $name, $email, $phone, $address, $aname, $anum, $rate, $terms, $freq, $discountPct, $taxRate, $currency, $act, $test, $key);
    $stmt->execute();
    enxureLogAction($mysqli, null, '', 'client_created', $name . ' — ' . implode('; ', enxureClientFieldDiffs(array_fill_keys(array_keys(ENXURE_CLIENT_DIFF_FIELDS), ''), $newValues)));
}
echo json_encode(['success' => true]);
exit;
}

function enxureHandleDeleteClient($mysqli): void
{
$stmt = $mysqli->prepare("DELETE FROM enxure_clients WHERE id=?");
$stmt->bind_param("i", $_POST['id']);
$stmt->execute();
echo json_encode(['success' => true]);
exit;
}

function enxureHandleUpdateClientFlags($mysqli): void
{
$id = (int) ($_POST['id'] ?? 0);
$field = $_POST['field'] ?? '';
if (!in_array($field, ['is_active', 'is_test'], true)) {
    echo json_encode(['success' => false, 'error' => 'Invalid field']);
    exit;
}
$value = (int) ($_POST['value'] ?? 0);
$before = $mysqli->prepare("SELECT client_name, $field AS current_value FROM enxure_clients WHERE id = ?");
$before->bind_param("i", $id);
$before->execute();
$before = $before->get_result()->fetch_assoc();
$stmt = $mysqli->prepare("UPDATE enxure_clients SET $field = ? WHERE id = ?");
$stmt->bind_param("ii", $value, $id);
$stmt->execute();
if ($before && (int) $before['current_value'] !== $value) {
    [$label] = ENXURE_CLIENT_DIFF_FIELDS[$field];
    enxureLogAction($mysqli, null, '', 'client_updated', $before['client_name'] . ' — ' . $label . ': ' . enxureFormatClientDiffValue($before['current_value'], 'bool') . ' → ' . enxureFormatClientDiffValue($value, 'bool'));
}
echo json_encode(['success' => true]);
exit;
}

function enxureHandleGeneratePortalToken($mysqli): void
{
// Regenerating invalidates the old link (one-token-per-client column,
// just overwritten); the old URL then shows "Link not found".
$id = (int) ($_POST['id'] ?? 0);
$token = bin2hex(random_bytes(24));
$expiryDays = ['never' => null, '30' => 30, '90' => 90, '365' => 365][$_POST['expiry'] ?? 'never'] ?? null;
if ($expiryDays === null) {
    $stmt = $mysqli->prepare("UPDATE enxure_clients SET portal_token = ?, portal_token_expires_at = NULL WHERE id = ?");
    $stmt->bind_param("si", $token, $id);
} else {
    $stmt = $mysqli->prepare("UPDATE enxure_clients SET portal_token = ?, portal_token_expires_at = DATE_ADD(NOW(), INTERVAL ? DAY) WHERE id = ?");
    $stmt->bind_param("sii", $token, $expiryDays, $id);
}
$stmt->execute();
echo json_encode(['success' => true, 'token' => $token]);
exit;
}

function enxureHandleRevokePortalToken($mysqli): void
{
$id = (int) ($_POST['id'] ?? 0);
$stmt = $mysqli->prepare("UPDATE enxure_clients SET portal_token = NULL, portal_token_expires_at = NULL WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
echo json_encode(['success' => true]);
exit;
}

function enxureHandleImportClientsCsv($mysqli): void
{
if (!isset($_FILES['clients_file']) || $_FILES['clients_file']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'error' => 'No file uploaded, or the upload failed.']);
    exit;
}
if (!is_uploaded_file($_FILES['clients_file']['tmp_name'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid upload.']);
    exit;
}
$fh = fopen($_FILES['clients_file']['tmp_name'], 'r');
if ($fh === false) {
    echo json_encode(['success' => false, 'error' => 'Failed to read the uploaded file.']);
    exit;
}
echo json_encode(array_merge(['success' => true], enxureImportClientsCsvRows($mysqli, $fh)));
exit;
}

// Expects a CSV with header row: Client Name, Email, Rate, Billing
// Frequency, Account Name, Account Number, Payment Terms Days, Phone,
// Address — the Add Client fields, not the richer "Export Clients" CSV
// format. Phone/Address are trailing and optional so CSVs written
// before those fields existed still import cleanly.
function enxureImportClientsCsvRows($mysqli, $fh): array
{
$existingKeys = [];
$keyRes = $mysqli->query("SELECT client_key FROM enxure_clients");
while ($kr = $keyRes->fetch_assoc())
    $existingKeys[$kr['client_key']] = true;

$insert = $mysqli->prepare("INSERT INTO enxure_clients (client_name, email, phone, address, account_name, account_number, monthly_rate, payment_terms_days, billing_frequency, is_active, is_test, client_key) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 0, ?)");
$imported = 0;
$skipped = 0;
$rowNum = 0;
$errors = [];
while (($row = fgetcsv($fh, 0, ',', '"', "\\")) !== false) {
    $rowNum++;
    if ($rowNum === 1) {
        // Header row — skip unconditionally rather than sniffing, since
        // a real client name could otherwise be misdetected as one.
        continue;
    }
    $name = trim($row[0] ?? '');
    if ($name === '') {
        $skipped++;
        continue;
    }
    $email = trim($row[1] ?? '');
    $rate = enxureParseAmount($row[2] ?? '0');
    $freq = in_array(strtolower(trim($row[3] ?? '')), ['weekly', 'monthly', 'quarterly', 'annually'], true)
        ? strtolower(trim($row[3])) : 'monthly';
    $aname = trim($row[4] ?? '');
    $anum = trim($row[5] ?? '');
    $terms = (int) ($row[6] ?? 21);
    if ($terms < 1)
        $terms = 21;
    $phone = trim($row[7] ?? '');
    $address = trim($row[8] ?? '');

    $key = strtolower(substr(preg_replace('/[^a-zA-Z0-9]/', '', $name), 0, 3));
    if (!$key)
        $key = substr(md5($name . $rowNum), 0, 3);
    $baseKey = $key;
    $suffix = 2;
    while (isset($existingKeys[$key])) {
        $key = substr($baseKey, 0, 2) . $suffix;
        $suffix++;
        if ($suffix > 9) {
            $key = substr(md5($name . $rowNum . $suffix), 0, 3);
            break;
        }
    }
    $existingKeys[$key] = true;

    $insert->bind_param("ssssssdiss", $name, $email, $phone, $address, $aname, $anum, $rate, $terms, $freq, $key);
    if ($insert->execute()) {
        $imported++;
    } else {
        $skipped++;
        if (count($errors) < 10)
            $errors[] = "Row $rowNum ($name): " . $mysqli->error;
    }
}
fclose($fh);
return ['imported' => $imported, 'skipped' => $skipped, 'errors' => $errors];
}

function enxureHandleGetCrmData($mysqli): void
{
$clientId = (int) ($_POST['client_id'] ?? 0);
$stats = $mysqli->query("SELECT SUM(amount) as total_billed, SUM(CASE WHEN status='paid' THEN amount ELSE 0 END) as total_paid, COUNT(*) as inv_count FROM enxure_invoices WHERE client_key = (SELECT client_key FROM enxure_clients WHERE id = $clientId) AND is_quote = 0")->fetch_assoc();
$recent = [];
$rRes = $mysqli->query("SELECT invoice_number, invoice_date, amount, status FROM enxure_invoices WHERE client_key = (SELECT client_key FROM enxure_clients WHERE id = $clientId) AND is_quote = 0 ORDER BY invoice_date DESC LIMIT 5");
while ($r = $rRes->fetch_assoc())
    $recent[] = $r;
$clientRow = $mysqli->query("SELECT crm_notes FROM enxure_clients WHERE id = $clientId")->fetch_assoc();
echo json_encode(['success' => true, 'stats' => $stats, 'recent' => $recent, 'crm_notes' => $clientRow['crm_notes'] ?? '']);
exit;
}

function enxureHandleSaveCrmNotes($mysqli): void
{
$clientId = (int) ($_POST['client_id'] ?? 0);
$notes = $_POST['notes'] ?? '';
$stmt = $mysqli->prepare("UPDATE enxure_clients SET crm_notes = ? WHERE id = ?");
$stmt->bind_param("si", $notes, $clientId);
$stmt->execute();
echo json_encode(['success' => true]);
exit;
}
