<?php
/**
 * Live market quotes for the investment plan pages (polled every 30s).
 * GET /api/live_quotes.php?keys=GOLD,BTC,SPX
 * Logged-in users/admins only. Served from the shared quote cache, so
 * polling never triggers more than one provider request per market per minute.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

if (!current_user() && !current_admin()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not logged in']);
    exit;
}

$keys = array_slice(parse_market_keys($_GET['keys'] ?? ''), 0, 40);
$pdo = get_db();
$out = [];
foreach (get_live_quotes($pdo, $keys) as $k => $q) {
    if (!$q) { $out[$k] = null; continue; }
    $out[$k] = [
        'price' => $q['price'],
        'price_fmt' => fmt_market_price($q['price'], $q['decimals']),
        'change_pct' => $q['change_pct'] === null ? null : round($q['change_pct'], 4),
        'change_fmt' => $q['change_pct'] === null ? '—' : fmt_pct($q['change_pct']),
        'age' => $q['age'],
    ];
}
echo json_encode(['ok' => true, 'quotes' => $out, 'server_time' => date('c')]);
