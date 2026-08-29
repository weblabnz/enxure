# Invoxa — codebase layout

Developer reference for how `invoxa.php` and `lib/` are organized. Not bind-mounted or shown in the in-app Docs viewer.

## Entry point

`src/invoxa.php` is the only web-facing PHP file (see `nginx/nginx.conf`) and handles every request: connects to MySQL, then falls into one of a few branches depending on the request —

- `?doc=`, `?health` — small standalone endpoints, handled inline near the top before anything else loads.
- `?portal=` — the public Client Portal (token-gated, no admin session). Still inline in `invoxa.php`.
- `?apiv1=` — the external REST API. Dispatch lives in `lib/api_v1.php`.
- A POST with `action=...` — the AJAX action dispatch (one big `if ($_POST['action'] === '...')` chain, guarded by a license/admin-only allowlist). Each action either stays inline in `invoxa.php` or calls an `invoxaHandleXxx()` function defined in the relevant `lib/*.php` file.
- Anything else — falls through to `?api=`/`?export=` GET routes, then Data Fetching (queries every value the page template needs), then the full HTML page render.

## `lib/` — required early (function definitions only, no top-level side effects)

These are `require_once`'d in a block near the top of `invoxa.php` (before the auth gate even runs), so their functions are available everywhere — the AJAX dispatch, the page render, and each other — regardless of which branch a given request takes. None of them do anything on their own at require-time; they just define functions.

| File | Contents |
|---|---|
| `lib/markdown.php` | `invoxaRenderMarkdown()` — the in-app doc modal. |
| `lib/invoice_helpers.php` | Pure (no `$mysqli`) invoice mechanics: totals, templates, PDF/HTML generation, Stripe/PayPal API calls, notification senders. |
| `lib/auth.php` | 2FA (TOTP/base32), backup codes, API token generation/lookup, password-reset/verification/welcome email senders. Pure functions — no execution-order dependencies, which is why this is safe to require early even though `lib/auth_gate.php` (below) calls into it. |
| `lib/clients.php` | Client rendering + AJAX handlers (save/delete/portal token/CRM notes/CSV import). |
| `lib/stats.php` | Dashboard/Statistics rendering (`renderStatsSection()` etc.) + related AJAX/GET routes. |
| `lib/exports.php` | CSV/PDF/accounting-journal export routes. |
| `lib/payments.php` | `$mysqli`-touching payment logic: recording/refunding, the public Stripe/PayPal payment/return/webhook routes, payment AJAX handlers. |
| `lib/backup.php` | Data Management: Sync/Audit-log rendering, demo data seed/clear, legacy-table remap, the entire Test Suite (`invoxaTestDefinitions()`/`invoxaRunTestSuite()`), and every Backup & Restore / Data Repair AJAX handler. |
| `lib/settings.php` | Every Settings-tab AJAX handler (business identity, invoice defaults/numbering/template, email templates, payment details, notifications, API tokens, 2FA, users, license key, profile). |
| `lib/license.php` | `licenseIsValid()` — required later, from inside `lib/auth_gate.php`, once `$settings` is loaded (see below). |

Vendored third-party libraries also live under `lib/` (`phpmailer/`, `dompdf/`, `php-font-lib/`, `php-svg-lib/`, `php-css-parser/`, `html5-php/`) plus `lib/pdf_autoload.php`, their autoloader.

## `lib/` — required in place (top-level executable code / raw page markup)

These are **not** required at the top of the file. Each is `require_once`'d at the exact spot in `invoxa.php` where its code used to live inline — PHP splices an include's top-level code into the caller's scope at that exact point, so moving code like this changes nothing about execution order, variable scope, or timing. That's also why none of these can be required early: they either need `$mysqli`/session state that isn't ready yet, or they need to run at page-render time specifically.

| File | Required from | Contents |
|---|---|---|
| `lib/auth_gate.php` | `invoxa.php`, right after the DB connection | Defensive schema migrations, session/login/signup/2FA/password-reset POST handling, `$isAuth`/`$isAdmin`/`$isCron` computation, `$settings`/license load, `invoxaLogAction()`, the test-view filter helpers. |
| `lib/api_v1.php` | `invoxa.php`, after the Client Portal block | The `?apiv1=` endpoint dispatch, plus the "show the login page" gate/render for anyone who isn't authenticated. |
| `lib/settings_page.php` | `invoxa.php`, at the Settings tab's position in the page body | The entire Settings tab's HTML (`<div id="sec-settings">`). Pure markup + inline `<?php ?>` expressions, no function definitions. |
| `lib/backup_page.php` | `invoxa.php`, at the Data Management tab's position | The entire Backup & Restore / Data Management tab's HTML (`<div id="sec-backup">`). Same shape as `settings_page.php`. |
| `lib/page_head.php` | `invoxa.php`, right after the last GET-route branch | `<!DOCTYPE html>` through `</head>` — meta tags, the theme-flash-prevention inline script, and all page CSS. |
| `lib/page_nav.php` | `invoxa.php`, right after `page_head.php` | `<body>` through the sidebar's closing tag: mobile brand icon/menu button, the mobile bottom nav, and the full sidebar (nav items, global search, user panel). |
| `lib/tab_dashboard.php` / `tab_invoices.php` / `tab_billing.php` / `tab_clients.php` / `tab_expenses.php` / `tab_quotes.php` | `invoxa.php`, inside `<div class="main">`, one after another in tab order | Each tab's full `<div id="sec-X" class="section">...</div>`. |
| `lib/tabs_misc.php` | `invoxa.php`, after `tab_quotes.php` | The three trivial report tabs — Stats, Audit, Sync — each just a `<div id="sec-X">` wrapping a call to its already-extracted `renderXSection()`. |
| `lib/tab_docs.php` | `invoxa.php`, after `tabs_misc.php` | The in-app Docs tab (Quick Start/feature docs/Roadmap/Changelog viewer). |
| `lib/page_modals.php` | `invoxa.php`, after `backup_page.php` | Every modal dialog (Add/Edit Client, invoice line items, CRM panel, etc). |
| `lib/page_script.php` | `invoxa.php`, after `page_modals.php`, through to just before `</body>` | The `simple-datatables` script tag plus the entire inline `<script>` block — all client-side JS for the SPA. Almost entirely static JS; only 3 lines interpolate a PHP value (`$settings['currency']`, `$missingFiles`, `$missingDiskData`), all already loaded by Data Fetching before this point. |

**The rule of thumb when splitting more of this file:** if the code only defines functions, require it early (top of `invoxa.php`, alongside `clients.php`/`stats.php`/etc.) so it's available to both the AJAX dispatch and the page render. If it's top-level executable code or raw page markup that only runs once, require it in place at its original position instead — and double-check any `__DIR__`-relative paths inside it, since a file that moved from `invoxa.php`'s directory into `lib/` needs those adjusted — the 2.11.5 fix was exactly this: `lib/auth_gate.php` required `__DIR__ . '/lib/license.php'` instead of `__DIR__ . '/license.php'`.

## Still inline in `invoxa.php`

`invoxa.php` is down to ~2,300 lines, all backend logic — no more page markup:

- Cron API key / path constants / email template defaults (top-of-file config).
- The Client Portal (`?portal=`, public/token-gated).
- Invoice Generation Core: `generateInvoiceNumber()`, `processInvoice()`, `notifyChannel()`, `convertQuoteToInvoice()`, `sendOverdueReminders()`, `applyLateFees()`, `pruneAuditActions()`, and the invoice/quote/expense/recurring-expense/activity row renderers.
- The remaining AJAX handlers that don't belong to Settings/Backup/clients/stats/exports/payments (expenses, ad hoc/quote generation, recurring billing, cron schedule editing).
- Data Fetching — the block that runs every query the page template needs before rendering.
- The `<?php require_once ... ?>` calls that assemble the page template from the `lib/page_*.php`, `lib/tab_*.php`, `lib/tabs_misc.php`, `lib/settings_page.php`, and `lib/backup_page.php` files above.

## Security review

**2026-08-29 (2.11.8).** Two parts:

1. **Regression check on the 2.11.4–2.11.7 code-organization moves.** Diffed the pre-move code against the current code for every security-relevant piece: the `$__licensePaidActions`/`$__adminOnlyActions` gating allowlists in `invoxa.php` (byte-identical), the full set of 96 distinct `$_POST['action']` handlers (same set before/after, none dropped/duplicated/renamed), and every hand-transcribed handler function in `lib/settings.php` (27) and `lib/backup.php` (17) line-by-line against the original inline code (identical except deliberate comment removal and two required scope fixes — removing a redundant `global $settings;`, adding a required `global $__actorUserId, $__actorUsername;` — both verified correct).
2. **Fresh-eyes review of the current code**, covering `auth_gate.php`, `auth.php`, `api_v1.php`, `settings.php`, `backup.php`, the Client Portal, the payment webhook handlers, and `license.php`. Checked for SQL injection, path traversal, auth/session sequencing and privilege escalation, XSS (including the user-editable custom invoice template engine), token generation strength, and webhook signature verification.

**Result:** no high-confidence exploitable findings. Two defense-in-depth gaps were found and added to the Roadmap rather than treated as urgent: no CSRF tokens (mitigated today by browsers' default same-site cookie behavior), and no `session_regenerate_id()` call after login (a session-fixation-shaped gap with no known concrete exploit path). `license.php`'s license-key check was also reviewed specifically for bypass potential: it's cryptographically sound (ed25519 signature via libsodium, email+domain binding, fails closed), and its own docblock already states the correct threat model for AGPL-licensed software — it's a deterrent against casual copying, not DRM; a buyer who controls their own server can lawfully patch it out under their AGPL rights, so that isn't a finding to fix.

This should be repeated periodically — at minimum before any release touching auth, sessions, tokens, payments, or licensing, and otherwise on a regular cadence rather than only reactively.

## History

- **2.11.3** — client/stats/exports/payments logic split into `lib/clients.php`, `lib/stats.php`, `lib/exports.php`, `lib/payments.php`.
- **2.11.4** — auth/2FA/API-token logic, Settings, and Backup & Restore split into the `lib/auth*.php`, `lib/settings*.php`, `lib/backup*.php` files described above.
- **2.11.6** — the page template split into the `lib/page_*.php`/`lib/tab_*.php` files described above. This was the last item on the original code-organization roadmap.
- **2.11.8** — security review (see above); new users now get a welcome email with a link to set their own password, instead of only ever knowing the one their admin typed in for them.
