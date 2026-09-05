# Changelog

All notable changes to enXure are documented here. Dates are when a release was cut, not individual commit dates.

## [3.0.7] - 2026-09-05

### Changed
- Updated the Polar license purchase link (README.md, public/index.html) to the correct checkout URL, and moved `LICENSE_PURCHASE_URL` in `enxure.php` into a single shared file (`src/lib/license_purchase_url.php`) so the in-app Settings > License button reads from one canonical source instead of a hardcoded string.


## [3.0.6] - 2026-09-05

### Changed
- Docs/Settings sub-nav items (`.subnav-item`, e.g. Quick Start under Getting Started) now stretch to fill the full 220px nav column width instead of shrinking to their text content, so hover/active backgrounds span the whole row rather than just the label.


## [3.0.5] - 2026-09-05

### Changed
- Updated `LICENSE_PURCHASE_URL` to the new Polar checkout link.


## [3.0.4] - 2026-09-05

### Changed
- Settings > License: moved the Polar purchase link out of the card body and into the card header's top-right corner, as a plain grey button rather than the blue primary one — matches the header-button pattern already used elsewhere in Settings (e.g. Email's "Open Mailpit").


## [3.0.3] - 2026-09-05

### Changed
- Settings > License now always shows the Polar purchase link, not just while unlicensed — labeled "Buy a License" before a key is activated and "Polar Purchase Page" afterward, so a licensed user can still get back to Polar (e.g. to check an order or buy again) instead of the link disappearing once `$licenseValid` is true.


## [3.0.2] - 2026-09-05

### Changed
- Docs > Features category renamed to Docs > User Guide, since that's what it actually is: the how-to reference for each feature area, not a features list.

### Added
- Docs > Getting Started > Features: a new card-based overview of enXure's ~20 main feature areas (Invoicing & Quotes, Recurring Billing, Payments, Client Portal, Security, External API, Reporting, Data Management, Notifications, Keyboard Shortcuts, and more), each card linking straight to its page under Docs > User Guide, laid out in a responsive grid.

### Fixed
- README screenshot pairs shown in Docs > Quick Start rendered unevenly, with the left-column image consistently larger than the right one — the table's automatic column-width algorithm was sizing each column from the images' native pixel dimensions before the existing image size cap applied. `.doc-content table` now uses `table-layout: fixed` so paired screenshots always share an equal-width column, and the image cap itself was raised to `max-width: 100%` of that column.


## [3.0.1] - 2026-09-04

### Changed
- Database table names renamed from `invoxa_*` to `enxure_*` (e.g. `invoxa_users` -> `enxure_users`) for full consistency with the 3.0.0 rename — reversing that release's deliberate choice to leave them as-is, now that the query updates across every `src/lib/*.php` file and `sql/01-schema.sql` have been made and verified end to end (health check, row counts, live queries) on both the production and test databases.


All notable changes to enXure are documented here. Dates are when a release was cut, not individual commit dates.

## [3.0.0] - 2026-09-04

### Changed
- Renamed the product from Invoxa to enXure, following a trademark conflict with an unrelated, established invoicing company already operating under the Invoxa name. Every user-facing surface is updated: page titles, the PWA manifest, EULA, README/INSTALL/CODEBASE docs, the wordmark, and the "Powered by enXure" credit line on invoices and emails. The icon/mark graphic itself is unchanged. Internal implementation details with no user-facing exposure — database table names (`invoxa_users`, `invoxa_invoices`, etc.) — are intentionally left as-is, since renaming them carried real data-migration risk for no visible benefit.
- Database renamed from `invoxa` to `enxure` via `RENAME TABLE` (a same-server metadata operation — no data copied or at risk); the underlying MySQL user was renamed to match.
- Docker container, network, and volume names, and the bind-mounted `invoxa-invoices`/`invoxa-backups` directories, all renamed to their `enxure-*` equivalents. Historical invoice/quote HTML files keep their original "Powered by Invoxa" branding as an accurate record of what was actually issued at the time; only their shared logo-image path reference was updated, since that pointed at a resource that moved.
- The seller-only licensing tooling (`docker-enxure-licensing`) and its signing key files were renamed to match; the key material itself is byte-for-byte unchanged, so previously-issued license keys remain valid.


All notable changes to Invoxa are documented here. Dates are when a release was cut, not individual commit dates.

## [2.12.2] - 2026-09-04

### Added
- INSTALL.md gained a "Testing email safely (optional)" section under Email/SMTP setup — how to add a Mailpit mail-sink service to your own docker-compose.yml and point SMTP_* at it, so invoice/reminder emails (and Data Management > Test Suite's Email Delivery checks) can be verified without a real send.

### Fixed
- The Test Suite's Mailpit tooltips pointed end users at DEPLOY.md, a gitignored file that exists only in the maintainer's own checkout and is never shipped to a real install. They now point at INSTALL.md's new Testing email safely section instead.

## [2.12.1] - 2026-09-04

### Fixed
- A fenced code block indented under a list item (e.g. INSTALL.md's Gmail SMTP setup step) rendered as garbled literal backticks instead of a code block — the renderer's fence detection required the ``` marker at the very start of the line, with zero tolerance for the indentation a nested list item naturally has. Now tolerates up to 3 leading spaces on the fence (matching CommonMark), and strips that same indentation back off the code content so it doesn't render with the list item's indent baked in.

## [2.12.0] - 2026-09-04

### Added
- Keyboard shortcuts: `N` jumps to Ad Hoc Invoice (reset to New Invoice mode), `E` opens Add Expense, `1`–`9`/`0` jump to a sidebar tab, and `?` opens a shortcuts overlay listing them all — all ignored while typing in a field or with a modal open. Documented under Docs > Features > Keyboard Shortcuts.
- Live exchange-rate conversion for Statistics, Forecasting, and AR Aging: these now blend every currency into one converted total using a daily-cached exchange rate, instead of excluding other-currency invoices the way they always had to before. Defaults to Frankfurter (free, no API key); a custom provider URL and API key can be configured instead. A currency with no rate available falls back to being excluded, exactly like before this feature existed, so a provider outage never blends in a wrong number.
- Settings > Finance: a new settings tab (Currency Code, Tax Year Starts, and the exchange-rate provider config moved out of Branding, where they never really belonged) plus a "Check a Rate" tool to look up a live rate between any two currencies on demand.
- 3-letter currency code fields (Settings > Finance, Add/Edit Client) now offer USD/EUR/GBP/AUD/NZD/CAD as datalist suggestions while still accepting any code typed in.
- Data Management > Test Suite gained two Core Logic checks for the new FX math (`invoxaSumByCcyConverted`, `invoxaFxRequestUrl`) — pure-function, no network call, so they run everywhere including production.

### Fixed
- README.md's `<details>`/`<summary>` collapsible section rendered as literal `&lt;details&gt;` text in the in-app doc viewer instead of an actual disclosure widget — the renderer only allow-listed `<br>`, nothing else.
- Links to INSTALL.md from within README.md (and vice versa) didn't go anywhere inside the app — the doc viewer renders their content dynamically rather than serving the raw files, so a plain `href="INSTALL.md#anchor"` 404s. They now route through the app's own doc navigation and jump to the right heading.
- A same-page anchor link (e.g. `[Licensing](#licensing)`) opened a blank new tab instead of jumping to the heading — `target="_blank"` was applied to every link, not just external ones.
- Rendered headings in README.md/INSTALL.md now carry a GitHub-style `id` slug, so `#anchor` links actually have something to land on; this also caught (and fixed) one pre-existing anchor in README.md that didn't match its heading's real slug.
- README.md's "Migrating or locked out?" line linked to INSTALL.md twice in one sentence for two different sections; reworded to name the file once.

## [2.11.48] - 2026-09-04

### Added
- Overdue invoices now have a manual "Send Reminder" button (invoice row actions, bell icon) alongside Resend Invoice Email — sends the same reminder template/content as the automatic overdue sweep, on demand, for a single invoice. Rejects invoices that aren't actually overdue and logs `reminder_sent`/`reminder_failed` to the Audit Log the same way the automatic sweep does.
- Data Management > Test Suite gained an "Email Delivery" group — unlike the existing Email Content group (pure template/HTML-generation checks), these actually send through real SMTP and verify the result via Mailpit's API. Covers every real send path: the overdue reminder, resend, a fully-rendered new invoice, password reset, welcome, email verification, and Settings > Email's SMTP test message. Each only runs when `SMTP_HOST=mailpit` (the test instance's default) — everywhere else, including production, they report a new "Skipped" status instead of pass/fail, so the suite stays exactly as side-effect-free elsewhere as it's always been. Messages a test finds are left in Mailpit rather than deleted, since it doubles as a real inbox for eyeballing what an email actually looks like.

### Changed
- `resend_invoice_email`'s and `test_email`'s dispatch handlers extracted into standalone `resendInvoiceEmail()`/`invoxaSendTestEmail()` functions (matching `sendReminderEmailForInvoice()`'s existing shape) so the new Email Delivery tests can call the real send logic directly instead of duplicating it.
- Test Suite's header tooltip and the Email Delivery group's row both now note that those checks require Mailpit and point to DEPLOY.md's Test instance section for how to set it up.

## [2.11.47] - 2026-09-04

### Fixed
- Same `SMTPAuth`-hardcoded-`true` issue as 2.11.46, for the remaining send paths: password reset, welcome, and verification emails, and Settings > Email's "Send Test Email" button. All were forcing `AUTH LOGIN` even with no `SMTP_USER` configured, breaking no-auth relays like Mailpit.

### Added
- Settings > Email shows an "Open Mailpit" button next to the "Mail Sink" badge when `SMTP_HOST` is `mailpit`, linking straight to its web inbox instead of requiring the port to be looked up separately.

## [2.11.46] - 2026-09-04

### Fixed
- Amounts of $1,000 or more silently lost their thousands-separator comma wherever a comma-formatted string was cast straight to a number with `(float)`/`floatval()` — CSV import (client rate, invoice amount/paid amount, expense amount), `generateInvoiceHTML()`'s Subtotal re-derivation for custom templates and discount/tax invoices, receipt OCR total extraction, and recurring-billing line items. Added a shared `invoxaParseAmount()` helper (strips thousands-separator commas before casting) and routed every one of those call sites through it.
- `SMTPAuth` was hardcoded `true` on invoice/quote send, overdue reminders, and recurring billing regardless of whether `SMTP_USER` was actually set, so PHPMailer always attempted `AUTH LOGIN` even against a relay configured with no username. Now only enabled when `SMTP_USER` is non-empty.

### Added
- Extensive new Test Suite coverage for amounts at and past the $1,000 comma threshold, across Stripe conversion, `computeInvoiceTotals()`, CSV import (clients/invoices/expenses), Ad Hoc invoices, Quotes, the payment/refund ledger, and the accounting journal.

### Changed
- CSV import handlers (`invoxaHandleImportClientsCsv`, `invoxaHandleImportInvoicesCsv`, `invoxaHandleImportExpensesCsv`) split into separate `invoxaImport*CsvRows()` functions so the comma-parsing fix above could be covered directly against an in-memory CSV in the test suite, rather than only through a real file upload.
- Test Suite page (Settings > Backup & Restore) reorganized: filter/selection controls moved into a bordered toolbar, added a live "N of M selected" count and a progress bar while tests run, pass/fail badges instead of colored icons, and a running test count next to the section title.
- Audit Log timeline rows switched from fixed-width flex columns (with wrapping) to ellipsis truncation, fixing awkward wrapping on narrower screens.

## [2.11.45] - 2026-09-04

### Added
- Editing or creating a client now logs a `client_updated`/`client_created` audit entry listing exactly which fields changed, e.g. "Monthly rate: 500.00 → 550.00; Currency: USD → NZD" — previously the Audit Log only recorded that a client was touched, not what changed. Toggling a client's Active or Test status from the client list logs the same way.

## [2.11.44] - 2026-09-02

### Fixed
- "Create Backup" on the Backup & Restore page never refreshed the Restore Database dropdown after a successful backup — unlike Import Backup File, which already called `loadBackupList()` on success, `backupDatabase()` didn't, so a freshly created backup only showed up in the dropdown after a manual refresh click or a full page reload.

### Changed
- Statistics tab and Dashboard stat-card colors (top-border accents, tidbit icon badges, and assorted inline value colors) now follow one consistent rule instead of mixed hardcoded hex and ad hoc choices: green (`var(--success)`) for money actually received, amber (`var(--warning)`) for money still owed, red (`var(--danger)`) for costs/failures, blue (`var(--accent)`) for neutral volume metrics. This surfaced and fixed several real mismatches — "Outstanding Receivables" was hardcoded red while the equivalent Tax Year "Outstanding" card was amber/green; Monthly Recurring Revenue was colored amber (a caution color) despite being a positive metric; the Forecasting card showed MRR × 12 in amber directly above the same MRR figure in green; the Dashboard's "This Month" tidbit (invoiced, not yet paid) was colored green like actually-received revenue; the Dashboard's "Total Outstanding" never flipped to green at $0 the way the Statistics tab's equivalent cards now do; and "Active Clients" was green in the Statistics tab but uncolored on the Dashboard. Also converted the remaining hardcoded hex colors across `stats.php` to their matching theme variables so they track light/dark mode instead of staying pinned to the dark theme's shades.

## [2.11.43] - 2026-09-01

### Fixed
- 2.11.42's fix for the page-size dropdown's "All" option still relabeled it after the fact (just with better timing), which didn't eliminate the visible resize on refresh — the option briefly rendered as "99999" before being renamed. `simple-datatables` actually supports a `[label, value]` pair per `perPageSelect` entry, so `getTblOpts()` now passes `['All', 99999]` directly and the dropdown never renders the wrong text in the first place, on both initial load and background refresh. Removed the now-unnecessary relabeling `setTimeout` calls entirely.

## [2.11.42] - 2026-09-01

### Fixed
- Dashboard's Revenue Over Time chart range buttons ("Last 12 Months" / "All Time") always re-highlighted "Last 12 Months" after navigating away from Dashboard and back, even if "All Time" had been selected — `refreshDashboard()` swaps in a fresh server render of the chart card, which always defaults to the "Last 12 Months" button styled active, and nothing re-synced the highlight to the JS-side `chartRange` state (which itself was tracked correctly) until the next manual click. Button state is now re-applied from `chartRange` on every chart (re)render via a shared `syncChartRangeButtons()`, called immediately when the fresh HTML lands rather than only after the chart data re-fetch completes, removing the visible flash back to the wrong button.
- Invoices/Clients/Quotes/Expenses tabs' page-size dropdown ("All" option) visibly redrew and widened right after a background refresh (see 2.11.41) — `refreshTable()` relabeled the `99999` option to "All" synchronously right after recreating the DataTable, before the library had finished laying out the selector, so the wider "99999" text rendered first. Matched the same 100ms-delayed relabel already used at initial page load.

## [2.11.41] - 2026-09-01

### Fixed
- Invoices/Clients/Quotes/Expenses tabs' background refresh (clicking the nav item while data changed elsewhere) never actually worked: `simple-datatables` rebuilds each tab's `<tbody>` for its own pagination on page load, so the id `refreshTable()` looked up (e.g. `invoicesTbody`) no longer existed by the time it ran, throwing `TypeError: Cannot read properties of null (reading 'closest')` before the fetch ever fired. `refreshTable()` and `waitForTableRefresh()` now look the tbody up live via the table element instead of a stale id. Also added the missing `destroyable: true` DataTable option, without which `.destroy()` was a no-op and would have stacked a duplicate DataTable wrapper on every refresh once the crash above was fixed.

## [2.11.40] - 2026-09-01

### Changed
- Sidebar's bottom panel (Logout button, version/changelog line) now has more padding and a subtle `--surface-2` background tint to set it apart from the nav list above, and on mobile the gap between the global search box and that panel is wider too.

### Removed
- "Bulk CSV import for Invoices and Expenses" and "Dashboard customization" dropped from the in-app Roadmap doc — both shipped already (CSV import in 2.11.36; drag-reorder/resize/hide for Dashboard cards previously).

## [2.11.39] - 2026-09-01

### Changed
- Statistics/Dashboard's default card layout (order and widths, for a fresh install or any user with no saved customization) now matches the arrangement actually in use, instead of the original ad-hoc default: Revenue, Clients, Expenses, Tax, Activity, and System panes reordered/resized, and the Dashboard's Revenue Over Time / Client Share charts changed from an even 3/3 width split to 4/2.

### Fixed
- Revenue and System panes' default (unsaved) layout put cards in the wrong columns — `layoutStatsPane()` only respects a card's column when it carries an explicit `data-card-col` attribute, falling back to plain left-right alternating by position otherwise, so those two panes' grouped-by-column arrangement (all of one group left, all of another right, rather than alternating) could never be reproduced by DOM order alone. Added the missing `data-card-col` attributes to both panes' cards to match.

## [2.11.38] - 2026-09-01

### Added
- Untracked HTML Invoices now has a "Delete All" bulk action alongside "Import All", removing every currently-listed untracked file from disk in one go (new `delete_all_untracked_files` admin action). The per-row trash icon on that same table now actually works too — it called a `deleteUntrackedFile()` JS function that was never written, so clicking it silently threw a `ReferenceError` and did nothing.

### Changed
- "Import All Missing" and "Delete All DB Entries" relabeled to "Import All" and "Delete All" — the panel headers ("Untracked HTML Invoices", "Missing HTML Files") already say what's being acted on, so the buttons didn't need to repeat it.

### Fixed
- Untracked HTML Invoices import: when a file's folder didn't match any existing client, the code fabricated a stand-in client and attempted the insert anyway using the full folder name as `client_key` — a column capped at `VARCHAR(10)`, so the insert threw a strict-mode SQL error on any realistic folder name. That error was caught and counted but never sent to the frontend, so the button reported success while quietly importing nothing. Now skips those files and reports "no client found for folder 'X' — create a matching client first" instead.
- `showToast()`'s auto-hide reset `className` to the base `toast` class in one step, dropping the `error` class (and its red background) instantly while the 0.25–0.35s fade-out transition was still playing — every error toast in the app flashed green right at the end of its fade. Now only the `show` class is removed on auto-hide, so the color stays consistent through the whole animation.
- "Rebuild HTML Files" (Missing HTML Files panel) tracked disk-write failures server-side (`errors`) but never included that count in its summary toast, so a failed rebuild could look identical to a full success. Now surfaced alongside the existing no-content-to-rebuild-from message.
- The invoice preview modal's iframe was hardcoded to `height:70vh` while its outer `.modal` only capped at `max-height:75vh` — header height on top of that pushed the real total past 75vh, and with `.modal-body` set to `overflow:hidden` (not the class's default `auto`), the excess was clipped with no way to scroll to it (the iframe's own scrollbar only moved its internal document, not the outer clip line). `.modal-body` now flexes to fill whatever space remains under the header, and the iframe fills `100%` of that instead of an independent, non-additive viewport value.

## [2.11.37] - 2026-08-31

### Fixed
- The Dashboard's "Revenue Over Time" card hid its "Last 12 Months"/"All Time" range buttons right up against the top-right corner, which on hover is also where that card's drag-handle, width-cycle, and hide-widget icons render — the two controls crowded each other. Gave the range buttons a fixed right margin so they clear that icon row.
- Audit Log entries now have a subtle left border between each field (type, notes, performed-by, client) instead of relying on the gap alone, so a dense row reads as distinct columns rather than one run-on line.

## [2.11.36] - 2026-08-31

### Added
- Invoices and Expenses now support the same bulk CSV import Clients already had, so migrating off a spreadsheet is a single upload for all three record types instead of just Clients. Invoice import matches each row's Client Name against an existing client (case-insensitive), auto-generates the invoice number via the existing `generateInvoiceNumber()` when the CSV leaves it blank, and — when a Paid Amount is given — writes a matching `invoxa_payments` ledger row (not just the cached `invoxa_invoices.paid_amount`) so Data Repair's "Reconcile Payment Totals" doesn't zero it back out later. Expense import matches Category by either its internal key or its human-readable label (e.g. "Software & Subscriptions"). Both new imports are admin-gated the same way `import_clients_csv` already is, and a bad row is skipped with an error rather than aborting the whole file.

### Changed
- The Clients/Invoices/Expenses toolbars' Export/Import controls were relabeled to stop repeating the same words between a group's header and its buttons (a header reading "Export / Import" next to a "CSV" button and an "Import" button was redundant) — headers now name the shared subject ("CSV") and buttons name the action ("Export"/"Import"). Invoices' Import button was also pulled out of the multi-format Export dropdown's toolbar group into its own "CSV" group, since it only ever accepts CSV regardless of what the export-format dropdown has selected.
- Every table's search box no longer shows a redundant "Search" label next to its "Search..." placeholder (`labels.searchLabel` in the shared `getTblOpts()`).

### Fixed
- Dashboard's Customize button sat slightly low relative to the "Next Auto-Run" text beside it — nudged up 2px.
- The "entries per page" text next to each table's page-size dropdown rendered in full primary text color instead of the secondary grey used for the rest of the table chrome (search placeholder, pagination info) — it lived in an unstyled `<label>` that `.datatable-info`'s color rule didn't reach.

## [2.11.35] - 2026-08-31

### Added
- Dashboard now has the same kind of card customization Statistics has, via its own independent implementation (not shared code, so Statistics' own drag/resize behavior is untouched): the four "flash card" KPI tiles at the top drag-reorder within a fixed 4-wide row, and a new Customize button (next to Next Auto-Run) lists three more you can swap in — Total Paid, Overdue Invoices, Failed Emails — capped at four visible at a time. The Revenue Over Time and Client Share charts below drag-reorder and resize between 1/3, 1/2, 2/3, and full width via a cycling width button. All of it saves per-user to `invoxa_stats_layout` under new `dashboard-tidbits`/`dashboard-charts` panes, reusing the existing table and save/load functions since they were already pane-agnostic.

### Fixed
- The Dashboard customization above shipped with a temporal-dead-zone bug: the chart width-cycle button's label lookup tables were declared with `const` after the point in `page_script.php` where the Dashboard's layout-apply function is first called at page load, so once a user had a saved chart layout, that call threw a `ReferenceError` and silently aborted the rest of the script — breaking chart-card sizing, drag-and-drop, and the Dashboard's "Next Auto-Run" cron message all at once, on any page load with a saved Dashboard layout. Moved the declarations to the top of the script, before any code can reach them.

## [2.11.34] - 2026-08-31

### Changed
- Settings > Branding's five cards (Business Identity, Invoice Template, Invoice Defaults, Default Payment Details, Invoice Numbering) get the same per-field dividers Preferences just got — each field now sits in its own bordered row instead of the fields running into each other with only a small margin between them.

## [2.11.33] - 2026-08-31

### Added
- Settings > Preferences' theme toggle is now a three-way Light/Dark/Match System choice instead of a Light-Mode checkbox — "Match System" follows the OS/browser's `prefers-color-scheme` and switches live if it changes while the app is open. New installs (and anyone who's never touched the toggle) now default to Match System instead of a hardcoded Light.
- Settings > Preferences gains a Density option (Comfortable/Compact) that tightens padding on cards, tables, and buttons app-wide for fitting more on screen, a Corner Style option (Rounded/Sharp) that swaps the app's border-radius tokens, and a Custom CSS textarea for raw overrides beyond the built-in presets — all three, like Accent Color, are stored per-browser and independent of each other and of Branding.

### Changed
- Settings > Preferences now has a visible divider between each preference, so toggles and their description text read as distinct rows instead of running into each other.

### Fixed
- The new Accent Color swatches, Custom Brand Color live preview, and (this release) the new Theme/Density/Corner Style controls were silently failing to show their active/selected state on page load — their init calls lived in inline `<script>` tags inside `settings_page.php`, which renders and executes before `page_script.php` (where the functions they call are defined) loads, so every call threw `ReferenceError` and was dropped. Moved all of this init into `page_script.php` itself, after the relevant functions are defined.

## [2.11.32] - 2026-08-31

### Added
- Settings > Branding's "Primary Brand Color" field now offers eight curated presets (Invoxa Blue, Slate, Emerald, Violet, Amber, Rose, Teal, Charcoal) as one-click swatches alongside the existing custom color picker, plus a live preview showing a mock invoice header and Client Portal "Pay Now" button that update instantly as you pick — no more hand-tuning a hex value blind. The Client Portal itself now actually uses this color (pay/accept buttons, "Back to your invoices" links) instead of a hardcoded blue, so branding finally carries all the way through to what clients see there, not just invoices/quotes/PDFs. Roadmap item removed.
- Settings > Preferences gets an "App Accent Color" picker — the same eight presets, but for the Invoxa interface itself (buttons, links, highlights) rather than client-facing documents. Stored in the browser (`localStorage`), independent of the Light/Dark toggle and of Branding's brand color, with a "Reset to Theme Default" button to go back to the built-in blue.

### Changed
- Settings pages now have consistent vertical spacing between cards across every subarea, matching Billing's spacing — the subnav pane's own flex gap was stacking on top of each card's margin, and Account's second card additionally had a stray extra margin-top, both now removed.

## [2.11.31] - 2026-08-31

### Fixed
- "Create Backup" wrote every backup on a given day to the same `backup_YYYY-MM-DD.sql` filename, so a second manual backup taken the same day silently overwrote the first instead of adding a new file — `local_backup_retention_count` looked broken (backups stuck at whatever count you already had) when it was actually the missing file just never existing in the first place. New backups now get a numeric suffix (`backup_YYYY-MM-DD_1.sql`, `_2.sql`, ...) when that day already has one, the same shape `import_backup` already accepted.

### Added
- Automatic local backups (Data Management > Backup & Restore) — **off by default**. When enabled, runs the same backup as the manual "Create Backup" button once a day on its own fixed cron schedule (02:30 server time, independent of Recurring Billing's schedule), no license required. Manual and automatic backups share the same `invoxa-backups/` folder and both now obey `local_backup_retention_count` through the same shared prune step.

## [2.11.30] - 2026-08-31

### Added
- Statistics tab cards can now be drag-reordered and resized between half and full width, independently within each subnav pane (Revenue, Forecasting, Clients, Expenses, Tax & Compliance, Activity, System). Layout is saved per user (new `invoxa_stats_layout` table, `save_stats_layout` action) and restored on every reload or restart. Each pane lays cards out as two independent columns rather than a single shared-row grid, so a short card is never stretched to match a taller neighbor, and a card can be dragged into a column that's currently empty.

## [2.11.29] - 2026-08-31

### Added
- Data Repair gets three more tools alongside the existing "Reset paid_at to End-of-Month": **Backfill Missing Client Names** fills a blank invoice client-name snapshot from the current client record; **Dedupe Payment Ledger** removes exact-duplicate payment rows (same invoice, provider, amount, note, and date), keeping the earliest and recalculating affected invoices' cached paid total; **Reconcile Payment Totals** recalculates every invoice's cached paid amount from the actual sum of its ledger rows and flips status to paid for anything fully paid that isn't already marked as such, without ever reversing an already-paid invoice or touching a voided one.

### Changed
- Settings > Notifications' event group headings (Payments & Billing, Invoices & Quotes, Recurring & Automation, Security) now sit in a bordered, background-shaded bar instead of plain uppercase text, so the four groups read as distinct sections instead of one crowded block.

## [2.11.28] - 2026-08-31

### Fixed
- Test Suite fixture runs (Payments & Refunds group especially) were firing real Telegram/Slack/webhook notifications for disposable ZTEST- invoices — `recordInvoicePayment()` and friends run the same `notifyChannel()` call production code does. `invoxaRunTestSuite()` now forces `notification_channel` to 'none' both on the `$settings` passed into test closures and on `$GLOBALS['settings']`, since some call paths (`invoxaLogUnmatchedWebhook()`, the one actually responsible for the unmatched-webhook message that was leaking out) read the global directly rather than taking `$settings` as a parameter. Restored in a `finally` block so it can't leak past the run even if a test throws unexpectedly.

### Changed
- Test Suite now runs one test at a time (was one big batch request) so each row ticks pass/fail live as it finishes instead of all 60 jumping from "Running…" to a result together at the end, and shows each test's execution time in a new Time column — the point being to actually see which case is slow, not just that the suite as a whole took a while.
- Test Suite and Offsite Push descriptions trimmed down considerably; Test Suite's now also states it won't send real notifications, matching the fix above. Test Suite's Category column widened slightly so longer names (e.g. "Payments & Refunds") don't wrap to a second line.

## [2.11.27] - 2026-08-31

### Changed
- Docs submenu categories: only Features (the 10-item one) starts collapsed on load — Getting Started and Reference stay open, since they're short enough not to need hiding. Each category heading now shows an item count badge (Getting Started 2, Features 10, Reference 4) and a chevron that rotates to reflect open/closed state, and there's more breathing room between the heading and the first item beneath it.

## [2.11.26] - 2026-08-31

### Added
- Audit Log gets a paginated "Show Next 100" button (`renderAuditItems()`, a new `audit_items` AJAX endpoint) instead of a hard 200-row cap with nothing beyond it, and an Export CSV button that downloads whatever's currently loaded and passing the search/type filter, matching the existing bulk-export convention used on Invoices/Expenses/Quotes.
- Docs submenu categories (Getting Started/Features/Reference) are now collapsible `<details>` sections instead of always-expanded lists — only Getting Started is open by default, cutting the always-visible item count from 16 to 2. Selecting a page (including via the remembered last-viewed tab on reload) or matching a search term auto-expands its category.

### Changed
- Audit Log timeline gets column headings, and the Client column moved next to Performed By on the right. The old "Invoice" column is now "Type": a short subsystem tag (INV, QTE, SMTP, BILL, SYS, NOTIF, SEC, WH, API, USR, SYNC) shown for every row instead of a literal invoice number that was blank (just "Inv" with nothing after it) for the many action types — recurring billing runs, SMTP tests, API token changes, user management, etc. — that were never invoice-scoped to begin with. The Client column is blank for those same non-client actions instead of the misleading "Unknown Client" it showed before; a genuinely orphaned invoice (client record deleted) still shows "Unknown Client".

### Fixed
- `renderAuditSection()`/`renderAuditItems()` now query the audit log directly instead of reusing the page's pre-fetched, 200-row-capped `$actions` array, so the Audit Log tab (and its Refresh button) are no longer tied to that cap.

## [2.11.25] - 2026-08-31

### Added
- New `notify_on_recurring_run` notification event (Settings > Notifications > Recurring & Automation) pings the configured channel whenever a cron-triggered recurring billing run actually emails invoices — previously the only recurring-billing alert was `notify_on_recurring_errors`, which stays silent on a clean run, so there was no way to confirm the cron had fired and sent anything without checking the audit log.

### Changed
- Settings > Notifications event list regrouped from one flat block of 10 checkboxes into four collapsible sections (Payments & Billing, Invoices & Quotes, Recurring & Automation, Security) so related events are easier to scan; Select All/Select None and the save handler are unaffected since they still operate on every `.notify-event-cb` inside the form regardless of grouping.
- Login screen (`api_v1.php`'s `.auth-box`) now fills the full viewport on screens 640px and narrower instead of floating as a fixed max-width card with the background gradient visible above and below it.

## [2.11.24] - 2026-08-30

### Fixed
- Mobile submenu highlight for Statistics, Data Management, Docs, and Settings could get stuck on the first sub-item selected and never move to whichever one was tapped next. `placeSubnavs()` relocates each section's `.subnav` button list out of its `#sec-*` container into the mobile `.nav-subnav-slot` on narrow screens, but `navStats()`/`navDocs()`/`navSettings()`/`navBackup()` only ever looked for `.subnav-item` inside the original `#sec-*` container, so on mobile the active-item toggle silently matched nothing after that relocation. Only a full page reload (which re-applies the stored sub-tab before relocation runs) showed the correct item highlighted. All four functions now also match `.nav-subnav-slot[data-for="..."] .subnav-item`, the same dual-location pattern `placeSubnavs()` itself uses.

## [2.11.23] - 2026-08-30

### Fixed
- Recurring billing cron could silently stop firing after any php container restart/rebuild. `php/docker-entrypoint.sh` recursively chowned the entire shared `crontab-data` volume (including `/etc/invoxa-crontab`) to `www-data:www-data` on every boot, which also flipped the crontab file busybox crond loads back from `root:33` to `www-data:www-data` — crond refuses to load a crontab it doesn't own as root, so it would keep running whatever schedule it last loaded successfully until the cron container itself restarted and re-chowned the file. php's entrypoint no longer recursively chowns the crontab directory; only the cron container's entrypoint manages ownership there now.

## [2.11.22] - 2026-08-30

### Changed
- License enforcement for payment collection, the external API, and the paid-action gate no longer depends on a single shared flag computed once per request — each now re-verifies the signature independently at the point of use. No behavior change for a valid license.

## [2.11.21] - 2026-08-30

### Changed
- Roadmap timeline (added in 2.11.20) tightened up: less padding between entries and inside each card, and a shorter line-height on the description text, so the list reads as a compact stack instead of feeling spread out.

## [2.11.20] - 2026-08-30

### Changed
- Docs > Reference > Roadmap restyled to match the Changelog's timeline: each item is now its own card with a connecting dot line and an effort badge (Quick win/Medium lift/Larger effort, color-coded green/amber/red) instead of a plain bullet list, so difficulty is visible at a glance instead of just implied by the writing.

## [2.11.19] - 2026-08-30

### Added
- Docs > Reference > Roadmap expanded from a single item to eight: keyboard shortcuts, branding theme presets, bulk CSV import for Invoices and Expenses, dashboard customization (drag-and-drop stat cards/charts), live exchange-rate conversion for Statistics/Forecasting/AR Aging, passkey/WebAuthn login, and two-way Xero/QuickBooks Online sync, alongside the existing CSRF tokens item — a mix of quick visual wins and larger integrations, not just security work.

## [2.11.18] - 2026-08-30

### Changed
- Docs > Reference > Changelog rebuilt from a flat list of headings into a timeline: each release gets a monospace version badge, formatted date, and its own dot on a connecting line, with Added/Changed/Fixed/Removed grouped into colored, iconed sections instead of plain sub-headings. The newest release carries a "Latest" badge; everything past the last 8 releases collapses behind a "Show N older releases" toggle instead of dumping the full history at once.

## [2.11.17] - 2026-08-30

### Fixed
- The session ID wasn't rotated after a successful login, a session-fixation-shaped gap flagged in the Security Review (see CODEBASE.md) with no known exploit path but no reason to leave open either. `auth_gate.php` now calls `session_regenerate_id(true)` on all four paths that grant an authenticated session — password login, signup, 2FA verification, and password reset — so a pre-login session ID can never carry over into an authenticated one. CSRF tokens are now the only item left on the Roadmap.

## [2.11.16] - 2026-08-30

### Fixed
- Statistics, the Top 5 Clients table, and the Tax Year / Monthly Summary / Accounting Journal / QuickBooks (IIF) exports silently excluded any invoice, payment, or client in a currency other than the instance default instead of accounting for it. They're now grouped in: Stats cards and tables show every currency (e.g. "USD $500.00 + EUR $200.00"), the Monthly Summary CSV emits one row per month per currency, and the Accounting Journal/IIF exports post other-currency entries to a currency-suffixed account (e.g. "Accounts Receivable (EUR)") so debits and credits still balance within their own currency instead of blending into the default-currency ledger. Stats' charts, the 12-Month Forecasting tab, and AR Aging still total in the default currency only, since a single chart axis or forecast can't meaningfully mix currencies without an exchange rate — the page's banner now says so accurately instead of claiming the whole page was default-currency-only.

## [2.11.15] - 2026-08-29

### Added
- Settings > Data Management > Screenshots: recaptures the README's screenshots from your own browser tab and overwrites the matching file in `docs/screenshots/` in place — tick the pages you want, click Capture, and it switches through them and uploads a frame of each. Uses the browser's native Screen Capture API (one permission prompt covers every page) and PHP's GD to encode to WebP server-side, so it needed no new runtime dependency — `php/Dockerfile` now builds `gd` with `--with-webp` (`libwebp-dev`), and `docs/screenshots` is now a read-write mount on the `php` service. Admin-only, same as the rest of Data Management.

### Changed
- Sync moved from its own top-level sidebar item into Data Management as a subnav page (it's the same maintainer-housekeeping category as Data Repair, Backup & Restore, and now Screenshots) — its "files needing sync" count badge moved onto the Data Management nav item itself. Data Management's other subnav items are reordered by function: Backup & Restore, Offsite Push, Sync, Audit Log Retention, Demo Data, Test Suite, Screenshots, then Data Repair and Factory Reset unchanged at the end.

### Fixed
- Sync's Refresh button gave no feedback while it re-checked — now dims the pane and shows the same spinner used by the Invoices/Clients/Quotes/Expenses table refreshes.
- The dashboard revenue chart's gradient fill (added in 2.11.14) was computed once from the canvas's on-screen size before Chart.js had laid it out, so it sometimes didn't cover the full chart until a page reload. Switched to Chart.js's scriptable-color pattern, recomputed from the chart's actual layout on every render and resize.

## [2.11.14] - 2026-08-29

### Fixed
- Settings > Users nav dot was hardcoded green regardless of how many users existed — now green only when more than one user does, matching the on/off pattern every other subnav dot already follows, since a single user isn't the multi-user ability the dot is meant to signal.
- Two Test Suite cases ("client currency wins, blank falls back to the instance default" and "blank currency resolves to the instance default") failed on any instance where `APP_CURRENCY` differs from USD and Settings > General's currency hasn't been explicitly saved yet — their expected value only accounted for `$settings['currency']` falling back to a hardcoded `'USD'`, not `invoxaResolveCurrency()`'s actual fallback to the `APP_CURRENCY` env var. Test expectations now mirror that real fallback chain.
- Add Client accepted a blank Client Name, silently falling back to a random 3-character client key. Add Expense and Add Recurring Expense accepted a blank Vendor and a zero/blank Amount. All three are now validated both server-side (the real guard) and client-side (immediate feedback), matching the pattern Add User already used.

### Added
- Dashboard stat cards (Total Invoiced, This Month, Outstanding, Active Clients) count up from 0 on load and on refresh instead of appearing instantly, with a skeleton shimmer placeholder while a refresh is in flight; the same shimmer replaces the "Next Auto-Run" label and the CRM drawer's bare "Loading..." text.
- The dashboard's Total (All Clients) revenue line now fills down to a soft gradient glow instead of sitting flat and unfilled.
- Toasts now pop in with a bouncy scale/slide and a check/exclamation icon instead of appearing as a flat text bar.

## [2.11.13] - 2026-08-29

### Added
- A support contact address, now that Service Desk is working: README's new Support section and the in-app Docs > Source Code pane both point to it as a GitLab-account-free alternative to filing an issue directly.

## [2.11.12] - 2026-08-29

### Added
- Settings > Account now shows a status dot for Two-Factor Authentication, same as the existing dots on Email/Billing/Payments/API/Notifications/Users/License.

## [2.11.11] - 2026-08-29

### Fixed
- No gap between the "Regenerate Backup Codes" and "Disable Two-Factor Authentication" buttons — only ever visible after the 2.11.10 fix made that panel reachable without a reload.

## [2.11.10] - 2026-08-29

### Fixed
- Enabling Two-Factor Authentication never revealed the "Disable Two-Factor Authentication" panel afterward — it only appeared after a manual page reload, making 2FA look impossible to turn back off. Pre-existing, not introduced by the 2.11.3–2.11.9 changes.

## [2.11.9] - 2026-08-29

### Added
- Two-Factor Authentication setup now shows a QR code to scan, instead of only the manual setup key. Generated entirely client-side (vendored `qrcode-generator`, no data leaves the browser) from the same `otpauth://` URI the setup key was already built from.

## [2.11.8] - 2026-08-29

### Added
- Creating a user (Settings > Users) now emails the new account a welcome message with a link to set their own password, instead of the admin's chosen password being the only one they ever know. Reuses the existing password-reset token machinery rather than emailing a plaintext password.
- A security review — see CODEBASE.md for scope and findings. No high-confidence exploitable issues found; two defense-in-depth items (CSRF tokens, session ID regeneration on login) added to the Roadmap.

## [2.11.7] - 2026-08-29

### Fixed
- Outgoing emails (invoices, resends, overdue reminders, the SMTP test) never set PHPMailer's `CharSet`, so it defaulted to ISO-8859-1 — any non-ASCII character (the em dash in the "Powered by Invoxa" footer, currency symbols, accented names) rendered as mojibake in the recipient's inbox. All four send sites now set `CharSet = 'UTF-8'`, matching the password-reset/verification emails, which already had it. Pre-existing, not introduced by the 2.11.3–2.11.6 code-organization moves.

## [2.11.6] - 2026-08-29

### Changed
- Code organization: `invoxa.php`'s page template — the HTML head/styles, sidebar/mobile nav, every tab's markup (Dashboard, Invoices, Ad Hoc Invoice, Clients, Expenses, Quotes, Stats, Audit, Sync, Docs), every modal, and the entire client-side `<script>` block — moved into new `lib/page_head.php`, `lib/page_nav.php`, `lib/tab_*.php`, `lib/tabs_misc.php`, `lib/page_modals.php`, and `lib/page_script.php`. This was the last piece of the 2.11.3/2.11.4 code-organization work — `invoxa.php` itself is now backend logic only, down from over 13,000 lines to around 2,300. A relocation, not a rewrite — behavior is meant to be unchanged, run the internal Test Suite after upgrading to confirm.

## [2.11.5] - 2026-08-29

### Fixed
- 2.11.4 broke every request with a 500: `lib/auth_gate.php` requires `lib/license.php`, but the path was left over from when that code lived in `invoxa.php` itself (where `__DIR__` pointed at `src/`) — now that the code lives inside `lib/`, `__DIR__` already points there, so the old path doubled up to `lib/lib/license.php`. Fixed to `__DIR__ . '/license.php'`.

## [2.11.4] - 2026-08-29

### Changed
- Code organization: `invoxa.php`'s auth system (session bootstrap, login/signup/2FA/password-reset flow), 2FA/backup-code/API-token logic, the public `?apiv1=` API dispatch, Settings, and Backup & Restore (including Demo Data, the Test Suite, Audit Log Retention, Offsite Push, and Data Repair) — both their rendering and their AJAX action handlers — moved into new `lib/auth.php`, `lib/auth_gate.php`, `lib/api_v1.php`, `lib/settings.php`/`lib/settings_page.php`, and `lib/backup.php`/`lib/backup_page.php`. A relocation, not a rewrite — behavior is meant to be unchanged, run the internal Test Suite after upgrading to confirm. The page template is the only piece still in `invoxa.php` — that split is future work.

## [2.11.3] - 2026-08-29

### Changed
- Code organization: `invoxa.php`'s client, stats, exports, and payments logic — rendering functions, AJAX action handlers, and the public Stripe/PayPal payment routes — moved into new `lib/clients.php`, `lib/stats.php`, `lib/exports.php`, and `lib/payments.php`, alongside the existing `lib/invoice_helpers.php`/`lib/license.php`. A relocation, not a rewrite — behavior is meant to be unchanged, run the internal Test Suite after upgrading to confirm. Settings, Backup & Restore, auth/2FA/API tokens, and the page template still live in `invoxa.php` — that split is future work.

## [2.11.2] - 2026-08-29

### Changed
- Test Suite reorganization: TOTP, Stripe webhook signature, and Backup codes format/uniqueness checks moved from Core Logic into Security, where the rest of the auth/crypto checks already live — Backup codes had ended up split across both groups. Renamed the Recurring Billing / Cron group to Billing Cron.

## [2.11.1] - 2026-08-29

### Added
- Test Suite coverage for multi-currency: `invoxaResolveCurrency()`/`invoxaNormalizeCurrencyCode()` fallback and normalization, `invoxaGroupAmountsByCurrency()`/`invoxaFormatMoneyByCurrency()` grouping instead of blending, a fresh client's blank currency resolving to the instance default, and the core guarantee that an invoice's currency is a snapshot at creation time rather than a live link to its client's (a later change to the client's currency doesn't rewrite past invoices).

## [2.11.0] - 2026-08-29

### Added
- **Multi-currency, per client/invoice.** Each client now carries its own Currency (3-letter code, e.g. USD/EUR/GBP) in the Add/Edit Client form, defaulting to blank ("use the instance default" from Settings &gt; General). Every invoice/quote snapshots its client's currency at creation time into its own row, so changing a client's currency later never rewrites past invoices. This covers ad hoc invoices, quotes, recurring billing, late fees, invoice PDFs, the Stripe/PayPal payment flow, payment/refund/late-fee notifications, and the Client Portal — all read and charge in the invoice's own currency rather than the instance-wide setting. There's no automatic exchange-rate conversion: the Dashboard's headline totals and the Invoices/Clients/Quotes tabs group amounts by currency instead of adding them together (so a mix of currencies never gets silently blended into one wrong number), and CSV exports gain a Currency column. Statistics and the Accounting Journal/QuickBooks (IIF) exports report on the instance default currency only for now, excluding other-currency invoices rather than mixing them in — see Roadmap for extending that to group by currency too.

## [2.10.3] - 2026-08-29

### Fixed
- Mobile sidebar: a subnav (Statistics/Data Management/Docs/Settings) now collapses back down after picking one of its items, instead of staying expanded and the chevron staying rotated the next time the menu is reopened.
- Settings &gt; Users table no longer overflows off-screen on mobile — it scrolls horizontally within its own card, matching the pattern already used for the Data Management &gt; Test Suite table.
- Mobile menu (and its backdrop) now renders above the bottom icon bar instead of underneath it when opened.
- Invoices/Quotes/Expenses/Clients: the toolbar row (Export/Filter/Saved Views) is now collapsed behind a single "Filters &amp; Export" toggle on mobile, instead of each group wrapping onto its own row and eating vertical space; the expanded panel renders as one bordered card with divider rows instead of separate floating boxes, and the Export group no longer overflows past the screen edge.

### Added
- A fixed Invoxa mark icon now shows at the top of the mobile view while the sidebar is closed, so the brand is visible outside of just the open menu.

## [2.10.2] - 2026-08-28

### Added
- Statistics: five new charts. Revenue tab gets an Invoice Status Breakdown (doughnut, by amount) and a Revenue Trend line chart (trailing 12 calendar months, independent of the tax-year window the Tax tab uses); Forecasting's existing Accounts Receivable Aging list gets a matching bar chart above it; a new Expenses tab shows a tax-year Profit & Loss summary (revenue received vs expenses vs net income), an Expenses by Category doughnut, and an Expenses Over Time bar chart — Expenses previously had no presence anywhere in Statistics at all.
- Statistics &gt; System: three more cards — Storage Footprint (a bar chart of database size vs the invoices/ and backups/ directories on disk, plus the combined total), Webhook Health (unmatched Stripe/PayPal webhook counts, last 30 days and all-time — sits side by side with Email Delivery Health), and Environment (PHP/MySQL/app version, for support requests — sits side by side with Storage Footprint).

### Changed
- Data Management &gt; System &gt; "Tables in Database" list no longer needs to scroll for a normal-sized instance (200px &rarr; 480px).

### Fixed
- Test Suite caught a real Receipt OCR bug: `parseReceiptOcrText()` didn't strip leading OCR noise (a stray symbol Tesseract sometimes reads at the start of a line) off the vendor line before returning it, so a receipt whose store name got a garbled leading character would carry that garbling straight into the prefilled Vendor field.

## [2.10.1] - 2026-08-28

### Added
- Audit Log now records *which* user performed an action — every `invoxa_actions` row gets a `performed_by_user_id`/`performed_by_username` (the username is denormalized at insert time so the trail stays readable even after that user is later deleted), and the Activity/Audit Log timeline shows a "performed by" label on every entry (cron/system-triggered rows, like the nightly recurring-billing run or webhook events, show "System"). All ~30 places that write an audit entry now go through one shared `invoxaLogAction()` helper instead of each hand-rolling its own `INSERT`. Settings > Users actions (create/role change/password reset/delete) now also write their own audit entries, not just a notification.

### Changed
- Sidebar: the gap above Search (below Settings) now matches the gap above the "Data & Tools" section label (below Clients) — was noticeably tighter before.

## [2.10.0] - 2026-08-28

### Added
- **Multi-user accounts (Settings > Users).** Invoxa was single-admin-only until now — sign-up was hard-gated to the very first account, and every "your account" query (profile, 2FA, license binding) silently assumed there was only ever one row in `invoxa_users`. Added a `role` column (`admin`/`member`), a Settings > Users pane to add/edit-role/delete accounts, and scoped every one of those "your account" queries to the actual logged-in session instead of an arbitrary `LIMIT 1` row. Admins have full access, including Settings and Data Management; members get full day-to-day access (Dashboard, Invoices, Clients, Quotes, Expenses) plus their own Account tab (username/email/password/2FA), but nothing else under Settings and no Data Management. The account created at signup is always an admin; the last remaining admin can't be demoted or deleted. Adding a second (or further) account is the 7th paid capability — editing or removing an existing one stays free, same pattern as API tokens and the Client Portal. The license's email-binding check now always reads the original (lowest-id) account specifically, regardless of who's logged in or how many teammates exist.

## [2.9.6] - 2026-08-28

### Added
- Add/Edit Expense: the old single "Receipts" upload is now two separate slots, Invoice (the vendor's bill) and Receipt (proof of payment) — each expense attachment is tagged `invoice` or `receipt` in the database accordingly, and each already-uploaded file gets a "move" button to re-tag it into the other slot if it was dropped in the wrong one. Picking image receipt(s) in the Receipt slot runs each through OCR (Tesseract) server-side and prefills Vendor and Amount if they're still blank — a "Prefilled from the receipt — double-check before saving" note appears so it's clear the values are a guess, not a manual entry. When more than one image is attached there, whichever one has a line genuinely labeled TOTAL wins over one where the amount is just a largest-number guess. Only the Receipt slot is ever scanned — the Invoice slot is never OCR'd. PDFs and files with no detected total/vendor fall back to manual entry as before.
- Settings > Notifications: extended from 3 event toggles to 10 — added invoice email failures, late fees charged, invoices voided, unmatched payment webhooks, refunds (split out from "payment received"), recurring billing run errors, and security events (2FA enabled/disabled, API tokens created/revoked) — plus a Select All / Select None control for the list.

### Changed
- Mobile bottom nav's third button is now "Add Invoice" (jumps to the Ad Hoc Invoice section) instead of "Add Expense" (which opened a modal) — invoicing is the more frequent on-the-go action; logging an expense is still one tap away via Invoices/Dashboard.

### Fixed
- `nav()` (both the sidebar and the mobile bottom nav) now closes any open modal on navigation — previously, opening Add Expense from the mobile bottom nav and then tapping Dashboard/Invoices/Clients left the modal open on top of the newly navigated section.
- Docs > Quick Start's Screenshots table rendered as broken text (`!Invoices<br>Invoices — ...`) instead of images: the in-app markdown renderer had no `![alt](url)` image support at all (it fell through to the link-handling code, which strips anything that isn't `http(s)`/`#anchor`/`*.md`, leaving a stray `!` and a bare `<br>` that got HTML-escaped instead of rendered) — added real image support, and even with that fixed the screenshot files were never reachable: nginx blocked all of `/docs/` (including `/docs/screenshots/`) and the `docs/screenshots/` folder wasn't mounted into any container. Added a `^~ /docs/screenshots/` allow-list ahead of that block and mounted the folder read-only into the nginx service.

## [2.9.5] - 2026-08-27

### Changed
- The Settings > Email mail-status badge/dot (added in 2.9.4) no longer requires `INVOXA_INSTANCE_LABEL` to be set — it now shows on every instance, including production, and gained a third state: "Not Configured" (red) when `SMTP_HOST` is empty, alongside the existing "Real SMTP" (green) and "Mail Sink" (amber).

## [2.9.4] - 2026-08-27

### Added
- Settings > Email: a "Real SMTP" / "Mail Sink" badge appears on the Test Email Server card, plus a matching status dot next to Email in the sidebar — makes it obvious at a glance whether outgoing mail is really being sent or just caught locally for safe testing.

## [2.9.3] - 2026-08-27

### Added
- Docs > Reference > Roadmap: a short list of ideas being considered for future releases (multi-currency per client/invoice, two-way Xero/QuickBooks sync, receipt OCR) — not commitments, just the current shortlist.

### Changed
- Tightened the sidebar nav's vertical spacing (item padding/margin and the gap around section labels) — there are enough main-menu items now that the previous spacing pushed Settings/Logout further down than it needed to.

## [2.9.2] - 2026-08-27

### Changed
- The sidebar's global search box moved from directly under the logo down to just above Logout, so it no longer competes with the nav for top billing — still reachable the same way (Ctrl/Cmd+K), just lower-priority placement.

### Fixed
- Moving the search box introduced a top border/spacer directly on its own wrapper, which threw off the vertical centering of its icon and "Ctrl K" hint against the input (both are positioned relative to that wrapper's middle). The spacing now lives on a separate outer element instead.

## [2.9.1] - 2026-08-27

### Changed
- Test Suite expanded with 11 additional checks — invoice-total edge cases (100% discount, negative/credit line items, out-of-range percentage clamping), tax-year rollover across the calendar boundary, invoice numbering (default and custom template/padding), TOTP clock-drift tolerance, quote-to-invoice conversion, the Clients bulk flag update, and the accounting journal's double-entry balance — filling in coverage that was noticeably thinner than the rest of the app's.
- Dashboard stat cards no longer lift on hover, just a subtle shadow change.
- A bit more breathing room between Logout and the version/changelog line in the sidebar footer.

### Removed
- "Bulk Mark Paid" under Data Management > Bulk Actions — the Invoices tab's own bulk action bar (checkbox select + Mark Paid) covers the same job now, so this had become a second way to do the same thing. Docs updated to match.

### Fixed
- The Test Suite table's column widths jumped around when switching between the group pill filters, since the table sized columns off whatever rows happened to be visible; now fixed-width so filtering doesn't reflow the table. Also fixed the checkbox column overlapping into Category once that width was pinned down, and loosened up padding across the table generally.

## [2.9.0] - 2026-08-27

### Added
- Bulk actions on Clients, Expenses, and Quotes: a checkbox column plus a bulk action bar, matching what Invoices already had — Clients (Active/Inactive/Test/Unmark Test/Delete), Expenses (Export CSV/Delete), Quotes (Convert to Invoice/Export CSV/Delete).
- Quotes now has a whole-table CSV export (an Export group in the toolbar), alongside the existing per-row bulk export.

### Changed
- Invoices/Clients/Expenses/Quotes toolbars now share one consistent layout: page controls live in their own row below the heading instead of some being inline with the title, and each table's bulk-action bar always appears as its own row (sized to its buttons, not stretched full-width) instead of varying by page.
- Invoice emails attach the invoice as HTML again instead of a rendered PDF.
- Removed the "Welcome back" dashboard banner — it didn't add anything the page title didn't already say.

### Fixed
- The Clients table's Client Name cell had `display:flex` set directly on the `<td>`, which broke its table-cell box model and threw off row-height/border alignment against sibling columns (most visible once the new checkbox column was added). Moved the flex layout to an inner wrapper `<div>` instead.

## [2.8.1] - 2026-08-25

### Changed
- Dashboard stat cards now lead with a small colored icon, matching the rest of the app's cards.
- Empty states are consistent everywhere instead of a mix of plain library text and grey one-liners: Invoices, Clients, Quotes, and Expenses now show an icon and a friendlier message when there's nothing to list yet, matching the treatment Recurring Expenses and the Sync tab already had.
- The active sidebar nav item now gets a left accent bar in addition to its background tint.
- The Clients table shows a colored initials avatar next to each client name instead of plain text, for faster scanning.
- Switching to Invoices/Clients/Quotes/Expenses now dims the table and shows a brief spinner while its background refresh is in flight, instead of the data silently swapping in.

## [2.8.0] - 2026-08-25

### Added
- Client Portal: open quotes now show in the portal (previously invoice-only) with an Accept Quote button — a confirmation step first, then it converts to a real invoice the same way the admin's own Convert button does, notifying you (new "notify when a client accepts a quote" toggle under Settings > Notifications) instead of you having to check back. An expired quote can't be accepted. The admin-side Convert action and the portal's client-side accept now share one `convertQuoteToInvoice()` implementation instead of two copies of the same logic, and quote conversions are logged to the Audit Log for the first time.

## [2.7.0] - 2026-08-25

### Added
- Generic webhook notifications: Settings > Notifications now offers a "Generic Webhook" channel alongside Telegram/Slack, for anything that isn't either specifically — ntfy, a Discord webhook, or your own receiver — with a Payload Format choice (plain text, Slack-style JSON, or Discord-style JSON) since there's no one shape every receiver expects.
- Global quick search: a search box in the sidebar (Ctrl/Cmd+K to focus) that looks across invoices, quotes, clients, and expenses at once and jumps straight to a match's own tab, instead of only being able to search within whichever table is already open.

## [2.6.0] - 2026-08-25

### Added
- Recurring expenses: Expenses now has a Recurring Expenses card where a template (vendor, category, amount, frequency) auto-logs a new expense on its own schedule the next time recurring billing runs (Settings > Billing, or the cron), with the same per-period double-logging guard recurring invoices already use. Same license bucket as recurring billing automation; deleting a template stays free.
- Quote expiry: quotes can now have a "Quote Expires" date, separate from the due date they carry over once converted to an invoice. Shown on the quote itself and in the Quotes table, with an "Expired" badge once past — converting an expired quote to an invoice now warns first.
- Bulk actions on Invoices: a checkbox column plus a bulk action bar (Mark Paid, Resend, Export CSV, Delete) for handling several invoices at once instead of one at a time.
- A 4-icon bottom navigation bar (Dashboard, Invoices, Add Expense, Clients) on mobile, for the handful of things worth reaching without opening the hamburger menu.

## [2.5.0] - 2026-08-25

### Added
- Expenses now support multiple receipts per record (a scanned receipt plus a card statement excerpt, for example) instead of just one — the Expenses table shows a receipt count, and the Add/Edit Expense modal lets you upload, view, and delete individual receipts. Existing single receipts are migrated in automatically.
- Docs > Reference now has a Source Code page linking to the public GitLab repo; the same link is also in the sidebar footer and the README.

### Fixed
- Statistics never scrolled — an extra, non-flex wrapper `<div>` around its `.section-scroll` (present only on this tab) meant the scroll container never got a bounded height, so content grew past the viewport and was silently clipped instead of scrolling.
- On mobile, Settings/Statistics/Docs/Data Management's in-page sub-navigation was a sticky bar that wrapped into several rows and stayed pinned to the top of the tab as you scrolled, eating most of the screen (a stray `align-items: flex-start` on the shared layout also kept their content from ever stretching to full width, which was the likely cause of those tabs feeling like they wouldn't scroll at all). It's now tied into the main hamburger menu instead — each of those four tabs gets a collapsible section in the mobile sidebar, consistent with the rest of mobile navigation.
- Dashboard's "Next Auto-Run" line could overflow past the "Dashboard" heading on narrow screens instead of wrapping to its own line.

### Changed
- The mobile hamburger menu now opens from the right edge of the screen instead of the left.
- The Expense date field shows an explicit `YYYY-MM-DD` readout beside it, since the native date picker's displayed day/month order follows the browser's own language setting rather than the OS region and can't be forced from the page.
- The Business Identity tax field (Settings > Branding) and its label on invoices/quotes now lead with "GST", with "VAT" kept alongside it for anyone on VAT terminology instead — was VAT-first, which doesn't match the primary GST audience.

## [2.4.0] - 2026-08-23

### Added
- VAT/Tax ID number field for the business (Settings > Branding > Business Identity), and Phone/Address fields for clients — both flow through to generated invoices/quotes and to the Client CSV export/import.
- Custom invoice template mode: alongside Detailed/Compact, Settings > Branding > Invoice Template now offers a "Custom" layout with a small nunjucks-style template editor (variables, conditionals, loops over line items), a "Load Default Template" starting point, and a "Preview Sample" button that renders a dummy invoice in the selected layout without saving anything.

### Fixed
- Quotes generated with a custom invoice template kept saying "Invoice" instead of "Quote" — the document heading/title is now driven by an actual template variable (`document_type`) instead of a post-generation string replace that only worked against the built-in Detailed/Compact layouts.

### Changed
- Settings > Branding's "Business Identity" card was doing double duty as both business details and invoice-layout configuration, and its "Remove Powered by Invoxa" checkbox rendered oversized (missing the shared label styling that keeps every other form label the same size). Split into two cards — Business Identity and Invoice Template — and fixed the checkbox's styling to match the rest of the form.

## [2.3.7] - 2026-08-22

### Fixed
- Dashboard's charts stayed a fixed pixel size while dragging the browser window wider/narrower (Statistics' charts already worked correctly). Root cause: `.card` — the Dashboard chart's container — is a CSS grid item with no `min-width` set, so it defaults to `min-width: auto` and can't actually shrink below the chart's own rendered width; Statistics' equivalent container already had the `min-width: 0` fix for this well-known grid/flexbox behavior, `.card` didn't. Added the same fix to `.card`, plus a debounced `window.resize` listener that explicitly resizes every chart on the page (via Chart.js's own instance registry) as a backstop.
- Dashboard's "N Overdue Invoices" alert's **View All** button just switched to the Invoices tab without filtering — now it also applies the existing Overdue status filter, landing on the actual prefiltered list instead of the full unfiltered table.

### Changed
- Light theme is now the default for a first-time visitor (previously dark) — still remembered per-browser via the existing Settings toggle once changed. The login/signup screen, previously a separate hardcoded-dark page with no light variant, now follows the same saved preference (and defaults to light for a first-time visitor too), so there's no jarring dark-login-into-light-app mismatch.

## [2.3.5] - 2026-08-22

### Fixed
- Statistics' Revenue/Forecasting/Clients/Tax & Compliance/Activity/System sub-tabs were unclickable when unlicensed — the dimmed, view-only preview treatment was applied to the whole subnav (buttons included) instead of just the content panes, so a prospective buyer couldn't even browse between sections to see what each one offers. The sub-tab buttons are now always clickable; only the actual data underneath stays dimmed and non-interactive.

## [2.3.4] - 2026-08-22

### Changed
- Password-reset and email-confirmation emails now send as "Invoxa (No-Reply)" instead of the business's own configured name — they're system-generated security emails, not business correspondence, so they shouldn't look like they came from (or expect a reply to) the business itself. The sending address is unchanged, still whatever `SMTP_FROM_EMAIL` the install has configured, so deliverability through the installer's own mail setup isn't affected.

## [2.3.3] - 2026-08-22

### Added
- Settings > Authentication now shows the account email's verification status — a green "Verified" line once confirmed, or an amber "Not verified" warning with a **Verify Now** button if not, so the state from the first-login onboarding guide (2.3.0) doesn't just disappear once that one-time modal is dismissed.

## [2.3.2] - 2026-08-22

### Fixed
- Signup and Settings > Authentication's password change accepted anything non-empty — a password like "test" went straight through with no length check, while the newer password-reset flow (2.2.0) already enforced an 8-character minimum. All three now share the same `PASSWORD_MIN_LENGTH` (8) requirement, both server-side and as a `minlength` hint on the fields themselves.

## [2.3.1] - 2026-08-22

### Added
- New `INVOXA_INSTANCE_LABEL` env var appends to the browser tab title (e.g. "Invoxa (Demo)") on both the login screen and the main app shell, so a Demo or Test instance running alongside production is identifiable at a glance across tabs. Set on the `docker-compose.demo.yml` override; unset by default, so production's tab title is unchanged.

## [2.3.0] - 2026-08-22

### Added
- Email confirmation: signup only checked that the account email was well-formed, never that it was actually reachable — since that same address is now the sole recovery path (2.2.0), a typo'd or fake email would silently break recovery later with no way to tell. A confirmation link is now emailed at signup (and again whenever the email is changed in Settings), with a **Resend confirmation email** option in the first-login onboarding guide until it's clicked.

## [2.2.0] - 2026-08-22

### Added
- Account recovery: a **Forgot your password or username?** link on the login screen sends a reset email (containing the account's username plus a one-time link, valid 30 minutes) to the address on file. Whether or not that email matches an account, the same generic confirmation is shown, so the login screen never reveals which email is registered. Following a valid link also offers **Erase Everything & Start Over** — typing `RESET` wipes every client, invoice, and setting and returns to the signup screen — available only via a verified reset link rather than as an unauthenticated action on the login screen, since proving control of the account's email is the only thing standing between an anonymous visitor and a full data wipe.

## [2.1.0] - 2026-08-22

### Added
- A one-time onboarding guide appears right after the very first signup (or after a Factory Reset, which returns the app to that same first-run state) — proper Invoxa branding (icon and wordmark, matching the sidebar), and a **Load Demo Data** button that jumps straight to Data Management > Demo Data so a new install can be populated and explored immediately, or **Start from scratch** to dismiss and begin with a clean slate.

### Fixed
- The very first sign-in after account creation showed "Welcome back, {username}" — the same flash used on every subsequent login — which is wrong for an account that has never logged in before. That first sign-in now shows the new onboarding guide instead; the "Welcome back" flash is unchanged for every login after that.

## [2.0.0] - 2026-08-19

### Changed
- **The license key now gates six specific paid features instead of the whole app.** Previously an unlicensed install was "browsable" but couldn't send invoices at all; now client/invoice/quote management, manual payments, backups, 2FA, and the Dashboard all work fully with no key. A license unlocks: Stripe/PayPal payment collection, recurring billing automation (including late fees and reminders), the Client Portal, creating/renewing external API tokens, Reporting & Statistics (the six-tab view — the Dashboard's own basic totals stay free), and removing the "Powered by Invoxa" credit line from invoices/emails. Revoking or deleting an API token, and revoking a Client Portal link, both stay free — turning something off never requires a license.
- **Gated features now stop working the moment a license is deactivated, not just at the point of configuring them.** The "Pay Now" button and the payment page behind it, external API token authentication, and Client Portal links all now re-check the license live, on every use — previously something configured while licensed (a Stripe key saved, an API token issued, a portal link generated) would keep quietly working after the license was removed. Settings > Billing, Payments, and API Access are now fully greyed out and non-interactive when unlicensed (not just showing a lock icon on the tab), and their on/off status dots no longer show "active" for something that's actually locked and not running.
- Renamed Settings > "Billing & Reminders" to just **Billing**.
- Every gated feature now shows a lock icon at the point you'd browse into it — the Statistics sidebar item, the Billing/Payments/API Access tabs in Settings, and the Client Portal's Generate/Regenerate buttons — instead of only finding out by clicking and getting an error. Disabled buttons and dropdowns are now visibly greyed out app-wide, not just inert.
- Activating an invalid license now shows the specific reason (bad signature, email mismatch, domain mismatch, no profile email set) directly in the toast, instead of a generic "not valid for this domain/install" that required checking the License tab separately to find out why.
- Added a **Deactivate License** button (Settings > License), and saving an invalid/blank key now reloads the page after showing why — previously an invalid key was still saved server-side (correctly locking the paid features back out) but the page kept showing the old "Licensed" state until you manually refreshed.
- Statistics gains real charts, not just numbers and tables — Revenue Breakdown (Revenue tab, now sitting beside the Tax Year Summary instead of stacked full-width below it), a monthly Invoiced-vs-Paid bar (Tax & Compliance), Top 5 Clients by Paid Revenue and Most Active Clients as horizontal bar charts (Clients/Activity tabs), and an Email Delivery Health donut (System tab) — all reusing data already computed server-side. The free Dashboard already had charts; the paid Statistics area didn't, which undersold exactly the thing it's asking people to pay for.
- Fixed the new Statistics charts rendering broken/oversized — none of them set `maintainAspectRatio: false`, so Chart.js was sizing them off its own default aspect ratio instead of the actual container, fighting the explicit heights around them. Also moved Top 5 Clients to sit beside Client & Payment Insights (instead of stacked below Clients Needing Attention), and dropped the Billing Frequency Mix chart entirely — not useful.
- Statistics now shows the same greyed-out, view-only preview pattern as Billing/Payments/API when unlicensed, instead of hiding the real content behind a plain pitch — a prospective buyer sees their own actual charts and numbers (dimmed, non-interactive) rather than just being told what they're missing.

## [1.17.0] - 2026-08-19

### Added
- "Show Only Test/Dummy Data" toggle (Settings > General > Preferences) — flips every list, chart, and total across the app to show *only* is_test-flagged records instead of your real data, for safely previewing Demo Data in the app's own real screens without it mixing in with your own clients and invoices. Empty until you seed Demo Data, populated once you do, and gone the instant you turn it back off — Clear Dummy Data then removes it for good. Overrides the existing "Hide Test Clients Globally" toggle while it's on.

## [1.16.0] - 2026-08-19

### Added
- Test Suite grows a new "External API" section — token creation, authentication via the stored hash, revocation, and expiry — plus real end-to-end workflow checks under Clients & Invoices: building an Ad Hoc invoice's total from line items/discount/tax, voiding and unvoiding an invoice against the outstanding-total query, a quote's numbering staying separate from real invoices, portal link revocation, and recording an expense. Recurring Billing / Cron gains a late fee eligibility check (overdue past the grace period vs. still within it vs. already charged). 35 tests across 7 sections, up from 21 across 6 — same rules as every other test here: disposable fixtures, cleaned up in a finally block, no real email or gateway call.

### Changed
- Documentation search (Docs tab) now matches multiple words in any order instead of one literal substring — searching "payments client" finds any page containing both words anywhere in its content, not just pages containing that exact phrase.
- Every Features documentation page (Invoicing & Quotes, Recurring Billing, Payments, Clients & Client Portal, Security, External API, Reporting, Data Management, Notifications) rewritten with substantially more depth — exact field names, button labels, and settings locations for each workflow — in place of the previous short, high-level summaries, including the previously-undocumented Data Repair tool. Visual style is unchanged.

## [1.15.0] - 2026-08-18

### Added
- Documentation (Docs tab) reorganized into a two-level sidebar — Getting Started, Features (Overview plus a focused page each for Invoicing & Quotes, Recurring Billing, Payments, Clients & Portal, Security, External API, Reporting, Data Management, and Notifications), and Reference — with a search box that filters the page list by title and by each page's own content as you type.
- Test Suite expanded well beyond invoice math and payment logic: creating a client, invoice numbering staying unique as invoices are added, an invoice storing the exact amount billed, the Client Portal's own query correctly excluding draft invoices, a payment actually writing its own Audit Log entry, the Recurring Billing double-billing guard correctly detecting (and correctly *not* detecting) an already-billed client, and email content checks (template token substitution, generated invoice HTML containing the right client/number/amount) — all without ever sending a real email or touching cron directly.
- Test Suite is now organized into named sections (Core Logic, Clients & Invoices, Payments & Refunds, Recurring Billing / Cron, Email Content, Security) with a header checkbox per section to toggle the whole group at once, plus pill buttons above the table — "All" (shown bold/highlighted by default) or any one section — that isolate the table to just that section's rows and pre-select them, for running a focused slice without scrolling past everything else.

### Changed
- Settings > Notifications now shows the same active/off indicator dot as Payments, API Access, Billing & Reminders, and License, instead of being the one integration tab with no at-a-glance status.

## [1.14.0] - 2026-08-18

### Added
- Features page (Docs > Features) — a single-screen summary of what the app covers (Invoicing, Recurring Billing, Payments, Clients & Client Portal, Security, External API, Reporting, Data Management, Notifications), for a quick "what does this actually do" reference without reading the full Quick Start guide.
- API tokens can now be permanently deleted (Settings > API Access), not just revoked — Revoke immediately cuts off a live token and keeps it listed as an audit trail (the normal pattern, same as GitHub/Stripe); Delete is a separate, explicit action for clearing a token out of the list entirely once it's already revoked or expired.

### Changed
- Test Suite (Data Management) reworked from a single "Run All Tests" button into an itemized table: each test is its own row with a checkbox (Select All/Select None, all checked by default), split into a Category column and a Case column (hover for the full explanation of what that check verifies), and a Status column that shows "Not run" until you run it, then keeps its last result — a row you leave unchecked isn't touched, so its previous pass/fail sticks around instead of going blank. Run Selected only executes the checked rows. The Run button and pass/fail summary moved to the top of the panel, next to each other, instead of below the table.

## [1.13.0] - 2026-08-18

### Added
- External API (Settings > API Access) — a small read/write token-authenticated API for scripts and other tools: list invoices, get one by number, list clients, and record a payment against an invoice. Tokens are created/renewed/revoked from Settings, shown in full exactly once at creation, and the same panel includes a guide with copy-pasteable `curl` examples for every endpoint. Gated behind a license like the rest of the app's mutating features.
- Test Suite (Data Management > Test Suite) — a "Run All Tests" button covering invoice math, TOTP, Stripe/PayPal amount conversion and webhook signature verification, and the payment ledger's real database behavior (partial payments, duplicate-webhook idempotency, refunds). Every check that touches the database uses its own disposable client/invoice and deletes it again immediately after, pass or fail — never a real client, never Demo Data's fixtures. Doesn't call the real Stripe/PayPal/SMTP APIs.
- 2FA backup codes — enabling two-factor authentication now also issues 10 single-use backup codes, shown once and usable in place of a TOTP code if you lose your authenticator device. Regenerate them any time from Settings > Authentication.
- Login lockout — 5 failed attempts (password or 2FA code, either counts) locks the account for 15 minutes. Applies to both login stages, not just the password.
- Refund handling — a refund issued from the Stripe or PayPal dashboard now reopens the invoice and reduces its recorded paid amount instead of leaving it marked paid forever. Requires subscribing your existing webhook to one additional event per gateway (`charge.refunded` for Stripe, `PAYMENT.CAPTURE.REFUNDED` for PayPal) — see Settings > Payments for the exact webhook URLs/events.
- Client Portal links can now be given an expiry (30/90/365 days, or never) when generated or regenerated, instead of only ever being valid until manually revoked.
- Audit Log now records when a Stripe/PayPal webhook arrives referencing an invoice number Invoxa doesn't recognize, instead of silently dropping it.

### Changed
- Client edit/add form is 50% wider and organized into clearly separated sections (Identity, Billing Terms, Bank Details, Status, Client Portal) instead of one long stacked list of fields.
- Settings and Data Management sub-tabs reordered to group related settings together (e.g. Payments next to the new API Access, danger-zone actions last in Data Management).
- Sidebar order swapped to Expenses above Clients.
- PayPal webhook handling now rejects obviously-malformed requests locally before making any outbound call to PayPal's API, rather than spending an OAuth token fetch and a verify-signature call on traffic that could never have verified anyway.

### Fixed
- Settings > General's default table page size didn't apply when switching to the Invoices/Clients/Quotes/Expenses tab — only a full page reload picked up a changed setting, because the page size was captured once at initial load instead of re-read on each tab's background refresh.
- A payment gateway webhook and its corresponding return-page (the page a client's browser lands on right after paying) landing within milliseconds of each other could, in the rare case both raced past the duplicate-payment check at the same moment, crash instead of one of them safely no-op'ing.

## [1.12.0] - 2026-08-14

### Added
- Online payment collection via Stripe and/or PayPal — enable under Settings > Payments. Adds a "Pay Now" button to emailed invoices and outstanding Client Portal invoices; a client paying is captured through the standard hosted-checkout flow for each (Stripe Checkout Session, PayPal Order + Capture) and always confirmed via webhook, never trusted from the browser redirect alone. Off by default; each gateway needs its own credentials and webhook configured before it does anything.
- Settings > Payments also adds a "Public URL" field — required for Pay Now links on Recurring Billing invoices specifically, since those are generated by a background cron job with no browser request to infer your domain from.

### Changed
- The payment ledger (see 1.9.0) now records which gateway a payment came from (manual/Stripe/PayPal) and, for gateway payments, a provider reference used to guarantee a redelivered or duplicate webhook can never credit the same payment twice. Manual "Mark Paid" and "Bulk Mark Paid" are unaffected — same behavior, now routed through one shared function instead of three separate copies of the same logic.

## [1.11.0] - 2026-08-05

### Added
- Client Portal — a read-only, token-gated link (no login) each client can use to see their own invoice list and paid/outstanding/overdue status, without you emailing PDFs back and forth. Generate/regenerate/revoke a client's link from the Client form; nothing is sent automatically, you share the link yourself. Off by default — no client has a link until you generate one.

## [1.10.0] - 2026-07-24

### Added
- Two-factor authentication (TOTP) for the admin account — enable under Settings > Authentication (shows a secret key to add to any authenticator app; no external service involved). Once enabled, login requires a 6-digit code after the password. Off by default for every existing install.

## [1.9.0] - 2026-07-13

### Added
- Payment ledger — each "Mark Paid" now logs its own installment (amount + optional note) instead of overwriting a single paid_amount figure, so an invoice paid off in several parts keeps a real history. The Mark Paid modal shows prior installments and defaults the amount field to the remaining balance rather than the full invoice total. "Mark Unpaid"/"Clear Partial Payment" and Bulk Mark Paid stay consistent with the ledger.

## [1.8.0] - 2026-06-29

### Added
- Per-client discount % and tax % on the Client form, applied automatically to that client's Recurring Billing invoices — the same Subtotal/Discount/Tax/Total breakdown Ad Hoc invoices already show. Both default to 0 (no discount, no tax) for every existing client until edited, so recurring billing behaves exactly as before unless a client is explicitly given a rate.

## [1.7.0] - 2026-06-15

### Added
- Void/cancel status for invoices — pull a mistaken invoice out of every outstanding, overdue, and revenue total without deleting it (and losing its audit trail). Unvoid to restore it.
- Resend Invoice Email — re-send the exact original invoice (same HTML, logo, and PDF attachment) straight from the Invoices tab, without regenerating a new invoice number.
- Bulk client import via CSV, alongside the existing CSV export.
- Local backup retention setting — automatically prune old backups in `invoxa-backups/` down to a configured count after each new one.
- Statistics rebuilt into six focused tabs (Revenue, Forecasting, Clients, Tax & Compliance, Activity, System) in place of one long scrolling page, with several new reports: Accounts Receivable Aging, Quote Pipeline, Voided total, Client Growth & Mix, Clients Needing Attention, Email Delivery Health, Tax Year progress + monthly breakdown, and Most Active Clients.
- Server-side PDF generation (dompdf) — a real "Download PDF" button and PDF email attachments, replacing the old client-side screenshot-based export.
- "Export PDFs" — bundle every invoice into a single zip download, alongside the existing CSV export.
- Offsite Backup Push panel under Data Management — toggle plus destination settings for a scheduled rclone push; credentials stay out of the app and live on the cron container.
- Invoice-level discount % and tax % fields on Ad Hoc invoices, shown as Subtotal/Discount/Tax rows under the line items.
- Bulk Mark Paid moved from the Invoices toolbar into Data Management → Bulk Actions, alongside the other bulk/administrative operations.
- Brief "Welcome back" flash on login.

### Fixed
- Embedded logo failing to render in exported PDFs, including for invoices reconstructed from synced files.
- Several sidebar/modal elements (invoice count badges, modal close buttons) that were unreadable in light mode due to hardcoded white text on a light background.

## [1.6.0] - 2026-05-11

### Added
- Payment reminders now resend the original invoice's HTML rather than a bare plain-text notice, so a client chasing an overdue reminder sees the actual invoice again.
- Late fee automation: configurable grace period, charged as a proper billable invoice rather than a note.
- Audit Log retention setting (keep last 30/180/365 days, or forever).

### Changed
- Recurring billing's double-billing guard can now be temporarily bypassed from Settings for re-running a missed cycle, instead of only via direct DB access.

## [1.5.0] - 2026-02-20

### Added
- Quotes: save an Ad Hoc invoice as a quote first, then convert it to a real invoice later without retyping anything.
- CRM notes and a slide-out client drawer showing recent invoices and running totals.
- Tax year CSV exports (full invoice list and monthly summary), with a configurable tax year start month.

## [1.4.0] - 2025-11-03

### Added
- Filesystem Sync tab — reconcile invoice HTML files on disk against the database in both directions after a restore or manual file changes.
- Database backup/restore from within the app, including a dry-run preview before restoring.
- License system with per-install domain/email binding.

### Fixed
- Invoice numbering could collide when a client's template didn't include their client key.

## [1.3.0] - 2025-08-14

### Added
- Recurring billing via cron, with per-client billing frequency (weekly/monthly/quarterly/annually) and payment terms.
- Dashboard charts for monthly revenue and per-client breakdown.

## [1.2.0] - 2025-05-02

### Added
- Editable email templates for invoice and reminder emails.
- Branding settings: logo upload, brand color, business identity separate from the app's own identity.

## [1.1.0] - 2025-02-18

### Added
- Client notes and internal memos on invoices.
- CSV export for invoices and clients.

### Fixed
- Partial payments weren't reflected correctly in the outstanding balance shown on the dashboard.

## [1.0.0] - 2024-11-01

Initial release — client management, ad hoc invoice generation and email delivery, basic dashboard, single admin account.
