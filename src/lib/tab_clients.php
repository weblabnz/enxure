        <!-- CLIENTS -->
        <div id="sec-clients" class="section">
            <h2 class="page-title">Clients</h2>
            <!-- Client toolbar: same group layout as the Invoices toolbar (a
                 sibling of .section-scroll, not a child inside it — stays fixed
                 while the table below scrolls). -->
            <div style="display: flex; flex-wrap: wrap; gap: 0.75rem; align-items: stretch; margin-bottom: 1.5rem;">

                <button type="button" class="toolbar-toggle" id="clientsToolbarToggle" onclick="toggleToolbar('clients')">
                    <span><i class="fa-solid fa-sliders"></i> Filters &amp; Export</span>
                    <i class="fa-solid fa-chevron-down toolbar-toggle-chevron"></i>
                </button>
                <div class="toolbar-collapsible" id="clientsToolbarGroups">

                <!-- Group 1: Export / Import -->
                <div
                    style="display: flex; flex-direction: row; align-items: center; gap: 0.75rem; background: var(--surface); border: 1px solid var(--border); border-radius: 8px; padding: 0.5rem 0.9rem;">
                    <span
                        style="font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.06em; color: var(--text-secondary); font-weight: 600; white-space: nowrap; padding-right: 0.75rem; border-right: 1px solid var(--border);"><i
                            class="fa-solid fa-file-export" style="margin-right:0.3rem;"></i>Export</span>
                    <button class="btn" style="background: var(--surface-hover); white-space: nowrap;"
                        onclick="window.location.href='?export=clients'"><i class="fa-solid fa-file-csv"></i> CSV</button>
                    <label class="btn" style="background: var(--surface-hover); cursor:pointer; margin:0; white-space: nowrap;"
                        title="CSV with a header row: Client Name, Email, Rate, Billing Frequency, Account Name, Account Number, Payment Terms Days, Phone, Address (Phone/Address are optional)">
                        <i class="fa-solid fa-file-import"></i> Import
                        <input type="file" id="importClientsFile" accept=".csv" style="display:none;"
                            onchange="importClientsCsv(this.files[0])"></label>
                </div>

                <!-- Group 2: Saved Views -->
                <div
                    style="display: flex; flex-direction: row; align-items: center; gap: 0.75rem; background: var(--surface); border: 1px solid var(--border); border-radius: 8px; padding: 0.5rem 0.9rem;">
                    <span
                        style="font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.06em; color: var(--text-secondary); font-weight: 600; white-space: nowrap; padding-right: 0.75rem; border-right: 1px solid var(--border);"><i
                            class="fa-solid fa-bookmark" style="margin-right:0.3rem;"></i>Views</span>
                    <select id="clientsViewSelect" onchange="applyFilterView('clients', this.value)"
                        style="padding: 0.45rem 0.65rem; background: var(--surface-2); border: 1px solid var(--border); border-radius: 6px; color: var(--text-primary); font-family: inherit; font-size: 0.85rem; min-width: 150px;">
                        <option value="">Saved Views…</option>
                    </select>
                    <button type="button" class="btn small" title="Save the current search as a view"
                        onclick="saveFilterView('clients')"><i class="fa-solid fa-plus"></i></button>
                    <button type="button" class="btn small" title="Delete the selected view"
                        onclick="deleteFilterView('clients')"><i class="fa-solid fa-trash"></i></button>
                </div>

                </div>

                <button class="btn primary" onclick="openClientModal()"><i class="fa-solid fa-plus"></i> Add
                    Client</button>

            </div>

            <!-- Bulk Actions — hidden until at least one row is checked; a sibling
                 of the toolbar above (not one of its flex items) so it always
                 falls on its own row, sized to its content rather than the full
                 row width. -->
            <div id="clientBulkBar" style="display:none; flex-direction: row; align-items: center; gap: 0.75rem; background: var(--accent-soft); border: 1px solid var(--accent); border-radius: 8px; padding: 0.5rem 0.9rem; width: fit-content; margin-bottom: 1.5rem;">
                <span id="clientBulkCount" style="font-size: 0.85rem; font-weight: 600; color: var(--accent); white-space: nowrap;"></span>
                <button type="button" class="btn small success" onclick="bulkSetClientFlag('is_active', 1, 'Marked active')"><i class="fa-solid fa-circle-check"></i> Active</button>
                <button type="button" class="btn small" onclick="bulkSetClientFlag('is_active', 0, 'Marked inactive')"><i class="fa-solid fa-circle-xmark"></i> Inactive</button>
                <button type="button" class="btn small" onclick="bulkSetClientFlag('is_test', 1, 'Marked as test')"><i class="fa-solid fa-flask"></i> Test</button>
                <button type="button" class="btn small" onclick="bulkSetClientFlag('is_test', 0, 'Unmarked as test')"><i class="fa-solid fa-flask-vial"></i> Unmark Test</button>
                <button type="button" class="btn small danger" onclick="bulkDeleteClients()"><i class="fa-solid fa-trash"></i> Delete</button>
            </div>

            <div class="section-scroll">
            <div class="card">
                <table id="clientsTable">
                    <thead>
                        <tr>
                            <th data-sortable="false" style="width:32px;"><input type="checkbox" id="clientsSelectAll" onchange="toggleSelectAllClients(this)"></th>
                            <th>Client Name</th>
                            <th>Email</th>
                            <th>Rate</th>
                            <th style="text-align:center;">Status</th>
                            <th>Invoices</th>
                            <th>Total Billed</th>
                            <th>Total Paid</th>
                            <th>Outstanding</th>
                            <th data-sortable="false">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="clientsTbody">
                        <?= renderClientRows($clients) ?>
                    </tbody>
                </table>
            </div>
            </div>
        </div>

