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
define('APP_VERSION', '2.9.0');

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


// ── Auth System ──────────────────────────────────────────────────────────────
// Defensive fallback only — sql/01-schema.sql is the canonical source of these tables.
$mysqli->query("CREATE TABLE IF NOT EXISTS invoxa_users (id INT AUTO_INCREMENT PRIMARY KEY, username VARCHAR(255) NOT NULL UNIQUE, email VARCHAR(255) DEFAULT NULL, password_hash VARCHAR(255) NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
$mysqli->query("CREATE TABLE IF NOT EXISTS invoxa_settings (setting_key VARCHAR(50) PRIMARY KEY, setting_value TEXT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
// Expenses (accounts payable) — same defensive-fallback reasoning as above;
// sql/01-schema.sql is still canonical for a fresh install.
$mysqli->query("CREATE TABLE IF NOT EXISTS invoxa_expenses (id INT AUTO_INCREMENT PRIMARY KEY, expense_date DATE NOT NULL, vendor VARCHAR(150) NOT NULL DEFAULT '', category VARCHAR(50) NOT NULL DEFAULT 'other', amount DECIMAL(10,2) NOT NULL DEFAULT 0.00, description TEXT, receipt_path VARCHAR(500) DEFAULT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, INDEX idx_expense_date (expense_date), INDEX idx_category (category)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
// Expense receipts — one row per uploaded file (an expense can have more than
// one, e.g. a receipt plus a card statement excerpt), files live on disk
// under RECEIPTS_DIR/<expense_id>/. Superseded expenses.receipt_path (single
// file, kept only for old rows) below.
$mysqli->query("CREATE TABLE IF NOT EXISTS invoxa_expense_receipts (id INT AUTO_INCREMENT PRIMARY KEY, expense_id INT NOT NULL, filename VARCHAR(255) NOT NULL, stored_path VARCHAR(500) NOT NULL, file_size INT NOT NULL DEFAULT 0, uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP, INDEX idx_expense_id (expense_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
$mysqli->query("INSERT INTO invoxa_expense_receipts (expense_id, filename, stored_path, file_size)
    SELECT e.id, e.receipt_path, e.receipt_path, 0 FROM invoxa_expenses e
    WHERE e.receipt_path IS NOT NULL AND e.receipt_path != ''
    AND NOT EXISTS (SELECT 1 FROM invoxa_expense_receipts r WHERE r.expense_id = e.id AND r.stored_path = e.receipt_path)");
// Recurring expense templates (hosting, SaaS subscriptions, etc.) — the
// run_recurring cron action auto-logs one invoxa_expenses row per active
// template each period, same guard-against-double-billing idea as recurring
// invoices. A paid feature, same bucket as recurring billing automation.
$mysqli->query("CREATE TABLE IF NOT EXISTS invoxa_recurring_expenses (id INT AUTO_INCREMENT PRIMARY KEY, vendor VARCHAR(150) NOT NULL DEFAULT '', category VARCHAR(50) NOT NULL DEFAULT 'other', amount DECIMAL(10,2) NOT NULL DEFAULT 0.00, description TEXT, frequency ENUM('weekly','monthly','quarterly','annually') NOT NULL DEFAULT 'monthly', is_active TINYINT(1) NOT NULL DEFAULT 1, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
// Links an auto-logged expense back to the template that created it, so the
// cron run can tell "already logged this period" apart per template instead
// of guessing from vendor/amount text.
$hasRecurringExpenseCol = $mysqli->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invoxa_expenses' AND COLUMN_NAME = 'recurring_expense_id'")->num_rows > 0;
if (!$hasRecurringExpenseCol) {
    $mysqli->query("ALTER TABLE invoxa_expenses ADD COLUMN recurring_expense_id INT DEFAULT NULL, ADD INDEX idx_recurring_expense_id (recurring_expense_id)");
}
// Invoice attachments (contracts, receipts) — one row per uploaded file, files
// themselves live on disk under INVOICES_DIR/attachments/<invoice_id>/.
$mysqli->query("CREATE TABLE IF NOT EXISTS invoxa_invoice_attachments (id INT AUTO_INCREMENT PRIMARY KEY, invoice_id INT NOT NULL, filename VARCHAR(255) NOT NULL, stored_path VARCHAR(500) NOT NULL, file_size INT NOT NULL DEFAULT 0, uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP, INDEX idx_invoice_id (invoice_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
// Payment ledger — one row per installment against an invoice.
// invoxa_invoices.paid_amount/paid_at stay as a cached running total (read
// directly by stats/export/dashboard queries), kept in sync from this
// table's SUM() by the mark_paid/mark_unpaid actions below.
$mysqli->query("CREATE TABLE IF NOT EXISTS invoxa_payments (id INT AUTO_INCREMENT PRIMARY KEY, invoice_id INT NOT NULL, amount DECIMAL(10,2) NOT NULL DEFAULT 0.00, note VARCHAR(255) DEFAULT '', provider VARCHAR(20) NOT NULL DEFAULT 'manual', provider_ref VARCHAR(255) DEFAULT NULL, paid_at DATETIME DEFAULT CURRENT_TIMESTAMP, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, INDEX idx_invoice_id (invoice_id), UNIQUE KEY uniq_provider_ref (provider, provider_ref)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
// Older installs have setting_value as VARCHAR(255), too narrow for a
// multi-line reminder body template or some license keys. Widening to TEXT
// is lossless.
$settingsValueType = $mysqli->query("SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invoxa_settings' AND COLUMN_NAME = 'setting_value'")->fetch_assoc()['DATA_TYPE'] ?? '';
if ($settingsValueType === 'varchar') {
    $mysqli->query("ALTER TABLE invoxa_settings MODIFY setting_value TEXT");
}
// Migration for installs that predate the profile-email license binding —
// fresh installs already have this column via 01-schema.sql; this only
// fires for pre-existing tables.
$hasEmailCol = $mysqli->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invoxa_users' AND COLUMN_NAME = 'email'")->num_rows > 0;
if (!$hasEmailCol) {
    $mysqli->query("ALTER TABLE invoxa_users ADD COLUMN email VARCHAR(255) DEFAULT NULL AFTER username");
}
// Same idea for installs that predate per-client payment terms — 21 days matches
// the fixed "+3 weeks" due date every invoice used before this column existed.
$hasTermsCol = $mysqli->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invoxa_clients' AND COLUMN_NAME = 'payment_terms_days'")->num_rows > 0;
if (!$hasTermsCol) {
    $mysqli->query("ALTER TABLE invoxa_clients ADD COLUMN payment_terms_days INT NOT NULL DEFAULT 21 AFTER monthly_rate");
}
// Same idea for installs that predate per-client billing frequency — the
// monthly default preserves existing clients' behavior until edited.
$hasFreqCol = $mysqli->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invoxa_clients' AND COLUMN_NAME = 'billing_frequency'")->num_rows > 0;
if (!$hasFreqCol) {
    $mysqli->query("ALTER TABLE invoxa_clients ADD COLUMN billing_frequency ENUM('weekly','monthly','quarterly','annually') NOT NULL DEFAULT 'monthly' AFTER payment_terms_days");
}
// Same idea for installs that predate per-client discount/tax — the 0.00
// default matches prior behavior (no discount, no tax) until a client is
// edited.
$hasDiscountCol = $mysqli->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invoxa_clients' AND COLUMN_NAME = 'discount_pct'")->num_rows > 0;
if (!$hasDiscountCol) {
    $mysqli->query("ALTER TABLE invoxa_clients ADD COLUMN discount_pct DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER billing_frequency");
}
$hasTaxCol = $mysqli->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invoxa_clients' AND COLUMN_NAME = 'tax_rate'")->num_rows > 0;
if (!$hasTaxCol) {
    $mysqli->query("ALTER TABLE invoxa_clients ADD COLUMN tax_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER discount_pct");
}
$hasClientPhoneCol = $mysqli->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invoxa_clients' AND COLUMN_NAME = 'phone'")->num_rows > 0;
if (!$hasClientPhoneCol) {
    $mysqli->query("ALTER TABLE invoxa_clients ADD COLUMN phone VARCHAR(50) NOT NULL DEFAULT '' AFTER email, ADD COLUMN address TEXT AFTER phone");
}
// Same idea for installs that predate two-factor auth — NULL defaults keep
// 2FA off until enabled under Settings > Authentication.
$hasTotpCol = $mysqli->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invoxa_users' AND COLUMN_NAME = 'totp_secret'")->num_rows > 0;
if (!$hasTotpCol) {
    $mysqli->query("ALTER TABLE invoxa_users ADD COLUMN totp_secret VARCHAR(64) DEFAULT NULL, ADD COLUMN totp_secret_pending VARCHAR(64) DEFAULT NULL");
}
// Same idea for installs that predate the client portal — NULL means no portal
// link has ever been generated for that client, same as a fresh install.
$hasPortalTokenCol = $mysqli->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invoxa_clients' AND COLUMN_NAME = 'portal_token'")->num_rows > 0;
if (!$hasPortalTokenCol) {
    $mysqli->query("ALTER TABLE invoxa_clients ADD COLUMN portal_token VARCHAR(64) DEFAULT NULL UNIQUE");
}
// Same idea for installs that predate Stripe/PayPal payment collection —
// the provider='manual' default preserves the existing ledger.
$hasProviderCol = $mysqli->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invoxa_payments' AND COLUMN_NAME = 'provider'")->num_rows > 0;
if (!$hasProviderCol) {
    $mysqli->query("ALTER TABLE invoxa_payments ADD COLUMN provider VARCHAR(20) NOT NULL DEFAULT 'manual', ADD COLUMN provider_ref VARCHAR(255) DEFAULT NULL, ADD UNIQUE KEY uniq_provider_ref (provider, provider_ref)");
}
// Login lockout — every existing account starts at 0 failed attempts/never
// locked, same as before this existed.
$hasLockoutCol = $mysqli->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invoxa_users' AND COLUMN_NAME = 'failed_login_attempts'")->num_rows > 0;
if (!$hasLockoutCol) {
    $mysqli->query("ALTER TABLE invoxa_users ADD COLUMN failed_login_attempts INT NOT NULL DEFAULT 0, ADD COLUMN locked_until DATETIME DEFAULT NULL");
}
$hasResetTokenCol = $mysqli->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invoxa_users' AND COLUMN_NAME = 'reset_token_hash'")->num_rows > 0;
if (!$hasResetTokenCol) {
    $mysqli->query("ALTER TABLE invoxa_users ADD COLUMN reset_token_hash VARCHAR(64) DEFAULT NULL, ADD COLUMN reset_token_expires DATETIME DEFAULT NULL");
}
$hasVerifyTokenCol = $mysqli->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invoxa_users' AND COLUMN_NAME = 'verify_token_hash'")->num_rows > 0;
if (!$hasVerifyTokenCol) {
    $mysqli->query("ALTER TABLE invoxa_users ADD COLUMN email_verified_at DATETIME DEFAULT NULL, ADD COLUMN verify_token_hash VARCHAR(64) DEFAULT NULL, ADD COLUMN verify_token_expires DATETIME DEFAULT NULL");
}
$mysqli->query("CREATE TABLE IF NOT EXISTS invoxa_totp_backup_codes (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, code_hash VARCHAR(255) NOT NULL, used_at DATETIME DEFAULT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, INDEX idx_user_id (user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
$mysqli->query("CREATE TABLE IF NOT EXISTS invoxa_api_tokens (id INT AUTO_INCREMENT PRIMARY KEY, label VARCHAR(100) NOT NULL, token_hash VARCHAR(64) NOT NULL, token_prefix VARCHAR(16) NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, last_used_at DATETIME DEFAULT NULL, expires_at DATETIME DEFAULT NULL, revoked_at DATETIME DEFAULT NULL, UNIQUE KEY uniq_token_hash (token_hash), INDEX idx_revoked (revoked_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
// Portal link expiry — NULL (never expires) for every link generated before
// this existed, matching generate_portal_token's prior behavior exactly.
$hasPortalExpiryCol = $mysqli->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invoxa_clients' AND COLUMN_NAME = 'portal_token_expires_at'")->num_rows > 0;
if (!$hasPortalExpiryCol) {
    $mysqli->query("ALTER TABLE invoxa_clients ADD COLUMN portal_token_expires_at DATETIME DEFAULT NULL");
}
// Same idea for installs that predate the "void" status — lets a mistaken
// invoice be excluded from totals without deleting it (which would lose
// its audit trail).
$statusColType = $mysqli->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invoxa_invoices' AND COLUMN_NAME = 'status'")->fetch_assoc()['COLUMN_TYPE'] ?? '';
if ($statusColType && strpos($statusColType, "'void'") === false) {
    $mysqli->query("ALTER TABLE invoxa_invoices MODIFY status ENUM('sent','failed','pending','draft','paid','void') DEFAULT 'pending'");
}
// Quote expiry — NULL (no expiry) for every quote saved before this existed,
// same as a quote with the field left blank going forward.
$hasQuoteExpiryCol = $mysqli->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invoxa_invoices' AND COLUMN_NAME = 'quote_expires_at'")->num_rows > 0;
if (!$hasQuoteExpiryCol) {
    $mysqli->query("ALTER TABLE invoxa_invoices ADD COLUMN quote_expires_at DATE DEFAULT NULL");
}

$userCount = $mysqli->query("SELECT COUNT(*) as c FROM invoxa_users")->fetch_assoc()['c'] ?? 0;
$authError = '';
$forgotSent = false;
$resetTokenUser = null;
if (isset($_GET['reset_token'])) {
    $resetTokenHash = hash('sha256', (string) $_GET['reset_token']);
    $stmt = $mysqli->prepare("SELECT id, username FROM invoxa_users WHERE reset_token_hash = ? AND reset_token_expires > NOW()");
    $stmt->bind_param("s", $resetTokenHash);
    $stmt->execute();
    $resetTokenUser = $stmt->get_result()->fetch_assoc() ?: null;
    if (!$resetTokenUser) {
        $authError = "This reset link is invalid or has expired.";
    }
}
$authMode = $userCount == 0 ? 'signup' : (isset($_SESSION['invoxa_2fa_pending_user']) ? 'totp' : ($resetTokenUser ? 'reset' : (isset($_GET['forgot']) ? 'forgot' : 'login')));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['auth_action'])) {
    if ($_POST['auth_action'] === 'signup' && $userCount == 0) {
        $user = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $pass = $_POST['password'] ?? '';
        if ($user && $pass && $email && filter_var($email, FILTER_VALIDATE_EMAIL) && strlen($pass) >= PASSWORD_MIN_LENGTH) {
            $hash = password_hash($pass, PASSWORD_DEFAULT);
            $stmt = $mysqli->prepare("INSERT INTO invoxa_users (username, email, password_hash) VALUES (?, ?, ?)");
            $stmt->bind_param("sss", $user, $email, $hash);
            $stmt->execute();
            invoxaIssueEmailVerification($mysqli, (int) $mysqli->insert_id, $user, $email);
            $_SESSION['invoxa_auth'] = true;
            $_SESSION['invoxa_username'] = $user;
            header("Location: ?login=1&welcome=1");
            exit;
        } elseif ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $authError = "Enter a valid email address.";
        } elseif ($pass && strlen($pass) < PASSWORD_MIN_LENGTH) {
            $authError = "Password must be at least " . PASSWORD_MIN_LENGTH . " characters.";
        } else {
            $authError = "Please fill all fields.";
        }
    } elseif ($_POST['auth_action'] === 'login') {
        $user = trim($_POST['username'] ?? '');
        $pass = $_POST['password'] ?? '';
        $stmt = $mysqli->prepare("SELECT id, password_hash, totp_secret, failed_login_attempts, locked_until FROM invoxa_users WHERE username = ?");
        $stmt->bind_param("s", $user);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            $lockWait = invoxaLockoutMinutesRemaining($row['locked_until']);
            if ($lockWait > 0) {
                $authError = "Too many failed attempts. Try again in {$lockWait} minute" . ($lockWait === 1 ? '' : 's') . ".";
            } elseif (password_verify($pass, $row['password_hash'])) {
                if (!empty($row['totp_secret'])) {
                    // Correct password but 2FA is enabled — hold off on a real session
                    // until verify_totp succeeds. No redirect needed; the render
                    // block below reads $authMode fresh.
                    $_SESSION['invoxa_2fa_pending_user'] = $user;
                    $authMode = 'totp';
                } else {
                    $mysqli->query("UPDATE invoxa_users SET failed_login_attempts = 0, locked_until = NULL WHERE id = " . (int) $row['id']);
                    $_SESSION['invoxa_auth'] = true;
                    $_SESSION['invoxa_username'] = $user;
                    header("Location: ?login=1");
                    exit;
                }
            } else {
                invoxaRegisterFailedLogin($mysqli, (int) $row['id'], (int) $row['failed_login_attempts']);
                $authError = "Invalid username or password.";
            }
        } else {
            $authError = "Invalid username or password.";
        }
    } elseif ($_POST['auth_action'] === 'verify_totp') {
        $pendingUser = $_SESSION['invoxa_2fa_pending_user'] ?? null;
        if (!$pendingUser) {
            $authError = "Login session expired — enter your password again.";
            $authMode = 'login';
        } else {
            $stmt = $mysqli->prepare("SELECT id, totp_secret, failed_login_attempts, locked_until FROM invoxa_users WHERE username = ?");
            $stmt->bind_param("s", $pendingUser);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $lockWait = $row ? invoxaLockoutMinutesRemaining($row['locked_until']) : 0;
            $code = trim($_POST['code'] ?? '');
            $codeOk = $row && !empty($row['totp_secret']) && (
                verifyTotpCode($row['totp_secret'], $code) || invoxaConsumeBackupCode($mysqli, (int) $row['id'], $code)
            );
            if ($lockWait > 0) {
                unset($_SESSION['invoxa_2fa_pending_user']);
                $authError = "Too many failed attempts. Try again in {$lockWait} minute" . ($lockWait === 1 ? '' : 's') . ".";
                $authMode = 'login';
            } elseif ($codeOk) {
                $mysqli->query("UPDATE invoxa_users SET failed_login_attempts = 0, locked_until = NULL WHERE id = " . (int) $row['id']);
                unset($_SESSION['invoxa_2fa_pending_user']);
                $_SESSION['invoxa_auth'] = true;
                $_SESSION['invoxa_username'] = $pendingUser;
                header("Location: ?login=1");
                exit;
            } else {
                if ($row) {
                    invoxaRegisterFailedLogin($mysqli, (int) $row['id'], (int) $row['failed_login_attempts']);
                }
                $authError = "Invalid code. Try again.";
                $authMode = 'totp';
            }
        }
    } elseif ($_POST['auth_action'] === 'forgot_password') {
        $email = trim($_POST['email'] ?? '');
        if ($email) {
            $stmt = $mysqli->prepare("SELECT id, username FROM invoxa_users WHERE email = ?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            if ($row) {
                $rawToken = bin2hex(random_bytes(32));
                $resetTokenHash = hash('sha256', $rawToken);
                $stmt = $mysqli->prepare("UPDATE invoxa_users SET reset_token_hash = ?, reset_token_expires = DATE_ADD(NOW(), INTERVAL 30 MINUTE) WHERE id = ?");
                $stmt->bind_param("si", $resetTokenHash, $row['id']);
                $stmt->execute();
                invoxaSendPasswordResetEmail($row['username'], $email, $rawToken);
            }
        }
        $authMode = 'forgot';
        $forgotSent = true;
    } elseif ($_POST['auth_action'] === 'reset_password') {
        $rawToken = $_POST['token'] ?? '';
        $resetTokenHash = hash('sha256', $rawToken);
        $stmt = $mysqli->prepare("SELECT id, username FROM invoxa_users WHERE reset_token_hash = ? AND reset_token_expires > NOW()");
        $stmt->bind_param("s", $resetTokenHash);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if (!$row) {
            $authError = "This reset link is invalid or has expired.";
            $authMode = 'login';
        } else {
            $pass = $_POST['password'] ?? '';
            $confirm = $_POST['password_confirm'] ?? '';
            if (strlen($pass) < PASSWORD_MIN_LENGTH) {
                $authError = "Password must be at least " . PASSWORD_MIN_LENGTH . " characters.";
                $authMode = 'reset';
            } elseif ($pass !== $confirm) {
                $authError = "Passwords don't match.";
                $authMode = 'reset';
            } else {
                $hash = password_hash($pass, PASSWORD_DEFAULT);
                $stmt = $mysqli->prepare("UPDATE invoxa_users SET password_hash = ?, reset_token_hash = NULL, reset_token_expires = NULL, failed_login_attempts = 0, locked_until = NULL WHERE id = ?");
                $stmt->bind_param("si", $hash, $row['id']);
                $stmt->execute();
                $_SESSION['invoxa_auth'] = true;
                $_SESSION['invoxa_username'] = $row['username'];
                header("Location: ?login=1");
                exit;
            }
        }
    } elseif ($_POST['auth_action'] === 'start_over') {
        $rawToken = $_POST['token'] ?? '';
        $resetTokenHash = hash('sha256', $rawToken);
        $stmt = $mysqli->prepare("SELECT id FROM invoxa_users WHERE reset_token_hash = ? AND reset_token_expires > NOW()");
        $stmt->bind_param("s", $resetTokenHash);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if (!$row) {
            $authError = "This reset link is invalid or has expired.";
            $authMode = 'login';
        } elseif (($_POST['confirm'] ?? '') !== 'RESET') {
            $authError = "Type RESET to confirm.";
            $authMode = 'reset';
        } else {
            invoxaWipeAllData($mysqli);
            session_destroy();
            header("Location: ?");
            exit;
        }
    } elseif ($_POST['auth_action'] === 'logout') {
        session_destroy();
        header("Location: ?");
        exit;
    }
}

$isAuth = isset($_SESSION['invoxa_auth']) && $_SESSION['invoxa_auth'] === true;
$isCron = CRON_SECRET !== '' && isset($_REQUEST['cron_key']) && hash_equals(CRON_SECRET, (string) $_REQUEST['cron_key']);

if (isset($_GET['verify_token'])) {
    $verifyHash = hash('sha256', (string) $_GET['verify_token']);
    $stmt = $mysqli->prepare("UPDATE invoxa_users SET email_verified_at = NOW(), verify_token_hash = NULL, verify_token_expires = NULL WHERE verify_token_hash = ? AND verify_token_expires > NOW()");
    $stmt->bind_param("s", $verifyHash);
    $stmt->execute();
    header("Location: ?" . ($stmt->affected_rows > 0 ? "email_verified=1" : "email_verify_failed=1"));
    exit;
}

// Settings + license load before the auth gate — the login screen needs the
// brand name, and the AJAX action dispatch block below needs both for every
// mutating action.
$settings = [];
$res = $mysqli->query("SELECT setting_key, setting_value FROM invoxa_settings");
while ($r = $res->fetch_assoc()) {
    $settings[$r['setting_key']] = $r['setting_value'];
}
require_once __DIR__ . '/lib/license.php';

// Three data-view states shared by every Invoices/Clients/Dashboard/Stats
// query: real-only (default), mixed, or test-only. Test-only wins over
// hide-test since it's a deliberate, temporary preview of Demo Data. Two
// shapes: one for invoxa_invoices (no is_test column of its own, only via
// its client), one for invoxa_clients' own column.
function invoxaTestViewFilter(bool $hideTest, bool $showTestOnly, string $keyword = 'AND', string $keyCol = 'client_key'): string
{
    if ($showTestOnly) {
        return "$keyword $keyCol IN (SELECT client_key FROM invoxa_clients WHERE is_test = 1)";
    }
    if ($hideTest) {
        return "$keyword $keyCol NOT IN (SELECT client_key FROM invoxa_clients WHERE is_test = 1)";
    }
    return '';
}
function invoxaTestViewClientFilter(bool $hideTest, bool $showTestOnly, string $keyword = 'AND', string $col = 'is_test'): string
{
    if ($showTestOnly) {
        return "$keyword $col = 1";
    }
    if ($hideTest) {
        return "$keyword $col = 0";
    }
    return '';
}
// $isCron requests already passed the CRON_SECRET check and hit the app via
// the internal Docker service name, not the real domain — skip domain-binding
// so recurring billing doesn't self-block. Signature verification still
// always runs.
$licenseFailReason = '';
$licenseValid = licenseIsValid($mysqli, $settings, $isCron, $licenseFailReason);

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
    $currencyCode = $settings['currency'] ?? (getenv('APP_CURRENCY') ?: 'USD');
    $portalStyle = '*{box-sizing:border-box;}body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Inter,Roboto,sans-serif;background:#0a0f1c;color:#f7f9fc;margin:0;padding:2rem 1.25rem;}.wrap{max-width:760px;margin:0 auto;}h1{font-size:1.4rem;margin:0 0 0.25rem;}h2{font-size:1.05rem;margin:2rem 0 0.75rem;}.sub{color:#90a0bb;font-size:0.9rem;margin:0 0 2rem;}table{width:100%;border-collapse:collapse;background:#131b2e;border-radius:12px;overflow:hidden;border:1px solid rgba(255,255,255,0.08);}th,td{padding:0.85rem 1rem;text-align:left;font-size:0.9rem;}th{background:rgba(255,255,255,0.04);color:#90a0bb;font-weight:600;text-transform:uppercase;font-size:0.75rem;letter-spacing:0.04em;}td{border-top:1px solid rgba(255,255,255,0.06);}.status{display:inline-block;padding:0.2rem 0.6rem;border-radius:999px;font-size:0.78rem;font-weight:600;}.status-paid{background:rgba(34,197,94,0.15);color:#4ade80;}.status-overdue{background:rgba(239,68,68,0.15);color:#f87171;}.status-outstanding{background:rgba(234,179,8,0.15);color:#facc15;}.status-void{background:rgba(148,163,184,0.15);color:#94a3b8;}.status-quote{background:rgba(139,92,246,0.15);color:#a78bfa;}.empty{color:#90a0bb;text-align:center;padding:3rem 1rem;}.pay-btn,.accept-btn{display:inline-block;background:#4f7cff;color:#fff;text-decoration:none;padding:0.4rem 0.85rem;border-radius:6px;font-size:0.82rem;font-weight:600;white-space:nowrap;border:none;font-family:inherit;cursor:pointer;}.pay-btn:hover,.accept-btn:hover{background:#3d63e0;}.confirm-box{background:#131b2e;border:1px solid rgba(255,255,255,0.08);border-radius:12px;padding:1.5rem;}.confirm-actions{display:flex;gap:0.75rem;margin-top:1.25rem;}.cancel-link{display:inline-flex;align-items:center;color:#90a0bb;text-decoration:none;font-size:0.9rem;padding:0.4rem 0.85rem;}';
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
        $quoteRow = $mysqli->query("SELECT invoice_number, amount, quote_expires_at, client_key FROM invoxa_invoices WHERE id = $quoteId AND is_quote = 1")->fetch_assoc();
        if (!$quoteRow || $quoteRow['client_key'] !== $portalClient['client_key']) {
            echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>' . htmlspecialchars($businessName) . '</title><style>' . $portalStyle . '</style></head><body><div class="wrap"><h1>Quote not found</h1><p class="sub">This quote is no longer available. <a href="?portal=' . htmlspecialchars($portalToken) . '" style="color:#4f7cff;">Back to your invoices</a></p></div></body></html>';
            exit;
        }
        $expired = !empty($quoteRow['quote_expires_at']) && $quoteRow['quote_expires_at'] < date('Y-m-d');
        if ($expired) {
            echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>' . htmlspecialchars($businessName) . '</title><style>' . $portalStyle . '</style></head><body><div class="wrap"><h1>This quote has expired</h1><p class="sub">Contact ' . htmlspecialchars($businessName) . ' for a new one. <a href="?portal=' . htmlspecialchars($portalToken) . '" style="color:#4f7cff;">Back to your invoices</a></p></div></body></html>';
            exit;
        }
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . htmlspecialchars($businessName) . '</title><style>' . $portalStyle . '</style></head><body><div class="wrap"><h1>Accept quote ' . htmlspecialchars($quoteRow['invoice_number']) . '?</h1><div class="confirm-box"><p style="margin:0; color:#90a0bb;">' . htmlspecialchars($currencyCode) . ' ' . number_format((float) $quoteRow['amount'], 2) . '. Accepting turns this into a real invoice — ' . htmlspecialchars($businessName) . ' will be notified right away.</p><form method="POST" class="confirm-actions"><input type="hidden" name="confirm_accept_quote" value="' . (int) $quoteId . '"><button type="submit" class="accept-btn">Accept Quote</button><a href="?portal=' . htmlspecialchars($portalToken) . '" class="cancel-link">Cancel</a></form></div></div></body></html>';
        exit;
    }
    $invRes = $mysqli->prepare("SELECT invoice_number, invoice_date, due_date, amount, paid_amount, status FROM invoxa_invoices WHERE client_key = ? AND is_quote = 0 AND status != 'draft' ORDER BY invoice_date DESC");
    $invRes->bind_param("s", $portalClient['client_key']);
    $invRes->execute();
    $portalInvoices = $invRes->get_result();
    $paymentsOn = ($settings['stripe_enabled'] ?? '0') === '1' || ($settings['paypal_enabled'] ?? '0') === '1';
    $rowsHtml = '';
    $today = date('Y-m-d');
    while ($inv = $portalInvoices->fetch_assoc()) {
        $paidAmt = (float) ($inv['paid_amount'] ?? 0);
        $amt = (float) $inv['amount'];
        $unpaid = !in_array($inv['status'], ['paid', 'void'], true) && $paidAmt < $amt;
        if ($inv['status'] === 'void') {
            $statusHtml = '<span class="status status-void">Void</span>';
        } elseif ($inv['status'] === 'paid') {
            $statusHtml = '<span class="status status-paid">Paid</span>';
        } elseif ($paidAmt > 0) {
            $statusHtml = '<span class="status status-outstanding">Partially Paid (' . htmlspecialchars($currencyCode) . ' ' . number_format($paidAmt, 2) . ' of ' . number_format($amt, 2) . ')</span>';
        } elseif (!empty($inv['due_date']) && $inv['due_date'] < $today) {
            $statusHtml = '<span class="status status-overdue">Overdue</span>';
        } else {
            $statusHtml = '<span class="status status-outstanding">Awaiting Payment</span>';
        }
        $payCell = ($paymentsOn && $unpaid)
            ? '<a href="?pay=' . rawurlencode($inv['invoice_number']) . '" class="pay-btn">Pay Now</a>'
            : '';
        $rowsHtml .= '<tr><td>' . htmlspecialchars($inv['invoice_number']) . '</td><td>' . htmlspecialchars(substr($inv['invoice_date'], 0, 10)) . '</td><td>' . htmlspecialchars($inv['due_date'] ?? '') . '</td><td>' . htmlspecialchars($currencyCode) . ' ' . number_format($amt, 2) . '</td><td>' . $statusHtml . '</td><td>' . $payCell . '</td></tr>';
    }
    $tableOrEmpty = $rowsHtml !== ''
        ? '<table><thead><tr><th>Invoice</th><th>Date</th><th>Due</th><th>Amount</th><th>Status</th><th></th></tr></thead><tbody>' . $rowsHtml . '</tbody></table>'
        : '<div class="empty">No invoices yet.</div>';

    $quoteRes = $mysqli->prepare("SELECT id, invoice_number, invoice_date, amount, quote_expires_at FROM invoxa_invoices WHERE client_key = ? AND is_quote = 1 ORDER BY invoice_date DESC");
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
        $quoteRowsHtml .= '<tr><td>' . htmlspecialchars($q['invoice_number']) . '</td><td>' . htmlspecialchars(substr($q['invoice_date'], 0, 10)) . '</td><td>' . htmlspecialchars($currencyCode) . ' ' . number_format((float) $q['amount'], 2) . '</td><td>' . $expiresCell . '</td><td>' . $actionCell . '</td></tr>';
    }
    $quotesSectionHtml = $quoteRowsHtml !== ''
        ? '<h2>Open Quotes</h2><table><thead><tr><th>Quote</th><th>Date</th><th>Amount</th><th>Valid Until</th><th></th></tr></thead><tbody>' . $quoteRowsHtml . '</tbody></table>'
        : '';

    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . htmlspecialchars($businessName) . ' — Invoices</title><meta name="robots" content="noindex, nofollow"><style>' . $portalStyle . '</style></head><body><div class="wrap"><h1>' . htmlspecialchars($businessName) . '</h1><p class="sub">Invoices for ' . htmlspecialchars($portalClient['client_name']) . '</p>' . $tableOrEmpty . $quotesSectionHtml . '</div></body></html>';
    exit;
}

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
    $stmt = $mysqli->prepare("SELECT id, amount, paid_amount, status FROM invoxa_invoices WHERE invoice_number = ? AND is_quote = 0");
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
    if (!$licenseValid) {
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
    $currencyCode = $settings['currency'] ?? (getenv('APP_CURRENCY') ?: 'USD');
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

// ── External API (v1) ────────────────────────────────────────────────────────
// ?apiv1=<endpoint> is deliberately separate from ?api=<endpoint>, which is
// used internally for session-authenticated page fragments (chart data,
// stats, table_html) — mixing the two auth models under one key invites bugs.
// Token-authenticated only (Settings > API Access issues/revokes tokens),
// same reasoning as the payment webhooks above being outside $isAuth.
if (isset($_GET['apiv1'])) {
    header('Content-Type: application/json');
    $apiToken = invoxaAuthenticateApiRequest($mysqli);
    if (!$apiToken) {
        http_response_code(401);
        echo json_encode(['error' => 'Missing or invalid API token. Send it as: Authorization: Bearer <token>. Generate one under Settings > API Access.']);
        exit;
    }
    $endpoint = (string) $_GET['apiv1'];
    $method = $_SERVER['REQUEST_METHOD'];

    if ($endpoint === 'invoices.list' && $method === 'GET') {
        $limit = min(200, max(1, (int) ($_GET['limit'] ?? 50)));
        $offset = max(0, (int) ($_GET['offset'] ?? 0));
        $where = ['is_quote = 0'];
        $types = '';
        $params = [];
        if (!empty($_GET['status'])) {
            $where[] = 'status = ?';
            $types .= 's';
            $params[] = $_GET['status'];
        }
        if (!empty($_GET['client_key'])) {
            $where[] = 'client_key = ?';
            $types .= 's';
            $params[] = $_GET['client_key'];
        }
        $types .= 'ii';
        $params[] = $limit;
        $params[] = $offset;
        $stmt = $mysqli->prepare("SELECT id, invoice_number, client_key, client_name, invoice_date, due_date, amount, paid_amount, status FROM invoxa_invoices WHERE " . implode(' AND ', $where) . " ORDER BY invoice_date DESC LIMIT ? OFFSET ?");
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        echo json_encode(['data' => $stmt->get_result()->fetch_all(MYSQLI_ASSOC)]);
        exit;
    }
    if ($endpoint === 'invoices.get' && $method === 'GET') {
        $invNum = (string) ($_GET['invoice_number'] ?? '');
        $stmt = $mysqli->prepare("SELECT id, invoice_number, client_key, client_name, invoice_date, due_date, amount, paid_amount, status FROM invoxa_invoices WHERE invoice_number = ? AND is_quote = 0");
        $stmt->bind_param("s", $invNum);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if (!$row) {
            http_response_code(404);
            echo json_encode(['error' => 'Invoice not found']);
            exit;
        }
        echo json_encode(['data' => $row]);
        exit;
    }
    if ($endpoint === 'clients.list' && $method === 'GET') {
        $limit = min(200, max(1, (int) ($_GET['limit'] ?? 50)));
        $offset = max(0, (int) ($_GET['offset'] ?? 0));
        $stmt = $mysqli->prepare("SELECT id, client_key, client_name, email, is_active, billing_frequency FROM invoxa_clients ORDER BY client_name ASC LIMIT ? OFFSET ?");
        $stmt->bind_param("ii", $limit, $offset);
        $stmt->execute();
        echo json_encode(['data' => $stmt->get_result()->fetch_all(MYSQLI_ASSOC)]);
        exit;
    }
    if ($endpoint === 'payments.record' && $method === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true);
        if (!is_array($body)) {
            $body = $_POST;
        }
        $invNum = (string) ($body['invoice_number'] ?? '');
        $amount = (float) ($body['amount'] ?? 0);
        $note = trim((string) ($body['note'] ?? ''));
        $reference = isset($body['reference']) && trim((string) $body['reference']) !== '' ? trim((string) $body['reference']) : null;
        $invRow = $mysqli->query("SELECT id FROM invoxa_invoices WHERE invoice_number = '" . $mysqli->real_escape_string($invNum) . "'")->fetch_assoc();
        if (!$invRow) {
            http_response_code(404);
            echo json_encode(['error' => 'Invoice not found']);
            exit;
        }
        $result = recordInvoicePayment($mysqli, $settings, (int) $invRow['id'], $amount, $note !== '' ? $note : ('Recorded via API token "' . $apiToken['label'] . '"'), 'api', $reference);
        if (!$result['success']) {
            http_response_code(400);
            echo json_encode(['error' => $result['error']]);
            exit;
        }
        echo json_encode(['data' => ['recorded' => true, 'duplicate' => $result['duplicate'] ?? false]]);
        exit;
    }

    http_response_code(404);
    echo json_encode(['error' => "Unknown API endpoint: {$endpoint}"]);
    exit;
}

if (!$isAuth && !$isCron) {
    // Product identity (favicon/title/chrome) is always "Invoxa" — brand settings only
    // customize invoice output (see processInvoice()/generateInvoiceHTML()), not the tool itself.
    echo '<!DOCTYPE html><html lang="en"><head><script>document.documentElement.setAttribute("data-theme", localStorage.getItem("invoxa_theme") || "light");</script><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Invoxa' . (INSTANCE_LABEL ? ' (' . htmlspecialchars(INSTANCE_LABEL) . ')' : '') . ' - ' . (['signup' => 'Setup', 'totp' => 'Two-Factor', 'forgot' => 'Recover Access', 'reset' => 'Reset Password'][$authMode] ?? 'Login') . '</title><link rel="icon" type="image/svg+xml" href="assets/img/invoxa-mark.svg"><link rel="alternate icon" href="assets/img/favicon.ico"><link rel="apple-touch-icon" href="assets/img/apple-touch-icon.png"><link rel="manifest" href="manifest.webmanifest"><meta name="theme-color" content="#0a0f1c"><style>*{box-sizing:border-box;}html{overflow:hidden;height:100%;}body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Inter,Roboto,sans-serif;background:radial-gradient(1100px 500px at 15% -10%, rgba(79,124,255,0.2), transparent 60%), radial-gradient(rgba(255,255,255,0.08) 1px, transparent 1px), #0a0f1c;background-size:auto,24px 24px,auto;color:#f7f9fc;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;overflow:hidden;position:relative;}body::before,body::after{content:"";position:absolute;border-radius:50%;filter:blur(50px);pointer-events:none;z-index:0;}body::before{width:600px;height:600px;top:-80px;left:-80px;background:radial-gradient(circle at 30% 30%, rgba(79,124,255,0.8), transparent 70%);animation:invoxaDriftA 22s ease-in-out infinite alternate;}body::after{width:540px;height:540px;bottom:-100px;right:-100px;background:radial-gradient(circle at 70% 70%, rgba(29,78,216,0.7), transparent 70%);animation:invoxaDriftB 26s ease-in-out infinite alternate;}@keyframes invoxaDriftA{0%{transform:translate(0,0);}100%{transform:translate(50px,35px);}}@keyframes invoxaDriftB{0%{transform:translate(0,0);}100%{transform:translate(-45px,-30px);}}@keyframes invoxaCardIn{from{opacity:0;transform:translateY(14px) scale(.97);}to{opacity:1;transform:translateY(0) scale(1);}}@keyframes invoxaFloat{0%,100%{transform:translateY(0);}50%{transform:translateY(-12px);}}@keyframes invoxaGlow{0%,100%{box-shadow:0 8px 24px -8px rgba(79,124,255,0.5);}50%{box-shadow:0 14px 34px -6px rgba(79,124,255,0.8);}}@media (prefers-reduced-motion: reduce){body::before,body::after,.auth-box,.auth-logo img{animation:none!important;}}.auth-box{position:relative;z-index:1;overflow:hidden;background:#131b2e;padding:2.75rem 2.5rem;border-radius:18px;width:100%;max-width:400px;border:1px solid rgba(255,255,255,0.08);box-shadow:0 24px 48px -16px rgba(0,0,0,0.55);animation:invoxaCardIn .5s ease both;}.auth-box::before{content:"";position:absolute;top:0;left:0;right:0;height:5px;background:linear-gradient(90deg,#5b8cff,#1d4ed8);box-shadow:0 0 20px 2px rgba(79,124,255,0.6);}.auth-logo{display:flex;justify-content:center;margin-bottom:1.25rem;}.auth-logo img{width:52px;height:52px;border-radius:14px;box-shadow:0 8px 24px -8px rgba(79,124,255,0.5);animation:invoxaFloat 2.6s ease-in-out infinite, invoxaGlow 2.6s ease-in-out infinite;}h2{margin-top:0;text-align:center;margin-bottom:1.75rem;font-weight:700;letter-spacing:-0.01em;font-size:1.35rem;}.form-group{margin-bottom:1.25rem;}label{display:block;margin-bottom:0.5rem;color:#90a0bb;font-size:0.85rem;font-weight:600;}input{width:100%;padding:0.75rem 0.9rem;background:#1a2439;border:1px solid rgba(255,255,255,0.08);color:#f7f9fc;border-radius:10px;box-sizing:border-box;font-family:inherit;font-size:16px;transition:border-color .15s ease, box-shadow .15s ease;}input:focus{outline:none;border-color:#4f7cff;box-shadow:0 0 0 3px rgba(79,124,255,0.15);}button{width:100%;padding:0.8rem;background:#4f7cff;border:none;color:white;border-radius:10px;font-weight:600;cursor:pointer;margin-top:0.5rem;font-family:inherit;font-size:0.95rem;transition:background 0.15s ease, transform .1s ease;box-shadow:0 4px 14px -4px rgba(79,124,255,0.5);}button:hover{background:#3d63e0;}button:active{transform:translateY(1px);}.error{color:#f5455c;margin-bottom:1.25rem;text-align:center;font-size:0.875rem;background:rgba(245,69,92,0.1);padding:0.6rem;border-radius:8px;}.doc-links{display:flex;justify-content:center;gap:1.25rem;margin-top:1.75rem;padding-top:1.25rem;border-top:1px solid rgba(255,255,255,0.08);}.doc-links a{color:#90a0bb;font-size:0.8rem;text-decoration:none;font-weight:500;background:none;border:none;padding:0;cursor:pointer;width:auto;margin:0;box-shadow:none;}.doc-links a:hover{color:#4f7cff;}.doc-modal-overlay{position:fixed;inset:0;background:rgba(5,8,16,0.65);backdrop-filter:blur(6px);display:none;align-items:center;justify-content:center;z-index:1000;}.doc-modal-overlay.active{display:flex;}.doc-modal{background:#131b2e;border:1px solid rgba(255,255,255,0.08);border-radius:16px;width:90%;max-width:640px;max-height:78vh;display:flex;flex-direction:column;box-shadow:0 24px 48px -16px rgba(0,0,0,0.55);}.doc-modal-header{padding:1.1rem 1.25rem;border-bottom:1px solid rgba(255,255,255,0.08);display:flex;justify-content:space-between;align-items:center;font-weight:700;}.doc-modal-actions{display:flex;gap:0.5rem;align-items:center;}.doc-modal button{width:auto;margin:0;box-shadow:none;font-family:inherit;}.doc-tab-btn{padding:0.4rem 0.7rem;font-size:0.75rem;background:#1a2439;border:1px solid rgba(255,255,255,0.08);border-radius:8px;color:#f7f9fc;}.doc-tab-btn:hover{background:#212d47;}.doc-close-btn{padding:0.3rem 0.6rem;background:transparent;font-size:1.1rem;line-height:1;border:none;color:#90a0bb;}.doc-close-btn:hover{background:transparent;color:#f5455c;}.doc-modal-body{padding:1.25rem 1.5rem;overflow-y:auto;}.doc-modal-body .doc-content h1,.doc-modal-body .doc-content h2,.doc-modal-body .doc-content h3,.doc-modal-body .doc-content h4{color:#f7f9fc;margin:1.25rem 0 0.6rem;line-height:1.3;}.doc-modal-body .doc-content h1:first-child,.doc-modal-body .doc-content h2:first-child{margin-top:0;}.doc-modal-body .doc-content h1{font-size:1.25rem;}.doc-modal-body .doc-content h2{font-size:1.05rem;border-bottom:1px solid rgba(255,255,255,0.08);padding-bottom:0.35rem;}.doc-modal-body .doc-content h3{font-size:0.95rem;}.doc-modal-body .doc-content p,.doc-modal-body .doc-content li{color:#90a0bb;font-size:0.88rem;line-height:1.6;}.doc-modal-body .doc-content ul,.doc-modal-body .doc-content ol{margin:0.5rem 0 0.75rem;padding-left:1.3rem;}.doc-modal-body .doc-content strong{color:#f7f9fc;}.doc-modal-body .doc-content a{color:#4f7cff;text-decoration:none;}.doc-modal-body .doc-content a:hover{text-decoration:underline;}.doc-modal-body .doc-content code{background:#1a2439;border:1px solid rgba(255,255,255,0.08);border-radius:4px;padding:0.1rem 0.4rem;font-size:0.8rem;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;color:#f7f9fc;}.doc-modal-body .doc-content pre{background:#1a2439;border:1px solid rgba(255,255,255,0.08);border-radius:10px;padding:0.8rem 1rem;overflow-x:auto;margin:0.75rem 0;}.doc-modal-body .doc-content pre code{background:none;border:none;padding:0;}.doc-modal-body .doc-content table{width:100%;border-collapse:collapse;margin:0.75rem 0 1.1rem;font-size:0.82rem;}.doc-modal-body .doc-content th,.doc-modal-body .doc-content td{border:1px solid rgba(255,255,255,0.08);padding:0.45rem 0.6rem;text-align:left;}.doc-modal-body .doc-content th{background:#1a2439;color:#f7f9fc;}.doc-modal-body .doc-content td{color:#90a0bb;}[data-theme="light"] body{background:radial-gradient(1100px 500px at 15% -10%, rgba(61,99,224,0.16), transparent 60%), radial-gradient(rgba(15,23,42,0.06) 1px, transparent 1px), #e8ecf4;background-size:auto,24px 24px,auto;color:#0f172a;}[data-theme="light"] body::before{background:radial-gradient(circle at 30% 30%, rgba(61,99,224,0.6), transparent 70%);}[data-theme="light"] body::after{background:radial-gradient(circle at 70% 70%, rgba(29,78,216,0.5), transparent 70%);}[data-theme="light"] .auth-box{background:#ffffff;border-color:rgba(15,23,42,0.08);box-shadow:0 24px 48px -16px rgba(15,23,42,0.12);}[data-theme="light"] label{color:#5c6b85;}[data-theme="light"] input{background:#f8f9fd;border-color:rgba(15,23,42,0.08);color:#0f172a;}[data-theme="light"] input:focus{border-color:#3d63e0;box-shadow:0 0 0 3px rgba(61,99,224,0.15);}[data-theme="light"] button{background:#3d63e0;box-shadow:0 4px 14px -4px rgba(61,99,224,0.5);}[data-theme="light"] button:hover{background:#2e4fc0;}[data-theme="light"] .error{color:#dc2626;background:rgba(220,38,38,0.08);}[data-theme="light"] .doc-links{border-top-color:rgba(15,23,42,0.08);}[data-theme="light"] .doc-links a{color:#5c6b85;}[data-theme="light"] .doc-links a:hover{color:#3d63e0;}[data-theme="light"] .doc-modal-overlay{background:rgba(15,23,42,0.45);}[data-theme="light"] .doc-modal{background:#ffffff;border-color:rgba(15,23,42,0.08);box-shadow:0 24px 48px -16px rgba(15,23,42,0.12);}[data-theme="light"] .doc-modal-header{border-bottom-color:rgba(15,23,42,0.08);}[data-theme="light"] .doc-tab-btn{background:#f8f9fd;border-color:rgba(15,23,42,0.08);color:#0f172a;}[data-theme="light"] .doc-tab-btn:hover{background:#eef1f8;}[data-theme="light"] .doc-close-btn{color:#5c6b85;}[data-theme="light"] .doc-close-btn:hover{color:#dc2626;}[data-theme="light"] .doc-modal-body .doc-content h1,[data-theme="light"] .doc-modal-body .doc-content h2,[data-theme="light"] .doc-modal-body .doc-content h3,[data-theme="light"] .doc-modal-body .doc-content h4{color:#0f172a;}[data-theme="light"] .doc-modal-body .doc-content h2{border-bottom-color:rgba(15,23,42,0.08);}[data-theme="light"] .doc-modal-body .doc-content p,[data-theme="light"] .doc-modal-body .doc-content li{color:#5c6b85;}[data-theme="light"] .doc-modal-body .doc-content strong{color:#0f172a;}[data-theme="light"] .doc-modal-body .doc-content a{color:#3d63e0;}[data-theme="light"] .doc-modal-body .doc-content code{background:#f8f9fd;border-color:rgba(15,23,42,0.08);color:#0f172a;}[data-theme="light"] .doc-modal-body .doc-content pre{background:#f8f9fd;border-color:rgba(15,23,42,0.08);}[data-theme="light"] .doc-modal-body .doc-content th,[data-theme="light"] .doc-modal-body .doc-content td{border-color:rgba(15,23,42,0.08);}[data-theme="light"] .doc-modal-body .doc-content th{background:#f8f9fd;color:#0f172a;}[data-theme="light"] .doc-modal-body .doc-content td{color:#5c6b85;}</style></head><body><div class="auth-box"><div class="auth-logo"><img src="assets/img/invoxa-mark.svg" width="52" height="52" alt=""></div><h2>Invoxa ' . (['signup' => 'Setup', 'totp' => 'Two-Factor Authentication', 'forgot' => 'Recover Access', 'reset' => 'Reset Password'][$authMode] ?? 'Login') . '</h2>';
    if ($authMode === 'signup')
        echo '<p style="text-align:center; color:#94a3b8; font-size:0.875rem; margin-bottom: 1.5rem;">Create your master admin account.</p>';
    if ($authMode === 'totp')
        echo '<p style="text-align:center; color:#94a3b8; font-size:0.875rem; margin-bottom: 1.5rem;">Enter the 6-digit code from your authenticator app.</p>';
    if ($authMode === 'forgot')
        echo '<p style="text-align:center; color:#94a3b8; font-size:0.875rem; margin-bottom: 1.5rem;">Enter your account email and we\'ll send a link to reset your password (and remind you of your username).</p>';
    if ($authMode === 'reset')
        echo '<p style="text-align:center; color:#94a3b8; font-size:0.875rem; margin-bottom: 1.5rem;">Set a new password for <strong>' . htmlspecialchars($resetTokenUser['username'] ?? '') . '</strong>.</p>';
    if (isset($_GET['email_verified']))
        echo '<div class="error" style="color:#22c55e; background:rgba(34,197,94,0.1);">Email confirmed — account recovery will reach you at that address.</div>';
    if (isset($_GET['email_verify_failed']))
        echo '<div class="error">That confirmation link is invalid or has expired.</div>';
    if ($authError)
        echo '<div class="error">' . $authError . '</div>';
    if ($authMode === 'totp') {
        echo '<form method="POST"><input type="hidden" name="auth_action" value="verify_totp"><div class="form-group"><label>Code</label><input type="text" name="code" inputmode="numeric" pattern="[0-9]*" maxlength="6" autocomplete="one-time-code" required autofocus></div><button type="submit">Verify Code</button></form>';
        echo '<form method="POST" style="margin-top:0.5rem;"><input type="hidden" name="auth_action" value="logout"><button type="submit" style="background:transparent; color:#90a0bb; box-shadow:none;">Cancel, use a different account</button></form>';
    } elseif ($authMode === 'forgot') {
        if ($forgotSent) {
            echo '<p style="text-align:center; color:#94a3b8; font-size:0.875rem;">If that email is on file, a reset link is on its way. It expires in 30 minutes.</p>';
        } else {
            echo '<form method="POST"><input type="hidden" name="auth_action" value="forgot_password"><div class="form-group"><label>Email</label><input type="email" name="email" required autofocus></div><button type="submit">Send Reset Link</button></form>';
        }
        echo '<div style="text-align:center; margin-top:1rem;"><a href="?" style="color:#90a0bb; font-size:0.85rem; text-decoration:none;">Back to login</a></div>';
    } elseif ($authMode === 'reset') {
        echo '<form method="POST"><input type="hidden" name="auth_action" value="reset_password"><input type="hidden" name="token" value="' . htmlspecialchars((string) $_GET['reset_token']) . '"><div class="form-group"><label>New Password</label><input type="password" name="password" minlength="' . PASSWORD_MIN_LENGTH . '" required autofocus></div><div class="form-group"><label>Confirm New Password</label><input type="password" name="password_confirm" minlength="' . PASSWORD_MIN_LENGTH . '" required></div><button type="submit">Set New Password</button></form>';
        echo '<div style="text-align:center; margin-top:1.5rem; padding-top:1.25rem; border-top:1px solid rgba(255,255,255,0.08);"><p style="color:#94a3b8; font-size:0.8rem; margin-bottom:0.75rem;">Rather start completely fresh instead? This erases every client, invoice, and setting.</p><form method="POST" onsubmit="return confirm(\'Erase everything and start over? This cannot be undone.\');"><input type="hidden" name="auth_action" value="start_over"><input type="hidden" name="token" value="' . htmlspecialchars((string) $_GET['reset_token']) . '"><input type="text" name="confirm" placeholder="Type RESET to confirm" required style="margin-bottom:0.75rem;"><button type="submit" style="background:#f5455c;">Erase Everything &amp; Start Over</button></form></div>';
    } else {
        $emailField = $authMode === 'signup' ? '<div class="form-group"><label>Email</label><input type="email" name="email" required placeholder="Must match the email your license was issued to"></div>' : '';
        $passwordMinAttr = $authMode === 'signup' ? ' minlength="' . PASSWORD_MIN_LENGTH . '"' : '';
        echo '<form method="POST"><input type="hidden" name="auth_action" value="' . $authMode . '"><div class="form-group"><label>Username</label><input type="text" name="username" required autofocus></div>' . $emailField . '<div class="form-group"><label>Password</label><input type="password" name="password" required' . $passwordMinAttr . '></div><button type="submit">' . ($authMode === 'signup' ? 'Create Account' : 'Secure Login') . '</button></form>';
        if ($authMode === 'login')
            echo '<div style="text-align:center; margin-top:1rem;"><a href="?forgot=1" style="color:#90a0bb; font-size:0.85rem; text-decoration:none;">Forgot your password or username?</a></div>';
    }
    // Plain text links, not Font Awesome icons — this standalone auth page
    // doesn't load the icon stylesheet. Docs open in an in-page modal so the
    // viewer looks consistent everywhere it appears.
    echo '<div class="doc-links"><a href="javascript:void(0)" onclick="openDocModal(\'readme\')">Quick Start</a><a href="javascript:void(0)" onclick="openDocModal(\'install\')">Installation Guide</a></div>';
    echo '<div id="docModal" class="doc-modal-overlay"><div class="doc-modal"><div class="doc-modal-header"><span id="docModalTitle">Documentation</span><div class="doc-modal-actions"><button type="button" class="doc-tab-btn" onclick="loadDoc(\'readme\')">Quick Start</button><button type="button" class="doc-tab-btn" onclick="loadDoc(\'install\')">Installation Guide</button><button type="button" class="doc-close-btn" onclick="closeDocModal()">&times;</button></div></div><div class="doc-modal-body" id="docModalBody"></div></div></div>';
    echo '<script>const docTitles={readme:"Quick Start",install:"Installation Guide"};function loadDoc(doc){document.getElementById("docModalTitle").textContent=docTitles[doc]||"Documentation";var body=document.getElementById("docModalBody");body.innerHTML=\'<p style="color:#90a0bb;">Loading…</p>\';fetch("?doc="+doc).then(function(r){return r.text()}).then(function(html){body.innerHTML=html}).catch(function(){body.innerHTML=\'<p style="color:#90a0bb;">Failed to load document.</p>\'})}function openDocModal(doc){loadDoc(doc);document.getElementById("docModal").classList.add("active")}function closeDocModal(){document.getElementById("docModal").classList.remove("active")}document.addEventListener("click",function(e){if(e.target.id==="docModal")closeDocModal()});</script>';
    echo '</div></body></html>';
    exit;
}

$emailPassword = getenv('SMTP_PASSWORD') ?: '';

// ── Invoice Generation Core ──────────────────────────────────────────────────
// A short, stable fingerprint of the active license, quietly embedded in
// every generated invoice (see generateInvoiceHTML()). Traces a leaked
// invoice back to the license it came from via a fingerprint-to-buyer
// lookup kept outside this app. Not shown in the UI, and not a substitute
// for the signature check — a deterrent only.
// (invoiceWatermarkFingerprint, computeInvoiceTotals, formatPct now live in
// lib/invoice_helpers.php — see the require_once near the top of this file)

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

// (expenseCategories now lives in lib/invoice_helpers.php)

// Builds a simple double-entry General Journal from invoices, payments,
// and logged expenses — for handing to an accountant or importing into a
// bookkeeping tool. One row per side of each entry (Date, Account, Debit,
// Credit, Memo, Reference):
//   - Invoice issued:      Dr Accounts Receivable / Cr Sales Income
//   - Payment received:    Dr Cash & Bank / Cr Accounts Receivable
//   - Expense logged:      Dr <category> Expense / Cr Cash & Bank
// Fixed 4-account chart (plus one Expense account per expenseCategories()
// entry), not user-configurable — single-admin, no multi-entity
// bookkeeping. Every entry balances, making this genuinely importable.
function buildAccountingJournal($mysqli, string $startDate, string $testFilter): array
{
    $categories = expenseCategories();
    $rows = [];

    $res = $mysqli->query("SELECT invoice_number, client_name, invoice_date, amount FROM invoxa_invoices WHERE is_quote = 0 AND status != 'void' AND invoice_date >= '$startDate' $testFilter ORDER BY invoice_date ASC");
    while ($r = $res->fetch_assoc()) {
        $date = substr($r['invoice_date'], 0, 10);
        $memo = "Invoice {$r['invoice_number']} — {$r['client_name']}";
        $amount = round((float) $r['amount'], 2);
        $rows[] = ['date' => $date, 'account' => 'Accounts Receivable', 'debit' => $amount, 'credit' => 0, 'memo' => $memo, 'ref' => $r['invoice_number']];
        $rows[] = ['date' => $date, 'account' => 'Sales Income', 'debit' => 0, 'credit' => $amount, 'memo' => $memo, 'ref' => $r['invoice_number']];
    }

    $res = $mysqli->query("SELECT invoice_number, client_name, paid_at, paid_amount FROM invoxa_invoices WHERE is_quote = 0 AND status != 'void' AND paid_amount > 0 AND paid_at >= '$startDate' $testFilter ORDER BY paid_at ASC");
    while ($r = $res->fetch_assoc()) {
        $date = substr($r['paid_at'], 0, 10);
        $memo = "Payment received for invoice {$r['invoice_number']} — {$r['client_name']}";
        $amount = round((float) $r['paid_amount'], 2);
        $rows[] = ['date' => $date, 'account' => 'Cash & Bank', 'debit' => $amount, 'credit' => 0, 'memo' => $memo, 'ref' => $r['invoice_number']];
        $rows[] = ['date' => $date, 'account' => 'Accounts Receivable', 'debit' => 0, 'credit' => $amount, 'memo' => $memo, 'ref' => $r['invoice_number']];
    }

    $res = $mysqli->query("SELECT id, expense_date, vendor, category, amount FROM invoxa_expenses WHERE expense_date >= '$startDate' ORDER BY expense_date ASC");
    while ($r = $res->fetch_assoc()) {
        $date = substr($r['expense_date'], 0, 10);
        $account = ($categories[$r['category']] ?? ucfirst($r['category'])) . ' Expense';
        $memo = trim($r['vendor'] . ($r['vendor'] !== '' ? ' — ' : '') . 'Expense #' . $r['id']);
        $amount = round((float) $r['amount'], 2);
        $rows[] = ['date' => $date, 'account' => $account, 'debit' => $amount, 'credit' => 0, 'memo' => $memo, 'ref' => 'EXP-' . $r['id']];
        $rows[] = ['date' => $date, 'account' => 'Cash & Bank', 'debit' => 0, 'credit' => $amount, 'memo' => $memo, 'ref' => 'EXP-' . $r['id']];
    }

    usort($rows, fn($a, $b) => $a['date'] <=> $b['date']);
    return $rows;
}

// (generateInvoiceHTML and generateInvoicePdf now live in lib/invoice_helpers.php)

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
    $currencyCode = $settings['currency'] ?? (getenv('APP_CURRENCY') ?: 'USD');
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
    $stmt = $mysqli->prepare("INSERT INTO invoxa_invoices (invoice_number, client_key, client_name, recipient_email, invoice_date, due_date, amount, status, html_content, file_path) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ssssssdsss", $invNum, $client['client_key'], $client['client_name'], $client['email'], $date, $dueDate, $amount, $status, $htmlContent, $relPath);
    $stmt->execute();

    $actionType = $emailSent ? 'email_sent' : 'email_failed';
    $notes = $emailSent ? "Invoice generated and emailed to {$client['email']}" : "Send failed: " . $errorMsg;
    $stmtAction = $mysqli->prepare("INSERT INTO invoxa_actions (invoice_id, invoice_number, action_type, notes) VALUES (?, ?, ?, ?)");
    $iid = $stmt->insert_id;
    $stmtAction->bind_param("isss", $iid, $invNum, $actionType, $notes);
    $stmtAction->execute();

    if ($memo !== null && trim($memo) !== '') {
        $memoStmt = $mysqli->prepare("INSERT INTO invoxa_actions (invoice_id, invoice_number, action_type, notes) VALUES (?, ?, 'note_added', ?)");
        $memoTrimmed = trim($memo);
        $memoStmt->bind_param("iss", $iid, $invNum, $memoTrimmed);
        $memoStmt->execute();
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
        $stmt = $mysqli->prepare("INSERT INTO invoxa_actions (invoice_id, invoice_number, action_type, notes) VALUES (NULL, '', 'notification_failed', ?)");
        $stmt->bind_param("s", $notes);
        $stmt->execute();
    }
}

// Single source of truth for crediting a payment against an invoice — used by
// Mark Paid / Bulk Mark Paid and by the Stripe/PayPal return-URL handlers and
// webhooks below.
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

    $invRow = $mysqli->query("SELECT amount, invoice_number, status, client_name FROM invoxa_invoices WHERE id = " . (int) $invoiceId)->fetch_assoc();
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
    $stmt = $mysqli->prepare("INSERT INTO invoxa_actions (invoice_id, invoice_number, action_type, notes) VALUES (?, ?, ?, ?)");
    $actionType = $isPartial ? 'mark_partial_paid' : 'mark_paid';
    $notes = ($isPartial ? "Partial payment logged: $" : "Marked as paid: $") . number_format($amount, 2)
        . " (total paid to date: $" . number_format($totalPaid, 2) . " of $" . number_format($invAmount, 2) . ")"
        . $sourceLabel
        . ($note !== '' ? " — {$note}" : '');
    $stmt->bind_param("isss", $invoiceId, $invNum, $actionType, $notes);
    $stmt->execute();

    $currencyCode = $settings['currency'] ?? 'USD';
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

    $invRow = $mysqli->query("SELECT amount, invoice_number, status, client_name FROM invoxa_invoices WHERE id = " . (int) $invoiceId)->fetch_assoc();
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
    $stmt = $mysqli->prepare("INSERT INTO invoxa_actions (invoice_id, invoice_number, action_type, notes) VALUES (?, ?, 'refund_issued', ?)");
    $notes = "Refund issued: $" . number_format($refundAmount, 2) . " (total paid now: $" . number_format($totalPaid, 2) . " of $" . number_format($invAmount, 2) . ")" . $sourceLabel;
    $stmt->bind_param("iss", $invoiceId, $invRow['invoice_number'], $notes);
    $stmt->execute();

    $currencyCode = $settings['currency'] ?? 'USD';
    notifyChannel($mysqli, $settings, 'notify_on_payment', "\xE2\x86\xA9\xEF\xB8\x8F Refund issued — {$invRow['invoice_number']} ({$invRow['client_name']}){$sourceLabel}: {$currencyCode} " . number_format($refundAmount, 2));

    return ['success' => true, 'duplicate' => false, 'total_paid' => $totalPaid, 'invoice_number' => $invRow['invoice_number']];
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
    $stmt = $mysqli->prepare("INSERT INTO invoxa_actions (invoice_id, invoice_number, action_type, notes) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("isss", $quoteId, $newNum, $actionType, $actionNotes);
    $stmt->execute();

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
    $notes = ucfirst($provider) . " webhook ({$eventType}) referenced an invoice/reference Invoxa doesn't recognize: '{$reference}'. Payment not credited.";
    $stmt = $mysqli->prepare("INSERT INTO invoxa_actions (invoice_id, invoice_number, action_type, notes) VALUES (NULL, '', 'webhook_unmatched', ?)");
    $stmt->bind_param("s", $notes);
    $stmt->execute();
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
    $currencyCode = $settings['currency'] ?? (getenv('APP_CURRENCY') ?: 'USD');

    require_once PHPMAILER_DIR . 'PHPMailer.php';
    require_once PHPMAILER_DIR . 'SMTP.php';
    require_once PHPMAILER_DIR . 'Exception.php';

    while ($inv = $res->fetch_assoc()) {
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
        $stmt = $mysqli->prepare("INSERT INTO invoxa_actions (invoice_id, invoice_number, action_type, notes) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("isss", $inv['id'], $inv['invoice_number'], $actionType, $notes);
        $stmt->execute();

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
        $act = $mysqli->prepare("INSERT INTO invoxa_actions (invoice_id, invoice_number, action_type, notes) VALUES (?, ?, 'late_fee_charged', ?)");
        $act->bind_param("iss", $inv['id'], $inv['invoice_number'], $notes);
        $act->execute();

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
    $logStmt = $mysqli->prepare("INSERT INTO invoxa_actions (invoice_id, invoice_number, action_type, notes) VALUES (NULL, '', 'audit_log_pruned', ?)");
    $logStmt->bind_param("s", $notes);
    $logStmt->execute();

    return $pruned;
}

// Shared by the initial page render and the ?api=table_html&which=invoices
// fragment endpoint, so the AJAX refresh can't drift from a full page load.
function renderInvoiceRows(array $invoices): string
{
    ob_start();
    foreach ($invoices as $inv):
        $isOverdue = (!in_array($inv['status'], ['paid', 'void'], true) && strtotime($inv['due_date']) < time());
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
                        $<?= number_format($inv['amount'], 2) ?></div>
                    <div style="color:var(--warning); font-weight:600;">
                        $<?= number_format($inv['amount'] - $inv['paid_amount'], 2) ?></div>
                <?php else: ?>
                    $<?= number_format($inv['amount'], 2) ?>
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
    ob_start();
    foreach ($clients as $c):
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
            <td>$<?= number_format($c['monthly_rate'], 2) ?>
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
            <td>$<?= number_format($c['total_billed'] ?? 0, 2) ?></td>
            <td style="color: var(--success);">$<?= number_format($c['total_paid'] ?? 0, 2) ?></td>
            <td
                style="color: <?= (($c['total_billed'] - $c['total_paid']) > 0) ? 'var(--warning)' : 'inherit' ?>">
                $<?= number_format(max(0, $c['total_billed'] - $c['total_paid']), 2) ?></td>
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

// Same idea for the Quotes table — takes the mysqli result directly since the
// original inline block used a while() over the live query rather than an array.
function renderQuoteRows($qRes): string
{
    ob_start();
    while ($q = $qRes->fetch_assoc()):
        $__quoteExpired = !empty($q['quote_expires_at']) && $q['quote_expires_at'] < date('Y-m-d');
        ?>
        <tr>
            <td><input type="checkbox" class="quote-select-cb" value="<?= $q['id'] ?>" data-expired="<?= $__quoteExpired ? '1' : '0' ?>" onchange="updateQuoteBulkBar()"></td>
            <td><strong><?= htmlspecialchars($q['invoice_number']) ?></strong></td>
            <td><?= htmlspecialchars($q['client_name']) ?></td>
            <td><?= htmlspecialchars(substr($q['invoice_date'], 0, 10)) ?></td>
            <td>$<?= number_format($q['amount'], 2) ?></td>
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

// Dashboard's alert strips + top stat cards — the parts that can change from
// actions taken elsewhere without the Dashboard tab being reloaded.
function renderDashboardStats(array $settings, array $failedInvoices, array $overdueInvoices, float $total_invoiced, float $total_monthly, float $total_paid, int $client_count): string
{
    ob_start();
    ?>
    <?php if (count($failedInvoices) > 0): ?>
        <div class="alert-strip" style="background:var(--danger); color:white; border:none; margin-bottom:1rem;"><i
                class="fa-solid fa-triangle-exclamation" style="color:white;"></i>
            <div><strong><?= count($failedInvoices) ?> Failed
                    Email<?= count($failedInvoices) > 1 ? 's' : '' ?>!</strong> Invoice emails failed to send.
                Please check the Audit Log for details.</div>
            <button class="btn small"
                style="margin-left: auto; background:rgba(255,255,255,0.2); color:white; border:none;"
                onclick="nav('audit', true)">View Audit Log</button>
        </div>
    <?php endif; ?>
    <?php if (count($overdueInvoices) > 0): ?>
        <div class="alert-strip"><i class="fa-solid fa-circle-exclamation"></i>
            <div><strong><?= count($overdueInvoices) ?> Overdue Invoices!</strong> You have <?= htmlspecialchars($settings['currency'] ?? 'USD') ?>
                $<?= number_format(array_sum(array_column($overdueInvoices, 'amount')), 2) ?> in outstanding overdue
                payments.</div><button class="btn small"
                style="margin-left: auto; border-color: var(--danger); color: var(--danger);"
                onclick="nav('invoices'); document.getElementById('invoiceStatusFilter').value = 'overdue'; filterInvoicesByStatus('overdue');">View
                All</button>
        </div>
    <?php endif; ?>
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-card-top">
                <div class="stat-title">Total Invoiced (All Time)</div>
                <div class="stat-icon"><i class="fa-solid fa-sack-dollar"></i></div>
            </div>
            <div class="stat-value"><?= htmlspecialchars($settings['currency'] ?? 'USD') ?> $<?= number_format($total_invoiced, 2) ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-card-top">
                <div class="stat-title">This Month</div>
                <div class="stat-icon success"><i class="fa-solid fa-calendar-check"></i></div>
            </div>
            <div class="stat-value" style="color: var(--success)"><?= htmlspecialchars($settings['currency'] ?? 'USD') ?> $<?= number_format($total_monthly, 2) ?>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-card-top">
                <div class="stat-title">Total Outstanding</div>
                <div class="stat-icon warning"><i class="fa-solid fa-hourglass-half"></i></div>
            </div>
            <div class="stat-value" style="color: var(--warning)"><?= htmlspecialchars($settings['currency'] ?? 'USD') ?>
                $<?= number_format($total_invoiced - $total_paid, 2) ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-card-top">
                <div class="stat-title">Active Clients</div>
                <div class="stat-icon"><i class="fa-solid fa-users"></i></div>
            </div>
            <div class="stat-value"><?= $client_count ?></div>
        </div>
    </div>
    <?php
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

// The entire Statistics & Forecasting tab — read-only, derived-on-render
// content with no client-side state to preserve, so it renders the whole tab
// body rather than being split row-by-row like the functions above. Pulls
// its ~15 $stats_* inputs via `global` rather than a long parameter list.
function renderStatsSection(): string
{
    global $licenseValid;
    global $taxYearLabel, $stats_ty_invoiced, $stats_ty_paid, $stats_ty_outstanding,
    $stats_all_time_revenue, $stats_outstanding_revenue, $stats_overdue_count, $stats_mrr, $stats_avg_invoice,
    $stats_12m_projected, $stats_avg_days, $stats_active_clients, $stats_inactive_clients, $stats_client_ratio,
    $top_clients, $stats_db_rows, $backup_count, $latest_backup, $all_tables_info,
    $stats_void_count, $stats_void_amount, $stats_quote_pipeline_count, $stats_quote_pipeline_value, $stats_aging,
    $stats_new_clients_month, $stats_billing_freq, $clients_needing_attention,
    $stats_email_sent, $stats_email_failed, $stats_email_total, $stats_email_success_rate,
    $stats_ty_monthly, $stats_tax_year_days_total, $stats_tax_year_days_elapsed, $stats_tax_year_progress_pct,
    $stats_last_recurring_run, $stats_late_fees_charged, $stats_reminders_sent, $stats_reminders_failed,
    $most_active_clients;
    ob_start();
    ?>
    <h2 class="page-title">Data Statistics &amp; Forecasting</h2>
    <?php if (!$licenseValid): ?>
        <div class="card" style="border-left:3px solid var(--warning); margin: 0 1.5rem 1.75rem;">
            <div class="card-body" style="display:flex; align-items:center; gap:0.75rem; padding:1rem 1.25rem;">
                <i class="fa-solid fa-lock" style="color:var(--warning); font-size:1.1rem;"></i>
                <div><strong>Reporting &amp; Statistics requires a license.</strong>
                    <span style="color:var(--text-secondary); font-size:0.85rem; display:block; margin-top:0.15rem;">
                        This is what you get — everything below is a live preview of your own real data, view-only
                        until you add a key. The Dashboard's own basic totals stay free either way.</span>
                </div>
                <button class="btn primary" style="margin-left:auto; white-space:nowrap;"
                    onclick="nav('settings', true); navSettings('license');"><i class="fa-solid fa-key"></i> Add a
                    License Key</button>
            </div>
        </div>
    <?php endif; ?>
    <div class="section-scroll">
    <div class="subnav-layout">

        <nav class="subnav">
            <button type="button" class="subnav-item active" data-stats-target="revenue"
                onclick="navStats('revenue')"><i class="fa-solid fa-sack-dollar"></i> Revenue</button>
            <button type="button" class="subnav-item" data-stats-target="forecasting"
                onclick="navStats('forecasting')"><i class="fa-solid fa-chart-line"></i> Forecasting</button>
            <button type="button" class="subnav-item" data-stats-target="clients"
                onclick="navStats('clients')"><i class="fa-solid fa-users"></i> Clients</button>
            <button type="button" class="subnav-item" data-stats-target="tax"
                onclick="navStats('tax')"><i class="fa-solid fa-file-invoice-dollar"></i> Tax &amp; Compliance</button>
            <button type="button" class="subnav-item" data-stats-target="activity"
                onclick="navStats('activity')"><i class="fa-solid fa-bolt"></i> Activity</button>
            <button type="button" class="subnav-item" data-stats-target="system"
                onclick="navStats('system')"><i class="fa-solid fa-server"></i> System</button>
        </nav>

        <div class="subnav-content" style="<?= $licenseValid ? '' : 'opacity:0.5; pointer-events:none; user-select:none;' ?>">

            <!-- Revenue -->
            <div class="subnav-pane active" id="stats-pane-revenue">
                <div class="mobile-grid" style="display:grid; grid-template-columns:1.3fr 1fr; gap:1rem; align-items:stretch;">
                <div class="card" style="margin-bottom:0;">
                    <div class="card-header">
                        <h3>Tax Year Summary (<?= $taxYearLabel ?>)</h3>
                    </div>
                    <div class="card-body">
                        <div class="stats-grid" style="margin-bottom: 0;">
                            <div class="stat-card" style="border-top: 3px solid #3b82f6;">
                                <div class="label">Total Invoiced</div>
                                <div class="value">$<?= number_format($stats_ty_invoiced, 2) ?></div>
                            </div>
                            <div class="stat-card" style="border-top: 3px solid #10b981;">
                                <div class="label">Total Paid</div>
                                <div class="value">$<?= number_format($stats_ty_paid, 2) ?></div>
                            </div>
                            <div class="stat-card"
                                style="border-top: 3px solid <?= $stats_ty_outstanding > 0 ? '#f59e0b' : '#10b981' ?>;">
                                <div class="label">Outstanding</div>
                                <div class="value">$<?= number_format($stats_ty_outstanding, 2) ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card" style="margin-bottom:0;">
                    <div class="card-header">
                        <h3>Revenue Breakdown</h3>
                    </div>
                    <div class="card-body">
                        <div style="height:200px; position:relative;"><canvas id="revenueBreakdownChart"></canvas></div>
                    </div>
                    <script>
                        window.__revenueBreakdownData = {
                            invoiced: <?= json_encode((float) $stats_ty_invoiced) ?>,
                            paid: <?= json_encode((float) $stats_ty_paid) ?>,
                            outstanding: <?= json_encode((float) $stats_ty_outstanding) ?>
                        };
                    </script>
                </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h3>Financial Summary (All-Time)</h3>
                    </div>
                    <div class="card-body">
                        <div class="stats-grid" style="margin-bottom: 0;">
                            <div class="stat-card" style="border-top: 3px solid #10b981;">
                                <div class="label">All-Time Revenue</div>
                                <div class="value">$<?= number_format($stats_all_time_revenue, 2) ?></div>
                            </div>
                            <div class="stat-card" style="border-top: 3px solid #ef4444;">
                                <div class="label">Outstanding Receivables</div>
                                <div class="value">$<?= number_format($stats_outstanding_revenue, 2) ?> <span
                                        style="font-size: 1rem; color: var(--text-secondary); font-weight: normal;">(<?= $stats_overdue_count ?>
                                        overdue)</span></div>
                            </div>
                            <div class="stat-card" style="border-top: 3px solid var(--warning);">
                                <div class="label">Monthly Recurring (<span class="has-tooltip"
                                        data-tip="Monthly Recurring Revenue — total fixed monthly fees from active clients">MRR</span>)
                                </div>
                                <div class="value">$<?= number_format($stats_mrr, 2) ?></div>
                            </div>
                            <div class="stat-card" style="border-top: 3px solid #3b82f6;">
                                <div class="label">Average Invoice Value</div>
                                <div class="value">$<?= number_format($stats_avg_invoice, 2) ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h3>Quotes &amp; Voided Invoices</h3>
                    </div>
                    <div class="card-body">
                        <div class="stats-grid" style="margin-bottom: 0;">
                            <div class="stat-card" style="border-top: 3px solid #8b5cf6;">
                                <div class="label">Open Quote Pipeline</div>
                                <div class="value">$<?= number_format($stats_quote_pipeline_value, 2) ?> <span
                                        style="font-size: 1rem; color: var(--text-secondary); font-weight: normal;">(<?= $stats_quote_pipeline_count ?>
                                        open)</span></div>
                            </div>
                            <div class="stat-card" style="border-top: 3px solid var(--text-secondary);">
                                <div class="label">Voided (All-Time)</div>
                                <div class="value">$<?= number_format($stats_void_amount, 2) ?> <span
                                        style="font-size: 1rem; color: var(--text-secondary); font-weight: normal;">(<?= $stats_void_count ?>
                                        invoice<?= $stats_void_count === 1 ? '' : 's' ?>)</span></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Forecasting -->
            <div class="subnav-pane" id="stats-pane-forecasting">
                <div class="card">
                    <div class="card-header">
                        <h3>12-Month Forecasting</h3>
                    </div>
                    <div class="card-body">
                        <p style="color:var(--text-secondary); margin-bottom: 1rem; font-size:0.875rem;">Projected
                            earnings based on
                            active client subscriptions and currently outstanding invoices.</p>
                        <ul
                            style="list-style:none; padding:0; margin:0; display:flex; flex-direction:column; gap:0.75rem;">
                            <li
                                style="display:flex; justify-content:space-between; border-bottom:1px solid rgba(16,185,129,0.3); padding-bottom:0.6rem; background:rgba(16,185,129,0.08); border-radius:6px; padding:0.5rem 0.6rem; margin-bottom:0.25rem;">
                                <span style="color:#10b981; font-weight:600;">Expected Yearly Value:</span>
                                <strong style="color:#10b981;">$<?= number_format($stats_12m_projected, 2) ?></strong>
                            </li>
                            <li
                                style="display:flex; justify-content:space-between; border-bottom:1px solid rgba(255,255,255,0.05); padding-bottom:0.6rem;">
                                <span style="color:var(--text-secondary);">Recurring (<span class="has-tooltip"
                                        data-tip="Monthly Recurring Revenue × 12 months">MRR</span> × 12):</span>
                                <strong style="color:var(--warning);">$<?= number_format($stats_mrr * 12, 2) ?></strong>
                            </li>
                            <li
                                style="display:flex; justify-content:space-between; border-bottom:1px solid rgba(255,255,255,0.05); padding-bottom:0.6rem;">
                                <span style="color:var(--text-secondary);">Outstanding Invoices:</span>
                                <strong
                                    style="color:#f59e0b;">$<?= number_format($stats_outstanding_revenue, 2) ?></strong>
                            </li>
                            <li
                                style="display:flex; justify-content:space-between; border-bottom:1px solid rgba(255,255,255,0.05); padding-bottom:0.6rem;">
                                <span style="color:var(--text-secondary);"><span class="has-tooltip"
                                        data-tip="MRR alone — what you'd expect month-to-month once the current outstanding balance is collected, not a smoothed average of the one-off backlog">Recurring
                                        Monthly Avg</span>:</span>
                                <strong style="color:#10b981;">$<?= number_format($stats_mrr, 2) ?></strong>
                            </li>
                            <li style="display:flex; justify-content:space-between;">
                                <span style="color:var(--text-secondary);"><span class="has-tooltip"
                                        data-tip="How much of the yearly forecast comes from predictable MRR vs one-off outstanding invoices">MRR
                                        Contribution</span>:</span>
                                <strong><?= $stats_12m_projected > 0 ? number_format(($stats_mrr * 12 / $stats_12m_projected) * 100, 1) : '0.0' ?>%</strong>
                            </li>
                        </ul>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h3>Accounts Receivable Aging</h3>
                    </div>
                    <div class="card-body">
                        <p style="color:var(--text-secondary); margin-bottom: 1rem; font-size:0.875rem;">How overdue
                            the currently outstanding balance is, bucketed by days past due date.</p>
                        <?php $stats_aging_max = max(1, ...array_column($stats_aging, 'amount')); ?>
                        <ul style="list-style:none; padding:0; margin:0; display:flex; flex-direction:column; gap:0.75rem;">
                            <?php foreach ($stats_aging as $bucket): ?>
                                <li>
                                    <div style="display:flex; justify-content:space-between; margin-bottom:0.3rem; font-size:0.85rem;">
                                        <span style="color:var(--text-secondary);"><?= htmlspecialchars($bucket['label']) ?>
                                            <span style="color:var(--text-secondary);">(<?= $bucket['count'] ?>)</span></span>
                                        <strong>$<?= number_format($bucket['amount'], 2) ?></strong>
                                    </div>
                                    <div style="background:var(--surface-hover); border-radius:4px; height:8px; overflow:hidden;">
                                        <div style="background:<?= $bucket['color'] ?>; height:100%; width:<?= round($bucket['amount'] / $stats_aging_max * 100, 1) ?>%;">
                                        </div>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Clients -->
            <div class="subnav-pane" id="stats-pane-clients">
                <div style="display: grid; grid-template-columns: 1fr; gap: 1.5rem;">
                <div class="mobile-grid" style="display:grid; grid-template-columns:1fr 1.2fr; gap:1.5rem; align-items:start;">
                    <div class="card" style="margin-bottom:0;">
                        <div class="card-header">
                            <h3>Client & Payment Insights</h3>
                        </div>
                        <div class="card-body">
                            <ul
                                style="list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 1rem;">
                                <li
                                    style="display: flex; justify-content: space-between; border-bottom: 1px solid rgba(255,255,255,0.05); padding-bottom: 0.5rem;">
                                    <span style="color:var(--text-secondary);"><span class="has-tooltip"
                                            data-tip="Average days from invoice date to payment, based on invoices paid in the last 3 months">Payment
                                            Velocity</span>:</span>
                                    <strong><?= $stats_avg_days ?> Days Avg</strong>
                                </li>
                                <li
                                    style="display: flex; justify-content: space-between; border-bottom: 1px solid rgba(255,255,255,0.05); padding-bottom: 0.5rem;">
                                    <span style="color:var(--text-secondary);">Active Clients:</span>
                                    <strong style="color:#10b981;"><?= $stats_active_clients ?></strong>
                                </li>
                                <li
                                    style="display: flex; justify-content: space-between; border-bottom: 1px solid rgba(255,255,255,0.05); padding-bottom: 0.5rem;">
                                    <span style="color:var(--text-secondary);">Inactive Clients:</span>
                                    <strong style="color:#ef4444;"><?= $stats_inactive_clients ?></strong>
                                </li>
                                <li
                                    style="display: flex; justify-content: space-between; border-bottom: 1px solid rgba(255,255,255,0.05); padding-bottom: 0.5rem;">
                                    <span style="color:var(--text-secondary);">Active/Inactive Ratio:</span>
                                    <strong><?= $stats_client_ratio ?></strong>
                                </li>
                                <li
                                    style="display: flex; justify-content: space-between; border-bottom: 1px solid rgba(255,255,255,0.05); padding-bottom: 0.5rem;">
                                    <span style="color:var(--text-secondary);">New Clients This Month:</span>
                                    <strong style="color:#10b981;">+<?= $stats_new_clients_month ?></strong>
                                </li>
                                <li style="display: flex; justify-content: space-between; align-items:flex-start;">
                                    <span style="color:var(--text-secondary); padding-top:0.15rem;">Billing Frequency:</span>
                                    <strong style="text-align:right; font-weight:500;">
                                        <?php
                                        $freqLabels = ['weekly' => 'Weekly', 'monthly' => 'Monthly', 'quarterly' => 'Quarterly', 'annually' => 'Annually'];
                                        $freqParts = [];
                                        foreach ($freqLabels as $fkey => $flabel) {
                                            if (!empty($stats_billing_freq[$fkey]))
                                                $freqParts[] = $flabel . ': ' . $stats_billing_freq[$fkey];
                                        }
                                        echo htmlspecialchars($freqParts ? implode(' · ', $freqParts) : 'No active clients');
                                        ?>
                                    </strong>
                                </li>
                            </ul>
                        </div>
                    </div>

                    <div class="card" style="margin-bottom:0;">
                        <div class="card-header">
                            <h3>Top 5 Clients (By Paid Revenue)</h3>
                        </div>
                        <?php if (!empty($top_clients)): ?>
                            <div class="card-body">
                                <div style="height:<?= max(140, count($top_clients) * 44) ?>px; position:relative;"><canvas id="topClientsChart"></canvas></div>
                            </div>
                            <script>
                                window.__topClientsData = <?= json_encode(array_map(fn($tc) => ['name' => $tc['client_name'], 'revenue' => (float) $tc['total_revenue']], $top_clients)) ?>;
                            </script>
                        <?php endif; ?>
                        <div class="card-body" style="padding: 0; <?= !empty($top_clients) ? 'border-top:1px solid var(--border);' : '' ?>">
                            <table style="width: 100%; border-collapse: collapse; text-align: left;">
                                <thead>
                                    <tr
                                        style="border-bottom: 1px solid var(--border); color: var(--text-secondary); font-size: 0.85rem; text-transform: uppercase;">
                                        <th style="padding: 1rem;">Client Name</th>
                                        <th style="padding: 1rem; text-align: right;">Total Paid Revenue</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($top_clients)): ?>
                                        <tr>
                                            <td colspan="2"
                                                style="padding: 1rem; text-align: center; color: var(--text-secondary);">No data
                                                yet</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($top_clients as $index => $tc): ?>
                                            <tr style="border-bottom: 1px solid rgba(255,255,255,0.05);">
                                                <td style="padding: 1rem;">
                                                    <?= ($index == 0) ? '<i class="fa-solid fa-crown" style="color: #f59e0b; margin-right: 0.5rem;"></i>' : '' ?>
                                                    <?= htmlspecialchars($tc['client_name']) ?>
                                                </td>
                                                <td style="padding: 1rem; text-align: right; font-weight: 600; color: #10b981;">
                                                    $<?= number_format($tc['total_revenue'], 2) ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                    <div class="card">
                        <div class="card-header">
                            <h3>Clients Needing Attention <span class="has-tooltip"
                                    data-tip="Active clients with no invoice in the last 60+ days">?</span></h3>
                        </div>
                        <div class="card-body" style="padding: 0;">
                            <table style="width: 100%; border-collapse: collapse; text-align: left;">
                                <thead>
                                    <tr
                                        style="border-bottom: 1px solid var(--border); color: var(--text-secondary); font-size: 0.85rem; text-transform: uppercase;">
                                        <th style="padding: 1rem;">Client Name</th>
                                        <th style="padding: 1rem; text-align: right;">Last Invoice</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($clients_needing_attention)): ?>
                                        <tr>
                                            <td colspan="2"
                                                style="padding: 1rem; text-align: center; color: var(--text-secondary);">
                                                Every active client has been invoiced within the last 60 days.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($clients_needing_attention as $ca): ?>
                                            <tr style="border-bottom: 1px solid rgba(255,255,255,0.05);">
                                                <td style="padding: 1rem;"><?= htmlspecialchars($ca['client_name']) ?></td>
                                                <td style="padding: 1rem; text-align: right; color: var(--warning);">
                                                    <?= $ca['last_invoice'] ? htmlspecialchars(date('Y-m-d', strtotime($ca['last_invoice']))) : 'Never' ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tax & Compliance -->
            <div class="subnav-pane" id="stats-pane-tax">
                <div class="card">
                    <div class="card-header">
                        <h3>Tax Year Progress (<?= htmlspecialchars($taxYearLabel) ?>)</h3>
                    </div>
                    <div class="card-body">
                        <div style="display:flex; justify-content:space-between; margin-bottom:0.4rem; font-size:0.85rem; color:var(--text-secondary);">
                            <span>Day <?= $stats_tax_year_days_elapsed ?> of <?= $stats_tax_year_days_total ?></span>
                            <span><?= $stats_tax_year_progress_pct ?>% elapsed</span>
                        </div>
                        <div style="background:var(--surface-hover); border-radius:4px; height:10px; overflow:hidden;">
                            <div style="background:var(--accent); height:100%; width:<?= $stats_tax_year_progress_pct ?>%;">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h3>Monthly Breakdown</h3>
                    </div>
                    <div class="card-body">
                        <div style="height:280px; position:relative;"><canvas id="taxMonthlyChart"></canvas></div>
                    </div>
                    <script>
                        window.__taxMonthlyData = <?= json_encode($stats_ty_monthly) ?>;
                    </script>
                    <div class="card-body" style="padding: 0; border-top:1px solid var(--border);">
                        <table style="width: 100%; border-collapse: collapse; text-align: left;">
                            <thead>
                                <tr
                                    style="border-bottom: 1px solid var(--border); color: var(--text-secondary); font-size: 0.85rem; text-transform: uppercase;">
                                    <th style="padding: 1rem;">Month</th>
                                    <th style="padding: 1rem; text-align: right;">Invoiced</th>
                                    <th style="padding: 1rem; text-align: right;">Paid</th>
                                    <th style="padding: 1rem; text-align: right;">Outstanding</th>
                                    <th style="padding: 1rem; text-align: right;">Unpaid Invoices</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($stats_ty_monthly)): ?>
                                    <tr>
                                        <td colspan="5"
                                            style="padding: 1rem; text-align: center; color: var(--text-secondary);">No
                                            invoices yet this tax year</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($stats_ty_monthly as $tym): ?>
                                        <tr style="border-bottom: 1px solid rgba(255,255,255,0.05);">
                                            <td style="padding: 1rem;"><?= htmlspecialchars($tym['month']) ?></td>
                                            <td style="padding: 1rem; text-align: right;">$<?= number_format($tym['total_invoiced'], 2) ?></td>
                                            <td style="padding: 1rem; text-align: right; color:#10b981;">$<?= number_format($tym['total_paid'], 2) ?></td>
                                            <td style="padding: 1rem; text-align: right; color:var(--warning);">$<?= number_format($tym['outstanding'], 2) ?></td>
                                            <td style="padding: 1rem; text-align: right;"><?= (int) $tym['unpaid_count'] ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Activity -->
            <div class="subnav-pane" id="stats-pane-activity">
                <div class="card">
                    <div class="card-header">
                        <h3>Recurring Billing</h3>
                    </div>
                    <div class="card-body">
                        <?php if ($stats_last_recurring_run): ?>
                            <p style="color:var(--text-secondary); font-size:0.8rem; margin-bottom:0.3rem;">Last run:
                                <?= htmlspecialchars($stats_last_recurring_run['performed_at']) ?></p>
                            <p style="margin:0 0 1rem;"><?= htmlspecialchars($stats_last_recurring_run['notes']) ?></p>
                        <?php else: ?>
                            <p style="color:var(--text-secondary); margin-bottom:1rem;">Recurring billing hasn't run
                                yet — see Settings &gt; Billing.</p>
                        <?php endif; ?>
                        <div style="display:flex; gap:2rem; flex-wrap:wrap; border-top:1px solid var(--border); padding-top:1rem;">
                            <div>
                                <p style="color:var(--text-secondary); margin-bottom:0.5rem;">Reminders Sent (All-Time):</p>
                                <div style="font-size:1.2rem; font-weight:700;"><?= number_format($stats_reminders_sent) ?>
                                    <?php if ($stats_reminders_failed > 0): ?>
                                        <span style="font-size:0.9rem; font-weight:400; color:var(--danger);"><?= $stats_reminders_failed ?>
                                            failed</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div>
                                <p style="color:var(--text-secondary); margin-bottom:0.5rem;">Late Fees Charged (All-Time):</p>
                                <div style="font-size:1.2rem; font-weight:700;"><?= number_format($stats_late_fees_charged) ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h3>Most Active Clients (By Invoice Count)</h3>
                    </div>
                    <?php if (!empty($most_active_clients)): ?>
                        <div class="card-body">
                            <div style="height:<?= max(140, count($most_active_clients) * 44) ?>px; position:relative;"><canvas id="activeClientsChart"></canvas></div>
                        </div>
                        <script>
                            window.__activeClientsData = <?= json_encode(array_map(fn($ac) => ['name' => $ac['client_name'], 'count' => (int) $ac['invoice_count']], $most_active_clients)) ?>;
                        </script>
                    <?php endif; ?>
                    <div class="card-body" style="padding: 0; <?= !empty($most_active_clients) ? 'border-top:1px solid var(--border);' : '' ?>">
                        <table style="width: 100%; border-collapse: collapse; text-align: left;">
                            <thead>
                                <tr
                                    style="border-bottom: 1px solid var(--border); color: var(--text-secondary); font-size: 0.85rem; text-transform: uppercase;">
                                    <th style="padding: 1rem;">Client Name</th>
                                    <th style="padding: 1rem; text-align: right;">Invoices</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($most_active_clients)): ?>
                                    <tr>
                                        <td colspan="2"
                                            style="padding: 1rem; text-align: center; color: var(--text-secondary);">No
                                            data yet</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($most_active_clients as $ac): ?>
                                        <tr style="border-bottom: 1px solid rgba(255,255,255,0.05);">
                                            <td style="padding: 1rem;"><?= htmlspecialchars($ac['client_name']) ?></td>
                                            <td style="padding: 1rem; text-align: right; font-weight: 600;">
                                                <?= (int) $ac['invoice_count'] ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- System -->
            <div class="subnav-pane" id="stats-pane-system">
                <div class="card">
                    <div class="card-header">
                        <h3>Email Delivery Health</h3>
                    </div>
                    <div class="card-body">
                        <div style="display: flex; gap: 2rem; flex-wrap: wrap; align-items:center;">
                            <div>
                                <p style="color:var(--text-secondary); margin-bottom: 0.5rem;">Success Rate (All-Time):</p>
                                <div style="font-size: 1.5rem; font-weight: 700; color: <?= $stats_email_success_rate >= 95 ? '#10b981' : ($stats_email_success_rate >= 80 ? 'var(--warning)' : 'var(--danger)') ?>;">
                                    <?= $stats_email_success_rate ?>%</div>
                            </div>
                            <div>
                                <p style="color:var(--text-secondary); margin-bottom: 0.5rem;">Sent:</p>
                                <div style="font-size: 1.2rem; font-weight: 700; color:#10b981;"><?= number_format($stats_email_sent) ?></div>
                            </div>
                            <div>
                                <p style="color:var(--text-secondary); margin-bottom: 0.5rem;">Failed:</p>
                                <div style="font-size: 1.2rem; font-weight: 700; color:<?= $stats_email_failed > 0 ? 'var(--danger)' : 'var(--text-secondary)' ?>;">
                                    <?= number_format($stats_email_failed) ?></div>
                            </div>
                            <?php if ($stats_email_total > 0): ?>
                                <div style="height:110px; width:110px; position:relative; margin-left:auto;">
                                    <canvas id="emailHealthChart"></canvas>
                                </div>
                                <script>
                                    window.__emailHealthData = { sent: <?= (int) $stats_email_sent ?>, failed: <?= (int) $stats_email_failed ?> };
                                </script>
                            <?php endif; ?>
                        </div>
                        <?php if ($stats_email_failed > 0): ?>
                            <p style="color:var(--text-secondary); font-size:0.8rem; margin-top:1rem; margin-bottom:0;">
                                Check the Audit Log for individual <code>email_failed</code> entries — usually an SMTP
                                config or bad recipient address issue.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h3>System Health</h3>
                    </div>
                    <div class="card-body">
                        <div style="display: flex; gap: 2rem; margin-bottom: 1.5rem;">
                            <div>
                                <p style="color:var(--text-secondary); margin-bottom: 0.5rem;">Database Rows Evaluated:
                                </p>
                                <div style="font-size: 1.5rem; font-weight: 700;"><?= number_format($stats_db_rows) ?>
                                </div>
                            </div>
                            <div>
                                <p style="color:var(--text-secondary); margin-bottom: 0.5rem;">Backup Storage Health:
                                </p>
                                <div style="font-size: 1.2rem; font-weight: 700; color: #10b981;"><?= $backup_count ?>
                                    Files</div>
                                <div style="font-size: 0.8rem; color: var(--text-secondary);">Last Backup:
                                    <?= $latest_backup ?>
                                </div>
                            </div>
                        </div>

                        <div
                            style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                            <p style="color:var(--text-secondary); margin: 0; font-weight: 600;">Tables in Database:</p>
                            <label
                                style="font-size: 0.8rem; cursor: pointer; color: var(--text-secondary); display: flex; align-items: center; gap: 0.3rem;">
                                <input type="checkbox" onchange="toggleOtherTables('stats', this.checked)"> Show all
                                tables
                            </label>
                        </div>
                        <div
                            style="max-height: 200px; overflow-y: auto; background: var(--surface-hover); padding: 0.5rem; border-radius: 4px; border: 1px solid var(--border);">
                            <ul style="list-style: none; padding: 0; margin: 0; font-size: 0.85rem;">
                                <?php foreach ($all_tables_info as $tName => $tRows): ?>
                                    <?php $isInvoxa = (strpos($tName, 'invoxa_') === 0); ?>
                                    <li class="stat-table-item <?= $isInvoxa ? 'invoxa-table' : 'other-table' ?>"
                                        style="<?= !$isInvoxa ? 'display:none;' : 'display:flex;' ?> justify-content: space-between; padding: 0.3rem 0; border-bottom: 1px solid var(--border);">
                                        <span style="color: var(--text-primary);"><?= htmlspecialchars($tName) ?></span>
                                        <span style="color: #10b981; font-weight: 600;"><?= number_format($tRows) ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
    </div>
    <?php
    return ob_get_clean();
}

// The entire Filesystem Sync tab — same reasoning as renderStatsSection() above
// (no client-side state worth preserving across a refresh).
function renderSyncSection(array $missingFiles, array $knownClientFolders, array $missingDiskData): string
{
    ob_start();
    ?>
    <h2 class="page-title">Filesystem Sync
        <button class="btn" onclick="refreshSync()" title="Recheck sync status"><i
                class="fa-solid fa-rotate"></i> Refresh</button>
    </h2>
    <div class="section-scroll">
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: 1.5rem;">
        <div class="card" style="margin-bottom: 0;">
            <div class="card-header">
                <h3 style="margin:0; font-size: 1rem;">Untracked HTML Invoices</h3>
                <?php if (count($missingFiles) > 0): ?><button class="btn primary" id="syncBtn"
                        onclick="syncFiles()"><i class="fa-solid fa-download"></i> Import All
                        Missing</button><?php endif; ?>
            </div>
            <table class="datatable-table">
                <thead>
                    <tr>
                        <th>File Path</th>
                        <th>Client Match</th>
                        <th style="width:50px;"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($missingFiles) === 0): ?>
                        <tr>
                            <td colspan="3" class="empty-state"><i class="fa-solid fa-circle-check" style="color:var(--success);"></i>Everything is synced!</td>
                        </tr>
                    <?php else:
                        foreach ($missingFiles as $mf):
                            $mfFolder = explode('/', $mf)[1] ?? '';
                            $matched = isset($knownClientFolders[strtolower($mfFolder)]);
                            $clientLabel = $matched ? htmlspecialchars($knownClientFolders[strtolower($mfFolder)]) : htmlspecialchars($mfFolder) . ' <em style="color:var(--warning); font-size:0.8rem;">(no client &mdash; folder name used)</em>';
                            ?>
                            <tr>
                                <td style="font-family:monospace;"><?= htmlspecialchars($mf) ?></td>
                                <td><?php if ($matched): ?><i class="fa-solid fa-circle-check"
                                            style="color:var(--success); margin-right:0.4rem;"></i><?php else: ?><i
                                            class="fa-solid fa-triangle-exclamation"
                                            style="color:var(--warning); margin-right:0.4rem;"></i><?php endif; ?><?= $clientLabel ?>
                                </td>
                                <td><button class="btn small danger"
                                        onclick="deleteUntrackedFile('<?= htmlspecialchars($mf, ENT_QUOTES) ?>')"><i
                                            class="fa-solid fa-trash"></i></button></td>
                            </tr>
                        <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <div class="card" style="margin-bottom: 0;">
            <div class="card-header">
                <h3 style="margin:0; font-size: 1rem;">Missing HTML Files (In DB, missing on disk)</h3>
                <?php if (count($missingDiskData) > 0): ?>
                    <div style="display:flex; gap:0.5rem;">
                        <button class="btn danger" id="delDbBtn" onclick="deleteMissingDb()"><i
                                class="fa-solid fa-trash"></i> Delete All DB Entries</button>
                        <button class="btn primary" id="restoreBtn" onclick="restoreMissingFiles()"><i
                                class="fa-solid fa-file-export"></i> Rebuild HTML Files</button>
                    </div>
                <?php endif; ?>
            </div>
            <table class="datatable-table">
                <thead>
                    <tr>
                        <th>Invoice #</th>
                        <th>Expected File Path</th>
                        <th style="width:150px;">Rebuildable?</th>
                        <th style="width:50px;"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($missingDiskData) === 0): ?>
                        <tr>
                            <td colspan="4" class="empty-state"><i class="fa-solid fa-circle-check" style="color:var(--success);"></i>Everything is synced!</td>
                        </tr>
                    <?php else:
                        foreach ($missingDiskData as $md): ?>
                            <tr>
                                <td style="font-weight:600;"><?= htmlspecialchars($md['invoice_number']) ?></td>
                                <td style="font-family:monospace; color:var(--danger);">
                                    <?= htmlspecialchars($md['file_path']) ?>
                                </td>
                                <td>
                                    <?php if ($md['has_content']): ?>
                                        <span style="color:var(--success); font-size:0.8rem;"><i
                                                class="fa-solid fa-circle-check"></i> Yes</span>
                                    <?php else: ?>
                                        <span style="color:var(--text-secondary); font-size:0.8rem;"
                                            title="No content stored to rebuild from — likely an imported historical record without an original invoice file"><i
                                                class="fa-solid fa-circle-minus"></i> No content</span>
                                    <?php endif; ?>
                                </td>
                                <td><button class="btn small danger"
                                        onclick="deleteSingleDbEntry(<?= $md['id'] ?>, '<?= htmlspecialchars($md['invoice_number'], ENT_QUOTES) ?>')"><i
                                                class="fa-solid fa-trash"></i></button></td>
                            </tr>
                        <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    </div>
    <?php
    return ob_get_clean();
}

// The Audit Log tab — same reasoning as renderStatsSection() above (no
// client-side state worth preserving across a refresh).
function renderAuditSection(array $actions): string
{
    $icons = ['email_sent' => 'fa-envelope', 'email_failed' => 'fa-circle-xmark', 'mark_paid' => 'fa-check', 'manual_send' => 'fa-paper-plane', 'note_added' => 'fa-comment', 'synced' => 'fa-rotate', 'smtp_test' => 'fa-vial', 'reminder_sent' => 'fa-bell', 'reminder_failed' => 'fa-bell-slash', 'late_fee_charged' => 'fa-triangle-exclamation', 'recurring_run' => 'fa-arrows-rotate', 'audit_log_pruned' => 'fa-broom', 'invoice_voided' => 'fa-ban', 'invoice_unvoided' => 'fa-rotate-left', 'notification_test' => 'fa-paper-plane', 'notification_failed' => 'fa-circle-xmark', 'totp_enabled' => 'fa-shield-halved', 'totp_disabled' => 'fa-shield', 'refund_issued' => 'fa-rotate-left', 'webhook_unmatched' => 'fa-triangle-exclamation', 'api_token_created' => 'fa-key', 'api_token_revoked' => 'fa-ban', 'quote_accepted' => 'fa-file-circle-check', 'quote_converted' => 'fa-file-invoice'];
    ob_start();
    ?>
    <h2 class="page-title">Audit Log
        <button class="btn" onclick="refreshAuditSection()" title="Reload audit log"><i
                class="fa-solid fa-rotate"></i> Refresh</button>
    </h2>
    <!-- Deliberately a sibling of .section-scroll, not a child inside it — same
         reasoning as h2.page-title: this needs to stay put while the timeline
         below scrolls, and the robust way to do that is to keep it structurally
         outside the scrolling container rather than sticky-positioned inside it
         (a full-width sticky element there would need a background trick to hide
         scrolled content passing underneath, which is exactly what didn't work
         out for the page headings earlier). -->
    <div style="display:flex; gap:0.75rem; flex-wrap:wrap; margin-bottom:1rem; align-items:center; flex-shrink:0;">
        <input type="text" id="auditSearchInput" class="form-control" placeholder="Search client, invoice #, notes…"
            style="max-width:320px;" oninput="filterAuditLog()">
        <select id="auditTypeFilter" class="form-control" style="max-width:220px;" onchange="filterAuditLog()">
            <option value="">All Types</option>
            <?php foreach (array_keys($icons) as $type): ?>
                <option value="<?= htmlspecialchars($type) ?>"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $type))) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="section-scroll">
    <div class="card">
        <div class="card-body timeline" id="auditTimelineBody">
            <?php
            foreach ($actions as $act):
                $icon = $icons[$act['action_type']] ?? 'fa-bolt';
                $client = !empty($act['client_name']) ? htmlspecialchars($act['client_name']) : 'Unknown Client';
                $searchBlob = strtolower($client . ' ' . $act['invoice_number'] . ' ' . str_replace('_', ' ', $act['action_type']) . ' ' . ($act['notes'] ?? ''));
                ?>
                <div class="timeline-item" data-action-type="<?= htmlspecialchars($act['action_type']) ?>"
                    data-search="<?= htmlspecialchars($searchBlob) ?>">
                    <div class="timeline-icon"><i class="fa-solid <?= $icon ?>"></i></div>
                    <div class="timeline-content">
                        <div class="timeline-time"><?= date('M j, Y H:i', strtotime($act['performed_at'])) ?></div>
                        <div
                            style="font-size: 0.85rem; font-weight: 600; white-space: nowrap; min-width: 90px; color: var(--accent);">
                            Inv <?= htmlspecialchars($act['invoice_number']) ?></div>
                        <div style="font-size: 0.85rem; min-width: 140px; color: var(--text-secondary);"
                            title="<?= $client ?>"><i class="fa-solid fa-user"
                                style="font-size: 0.75rem; margin-right: 0.3rem;"></i><?= $client ?></div>
                        <div style="font-size: 0.85rem; color: var(--text-primary); flex: 1; min-width: 200px;">
                            <span
                                style="background: rgba(255,255,255,0.05); padding: 0.2rem 0.4rem; border-radius: 4px; border: 1px solid var(--border); font-size: 0.65rem; text-transform: uppercase; margin-right: 0.75rem; font-weight: 600; letter-spacing: 0.5px;"><?= htmlspecialchars(str_replace('_', ' ', $act['action_type'])) ?></span><?= htmlspecialchars($act['notes'] ?? '') ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
            <p id="auditNoResults" style="display:none; color:var(--text-secondary); text-align:center; padding:1.5rem 0; margin:0;">
                No entries match your search/filter.</p>
        </div>
    </div>
    </div>
    <?php
    return ob_get_clean();
}

// TEMPORARY backward-compat shim for buyers migrating off "weblab", the
// pre-Invoxa tool this product was built from: old backup_db-style exports
// use weblab_actions/weblab_clients/weblab_invoices/weblab_settings/weblab_users
// instead of invoxa_*. Remove once the migration window has passed — exports
// from Invoxa itself never contain "weblab_". Scoped to the four statement
// keywords that name a table, not a blind string replace, so it can't touch
// "weblab" inside actual data (invoice HTML, notes, emails, etc.).
function invoxaRemapLegacyTableNames(string $sql, ?bool &$didRemap = null): string
{
    $didRemap = false;
    $pattern = '/\b(DROP TABLE IF EXISTS\s+`?|CREATE TABLE\s+`?|INSERT INTO\s+`?|ALTER TABLE\s+`?)weblab_/i';
    $result = preg_replace($pattern, '$1invoxa_', $sql, -1, $count);
    $didRemap = $count > 0;
    return $result;
}

// Demo/sample data (Data Management > Demo Data) — every seeded client_key
// starts with 'dm' so it can be found and torn down precisely.
const INVOXA_DEMO_CLIENT_KEY_PREFIX = 'dm';

function clearDemoData($mysqli): int
{
    $res = $mysqli->query("SELECT client_key, client_name FROM invoxa_clients WHERE client_key LIKE '" . INVOXA_DEMO_CLIENT_KEY_PREFIX . "%'");
    $keys = [];
    $folders = [];
    while ($row = $res->fetch_assoc()) {
        $keys[] = $row['client_key'];
        $folders[] = strtolower(str_replace(' ', '_', $row['client_name']));
    }
    if (!$keys) {
        return 0;
    }
    $inList = "'" . implode("','", array_map([$mysqli, 'real_escape_string'], $keys)) . "'";
    $idsRes = $mysqli->query("SELECT id FROM invoxa_invoices WHERE client_key IN ($inList)");
    $ids = [];
    while ($r = $idsRes->fetch_assoc()) {
        $ids[] = (int) $r['id'];
    }
    if ($ids) {
        $mysqli->query("DELETE FROM invoxa_actions WHERE invoice_id IN (" . implode(',', $ids) . ")");
        $mysqli->query("DELETE FROM invoxa_payments WHERE invoice_id IN (" . implode(',', $ids) . ")");
    }
    $mysqli->query("DELETE FROM invoxa_invoices WHERE client_key IN ($inList)");
    $mysqli->query("DELETE FROM invoxa_clients WHERE client_key IN ($inList)");
    foreach (array_unique($folders) as $folder) {
        $dir = INVOICES_DIR . $folder;
        if (is_dir($dir)) {
            foreach (glob($dir . '/*') as $f) {
                if (is_file($f)) {
                    @unlink($f);
                }
            }
            @rmdir($dir);
        }
    }
    return count($keys);
}

function seedDemoData($mysqli, array $settings): int
{
    // Clean slate first, so clicking "Insert" twice refreshes rather than duplicates.
    clearDemoData($mysqli);

    // "Hide Test Clients Globally" defaults ON, which would otherwise hide the
    // data this just inserted from the Invoices tab, Dashboard, and Stats.
    $mysqli->query("INSERT INTO invoxa_settings (setting_key, setting_value) VALUES ('hide_test', '0') ON DUPLICATE KEY UPDATE setting_value = '0'");

    $demoClients = [
        ['name' => 'Acme Web Co', 'rate' => 450, 'acc' => 'Acme Web Co', 'accnum' => '12-3001-0000001-00', 'desc' => 'Website hosting & maintenance'],
        ['name' => 'Blue Harbor Design', 'rate' => 800, 'acc' => 'Blue Harbor Design Ltd', 'accnum' => '12-3002-0000002-00', 'desc' => 'Design retainer'],
        ['name' => 'Nimbus Retail Group', 'rate' => 1200, 'acc' => 'Nimbus Retail Group', 'accnum' => '12-3003-0000003-00', 'desc' => 'E-commerce platform support'],
        ['name' => 'Golden Fern Bakery', 'rate' => 150, 'acc' => 'Golden Fern Bakery', 'accnum' => '12-3004-0000004-00', 'desc' => 'Website hosting'],
        ['name' => 'Ironclad Logistics', 'rate' => 950, 'acc' => 'Ironclad Logistics Ltd', 'accnum' => '12-3005-0000005-00', 'desc' => 'Systems support retainer'],
        ['name' => 'Willow Creek Studio', 'rate' => 300, 'acc' => 'Willow Creek Studio', 'accnum' => '12-3006-0000006-00', 'desc' => 'Website hosting & updates'],
    ];

    $brandColor = $settings['brand_color'] ?? '#4a90e2';
    $footerText = $settings['footer_text'] ?? '';
    $currencyCode = $settings['currency'] ?? (getenv('APP_CURRENCY') ?: 'USD');
    $fromEmail = getenv('SMTP_FROM_EMAIL') ?: '';
    $fingerprint = invoiceWatermarkFingerprint($settings);
    $monthsBack = 24;
    $today = new DateTime();
    $insertClient = $mysqli->prepare("INSERT INTO invoxa_clients (client_key, client_name, email, account_name, account_number, monthly_rate, is_active, is_test) VALUES (?, ?, ?, ?, ?, ?, 1, 1)");
    $insertInvoice = $mysqli->prepare("INSERT INTO invoxa_invoices (invoice_number, client_key, client_name, recipient_email, invoice_date, due_date, amount, status, paid_at, paid_amount, html_content, file_path, is_quote) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $insertAction = $mysqli->prepare("INSERT INTO invoxa_actions (invoice_id, invoice_number, action_type, notes) VALUES (?, ?, ?, ?)");

    foreach ($demoClients as $ci => $dc) {
        $clientKey = INVOXA_DEMO_CLIENT_KEY_PREFIX . sprintf('%02d', $ci + 1);
        $email = preg_replace('/[^a-z0-9]/', '', strtolower($dc['name'])) . '@example.com';
        $insertClient->bind_param("sssssd", $clientKey, $dc['name'], $email, $dc['acc'], $dc['accnum'], $dc['rate']);
        $insertClient->execute();

        $folderName = strtolower(str_replace(' ', '_', $dc['name']));
        $invoiceDir = INVOICES_DIR . $folderName;
        if (!is_dir($invoiceDir)) {
            @mkdir($invoiceDir, 0777, true);
        }

        for ($m = $monthsBack - 1; $m >= 0; $m--) {
            $invDate = (clone $today)->modify("-{$m} months")->modify('first day of this month')->modify('+' . (3 + $ci * 3) . ' days');
            if ($invDate > $today) {
                continue;
            }
            $dueDate = (clone $invDate)->modify('+3 weeks');
            $variance = 1 + (rand(-10, 10) / 100);
            $amount = round($dc['rate'] * $variance, 2);
            $isCurrentMonth = ($m === 0);
            $paid = !$isCurrentMonth && rand(1, 100) <= 80;
            $status = $paid ? 'paid' : 'sent';
            $paidAt = null;
            $paidAmount = null;
            if ($paid) {
                $paidAtDt = (clone $invDate)->modify('+' . rand(1, 12) . ' days');
                if ($paidAtDt > $today) {
                    $paidAtDt = clone $today;
                }
                $paidAt = $paidAtDt->format('Y-m-d');
                $paidAmount = $amount;
            }

            $seq = $monthsBack - $m;
            $invNum = strtoupper($clientKey) . sprintf('%03d', $seq);
            $lineItems = [['code' => 'WEB01', 'desc' => $dc['desc'] . ' — ' . $invDate->format('F Y'), 'amount' => number_format($amount, 2)]];
            $htmlContent = generateInvoiceHTML($dc['name'], $invDate->format('Y-m-d'), $dueDate->format('Y-m-d'), $invNum, number_format($amount, 2), $dc['acc'], $dc['accnum'], $fromEmail, $lineItems, $brandColor, $footerText, $currencyCode, $fingerprint);
            $htmlForFile = str_replace('src="cid:logo_cid"', 'src="' . INVOICES_URL . LOGO_FILENAME . '"', $htmlContent);
            @file_put_contents("$invoiceDir/$invNum.html", $htmlForFile);
            $relPath = "invoices/$folderName/$invNum.html";
            $invDateStr = $invDate->format('Y-m-d');
            $dueDateStr = $dueDate->format('Y-m-d');
            $isQuote = 0;

            $insertInvoice->bind_param("ssssssdsssssi", $invNum, $clientKey, $dc['name'], $email, $invDateStr, $dueDateStr, $amount, $status, $paidAt, $paidAmount, $htmlContent, $relPath, $isQuote);
            $insertInvoice->execute();
            $iid = $insertInvoice->insert_id;
            $actionType = $paid ? 'mark_paid' : 'email_sent';
            $notes = $paid ? 'Marked as paid: $' . number_format($amount, 2) : 'Invoice generated and emailed to ' . $email;
            $insertAction->bind_param("isss", $iid, $invNum, $actionType, $notes);
            $insertAction->execute();
        }

        // A couple of clients also get an open quote, so the Quotes tab isn't empty.
        if ($ci === 1 || $ci === 4) {
            $qDate = (clone $today)->modify('-' . rand(2, 10) . ' days');
            $qDue = (clone $qDate)->modify('+3 weeks');
            $qAmount = round($dc['rate'] * (1.5 + rand(0, 50) / 100), 2);
            $quoteNum = 'Q' . strtoupper($clientKey) . '001';
            $qLineItems = [['code' => 'PROJ01', 'desc' => 'Proposed project scope', 'amount' => number_format($qAmount, 2)]];
            $qHtml = generateInvoiceHTML($dc['name'], $qDate->format('Y-m-d'), $qDue->format('Y-m-d'), $quoteNum, number_format($qAmount, 2), $dc['acc'], $dc['accnum'], $fromEmail, $qLineItems, $brandColor, $footerText, $currencyCode, $fingerprint, documentType: 'Quote');
            $qHtmlForFile = str_replace('src="cid:logo_cid"', 'src="' . INVOICES_URL . LOGO_FILENAME . '"', $qHtml);
            @file_put_contents("$invoiceDir/$quoteNum.html", $qHtmlForFile);
            $qRelPath = "invoices/$folderName/$quoteNum.html";
            $qDateStr = $qDate->format('Y-m-d');
            $qDueStr = $qDue->format('Y-m-d');
            $qStatus = 'draft';
            $qPaidAt = null;
            $qPaidAmount = null;
            $qIsQuote = 1;
            $insertInvoice->bind_param("ssssssdsssssi", $quoteNum, $clientKey, $dc['name'], $email, $qDateStr, $qDueStr, $qAmount, $qStatus, $qPaidAt, $qPaidAmount, $qHtml, $qRelPath, $qIsQuote);
            $insertInvoice->execute();
        }
    }

    return count($demoClients);
}

function extractField(string $html, string $label): ?string
{
    if (preg_match('/<strong>' . preg_quote($label, '/') . ':<\/strong>\s*([^<]+)/i', $html, $m)) {
        return trim($m[1]);
    }
    return null;
}
function normaliseDateTime(?string $raw): ?string
{
    if (!$raw)
        return null;
    try {
        $dt = new DateTime(trim($raw));
        return $dt->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        return null;
    }
}
function normaliseDate(?string $raw): ?string
{
    if (!$raw)
        return null;
    try {
        $dt = new DateTime(trim($raw));
        return $dt->format('Y-m-d');
    } catch (Exception $e) {
        return null;
    }
}

// Fiscal/tax-year start relative to "now" — driven by the tax_year_start_month setting
// (default January = calendar year) rather than a hardcoded NZ April 1 assumption.
function getTaxYearStart(int $startMonth, ?DateTime $now = null): DateTime
{
    $now = $now ?? new DateTime();
    $startMonth = ($startMonth >= 1 && $startMonth <= 12) ? $startMonth : 1;
    $taxYearStart = new DateTime();
    if ((int) $now->format('n') < $startMonth) {
        $taxYearStart->setDate((int) $now->format('Y') - 1, $startMonth, 1);
    } else {
        $taxYearStart->setDate((int) $now->format('Y'), $startMonth, 1);
    }
    $taxYearStart->setTime(0, 0, 0);
    return $taxYearStart;
}

// ── Test Suite ────────────────────────────────────────────────────────────────
// Runs from Data Management > Test Suite (see run_test_suite below). Covers
// pure logic (invoice math, TOTP, Stripe/PayPal conversion and signature
// verification, lockout timing, backup code format) plus the payment
// ledger's DB behavior — never a real Stripe/PayPal/SMTP call. DB-touching
// tests use disposable fixtures (client_key prefixed 'zt') deleted in a
// finally block regardless of pass/fail.
class InvoxaTestFailure extends Exception
{
}

function invoxaAssertEquals($expected, $actual, string $label = ''): void
{
    if ($expected != $actual) {
        throw new InvoxaTestFailure(($label !== '' ? "$label: " : '') . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function invoxaAssertTrue(bool $condition, string $label = ''): void
{
    if (!$condition) {
        throw new InvoxaTestFailure($label !== '' ? $label : 'assertion failed');
    }
}

// Creates a disposable client for a DB-touching test. is_test=1 excludes it
// from real reporting even if cleanup fails to run; client_key is namespaced
// 'zt' so it can't collide with a real client or Demo Data's 'dm' fixtures.
function invoxaTestCreateClient($mysqli): array
{
    $key = 'zt' . substr(bin2hex(random_bytes(4)), 0, 6);
    $name = 'Test Suite Fixture';
    $stmt = $mysqli->prepare("INSERT INTO invoxa_clients (client_key, client_name, email, is_active, is_test) VALUES (?, ?, 'testsuite@invalid.example', 1, 1)");
    $stmt->bind_param("ss", $key, $name);
    $stmt->execute();
    return [$mysqli->insert_id, $key];
}

function invoxaTestCreateInvoice($mysqli, string $clientKey, float $amount): int
{
    // Must end in digits — generateInvoiceNumber()'s "highest number so far"
    // lookup only matches a trailing run of digits (/(\d+)$/).
    $invNum = 'ZTEST-' . strtoupper(bin2hex(random_bytes(3))) . random_int(100, 999);
    $stmt = $mysqli->prepare("INSERT INTO invoxa_invoices (invoice_number, client_key, client_name, recipient_email, invoice_date, due_date, amount, status) VALUES (?, ?, 'Test Suite Fixture', 'testsuite@invalid.example', NOW(), DATE_ADD(NOW(), INTERVAL 21 DAY), ?, 'sent')");
    $stmt->bind_param("ssd", $invNum, $clientKey, $amount);
    $stmt->execute();
    return $mysqli->insert_id;
}

// Deletes everything a test fixture created — payments, actions, invoices,
// then the client (children before parents). Called from a finally block.
function invoxaTestCleanupClient($mysqli, int $clientId, string $clientKey): void
{
    $ids = [];
    $res = $mysqli->query("SELECT id FROM invoxa_invoices WHERE client_key = '" . $mysqli->real_escape_string($clientKey) . "'");
    while ($r = $res->fetch_assoc()) {
        $ids[] = (int) $r['id'];
    }
    if ($ids) {
        $inList = implode(',', $ids);
        $mysqli->query("DELETE FROM invoxa_payments WHERE invoice_id IN ($inList)");
        $mysqli->query("DELETE FROM invoxa_actions WHERE invoice_id IN ($inList)");
        $mysqli->query("DELETE FROM invoxa_invoices WHERE id IN ($inList)");
    }
    $mysqli->query("DELETE FROM invoxa_clients WHERE id = " . (int) $clientId);
}

// Returns the full catalogue of tests, keyed by "Category: Label" (the
// canonical id for selection/result-matching). Each entry carries the group,
// category/label, a $description for the row's tooltip, and the callable.
// Building this just constructs closures — nothing executes yet, which is
// also how the checkbox list gets its rows without running any test.
function invoxaTestDefinitions($mysqli, array $settings): array
{
    $definitions = [];
    $run = function (string $group, string $category, string $label, string $description, callable $fn) use (&$definitions) {
        $definitions["{$category}: {$label}"] = ['group' => $group, 'category' => $category, 'label' => $label, 'description' => $description, 'fn' => $fn];
    };

    // ── Core Logic ── pure functions, no database, no network.
    $run('Core Logic', 'computeInvoiceTotals', 'no discount/tax', 'A $100 line item with 0% discount and 0% tax totals exactly $100.', function () {
        $items = [['amount' => 100]];
        $t = computeInvoiceTotals($items, 0, 0);
        invoxaAssertEquals(100.0, $t['total'], 'total');
    });
    $run('Core Logic', 'computeInvoiceTotals', 'discount before tax', 'A $100 item with 10% discount then 15% tax totals $103.50 — discount is applied first, tax on what\'s left.', function () {
        $items = [['amount' => 100]];
        $t = computeInvoiceTotals($items, 10, 15); // 100 -10% = 90, +15% tax = 103.5
        invoxaAssertEquals(103.5, $t['total'], 'total');
    });
    $run('Core Logic', 'formatPct', 'trims trailing zeros', 'formatPct() renders 7.5 as "7.5%" and 10 as "10%", never "10.00%".', function () {
        invoxaAssertEquals('7.5%', formatPct(7.5));
        invoxaAssertEquals('10%', formatPct(10));
    });
    $run('Core Logic', 'base32', 'round-trip', 'Encoding random bytes to base32 and decoding the result returns the exact original bytes.', function () {
        $raw = random_bytes(20);
        invoxaAssertTrue(base32Decode(base32Encode($raw)) === $raw);
    });
    $run('Core Logic', 'TOTP', 'current code verifies', 'A freshly generated secret\'s current 30-second TOTP code passes verifyTotpCode().', function () {
        $secret = generateTotpSecret();
        $code = totpCodeAt($secret, (int) floor(time() / 30));
        invoxaAssertTrue(verifyTotpCode($secret, $code));
    });
    $run('Core Logic', 'TOTP', 'wrong code rejected', 'An incorrect 6-digit code fails verifyTotpCode() against a freshly generated secret.', function () {
        $secret = generateTotpSecret();
        $real = totpCodeAt($secret, (int) floor(time() / 30));
        $wrong = ($real === '000000') ? '111111' : '000000';
        invoxaAssertTrue(!verifyTotpCode($secret, $wrong));
    });
    $run('Core Logic', 'Stripe', 'USD amount round-trip', '$19.99 converts to 1999 (cents) via stripeAmountToMinorUnits() and back to $19.99 via stripeAmountFromMinorUnits().', function () {
        $minor = stripeAmountToMinorUnits(19.99, 'USD');
        invoxaAssertEquals(1999, $minor);
        invoxaAssertEquals(19.99, stripeAmountFromMinorUnits($minor, 'USD'));
    });
    $run('Core Logic', 'Stripe', 'zero-decimal currency', 'JPY 500 stays 500 (not multiplied by 100) since Stripe treats JPY as a zero-decimal currency.', function () {
        invoxaAssertEquals(500, stripeAmountToMinorUnits(500, 'JPY'));
    });
    $run('Core Logic', 'Stripe webhook signature', 'valid signature accepted', 'A signature correctly computed as HMAC-SHA256 over "{timestamp}.{payload}" verifies successfully.', function () {
        $payload = '{"type":"test"}';
        $secret = 'whsec_testsecret';
        $ts = time();
        $sig = hash_hmac('sha256', $ts . '.' . $payload, $secret);
        invoxaAssertTrue(stripeVerifyWebhookSignature($payload, "t={$ts},v1={$sig}", $secret));
    });
    $run('Core Logic', 'Stripe webhook signature', 'tampered payload rejected', 'Changing the payload after signing invalidates the signature check, as it should.', function () {
        $payload = '{"type":"test"}';
        $secret = 'whsec_testsecret';
        $ts = time();
        $sig = hash_hmac('sha256', $ts . '.' . $payload, $secret);
        invoxaAssertTrue(!stripeVerifyWebhookSignature('{"type":"tampered"}', "t={$ts},v1={$sig}", $secret));
    });
    $run('Core Logic', 'Stripe webhook signature', 'stale timestamp rejected', 'A signature computed from a timestamp far in the past is rejected — this is what blocks replay attacks.', function () {
        $payload = '{"type":"test"}';
        $secret = 'whsec_testsecret';
        $ts = time() - 999999;
        $sig = hash_hmac('sha256', $ts . '.' . $payload, $secret);
        invoxaAssertTrue(!stripeVerifyWebhookSignature($payload, "t={$ts},v1={$sig}", $secret));
    });
    $run('Core Logic', 'Lockout', 'minutes-remaining math', 'invoxaLockoutMinutesRemaining() returns 0 for no lock or an already-expired one, and ~5 for a lock 5 minutes in the future.', function () {
        invoxaAssertEquals(0, invoxaLockoutMinutesRemaining(null));
        invoxaAssertEquals(0, invoxaLockoutMinutesRemaining(date('Y-m-d H:i:s', time() - 60)));
        $remaining = invoxaLockoutMinutesRemaining(date('Y-m-d H:i:s', time() + 300));
        invoxaAssertTrue($remaining >= 4 && $remaining <= 5, "expected ~5 minutes, got {$remaining}");
    });
    $run('Core Logic', 'Backup codes', 'format & uniqueness', '10 generated backup codes are all unique and every one matches the XXXXX-XXXXX uppercase-hex format.', function () {
        $codes = invoxaGenerateBackupCodes(10);
        invoxaAssertEquals(10, count($codes));
        invoxaAssertEquals(10, count(array_unique($codes)));
        foreach ($codes as $c) {
            invoxaAssertTrue((bool) preg_match('/^[0-9A-F]{5}-[0-9A-F]{5}$/', $c), "code format: $c");
        }
    });
    $run('Core Logic', 'invoxaTestViewFilter', 'all three data-view states', 'The Preferences data-view filter picks the right SQL fragment for each of its three states — real-only (hide test), everything (both off), and test-only (the "Show Only Test/Dummy Data" toggle, which wins over "Hide Test Clients Globally" whenever both are somehow on) — for both the client_key-subquery shape (invoices) and the direct-column shape (clients).', function () {
        invoxaAssertEquals("AND client_key NOT IN (SELECT client_key FROM invoxa_clients WHERE is_test = 1)", invoxaTestViewFilter(true, false), 'real-only');
        invoxaAssertEquals("", invoxaTestViewFilter(false, false), 'everything');
        invoxaAssertEquals("AND client_key IN (SELECT client_key FROM invoxa_clients WHERE is_test = 1)", invoxaTestViewFilter(false, true), 'test-only');
        invoxaAssertEquals("AND client_key IN (SELECT client_key FROM invoxa_clients WHERE is_test = 1)", invoxaTestViewFilter(true, true), 'test-only wins when both are on');
        invoxaAssertEquals("WHERE is_test = 0", invoxaTestViewClientFilter(true, false, 'WHERE'), 'real-only, direct column');
        invoxaAssertEquals("WHERE is_test = 1", invoxaTestViewClientFilter(false, true, 'WHERE'), 'test-only, direct column');
        invoxaAssertEquals("", invoxaTestViewClientFilter(false, false, 'WHERE'), 'everything, direct column');
    });

    // ── Clients & Invoices ── the "add a client" / "add an invoice" paths,
    // exercised against disposable fixtures rather than the real AJAX actions.
    $run('Clients & Invoices', 'Client', 'created with correct defaults', 'A newly inserted client comes back active, flagged as test data, and with 0% discount/tax — the same defaults the Add Client form relies on.', function () use ($mysqli) {
        [$clientId, $clientKey] = invoxaTestCreateClient($mysqli);
        try {
            $row = $mysqli->query("SELECT is_active, is_test, discount_pct, tax_rate FROM invoxa_clients WHERE id = $clientId")->fetch_assoc();
            invoxaAssertTrue((bool) $row, 'client row exists');
            invoxaAssertEquals(1, (int) $row['is_active']);
            invoxaAssertEquals(1, (int) $row['is_test']);
            invoxaAssertEquals(0.0, (float) $row['discount_pct']);
            invoxaAssertEquals(0.0, (float) $row['tax_rate']);
        } finally {
            invoxaTestCleanupClient($mysqli, $clientId, $clientKey);
        }
    });
    $run('Clients & Invoices', 'Invoice numbering', 'increases as invoices are added', 'generateInvoiceNumber() returns a higher sequence the second time it\'s called for the same client, after one invoice has actually been recorded in between.', function () use ($mysqli, $settings) {
        [$clientId, $clientKey] = invoxaTestCreateClient($mysqli);
        try {
            $clientName = 'Test Suite Fixture';
            $first = generateInvoiceNumber($mysqli, $clientKey, $clientName, $settings);
            invoxaTestCreateInvoice($mysqli, $clientKey, 10.00);
            $second = generateInvoiceNumber($mysqli, $clientKey, $clientName, $settings);
            invoxaAssertTrue($first !== $second, "expected a different number, got '{$first}' both times");
        } finally {
            invoxaTestCleanupClient($mysqli, $clientId, $clientKey);
            // generateInvoiceNumber() creates INVOICES_DIR/<client folder> as a
            // side effect; rmdir only succeeds if it's still empty.
            @rmdir(INVOICES_DIR . 'test_suite_fixture');
        }
    });
    $run('Clients & Invoices', 'Invoice', 'stores the exact amount billed', 'An invoice inserted for $123.45 reads back as exactly $123.45 — no float-rounding drift through the DECIMAL(10,2) column.', function () use ($mysqli) {
        [$clientId, $clientKey] = invoxaTestCreateClient($mysqli);
        try {
            $invId = invoxaTestCreateInvoice($mysqli, $clientKey, 123.45);
            $row = $mysqli->query("SELECT amount, status FROM invoxa_invoices WHERE id = $invId")->fetch_assoc();
            invoxaAssertEquals(123.45, (float) $row['amount']);
            invoxaAssertEquals('sent', $row['status']);
        } finally {
            invoxaTestCleanupClient($mysqli, $clientId, $clientKey);
        }
    });
    $run('Clients & Invoices', 'Client Portal', 'excludes draft invoices', 'The Client Portal\'s own query (status != \'draft\') leaves a draft invoice out of what a client sees, while a sent one for the same client still shows up.', function () use ($mysqli) {
        [$clientId, $clientKey] = invoxaTestCreateClient($mysqli);
        try {
            $sentId = invoxaTestCreateInvoice($mysqli, $clientKey, 40.00);
            $draftId = invoxaTestCreateInvoice($mysqli, $clientKey, 40.00);
            $mysqli->query("UPDATE invoxa_invoices SET status = 'draft' WHERE id = $draftId");
            $visibleIds = [];
            $res = $mysqli->query("SELECT id FROM invoxa_invoices WHERE client_key = '" . $mysqli->real_escape_string($clientKey) . "' AND is_quote = 0 AND status != 'draft'");
            while ($r = $res->fetch_assoc()) {
                $visibleIds[] = (int) $r['id'];
            }
            invoxaAssertTrue(in_array($sentId, $visibleIds, true), 'sent invoice should be visible');
            invoxaAssertTrue(!in_array($draftId, $visibleIds, true), 'draft invoice should not be visible');
        } finally {
            invoxaTestCleanupClient($mysqli, $clientId, $clientKey);
        }
    });
    $run('Clients & Invoices', 'Portal token', 'resolves correct client', 'Looking up a client by the portal_token just written for them returns that same client\'s id, not some other row.', function () use ($mysqli) {
        [$clientId, $clientKey] = invoxaTestCreateClient($mysqli);
        try {
            $token = bin2hex(random_bytes(24));
            $mysqli->query("UPDATE invoxa_clients SET portal_token = '" . $mysqli->real_escape_string($token) . "' WHERE id = $clientId");
            $found = $mysqli->query("SELECT id FROM invoxa_clients WHERE portal_token = '" . $mysqli->real_escape_string($token) . "'")->fetch_assoc();
            invoxaAssertTrue($found && (int) $found['id'] === $clientId);
        } finally {
            invoxaTestCleanupClient($mysqli, $clientId, $clientKey);
        }
    });
    $run('Clients & Invoices', 'Portal token', 'revoke invalidates the link', 'Revoking a client\'s portal link (setting portal_token back to NULL, the same update revoke_portal_token runs) means the old token no longer resolves to any client — the same lookup a portal page uses to validate a link.', function () use ($mysqli) {
        [$clientId, $clientKey] = invoxaTestCreateClient($mysqli);
        try {
            $token = bin2hex(random_bytes(24));
            $mysqli->query("UPDATE invoxa_clients SET portal_token = '" . $mysqli->real_escape_string($token) . "' WHERE id = $clientId");
            $stillFound = $mysqli->query("SELECT id FROM invoxa_clients WHERE portal_token = '" . $mysqli->real_escape_string($token) . "'")->fetch_assoc();
            invoxaAssertTrue((bool) $stillFound, 'token should resolve before revoking');
            $stmt = $mysqli->prepare("UPDATE invoxa_clients SET portal_token = NULL, portal_token_expires_at = NULL WHERE id = ?");
            $stmt->bind_param("i", $clientId);
            $stmt->execute();
            $afterRevoke = $mysqli->query("SELECT id FROM invoxa_clients WHERE portal_token = '" . $mysqli->real_escape_string($token) . "'")->fetch_assoc();
            invoxaAssertTrue(!$afterRevoke, 'old token should no longer resolve after being revoked');
        } finally {
            invoxaTestCleanupClient($mysqli, $clientId, $clientKey);
        }
    });
    $run('Clients & Invoices', 'Ad Hoc invoice', 'line items total matches stored amount', 'Building an invoice from three line items with a 10% discount and 8% tax (the same computeInvoiceTotals() the Ad Hoc invoice builder uses) and storing that total behaves exactly like a real Ad Hoc save — the stored amount matches the computed total to the cent.', function () use ($mysqli) {
        [$clientId, $clientKey] = invoxaTestCreateClient($mysqli);
        try {
            $items = [['amount' => 150], ['amount' => 75.50], ['amount' => 24.50]];
            $totals = computeInvoiceTotals($items, 10, 8);
            $invId = invoxaTestCreateInvoice($mysqli, $clientKey, $totals['total']);
            $row = $mysqli->query("SELECT amount FROM invoxa_invoices WHERE id = $invId")->fetch_assoc();
            invoxaAssertEquals(round($totals['total'], 2), round((float) $row['amount'], 2), 'stored amount matches computed total');
        } finally {
            invoxaTestCleanupClient($mysqli, $clientId, $clientKey);
        }
    });
    $run('Clients & Invoices', 'Void invoice', 'removed from and restored to outstanding total', 'Voiding an invoice (the same status update void_invoice runs) drops it out of an "outstanding" query the same way the dashboard\'s totals filter it out; unvoiding puts it straight back.', function () use ($mysqli) {
        [$clientId, $clientKey] = invoxaTestCreateClient($mysqli);
        try {
            $invId = invoxaTestCreateInvoice($mysqli, $clientKey, 200.00);
            $outstandingSql = "SELECT COUNT(*) as c FROM invoxa_invoices WHERE id = $invId AND status NOT IN ('paid', 'void')";
            $before = (int) $mysqli->query($outstandingSql)->fetch_assoc()['c'];
            invoxaAssertEquals(1, $before, 'freshly sent invoice should count as outstanding');
            $stmt = $mysqli->prepare("UPDATE invoxa_invoices SET status = 'void' WHERE id = ?");
            $stmt->bind_param("i", $invId);
            $stmt->execute();
            $whileVoid = (int) $mysqli->query($outstandingSql)->fetch_assoc()['c'];
            invoxaAssertEquals(0, $whileVoid, 'voided invoice should drop out of the outstanding total');
            $stmt = $mysqli->prepare("UPDATE invoxa_invoices SET status = 'sent' WHERE id = ? AND status = 'void'");
            $stmt->bind_param("i", $invId);
            $stmt->execute();
            $afterUnvoid = (int) $mysqli->query($outstandingSql)->fetch_assoc()['c'];
            invoxaAssertEquals(1, $afterUnvoid, 'unvoided invoice should count as outstanding again');
        } finally {
            invoxaTestCleanupClient($mysqli, $clientId, $clientKey);
        }
    });
    $run('Clients & Invoices', 'Quote', 'numbered separately from real invoices', 'A saved quote uses the Q<CLIENTKEY>NNN numbering format and is excluded from a real-invoice list query (is_quote = 0) while still showing up in a quotes query (is_quote = 1) for the same client.', function () use ($mysqli) {
        [$clientId, $clientKey] = invoxaTestCreateClient($mysqli);
        try {
            $quoteNum = 'Q' . strtoupper($clientKey) . '001';
            invoxaAssertTrue((bool) preg_match('/^Q[A-Z0-9]+\d{3}$/', $quoteNum), 'quote number format');
            $stmt = $mysqli->prepare("INSERT INTO invoxa_invoices (invoice_number, client_key, client_name, recipient_email, invoice_date, due_date, amount, status, is_quote) VALUES (?, ?, 'Test Suite Fixture', 'testsuite@invalid.example', NOW(), DATE_ADD(NOW(), INTERVAL 21 DAY), 500.00, 'sent', 1)");
            $stmt->bind_param("ss", $quoteNum, $clientKey);
            $stmt->execute();
            $realCount = (int) $mysqli->query("SELECT COUNT(*) as c FROM invoxa_invoices WHERE client_key = '" . $mysqli->real_escape_string($clientKey) . "' AND is_quote = 0")->fetch_assoc()['c'];
            $quoteCount = (int) $mysqli->query("SELECT COUNT(*) as c FROM invoxa_invoices WHERE client_key = '" . $mysqli->real_escape_string($clientKey) . "' AND is_quote = 1")->fetch_assoc()['c'];
            invoxaAssertEquals(0, $realCount, 'a quote should not appear in the real-invoice list');
            invoxaAssertEquals(1, $quoteCount, 'the quote should appear in the quotes list');
        } finally {
            invoxaTestCleanupClient($mysqli, $clientId, $clientKey);
        }
    });
    $run('Clients & Invoices', 'Expense', 'created with correct fields', 'Recording an expense (the same fields save_expense writes: date, vendor, category, amount, description) reads back exactly as entered, including the DECIMAL(10,2) amount.', function () use ($mysqli) {
        $expenseId = null;
        try {
            $stmt = $mysqli->prepare("INSERT INTO invoxa_expenses (expense_date, vendor, category, amount, description) VALUES (CURDATE(), ?, 'software', ?, 'Test suite fixture expense')");
            $vendor = 'Test Suite Vendor';
            $amount = 42.75;
            $stmt->bind_param("sd", $vendor, $amount);
            $stmt->execute();
            $expenseId = $mysqli->insert_id;
            $row = $mysqli->query("SELECT vendor, category, amount FROM invoxa_expenses WHERE id = $expenseId")->fetch_assoc();
            invoxaAssertEquals('Test Suite Vendor', $row['vendor']);
            invoxaAssertEquals('software', $row['category']);
            invoxaAssertEquals(42.75, (float) $row['amount']);
        } finally {
            if ($expenseId) {
                $mysqli->query("DELETE FROM invoxa_expenses WHERE id = " . (int) $expenseId);
            }
        }
    });

    // ── Payments & Refunds ── the ledger's actual crediting/reversing logic.
    $run('Payments & Refunds', 'Payment ledger', 'partial then full payment', 'A $100 invoice paid $40 then $60 stays open (status "sent") after the first payment and flips to "paid" after the second, with paid_amount tracked correctly at each step.', function () use ($mysqli, $settings) {
        [$clientId, $clientKey] = invoxaTestCreateClient($mysqli);
        try {
            $invId = invoxaTestCreateInvoice($mysqli, $clientKey, 100.00);
            $r1 = recordInvoicePayment($mysqli, $settings, $invId, 40.00, 'test partial', 'manual');
            invoxaAssertTrue($r1['success'] && !$r1['duplicate']);
            $mid = $mysqli->query("SELECT status, paid_amount FROM invoxa_invoices WHERE id = $invId")->fetch_assoc();
            invoxaAssertEquals('sent', $mid['status'], 'status stays open after partial payment');
            invoxaAssertEquals(40.00, (float) $mid['paid_amount']);
            $r2 = recordInvoicePayment($mysqli, $settings, $invId, 60.00, 'test remainder', 'manual');
            invoxaAssertTrue($r2['success']);
            $after = $mysqli->query("SELECT status, paid_amount FROM invoxa_invoices WHERE id = $invId")->fetch_assoc();
            invoxaAssertEquals('paid', $after['status']);
            invoxaAssertEquals(100.00, (float) $after['paid_amount']);
        } finally {
            invoxaTestCleanupClient($mysqli, $clientId, $clientKey);
        }
    });
    $run('Payments & Refunds', 'Payment ledger', 'duplicate webhook idempotency', 'Recording the same gateway payment reference (provider_ref) twice only credits the invoice once — the second call comes back as a no-op duplicate, not a second ledger row.', function () use ($mysqli, $settings) {
        [$clientId, $clientKey] = invoxaTestCreateClient($mysqli);
        try {
            $invId = invoxaTestCreateInvoice($mysqli, $clientKey, 50.00);
            $ref = 'test_ref_' . bin2hex(random_bytes(6));
            $r1 = recordInvoicePayment($mysqli, $settings, $invId, 50.00, 'test', 'stripe', $ref);
            invoxaAssertTrue($r1['success'] && !$r1['duplicate']);
            $r2 = recordInvoicePayment($mysqli, $settings, $invId, 50.00, 'test', 'stripe', $ref);
            invoxaAssertTrue($r2['success'] && $r2['duplicate'], 'second call with the same provider_ref should be a no-op');
            $count = (int) $mysqli->query("SELECT COUNT(*) as c FROM invoxa_payments WHERE invoice_id = $invId")->fetch_assoc()['c'];
            invoxaAssertEquals(1, $count, 'exactly one ledger row despite two calls');
        } finally {
            invoxaTestCleanupClient($mysqli, $clientId, $clientKey);
        }
    });
    $run('Payments & Refunds', 'Refund', 'reopens paid invoice', 'Refunding a fully-paid invoice\'s full amount reopens it (status back to "sent") and drops paid_amount back to $0.', function () use ($mysqli, $settings) {
        [$clientId, $clientKey] = invoxaTestCreateClient($mysqli);
        try {
            $invId = invoxaTestCreateInvoice($mysqli, $clientKey, 80.00);
            recordInvoicePayment($mysqli, $settings, $invId, 80.00, 'test', 'stripe', 'test_charge_' . bin2hex(random_bytes(6)));
            $before = $mysqli->query("SELECT status FROM invoxa_invoices WHERE id = $invId")->fetch_assoc();
            invoxaAssertEquals('paid', $before['status']);
            recordInvoiceRefund($mysqli, $settings, $invId, 80.00, 'stripe', 'test_refund_' . bin2hex(random_bytes(6)));
            $after = $mysqli->query("SELECT status, paid_amount FROM invoxa_invoices WHERE id = $invId")->fetch_assoc();
            invoxaAssertEquals('sent', $after['status'], 'invoice reopens after a full refund');
            invoxaAssertEquals(0.00, (float) $after['paid_amount']);
        } finally {
            invoxaTestCleanupClient($mysqli, $clientId, $clientKey);
        }
    });
    $run('Payments & Refunds', 'Audit Log', 'payment creates a matching entry', 'recordInvoicePayment() writes its own invoxa_actions row (mark_paid/mark_partial_paid) against the right invoice — the same audit trail the Activity tab reads.', function () use ($mysqli, $settings) {
        [$clientId, $clientKey] = invoxaTestCreateClient($mysqli);
        try {
            $invId = invoxaTestCreateInvoice($mysqli, $clientKey, 25.00);
            recordInvoicePayment($mysqli, $settings, $invId, 25.00, 'test', 'manual');
            $row = $mysqli->query("SELECT action_type FROM invoxa_actions WHERE invoice_id = $invId AND action_type IN ('mark_paid', 'mark_partial_paid') ORDER BY id DESC LIMIT 1")->fetch_assoc();
            invoxaAssertTrue((bool) $row, 'expected an audit log entry for this payment');
        } finally {
            invoxaTestCleanupClient($mysqli, $clientId, $clientKey);
        }
    });

    // ── External API ── the token lifecycle (create, authenticate, renew,
    // revoke), exercised via invoxaCreateApiToken() and the same token_hash
    // lookup invoxaAuthenticateApiRequest() runs — that function itself isn't
    // called directly since it reads a real Authorization header, which this
    // test has no HTTP request to provide.
    $run('External API', 'Token', 'created and authenticates', 'invoxaCreateApiToken() returns a raw token whose SHA-256 hash matches what was persisted — the exact lookup invoxaAuthenticateApiRequest() runs against the Authorization header on a real request.', function () use ($mysqli) {
        $created = invoxaCreateApiToken($mysqli, 'Test Suite Fixture Token', null);
        try {
            invoxaAssertTrue(str_starts_with($created['token'], 'ivx_'), 'token should use the ivx_ prefix');
            $hash = hash('sha256', $created['token']);
            $row = $mysqli->query("SELECT id, revoked_at, expires_at FROM invoxa_api_tokens WHERE token_hash = '" . $mysqli->real_escape_string($hash) . "'")->fetch_assoc();
            invoxaAssertTrue((bool) $row && (int) $row['id'] === (int) $created['id'], 'stored hash should resolve back to the created token');
            invoxaAssertTrue($row['revoked_at'] === null && $row['expires_at'] === null, 'a freshly created never-expiring token should be neither revoked nor expired');
        } finally {
            $mysqli->query("DELETE FROM invoxa_api_tokens WHERE id = " . (int) $created['id']);
        }
    });
    $run('External API', 'Token', 'revoked token fails to authenticate', 'The same query invoxaAuthenticateApiRequest() runs (token_hash match AND revoked_at IS NULL) stops matching a token the instant it\'s revoked — mirroring what "Revoke" in Settings > API Access actually does.', function () use ($mysqli) {
        $created = invoxaCreateApiToken($mysqli, 'Test Suite Fixture Token', null);
        try {
            $hash = hash('sha256', $created['token']);
            $authSql = "SELECT id FROM invoxa_api_tokens WHERE token_hash = '" . $mysqli->real_escape_string($hash) . "' AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at > NOW())";
            $before = $mysqli->query($authSql)->fetch_assoc();
            invoxaAssertTrue((bool) $before, 'token should authenticate before being revoked');
            $mysqli->query("UPDATE invoxa_api_tokens SET revoked_at = NOW() WHERE id = " . (int) $created['id']);
            $after = $mysqli->query($authSql)->fetch_assoc();
            invoxaAssertTrue(!$after, 'a revoked token should no longer authenticate');
        } finally {
            $mysqli->query("DELETE FROM invoxa_api_tokens WHERE id = " . (int) $created['id']);
        }
    });
    $run('External API', 'Token', 'expired token fails to authenticate', 'A token created with its expiry already in the past fails the same "not expired" check a live request goes through, even though it was never explicitly revoked.', function () use ($mysqli) {
        $created = invoxaCreateApiToken($mysqli, 'Test Suite Fixture Token', 30);
        try {
            $mysqli->query("UPDATE invoxa_api_tokens SET expires_at = DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE id = " . (int) $created['id']);
            $hash = hash('sha256', $created['token']);
            $authSql = "SELECT id FROM invoxa_api_tokens WHERE token_hash = '" . $mysqli->real_escape_string($hash) . "' AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at > NOW())";
            $row = $mysqli->query($authSql)->fetch_assoc();
            invoxaAssertTrue(!$row, 'a token past its expiry should not authenticate');
        } finally {
            $mysqli->query("DELETE FROM invoxa_api_tokens WHERE id = " . (int) $created['id']);
        }
    });

    // ── Recurring Billing / Cron ── the double-billing guard's query, checked
    // directly rather than via run_recurring(), which would bill real clients.
    $run('Recurring Billing / Cron', 'Double-billing guard', 'detects an invoice already billed this month', 'The same "already billed this period" query run_recurring() uses for monthly clients correctly finds an invoice dated today, and correctly finds none for a client with no invoices at all.', function () use ($mysqli) {
        [$billedId, $billedKey] = invoxaTestCreateClient($mysqli);
        [$freshId, $freshKey] = invoxaTestCreateClient($mysqli);
        try {
            invoxaTestCreateInvoice($mysqli, $billedKey, 30.00);
            $guardSql = "SELECT COUNT(*) as c FROM invoxa_invoices WHERE client_key = ? AND is_quote = 0 AND MONTH(invoice_date) = MONTH(CURDATE()) AND YEAR(invoice_date) = YEAR(CURDATE())";
            $stmt = $mysqli->prepare($guardSql);
            $stmt->bind_param("s", $billedKey);
            $stmt->execute();
            $billedCount = (int) $stmt->get_result()->fetch_assoc()['c'];
            invoxaAssertTrue($billedCount > 0, 'client with an invoice this month should be caught by the guard');
            $stmt2 = $mysqli->prepare($guardSql);
            $stmt2->bind_param("s", $freshKey);
            $stmt2->execute();
            $freshCount = (int) $stmt2->get_result()->fetch_assoc()['c'];
            invoxaAssertEquals(0, $freshCount, 'client with no invoices should not be caught by the guard');
        } finally {
            invoxaTestCleanupClient($mysqli, $billedId, $billedKey);
            invoxaTestCleanupClient($mysqli, $freshId, $freshKey);
        }
    });
    $run('Recurring Billing / Cron', 'Late fees', 'eligibility query catches overdue, skips grace-period and already-charged invoices', 'The same eligibility check applyLateFees() runs (unpaid, non-quote, due_date past the grace period, no existing late_fee_charged action) picks up an invoice 10 days overdue against a 7-day grace period, but correctly skips one only 3 days overdue, and skips an eligible invoice that already has a late_fee_charged entry against it.', function () use ($mysqli) {
        [$clientId, $clientKey] = invoxaTestCreateClient($mysqli);
        try {
            $graceDays = 7;
            $eligibleId = invoxaTestCreateInvoice($mysqli, $clientKey, 100.00);
            $mysqli->query("UPDATE invoxa_invoices SET due_date = DATE_SUB(CURDATE(), INTERVAL 10 DAY) WHERE id = $eligibleId");
            $withinGraceId = invoxaTestCreateInvoice($mysqli, $clientKey, 100.00);
            $mysqli->query("UPDATE invoxa_invoices SET due_date = DATE_SUB(CURDATE(), INTERVAL 3 DAY) WHERE id = $withinGraceId");
            $alreadyChargedId = invoxaTestCreateInvoice($mysqli, $clientKey, 100.00);
            $mysqli->query("UPDATE invoxa_invoices SET due_date = DATE_SUB(CURDATE(), INTERVAL 30 DAY) WHERE id = $alreadyChargedId");
            $invNum = $mysqli->query("SELECT invoice_number FROM invoxa_invoices WHERE id = $alreadyChargedId")->fetch_assoc()['invoice_number'];
            $stmt = $mysqli->prepare("INSERT INTO invoxa_actions (invoice_id, invoice_number, action_type, notes) VALUES (?, ?, 'late_fee_charged', 'test fixture')");
            $stmt->bind_param("is", $alreadyChargedId, $invNum);
            $stmt->execute();

            $eligibleSql = "SELECT i.id FROM invoxa_invoices i
                 WHERE i.is_quote = 0
                   AND i.status IN ('sent', 'pending')
                   AND i.due_date IS NOT NULL
                   AND i.due_date <= DATE_SUB(CURDATE(), INTERVAL ? DAY)
                   AND NOT EXISTS (
                       SELECT 1 FROM invoxa_actions a
                       WHERE a.invoice_id = i.id AND a.action_type = 'late_fee_charged'
                   )
                   AND i.client_key = ?";
            $stmt = $mysqli->prepare($eligibleSql);
            $stmt->bind_param("is", $graceDays, $clientKey);
            $stmt->execute();
            $eligibleIds = [];
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) {
                $eligibleIds[] = (int) $r['id'];
            }
            invoxaAssertTrue(in_array($eligibleId, $eligibleIds, true), 'invoice past the grace period should be eligible');
            invoxaAssertTrue(!in_array($withinGraceId, $eligibleIds, true), 'invoice still within the grace period should not be eligible');
            invoxaAssertTrue(!in_array($alreadyChargedId, $eligibleIds, true), 'invoice already charged a late fee should not be eligible again');
        } finally {
            invoxaTestCleanupClient($mysqli, $clientId, $clientKey);
        }
    });

    // ── Email Content ── validates what would go into an email (template
    // substitution, generated invoice HTML) without calling PHPMailer or SMTP.
    $run('Email Content', 'renderEmailTemplate', 'substitutes tokens correctly', 'A template with {client_name}/{invoice_number} placeholders renders with those exact values substituted, and nothing else altered.', function () {
        $out = renderEmailTemplate('Hi {client_name}, invoice {invoice_number} is ready.', ['client_name' => 'Acme Co', 'invoice_number' => 'INV042']);
        invoxaAssertEquals('Hi Acme Co, invoice INV042 is ready.', $out);
    });
    $run('Email Content', 'generateInvoiceHTML', 'includes the client, number, and amount', 'The generated invoice HTML (the same markup that becomes the email body and the PDF) contains the client name, invoice number, and formatted amount passed in.', function () {
        $html = generateInvoiceHTML('Test Client Co', '2026-01-01', '2026-01-22', 'INVTEST01', '99.00', '', '', 'billing@example.com', [['code' => 'WEB01', 'desc' => 'Test line', 'amount' => '99.00']]);
        invoxaAssertTrue(str_contains($html, 'Test Client Co'), 'missing client name');
        invoxaAssertTrue(str_contains($html, 'INVTEST01'), 'missing invoice number');
        invoxaAssertTrue(str_contains($html, '99.00'), 'missing amount');
    });

    // ── Security ── account-recovery paths that touch the database, using a
    // real but isolated, fake user id (never invoxa_users itself).
    $run('Security', 'Backup codes', 'single-use consumption', 'A backup code works the first time it\'s used; reusing that exact same code a second time is rejected.', function () use ($mysqli) {
        // A fake, out-of-range user_id — invoxaConsumeBackupCode() only ever
        // queries invoxa_totp_backup_codes by user_id, never invoxa_users, so
        // this never touches the real admin account.
        $fakeUserId = 999900000 + random_int(1, 99999);
        try {
            $codes = invoxaGenerateBackupCodes(1);
            $hash = password_hash(str_replace('-', '', $codes[0]), PASSWORD_DEFAULT);
            $stmt = $mysqli->prepare("INSERT INTO invoxa_totp_backup_codes (user_id, code_hash) VALUES (?, ?)");
            $stmt->bind_param("is", $fakeUserId, $hash);
            $stmt->execute();
            invoxaAssertTrue(invoxaConsumeBackupCode($mysqli, $fakeUserId, $codes[0]), 'valid unused code is accepted');
            invoxaAssertTrue(!invoxaConsumeBackupCode($mysqli, $fakeUserId, $codes[0]), 'the same code cannot be used twice');
        } finally {
            $mysqli->query("DELETE FROM invoxa_totp_backup_codes WHERE user_id = " . (int) $fakeUserId);
        }
    });

    return $definitions;
}

// $selected — test names to run, or null to run everything. Unknown names
// are silently ignored, so a stale checkbox list in another tab can't crash a run.
function invoxaRunTestSuite($mysqli, array $settings, ?array $selected = null): array
{
    $results = [];
    foreach (invoxaTestDefinitions($mysqli, $settings) as $name => $test) {
        if ($selected !== null && !in_array($name, $selected, true)) {
            continue;
        }
        try {
            $test['fn']();
            $results[] = ['name' => $name, 'status' => 'pass', 'message' => ''];
        } catch (Throwable $e) {
            $results[] = ['name' => $name, 'status' => 'fail', 'message' => $e->getMessage()];
        }
    }
    return [
        'results' => $results,
        'passed' => count(array_filter($results, fn($r) => $r['status'] === 'pass')),
        'failed' => count(array_filter($results, fn($r) => $r['status'] === 'fail')),
    ];
}

// ── AJAX Handlers ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    try {
        // Open-core: everything works without a license except six paid
        // capabilities. Four are POST actions, gated here in one place; the
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
        $__licensePaidActions = ['save_payment_settings', 'test_stripe_connection', 'test_paypal_connection', 'run_recurring', 'toggle_cron', 'update_cron', 'toggle_recurring_bypass_guard', 'toggle_late_fees', 'save_late_fee_settings', 'toggle_reminders', 'generate_portal_token', 'create_api_token', 'renew_api_token', 'save_recurring_expense', 'toggle_recurring_expense'];
        if (!$licenseValid && in_array($_POST['action'], $__licensePaidActions, true)) {
            echo json_encode(['success' => false, 'error' => 'This needs a license — add a key under Settings > License, or see Docs for what a license unlocks.']);
            exit;
        }
        if ($_POST['action'] === 'get_nav_counts') {
            // Lets the sidebar poll for fresh badge counts (e.g. invoices the cron
            // container generates in the background) without a full page reload.
            $hideTestNav = isset($settings['hide_test']) ? ($settings['hide_test'] === '1') : true;
            $showTestOnlyNav = ($settings['show_test_only'] ?? '0') === '1';
            $testFilterNav = invoxaTestViewFilter($hideTestNav, $showTestOnlyNav);
            $navUnpaid = $mysqli->query("SELECT COUNT(*) as c FROM invoxa_invoices WHERE status IN ('sent', 'pending') $testFilterNav")->fetch_assoc()['c'] ?? 0;
            $navClients = $mysqli->query("SELECT COUNT(*) as c FROM invoxa_clients WHERE is_active = 1 " . invoxaTestViewClientFilter($hideTestNav, $showTestOnlyNav))->fetch_assoc()['c'] ?? 0;
            $navQuotes = $mysqli->query("SELECT COUNT(*) as c FROM invoxa_invoices WHERE is_quote = 1")->fetch_assoc()['c'] ?? 0;
            $navInvoices = $mysqli->query("SELECT COUNT(*) as c FROM invoxa_invoices WHERE is_quote = 0 $testFilterNav")->fetch_assoc()['c'] ?? 0;
            $navExpenses = $mysqli->query("SELECT COUNT(*) as c FROM invoxa_expenses")->fetch_assoc()['c'] ?? 0;
            echo json_encode([
                'success' => true,
                'invoice_count' => (int) $navInvoices,
                'unpaid_count' => (int) $navUnpaid,
                'quote_count' => (int) $navQuotes,
                'client_count' => (int) $navClients,
                'expense_count' => (int) $navExpenses,
            ]);
            exit;
        }
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
        if ($_POST['action'] === 'save_license_key') {
            $key = trim($_POST['license_key'] ?? '');
            $stmt = $mysqli->prepare("INSERT INTO invoxa_settings (setting_key, setting_value) VALUES ('license_key', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
            $stmt->bind_param("s", $key);
            $stmt->execute();
            $reason = null;
            $valid = licenseIsValid($mysqli, array_merge($settings, ['license_key' => $key]), false, $reason);
            echo json_encode(['success' => true, 'valid' => $valid, 'reason' => $reason]);
            exit;
        }
        if ($_POST['action'] === 'save_client') {
            $id = (int) ($_POST['id'] ?? 0);
            $key = strtolower(substr(preg_replace('/[^a-zA-Z0-9]/', '', $_POST['client_name']), 0, 3));
            if (!$key)
                $key = substr(md5(time()), 0, 3);
            $name = $_POST['client_name'];
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
            $act = (int) ($_POST['is_active'] ?? 0);
            $test = (int) ($_POST['is_test'] ?? 0);
            if ($id > 0) {
                $stmt = $mysqli->prepare("UPDATE invoxa_clients SET client_name=?, email=?, phone=?, address=?, account_name=?, account_number=?, monthly_rate=?, payment_terms_days=?, billing_frequency=?, discount_pct=?, tax_rate=?, is_active=?, is_test=? WHERE id=?");
                $stmt->bind_param("ssssssdisddiii", $name, $email, $phone, $address, $aname, $anum, $rate, $terms, $freq, $discountPct, $taxRate, $act, $test, $id);
            } else {
                $stmt = $mysqli->prepare("INSERT INTO invoxa_clients (client_name, email, phone, address, account_name, account_number, monthly_rate, payment_terms_days, billing_frequency, discount_pct, tax_rate, is_active, is_test, client_key) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("ssssssdisddiis", $name, $email, $phone, $address, $aname, $anum, $rate, $terms, $freq, $discountPct, $taxRate, $act, $test, $key);
            }
            $stmt->execute();
            echo json_encode(['success' => true]);
            exit;
        }
        if ($_POST['action'] === 'delete_client') {
            $stmt = $mysqli->prepare("DELETE FROM invoxa_clients WHERE id=?");
            $stmt->bind_param("i", $_POST['id']);
            $stmt->execute();
            echo json_encode(['success' => true]);
            exit;
        }
        if ($_POST['action'] === 'update_client_flags') {
            $id = (int) ($_POST['id'] ?? 0);
            $field = $_POST['field'] ?? '';
            if (!in_array($field, ['is_active', 'is_test'], true)) {
                echo json_encode(['success' => false, 'error' => 'Invalid field']);
                exit;
            }
            $value = (int) ($_POST['value'] ?? 0);
            $stmt = $mysqli->prepare("UPDATE invoxa_clients SET $field = ? WHERE id = ?");
            $stmt->bind_param("ii", $value, $id);
            $stmt->execute();
            echo json_encode(['success' => true]);
            exit;
        }
        if ($_POST['action'] === 'generate_portal_token') {
            // Regenerating invalidates the old link (one-token-per-client column,
            // just overwritten); the old URL then shows "Link not found".
            $id = (int) ($_POST['id'] ?? 0);
            $token = bin2hex(random_bytes(24));
            $expiryDays = ['never' => null, '30' => 30, '90' => 90, '365' => 365][$_POST['expiry'] ?? 'never'] ?? null;
            if ($expiryDays === null) {
                $stmt = $mysqli->prepare("UPDATE invoxa_clients SET portal_token = ?, portal_token_expires_at = NULL WHERE id = ?");
                $stmt->bind_param("si", $token, $id);
            } else {
                $stmt = $mysqli->prepare("UPDATE invoxa_clients SET portal_token = ?, portal_token_expires_at = DATE_ADD(NOW(), INTERVAL ? DAY) WHERE id = ?");
                $stmt->bind_param("sii", $token, $expiryDays, $id);
            }
            $stmt->execute();
            echo json_encode(['success' => true, 'token' => $token]);
            exit;
        }
        if ($_POST['action'] === 'revoke_portal_token') {
            $id = (int) ($_POST['id'] ?? 0);
            $stmt = $mysqli->prepare("UPDATE invoxa_clients SET portal_token = NULL, portal_token_expires_at = NULL WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            echo json_encode(['success' => true]);
            exit;
        }
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
            $res = $mysqli->query("SELECT id, filename, stored_path, file_size, uploaded_at FROM invoxa_expense_receipts WHERE expense_id = $expenseId ORDER BY uploaded_at DESC");
            $receipts = [];
            while ($r = $res->fetch_assoc()) {
                $r['url'] = RECEIPTS_URL . implode('/', array_map('rawurlencode', explode('/', $r['stored_path'])));
                $receipts[] = $r;
            }
            echo json_encode(['success' => true, 'receipts' => $receipts]);
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
            $stmt = $mysqli->prepare("INSERT INTO invoxa_expense_receipts (expense_id, filename, stored_path, file_size) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("issi", $expenseId, $origName, $storedPath, $size);
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
        if ($_POST['action'] === 'import_clients_csv') {
            // Expects a CSV with header row: Client Name, Email, Rate, Billing
            // Frequency, Account Name, Account Number, Payment Terms Days, Phone,
            // Address — the Add Client fields, not the richer "Export Clients" CSV
            // format. Phone/Address are trailing and optional so CSVs written
            // before those fields existed still import cleanly.
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
            $existingKeys = [];
            $keyRes = $mysqli->query("SELECT client_key FROM invoxa_clients");
            while ($kr = $keyRes->fetch_assoc())
                $existingKeys[$kr['client_key']] = true;

            $insert = $mysqli->prepare("INSERT INTO invoxa_clients (client_name, email, phone, address, account_name, account_number, monthly_rate, payment_terms_days, billing_frequency, is_active, is_test, client_key) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 0, ?)");
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
                $rate = (float) ($row[2] ?? 0);
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
            echo json_encode(['success' => true, 'imported' => $imported, 'skipped' => $skipped, 'errors' => $errors]);
            exit;
        }
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
            $currencyCode = $settings['currency'] ?? (getenv('APP_CURRENCY') ?: 'USD');
            $html = generateInvoiceHTML($client['client_name'], $date, $dueDate, $invNum, number_format($amount, 2), $client['account_name'] ?: ($settings['default_account_name'] ?? ''), $client['account_number'] ?: ($settings['default_account_number'] ?? ''), getenv('SMTP_FROM_EMAIL') ?: '', $lineItems, $brandColor, $footerText, $currencyCode, invoiceWatermarkFingerprint($settings), $totals['discount_pct'], $totals['tax_rate'], $settings['invoice_template'] ?? 'detailed', null, !($licenseValid && ($settings['hide_powered_by'] ?? '0') === '1'), vatNumber: $settings['vat_number'] ?? '', recipientPhone: $client['phone'] ?? '', recipientAddress: $client['address'] ?? '', customTemplate: ($settings['invoice_template'] ?? 'detailed') === 'custom' ? ($settings['custom_invoice_template'] ?? '') : null, businessName: $settings['business_name'] ?? '');
            echo json_encode(['success' => true, 'html' => $html, 'invoice_number' => $invNum]);
            exit;
        }
        if ($_POST['action'] === 'preview_adhoc_pdf') {
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
            $currencyCode = $settings['currency'] ?? (getenv('APP_CURRENCY') ?: 'USD');
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
            $currencyCode = $settings['currency'] ?? (getenv('APP_CURRENCY') ?: 'USD');
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
            $stmt = $mysqli->prepare("INSERT INTO invoxa_invoices (invoice_number, client_key, client_name, recipient_email, invoice_date, due_date, amount, status, html_content, file_path, is_quote, quote_expires_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'draft', ?, ?, 1, ?)");
            $stmt->bind_param("ssssssdsss", $quoteNum, $client['client_key'], $client['client_name'], $client['email'], $date, $dueDate, $amount, $htmlContent, $relPath, $quoteExpiresAt);
            $stmt->execute();
            $memo = trim($_POST['memo'] ?? '');
            if ($memo !== '') {
                $qid = $stmt->insert_id;
                $memoStmt = $mysqli->prepare("INSERT INTO invoxa_actions (invoice_id, invoice_number, action_type, notes) VALUES (?, ?, 'note_added', ?)");
                $memoStmt->bind_param("iss", $qid, $quoteNum, $memo);
                $memoStmt->execute();
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
            $runStmt = $mysqli->prepare("INSERT INTO invoxa_actions (invoice_id, invoice_number, action_type, notes) VALUES (NULL, '', 'recurring_run', ?)");
            $runStmt->bind_param("s", $runNotes);
            $runStmt->execute();
            echo json_encode(['success' => true, 'sent' => $sent, 'errors' => $errors, 'skipped' => $skipped, 'reminders_sent' => $remindersSent, 'reminder_errors' => $reminderErrors, 'late_fees_charged' => $lateFeesCharged, 'late_fee_errors' => $lateFeeErrors, 'audit_log_pruned' => $auditPruned, 'recurring_expenses_logged' => $recurExpSent, 'recurring_expenses_skipped' => $recurExpSkipped, 'recurring_expenses_errors' => $recurExpErrors]);
            exit;
        }
        if ($_POST['action'] === 'mark_paid') {
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
        if ($_POST['action'] === 'get_invoice_payments') {
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
        if ($_POST['action'] === 'mark_unpaid') {
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
            $stmt = $mysqli->prepare("INSERT INTO invoxa_actions (invoice_id, invoice_number, action_type, notes) VALUES (?, ?, 'mark_unpaid', 'Marked as unpaid — payment history cleared')");
            $stmt->bind_param("is", $id, $invNum);
            $stmt->execute();
            echo json_encode(['success' => true]);
            exit;
        }
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
            $stmt = $mysqli->prepare("INSERT INTO invoxa_actions (invoice_id, invoice_number, action_type, notes) VALUES (?, ?, 'invoice_voided', ?)");
            $stmt->bind_param("iss", $id, $invRow['invoice_number'], $notes);
            $stmt->execute();
            echo json_encode(['success' => true]);
            exit;
        }
        if ($_POST['action'] === 'unvoid_invoice') {
            $id = (int) ($_POST['id'] ?? 0);
            $invNum = $mysqli->query("SELECT invoice_number FROM invoxa_invoices WHERE id = $id")->fetch_assoc()['invoice_number'] ?? '';
            $stmt = $mysqli->prepare("UPDATE invoxa_invoices SET status = 'sent' WHERE id = ? AND status = 'void'");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $stmt = $mysqli->prepare("INSERT INTO invoxa_actions (invoice_id, invoice_number, action_type, notes) VALUES (?, ?, 'invoice_unvoided', 'Restored from void')");
            $stmt->bind_param("is", $id, $invNum);
            $stmt->execute();
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
            $currencyCode = $settings['currency'] ?? (getenv('APP_CURRENCY') ?: 'USD');
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
            $stmt = $mysqli->prepare("INSERT INTO invoxa_actions (invoice_id, invoice_number, action_type, notes) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("isss", $id, $inv['invoice_number'], $actionType, $notes);
            $stmt->execute();
            // A successful resend clears a previously-failed status — it's been
            // sent now, same as if it had succeeded the first time.
            if ($emailSent && $inv['status'] === 'failed') {
                $mysqli->query("UPDATE invoxa_invoices SET status = 'sent' WHERE id = $id");
            }
            echo json_encode(['success' => $emailSent, 'error' => $errorMsg]);
            exit;
        }
        if ($_POST['action'] === 'bulk_mark_paid') {
            $clientKey = $mysqli->real_escape_string($_POST['client_key'] ?? '');
            if (!$clientKey) {
                echo json_encode(['success' => false, 'error' => 'No client selected']);
                exit;
            }
            $res = $mysqli->query("SELECT id, amount, paid_amount FROM invoxa_invoices WHERE client_key = '$clientKey' AND status IN ('sent', 'pending')");
            $updated = 0;
            // Credits the remaining balance (not the full amount) through the same
            // recordInvoicePayment() every other payment path uses, so a partial
            // payment already recorded isn't double-counted.
            while ($row = $res->fetch_assoc()) {
                $remaining = (float) $row['amount'] - (float) ($row['paid_amount'] ?? 0);
                if ($remaining > 0) {
                    recordInvoicePayment($mysqli, $settings, (int) $row['id'], $remaining, 'Bulk marked as paid', 'manual');
                } else {
                    // Nothing left to credit (paid_amount already >= amount but status
                    // somehow wasn't flipped) — just fix the status directly.
                    $mysqli->query("UPDATE invoxa_invoices SET status = 'paid', paid_at = NOW() WHERE id = " . (int) $row['id']);
                }
                $updated++;
            }
            echo json_encode(['success' => true, 'updated' => $updated]);
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
            $stmt = $mysqli->prepare("INSERT INTO invoxa_actions (invoice_id, invoice_number, action_type, notes) VALUES (?, ?, 'note_added', ?)");
            $stmt->bind_param("iss", $id, $invNum, $note);
            $stmt->execute();
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
        if ($_POST['action'] === 'test_email') {
            $to = $_POST['email'];
            require_once PHPMAILER_DIR . 'PHPMailer.php';
            require_once PHPMAILER_DIR . 'SMTP.php';
            require_once PHPMAILER_DIR . 'Exception.php';
            $fromName = $settings['business_name'] ?? (getenv('SMTP_FROM_NAME') ?: 'Invoxa');
            $fromEmail = getenv('SMTP_FROM_EMAIL') ?: '';
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
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
                $mail->addAddress($to);
                $mail->Subject = "SMTP Test - {$fromName}";
                $mail->Body = "This is a test email sent from {$fromName} to verify SMTP configuration.";
                $mail->send();
                $logNotes = "Test email sent to {$to}";
                $stmtAction = $mysqli->prepare("INSERT INTO invoxa_actions (invoice_id, invoice_number, action_type, notes) VALUES (NULL, '', 'smtp_test', ?)");
                $stmtAction->bind_param("s", $logNotes);
                $stmtAction->execute();
                echo json_encode(['success' => true]);
                exit;
            } catch (Exception $e) {
                $logNotes = "Test email to {$to} failed: " . $e->getMessage();
                $stmtAction = $mysqli->prepare("INSERT INTO invoxa_actions (invoice_id, invoice_number, action_type, notes) VALUES (NULL, '', 'smtp_test', ?)");
                $stmtAction->bind_param("s", $logNotes);
                $stmtAction->execute();
                throw new Exception($e->getMessage());
            }
        }
        if ($_POST['action'] === 'save_notification_settings') {
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
            $upsert = $mysqli->prepare("INSERT INTO invoxa_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
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
            ] as $key => $value) {
                $upsert->bind_param("ss", $key, $value);
                $upsert->execute();
            }
            echo json_encode(['success' => true]);
            exit;
        }
        if ($_POST['action'] === 'test_notification') {
            // Tests against whatever channel/fields are currently typed in the form,
            // not the saved settings, so you don't have to Save blind first.
            $channel = in_array($_POST['notification_channel'] ?? 'none', ['telegram', 'slack', 'webhook'], true)
                ? $_POST['notification_channel'] : null;
            $fromName = $settings['business_name'] ?? 'Invoxa';
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
            $stmtAction = $mysqli->prepare("INSERT INTO invoxa_actions (invoice_id, invoice_number, action_type, notes) VALUES (NULL, '', ?, ?)");
            $actionType = $result['success'] ? 'notification_test' : 'notification_failed';
            $stmtAction->bind_param("ss", $actionType, $logNotes);
            $stmtAction->execute();
            echo json_encode($result);
            exit;
        }
        if ($_POST['action'] === 'save_payment_settings') {
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
        if ($_POST['action'] === 'test_stripe_connection') {
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
        if ($_POST['action'] === 'test_paypal_connection') {
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
        if ($_POST['action'] === 'create_api_token') {
            $label = trim($_POST['label'] ?? '');
            if ($label === '') {
                throw new Exception('Give this token a label so you can tell it apart later.');
            }
            $expiryDays = ['never' => null, '30' => 30, '90' => 90, '365' => 365][$_POST['expiry'] ?? 'never'] ?? null;
            $created = invoxaCreateApiToken($mysqli, $label, $expiryDays);
            $mysqli->query("INSERT INTO invoxa_actions (invoice_id, invoice_number, action_type, notes) VALUES (NULL, '', 'api_token_created', 'API token created: " . $mysqli->real_escape_string($label) . "')");
            echo json_encode(['success' => true, 'token' => $created['token']]);
            exit;
        }
        if ($_POST['action'] === 'renew_api_token') {
            $id = (int) ($_POST['id'] ?? 0);
            $expiryDays = ['never' => null, '30' => 30, '90' => 90, '365' => 365][$_POST['expiry'] ?? 'never'] ?? null;
            if ($expiryDays === null) {
                $stmt = $mysqli->prepare("UPDATE invoxa_api_tokens SET expires_at = NULL WHERE id = ? AND revoked_at IS NULL");
                $stmt->bind_param("i", $id);
            } else {
                $stmt = $mysqli->prepare("UPDATE invoxa_api_tokens SET expires_at = DATE_ADD(NOW(), INTERVAL ? DAY) WHERE id = ? AND revoked_at IS NULL");
                $stmt->bind_param("ii", $expiryDays, $id);
            }
            $stmt->execute();
            echo json_encode(['success' => true]);
            exit;
        }
        if ($_POST['action'] === 'revoke_api_token') {
            $id = (int) ($_POST['id'] ?? 0);
            $label = $mysqli->query("SELECT label FROM invoxa_api_tokens WHERE id = " . $id)->fetch_assoc()['label'] ?? '';
            $stmt = $mysqli->prepare("UPDATE invoxa_api_tokens SET revoked_at = NOW() WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $mysqli->query("INSERT INTO invoxa_actions (invoice_id, invoice_number, action_type, notes) VALUES (NULL, '', 'api_token_revoked', 'API token revoked: " . $mysqli->real_escape_string($label) . "')");
            echo json_encode(['success' => true]);
            exit;
        }
        if ($_POST['action'] === 'delete_api_token') {
            // Only ever removes a token that's already revoked or expired — an
            // active token must be revoked first (see revoke_api_token above),
            // so a live token can't skip that audit trail entry.
            $id = (int) ($_POST['id'] ?? 0);
            $row = $mysqli->query("SELECT label, revoked_at, expires_at FROM invoxa_api_tokens WHERE id = " . $id)->fetch_assoc();
            if (!$row) {
                throw new Exception('Token not found');
            }
            $isExpired = !empty($row['expires_at']) && strtotime($row['expires_at']) < time();
            if (empty($row['revoked_at']) && !$isExpired) {
                throw new Exception('Revoke this token before deleting it.');
            }
            $stmt = $mysqli->prepare("DELETE FROM invoxa_api_tokens WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $mysqli->query("INSERT INTO invoxa_actions (invoice_id, invoice_number, action_type, notes) VALUES (NULL, '', 'api_token_revoked', 'API token permanently deleted: " . $mysqli->real_escape_string($row['label']) . "')");
            echo json_encode(['success' => true]);
            exit;
        }
        if ($_POST['action'] === 'update_profile') {
            $newUsername = trim($_POST['new_username'] ?? '');
            $newEmail = trim($_POST['new_email'] ?? '');
            $currentPassword = $_POST['current_password'] ?? '';
            $newPassword = $_POST['new_password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';
            // Fetch current user (single admin)
            $userRes = $mysqli->query("SELECT * FROM invoxa_users LIMIT 1");
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
            // Build update query
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
                $stmt = $mysqli->prepare('UPDATE invoxa_users SET ' . implode(', ', $fields) . ' WHERE id = ?');
                $params[] = $user['id'];
                $types .= 'i';
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
            }
            if ($emailChanged) {
                invoxaIssueEmailVerification($mysqli, (int) $user['id'], $newUsername !== '' ? $newUsername : $user['username'], $newEmail);
            }
            echo json_encode(['success' => true]);
            exit;
        }
        if ($_POST['action'] === 'totp_setup_init') {
            // Single-admin app (see the signup gate above) — no user_id param needed,
            // it's always "the" account.
            $userRow = $mysqli->query("SELECT id, username FROM invoxa_users LIMIT 1")->fetch_assoc();
            if (!$userRow) {
                throw new Exception('Account not found');
            }
            $secret = generateTotpSecret();
            $stmt = $mysqli->prepare("UPDATE invoxa_users SET totp_secret_pending = ? WHERE id = ?");
            $stmt->bind_param("si", $secret, $userRow['id']);
            $stmt->execute();
            echo json_encode(['success' => true, 'secret' => $secret, 'account_label' => $userRow['username'], 'otpauth_uri' => totpOtpauthUri($secret, $userRow['username'])]);
            exit;
        }
        if ($_POST['action'] === 'totp_setup_confirm') {
            $userRow = $mysqli->query("SELECT id, totp_secret_pending FROM invoxa_users LIMIT 1")->fetch_assoc();
            if (!$userRow || empty($userRow['totp_secret_pending'])) {
                throw new Exception('No setup in progress — click Enable Two-Factor Authentication to start again.');
            }
            if (!verifyTotpCode($userRow['totp_secret_pending'], $_POST['code'] ?? '')) {
                throw new Exception('Invalid code. Check the time on your device and try again.');
            }
            $stmt = $mysqli->prepare("UPDATE invoxa_users SET totp_secret = totp_secret_pending, totp_secret_pending = NULL WHERE id = ?");
            $stmt->bind_param("i", $userRow['id']);
            $stmt->execute();
            $backupCodes = invoxaIssueBackupCodes($mysqli, (int) $userRow['id']);
            $mysqli->query("INSERT INTO invoxa_actions (invoice_id, invoice_number, action_type, notes) VALUES (NULL, '', 'totp_enabled', 'Two-factor authentication enabled')");
            echo json_encode(['success' => true, 'backup_codes' => $backupCodes]);
            exit;
        }
        if ($_POST['action'] === 'totp_regenerate_backup_codes') {
            $userRow = $mysqli->query("SELECT id, password_hash, totp_secret FROM invoxa_users LIMIT 1")->fetch_assoc();
            if (!$userRow || empty($userRow['totp_secret'])) {
                throw new Exception('Two-factor authentication is not enabled.');
            }
            if (!password_verify($_POST['current_password'] ?? '', $userRow['password_hash'])) {
                throw new Exception('Current password is incorrect.');
            }
            $backupCodes = invoxaIssueBackupCodes($mysqli, (int) $userRow['id']);
            $mysqli->query("INSERT INTO invoxa_actions (invoice_id, invoice_number, action_type, notes) VALUES (NULL, '', 'totp_enabled', 'Backup codes regenerated — previous codes no longer work')");
            echo json_encode(['success' => true, 'backup_codes' => $backupCodes]);
            exit;
        }
        if ($_POST['action'] === 'totp_disable') {
            $userRow = $mysqli->query("SELECT id, password_hash FROM invoxa_users LIMIT 1")->fetch_assoc();
            if (!$userRow || !password_verify($_POST['current_password'] ?? '', $userRow['password_hash'])) {
                throw new Exception('Current password is incorrect.');
            }
            $stmt = $mysqli->prepare("UPDATE invoxa_users SET totp_secret = NULL, totp_secret_pending = NULL WHERE id = ?");
            $stmt->bind_param("i", $userRow['id']);
            $stmt->execute();
            $mysqli->query("DELETE FROM invoxa_totp_backup_codes WHERE user_id = " . (int) $userRow['id']);
            $mysqli->query("INSERT INTO invoxa_actions (invoice_id, invoice_number, action_type, notes) VALUES (NULL, '', 'totp_disabled', 'Two-factor authentication disabled')");
            echo json_encode(['success' => true]);
            exit;
        }
        if ($_POST['action'] === 'toggle_test_clients') {
            $val = $_POST['hide'] === '1' ? '1' : '0';
            $mysqli->query("INSERT INTO invoxa_settings (setting_key, setting_value) VALUES ('hide_test', '$val') ON DUPLICATE KEY UPDATE setting_value = '$val'");
            echo json_encode(['success' => true]);
            exit;
        }
        if ($_POST['action'] === 'toggle_show_test_only') {
            $val = $_POST['show'] === '1' ? '1' : '0';
            $mysqli->query("INSERT INTO invoxa_settings (setting_key, setting_value) VALUES ('show_test_only', '$val') ON DUPLICATE KEY UPDATE setting_value = '$val'");
            echo json_encode(['success' => true]);
            exit;
        }
        if ($_POST['action'] === 'get_default_invoice_template') {
            echo json_encode(['success' => true, 'template' => defaultCustomInvoiceTemplate()]);
            exit;
        }
        if ($_POST['action'] === 'preview_invoice_template') {
            $template = in_array($_POST['template'] ?? '', ['compact', 'custom'], true) ? $_POST['template'] : 'detailed';
            $customHtml = $_POST['custom_html'] ?? ($settings['custom_invoice_template'] ?? '');
            echo json_encode(['success' => true, 'html' => invoxaSampleInvoiceHtml($template, $customHtml, $settings, $licenseValid)]);
            exit;
        }
        if ($_POST['action'] === 'save_invoice_template') {
            $invoiceTemplate = in_array($_POST['invoice_template'] ?? '', ['compact', 'custom'], true) ? $_POST['invoice_template'] : 'detailed';
            $customInvoiceTemplate = $_POST['custom_invoice_template'] ?? '';

            $upsert = $mysqli->prepare("INSERT INTO invoxa_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
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
        if ($_POST['action'] === 'save_business_identity') {
            $businessName = $_POST['business_name'] ?? '';
            $vatNumber = trim($_POST['vat_number'] ?? '');
            $brandColor = $_POST['brand_color'] ?? '#4a90e2';

            $upsert = $mysqli->prepare("INSERT INTO invoxa_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
            foreach ([
                'business_name' => $businessName,
                'vat_number' => $vatNumber,
                'brand_color' => $brandColor,
            ] as $key => $value) {
                $upsert->bind_param("ss", $key, $value);
                $upsert->execute();
            }

            // Silently ignored (not rejected) when unlicensed — hiding "Powered by
            // Invoxa" is a paid feature, but an unlicensed user saving other fields
            // in this same form shouldn't get an error over it.
            if ($licenseValid) {
                $hidePoweredBy = ($_POST['hide_powered_by'] ?? '0') === '1' ? '1' : '0';
                $upsertHidePB = $mysqli->prepare("INSERT INTO invoxa_settings (setting_key, setting_value) VALUES ('hide_powered_by', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
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
        if ($_POST['action'] === 'save_invoice_defaults') {
            $currency = strtoupper(preg_replace('/[^A-Za-z]/', '', $_POST['currency'] ?? '')) ?: 'USD';
            $currency = substr($currency, 0, 3);
            $taxYearStartMonth = (int) ($_POST['tax_year_start_month'] ?? 1);
            if ($taxYearStartMonth < 1 || $taxYearStartMonth > 12)
                $taxYearStartMonth = 1;

            $upsert = $mysqli->prepare("INSERT INTO invoxa_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
            foreach ([
                'currency' => $currency,
                'tax_year_start_month' => (string) $taxYearStartMonth,
            ] as $key => $value) {
                $upsert->bind_param("ss", $key, $value);
                $upsert->execute();
            }
            echo json_encode(['success' => true]);
            exit;
        }
        if ($_POST['action'] === 'save_payment_details') {
            $footerText = $_POST['footer_text'] ?? '';
            $defaultAccountName = $_POST['default_account_name'] ?? '';
            $defaultAccountNumber = $_POST['default_account_number'] ?? '';

            $upsert = $mysqli->prepare("INSERT INTO invoxa_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
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
        if ($_POST['action'] === 'save_email_templates') {
            $invoiceEmailSubject = trim($_POST['invoice_email_subject'] ?? '') ?: DEFAULT_INVOICE_SUBJECT;
            $reminderEmailSubject = trim($_POST['reminder_email_subject'] ?? '') ?: DEFAULT_REMINDER_SUBJECT;
            $reminderEmailBody = trim($_POST['reminder_email_body'] ?? '') ?: DEFAULT_REMINDER_BODY;

            $upsert = $mysqli->prepare("INSERT INTO invoxa_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
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
        if ($_POST['action'] === 'save_invoice_numbering') {
            $template = trim($_POST['invoice_number_template'] ?? '') ?: '{key}{seq}';
            $padding = (int) ($_POST['invoice_number_padding'] ?? 3);
            if ($padding < 1 || $padding > 10)
                $padding = 3;
            $upsert = $mysqli->prepare("INSERT INTO invoxa_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
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
        if ($_POST['action'] === 'save_offsite_backup') {
            // Only the toggle + non-secret destination info lives here. The rclone
            // remote itself (credentials, endpoint) is configured separately in the
            // cron container's rclone.conf, keyed by remote name — never stored in
            // this DB, so a compromise of the web app can't leak storage credentials.
            $enabled = ($_POST['offsite_backup_enabled'] ?? '0') === '1' ? '1' : '0';
            $remoteName = trim($_POST['offsite_remote_name'] ?? '');
            $remotePath = trim($_POST['offsite_remote_path'] ?? '');
            $retention = (int) ($_POST['offsite_retention_count'] ?? 14);
            if ($retention < 1 || $retention > 365)
                $retention = 14;

            $upsert = $mysqli->prepare("INSERT INTO invoxa_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
            foreach ([
                'offsite_backup_enabled' => $enabled,
                'offsite_remote_name' => $remoteName,
                'offsite_remote_path' => $remotePath,
                'offsite_retention_count' => (string) $retention,
            ] as $key => $value) {
                $upsert->bind_param("ss", $key, $value);
                $upsert->execute();
            }
            echo json_encode(['success' => true]);
            exit;
        }
        if ($_POST['action'] === 'backup_db') {
            error_reporting(0);
            ob_start();
            try {
                $tables = [];
                if (isset($_POST['tables']) && !empty($_POST['tables'])) {
                    $selected = explode(',', $_POST['tables']);
                    $result = $mysqli->query("SHOW TABLES");
                    while ($row = $result->fetch_row()) {
                        if (in_array($row[0], $selected)) {
                            $tables[] = $row[0];
                        }
                    }
                } else {
                    $result = $mysqli->query("SHOW TABLES");
                    while ($row = $result->fetch_row()) {
                        $tables[] = $row[0];
                    }
                }
                $sql = "";
                foreach ($tables as $table) {
                    $result = $mysqli->query("SHOW CREATE TABLE " . $table);
                    $row = $result->fetch_row();
                    $sql .= "DROP TABLE IF EXISTS " . $table . ";\n";
                    $sql .= $row[1] . ";\n\n";
                    $result = $mysqli->query("SELECT * FROM " . $table);
                    $num_fields = $result->field_count;
                    for ($i = 0; $i < $num_fields; $i++) {
                        while ($row = $result->fetch_row()) {
                            $sql .= "INSERT INTO " . $table . " VALUES(";
                            for ($j = 0; $j < $num_fields; $j++) {
                                if (isset($row[$j])) {
                                    $val = addslashes($row[$j]);
                                    $val = str_replace("\n", "\\n", $val);
                                    $sql .= '"' . $val . '"';
                                } else {
                                    $sql .= 'NULL';
                                }
                                if ($j < ($num_fields - 1)) {
                                    $sql .= ',';
                                }
                            }
                            $sql .= ");\n";
                        }
                    }
                    $sql .= "\n\n";
                }

                $filename = "backup_" . date("Y-m-d") . ".sql";
                if (!is_dir(BACKUPS_DIR)) {
                    @mkdir(BACKUPS_DIR, 0777, true);
                }
                if (file_put_contents(BACKUPS_DIR . $filename, $sql) === false) {
                    throw new Exception("Failed to write to file.");
                }

                // Prune old backups beyond the configured retention count, if set —
                // same "keep last N" idea as Audit Log Retention, but for files on
                // disk. 0/unset keeps everything (the default).
                $retainCount = (int) ($settings['local_backup_retention_count'] ?? 0);
                if ($retainCount > 0) {
                    $backupFiles = glob(BACKUPS_DIR . 'backup_*.sql') ?: [];
                    usort($backupFiles, fn($a, $b) => filemtime($b) - filemtime($a));
                    foreach (array_slice($backupFiles, $retainCount) as $oldFile) {
                        @unlink($oldFile);
                    }
                }

                ob_clean();
                echo json_encode(['success' => true, 'downloadUrl' => '/invoxa-backups/' . $filename]);
            } catch (Throwable $e) {
                ob_clean();
                echo json_encode(['success' => false, 'error' => $e->getMessage() . ' on line ' . $e->getLine()]);
            }
            exit;
        }
        if ($_POST['action'] === 'get_db_stats') {
            error_reporting(0);
            ob_start();
            try {
                $stats = [];
                $res = $mysqli->query("SHOW TABLES");
                while ($row = $res->fetch_row()) {
                    $t = $row[0];
                    $c = $mysqli->query("SELECT COUNT(*) FROM " . $t)->fetch_row()[0];
                    $stats[$t] = (int) $c;
                }
                ob_clean();
                echo json_encode(['success' => true, 'stats' => $stats]);
            } catch (Throwable $e) {
                ob_clean();
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            exit;
        }
        if ($_POST['action'] === 'list_backups') {
            $files = [];
            foreach (glob(BACKUPS_DIR . 'backup_*.sql') as $f) {
                $files[] = ['filename' => basename($f), 'size' => filesize($f), 'modified' => date('Y-m-d H:i:s', filemtime($f))];
            }
            usort($files, fn($a, $b) => $b['modified'] <=> $a['modified']);
            echo json_encode(['success' => true, 'backups' => $files]);
            exit;
        }
        if ($_POST['action'] === 'import_backup') {
            // For migrating to a new install: accepts a backup file exported via
            // 'backup_db' on another Invoxa instance and drops it into BACKUPS_DIR so
            // it goes through the normal list/preview/restore flow below, rather than
            // a separate upload-and-run-arbitrary-SQL path.
            error_reporting(0);
            ob_start();
            try {
                if (!isset($_FILES['backup_file']) || $_FILES['backup_file']['error'] !== UPLOAD_ERR_OK) {
                    throw new Exception('No file uploaded, or the upload failed.');
                }
                if (!is_uploaded_file($_FILES['backup_file']['tmp_name'])) {
                    throw new Exception('Invalid upload.');
                }
                $origName = basename($_FILES['backup_file']['name']);
                if (strtolower(pathinfo($origName, PATHINFO_EXTENSION)) !== 'sql') {
                    throw new Exception('Only .sql backup files are accepted.');
                }
                if (!is_dir(BACKUPS_DIR)) {
                    @mkdir(BACKUPS_DIR, 0777, true);
                }
                if (preg_match('/^backup_\d{4}-\d{2}-\d{2}(_\d+)?\.sql$/', $origName) && !is_file(BACKUPS_DIR . $origName)) {
                    $safeName = $origName;
                } else {
                    $safeName = 'backup_' . date('Y-m-d') . '_imported_' . bin2hex(random_bytes(3)) . '.sql';
                }
                $content = file_get_contents($_FILES['backup_file']['tmp_name']);
                if ($content === false) {
                    throw new Exception('Failed to read the uploaded file.');
                }
                $remapped = false;
                $content = invoxaRemapLegacyTableNames($content, $remapped);
                if (file_put_contents(BACKUPS_DIR . $safeName, $content) === false) {
                    throw new Exception('Failed to save the uploaded file.');
                }
                ob_clean();
                echo json_encode(['success' => true, 'filename' => $safeName, 'remapped' => $remapped]);
            } catch (Throwable $e) {
                ob_clean();
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            exit;
        }
        if ($_POST['action'] === 'factory_reset') {
            error_reporting(0);
            ob_start();
            try {
                if (($_POST['confirm'] ?? '') !== 'RESET') {
                    throw new Exception('Type RESET to confirm.');
                }
                $userRes = $mysqli->query("SELECT password_hash FROM invoxa_users LIMIT 1");
                $user = $userRes ? $userRes->fetch_assoc() : null;
                if (!$user || !password_verify($_POST['password'] ?? '', $user['password_hash'])) {
                    throw new Exception('Current password is incorrect.');
                }
                invoxaWipeAllData($mysqli);
                $_SESSION = [];
                session_destroy();
                ob_clean();
                echo json_encode(['success' => true]);
            } catch (Throwable $e) {
                ob_clean();
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            exit;
        }
        if ($_POST['action'] === 'resend_verification_email') {
            error_reporting(0);
            ob_start();
            try {
                $user = $mysqli->query("SELECT id, username, email FROM invoxa_users LIMIT 1")->fetch_assoc();
                if (!$user || empty($user['email'])) {
                    throw new Exception('No account email on file.');
                }
                $sent = invoxaIssueEmailVerification($mysqli, (int) $user['id'], $user['username'], $user['email']);
                ob_clean();
                echo json_encode(['success' => $sent, 'error' => $sent ? '' : 'Failed to send — check SMTP settings.']);
            } catch (Throwable $e) {
                ob_clean();
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            exit;
        }
        if ($_POST['action'] === 'seed_demo_data') {
            error_reporting(0);
            ob_start();
            try {
                global $settings;
                $count = seedDemoData($mysqli, $settings);
                ob_clean();
                echo json_encode(['success' => true, 'count' => $count]);
            } catch (Throwable $e) {
                ob_clean();
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            exit;
        }
        if ($_POST['action'] === 'clear_demo_data') {
            error_reporting(0);
            ob_start();
            try {
                $count = clearDemoData($mysqli);
                ob_clean();
                echo json_encode(['success' => true, 'count' => $count]);
            } catch (Throwable $e) {
                ob_clean();
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            exit;
        }
        if ($_POST['action'] === 'run_test_suite') {
            $selected = null;
            if (isset($_POST['tests'])) {
                $decoded = json_decode($_POST['tests'], true);
                if (is_array($decoded)) {
                    $selected = $decoded;
                }
            }
            echo json_encode(array_merge(['success' => true], invoxaRunTestSuite($mysqli, $settings, $selected)));
            exit;
        }
        if ($_POST['action'] === 'preview_restore') {
            // Dry-run summary for a backup, computed server-side so the raw SQL dump
            // (password hashes, client PII) never has to be sent to the browser.
            $filename = basename($_POST['filename'] ?? '');
            if (!preg_match('/^backup_\d{4}-\d{2}-\d{2}(_\d+)?\.sql$/', $filename)) {
                echo json_encode(['success' => false, 'error' => 'Invalid backup filename.']);
                exit;
            }
            $path = BACKUPS_DIR . $filename;
            if (!is_file($path)) {
                echo json_encode(['success' => false, 'error' => 'Backup file not found.']);
                exit;
            }
            $fileStats = [];
            $handle = fopen($path, 'r');
            while (($line = fgets($handle)) !== false) {
                if (preg_match('/CREATE TABLE\s+(?:IF NOT EXISTS\s+)?`?([a-zA-Z0-9_]+)`?/i', $line, $m)) {
                    $fileStats[$m[1]] = $fileStats[$m[1]] ?? 0;
                }
                if (preg_match('/INSERT INTO\s+`?([a-zA-Z0-9_]+)`?/i', $line, $m)) {
                    $fileStats[$m[1]] = ($fileStats[$m[1]] ?? 0) + 1;
                }
            }
            fclose($handle);
            echo json_encode(['success' => true, 'fileStats' => $fileStats]);
            exit;
        }
        if ($_POST['action'] === 'restore_db_backup') {
            // Only restores files this app itself generated via 'backup_db',
            // identified by filename and re-verified to exist in BACKUPS_DIR — never
            // arbitrary uploaded SQL. Requires an explicit confirmation flag.
            error_reporting(0);
            ob_start();
            try {
                $filename = basename($_POST['filename'] ?? '');
                if ($_POST['confirm'] !== '1') {
                    throw new Exception('Restore was not confirmed.');
                }
                if (!preg_match('/^backup_\d{4}-\d{2}-\d{2}(_\d+)?\.sql$/', $filename)) {
                    throw new Exception('Invalid backup filename.');
                }
                $path = BACKUPS_DIR . $filename;
                if (!is_file($path)) {
                    throw new Exception('Backup file not found.');
                }

                $sql = "SET FOREIGN_KEY_CHECKS = 0;\n" . file_get_contents($path) . "\nSET FOREIGN_KEY_CHECKS = 1;\n";
                if ($mysqli->multi_query($sql)) {
                    do {
                        if ($res = $mysqli->store_result()) {
                            $res->free();
                        }
                    } while ($mysqli->more_results() && $mysqli->next_result());

                    if ($mysqli->errno) {
                        throw new Exception("Restore failed on statement: " . $mysqli->error);
                    }

                    ob_clean();
                    echo json_encode(['success' => true]);
                } else {
                    throw new Exception("Restore failed: " . $mysqli->error);
                }
            } catch (Throwable $e) {
                ob_clean();
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            exit;
        }
        if ($_POST['action'] === 'get_crm_data') {
            $clientId = (int) ($_POST['client_id'] ?? 0);
            $stats = $mysqli->query("SELECT SUM(amount) as total_billed, SUM(CASE WHEN status='paid' THEN amount ELSE 0 END) as total_paid, COUNT(*) as inv_count FROM invoxa_invoices WHERE client_key = (SELECT client_key FROM invoxa_clients WHERE id = $clientId) AND is_quote = 0")->fetch_assoc();
            $recent = [];
            $rRes = $mysqli->query("SELECT invoice_number, invoice_date, amount, status FROM invoxa_invoices WHERE client_key = (SELECT client_key FROM invoxa_clients WHERE id = $clientId) AND is_quote = 0 ORDER BY invoice_date DESC LIMIT 5");
            while ($r = $rRes->fetch_assoc())
                $recent[] = $r;
            $clientRow = $mysqli->query("SELECT crm_notes FROM invoxa_clients WHERE id = $clientId")->fetch_assoc();
            echo json_encode(['success' => true, 'stats' => $stats, 'recent' => $recent, 'crm_notes' => $clientRow['crm_notes'] ?? '']);
            exit;
        }
        if ($_POST['action'] === 'save_crm_notes') {
            $clientId = (int) ($_POST['client_id'] ?? 0);
            $notes = $_POST['notes'] ?? '';
            $stmt = $mysqli->prepare("UPDATE invoxa_clients SET crm_notes = ? WHERE id = ?");
            $stmt->bind_param("si", $notes, $clientId);
            $stmt->execute();
            echo json_encode(['success' => true]);
            exit;
        }
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
        if ($_POST['action'] === 'save_audit_retention') {
            $days = in_array($_POST['audit_log_retention_days'] ?? '', ['0', '30', '180', '365'], true)
                ? $_POST['audit_log_retention_days'] : '0';
            $stmt = $mysqli->prepare("INSERT INTO invoxa_settings (setting_key, setting_value) VALUES ('audit_log_retention_days', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
            $stmt->bind_param("s", $days);
            $stmt->execute();
            echo json_encode(['success' => true]);
            exit;
        }
        if ($_POST['action'] === 'save_backup_retention') {
            $count = (int) ($_POST['local_backup_retention_count'] ?? 0);
            if ($count < 0 || $count > 365)
                $count = 0;
            $stmt = $mysqli->prepare("INSERT INTO invoxa_settings (setting_key, setting_value) VALUES ('local_backup_retention_count', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
            $val = (string) $count;
            $stmt->bind_param("s", $val);
            $stmt->execute();
            echo json_encode(['success' => true]);
            exit;
        }
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
        if ($_POST['action'] === 'sync_missing') {
            $files = json_decode($_POST['files'], true);
            $imported = 0;
            $errors = 0;
            $skipped = 0;
            $mismatches = [];
            $clientMap = [];
            $res = $mysqli->query("SELECT * FROM invoxa_clients");
            while ($row = $res->fetch_assoc()) {
                $clientMap[strtolower(str_replace(' ', '_', $row['client_name']))] = $row;
            }
            $insertInvoice = $mysqli->prepare("INSERT INTO invoxa_invoices (invoice_number, client_key, client_name, recipient_email, invoice_date, due_date, amount, status, html_content, file_path) VALUES (?, ?, ?, ?, ?, ?, ?, 'sent', ?, ?) ON DUPLICATE KEY UPDATE file_path = VALUES(file_path), html_content = VALUES(html_content), amount = VALUES(amount), client_key = VALUES(client_key), client_name = VALUES(client_name)");
            $insertAction = $mysqli->prepare("INSERT INTO invoxa_actions (invoice_number, action_type, notes) SELECT ?, 'synced', 'Imported via Web UI Sync' WHERE NOT EXISTS (SELECT 1 FROM invoxa_actions WHERE invoice_number = ? AND action_type = 'synced')");
            foreach ($files as $filePath) {
                $fullPath = "/usr/share/nginx/html/invoxa-invoices/" . preg_replace('#^invoices/#', '', $filePath);
                if (!file_exists($fullPath)) {
                    $errors++;
                    continue;
                }
                $parts = explode('/', $filePath);
                $folderName = $parts[1] ?? '';
                $filename = basename($fullPath);
                // Try to match client by folder name, fall back to folder name as placeholder
                $client = $clientMap[strtolower($folderName)] ?? null;
                if (!$client) {
                    // Use folder name as placeholder client info so the file still imports
                    $client = ['client_key' => strtolower($folderName), 'client_name' => $folderName, 'email' => ''];
                }
                $html = file_get_contents($fullPath);
                $amount = (float) preg_replace('/[^0-9.]/', '', extractField($html, 'Amount Due') ?? '0');

                $filenameInvNum = pathinfo($filename, PATHINFO_FILENAME);
                $internalInvNum = extractField($html, 'Invoice Number');

                // Ensure filename and inside report are correct and flag if not
                if ($internalInvNum && $internalInvNum !== $filenameInvNum) {
                    $mismatches[] = "File '$filename' has internal invoice number '$internalInvNum'";
                    $skipped++;
                    continue;
                }

                $invNum = $filenameInvNum;

                try {
                    $insertInvoice->bind_param("ssssssdss", $invNum, $client['client_key'], $client['client_name'], $client['email'], normaliseDateTime(extractField($html, 'Invoice Date')), normaliseDate(extractField($html, 'Invoice Due')), $amount, $html, $filePath);
                    $insertInvoice->execute();
                    if ($insertInvoice->affected_rows > 0) {
                        $insertAction->bind_param("ss", $invNum, $invNum);
                        $insertAction->execute();
                        $imported++;
                    }
                } catch (Exception $e) {
                    $errors++;
                }
            }
            echo json_encode(['success' => true, 'imported' => $imported, 'skipped' => $skipped, 'errors' => $errors, 'mismatches' => $mismatches]);
            exit;
        }
        if ($_POST['action'] === 'restore_missing') {
            $ids = json_decode($_POST['ids'], true);
            $restored = 0;
            $errors = 0;
            if (empty($ids)) {
                echo json_encode(['success' => true, 'restored' => 0, 'errors' => 0, 'no_content' => 0]);
                exit;
            }
            $idList = implode(',', array_map('intval', $ids));
            // Some rows (e.g. historical records imported without an original file)
            // have no html_content to rebuild from — count those separately so the
            // UI can explain "nothing to rebuild" instead of a silent 0.
            $noContent = $mysqli->query("SELECT COUNT(*) as c FROM invoxa_invoices WHERE id IN ($idList) AND (html_content IS NULL OR html_content = '')")->fetch_assoc()['c'] ?? 0;
            $res = $mysqli->query("SELECT id, client_name, file_path, html_content FROM invoxa_invoices WHERE id IN ($idList) AND html_content IS NOT NULL AND html_content != ''");
            while ($row = $res->fetch_assoc()) {
                if (!$row['file_path'])
                    continue;
                $fullPath = "/usr/share/nginx/html/invoxa-invoices/" . preg_replace('#^invoices/#', '', $row['file_path']);
                $dir = dirname($fullPath);
                if (!is_dir($dir))
                    @mkdir($dir, 0777, true);
                if (@file_put_contents($fullPath, $row['html_content']) !== false) {
                    $restored++;
                } else {
                    $errors++;
                }
            }
            echo json_encode(['success' => true, 'restored' => $restored, 'errors' => $errors, 'no_content' => (int) $noContent]);
            exit;
        }
        if ($_POST['action'] === 'delete_missing_db') {
            $ids = json_decode($_POST['ids'], true);
            if (empty($ids)) {
                echo json_encode(['success' => true, 'deleted' => 0]);
                exit;
            }
            $idList = implode(',', array_map('intval', $ids));
            $mysqli->query("DELETE FROM invoxa_actions WHERE invoice_id IN ($idList)");
            $mysqli->query("DELETE FROM invoxa_invoices WHERE id IN ($idList)");
            echo json_encode(['success' => true, 'deleted' => $mysqli->affected_rows]);
            exit;
        }
        if ($_POST['action'] === 'delete_untracked_file') {
            $filePath = $_POST['file'] ?? '';
            // Sanitise: only allow paths that look like invoices/folder/file.html
            if (!preg_match('#^invoices/[\w\-]+/[\w\-]+\.html$#', $filePath)) {
                echo json_encode(['success' => false, 'error' => 'Invalid file path']);
                exit;
            }
            $fullPath = '/usr/share/nginx/html/invoxa-invoices/' . preg_replace('#^invoices/#', '', $filePath);
            if (file_exists($fullPath)) {
                @unlink($fullPath);
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => 'File not found']);
            }
            exit;
        }
        if ($_POST['action'] === 'delete_single_db_entry') {
            $id = (int) ($_POST['id'] ?? 0);
            $row = $mysqli->query("SELECT file_path FROM invoxa_invoices WHERE id = $id")->fetch_assoc();
            if ($row) {
                if ($row['file_path']) {
                    $fp = '/usr/share/nginx/html/invoxa-invoices/' . preg_replace('#^invoices/#', '', $row['file_path']);
                    if (file_exists($fp))
                        @unlink($fp);
                }
                $mysqli->query("DELETE FROM invoxa_actions WHERE invoice_id = $id");
                $mysqli->query("DELETE FROM invoxa_invoices WHERE id = $id");
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Record not found']);
            }
            exit;
        }
        if ($_POST['action'] === 'preview_tax_year') {
            $now = new DateTime();
            $taxYearStart = getTaxYearStart((int) ($settings['tax_year_start_month'] ?? 1), $now);
            $startStr = $taxYearStart->format('Y-m-d');
            $taxYearLabel = $taxYearStart->format('Y-m-d') . " to " . $now->format('Y-m-d');
            $hideTestRes2 = $mysqli->query("SELECT setting_value FROM invoxa_settings WHERE setting_key = 'hide_test'");
            $hideTest2 = ($hideTestRes2 && $hideTestRes2->num_rows > 0) ? ($hideTestRes2->fetch_assoc()['setting_value'] === '1') : true;
            $showTestOnlyRes2 = $mysqli->query("SELECT setting_value FROM invoxa_settings WHERE setting_key = 'show_test_only'");
            $showTestOnly2 = ($showTestOnlyRes2 && $showTestOnlyRes2->num_rows > 0) ? ($showTestOnlyRes2->fetch_assoc()['setting_value'] === '1') : false;
            $tf2 = invoxaTestViewFilter($hideTest2, $showTestOnly2);
            $res = $mysqli->query("SELECT invoice_number, client_name, invoice_date, due_date, amount, status, paid_amount, paid_at FROM invoxa_invoices WHERE is_quote = 0 AND status != 'void' AND invoice_date >= '$startStr' $tf2 ORDER BY invoice_date ASC");
            $rows = [];
            $totalInvoiced = 0;
            $totalPaid = 0;
            while ($r = $res->fetch_assoc()) {
                $rows[] = $r;
                $totalInvoiced += (float) $r['amount'];
                $totalPaid += (float) $r['paid_amount'];
            }
            // Cash-basis net income (paid revenue minus expenses over the same tax-year
            // window) — unlike Total Invoiced above, this excludes unpaid billings.
            $totalExpenses = (float) ($mysqli->query("SELECT SUM(amount) as s FROM invoxa_expenses WHERE expense_date >= '$startStr'")->fetch_assoc()['s'] ?? 0);
            echo json_encode(['success' => true, 'rows' => $rows, 'label' => $taxYearLabel, 'start' => $startStr, 'total_invoiced' => $totalInvoiced, 'total_paid' => $totalPaid, 'outstanding' => $totalInvoiced - $totalPaid, 'total_expenses' => $totalExpenses, 'net_income' => $totalPaid - $totalExpenses]);
            exit;
        }
        if ($_POST['action'] === 'preview_tax_year_monthly') {
            $now = new DateTime();
            $taxYearStart = getTaxYearStart((int) ($settings['tax_year_start_month'] ?? 1), $now);
            $startStr = $taxYearStart->format('Y-m-d');
            $taxYearLabel = $taxYearStart->format('Y-m-d') . " to " . $now->format('Y-m-d');
            $hideTestRes2 = $mysqli->query("SELECT setting_value FROM invoxa_settings WHERE setting_key = 'hide_test'");
            $hideTest2 = ($hideTestRes2 && $hideTestRes2->num_rows > 0) ? ($hideTestRes2->fetch_assoc()['setting_value'] === '1') : true;
            $showTestOnlyRes2 = $mysqli->query("SELECT setting_value FROM invoxa_settings WHERE setting_key = 'show_test_only'");
            $showTestOnly2 = ($showTestOnlyRes2 && $showTestOnlyRes2->num_rows > 0) ? ($showTestOnlyRes2->fetch_assoc()['setting_value'] === '1') : false;
            $tf2 = invoxaTestViewFilter($hideTest2, $showTestOnly2);
            $res = $mysqli->query("
                SELECT DATE_FORMAT(invoice_date, '%Y-%m') as month,
                       SUM(amount) as total_invoiced,
                       SUM(COALESCE(paid_amount, 0)) as total_paid,
                       SUM(amount) - SUM(COALESCE(paid_amount, 0)) as outstanding,
                       SUM(CASE WHEN status NOT IN ('paid') THEN 1 ELSE 0 END) as unpaid_count
                FROM invoxa_invoices
                WHERE is_quote = 0 AND status != 'void' AND invoice_date >= '$startStr' $tf2
                GROUP BY DATE_FORMAT(invoice_date, '%Y-%m')
                ORDER BY month ASC
            ");
            $expensesByMonth = [];
            $expRes = $mysqli->query("SELECT DATE_FORMAT(expense_date, '%Y-%m') as month, SUM(amount) as total FROM invoxa_expenses WHERE expense_date >= '$startStr' GROUP BY DATE_FORMAT(expense_date, '%Y-%m')");
            while ($er = $expRes->fetch_assoc())
                $expensesByMonth[$er['month']] = (float) $er['total'];

            $rows = [];
            $totalInvoiced = 0;
            $totalPaid = 0;
            $totalExpenses = 0;
            while ($r = $res->fetch_assoc()) {
                $dt2 = DateTime::createFromFormat('Y-m', $r['month']);
                $r['month_label'] = $dt2 ? $dt2->format('F Y') : $r['month'];
                $outstanding = round((float) $r['outstanding'], 2);
                if ($r['unpaid_count'] > 0 && $outstanding > 0)
                    $r['pay_status'] = 'Partial Paid';
                elseif ($outstanding <= 0)
                    $r['pay_status'] = 'Paid';
                else
                    $r['pay_status'] = 'Unpaid';
                $monthExpenses = $expensesByMonth[$r['month']] ?? 0.0;
                unset($expensesByMonth[$r['month']]);
                $r['month_expenses'] = $monthExpenses;
                $r['month_net_income'] = (float) $r['total_paid'] - $monthExpenses;
                $totalInvoiced += (float) $r['total_invoiced'];
                $totalPaid += (float) $r['total_paid'];
                $totalExpenses += $monthExpenses;
                $rows[] = $r;
            }
            // Months with expenses but no invoices that month still belong in the
            // tax-year total even though they never generated a $res row above.
            foreach ($expensesByMonth as $leftoverMonth => $leftoverAmount) {
                $totalExpenses += $leftoverAmount;
            }
            echo json_encode(['success' => true, 'rows' => $rows, 'label' => $taxYearLabel, 'start' => $startStr, 'total_invoiced' => $totalInvoiced, 'total_paid' => $totalPaid, 'outstanding' => $totalInvoiced - $totalPaid, 'total_expenses' => $totalExpenses, 'net_income' => $totalPaid - $totalExpenses]);
            exit;
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

if (isset($_GET['api'])) {
    if ($_GET['api'] === 'chart') {
        header('Content-Type: application/json');
        // Respect the same "Hide Test Clients Globally" setting every other view
        // honours (this used to hardcode is_test=0, ignoring the toggle).
        $hideTestRes = $mysqli->query("SELECT setting_value FROM invoxa_settings WHERE setting_key = 'hide_test'");
        $hideTestChart = ($hideTestRes && $hideTestRes->num_rows > 0) ? ($hideTestRes->fetch_assoc()['setting_value'] === '1') : true;
        $showTestOnlyResChart = $mysqli->query("SELECT setting_value FROM invoxa_settings WHERE setting_key = 'show_test_only'");
        $showTestOnlyChart = ($showTestOnlyResChart && $showTestOnlyResChart->num_rows > 0) ? ($showTestOnlyResChart->fetch_assoc()['setting_value'] === '1') : false;
        $chartClientFilter = invoxaTestViewClientFilter($hideTestChart, $showTestOnlyChart, 'WHERE');
        $chartInvoiceFilter = invoxaTestViewFilter($hideTestChart, $showTestOnlyChart);
        $clientsRes = $mysqli->query("SELECT client_key, client_name FROM invoxa_clients $chartClientFilter ORDER BY client_name ASC");
        $chartClients = [];
        while ($cr = $clientsRes->fetch_assoc())
            $chartClients[$cr['client_key']] = $cr['client_name'];
        $q = "SELECT DATE_FORMAT(invoice_date, '%Y-%m') as month, client_key, SUM(amount) as total FROM invoxa_invoices WHERE status NOT IN ('failed', 'void') $chartInvoiceFilter GROUP BY month, client_key ORDER BY month ASC";
        $byMonth = [];
        $res = $mysqli->query($q);
        while ($r = $res->fetch_assoc()) {
            $byMonth[$r['month']][$r['client_key']] = (float) $r['total'];
        }
        $months = array_keys($byMonth);
        // Build cumulative per client and total
        $cumulative = [];
        $result = [];
        foreach ($months as $m) {
            $row = ['month' => $m, 'total' => 0];
            foreach ($chartClients as $ck => $cn) {
                $amt = $byMonth[$m][$ck] ?? 0;
                $cumulative[$ck] = ($cumulative[$ck] ?? 0) + $amt;
                $row[$ck] = round($cumulative[$ck], 2);
                $row['total'] += $cumulative[$ck];
            }
            $row['total'] = round($row['total'], 2);
            // Also store monthly (non-cumulative) per client
            foreach ($chartClients as $ck => $cn) {
                $row[$ck . '_monthly'] = round($byMonth[$m][$ck] ?? 0, 2);
            }
            $row['total_monthly'] = round(array_sum($byMonth[$m] ?? []), 2);
            $result[] = $row;
        }
        echo json_encode(['clients' => $chartClients, 'data' => $result]);
        exit;
    }
    if ($_GET['api'] === 'stats') {
        header('Content-Type: application/json');
        // For external consumers hitting this with ?cron_key=... instead of a
        // browser session — same hide-test-clients convention as the chart endpoint.
        $hideTestRes = $mysqli->query("SELECT setting_value FROM invoxa_settings WHERE setting_key = 'hide_test'");
        $hideTestStats = ($hideTestRes && $hideTestRes->num_rows > 0) ? ($hideTestRes->fetch_assoc()['setting_value'] === '1') : true;
        $showTestOnlyResStats = $mysqli->query("SELECT setting_value FROM invoxa_settings WHERE setting_key = 'show_test_only'");
        $showTestOnlyStats = ($showTestOnlyResStats && $showTestOnlyResStats->num_rows > 0) ? ($showTestOnlyResStats->fetch_assoc()['setting_value'] === '1') : false;
        $statsInvoiceFilter = invoxaTestViewFilter($hideTestStats, $showTestOnlyStats);

        // Same definition of "unpaid" the dashboard stat card uses.
        $unpaidRow = $mysqli->query("SELECT COUNT(*) as c, SUM(amount - COALESCE(paid_amount, 0)) as amt FROM invoxa_invoices WHERE status IN ('sent', 'pending') $statsInvoiceFilter")->fetch_assoc();
        // Same definition of "overdue" the dashboard stat card uses.
        $overdueRow = $mysqli->query("SELECT COUNT(*) as c FROM invoxa_invoices WHERE status NOT IN ('paid', 'void') AND due_date < CURDATE() AND is_quote = 0 $statsInvoiceFilter")->fetch_assoc();
        // Same definition of "failed" (email send failures) the dashboard's failed-invoices list uses.
        $failedRow = $mysqli->query("SELECT COUNT(*) as c FROM invoxa_invoices WHERE status = 'failed' $statsInvoiceFilter")->fetch_assoc();

        echo json_encode([
            'failed' => [
                'count' => (int) ($failedRow['c'] ?? 0),
            ],
            'unpaid' => [
                'count' => (int) ($unpaidRow['c'] ?? 0),
                'amount' => round((float) ($unpaidRow['amt'] ?? 0), 2),
            ],
            'overdue' => [
                'count' => (int) ($overdueRow['c'] ?? 0),
            ],
        ]);
        exit;
    }
}

if (isset($_GET['export']) && $_GET['export'] === 'invoice_pdf') {
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

if (isset($_GET['export'])) {
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
        fputcsv($out, ['Invoice Number', 'Client Name', 'Email', 'Invoice Date', 'Due Date', 'Amount', 'Status', 'Paid Amount', 'Paid Date'], ',', '"', "\\");
        $res = $mysqli->query("SELECT invoice_number, client_name, recipient_email, invoice_date, due_date, amount, status, paid_amount, paid_at FROM invoxa_invoices WHERE 1 $testFilter ORDER BY invoice_date DESC");
        while ($r = $res->fetch_assoc())
            fputcsv($out, $r, ',', '"', "\\");
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
        fputcsv($out, ['Invoice Number', 'Client Name', 'Invoice Date', 'Due Date', 'Amount', 'Status', 'Paid Amount', 'Paid Date'], ',', '"', "\\");
        $res = $mysqli->query("SELECT invoice_number, client_name, invoice_date, due_date, amount, status, paid_amount, paid_at FROM invoxa_invoices WHERE is_quote = 0 AND status != 'void' AND invoice_date >= '$startStr' $testFilter ORDER BY invoice_date ASC");
        while ($r = $res->fetch_assoc())
            fputcsv($out, $r, ',', '"', "\\");
        fclose($out);
        exit;
    }
    if ($_GET['export'] === 'tax_year_monthly') {
        // Monthly summary for the current tax year (April 1st to now)
        $now = new DateTime();
        $taxYearStart = getTaxYearStart((int) ($settings['tax_year_start_month'] ?? 1), $now);
        $startStr = $taxYearStart->format('Y-m-d');
        $taxYearLabel = $taxYearStart->format('Y') . '-' . $now->format('Y');
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="invoices_monthly_summary_' . $taxYearLabel . '_' . date('Ymd') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Month', 'Total Invoiced', 'Total Paid', 'Outstanding', 'Payment Status', 'Expenses', 'Net Income'], ',', '"', "\\");
        // Get monthly aggregates
        $res = $mysqli->query("
            SELECT
                DATE_FORMAT(invoice_date, '%Y-%m') as month,
                SUM(amount) as total_invoiced,
                SUM(COALESCE(paid_amount, 0)) as total_paid,
                SUM(amount) - SUM(COALESCE(paid_amount, 0)) as outstanding,
                SUM(CASE WHEN status NOT IN ('paid') THEN 1 ELSE 0 END) as unpaid_count
            FROM invoxa_invoices
            WHERE is_quote = 0
              AND status != 'void'
              AND invoice_date >= '$startStr'
              $testFilter
            GROUP BY DATE_FORMAT(invoice_date, '%Y-%m')
            ORDER BY month ASC
        ");
        $expensesByMonthCsv = [];
        $expResCsv = $mysqli->query("SELECT DATE_FORMAT(expense_date, '%Y-%m') as month, SUM(amount) as total FROM invoxa_expenses WHERE expense_date >= '$startStr' GROUP BY DATE_FORMAT(expense_date, '%Y-%m')");
        while ($er = $expResCsv->fetch_assoc())
            $expensesByMonthCsv[$er['month']] = (float) $er['total'];
        while ($r = $res->fetch_assoc()) {
            // Format month as readable e.g. April 2026
            $dt = DateTime::createFromFormat('Y-m', $r['month']);
            $monthLabel = $dt ? $dt->format('F Y') : $r['month'];
            $outstanding = round((float) $r['outstanding'], 2);
            // Determine payment status
            if ($r['unpaid_count'] > 0 && $outstanding > 0) {
                $payStatus = 'Partial Paid';
            } elseif ($outstanding <= 0) {
                $payStatus = 'Paid';
            } else {
                $payStatus = 'Unpaid';
            }
            $monthExpensesCsv = $expensesByMonthCsv[$r['month']] ?? 0.0;
            fputcsv($out, [
                $monthLabel,
                number_format((float) $r['total_invoiced'], 2),
                number_format((float) $r['total_paid'], 2),
                number_format($outstanding, 2),
                $payStatus,
                number_format($monthExpensesCsv, 2),
                number_format((float) $r['total_paid'] - $monthExpensesCsv, 2)
            ], ',', '"', "\\");
        }
        fclose($out);
        exit;
    }
    if ($_GET['export'] === 'clients') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="clients_export_' . date('Ymd') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Client Name', 'Email', 'Phone', 'Address', 'Rate', 'Billing Frequency', 'Invoices', 'Total Billed', 'Total Paid', 'Outstanding'], ',', '"', "\\");
        $res = $mysqli->query("SELECT c.client_name, c.email, c.phone, c.address, c.monthly_rate, c.billing_frequency, COUNT(i.id) as inv_count, SUM(i.amount) as total_billed, SUM(i.paid_amount) as total_paid FROM invoxa_clients c LEFT JOIN invoxa_invoices i ON c.client_key = i.client_key AND i.status NOT IN ('failed', 'void') WHERE 1 " . invoxaTestViewClientFilter($hideTest, $showTestOnly, 'AND', 'c.is_test') . " GROUP BY c.id ORDER BY c.client_name ASC");
        while ($r = $res->fetch_assoc()) {
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
        fputcsv($out, ['Quote Number', 'Client Name', 'Email', 'Quote Date', 'Amount', 'Expires'], ',', '"', "\\");
        $res = $mysqli->query("SELECT invoice_number, client_name, recipient_email, invoice_date, amount, quote_expires_at FROM invoxa_invoices WHERE is_quote = 1 $testFilter ORDER BY invoice_date DESC");
        while ($r = $res->fetch_assoc())
            fputcsv($out, $r, ',', '"', "\\");
        fclose($out);
        exit;
    }
    if ($_GET['export'] === 'accounting_journal') {
        $taxYearStart = getTaxYearStart((int) ($settings['tax_year_start_month'] ?? 1));
        $journal = buildAccountingJournal($mysqli, $taxYearStart->format('Y-m-d'), $testFilter);
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="accounting_journal_' . date('Ymd') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Date', 'Account', 'Debit', 'Credit', 'Memo', 'Reference'], ',', '"', "\\");
        foreach ($journal as $row) {
            fputcsv($out, [
                $row['date'],
                $row['account'],
                $row['debit'] > 0 ? number_format($row['debit'], 2) : '',
                $row['credit'] > 0 ? number_format($row['credit'], 2) : '',
                $row['memo'],
                $row['ref'],
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
        $journal = buildAccountingJournal($mysqli, $taxYearStart->format('Y-m-d'), $testFilter);
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

$total_invoiced = $mysqli->query("SELECT SUM(amount) as s FROM invoxa_invoices WHERE status NOT IN ('failed', 'void') $testFilter")->fetch_assoc()['s'] ?? 0;
$total_paid = $mysqli->query("SELECT SUM(paid_amount) as s FROM invoxa_invoices WHERE paid_amount > 0 $testFilter")->fetch_assoc()['s'] ?? 0;
$total_monthly = $mysqli->query("SELECT SUM(amount) as s FROM invoxa_invoices WHERE status NOT IN ('failed', 'void') AND MONTH(invoice_date) = MONTH(CURRENT_DATE()) AND YEAR(invoice_date) = YEAR(CURRENT_DATE()) $testFilter")->fetch_assoc()['s'] ?? 0;
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

$res_rev = $mysqli->query("SELECT SUM(amount - COALESCE(paid_amount, 0)) as outstanding FROM invoxa_invoices WHERE status NOT IN ('paid', 'void') AND is_quote = 0 $testFilter");
$stats_outstanding_revenue = $res_rev->fetch_assoc()['outstanding'] ?? 0;

$res_overdue = $mysqli->query("SELECT COUNT(*) as cnt FROM invoxa_invoices WHERE status NOT IN ('paid', 'void') AND due_date < CURDATE() AND is_quote = 0 $testFilter");
$stats_overdue_count = $res_overdue->fetch_assoc()['cnt'] ?? 0;

$res_paid = $mysqli->query("SELECT SUM(paid_amount) as paid, AVG(paid_amount) as avg_val FROM invoxa_invoices WHERE paid_amount > 0 AND is_quote = 0 $testFilter");
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
    WHERE is_quote = 0 AND status != 'void' AND invoice_date >= '$startStr' $testFilter
");
$row_ty = $res_ty->fetch_assoc();
$stats_ty_invoiced = $row_ty['total_invoiced'] ?? 0;
$stats_ty_paid = $row_ty['total_paid'] ?? 0;
$stats_ty_outstanding = $row_ty['outstanding'] ?? 0;


$res_mrr = $mysqli->query("SELECT SUM(monthly_rate) as mrr FROM invoxa_clients WHERE is_active = 1 " . invoxaTestViewClientFilter($hideTest, $showTestOnly));
$stats_mrr = $res_mrr->fetch_assoc()['mrr'] ?? 0;

$stats_12m_projected = ($stats_mrr * 12) + $stats_outstanding_revenue;

// Top clients
$top_clients = [];
$res_top = $mysqli->query("
    SELECT c.client_name, SUM(i.paid_amount) as total_revenue
    FROM invoxa_invoices i
    JOIN invoxa_clients c ON i.client_key = c.client_key
    WHERE i.paid_amount > 0 AND i.is_quote = 0 " . invoxaTestViewClientFilter($hideTest, $showTestOnly, 'AND', 'c.is_test') . "
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
$res_void = $mysqli->query("SELECT COUNT(*) as c, SUM(amount) as total FROM invoxa_invoices WHERE status = 'void' AND is_quote = 0 $testFilter");
$row_void = $res_void->fetch_assoc();
$stats_void_count = (int) ($row_void['c'] ?? 0);
$stats_void_amount = $row_void['total'] ?? 0;

// Quote Pipeline — quotes still open (not yet converted, not voided). Once a
// quote converts, is_quote flips to 0 and it drops out of this count.
$res_pipeline = $mysqli->query("SELECT COUNT(*) as c, SUM(amount) as total FROM invoxa_invoices WHERE is_quote = 1 AND status != 'void' $testFilter");
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
    WHERE is_quote = 0 AND status NOT IN ('paid', 'void') $testFilter
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
    WHERE is_quote = 0 AND status != 'void' AND invoice_date >= '$startStr' $testFilter
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
        echo renderDashboardStats($settings, $failedInvoices, $overdueInvoices, (float) $total_invoiced, (float) $total_monthly, (float) $total_paid, (int) $client_count);
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
            margin: 1.25rem 0 0.5rem;
        }

        .nav-item {
            position: relative;
            margin: 0.1rem 0.75rem;
            padding: 0.65rem 0.85rem;
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
            transition: transform 0.15s ease, box-shadow 0.15s ease;
        }

        .stat-card:hover {
            transform: translateY(-2px);
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

        .sidebar-backdrop {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(5, 8, 16, 0.6);
            z-index: 1090;
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

            .sidebar {
                position: fixed;
                top: 0;
                right: -300px;
                height: 100vh;
                z-index: 1100;
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
        }
    </style>
</head>

<body>

    <button type="button" class="mobile-menu-btn" onclick="toggleSidebar()" aria-label="Toggle menu"><i
            class="fa-solid fa-bars"></i></button>
    <div id="sidebarBackdrop" class="sidebar-backdrop" onclick="toggleSidebar()"></div>

    <nav class="mobile-bottom-nav">
        <button type="button" class="mobile-bottom-nav-item" data-target="dashboard" onclick="nav('dashboard', true)"><i
                class="fa-solid fa-chart-pie"></i><span>Dashboard</span></button>
        <button type="button" class="mobile-bottom-nav-item" data-target="invoices" onclick="nav('invoices', true)"><i
                class="fa-solid fa-file-lines"></i><span>Invoices</span></button>
        <button type="button" class="mobile-bottom-nav-item" onclick="openExpenseModal()"><i
                class="fa-solid fa-circle-plus"></i><span>Add Expense</span></button>
        <button type="button" class="mobile-bottom-nav-item" data-target="clients" onclick="nav('clients', true)"><i
                class="fa-solid fa-users"></i><span>Clients</span></button>
    </nav>

    <div class="sidebar">
        <div class="sidebar-header">
            <h1 id="sidebarBrandName"><img src="assets/img/invoxa-mark.svg" width="36" height="36" alt="">
                <img src="assets/img/invoxa-wordmark.svg" height="30" alt="Invoxa" style="width:auto;"></h1>
        </div>
        <div class="global-search-wrap">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" id="globalSearchInput" placeholder="Search"
                autocomplete="off" oninput="handleGlobalSearch()" onkeydown="handleGlobalSearchKeydown(event)"
                onfocus="if (document.getElementById('globalSearchResults').innerHTML.trim() !== '') document.getElementById('globalSearchResults').classList.add('active')">
            <kbd>Ctrl K</kbd>
            <div id="globalSearchResults" class="global-search-results"></div>
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
        <div class="nav-item tool-item" data-target="backup" onclick="nav('backup', true)"><i
                class="fa-solid fa-database"></i> Data Management
            <button type="button" class="nav-subnav-toggle" onclick="event.stopPropagation(); toggleNavSubnav('backup')"
                aria-label="Expand Data Management menu"><i class="fa-solid fa-chevron-down"></i></button>
        </div>
        <div class="nav-subnav-slot" data-for="backup"></div>
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
        <div class="user-panel">
            <form method="POST"><input type="hidden" name="auth_action" value="logout"><button type="submit"
                    class="logout-btn"><i class="fa-solid fa-right-from-bracket"></i> Logout</button></form>
            <div style="display:flex; align-items:center; justify-content:center; gap:0.6rem; margin-top:0.5rem; font-size:0.75rem; color:var(--text-secondary);">
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
                    <?= renderDashboardStats($settings, $failedInvoices, $overdueInvoices, (float) $total_invoiced, (float) $total_monthly, (float) $total_paid, (int) $client_count) ?>
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
                            title="Preview and export all invoices for the current tax year, ordered by date">Tax
                            Year Invoices</option>
                        <option value="tax_year_monthly"
                            title="Preview and export a monthly summary for the current tax year, showing paid/partial paid status">
                            Monthly Summary</option>
                        <option value="accounting_journal"
                            title="Double-entry General Journal (invoices, payments, expenses) for the current tax year, as a plain CSV any bookkeeping tool can import">
                            Accounting Journal (CSV)</option>
                        <option value="accounting_iif"
                            title="Same General Journal as an .iif file for QuickBooks Desktop's File > Utilities > Import > IIF Files">
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
                                    data-terms="<?= (int) ($c['payment_terms_days'] ?? 21) ?>"><?= htmlspecialchars($c['client_name']) ?>
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
                                    <th style="padding:0 0.5rem 0.4rem 0; width:110px; text-align:right;">Amount (<?= htmlspecialchars($settings['currency'] ?? 'USD') ?>)
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

                <!-- Group 1: Export -->
                <div
                    style="display: flex; flex-direction: row; align-items: center; gap: 0.75rem; background: var(--surface); border: 1px solid var(--border); border-radius: 8px; padding: 0.5rem 0.9rem;">
                    <span
                        style="font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.06em; color: var(--text-secondary); font-weight: 600; white-space: nowrap; padding-right: 0.75rem; border-right: 1px solid var(--border);"><i
                            class="fa-solid fa-file-export" style="margin-right:0.3rem;"></i>Export</span>
                    <button class="btn" style="background: var(--surface-hover); white-space: nowrap;"
                        onclick="window.location.href='?export=quotes'"><i class="fa-solid fa-file-csv"></i> CSV</button>
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
                    'Reference' => ['changelog' => 'Changelog', 'license' => 'License (AGPL-3.0)', 'source' => 'Source Code'],
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
                                    unlock for six specific features (Stripe/PayPal payment collection, recurring
                                    billing automation, the Client Portal, the external API, Reporting &amp;
                                    Statistics, and removing the "Powered by Invoxa" credit) — see
                                    <strong>Security</strong> under Features for how that works.</p>
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
                                <p>A self-hosted invoicing and billing tool for one business, run from a single admin
                                    account. Each topic under <strong>Features</strong> in the sidebar covers one part
                                    in more depth — this page is just the map.</p>
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
                                    automatically as each row is added. <strong>Bulk Mark Paid</strong>, for clearing
                                    several invoices at once, lives under Data Management &gt; Bulk Actions rather
                                    than the Invoices toolbar.</p>
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
                                    <strong>Email Address</strong>; <strong>Rate</strong> (per billing period, in
                                    your instance currency) and <strong>Billing Frequency</strong>
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
                                <h2>Invoxa is open source — licensing only unlocks six extras</h2>
                                <p>Invoxa is free and open source (AGPL-3.0): client and invoice management, quotes,
                                    manual payments, backups, and 2FA all work fully with no license key at all — an
                                    unlicensed install is never locked out of its own account or its own data. A
                                    license is a paid, optional unlock for six specific capabilities: Stripe/PayPal
                                    payment collection, recurring billing automation, the Client Portal, the
                                    external API, Reporting &amp; Statistics, and removing the "Powered by Invoxa"
                                    credit line from invoices and emails. Everything else in this Docs section works
                                    exactly the same whether or not you've added a key.</p>
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
                                <h2>Bulk Actions</h2>
                                <p>Bulk Mark Paid lives here rather than on the Invoices toolbar, alongside the
                                    other administrative, multi-record operations — deliberately separated from the
                                    single-invoice actions so a bulk action is never one accidental click away.</p>
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
                                    sections (Core Logic, Clients &amp; Invoices, Payments &amp; Refunds, Recurring
                                    Billing / Cron, Email Content, Security), each with its own checkbox to select
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
                                    every stored backup, and the admin account itself, landing back on the signup
                                    screen exactly like a fresh install. It requires typing <code>RESET</code>
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

        <!-- SETTINGS -->
        <div id="sec-settings" class="section">
            <h2 class="page-title">Settings</h2>
            <div class="section-scroll">
            <div class="subnav-layout">

                <nav class="subnav">
                    <button type="button" class="subnav-item active" data-settings-target="general"
                        onclick="navSettings('general')"><i class="fa-solid fa-sliders"></i> General</button>
                    <button type="button" class="subnav-item" data-settings-target="account"
                        onclick="navSettings('account')"><i class="fa-solid fa-lock"></i> Account</button>
                    <button type="button" class="subnav-item" data-settings-target="branding"
                        onclick="navSettings('branding')"><i class="fa-solid fa-paint-roller"></i> Branding</button>
                    <button type="button" class="subnav-item" data-settings-target="email"
                        onclick="navSettings('email')"><i class="fa-solid fa-envelope"></i> Email</button>
                    <button type="button" class="subnav-item" data-settings-target="billing"
                        onclick="navSettings('billing')"><i class="fa-solid fa-clock"></i> Billing
                        <span style="margin-left:auto; display:inline-flex; align-items:center; gap:0.4rem;">
                            <?php if (!$licenseValid): ?><i class="fa-solid fa-lock" title="Requires a license"
                                    style="color:var(--text-secondary); font-size:0.8rem;"></i><?php endif; ?>
                            <span class="subnav-dot <?= ($cronEnabled && $licenseValid) ? 'on' : 'off' ?>" style="margin-left:0;"
                                title="<?= !$licenseValid ? 'Recurring billing requires a license — inactive regardless of this setting' : ($cronEnabled ? 'Recurring billing is active' : 'Recurring billing is paused') ?>"></span>
                        </span></button>
                    <?php $__paymentsConfigured = ($settings['stripe_enabled'] ?? '0') === '1' || ($settings['paypal_enabled'] ?? '0') === '1'; $__paymentsOn = $__paymentsConfigured && $licenseValid; ?>
                    <button type="button" class="subnav-item" data-settings-target="payments"
                        onclick="navSettings('payments')"><i class="fa-solid fa-credit-card"></i> Payments
                        <span style="margin-left:auto; display:inline-flex; align-items:center; gap:0.4rem;">
                            <?php if (!$licenseValid): ?><i class="fa-solid fa-lock" title="Requires a license"
                                    style="color:var(--text-secondary); font-size:0.8rem;"></i><?php endif; ?>
                            <span class="subnav-dot <?= $__paymentsOn ? 'on' : 'off' ?>" style="margin-left:0;"
                                title="<?= !$licenseValid ? 'Online payment collection requires a license — inactive regardless of this setting' : ($__paymentsOn ? 'Online payment collection active' : 'Online payment collection off') ?>"></span>
                        </span></button>
                    <?php $__apiTokenCount = $licenseValid ? (int) ($mysqli->query("SELECT COUNT(*) as c FROM invoxa_api_tokens WHERE revoked_at IS NULL AND (expires_at IS NULL OR expires_at > NOW())")->fetch_assoc()['c'] ?? 0) : 0; ?>
                    <button type="button" class="subnav-item" data-settings-target="api"
                        onclick="navSettings('api')"><i class="fa-solid fa-plug-circle-bolt"></i> API Access
                        <span style="margin-left:auto; display:inline-flex; align-items:center; gap:0.4rem;">
                            <?php if (!$licenseValid): ?><i class="fa-solid fa-lock" title="Requires a license"
                                    style="color:var(--text-secondary); font-size:0.8rem;"></i><?php endif; ?>
                            <span class="subnav-dot <?= $__apiTokenCount > 0 ? 'on' : 'off' ?>" style="margin-left:0;"
                                title="<?= !$licenseValid ? 'The API requires a license — inactive regardless of any existing tokens' : $__apiTokenCount . ' active token' . ($__apiTokenCount === 1 ? '' : 's') ?>"></span>
                        </span></button>
                    <?php $__notificationsOn = ($settings['notification_channel'] ?? 'none') !== 'none'; ?>
                    <button type="button" class="subnav-item" data-settings-target="notifications"
                        onclick="navSettings('notifications')"><i class="fa-solid fa-bell"></i> Notifications
                        <span class="subnav-dot <?= $__notificationsOn ? 'on' : 'off' ?>"
                            title="<?= $__notificationsOn ? 'Notifications active (' . htmlspecialchars(ucfirst($settings['notification_channel'])) . ')' : 'Notifications off' ?>"></span></button>
                    <button type="button" class="subnav-item" data-settings-target="license"
                        onclick="navSettings('license')"><i class="fa-solid fa-key"></i> License
                        <span class="subnav-dot <?= $licenseValid ? 'on' : 'off' ?>"
                            title="<?= $licenseValid ? 'Licensed' : 'Not licensed' ?>"></span></button>
                </nav>

                <div class="subnav-content">

                    <!-- General -->
                    <div class="subnav-pane active" id="settings-pane-general">
                        <div class="card">
                            <div class="card-header">
                                <h3 style="margin:0; font-size: 1.1rem;"><i class="fa-solid fa-sliders"
                                        style="color:var(--accent); margin-right:0.5rem;"></i>Preferences</h3>
                            </div>
                            <div class="card-body">
                                <label
                                    style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer; color: var(--text-primary); font-weight: 500;">
                                    <input type="checkbox" id="hideTestToggle" <?= $hideTest ? 'checked' : '' ?>
                                        onchange="toggleTestClients(this.checked)" style="width:18px;height:18px;"> Hide
                                    Test Clients Globally
                                </label>
                                <p
                                    style="color: var(--text-secondary); margin-top: 0.5rem; font-size: 0.85rem; margin-bottom: 1.5rem;">
                                    When checked, all dummy/test clients and their invoices will be hidden.</p>

                                <label
                                    style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer; color: var(--text-primary); font-weight: 500;">
                                    <input type="checkbox" id="showTestOnlyToggle" <?= $showTestOnly ? 'checked' : '' ?>
                                        onchange="toggleShowTestOnly(this.checked)" style="width:18px;height:18px;"> Show
                                    Only Test/Dummy Data
                                </label>
                                <p
                                    style="color: var(--text-secondary); margin-top: 0.5rem; font-size: 0.85rem; margin-bottom: 1.5rem;">
                                    Flips every list/chart to show <strong>only</strong> dummy/test data instead of your
                                    real data — for safely previewing Demo Data (Data Management &gt; Demo Data) without
                                    it mixing in with your own clients and invoices. Overrides "Hide Test Clients
                                    Globally" above while it's on; turn it back off to return to your normal view.</p>

                                <label
                                    style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer; color: var(--text-primary); font-weight: 500;">
                                    <input type="checkbox" id="lightModeToggle" onchange="toggleTheme(this.checked)"
                                        style="width:18px;height:18px;"> Enable Light Mode
                                </label>
                                <script>
                                    document.getElementById('lightModeToggle').checked = document.documentElement.getAttribute('data-theme') === 'light';
                                </script>
                                <p
                                    style="color: var(--text-secondary); margin-top: 0.5rem; font-size: 0.85rem; margin-bottom: 1.5rem;">
                                    Switch between light and dark themes.</p>

                                <label
                                    style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer; color: var(--text-primary); font-weight: 500;">
                                    <input type="checkbox" id="welcomeFlashToggle" checked
                                        onchange="localStorage.setItem('invoxa_show_welcome', this.checked ? '1' : '0')"
                                        style="width:18px;height:18px;"> Show Welcome Message on Login
                                </label>
                                <script>
                                    document.getElementById('welcomeFlashToggle').checked = localStorage.getItem('invoxa_show_welcome') !== '0';
                                </script>
                                <p
                                    style="color: var(--text-secondary); margin-top: 0.5rem; font-size: 0.85rem; margin-bottom: 1.5rem;">
                                    The brief "Welcome back" card shown right after signing in.</p>

                                <div class="form-group" style="max-width:320px;">
                                    <label class="form-label" style="font-size:0.8rem; color:var(--text-secondary);">Default
                                        Landing Tab</label>
                                    <select id="defaultTabSelect" class="form-control"
                                        onchange="localStorage.setItem('invoxa_default_tab', this.value)">
                                        <option value="dashboard">Dashboard</option>
                                        <option value="invoices">Invoices</option>
                                        <option value="clients">Clients</option>
                                        <option value="stats">Statistics</option>
                                        <option value="billing">Ad Hoc Invoice</option>
                                    </select>
                                    <script>
                                        document.getElementById('defaultTabSelect').value = localStorage.getItem('invoxa_default_tab') || 'dashboard';
                                    </script>
                                    <p style="color:var(--text-secondary); font-size:0.8rem; margin-top:0.35rem; margin-bottom:1.5rem;">
                                        Which tab opens right after you log in.</p>
                                </div>

                                <div class="form-group" style="max-width:320px;">
                                    <label class="form-label" style="font-size:0.8rem; color:var(--text-secondary);">Default
                                        Table Page Size</label>
                                    <select id="defaultPageSizeSelect" class="form-control"
                                        onchange="localStorage.setItem('invoxa_table_page_size', this.value)">
                                        <option value="12">12 rows</option>
                                        <option value="30">30 rows</option>
                                        <option value="50">50 rows</option>
                                        <option value="99999">All</option>
                                    </select>
                                    <script>
                                        document.getElementById('defaultPageSizeSelect').value = localStorage.getItem('invoxa_table_page_size') || '12';
                                    </script>
                                    <p style="color:var(--text-secondary); font-size:0.8rem; margin-top:0.35rem;">
                                        Applies to Invoices, Clients, and Quotes — takes effect on your next visit to
                                        each tab.</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Account -->
                    <div class="subnav-pane" id="settings-pane-account">
                        <div class="card">
                            <div class="card-header">
                                <h3 style="margin:0; font-size: 1.1rem;"><i class="fa-solid fa-lock"
                                        style="color:var(--accent); margin-right:0.5rem;"></i>Authentication</h3>
                            </div>
                            <div class="card-body">
                                <div class="form-group">
                                    <label class="form-label"
                                        style="font-size:0.8rem; color:var(--text-secondary);">Username</label>
                                    <?php $__u = $mysqli->query("SELECT username, email, totp_secret, email_verified_at FROM invoxa_users LIMIT 1")->fetch_assoc();
                                    $__totpEnabled = !empty($__u['totp_secret']); ?>
                                    <input type="text" id="newUsername" class="form-control"
                                        value="<?= htmlspecialchars($__u['username'] ?? '') ?>">
                                </div>
                                <div class="form-group" style="margin-top:0.5rem;">
                                    <label class="form-label" style="font-size:0.8rem; color:var(--text-secondary);">Email</label>
                                    <input type="email" id="newEmail" class="form-control"
                                        value="<?= htmlspecialchars($__u['email'] ?? '') ?>">
                                    <p style="color:var(--text-secondary); font-size:0.8rem; margin-top:0.35rem;">Must match
                                        the email your license was issued to — see the License tab.</p>
                                    <?php if (!empty($__u['email'])): ?>
                                        <?php if (!empty($__u['email_verified_at'])): ?>
                                            <p style="color:#22c55e; font-size:0.8rem; margin-top:0.35rem;"><i
                                                    class="fa-solid fa-circle-check"></i> Verified</p>
                                        <?php else: ?>
                                            <div style="display:flex; align-items:center; gap:0.75rem; margin-top:0.5rem;">
                                                <p style="color:var(--warning); font-size:0.8rem; margin:0;"><i
                                                        class="fa-solid fa-triangle-exclamation"></i> Not verified —
                                                    account recovery can't reach this address yet.</p>
                                                <button class="btn" id="resendVerifyBtnSettings"
                                                    style="width:auto; margin:0; padding:0.4rem 0.8rem; font-size:0.8rem;"
                                                    onclick="resendVerificationEmail('resendVerifyBtnSettings')">Verify
                                                    Now</button>
                                            </div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                                <div class="form-group" style="margin-top:0.5rem;">
                                    <label class="form-label" style="font-size:0.8rem; color:var(--text-secondary);">Current
                                        password <span style="color:#e74c3c;">*</span></label>
                                    <input type="password" id="currentPassword" class="form-control"
                                        placeholder="Required to save any changes">
                                </div>
                                <div class="form-group" style="margin-top:0.5rem;">
                                    <label class="form-label" style="font-size:0.8rem; color:var(--text-secondary);">New
                                        password <span style="color:var(--text-secondary); font-weight:400;">(leave blank to
                                            keep current)</span></label>
                                    <input type="password" id="newPassword" class="form-control" minlength="<?= PASSWORD_MIN_LENGTH ?>"
                                        placeholder="Leave blank to keep current password">
                                </div>
                                <div class="form-group" style="margin-top:0.5rem;">
                                    <label class="form-label" style="font-size:0.8rem; color:var(--text-secondary);">Confirm
                                        new password</label>
                                    <input type="password" id="confirmPassword" class="form-control" minlength="<?= PASSWORD_MIN_LENGTH ?>"
                                        placeholder="Confirm new password">
                                </div>
                                <button class="btn primary" id="saveProfileBtn" onclick="saveProfile()"
                                    style="margin-top:0.5rem;"><i class="fa-solid fa-save"></i> Save Profile</button>
                            </div>
                        </div>
                        <div class="card" style="margin-top:1rem;">
                            <div class="card-header">
                                <h3 style="margin:0; font-size: 1.1rem;"><i class="fa-solid fa-shield-halved"
                                        style="color:var(--accent); margin-right:0.5rem;"></i>Two-Factor Authentication</h3>
                            </div>
                            <div class="card-body">
                                <div id="totpDisabledView" style="<?= $__totpEnabled ? 'display:none;' : '' ?>">
                                    <p style="color:var(--text-secondary); font-size:0.9rem; margin-bottom:1rem;">Adds
                                        a 6-digit code from an authenticator app (Google Authenticator, Authy,
                                        1Password, etc.) on top of your password. Recommended — this account has
                                        full access to client bank details and invoice data.</p>
                                    <button class="btn primary" id="totpStartBtn" onclick="startTotpSetup()"><i
                                            class="fa-solid fa-shield-halved"></i> Enable Two-Factor
                                        Authentication</button>
                                    <div id="totpSetupWrap" style="display:none; margin-top:1rem;">
                                        <div class="form-group">
                                            <label class="form-label">Secret Key</label>
                                            <input type="text" id="totpSecretDisplay" class="form-control" readonly>
                                            <p style="color:var(--text-secondary); font-size:0.8rem; margin-top:0.35rem;">
                                                Add this as a new account in your authenticator app — look for "Enter
                                                setup key manually" / "Can't scan a QR code?". Account name: <code
                                                    id="totpAccountLabel"></code>.</p>
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label">Confirmation Code</label>
                                            <input type="text" id="totpConfirmCode" class="form-control"
                                                placeholder="6-digit code" maxlength="6" inputmode="numeric">
                                        </div>
                                        <button class="btn success" id="totpConfirmBtn" onclick="confirmTotpSetup()"><i
                                                class="fa-solid fa-check"></i> Confirm & Enable</button>
                                        <button class="btn" onclick="cancelTotpSetup()">Cancel</button>
                                    </div>
                                </div>
                                <div id="totpBackupCodesWrap" style="display:none; margin-top:1rem; padding:1rem; border:1px solid var(--warning); border-radius:8px;">
                                    <p style="color:var(--warning); font-weight:600; margin-top:0;"><i
                                            class="fa-solid fa-triangle-exclamation"></i> Save these backup codes now
                                        — they won't be shown again.</p>
                                    <p style="color:var(--text-secondary); font-size:0.85rem;">Each one lets you sign
                                        in once if you lose access to your authenticator app, instead of a code. Store
                                        them somewhere safe (a password manager, printed and locked away) — anyone
                                        with one can use it just like a code.</p>
                                    <pre id="totpBackupCodesList" style="background:var(--bg); border-radius:6px; padding:0.75rem 1rem; font-size:0.9rem; line-height:1.6; user-select:all;"></pre>
                                    <button class="btn" type="button" onclick="window.location.reload();"><i
                                            class="fa-solid fa-check"></i> I've saved these</button>
                                </div>
                                <div id="totpEnabledView" style="<?= $__totpEnabled ? '' : 'display:none;' ?>">
                                    <p style="color:var(--success); font-size:0.9rem; margin-bottom:1rem;"><i
                                            class="fa-solid fa-circle-check"></i> Two-factor authentication is
                                        enabled.</p>
                                    <div class="form-group">
                                        <label class="form-label">Current Password <span
                                                style="color:#e74c3c;">*</span></label>
                                        <input type="password" id="totpDisablePassword" class="form-control"
                                            placeholder="Required to disable or regenerate backup codes">
                                    </div>
                                    <button class="btn" id="totpRegenBtn" onclick="regenerateBackupCodes()"><i
                                            class="fa-solid fa-rotate"></i> Regenerate Backup Codes</button>
                                    <button class="btn" id="totpDisableBtn" style="background:var(--danger); color:white; border:none;"
                                        onclick="disableTotp()"><i class="fa-solid fa-shield-halved"></i> Disable
                                        Two-Factor Authentication</button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Billing -->
                    <div class="subnav-pane" id="settings-pane-billing">
                        <?php if (!$licenseValid): ?>
                            <div class="card" style="border-left:3px solid var(--warning); margin-bottom:1rem;">
                                <div class="card-body" style="display:flex; align-items:center; gap:0.75rem; padding:1rem 1.25rem;">
                                    <i class="fa-solid fa-lock" style="color:var(--warning); font-size:1.1rem;"></i>
                                    <div><strong>Recurring billing requires a license.</strong>
                                        <span style="color:var(--text-secondary); font-size:0.85rem; display:block; margin-top:0.15rem;">
                                            Everything below is view-only until you add a key — Ad Hoc invoicing stays free either way.</span>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                        <div style="<?= $licenseValid ? '' : 'opacity:0.5; pointer-events:none; user-select:none;' ?>">
                        <div class="card">
                            <div class="card-header">
                                <h3 style="margin:0; font-size: 1.1rem;"><i class="fa-solid fa-clock"
                                        style="color:var(--accent); margin-right:0.5rem;"></i>Recurring Billing Schedule
                                </h3>
                                <label
                                    style="display:flex; align-items:center; gap:0.4rem; font-size:0.8rem; color:var(--text-secondary); cursor:pointer;">
                                    <input type="checkbox" id="cronEnabledToggle" <?= $cronEnabled ? 'checked' : '' ?>
                                        onchange="toggleCronEnabled(this.checked)"> Active
                                </label>
                            </div>
                            <div class="card-body">
                                <p style="color: var(--text-secondary); margin-bottom: 1rem; font-size: 0.9rem;">Set the
                                    cron schedule this check runs on. Changes are applied instantly to the
                                    cron server. Untick <strong>Active</strong> to pause it without losing the schedule —
                                    useful for maintenance or testing. Each client only actually gets billed at their
                                    own <strong>Billing Frequency</strong> (set per client, defaults to Monthly) —
                                    this schedule just controls how often that gets checked.</p>
                                <div class="form-group">
                                    <label class="form-label">Cron Expression (e.g., <code>15 7 3 * *</code> for 3rd of
                                        month at 7:15 AM)</label>
                                    <div style="display: flex; gap: 0.5rem; align-items:center;">
                                        <input type="text" id="cronInput" class="form-control"
                                            value="<?= htmlspecialchars($currentCron) ?>" placeholder="* * * * *">
                                        <button class="btn success" id="saveCronBtn" onclick="saveCron()"><i
                                                class="fa-solid fa-check"></i> Save</button>
                                    </div>
                                    <p id="cronHuman"
                                        style="margin-top:0.5rem; font-size:0.85rem; color:var(--text-secondary);"></p>
                                </div>
                                <div class="form-group" style="margin-top:1rem; padding-top:1rem; border-top:1px solid var(--border);">
                                    <label style="display:flex; align-items:center; gap:0.5rem; cursor:pointer; color:var(--warning); font-weight:500;">
                                        <input type="checkbox" id="recurringBypassGuardToggle" <?= $recurringBypassGuard ? 'checked' : '' ?>
                                            onchange="toggleRecurringBypassGuard(this.checked)"> Allow re-billing within the same period
                                    </label>
                                    <p style="color:var(--text-secondary); font-size:0.8rem; margin-top:0.35rem;">
                                        <strong>Off by default — leave it off in normal operation.</strong> When on,
                                        the double-billing guard is skipped entirely, so every active client gets a
                                        new invoice on every run, even if they already have one this period. Only
                                        useful for testing the recurring billing flow without deleting a test
                                        invoice each time. Leaving this on against real clients will double-charge
                                        them.</p>
                                </div>
                            </div>
                        </div>

                        <div class="card">
                            <div class="card-header">
                                <h3 style="margin:0; font-size: 1.1rem;"><i class="fa-solid fa-bell"
                                        style="color:var(--accent); margin-right:0.5rem;"></i>Payment Reminders
                                </h3>
                                <label
                                    style="display:flex; align-items:center; gap:0.4rem; font-size:0.8rem; color:var(--text-secondary); cursor:pointer;">
                                    <input type="checkbox" id="remindersEnabledToggle" <?= $remindersEnabled ? 'checked' : '' ?>
                                        onchange="toggleReminders(this.checked)"> Active
                                </label>
                            </div>
                            <div class="card-body">
                                <p style="color: var(--text-secondary); margin-bottom: 0; font-size: 0.9rem;">When
                                    active, sends one automatic reminder email per invoice once its due date is <strong>7
                                        or more days in the past</strong> and it's still unpaid. Each invoice is only
                                    reminded once, ever. Runs on the same schedule as Recurring Billing above — the
                                    subject/body templates are set under Email.</p>
                            </div>
                        </div>

                        <div class="card">
                            <div class="card-header">
                                <h3 style="margin:0; font-size: 1.1rem;"><i class="fa-solid fa-triangle-exclamation"
                                        style="color:var(--accent); margin-right:0.5rem;"></i>Late Fees
                                </h3>
                                <label
                                    style="display:flex; align-items:center; gap:0.4rem; font-size:0.8rem; color:var(--text-secondary); cursor:pointer;">
                                    <input type="checkbox" id="lateFeesEnabledToggle" <?= $lateFeesEnabled ? 'checked' : '' ?>
                                        onchange="toggleLateFees(this.checked)"> Active
                                </label>
                            </div>
                            <div class="card-body">
                                <p style="color: var(--text-secondary); margin-bottom: 1rem; font-size: 0.9rem;">
                                    <strong>Off by default.</strong> When active, generates and emails a separate late
                                    fee invoice — once per overdue invoice, ever — once it's this many days past due
                                    and still unpaid. Runs on the same schedule as Recurring Billing above.</p>
                                <form id="lateFeeSettingsForm" onsubmit="event.preventDefault(); saveLateFeeSettings();">
                                    <div class="form-group">
                                        <label class="form-label" for="lateFeeType">Fee Type</label>
                                        <select id="lateFeeType" name="late_fee_type" class="form-control">
                                            <option value="percent" <?= ($settings['late_fee_type'] ?? 'percent') === 'percent' ? 'selected' : '' ?>>
                                                Percentage of outstanding balance</option>
                                            <option value="flat" <?= ($settings['late_fee_type'] ?? 'percent') === 'flat' ? 'selected' : '' ?>>
                                                Flat amount</option>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label" for="lateFeeValue">Fee Value (<?= htmlspecialchars($settings['currency'] ?? 'USD') ?> or %, depending on type above)</label>
                                        <input type="number" id="lateFeeValue" name="late_fee_value" class="form-control"
                                            step="0.01" min="0"
                                            value="<?= htmlspecialchars($settings['late_fee_value'] ?? '5') ?>">
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label" for="lateFeeGraceDays">Grace Period (days overdue before the fee applies)</label>
                                        <input type="number" id="lateFeeGraceDays" name="late_fee_grace_days" class="form-control"
                                            step="1" min="0"
                                            value="<?= htmlspecialchars($settings['late_fee_grace_days'] ?? '7') ?>">
                                    </div>
                                    <button type="submit" class="btn primary" id="saveLateFeeSettingsBtn"><i
                                            class="fa-solid fa-save"></i> Save Late Fee Settings</button>
                                </form>
                            </div>
                        </div>
                        </div>
                    </div>

                    <!-- Email -->
                    <div class="subnav-pane" id="settings-pane-email">
                        <div class="card">
                            <div class="card-header">
                                <h3 style="margin:0; font-size: 1.1rem;"><i class="fa-solid fa-envelope"
                                        style="color:var(--accent); margin-right:0.5rem;"></i>Test Email Server</h3>
                            </div>
                            <div class="card-body">
                                <p style="color: var(--text-secondary); margin-bottom: 1rem; font-size: 0.9rem;">Send a test
                                    email using the configured SMTP credentials.</p>
                                <div class="form-group">
                                    <label class="form-label">Recipient Email</label>
                                    <input type="email" id="testEmailInput" class="form-control"
                                        placeholder="you@example.com">
                                </div>
                                <button class="btn primary" id="sendTestEmailBtn" onclick="sendTestEmail()"><i
                                        class="fa-solid fa-paper-plane"></i> Send Test Email</button>
                            </div>
                        </div>

                        <div class="card">
                            <div class="card-header">
                                <h3 style="margin:0; font-size: 1.1rem;"><i class="fa-solid fa-envelope-open-text"
                                        style="color:var(--accent); margin-right:0.5rem;"></i>Email Templates</h3>
                            </div>
                            <div class="card-body">
                                <p style="color:var(--text-secondary); font-size:0.8rem; margin-top:0; margin-bottom:1rem;">
                                    Subject/body of the invoice and reminder emails sent to clients. Available
                                    placeholders: <code>{business_name}</code> <code>{client_name}</code>
                                    <code>{invoice_number}</code> <code>{amount}</code> <code>{due_date}</code>
                                    <code>{days_overdue}</code> (reminder only).</p>
                                <form id="emailTemplatesForm" onsubmit="event.preventDefault(); saveEmailTemplates();">
                                    <div class="form-group">
                                        <label class="form-label" for="invoiceEmailSubject">Invoice Email Subject</label>
                                        <input type="text" id="invoiceEmailSubject" name="invoice_email_subject" class="form-control"
                                            value="<?= htmlspecialchars($settings['invoice_email_subject'] ?? DEFAULT_INVOICE_SUBJECT) ?>">
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label" for="reminderEmailSubject">Reminder Email Subject</label>
                                        <input type="text" id="reminderEmailSubject" name="reminder_email_subject" class="form-control"
                                            value="<?= htmlspecialchars($settings['reminder_email_subject'] ?? DEFAULT_REMINDER_SUBJECT) ?>">
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label" for="reminderEmailBody">Reminder Email Body</label>
                                        <textarea id="reminderEmailBody" name="reminder_email_body" class="form-control"
                                            rows="6"><?= htmlspecialchars($settings['reminder_email_body'] ?? DEFAULT_REMINDER_BODY) ?></textarea>
                                    </div>
                                    <button type="submit" class="btn primary" id="saveEmailTemplatesBtn"><i
                                            class="fa-solid fa-save"></i> Save Email Templates</button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Notifications -->
                    <div class="subnav-pane" id="settings-pane-notifications">
                        <div class="card">
                            <div class="card-header">
                                <h3 style="margin:0; font-size: 1.1rem;"><i class="fa-solid fa-bell"
                                        style="color:var(--accent); margin-right:0.5rem;"></i>Notifications</h3>
                            </div>
                            <div class="card-body">
                                <p style="color: var(--text-secondary); margin-bottom: 1rem; font-size: 0.9rem;">
                                    Pings a chat when a payment comes in or an invoice goes overdue — separate from
                                    the SMTP email above, so it still works even if email delivery is broken. Pick one
                                    channel below.</p>
                                <form id="notificationSettingsForm" onsubmit="event.preventDefault(); saveNotificationSettings();">
                                    <div class="form-group">
                                        <label class="form-label" for="notificationChannel">Channel</label>
                                        <select id="notificationChannel" name="notification_channel" class="form-control"
                                            onchange="updateNotificationChannelUI()">
                                            <?php $notifChannel = $settings['notification_channel'] ?? 'none'; ?>
                                            <option value="none" <?= $notifChannel === 'none' ? 'selected' : '' ?>>None</option>
                                            <option value="telegram" <?= $notifChannel === 'telegram' ? 'selected' : '' ?>>Telegram</option>
                                            <option value="slack" <?= $notifChannel === 'slack' ? 'selected' : '' ?>>Slack</option>
                                            <option value="webhook" <?= $notifChannel === 'webhook' ? 'selected' : '' ?>>Generic Webhook</option>
                                        </select>
                                    </div>
                                    <div id="telegramFields">
                                        <p style="color:var(--text-secondary); font-size:0.8rem; margin-top:0; margin-bottom:0.75rem;">
                                            Message <a href="https://t.me/BotFather" target="_blank" rel="noopener">@BotFather</a>
                                            to create a bot and get its token, then message your new bot once and open
                                            <code>https://api.telegram.org/bot&lt;token&gt;/getUpdates</code> in a
                                            browser to find your chat ID.</p>
                                        <div class="form-group">
                                            <label class="form-label" for="telegramBotToken">Bot Token</label>
                                            <input type="password" id="telegramBotToken" name="telegram_bot_token" class="form-control"
                                                autocomplete="off" placeholder="123456789:AA..."
                                                value="<?= htmlspecialchars($settings['telegram_bot_token'] ?? '') ?>">
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label" for="telegramChatId">Chat ID</label>
                                            <input type="text" id="telegramChatId" name="telegram_chat_id" class="form-control"
                                                placeholder="e.g. 123456789"
                                                value="<?= htmlspecialchars($settings['telegram_chat_id'] ?? '') ?>">
                                        </div>
                                    </div>
                                    <div id="slackFields">
                                        <p style="color:var(--text-secondary); font-size:0.8rem; margin-top:0; margin-bottom:0.75rem;">
                                            Create an <a href="https://api.slack.com/messaging/webhooks" target="_blank" rel="noopener">Incoming
                                                Webhook</a> for the channel you want to post to, then paste its URL below.</p>
                                        <div class="form-group">
                                            <label class="form-label" for="slackWebhookUrl">Webhook URL</label>
                                            <input type="password" id="slackWebhookUrl" name="slack_webhook_url" class="form-control"
                                                autocomplete="off" placeholder="https://hooks.slack.com/services/..."
                                                value="<?= htmlspecialchars($settings['slack_webhook_url'] ?? '') ?>">
                                        </div>
                                    </div>
                                    <div id="webhookFields">
                                        <p style="color:var(--text-secondary); font-size:0.8rem; margin-top:0; margin-bottom:0.75rem;">
                                            Any URL that accepts a POST — <a href="https://ntfy.sh" target="_blank" rel="noopener">ntfy</a>,
                                            a Discord webhook, or your own shell script/receiver. Pick the format it expects below.</p>
                                        <div class="form-group">
                                            <label class="form-label" for="webhookUrl">Webhook URL</label>
                                            <input type="password" id="webhookUrl" name="webhook_url" class="form-control"
                                                autocomplete="off" placeholder="https://ntfy.sh/your-topic"
                                                value="<?= htmlspecialchars($settings['webhook_url'] ?? '') ?>">
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label" for="webhookFormat">Payload Format</label>
                                            <?php $webhookFormat = $settings['webhook_format'] ?? 'json_text'; ?>
                                            <select id="webhookFormat" name="webhook_format" class="form-control">
                                                <option value="json_text" <?= $webhookFormat === 'json_text' ? 'selected' : '' ?>>JSON — {"text": "..."} (Slack-style, e.g. Mattermost)</option>
                                                <option value="plain" <?= $webhookFormat === 'plain' ? 'selected' : '' ?>>Plain text body (ntfy, shell scripts)</option>
                                                <option value="discord" <?= $webhookFormat === 'discord' ? 'selected' : '' ?>>JSON — {"content": "..."} (Discord)</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="form-group" style="margin-top:0.5rem; padding-top:0.75rem; border-top:1px solid var(--border);">
                                        <label style="display:flex; align-items:center; gap:0.5rem; cursor:pointer; font-weight:400;">
                                            <input type="checkbox" id="notifyOnPayment" name="notify_on_payment" value="1"
                                                <?= ($settings['notify_on_payment'] ?? '1') === '1' ? 'checked' : '' ?>>
                                            Notify when a payment is received
                                        </label>
                                    </div>
                                    <div class="form-group">
                                        <label style="display:flex; align-items:center; gap:0.5rem; cursor:pointer; font-weight:400;">
                                            <input type="checkbox" id="notifyOnOverdue" name="notify_on_overdue" value="1"
                                                <?= ($settings['notify_on_overdue'] ?? '1') === '1' ? 'checked' : '' ?>>
                                            Notify when an invoice becomes overdue (same trigger as the reminder email)
                                        </label>
                                    </div>
                                    <div class="form-group">
                                        <label style="display:flex; align-items:center; gap:0.5rem; cursor:pointer; font-weight:400;">
                                            <input type="checkbox" id="notifyOnQuoteAccepted" name="notify_on_quote_accepted" value="1"
                                                <?= ($settings['notify_on_quote_accepted'] ?? '1') === '1' ? 'checked' : '' ?>>
                                            Notify when a client accepts a quote from their Client Portal
                                        </label>
                                    </div>
                                    <div style="display:flex; gap:0.75rem; flex-wrap:wrap;">
                                        <button type="submit" class="btn primary" id="saveNotificationSettingsBtn"><i
                                                class="fa-solid fa-save"></i> Save Notification Settings</button>
                                        <button type="button" class="btn" id="sendTestNotificationBtn" onclick="sendTestNotification()"><i
                                                class="fa-solid fa-paper-plane"></i> Send Test Message</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Payments -->
                    <div class="subnav-pane" id="settings-pane-payments">
                        <?php if (!$licenseValid): ?>
                            <div class="card" style="border-left:3px solid var(--warning); margin-bottom:1rem;">
                                <div class="card-body" style="display:flex; align-items:center; gap:0.75rem; padding:1rem 1.25rem;">
                                    <i class="fa-solid fa-lock" style="color:var(--warning); font-size:1.1rem;"></i>
                                    <div><strong>Stripe/PayPal payment collection requires a license.</strong>
                                        <span style="color:var(--text-secondary); font-size:0.85rem; display:block; margin-top:0.15rem;">
                                            Everything below is view-only until you add a key — marking invoices paid manually stays free either way.</span>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                        <div style="<?= $licenseValid ? '' : 'opacity:0.5; pointer-events:none; user-select:none;' ?>">
                        <div class="card">
                            <div class="card-header">
                                <h3 style="margin:0; font-size: 1.1rem;"><i class="fa-solid fa-credit-card"
                                        style="color:var(--accent); margin-right:0.5rem;"></i>Online Payments</h3>
                            </div>
                            <div class="card-body">
                                <p style="color: var(--text-secondary); margin-bottom: 1rem; font-size: 0.9rem;">
                                    Adds a "Pay Now" button to emailed invoices and the Client Portal. Enable either or
                                    both — a client sees a payment method chooser if more than one is on. Both webhooks
                                    are required, not optional: they're the only path a payment actually gets marked
                                    paid on (the return-URL page is just a faster confirmation the client sees; it isn't
                                    trusted for crediting the invoice on its own).
                                </p>
                                <form id="paymentSettingsForm" onsubmit="event.preventDefault(); savePaymentSettings();">
                                    <div class="form-group">
                                        <label class="form-label" for="publicUrl">Public URL</label>
                                        <input type="text" id="publicUrl" name="public_url" class="form-control"
                                            placeholder="https://invoxa.example.com"
                                            value="<?= htmlspecialchars($settings['public_url'] ?? '') ?>">
                                        <p style="color:var(--text-secondary); font-size:0.8rem; margin-top:0.35rem;">
                                            The address clients reach this app on. Required for Pay Now links to work
                                            in emailed invoices — Recurring Billing generates those from a background
                                            cron job that has no browser request to infer your domain from (it hits
                                            the app over an internal address that isn't reachable outside this
                                            server). Ad Hoc invoices sent interactively don't strictly need this set,
                                            but leaving it blank means recurring invoices silently get no Pay Now
                                            button at all.</p>
                                    </div>
                                    <div style="padding:1rem; border:1px solid var(--border); border-radius:8px; margin-bottom:1rem;">
                                        <label style="display:flex; align-items:center; gap:0.5rem; cursor:pointer; font-weight:600; margin-bottom:0.75rem;">
                                            <input type="checkbox" id="stripeEnabled" name="stripe_enabled" value="1"
                                                <?= ($settings['stripe_enabled'] ?? '0') === '1' ? 'checked' : '' ?>>
                                            <i class="fa-brands fa-stripe-s"></i> Stripe
                                        </label>
                                        <div class="form-group">
                                            <label class="form-label" for="stripeSecretKey">Secret Key</label>
                                            <input type="password" id="stripeSecretKey" name="stripe_secret_key" class="form-control"
                                                autocomplete="off" placeholder="sk_live_... or sk_test_..."
                                                value="<?= htmlspecialchars($settings['stripe_secret_key'] ?? '') ?>">
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label" for="stripeWebhookSecret">Webhook Signing Secret</label>
                                            <input type="password" id="stripeWebhookSecret" name="stripe_webhook_secret" class="form-control"
                                                autocomplete="off" placeholder="whsec_..."
                                                value="<?= htmlspecialchars($settings['stripe_webhook_secret'] ?? '') ?>">
                                            <p style="color:var(--text-secondary); font-size:0.8rem; margin-top:0.35rem;">
                                                In the Stripe Dashboard, add a webhook endpoint pointed at
                                                <code><?= htmlspecialchars((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '')) ?>/?webhook=stripe</code>,
                                                listening for <code>checkout.session.completed</code>,
                                                <code>checkout.session.async_payment_succeeded</code>, and
                                                <code>charge.refunded</code> (the last one is what keeps an invoice's
                                                status accurate if you refund a client from the Stripe dashboard) —
                                                then paste its signing secret here.</p>
                                        </div>
                                        <button type="button" class="btn" id="testStripeBtn" onclick="testStripeConnection()"><i
                                                class="fa-solid fa-plug"></i> Test Connection</button>
                                    </div>
                                    <div style="padding:1rem; border:1px solid var(--border); border-radius:8px;">
                                        <label style="display:flex; align-items:center; gap:0.5rem; cursor:pointer; font-weight:600; margin-bottom:0.75rem;">
                                            <input type="checkbox" id="paypalEnabled" name="paypal_enabled" value="1"
                                                <?= ($settings['paypal_enabled'] ?? '0') === '1' ? 'checked' : '' ?>>
                                            <i class="fa-brands fa-paypal"></i> PayPal
                                        </label>
                                        <div class="form-group">
                                            <label class="form-label" for="paypalEnvironment">Environment</label>
                                            <?php $paypalEnv = $settings['paypal_environment'] ?? 'sandbox'; ?>
                                            <select id="paypalEnvironment" name="paypal_environment" class="form-control">
                                                <option value="sandbox" <?= $paypalEnv === 'sandbox' ? 'selected' : '' ?>>Sandbox (testing)</option>
                                                <option value="live" <?= $paypalEnv === 'live' ? 'selected' : '' ?>>Live</option>
                                            </select>
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label" for="paypalClientId">Client ID</label>
                                            <input type="text" id="paypalClientId" name="paypal_client_id" class="form-control"
                                                autocomplete="off"
                                                value="<?= htmlspecialchars($settings['paypal_client_id'] ?? '') ?>">
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label" for="paypalClientSecret">Client Secret</label>
                                            <input type="password" id="paypalClientSecret" name="paypal_client_secret" class="form-control"
                                                autocomplete="off"
                                                value="<?= htmlspecialchars($settings['paypal_client_secret'] ?? '') ?>">
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label" for="paypalWebhookId">Webhook ID</label>
                                            <input type="text" id="paypalWebhookId" name="paypal_webhook_id" class="form-control"
                                                autocomplete="off"
                                                value="<?= htmlspecialchars($settings['paypal_webhook_id'] ?? '') ?>">
                                            <p style="color:var(--text-secondary); font-size:0.8rem; margin-top:0.35rem;">
                                                In the PayPal Developer Dashboard, add a webhook pointed at
                                                <code><?= htmlspecialchars((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '')) ?>/?webhook=paypal</code>,
                                                subscribed to <code>PAYMENT.CAPTURE.COMPLETED</code> and
                                                <code>PAYMENT.CAPTURE.REFUNDED</code> (the second one is what keeps an
                                                invoice's status accurate if you refund a client from the PayPal
                                                dashboard) — then paste its Webhook ID here (not the secret; PayPal
                                                verifies webhooks by calling back to its own API, not a local
                                                signature).</p>
                                        </div>
                                        <button type="button" class="btn" id="testPaypalBtn" onclick="testPaypalConnection()"><i
                                                class="fa-solid fa-plug"></i> Test Connection</button>
                                    </div>
                                    <button type="submit" class="btn primary" id="savePaymentSettingsBtn" style="margin-top:1rem;"><i
                                            class="fa-solid fa-save"></i> Save Payment Settings</button>
                                </form>
                            </div>
                        </div>
                        </div>
                    </div>

                    <!-- API Access -->
                    <?php $__apiBaseUrl = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'your-domain'); ?>
                    <div class="subnav-pane" id="settings-pane-api">
                        <?php if (!$licenseValid): ?>
                            <div class="card" style="border-left:3px solid var(--warning); margin-bottom:1rem;">
                                <div class="card-body" style="display:flex; align-items:center; gap:0.75rem; padding:1rem 1.25rem;">
                                    <i class="fa-solid fa-lock" style="color:var(--warning); font-size:1.1rem;"></i>
                                    <div><strong>Creating or renewing API tokens requires a license.</strong>
                                        <span style="color:var(--text-secondary); font-size:0.85rem; display:block; margin-top:0.15rem;">
                                            Revoking or deleting an existing token stays free either way.</span>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                        <div style="<?= $licenseValid ? '' : 'opacity:0.5; pointer-events:none; user-select:none;' ?>">
                        <div class="card">
                            <div class="card-header">
                                <h3 style="margin:0; font-size: 1.1rem;"><i class="fa-solid fa-plug-circle-bolt"
                                        style="color:var(--accent); margin-right:0.5rem;"></i>API Access</h3>
                            </div>
                            <div class="card-body">
                                <p style="color: var(--text-secondary); font-size: 0.9rem; margin-bottom: 1rem;">
                                    A small read/write API for scripts and other tools to talk to this instance —
                                    list invoices, look one up, list clients, and record a payment against an
                                    invoice. Each token is a credential in its own right: anyone with one can read
                                    invoice/client data and record payments until you revoke it, so treat it like a
                                    password and only hand it to something you trust.
                                </p>

                                <!-- Create token -->
                                <div style="padding:1rem; border:1px solid var(--border); border-radius:8px; margin-bottom:1.5rem; <?= $licenseValid ? '' : 'opacity:0.5;' ?>">
                                    <div style="display:flex; gap:1rem; align-items:flex-end; flex-wrap:wrap;">
                                        <div class="form-group" style="margin-bottom:0; flex:1; min-width:180px;">
                                            <label class="form-label">Label</label>
                                            <input type="text" id="apiTokenLabel" class="form-control" placeholder="e.g. Zapier, accounting sync" <?= $licenseValid ? '' : 'disabled' ?>>
                                        </div>
                                        <div class="form-group" style="margin-bottom:0;">
                                            <label class="form-label">Expires</label>
                                            <select id="apiTokenExpiry" class="form-control" <?= $licenseValid ? '' : 'disabled' ?>>
                                                <option value="never">Never</option>
                                                <option value="30">30 days</option>
                                                <option value="90" selected>90 days</option>
                                                <option value="365">1 year</option>
                                            </select>
                                        </div>
                                        <button class="btn primary" id="createApiTokenBtn" type="button" onclick="createApiToken()"
                                            <?= $licenseValid ? '' : 'disabled title="Requires a license"' ?>><i
                                                class="fa-solid fa-plus"></i> Create Token</button>
                                    </div>
                                    <div id="apiTokenNewWrap" style="display:none; margin-top:1rem; padding:1rem; border:1px solid var(--warning); border-radius:8px;">
                                        <p style="color:var(--warning); font-weight:600; margin-top:0;"><i
                                                class="fa-solid fa-triangle-exclamation"></i> Copy this token now — it
                                            won't be shown again.</p>
                                        <div style="display:flex; gap:0.5rem;">
                                            <input type="text" id="apiTokenNewValue" class="form-control" readonly style="font-family:ui-monospace,monospace;">
                                            <button class="btn" type="button" onclick="copyApiToken()" style="width:auto; white-space:nowrap;"><i
                                                    class="fa-solid fa-copy"></i> Copy</button>
                                        </div>
                                    </div>
                                </div>

                                <!-- Existing tokens -->
                                <?php
                                $__apiTokens = $mysqli->query("SELECT id, label, token_prefix, created_at, last_used_at, expires_at, revoked_at FROM invoxa_api_tokens ORDER BY created_at DESC");
                                ?>
                                <table style="width:100%; border-collapse:collapse; margin-bottom:1.5rem; font-size:0.85rem;">
                                    <thead>
                                        <tr style="text-align:left; color:var(--text-secondary); border-bottom:1px solid var(--border);">
                                            <th style="padding:0.4rem 0.5rem;">Label</th>
                                            <th style="padding:0.4rem 0.5rem;">Token</th>
                                            <th style="padding:0.4rem 0.5rem;">Last Used</th>
                                            <th style="padding:0.4rem 0.5rem;">Status</th>
                                            <th style="padding:0.4rem 0.5rem;"></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if ($__apiTokens->num_rows === 0): ?>
                                            <tr>
                                                <td colspan="5" style="padding:0.75rem 0.5rem; color:var(--text-secondary);">No API tokens yet.</td>
                                            </tr>
                                        <?php else: while ($__t = $__apiTokens->fetch_assoc()):
                                            $__expired = !empty($__t['expires_at']) && strtotime($__t['expires_at']) < time();
                                            $__statusLabel = $__t['revoked_at'] ? 'Revoked' : ($__expired ? 'Expired' : 'Active');
                                            $__statusColor = $__t['revoked_at'] || $__expired ? 'var(--text-secondary)' : 'var(--success)';
                                            ?>
                                            <tr style="border-bottom:1px solid var(--border);">
                                                <td style="padding:0.5rem;"><?= htmlspecialchars($__t['label']) ?></td>
                                                <td style="padding:0.5rem; font-family:ui-monospace,monospace;"><?= htmlspecialchars($__t['token_prefix']) ?>…</td>
                                                <td style="padding:0.5rem; color:var(--text-secondary);"><?= $__t['last_used_at'] ? htmlspecialchars($__t['last_used_at']) : 'Never' ?></td>
                                                <td style="padding:0.5rem; color:<?= $__statusColor ?>;"><?= $__statusLabel ?><?= (!$__t['revoked_at'] && !$__expired && $__t['expires_at']) ? ' (expires ' . htmlspecialchars(substr($__t['expires_at'], 0, 10)) . ')' : '' ?></td>
                                                <td style="padding:0.5rem; white-space:nowrap;">
                                                    <?php if (!$__t['revoked_at']): ?>
                                                        <select class="form-control" style="display:inline-block; width:auto; font-size:0.8rem; padding:0.2rem 0.4rem;" id="apiTokenRenewSelect<?= $__t['id'] ?>" <?= $licenseValid ? '' : 'disabled' ?>>
                                                            <option value="never">Never</option>
                                                            <option value="30">30 days</option>
                                                            <option value="90" selected>90 days</option>
                                                            <option value="365">1 year</option>
                                                        </select>
                                                        <button class="btn small" type="button" onclick="renewApiToken(<?= $__t['id'] ?>)" title="<?= $licenseValid ? 'Renew' : 'Requires a license' ?>" <?= $licenseValid ? '' : 'disabled' ?>><i class="fa-solid fa-rotate"></i></button>
                                                        <button class="btn small danger" type="button" onclick="revokeApiToken(<?= $__t['id'] ?>)" title="Revoke" style="pointer-events:auto; opacity:1;"><i class="fa-solid fa-ban"></i></button>
                                                    <?php endif; ?>
                                                    <?php if ($__t['revoked_at'] || $__expired): ?>
                                                        <button class="btn small danger" type="button" onclick="deleteApiToken(<?= $__t['id'] ?>)" title="Permanently delete" style="pointer-events:auto; opacity:1;"><i class="fa-solid fa-trash"></i></button>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endwhile; endif; ?>
                                    </tbody>
                                </table>

                                <!-- Guide -->
                                <h4 style="margin:0 0 0.5rem;">Guide</h4>
                                <p style="color:var(--text-secondary); font-size:0.85rem; margin-top:0;">
                                    Base URL: <code><?= htmlspecialchars($__apiBaseUrl) ?></code>. Every request needs
                                    <code>Authorization: Bearer &lt;your token&gt;</code>. Responses are JSON —
                                    <code>{"data": ...}</code> on success, <code>{"error": "..."}</code> on failure.
                                </p>

                                <?php
                                $__apiExamples = [
                                    [
                                        'title' => 'List invoices',
                                        'desc' => 'Optional query params: status (e.g. sent/paid/void), client_key, limit (default 50, max 200), offset.',
                                        'curl' => "curl \"{$__apiBaseUrl}/?apiv1=invoices.list&status=sent&limit=20\" \\\n  -H \"Authorization: Bearer ivx_your_token_here\"",
                                    ],
                                    [
                                        'title' => 'Get one invoice',
                                        'desc' => 'By invoice number.',
                                        'curl' => "curl \"{$__apiBaseUrl}/?apiv1=invoices.get&invoice_number=INV001\" \\\n  -H \"Authorization: Bearer ivx_your_token_here\"",
                                    ],
                                    [
                                        'title' => 'List clients',
                                        'desc' => 'Optional query params: limit (default 50, max 200), offset.',
                                        'curl' => "curl \"{$__apiBaseUrl}/?apiv1=clients.list\" \\\n  -H \"Authorization: Bearer ivx_your_token_here\"",
                                    ],
                                    [
                                        'title' => 'Record a payment',
                                        'desc' => 'JSON body: invoice_number (required), amount (required), note (optional), reference (optional — your own idempotency key, so retrying the exact same call never double-credits it).',
                                        'curl' => "curl -X POST \"{$__apiBaseUrl}/?apiv1=payments.record\" \\\n  -H \"Authorization: Bearer ivx_your_token_here\" \\\n  -H \"Content-Type: application/json\" \\\n  -d '{\"invoice_number\": \"INV001\", \"amount\": 50.00, \"note\": \"Bank transfer\", \"reference\": \"txn_12345\"}'",
                                    ],
                                ];
                                foreach ($__apiExamples as $__i => $__ex):
                                    $__exId = 'apiExample' . $__i;
                                    ?>
                                    <div style="margin-bottom:1rem;">
                                        <strong style="font-size:0.9rem;"><?= htmlspecialchars($__ex['title']) ?></strong>
                                        <p style="color:var(--text-secondary); font-size:0.8rem; margin:0.15rem 0 0.4rem;"><?= htmlspecialchars($__ex['desc']) ?></p>
                                        <div style="position:relative;">
                                            <pre id="<?= $__exId ?>" style="background:var(--bg); border:1px solid var(--border); border-radius:6px; padding:0.75rem 1rem; font-size:0.8rem; overflow-x:auto; margin:0; white-space:pre-wrap;"><?= htmlspecialchars($__ex['curl']) ?></pre>
                                            <button class="btn small" type="button" onclick="copyApiExample('<?= $__exId ?>')" style="position:absolute; top:0.4rem; right:0.4rem;"><i class="fa-solid fa-copy"></i> Copy</button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        </div>
                    </div>

                    <!-- Branding -->
                    <div class="subnav-pane" id="settings-pane-branding">
                        <div class="card">
                            <div class="card-header">
                                <h3 style="margin:0; font-size: 1.1rem;"><i class="fa-solid fa-id-badge"
                                        style="color:var(--accent); margin-right:0.5rem;"></i>Business Identity</h3>
                            </div>
                            <div class="card-body">
                                <form id="businessIdentityForm" onsubmit="event.preventDefault(); saveBusinessIdentity();">
                                    <div class="form-group">
                                        <label class="form-label" for="businessName">Business Name</label>
                                        <input type="text" id="businessName" name="business_name" class="form-control"
                                            value="<?= htmlspecialchars($settings['business_name'] ?? '') ?>"
                                            placeholder="Your Business Name">
                                        <p style="color:var(--text-secondary); font-size:0.8rem; margin-top:0.35rem;">Used as
                                            the sender name and subject line on invoices sent to your clients. Leave blank
                                            to use "Invoxa".</p>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label" for="vatNumber">GST / VAT Number</label>
                                        <input type="text" id="vatNumber" name="vat_number" class="form-control"
                                            value="<?= htmlspecialchars($settings['vat_number'] ?? '') ?>"
                                            placeholder="e.g. 123-456-789">
                                        <p style="color:var(--text-secondary); font-size:0.8rem; margin-top:0.35rem;">Shown
                                            on invoices and quotes when set. Leave blank to omit it.</p>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label" for="brandColor">Primary Brand Color</label>
                                        <div style="display:flex; gap:1rem; align-items:center;">
                                            <input type="color" id="brandColor" name="brand_color"
                                                value="<?= htmlspecialchars($settings['brand_color'] ?? '#4a90e2') ?>"
                                                style="width:50px;height:40px;padding:0;background:none;border:none;cursor:pointer;">
                                        </div>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label" style="display: flex; align-items: center; gap: 0.5rem; cursor: <?= $licenseValid ? 'pointer' : 'not-allowed' ?>;">
                                            <input type="checkbox" id="hidePoweredByToggle" name="hide_powered_by"
                                                value="1" <?= ($settings['hide_powered_by'] ?? '0') === '1' ? 'checked' : '' ?>
                                                <?= $licenseValid ? '' : 'disabled' ?> style="width:16px;height:16px;">
                                            Remove "Powered by Invoxa" from invoices &amp; emails
                                        </label>
                                        <p style="color:var(--text-secondary); font-size:0.8rem; margin-top:0.35rem;">
                                            <?= $licenseValid
                                                ? 'Removes the small credit line from every generated invoice, quote, and email.'
                                                : 'Requires a license — <a href="#" onclick="navSettings(\'license\'); return false;">add a key</a> to unlock.' ?>
                                        </p>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label" for="logoUpload">Invoice Logo</label>
                                        <input type="file" id="logoUpload" name="logo" class="form-control" accept="image/*"
                                            style="padding:0.5rem;">
                                        <?php if (file_exists('/usr/share/nginx/html/invoxa-invoices/invoxa_logo.jpg')): ?>
                                            <div style="margin-top:0.5rem; color:var(--success); font-size:0.85rem;"><i
                                                    class="fa-solid fa-check"></i> Custom logo uploaded</div>
                                        <?php endif; ?>
                                    </div>
                                    <button type="submit" class="btn primary" id="saveBusinessIdentityBtn"><i
                                            class="fa-solid fa-save"></i> Save Business Identity</button>
                                </form>
                            </div>
                        </div>

                        <div class="card">
                            <div class="card-header">
                                <h3 style="margin:0; font-size: 1.1rem;"><i class="fa-solid fa-file-invoice"
                                        style="color:var(--accent); margin-right:0.5rem;"></i>Invoice Template</h3>
                            </div>
                            <div class="card-body">
                                <form id="invoiceTemplateForm" onsubmit="event.preventDefault(); saveInvoiceTemplate();">
                                    <div class="form-group">
                                        <label class="form-label" for="invoiceTemplate">Layout</label>
                                        <div style="display:flex; gap:0.5rem; align-items:flex-start;">
                                            <select id="invoiceTemplate" name="invoice_template" class="form-control"
                                                onchange="document.getElementById('customTemplateGroup').style.display = this.value === 'custom' ? '' : 'none';">
                                                <?php $__invTpl = $settings['invoice_template'] ?? 'detailed'; ?>
                                                <option value="detailed" <?= $__invTpl === 'detailed' ? 'selected' : '' ?>>
                                                    Detailed (spacious, original layout)</option>
                                                <option value="compact" <?= $__invTpl === 'compact' ? 'selected' : '' ?>>
                                                    Compact (tighter spacing, smaller logo — fits more line items per page)</option>
                                                <option value="custom" <?= $__invTpl === 'custom' ? 'selected' : '' ?>>
                                                    Custom (edit the HTML template yourself)</option>
                                            </select>
                                            <button type="button" class="btn" style="white-space:nowrap;"
                                                onclick="previewInvoiceTemplate()"><i class="fa-solid fa-eye"></i> Preview
                                                Sample</button>
                                        </div>
                                        <p style="color:var(--text-secondary); font-size:0.8rem; margin-top:0.35rem;">
                                            Applies to every newly generated invoice, quote, and PDF — existing invoices
                                            already on disk keep the layout they were created with. "Preview Sample" shows a
                                            dummy invoice in the selected layout without saving anything.</p>
                                    </div>
                                    <div id="customTemplateGroup" style="display: <?= $__invTpl === 'custom' ? '' : 'none' ?>;">
                                        <div class="form-group">
                                            <label class="form-label" for="customInvoiceTemplate">Custom Template HTML</label>
                                            <textarea id="customInvoiceTemplate" name="custom_invoice_template" class="form-control"
                                                rows="14" spellcheck="false"
                                                style="font-family:monospace; font-size:0.8rem; white-space:pre;"
                                                placeholder="Click &quot;Load Default Template&quot; below to start from Invoxa's built-in layout."><?= htmlspecialchars($settings['custom_invoice_template'] ?? '') ?></textarea>
                                            <div style="display:flex; gap:0.5rem; margin-top:0.5rem;">
                                                <button type="button" class="btn" onclick="loadDefaultInvoiceTemplate()"><i
                                                        class="fa-solid fa-rotate-left"></i> Load Default Template</button>
                                                <button type="button" class="btn" onclick="previewInvoiceTemplate()"><i
                                                        class="fa-solid fa-eye"></i> Preview Sample</button>
                                            </div>
                                        </div>
                                        <div class="form-group">
                                            <details style="color:var(--text-secondary); font-size:0.8rem;">
                                                <summary class="form-label" style="cursor:pointer; display:inline;">Available
                                                    template variables</summary>
                                                <p style="margin:0.5rem 0 0.25rem;">Output a value with
                                                    <code>{{ variable }}</code>, or <code>{{ variable|raw }}</code> to output
                                                    HTML without escaping. Conditionals:
                                                    <code>{% if variable %}...{% endif %}</code> (also
                                                    <code>{% if not variable %}</code>, and <code>{% else %}</code>). Loop
                                                    over line items with
                                                    <code>{% for item in line_items %}{{ item.code }} {{ item.desc }} {{
                                                        item.amount }}{% endfor %}</code>.</p>
                                                <p style="margin:0.5rem 0 0;"><code>business_name</code>,
                                                    <code>document_type</code> ("Invoice" or "Quote"),
                                                    <code>vat_number</code>, <code>recipient</code>,
                                                    <code>recipient_phone</code>, <code>recipient_address</code>,
                                                    <code>date</code>, <code>due_date</code>, <code>quote_expires_at</code> (quotes only), <code>invoice_number</code>,
                                                    <code>amount</code>, <code>currency_code</code>,
                                                    <code>account_name</code>, <code>account_number</code>,
                                                    <code>sender_email</code>, <code>brand_color</code>,
                                                    <code>footer_text</code>, <code>line_items</code>,
                                                    <code>subtotal</code>, <code>has_discount</code>,
                                                    <code>discount_pct</code>, <code>discount</code>,
                                                    <code>has_tax</code>, <code>tax_rate</code>, <code>tax</code>,
                                                    <code>has_pay_url</code>, <code>pay_url</code>,
                                                    <code>show_powered_by</code>, <code>logo_tag</code> (use with
                                                    <code>|raw</code>).</p>
                                            </details>
                                        </div>
                                    </div>
                                    <button type="submit" class="btn primary" id="saveInvoiceTemplateBtn"><i
                                            class="fa-solid fa-save"></i> Save Invoice Template</button>
                                </form>
                            </div>
                        </div>

                        <div class="card">
                            <div class="card-header">
                                <h3 style="margin:0; font-size: 1.1rem;"><i class="fa-solid fa-coins"
                                        style="color:var(--accent); margin-right:0.5rem;"></i>Invoice Defaults</h3>
                            </div>
                            <div class="card-body">
                                <form id="invoiceDefaultsForm" onsubmit="event.preventDefault(); saveInvoiceDefaults();">
                                    <div class="form-group">
                                        <label class="form-label" for="currency">Currency Code</label>
                                        <input type="text" id="currency" name="currency" class="form-control" maxlength="3"
                                            style="text-transform:uppercase; max-width:100px;"
                                            value="<?= htmlspecialchars($settings['currency'] ?? 'USD') ?>" placeholder="USD">
                                        <p style="color:var(--text-secondary); font-size:0.8rem; margin-top:0.35rem;">3-letter
                                            code shown on invoices (e.g. USD, NZD, GBP, EUR).</p>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label" for="taxYearStartMonth">Tax Year Starts</label>
                                        <select id="taxYearStartMonth" name="tax_year_start_month" class="form-control">
                                            <?php
                                            $__months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
                                            $__tysm = (int) ($settings['tax_year_start_month'] ?? 1);
                                            foreach ($__months as $__i => $__m):
                                                ?>
                                                <option value="<?= $__i + 1 ?>" <?= $__tysm === $__i + 1 ? 'selected' : '' ?>>
                                                    <?= $__m ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <p style="color:var(--text-secondary); font-size:0.8rem; margin-top:0.35rem;">Used by
                                            the Tax Year report. Choose January for a calendar-year default.</p>
                                    </div>
                                    <button type="submit" class="btn primary" id="saveInvoiceDefaultsBtn"><i
                                            class="fa-solid fa-save"></i> Save Invoice Defaults</button>
                                </form>
                            </div>
                        </div>

                        <div class="card">
                            <div class="card-header">
                                <h3 style="margin:0; font-size: 1.1rem;"><i class="fa-solid fa-building-columns"
                                        style="color:var(--accent); margin-right:0.5rem;"></i>Default Payment Details</h3>
                            </div>
                            <div class="card-body">
                                <p style="color:var(--text-secondary); font-size:0.8rem; margin-top:0; margin-bottom:1rem;">
                                    Used whenever a client doesn't have their own account details set.</p>
                                <form id="paymentDetailsForm" onsubmit="event.preventDefault(); savePaymentDetails();">
                                    <div class="form-group">
                                        <label class="form-label" for="footerText">Footer Text</label>
                                        <textarea id="footerText" name="footer_text" class="form-control" rows="2"
                                            placeholder="Payment instructions..."><?= htmlspecialchars($settings['footer_text'] ?? '') ?></textarea>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label" for="defaultAccountName">Default Account Name</label>
                                        <input type="text" id="defaultAccountName" name="default_account_name"
                                            class="form-control" value="<?= htmlspecialchars($settings['default_account_name'] ?? '') ?>"
                                            placeholder="Used when a client has no account details set">
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label" for="defaultAccountNumber">Default Account Number</label>
                                        <input type="text" id="defaultAccountNumber" name="default_account_number"
                                            class="form-control" value="<?= htmlspecialchars($settings['default_account_number'] ?? '') ?>">
                                    </div>
                                    <button type="submit" class="btn primary" id="savePaymentDetailsBtn"><i
                                            class="fa-solid fa-save"></i> Save Payment Details</button>
                                </form>
                            </div>
                        </div>

                        <div class="card">
                            <div class="card-header">
                                <h3 style="margin:0; font-size: 1.1rem;"><i class="fa-solid fa-hashtag"
                                        style="color:var(--accent); margin-right:0.5rem;"></i>Invoice Numbering</h3>
                            </div>
                            <div class="card-body">
                                <p style="color:var(--text-secondary); font-size:0.8rem; margin-top:0; margin-bottom:1rem;">
                                    Controls the format of generated invoice numbers. Defaults below match the
                                    original fixed format (e.g. <code>GJM010</code>) — change only if you want
                                    something different. Available placeholders: <code>{key}</code> (client key)
                                    <code>{seq}</code> (sequence number) <code>{year}</code> <code>{month}</code>.</p>
                                <form id="invoiceNumberingForm" onsubmit="event.preventDefault(); saveInvoiceNumbering();">
                                    <div class="form-group">
                                        <label class="form-label" for="invoiceNumberTemplate">Number Format</label>
                                        <input type="text" id="invoiceNumberTemplate" name="invoice_number_template" class="form-control"
                                            value="<?= htmlspecialchars($settings['invoice_number_template'] ?? '{key}{seq}') ?>">
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label" for="invoiceNumberPadding">Sequence Padding (digits)</label>
                                        <input type="number" id="invoiceNumberPadding" name="invoice_number_padding" class="form-control"
                                            step="1" min="1" max="10"
                                            value="<?= htmlspecialchars($settings['invoice_number_padding'] ?? '3') ?>">
                                        <p style="color:var(--text-secondary); font-size:0.8rem; margin-top:0.35rem;">
                                            3 = 010, 4 = 0010, etc.</p>
                                    </div>
                                    <button type="submit" class="btn primary" id="saveInvoiceNumberingBtn"><i
                                            class="fa-solid fa-save"></i> Save Numbering Format</button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- License -->
                    <div class="subnav-pane" id="settings-pane-license">
                        <div class="card">
                            <div class="card-header">
                                <h3 style="margin:0; font-size: 1.1rem;"><i class="fa-solid fa-key"
                                        style="color:var(--accent); margin-right:0.5rem;"></i>License</h3>
                            </div>
                            <div class="card-body">
                                <div style="margin-bottom:1rem;">
                                    <?php if ($licenseValid): ?>
                                        <span style="color:var(--success); font-weight:600;"><i class="fa-solid fa-circle-check"></i> Licensed</span>
                                    <?php else: ?>
                                        <span style="color:var(--warning); font-weight:600;"><i class="fa-solid fa-triangle-exclamation"></i> Not licensed</span>
                                        <?php
                                        $__licenseMsgs = [
                                            'empty' => 'Invoxa is free and open source — everything works without a key. A license unlocks six paid extras: Stripe/PayPal payment collection, recurring billing automation, the Client Portal, the external API, Reporting & Statistics, and removing the "Powered by Invoxa" credit.',
                                            'demo_mode' => 'This is a public demo instance — paid features stay locked here regardless of any key entered, so you can see them (dimmed) without anyone being able to actually use them. Buy a license to unlock them on your own instance.',
                                            'malformed' => 'That license key doesn\'t look valid — check you copied the whole string with nothing missing.',
                                            'bad_signature' => 'That license key failed verification — check you copied it exactly, with nothing missing or altered.',
                                            'no_profile_email' => 'Your admin account has no email set. Add the email your license was issued to under Authentication.',
                                            'email_mismatch' => 'This license was issued to a different email than your admin account\'s. Update your email under Authentication to match, or contact your seller for a new key.',
                                            'domain_mismatch' => 'This license is issued for a different domain than the one you\'re accessing this instance on. Contact your seller for a new key if you\'ve moved domains.',
                                        ];
                                        $__licenseMsg = $__licenseMsgs[$licenseFailReason] ?? $__licenseMsgs['empty'];
                                        ?>
                                        <p style="color:var(--text-secondary); font-size:0.8rem; margin-top:0.35rem;">
                                            <?= htmlspecialchars($__licenseMsg) ?></p>
                                        <a href="<?= htmlspecialchars(LICENSE_PURCHASE_URL) ?>" target="_blank" rel="noopener" class="btn primary" style="margin-top:0.5rem;"><i class="fa-solid fa-cart-shopping"></i> Buy a License</a>
                                    <?php endif; ?>
                                </div>
                                <div class="form-group">
                                    <label class="form-label" style="font-size:0.8rem; color:var(--text-secondary);">License
                                        Key</label>
                                    <textarea id="licenseKey" class="form-control" rows="5"
                                        placeholder="Paste the license key you received"><?= htmlspecialchars($settings['license_key'] ?? '') ?></textarea>
                                </div>
                                <button class="btn primary" id="saveLicenseBtn" onclick="saveLicenseKey()"
                                    style="margin-top:0.5rem;"><i class="fa-solid fa-save"></i> Activate License</button>
                                <?php if ($licenseValid): ?>
                                    <button class="btn" id="clearLicenseBtn" onclick="clearLicenseKey()"
                                        style="margin-top:0.5rem;"><i class="fa-solid fa-ban"></i> Deactivate
                                        License</button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
            </div>
        </div>

        <!-- LICENSE -->
        <!-- BACKUP & RESTORE -->
        <div id="sec-backup" class="section">
            <h2 class="page-title">Data Management</h2>
            <div class="section-scroll">
            <div class="subnav-layout">

                <nav class="subnav">
                    <button type="button" class="subnav-item active" data-backup-target="backup"
                        onclick="navBackup('backup')"><i class="fa-solid fa-database"></i> Backup &amp; Restore</button>
                    <button type="button" class="subnav-item" data-backup-target="offsite"
                        onclick="navBackup('offsite')"><i class="fa-solid fa-cloud-arrow-up"></i> Offsite Push</button>
                    <button type="button" class="subnav-item" data-backup-target="bulk"
                        onclick="navBackup('bulk')"><i class="fa-solid fa-check-double"></i> Bulk Actions</button>
                    <button type="button" class="subnav-item" data-backup-target="demo"
                        onclick="navBackup('demo')"><i class="fa-solid fa-wand-magic-sparkles"></i> Demo Data</button>
                    <button type="button" class="subnav-item" data-backup-target="audit"
                        onclick="navBackup('audit')"><i class="fa-solid fa-broom"></i> Audit Log Retention</button>
                    <button type="button" class="subnav-item" data-backup-target="testsuite"
                        onclick="navBackup('testsuite')"><i class="fa-solid fa-vial"></i> Test Suite</button>
                    <button type="button" class="subnav-item danger" data-backup-target="repair"
                        onclick="navBackup('repair')"><i class="fa-solid fa-wrench"></i> Data Repair</button>
                    <button type="button" class="subnav-item danger" data-backup-target="danger"
                        onclick="navBackup('danger')"><i class="fa-solid fa-triangle-exclamation"></i> Factory Reset</button>
                </nav>

                <div class="subnav-content">

                    <!-- Backup & Restore -->
                    <div class="subnav-pane active" id="backup-pane-backup">
                        <div class="card">
                            <div class="card-header">
                                <h3 style="margin:0;"><i class="fa-solid fa-database"
                                        style="color:var(--accent); margin-right:0.5rem;"></i> Database Management</h3>
                            </div>
                            <div class="card-body">
                                <p style="color:var(--text-secondary); margin-bottom:1rem;">Backup your database tables or restore
                                    from a previous backup.</p>
                                <p style="color:var(--warning); font-size:0.85rem; margin-top:0; margin-bottom:1.5rem; display:flex; align-items:flex-start; gap:0.5rem;">
                                    <i class="fa-solid fa-triangle-exclamation" style="margin-top:0.15rem;"></i>
                                    <span>Backup files are plain, unencrypted SQL — they contain client names, emails, and invoice
                                        amounts in the clear. Store downloaded backups somewhere access-controlled, same as you
                                        would any other file with client PII in it.</span>
                                </p>

                                <div style="margin-bottom:2rem;">
                                    <div
                                        style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                                        <h4 style="margin:0;">Select Tables to Export</h4>
                                        <label
                                            style="font-size: 0.8rem; cursor: pointer; color: var(--text-secondary); display: flex; align-items: center; gap: 0.3rem;">
                                            <input type="checkbox" onchange="toggleOtherTables('backup', this.checked)"> Show all
                                            tables
                                        </label>
                                    </div>
                                    <div
                                        style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 0.5rem; background: var(--surface-hover); padding: 1rem; border-radius: 6px; border: 1px solid var(--border); margin-bottom: 1.5rem;">
                                        <?php foreach ($all_tables_info as $tName => $tRows): ?>
                                            <?php $isInvoxa = (strpos($tName, 'invoxa_') === 0); ?>
                                            <label class="backup-table-item <?= $isInvoxa ? 'invoxa-table' : 'other-table' ?>"
                                                style="<?= !$isInvoxa ? 'display:none;' : 'display:flex;' ?> align-items: center; gap: 0.5rem; font-size: 0.85rem; cursor: pointer; color: var(--text-primary);">
                                                <input type="checkbox" class="backup-table-checkbox"
                                                    value="<?= htmlspecialchars($tName) ?>" <?= $isInvoxa ? 'checked' : '' ?>>
                                                <?= htmlspecialchars($tName) ?> <span
                                                    style="color: var(--text-secondary); font-size: 0.75rem;">(<?= number_format($tRows) ?>)</span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                    <button class="btn primary" onclick="backupDatabase()"><i class="fa-solid fa-download"></i>
                                        Create Backup</button>
                                </div>

                                <div style="border-top:1px solid var(--border); padding-top:1.5rem; margin-bottom:1.5rem;">
                                    <h4 style="margin-top:0; margin-bottom:0.5rem;">Local Backup Retention</h4>
                                    <p style="color:var(--text-secondary); font-size:0.85rem; margin-top:0; margin-bottom:1rem;">
                                        After each new backup, automatically delete older ones beyond this count from
                                        <code>invoxa-backups/</code>. <strong>0 = keep every backup forever</strong>
                                        (today's default).</p>
                                    <div style="display:flex; align-items:center; gap:0.75rem;">
                                        <input type="number" id="localBackupRetentionCount" class="form-control" min="0"
                                            max="365" style="max-width:120px;"
                                            value="<?= htmlspecialchars($settings['local_backup_retention_count'] ?? '0') ?>">
                                        <button class="btn primary" id="saveBackupRetentionBtn"
                                            onclick="saveBackupRetention()"><i class="fa-solid fa-save"></i> Save</button>
                                    </div>
                                </div>

                                <div style="border-top:1px solid rgba(255,255,255,0.1); padding-top:1.5rem;">
                                    <h4 style="margin-top:0; margin-bottom:10px;">Restore Database</h4>
                                    <p style="color:var(--text-secondary); font-size:0.85rem; margin-top:0; margin-bottom:1rem;">
                                        Restore only works from backups in the list below — either created here, or a backup file
                                        exported (via Create Backup) from another Invoxa install, e.g. when migrating to a new
                                        server. Arbitrary SQL isn't accepted, only Invoxa's own backup file format.</p>
                                    <div style="display:flex; gap:0.75rem; margin-bottom:1rem; flex-wrap:wrap; align-items:center;">
                                        <select id="restoreBackupSelect" class="form-control" style="max-width:360px;"></select>
                                        <button class="btn" onclick="loadBackupList()" title="Refresh list"><i
                                                class="fa-solid fa-rotate"></i></button>
                                        <label class="btn" style="cursor:pointer; margin:0;"><i class="fa-solid fa-file-import"></i>
                                            Import Backup File
                                            <input type="file" id="importBackupFile" accept=".sql" style="display:none;"
                                                onchange="importBackup(this.files[0])"></label>
                                    </div>
                                    <div style="display:flex; gap:1rem;">
                                        <button class="btn" onclick="testRestore()"><i class="fa-solid fa-vial"></i> Test Restore
                                            (Dry Run)</button>
                                        <button class="btn" style="background:var(--danger); color:white; border:none;"
                                            onclick="confirmRestore()"><i class="fa-solid fa-upload"></i> Restore Selected
                                            Backup</button>
                                    </div>
                                </div>

                                <div style="border-top:1px solid rgba(255,255,255,0.1); margin-top:1.5rem; padding-top:1.5rem;">
                                    <h4 style="margin-top:0; margin-bottom:10px;"><i class="fa-solid fa-right-left"
                                            style="color:var(--accent); margin-right:0.4rem;"></i>Migrating to a New Server</h4>
                                    <p style="color:var(--text-secondary); font-size:0.85rem; margin:0;">
                                        On the <strong>old</strong> server: select all tables above and click <strong>Create
                                            Backup</strong>, then download the resulting <code>backup_YYYY-MM-DD.sql</code> file (see
                                        <button class="btn" style="padding:0.15rem 0.5rem; font-size:0.75rem; margin:0 0.15rem;"
                                            onclick="nav('docs', true); navDocs('install');">Installation Guide</button> for the full walkthrough).
                                        On the <strong>new</strong> server: run <code>docker compose up -d --build</code>, sign up
                                        for the fresh admin account, then use <strong>Import Backup File</strong> above to upload
                                        that same file and restore it.</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Demo Data -->
                    <div class="subnav-pane" id="backup-pane-demo">
                        <div class="card">
                            <div class="card-header">
                                <h3 style="margin:0; font-size: 1.1rem;"><i class="fa-solid fa-wand-magic-sparkles"
                                        style="color:var(--accent); margin-right:0.5rem;"></i>Demo Data</h3>
                            </div>
                            <div class="card-body">
                                <p style="color: var(--text-secondary); font-size: 0.9rem; margin-bottom: 1rem;">
                                    Populate this instance with a handful of sample clients, invoices, and quotes spread across
                                    the last several months — a quick way to see the dashboard charts, statistics, and invoice
                                    list actually filled in. Tagged as test data (<code>is_test</code>), so <strong>Hide Test
                                        Clients Globally</strong> in Settings hides it from real reporting.
                                </p>
                                <div style="display:flex; gap:0.75rem; flex-wrap:wrap;">
                                    <button class="btn primary" id="seedDemoBtn" onclick="seedDemoData()"><i
                                            class="fa-solid fa-wand-magic-sparkles"></i> Insert Dummy Data</button>
                                    <button class="btn" id="clearDemoBtn" onclick="clearDemoData()"><i
                                            class="fa-solid fa-broom"></i> Clear Dummy Data</button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Test Suite -->
                    <div class="subnav-pane" id="backup-pane-testsuite">
                        <div class="card">
                            <div class="card-header">
                                <h3 style="margin:0; font-size: 1.1rem;"><i class="fa-solid fa-vial"
                                        style="color:var(--accent); margin-right:0.5rem;"></i>Test Suite</h3>
                            </div>
                            <div class="card-body">
                                <p style="color: var(--text-secondary); font-size: 0.9rem; margin-bottom: 1rem;">
                                    Checks invoice math, TOTP, Stripe/PayPal amount conversion and webhook signature
                                    verification, and the payment ledger's actual database behavior (partial payments,
                                    duplicate-webhook idempotency, refunds). Every check that touches the database
                                    creates its own disposable client/invoice (never a real one, never Demo Data's)
                                    and deletes it again immediately after — nothing from a run is left behind, pass
                                    or fail. Does <strong>not</strong> call the real Stripe/PayPal/SMTP APIs — those
                                    need live credentials this can't assume exist, and a real API call isn't something
                                    a button click should cause.
                                </p>
                                <?php
                                $__testDefs = invoxaTestDefinitions($mysqli, $settings);
                                $__testGroups = array_values(array_unique(array_column($__testDefs, 'group')));
                                ?>
                                <div style="margin-bottom:0.75rem; display:flex; align-items:center; gap:0.75rem; flex-wrap:wrap;">
                                    <button class="btn primary" id="runTestSuiteBtn" onclick="runTestSuite()"><i
                                            class="fa-solid fa-play"></i> Run Selected</button>
                                    <button class="btn small" type="button" onclick="selectAllTests(true)">Select All</button>
                                    <button class="btn small" type="button" onclick="selectAllTests(false)">Select None</button>
                                    <div id="testSuiteSummary" style="font-size:0.9rem;"></div>
                                </div>
                                <div style="margin-bottom:1rem; display:flex; align-items:center; gap:0.4rem; flex-wrap:wrap;">
                                    <span style="color:var(--text-secondary); font-size:0.8rem;">Section:</span>
                                    <button type="button" class="pill-btn active" data-pill-group="__all__" onclick="selectAllTestsPill()">All</button>
                                    <?php foreach ($__testGroups as $__g): ?>
                                        <button type="button" class="pill-btn" data-pill-group="<?= htmlspecialchars($__g) ?>" onclick="selectTestGroupOnly('<?= htmlspecialchars(addslashes($__g)) ?>')"><?= htmlspecialchars($__g) ?></button>
                                    <?php endforeach; ?>
                                </div>
                                <div style="overflow-x:auto;">
                                    <table style="width:100%; border-collapse:collapse; font-size:0.85rem;">
                                        <thead>
                                            <tr style="text-align:left; color:var(--text-secondary); border-bottom:1px solid var(--border);">
                                                <th style="padding:0.4rem 0.5rem; width:1%;"></th>
                                                <th style="padding:0.4rem 0.5rem;">Category</th>
                                                <th style="padding:0.4rem 0.5rem;">Case <span style="font-weight:400; text-transform:none;">(hover for detail)</span></th>
                                                <th style="padding:0.4rem 0.5rem; text-align:right;">Status</th>
                                            </tr>
                                        </thead>
                                        <tbody id="testSuiteList">
                                            <?php $__lastGroup = null; $__firstGroup = true; foreach ($__testDefs as $__testName => $__test): ?>
                                                <?php if ($__test['group'] !== $__lastGroup): $__lastGroup = $__test['group']; ?>
                                                    <tr class="test-suite-group-row">
                                                        <td colspan="4" style="padding:0.6rem 0.5rem 0.35rem; <?= $__firstGroup ? '' : 'border-top:2px solid var(--border);' ?>">
                                                            <label style="cursor:pointer; display:flex; align-items:center; gap:0.5rem; font-weight:600; font-size:0.78rem; text-transform:uppercase; letter-spacing:0.03em; color:var(--accent);">
                                                                <input type="checkbox" class="test-suite-group-checkbox" data-group="<?= htmlspecialchars($__lastGroup) ?>" checked onclick="toggleTestGroup(this)">
                                                                <?= htmlspecialchars($__lastGroup) ?>
                                                            </label>
                                                        </td>
                                                    </tr>
                                                    <?php $__firstGroup = false; ?>
                                                <?php endif; ?>
                                                <tr class="test-suite-row" data-test-name="<?= htmlspecialchars($__testName) ?>" data-group="<?= htmlspecialchars($__test['group']) ?>" style="border-bottom:1px solid var(--border);">
                                                    <td style="padding:0.4rem 0.5rem 0.4rem 1.75rem;"><input type="checkbox" class="test-suite-checkbox" checked></td>
                                                    <td style="padding:0.4rem 0.5rem; color:var(--text-secondary); white-space:nowrap;"><?= htmlspecialchars($__test['category']) ?></td>
                                                    <td style="padding:0.4rem 0.5rem; cursor:help;" title="<?= htmlspecialchars($__test['description']) ?>"><?= htmlspecialchars($__test['label']) ?></td>
                                                    <td class="test-suite-status" style="padding:0.4rem 0.5rem; text-align:right; color:var(--text-secondary); white-space:nowrap;">Not run</td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Audit Log Retention -->
                    <div class="subnav-pane" id="backup-pane-audit">
                        <div class="card">
                            <div class="card-header">
                                <h3 style="margin:0; font-size: 1.1rem;"><i class="fa-solid fa-broom"
                                        style="color:var(--accent); margin-right:0.5rem;"></i>Audit Log Retention</h3>
                            </div>
                            <div class="card-body">
                                <p style="color: var(--text-secondary); font-size: 0.9rem; margin-bottom: 1rem;">
                                    Automatically deletes Audit Log entries older than the chosen period — checked
                                    whenever the Recurring Billing cron fires (Settings &gt; Billing), not
                                    on a schedule of its own. <strong>Off by default</strong> — entries are kept forever
                                    until you turn this on.</p>
                                <div class="form-group">
                                    <select id="auditRetentionSelect" class="form-control">
                                        <option value="0" <?= ($settings['audit_log_retention_days'] ?? '0') === '0' ? 'selected' : '' ?>>Off — keep forever</option>
                                        <option value="30" <?= ($settings['audit_log_retention_days'] ?? '0') === '30' ? 'selected' : '' ?>>Keep last 1 month</option>
                                        <option value="180" <?= ($settings['audit_log_retention_days'] ?? '0') === '180' ? 'selected' : '' ?>>Keep last 6 months</option>
                                        <option value="365" <?= ($settings['audit_log_retention_days'] ?? '0') === '365' ? 'selected' : '' ?>>Keep last 1 year</option>
                                    </select>
                                </div>
                                <button class="btn primary" id="saveAuditRetentionBtn" onclick="saveAuditRetention()"><i
                                        class="fa-solid fa-save"></i> Save</button>
                            </div>
                        </div>
                    </div>

                    <!-- Bulk Actions -->
                    <div class="subnav-pane" id="backup-pane-bulk">
                        <div class="card">
                            <div class="card-header">
                                <h3 style="margin:0; font-size: 1.1rem;"><i class="fa-solid fa-check-double"
                                        style="color:var(--accent); margin-right:0.5rem;"></i>Bulk Mark Paid</h3>
                            </div>
                            <div class="card-body">
                                <p style="color: var(--text-secondary); font-size: 0.9rem; margin-bottom: 1rem;">
                                    Marks every unpaid invoice for one client as paid in one go, each using its own
                                    invoice amount. Useful for catching up a client's history in bulk rather than
                                    marking invoices paid one at a time. <strong>This cannot be undone.</strong>
                                </p>
                                <div style="display:flex; align-items:center; gap:0.75rem; flex-wrap:wrap;">
                                    <select id="bulkClientSelect" class="form-control" style="max-width:280px;">
                                        <option value="">— select client —</option>
                                        <?php foreach ($clients as $c): ?>
                                            <option value="<?= htmlspecialchars($c['client_key']) ?>">
                                                <?= htmlspecialchars($c['client_name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button id="bulkMarkPaidBtn" class="btn success" onclick="bulkMarkPaid()">
                                        <i class="fa-solid fa-check-double"></i> Mark All Paid
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Offsite Push -->
                    <div class="subnav-pane" id="backup-pane-offsite">
                        <div class="card">
                            <div class="card-header">
                                <h3 style="margin:0; font-size: 1.1rem;"><i class="fa-solid fa-cloud-arrow-up"
                                        style="color:var(--accent); margin-right:0.5rem;"></i>Offsite Push</h3>
                            </div>
                            <div class="card-body">
                                <p style="color: var(--text-secondary); font-size: 0.9rem; margin-bottom: 1rem;">
                                    Copies the SQL backups from <strong>Backup &amp; Restore</strong> to a remote
                                    location (S3, B2, SFTP, another server, etc.) using
                                    <a href="https://rclone.org/" target="_blank" rel="noopener">rclone</a>. This
                                    panel only stores the <em>on/off switch</em> and which remote to use &mdash; it
                                    does not talk to the remote itself. A scheduled job on the <code>cron</code>
                                    container reads this setting, and if enabled, runs
                                    <code>rclone copy</code> using a remote of this name defined in that
                                    container's own <code>rclone.conf</code>. The actual storage credentials
                                    (access keys, host, etc.) are configured there, on disk in the cron container
                                    &mdash; never entered here and never stored in this app's database &mdash; so
                                    nothing this web app touches or serves can leak your offsite storage
                                    credentials. Setting up <code>rclone.conf</code> and the scheduled job itself is
                                    a one-time infrastructure step outside this app; this toggle just tells that
                                    job whether to run and where to push to.
                                </p>
                                <div class="form-group">
                                    <label class="form-label" style="display:flex; align-items:center; gap:0.5rem; cursor:pointer;">
                                        <input type="checkbox" id="offsiteBackupEnabled"
                                            <?= ($settings['offsite_backup_enabled'] ?? '0') === '1' ? 'checked' : '' ?>>
                                        Enable offsite push
                                    </label>
                                </div>
                                <div class="form-group">
                                    <label class="form-label" style="font-size:0.8rem; color:var(--text-secondary);">Rclone
                                        Remote Name</label>
                                    <input type="text" id="offsiteRemoteName" class="form-control"
                                        placeholder="e.g. s3-offsite"
                                        value="<?= htmlspecialchars($settings['offsite_remote_name'] ?? '') ?>">
                                    <p style="color:var(--text-secondary); font-size:0.75rem; margin-top:0.3rem;">Must
                                        match a remote already defined in the cron container's
                                        <code>rclone.conf</code>.</p>
                                </div>
                                <div class="form-group">
                                    <label class="form-label" style="font-size:0.8rem; color:var(--text-secondary);">Destination
                                        Path</label>
                                    <input type="text" id="offsiteRemotePath" class="form-control"
                                        placeholder="e.g. invoxa-backups/"
                                        value="<?= htmlspecialchars($settings['offsite_remote_path'] ?? '') ?>">
                                </div>
                                <div class="form-group">
                                    <label class="form-label" style="font-size:0.8rem; color:var(--text-secondary);">Keep
                                        last N backups offsite</label>
                                    <input type="number" id="offsiteRetentionCount" class="form-control" min="1"
                                        max="365" style="max-width:120px;"
                                        value="<?= htmlspecialchars($settings['offsite_retention_count'] ?? '14') ?>">
                                </div>
                                <button class="btn primary" id="saveOffsiteBackupBtn" onclick="saveOffsiteBackup()"><i
                                        class="fa-solid fa-save"></i> Save</button>

                                <div style="margin-top:1.5rem; padding-top:1rem; border-top:1px solid var(--border);">
                                    <h4 style="margin:0 0 0.5rem;">Status</h4>
                                    <?php if ($offsite_status): ?>
                                        <p style="color:var(--text-secondary); font-size:0.85rem; margin:0;">
                                            Last push: <strong><?= htmlspecialchars($offsite_status['last_attempt'] ?? 'unknown') ?></strong>
                                            &mdash;
                                            <?php if (($offsite_status['success'] ?? false)): ?>
                                                <span style="color:var(--success);">succeeded</span>
                                            <?php else: ?>
                                                <span style="color:var(--danger);">failed<?= !empty($offsite_status['error']) ? ': ' . htmlspecialchars($offsite_status['error']) : '' ?></span>
                                            <?php endif; ?>
                                        </p>
                                    <?php else: ?>
                                        <p style="color:var(--text-secondary); font-size:0.85rem; margin:0;">No
                                            offsite push has reported in yet. This updates once the cron-side job
                                            has run at least once.</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Data Repair -->
                    <div class="subnav-pane" id="backup-pane-repair">
                        <div class="card" style="border-top: 3px solid #ef4444;">
                            <div class="card-header">
                                <h3 style="margin:0; font-size: 1.1rem;"><i class="fa-solid fa-wrench"
                                        style="color:#ef4444; margin-right:0.5rem;"></i>Data Repair</h3>
                            </div>
                            <div class="card-body">
                                <p style="color: var(--text-secondary); font-size: 0.9rem; margin-bottom: 1rem;">Fix
                                    historical
                                    <code>paid_at</code> dates that were bulk-set incorrectly. This resets
                                    <strong>all paid invoices</strong> so their <code>paid_at</code> becomes the last day of
                                    their invoice month &mdash; giving a more accurate Payment Velocity figure.
                                </p>
                                <button class="btn" id="fixPaidDatesBtn"
                                    style="background: var(--danger); color:white; border:none;"
                                    onclick="fixPaidDates()"><i class="fa-solid fa-calendar-xmark"></i> Reset paid_at to
                                    End-of-Month</button>
                            </div>
                        </div>
                    </div>

                    <!-- Factory Reset -->
                    <div class="subnav-pane" id="backup-pane-danger">
                        <div class="card" style="border-top: 3px solid #ef4444;">
                            <div class="card-header">
                                <h3 style="margin:0; font-size: 1.1rem;"><i class="fa-solid fa-triangle-exclamation"
                                        style="color:#ef4444; margin-right:0.5rem;"></i>Factory Reset</h3>
                            </div>
                            <div class="card-body">
                                <p style="color: var(--text-secondary); font-size: 0.9rem; margin-bottom: 1rem;">
                                    Permanently erases <strong>everything</strong>: every client, invoice, quote, note, and setting
                                    (brand, currency, license key), every generated invoice file, every stored backup, and the admin
                                    account itself. You'll land back on the signup screen, exactly like a fresh install. This cannot
                                    be undone — take a backup first if there's any chance you'll want this data again.
                                </p>
                                <button class="btn" style="background:var(--danger); color:white; border:none;"
                                    onclick="openFactoryReset()"><i class="fa-solid fa-bomb"></i> Factory Reset…</button>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
            </div>
        </div>

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
                        <div class="form-group"><label class="form-label">Rate (<?= htmlspecialchars($settings['currency'] ?? 'USD') ?> per billing period)</label><input type="number"
                                id="clientRate" class="form-control" step="0.01"></div>
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
                        <div id="clientPortalNoLinkWrap">
                            <div class="form-group" style="max-width:220px;">
                                <label class="form-label">Expires</label>
                                <select id="clientPortalExpiry" class="form-control" <?= $licenseValid ? '' : 'disabled' ?>>
                                    <option value="never">Never</option>
                                    <option value="30">30 days</option>
                                    <option value="90" selected>90 days</option>
                                    <option value="365">1 year</option>
                                </select>
                            </div>
                            <button class="btn" id="generatePortalLinkBtn" type="button" onclick="generatePortalLink()"
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
                    <div class="form-group"><label class="form-label">Receipts <span
                                style="font-weight:400; color:var(--text-secondary);">(optional — a scanned receipt plus a card statement excerpt, for example)</span></label>
                        <div id="expenseReceiptsList" style="margin-bottom:0.5rem;"></div>
                        <input type="file" id="expenseReceiptFiles" class="form-control" accept="image/*,.pdf" multiple
                            style="padding:0.5rem;">
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
                    <div class="form-group"><label class="form-label">This Payment (<?= htmlspecialchars($settings['currency'] ?? 'USD') ?>)</label><input type="number"
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
                        backup, and the admin account. There is no undo.</p>
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

        <?php $__ev = $mysqli->query("SELECT email, email_verified_at FROM invoxa_users LIMIT 1")->fetch_assoc(); ?>
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
                if (target === 'revenue' && window.__revenueBreakdownData && document.getElementById('revenueBreakdownChart')) {
                    __statsChartsInit.revenue = true;
                    const d = window.__revenueBreakdownData;
                    new Chart(document.getElementById('revenueBreakdownChart').getContext('2d'), {
                        type: 'bar',
                        data: { labels: ['Invoiced', 'Paid', 'Outstanding'], datasets: [{ data: [d.invoiced, d.paid, d.outstanding], backgroundColor: ['#3b82f6', '#10b981', '#f59e0b'] }] },
                        options: { responsive: true, maintainAspectRatio: false, indexAxis: 'y', plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true } } }
                    });
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
                if (target === 'system' && window.__emailHealthData && document.getElementById('emailHealthChart')) {
                    __statsChartsInit.system = true;
                    const d = window.__emailHealthData;
                    new Chart(document.getElementById('emailHealthChart').getContext('2d'), {
                        type: 'doughnut',
                        data: { labels: ['Sent', 'Failed'], datasets: [{ data: [d.sent, d.failed], backgroundColor: ['#10b981', '#ef4444'] }] },
                        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, cutout: '65%' }
                    });
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
                document.getElementById('expenseReceiptFiles').value = '';
                document.getElementById('expenseReceiptsList').innerHTML = '';
                document.getElementById('expenseModal').classList.add('active');
                if (e && e.id) loadExpenseReceipts(e.id);
            }
            async function loadExpenseReceipts(expenseId) {
                const list = document.getElementById('expenseReceiptsList');
                list.innerHTML = '<p style="color:var(--text-secondary); font-size:0.85rem; margin:0;">Loading…</p>';
                const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'get_expense_receipts', expense_id: expenseId }) });
                const json = await res.json();
                if (!json.success || !json.receipts.length) { list.innerHTML = ''; return; }
                list.innerHTML = json.receipts.map(r => `
                    <div style="display:flex; align-items:center; justify-content:space-between; gap:0.75rem; padding:0.4rem 0; border-bottom:1px solid var(--border);">
                        <a href="${r.url}" target="_blank" style="color:var(--text-primary); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; font-size:0.85rem;"><i class="fa-solid fa-paperclip"></i> ${r.filename}</a>
                        <div style="display:flex; align-items:center; gap:0.5rem; white-space:nowrap;">
                            <span style="color:var(--text-secondary); font-size:0.75rem;">${_formatFileSize(r.file_size)}</span>
                            <button type="button" class="btn small danger" onclick="deleteExpenseReceipt(${r.id}, ${expenseId})"><i class="fa-solid fa-trash"></i></button>
                        </div>
                    </div>
                `).join('');
            }
            async function deleteExpenseReceipt(id, expenseId) {
                if (!confirm('Delete this receipt?')) return;
                const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'delete_expense_receipt', id: id }) });
                const json = await res.json();
                if (json.success) { showToast('Receipt deleted!'); await loadExpenseReceipts(expenseId); refreshTable('expenses'); }
                else showToast(json.error || 'Failed to delete', true);
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
                const receiptFiles = document.getElementById('expenseReceiptFiles').files;
                for (const file of receiptFiles) {
                    const rFormData = new FormData();
                    rFormData.append('action', 'upload_expense_receipt');
                    rFormData.append('expense_id', json.id);
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
            async function bulkMarkPaid() {
                const clientKey = document.getElementById('bulkClientSelect').value;
                if (!clientKey) return showToast('Please select a client first', true);
                const clientName = document.getElementById('bulkClientSelect').selectedOptions[0].textContent;
                if (!confirm(`Mark ALL unpaid invoices for "${clientName}" as paid using each invoice's own amount?\n\nThis cannot be undone.`)) return;
                const btn = document.getElementById('bulkMarkPaidBtn'); btn.disabled = true;
                btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Processing...';
                const data = new URLSearchParams({ action: 'bulk_mark_paid', client_key: clientKey });
                const res = await fetch('', { method: 'POST', body: data }); const json = await res.json();
                if (json.success) { showToast(`✓ ${json.updated} invoice(s) marked as paid!`); setTimeout(() => window.location.reload(), 1500); }
                else { showToast(json.error || 'Failed', true); btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-check-double"></i> Mark All Unpaid → Paid'; }
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
                if (!confirm('Deactivate your license? The six paid features (payment collection, recurring billing, Client Portal, external API, Reporting & Statistics, and Powered-by removal) will lock again until you activate a key.')) return;
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
