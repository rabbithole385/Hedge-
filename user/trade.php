<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/market_data.php';

$user = require_login();
require_kyc($user);
$pdo = get_db();
process_due_instrument_ticks($pdo);
process_all_due_ticks($pdo);

$instruments = get_all_instruments($pdo);
$bySymbol = [];
foreach ($instruments as $i) $bySymbol[$i['symbol']] = $i;
$order = ['crypto' => 0, 'stocks' => 1, 'metals' => 2, 'commodities' => 3, 'forex' => 4, 'bonds' => 5];
usort($instruments, fn($a, $b) => [instrument_is_live($a) ? 0 : 1, $order[$a['asset_type']] ?? 9, instrument_priority($a['symbol']), $a['symbol']] <=> [instrument_is_live($b) ? 0 : 1, $order[$b['asset_type']] ?? 9, instrument_priority($b['symbol']), $b['symbol']]);

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors[] = 'Session expired, please try again.';
    } else {
        $action = $_POST['action'] ?? '';
        $symbol = strtoupper(trim($_POST['symbol'] ?? ''));
        $inst = $bySymbol[$symbol] ?? null;
        if (!$inst) {
            $errors[] = 'Select a valid instrument.';
        } else {
            $price = (float)$inst['current_price'];
            $mode = $_POST['mode'] ?? 'usd';
            $quantity = $mode === 'usd'
                ? ($price > 0 ? floor(((float)($_POST['amount_usd'] ?? 0) / $price) * 1e8) / 1e8 : 0)
                : (float)($_POST['quantity'] ?? 0);
            if (!instrument_is_live($inst)) {
                $errors[] = "Live price for {$symbol} is unavailable right now, so trading is paused.";
            } elseif ($quantity <= 0) {
                $errors[] = 'Enter a valid amount.';
            } elseif ($action === 'buy' || $action === 'sell') {
                try {
                    if ($action === 'buy') buy_asset($pdo, $user['id'], $symbol, $symbol, $inst['asset_type'], $quantity, $price);
                    else sell_asset($pdo, $user['id'], $symbol, $quantity, $price);
                    $q = rtrim(rtrim(number_format($quantity, 8, '.', ''), '0'), '.');
                    flash_set('success', ($action === 'buy' ? 'Bought ' : 'Sold ') . "{$q} {$symbol} at " . fmt_instrument_price($price) . '.');
                    redirect('/user/trade.php?symbol=' . urlencode($symbol));
                } catch (Throwable $e) {
                    $errors[] = $e->getMessage();
                }
            }
        }
    }
}

$user = current_user();
$stmt = $pdo->prepare('SELECT * FROM holdings WHERE user_id = ? ORDER BY symbol ASC');
$stmt->execute([$user['id']]);
$holdings = $stmt->fetchAll();
$heldQty = [];
$totVal = 0; $totCost = 0;
foreach ($holdings as $h) {
    $heldQty[$h['symbol']] = (float)$h['quantity'];
    $p = isset($bySymbol[$h['symbol']]) ? (float)$bySymbol[$h['symbol']]['current_price'] : 0;
    $totVal += $p * (float)$h['quantity'];
    $totCost += (float)$h['avg_cost'] * (float)$h['quantity'];
}

$selected = strtoupper($_GET['symbol'] ?? $_POST['symbol'] ?? '');
if (!isset($bySymbol[$selected])) {
    $selected = 'BTC';
    foreach ($instruments as $i) { if (instrument_is_live($i)) { $selected = isset($bySymbol['BTC']) && instrument_is_live($bySymbol['BTC']) ? 'BTC' : $i['symbol']; break; } }
}

$labels = ['stocks' => 'Stock', 'metals' => 'Metal', 'commodities' => 'Commodity', 'crypto' => 'Crypto', 'forex' => 'Forex', 'bonds' => 'Bond'];
$js = [];
foreach ($instruments as $i) {
    [, $pc] = get_24h_change($i);
    $js[] = [
        's' => $i['symbol'], 'n' => $i['name'], 't' => $labels[$i['asset_type']] ?? $i['asset_type'],
        'p' => (float)$i['current_price'], 'pf' => fmt_instrument_price((float)$i['current_price']),
        'c' => $pc === null ? null : round($pc, 2), 'live' => instrument_is_live($i), 'icon' => asset_icon_instrument($i, 32),
        'held' => $heldQty[$i['symbol']] ?? 0,
    ];
}

$pageTitle = 'Trade';
$navContext = 'user';
require __DIR__ . '/../includes/header.php';
?>
<link rel="stylesheet" href="/assets/css/plans.css?v=3">
<link rel="stylesheet" href="/assets/css/markets.css?v=3">
<div class="container" style="max-width:1220px;">
  <?php if ($msg = flash_get('success')): ?><div class="alert success"><?= e($msg) ?></div><?php endif; ?>
  <?php foreach ($errors as $err): ?><div class="alert error"><?= e($err) ?></div><?php endforeach; ?>

  <div class="mx-head">
    <div>
      <span class="lp-live"><i></i>Live prices</span>
      <h1>Trade</h1>
      <p>Buy and sell <?= count($instruments) ?> real markets at their live price.</p>
    </div>
    <a class="btn secondary" href="/user/markets.php">Browse all markets</a>
  </div>

  <div class="ix-grid th">
    <div class="lp-panel" style="position:sticky;top:90px;">
      <div class="lp-section-title">Order ticket</div>
      <div class="th-picker" id="thPicker">
        <button type="button" class="th-picker-btn" id="thBtn"></button>
        <div class="th-pop" id="thPop">
          <input type="search" id="thSearch" placeholder="Search 140+ markets" autocomplete="off">
          <div class="th-opts" id="thOpts"></div>
        </div>
      </div>

      <div class="ix-tabs" id="ixSide" style="margin-top:16px;">
        <button type="button" class="on buy" data-side="buy">Buy</button>
        <button type="button" class="sell" data-side="sell">Sell</button>
      </div>
      <div class="lp-form-balance"><span>Wallet</span><b><?= fmt_money($user['wallet_balance']) ?></b></div>

      <form method="post" id="ixForm">
        <?= csrf_field() ?>
        <input type="hidden" name="symbol" id="thSymbol" value="<?= e($selected) ?>">
        <input type="hidden" name="action" id="ixAction" value="buy">
        <input type="hidden" name="mode" id="ixModeField" value="usd">
        <input type="hidden" name="amount_usd" id="ixUsd">
        <input type="hidden" name="quantity" id="ixQty">
        <div class="ix-mode" id="ixMode">
          <button type="button" class="on" data-mode="usd">Amount in $</button>
          <button type="button" data-mode="qty">Quantity</button>
        </div>
        <div class="lp-amount">
          <span class="cur" id="ixCur">$</span>
          <input type="number" id="ixInput" step="any" min="0" inputmode="decimal" placeholder="0.00" required>
        </div>
        <div class="lp-chips" style="margin-bottom:0;">
          <button type="button" data-frac="0.25">25%</button>
          <button type="button" data-frac="0.5">50%</button>
          <button type="button" data-frac="0.75">75%</button>
          <button type="button" data-frac="1">Max</button>
        </div>
        <div class="ix-summary">
          <div><span>Price</span><b id="ixSumPrice">—</b></div>
          <div><span>Quantity</span><b id="ixSumQty">0</b></div>
          <div><span id="ixSumLabel">Total cost</span><b id="ixSumTotal">$0.00</b></div>
        </div>
        <button class="btn buy block" id="ixSubmit" type="submit" style="padding:14px;font-size:15px;">Buy</button>
        <p class="lp-fine" id="thDeadNote" style="display:none;">No live price for this market right now, so orders are paused.</p>
      </form>
    </div>

    <div class="lp-panel">
      <div style="display:flex;justify-content:space-between;align-items:flex-end;gap:12px;flex-wrap:wrap;margin-bottom:14px;">
        <div>
          <div class="lp-section-title" style="margin-bottom:4px;">Your holdings</div>
          <div style="font:700 26px var(--font-mono);"><?= fmt_money($totVal) ?></div>
        </div>
        <?php if ($holdings): $tp = $totVal - $totCost; ?>
          <div style="text-align:right;">
            <div class="lp-section-title" style="margin-bottom:4px;">Unrealised P&amp;L</div>
            <div class="mono-num <?= $tp >= 0 ? 'pos' : 'neg' ?>" style="font-size:17px;"><?= fmt_money($tp) ?> (<?= fmt_pct($totCost > 0 ? $tp / $totCost * 100 : 0) ?>)</div>
          </div>
        <?php endif; ?>
      </div>
      <?php if (!$holdings): ?>
        <div class="lp-empty" style="padding:40px 20px;">
          <h3 style="color:var(--text);margin-bottom:6px;">No positions yet</h3>
          <p style="margin:0;">Pick a market on the left and place your first order.</p>
        </div>
      <?php else: ?>
        <div class="hx-list">
          <div class="hx-row hd"><div>Asset</div><div class="r c2">Quantity</div><div class="r c3">Avg cost</div><div class="r">Value / P&amp;L</div></div>
          <?php foreach ($holdings as $h):
              $inst = $bySymbol[$h['symbol']] ?? null;
              $p = $inst ? (float)$inst['current_price'] : 0;
              $q = (float)$h['quantity'];
              $val = $p * $q; $cost = (float)$h['avg_cost'] * $q; $pl = $val - $cost;
          ?>
            <a class="hx-row" href="/user/instrument.php?symbol=<?= urlencode($h['symbol']) ?>">
              <div class="mx-asset">
                <?= $inst ? asset_icon_instrument($inst, 34) : asset_icon($h['symbol'], 'stock', 34) ?>
                <div class="nm"><b><?= e($inst['name'] ?? $h['symbol']) ?></b><small><?= e($h['symbol']) ?> &middot; <?= fmt_instrument_price($p) ?></small></div>
              </div>
              <div class="r c2"><?= e(rtrim(rtrim(number_format($q, 8), '0'), '.')) ?></div>
              <div class="r c3"><?= fmt_instrument_price((float)$h['avg_cost']) ?></div>
              <div class="r"><?= fmt_money($val) ?><small class="<?= $pl >= 0 ? 'pos' : 'neg' ?>"><?= fmt_money($pl) ?> (<?= fmt_pct($cost > 0 ? $pl / $cost * 100 : 0) ?>)</small></div>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
(function () {
  var LIST = <?= json_encode($js, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  var WALLET = <?= json_encode((float)$user['wallet_balance']) ?>;
  var cur = null, side = 'buy', mode = 'usd';
  var by = {}; LIST.forEach(function (x) { by[x.s] = x; });
  var btn = document.getElementById('thBtn'), pop = document.getElementById('thPop'), opts = document.getElementById('thOpts'), search = document.getElementById('thSearch'), input = document.getElementById('ixInput');
  function chg(c) { if (c === null) return '<span class="lp-chg flat">—</span>'; var k = Math.abs(c) < .005 ? 'flat' : (c > 0 ? 'up' : 'down'); return '<span class="lp-chg ' + k + '">' + (c > 0 ? '+' : '') + c.toFixed(2) + '%</span>'; }
  function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
  function money(v) { return '$' + v.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}); }
  function select(sym) {
    cur = by[sym] || LIST[0];
    document.getElementById('thSymbol').value = cur.s;
    btn.innerHTML = cur.icon + '<span class="nm"><b>' + esc(cur.n) + '</b><small>' + esc(cur.s) + ' · ' + (cur.live ? cur.pf : 'Unavailable') + '</small></span>' + (cur.live ? chg(cur.c) : '') + '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6"/></svg>';
    document.getElementById('ixSumPrice').textContent = cur.live ? cur.pf : '—';
    pop.classList.remove('open'); input.value = ''; calc();
  }
  function render(q) {
    q = (q || '').trim().toLowerCase();
    var html = '', n = 0;
    LIST.forEach(function (x) {
      if (q && (x.s + ' ' + x.n).toLowerCase().indexOf(q) === -1) return;
      if (++n > 80) return;
      html += '<div class="th-opt' + (x.live ? '' : ' dead') + '" data-s="' + esc(x.s) + '">' + x.icon + '<div style="min-width:0"><b>' + esc(x.n) + '</b><small>' + esc(x.s) + ' · ' + esc(x.t) + '</small></div><div class="px">' + (x.live ? x.pf : '—') + '<br>' + (x.live ? chg(x.c) : '') + '</div></div>';
    });
    opts.innerHTML = html || '<div class="mx-empty" style="padding:20px">No match</div>';
  }
  btn.addEventListener('click', function () { pop.classList.toggle('open'); if (pop.classList.contains('open')) { search.value = ''; render(''); search.focus(); } });
  search.addEventListener('input', function () { render(search.value); });
  opts.addEventListener('click', function (e) { var o = e.target.closest('.th-opt'); if (o) select(o.getAttribute('data-s')); });
  document.addEventListener('click', function (e) { if (!document.getElementById('thPicker').contains(e.target)) pop.classList.remove('open'); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') pop.classList.remove('open'); });

  function calc() {
    var v = parseFloat(input.value) || 0, P = cur ? cur.p : 0;
    var qty = mode === 'usd' ? (P > 0 ? v / P : 0) : v, total = qty * P;
    document.getElementById('ixSumQty').textContent = (Math.floor(qty * 1e8) / 1e8).toLocaleString(undefined, {maximumFractionDigits: 8}) + ' ' + (cur ? cur.s : '');
    document.getElementById('ixSumTotal').textContent = money(total);
    document.getElementById('ixSumLabel').textContent = side === 'buy' ? 'Total cost' : 'You receive';
    document.getElementById('ixUsd').value = mode === 'usd' ? v : '';
    document.getElementById('ixQty').value = mode === 'qty' ? v : '';
    var over = side === 'buy' ? total > WALLET + .001 : qty > (cur ? cur.held : 0) + 1e-9;
    document.getElementById('ixSumTotal').style.color = over ? 'var(--negative)' : '';
    var s = document.getElementById('ixSubmit');
    s.className = 'btn block ' + side;
    s.disabled = !cur || !cur.live;
    s.textContent = cur && cur.live ? (side === 'buy' ? 'Buy ' : 'Sell ') + cur.s : 'Trading paused';
    document.getElementById('thDeadNote').style.display = cur && !cur.live ? '' : 'none';
  }
  document.querySelectorAll('#ixSide button').forEach(function (b) { b.addEventListener('click', function () { side = b.getAttribute('data-side'); document.querySelectorAll('#ixSide button').forEach(function (x) { x.classList.toggle('on', x === b); }); document.getElementById('ixAction').value = side; calc(); }); });
  document.querySelectorAll('#ixMode button').forEach(function (b) { b.addEventListener('click', function () { mode = b.getAttribute('data-mode'); document.querySelectorAll('#ixMode button').forEach(function (x) { x.classList.toggle('on', x === b); }); document.getElementById('ixModeField').value = mode; document.getElementById('ixCur').textContent = mode === 'usd' ? '$' : '#'; input.value = ''; calc(); }); });
  document.querySelectorAll('#ixForm .lp-chips button').forEach(function (b) {
    b.addEventListener('click', function () {
      if (!cur) return; var f = parseFloat(b.getAttribute('data-frac'));
      if (side === 'buy') { var usd = Math.floor(WALLET * f * 100) / 100; input.value = mode === 'usd' ? usd.toFixed(2) : Math.floor(usd / cur.p * 1e8) / 1e8; }
      else { var q = Math.floor(cur.held * f * 1e8) / 1e8; input.value = mode === 'qty' ? q : (Math.floor(q * cur.p * 100) / 100).toFixed(2); }
      calc();
    });
  });
  input.addEventListener('input', calc);
  select(<?= json_encode($selected) ?>);
})();
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
