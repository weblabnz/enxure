#!/bin/sh
set -e

CRON_SCHEDULE="${CRON_SCHEDULE:-15 7 3 * *}"
CRONTAB_FILE=/etc/crontabs/root
SECRET_FILE=/etc/crontabs/.cron_secret

mkdir -p /etc/crontabs

# Reads the same shared-volume secret the php container generates on first
# boot (see php/docker-entrypoint.sh) if CRON_SECRET isn't set explicitly.
if [ -z "$CRON_SECRET" ]; then
    if [ ! -s "$SECRET_FILE" ]; then
        TMP="$SECRET_FILE.$$.tmp"
        head -c 24 /dev/urandom | od -An -tx1 | tr -d ' \n' >"$TMP"
        mv -n "$TMP" "$SECRET_FILE" 2>/dev/null || rm -f "$TMP"
    fi
    i=0
    while [ ! -s "$SECRET_FILE" ] && [ $i -lt 20 ]; do
        sleep 0.5
        i=$((i + 1))
    done
    if [ -s "$SECRET_FILE" ]; then
        CRON_SECRET=$(cat "$SECRET_FILE")
    else
        echo "WARNING: CRON_SECRET could not be determined — recurring billing will not run until it is set." >&2
    fi
fi

# Seeds a default schedule on first boot only; editing it from Settings >
# Recurring Billing in the app takes over after that. busybox crond only
# reloads the crontab when /etc/crontabs' own mtime changes (checked ~every
# 60s, with an hourly fallback), so the app must touch the directory after
# each save for edits to be picked up promptly — see the directory
# permissions below.
if [ ! -f "$CRONTAB_FILE" ] || ! grep -q "run_recurring" "$CRONTAB_FILE" 2>/dev/null; then
    echo "$CRON_SCHEDULE curl -s -S -X POST -d \"action=run_recurring&cron_key=$CRON_SECRET\" http://nginx/enxure.php >> /var/log/enxure-cron.log 2>&1" >>"$CRONTAB_FILE"
fi

# Automatic backups get their own fixed daily line rather than sharing the
# schedule above: run_recurring's action is rejected outright without a
# license (see enxure.php's $__licensePaidActions), but backups aren't a
# licensed feature and must keep working either way. Whether this actually
# does anything each time it fires is decided server-side by the
# auto_backup_enabled setting (Data Management > Backup & Restore) — off by
# default — not by this schedule.
if [ ! -f "$CRONTAB_FILE" ] || ! grep -q "run_auto_backup" "$CRONTAB_FILE" 2>/dev/null; then
    echo "30 2 * * * curl -s -S -X POST -d \"action=run_auto_backup&cron_key=$CRON_SECRET\" http://nginx/enxure.php >> /var/log/enxure-cron.log 2>&1" >>"$CRONTAB_FILE"
fi

# The app saves schedule changes to this file as www-data, not root — fix
# ownership on every boot so busybox crond (which refuses to load a crontab
# it doesn't own as root) can always load it. Group is www-data's gid (33,
# shared by both containers) with group-write, so the app can keep saving.
chown root:33 "$CRONTAB_FILE" 2>/dev/null || true
chmod 660 "$CRONTAB_FILE" 2>/dev/null || true

# The directory also needs group-write so the app can bump its mtime after a
# schedule save (see the note above on how crond picks up changes).
chown root:33 /etc/crontabs 2>/dev/null || true
chmod 770 /etc/crontabs 2>/dev/null || true

touch /var/log/enxure-cron.log
# crond's own startup/wakeup logging goes to stdout (`docker compose logs
# cron`). This file holds only actual job output from the crontab line's own
# redirect — one entry per real cron firing.
exec crond -f -l 5 -L /dev/stdout
