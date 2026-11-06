<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/market_data.php';

$user = require_login();
require_kyc($user);
$pdo = get_db();
process_due_instrument_ticks($pdo);
process_all_due_ticks($pdo);

$byCategory = get_instruments_by_category($pdo);
$tabs = [
    'all' => 'All',
    'crypto' => 'Crypto',
    'stocks' => 'Stocks',
    'forex' => 'Forex',
    'metals' => 'Metals',
    'commodities' => 'Commodities',
    'bonds' => 'Bonds',
];
$activeTab = $_GET['tab'] ?? 'all';
if (!isset($tabs[$activeTab])) $activeTab = 'all';

$all = array_merge(...array_values($byCategory));
$instruments = $activeTab === 'all' ? $all : $byCategory[$activeTab];
// Crypto first for "All", then by category; live ones before unavailable ones.
$order = ['crypto' => 0, 'stocks' => 1, 'metals' => 2, 'commodities' => 3, 'forex' => 4, 'bonds' => 5];
usort($instruments, function ($a, $b) use ($order) {
    $la = instrument_is_live($a) ? 0 : 1; $lb = instrument_is_live($b) ? 0 : 1;
    return [$la, $order[$a['asset_type']] ?? 9, instrument_priority($a['symbol']), $a['symbol']] <=> [$lb, $order[$b['asset_type']] ?? 9, instrument_priority($b['symbol']), $b['symbol']];
});

$liveCount = count(array_filter($all, 'instrument_is_live'));
$sparks = get_sparklines($pdo, array_column($instruments, 'symbol'));

$labels = ['stocks' => 'Stock', 'metals' => 'Metal', 'commodities' => 'Commodity', 'crypto' => 'Crypto', 'forex' => 'Forex', 'bonds' => 'Bond'];

// Top movers among live instruments
$withChange = [];
foreach ($all as $i) {
    if (!instrument_is_live($i)) continue;
    [, $p] = get_24h_change($i);
    if ($p !== null) $withChange[] = $i + ['_chg' => $p];
}
usort($withChange, fn($a, $b) => $b['_chg'] <=> $a['_chg']);
$movers = [];
if ($withChange) {
    $movers[] = ['Top gainer', $withChange[0]];
    if (count($withChange) > 1) $movers[] = ['Top loser', end($withChange)];
    // Two headline markets: the top-priority crypto and the top-priority non-crypto this site lists.
    $byPrio = $withChange;
    usort($byPrio, fn($a, $b) => [instrument_priority($a['symbol']), $a['symbol']] <=> [instrument_priority($b['symbol']), $b['symbol']]);
    $usedSyms = array_map(fn($m) => $m[1]['symbol'], $movers);
    foreach (['crypto', 'other'] as $want) {
        foreach ($byPrio as $w) {
            if (in_array($w['symbol'], $usedSyms, true)) continue;
            if (($want === 'crypto') !== ($w['asset_type'] === 'crypto')) continue;
            $movers[] = [$want === 'crypto' ? 'Crypto' : ucfirst($labels[$w['asset_type']] ?? 'Market'), $w];
            $usedSyms[] = $w['symbol'];
            break;
        }
    }
}
function mx_chg(?float $p): string {
    if ($p === null) return '<span class="lp-chg flat">—</span>';
    $c = abs($p) < 0.005 ? 'flat' : ($p > 0 ? 'up' : 'down');
    return '<span class="lp-chg ' . $c . '">' . fmt_pct($p) . '</span>';
}

$pageTitle = 'Live Markets';
$navContext = 'user';
require __DIR__ . '/../includes/header.php';
?>
<link rel="stylesheet" href="/assets/css/plans.css?v=3">
<link rel="stylesheet" href="/assets/css/markets.css?v=3">
<div class="container" style="max-width:1200px;">
  <?php if ($msg = flash_get('error')): ?><div class="alert error"><?= e($msg) ?></div><?php endif; ?>

  <div class="mx-head">
    <div>
      <span class="lp-live <?= $liveCount ? '' : 'off' ?>"><i></i><?= $liveCount ?> of <?= count($all) ?> markets live</span>
      <h1>Live Markets</h1>
      <p>Real-time prices for crypto, stocks, forex, metals, commodities and bonds.</p>
    </div>
    <a class="btn gold" href="/user/trade.php">Open trade ticket</a>
  </div>

  <?php if ($movers): ?>
    <div class="mx-movers">
      <?php foreach ($movers as [$label, $m]): ?>
        <a class="mx-mover" href="/user/instrument.php?symbol=<?= urlencode($m['symbol']) ?>">
          <?= asset_icon_instrument($m, 40) ?>
          <div class="t">
            <div class="k"><?= e($label) ?></div>
            <div class="n"><?= e($m['name']) ?></div>
            <div class="p"><?= fmt_instrument_price((float)$m['current_price']) ?></div>
          </div>
          <?= mx_chg($m['_chg']) ?>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="mx-toolbar">
    <nav class="mx-tabs">
      <?php foreach ($tabs as $key => $label):
        $n = $key === 'all' ? count($all) : count($byCategory[$key]); ?>
        <a class="<?= $activeTab === $key ? 'on' : '' ?>" href="/user/markets.php?tab=<?= e($key) ?>"><?= e($label) ?> <span><?= $n ?></span></a>
      <?php endforeach; ?>
    </nav>
    <label class="mx-search">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
      <input type="search" placeholder="Search name or symbol" oninput="mxFilter(this.value)" aria-label="Search markets">
    </label>
  </div>

  <div class="mx-list" id="mxList">
    <div class="mx-row hd"><div>Market</div><div style="text-align:right;">Price</div><div style="text-align:right;">24h</div><div>Last 24h</div><div></div></div>
    <?php if (!$instruments): ?>
      <div class="mx-empty">No instruments in this category yet.</div>
    <?php endif; ?>
    <?php foreach ($instruments as $i):
        [, $pct] = get_24h_change($i);
        $live = instrument_is_live($i);
        $sp = $sparks[$i['symbol']] ?? [];
    ?>
      <a class="mx-row" href="/user/instrument.php?symbol=<?= urlencode($i['symbol']) ?>" data-search="<?= e(strtolower($i['symbol'] . ' ' . $i['name'])) ?>">
        <div class="mx-asset">
          <?= asset_icon_instrument($i, 38) ?>
          <div class="nm"><b><?= e($i['name']) ?></b><small><?= e($i['symbol']) ?> &middot; <?= e($labels[$i['asset_type']] ?? ucfirst($i['asset_type'])) ?></small></div>
        </div>
        <div class="mx-price"><?= $live ? fmt_instrument_price((float)$i['current_price']) : '<span class="mx-stale">Unavailable</span>' ?></div>
        <div class="mx-chg"><?= $live ? mx_chg($pct) : mx_chg(null) ?></div>
        <div class="mx-spark"><?= $live ? render_sparkline_svg($sp, 160, 34, $pct === null ? null : $pct >= 0, $i['symbol']) : '' ?></div>
        <div class="mx-act"><span class="btn small <?= $live ? '' : 'secondary' ?>"><?= $live ? 'Trade' : 'View' ?></span></div>
      </a>
    <?php endforeach; ?>
    <div class="mx-empty" id="mxNone" style="display:none;">No markets match your search.</div>
  </div>

  <div class="lp-note">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 8v5M12 16h.01"/></svg>
    <div>Prices come from Binance and Yahoo Finance and refresh about once a minute. Stocks, forex and commodities only move while their markets are open. Bond prices follow US Treasury ETFs (SHY, IEF, TLT). Markets marked unavailable can't be traded until a live price returns.</div>
  </div>
</div>
<script>
function mxFilter(q) {
  q = q.trim().toLowerCase(); var shown = 0;
  document.querySelectorAll('#mxList a.mx-row').forEach(function (r) {
    var ok = r.getAttribute('data-search').indexOf(q) !== -1;
    r.style.display = ok ? '' : 'none'; if (ok) shown++;
  });
  document.getElementById('mxNone').style.display = shown ? 'none' : '';
}
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
