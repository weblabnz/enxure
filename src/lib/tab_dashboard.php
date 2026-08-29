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
                    <?= renderDashboardStats($settings, $failedInvoices, $overdueInvoices, $total_invoiced_by_ccy, $total_monthly_by_ccy, $total_paid_by_ccy, (int) $client_count) ?>
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

