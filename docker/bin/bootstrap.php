<?php
// Runs the app's own schema creation/upgrades once at start-up so the first visitor never waits.
require '/var/www/html/config/database.php';
require '/var/www/html/includes/functions.php';
require '/var/www/html/includes/market_data.php';
$pdo = get_db();
ensure_instrument_live_schema($pdo);
echo "[hedgeinv] database schema is up to date\n";
