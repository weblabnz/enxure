        <!-- DOCS -->
        <div id="sec-docs" class="section">
            <h2 class="page-title">Documentation</h2>
            <div class="section-scroll">
            <div class="subnav-layout">

                <?php
                // Two-level nav (category > page), plus a client-side search box that
                // filters by title and each page's rendered text (see filterDocsNav()
                // below) — every page's content is already in the DOM, just hidden.
                $__docCategories = [
                    'Getting Started' => ['readme' => 'Quick Start', 'install' => 'Installation Guide'],
                    'Features' => [
                        'overview' => 'Overview',
                        'feat-invoicing' => 'Invoicing & Quotes',
                        'feat-recurring' => 'Recurring Billing',
                        'feat-payments' => 'Payments',
                        'feat-clients' => 'Clients & Portal',
                        'feat-security' => 'Security',
                        'feat-api' => 'External API',
                        'feat-reporting' => 'Reporting',
                        'feat-data' => 'Data Management',
                        'feat-notifications' => 'Notifications',
                    ],
                    'Reference' => ['roadmap' => 'Roadmap', 'changelog' => 'Changelog', 'license' => 'License (AGPL-3.0)', 'source' => 'Source Code'],
                ];
                ?>
                <nav class="subnav" id="docsNav" style="min-width:220px;">
                    <div style="padding:0 0.25rem 0.75rem;">
                        <input type="text" id="docsSearchInput" class="form-control" placeholder="Search docs…"
                            oninput="filterDocsNav()" style="font-size:0.85rem;">
                    </div>
                    <?php foreach ($__docCategories as $__catName => $__catPages): ?>
                        <div class="docs-nav-category" data-category="<?= htmlspecialchars($__catName) ?>">
                            <div style="padding:0.5rem 0.75rem 0.25rem; font-size:0.72rem; font-weight:700; text-transform:uppercase; letter-spacing:0.05em; color:var(--text-secondary);">
                                <?= htmlspecialchars($__catName) ?></div>
                            <?php foreach ($__catPages as $__pageId => $__pageTitle): ?>
                                <button type="button" class="subnav-item docs-nav-page<?= $__pageId === 'readme' ? ' active' : '' ?>"
                                    data-docs-target="<?= htmlspecialchars($__pageId) ?>" data-title="<?= htmlspecialchars(strtolower($__pageTitle)) ?>"
                                    onclick="navDocs('<?= htmlspecialchars($__pageId) ?>')" style="padding-left:1.5rem;"><?= htmlspecialchars($__pageTitle) ?></button>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                    <div id="docsNoResults" style="display:none; padding:0.5rem 1rem; color:var(--text-secondary); font-size:0.85rem;">
                        No matching pages.</div>
                </nav>

                <div class="subnav-content">
                    <div class="subnav-pane active" id="docs-pane-readme">
                        <div class="card">
                            <div class="card-body doc-content">
                                <?php
                                $__readmeFile = DOCS_DIR . 'README.md';
                                echo is_file($__readmeFile) ? invoxaRenderMarkdown(file_get_contents($__readmeFile)) : '<p>Document not found.</p>';
                                ?>
                            </div>
                        </div>
                    </div>
                    <div class="subnav-pane" id="docs-pane-install">
                        <div class="card">
                            <div class="card-body doc-content">
                                <?php
                                $__installFile = DOCS_DIR . 'INSTALL.md';
                                echo is_file($__installFile) ? invoxaRenderMarkdown(file_get_contents($__installFile)) : '<p>Document not found.</p>';
                                ?>
                            </div>
                        </div>
                    </div>
                    <div class="subnav-pane" id="docs-pane-roadmap">
                        <div class="card">
                            <div class="card-body doc-content">
                                <h1>Roadmap</h1>
                                <p>What's coming next.</p>
                                <ul>
                                    <li><strong>Currency-grouped Stats &amp; accounting exports</strong> — Statistics and the Accounting Journal/QuickBooks (IIF) exports currently report on the instance default currency only, excluding other-currency invoices instead of grouping them in.</li>
                                    <li><strong>CSRF tokens</strong> — no explicit CSRF protection exists yet on state-changing actions; today's browsers' default same-site cookie behavior mitigates the classic attack, but proper tokens are the correct long-term fix (see Security Review in CODEBASE.md).</li>
                                    <li><strong>Session ID regeneration on login</strong> — the session ID isn't rotated after a successful login (session-fixation-shaped gap; no known exploit path currently, see Security Review in CODEBASE.md).</li>
                                </ul>
                                <p>No fixed release dates yet, but work is underway. If that would help you, or you have your own idea, raise it on the GitLab repo (see <strong>Source Code</strong>).</p>
                            </div>
                        </div>
                    </div>

                    <div class="subnav-pane" id="docs-pane-changelog">
                        <div class="card">
                            <div class="card-body doc-content">
                                <?php
                                $__changelogFile = DOCS_DIR . 'CHANGELOG.md';
                                echo is_file($__changelogFile) ? invoxaRenderMarkdown(file_get_contents($__changelogFile)) : '<p>Document not found.</p>';
                                ?>
                            </div>
                        </div>
                    </div>

                    <div class="subnav-pane" id="docs-pane-license">
                        <div class="card">
                            <div class="card-body doc-content">
                                <h1>License</h1>
                                <p>Invoxa is free and open source software, licensed under the GNU Affero General
                                    Public License v3.0 (AGPL-3.0). You can self-host it, read every line of it, and
                                    modify your own copy — the full, unmodified license text is reproduced below
                                    exactly as it must be distributed. A paid license key is a separate, optional
                                    unlock for seven specific features (Stripe/PayPal payment collection, recurring
                                    billing automation, the Client Portal, the external API, Reporting &amp;
                                    Statistics, adding teammates beyond your own account, and removing the "Powered
                                    by Invoxa" credit) — see <strong>Security</strong> under Features for how that
                                    works.</p>
                                <?php
                                $__licenseFile = DOCS_DIR . 'LICENSE';
                                echo is_file($__licenseFile)
                                    ? '<pre style="white-space:pre-wrap; font-family:inherit; font-size:0.88rem; line-height:1.55; background:var(--bg); border:1px solid var(--border); border-radius:8px; padding:1rem 1.25rem;">' . htmlspecialchars(file_get_contents($__licenseFile)) . '</pre>'
                                    : '<p>Document not found.</p>';
                                ?>
                            </div>
                        </div>
                    </div>

                    <div class="subnav-pane" id="docs-pane-source">
                        <div class="card">
                            <div class="card-body doc-content">
                                <h1>Source Code</h1>
                                <p>Invoxa's source is public on GitLab: <a href="https://gitlab.com/weblabnz/invoxa"
                                        target="_blank">gitlab.com/weblabnz/invoxa</a>. Read the code, file an issue, or
                                    fork it for your own self-hosted copy — see <strong>License (AGPL-3.0)</strong> for
                                    what that license requires if you distribute a modified version.</p>
                                <p>Don't want a GitLab account just to report something? Email
                                    <code>contact-project+weblabnz-invoxa-inv@incoming.gitlab.com</code> instead — it
                                    creates the same issue either way.</p>
                            </div>
                        </div>
                    </div>

                    <div class="subnav-pane" id="docs-pane-overview">
                        <div class="card">
                            <div class="card-body doc-content">
                                <h1>What Invoxa Does</h1>
                                <p>A self-hosted invoicing and billing tool for one business — one or more
                                    accounts (Settings &gt; Users), each Admin or Member. Each topic under
                                    <strong>Features</strong> in the sidebar covers one part in more depth — this
                                    page is just the map.</p>
                                <ul>
                                    <li><strong>Invoicing &amp; Quotes</strong> — ad hoc invoices, line items,
                                        discount/tax, PDF generation, quotes.</li>
                                    <li><strong>Recurring Billing</strong> — per-client schedule, cron-driven, late
                                        fees, reminders.</li>
                                    <li><strong>Payments</strong> — the payment ledger, Stripe/PayPal, refunds, Pay
                                        Now links.</li>
                                    <li><strong>Clients &amp; Portal</strong> — client records, CRM notes, the
                                        Client Portal (quote acceptance included).</li>
                                    <li><strong>Security</strong> — 2FA, backup codes, login lockout.</li>
                                    <li><strong>External API</strong> — token-authenticated read/write API for other
                                        tools.</li>
                                    <li><strong>Reporting</strong> — dashboard, statistics tabs, Audit Log.</li>
                                    <li><strong>Data Management</strong> — backups, offsite push, demo data, Test
                                        Suite.</li>
                                    <li><strong>Notifications</strong> — Slack/Telegram alerts.</li>
                                </ul>
                                <p>New here? Start with <strong>Quick Start</strong> or the <strong>Installation
                                        Guide</strong> above.</p>
                            </div>
                        </div>
                    </div>

                    <div class="subnav-pane" id="docs-pane-feat-invoicing">
                        <div class="card">
                            <div class="card-body doc-content">
                                <h1>Invoicing &amp; Quotes</h1>
                                <h2>Building an Ad Hoc invoice</h2>
                                <p>From the Invoices tab, start a new invoice by picking a <strong>Client</strong>
                                    from the dropdown, then use <strong>Add Row</strong> to build up as many line
                                    items as the job needs — each row has its own code, description, and amount, and
                                    any row can be removed again before sending. Two invoice-level fields sit under
                                    the line items: <strong>Discount %</strong> and <strong>Tax %</strong>, both
                                    optional. As soon as either is non-zero, Invoxa switches on a live
                                    Subtotal/Discount/Tax/Total breakdown so the math is visible before you send
                                    anything — leave both at zero and the invoice just totals the line items
                                    directly, no breakdown shown.</p>
                                <p>Due date can be typed in manually, or left blank to fall back to the client's own
                                    <strong>Payment Terms (days)</strong> figure from their Client record, counted
                                    from the invoice date. There's also an <strong>Internal Note</strong> field —
                                    it's saved with the invoice for your own reference but is never shown to the
                                    client or included in the emailed/PDF version.</p>
                                <h2>Templates &amp; sending</h2>
                                <p>Which layout an invoice renders in — <strong>Detailed</strong> or
                                    <strong>Compact</strong> — is a single instance-wide choice under Settings &gt;
                                    Branding, not something picked per invoice. Sending an invoice emails the client
                                    the rendered HTML and attaches a server-generated PDF (built with dompdf); the
                                    "Download PDF" button on the invoice itself renders through the exact same code
                                    path, so what you download always matches what a client received. Every send —
                                    and every send failure — is written to the Audit Log with the invoice number and
                                    recipient. <strong>Resend Invoice Email</strong> re-sends that same stored
                                    HTML/PDF later (e.g. a client says they lost it) without touching the invoice
                                    number or regenerating anything.</p>
                                <h2>Quotes</h2>
                                <p>Quotes use the identical line-item builder as Ad Hoc invoices, but
                                    <strong>Save Quote</strong> stores it without emailing anything and without
                                    consuming a real invoice number — quotes get their own numbering, formatted as
                                    <code>Q&lt;CLIENTKEY&gt;001</code> (the client's key, then a per-client sequence),
                                    so a quote number is never mistakable for an invoice number. When the client
                                    accepts, convert the quote to a real invoice from the Quotes list — every line
                                    item, discount, and tax setting carries over, so nothing gets retyped, and only
                                    at that point does it consume an actual invoice number and become billable.</p>
                                <h2>Void</h2>
                                <p>A mistaken or cancelled invoice can be voided instead of deleted, from the invoice
                                    row's action menu. Voiding pulls it out of every outstanding, overdue, and
                                    revenue total instantly, but the record itself — line items, amount, send
                                    history — stays intact and visible, so nothing about what happened is lost from
                                    the Audit Log. Unvoid restores it to exactly where it left off (paid/unpaid
                                    status included) if it turns out it shouldn't have been voided.</p>
                            </div>
                        </div>
                    </div>

                    <div class="subnav-pane" id="docs-pane-feat-recurring">
                        <div class="card">
                            <div class="card-body doc-content">
                                <h1>Recurring Billing</h1>
                                <p><strong>Requires a license.</strong> Ad Hoc invoicing stays free either way — this
                                    page covers the automated side specifically: the cron-driven billing schedule,
                                    late fees, and payment reminders below.</p>
                                <p>Each client carries its own billing schedule on the Client form:
                                    <strong>Billing Frequency</strong> (weekly/monthly/quarterly/annually),
                                    <strong>Rate</strong> (per billing period, in your instance currency),
                                    <strong>Payment Terms (days)</strong>, plus optional <strong>Discount %</strong>
                                    and <strong>Tax Rate %</strong> (both default to 0, so recurring invoices behave
                                    exactly like a plain rate unless you explicitly set one). One cron job — configured
                                    once under Settings &gt; Billing, not per client — walks every
                                    active client on each run and bills whichever ones are actually due for their own
                                    frequency; a client billed weekly and one billed annually can happily share the
                                    same cron trigger.</p>
                                <h2>Double-billing guard</h2>
                                <p>Before generating an invoice for a client, Invoxa checks whether that client
                                    already has an invoice in the current period — the current week/month/quarter/
                                    year, matched against their own frequency — and skips them if one already exists.
                                    That's what makes a misconfigured cron schedule (say, hourly instead of monthly)
                                    a non-event instead of a billing disaster: the guard just keeps skipping the
                                    client until the next real period starts. If you genuinely need to re-run a
                                    missed cycle on purpose, a "bypass guard" toggle in the same settings panel lets
                                    one run ignore the check.</p>
                                <h2>Late fees</h2>
                                <p>Off by default. When turned on (Settings &gt; Billing &gt; Late
                                    Fees), three fields control it: <strong>Fee Type</strong> (Percentage of the
                                    overdue invoice, or a Flat amount), <strong>Fee Value</strong> (the percentage or
                                    currency amount, depending on the type chosen), and <strong>Grace Period</strong>
                                    — how many days overdue an invoice must be before the fee applies. A late fee is
                                    charged as its own proper billable invoice, referencing the original overdue
                                    invoice's number in its description — never just a note tacked onto the existing
                                    invoice — and each overdue invoice is only ever charged one late fee, no matter
                                    how many further cron runs pass while it stays unpaid.</p>
                                <h2>Payment reminders</h2>
                                <p>Also off by default, toggled independently of late fees in the same settings
                                    panel. Once active, every unpaid invoice automatically gets one reminder email as
                                    soon as it crosses <strong>7 days overdue</strong> — that threshold isn't
                                    configurable, but the email itself is: edit the <strong>Reminder Email
                                        Subject</strong> and <strong>Reminder Email Body</strong> under Settings &gt;
                                    Email, using the same token placeholders (client name, invoice number, due date,
                                    days overdue, amount) as the main invoice template. The reminder resends the
                                    original invoice's actual HTML alongside the reminder text, so a client chasing
                                    it up sees the real invoice again, not just a bare notice.</p>
                            </div>
                        </div>
                    </div>

                    <div class="subnav-pane" id="docs-pane-feat-payments">
                        <div class="card">
                            <div class="card-body doc-content">
                                <h1>Payments</h1>
                                <p>Marking invoices paid manually is free. <strong>Stripe/PayPal collection requires
                                        a license</strong> — the Stripe &amp; Refunds sections below.</p>
                                <h2>Marking an invoice paid manually</h2>
                                <p>Open <strong>Mark Paid</strong> on any invoice and the modal shows the
                                    <strong>Payment History</strong> for that invoice — every installment already
                                    recorded — above a <strong>This Payment</strong> amount field that defaults to
                                    the remaining balance, not the full invoice total, so a partial payment doesn't
                                    require doing subtraction by hand. An optional <strong>Note</strong> field
                                    records anything worth remembering about that specific installment (a check
                                    number, "paid via bank transfer", etc). Because every payment — manual or
                                    online — is its own ledger row rather than a single paid/unpaid flag, an invoice
                                    can be paid off across several installments over time with a full, honest
                                    history, while the invoice's own cached paid amount and status stay correct
                                    automatically as each row is added. To clear several invoices at once, select
                                    them with the checkbox column on the Invoices tab and use <strong>Mark
                                        Paid</strong> in the bulk action bar that appears.</p>
                                <h2>Stripe &amp; PayPal</h2>
                                <p>Both are configured under Settings &gt; Payments, and both are off until you add
                                    credentials there: Stripe needs a <strong>Secret Key</strong> and a
                                    <strong>Webhook Signing Secret</strong>; PayPal needs an
                                    <strong>Environment</strong> (Sandbox/Live), <strong>Client ID</strong>,
                                    <strong>Client Secret</strong>, and <strong>Webhook ID</strong>. A
                                    <strong>Test Connection</strong> button next to each gateway's fields confirms
                                    the credentials actually work before you rely on them. A
                                    <strong>Public URL</strong> field on the same settings tab matters specifically
                                    for Recurring Billing invoices, since those are emailed by a background cron job
                                    with no browser request to infer your domain from — without it, a cron-generated
                                    invoice's Pay Now link can't be built.</p>
                                <p>Once enabled, a "Pay Now" button appears on emailed invoices and on outstanding
                                    invoices in the Client Portal, using each provider's own standard hosted checkout
                                    (a Stripe Checkout Session, or a PayPal Order that's then captured). A payment is
                                    only ever credited to an invoice once its webhook arrives and its signature
                                    verifies — Stripe's is checked locally with HMAC-SHA256 against your signing
                                    secret, PayPal's is verified by calling PayPal's own verify-webhook-signature
                                    API. The page a client's browser lands on right after paying is only ever a
                                    faster-feeling confirmation screen; it is never itself trusted to mark anything
                                    paid, so a closed tab or a flaky redirect can't cause a missed payment.</p>
                                <h2>Refunds</h2>
                                <p>A refund issued from the Stripe or PayPal dashboard (not from inside Invoxa —
                                    there's no refund button here, by design) reopens the invoice and reduces its
                                    recorded paid amount, arriving through that same webhook path. It requires
                                    subscribing your existing webhook to one extra event per gateway —
                                    <code>charge.refunded</code> for Stripe, <code>PAYMENT.CAPTURE.REFUNDED</code>
                                    for PayPal — the exact webhook URLs and event names to add are shown right on
                                    Settings &gt; Payments next to each gateway's credentials.</p>
                            </div>
                        </div>
                    </div>

                    <div class="subnav-pane" id="docs-pane-feat-clients">
                        <div class="card">
                            <div class="card-body doc-content">
                                <h1>Clients &amp; Client Portal</h1>
                                <h2>The client record</h2>
                                <p>The Add/Edit Client form, in order: <strong>Client Name</strong> and
                                    <strong>Email Address</strong>; <strong>Rate</strong> (per billing period) and
                                    <strong>Currency</strong> — a 3-letter code (USD, EUR, GBP, etc.) for that
                                    client's invoices and quotes; leave it blank to use the instance default
                                    (Settings &gt; General). Each invoice/quote snapshots the client's currency at
                                    the moment it's created, so changing a client's currency later never rewrites
                                    their past invoices. There's no automatic exchange-rate conversion — amounts in
                                    a different currency are grouped separately rather than added together, and
                                    Statistics/accounting exports currently report on the instance default currency
                                    only (see Roadmap); <strong>Billing Frequency</strong>
                                    (weekly/monthly/quarterly/annually); <strong>Payment Terms (days)</strong>,
                                    which drives the default due date on that client's invoices when one isn't set
                                    manually; <strong>Discount %</strong> and <strong>Tax Rate %</strong>, both
                                    defaulting to 0 and applied automatically to that client's Recurring Billing
                                    invoices; <strong>Bank Account Name</strong> and <strong>Bank Account
                                        Number</strong>, shown on that client's invoices unless overridden elsewhere;
                                    and two checkboxes, <strong>Active</strong> (checked by default — an inactive
                                    client is skipped by Recurring Billing) and <strong>Is Test Client</strong>
                                    (used by Demo Data and the Test Suite to mark records that should never count
                                    toward real totals or reports). Bulk import and export both go through CSV, from
                                    the Clients tab.</p>
                                <h2>CRM notes &amp; the client drawer</h2>
                                <p>Opening a client's CRM notes slides out a drawer alongside a quick summary of that
                                    client's own activity — recent invoices and running totals — so you can check
                                    context before writing a note, rather than needing to leave the client and go
                                    look it up separately. Notes are free-text and purely internal; they're never
                                    shown to the client anywhere, including in the Client Portal.</p>
                                <h2>Client Portal</h2>
                                <p><strong>Requires a license</strong> to generate or regenerate a link; revoking one
                                    is always free.</p>
                                <p>From the Client Portal section of the same Add/Edit Client form, generate a
                                    token-gated link for that client — no login involved — that shows their own
                                    invoice list and paid/outstanding/overdue status. Pick an <strong>Expires</strong>
                                    value (30 days, 90 days — the default, 1 year, or Never) before generating.
                                    Nothing is emailed automatically when a link is created; you copy and share it
                                    yourself however you'd normally reach that client. Regenerating or revoking a
                                    link immediately invalidates the old token, so a link you've shared can be cut
                                    off at any time without affecting the client's other data.</p>
                                <p>Invoice status is still read-only, but any of that client's open quotes now show
                                    there too with an <strong>Accept Quote</strong> button — a confirmation step
                                    first, then it converts straight to a real invoice the same way your own Convert
                                    button does, and you get notified (see Settings &gt; Notifications) instead of
                                    having to check back. An expired quote (see quote expiry under Invoicing &amp;
                                    Quotes) shows as Expired instead and can't be accepted.</p>
                            </div>
                        </div>
                    </div>

                    <div class="subnav-pane" id="docs-pane-feat-security">
                        <div class="card">
                            <div class="card-body doc-content">
                                <h1>Security</h1>
                                <h2>Two-factor authentication</h2>
                                <p>From Settings &gt; Account, the Two-Factor Authentication card's
                                    <strong>Enable Two-Factor Authentication</strong> button generates a fresh TOTP
                                    secret and shows it on screen
                                    for you to add to any standard authenticator app (Invoxa implements TOTP and
                                    base32 itself — no external service is contacted). You confirm setup by entering
                                    the 6-digit code the app produces; from that point on, login requires the
                                    password followed by a fresh code. At the same moment setup is confirmed, ten
                                    single-use <strong>backup codes</strong> are generated and shown exactly once —
                                    save them somewhere safe, since Invoxa doesn't display them again. Each backup
                                    code can substitute for a TOTP code at login exactly once; once used, or once
                                    <strong>Regenerate Backup Codes</strong> is clicked, it's dead. Both
                                    <strong>Regenerate Backup Codes</strong> and <strong>Disable Two-Factor
                                        Authentication</strong> require re-entering your current password in the
                                    Current Password field on the same card, so a session left logged in on a shared
                                    machine can't be used to quietly turn 2FA off or invalidate someone's saved
                                    codes.</p>
                                <h2>Login lockout</h2>
                                <p>5 failed attempts locks the account for 15 minutes — a wrong password counts, and
                                    so does a wrong 2FA code or a wrong/already-used backup code, at either stage of
                                    login. The counter resets on a successful login. This is enforced server-side
                                    regardless of what the login form itself shows, so it can't be bypassed by
                                    retrying more carefully.</p>
                                <h2>Users &amp; roles</h2>
                                <p>Settings &gt; Users manages every account. <strong>Admin</strong> accounts have
                                    full access, including Settings and Data Management.
                                    <strong>Member</strong> accounts can use everything day-to-day — Dashboard,
                                    Invoices, Clients, Quotes, Expenses — plus their own Account tab (username,
                                    email, password, 2FA), but nothing else under Settings and nothing under Data
                                    Management. The account created at signup is always an admin; the last admin
                                    can't be demoted or deleted, so there's always at least one account able to
                                    manage the rest. Adding a second (or further) account requires a license —
                                    editing or removing an existing one stays free either way, the same pattern as
                                    API tokens and the Client Portal below.</p>
                                <h2>Invoxa is open source — licensing only unlocks seven extras</h2>
                                <p>Invoxa is free and open source (AGPL-3.0): client and invoice management, quotes,
                                    manual payments, backups, and 2FA all work fully with no license key at all — an
                                    unlicensed install is never locked out of its own account or its own data. A
                                    license is a paid, optional unlock for seven specific capabilities: Stripe/PayPal
                                    payment collection, recurring billing automation, the Client Portal, the
                                    external API, Reporting &amp; Statistics, adding teammates beyond your own
                                    account, and removing the "Powered by Invoxa" credit line from invoices and
                                    emails. Everything else in this Docs section works exactly the same whether or
                                    not you've added a key.</p>
                            </div>
                        </div>
                    </div>

                    <div class="subnav-pane" id="docs-pane-feat-api">
                        <div class="card">
                            <div class="card-body doc-content">
                                <h1>External API</h1>
                                <p>A small read/write API for scripts and other tools, entirely managed from
                                    Settings &gt; API Access — the same page shows a built-in guide with
                                    copy-pasteable <code>curl</code> examples for every endpoint below, filled in
                                    with your own instance URL, so there's nothing to look up elsewhere to get
                                    started.</p>
                                <h2>Authentication</h2>
                                <p>Every request is routed through <code>?apiv1=</code> (kept deliberately separate
                                    from the app's own internal <code>?api=</code> parameter used by its dashboard
                                    charts and tables) and authenticated with a bearer token in the
                                    <code>Authorization</code> header. A request with a missing, revoked, expired, or
                                    malformed token gets a JSON error body — <code>{"error": "..."}</code> — and an
                                    appropriate HTTP status, never a silent empty result.</p>
                                <h2>Endpoints (v1)</h2>
                                <ul>
                                    <li><code>invoices.list</code> — list invoices, filterable by status and by
                                        client_key, paginated.</li>
                                    <li><code>invoices.get</code> — fetch a single invoice by its invoice number.</li>
                                    <li><code>clients.list</code> — list clients, paginated.</li>
                                    <li><code>payments.record</code> — record a payment against an invoice by number,
                                        with an optional idempotency reference so a retried request from a script
                                        can't double-credit the same payment.</li>
                                </ul>
                                <h2>Token lifecycle</h2>
                                <p><strong>Requires a license</strong> to create or renew a token — once you have a
                                    working one, every endpoint above (including <code>payments.record</code>) is
                                    available through it; revoking or deleting a token stays free.</p>
                                <p>Create a token with a label (so you remember what it's for) and an optional
                                    expiry; the full token value is shown exactly once, at creation — there's no way
                                    to view it again afterward, only to issue a new one. <strong>Revoke</strong>
                                    cuts a token off immediately (any request using it starts failing right away)
                                    but leaves it listed with a revoked status, as an audit trail of what existed and
                                    when it stopped working — the same pattern GitHub and Stripe use for their own
                                    tokens. <strong>Renew</strong> extends an active token's expiry without changing
                                    its value, so scripts already using it keep working. <strong>Delete</strong> is
                                    a separate, explicit action from Revoke — it permanently removes an
                                    already-revoked or already-expired token from the list, for actually clearing
                                    old entries out rather than just deactivating them.</p>
                            </div>
                        </div>
                    </div>

                    <div class="subnav-pane" id="docs-pane-feat-reporting">
                        <div class="card">
                            <div class="card-body doc-content">
                                <h1>Reporting</h1>
                                <h2>Dashboard</h2>
                                <p>The Dashboard is the at-a-glance landing view: monthly recurring revenue,
                                    outstanding balance, overdue balance, and a feed of recent activity, plus charts
                                    for monthly revenue and the per-client breakdown behind it — enough to answer
                                    "how's the business doing right now" without drilling into Statistics.</p>
                                <h2>Statistics</h2>
                                <p><strong>Requires a license.</strong> The Dashboard above stays free either way.</p>
                                <p>If any client is set to a currency other than the instance default (Settings &gt; General), Statistics and the Tax &amp; Compliance exports below report on the default currency only — invoices/clients in another currency are excluded from these totals and charts rather than being added together (see Clients &amp; Client Portal, and Roadmap). The Dashboard's own headline totals, and the Invoices/Clients/Quotes tabs, don't have this limitation — they show every currency, grouped rather than blended.</p>
                                <p>Statistics is split into six focused tabs rather than one long scrolling page:
                                    <strong>Revenue</strong>, <strong>Forecasting</strong>, <strong>Clients</strong>,
                                    <strong>Tax &amp; Compliance</strong>, <strong>Activity</strong>, and
                                    <strong>System</strong>. Between them they cover reports like Accounts
                                    Receivable Aging, Quote Pipeline (how many quotes are open vs. converted vs.
                                    stale), voided-invoice totals, Client Growth &amp; Mix, a "Clients Needing
                                    Attention" list, Email Delivery Health (send success/failure rates), Most Active
                                    Clients by invoice count, and tax-year progress with a monthly breakdown — the
                                    Tax &amp; Compliance tab is also where the tax-year CSV exports live (full
                                    invoice list, and a monthly summary), using whatever tax year start month is set
                                    in Settings.</p>
                                <h2>Audit Log</h2>
                                <p>Every invoice send (and send failure), payment, refund, void/unvoid, and
                                    account-security event — 2FA enabled/disabled, API token created/revoked/deleted,
                                    a login lockout — is written here with a timestamp, making it the one place to
                                    answer "what actually happened, and when" on this instance. It also records when
                                    a Stripe or PayPal webhook arrives referencing an invoice number Invoxa doesn't
                                    recognize, rather than silently dropping it. Retention is configurable from Data
                                    Management (30, 180, or 365 days, or kept forever) — older entries are pruned
                                    automatically once a retention period is set, rather than growing the table
                                    indefinitely by default.</p>
                            </div>
                        </div>
                    </div>

                    <div class="subnav-pane" id="docs-pane-feat-data">
                        <div class="card">
                            <div class="card-body doc-content">
                                <h1>Data Management</h1>
                                <h2>Backup &amp; Restore</h2>
                                <p>The Backup panel starts with <strong>Select Tables to Export</strong> — every
                                    table is included by default, with a "Show all tables" toggle for reaching the
                                    less common ones, so a backup can be scoped down (e.g. clients and invoices only)
                                    instead of always being all-or-nothing. <strong>Create Backup</strong> writes a
                                    timestamped file you can download. To bring one back, pick a backup and run
                                    <strong>Test Restore (Dry Run)</strong> first — it shows exactly what would
                                    change (rows/tables created, dropped, inserted) without touching the database —
                                    then <strong>Restore Selected</strong> to actually apply it. A local retention
                                    setting prunes old backups down to a configured count automatically after each
                                    new one, and an optional Offsite Push panel can send new backups to a remote
                                    destination via rclone, with credentials kept out of the app itself and living on
                                    the cron container instead.</p>
                                <h2>Demo Data</h2>
                                <p>Seeds a handful of sample clients, invoices, and quotes spread across recent
                                    months, every one of them flagged with the client-level <strong>Is Test
                                        Client</strong> marker — a safe way to see charts, Statistics tabs, and the
                                    Dashboard filled in before committing any real data. Clear Dummy Data removes
                                    everything it seeded, and only what it seeded.</p>
                                <p>To preview it in isolation rather than mixed in with your own clients and
                                    invoices, turn on <strong>Show Only Test/Dummy Data</strong> under Settings &gt;
                                    General &gt; Preferences before (or after) seeding — every list, chart, and
                                    total across the app flips to showing only <code>is_test = 1</code> records
                                    while it's on, and it's empty if no dummy data has been seeded yet. Turn it back
                                    off to return straight to your normal view. It overrides the separate
                                    <strong>Hide Test Clients Globally</strong> toggle while active, so you don't
                                    need to touch that one to preview.</p>
                                <h2>Test Suite</h2>
                                <p>An itemized, in-app correctness check for the app itself: invoice math, TOTP,
                                    Stripe/PayPal amount conversion and webhook signature verification, and real
                                    database behavior like the payment ledger, the Recurring Billing double-billing
                                    guard, and email content/template substitution. Tests are grouped into named
                                    sections (Core Logic, Clients &amp; Invoices, Payments &amp; Refunds, Billing
                                    Cron, Email Content, Security), each with its own checkbox to select
                                    the whole section at once, and pill buttons above the table — an "All" pill,
                                    bold by default, or any single section — to isolate the table to just that
                                    slice and pre-select its rows. Run Selected only executes checked rows; an
                                    unchecked row keeps showing its last result rather than reverting to "Not run."
                                    Every check that touches the database creates its own disposable
                                    client/invoice and deletes it again immediately afterward, pass or fail — never a
                                    real client, never Demo Data's fixtures — and none of it ever sends a real email
                                    or calls the real Stripe/PayPal APIs.</p>
                                <h2>Data Repair</h2>
                                <p>A narrow, specific fix rather than a general-purpose repair tool: <strong>Reset
                                        paid_at to End-of-Month</strong> corrects historical <code>paid_at</code>
                                    dates that were bulk-set incorrectly (e.g. from an old import) by resetting every
                                    paid invoice's <code>paid_at</code> to the last day of its own invoice month.
                                    That's what the Payment Velocity figure under Statistics &gt; Revenue is computed
                                    from, so a batch of invoices with a wrong or missing paid date will visibly skew
                                    that number until this is run.</p>
                                <h2>Danger zone</h2>
                                <p><strong>Factory Reset</strong> wipes the instance back to a clean install —
                                    every client, invoice, quote, note, and setting, every generated invoice file,
                                    every stored backup, and every user account (not just yours), landing back on the
                                    signup screen exactly like a fresh install. It requires typing <code>RESET</code>
                                    exactly into a confirmation field (the button stays disabled until that matches)
                                    plus re-entering your current admin password — two independent confirmations
                                    specifically because there's no undo once it runs; take a backup first if
                                    there's any chance you'll want this data again.</p>
                            </div>
                        </div>
                    </div>

                    <div class="subnav-pane" id="docs-pane-feat-notifications">
                        <div class="card">
                            <div class="card-body doc-content">
                                <h1>Notifications</h1>
                                <p>Settings &gt; Notifications sends short alerts to Telegram, Slack, or a generic
                                    webhook — pick one channel; it isn't more than one at once. This path is
                                    deliberately independent of email delivery, so it keeps working even if SMTP is
                                    misconfigured or down, and is useful precisely because it's a second, separate
                                    way to notice something went wrong.</p>
                                <h2>Telegram</h2>
                                <p>Needs a <strong>Bot Token</strong> (create a bot via BotFather in Telegram to get
                                    one) and a <strong>Chat ID</strong> — the settings page includes a pointer to
                                    finding your chat ID via your browser, since it isn't something Telegram shows
                                    you directly in the app.</p>
                                <h2>Slack</h2>
                                <p>Needs only a <strong>Webhook URL</strong> — create an Incoming Webhook for a
                                    channel in your Slack workspace and paste its URL in.</p>
                                <h2>Generic Webhook</h2>
                                <p>For anything that isn't Slack or Telegram specifically — <a href="https://ntfy.sh"
                                        target="_blank" rel="noopener">ntfy</a>, a Discord webhook, or your own
                                    receiver. Needs a <strong>Webhook URL</strong> and a <strong>Payload Format</strong>
                                    matching what that receiver expects: plain text (ntfy and most shell-script
                                    receivers), <code>{"text": "..."}</code> (Slack-compatible, e.g. Mattermost), or
                                    <code>{"content": "..."}</code> (Discord). Unlike Telegram/Slack, success here just
                                    means the URL was reachable and didn't return an HTTP error — there's no single
                                    expected response body across every possible receiver.</p>
                                <h2>Events</h2>
                                <p>Two independently toggleable checkboxes control what triggers a message: notify
                                    when a payment is received (fires for both full and partial payments, and for
                                    refunds) and notify when an invoice becomes overdue (fires from the same cron
                                    trigger as Payment Reminders, regardless of whether the reminder email itself
                                    successfully sends). A <strong>Send Test Message</strong> button confirms the
                                    configured channel actually works before you rely on it.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            </div>
        </div>
