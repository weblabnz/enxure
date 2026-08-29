#!/bin/sh
set -e

# Ensures php-fpm (running as www-data) can write to the bind-mounted
# invoices/backups folders and the shared crontab volume.
mkdir -p /usr/share/nginx/html/invoxa-invoices /usr/share/nginx/html/invoxa-backups /usr/share/nginx/html/docs/screenshots /etc/invoxa-crontab
chown -R www-data:www-data /usr/share/nginx/html/invoxa-invoices /usr/share/nginx/html/invoxa-backups /usr/share/nginx/html/docs/screenshots /etc/invoxa-crontab

# If CRON_SECRET isn't set in .env, generate a unique one on first boot and
# persist it so it survives restarts and no manual setup step is required.
SECRET_FILE=/etc/invoxa-crontab/.cron_secret
if [ -z "$CRON_SECRET" ]; then
    if [ ! -s "$SECRET_FILE" ]; then
        TMP="$SECRET_FILE.$$.tmp"
        head -c 24 /dev/urandom | od -An -tx1 | tr -d ' \n' > "$TMP"
        chmod 600 "$TMP"
        mv -n "$TMP" "$SECRET_FILE" 2>/dev/null || rm -f "$TMP"
    fi
    i=0
    while [ ! -s "$SECRET_FILE" ] && [ $i -lt 20 ]; do sleep 0.5; i=$((i + 1)); done
    export CRON_SECRET=$(cat "$SECRET_FILE")
    chown www-data:www-data "$SECRET_FILE" 2>/dev/null || true
fi

exec docker-php-entrypoint php-fpm
