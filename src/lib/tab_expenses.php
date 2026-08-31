        <!-- EXPENSES -->
        <div id="sec-expenses" class="section">
            <h2 class="page-title">Expenses</h2>
            <!-- Expense toolbar: same group layout as the Invoices/Clients toolbar. -->
            <div style="display: flex; flex-wrap: wrap; gap: 0.75rem; align-items: stretch; margin-bottom: 1.5rem;">

                <button type="button" class="toolbar-toggle" id="expensesToolbarToggle" onclick="toggleToolbar('expenses')">
                    <span><i class="fa-solid fa-sliders"></i> Filters &amp; Export</span>
                    <i class="fa-solid fa-chevron-down toolbar-toggle-chevron"></i>
                </button>
                <div class="toolbar-collapsible" id="expensesToolbarGroups">

                <!-- Group 1: CSV export/import -->
                <div
                    style="display: flex; flex-direction: row; align-items: center; gap: 0.75rem; background: var(--surface); border: 1px solid var(--border); border-radius: 8px; padding: 0.5rem 0.9rem;">
                    <span
                        style="font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.06em; color: var(--text-secondary); font-weight: 600; white-space: nowrap; padding-right: 0.75rem; border-right: 1px solid var(--border);"><i
                            class="fa-solid fa-file-csv" style="margin-right:0.3rem;"></i>CSV</span>
                    <button class="btn" style="background: var(--surface-hover); white-space: nowrap;"
                        onclick="window.location.href='?export=expenses'"><i class="fa-solid fa-download"></i> Export</button>
                    <label class="btn" style="background: var(--surface-hover); cursor:pointer; margin:0; white-space: nowrap;"
                        title="CSV with a header row: Date, Vendor, Category, Amount, Description">
                        <i class="fa-solid fa-file-import"></i> Import
                        <input type="file" id="importExpensesFile" accept=".csv" style="display:none;"
                            onchange="importExpensesCsv(this.files[0])"></label>
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

