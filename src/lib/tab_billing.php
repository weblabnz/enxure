        <!-- AD HOC INVOICE -->
        <div id="sec-billing" class="section">
            <h2 class="page-title" id="billingPageTitle">Ad Hoc Invoice</h2>
            <div class="section-scroll">
            <div class="card" style="max-width: 900px;">
                <div class="card-header">
                    <h3 style="margin:0; font-size: 1.1rem;" id="billingCardTitle">Create Adhoc Invoice (One-Off)</h3>
                </div>
                <div class="card-body">
                    <input type="hidden" id="isQuoteFlag" value="0">
                    <div class="form-group">
                        <label class="form-label">Client</label>
                        <select id="adhocClient" class="form-control" onchange="updateAdhocClientInfo()">
                            <option value="">-- Select Client --</option>
                            <?php foreach ($clients as $c): ?>
                                <option value="<?= $c['id'] ?>"
                                    data-outstanding="<?= round(max(0, ($c['total_billed'] ?? 0) - ($c['total_paid'] ?? 0)), 2) ?>"
                                    data-terms="<?= (int) ($c['payment_terms_days'] ?? 21) ?>"
                                    data-currency="<?= htmlspecialchars(enxureResolveCurrency($c['currency'] ?? '', $settings)) ?>"><?= htmlspecialchars($c['client_name']) ?>
                                    (<?= htmlspecialchars($c['email']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                        <div id="adhocClientBalance" style="display:none; margin-top:0.4rem; font-size:0.8rem; color:var(--warning);"></div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Line Items</label>
                        <table style="width:100%; border-collapse:collapse; margin-bottom:0.5rem;">
                            <thead>
                                <tr style="font-size:0.8rem; color:var(--text-secondary);">
                                    <th style="padding:0 0.5rem 0.4rem 0; width:110px; text-align:left;">Code</th>
                                    <th style="padding:0 0.5rem 0.4rem 0; text-align:left;">Description</th>
                                    <th style="padding:0 0.5rem 0.4rem 0; width:110px; text-align:right;">Amount (<span id="adhocAmountCcy"><?= htmlspecialchars($settings['currency'] ?? 'USD') ?></span>)
                                    </th>
                                    <th style="width:32px;"></th>
                                </tr>
                            </thead>
                            <tbody id="lineItemsBody">
                                <tr class="line-item-row">
                                    <td style="padding:0 0.5rem 0.5rem 0;"><input type="text"
                                            class="form-control li-code" placeholder="WEB01" style="font-size:0.85rem;">
                                    </td>
                                    <td style="padding:0 0.5rem 0.5rem 0;"><input type="text"
                                            class="form-control li-desc" placeholder="e.g. Website setup fee"
                                            style="font-size:0.85rem;"></td>
                                    <td style="padding:0 0.5rem 0.5rem 0;"><input type="number"
                                            class="form-control li-amount" step="0.01" placeholder="0.00"
                                            style="font-size:0.85rem; text-align:right;"></td>
                                    <td style="padding:0 0 0.5rem 0;"></td>
                                </tr>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td colspan="2" style="text-align:right; padding:0.5rem 0.5rem 0 0; font-size:0.85rem; color:var(--text-secondary);">Subtotal</td>
                                    <td id="adhocSubtotal" style="text-align:right; padding:0.5rem 0.5rem 0 0;">0.00</td>
                                    <td></td>
                                </tr>
                                <tr>
                                    <td colspan="2" style="text-align:right; padding:0.5rem 0.5rem 0 0; font-size:0.85rem; color:var(--text-secondary);">Discount
                                        <input type="number" id="adhocDiscountPct" class="form-control" value="0" step="0.01" min="0" max="100"
                                            style="display:inline-block; width:60px; font-size:0.8rem; padding:0.2rem 0.4rem;"> %</td>
                                    <td id="adhocDiscountAmt" style="text-align:right; padding:0.5rem 0.5rem 0 0;">0.00</td>
                                    <td></td>
                                </tr>
                                <tr>
                                    <td colspan="2" style="text-align:right; padding:0.5rem 0.5rem 0 0; font-size:0.85rem; color:var(--text-secondary);">Tax
                                        <input type="number" id="adhocTaxRate" class="form-control" value="0" step="0.01" min="0" max="100"
                                            style="display:inline-block; width:60px; font-size:0.8rem; padding:0.2rem 0.4rem;"> %</td>
                                    <td id="adhocTaxAmt" style="text-align:right; padding:0.5rem 0.5rem 0 0;">0.00</td>
                                    <td></td>
                                </tr>
                                <tr>
                                    <td colspan="2" style="text-align:right; padding:0.5rem 0.5rem 0 0; font-size:0.85rem; color:var(--text-secondary);">Total</td>
                                    <td id="adhocRunningTotal" style="text-align:right; padding:0.5rem 0.5rem 0 0; font-weight:600;">0.00</td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                        <button type="button" class="btn small" onclick="addLineItem()" style="font-size:0.8rem;"><i
                                class="fa-solid fa-plus"></i> Add Row</button>
                    </div>
                    <div style="display:flex; gap:1rem; flex-wrap:wrap;">
                        <div class="form-group" style="flex:1; min-width:180px;">
                            <label class="form-label">Due Date <span style="font-weight:400; color:var(--text-secondary);">(optional)</span></label>
                            <input type="date" id="adhocDueDate" class="form-control">
                            <div id="adhocDueDateHint" style="margin-top:0.3rem; font-size:0.75rem; color:var(--text-secondary);"></div>
                        </div>
                        <div class="form-group" id="adhocQuoteExpiryGroup" style="display:none; flex:1; min-width:180px;">
                            <label class="form-label">Quote Expires <span style="font-weight:400; color:var(--text-secondary);">(optional)</span></label>
                            <input type="date" id="adhocQuoteExpiry" class="form-control">
                            <div style="margin-top:0.3rem; font-size:0.75rem; color:var(--text-secondary);">Shown to the client; leave blank for no expiry.</div>
                        </div>
                        <div class="form-group" style="flex:2; min-width:240px;">
                            <label class="form-label">Internal Note <span style="font-weight:400; color:var(--text-secondary);">(optional, not shown to client)</span></label>
                            <textarea id="adhocMemo" class="form-control" rows="1" placeholder="e.g. Approved by Jane on the phone"></textarea>
                        </div>
                    </div>
                    <div
                        style="display:flex; gap:0.75rem; flex-wrap:wrap; justify-content:flex-end; margin-top:1.75rem; padding-top:1.5rem; border-top:1px solid var(--border);">
                        <button class="btn" id="previewAdhocBtn" onclick="previewAdhocInvoice()"
                            style="padding:0.7rem 1.3rem;"><i class="fa-solid fa-eye"></i> Preview</button>
                        <button class="btn" id="saveQuoteBtn" onclick="sendAdhocInvoice(true)"
                            style="padding:0.7rem 1.3rem; background:rgba(139,92,246,0.2); border-color:rgba(139,92,246,0.4); color:#a78bfa;"><i
                                class="fa-solid fa-file-pen"></i> Save as Quote</button>
                        <button class="btn primary" id="sendAdhocBtn" onclick="sendAdhocInvoice(false)"
                            style="padding:0.7rem 1.5rem;"><i class="fa-solid fa-paper-plane"></i> Generate &amp;
                            Send</button>
                    </div>
                </div>
            </div>
            </div>
        </div>

