<?php
// ── Two-Factor Authentication (TOTP, RFC 6238) ───────────────────────────────
// No external library — HMAC-SHA1 + dynamic truncation are both built into
// PHP core. Base32 (RFC 4648) is implemented here too since that's the
// format every authenticator app expects for a manually-entered secret.
function base32Encode(string $data): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($data) as $byte) {
        $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
    }
    $bits = str_pad($bits, (int) (ceil(strlen($bits) / 5) * 5), '0', STR_PAD_RIGHT);
    $encoded = '';
    foreach (str_split($bits, 5) as $chunk) {
        $encoded .= $alphabet[bindec($chunk)];
    }
    return $encoded;
}

function base32Decode(string $b32): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $b32 = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $b32));
    $bits = '';
    foreach (str_split($b32) as $char) {
        $pos = strpos($alphabet, $char);
        if ($pos === false)
            continue;
        $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
    }
    $bytes = '';
    foreach (str_split($bits, 8) as $byteBits) {
        if (strlen($byteBits) < 8)
            continue; // trailing pad bits from base32's 5-bit grouping, not a real byte
        $bytes .= chr(bindec($byteBits));
    }
    return $bytes;
}

// 160 bits (20 bytes) — the size the TOTP RFC's reference implementation uses
// for SHA-1-based secrets.
function generateTotpSecret(): string
{
    return base32Encode(random_bytes(20));
}

function totpCodeAt(string $base32Secret, int $timeStep): string
{
    $key = base32Decode($base32Secret);
    $counter = pack('N*', 0) . pack('N*', $timeStep);
    $hash = hash_hmac('sha1', $counter, $key, true);
    $offset = ord($hash[19]) & 0x0F;
    $truncated = ((ord($hash[$offset]) & 0x7F) << 24)
        | ((ord($hash[$offset + 1]) & 0xFF) << 16)
        | ((ord($hash[$offset + 2]) & 0xFF) << 8)
        | (ord($hash[$offset + 3]) & 0xFF);
    return str_pad((string) ($truncated % 1000000), 6, '0', STR_PAD_LEFT);
}

// Accepts a code from one step either side of "now" (±30s) so a slightly slow
// phone clock, or a code typed just as it's about to roll over, isn't rejected.
function verifyTotpCode(string $base32Secret, string $code, int $window = 1): bool
{
    $code = preg_replace('/\s+/', '', (string) $code);
    if (!preg_match('/^\d{6}$/', $code))
        return false;
    $currentStep = (int) floor(time() / 30);
    for ($i = -$window; $i <= $window; $i++) {
        if (hash_equals(totpCodeAt($base32Secret, $currentStep + $i), $code)) {
            return true;
        }
    }
    return false;
}

// otpauth:// is the standard URI scheme authenticator apps use for setup.
// No QR library is vendored, so it's shown as a copyable value instead.
function totpOtpauthUri(string $secret, string $accountLabel, string $issuer = 'Invoxa'): string
{
    $label = rawurlencode($issuer) . ':' . rawurlencode($accountLabel);
    return 'otpauth://totp/' . $label . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer) . '&algorithm=SHA1&digits=6&period=30';
}

// Generates one-time-use 2FA backup codes, shown to the admin once (at
// totp_setup_confirm / totp_regenerate_backup_codes) and stored only as
// password_hash()es (see invoxaConsumeBackupCode()). XXXXX-XXXXX uppercase
// hex — easy to read/type, ~40 bits of entropy per code.
function invoxaGenerateBackupCodes(int $count = 10): array
{
    $codes = [];
    for ($i = 0; $i < $count; $i++) {
        $raw = strtoupper(bin2hex(random_bytes(5)));
        $codes[] = substr($raw, 0, 5) . '-' . substr($raw, 5, 5);
    }
    return $codes;
}

// Replaces any existing backup codes (both on initial enable and on
// regeneration — the old set should stop working either way) and returns
// the plaintext codes for the caller to show the admin once.
function invoxaIssueBackupCodes($mysqli, int $userId): array
{
    $mysqli->query("DELETE FROM invoxa_totp_backup_codes WHERE user_id = " . (int) $userId);
    $codes = invoxaGenerateBackupCodes();
    $insert = $mysqli->prepare("INSERT INTO invoxa_totp_backup_codes (user_id, code_hash) VALUES (?, ?)");
    foreach ($codes as $code) {
        $hash = password_hash(str_replace('-', '', $code), PASSWORD_DEFAULT);
        $insert->bind_param("is", $userId, $hash);
        $insert->execute();
    }
    return $codes;
}

// Minutes remaining on a locked_until value, or 0 if not locked — shared by
// the password and TOTP/backup-code lockout checks for consistent wording.
function invoxaLockoutMinutesRemaining(?string $lockedUntil): int
{
    if (empty($lockedUntil)) {
        return 0;
    }
    $remaining = strtotime($lockedUntil) - time();
    return $remaining > 0 ? (int) ceil($remaining / 60) : 0;
}

// Records one failed login attempt (wrong password, TOTP, or backup code
// all count the same) and locks the account at LOGIN_MAX_ATTEMPTS. The
// counter resets to 0 on lockout so only the lockout period gates retry.
function invoxaRegisterFailedLogin($mysqli, int $userId, int $currentAttempts): void
{
    $attempts = $currentAttempts + 1;
    if ($attempts >= LOGIN_MAX_ATTEMPTS) {
        $stmt = $mysqli->prepare("UPDATE invoxa_users SET failed_login_attempts = 0, locked_until = DATE_ADD(NOW(), INTERVAL ? MINUTE) WHERE id = ?");
        $lockoutMinutes = LOGIN_LOCKOUT_MINUTES;
        $stmt->bind_param("ii", $lockoutMinutes, $userId);
        $stmt->execute();
    } else {
        $stmt = $mysqli->prepare("UPDATE invoxa_users SET failed_login_attempts = ? WHERE id = ?");
        $stmt->bind_param("ii", $attempts, $userId);
        $stmt->execute();
    }
}

function invoxaSendPasswordResetEmail(string $username, string $toEmail, string $rawToken): bool
{
    require_once PHPMAILER_DIR . 'PHPMailer.php';
    require_once PHPMAILER_DIR . 'SMTP.php';
    require_once PHPMAILER_DIR . 'Exception.php';
    $fromName = 'Invoxa (No-Reply)';
    $fromEmail = getenv('SMTP_FROM_EMAIL') ?: '';
    $baseUrl = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '');
    $resetLink = $baseUrl . '/?reset_token=' . $rawToken;
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = getenv('SMTP_HOST') ?: '';
        $mail->Port = (int) (getenv('SMTP_PORT') ?: 587);
        $mail->SMTPAuth = true;
        $mail->Username = getenv('SMTP_USER') ?: '';
        $mail->Password = getenv('SMTP_PASSWORD') ?: '';
        $mail->SMTPSecure = match (strtolower(getenv('SMTP_ENCRYPTION') ?: 'tls')) {
            'ssl' => PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS,
            'none', '' => false,
            default => PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS,
        };
        $mail->CharSet = 'UTF-8';
        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($toEmail);
        $mail->Subject = 'Invoxa - Password Reset Request';
        $mail->isHTML(true);
        $mail->Body = '<p>Your Invoxa username is <strong>' . htmlspecialchars($username) . '</strong>.</p>'
            . '<p><a href="' . htmlspecialchars($resetLink) . '">Reset your password</a> - this link expires in 30 minutes.</p>'
            . '<p>If you didn\'t request this, you can ignore this email.</p>';
        $mail->send();
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function invoxaSendWelcomeEmail(string $username, string $toEmail, string $rawToken): bool
{
    require_once PHPMAILER_DIR . 'PHPMailer.php';
    require_once PHPMAILER_DIR . 'SMTP.php';
    require_once PHPMAILER_DIR . 'Exception.php';
    $fromName = 'Invoxa (No-Reply)';
    $fromEmail = getenv('SMTP_FROM_EMAIL') ?: '';
    $baseUrl = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '');
    $setPasswordLink = $baseUrl . '/?reset_token=' . $rawToken;
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = getenv('SMTP_HOST') ?: '';
        $mail->Port = (int) (getenv('SMTP_PORT') ?: 587);
        $mail->SMTPAuth = true;
        $mail->Username = getenv('SMTP_USER') ?: '';
        $mail->Password = getenv('SMTP_PASSWORD') ?: '';
        $mail->SMTPSecure = match (strtolower(getenv('SMTP_ENCRYPTION') ?: 'tls')) {
            'ssl' => PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS,
            'none', '' => false,
            default => PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS,
        };
        $mail->CharSet = 'UTF-8';
        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($toEmail);
        $mail->Subject = 'Invoxa - Your account is ready';
        $mail->isHTML(true);
        $mail->Body = '<p>An Invoxa account has been created for you. Your username is <strong>' . htmlspecialchars($username) . '</strong>.</p>'
            . '<p>Your administrator set an initial password for you, but for security we recommend setting your own instead: <a href="' . htmlspecialchars($setPasswordLink) . '">set your password</a> - this link expires in 24 hours.</p>'
            . '<p>If you\'d rather use the password your administrator gave you, you can ignore this email and log in directly.</p>';
        $mail->send();
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function invoxaIssueUserWelcomeEmail($mysqli, int $userId, string $username, string $email): bool
{
    $rawToken = bin2hex(random_bytes(32));
    $resetTokenHash = hash('sha256', $rawToken);
    $stmt = $mysqli->prepare("UPDATE invoxa_users SET reset_token_hash = ?, reset_token_expires = DATE_ADD(NOW(), INTERVAL 24 HOUR) WHERE id = ?");
    $stmt->bind_param("si", $resetTokenHash, $userId);
    $stmt->execute();
    return invoxaSendWelcomeEmail($username, $email, $rawToken);
}

function invoxaSendVerificationEmail(string $username, string $toEmail, string $rawToken): bool
{
    require_once PHPMAILER_DIR . 'PHPMailer.php';
    require_once PHPMAILER_DIR . 'SMTP.php';
    require_once PHPMAILER_DIR . 'Exception.php';
    $fromName = 'Invoxa (No-Reply)';
    $fromEmail = getenv('SMTP_FROM_EMAIL') ?: '';
    $baseUrl = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '');
    $verifyLink = $baseUrl . '/?verify_token=' . $rawToken;
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = getenv('SMTP_HOST') ?: '';
        $mail->Port = (int) (getenv('SMTP_PORT') ?: 587);
        $mail->SMTPAuth = true;
        $mail->Username = getenv('SMTP_USER') ?: '';
        $mail->Password = getenv('SMTP_PASSWORD') ?: '';
        $mail->SMTPSecure = match (strtolower(getenv('SMTP_ENCRYPTION') ?: 'tls')) {
            'ssl' => PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS,
            'none', '' => false,
            default => PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS,
        };
        $mail->CharSet = 'UTF-8';
        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($toEmail);
        $mail->Subject = 'Invoxa - Confirm Your Email';
        $mail->isHTML(true);
        $mail->Body = '<p>Hi ' . htmlspecialchars($username) . ',</p>'
            . '<p><a href="' . htmlspecialchars($verifyLink) . '">Confirm this email address</a> - this link expires in 24 hours.</p>'
            . '<p>This confirms we can actually reach you here, so account recovery works if you ever forget your password.</p>';
        $mail->send();
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function invoxaIssueEmailVerification($mysqli, int $userId, string $username, string $email): bool
{
    $rawToken = bin2hex(random_bytes(32));
    $verifyHash = hash('sha256', $rawToken);
    $stmt = $mysqli->prepare("UPDATE invoxa_users SET verify_token_hash = ?, verify_token_expires = DATE_ADD(NOW(), INTERVAL 24 HOUR) WHERE id = ?");
    $stmt->bind_param("si", $verifyHash, $userId);
    $stmt->execute();
    return invoxaSendVerificationEmail($username, $email, $rawToken);
}

function invoxaWipeAllData($mysqli): void
{
    $tables = [];
    $res = $mysqli->query("SHOW TABLES LIKE 'invoxa\\_%'");
    while ($row = $res->fetch_row()) {
        $tables[] = $row[0];
    }
    $mysqli->query("SET FOREIGN_KEY_CHECKS = 0");
    foreach ($tables as $table) {
        $mysqli->query("TRUNCATE TABLE `" . $table . "`");
    }
    $mysqli->query("SET FOREIGN_KEY_CHECKS = 1");
    foreach (glob(INVOICES_DIR . '*') as $f) {
        if (is_file($f)) {
            @unlink($f);
        }
    }
    foreach (glob(BACKUPS_DIR . '*') as $f) {
        if (is_file($f)) {
            @unlink($f);
        }
    }
}

// Checks $code against every unused backup code for this user (hashed
// storage means no direct lookup) and marks the match used. Not worth
// optimizing — a rarely-hit recovery path.
function invoxaConsumeBackupCode($mysqli, int $userId, string $code): bool
{
    $normalized = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));
    if ($normalized === '') {
        return false;
    }
    $stmt = $mysqli->prepare("SELECT id, code_hash FROM invoxa_totp_backup_codes WHERE user_id = ? AND used_at IS NULL");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $rows = $stmt->get_result();
    while ($row = $rows->fetch_assoc()) {
        if (password_verify($normalized, $row['code_hash'])) {
            $upd = $mysqli->prepare("UPDATE invoxa_totp_backup_codes SET used_at = NOW() WHERE id = ?");
            $upd->bind_param("i", $row['id']);
            $upd->execute();
            return true;
        }
    }
    return false;
}

// ── External API tokens ──────────────────────────────────────────────────────
// 'ivx_' prefix (like Stripe's sk_/pk_ or GitHub's ghp_) makes a token
// recognizable on sight — a convenience, not a security property.
function invoxaGenerateApiToken(): string
{
    return 'ivx_' . bin2hex(random_bytes(20));
}

// Returns the raw token — the only time it's ever available; only its hash
// and a short prefix are persisted.
function invoxaCreateApiToken($mysqli, string $label, ?int $expiresDays): array
{
    $raw = invoxaGenerateApiToken();
    $hash = hash('sha256', $raw);
    $prefix = substr($raw, 0, 10);
    if ($expiresDays === null) {
        $stmt = $mysqli->prepare("INSERT INTO invoxa_api_tokens (label, token_hash, token_prefix) VALUES (?, ?, ?)");
        $stmt->bind_param("sss", $label, $hash, $prefix);
    } else {
        $stmt = $mysqli->prepare("INSERT INTO invoxa_api_tokens (label, token_hash, token_prefix, expires_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? DAY))");
        $stmt->bind_param("sssi", $label, $hash, $prefix, $expiresDays);
    }
    $stmt->execute();
    return ['id' => $mysqli->insert_id, 'token' => $raw];
}

// Authenticates one API request via its Authorization: Bearer header —
// missing, unknown, and revoked/expired tokens all return null the same
// way, so no error message reveals which case applies. Touches
// last_used_at on success for Settings > API Access.
function invoxaAuthenticateApiRequest($mysqli): ?array
{
    // The API is a paid feature — re-checked on every request, not just at
    // token creation, so a deactivated license stops authenticating tokens.
    global $licenseValid;
    if (!$licenseValid) {
        return null;
    }
    $header = '';
    foreach (getallheaders() as $k => $v) {
        if (strtolower($k) === 'authorization') {
            $header = $v;
            break;
        }
    }
    if (!preg_match('/^Bearer\s+(\S+)$/i', $header, $m)) {
        return null;
    }
    $hash = hash('sha256', $m[1]);
    $stmt = $mysqli->prepare("SELECT id, label FROM invoxa_api_tokens WHERE token_hash = ? AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at > NOW())");
    $stmt->bind_param("s", $hash);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) {
        return null;
    }
    $mysqli->query("UPDATE invoxa_api_tokens SET last_used_at = NOW() WHERE id = " . (int) $row['id']);
    return $row;
}
