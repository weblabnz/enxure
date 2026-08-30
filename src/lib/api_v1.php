<?php
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
    echo '<!DOCTYPE html><html lang="en"><head><script>document.documentElement.setAttribute("data-theme", localStorage.getItem("invoxa_theme") || "light");</script><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Invoxa' . (INSTANCE_LABEL ? ' (' . htmlspecialchars(INSTANCE_LABEL) . ')' : '') . ' - ' . (['signup' => 'Setup', 'totp' => 'Two-Factor', 'forgot' => 'Recover Access', 'reset' => 'Reset Password'][$authMode] ?? 'Login') . '</title><link rel="icon" type="image/svg+xml" href="assets/img/invoxa-mark.svg"><link rel="alternate icon" href="assets/img/favicon.ico"><link rel="apple-touch-icon" href="assets/img/apple-touch-icon.png"><link rel="manifest" href="manifest.webmanifest"><meta name="theme-color" content="#0a0f1c"><style>*{box-sizing:border-box;}html{overflow:hidden;height:100%;}body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Inter,Roboto,sans-serif;background:radial-gradient(1100px 500px at 15% -10%, rgba(79,124,255,0.2), transparent 60%), radial-gradient(rgba(255,255,255,0.08) 1px, transparent 1px), #0a0f1c;background-size:auto,24px 24px,auto;color:#f7f9fc;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;overflow:hidden;position:relative;}body::before,body::after{content:"";position:absolute;border-radius:50%;filter:blur(50px);pointer-events:none;z-index:0;}body::before{width:600px;height:600px;top:-80px;left:-80px;background:radial-gradient(circle at 30% 30%, rgba(79,124,255,0.8), transparent 70%);animation:invoxaDriftA 22s ease-in-out infinite alternate;}body::after{width:540px;height:540px;bottom:-100px;right:-100px;background:radial-gradient(circle at 70% 70%, rgba(29,78,216,0.7), transparent 70%);animation:invoxaDriftB 26s ease-in-out infinite alternate;}@keyframes invoxaDriftA{0%{transform:translate(0,0);}100%{transform:translate(50px,35px);}}@keyframes invoxaDriftB{0%{transform:translate(0,0);}100%{transform:translate(-45px,-30px);}}@keyframes invoxaCardIn{from{opacity:0;transform:translateY(14px) scale(.97);}to{opacity:1;transform:translateY(0) scale(1);}}@keyframes invoxaFloat{0%,100%{transform:translateY(0);}50%{transform:translateY(-12px);}}@keyframes invoxaGlow{0%,100%{box-shadow:0 8px 24px -8px rgba(79,124,255,0.5);}50%{box-shadow:0 14px 34px -6px rgba(79,124,255,0.8);}}@media (prefers-reduced-motion: reduce){body::before,body::after,.auth-box,.auth-logo img{animation:none!important;}}@media (max-width:640px){.auth-box{max-width:none;width:100%;height:100vh;min-height:100vh;border-radius:0;box-shadow:none;display:flex;flex-direction:column;justify-content:center;overflow-y:auto;}}.auth-box{position:relative;z-index:1;overflow:hidden;background:#131b2e;padding:2.75rem 2.5rem;border-radius:18px;width:100%;max-width:400px;border:1px solid rgba(255,255,255,0.08);box-shadow:0 24px 48px -16px rgba(0,0,0,0.55);animation:invoxaCardIn .5s ease both;}.auth-box::before{content:"";position:absolute;top:0;left:0;right:0;height:5px;background:linear-gradient(90deg,#5b8cff,#1d4ed8);box-shadow:0 0 20px 2px rgba(79,124,255,0.6);}.auth-logo{display:flex;justify-content:center;margin-bottom:1.25rem;}.auth-logo img{width:52px;height:52px;border-radius:14px;box-shadow:0 8px 24px -8px rgba(79,124,255,0.5);animation:invoxaFloat 2.6s ease-in-out infinite, invoxaGlow 2.6s ease-in-out infinite;}h2{margin-top:0;text-align:center;margin-bottom:1.75rem;font-weight:700;letter-spacing:-0.01em;font-size:1.35rem;}.form-group{margin-bottom:1.25rem;}label{display:block;margin-bottom:0.5rem;color:#90a0bb;font-size:0.85rem;font-weight:600;}input{width:100%;padding:0.75rem 0.9rem;background:#1a2439;border:1px solid rgba(255,255,255,0.08);color:#f7f9fc;border-radius:10px;box-sizing:border-box;font-family:inherit;font-size:16px;transition:border-color .15s ease, box-shadow .15s ease;}input:focus{outline:none;border-color:#4f7cff;box-shadow:0 0 0 3px rgba(79,124,255,0.15);}button{width:100%;padding:0.8rem;background:#4f7cff;border:none;color:white;border-radius:10px;font-weight:600;cursor:pointer;margin-top:0.5rem;font-family:inherit;font-size:0.95rem;transition:background 0.15s ease, transform .1s ease;box-shadow:0 4px 14px -4px rgba(79,124,255,0.5);}button:hover{background:#3d63e0;}button:active{transform:translateY(1px);}.error{color:#f5455c;margin-bottom:1.25rem;text-align:center;font-size:0.875rem;background:rgba(245,69,92,0.1);padding:0.6rem;border-radius:8px;}.doc-links{display:flex;justify-content:center;gap:1.25rem;margin-top:1.75rem;padding-top:1.25rem;border-top:1px solid rgba(255,255,255,0.08);}.doc-links a{color:#90a0bb;font-size:0.8rem;text-decoration:none;font-weight:500;background:none;border:none;padding:0;cursor:pointer;width:auto;margin:0;box-shadow:none;}.doc-links a:hover{color:#4f7cff;}.doc-modal-overlay{position:fixed;inset:0;background:rgba(5,8,16,0.65);backdrop-filter:blur(6px);display:none;align-items:center;justify-content:center;z-index:1000;}.doc-modal-overlay.active{display:flex;}.doc-modal{background:#131b2e;border:1px solid rgba(255,255,255,0.08);border-radius:16px;width:90%;max-width:640px;max-height:78vh;display:flex;flex-direction:column;box-shadow:0 24px 48px -16px rgba(0,0,0,0.55);}.doc-modal-header{padding:1.1rem 1.25rem;border-bottom:1px solid rgba(255,255,255,0.08);display:flex;justify-content:space-between;align-items:center;font-weight:700;}.doc-modal-actions{display:flex;gap:0.5rem;align-items:center;}.doc-modal button{width:auto;margin:0;box-shadow:none;font-family:inherit;}.doc-tab-btn{padding:0.4rem 0.7rem;font-size:0.75rem;background:#1a2439;border:1px solid rgba(255,255,255,0.08);border-radius:8px;color:#f7f9fc;}.doc-tab-btn:hover{background:#212d47;}.doc-close-btn{padding:0.3rem 0.6rem;background:transparent;font-size:1.1rem;line-height:1;border:none;color:#90a0bb;}.doc-close-btn:hover{background:transparent;color:#f5455c;}.doc-modal-body{padding:1.25rem 1.5rem;overflow-y:auto;}.doc-modal-body .doc-content h1,.doc-modal-body .doc-content h2,.doc-modal-body .doc-content h3,.doc-modal-body .doc-content h4{color:#f7f9fc;margin:1.25rem 0 0.6rem;line-height:1.3;}.doc-modal-body .doc-content h1:first-child,.doc-modal-body .doc-content h2:first-child{margin-top:0;}.doc-modal-body .doc-content h1{font-size:1.25rem;}.doc-modal-body .doc-content h2{font-size:1.05rem;border-bottom:1px solid rgba(255,255,255,0.08);padding-bottom:0.35rem;}.doc-modal-body .doc-content h3{font-size:0.95rem;}.doc-modal-body .doc-content p,.doc-modal-body .doc-content li{color:#90a0bb;font-size:0.88rem;line-height:1.6;}.doc-modal-body .doc-content ul,.doc-modal-body .doc-content ol{margin:0.5rem 0 0.75rem;padding-left:1.3rem;}.doc-modal-body .doc-content strong{color:#f7f9fc;}.doc-modal-body .doc-content a{color:#4f7cff;text-decoration:none;}.doc-modal-body .doc-content a:hover{text-decoration:underline;}.doc-modal-body .doc-content code{background:#1a2439;border:1px solid rgba(255,255,255,0.08);border-radius:4px;padding:0.1rem 0.4rem;font-size:0.8rem;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;color:#f7f9fc;}.doc-modal-body .doc-content pre{background:#1a2439;border:1px solid rgba(255,255,255,0.08);border-radius:10px;padding:0.8rem 1rem;overflow-x:auto;margin:0.75rem 0;}.doc-modal-body .doc-content pre code{background:none;border:none;padding:0;}.doc-modal-body .doc-content table{width:100%;border-collapse:collapse;margin:0.75rem 0 1.1rem;font-size:0.82rem;}.doc-modal-body .doc-content th,.doc-modal-body .doc-content td{border:1px solid rgba(255,255,255,0.08);padding:0.45rem 0.6rem;text-align:left;}.doc-modal-body .doc-content th{background:#1a2439;color:#f7f9fc;}.doc-modal-body .doc-content td{color:#90a0bb;}.doc-modal-body .doc-content img{max-width:70%;height:auto;border-radius:8px;border:1px solid rgba(255,255,255,0.08);}[data-theme="light"] body{background:radial-gradient(1100px 500px at 15% -10%, rgba(61,99,224,0.16), transparent 60%), radial-gradient(rgba(15,23,42,0.06) 1px, transparent 1px), #e8ecf4;background-size:auto,24px 24px,auto;color:#0f172a;}[data-theme="light"] body::before{background:radial-gradient(circle at 30% 30%, rgba(61,99,224,0.6), transparent 70%);}[data-theme="light"] body::after{background:radial-gradient(circle at 70% 70%, rgba(29,78,216,0.5), transparent 70%);}[data-theme="light"] .auth-box{background:#ffffff;border-color:rgba(15,23,42,0.08);box-shadow:0 24px 48px -16px rgba(15,23,42,0.12);}[data-theme="light"] label{color:#5c6b85;}[data-theme="light"] input{background:#f8f9fd;border-color:rgba(15,23,42,0.08);color:#0f172a;}[data-theme="light"] input:focus{border-color:#3d63e0;box-shadow:0 0 0 3px rgba(61,99,224,0.15);}[data-theme="light"] button{background:#3d63e0;box-shadow:0 4px 14px -4px rgba(61,99,224,0.5);}[data-theme="light"] button:hover{background:#2e4fc0;}[data-theme="light"] .error{color:#dc2626;background:rgba(220,38,38,0.08);}[data-theme="light"] .doc-links{border-top-color:rgba(15,23,42,0.08);}[data-theme="light"] .doc-links a{color:#5c6b85;}[data-theme="light"] .doc-links a:hover{color:#3d63e0;}[data-theme="light"] .doc-modal-overlay{background:rgba(15,23,42,0.45);}[data-theme="light"] .doc-modal{background:#ffffff;border-color:rgba(15,23,42,0.08);box-shadow:0 24px 48px -16px rgba(15,23,42,0.12);}[data-theme="light"] .doc-modal-header{border-bottom-color:rgba(15,23,42,0.08);}[data-theme="light"] .doc-tab-btn{background:#f8f9fd;border-color:rgba(15,23,42,0.08);color:#0f172a;}[data-theme="light"] .doc-tab-btn:hover{background:#eef1f8;}[data-theme="light"] .doc-close-btn{color:#5c6b85;}[data-theme="light"] .doc-close-btn:hover{color:#dc2626;}[data-theme="light"] .doc-modal-body .doc-content h1,[data-theme="light"] .doc-modal-body .doc-content h2,[data-theme="light"] .doc-modal-body .doc-content h3,[data-theme="light"] .doc-modal-body .doc-content h4{color:#0f172a;}[data-theme="light"] .doc-modal-body .doc-content h2{border-bottom-color:rgba(15,23,42,0.08);}[data-theme="light"] .doc-modal-body .doc-content p,[data-theme="light"] .doc-modal-body .doc-content li{color:#5c6b85;}[data-theme="light"] .doc-modal-body .doc-content strong{color:#0f172a;}[data-theme="light"] .doc-modal-body .doc-content a{color:#3d63e0;}[data-theme="light"] .doc-modal-body .doc-content code{background:#f8f9fd;border-color:rgba(15,23,42,0.08);color:#0f172a;}[data-theme="light"] .doc-modal-body .doc-content pre{background:#f8f9fd;border-color:rgba(15,23,42,0.08);}[data-theme="light"] .doc-modal-body .doc-content th,[data-theme="light"] .doc-modal-body .doc-content td{border-color:rgba(15,23,42,0.08);}[data-theme="light"] .doc-modal-body .doc-content th{background:#f8f9fd;color:#0f172a;}[data-theme="light"] .doc-modal-body .doc-content td{color:#5c6b85;}</style></head><body><div class="auth-box"><div class="auth-logo"><img src="assets/img/invoxa-mark.svg" width="52" height="52" alt=""></div><h2>Invoxa ' . (['signup' => 'Setup', 'totp' => 'Two-Factor Authentication', 'forgot' => 'Recover Access', 'reset' => 'Reset Password'][$authMode] ?? 'Login') . '</h2>';
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
