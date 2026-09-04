# Invoxa — Installation Guide

## Requirements

- Docker and Docker Compose
- A domain or IP address to reach the app on
- An email account to send invoices from (a Gmail walkthrough is below — any SMTP provider works: your own mail server, Office 365, SendGrid, Mailgun, etc.)

## 1. Configure (optional)

Invoxa runs with zero configuration — `docker compose up -d --build` works as-is, no `.env` file needed. Skip straight to [step 2](#2-email--smtp-setup) if that's all you want for now; come back here later to customize anything.

If you do want to override something (most commonly SMTP, since there's no working default for that):

```bash
cd docker-invoxa
cp .env.example .env
```

Then edit `.env` as needed:

- **`DB_PASSWORD` / `DB_ROOT_PASSWORD`** — default to a fixed placeholder value if unset. The database isn't reachable outside the Docker network (no host port mapping), so this is low-risk left as-is, but set real random passwords if you'd rather not rely on that.
- **`CRON_SECRET`** — leave this unset (recommended). The app generates a random, unique-per-install value itself on first boot and reuses it after that — you never need to touch it. Only set it explicitly if you have a specific reason to (e.g. `openssl rand -hex 24`).
- **`CRON_SCHEDULE`** — when recurring billing runs, in standard 5-field cron syntax. The default (`15 7 3 * *`) runs at 7:15am on the 3rd of each month. You can also change this later from **Settings** in the app itself.
- **`APP_TIMEZONE`** — an IANA timezone, e.g. `America/New_York`, `Europe/London`, `Pacific/Auckland`. Affects dates shown/used throughout the app.
- **`APP_CURRENCY`** — a 3-letter code (`USD`, `NZD`, `GBP`, `EUR`, ...) used as the starting value; you can change it any time from **Settings** without touching `.env`.
- **`HTTP_PORT`** — the port on this machine the app will be reachable on.
- **SMTP settings** — see the next section.

## 2. Email / SMTP setup

Invoxa sends invoice emails via any standard SMTP account. If you don't have one to use, the easiest option is a Gmail account with an **app password**:

1. Go to your Google Account → **Security**.
2. Turn on **2-Step Verification** if it isn't already on (app passwords require it).
3. Go to **Security → 2-Step Verification → App passwords** (or visit
   `myaccount.google.com/apppasswords` directly).
4. Create a new app password — name it something like "Invoxa".
5. Google shows you a 16-character password (e.g. `abcd efgh ijkl mnop`). Copy it.
6. In `.env`, set:
   ```
   SMTP_HOST=smtp.gmail.com
   SMTP_PORT=587
   SMTP_USER=your-account@gmail.com
   SMTP_PASSWORD=abcdefghijklmnop     # the 16-character app password, no spaces
   SMTP_FROM_EMAIL=your-account@gmail.com
   SMTP_FROM_NAME=Your Business Name
   SMTP_ENCRYPTION=tls
   ```

Your regular Google account password will **not** work here — Gmail requires an app password for SMTP access from a script/application like this.

If you're using a different provider, the same five `SMTP_*` values apply — check your provider's SMTP documentation for the host/port. Common ones:

| Provider          | SMTP_HOST                  | SMTP_PORT | SMTP_ENCRYPTION |
|--------------------|-----------------------------|-----------|------------------|
| Gmail (Workspace or personal) | `smtp.gmail.com`   | 587       | `tls`            |
| Outlook / Office 365 | `smtp.office365.com`      | 587       | `tls`            |
| Zoho Mail          | `smtp.zoho.com`             | 587       | `tls`            |
| SendGrid           | `smtp.sendgrid.net`         | 587       | `tls`            |
| Mailgun            | `smtp.mailgun.org`          | 587       | `tls`            |

Once running, use **Settings > Send Test Email** in the app to confirm it's working before relying on it.

### Testing email safely (optional)

If you'd rather verify invoice/reminder emails without risking a real send — or don't have SMTP credentials yet — point Invoxa at a local mail-sink instead of a real provider. [Mailpit](https://mailpit.axllent.org/) is a lightweight SMTP server that captures everything sent to it in a web inbox instead of actually delivering it; nothing ever leaves your machine.

Add it as another service in your `docker-compose.yml`:

```yaml
  mailpit:
    image: axllent/mailpit:latest
    container_name: invoxa-mailpit
    restart: unless-stopped
    ports:
      - "8025:8025"   # web inbox — http://localhost:8025
    networks:
      - invoxa-net
```

Then in `.env`, point Invoxa's SMTP settings at it instead of a real provider:

```
SMTP_HOST=mailpit
SMTP_PORT=1025
SMTP_USER=
SMTP_PASSWORD=
SMTP_FROM_EMAIL=test@invoxa.local
SMTP_FROM_NAME=Invoxa (Test)
SMTP_ENCRYPTION=none
```

`docker compose up -d --build`, then open `http://localhost:8025` — every email Invoxa sends (invoices, reminders, password resets, and Data Management > Test Suite's "Email Delivery" checks) lands there instead of a real inbox. Settings > Email also shows an "Open Mailpit" shortcut whenever `SMTP_HOST=mailpit` is detected. Switch `SMTP_HOST` back to a real provider (and drop the `mailpit` service) whenever you're ready to send real mail.

## 3. Start the stack

```bash
docker compose up -d --build
```

This starts four containers: `invoxa-db` (MySQL), `invoxa-php`, `invoxa-nginx`, and `invoxa-cron`. Check they're all healthy:

```bash
docker compose ps
docker compose logs -f
```

## 4. First run

Open `http://<this-server>:<HTTP_PORT>` in a browser (default port 8090). The first visit shows a signup screen — this creates the one admin account for this instance. There's no separate registration; whoever signs up first is the admin.

**Use the exact email your license was issued to** when you sign up (or set it later under Settings > Authentication) — your license is tied to that email as well as your domain, so a mismatch means the app won't accept an otherwise-valid key.

## 5. Add your license

Without a license, Invoxa is browsable but won't send real invoices or run recurring billing. Go to the **License** tab in the sidebar, paste in the key you were given, and click
**Activate License**. The key is tied to both the domain you access the app on and the email on your admin account (Settings > Authentication) — if either changes, you'll need a new key from your seller.

## 6. Brand your invoices

Go to **Settings** to set your business name, logo, brand color, footer text (bank details/payment instructions), currency, and tax-year start month. These only affect what your clients see on invoices — the app's own interface always identifies itself as Invoxa.

## Updating

```bash
docker compose down
docker compose up -d --build
```

Your data isn't affected by rebuilding the images: clients/invoices/settings live in a Docker named volume for the database, and generated invoice files/backups live in the `invoxa-invoices/` and `invoxa-backups/` folders next to this file (ordinary host folders, not Docker-managed volumes — you can back them up directly with normal tools).

## Backups

Use the **Data Management** tab in the app to generate and restore database backups. Backup files land in the `invoxa-backups/` folder next to this file — not reachable from outside the app over HTTP, but directly browsable/backupable on the host.

## Migrating to a new server

Invoxa has exactly one admin account and no phone-home license server, so moving to a new machine is just: back up, copy the file, restore.

On the **old** server, in **Data Management**:

1. Under "Select Tables to Export", leave the default `invoxa_*` tables checked and click **Create Backup**.
2. Download the resulting file — it's named e.g. `backup_2026-08-13.sql`.

On the **new** server:

1. Set it up as in [step 3](#3-start-the-stack) (`docker compose up -d --build`), and create the admin account at first run.
2. If you had a license, add the same key under the **License** tab — it's tied to your domain, so if the new server has a different domain/IP, ask for a replacement key first (see the seller who issued your original one).
3. Go to **Data Management**, click **Import Backup File**, and select the `backup_2026-08-13.sql` you downloaded from the old server.
4. It appears in the dropdown above — select it and click **Test Restore (Dry Run)** first to see a summary of what it'll import, then **Restore Selected Backup** to apply it.

Your clients, invoices, and settings are now on the new server. The old server's data isn't deleted automatically — decommission it yourself once you've confirmed everything migrated correctly.

## Recovering access (forgot admin username/password)

Invoxa has exactly one admin account and no self-service "forgot password" email flow (there's no guarantee SMTP is even configured). If you're locked out, reset it from the command line on the machine running Invoxa:

```bash
cd docker-invoxa
docker compose exec db mysql -u invoxa -p invoxa -e "DELETE FROM invoxa_users;"
# password is DB_PASSWORD from your .env, or invoxa_default_change_me if unset
```

This only clears the one admin-account row — your clients, invoices, and settings are untouched. Reload the app in your browser: since there's no admin account anymore, it shows the **signup** screen again, and the next username/password you enter becomes the new admin login.

### Locked out by two-factor authentication (lost your authenticator device)

If you still know your password but can't produce a 2FA code, you don't need the full reset above — just clear the two-factor secret and sign in with your password alone:

```bash
cd docker-invoxa
docker compose exec db mysql -u invoxa -p invoxa -e "UPDATE invoxa_users SET totp_secret = NULL, totp_secret_pending = NULL;"
```

Two-factor is now off for this account; re-enable it under Settings > Authentication once you have a working authenticator app.
