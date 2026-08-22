# Changelog

All notable changes to Invoxa are documented here. Dates are when a release was cut, not individual commit dates.

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
