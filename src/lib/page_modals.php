        <!-- Modals -->
        <div id="clientModal" class="modal-overlay">
            <div class="modal large">
                <div class="modal-header">
                    <h2 id="clientModalTitle">Add Client</h2><button class="btn"
                        style="background:transparent; border:none; color:var(--text-primary);" onclick="closeModal('clientModal')"><i
                            class="fa-solid fa-xmark"></i></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="clientId">

                    <!-- Identity -->
                    <div class="client-form-grid">
                        <div class="form-group"><label class="form-label">Client Name</label><input type="text"
                                id="clientName" class="form-control"></div>
                        <div class="form-group"><label class="form-label">Email Address</label><input type="email"
                                id="clientEmail" class="form-control"></div>
                        <div class="form-group"><label class="form-label">Phone</label><input type="text"
                                id="clientPhone" class="form-control" placeholder="e.g. +1 555 123 4567"></div>
                        <div class="form-group" style="grid-column:1 / -1;"><label class="form-label">Address</label><textarea
                                id="clientAddress" class="form-control" rows="2" placeholder="Street, city, postal code, country"></textarea></div>
                    </div>

                    <!-- Billing terms -->
                    <div class="client-form-grid" style="margin-top:0.5rem; padding-top:1rem; border-top:1px solid var(--border);">
                        <div class="form-group"><label class="form-label">Rate (per billing period)</label><input type="number"
                                id="clientRate" class="form-control" step="0.01"></div>
                        <div class="form-group"><label class="form-label">Currency</label><input type="text"
                                id="clientCurrency" class="form-control" maxlength="3"
                                style="text-transform:uppercase; max-width:100px;"
                                placeholder="<?= htmlspecialchars($settings['currency'] ?? 'USD') ?>">
                            <p style="color:var(--text-secondary); font-size:0.8rem; margin-top:0.35rem;">3-letter
                                code for this client's invoices/quotes. Leave blank to use the instance default (<?= htmlspecialchars($settings['currency'] ?? 'USD') ?>).</p>
                        </div>
                        <div class="form-group"><label class="form-label">Billing Frequency</label>
                            <select id="clientBillingFrequency" class="form-control">
                                <option value="weekly">Weekly</option>
                                <option value="monthly" selected>Monthly</option>
                                <option value="quarterly">Quarterly</option>
                                <option value="annually">Annually</option>
                            </select>
                            <p style="color:var(--text-secondary); font-size:0.8rem; margin-top:0.35rem;">How often
                                Recurring Billing charges this client. Defaults to Monthly.</p>
                        </div>
                        <div class="form-group" style="grid-column:1 / -1;"><label class="form-label">Payment Terms (days)</label><input type="number"
                                id="clientPaymentTerms" class="form-control" step="1" min="1" placeholder="21" style="max-width:calc(50% - 0.625rem);">
                            <p style="color:var(--text-secondary); font-size:0.8rem; margin-top:0.35rem;">Days from
                                invoice date to due date, e.g. 15/30/45. Defaults to 21.</p>
                        </div>
                        <div class="form-group"><label class="form-label">Discount (%)</label><input type="number"
                                id="clientDiscountPct" class="form-control" step="0.01" min="0" max="100"
                                placeholder="0"></div>
                        <div class="form-group"><label class="form-label">Tax Rate (%)</label><input type="number"
                                id="clientTaxRate" class="form-control" step="0.01" min="0" max="100"
                                placeholder="0"></div>
                        <p style="grid-column:1 / -1; color:var(--text-secondary); font-size:0.8rem; margin:-0.5rem 0 0;">
                            Discount/Tax apply to this client's Recurring Billing invoices only. Both default to 0
                            when left blank.</p>
                    </div>

                    <!-- Bank details -->
                    <div class="client-form-grid" style="margin-top:0.5rem; padding-top:1rem; border-top:1px solid var(--border);">
                        <div class="form-group"><label class="form-label">Bank Account Name</label><input type="text"
                                id="clientAccName" class="form-control" placeholder="e.g. Jane Smith - Acme Web Co"></div>
                        <div class="form-group"><label class="form-label">Bank Account Number</label><input type="text"
                                id="clientAccNum" class="form-control" placeholder="e.g. 12-3456-7890123-00"></div>
                    </div>

                    <!-- Status -->
                    <div style="display:flex; align-items:center; gap:1.5rem; margin-top:0.5rem; padding-top:1rem; border-top:1px solid var(--border);">
                        <label style="display:flex; align-items:center; gap:0.5rem;"><input type="checkbox"
                                id="clientActive" checked> Active</label>
                        <label style="display:flex; align-items:center; gap:0.5rem;"><input type="checkbox"
                                id="clientTest"> Is Test Client</label>
                    </div>

                    <div id="clientPortalSection" style="margin-top:1.25rem; padding-top:1rem; border-top:1px solid var(--border); display:none;">
                        <label class="form-label">Client Portal <?php if (!$licenseValid): ?><i class="fa-solid fa-lock"
                                    title="Requires a license" style="color:var(--text-secondary); font-size:0.8rem; margin-left:0.35rem;"></i><?php endif; ?></label>
                        <p style="color:var(--text-secondary); font-size:0.8rem; margin-top:0;">A read-only link this
                            client can use to see their own invoices and payment status — no login required. You
                            share the link yourself (email, etc.); nothing is sent automatically.
                            <?php if (!$licenseValid): ?><strong>Generating or regenerating a link requires a
                                    license</strong> — revoking an existing one stays free.<?php endif; ?></p>
                        <div id="clientPortalNoLinkWrap" style="display:flex; gap:1.5rem; align-items:center; flex-wrap:wrap; margin-top:0.75rem;">
                            <select id="clientPortalExpiry" class="form-control" style="width:auto;" <?= $licenseValid ? '' : 'disabled' ?>>
                                <option value="never">Never</option>
                                <option value="30">30 days</option>
                                <option value="90" selected>90 days</option>
                                <option value="365">1 year</option>
                            </select>
                            <button class="btn" id="generatePortalLinkBtn" type="button" onclick="generatePortalLink()" style="width:auto;"
                                <?= $licenseValid ? '' : 'disabled title="Requires a license"' ?>><i
                                    class="fa-solid fa-link"></i> Generate Portal Link</button>
                        </div>
                        <div id="clientPortalLinkWrap" style="display:none;">
                            <div style="display:flex; gap:0.5rem;">
                                <input type="text" id="clientPortalUrl" class="form-control" readonly>
                                <button class="btn" type="button" onclick="copyPortalLink()" style="width:auto; white-space:nowrap;"><i
                                        class="fa-solid fa-copy"></i> Copy</button>
                            </div>
                            <p id="clientPortalExpiryNote" style="color:var(--text-secondary); font-size:0.8rem; margin:0.35rem 0 0;"></p>
                            <div style="display:flex; gap:0.5rem; margin-top:0.5rem; align-items:center;">
                                <select id="clientPortalRegenExpiry" class="form-control" style="width:auto;" <?= $licenseValid ? '' : 'disabled' ?>>
                                    <option value="never">Never expires</option>
                                    <option value="30">30 days</option>
                                    <option value="90" selected>90 days</option>
                                    <option value="365">1 year</option>
                                </select>
                                <button class="btn" type="button" onclick="generatePortalLink()" style="width:auto;"
                                    <?= $licenseValid ? '' : 'disabled title="Requires a license"' ?>><i
                                        class="fa-solid fa-rotate"></i> Regenerate</button>
                                <button class="btn danger" type="button" onclick="revokePortalLink()" style="width:auto;"><i
                                        class="fa-solid fa-ban"></i> Revoke</button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer"><button class="btn" onclick="closeModal('clientModal')">Cancel</button><button
                        class="btn primary" id="saveClientBtn" onclick="saveClient()"><i class="fa-solid fa-save"></i>
                        Save Client</button></div>
            </div>
        </div>

        <div id="expenseModal" class="modal-overlay">
            <div class="modal">
                <div class="modal-header">
                    <h2 id="expenseModalTitle">Add Expense</h2><button class="btn"
                        style="background:transparent; border:none; color:var(--text-primary);" onclick="closeModal('expenseModal')"><i
                            class="fa-solid fa-xmark"></i></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="expenseId">
                    <div class="form-group" style="width:50%;">
                        <label class="form-label">Date</label>
                        <div style="display:flex; align-items:center; gap:0.75rem;">
                            <input type="date" id="expenseDate" class="form-control" style="flex:1;"
                                oninput="document.getElementById('expenseDateIso').textContent = this.value">
                            <span id="expenseDateIso" style="font-size:0.8rem; color:var(--text-secondary); white-space:nowrap;"></span>
                        </div>
                    </div>
                    <div class="form-group"><label class="form-label">Vendor</label><input type="text"
                            id="expenseVendor" class="form-control" placeholder=""></div>
                    <div class="form-group"><label class="form-label">Category</label>
                        <select id="expenseCategory" class="form-control">
                            <?php foreach (expenseCategories() as $__catKey => $__catLabel): ?>
                                <option value="<?= htmlspecialchars($__catKey) ?>"><?= htmlspecialchars($__catLabel) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group"><label class="form-label">Amount (<?= htmlspecialchars($settings['currency'] ?? 'USD') ?>)</label>
                        <input type="number" id="expenseAmount" class="form-control" step="0.01" min="0"></div>
                    <div class="form-group"><label class="form-label">Description <span
                                style="font-weight:400; color:var(--text-secondary);">(optional)</span></label>
                        <textarea id="expenseDescription" class="form-control" rows="2"></textarea>
                    </div>
                    <div class="form-group"><label class="form-label">Invoice <span
                                style="font-weight:400; color:var(--text-secondary);">(optional — the vendor's bill, if you keep that separately from the receipt)</span></label>
                        <div id="expenseInvoiceFilesList" style="margin-bottom:0.5rem;"></div>
                        <input type="file" id="expenseInvoiceFiles" class="form-control" accept="image/*,.pdf" multiple
                            style="padding:0.5rem;">
                    </div>
                    <div class="form-group"><label class="form-label">Receipt <span
                                style="font-weight:400; color:var(--text-secondary);">(optional — proof of payment; an image here is scanned to prefill Vendor/Amount above)</span></label>
                        <div id="expenseReceiptsList" style="margin-bottom:0.5rem;"></div>
                        <input type="file" id="expenseReceiptFiles" class="form-control" accept="image/*,.pdf" multiple
                            style="padding:0.5rem;" onchange="handleExpenseReceiptFilesChange()">
                        <p id="expenseOcrStatus" style="display:none; color:var(--text-secondary); font-size:0.8rem; margin-top:0.35rem; margin-bottom:0;"></p>
                    </div>
                </div>
                <div class="modal-footer"><button class="btn" onclick="closeModal('expenseModal')">Cancel</button><button
                        class="btn primary" id="saveExpenseBtn" onclick="saveExpense()"><i class="fa-solid fa-save"></i>
                        Save Expense</button></div>
            </div>
        </div>

        <div id="recurringExpenseModal" class="modal-overlay">
            <div class="modal">
                <div class="modal-header">
                    <h2 id="recurringExpenseModalTitle">Add Recurring Expense</h2><button class="btn"
                        style="background:transparent; border:none; color:var(--text-primary);" onclick="closeModal('recurringExpenseModal')"><i
                            class="fa-solid fa-xmark"></i></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="recurringExpenseId">
                    <div class="form-group"><label class="form-label">Vendor</label><input type="text"
                            id="recurringExpenseVendor" class="form-control" placeholder=""></div>
                    <div class="form-group"><label class="form-label">Category</label>
                        <select id="recurringExpenseCategory" class="form-control">
                            <?php foreach (expenseCategories() as $__catKey => $__catLabel): ?>
                                <option value="<?= htmlspecialchars($__catKey) ?>"><?= htmlspecialchars($__catLabel) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div style="display:flex; gap:1rem;">
                        <div class="form-group" style="flex:1;"><label class="form-label">Amount (<?= htmlspecialchars($settings['currency'] ?? 'USD') ?>)</label>
                            <input type="number" id="recurringExpenseAmount" class="form-control" step="0.01" min="0"></div>
                        <div class="form-group" style="flex:1;"><label class="form-label">Frequency</label>
                            <select id="recurringExpenseFrequency" class="form-control">
                                <option value="weekly">Weekly</option>
                                <option value="monthly" selected>Monthly</option>
                                <option value="quarterly">Quarterly</option>
                                <option value="annually">Annually</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group"><label class="form-label">Description <span
                                style="font-weight:400; color:var(--text-secondary);">(optional)</span></label>
                        <textarea id="recurringExpenseDescription" class="form-control" rows="2"></textarea>
                    </div>
                    <p style="color:var(--text-secondary); font-size:0.8rem; margin:0;">Logged automatically as a new expense the next time recurring billing runs (Settings &gt; Billing, or the monthly cron), once per period on today's date — same guard against double-logging as recurring invoices.</p>
                </div>
                <div class="modal-footer"><button class="btn" onclick="closeModal('recurringExpenseModal')">Cancel</button><button
                        class="btn primary" id="saveRecurringExpenseBtn" onclick="saveRecurringExpense()"><i class="fa-solid fa-save"></i>
                        Save</button></div>
            </div>
        </div>

        <div id="viewModal" class="modal-overlay">
            <div class="modal large">
                <div class="modal-header">
                    <h2 id="viewModalTitle">Invoice</h2>
                    <div style="display:flex; gap:0.5rem; align-items:center;">
                        <button class="btn small" id="downloadPdfBtn" onclick="downloadInvoicePdf()"
                            style="font-size:0.8rem;" title="Download as PDF"><i
                                class="fa-solid fa-file-pdf"></i> Download PDF</button>
                        <button class="btn small" id="copyInvoiceLinkBtn" onclick="copyInvoiceLink()"
                            style="font-size:0.8rem;" title="Copy direct link to this invoice file"><i
                                class="fa-solid fa-link"></i> Copy Link</button>
                        <button class="btn small" id="attachmentsBtn" onclick="openAttachmentsModal()"
                            style="font-size:0.8rem;" title="Manage attachments (contracts, receipts)"><i
                                class="fa-solid fa-paperclip"></i> Attachments</button>
                        <button class="btn" style="background:transparent; border:none; color:var(--text-primary);"
                            onclick="closeModal('viewModal')"><i class="fa-solid fa-xmark"></i></button>
                    </div>
                </div>
                <div class="modal-body" style="padding: 0; overflow: hidden; position: relative;">
                    <iframe id="invoicePreview" style="width:100%; height:70vh; border:none; background:white;"></iframe>
                    <div id="invoiceMissingWarning"
                        style="display:none; height:70vh; align-items:center; justify-content:center; text-align:center; padding:2rem; box-sizing:border-box;">
                        <div>
                            <div style="font-size:2rem; margin-bottom:0.75rem; color:var(--warning);"><i
                                    class="fa-solid fa-triangle-exclamation"></i></div>
                            <h3 style="margin:0 0 0.5rem;">Invoice file not found</h3>
                            <p style="color:var(--text-secondary); max-width:420px; margin:0 auto 1rem;">The database
                                record exists, but its file is missing on disk — this instance's database and files
                                have drifted out of sync.</p>
                            <button class="btn primary" onclick="closeModal('viewModal'); nav('sync', true);"><i
                                    class="fa-solid fa-rotate"></i> Go to Sync</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div id="attachmentsModal" class="modal-overlay">
            <div class="modal">
                <div class="modal-header">
                    <h2 id="attachmentsModalTitle">Attachments</h2><button class="btn"
                        style="background:transparent; border:none; color:var(--text-primary);" onclick="closeModal('attachmentsModal')"><i
                            class="fa-solid fa-xmark"></i></button>
                </div>
                <div class="modal-body">
                    <p style="color:var(--text-secondary); font-size:0.85rem; margin-top:0;">Contracts, signed
                        receipts, or any other file worth keeping with this invoice. Stored on this server, not
                        emailed to the client.</p>
                    <div id="attachmentsList" style="margin-bottom:1rem;"></div>
                    <div style="display:flex; gap:0.5rem; align-items:center;">
                        <input type="file" id="attachmentFile" class="form-control" style="padding:0.5rem;">
                        <button class="btn primary" id="uploadAttachmentBtn" onclick="uploadAttachment()"
                            style="white-space:nowrap;"><i class="fa-solid fa-upload"></i> Upload</button>
                    </div>
                </div>
            </div>
        </div>

        <div id="restoreModal" class="modal-overlay">
            <div class="modal large">
                <div class="modal-header">
                    <h2 id="restoreModalTitle">Dry Run Summary</h2>
                    <button class="btn" style="background:transparent; border:none; color:var(--text-primary);"
                        onclick="closeModal('restoreModal')"><i class="fa-solid fa-xmark"></i></button>
                </div>
                <div class="modal-body" id="restoreModalBody" style="max-height:60vh; overflow-y:auto; padding: 1rem;">
                </div>
                <div class="modal-footer">
                    <button class="btn" onclick="closeModal('restoreModal')">Close</button>
                    <button class="btn" style="background:var(--danger); color:white; border:none;"
                        onclick="closeModal('restoreModal'); confirmRestore();"><i class="fa-solid fa-upload"></i>
                        Proceed to Restore</button>
                </div>
            </div>
        </div>

        <div id="paidModal" class="modal-overlay">
            <div class="modal">
                <div class="modal-header">
                    <h2>Mark as Paid</h2><button class="btn" style="background:transparent; border:none; color:var(--text-primary);"
                        onclick="closeModal('paidModal')"><i class="fa-solid fa-xmark"></i></button>
                </div>
                <div class="modal-body"><input type="hidden" id="paidInvoiceId">
                    <div class="form-group"><label class="form-label">Invoice Number</label><input type="text"
                            id="paidInvoiceNum" class="form-control" readonly></div>
                    <div id="paidHistoryWrap" style="display:none; margin-bottom:1rem;">
                        <label class="form-label">Payment History</label>
                        <div id="paidHistoryList" style="font-size:0.85rem; border:1px solid var(--border); border-radius:6px; padding:0.5rem 0.75rem;"></div>
                    </div>
                    <div class="form-group"><label class="form-label">This Payment (<span id="paidAmountCcy"><?= htmlspecialchars($settings['currency'] ?? 'USD') ?></span>)</label><input type="number"
                            step="0.01" min="0.01" id="paidAmount" class="form-control">
                        <p style="color:var(--text-secondary); font-size:0.8rem; margin-top:0.35rem;">Defaults to the
                            remaining balance. Enter a smaller amount to log a partial/installment payment — it's
                            added to this invoice's payment history, not overwritten.</p>
                    </div>
                    <div class="form-group"><label class="form-label">Note <span
                                style="font-weight:400; color:var(--text-secondary);">(optional)</span></label><input
                            type="text" id="paidNote" class="form-control" placeholder="e.g. bank transfer, deposit 1 of 3"></div>
                </div>
                <div class="modal-footer"><button class="btn" onclick="closeModal('paidModal')">Cancel</button><button
                        class="btn success" id="markPaidBtn" onclick="markPaid()"><i class="fa-solid fa-check"></i>
                        Confirm Payment</button></div>
            </div>
        </div>
        <div id="noteModal" class="modal-overlay">
            <div class="modal">
                <div class="modal-header">
                    <h2>Notes &mdash; <span id="noteInvoiceNum" style="font-weight:400; font-size:0.95em;"></span></h2>
                    <button class="btn" style="background:transparent; border:none; color:var(--text-primary);"
                        onclick="closeModal('noteModal')"><i class="fa-solid fa-xmark"></i></button>
                </div>
                <div class="modal-body"><input type="hidden" id="noteInvoiceId">
                    <div id="existingNotesList" style="margin-bottom:1.25rem;"></div>
                    <div class="form-group"><textarea id="noteText" class="form-control" rows="3"
                            placeholder="Type a new note..."></textarea></div>
                </div>
                <div class="modal-footer"><button class="btn" onclick="closeModal('noteModal')">Cancel</button><button
                        class="btn primary" id="addNoteBtn" onclick="addNote()"><i class="fa-solid fa-save"></i> Save
                        Note</button></div>
            </div>
        </div>

        <!-- Factory Reset Modal -->
        <div id="factoryResetModal" class="modal-overlay">
            <div class="modal">
                <div class="modal-header">
                    <h2 style="margin:0; font-size:1.15rem; color:var(--danger);"><i
                            class="fa-solid fa-triangle-exclamation"></i> Factory Reset</h2>
                    <button class="btn" style="background:transparent; border:none; color:var(--text-primary);"
                        onclick="closeModal('factoryResetModal')"><i class="fa-solid fa-xmark"></i></button>
                </div>
                <div class="modal-body">
                    <p style="color:var(--text-secondary); font-size:0.9rem; margin-top:0;">This permanently deletes
                        every client, invoice, quote, note, and setting, every generated invoice file, every stored
                        backup, and every user account — not just yours. There is no undo.</p>
                    <div class="form-group">
                        <label class="form-label" style="font-size:0.8rem; color:var(--text-secondary);">Type
                            <strong>RESET</strong> to confirm</label>
                        <input type="text" id="factoryResetConfirmText" class="form-control"
                            oninput="document.getElementById('factoryResetBtn').disabled = (this.value !== 'RESET')"
                            autocomplete="off">
                    </div>
                    <div class="form-group">
                        <label class="form-label" style="font-size:0.8rem; color:var(--text-secondary);">Current
                            password</label>
                        <input type="password" id="factoryResetPassword" class="form-control"
                            placeholder="Required to confirm it's really you">
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn" onclick="closeModal('factoryResetModal')">Cancel</button>
                    <button class="btn" id="factoryResetBtn" disabled
                        style="background:var(--danger); color:white; border:none;" onclick="doFactoryReset()"><i
                            class="fa-solid fa-bomb"></i> Erase Everything</button>
                </div>
            </div>
        </div>

        <!-- CSV Preview Modal -->
        <div id="csvPreviewModal" class="modal-overlay">
            <div class="modal large" style="max-width: 1000px; width: 95vw;">
                <div class="modal-header">
                    <div>
                        <h2 id="csvPreviewTitle" style="margin:0; font-size:1.15rem;">Export Preview</h2>
                        <p id="csvPreviewSubtitle"
                            style="margin:0.25rem 0 0; font-size:0.8rem; color:var(--text-secondary);"></p>
                    </div>
                    <button class="btn" style="background:transparent; border:none; color:var(--text-primary);"
                        onclick="closeModal('csvPreviewModal')"><i class="fa-solid fa-xmark"></i></button>
                </div>
                <div class="modal-body"
                    style="padding: 1.25rem; overflow-x: auto; overflow-y: auto; flex: 1 1 auto; min-height: 0;">
                    <!-- Summary cards -->
                    <div id="csvPreviewStats" class="mobile-grid"
                        style="display:grid; grid-template-columns:repeat(3,1fr); gap:0.75rem; margin-bottom:1.25rem;">
                    </div>
                    <!-- Loading state -->
                    <div id="csvPreviewLoading" style="text-align:center; padding:2rem; color:var(--text-secondary);">
                        <i class="fa-solid fa-spinner fa-spin" style="font-size:1.5rem; margin-bottom:0.5rem;"></i>
                        <p style="margin:0;">Loading preview data&hellip;</p>
                    </div>
                    <!-- Table -->
                    <div id="csvPreviewTableWrap" style="display:none;">
                        <table id="csvPreviewTable" style="width:100%; border-collapse:collapse; font-size:0.85rem;">
                            <thead id="csvPreviewHead"
                                style="position:sticky; top:0; background:var(--surface); z-index:2;"></thead>
                            <tbody id="csvPreviewBody"></tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer" style="justify-content:space-between; align-items:center;">
                    <span id="csvPreviewRowCount" style="color:var(--text-secondary); font-size:0.85rem;"></span>
                    <div style="display:flex; gap:0.75rem;">
                        <button class="btn" onclick="closeModal('csvPreviewModal')">Cancel</button>
                        <button id="csvPreviewCopyBtn" class="btn"
                            style="background:var(--surface-hover); white-space:nowrap;" onclick="_copyCsvToClipboard()"
                            disabled>
                            <i class="fa-solid fa-copy"></i> Copy
                        </button>
                        <a id="csvPreviewDownloadBtn" href="#" download
                            style="display:inline-flex; align-items:center; gap:0.4rem; padding:0.6rem 1rem; border-radius:6px; font-weight:600; font-size:0.9rem; color:white; text-decoration:none; transition:opacity 0.2s;"
                            onmouseover="this.style.opacity='0.85'" onmouseout="this.style.opacity='1'">
                            <i class="fa-solid fa-file-csv"></i> Download CSV
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <div id="toast" class="toast">Action completed successfully</div>

        <!-- Shown once, briefly, right after a login/signup redirect (?login=1) —
             see the justLoggedIn JS below. Not a toast (those are for action
             confirmations); this is a one-time greeting, so it gets its own
             element rather than reusing #toast. A light backdrop rides along
             with it (toggled by the same .show class) so the card doesn't get
             lost against whatever tab happens to be underneath. -->
        <div id="welcomeFlashBackdrop" class="welcome-flash-backdrop"></div>
        <div id="welcomeFlash" class="welcome-flash">
            <img src="assets/img/invoxa-mark.svg" alt="">
            <div>
                <div class="welcome-flash-eyebrow">
                    <span class="brand-wordmark">INVOXA</span>
                </div>
                <div class="welcome-flash-title">Welcome back, <?= htmlspecialchars($_SESSION['invoxa_username'] ?? 'there') ?></div>
                <div class="welcome-flash-sub"><?= htmlspecialchars($settings['business_name'] ?? 'Invoxa') ?> ·
                    signed in <?= htmlspecialchars(date('D, M j \a\t g:ia')) ?></div>
            </div>
        </div>

        <?php $__ev = $mysqli->query("SELECT email, email_verified_at FROM invoxa_users WHERE id = " . $currentUserId)->fetch_assoc(); ?>
        <div id="onboardingModal" class="modal-overlay">
            <div class="modal" style="max-width:440px; text-align:center;">
                <div class="modal-body" style="padding-top:2.5rem;">
                    <img src="assets/img/invoxa-mark.svg" width="48" height="48" alt=""
                        style="border-radius:12px; box-shadow:0 6px 18px -4px rgba(79,124,255,0.55); margin-bottom:1rem;">
                    <div style="margin-bottom:0.75rem;"><img src="assets/img/invoxa-wordmark.svg" height="26" alt="Invoxa"
                            style="width:auto;"></div>
                    <h2 style="margin:0 0 0.5rem; font-size:1.3rem;">Welcome to Invoxa</h2>
                    <p style="color:var(--text-secondary); font-size:0.9rem; margin:0 0 1.5rem;">Your account is set up.
                        Load a set of sample clients and invoices to explore the app right away, or start from a clean
                        slate — you'll find this again under Data Management &gt; Demo Data.</p>
                    <?php if ($__ev && empty($__ev['email_verified_at'])): ?>
                    <div style="background:var(--surface-hover); border-radius:10px; padding:0.85rem 1rem; margin-bottom:1.5rem; text-align:left;">
                        <p style="color:var(--text-secondary); font-size:0.82rem; margin:0 0 0.5rem;">We sent a
                            confirmation link to <strong><?= htmlspecialchars($__ev['email']) ?></strong> — click it so
                            account recovery can reach you if you ever forget your password.</p>
                        <button class="btn" id="resendVerifyBtn" style="width:auto; margin:0; padding:0.4rem 0.8rem; font-size:0.8rem;"
                            onclick="resendVerificationEmail()">Resend confirmation email</button>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer" style="justify-content:center; gap:0.75rem;">
                    <button class="btn" onclick="closeModal('onboardingModal')">Start from scratch</button>
                    <button class="btn primary"
                        onclick="closeModal('onboardingModal'); nav('backup', true); navBackup('demo');"><i
                            class="fa-solid fa-wand-magic-sparkles"></i> Load Demo Data</button>
                </div>
            </div>
        </div>

        <!-- CRM Slide-out Drawer -->
        <div id="crmDrawer"
            style="position:fixed; top:0; right:-440px; width:420px; height:100vh; background:var(--surface); border-left:1px solid var(--border); z-index:9999; transition:right 0.3s ease; display:flex; flex-direction:column; box-shadow:-8px 0 30px rgba(0,0,0,0.4);">
            <div
                style="padding:1.5rem; border-bottom:1px solid var(--border); display:flex; justify-content:space-between; align-items:center;">
                <h3 id="crmDrawerTitle" style="margin:0; font-size:1.1rem; color:var(--text-primary);"><i
                        class="fa-solid fa-user" style="color:var(--accent); margin-right:0.5rem;"></i>Client Details
                </h3>
                <button onclick="closeCrm()"
                    style="background:none; border:none; color:var(--text-secondary); cursor:pointer; font-size:1.2rem;"><i
                        class="fa-solid fa-xmark"></i></button>
            </div>
            <div id="crmDrawerBody" style="flex:1; overflow-y:auto; padding:1.5rem;">
                <div id="crmStats" class="mobile-grid" style="display:grid; grid-template-columns:1fr 1fr; gap:1rem; margin-bottom:1.5rem;">
                </div>
                <h4
                    style="color:var(--text-secondary); font-size:0.8rem; text-transform:uppercase; letter-spacing:0.05em; margin-bottom:0.75rem;">
                    Recent Invoices</h4>
                <div id="crmRecentInvoices" style="margin-bottom:1.5rem;"></div>
                <h4
                    style="color:var(--text-secondary); font-size:0.8rem; text-transform:uppercase; letter-spacing:0.05em; margin-bottom:0.75rem;">
                    Internal Notes</h4>
                <textarea id="crmNotes" class="form-control" rows="6"
                    placeholder="Private notes about this client..."></textarea>
                <button onclick="saveCrmNotes()" class="btn primary" style="margin-top:0.75rem; width:100%;"><i
                        class="fa-solid fa-save"></i> Save Notes</button>
            </div>
        </div>
        <div id="crmOverlay" onclick="closeCrm()"
            style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.4); z-index:9998;"></div>
