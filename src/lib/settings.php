<?php

function enxureHandleSaveLicenseKey($mysqli, array $settings): void
{
    $key = trim($_POST['license_key'] ?? '');
    $stmt = $mysqli->prepare("INSERT INTO enxure_settings (setting_key, setting_value) VALUES ('license_key', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    $stmt->bind_param("s", $key);
    $stmt->execute();
    $reason = null;
    $valid = licenseIsValid($mysqli, array_merge($settings, ['license_key' => $key]), false, $reason);
    echo json_encode(['success' => true, 'valid' => $valid, 'reason' => $reason]);
    exit;
}

// Sends the "SMTP Test" message — Settings > Email's Send Test Email button
// and the Email Delivery test group both go through this. Logs 'smtp_test'
// either way; callers decide how to surface the result.
function enxureSendTestEmail($mysqli, array $settings, string $emailPassword, string $to): array
{
    require_once PHPMAILER_DIR . 'PHPMailer.php';
    require_once PHPMAILER_DIR . 'SMTP.php';
    require_once PHPMAILER_DIR . 'Exception.php';
    $fromName = $settings['business_name'] ?? (getenv('SMTP_FROM_NAME') ?: 'enXure');
    $fromEmail = getenv('SMTP_FROM_EMAIL') ?: '';
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
        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($to);
        $mail->Subject = "SMTP Test - {$fromName}";
        $mail->Body = "This is a test email sent from {$fromName} to verify SMTP configuration.";
        $mail->send();
        enxureLogAction($mysqli, null, '', 'smtp_test', "Test email sent to {$to}");
        return ['sent' => true, 'error' => ''];
    } catch (Exception $e) {
        enxureLogAction($mysqli, null, '', 'smtp_test', "Test email to {$to} failed: " . $e->getMessage());
        return ['sent' => false, 'error' => $e->getMessage()];
    }
}

function enxureHandleTestEmail($mysqli, array $settings, string $emailPassword): void
{
    $result = enxureSendTestEmail($mysqli, $settings, $emailPassword, $_POST['email']);
    if (!$result['sent']) {
        throw new Exception($result['error']);
    }
    echo json_encode(['success' => true]);
    exit;
}

function enxureHandleSaveNotificationSettings($mysqli): void
{
    $channel = in_array($_POST['notification_channel'] ?? 'none', ['none', 'telegram', 'slack', 'webhook'], true)
        ? $_POST['notification_channel'] : 'none';
    $botToken = trim($_POST['telegram_bot_token'] ?? '');
    $chatId = trim($_POST['telegram_chat_id'] ?? '');
    $webhookUrl = trim($_POST['slack_webhook_url'] ?? '');
    $genericWebhookUrl = trim($_POST['webhook_url'] ?? '');
    $webhookFormat = in_array($_POST['webhook_format'] ?? 'json_text', ['json_text', 'plain', 'discord'], true)
        ? $_POST['webhook_format'] : 'json_text';
    $notifyPayment = ($_POST['notify_on_payment'] ?? '0') === '1' ? '1' : '0';
    $notifyOverdue = ($_POST['notify_on_overdue'] ?? '0') === '1' ? '1' : '0';
    $notifyQuoteAccepted = ($_POST['notify_on_quote_accepted'] ?? '0') === '1' ? '1' : '0';
    $notifyEmailFailed = ($_POST['notify_on_email_failed'] ?? '0') === '1' ? '1' : '0';
    $notifyLateFee = ($_POST['notify_on_late_fee'] ?? '0') === '1' ? '1' : '0';
    $notifyInvoiceVoided = ($_POST['notify_on_invoice_voided'] ?? '0') === '1' ? '1' : '0';
    $notifyWebhookUnmatched = ($_POST['notify_on_webhook_unmatched'] ?? '0') === '1' ? '1' : '0';
    $notifyRefund = ($_POST['notify_on_refund'] ?? '0') === '1' ? '1' : '0';
    $notifyRecurringRun = ($_POST['notify_on_recurring_run'] ?? '0') === '1' ? '1' : '0';
    $notifyRecurringErrors = ($_POST['notify_on_recurring_errors'] ?? '0') === '1' ? '1' : '0';
    $notifySecurityEvent = ($_POST['notify_on_security_event'] ?? '0') === '1' ? '1' : '0';
    $upsert = $mysqli->prepare("INSERT INTO enxure_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    foreach ([
        'notification_channel' => $channel,
        'telegram_bot_token' => $botToken,
        'telegram_chat_id' => $chatId,
        'slack_webhook_url' => $webhookUrl,
        'webhook_url' => $genericWebhookUrl,
        'webhook_format' => $webhookFormat,
        'notify_on_payment' => $notifyPayment,
        'notify_on_overdue' => $notifyOverdue,
        'notify_on_quote_accepted' => $notifyQuoteAccepted,
        'notify_on_email_failed' => $notifyEmailFailed,
        'notify_on_late_fee' => $notifyLateFee,
        'notify_on_invoice_voided' => $notifyInvoiceVoided,
        'notify_on_webhook_unmatched' => $notifyWebhookUnmatched,
        'notify_on_refund' => $notifyRefund,
        'notify_on_recurring_run' => $notifyRecurringRun,
        'notify_on_recurring_errors' => $notifyRecurringErrors,
        'notify_on_security_event' => $notifySecurityEvent,
    ] as $key => $value) {
        $upsert->bind_param("ss", $key, $value);
        $upsert->execute();
    }
    echo json_encode(['success' => true]);
    exit;
}

function enxureHandleTestNotification($mysqli, array $settings): void
{
    $channel = in_array($_POST['notification_channel'] ?? 'none', ['telegram', 'slack', 'webhook'], true)
        ? $_POST['notification_channel'] : null;
    $fromName = $settings['business_name'] ?? 'enXure';
    $message = "\xF0\x9F\x94\x94 Test notification from {$fromName}.";
    if ($channel === 'slack') {
        $result = sendSlackNotification(trim($_POST['slack_webhook_url'] ?? ''), $message);
    } elseif ($channel === 'telegram') {
        $result = sendTelegramNotification(trim($_POST['telegram_bot_token'] ?? ''), trim($_POST['telegram_chat_id'] ?? ''), $message);
    } elseif ($channel === 'webhook') {
        $webhookFormat = in_array($_POST['webhook_format'] ?? 'json_text', ['json_text', 'plain', 'discord'], true)
            ? $_POST['webhook_format'] : 'json_text';
        $result = sendWebhookNotification(trim($_POST['webhook_url'] ?? ''), $message, $webhookFormat);
    } else {
        $result = ['success' => false, 'error' => 'Choose a notification channel first'];
    }
    $logNotes = $result['success'] ? ucfirst($channel ?? '') . ' test message sent' : 'Notification test failed: ' . $result['error'];
    $actionType = $result['success'] ? 'notification_test' : 'notification_failed';
    enxureLogAction($mysqli, null, '', $actionType, $logNotes);
    echo json_encode($result);
    exit;
}

function enxureHandleCreateApiToken($mysqli, array $settings): void
{
    $label = trim($_POST['label'] ?? '');
    if ($label === '') {
        throw new Exception('Give this token a label so you can tell it apart later.');
    }
    $expiryDays = ['never' => null, '30' => 30, '90' => 90, '365' => 365][$_POST['expiry'] ?? 'never'] ?? null;
    $created = enxureCreateApiToken($mysqli, $label, $expiryDays);
    enxureLogAction($mysqli, null, '', 'api_token_created', 'API token created: ' . $label);
    notifyChannel($mysqli, $settings, 'notify_on_security_event', "\xF0\x9F\x9B\xA1\xEF\xB8\x8F API token created: {$label}");
    echo json_encode(['success' => true, 'token' => $created['token']]);
    exit;
}

function enxureHandleRenewApiToken($mysqli): void
{
    $id = (int) ($_POST['id'] ?? 0);
    $expiryDays = ['never' => null, '30' => 30, '90' => 90, '365' => 365][$_POST['expiry'] ?? 'never'] ?? null;
    if ($expiryDays === null) {
        $stmt = $mysqli->prepare("UPDATE enxure_api_tokens SET expires_at = NULL WHERE id = ? AND revoked_at IS NULL");
        $stmt->bind_param("i", $id);
    } else {
        $stmt = $mysqli->prepare("UPDATE enxure_api_tokens SET expires_at = DATE_ADD(NOW(), INTERVAL ? DAY) WHERE id = ? AND revoked_at IS NULL");
        $stmt->bind_param("ii", $expiryDays, $id);
    }
    $stmt->execute();
    echo json_encode(['success' => true]);
    exit;
}

function enxureHandleRevokeApiToken($mysqli, array $settings): void
{
    $id = (int) ($_POST['id'] ?? 0);
    $label = $mysqli->query("SELECT label FROM enxure_api_tokens WHERE id = " . $id)->fetch_assoc()['label'] ?? '';
    $stmt = $mysqli->prepare("UPDATE enxure_api_tokens SET revoked_at = NOW() WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    enxureLogAction($mysqli, null, '', 'api_token_revoked', 'API token revoked: ' . $label);
    notifyChannel($mysqli, $settings, 'notify_on_security_event', "\xF0\x9F\x9B\xA1\xEF\xB8\x8F API token revoked: {$label}");
    echo json_encode(['success' => true]);
    exit;
}

function enxureHandleDeleteApiToken($mysqli): void
{
    $id = (int) ($_POST['id'] ?? 0);
    $row = $mysqli->query("SELECT label, revoked_at, expires_at FROM enxure_api_tokens WHERE id = " . $id)->fetch_assoc();
    if (!$row) {
        throw new Exception('Token not found');
    }
    $isExpired = !empty($row['expires_at']) && strtotime($row['expires_at']) < time();
    if (empty($row['revoked_at']) && !$isExpired) {
        throw new Exception('Revoke this token before deleting it.');
    }
    $stmt = $mysqli->prepare("DELETE FROM enxure_api_tokens WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    enxureLogAction($mysqli, null, '', 'api_token_revoked', 'API token permanently deleted: ' . $row['label']);
    echo json_encode(['success' => true]);
    exit;
}

function enxureHandleUpdateProfile($mysqli, int $currentUserId): void
{
    $newUsername = trim($_POST['new_username'] ?? '');
    $newEmail = trim($_POST['new_email'] ?? '');
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $userRes = $mysqli->query("SELECT * FROM enxure_users WHERE id = " . $currentUserId);
    if (!$userRes || $userRes->num_rows === 0) {
        throw new Exception('User not found');
    }
    $user = $userRes->fetch_assoc();
    if (!password_verify($currentPassword, $user['password_hash'])) {
        throw new Exception('Current password is incorrect');
    }
    if ($newPassword !== '' && $newPassword !== $confirmPassword) {
        throw new Exception('New passwords do not match');
    }
    if ($newPassword !== '' && strlen($newPassword) < PASSWORD_MIN_LENGTH) {
        throw new Exception('Password must be at least ' . PASSWORD_MIN_LENGTH . ' characters');
    }
    if ($newEmail !== '' && !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Enter a valid email address');
    }
    $fields = [];
    $params = [];
    $types = '';
    if ($newUsername !== '' && $newUsername !== $user['username']) {
        $fields[] = 'username = ?';
        $params[] = $newUsername;
        $types .= 's';
    }
    $emailChanged = $newEmail !== '' && $newEmail !== ($user['email'] ?? '');
    if ($emailChanged) {
        $fields[] = 'email = ?';
        $params[] = $newEmail;
        $types .= 's';
        $fields[] = 'email_verified_at = NULL';
    }
    if ($newPassword !== '') {
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $fields[] = 'password_hash = ?';
        $params[] = $hash;
        $types .= 's';
    }
    if (count($fields) > 0) {
        $stmt = $mysqli->prepare('UPDATE enxure_users SET ' . implode(', ', $fields) . ' WHERE id = ?');
        $params[] = $user['id'];
        $types .= 'i';
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
    }
    if ($emailChanged) {
        enxureIssueEmailVerification($mysqli, (int) $user['id'], $newUsername !== '' ? $newUsername : $user['username'], $newEmail);
    }
    echo json_encode(['success' => true]);
    exit;
}

function enxureHandleTotpSetupInit($mysqli, int $currentUserId): void
{
    $userRow = $mysqli->query("SELECT id, username FROM enxure_users WHERE id = " . $currentUserId)->fetch_assoc();
    if (!$userRow) {
        throw new Exception('Account not found');
    }
    $secret = generateTotpSecret();
    $stmt = $mysqli->prepare("UPDATE enxure_users SET totp_secret_pending = ? WHERE id = ?");
    $stmt->bind_param("si", $secret, $userRow['id']);
    $stmt->execute();
    echo json_encode(['success' => true, 'secret' => $secret, 'account_label' => $userRow['username'], 'otpauth_uri' => totpOtpauthUri($secret, $userRow['username'])]);
    exit;
}

function enxureHandleTotpSetupConfirm($mysqli, int $currentUserId, array $settings): void
{
    $userRow = $mysqli->query("SELECT id, totp_secret_pending FROM enxure_users WHERE id = " . $currentUserId)->fetch_assoc();
    if (!$userRow || empty($userRow['totp_secret_pending'])) {
        throw new Exception('No setup in progress — click Enable Two-Factor Authentication to start again.');
    }
    if (!verifyTotpCode($userRow['totp_secret_pending'], $_POST['code'] ?? '')) {
        throw new Exception('Invalid code. Check the time on your device and try again.');
    }
    $stmt = $mysqli->prepare("UPDATE enxure_users SET totp_secret = totp_secret_pending, totp_secret_pending = NULL WHERE id = ?");
    $stmt->bind_param("i", $userRow['id']);
    $stmt->execute();
    $backupCodes = enxureIssueBackupCodes($mysqli, (int) $userRow['id']);
    enxureLogAction($mysqli, null, '', 'totp_enabled', 'Two-factor authentication enabled');
    notifyChannel($mysqli, $settings, 'notify_on_security_event', "\xF0\x9F\x9B\xA1\xEF\xB8\x8F Two-factor authentication enabled");
    echo json_encode(['success' => true, 'backup_codes' => $backupCodes]);
    exit;
}

function enxureHandleTotpRegenerateBackupCodes($mysqli, int $currentUserId, array $settings): void
{
    $userRow = $mysqli->query("SELECT id, password_hash, totp_secret FROM enxure_users WHERE id = " . $currentUserId)->fetch_assoc();
    if (!$userRow || empty($userRow['totp_secret'])) {
        throw new Exception('Two-factor authentication is not enabled.');
    }
    if (!password_verify($_POST['current_password'] ?? '', $userRow['password_hash'])) {
        throw new Exception('Current password is incorrect.');
    }
    $backupCodes = enxureIssueBackupCodes($mysqli, (int) $userRow['id']);
    enxureLogAction($mysqli, null, '', 'totp_enabled', 'Backup codes regenerated — previous codes no longer work');
    notifyChannel($mysqli, $settings, 'notify_on_security_event', "\xF0\x9F\x9B\xA1\xEF\xB8\x8F Two-factor authentication backup codes regenerated — previous codes no longer work");
    echo json_encode(['success' => true, 'backup_codes' => $backupCodes]);
    exit;
}

function enxureHandleTotpDisable($mysqli, int $currentUserId, array $settings): void
{
    $userRow = $mysqli->query("SELECT id, password_hash FROM enxure_users WHERE id = " . $currentUserId)->fetch_assoc();
    if (!$userRow || !password_verify($_POST['current_password'] ?? '', $userRow['password_hash'])) {
        throw new Exception('Current password is incorrect.');
    }
    $stmt = $mysqli->prepare("UPDATE enxure_users SET totp_secret = NULL, totp_secret_pending = NULL WHERE id = ?");
    $stmt->bind_param("i", $userRow['id']);
    $stmt->execute();
    $mysqli->query("DELETE FROM enxure_totp_backup_codes WHERE user_id = " . (int) $userRow['id']);
    enxureLogAction($mysqli, null, '', 'totp_disabled', 'Two-factor authentication disabled');
    notifyChannel($mysqli, $settings, 'notify_on_security_event', "\xF0\x9F\x9B\xA1\xEF\xB8\x8F Two-factor authentication disabled");
    echo json_encode(['success' => true]);
    exit;
}

function enxureHandleCreateUser($mysqli, array $settings): void
{
    $newUsername = trim($_POST['username'] ?? '');
    $newEmail = trim($_POST['email'] ?? '');
    $newPassword = $_POST['password'] ?? '';
    $newRole = ($_POST['role'] ?? 'member') === 'admin' ? 'admin' : 'member';
    if ($newUsername === '' || $newEmail === '' || $newPassword === '') {
        throw new Exception('Username, email, and password are all required.');
    }
    if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Enter a valid email address.');
    }
    if (strlen($newPassword) < PASSWORD_MIN_LENGTH) {
        throw new Exception('Password must be at least ' . PASSWORD_MIN_LENGTH . ' characters.');
    }
    $exists = $mysqli->query("SELECT 1 FROM enxure_users WHERE username = '" . $mysqli->real_escape_string($newUsername) . "'")->num_rows > 0;
    if ($exists) {
        throw new Exception('That username is already taken.');
    }
    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
    $stmt = $mysqli->prepare("INSERT INTO enxure_users (username, email, role, password_hash) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("ssss", $newUsername, $newEmail, $newRole, $hash);
    $stmt->execute();
    enxureIssueUserWelcomeEmail($mysqli, (int) $mysqli->insert_id, $newUsername, $newEmail);
    enxureLogAction($mysqli, null, '', 'user_created', "User account created: {$newUsername} ({$newRole})");
    notifyChannel($mysqli, $settings, 'notify_on_security_event', "\xF0\x9F\x9B\xA1\xEF\xB8\x8F User account created: {$newUsername} ({$newRole})");
    echo json_encode(['success' => true]);
    exit;
}

function enxureHandleUpdateUser($mysqli, array $settings): void
{
    $id = (int) ($_POST['id'] ?? 0);
    $newRole = ($_POST['role'] ?? 'member') === 'admin' ? 'admin' : 'member';
    $newPassword = $_POST['new_password'] ?? '';
    $target = $mysqli->query("SELECT id, username, role FROM enxure_users WHERE id = " . $id)->fetch_assoc();
    if (!$target) {
        throw new Exception('User not found.');
    }
    if ($target['role'] === 'admin' && $newRole === 'member') {
        $otherAdmins = (int) $mysqli->query("SELECT COUNT(*) as c FROM enxure_users WHERE role = 'admin' AND id != " . $id)->fetch_assoc()['c'];
        if ($otherAdmins === 0) {
            throw new Exception("Can't demote the last admin — promote someone else first.");
        }
    }
    if ($newPassword !== '' && strlen($newPassword) < PASSWORD_MIN_LENGTH) {
        throw new Exception('Password must be at least ' . PASSWORD_MIN_LENGTH . ' characters.');
    }
    if ($newPassword !== '') {
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmt = $mysqli->prepare("UPDATE enxure_users SET role = ?, password_hash = ? WHERE id = ?");
        $stmt->bind_param("ssi", $newRole, $hash, $id);
    } else {
        $stmt = $mysqli->prepare("UPDATE enxure_users SET role = ? WHERE id = ?");
        $stmt->bind_param("si", $newRole, $id);
    }
    $stmt->execute();
    if ($newRole !== $target['role']) {
        enxureLogAction($mysqli, null, '', 'user_role_changed', "{$target['username']}'s role changed to {$newRole}");
        notifyChannel($mysqli, $settings, 'notify_on_security_event', "\xF0\x9F\x9B\xA1\xEF\xB8\x8F {$target['username']}'s role changed to {$newRole}");
    }
    if ($newPassword !== '') {
        enxureLogAction($mysqli, null, '', 'user_password_reset', "Password reset for {$target['username']} by an admin");
    }
    echo json_encode(['success' => true]);
    exit;
}

function enxureHandleDeleteUser($mysqli, int $currentUserId, array $settings): void
{
    $id = (int) ($_POST['id'] ?? 0);
    if ($id === $currentUserId) {
        throw new Exception("You can't delete your own account — have another admin do it.");
    }
    $target = $mysqli->query("SELECT username, role FROM enxure_users WHERE id = " . $id)->fetch_assoc();
    if (!$target) {
        throw new Exception('User not found.');
    }
    if ($target['role'] === 'admin') {
        $otherAdmins = (int) $mysqli->query("SELECT COUNT(*) as c FROM enxure_users WHERE role = 'admin' AND id != " . $id)->fetch_assoc()['c'];
        if ($otherAdmins === 0) {
            throw new Exception("Can't delete the last admin.");
        }
    }
    $mysqli->query("DELETE FROM enxure_totp_backup_codes WHERE user_id = " . $id);
    $stmt = $mysqli->prepare("DELETE FROM enxure_users WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    enxureLogAction($mysqli, null, '', 'user_deleted', "User account deleted: {$target['username']}");
    notifyChannel($mysqli, $settings, 'notify_on_security_event', "\xF0\x9F\x9B\xA1\xEF\xB8\x8F User account deleted: {$target['username']}");
    echo json_encode(['success' => true]);
    exit;
}

function enxureHandleToggleTestClients($mysqli): void
{
    $val = $_POST['hide'] === '1' ? '1' : '0';
    $mysqli->query("INSERT INTO enxure_settings (setting_key, setting_value) VALUES ('hide_test', '$val') ON DUPLICATE KEY UPDATE setting_value = '$val'");
    echo json_encode(['success' => true]);
    exit;
}

function enxureHandleToggleShowTestOnly($mysqli): void
{
    $val = $_POST['show'] === '1' ? '1' : '0';
    $mysqli->query("INSERT INTO enxure_settings (setting_key, setting_value) VALUES ('show_test_only', '$val') ON DUPLICATE KEY UPDATE setting_value = '$val'");
    echo json_encode(['success' => true]);
    exit;
}

function enxureHandleGetDefaultInvoiceTemplate(): void
{
    echo json_encode(['success' => true, 'template' => defaultCustomInvoiceTemplate()]);
    exit;
}

function enxureHandlePreviewInvoiceTemplate(array $settings, bool $licenseValid): void
{
    $template = in_array($_POST['template'] ?? '', ['compact', 'custom'], true) ? $_POST['template'] : 'detailed';
    $customHtml = $_POST['custom_html'] ?? ($settings['custom_invoice_template'] ?? '');
    echo json_encode(['success' => true, 'html' => enxureSampleInvoiceHtml($template, $customHtml, $settings, $licenseValid)]);
    exit;
}

function enxureHandleSaveInvoiceTemplate($mysqli): void
{
    $invoiceTemplate = in_array($_POST['invoice_template'] ?? '', ['compact', 'custom'], true) ? $_POST['invoice_template'] : 'detailed';
    $customInvoiceTemplate = $_POST['custom_invoice_template'] ?? '';

    $upsert = $mysqli->prepare("INSERT INTO enxure_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    foreach ([
        'invoice_template' => $invoiceTemplate,
        'custom_invoice_template' => $customInvoiceTemplate,
    ] as $key => $value) {
        $upsert->bind_param("ss", $key, $value);
        $upsert->execute();
    }
    echo json_encode(['success' => true]);
    exit;
}

function enxureHandleSaveBusinessIdentity($mysqli, bool $licenseValid): void
{
    $businessName = $_POST['business_name'] ?? '';
    $vatNumber = trim($_POST['vat_number'] ?? '');
    $brandColor = $_POST['brand_color'] ?? '#4a90e2';

    $upsert = $mysqli->prepare("INSERT INTO enxure_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    foreach ([
        'business_name' => $businessName,
        'vat_number' => $vatNumber,
        'brand_color' => $brandColor,
    ] as $key => $value) {
        $upsert->bind_param("ss", $key, $value);
        $upsert->execute();
    }

    if ($licenseValid) {
        $hidePoweredBy = ($_POST['hide_powered_by'] ?? '0') === '1' ? '1' : '0';
        $upsertHidePB = $mysqli->prepare("INSERT INTO enxure_settings (setting_key, setting_value) VALUES ('hide_powered_by', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $upsertHidePB->bind_param("s", $hidePoweredBy);
        $upsertHidePB->execute();
    }

    if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
        if (!is_dir(INVOICES_DIR))
            @mkdir(INVOICES_DIR, 0777, true);
        $ext = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
            $targetPath = INVOICES_DIR . LOGO_FILENAME;
            move_uploaded_file($_FILES['logo']['tmp_name'], $targetPath);
        }
    }
    echo json_encode(['success' => true]);
    exit;
}

function enxureHandleSaveInvoiceDefaults($mysqli): void
{
    $currency = strtoupper(preg_replace('/[^A-Za-z]/', '', $_POST['currency'] ?? '')) ?: 'USD';
    $currency = substr($currency, 0, 3);
    $taxYearStartMonth = (int) ($_POST['tax_year_start_month'] ?? 1);
    if ($taxYearStartMonth < 1 || $taxYearStartMonth > 12)
        $taxYearStartMonth = 1;
    $fxProvider = ($_POST['fx_provider'] ?? '') === 'custom' ? 'custom' : 'frankfurter';
    $fxCustomUrl = trim($_POST['fx_custom_url'] ?? '');
    $fxCustomApiKey = trim($_POST['fx_custom_api_key'] ?? '');

    $upsert = $mysqli->prepare("INSERT INTO enxure_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    foreach ([
        'currency' => $currency,
        'tax_year_start_month' => (string) $taxYearStartMonth,
        'fx_provider' => $fxProvider,
        'fx_custom_url' => $fxCustomUrl,
        'fx_custom_api_key' => $fxCustomApiKey,
    ] as $key => $value) {
        $upsert->bind_param("ss", $key, $value);
        $upsert->execute();
    }
    echo json_encode(['success' => true]);
    exit;
}

function enxureHandleSavePaymentDetails($mysqli): void
{
    $footerText = $_POST['footer_text'] ?? '';
    $defaultAccountName = $_POST['default_account_name'] ?? '';
    $defaultAccountNumber = $_POST['default_account_number'] ?? '';

    $upsert = $mysqli->prepare("INSERT INTO enxure_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    foreach ([
        'footer_text' => $footerText,
        'default_account_name' => $defaultAccountName,
        'default_account_number' => $defaultAccountNumber,
    ] as $key => $value) {
        $upsert->bind_param("ss", $key, $value);
        $upsert->execute();
    }
    echo json_encode(['success' => true]);
    exit;
}

function enxureHandleSaveEmailTemplates($mysqli): void
{
    $invoiceEmailSubject = trim($_POST['invoice_email_subject'] ?? '') ?: DEFAULT_INVOICE_SUBJECT;
    $reminderEmailSubject = trim($_POST['reminder_email_subject'] ?? '') ?: DEFAULT_REMINDER_SUBJECT;
    $reminderEmailBody = trim($_POST['reminder_email_body'] ?? '') ?: DEFAULT_REMINDER_BODY;

    $upsert = $mysqli->prepare("INSERT INTO enxure_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    foreach ([
        'invoice_email_subject' => $invoiceEmailSubject,
        'reminder_email_subject' => $reminderEmailSubject,
        'reminder_email_body' => $reminderEmailBody,
    ] as $key => $value) {
        $upsert->bind_param("ss", $key, $value);
        $upsert->execute();
    }
    echo json_encode(['success' => true]);
    exit;
}

function enxureHandleSaveInvoiceNumbering($mysqli): void
{
    $template = trim($_POST['invoice_number_template'] ?? '') ?: '{key}{seq}';
    $padding = (int) ($_POST['invoice_number_padding'] ?? 3);
    if ($padding < 1 || $padding > 10)
        $padding = 3;
    $upsert = $mysqli->prepare("INSERT INTO enxure_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    foreach ([
        'invoice_number_template' => $template,
        'invoice_number_padding' => (string) $padding,
    ] as $key => $value) {
        $upsert->bind_param("ss", $key, $value);
        $upsert->execute();
    }
    echo json_encode(['success' => true]);
    exit;
}

function enxureHandleResendVerificationEmail($mysqli, int $currentUserId): void
{
    error_reporting(0);
    ob_start();
    try {
        $user = $mysqli->query("SELECT id, username, email FROM enxure_users WHERE id = " . $currentUserId)->fetch_assoc();
        if (!$user || empty($user['email'])) {
            throw new Exception('No account email on file.');
        }
        $sent = enxureIssueEmailVerification($mysqli, (int) $user['id'], $user['username'], $user['email']);
        ob_clean();
        echo json_encode(['success' => $sent, 'error' => $sent ? '' : 'Failed to send — check SMTP settings.']);
    } catch (Throwable $e) {
        ob_clean();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}
