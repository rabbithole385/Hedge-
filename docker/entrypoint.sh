#!/bin/bash
# Nexora container start-up: everything runs by itself.
#  1. Uploads: persistent disk if there is one, otherwise mirrored in the database (free plan)
#  2. Database: uses yours if configured (DB_* / Railway MySQL), otherwise starts a built-in one
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

# ---- Database: auto-detect, or start the built-in one -------------------------
has_db_config() {
  [ -n "${DB_HOST:-}" ] || [ -n "${MYSQLHOST:-}" ] || [ -n "${MYSQL_URL:-}" ] || [ -n "${DATABASE_URL:-}" ] \
    || [ -f "$APP/config/db_credentials.cpanel.php" ]
}
BUILTIN=0
if has_db_config; then
  log "using the configured external database"
elif [ "${BUILTIN_DB:-on}" != "off" ]; then
  BUILTIN=1
  if [ "$USE_DISK" = 1 ]; then
    DBDIR="$DATA/mysql"; PASSF="$DATA/.hedgeinv-dbpass"
    log "no database configured: starting the built-in database, stored on the volume ($DBDIR)"
  else
    DBDIR=/var/lib/hedgeinv-mysql; PASSF=/var/lib/.hedgeinv-dbpass
    log "WARNING: no database configured and no volume at $DATA."
    log "WARNING: the built-in database is running, but its data is LOST on every redeploy."
    log "WARNING: attach a volume mounted at $DATA to keep users, balances and uploads."
  fi
  mkdir -p "$DBDIR" /run/mysqld
  chown -R mysql:mysql "$DBDIR" /run/mysqld
  if [ ! -d "$DBDIR/mysql" ]; then
    log "creating the built-in database (first start) ..."
    mariadb-install-db --user=mysql --datadir="$DBDIR" --auth-root-authentication-method=socket \
      --skip-test-db >/dev/null 2>&1 || log "database initialisation reported a problem"
  fi
  mariadbd --user=mysql --datadir="$DBDIR" --socket=/run/mysqld/mysqld.sock \
    --bind-address=127.0.0.1 --port=3306 --skip-name-resolve \
    --character-set-server=utf8mb4 --collation-server=utf8mb4_general_ci \
    --max-allowed-packet=64M --innodb-buffer-pool-size=128M --default-time-zone=+01:00 \
    --log-error="$DBDIR/error.log" &
  DB_PID=$!
  for i in $(seq 1 60); do
    mariadb-admin --socket=/run/mysqld/mysqld.sock ping >/dev/null 2>&1 && break
    sleep 1
  done
  [ -s "$PASSF" ] || php -r 'echo bin2hex(random_bytes(24));' > "$PASSF"
  chmod 600 "$PASSF"
  DBP="$(cat "$PASSF")"
  mariadb --socket=/run/mysqld/mysqld.sock -uroot <<SQL || log "could not prepare the built-in database"
CREATE DATABASE IF NOT EXISTS hedgeinv CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
CREATE USER IF NOT EXISTS 'hedgeinv'@'127.0.0.1' IDENTIFIED BY '$DBP';
CREATE USER IF NOT EXISTS 'hedgeinv'@'localhost' IDENTIFIED BY '$DBP';
ALTER USER 'hedgeinv'@'127.0.0.1' IDENTIFIED BY '$DBP';
ALTER USER 'hedgeinv'@'localhost' IDENTIFIED BY '$DBP';
GRANT ALL PRIVILEGES ON hedgeinv.* TO 'hedgeinv'@'127.0.0.1';
GRANT ALL PRIVILEGES ON hedgeinv.* TO 'hedgeinv'@'localhost';
FLUSH PRIVILEGES;
SQL
  export DB_HOST=127.0.0.1 DB_PORT=3306 DB_NAME=hedgeinv DB_USER=hedgeinv DB_PASS="$DBP" DB_SSL=0
  unset DBP
  log "built-in database is ready"
  # Uploads on the volume already; no need to mirror them into the database.
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

if [ "$BUILTIN" = 1 ]; then
  # Stop the database cleanly when the platform stops the container.
  "$@" &
  WEB_PID=$!
  trap 'log "stopping"; kill -TERM $WEB_PID 2>/dev/null; mariadb-admin --socket=/run/mysqld/mysqld.sock shutdown 2>/dev/null; wait $DB_PID 2>/dev/null; exit 0' TERM INT
  wait $WEB_PID
  mariadb-admin --socket=/run/mysqld/mysqld.sock shutdown 2>/dev/null
  exit 0
fi
exec "$@"
