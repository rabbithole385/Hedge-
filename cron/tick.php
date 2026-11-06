<?php
/**
 * Call this via a cPanel Cron Job every 5-15 minutes for reliable automated
 * ticking, e.g.:
 *   curl -s "https://yourdomain.com/cron/tick.php?key=YOUR_CRON_SECRET" > /dev/null
 *
 * The secret is auto-generated on first run and stored in the settings
 * table. Find it via: SELECT value FROM settings WHERE setting_key='cron_secret';
 * (or just check /admin/settings.php - it shows the full ready-to-use command).
 *
 * Without a cron job, ticks still process lazily whenever a user or admin
 * visits their dashboard - this endpoint just makes it reliable and timely
 * even with no one browsing the site.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/market_data.php';

header('Content-Type: text/plain');

$pdo = get_db();
$expected = get_setting($pdo, 'cron_secret');
$provided = $_GET['key'] ?? '';

if (!$expected || !hash_equals($expected, $provided)) {
    http_response_code(403);
    echo "Forbidden.\n";
    exit;
}

$instruments = process_due_instrument_ticks($pdo, true);
$bots = process_due_bot_ticks($pdo);
$plans = process_due_plan_ticks($pdo);

echo "OK. Live instrument prices updated: {$instruments}. Bot ticks: {$bots}. Plan ticks: {$plans}.\n";
