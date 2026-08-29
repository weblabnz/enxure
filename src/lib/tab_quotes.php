        <!-- QUOTES -->
        <div id="sec-quotes" class="section">
            <!-- The icon+label are wrapped in one span so they're a single flex
                 item — h2.page-title's justify-content: space-between would
                 otherwise treat the icon and the text as two separate items and
                 push them apart from each other. -->
            <h2 class="page-title"><span><i class="fa-solid fa-file-pen"
                        style="color:var(--accent); margin-right:0.5rem;"></i>Quotes &amp; Estimates</span></h2>
            <!-- Quote toolbar: same group layout as the Invoices/Clients/Expenses toolbar. -->
            <div style="display: flex; flex-wrap: wrap; gap: 0.75rem; align-items: stretch; margin-bottom: 1.5rem;">

                <button type="button" class="toolbar-toggle" id="quotesToolbarToggle" onclick="toggleToolbar('quotes')">
                    <span><i class="fa-solid fa-sliders"></i> Filters &amp; Export</span>
                    <i class="fa-solid fa-chevron-down toolbar-toggle-chevron"></i>
                </button>
                <div class="toolbar-collapsible" id="quotesToolbarGroups">

                <!-- Group 1: Export -->
                <div
                    style="display: flex; flex-direction: row; align-items: center; gap: 0.75rem; background: var(--surface); border: 1px solid var(--border); border-radius: 8px; padding: 0.5rem 0.9rem;">
                    <span
                        style="font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.06em; color: var(--text-secondary); font-weight: 600; white-space: nowrap; padding-right: 0.75rem; border-right: 1px solid var(--border);"><i
                            class="fa-solid fa-file-export" style="margin-right:0.3rem;"></i>Export</span>
                    <button class="btn" style="background: var(--surface-hover); white-space: nowrap;"
                        onclick="window.location.href='?export=quotes'"><i class="fa-solid fa-file-csv"></i> CSV</button>
                </div>

                </div>

                <button class="btn primary" onclick="openQuoteModal()"><i class="fa-solid fa-plus"></i> New
                    Quote</button>

            </div>

            <!-- Bulk Actions — hidden until at least one row is checked; a sibling
                 of the toolbar above (not one of its flex items) so it always
                 falls on its own row, sized to its content rather than the full
                 row width. -->
            <div id="quoteBulkBar" style="display:none; flex-direction: row; align-items: center; gap: 0.75rem; background: var(--accent-soft); border: 1px solid var(--accent); border-radius: 8px; padding: 0.5rem 0.9rem; width: fit-content; margin-bottom: 1.5rem;">
                <span id="quoteBulkCount" style="font-size: 0.85rem; font-weight: 600; color: var(--accent); white-space: nowrap;"></span>
                <button type="button" class="btn small success" onclick="bulkConvertQuotes()"><i class="fa-solid fa-file-invoice"></i> Convert to Invoice</button>
                <button type="button" class="btn small" onclick="bulkExportQuotesCsv()"><i class="fa-solid fa-file-csv"></i> Export CSV</button>
                <button type="button" class="btn small danger" onclick="bulkDeleteQuotes()"><i class="fa-solid fa-trash"></i> Delete</button>
            </div>

            <div class="section-scroll">
            <div class="card">
                <div class="card-body" style="padding:0;">
                    <table id="quotesTable">
                        <thead>
                            <tr>
                                <th data-sortable="false" style="width:32px;"><input type="checkbox" id="quotesSelectAll" onchange="toggleSelectAllQuotes(this)"></th>
                                <th>Quote #</th>
                                <th>Client</th>
                                <th>Date</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th>Expires</th>
                                <th data-sortable="false">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="quotesTbody">
                            <?php
                            $qRes = $mysqli->query("SELECT * FROM invoxa_invoices WHERE is_quote = 1 ORDER BY invoice_date DESC");
                            echo renderQuoteRows($qRes);
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
            </div>
        </div>

