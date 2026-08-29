        <!-- INVOICES -->
        <div id="sec-invoices" class="section">
            <h2 class="page-title">Invoices</h2>
            <!-- A sibling of .section-scroll, not a child inside it — stays fixed
                 while the table below scrolls, same reasoning as h2.page-title and
                 the Audit Log toolbar. -->
            <!-- Invoice toolbar: two separate action groups -->
            <div style="display: flex; flex-wrap: wrap; gap: 0.75rem; align-items: stretch; margin-bottom: 1.5rem;">

                <button type="button" class="toolbar-toggle" id="invoicesToolbarToggle" onclick="toggleToolbar('invoices')">
                    <span><i class="fa-solid fa-sliders"></i> Filters &amp; Export</span>
                    <i class="fa-solid fa-chevron-down toolbar-toggle-chevron"></i>
                </button>
                <div class="toolbar-collapsible" id="invoicesToolbarGroups">

                <!-- Group 1: Exports -->
                <div
                    style="display: flex; flex-direction: row; align-items: center; gap: 0.75rem; background: var(--surface); border: 1px solid var(--border); border-radius: 8px; padding: 0.5rem 0.9rem;">
                    <span
                        style="font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.06em; color: var(--text-secondary); font-weight: 600; white-space: nowrap; padding-right: 0.75rem; border-right: 1px solid var(--border);"><i
                            class="fa-solid fa-file-export" style="margin-right:0.3rem;"></i>Export</span>
                    <select id="invoiceExportType"
                        style="padding: 0.45rem 0.65rem; background: var(--surface-2); border: 1px solid var(--border); border-radius: 6px; color: var(--text-primary); font-family: inherit; font-size: 0.85rem; min-width: 190px;">
                        <option value="invoices" title="Export all invoices as CSV">All Invoices (CSV)</option>
                        <option value="invoices_pdf" title="Download a PDF of every invoice, zipped into one file">All
                            Invoices (PDF)</option>
                        <option value="tax_year"
                            title="Preview and export all invoices for the current tax year, ordered by date. Limited to the instance default currency (Settings > General) — invoices in another currency are excluded.">Tax
                            Year Invoices</option>
                        <option value="tax_year_monthly"
                            title="Preview and export a monthly summary for the current tax year, showing paid/partial paid status. Amounts are in the instance default currency (Settings > General) — invoices in another currency are excluded.">
                            Monthly Summary</option>
                        <option value="accounting_journal"
                            title="Double-entry General Journal (invoices, payments, expenses) for the current tax year, as a plain CSV any bookkeeping tool can import. Only includes invoices in the instance default currency (Settings > General) — a ledger can't mix currencies in one balance.">
                            Accounting Journal (CSV)</option>
                        <option value="accounting_iif"
                            title="Same General Journal as an .iif file for QuickBooks Desktop's File > Utilities > Import > IIF Files. Only includes invoices in the instance default currency (Settings > General).">
                            QuickBooks (IIF)</option>
                    </select>
                    <button class="btn" style="background: var(--surface-hover); white-space: nowrap;"
                        onclick="runInvoiceExport()"><i class="fa-solid fa-download"></i> Export</button>
                </div>

                <!-- Group 2: Status Filter -->
                <div
                    style="display: flex; flex-direction: row; align-items: center; gap: 0.75rem; background: var(--surface); border: 1px solid var(--border); border-radius: 8px; padding: 0.5rem 0.9rem;">
                    <span
                        style="font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.06em; color: var(--text-secondary); font-weight: 600; white-space: nowrap; padding-right: 0.75rem; border-right: 1px solid var(--border);"><i
                            class="fa-solid fa-filter" style="margin-right:0.3rem;"></i>Filter</span>
                    <select id="invoiceStatusFilter" onchange="filterInvoicesByStatus(this.value)"
                        style="padding: 0.45rem 0.65rem; background: var(--surface-2); border: 1px solid var(--border); border-radius: 6px; color: var(--text-primary); font-family: inherit; font-size: 0.85rem; min-width: 150px;">
                        <option value="">All Statuses</option>
                        <option value="overdue">Overdue</option>
                        <option value="sent">Sent</option>
                        <option value="pending">Pending</option>
                        <option value="partial">Partial</option>
                        <option value="paid">Paid</option>
                        <option value="failed">Failed</option>
                        <option value="void">Void</option>
                    </select>
                </div>

                <!-- Group 3: Saved Views -->
                <div
                    style="display: flex; flex-direction: row; align-items: center; gap: 0.75rem; background: var(--surface); border: 1px solid var(--border); border-radius: 8px; padding: 0.5rem 0.9rem;">
                    <span
                        style="font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.06em; color: var(--text-secondary); font-weight: 600; white-space: nowrap; padding-right: 0.75rem; border-right: 1px solid var(--border);"><i
                            class="fa-solid fa-bookmark" style="margin-right:0.3rem;"></i>Views</span>
                    <select id="invoicesViewSelect" onchange="applyFilterView('invoices', this.value)"
                        style="padding: 0.45rem 0.65rem; background: var(--surface-2); border: 1px solid var(--border); border-radius: 6px; color: var(--text-primary); font-family: inherit; font-size: 0.85rem; min-width: 150px;">
                        <option value="">Saved Views…</option>
                    </select>
                    <button type="button" class="btn small" title="Save the current search/filter as a view"
                        onclick="saveFilterView('invoices')"><i class="fa-solid fa-plus"></i></button>
                    <button type="button" class="btn small" title="Delete the selected view"
                        onclick="deleteFilterView('invoices')"><i class="fa-solid fa-trash"></i></button>
                </div>

                </div>
            </div>

            <!-- Bulk Actions — hidden until at least one row is checked; a sibling
                 of the toolbar above (not one of its flex items) so it always
                 falls on its own row, sized to its content rather than the full
                 row width. -->
            <div id="invoiceBulkBar" style="display:none; flex-direction: row; align-items: center; gap: 0.75rem; background: var(--accent-soft); border: 1px solid var(--accent); border-radius: 8px; padding: 0.5rem 0.9rem; width: fit-content; margin-bottom: 1.5rem;">
                <span id="invoiceBulkCount" style="font-size: 0.85rem; font-weight: 600; color: var(--accent); white-space: nowrap;"></span>
                <button type="button" class="btn small success" onclick="bulkMarkPaidInvoices()"><i class="fa-solid fa-check"></i> Mark Paid</button>
                <button type="button" class="btn small" onclick="bulkResendInvoiceEmails()"><i class="fa-solid fa-paper-plane"></i> Resend</button>
                <button type="button" class="btn small" onclick="bulkExportInvoicesCsv()"><i class="fa-solid fa-file-csv"></i> Export CSV</button>
                <button type="button" class="btn small danger" onclick="bulkDeleteInvoices()"><i class="fa-solid fa-trash"></i> Delete</button>
            </div>

            <div class="section-scroll">
            <div class="card">
                <table id="invoicesTable">
                    <thead>
                        <tr>
                            <th data-sortable="false" style="width:32px;"><input type="checkbox" id="invoicesSelectAll" onchange="toggleSelectAllInvoices(this)"></th>
                            <th style="width:110px;">Invoice #</th>
                            <th>Date</th>
                            <th>Due Date</th>
                            <th>Client</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th style="width:180px;">File</th>
                            <th data-sortable="false">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="invoicesTbody">
                        <?= renderInvoiceRows($invoices) ?>
                    </tbody>
                </table>
            </div>
            </div>
        </div>

