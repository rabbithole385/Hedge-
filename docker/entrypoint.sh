#!/bin/bash
# HedgeInv container start-up: everything runs by itself.
#  1. Persistent uploads on the Render disk
#  2. Wait for the database
#  3. One-time import of your existing data (if you add it as a Secret File)
#  4. Create / upgrade the schema automatically
#  5. Background price + plan + bot updates every few minutes (no cron needed)
#  6. Start Apache
set -u
APP=/var/www/html
LIB=/usr/local/lib/hedgeinv
DATA="${DATA_DIR:-/var/data}"
log() { echo "[hedgeinv] $*"; }

# 1. Uploads (KYC, deposit proofs, photos) must survive deploys -> keep them on the disk.
if [ -d "$DATA" ] && [ -w "$DATA" ]; then
  mkdir -p "$DATA/uploads/kyc" "$DATA/uploads/deposit_proofs" "$DATA/uploads/experts"
  cp -an /opt/uploads-seed/. "$DATA/uploads/" 2>/dev/null || true
  rm -rf "$APP/assets/uploads"
  ln -s "$DATA/uploads" "$APP/assets/uploads"
  chown -R www-data:www-data "$DATA/uploads"
  log "uploads -> $DATA/uploads (persistent)"
else
  log "WARNING: no persistent disk at $DATA - uploads will be lost on redeploy"
fi

# 2. Wait for the database (up to ~2 minutes on a cold start).
if php "$LIB/wait-db.php"; then
  # 3. One-time import of an existing database dump.
  IMPORT_FILE="${IMPORT_SQL_FILE:-/etc/secrets/import.sql}"
  if [ -f "$IMPORT_FILE" ]; then
    if php "$LIB/needs-import.php"; then
      log "importing existing data from $IMPORT_FILE ..."
      # Drop the dump's own CREATE DATABASE / USE lines so it lands in this database.
      sed -E '/^CREATE DATABASE /d; /^USE `/d; /enable the sandbox mode/d' "$IMPORT_FILE" \
        | mariadb --host="$DB_HOST" --port="${DB_PORT:-3306}" --user="$DB_USER" --password="$DB_PASS" \
                  --default-character-set=utf8mb4 --skip-ssl "$DB_NAME" \
        && log "import finished" || log "IMPORT FAILED - check the file and redeploy"
    else
      log "import file present but database already has users - skipping (safe)"
    fi
  fi

  # 4. Create tables on a fresh database, add any new columns, seed defaults.
  php "$LIB/bootstrap.php" || log "bootstrap reported a problem (site will retry on first request)"
else
  log "database not reachable yet - starting web server anyway; it will connect when ready"
fi

# 5. Live prices, plans and bots keep updating even when nobody is browsing.
(
  sleep 20
  while true; do
    php "$LIB/tick.php" 2>&1 | sed 's/^/[ticker] /'
    sleep "${TICK_SECONDS:-120}"
  done
) &

# 6. Web server
exec "$@"
