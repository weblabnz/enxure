<?php
// Dashboard/Stats-tab rendering and the $mysqli-touching stats AJAX/GET
// endpoints (nav badge counts, DB table row counts, tax-year previews, the
// chart/stats JSON API). renderStatsSection() still pulls its ~50 $stats_*
// inputs via `global` from Data Fetching in invoxa.php — unchanged by this move.

// Max flash-card tidbits visible on the Dashboard at once (see $tidbits in
// renderDashboardStats() and the Customize menu's cap in page_script.php) —
// the tidbit row has more candidates than this so a business can pick which
// ones matter to them, but the row itself stays a fixed 4-wide strip.
const DASHBOARD_TIDBIT_VISIBLE_MAX = 4;

// Dashboard's alert strips + top "flash card" stats + charts — the parts
// that can change from actions taken elsewhere without the Dashboard tab
// being reloaded (see the ?api=table_html&which=dashboard_stats fragment
// endpoint). Two independent customizable regions, each with their own
// drag-reorder JS (initDashboardDragDrop/applyDashboardLayouts in
// page_script.php) deliberately separate from Statistics' own — saved per
// user in invoxa_stats_layout under the 'dashboard-tidbits'/'dashboard-charts'
// panes (see STATS_PANES below; that table/functions are pane-agnostic, so
// reusing them here doesn't touch Statistics' own behavior).
function renderDashboardStats($mysqli, int $currentUserId, array $settings, array $failedInvoices, array $overdueInvoices, array $total_invoiced_by_ccy, array $total_monthly_by_ccy, array $total_paid_by_ccy, int $client_count): string
{
    $outstanding_by_ccy = $total_invoiced_by_ccy;
    foreach ($total_paid_by_ccy as $ccy => $amount) {
        $outstanding_by_ccy[$ccy] = ($outstanding_by_ccy[$ccy] ?? 0) - $amount;
    }
    $allLayouts = invoxaGetStatsLayouts($mysqli, $currentUserId);
    $dashboardLayouts = ['dashboard-tidbits' => $allLayouts['dashboard-tidbits'] ?? [], 'dashboard-charts' => $allLayouts['dashboard-charts'] ?? []];
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
            <div><strong><?= count($overdueInvoices) ?> Overdue Invoices!</strong> You have
                <?= invoxaFormatMoneyByCurrency(invoxaGroupAmountsByCurrency($overdueInvoices, 'amount', $settings)) ?> in outstanding overdue
                payments.</div><button class="btn small"
                style="margin-left: auto; border-color: var(--danger); color: var(--danger);"
                onclick="nav('invoices'); document.getElementById('invoiceStatusFilter').value = 'overdue'; filterInvoicesByStatus('overdue');">View
                All</button>
        </div>
    <?php endif; ?>
    <div id="dashboardLayoutData" data-layouts="<?= htmlspecialchars(json_encode($dashboardLayouts), ENT_QUOTES) ?>" style="display:none"></div>
    <?php
    // The "flash card" tidbits — small, fixed-shape KPI tiles, unlike the
    // bigger cards below, so no width control; just reorder (drag-handle) and
    // show/hide via the Customize menu (renderDashboardWidgetMenu in
    // page_script.php), which also caps how many can be visible at once to
    // DASHBOARD_TIDBIT_VISIBLE_MAX — more candidates than fit in one row, so
    // a business picks which ones matter to them and swaps freely, without
    // the row growing unbounded. The last 3 start hidden; a saved layout (see
    // $dashboardLayouts above) overrides these defaults once the user's
    // customized anything.
    $outstanding_total = array_sum($outstanding_by_ccy);
    $tidbits = [
        ['id' => 'dash-total-invoiced', 'label' => 'Total Invoiced (All Time)', 'icon' => 'fa-sack-dollar', 'iconClass' => '', 'valueStyle' => '', 'value' => invoxaFormatMoneyByCurrency($total_invoiced_by_ccy), 'hidden' => false],
        // Invoiced-this-month, not paid-this-month — same "neutral volume" bucket
        // as Total Invoiced above, not the "money received" green used for
        // Total Paid, since nothing here confirms it's actually been collected.
        ['id' => 'dash-this-month', 'label' => 'This Month', 'icon' => 'fa-calendar-check', 'iconClass' => '', 'valueStyle' => '', 'value' => invoxaFormatMoneyByCurrency($total_monthly_by_ccy), 'hidden' => false],
        ['id' => 'dash-total-outstanding', 'label' => 'Total Outstanding', 'icon' => 'fa-hourglass-half', 'iconClass' => $outstanding_total > 0 ? 'warning' : 'success', 'valueStyle' => 'color: var(--' . ($outstanding_total > 0 ? 'warning' : 'success') . ')', 'value' => invoxaFormatMoneyByCurrency($outstanding_by_ccy), 'hidden' => false],
        ['id' => 'dash-active-clients', 'label' => 'Active Clients', 'icon' => 'fa-users', 'iconClass' => 'success', 'valueStyle' => 'color: var(--success)', 'value' => (string) $client_count, 'hidden' => false],
        ['id' => 'dash-total-paid', 'label' => 'Total Paid (All Time)', 'icon' => 'fa-circle-check', 'iconClass' => 'success', 'valueStyle' => 'color: var(--success)', 'value' => invoxaFormatMoneyByCurrency($total_paid_by_ccy), 'hidden' => true],
        ['id' => 'dash-overdue-count', 'label' => 'Overdue Invoices', 'icon' => 'fa-circle-exclamation', 'iconClass' => 'danger', 'valueStyle' => 'color: var(--danger)', 'value' => (string) count($overdueInvoices), 'hidden' => true],
        ['id' => 'dash-failed-emails', 'label' => 'Failed Emails', 'icon' => 'fa-triangle-exclamation', 'iconClass' => 'danger', 'valueStyle' => 'color: var(--danger)', 'value' => (string) count($failedInvoices), 'hidden' => true],
    ];
    ?>
    <div class="stats-grid dashboard-tidbit-row" data-dash-pane="dashboard-tidbits" data-max-visible="<?= DASHBOARD_TIDBIT_VISIBLE_MAX ?>">
        <?php foreach ($tidbits as $t): ?>
            <div class="card stat-card" data-card-id="<?= $t['id'] ?>" data-card-label="<?= htmlspecialchars($t['label']) ?>" data-card-hidden="<?= $t['hidden'] ? '1' : '0' ?>" draggable="true" style="margin-bottom:0;">
                <div class="card-drag-controls"><i class="fa-solid fa-grip-vertical drag-handle" title="Drag to reorder"></i></div>
                <div class="stat-card-top">
                    <div class="stat-title"><?= htmlspecialchars($t['label']) ?></div>
                    <div class="stat-icon <?= $t['iconClass'] ?>"><i class="fa-solid <?= $t['icon'] ?>"></i></div>
                </div>
                <div class="stat-value" style="<?= $t['valueStyle'] ?>"><?= $t['value'] ?></div>
            </div>
        <?php endforeach; ?>
    </div>
    <div class="dashboard-chart-row" data-dash-pane="dashboard-charts">
        <div class="card" data-card-id="dash-revenue-chart" data-card-width="4" data-card-label="Revenue Over Time" draggable="true" style="margin-bottom:0;">
            <div class="card-drag-controls"><i class="fa-solid fa-grip-vertical drag-handle" title="Drag to reorder"></i><button type="button" class="card-width-toggle" onclick="toggleDashboardChartWidth(this)" title="Cycle width (1/3, 1/2, 2/3, full)"><i class="fa-solid fa-arrows-left-right"></i><span class="width-label">1/2</span></button><button type="button" class="card-hide-toggle" onclick="hideDashboardCard(this)" title="Hide this widget"><i class="fa-solid fa-eye-slash"></i></button></div>
            <div class="card-header">
                <h3 style="margin:0; font-size:1rem;"><i class="fa-solid fa-chart-line"
                        style="color:var(--accent); margin-right:0.5rem;"></i>Revenue Over Time (Cumulative)
                </h3>
                <div style="display:flex; gap:0.5rem; align-items:center; margin-right:6rem;">
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
        <div class="card" data-card-id="dash-client-share-chart" data-card-width="2" data-card-label="Client Share" draggable="true" style="margin-bottom:0; display:flex; flex-direction:column;">
            <div class="card-drag-controls"><i class="fa-solid fa-grip-vertical drag-handle" title="Drag to reorder"></i><button type="button" class="card-width-toggle" onclick="toggleDashboardChartWidth(this)" title="Cycle width (1/3, 1/2, 2/3, full)"><i class="fa-solid fa-arrows-left-right"></i><span class="width-label">1/2</span></button><button type="button" class="card-hide-toggle" onclick="hideDashboardCard(this)" title="Hide this widget"><i class="fa-solid fa-eye-slash"></i></button></div>
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
    <?php
    return ob_get_clean();
}

// Panes with a draggable/reorderable card grid, saved per pane in
// invoxa_stats_layout — the Statistics subnav tabs (their own drag/resize
// logic in page_script.php, unrelated to Dashboard's), plus
// 'dashboard-tidbits'/'dashboard-charts' for the Dashboard tab's own stat
// cards and charts (see renderDashboardStats() — separate drag-reorder logic
// there too, so nothing here couples the two).
const STATS_PANES = ['dashboard-tidbits', 'dashboard-charts', 'revenue', 'forecasting', 'clients', 'expenses', 'tax', 'activity', 'system'];

// Per-user card order/width/hidden state, saved by save_stats_layout below
// and applied client-side — Statistics via applyStatsLayouts/
// toggleStatsCardWidth/initStatsDragDrop, Dashboard via its own
// applyDashboardLayouts/toggleDashboardChartWidth/hideDashboardCard/
// initDashboardDragDrop (all in page_script.php). The server always renders
// every pane's cards in their default order and visible, so a stale/corrupt
// saved layout can never break the page, only leave a user's customization
// unapplied until they re-save it. 'width' means different things per pane
// (Statistics: 1 or 2, half/full; dashboard-charts: a 6-unit grid span) —
// intentionally not validated against pane-specific semantics here, just
// clamped to a sane range, since this layer only persists opaque per-card
// layout data and never needs to interpret it.
function invoxaGetStatsLayouts($mysqli, int $userId): array
{
    $layouts = array_fill_keys(STATS_PANES, []);
    if ($userId <= 0) {
        return $layouts;
    }
    $res = $mysqli->query("SELECT pane, layout_json FROM invoxa_stats_layout WHERE user_id = " . $userId);
    while ($res && $row = $res->fetch_assoc()) {
        if (!in_array($row['pane'], STATS_PANES, true)) {
            continue;
        }
        $decoded = json_decode($row['layout_json'], true);
        if (is_array($decoded)) {
            $layouts[$row['pane']] = $decoded;
        }
    }
    return $layouts;
}

function invoxaHandleSaveStatsLayout($mysqli, int $currentUserId): void
{
    if ($currentUserId <= 0) {
        throw new Exception('Not logged in');
    }
    $pane = $_POST['pane'] ?? '';
    if (!in_array($pane, STATS_PANES, true)) {
        throw new Exception('Unknown pane');
    }
    $layout = json_decode($_POST['layout'] ?? '', true);
    if (!is_array($layout)) {
        throw new Exception('Invalid layout');
    }
    $clean = [];
    foreach (array_slice($layout, 0, 40) as $entry) {
        if (!is_array($entry) || !isset($entry['id']) || !is_string($entry['id']) || !preg_match('/^[a-z0-9-]{1,60}$/', $entry['id'])) {
            continue;
        }
        $width = max(1, min(12, (int) ($entry['width'] ?? 1)));
        $col = (int) ($entry['col'] ?? 0) === 1 ? 1 : 0;
        $hidden = !empty($entry['hidden']);
        $clean[] = ['id' => $entry['id'], 'width' => $width, 'col' => $col, 'hidden' => $hidden];
    }
    $json = json_encode($clean);
    $stmt = $mysqli->prepare("INSERT INTO invoxa_stats_layout (user_id, pane, layout_json) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE layout_json = VALUES(layout_json)");
    $stmt->bind_param('iss', $currentUserId, $pane, $json);
    $stmt->execute();
    echo json_encode(['success' => true]);
    exit;
}

// The entire Statistics & Forecasting tab — read-only, derived-on-render
// content with no client-side state to preserve, so it renders the whole tab
// body rather than being split row-by-row like the functions above. Pulls
// its ~15 $stats_* inputs via `global` rather than a long parameter list.
function renderStatsSection(): string
{
    global $licenseValid, $currentCron, $cronEnabled, $mysqli, $currentUserId;
    global $taxYearLabel, $stats_ty_invoiced, $stats_ty_paid, $stats_ty_outstanding,
    $stats_ty_invoiced_by_ccy, $stats_ty_paid_by_ccy, $stats_ty_outstanding_by_ccy,
    $stats_all_time_revenue_by_ccy, $stats_outstanding_revenue, $stats_outstanding_revenue_by_ccy, $stats_overdue_count, $stats_mrr, $stats_mrr_by_ccy, $stats_avg_invoice_by_ccy,
    $stats_12m_projected, $stats_avg_days, $stats_active_clients, $stats_inactive_clients, $stats_client_ratio,
    $top_clients, $stats_db_rows, $backup_count, $latest_backup, $all_tables_info,
    $stats_void_count, $stats_void_amount_by_ccy, $stats_quote_pipeline_count, $stats_quote_pipeline_value_by_ccy, $stats_aging,
    $stats_new_clients_month, $stats_billing_freq, $clients_needing_attention,
    $stats_email_sent, $stats_email_failed, $stats_email_total, $stats_email_success_rate,
    $stats_ty_monthly, $stats_tax_year_days_total, $stats_tax_year_days_elapsed, $stats_tax_year_progress_pct,
    $stats_last_recurring_run, $stats_late_fees_charged, $stats_reminders_sent, $stats_reminders_failed,
    $most_active_clients, $stats_invoice_status, $stats_revenue_trend, $stats_expense_ty_total, $stats_net_income_ty,
    $stats_expense_categories, $stats_expense_monthly, $stats_db_size_bytes, $stats_invoices_dir_size_bytes,
    $stats_backups_dir_size_bytes, $stats_webhook_unmatched_total, $stats_webhook_unmatched_30d,
    $stats_php_version, $stats_mysql_version, $stats_default_ccy, $stats_has_other_currency,
    $stats_fx_unconverted_currencies;
    $statsLayouts = invoxaGetStatsLayouts($mysqli, $currentUserId);
    ob_start();
    ?>
    <h2 class="page-title">Data Statistics &amp; Forecasting</h2>
    <?php // A data attribute (not a <script> tag) because refreshStatsSection() swaps
    // this markup in via innerHTML, which never executes embedded <script> tags. ?>
    <div id="statsLayoutData" data-layouts="<?= htmlspecialchars(json_encode($statsLayouts), ENT_QUOTES) ?>" style="display:none"></div>
    <?php if ($stats_has_other_currency && empty($stats_fx_unconverted_currencies)): ?>
        <div class="card" style="border-left:3px solid var(--accent); margin: 0 1.5rem 1.75rem;">
            <div class="card-body" style="display:flex; align-items:center; gap:0.75rem; padding:1rem 1.25rem;">
                <i class="fa-solid fa-right-left" style="color:var(--accent); font-size:1.1rem;"></i>
                <div><strong>Charts, Forecasting &amp; AR Aging blend every currency into <?= htmlspecialchars($stats_default_ccy) ?>, converted at a cached daily rate.</strong>
                    <span style="color:var(--text-secondary); font-size:0.85rem; display:block; margin-top:0.15rem;">
                        Set the rate provider under Settings &gt; Finance. Every other total, table, and export on this page still shows each currency separately too.</span>
                </div>
            </div>
        </div>
    <?php elseif ($stats_has_other_currency): ?>
        <div class="card" style="border-left:3px solid var(--warning); margin: 0 1.5rem 1.75rem;">
            <div class="card-body" style="display:flex; align-items:center; gap:0.75rem; padding:1rem 1.25rem;">
                <i class="fa-solid fa-triangle-exclamation" style="color:var(--warning); font-size:1.1rem;"></i>
                <div><strong>Charts, Forecasting &amp; AR Aging exclude <?= htmlspecialchars(implode(', ', $stats_fx_unconverted_currencies)) ?> — no exchange rate available.</strong>
                    <span style="color:var(--text-secondary); font-size:0.85rem; display:block; margin-top:0.15rem;">
                        Check the provider under Settings &gt; Finance; other currencies here are still converted normally. Every other total, table, and export on this page groups in every currency instead.</span>
                </div>
            </div>
        </div>
    <?php endif; ?>
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
            <button type="button" class="subnav-item" data-stats-target="expenses"
                onclick="navStats('expenses')"><i class="fa-solid fa-receipt"></i> Expenses</button>
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
                <div class="stats-columns" data-stats-pane="revenue">
                    <div class="card" data-card-id="rev-financial-summary" data-card-width="1" data-card-col="0" draggable="true" style="margin-bottom:0;">
                        <div class="card-drag-controls"><i class="fa-solid fa-grip-vertical drag-handle" title="Drag to reorder"></i><button type="button" class="card-width-toggle" onclick="toggleStatsCardWidth(this)" title="Toggle full width"><i class="fa-solid fa-expand"></i></button></div>
                        <div class="card-header">
                            <h3>Financial Summary (All-Time)</h3>
                        </div>
                        <div class="card-body">
                            <div class="stats-grid" style="margin-bottom: 0;">
                                <div class="stat-card" style="border-top: 3px solid var(--success);">
                                    <div class="label">All-Time Revenue</div>
                                    <div class="value"><?= invoxaFormatMoneyByCurrency($stats_all_time_revenue_by_ccy) ?></div>
                                </div>
                                <div class="stat-card"
                                    style="border-top: 3px solid <?= $stats_outstanding_revenue > 0 ? 'var(--warning)' : 'var(--success)' ?>;">
                                    <div class="label">Outstanding Receivables</div>
                                    <div class="value"><?= invoxaFormatMoneyByCurrency($stats_outstanding_revenue_by_ccy) ?> <span
                                            style="font-size: 1rem; color: var(--text-secondary); font-weight: normal;">(<?= $stats_overdue_count ?>
                                            overdue)</span></div>
                                </div>
                                <div class="stat-card" style="border-top: 3px solid var(--success);">
                                    <div class="label">Monthly Recurring (<span class="has-tooltip"
                                            data-tip="Monthly Recurring Revenue — total fixed monthly fees from active clients">MRR</span>)
                                    </div>
                                    <div class="value"><?= invoxaFormatMoneyByCurrency($stats_mrr_by_ccy) ?></div>
                                </div>
                                <div class="stat-card" style="border-top: 3px solid var(--accent);">
                                    <div class="label">Average Invoice Value</div>
                                    <div class="value"><?= invoxaFormatMoneyByCurrency($stats_avg_invoice_by_ccy) ?></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card" data-card-id="rev-trend" data-card-width="1" data-card-col="0" draggable="true" style="margin-bottom:0;">
                        <div class="card-drag-controls"><i class="fa-solid fa-grip-vertical drag-handle" title="Drag to reorder"></i><button type="button" class="card-width-toggle" onclick="toggleStatsCardWidth(this)" title="Toggle full width"><i class="fa-solid fa-expand"></i></button></div>
                        <div class="card-header">
                            <h3>Revenue Trend (Last 12 Months)</h3>
                        </div>
                        <div class="card-body">
                            <div style="height:220px; position:relative;"><canvas id="revenueTrendChart"></canvas></div>
                        </div>
                        <script>
                            window.__revenueTrendData = <?= json_encode($stats_revenue_trend) ?>;
                        </script>
                    </div>

                    <div class="card" data-card-id="rev-quotes-voided" data-card-width="1" data-card-col="0" draggable="true" style="margin-bottom:0;">
                        <div class="card-drag-controls"><i class="fa-solid fa-grip-vertical drag-handle" title="Drag to reorder"></i><button type="button" class="card-width-toggle" onclick="toggleStatsCardWidth(this)" title="Toggle full width"><i class="fa-solid fa-expand"></i></button></div>
                        <div class="card-header">
                            <h3>Quotes &amp; Voided Invoices</h3>
                        </div>
                        <div class="card-body">
                            <div class="stats-grid" style="margin-bottom: 0;">
                                <div class="stat-card" style="border-top: 3px solid #8b5cf6;">
                                    <div class="label">Open Quote Pipeline</div>
                                    <div class="value"><?= invoxaFormatMoneyByCurrency($stats_quote_pipeline_value_by_ccy) ?> <span
                                            style="font-size: 1rem; color: var(--text-secondary); font-weight: normal;">(<?= $stats_quote_pipeline_count ?>
                                            open)</span></div>
                                </div>
                                <div class="stat-card" style="border-top: 3px solid var(--text-secondary);">
                                    <div class="label">Voided (All-Time)</div>
                                    <div class="value"><?= invoxaFormatMoneyByCurrency($stats_void_amount_by_ccy) ?> <span
                                            style="font-size: 1rem; color: var(--text-secondary); font-weight: normal;">(<?= $stats_void_count ?>
                                            invoice<?= $stats_void_count === 1 ? '' : 's' ?>)</span></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card" data-card-id="rev-tax-year-summary" data-card-width="1" data-card-col="1" draggable="true" style="margin-bottom:0;">
                        <div class="card-drag-controls"><i class="fa-solid fa-grip-vertical drag-handle" title="Drag to reorder"></i><button type="button" class="card-width-toggle" onclick="toggleStatsCardWidth(this)" title="Toggle full width"><i class="fa-solid fa-expand"></i></button></div>
                        <div class="card-header">
                            <h3>Tax Year Summary (<?= $taxYearLabel ?>)</h3>
                        </div>
                        <div class="card-body">
                            <div class="stats-grid" style="margin-bottom: 0;">
                                <div class="stat-card" style="border-top: 3px solid var(--accent);">
                                    <div class="label">Total Invoiced</div>
                                    <div class="value"><?= invoxaFormatMoneyByCurrency($stats_ty_invoiced_by_ccy) ?></div>
                                </div>
                                <div class="stat-card" style="border-top: 3px solid var(--success);">
                                    <div class="label">Total Paid</div>
                                    <div class="value"><?= invoxaFormatMoneyByCurrency($stats_ty_paid_by_ccy) ?></div>
                                </div>
                                <div class="stat-card"
                                    style="border-top: 3px solid <?= $stats_ty_outstanding > 0 ? 'var(--warning)' : 'var(--success)' ?>;">
                                    <div class="label">Outstanding</div>
                                    <div class="value"><?= invoxaFormatMoneyByCurrency($stats_ty_outstanding_by_ccy) ?></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card" data-card-id="rev-breakdown" data-card-width="1" data-card-col="1" draggable="true" style="margin-bottom:0;">
                        <div class="card-drag-controls"><i class="fa-solid fa-grip-vertical drag-handle" title="Drag to reorder"></i><button type="button" class="card-width-toggle" onclick="toggleStatsCardWidth(this)" title="Toggle full width"><i class="fa-solid fa-expand"></i></button></div>
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

                    <div class="card" data-card-id="rev-invoice-status" data-card-width="1" data-card-col="1" draggable="true" style="margin-bottom:0;">
                        <div class="card-drag-controls"><i class="fa-solid fa-grip-vertical drag-handle" title="Drag to reorder"></i><button type="button" class="card-width-toggle" onclick="toggleStatsCardWidth(this)" title="Toggle full width"><i class="fa-solid fa-expand"></i></button></div>
                        <div class="card-header">
                            <h3>Invoice Status Breakdown</h3>
                        </div>
                        <?php if (!empty($stats_invoice_status)): ?>
                            <div class="card-body">
                                <div style="height:220px; position:relative;"><canvas id="invoiceStatusChart"></canvas></div>
                            </div>
                            <script>
                                window.__invoiceStatusData = <?= json_encode($stats_invoice_status) ?>;
                            </script>
                        <?php else: ?>
                            <div class="card-body">
                                <p style="color:var(--text-secondary); margin:0;">No invoices yet.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Forecasting -->
            <div class="subnav-pane" id="stats-pane-forecasting">
                <div class="stats-columns" data-stats-pane="forecasting">
                    <div class="card" data-card-id="fc-12month" data-card-width="1" draggable="true" style="margin-bottom:0;">
                        <div class="card-drag-controls"><i class="fa-solid fa-grip-vertical drag-handle" title="Drag to reorder"></i><button type="button" class="card-width-toggle" onclick="toggleStatsCardWidth(this)" title="Toggle full width"><i class="fa-solid fa-expand"></i></button></div>
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
                                style="display:flex; justify-content:space-between; border-bottom:1px solid color-mix(in srgb, var(--success) 30%, transparent); padding-bottom:0.6rem; background:color-mix(in srgb, var(--success) 8%, transparent); border-radius:6px; padding:0.5rem 0.6rem; margin-bottom:0.25rem;">
                                <span style="color:var(--success); font-weight:600;">Expected Yearly Value:</span>
                                <strong style="color:var(--success);"><?= htmlspecialchars($stats_default_ccy) ?> $<?= number_format($stats_12m_projected, 2) ?></strong>
                            </li>
                            <li
                                style="display:flex; justify-content:space-between; border-bottom:1px solid rgba(255,255,255,0.05); padding-bottom:0.6rem;">
                                <span style="color:var(--text-secondary);">Recurring (<span class="has-tooltip"
                                        data-tip="Monthly Recurring Revenue × 12 months">MRR</span> × 12):</span>
                                <strong style="color:var(--success);"><?= htmlspecialchars($stats_default_ccy) ?> $<?= number_format($stats_mrr * 12, 2) ?></strong>
                            </li>
                            <li
                                style="display:flex; justify-content:space-between; border-bottom:1px solid rgba(255,255,255,0.05); padding-bottom:0.6rem;">
                                <span style="color:var(--text-secondary);">Outstanding Invoices:</span>
                                <strong
                                    style="color:var(--warning);"><?= htmlspecialchars($stats_default_ccy) ?> $<?= number_format($stats_outstanding_revenue, 2) ?></strong>
                            </li>
                            <li
                                style="display:flex; justify-content:space-between; border-bottom:1px solid rgba(255,255,255,0.05); padding-bottom:0.6rem;">
                                <span style="color:var(--text-secondary);"><span class="has-tooltip"
                                        data-tip="MRR alone — what you'd expect month-to-month once the current outstanding balance is collected, not a smoothed average of the one-off backlog">Recurring
                                        Monthly Avg</span>:</span>
                                <strong style="color:var(--success);"><?= htmlspecialchars($stats_default_ccy) ?> $<?= number_format($stats_mrr, 2) ?></strong>
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

                    <div class="card" data-card-id="fc-ar-aging" data-card-width="1" draggable="true" style="margin-bottom:0;">
                        <div class="card-drag-controls"><i class="fa-solid fa-grip-vertical drag-handle" title="Drag to reorder"></i><button type="button" class="card-width-toggle" onclick="toggleStatsCardWidth(this)" title="Toggle full width"><i class="fa-solid fa-expand"></i></button></div>
                        <div class="card-header">
                            <h3>Accounts Receivable Aging</h3>
                        </div>
                    <div class="card-body">
                        <p style="color:var(--text-secondary); margin-bottom: 1rem; font-size:0.875rem;">How overdue
                            the currently outstanding balance is, bucketed by days past due date.</p>
                        <?php if (array_sum(array_column($stats_aging, 'amount')) > 0): ?>
                            <div style="height:160px; position:relative; margin-bottom:1.25rem;"><canvas id="arAgingChart"></canvas></div>
                            <script>
                                window.__arAgingData = <?= json_encode($stats_aging) ?>;
                            </script>
                        <?php endif; ?>
                        <?php $stats_aging_max = max(1, ...array_column($stats_aging, 'amount')); ?>
                        <ul style="list-style:none; padding:0; margin:0; display:flex; flex-direction:column; gap:0.75rem;">
                            <?php foreach ($stats_aging as $bucket): ?>
                                <li>
                                    <div style="display:flex; justify-content:space-between; margin-bottom:0.3rem; font-size:0.85rem;">
                                        <span style="color:var(--text-secondary);"><?= htmlspecialchars($bucket['label']) ?>
                                            <span style="color:var(--text-secondary);">(<?= $bucket['count'] ?>)</span></span>
                                        <strong><?= htmlspecialchars($stats_default_ccy) ?> $<?= number_format($bucket['amount'], 2) ?></strong>
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
            </div>

            <!-- Clients -->
            <div class="subnav-pane" id="stats-pane-clients">
                <div class="stats-columns" data-stats-pane="clients">
                    <div class="card" data-card-id="cl-payment-insights" data-card-width="1" draggable="true" style="margin-bottom:0;">
                        <div class="card-drag-controls"><i class="fa-solid fa-grip-vertical drag-handle" title="Drag to reorder"></i><button type="button" class="card-width-toggle" onclick="toggleStatsCardWidth(this)" title="Toggle full width"><i class="fa-solid fa-expand"></i></button></div>
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
                                    <strong style="color:var(--success);"><?= $stats_active_clients ?></strong>
                                </li>
                                <li
                                    style="display: flex; justify-content: space-between; border-bottom: 1px solid rgba(255,255,255,0.05); padding-bottom: 0.5rem;">
                                    <span style="color:var(--text-secondary);">Inactive Clients:</span>
                                    <strong style="color:var(--danger);"><?= $stats_inactive_clients ?></strong>
                                </li>
                                <li
                                    style="display: flex; justify-content: space-between; border-bottom: 1px solid rgba(255,255,255,0.05); padding-bottom: 0.5rem;">
                                    <span style="color:var(--text-secondary);">Active/Inactive Ratio:</span>
                                    <strong><?= $stats_client_ratio ?></strong>
                                </li>
                                <li
                                    style="display: flex; justify-content: space-between; border-bottom: 1px solid rgba(255,255,255,0.05); padding-bottom: 0.5rem;">
                                    <span style="color:var(--text-secondary);">New Clients This Month:</span>
                                    <strong style="color:var(--success);">+<?= $stats_new_clients_month ?></strong>
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

                    <div class="card" data-card-id="cl-top-clients" data-card-width="1" draggable="true" style="margin-bottom:0;">
                        <div class="card-drag-controls"><i class="fa-solid fa-grip-vertical drag-handle" title="Drag to reorder"></i><button type="button" class="card-width-toggle" onclick="toggleStatsCardWidth(this)" title="Toggle full width"><i class="fa-solid fa-expand"></i></button></div>
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
                                                <?= ($index == 0) ? '<i class="fa-solid fa-crown" style="color: var(--warning); margin-right: 0.5rem;"></i>' : '' ?>
                                                <?= htmlspecialchars($tc['client_name']) ?>
                                            </td>
                                            <td style="padding: 1rem; text-align: right; font-weight: 600; color: var(--success);">
                                                <?= invoxaFormatMoneyByCurrency($tc['by_ccy']) ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                    <div class="card" data-card-id="cl-needing-attention" data-card-width="1" draggable="true" style="margin-bottom:0;">
                        <div class="card-drag-controls"><i class="fa-solid fa-grip-vertical drag-handle" title="Drag to reorder"></i><button type="button" class="card-width-toggle" onclick="toggleStatsCardWidth(this)" title="Toggle full width"><i class="fa-solid fa-expand"></i></button></div>
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

            <!-- Expenses -->
            <div class="subnav-pane" id="stats-pane-expenses">
                <div class="stats-columns" data-stats-pane="expenses">
                    <div class="card" data-card-id="ex-pl-summary" data-card-width="2" draggable="true" style="margin-bottom:0;">
                        <div class="card-drag-controls"><i class="fa-solid fa-grip-vertical drag-handle" title="Drag to reorder"></i><button type="button" class="card-width-toggle" onclick="toggleStatsCardWidth(this)" title="Toggle full width"><i class="fa-solid fa-expand"></i></button></div>
                        <div class="card-header">
                            <h3>Profit &amp; Loss (<?= htmlspecialchars($taxYearLabel) ?>)</h3>
                        </div>
                        <div class="card-body">
                            <div class="stats-grid" style="margin-bottom: 0;">
                                <div class="stat-card" style="border-top: 3px solid var(--success);">
                                    <div class="label">Revenue Received</div>
                                    <div class="value"><?= htmlspecialchars($stats_default_ccy) ?> $<?= number_format($stats_ty_paid, 2) ?></div>
                                </div>
                                <div class="stat-card" style="border-top: 3px solid var(--danger);">
                                    <div class="label">Expenses</div>
                                    <div class="value"><?= htmlspecialchars($stats_default_ccy) ?> $<?= number_format($stats_expense_ty_total, 2) ?></div>
                                </div>
                                <div class="stat-card"
                                    style="border-top: 3px solid <?= $stats_net_income_ty >= 0 ? 'var(--success)' : 'var(--danger)' ?>;">
                                    <div class="label">Net Income</div>
                                    <div class="value"><?= htmlspecialchars($stats_default_ccy) ?> $<?= number_format($stats_net_income_ty, 2) ?></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card" data-card-id="ex-by-category" data-card-width="1" draggable="true" style="margin-bottom:0;">
                        <div class="card-drag-controls"><i class="fa-solid fa-grip-vertical drag-handle" title="Drag to reorder"></i><button type="button" class="card-width-toggle" onclick="toggleStatsCardWidth(this)" title="Toggle full width"><i class="fa-solid fa-expand"></i></button></div>
                        <div class="card-header">
                            <h3>Expenses by Category</h3>
                        </div>
                        <?php if (!empty($stats_expense_categories)): ?>
                            <div class="card-body">
                                <div style="height:220px; position:relative;"><canvas id="expenseCategoryChart"></canvas></div>
                            </div>
                            <script>
                                window.__expenseCategoryData = <?= json_encode($stats_expense_categories) ?>;
                            </script>
                        <?php else: ?>
                            <div class="card-body">
                                <p style="color:var(--text-secondary); margin:0;">No expenses logged this tax year.</p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="card" data-card-id="ex-over-time" data-card-width="1" draggable="true" style="margin-bottom:0;">
                        <div class="card-drag-controls"><i class="fa-solid fa-grip-vertical drag-handle" title="Drag to reorder"></i><button type="button" class="card-width-toggle" onclick="toggleStatsCardWidth(this)" title="Toggle full width"><i class="fa-solid fa-expand"></i></button></div>
                        <div class="card-header">
                            <h3>Expenses Over Time</h3>
                        </div>
                        <?php if (!empty($stats_expense_monthly)): ?>
                            <div class="card-body">
                                <div style="height:220px; position:relative;"><canvas id="expenseTrendChart"></canvas></div>
                            </div>
                            <script>
                                window.__expenseTrendData = <?= json_encode($stats_expense_monthly) ?>;
                            </script>
                        <?php else: ?>
                            <div class="card-body">
                                <p style="color:var(--text-secondary); margin:0;">No expenses logged this tax year.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Tax & Compliance -->
            <div class="subnav-pane" id="stats-pane-tax">
                <div class="stats-columns" data-stats-pane="tax">
                    <div class="card" data-card-id="tax-year-progress" data-card-width="2" draggable="true" style="margin-bottom:0;">
                        <div class="card-drag-controls"><i class="fa-solid fa-grip-vertical drag-handle" title="Drag to reorder"></i><button type="button" class="card-width-toggle" onclick="toggleStatsCardWidth(this)" title="Toggle full width"><i class="fa-solid fa-expand"></i></button></div>
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

                    <div class="card" data-card-id="tax-monthly-breakdown" data-card-width="2" draggable="true" style="margin-bottom:0;">
                        <div class="card-drag-controls"><i class="fa-solid fa-grip-vertical drag-handle" title="Drag to reorder"></i><button type="button" class="card-width-toggle" onclick="toggleStatsCardWidth(this)" title="Toggle full width"><i class="fa-solid fa-expand"></i></button></div>
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
                                            <td style="padding: 1rem; text-align: right;"><?= invoxaFormatMoneyByCurrency($tym['by_ccy']['invoiced']) ?></td>
                                            <td style="padding: 1rem; text-align: right; color:var(--success);"><?= invoxaFormatMoneyByCurrency($tym['by_ccy']['paid']) ?></td>
                                            <td style="padding: 1rem; text-align: right; color:var(--warning);"><?= invoxaFormatMoneyByCurrency($tym['by_ccy']['outstanding']) ?></td>
                                            <td style="padding: 1rem; text-align: right;"><?= (int) $tym['unpaid_count'] ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    </div>
                </div>
            </div>

            <!-- Activity -->
            <div class="subnav-pane" id="stats-pane-activity">
                <div class="stats-columns" data-stats-pane="activity">
                    <div class="card" data-card-id="act-most-active-clients" data-card-width="1" draggable="true" style="margin-bottom:0;">
                        <div class="card-drag-controls"><i class="fa-solid fa-grip-vertical drag-handle" title="Drag to reorder"></i><button type="button" class="card-width-toggle" onclick="toggleStatsCardWidth(this)" title="Toggle full width"><i class="fa-solid fa-expand"></i></button></div>
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

                    <div class="card" data-card-id="act-recurring-billing" data-card-width="1" draggable="true" style="margin-bottom:0;">
                        <div class="card-drag-controls"><i class="fa-solid fa-grip-vertical drag-handle" title="Drag to reorder"></i><button type="button" class="card-width-toggle" onclick="toggleStatsCardWidth(this)" title="Toggle full width"><i class="fa-solid fa-expand"></i></button></div>
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
                </div>
            </div>

            <!-- System -->
            <div class="subnav-pane" id="stats-pane-system">
                <div class="stats-columns" data-stats-pane="system">
                    <div class="card" data-card-id="sys-email-health" data-card-width="1" data-card-col="0" draggable="true" style="margin-bottom:0;">
                        <div class="card-drag-controls"><i class="fa-solid fa-grip-vertical drag-handle" title="Drag to reorder"></i><button type="button" class="card-width-toggle" onclick="toggleStatsCardWidth(this)" title="Toggle full width"><i class="fa-solid fa-expand"></i></button></div>
                        <div class="card-header">
                            <h3>Email Delivery Health</h3>
                        </div>
                        <div class="card-body">
                            <div style="display: flex; gap: 2rem; flex-wrap: wrap; align-items:center;">
                                <div>
                                    <p style="color:var(--text-secondary); margin-bottom: 0.5rem;">Success Rate (All-Time):</p>
                                    <div style="font-size: 1.5rem; font-weight: 700; color: <?= $stats_email_success_rate >= 95 ? 'var(--success)' : ($stats_email_success_rate >= 80 ? 'var(--warning)' : 'var(--danger)') ?>;">
                                        <?= $stats_email_success_rate ?>%</div>
                                </div>
                                <div>
                                    <p style="color:var(--text-secondary); margin-bottom: 0.5rem;">Sent:</p>
                                    <div style="font-size: 1.2rem; font-weight: 700; color:var(--success);"><?= number_format($stats_email_sent) ?></div>
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

                    <div class="card" data-card-id="sys-environment" data-card-width="1" data-card-col="0" draggable="true" style="margin-bottom:0;">
                        <div class="card-drag-controls"><i class="fa-solid fa-grip-vertical drag-handle" title="Drag to reorder"></i><button type="button" class="card-width-toggle" onclick="toggleStatsCardWidth(this)" title="Toggle full width"><i class="fa-solid fa-expand"></i></button></div>
                        <div class="card-header">
                            <h3>Environment</h3>
                        </div>
                        <div class="card-body">
                            <div style="display: flex; gap: 2rem; flex-wrap: wrap;">
                                <div>
                                    <p style="color:var(--text-secondary); margin-bottom: 0.5rem;">PHP Version:</p>
                                    <div style="font-size: 1.1rem; font-weight: 700;"><?= htmlspecialchars($stats_php_version) ?></div>
                                </div>
                                <div>
                                    <p style="color:var(--text-secondary); margin-bottom: 0.5rem;">MySQL Version:</p>
                                    <div style="font-size: 1.1rem; font-weight: 700;"><?= htmlspecialchars($stats_mysql_version) ?></div>
                                </div>
                                <div>
                                    <p style="color:var(--text-secondary); margin-bottom: 0.5rem;">App Version:</p>
                                    <div style="font-size: 1.1rem; font-weight: 700;"><?= htmlspecialchars(APP_VERSION) ?></div>
                                </div>
                                <div>
                                    <p style="color:var(--text-secondary); margin-bottom: 0.5rem;">Timezone:</p>
                                    <div style="font-size: 1.1rem; font-weight: 700;"><?= htmlspecialchars(date_default_timezone_get()) ?></div>
                                </div>
                                <div>
                                    <p style="color:var(--text-secondary); margin-bottom: 0.5rem;">License:</p>
                                    <div style="font-size: 1.1rem; font-weight: 700; color:<?= $licenseValid ? 'var(--success)' : 'var(--warning)' ?>;">
                                        <?= $licenseValid ? 'Licensed' : 'Unlicensed' ?></div>
                                </div>
                                <div>
                                    <p style="color:var(--text-secondary); margin-bottom: 0.5rem;">Recurring Billing Cron:</p>
                                    <div style="font-size: 1.1rem; font-weight: 700;"><code><?= htmlspecialchars($currentCron) ?></code>
                                    </div>
                                    <div style="font-size: 0.8rem; color:<?= $cronEnabled ? 'var(--success)' : 'var(--text-secondary)' ?>;">
                                        <?= $cronEnabled ? 'Enabled' : 'Disabled' ?></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card" data-card-id="sys-storage-footprint" data-card-width="1" data-card-col="0" draggable="true" style="margin-bottom:0;">
                        <div class="card-drag-controls"><i class="fa-solid fa-grip-vertical drag-handle" title="Drag to reorder"></i><button type="button" class="card-width-toggle" onclick="toggleStatsCardWidth(this)" title="Toggle full width"><i class="fa-solid fa-expand"></i></button></div>
                        <div class="card-header">
                            <h3>Storage Footprint</h3>
                        </div>
                        <div class="card-body">
                            <div style="height:150px; position:relative; margin-bottom:0.75rem;"><canvas id="storageFootprintChart"></canvas></div>
                            <p style="color:var(--text-secondary); font-size:0.8rem; margin:0;">Total: <?= htmlspecialchars(invoxaFormatBytes($stats_db_size_bytes + $stats_invoices_dir_size_bytes + $stats_backups_dir_size_bytes)) ?></p>
                        </div>
                        <script>
                            window.__storageFootprintData = {
                                db: <?= (int) $stats_db_size_bytes ?>,
                                invoices: <?= (int) $stats_invoices_dir_size_bytes ?>,
                                backups: <?= (int) $stats_backups_dir_size_bytes ?>,
                                labels: {
                                    db: <?= json_encode('Database (' . invoxaFormatBytes($stats_db_size_bytes) . ')') ?>,
                                    invoices: <?= json_encode('Invoices (' . invoxaFormatBytes($stats_invoices_dir_size_bytes) . ')') ?>,
                                    backups: <?= json_encode('Backups (' . invoxaFormatBytes($stats_backups_dir_size_bytes) . ')') ?>
                                }
                            };
                        </script>
                    </div>

                    <div class="card" data-card-id="sys-webhook-health" data-card-width="1" data-card-col="1" draggable="true" style="margin-bottom:0;">
                        <div class="card-drag-controls"><i class="fa-solid fa-grip-vertical drag-handle" title="Drag to reorder"></i><button type="button" class="card-width-toggle" onclick="toggleStatsCardWidth(this)" title="Toggle full width"><i class="fa-solid fa-expand"></i></button></div>
                        <div class="card-header">
                            <h3>Webhook Health</h3>
                        </div>
                        <div class="card-body">
                            <div style="display: flex; gap: 2rem; flex-wrap: wrap;">
                                <div>
                                    <p style="color:var(--text-secondary); margin-bottom: 0.5rem;">Unmatched (Last 30 Days):</p>
                                    <div style="font-size: 1.5rem; font-weight: 700; color:<?= $stats_webhook_unmatched_30d > 0 ? 'var(--warning)' : 'var(--success)' ?>;">
                                        <?= number_format($stats_webhook_unmatched_30d) ?></div>
                                </div>
                                <div>
                                    <p style="color:var(--text-secondary); margin-bottom: 0.5rem;">Unmatched (All-Time):</p>
                                    <div style="font-size: 1.2rem; font-weight: 700;"><?= number_format($stats_webhook_unmatched_total) ?></div>
                                </div>
                            </div>
                            <?php if ($stats_webhook_unmatched_30d > 0): ?>
                                <p style="color:var(--text-secondary); font-size:0.8rem; margin-top:1rem; margin-bottom:0;">
                                    Check the Audit Log for individual <code>webhook_unmatched</code> entries — usually a Stripe/PayPal event referencing an invoice that was deleted or never existed here.</p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="card" data-card-id="sys-system-health" data-card-width="1" data-card-col="1" draggable="true" style="margin-bottom:0;">
                        <div class="card-drag-controls"><i class="fa-solid fa-grip-vertical drag-handle" title="Drag to reorder"></i><button type="button" class="card-width-toggle" onclick="toggleStatsCardWidth(this)" title="Toggle full width"><i class="fa-solid fa-expand"></i></button></div>
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
                                    <div style="font-size: 1.2rem; font-weight: 700; color: var(--success);"><?= $backup_count ?>
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
                                style="max-height: 480px; overflow-y: auto; background: var(--surface-hover); padding: 0.5rem; border-radius: 4px; border: 1px solid var(--border);">
                                <ul style="list-style: none; padding: 0; margin: 0; font-size: 0.85rem;">
                                    <?php foreach ($all_tables_info as $tName => $tRows): ?>
                                        <?php $isInvoxa = (strpos($tName, 'invoxa_') === 0); ?>
                                        <li class="stat-table-item <?= $isInvoxa ? 'invoxa-table' : 'other-table' ?>"
                                            style="<?= !$isInvoxa ? 'display:none;' : 'display:flex;' ?> justify-content: space-between; padding: 0.3rem 0; border-bottom: 1px solid var(--border);">
                                            <span style="color: var(--text-primary);"><?= htmlspecialchars($tName) ?></span>
                                            <span style="color: var(--success); font-weight: 600;"><?= number_format($tRows) ?></span>
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
    </div>
    <?php
    return ob_get_clean();
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

function invoxaHandleGetNavCounts($mysqli, array $settings): void
{
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

function invoxaHandleGetDbStats($mysqli): void
{
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

function invoxaHandlePreviewTaxYear($mysqli, array $settings): void
{
$now = new DateTime();
$taxYearStart = getTaxYearStart((int) ($settings['tax_year_start_month'] ?? 1), $now);
$startStr = $taxYearStart->format('Y-m-d');
$taxYearLabel = $taxYearStart->format('Y-m-d') . " to " . $now->format('Y-m-d');
$hideTestRes2 = $mysqli->query("SELECT setting_value FROM invoxa_settings WHERE setting_key = 'hide_test'");
$hideTest2 = ($hideTestRes2 && $hideTestRes2->num_rows > 0) ? ($hideTestRes2->fetch_assoc()['setting_value'] === '1') : true;
$showTestOnlyRes2 = $mysqli->query("SELECT setting_value FROM invoxa_settings WHERE setting_key = 'show_test_only'");
$showTestOnly2 = ($showTestOnlyRes2 && $showTestOnlyRes2->num_rows > 0) ? ($showTestOnlyRes2->fetch_assoc()['setting_value'] === '1') : false;
$tf2 = invoxaTestViewFilter($hideTest2, $showTestOnly2);
$defaultCcy2 = invoxaResolveCurrency('', $settings);
$res = $mysqli->query("SELECT invoice_number, client_name, invoice_date, due_date, amount, currency, status, paid_amount, paid_at FROM invoxa_invoices WHERE is_quote = 0 AND status != 'void' AND invoice_date >= '$startStr' $tf2 ORDER BY invoice_date ASC");
$rows = [];
$invoicedByCcy = [];
$paidByCcy = [];
$defaultPaid = 0.0;
while ($r = $res->fetch_assoc()) {
    $ccy = invoxaResolveCurrency($r['currency'], $settings);
    $r['currency'] = $ccy;
    $rows[] = $r;
    $invoicedByCcy[$ccy] = ($invoicedByCcy[$ccy] ?? 0) + (float) $r['amount'];
    $paidByCcy[$ccy] = ($paidByCcy[$ccy] ?? 0) + (float) $r['paid_amount'];
    if ($ccy === $defaultCcy2) {
        $defaultPaid += (float) $r['paid_amount'];
    }
}
$outstandingByCcy = [];
foreach ($invoicedByCcy as $ccy => $inv) {
    $outstandingByCcy[$ccy] = $inv - ($paidByCcy[$ccy] ?? 0);
}
// Cash-basis net income (paid revenue minus expenses over the same tax-year
// window) — unlike Total Invoiced above, this excludes unpaid billings.
// Kept in the default currency only, since expenses have no currency field
// to convert other-currency revenue against.
$totalExpenses = (float) ($mysqli->query("SELECT SUM(amount) as s FROM invoxa_expenses WHERE expense_date >= '$startStr'")->fetch_assoc()['s'] ?? 0);
echo json_encode(['success' => true, 'rows' => $rows, 'label' => $taxYearLabel, 'start' => $startStr, 'total_invoiced' => invoxaStatDisplay($invoicedByCcy), 'total_paid' => invoxaStatDisplay($paidByCcy), 'outstanding' => invoxaStatDisplay($outstandingByCcy), 'total_expenses' => $totalExpenses, 'net_income' => $defaultPaid - $totalExpenses]);
exit;
}

function invoxaHandlePreviewTaxYearMonthly($mysqli, array $settings): void
{
$now = new DateTime();
$taxYearStart = getTaxYearStart((int) ($settings['tax_year_start_month'] ?? 1), $now);
$startStr = $taxYearStart->format('Y-m-d');
$taxYearLabel = $taxYearStart->format('Y-m-d') . " to " . $now->format('Y-m-d');
$hideTestRes2 = $mysqli->query("SELECT setting_value FROM invoxa_settings WHERE setting_key = 'hide_test'");
$hideTest2 = ($hideTestRes2 && $hideTestRes2->num_rows > 0) ? ($hideTestRes2->fetch_assoc()['setting_value'] === '1') : true;
$showTestOnlyRes2 = $mysqli->query("SELECT setting_value FROM invoxa_settings WHERE setting_key = 'show_test_only'");
$showTestOnly2 = ($showTestOnlyRes2 && $showTestOnlyRes2->num_rows > 0) ? ($showTestOnlyRes2->fetch_assoc()['setting_value'] === '1') : false;
$tf2 = invoxaTestViewFilter($hideTest2, $showTestOnly2);
$defaultCcy3 = invoxaResolveCurrency('', $settings);
// One row per month per currency — no exclusion, so an other-currency month
// still shows up instead of being dropped from the summary.
$res = $mysqli->query("
    SELECT DATE_FORMAT(invoice_date, '%Y-%m') as month, currency,
           SUM(amount) as total_invoiced,
           SUM(COALESCE(paid_amount, 0)) as total_paid,
           SUM(amount) - SUM(COALESCE(paid_amount, 0)) as outstanding,
           SUM(CASE WHEN status NOT IN ('paid') THEN 1 ELSE 0 END) as unpaid_count
    FROM invoxa_invoices
    WHERE is_quote = 0 AND status != 'void' AND invoice_date >= '$startStr' $tf2
    GROUP BY DATE_FORMAT(invoice_date, '%Y-%m'), currency
    ORDER BY month ASC
");
$byMonthCcy = [];
while ($r = $res->fetch_assoc()) {
    $ccy = invoxaResolveCurrency($r['currency'], $settings);
    $key = $r['month'] . '|' . $ccy;
    if (!isset($byMonthCcy[$key])) {
        $byMonthCcy[$key] = ['month' => $r['month'], 'currency' => $ccy, 'total_invoiced' => 0.0, 'total_paid' => 0.0, 'outstanding' => 0.0, 'unpaid_count' => 0];
    }
    $byMonthCcy[$key]['total_invoiced'] += (float) $r['total_invoiced'];
    $byMonthCcy[$key]['total_paid'] += (float) $r['total_paid'];
    $byMonthCcy[$key]['outstanding'] += (float) $r['outstanding'];
    $byMonthCcy[$key]['unpaid_count'] += (int) $r['unpaid_count'];
}
$expensesByMonth = [];
$expRes = $mysqli->query("SELECT DATE_FORMAT(expense_date, '%Y-%m') as month, SUM(amount) as total FROM invoxa_expenses WHERE expense_date >= '$startStr' GROUP BY DATE_FORMAT(expense_date, '%Y-%m')");
while ($er = $expRes->fetch_assoc())
    $expensesByMonth[$er['month']] = (float) $er['total'];

// Expenses have no currency field, so they (and the Net Income they feed
// into) are only ever attributed to each month's default-currency row.
$rows = [];
$invoicedByCcy = [];
$paidByCcy = [];
$defaultPaidTotal = 0.0;
$totalExpenses = 0.0;
$defaultCcyMonthsSeen = [];
foreach ($byMonthCcy as $r) {
    $dt2 = DateTime::createFromFormat('Y-m', $r['month']);
    $r['month_label'] = $dt2 ? $dt2->format('F Y') : $r['month'];
    $outstanding = round($r['outstanding'], 2);
    if ($r['unpaid_count'] > 0 && $outstanding > 0)
        $r['pay_status'] = 'Partial Paid';
    elseif ($outstanding <= 0)
        $r['pay_status'] = 'Paid';
    else
        $r['pay_status'] = 'Unpaid';
    $isDefaultCcy = $r['currency'] === $defaultCcy3;
    $monthExpenses = $isDefaultCcy ? ($expensesByMonth[$r['month']] ?? 0.0) : 0.0;
    $r['month_expenses'] = $monthExpenses;
    $r['month_net_income'] = $isDefaultCcy ? ($r['total_paid'] - $monthExpenses) : null;
    $invoicedByCcy[$r['currency']] = ($invoicedByCcy[$r['currency']] ?? 0) + $r['total_invoiced'];
    $paidByCcy[$r['currency']] = ($paidByCcy[$r['currency']] ?? 0) + $r['total_paid'];
    if ($isDefaultCcy) {
        $defaultPaidTotal += $r['total_paid'];
        $totalExpenses += $monthExpenses;
        $defaultCcyMonthsSeen[$r['month']] = true;
    }
    $rows[] = $r;
}
// Months with expenses but no default-currency invoices that month still
// belong in the tax-year total even though they never generated a row above.
foreach ($expensesByMonth as $leftoverMonth => $leftoverAmount) {
    if (!isset($defaultCcyMonthsSeen[$leftoverMonth])) {
        $totalExpenses += $leftoverAmount;
    }
}
$outstandingByCcy = [];
foreach ($invoicedByCcy as $ccy => $inv) {
    $outstandingByCcy[$ccy] = $inv - ($paidByCcy[$ccy] ?? 0);
}
echo json_encode(['success' => true, 'rows' => $rows, 'label' => $taxYearLabel, 'start' => $startStr, 'total_invoiced' => invoxaStatDisplay($invoicedByCcy), 'total_paid' => invoxaStatDisplay($paidByCcy), 'outstanding' => invoxaStatDisplay($outstandingByCcy), 'total_expenses' => $totalExpenses, 'net_income' => $defaultPaidTotal - $totalExpenses]);
exit;
}

function invoxaHandleStatsApiRoutes($mysqli, array $settings): void
{
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
        $chartDefaultCcyEsc = $mysqli->real_escape_string(invoxaResolveCurrency('', $settings));
        $q = "SELECT DATE_FORMAT(invoice_date, '%Y-%m') as month, client_key, SUM(amount) as total FROM invoxa_invoices WHERE status NOT IN ('failed', 'void') AND (currency = '' OR currency = '$chartDefaultCcyEsc') $chartInvoiceFilter GROUP BY month, client_key ORDER BY month ASC";
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
