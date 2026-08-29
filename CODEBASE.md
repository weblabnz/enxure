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
| `lib/auth.php` | 2FA (TOTP/base32), backup codes, API token generation/lookup, password-reset/verification email senders. Pure functions — no execution-order dependencies, which is why this is safe to require early even though `lib/auth_gate.php` (below) calls into it. |
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

**The rule of thumb when splitting more of this file:** if the code only defines functions, require it early (top of `invoxa.php`, alongside `clients.php`/`stats.php`/etc.) so it's available to both the AJAX dispatch and the page render. If it's top-level executable code or raw page markup that only runs once, require it in place at its original position instead — and double-check any `__DIR__`-relative paths inside it, since a file that moved from `invoxa.php`'s directory into `lib/` needs those adjusted — the 2.11.5 fix was exactly this: `lib/auth_gate.php` required `__DIR__ . '/lib/license.php'` instead of `__DIR__ . '/license.php'`.

## Still inline in `invoxa.php`

- Cron API key / path constants / email template defaults (top-of-file config).
- The Client Portal (`?portal=`, public/token-gated).
- Invoice Generation Core: `generateInvoiceNumber()`, `processInvoice()`, `notifyChannel()`, `convertQuoteToInvoice()`, `sendOverdueReminders()`, `applyLateFees()`, `pruneAuditActions()`, and the invoice/quote/expense/recurring-expense/activity row renderers.
- The remaining AJAX handlers that don't belong to Settings/Backup/clients/stats/exports/payments (expenses, ad hoc/quote generation, recurring billing, cron schedule editing).
- Data Fetching — the block that runs every query the page template needs before rendering.
- **The page template** — the HTML shell (head/styles/sidebar/nav/modals), the Dashboard/Invoices/Billing/Clients/Expenses/Quotes/Stats/Audit/Sync/Docs tab markup, and the big inline `<script>` block with all client-side JS. This is the one piece from the 2.11.3/2.11.4 roadmap item not yet split out — by far the largest remaining chunk (~6,200 lines), and structurally different from everything above since it's one continuous block rendered exactly once rather than a set of discrete handlers.

## History

- **2.11.3** — client/stats/exports/payments logic split into `lib/clients.php`, `lib/stats.php`, `lib/exports.php`, `lib/payments.php`.
- **2.11.4** — auth/2FA/API-token logic, Settings, and Backup & Restore split into the `lib/auth*.php`, `lib/settings*.php`, `lib/backup*.php` files described above.
- Next: the page template.
