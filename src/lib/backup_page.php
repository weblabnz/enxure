        <!-- LICENSE -->
        <!-- BACKUP & RESTORE -->
        <?php if ($isAdmin): ?>
        <div id="sec-backup" class="section">
            <h2 class="page-title">Data Management</h2>
            <div class="section-scroll">
            <div class="subnav-layout">

                <nav class="subnav">
                    <button type="button" class="subnav-item active" data-backup-target="backup"
                        onclick="navBackup('backup')"><i class="fa-solid fa-database"></i> Backup &amp; Restore</button>
                    <button type="button" class="subnav-item" data-backup-target="offsite"
                        onclick="navBackup('offsite')"><i class="fa-solid fa-cloud-arrow-up"></i> Offsite Push</button>
                    <button type="button" class="subnav-item" data-backup-target="sync"
                        onclick="navBackup('sync')"><i class="fa-solid fa-rotate"></i> Sync</button>
                    <button type="button" class="subnav-item" data-backup-target="audit"
                        onclick="navBackup('audit')"><i class="fa-solid fa-broom"></i> Audit Log Retention</button>
                    <button type="button" class="subnav-item" data-backup-target="demo"
                        onclick="navBackup('demo')"><i class="fa-solid fa-wand-magic-sparkles"></i> Demo Data</button>
                    <button type="button" class="subnav-item" data-backup-target="testsuite"
                        onclick="navBackup('testsuite')"><i class="fa-solid fa-vial"></i> Test Suite</button>
                    <button type="button" class="subnav-item" data-backup-target="screenshots"
                        onclick="navBackup('screenshots')"><i class="fa-solid fa-camera"></i> Screenshots</button>
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

                    <!-- Sync -->
                    <div class="subnav-pane" id="backup-pane-sync">
                        <?= renderSyncSection($missingFiles, $knownClientFolders, $missingDiskData) ?>
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
                                    Checks invoice math, TOTP, Stripe/PayPal conversion and webhook verification,
                                    receipt OCR, user roles, and payment-ledger behavior. Each check creates its own
                                    disposable data and deletes it after — nothing is left behind, pass or fail. Does
                                    <strong>not</strong> call the real Stripe/PayPal/SMTP APIs, or send real
                                    Telegram/Slack/webhook notifications even if you have those configured.
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
                                    <table style="width:100%; table-layout:fixed; border-collapse:collapse; font-size:0.85rem;">
                                        <thead>
                                            <tr style="text-align:left; color:var(--text-secondary); border-bottom:1px solid var(--border);">
                                                <th style="padding:0.55rem 0.5rem; width:48px;"></th>
                                                <th style="padding:0.55rem 0.75rem; width:230px;">Category</th>
                                                <th style="padding:0.55rem 0.75rem;">Case <span style="font-weight:400; text-transform:none;">(hover for detail)</span></th>
                                                <th style="padding:0.55rem 0.75rem; text-align:right; width:70px;">Time</th>
                                                <th style="padding:0.55rem 0.75rem; text-align:right; width:100px;">Status</th>
                                            </tr>
                                        </thead>
                                        <tbody id="testSuiteList">
                                            <?php $__lastGroup = null; $__firstGroup = true; foreach ($__testDefs as $__testName => $__test): ?>
                                                <?php if ($__test['group'] !== $__lastGroup): $__lastGroup = $__test['group']; ?>
                                                    <tr class="test-suite-group-row">
                                                        <td colspan="5" style="padding:0.75rem 0.75rem 0.5rem; <?= $__firstGroup ? '' : 'border-top:2px solid var(--border);' ?>">
                                                            <label style="cursor:pointer; display:flex; align-items:center; gap:0.5rem; font-weight:600; font-size:0.78rem; text-transform:uppercase; letter-spacing:0.03em; color:var(--accent);">
                                                                <input type="checkbox" class="test-suite-group-checkbox" data-group="<?= htmlspecialchars($__lastGroup) ?>" checked onclick="toggleTestGroup(this)">
                                                                <?= htmlspecialchars($__lastGroup) ?>
                                                            </label>
                                                        </td>
                                                    </tr>
                                                    <?php $__firstGroup = false; ?>
                                                <?php endif; ?>
                                                <tr class="test-suite-row" data-test-name="<?= htmlspecialchars($__testName) ?>" data-group="<?= htmlspecialchars($__test['group']) ?>" style="border-bottom:1px solid var(--border);">
                                                    <td style="padding:0.55rem 0.5rem 0.55rem 1.25rem;"><input type="checkbox" class="test-suite-checkbox" checked></td>
                                                    <td style="padding:0.55rem 0.75rem; color:var(--text-secondary); overflow-wrap:break-word;"><?= htmlspecialchars($__test['category']) ?></td>
                                                    <td style="padding:0.55rem 0.75rem; cursor:help;" title="<?= htmlspecialchars($__test['description']) ?>"><?= htmlspecialchars($__test['label']) ?></td>
                                                    <td class="test-suite-time" style="padding:0.55rem 0.75rem; text-align:right; color:var(--text-secondary); white-space:nowrap; font-variant-numeric:tabular-nums;"></td>
                                                    <td class="test-suite-status" style="padding:0.55rem 0.75rem; text-align:right; color:var(--text-secondary); white-space:nowrap;">Not run</td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Screenshots -->
                    <div class="subnav-pane" id="backup-pane-screenshots">
                        <div class="card">
                            <div class="card-header">
                                <h3 style="margin:0; font-size: 1.1rem;"><i class="fa-solid fa-camera"
                                        style="color:var(--accent); margin-right:0.5rem;"></i>Screenshots</h3>
                            </div>
                            <div class="card-body">
                                <p style="color: var(--text-secondary); font-size: 0.9rem; margin-bottom: 1rem;">
                                    Captures each selected page from this tab and overwrites its file in
                                    <code>docs/screenshots/</code>. Resize the window first — it captures exactly
                                    what's on screen. Needs HTTPS (or <code>localhost</code>); one share-this-tab
                                    prompt covers every page.
                                </p>
                                <div style="margin-bottom:1rem; display:flex; align-items:center; gap:0.75rem; flex-wrap:wrap;">
                                    <button class="btn primary" type="button" id="captureScreenshotsBtn" onclick="captureScreenshots()"><i
                                            class="fa-solid fa-camera"></i> Capture Screenshots</button>
                                    <button class="btn small" type="button" onclick="selectAllScreenshots(true)">Select All</button>
                                    <button class="btn small" type="button" onclick="selectAllScreenshots(false)">Select None</button>
                                </div>
                                <div style="display:flex; flex-direction:column; gap:0.5rem;">
                                    <?php $__screenshotManifest = json_decode(file_get_contents(__DIR__ . '/screenshot_manifest.json'), true) ?: []; ?>
                                    <?php foreach ($__screenshotManifest as $__shot): ?>
                                        <label style="display:flex; align-items:center; gap:0.6rem; padding:0.4rem 0.6rem; border:1px solid var(--border); border-radius:6px; cursor:pointer;">
                                            <input type="checkbox" class="screenshot-page-checkbox" value="<?= htmlspecialchars($__shot['key']) ?>" checked>
                                            <span><?= htmlspecialchars($__shot['label']) ?></span>
                                            <span style="margin-left:auto; color:var(--text-secondary); font-size:0.8rem;"><?= htmlspecialchars($__shot['file']) ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                                <script>window.__screenshotManifest = <?= json_encode($__screenshotManifest) ?>;</script>
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

                    <!-- Offsite Push -->
                    <div class="subnav-pane" id="backup-pane-offsite">
                        <div class="card">
                            <div class="card-header">
                                <h3 style="margin:0; font-size: 1.1rem;"><i class="fa-solid fa-cloud-arrow-up"
                                        style="color:var(--accent); margin-right:0.5rem;"></i>Offsite Push</h3>
                            </div>
                            <div class="card-body">
                                <p style="color: var(--text-secondary); font-size: 0.9rem; margin-bottom: 1rem;">
                                    Copies SQL backups from <strong>Backup &amp; Restore</strong> to a remote (S3,
                                    B2, SFTP, etc.) via <a href="https://rclone.org/" target="_blank"
                                        rel="noopener">rclone</a>, run by a scheduled job on the <code>cron</code>
                                    container. This panel only sets the on/off switch and remote name &mdash;
                                    credentials live in that container's <code>rclone.conf</code>, set up once
                                    outside this app, never entered or stored here.
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
        <?php endif; ?>
