<?php
// Dashboard/Stats-tab rendering and the $mysqli-touching stats AJAX/GET
// endpoints (nav badge counts, DB table row counts, tax-year previews, the
// chart/stats JSON API). renderStatsSection() still pulls its ~50 $stats_*
// inputs via `global` from Data Fetching in invoxa.php — unchanged by this move.

// Dashboard's alert strips + top stat cards — the parts that can change from
// actions taken elsewhere without the Dashboard tab being reloaded.
function renderDashboardStats(array $settings, array $failedInvoices, array $overdueInvoices, array $total_invoiced_by_ccy, array $total_monthly_by_ccy, array $total_paid_by_ccy, int $client_count): string
{
    $outstanding_by_ccy = $total_invoiced_by_ccy;
    foreach ($total_paid_by_ccy as $ccy => $amount) {
        $outstanding_by_ccy[$ccy] = ($outstanding_by_ccy[$ccy] ?? 0) - $amount;
    }
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
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-card-top">
                <div class="stat-title">Total Invoiced (All Time)</div>
                <div class="stat-icon"><i class="fa-solid fa-sack-dollar"></i></div>
            </div>
            <div class="stat-value"><?= invoxaFormatMoneyByCurrency($total_invoiced_by_ccy) ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-card-top">
                <div class="stat-title">This Month</div>
                <div class="stat-icon success"><i class="fa-solid fa-calendar-check"></i></div>
            </div>
            <div class="stat-value" style="color: var(--success)"><?= invoxaFormatMoneyByCurrency($total_monthly_by_ccy) ?>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-card-top">
                <div class="stat-title">Total Outstanding</div>
                <div class="stat-icon warning"><i class="fa-solid fa-hourglass-half"></i></div>
            </div>
            <div class="stat-value" style="color: var(--warning)"><?= invoxaFormatMoneyByCurrency($outstanding_by_ccy) ?></div>
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

// The entire Statistics & Forecasting tab — read-only, derived-on-render
// content with no client-side state to preserve, so it renders the whole tab
// body rather than being split row-by-row like the functions above. Pulls
// its ~15 $stats_* inputs via `global` rather than a long parameter list.
function renderStatsSection(): string
{
    global $licenseValid;
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
    $stats_php_version, $stats_mysql_version, $stats_default_ccy, $stats_has_other_currency;
    ob_start();
    ?>
    <h2 class="page-title">Data Statistics &amp; Forecasting</h2>
    <?php if ($stats_has_other_currency): ?>
        <div class="card" style="border-left:3px solid var(--warning); margin: 0 1.5rem 1.75rem;">
            <div class="card-body" style="display:flex; align-items:center; gap:0.75rem; padding:1rem 1.25rem;">
                <i class="fa-solid fa-circle-info" style="color:var(--warning); font-size:1.1rem;"></i>
                <div><strong>Charts, Forecasting &amp; AR Aging total in <?= htmlspecialchars($stats_default_ccy) ?> only.</strong>
                    <span style="color:var(--text-secondary); font-size:0.85rem; display:block; margin-top:0.15rem;">
                        Every other total, table, and export on this page groups in every currency instead.</span>
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
                <div class="mobile-grid" style="display:grid; grid-template-columns:1.3fr 1fr; gap:1rem; align-items:stretch;">
                <div class="card" style="margin-bottom:0;">
                    <div class="card-header">
                        <h3>Tax Year Summary (<?= $taxYearLabel ?>)</h3>
                    </div>
                    <div class="card-body">
                        <div class="stats-grid" style="margin-bottom: 0;">
                            <div class="stat-card" style="border-top: 3px solid #3b82f6;">
                                <div class="label">Total Invoiced</div>
                                <div class="value"><?= invoxaFormatMoneyByCurrency($stats_ty_invoiced_by_ccy) ?></div>
                            </div>
                            <div class="stat-card" style="border-top: 3px solid #10b981;">
                                <div class="label">Total Paid</div>
                                <div class="value"><?= invoxaFormatMoneyByCurrency($stats_ty_paid_by_ccy) ?></div>
                            </div>
                            <div class="stat-card"
                                style="border-top: 3px solid <?= $stats_ty_outstanding > 0 ? '#f59e0b' : '#10b981' ?>;">
                                <div class="label">Outstanding</div>
                                <div class="value"><?= invoxaFormatMoneyByCurrency($stats_ty_outstanding_by_ccy) ?></div>
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
                                <div class="value"><?= invoxaFormatMoneyByCurrency($stats_all_time_revenue_by_ccy) ?></div>
                            </div>
                            <div class="stat-card" style="border-top: 3px solid #ef4444;">
                                <div class="label">Outstanding Receivables</div>
                                <div class="value"><?= invoxaFormatMoneyByCurrency($stats_outstanding_revenue_by_ccy) ?> <span
                                        style="font-size: 1rem; color: var(--text-secondary); font-weight: normal;">(<?= $stats_overdue_count ?>
                                        overdue)</span></div>
                            </div>
                            <div class="stat-card" style="border-top: 3px solid var(--warning);">
                                <div class="label">Monthly Recurring (<span class="has-tooltip"
                                        data-tip="Monthly Recurring Revenue — total fixed monthly fees from active clients">MRR</span>)
                                </div>
                                <div class="value"><?= invoxaFormatMoneyByCurrency($stats_mrr_by_ccy) ?></div>
                            </div>
                            <div class="stat-card" style="border-top: 3px solid #3b82f6;">
                                <div class="label">Average Invoice Value</div>
                                <div class="value"><?= invoxaFormatMoneyByCurrency($stats_avg_invoice_by_ccy) ?></div>
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

                <div class="mobile-grid" style="display:grid; grid-template-columns:1fr 1.3fr; gap:1rem; align-items:stretch;">
                <div class="card" style="margin-bottom:0;">
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

                <div class="card" style="margin-bottom:0;">
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
                                                    <?= invoxaFormatMoneyByCurrency($tc['by_ccy']) ?>
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

            <!-- Expenses -->
            <div class="subnav-pane" id="stats-pane-expenses">
                <div class="mobile-grid" style="display:grid; grid-template-columns:1fr 1fr; gap:1rem; align-items:stretch;">
                <div class="card" style="margin-bottom:0;">
                    <div class="card-header">
                        <h3>Profit &amp; Loss (<?= htmlspecialchars($taxYearLabel) ?>)</h3>
                    </div>
                    <div class="card-body">
                        <div class="stats-grid" style="margin-bottom: 0;">
                            <div class="stat-card" style="border-top: 3px solid #10b981;">
                                <div class="label">Revenue Received</div>
                                <div class="value">$<?= number_format($stats_ty_paid, 2) ?></div>
                            </div>
                            <div class="stat-card" style="border-top: 3px solid #ef4444;">
                                <div class="label">Expenses</div>
                                <div class="value">$<?= number_format($stats_expense_ty_total, 2) ?></div>
                            </div>
                            <div class="stat-card"
                                style="border-top: 3px solid <?= $stats_net_income_ty >= 0 ? '#10b981' : '#ef4444' ?>;">
                                <div class="label">Net Income</div>
                                <div class="value">$<?= number_format($stats_net_income_ty, 2) ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card" style="margin-bottom:0;">
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
                </div>

                <div class="card">
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
                                            <td style="padding: 1rem; text-align: right;"><?= invoxaFormatMoneyByCurrency($tym['by_ccy']['invoiced']) ?></td>
                                            <td style="padding: 1rem; text-align: right; color:#10b981;"><?= invoxaFormatMoneyByCurrency($tym['by_ccy']['paid']) ?></td>
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
                <div class="mobile-grid" style="display:grid; grid-template-columns:1fr 1fr; gap:1rem; align-items:stretch;">
                <div class="card" style="margin-bottom:0;">
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

                <div class="card" style="margin-bottom:0;">
                    <div class="card-header">
                        <h3>Webhook Health</h3>
                    </div>
                    <div class="card-body">
                        <div style="display: flex; gap: 2rem; flex-wrap: wrap;">
                            <div>
                                <p style="color:var(--text-secondary); margin-bottom: 0.5rem;">Unmatched (Last 30 Days):</p>
                                <div style="font-size: 1.5rem; font-weight: 700; color:<?= $stats_webhook_unmatched_30d > 0 ? 'var(--warning)' : '#10b981' ?>;">
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
                            style="max-height: 480px; overflow-y: auto; background: var(--surface-hover); padding: 0.5rem; border-radius: 4px; border: 1px solid var(--border);">
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

                <div class="mobile-grid" style="display:grid; grid-template-columns:1fr 1fr; gap:1rem; align-items:stretch;">
                <div class="card" style="margin-bottom:0;">
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

                <div class="card" style="margin-bottom:0;">
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
