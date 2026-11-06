<?php
// Background updater (replaces the cPanel cron job): live prices, AI bots, investment plans.
require '/var/www/html/config/database.php';
require '/var/www/html/includes/functions.php';
require '/var/www/html/includes/market_data.php';
try {
    $pdo = get_db();
    $i = process_due_instrument_ticks($pdo, true);
    process_all_due_ticks($pdo);
    echo date('H:i:s') . " live prices updated: {$i}; plans and bots checked\n";
} catch (Throwable $e) {
    echo date('H:i:s') . ' update skipped: ' . $e->getMessage() . "\n";
}
