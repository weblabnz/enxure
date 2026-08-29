<?php
// ── Auth System ──────────────────────────────────────────────────────────────
// Defensive fallback only — sql/01-schema.sql is the canonical source of these tables.
$mysqli->query("CREATE TABLE IF NOT EXISTS invoxa_users (id INT AUTO_INCREMENT PRIMARY KEY, username VARCHAR(255) NOT NULL UNIQUE, email VARCHAR(255) DEFAULT NULL, password_hash VARCHAR(255) NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
$mysqli->query("CREATE TABLE IF NOT EXISTS invoxa_settings (setting_key VARCHAR(50) PRIMARY KEY, setting_value TEXT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
// Expenses (accounts payable) — same defensive-fallback reasoning as above;
// sql/01-schema.sql is still canonical for a fresh install.
$mysqli->query("CREATE TABLE IF NOT EXISTS invoxa_expenses (id INT AUTO_INCREMENT PRIMARY KEY, expense_date DATE NOT NULL, vendor VARCHAR(150) NOT NULL DEFAULT '', category VARCHAR(50) NOT NULL DEFAULT 'other', amount DECIMAL(10,2) NOT NULL DEFAULT 0.00, description TEXT, receipt_path VARCHAR(500) DEFAULT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, INDEX idx_expense_date (expense_date), INDEX idx_category (category)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
// Expense attachments — one row per uploaded file (an expense can have more
// than one of each), files live on disk under RECEIPTS_DIR/<expense_id>/.
// doc_type separates the Add Expense modal's two upload slots — Invoice (the
// vendor's bill) and Receipt (proof of payment, the only one Receipt OCR
// reads). Superseded expenses.receipt_path (single file, kept only for old
// rows, always doc_type='receipt') below.
$mysqli->query("CREATE TABLE IF NOT EXISTS invoxa_expense_receipts (id INT AUTO_INCREMENT PRIMARY KEY, expense_id INT NOT NULL, filename VARCHAR(255) NOT NULL, stored_path VARCHAR(500) NOT NULL, file_size INT NOT NULL DEFAULT 0, uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP, INDEX idx_expense_id (expense_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
$mysqli->query("INSERT INTO invoxa_expense_receipts (expense_id, filename, stored_path, file_size)
    SELECT e.id, e.receipt_path, e.receipt_path, 0 FROM invoxa_expenses e
    WHERE e.receipt_path IS NOT NULL AND e.receipt_path != ''
    AND NOT EXISTS (SELECT 1 FROM invoxa_expense_receipts r WHERE r.expense_id = e.id AND r.stored_path = e.receipt_path)");
// Same idea for installs that predate splitting the Add Expense upload into
// separate Invoice/Receipt slots — every file uploaded before this existed
// was under the old single "Receipts" field, so it defaults to 'receipt'.
$hasDocTypeCol = $mysqli->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invoxa_expense_receipts' AND COLUMN_NAME = 'doc_type'")->num_rows > 0;
if (!$hasDocTypeCol) {
    $mysqli->query("ALTER TABLE invoxa_expense_receipts ADD COLUMN doc_type ENUM('invoice','receipt') NOT NULL DEFAULT 'receipt' AFTER file_size");
}
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
// Multi-user (Settings > Users, a paid feature for account #2 onward) — every
// pre-existing install has exactly one account, and DEFAULT 'admin' is
// correct for it (it's the one that went through the signup screen).
$hasRoleCol = $mysqli->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invoxa_users' AND COLUMN_NAME = 'role'")->num_rows > 0;
if (!$hasRoleCol) {
    $mysqli->query("ALTER TABLE invoxa_users ADD COLUMN role ENUM('admin','member') NOT NULL DEFAULT 'admin' AFTER email");
}
$hasActionUserCol = $mysqli->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invoxa_actions' AND COLUMN_NAME = 'performed_by_user_id'")->num_rows > 0;
if (!$hasActionUserCol) {
    $mysqli->query("ALTER TABLE invoxa_actions ADD COLUMN performed_by_user_id INT NULL AFTER performed_at, ADD COLUMN performed_by_username VARCHAR(190) NULL AFTER performed_by_user_id, ADD INDEX idx_performed_by_user_id (performed_by_user_id)");
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
$hasClientCurrencyCol = $mysqli->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invoxa_clients' AND COLUMN_NAME = 'currency'")->num_rows > 0;
if (!$hasClientCurrencyCol) {
    $mysqli->query("ALTER TABLE invoxa_clients ADD COLUMN currency VARCHAR(3) NOT NULL DEFAULT '' AFTER tax_rate");
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
$hasInvoiceCurrencyCol = $mysqli->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invoxa_invoices' AND COLUMN_NAME = 'currency'")->num_rows > 0;
if (!$hasInvoiceCurrencyCol) {
    $mysqli->query("ALTER TABLE invoxa_invoices ADD COLUMN currency VARCHAR(3) NOT NULL DEFAULT '' AFTER amount");
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
            $_SESSION['invoxa_user_id'] = (int) $mysqli->insert_id;
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
                    $_SESSION['invoxa_user_id'] = (int) $row['id'];
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
                $_SESSION['invoxa_user_id'] = (int) $row['id'];
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
                $_SESSION['invoxa_user_id'] = (int) $row['id'];
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

// The logged-in user's id/role — every "my account" or "am I allowed to do
// this" check below is scoped to this, instead of the pre-multi-user
// assumption that invoxa_users always has exactly one row.
$currentUserId = 0;
$currentUserRole = 'admin';
$currentUsername = null;
if ($isAuth) {
    $currentUserId = (int) ($_SESSION['invoxa_user_id'] ?? 0);
    if ($currentUserId > 0) {
        $__curUserRow = $mysqli->query("SELECT role, username FROM invoxa_users WHERE id = " . $currentUserId)->fetch_assoc();
        $currentUserRole = $__curUserRow['role'] ?? 'admin';
        $currentUsername = $__curUserRow['username'] ?? null;
    } elseif (isset($_SESSION['invoxa_username'])) {
        // A session created before multi-user existed never stored an id —
        // resolve and cache it once instead of forcing a re-login.
        $legacyRow = $mysqli->query("SELECT id, role FROM invoxa_users WHERE username = '" . $mysqli->real_escape_string($_SESSION['invoxa_username']) . "'")->fetch_assoc();
        if ($legacyRow) {
            $currentUserId = (int) $legacyRow['id'];
            $currentUserRole = $legacyRow['role'];
            $currentUsername = $_SESSION['invoxa_username'];
            $_SESSION['invoxa_user_id'] = $currentUserId;
        }
    }
}
$isAdmin = $currentUserRole === 'admin';

$__actorUserId = $currentUserId > 0 ? $currentUserId : null;
$__actorUsername = $currentUsername;

function invoxaLogAction($mysqli, $invoiceId, string $invoiceNumber, string $actionType, string $notes = ''): void
{
    global $__actorUserId, $__actorUsername;
    $stmt = $mysqli->prepare("INSERT INTO invoxa_actions (invoice_id, invoice_number, action_type, notes, performed_by_user_id, performed_by_username) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param('isssis', $invoiceId, $invoiceNumber, $actionType, $notes, $__actorUserId, $__actorUsername);
    $stmt->execute();
}

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
require_once __DIR__ . '/license.php';

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

