        <!-- SETTINGS -->
        <div id="sec-settings" class="section">
            <h2 class="page-title">Settings</h2>
            <div class="section-scroll">
            <div class="subnav-layout">

                <nav class="subnav">
                    <?php if ($isAdmin): ?>
                    <button type="button" class="subnav-item active" data-settings-target="general"
                        onclick="navSettings('general')"><i class="fa-solid fa-sliders"></i> General</button>
                    <?php endif; ?>
                    <button type="button" class="subnav-item<?= $isAdmin ? '' : ' active' ?>" data-settings-target="account"
                        onclick="navSettings('account')"><i class="fa-solid fa-lock"></i> Account</button>
                    <?php if ($isAdmin): ?>
                    <button type="button" class="subnav-item" data-settings-target="branding"
                        onclick="navSettings('branding')"><i class="fa-solid fa-paint-roller"></i> Branding</button>
                    <button type="button" class="subnav-item" data-settings-target="email"
                        onclick="navSettings('email')"><i class="fa-solid fa-envelope"></i> Email
                        <?php $__mailSink = getenv('SMTP_HOST') === 'mailpit'; $__smtpConfigured = trim((string) getenv('SMTP_HOST')) !== ''; ?>
                        <span style="margin-left:auto; display:inline-flex; align-items:center;">
                            <span class="subnav-dot <?= ($__smtpConfigured && !$__mailSink) ? 'on' : 'off' ?>" style="margin-left:0;"
                                title="<?= $__mailSink ? 'Mail sink active — outgoing mail is caught, not really sent' : ($__smtpConfigured ? 'Real SMTP active' : 'SMTP not configured — invoice emails will not send') ?>"></span>
                        </span>
                    </button>
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
                    <?php $__userCount = (int) ($mysqli->query("SELECT COUNT(*) as c FROM invoxa_users")->fetch_assoc()['c'] ?? 0); ?>
                    <button type="button" class="subnav-item" data-settings-target="users"
                        onclick="navSettings('users')"><i class="fa-solid fa-users-gear"></i> Users
                        <span style="margin-left:auto; display:inline-flex; align-items:center; gap:0.4rem;">
                            <?php if (!$licenseValid): ?><i class="fa-solid fa-lock" title="Adding more than one user requires a license"
                                    style="color:var(--text-secondary); font-size:0.8rem;"></i><?php endif; ?>
                            <span class="subnav-dot on" style="margin-left:0;" title="<?= $__userCount ?> user<?= $__userCount === 1 ? '' : 's' ?>"></span>
                        </span></button>
                    <button type="button" class="subnav-item" data-settings-target="license"
                        onclick="navSettings('license')"><i class="fa-solid fa-key"></i> License
                        <span class="subnav-dot <?= $licenseValid ? 'on' : 'off' ?>"
                            title="<?= $licenseValid ? 'Licensed' : 'Not licensed' ?>"></span></button>
                    <?php endif; ?>
                </nav>

                <div class="subnav-content">

                    <?php if ($isAdmin): ?>
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
                    <?php endif; ?>

                    <!-- Account -->
                    <div class="subnav-pane<?= $isAdmin ? '' : ' active' ?>" id="settings-pane-account">
                        <div class="card">
                            <div class="card-header">
                                <h3 style="margin:0; font-size: 1.1rem;"><i class="fa-solid fa-lock"
                                        style="color:var(--accent); margin-right:0.5rem;"></i>Authentication</h3>
                            </div>
                            <div class="card-body">
                                <div class="form-group">
                                    <label class="form-label"
                                        style="font-size:0.8rem; color:var(--text-secondary);">Username</label>
                                    <?php $__u = $mysqli->query("SELECT username, email, totp_secret, email_verified_at FROM invoxa_users WHERE id = " . $currentUserId)->fetch_assoc();
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
                                            <label class="form-label">Scan this QR code</label>
                                            <div id="totpQrCode" style="display:inline-block; line-height:0; border-radius:8px; overflow:hidden;"></div>
                                            <p style="color:var(--text-secondary); font-size:0.8rem; margin-top:0.35rem;">
                                                Scan with your authenticator app (Google Authenticator, Authy,
                                                1Password, etc). Account name: <code id="totpAccountLabel"></code>.</p>
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label">Secret Key</label>
                                            <input type="text" id="totpSecretDisplay" class="form-control" readonly>
                                            <p style="color:var(--text-secondary); font-size:0.8rem; margin-top:0.35rem;">
                                                Can't scan the QR code? Add this as a new account manually instead —
                                                look for "Enter setup key manually" in your authenticator app.</p>
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
                                    <button class="btn" id="totpRegenBtn" style="margin-right:0.5rem;" onclick="regenerateBackupCodes()"><i
                                            class="fa-solid fa-rotate"></i> Regenerate Backup Codes</button>
                                    <button class="btn" id="totpDisableBtn" style="background:var(--danger); color:white; border:none;"
                                        onclick="disableTotp()"><i class="fa-solid fa-shield-halved"></i> Disable
                                        Two-Factor Authentication</button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <?php if ($isAdmin): ?>
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
                            <div class="card-header" style="display:flex; align-items:center; justify-content:space-between;">
                                <h3 style="margin:0; font-size: 1.1rem;"><i class="fa-solid fa-envelope"
                                        style="color:var(--accent); margin-right:0.5rem;"></i>Test Email Server</h3>
                                <?php if (getenv('SMTP_HOST') === 'mailpit'): ?>
                                    <span class="badge" style="background:rgba(255,193,7,0.12); color:var(--warning); border:1px solid rgba(255,193,7,0.25);">Mail Sink</span>
                                <?php elseif (trim((string) getenv('SMTP_HOST')) !== ''): ?>
                                    <span class="badge" style="background:rgba(40,167,69,0.12); color:var(--success); border:1px solid rgba(40,167,69,0.25);">Real SMTP</span>
                                <?php else: ?>
                                    <span class="badge" style="background:rgba(245,69,92,0.12); color:var(--danger); border:1px solid rgba(245,69,92,0.25);">Not Configured</span>
                                <?php endif; ?>
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
                                    <div style="margin-top:0.5rem; padding-top:0.75rem; border-top:1px solid var(--border); display:flex; align-items:center; justify-content:space-between;">
                                        <span style="font-size:0.8rem; color:var(--text-secondary); font-weight:500;">Events</span>
                                        <span style="display:flex; gap:0.5rem;">
                                            <button type="button" class="btn small" onclick="setAllNotifyEvents(true)">Select All</button>
                                            <button type="button" class="btn small" onclick="setAllNotifyEvents(false)">Select None</button>
                                        </span>
                                    </div>
                                    <div class="form-group" style="margin-top:0.5rem;">
                                        <label style="display:flex; align-items:center; gap:0.5rem; cursor:pointer; font-weight:400;">
                                            <input type="checkbox" class="notify-event-cb" id="notifyOnPayment" name="notify_on_payment" value="1"
                                                <?= ($settings['notify_on_payment'] ?? '1') === '1' ? 'checked' : '' ?>>
                                            Notify when a payment is received
                                        </label>
                                    </div>
                                    <div class="form-group">
                                        <label style="display:flex; align-items:center; gap:0.5rem; cursor:pointer; font-weight:400;">
                                            <input type="checkbox" class="notify-event-cb" id="notifyOnRefund" name="notify_on_refund" value="1"
                                                <?= ($settings['notify_on_refund'] ?? '1') === '1' ? 'checked' : '' ?>>
                                            Notify when a refund is issued
                                        </label>
                                    </div>
                                    <div class="form-group">
                                        <label style="display:flex; align-items:center; gap:0.5rem; cursor:pointer; font-weight:400;">
                                            <input type="checkbox" class="notify-event-cb" id="notifyOnOverdue" name="notify_on_overdue" value="1"
                                                <?= ($settings['notify_on_overdue'] ?? '1') === '1' ? 'checked' : '' ?>>
                                            Notify when an invoice becomes overdue (same trigger as the reminder email)
                                        </label>
                                    </div>
                                    <div class="form-group">
                                        <label style="display:flex; align-items:center; gap:0.5rem; cursor:pointer; font-weight:400;">
                                            <input type="checkbox" class="notify-event-cb" id="notifyOnQuoteAccepted" name="notify_on_quote_accepted" value="1"
                                                <?= ($settings['notify_on_quote_accepted'] ?? '1') === '1' ? 'checked' : '' ?>>
                                            Notify when a client accepts a quote from their Client Portal
                                        </label>
                                    </div>
                                    <div class="form-group">
                                        <label style="display:flex; align-items:center; gap:0.5rem; cursor:pointer; font-weight:400;">
                                            <input type="checkbox" class="notify-event-cb" id="notifyOnEmailFailed" name="notify_on_email_failed" value="1"
                                                <?= ($settings['notify_on_email_failed'] ?? '1') === '1' ? 'checked' : '' ?>>
                                            Notify when an invoice email fails to send
                                        </label>
                                    </div>
                                    <div class="form-group">
                                        <label style="display:flex; align-items:center; gap:0.5rem; cursor:pointer; font-weight:400;">
                                            <input type="checkbox" class="notify-event-cb" id="notifyOnLateFee" name="notify_on_late_fee" value="1"
                                                <?= ($settings['notify_on_late_fee'] ?? '1') === '1' ? 'checked' : '' ?>>
                                            Notify when a late fee is charged
                                        </label>
                                    </div>
                                    <div class="form-group">
                                        <label style="display:flex; align-items:center; gap:0.5rem; cursor:pointer; font-weight:400;">
                                            <input type="checkbox" class="notify-event-cb" id="notifyOnInvoiceVoided" name="notify_on_invoice_voided" value="1"
                                                <?= ($settings['notify_on_invoice_voided'] ?? '1') === '1' ? 'checked' : '' ?>>
                                            Notify when an invoice is voided
                                        </label>
                                    </div>
                                    <div class="form-group">
                                        <label style="display:flex; align-items:center; gap:0.5rem; cursor:pointer; font-weight:400;">
                                            <input type="checkbox" class="notify-event-cb" id="notifyOnWebhookUnmatched" name="notify_on_webhook_unmatched" value="1"
                                                <?= ($settings['notify_on_webhook_unmatched'] ?? '1') === '1' ? 'checked' : '' ?>>
                                            Notify when an incoming payment doesn't match any invoice
                                        </label>
                                    </div>
                                    <div class="form-group">
                                        <label style="display:flex; align-items:center; gap:0.5rem; cursor:pointer; font-weight:400;">
                                            <input type="checkbox" class="notify-event-cb" id="notifyOnRecurringErrors" name="notify_on_recurring_errors" value="1"
                                                <?= ($settings['notify_on_recurring_errors'] ?? '1') === '1' ? 'checked' : '' ?>>
                                            Notify when a recurring billing run has errors
                                        </label>
                                    </div>
                                    <div class="form-group">
                                        <label style="display:flex; align-items:center; gap:0.5rem; cursor:pointer; font-weight:400;">
                                            <input type="checkbox" class="notify-event-cb" id="notifyOnSecurityEvent" name="notify_on_security_event" value="1"
                                                <?= ($settings['notify_on_security_event'] ?? '1') === '1' ? 'checked' : '' ?>>
                                            Notify on security events (2FA enabled/disabled, API tokens created/revoked)
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
                                            'empty' => 'Invoxa is free and open source — everything works without a key. A license unlocks seven paid extras: Stripe/PayPal payment collection, recurring billing automation, the Client Portal, the external API, Reporting & Statistics, adding teammates beyond your own account (Settings > Users), and removing the "Powered by Invoxa" credit.',
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

                    <!-- Users -->
                    <div class="subnav-pane" id="settings-pane-users">
                        <?php if (!$licenseValid): ?>
                            <div class="card" style="border-left:3px solid var(--warning); margin-bottom:1rem;">
                                <div class="card-body" style="display:flex; align-items:center; gap:0.75rem; padding:1rem 1.25rem;">
                                    <i class="fa-solid fa-lock" style="color:var(--warning); font-size:1.1rem;"></i>
                                    <div><strong>Adding more than one user requires a license.</strong>
                                        <span style="color:var(--text-secondary); font-size:0.85rem; display:block; margin-top:0.15rem;">
                                            Editing or removing an existing user stays free either way.</span>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                        <div class="card">
                            <div class="card-header">
                                <h3 style="margin:0; font-size: 1.1rem;"><i class="fa-solid fa-users-gear"
                                        style="color:var(--accent); margin-right:0.5rem;"></i>Users</h3>
                            </div>
                            <div class="card-body">
                                <p style="color: var(--text-secondary); font-size: 0.9rem; margin-bottom: 1rem;">
                                    <strong>Admin</strong> accounts have full access, including Settings and Data
                                    Management. <strong>Member</strong> accounts can use everything day-to-day —
                                    Dashboard, Invoices, Clients, Quotes, Expenses — plus their own Account tab, but
                                    not the rest of Settings or Data Management.
                                </p>

                                <!-- Create user -->
                                <div style="padding:1rem; border:1px solid var(--border); border-radius:8px; margin-bottom:1.5rem; <?= $licenseValid ? '' : 'opacity:0.5;' ?>">
                                    <div style="display:flex; gap:1rem; align-items:flex-end; flex-wrap:wrap;">
                                        <div class="form-group" style="margin-bottom:0; flex:1; min-width:140px;">
                                            <label class="form-label">Username</label>
                                            <input type="text" id="newUserUsername" class="form-control" <?= $licenseValid ? '' : 'disabled' ?>>
                                        </div>
                                        <div class="form-group" style="margin-bottom:0; flex:1; min-width:180px;">
                                            <label class="form-label">Email</label>
                                            <input type="email" id="newUserEmail" class="form-control" <?= $licenseValid ? '' : 'disabled' ?>>
                                        </div>
                                        <div class="form-group" style="margin-bottom:0; flex:1; min-width:140px;">
                                            <label class="form-label">Password</label>
                                            <input type="password" id="newUserPassword" class="form-control" minlength="<?= PASSWORD_MIN_LENGTH ?>" autocomplete="new-password" <?= $licenseValid ? '' : 'disabled' ?>>
                                        </div>
                                        <div class="form-group" style="margin-bottom:0;">
                                            <label class="form-label">Role</label>
                                            <select id="newUserRole" class="form-control" <?= $licenseValid ? '' : 'disabled' ?>>
                                                <option value="member" selected>Member</option>
                                                <option value="admin">Admin</option>
                                            </select>
                                        </div>
                                        <button class="btn primary" id="createUserBtn" type="button" onclick="createUser()"
                                            <?= $licenseValid ? '' : 'disabled title="Requires a license"' ?>><i
                                                class="fa-solid fa-plus"></i> Add User</button>
                                    </div>
                                </div>

                                <!-- Existing users -->
                                <?php $__allUsers = $mysqli->query("SELECT id, username, email, role, created_at FROM invoxa_users ORDER BY id ASC"); ?>
                                <div style="overflow-x:auto;">
                                <table style="width:100%; border-collapse:collapse; font-size:0.85rem;">
                                    <thead>
                                        <tr style="text-align:left; color:var(--text-secondary); border-bottom:1px solid var(--border);">
                                            <th style="padding:0.4rem 0.5rem;">Username</th>
                                            <th style="padding:0.4rem 0.5rem;">Email</th>
                                            <th style="padding:0.4rem 0.5rem;">Role</th>
                                            <th style="padding:0.4rem 0.5rem;">Created</th>
                                            <th style="padding:0.4rem 0.5rem;"></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php while ($__u2 = $__allUsers->fetch_assoc()): $__isSelf = (int) $__u2['id'] === $currentUserId; ?>
                                            <tr style="border-bottom:1px solid var(--border);">
                                                <td style="padding:0.5rem; white-space:nowrap;"><?= htmlspecialchars($__u2['username']) ?><?= $__isSelf ? ' <span class="badge" style="background:var(--surface-hover); color:var(--text-primary);">You</span>' : '' ?></td>
                                                <td style="padding:0.5rem; color:var(--text-secondary); white-space:nowrap;"><?= htmlspecialchars($__u2['email'] ?? '') ?></td>
                                                <td style="padding:0.5rem;">
                                                    <select class="form-control" style="display:inline-block; width:auto; font-size:0.8rem; padding:0.2rem 0.4rem;" id="userRoleSelect<?= $__u2['id'] ?>">
                                                        <option value="member" <?= $__u2['role'] === 'member' ? 'selected' : '' ?>>Member</option>
                                                        <option value="admin" <?= $__u2['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                                                    </select>
                                                </td>
                                                <td style="padding:0.5rem; color:var(--text-secondary); white-space:nowrap;"><?= htmlspecialchars(substr($__u2['created_at'], 0, 10)) ?></td>
                                                <td style="padding:0.5rem; white-space:nowrap;">
                                                    <button class="btn small" type="button" onclick="updateUserRole(<?= $__u2['id'] ?>)" title="Save role"><i class="fa-solid fa-save"></i></button>
                                                    <button class="btn small danger" type="button" onclick="deleteUser(<?= $__u2['id'] ?>)" title="<?= $__isSelf ? "Can't delete your own account" : 'Delete' ?>" <?= $__isSelf ? 'disabled' : '' ?>><i class="fa-solid fa-trash"></i></button>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                </div>
            </div>
            </div>
        </div>
