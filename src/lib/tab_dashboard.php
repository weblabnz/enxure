        <!-- DASHBOARD -->
        <div id="sec-dashboard" class="section">
            <h2 class="page-title">Dashboard
                <div style="display:flex; align-items:center; gap:1.5rem;">
                    <div style="color:var(--text-secondary); font-size:0.9rem; font-weight:400;">
                        <i class="fa-solid fa-clock-rotate-left" style="margin-right:0.25rem;"></i>Next Auto-Run: <span
                            id="nextCronRunDashboard" style="color:var(--accent); font-weight:600;"><span class="skeleton"></span></span>
                    </div>
                    <div class="widget-manage" style="position:relative; top:-2px;">
                        <button type="button" class="btn small" onclick="toggleDashboardWidgetMenu()"><i
                                class="fa-solid fa-sliders"></i> Customize</button>
                        <div id="dashboardWidgetMenu" class="widget-manage-menu" hidden></div>
                    </div>
                </div>
            </h2>
            <div class="section-scroll">
                <div id="dashboardStatsWrap">
                    <?= renderDashboardStats($mysqli, $currentUserId, $settings, $failedInvoices, $overdueInvoices, $total_invoiced_by_ccy, $total_monthly_by_ccy, $total_paid_by_ccy, (int) $client_count) ?>
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

