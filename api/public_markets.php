<?php
/**
 * Public market snapshot for the marketing pages (home page ticker, "most traded").
 * Read-only, no login, cached for 30s. Only public market data, no user data.
 * GET /api/public_markets.php
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=30');
header('X-Robots-Tag: noindex');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/market_data.php';

try {
    $pdo = get_db();
    process_due_instrument_ticks($pdo); // throttled internally to once a minute
    $all = get_all_instruments($pdo);
} catch (Throwable $e) {
    http_response_code(503);
    echo json_encode(['ok' => false]);
    exit;
}

$labels = ['crypto' => 'Crypto', 'stocks' => 'Stocks', 'forex' => 'Forex', 'metals' => 'Metals', 'commodities' => 'Commodities', 'bonds' => 'Bonds'];
$counts = array_fill_keys(array_keys($labels), 0);
$live = [];
foreach ($all as $i) {
    if (isset($counts[$i['asset_type']])) $counts[$i['asset_type']]++;
    // Stablecoins barely move, so they make a dull showcase.
    if (instrument_is_live($i) && !in_array($i['symbol'], ['USDT', 'USDC', 'DAI', 'BUSD'], true)) $live[] = $i;
}
usort($live, fn($a, $b) => [instrument_priority($a['symbol']), $a['symbol']] <=> [instrument_priority($b['symbol']), $b['symbol']]);

// ?type=crypto,forex  -> every live market of those types (for the market pages)
// ?symbols=SPY,QQQ     -> just those symbols
// (no params)          -> a balanced showcase mix for the home page
$types = array_values(array_intersect(array_map('trim', explode(',', strtolower($_GET['type'] ?? ''))), array_keys($labels)));
$symbols = array_values(array_filter(array_map(fn($x) => strtoupper(trim($x)), explode(',', $_GET['symbols'] ?? ''))));
$picked = []; $per = [];
if ($types || $symbols) {
    foreach ($all as $i) {
        if (!instrument_is_live($i)) continue;
        if ($types && !in_array($i['asset_type'], $types, true)) continue;
        if ($symbols && !in_array($i['symbol'], $symbols, true)) continue;
        $picked[] = $i;
    }
    usort($picked, fn($a, $b) => [instrument_priority($a['symbol']), $a['symbol']] <=> [instrument_priority($b['symbol']), $b['symbol']]);
    $picked = array_slice($picked, 0, 200);
} else {
    // A balanced mix: a few of each category, best-known first.
    foreach ($live as $i) {
        $t = $i['asset_type'];
        if (($per[$t] ?? 0) >= ($t === 'crypto' || $t === 'stocks' ? 5 : 2)) continue;
        $per[$t] = ($per[$t] ?? 0) + 1;
        $picked[] = $i;
        if (count($picked) >= 16) break;
    }
}
$sparks = get_sparklines($pdo, array_column($picked, 'symbol'), 24);

$out = [];
foreach ($picked as $i) {
    [, $pct] = get_24h_change($i);
    $out[] = [
        'symbol' => $i['symbol'],
        'name' => $i['name'],
        'type' => $labels[$i['asset_type']] ?? ucfirst($i['asset_type']),
        'price' => (float)$i['current_price'],
        'price_fmt' => fmt_instrument_price((float)$i['current_price']),
        'change_pct' => $pct === null ? null : round($pct, 2),
        'icon' => asset_icon_instrument($i, 36),
        'spark' => $sparks[$i['symbol']] ?? [],
    ];
}
echo json_encode([
    'ok' => true,
    'total' => count($all),
    'live' => count($live),
    'counts' => $counts,
    'markets' => $out,
], JSON_UNESCAPED_SLASHES);
