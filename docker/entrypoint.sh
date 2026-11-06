#!/bin/bash
# HedgeInv container start-up: everything runs by itself.
#  1. Uploads: persistent disk if there is one, otherwise mirrored in the database (free plan)
#  2. Wait for the database
#  3. One-time import of your existing data (only if you add it as a Secret File)
#  4. Create / upgrade the schema automatically
#  5. Background price + plan + bot updates (no cron needed)
#  6. Start Apache
set -u
APP=/var/www/html
LIB=/usr/local/lib/hedgeinv
DATA="${DATA_DIR:-/var/data}"
log() { echo "[hedgeinv] $*"; }

# Apache must run exactly one MPM (mod_php needs prefork). Some hosts' build layers
# re-enable mpm_event, which crashes Apache with "More than one MPM loaded".
rm -f /etc/apache2/mods-enabled/mpm_event.* /etc/apache2/mods-enabled/mpm_worker.* 2>/dev/null
[ -e /etc/apache2/mods-enabled/mpm_prefork.load ] || a2enmod -q mpm_prefork >/dev/null 2>&1

USE_DISK=0
if [ -d "$DATA" ] && [ -w "$DATA" ] && grep -qs " $DATA " /proc/mounts; then USE_DISK=1; fi

if [ "$USE_DISK" = 1 ]; then
  mkdir -p "$DATA/uploads/kyc" "$DATA/uploads/deposit_proofs" "$DATA/uploads/experts"
  cp -an /opt/uploads-seed/. "$DATA/uploads/" 2>/dev/null || true
  rm -rf "$APP/assets/uploads"
  ln -s "$DATA/uploads" "$APP/assets/uploads"
  chown -R www-data:www-data "$DATA/uploads"
  log "uploads stored on the persistent disk ($DATA/uploads)"
else
  mkdir -p "$APP/assets/uploads/kyc" "$APP/assets/uploads/deposit_proofs" "$APP/assets/uploads/experts"
  chown -R www-data:www-data "$APP/assets/uploads"
  log "no persistent volume at $DATA: uploads will be mirrored in the database"
fi

DB_OK=0
if php "$LIB/wait-db.php"; then
  DB_OK=1
  IMPORT_FILE="${IMPORT_SQL_FILE:-/etc/secrets/import.sql}"
  if [ -f "$IMPORT_FILE" ]; then
    if php "$LIB/needs-import.php"; then
      log "importing existing data from $IMPORT_FILE ..."
      php "$LIB/import.php" "$IMPORT_FILE" || log "IMPORT REPORTED ERRORS - check the lines above"
    else
      log "import file present but database already has users - skipping (safe)"
    fi
  fi
  php "$LIB/bootstrap.php" || log "bootstrap reported a problem (site will retry on first request)"
  if [ "$USE_DISK" = 0 ]; then
    php "$LIB/files-sync.php" down
    php "$LIB/files-sync.php" up   # seed photos shipped with the code
  fi
else
  log "database not reachable yet - starting web server anyway; it will connect when ready"
fi

# Background: live prices, plans and bots keep updating while the server is awake.
(
  sleep 20
  while true; do
    php "$LIB/tick.php" 2>&1 | sed 's/^/[ticker] /'
    sleep "${TICK_SECONDS:-120}"
  done
) &

# Background (free plan): save new uploads to the database within ~20 seconds.
if [ "$USE_DISK" = 0 ]; then
  (
    while true; do
      sleep 20
      php "$LIB/files-sync.php" up
    done
  ) &
fi

exec "$@"
