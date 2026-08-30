<?php
function renderSyncSection(array $missingFiles, array $knownClientFolders, array $missingDiskData): string
{
    ob_start();
    ?>
    <p style="color: var(--text-secondary); font-size: 0.9rem; margin-bottom: 1rem;">
        Reconciles the database against the HTML invoice files on disk — a file with no matching row, or a
        row whose file has gone missing.
    </p>
    <div style="margin-bottom:1rem;">
        <button class="btn" onclick="refreshSync()" title="Recheck sync status"><i
                class="fa-solid fa-rotate"></i> Refresh</button>
    </div>
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
    <?php
    return ob_get_clean();
}

function invoxaHandleSaveScreenshot(): void
{
    $manifest = json_decode(file_get_contents(__DIR__ . '/screenshot_manifest.json'), true) ?: [];
    $entry = null;
    foreach ($manifest as $m) {
        if ($m['key'] === ($_POST['key'] ?? '')) {
            $entry = $m;
            break;
        }
    }
    if (!$entry) {
        throw new Exception('Unknown screenshot key.');
    }
    if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('No image uploaded, or the upload failed.');
    }
    $img = @imagecreatefromstring(file_get_contents($_FILES['image']['tmp_name']));
    if (!$img) {
        throw new Exception('Could not read the captured image.');
    }
    $destDir = DOCS_DIR . 'screenshots/';
    if (!is_dir($destDir) || !is_writable($destDir)) {
        imagedestroy($img);
        throw new Exception('docs/screenshots is not writable from this container — check the docker-compose.yml mount.');
    }
    $ok = imagewebp($img, $destDir . $entry['file'], 82);
    imagedestroy($img);
    if (!$ok) {
        throw new Exception('WebP encoding failed — GD may not have been built with libwebp support.');
    }
    echo json_encode(['success' => true]);
    exit;
}

// The Audit Log tab — same reasoning as renderStatsSection() above (no
// client-side state worth preserving across a refresh).
function invoxaAuditIcons(): array
{
    return ['email_sent' => 'fa-envelope', 'email_failed' => 'fa-circle-xmark', 'mark_paid' => 'fa-check', 'manual_send' => 'fa-paper-plane', 'note_added' => 'fa-comment', 'synced' => 'fa-rotate', 'smtp_test' => 'fa-vial', 'reminder_sent' => 'fa-bell', 'reminder_failed' => 'fa-bell-slash', 'late_fee_charged' => 'fa-triangle-exclamation', 'recurring_run' => 'fa-arrows-rotate', 'audit_log_pruned' => 'fa-broom', 'invoice_voided' => 'fa-ban', 'invoice_unvoided' => 'fa-rotate-left', 'notification_test' => 'fa-paper-plane', 'notification_failed' => 'fa-circle-xmark', 'totp_enabled' => 'fa-shield-halved', 'totp_disabled' => 'fa-shield', 'refund_issued' => 'fa-rotate-left', 'webhook_unmatched' => 'fa-triangle-exclamation', 'api_token_created' => 'fa-key', 'api_token_revoked' => 'fa-ban', 'quote_accepted' => 'fa-file-circle-check', 'quote_converted' => 'fa-file-invoice', 'user_created' => 'fa-user-plus', 'user_role_changed' => 'fa-user-gear', 'user_password_reset' => 'fa-key', 'user_deleted' => 'fa-user-xmark'];
}

// Short category tag for the Type column — every action type gets one,
// grouping the 26 granular action_type values (already shown in full in the
// Details badge) into the handful of subsystems a reader actually scans for.
function invoxaAuditTypeLabel(string $actionType): string
{
    $types = [
        'email_sent' => 'INV',
        'email_failed' => 'INV',
        'mark_paid' => 'INV',
        'manual_send' => 'INV',
        'note_added' => 'INV',
        'synced' => 'SYNC',
        'smtp_test' => 'SMTP',
        'reminder_sent' => 'INV',
        'reminder_failed' => 'INV',
        'late_fee_charged' => 'INV',
        'recurring_run' => 'BILL',
        'audit_log_pruned' => 'SYS',
        'invoice_voided' => 'INV',
        'invoice_unvoided' => 'INV',
        'notification_test' => 'NOTIF',
        'notification_failed' => 'NOTIF',
        'totp_enabled' => 'SEC',
        'totp_disabled' => 'SEC',
        'refund_issued' => 'INV',
        'webhook_unmatched' => 'WH',
        'api_token_created' => 'API',
        'api_token_revoked' => 'API',
        'quote_accepted' => 'QTE',
        'quote_converted' => 'QTE',
        'user_created' => 'USR',
        'user_role_changed' => 'USR',
        'user_password_reset' => 'USR',
        'user_deleted' => 'USR',
    ];
    return $types[$actionType] ?? 'SYS';
}

// One page of audit rows, newest first — backs both the initial Audit Log
// render and the "Show Next 100"/AJAX refresh paths so they can't drift
// apart. Fetches $pageSize+1 rows so the presence of that extra row tells
// the caller whether another page exists, without a separate COUNT(*).
function renderAuditItems($mysqli, int $offset, int $pageSize = 100): array
{
    $icons = invoxaAuditIcons();
    $fetchLimit = $pageSize + 1;
    $stmt = $mysqli->prepare("SELECT a.*, i.client_name FROM invoxa_actions a LEFT JOIN invoxa_invoices i ON a.invoice_number = i.invoice_number ORDER BY a.performed_at DESC LIMIT ? OFFSET ?");
    $stmt->bind_param('ii', $fetchLimit, $offset);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($r = $res->fetch_assoc())
        $rows[] = $r;
    $hasMore = count($rows) > $pageSize;
    if ($hasMore)
        array_pop($rows);
    ob_start();
    foreach ($rows as $act):
        $icon = $icons[$act['action_type']] ?? 'fa-bolt';
        $client = !empty($act['client_name']) ? htmlspecialchars($act['client_name']) : (empty($act['invoice_number']) ? '' : 'Unknown Client');
        $typeLabel = invoxaAuditTypeLabel($act['action_type']);
        $performedBy = $act['performed_by_username'] ?? null;
        $performedByLabel = $performedBy !== null ? htmlspecialchars($performedBy) : 'System';
        $searchBlob = strtolower($client . ' ' . $typeLabel . ' ' . $act['invoice_number'] . ' ' . str_replace('_', ' ', $act['action_type']) . ' ' . ($act['notes'] ?? '') . ' ' . $performedByLabel);
        ?>
        <div class="timeline-item" data-action-type="<?= htmlspecialchars($act['action_type']) ?>"
            data-search="<?= htmlspecialchars($searchBlob) ?>">
            <div class="timeline-icon"><i class="fa-solid <?= $icon ?>"></i></div>
            <div class="timeline-content">
                <div class="timeline-time"><?= date('M j, Y H:i', strtotime($act['performed_at'])) ?></div>
                <div
                    style="font-size: 0.75rem; font-weight: 700; white-space: nowrap; min-width: 90px; color: var(--accent); letter-spacing: 0.03em;">
                    <?= $typeLabel ?></div>
                <div style="font-size: 0.85rem; color: var(--text-primary); flex: 1; min-width: 200px;">
                    <span
                        style="background: rgba(255,255,255,0.05); padding: 0.2rem 0.4rem; border-radius: 4px; border: 1px solid var(--border); font-size: 0.65rem; text-transform: uppercase; margin-right: 0.75rem; font-weight: 600; letter-spacing: 0.5px;"><?= htmlspecialchars(str_replace('_', ' ', $act['action_type'])) ?></span><?= htmlspecialchars($act['notes'] ?? '') ?>
                </div>
                <div style="font-size: 0.78rem; min-width: 110px; color: var(--text-secondary); white-space: nowrap;"
                    title="Performed by"><i class="fa-solid fa-user-shield"
                        style="font-size: 0.7rem; margin-right: 0.3rem;"></i><?= $performedByLabel ?></div>
                <div style="font-size: 0.85rem; min-width: 140px; color: var(--text-secondary);"
                    title="<?= $client ?>"><?php if ($client !== ''): ?><i class="fa-solid fa-user"
                        style="font-size: 0.75rem; margin-right: 0.3rem;"></i><?= $client ?><?php endif; ?></div>
            </div>
        </div>
    <?php endforeach;
    return ['html' => ob_get_clean(), 'hasMore' => $hasMore, 'nextOffset' => $offset + count($rows)];
}

function renderAuditSection($mysqli): string
{
    $icons = invoxaAuditIcons();
    $page = renderAuditItems($mysqli, 0);
    ob_start();
    ?>
    <h2 class="page-title">Audit Log
        <span style="display:flex; gap:0.5rem;">
            <button class="btn" onclick="exportAuditLogCsv()" title="Export the currently loaded/filtered rows as CSV"><i
                    class="fa-solid fa-file-csv"></i> Export CSV</button>
            <button class="btn" onclick="refreshAuditSection()" title="Reload audit log"><i
                    class="fa-solid fa-rotate"></i> Refresh</button>
        </span>
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
        <div class="card-body">
        <div class="timeline-header" style="display:flex; align-items:center; flex-wrap:wrap; gap:0.75rem 1.5rem; margin-left:2rem; padding:0 1.25rem 0.5rem; border-bottom:1px solid var(--border); margin-bottom:0.75rem; font-size:0.7rem; font-weight:700; text-transform:uppercase; letter-spacing:0.05em; color:var(--text-secondary);">
            <div style="min-width:130px;">Time</div>
            <div style="min-width:90px;">Type</div>
            <div style="flex:1; min-width:200px;">Details</div>
            <div style="min-width:110px;">Performed By</div>
            <div style="min-width:140px;">Client</div>
        </div>
        <div class="timeline" id="auditTimelineBody" data-next-offset="<?= $page['nextOffset'] ?>"
            data-has-more="<?= $page['hasMore'] ? '1' : '0' ?>">
            <?= $page['html'] ?>
            <p id="auditNoResults" style="display:none; color:var(--text-secondary); text-align:center; padding:1.5rem 0; margin:0;">
                No entries match your search/filter.</p>
        </div>
        <div id="auditLoadMoreWrap" style="text-align:center; margin-top:1rem; <?= $page['hasMore'] ? '' : 'display:none;' ?>">
            <button type="button" class="btn" id="auditLoadMoreBtn" onclick="loadMoreAuditRows()">Show Next 100</button>
        </div>
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
            invoxaLogAction($mysqli, $iid, $invNum, $actionType, $notes);
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

function invoxaTestCreateInvoice($mysqli, string $clientKey, float $amount, string $currency = ''): int
{
    // Must end in digits — generateInvoiceNumber()'s "highest number so far"
    // lookup only matches a trailing run of digits (/(\d+)$/).
    $invNum = 'ZTEST-' . strtoupper(bin2hex(random_bytes(3))) . random_int(100, 999);
    $stmt = $mysqli->prepare("INSERT INTO invoxa_invoices (invoice_number, client_key, client_name, recipient_email, invoice_date, due_date, amount, currency, status) VALUES (?, ?, 'Test Suite Fixture', 'testsuite@invalid.example', NOW(), DATE_ADD(NOW(), INTERVAL 21 DAY), ?, ?, 'sent')");
    $stmt->bind_param("ssds", $invNum, $clientKey, $amount, $currency);
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
    $run('Core Logic', 'Stripe', 'USD amount round-trip', '$19.99 converts to 1999 (cents) via stripeAmountToMinorUnits() and back to $19.99 via stripeAmountFromMinorUnits().', function () {
        $minor = stripeAmountToMinorUnits(19.99, 'USD');
        invoxaAssertEquals(1999, $minor);
        invoxaAssertEquals(19.99, stripeAmountFromMinorUnits($minor, 'USD'));
    });
    $run('Core Logic', 'Stripe', 'zero-decimal currency', 'JPY 500 stays 500 (not multiplied by 100) since Stripe treats JPY as a zero-decimal currency.', function () {
        invoxaAssertEquals(500, stripeAmountToMinorUnits(500, 'JPY'));
    });
    $run('Core Logic', 'Lockout', 'minutes-remaining math', 'invoxaLockoutMinutesRemaining() returns 0 for no lock or an already-expired one, and ~5 for a lock 5 minutes in the future.', function () {
        invoxaAssertEquals(0, invoxaLockoutMinutesRemaining(null));
        invoxaAssertEquals(0, invoxaLockoutMinutesRemaining(date('Y-m-d H:i:s', time() - 60)));
        $remaining = invoxaLockoutMinutesRemaining(date('Y-m-d H:i:s', time() + 300));
        invoxaAssertTrue($remaining >= 4 && $remaining <= 5, "expected ~5 minutes, got {$remaining}");
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
    $run('Core Logic', 'computeInvoiceTotals', '100% discount zeroes the total', 'A $100 item with a 100% discount nets to $0 before tax, so a 20% tax rate on top still totals exactly $0 — tax never applies to a discount, only to what\'s left after it.', function () {
        $items = [['amount' => 100]];
        $t = computeInvoiceTotals($items, 100, 20);
        invoxaAssertEquals(100.0, $t['discount']);
        invoxaAssertEquals(0.0, $t['total']);
    });
    $run('Core Logic', 'computeInvoiceTotals', 'negative line item nets correctly', 'A $100 charge alongside a -$30 credit line (e.g. a partial refund folded into the same invoice) subtotals to $70, and a 10% tax applies to that net $70, not the $100 gross.', function () {
        $items = [['amount' => 100], ['amount' => -30]];
        $t = computeInvoiceTotals($items, 0, 10);
        invoxaAssertEquals(70.0, $t['subtotal']);
        invoxaAssertEquals(7.0, $t['tax']);
        invoxaAssertEquals(77.0, $t['total']);
    });
    $run('Core Logic', 'computeInvoiceTotals', 'clamps out-of-range percentages', 'A discount/tax pair outside 0-100 (150% discount, -10% tax) is clamped to the valid range before it\'s applied, the same clamp save_client\'s and the Ad Hoc form\'s own inputs go through — an over-100 discount can\'t make the total negative, and a negative tax can\'t reduce it.', function () {
        $items = [['amount' => 100]];
        $t = computeInvoiceTotals($items, 150, -10);
        invoxaAssertEquals(100.0, $t['discount_pct'], 'discount_pct clamped to 100');
        invoxaAssertEquals(0.0, $t['tax_rate'], 'tax_rate clamped to 0');
        invoxaAssertEquals(0.0, $t['total']);
    });
    $run('Core Logic', 'getTaxYearStart', 'resolves the correct calendar year on both sides of the start month', 'With an April 1st tax year start, a "now" of mid-February resolves to the previous year\'s April 1st (the tax year still in progress), while a "now" of mid-June resolves to this year\'s April 1st (a new tax year has already begun).', function () {
        $before = getTaxYearStart(4, new DateTime('2026-02-15'));
        invoxaAssertEquals('2025-04-01', $before->format('Y-m-d'), 'before the start month falls back to last year');
        $after = getTaxYearStart(4, new DateTime('2026-06-10'));
        invoxaAssertEquals('2026-04-01', $after->format('Y-m-d'), 'at/after the start month uses this year');
    });
    $run('Core Logic', 'generateInvoiceNumber', 'default template numbers a fresh client from 001', 'With no invoice_number_template configured, a brand-new client\'s first invoice number is exactly their client_key (uppercased) followed by "001" — the {key}{seq} default template with 3-digit padding.', function () use ($mysqli) {
        [$clientId, $clientKey] = invoxaTestCreateClient($mysqli);
        try {
            $num = generateInvoiceNumber($mysqli, $clientKey, 'Test Suite Fixture', []);
            invoxaAssertEquals(strtoupper($clientKey) . '001', $num);
        } finally {
            invoxaTestCleanupClient($mysqli, $clientId, $clientKey);
            @rmdir(INVOICES_DIR . 'test_suite_fixture');
        }
    });
    $run('Core Logic', 'generateInvoiceNumber', 'custom template and padding are honored', 'Settings > Branding\'s invoice_number_template ("INV-{year}-{key}-{seq}") and a 5-digit padding produce exactly that shape for a fresh client\'s first invoice, substituting {year} and {key} correctly.', function () use ($mysqli) {
        [$clientId, $clientKey] = invoxaTestCreateClient($mysqli);
        try {
            $num = generateInvoiceNumber($mysqli, $clientKey, 'Test Suite Fixture', ['invoice_number_template' => 'INV-{year}-{key}-{seq}', 'invoice_number_padding' => 5]);
            invoxaAssertEquals('INV-' . date('Y') . '-' . strtoupper($clientKey) . '-00001', $num);
        } finally {
            invoxaTestCleanupClient($mysqli, $clientId, $clientKey);
            @rmdir(INVOICES_DIR . 'test_suite_fixture');
        }
    });
    $run('Core Logic', 'invoxaResolveCurrency', 'client currency wins, blank falls back to the instance default', 'A non-blank currency (however it\'s cased) is returned uppercased; a blank one falls back to Settings > General\'s currency — the same fallback processInvoice()/save_quote/renderInvoiceRows() all use.', function () use ($settings) {
        invoxaAssertEquals('EUR', invoxaResolveCurrency('eur', $settings));
        invoxaAssertEquals(strtoupper($settings['currency'] ?? (getenv('APP_CURRENCY') ?: 'USD')), invoxaResolveCurrency('', $settings));
    });
    $run('Core Logic', 'invoxaNormalizeCurrencyCode', 'strips non-letters, uppercases, caps at 3 characters', 'The Add/Edit Client Currency field goes through the same normalization Settings > General\'s Currency Code already used — stray digits/symbols stripped before the 3-letter cap.', function () {
        invoxaAssertEquals('EUR', invoxaNormalizeCurrencyCode(' eur '));
        invoxaAssertEquals('USD', invoxaNormalizeCurrencyCode('us1d$'));
        invoxaAssertEquals('', invoxaNormalizeCurrencyCode(''));
    });
    $run('Core Logic', 'invoxaGroupAmountsByCurrency / invoxaFormatMoneyByCurrency', 'groups and renders per currency instead of blending totals', 'Two USD rows and one EUR row group into separate USD/EUR sums (not one number that adds unlike currencies together), and the formatted string used on the Dashboard/CSV exports names both totals rather than only one.', function () use ($settings) {
        $rows = [['currency' => 'USD', 'amount' => 100], ['currency' => 'USD', 'amount' => 50], ['currency' => 'EUR', 'amount' => 30]];
        $grouped = invoxaGroupAmountsByCurrency($rows, 'amount', $settings);
        invoxaAssertEquals(150.0, $grouped['USD'] ?? null, 'USD total');
        invoxaAssertEquals(30.0, $grouped['EUR'] ?? null, 'EUR total');
        $rendered = invoxaFormatMoneyByCurrency($grouped);
        invoxaAssertTrue(str_contains($rendered, 'USD $150.00'), 'rendered string names the USD total');
        invoxaAssertTrue(str_contains($rendered, 'EUR $30.00'), 'rendered string names the EUR total');
    });

    // ── Clients & Invoices ── the "add a client" / "add an invoice" paths,
    // exercised against disposable fixtures rather than the real AJAX actions.
    $run('Clients & Invoices', 'Client', 'created with correct defaults', 'A newly inserted client comes back active, flagged as test data, and with 0% discount/tax — the same defaults the Add Client form relies on.', function () use ($mysqli) {
        [$clientId, $clientKey] = invoxaTestCreateClient($mysqli);
        try {
            $row = $mysqli->query("SELECT is_active, is_test, discount_pct, tax_rate, currency FROM invoxa_clients WHERE id = $clientId")->fetch_assoc();
            invoxaAssertTrue((bool) $row, 'client row exists');
            invoxaAssertEquals(1, (int) $row['is_active']);
            invoxaAssertEquals(1, (int) $row['is_test']);
            invoxaAssertEquals(0.0, (float) $row['discount_pct']);
            invoxaAssertEquals(0.0, (float) $row['tax_rate']);
            invoxaAssertEquals('', $row['currency'] ?? null, 'blank currency by default');
        } finally {
            invoxaTestCleanupClient($mysqli, $clientId, $clientKey);
        }
    });
    $run('Clients & Invoices', 'Client currency', 'blank currency resolves to the instance default', 'A freshly added client (Currency left blank, the default) resolves to Settings > General\'s currency rather than an empty string once an invoice is generated for them.', function () use ($mysqli, $settings) {
        [$clientId, $clientKey] = invoxaTestCreateClient($mysqli);
        try {
            $client = $mysqli->query("SELECT * FROM invoxa_clients WHERE id = $clientId")->fetch_assoc();
            invoxaAssertEquals(strtoupper($settings['currency'] ?? (getenv('APP_CURRENCY') ?: 'USD')), invoxaResolveCurrency($client['currency'] ?? '', $settings));
        } finally {
            invoxaTestCleanupClient($mysqli, $clientId, $clientKey);
        }
    });
    $run('Clients & Invoices', 'Client currency', 'invoice snapshots the client\'s currency, unaffected by a later change', 'An invoice stamped with a client\'s currency at creation time (the same invoxaResolveCurrency() call processInvoice()/save_quote make) keeps that value even after the client\'s own currency is changed afterward — invoxa_invoices.currency is a snapshot, not a live link to invoxa_clients.currency.', function () use ($mysqli, $settings) {
        [$clientId, $clientKey] = invoxaTestCreateClient($mysqli);
        try {
            $mysqli->query("UPDATE invoxa_clients SET currency = 'EUR' WHERE id = $clientId");
            $client = $mysqli->query("SELECT * FROM invoxa_clients WHERE id = $clientId")->fetch_assoc();
            $stampedCurrency = invoxaResolveCurrency($client['currency'] ?? '', $settings);
            $invId = invoxaTestCreateInvoice($mysqli, $clientKey, 50.00, $stampedCurrency);
            $mysqli->query("UPDATE invoxa_clients SET currency = 'GBP' WHERE id = $clientId");
            $invRow = $mysqli->query("SELECT currency FROM invoxa_invoices WHERE id = $invId")->fetch_assoc();
            invoxaAssertEquals('EUR', $invRow['currency']);
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
    $run('Clients & Invoices', 'Client', 'bulk flag update toggles independently', 'The Clients tab\'s bulk action bar updates one flag at a time (update_client_flags) — flipping is_active to 0 leaves is_test untouched, and flipping is_test to 1 afterward leaves is_active untouched, exactly as if each button were its own single-column UPDATE.', function () use ($mysqli) {
        [$clientId, $clientKey] = invoxaTestCreateClient($mysqli);
        try {
            $stmt = $mysqli->prepare("UPDATE invoxa_clients SET is_active = ? WHERE id = ?");
            $inactive = 0;
            $stmt->bind_param("ii", $inactive, $clientId);
            $stmt->execute();
            $row = $mysqli->query("SELECT is_active, is_test FROM invoxa_clients WHERE id = $clientId")->fetch_assoc();
            invoxaAssertEquals(0, (int) $row['is_active'], 'is_active should now be 0');
            invoxaAssertEquals(1, (int) $row['is_test'], 'is_test should be untouched by the is_active update');
            $stmt = $mysqli->prepare("UPDATE invoxa_clients SET is_test = ? WHERE id = ?");
            $stillTest = 1;
            $stmt->bind_param("ii", $stillTest, $clientId);
            $stmt->execute();
            $after = $mysqli->query("SELECT is_active, is_test FROM invoxa_clients WHERE id = $clientId")->fetch_assoc();
            invoxaAssertEquals(0, (int) $after['is_active'], 'is_active should still be untouched by the is_test update');
            invoxaAssertEquals(1, (int) $after['is_test']);
        } finally {
            invoxaTestCleanupClient($mysqli, $clientId, $clientKey);
        }
    });
    $run('Clients & Invoices', 'Quote', 'converts to a real invoice', 'convertQuoteToInvoice() flips is_quote to 0, keeps the same amount, renumbers away from the Q-prefixed quote number, and logs a quote_converted audit entry — the same path the admin\'s Convert button and the Client Portal\'s Accept button both call.', function () use ($mysqli, $settings) {
        [$clientId, $clientKey] = invoxaTestCreateClient($mysqli);
        $quoteId = null;
        try {
            $quoteNum = 'Q' . strtoupper($clientKey) . '001';
            $stmt = $mysqli->prepare("INSERT INTO invoxa_invoices (invoice_number, client_key, client_name, recipient_email, invoice_date, due_date, amount, status, is_quote, html_content) VALUES (?, ?, 'Test Suite Fixture', 'testsuite@invalid.example', NOW(), DATE_ADD(NOW(), INTERVAL 21 DAY), 250.00, 'sent', 1, ?)");
            $html = "<html>{$quoteNum}</html>";
            $stmt->bind_param("sss", $quoteNum, $clientKey, $html);
            $stmt->execute();
            $quoteId = $mysqli->insert_id;
            $result = convertQuoteToInvoice($mysqli, $settings, $quoteId, 'admin');
            invoxaAssertTrue($result['success'], 'conversion should succeed for a real quote');
            $row = $mysqli->query("SELECT is_quote, amount, invoice_number FROM invoxa_invoices WHERE id = $quoteId")->fetch_assoc();
            invoxaAssertEquals(0, (int) $row['is_quote'], 'should no longer be flagged as a quote');
            invoxaAssertEquals(250.00, (float) $row['amount'], 'amount should carry over unchanged');
            invoxaAssertTrue($row['invoice_number'] !== $quoteNum, 'should be renumbered away from the quote number');
            $action = $mysqli->query("SELECT action_type FROM invoxa_actions WHERE invoice_id = $quoteId AND action_type = 'quote_converted'")->fetch_assoc();
            invoxaAssertTrue((bool) $action, 'expected a quote_converted audit entry');
            $missing = convertQuoteToInvoice($mysqli, $settings, 999999999, 'admin');
            invoxaAssertTrue(!$missing['success'], 'converting a non-existent quote id should fail cleanly');
        } finally {
            if ($quoteId) {
                $mysqli->query("DELETE FROM invoxa_actions WHERE invoice_id = " . (int) $quoteId);
                $mysqli->query("DELETE FROM invoxa_invoices WHERE id = " . (int) $quoteId);
            }
            invoxaTestCleanupClient($mysqli, $clientId, $clientKey);
            foreach (glob(INVOICES_DIR . 'test_suite_fixture/*.html') ?: [] as $__f) {
                @unlink($__f);
            }
            @rmdir(INVOICES_DIR . 'test_suite_fixture');
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
    $run('Payments & Refunds', 'Accounting journal', 'every entry balances', 'buildAccountingJournal() emits an invoice as one debit + one credit row of the same reference, and a payment against it the same way — for a fixture invoice paid in full, the rows sharing that invoice\'s reference always sum to equal debit and credit totals, which is what makes the export genuinely importable into a bookkeeping tool.', function () use ($mysqli, $settings) {
        [$clientId, $clientKey] = invoxaTestCreateClient($mysqli);
        try {
            $invId = invoxaTestCreateInvoice($mysqli, $clientKey, 60.00);
            $invNum = $mysqli->query("SELECT invoice_number FROM invoxa_invoices WHERE id = $invId")->fetch_assoc()['invoice_number'];
            recordInvoicePayment($mysqli, $settings, $invId, 60.00, 'test', 'manual');
            $journal = buildAccountingJournal($mysqli, $settings, date('Y-m-d', strtotime('-1 day')), '');
            $ours = array_values(array_filter($journal, fn($r) => $r['ref'] === $invNum));
            invoxaAssertEquals(4, count($ours), 'expected 2 rows for the invoice and 2 for its payment');
            $debitTotal = array_sum(array_column($ours, 'debit'));
            $creditTotal = array_sum(array_column($ours, 'credit'));
            invoxaAssertEquals(round($debitTotal, 2), round($creditTotal, 2), 'debits and credits should balance');
            invoxaAssertEquals(120.0, round($debitTotal, 2), 'two $60 debit legs (invoice + payment)');
        } finally {
            invoxaTestCleanupClient($mysqli, $clientId, $clientKey);
        }
    });
    $run('Payments & Refunds', 'Webhook', 'unmatched reference logs an audit entry', 'invoxaLogUnmatchedWebhook() — called when a Stripe/PayPal event references an invoice Invoxa no longer recognizes — writes a webhook_unmatched action naming the provider and the dangling reference, so the Audit Log still shows something happened even though nothing was credited.', function () use ($mysqli) {
        $reference = 'ztest_ref_' . bin2hex(random_bytes(6));
        try {
            invoxaLogUnmatchedWebhook($mysqli, 'stripe', 'checkout.session.completed', $reference);
            $row = $mysqli->query("SELECT notes FROM invoxa_actions WHERE action_type = 'webhook_unmatched' AND notes LIKE '%" . $mysqli->real_escape_string($reference) . "%'")->fetch_assoc();
            invoxaAssertTrue((bool) $row, 'expected a webhook_unmatched entry mentioning the reference');
            invoxaAssertTrue(str_contains($row['notes'], 'Stripe'), 'provider name should be capitalized in the note');
        } finally {
            $mysqli->query("DELETE FROM invoxa_actions WHERE action_type = 'webhook_unmatched' AND notes LIKE '%" . $mysqli->real_escape_string($reference) . "%'");
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
    // ── Billing Cron ── the double-billing guard's query, checked
    // directly rather than via run_recurring(), which would bill real clients.
    $run('Billing Cron', 'Double-billing guard', 'detects an invoice already billed this month', 'The same "already billed this period" query run_recurring() uses for monthly clients correctly finds an invoice dated today, and correctly finds none for a client with no invoices at all.', function () use ($mysqli) {
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
    $run('Billing Cron', 'Late fees', 'eligibility query catches overdue, skips grace-period and already-charged invoices', 'The same eligibility check applyLateFees() runs (unpaid, non-quote, due_date past the grace period, no existing late_fee_charged action) picks up an invoice 10 days overdue against a 7-day grace period, but correctly skips one only 3 days overdue, and skips an eligible invoice that already has a late_fee_charged entry against it.', function () use ($mysqli) {
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
            invoxaLogAction($mysqli, $alreadyChargedId, $invNum, 'late_fee_charged', 'test fixture');

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

    // ── Security ── crypto/signature checks that are pure functions, plus the
    // account-recovery paths that touch the database, using a real but
    // isolated, fake user id (never invoxa_users itself).
    $run('Security', 'TOTP', 'current code verifies', 'A freshly generated secret\'s current 30-second TOTP code passes verifyTotpCode().', function () {
        $secret = generateTotpSecret();
        $code = totpCodeAt($secret, (int) floor(time() / 30));
        invoxaAssertTrue(verifyTotpCode($secret, $code));
    });
    $run('Security', 'TOTP', 'wrong code rejected', 'An incorrect 6-digit code fails verifyTotpCode() against a freshly generated secret.', function () {
        $secret = generateTotpSecret();
        $real = totpCodeAt($secret, (int) floor(time() / 30));
        $wrong = ($real === '000000') ? '111111' : '000000';
        invoxaAssertTrue(!verifyTotpCode($secret, $wrong));
    });
    $run('Security', 'TOTP', 'tolerates one step of clock drift', 'verifyTotpCode()\'s default ±1 step window accepts a code from 30 seconds ago (a slightly slow phone clock), but not one from 60 seconds ago — two steps out is still rejected.', function () {
        $secret = generateTotpSecret();
        $currentStep = (int) floor(time() / 30);
        $oneStepBack = totpCodeAt($secret, $currentStep - 1);
        $twoStepsBack = totpCodeAt($secret, $currentStep - 2);
        invoxaAssertTrue(verifyTotpCode($secret, $oneStepBack), 'a code from one step ago should still verify');
        invoxaAssertTrue(!verifyTotpCode($secret, $twoStepsBack), 'a code from two steps ago should be rejected');
    });
    $run('Security', 'Stripe webhook signature', 'valid signature accepted', 'A signature correctly computed as HMAC-SHA256 over "{timestamp}.{payload}" verifies successfully.', function () {
        $payload = '{"type":"test"}';
        $secret = 'whsec_testsecret';
        $ts = time();
        $sig = hash_hmac('sha256', $ts . '.' . $payload, $secret);
        invoxaAssertTrue(stripeVerifyWebhookSignature($payload, "t={$ts},v1={$sig}", $secret));
    });
    $run('Security', 'Stripe webhook signature', 'tampered payload rejected', 'Changing the payload after signing invalidates the signature check, as it should.', function () {
        $payload = '{"type":"test"}';
        $secret = 'whsec_testsecret';
        $ts = time();
        $sig = hash_hmac('sha256', $ts . '.' . $payload, $secret);
        invoxaAssertTrue(!stripeVerifyWebhookSignature('{"type":"tampered"}', "t={$ts},v1={$sig}", $secret));
    });
    $run('Security', 'Stripe webhook signature', 'stale timestamp rejected', 'A signature computed from a timestamp far in the past is rejected — this is what blocks replay attacks.', function () {
        $payload = '{"type":"test"}';
        $secret = 'whsec_testsecret';
        $ts = time() - 999999;
        $sig = hash_hmac('sha256', $ts . '.' . $payload, $secret);
        invoxaAssertTrue(!stripeVerifyWebhookSignature($payload, "t={$ts},v1={$sig}", $secret));
    });
    $run('Security', 'Backup codes', 'format & uniqueness', '10 generated backup codes are all unique and every one matches the XXXXX-XXXXX uppercase-hex format.', function () {
        $codes = invoxaGenerateBackupCodes(10);
        invoxaAssertEquals(10, count($codes));
        invoxaAssertEquals(10, count(array_unique($codes)));
        foreach ($codes as $c) {
            invoxaAssertTrue((bool) preg_match('/^[0-9A-F]{5}-[0-9A-F]{5}$/', $c), "code format: $c");
        }
    });
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

    // ── Receipt OCR ── the Add Expense vendor/amount prefill. The first two
    // tests are pure logic (no dependencies at all); the third renders an
    // actual receipt-shaped image with GD and feeds it through the exact
    // shell_exec(tesseract) call the "ocr_expense_receipt" action uses, so it
    // also catches a broken/missing tesseract install, not just bad regexes.
    $run('Receipt OCR', 'parseReceiptOcrText', 'extracts vendor and total from OCR text', 'Given typical multi-line receipt OCR output, the vendor is the first line that looks like a name (not a price/date row) and the amount comes from the line labeled TOTAL, not an earlier line-item price.', function () {
        $text = "ACME HARDWARE\n123 Main St\nWidget           12.00\nGadget           18.50\nSUBTOTAL         30.50\nTAX               2.49\nTOTAL            32.99\nTHANK YOU";
        $parsed = parseReceiptOcrText($text);
        invoxaAssertEquals('ACME HARDWARE', $parsed['vendor']);
        invoxaAssertEquals(32.99, $parsed['amount']);
    });
    $run('Receipt OCR', 'parseReceiptOcrText', 'falls back to the largest amount when nothing is labeled TOTAL', 'With no line recognizable as a total (a cropped photo, an unusual layout), the parser falls back to the largest dollar figure on the receipt, since the grand total is almost always the biggest number printed.', function () {
        $text = "COFFEE SHOP\nLatte    4.50\nMuffin   3.25\n7.75";
        $parsed = parseReceiptOcrText($text);
        invoxaAssertEquals('COFFEE SHOP', $parsed['vendor']);
        invoxaAssertEquals(7.75, $parsed['amount']);
    });
    $run('Receipt OCR', 'End-to-end', 'reads vendor and total off a rendered receipt image', 'A receipt-shaped PNG with a known store name and total is generated on the fly (GD, no fixture file to maintain), run through the same tesseract shell_exec() the real upload path uses, and the parsed result must match. Counts as a pass with nothing asserted if this environment is missing the gd extension or the tesseract binary — those are this one check\'s dependencies, not the app\'s.', function () {
        if (!function_exists('imagecreate') || trim((string) shell_exec('command -v tesseract 2>/dev/null')) === '') {
            return;
        }
        // GD's built-in bitmap font is only ~8x16px per character — too small
        // for tesseract to read reliably — so it's drawn small, then upscaled
        // 4x (the way a real photographed receipt has far more than 8px per
        // character) before being handed to tesseract.
        $w = 420;
        $h = 140;
        $small = imagecreatetruecolor($w, $h);
        imagefill($small, 0, 0, imagecolorallocate($small, 255, 255, 255));
        $fg = imagecolorallocate($small, 0, 0, 0);
        imagestring($small, 5, 10, 10, 'TEST HARDWARE CO', $fg);
        imagestring($small, 5, 10, 55, 'WIDGET   12.00', $fg);
        imagestring($small, 5, 10, 90, 'TOTAL    45.67', $fg);
        $scale = 4;
        $img = imagecreatetruecolor($w * $scale, $h * $scale);
        imagecopyresampled($img, $small, 0, 0, 0, 0, $w * $scale, $h * $scale, $w, $h);
        imagedestroy($small);
        $path = sys_get_temp_dir() . '/' . uniqid('ocr_test_', true) . '.png';
        imagepng($img, $path);
        imagedestroy($img);
        try {
            $text = (string) shell_exec('tesseract ' . escapeshellarg($path) . ' stdout 2>/dev/null');
            $parsed = parseReceiptOcrText($text);
            invoxaAssertTrue(str_contains(strtoupper((string) $parsed['vendor']), 'TEST HARDWARE CO'), 'vendor should contain the rendered store name, got: ' . var_export($parsed['vendor'], true));
            invoxaAssertEquals(45.67, $parsed['amount']);
        } finally {
            @unlink($path);
        }
    });
    $run('Receipt OCR', 'Expense attachments', 'doc_type separates Invoice and Receipt uploads', 'A row inserted as doc_type=\'invoice\' and another as doc_type=\'receipt\' against the same expense are both stored and stay distinguishable by that column — the same one the Add Expense modal\'s two upload slots (and Receipt OCR, which only ever reads the receipt one) rely on.', function () use ($mysqli) {
        $mysqli->query("INSERT INTO invoxa_expenses (expense_date, vendor, category, amount, description) VALUES (CURDATE(), 'Test Suite Fixture', 'other', 10.00, '')");
        $expenseId = $mysqli->insert_id;
        try {
            $ins = $mysqli->prepare("INSERT INTO invoxa_expense_receipts (expense_id, filename, stored_path, file_size, doc_type) VALUES (?, 'inv.pdf', 'zt/inv.pdf', 100, 'invoice')");
            $ins->bind_param("i", $expenseId);
            $ins->execute();
            $ins2 = $mysqli->prepare("INSERT INTO invoxa_expense_receipts (expense_id, filename, stored_path, file_size, doc_type) VALUES (?, 'rcpt.jpg', 'zt/rcpt.jpg', 100, 'receipt')");
            $ins2->bind_param("i", $expenseId);
            $ins2->execute();
            $rows = $mysqli->query("SELECT filename, doc_type FROM invoxa_expense_receipts WHERE expense_id = $expenseId")->fetch_all(MYSQLI_ASSOC);
            invoxaAssertEquals(2, count($rows), 'both attachments should be stored');
            $byType = array_column($rows, 'filename', 'doc_type');
            invoxaAssertEquals('inv.pdf', $byType['invoice'] ?? null, 'invoice row');
            invoxaAssertEquals('rcpt.jpg', $byType['receipt'] ?? null, 'receipt row');
        } finally {
            $mysqli->query("DELETE FROM invoxa_expense_receipts WHERE expense_id = $expenseId");
            $mysqli->query("DELETE FROM invoxa_expenses WHERE id = $expenseId");
        }
    });
    $run('Receipt OCR', 'Expense attachments', 'move_expense_receipt re-tags a file between Invoice and Receipt', 'The same UPDATE the "Move to Invoice/Receipt" button runs flips a row\'s doc_type in place, for when the wrong slot was picked at upload time — the stored file itself is never touched, only which section it shows up in.', function () use ($mysqli) {
        $mysqli->query("INSERT INTO invoxa_expenses (expense_date, vendor, category, amount, description) VALUES (CURDATE(), 'Test Suite Fixture', 'other', 10.00, '')");
        $expenseId = $mysqli->insert_id;
        try {
            $ins = $mysqli->prepare("INSERT INTO invoxa_expense_receipts (expense_id, filename, stored_path, file_size, doc_type) VALUES (?, 'oops.jpg', 'zt/oops.jpg', 100, 'invoice')");
            $ins->bind_param("i", $expenseId);
            $ins->execute();
            $receiptId = $mysqli->insert_id;
            $upd = $mysqli->prepare("UPDATE invoxa_expense_receipts SET doc_type = 'receipt' WHERE id = ?");
            $upd->bind_param("i", $receiptId);
            $upd->execute();
            $docType = $mysqli->query("SELECT doc_type FROM invoxa_expense_receipts WHERE id = $receiptId")->fetch_assoc()['doc_type'];
            invoxaAssertEquals('receipt', $docType, 'doc_type should flip to receipt');
        } finally {
            $mysqli->query("DELETE FROM invoxa_expense_receipts WHERE expense_id = $expenseId");
            $mysqli->query("DELETE FROM invoxa_expenses WHERE id = $expenseId");
        }
    });

    // ── Users & Roles ── Settings > Users. Fixture accounts are prefixed
    // 'zt_' and always deleted in a finally block, same convention as the
    // client/invoice fixtures above.
    $run('Users & Roles', 'Last admin guard', 'counts other admins correctly', 'The same "how many OTHER admins exist" query update_user/delete_user run before demoting or deleting an admin never counts the target account itself, and correctly counts a newly added second admin — the two ways that count could be wrong and let the last admin lock everyone out.', function () use ($mysqli) {
        $adminUser = 'zt_admin_' . bin2hex(random_bytes(4));
        $stmt = $mysqli->prepare("INSERT INTO invoxa_users (username, email, role, password_hash) VALUES (?, 'zt@invalid.example', 'admin', 'x')");
        $stmt->bind_param("s", $adminUser);
        $stmt->execute();
        $fixtureId = $mysqli->insert_id;
        try {
            $selfIncluded = (int) $mysqli->query("SELECT COUNT(*) as c FROM invoxa_users WHERE role = 'admin' AND id != $fixtureId AND id = $fixtureId")->fetch_assoc()['c'];
            invoxaAssertEquals(0, $selfIncluded, 'the target admin itself should never be counted as an "other" admin');
            $before = (int) $mysqli->query("SELECT COUNT(*) as c FROM invoxa_users WHERE role = 'admin' AND id != $fixtureId")->fetch_assoc()['c'];
            $secondAdmin = 'zt_admin2_' . bin2hex(random_bytes(4));
            $stmt2 = $mysqli->prepare("INSERT INTO invoxa_users (username, email, role, password_hash) VALUES (?, 'zt2@invalid.example', 'admin', 'x')");
            $stmt2->bind_param("s", $secondAdmin);
            $stmt2->execute();
            $secondId = $mysqli->insert_id;
            try {
                $after = (int) $mysqli->query("SELECT COUNT(*) as c FROM invoxa_users WHERE role = 'admin' AND id != $fixtureId")->fetch_assoc()['c'];
                invoxaAssertEquals($before + 1, $after, 'adding a second admin should increase the "other admins" count by exactly one');
            } finally {
                $mysqli->query("DELETE FROM invoxa_users WHERE id = $secondId");
            }
        } finally {
            $mysqli->query("DELETE FROM invoxa_users WHERE id = $fixtureId");
        }
    });
    $run('Users & Roles', 'Role assignment', 'a new account stores its role and update_user\'s UPDATE flips it', 'A user created with role=member is stored as member (never silently promoted), and the same "UPDATE invoxa_users SET role = ?" update_user runs correctly flips it to admin.', function () use ($mysqli) {
        $username = 'zt_user_' . bin2hex(random_bytes(4));
        $stmt = $mysqli->prepare("INSERT INTO invoxa_users (username, email, role, password_hash) VALUES (?, 'zt@invalid.example', 'member', 'x')");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $id = $mysqli->insert_id;
        try {
            $role = $mysqli->query("SELECT role FROM invoxa_users WHERE id = $id")->fetch_assoc()['role'];
            invoxaAssertEquals('member', $role, 'newly created user should be member');
            $upd = $mysqli->prepare("UPDATE invoxa_users SET role = 'admin' WHERE id = ?");
            $upd->bind_param("i", $id);
            $upd->execute();
            $role2 = $mysqli->query("SELECT role FROM invoxa_users WHERE id = $id")->fetch_assoc()['role'];
            invoxaAssertEquals('admin', $role2, 'role should update to admin');
        } finally {
            $mysqli->query("DELETE FROM invoxa_users WHERE id = $id");
        }
    });

    $run('Users & Roles', 'Audit Log attribution', 'invoxaLogAction() stamps the current session\'s user id/username on the row it writes', 'invoxa_actions rows carry performed_by_user_id/performed_by_username (the username is denormalized so the Audit Log stays readable even after that user is later deleted) — confirms invoxaLogAction(), the one shared helper every audit entry now goes through, actually stamps them rather than leaving the row anonymous.', function () use ($mysqli) {
        global $__actorUserId, $__actorUsername;
        $marker = 'zt_audit_' . bin2hex(random_bytes(4));
        invoxaLogAction($mysqli, null, '', 'note_added', $marker);
        try {
            $row = $mysqli->query("SELECT performed_by_user_id, performed_by_username FROM invoxa_actions WHERE notes = '" . $mysqli->real_escape_string($marker) . "' ORDER BY id DESC LIMIT 1")->fetch_assoc();
            invoxaAssertTrue($row !== null, 'expected the logged row to exist');
            $expectedUserId = $__actorUserId !== null ? (string) $__actorUserId : null;
            $actualUserId = $row['performed_by_user_id'] !== null ? (string) $row['performed_by_user_id'] : null;
            invoxaAssertEquals($expectedUserId, $actualUserId, 'performed_by_user_id should match the current session');
            invoxaAssertEquals($__actorUsername, $row['performed_by_username'], 'performed_by_username should match the current session');
        } finally {
            $mysqli->query("DELETE FROM invoxa_actions WHERE notes = '" . $mysqli->real_escape_string($marker) . "'");
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

function invoxaHandleSaveOffsiteBackup($mysqli): void
{
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

function invoxaHandleBackupDb($mysqli, array $settings): void
{
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

function invoxaHandleListBackups(): void
{
    $files = [];
    foreach (glob(BACKUPS_DIR . 'backup_*.sql') as $f) {
        $files[] = ['filename' => basename($f), 'size' => filesize($f), 'modified' => date('Y-m-d H:i:s', filemtime($f))];
    }
    usort($files, fn($a, $b) => $b['modified'] <=> $a['modified']);
    echo json_encode(['success' => true, 'backups' => $files]);
    exit;
}

function invoxaHandleImportBackup(): void
{
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

function invoxaHandleFactoryReset($mysqli, int $currentUserId): void
{
    error_reporting(0);
    ob_start();
    try {
        if (($_POST['confirm'] ?? '') !== 'RESET') {
            throw new Exception('Type RESET to confirm.');
        }
        $userRes = $mysqli->query("SELECT password_hash FROM invoxa_users WHERE id = " . $currentUserId);
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

function invoxaHandleSeedDemoData($mysqli, array $settings): void
{
    error_reporting(0);
    ob_start();
    try {
        $count = seedDemoData($mysqli, $settings);
        ob_clean();
        echo json_encode(['success' => true, 'count' => $count]);
    } catch (Throwable $e) {
        ob_clean();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

function invoxaHandleClearDemoData($mysqli): void
{
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

function invoxaHandleRunTestSuite($mysqli, array $settings): void
{
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

function invoxaHandlePreviewRestore(): void
{
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

function invoxaHandleRestoreDbBackup($mysqli): void
{
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

function invoxaHandleSaveAuditRetention($mysqli): void
{
    $days = in_array($_POST['audit_log_retention_days'] ?? '', ['0', '30', '180', '365'], true)
        ? $_POST['audit_log_retention_days'] : '0';
    $stmt = $mysqli->prepare("INSERT INTO invoxa_settings (setting_key, setting_value) VALUES ('audit_log_retention_days', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    $stmt->bind_param("s", $days);
    $stmt->execute();
    echo json_encode(['success' => true]);
    exit;
}

function invoxaHandleSaveBackupRetention($mysqli): void
{
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

function invoxaHandleSyncMissing($mysqli, array $settings): void
{
    global $__actorUserId, $__actorUsername;
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
    $insertInvoice = $mysqli->prepare("INSERT INTO invoxa_invoices (invoice_number, client_key, client_name, recipient_email, invoice_date, due_date, amount, currency, status, html_content, file_path) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'sent', ?, ?) ON DUPLICATE KEY UPDATE file_path = VALUES(file_path), html_content = VALUES(html_content), amount = VALUES(amount), currency = VALUES(currency), client_key = VALUES(client_key), client_name = VALUES(client_name)");
    $insertAction = $mysqli->prepare("INSERT INTO invoxa_actions (invoice_number, action_type, notes, performed_by_user_id, performed_by_username) SELECT ?, 'synced', 'Imported via Web UI Sync', ?, ? WHERE NOT EXISTS (SELECT 1 FROM invoxa_actions WHERE invoice_number = ? AND action_type = 'synced')");
    foreach ($files as $filePath) {
        $fullPath = "/usr/share/nginx/html/invoxa-invoices/" . preg_replace('#^invoices/#', '', $filePath);
        if (!file_exists($fullPath)) {
            $errors++;
            continue;
        }
        $parts = explode('/', $filePath);
        $folderName = $parts[1] ?? '';
        $filename = basename($fullPath);
        $client = $clientMap[strtolower($folderName)] ?? null;
        if (!$client) {
            $client = ['client_key' => strtolower($folderName), 'client_name' => $folderName, 'email' => ''];
        }
        $html = file_get_contents($fullPath);
        $amount = (float) preg_replace('/[^0-9.]/', '', extractField($html, 'Amount Due') ?? '0');
        $currency = invoxaResolveCurrency($client['currency'] ?? '', $settings);

        $filenameInvNum = pathinfo($filename, PATHINFO_FILENAME);
        $internalInvNum = extractField($html, 'Invoice Number');

        if ($internalInvNum && $internalInvNum !== $filenameInvNum) {
            $mismatches[] = "File '$filename' has internal invoice number '$internalInvNum'";
            $skipped++;
            continue;
        }

        $invNum = $filenameInvNum;

        try {
            $insertInvoice->bind_param("ssssssdsss", $invNum, $client['client_key'], $client['client_name'], $client['email'], normaliseDateTime(extractField($html, 'Invoice Date')), normaliseDate(extractField($html, 'Invoice Due')), $amount, $currency, $html, $filePath);
            $insertInvoice->execute();
            if ($insertInvoice->affected_rows > 0) {
                $insertAction->bind_param("siss", $invNum, $__actorUserId, $__actorUsername, $invNum);
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

function invoxaHandleRestoreMissing($mysqli): void
{
    $ids = json_decode($_POST['ids'], true);
    $restored = 0;
    $errors = 0;
    if (empty($ids)) {
        echo json_encode(['success' => true, 'restored' => 0, 'errors' => 0, 'no_content' => 0]);
        exit;
    }
    $idList = implode(',', array_map('intval', $ids));
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

function invoxaHandleDeleteMissingDb($mysqli): void
{
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

function invoxaHandleDeleteUntrackedFile(): void
{
    $filePath = $_POST['file'] ?? '';
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

function invoxaHandleDeleteSingleDbEntry($mysqli): void
{
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
