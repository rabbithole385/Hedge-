<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/market_data.php';

$user = require_login();
require_kyc($user);
$pdo = get_db();
process_due_instrument_ticks($pdo);
process_all_due_ticks($pdo);

$symbol = strtoupper(trim($_GET['symbol'] ?? ''));
$instrument = get_instrument_by_symbol($pdo, $symbol);

if (!$instrument) {
    flash_set('error', 'Instrument not found.');
    redirect('/user/markets.php');
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors[] = 'Session expired, please try again.';
    } else {
        $instrument = get_instrument_by_symbol($pdo, $symbol); // freshest price
        $action = $_POST['action'] ?? '';
        $price = (float)$instrument['current_price'];
        $mode = $_POST['mode'] ?? 'qty';
        $quantity = $mode === 'usd'
            ? ($price > 0 ? floor(((float)($_POST['amount_usd'] ?? 0) / $price) * 1e8) / 1e8 : 0)
            : (float)($_POST['quantity'] ?? 0);

        if (!instrument_is_live($instrument)) {
            $errors[] = 'Live price for ' . $symbol . ' is unavailable right now, so trading is paused. Please try again shortly.';
        } elseif ($quantity <= 0) {
            $errors[] = 'Enter a valid amount.';
        } elseif ($action === 'buy' || $action === 'sell') {
            try {
                if ($action === 'buy') buy_asset($pdo, $user['id'], $symbol, $symbol, $instrument['asset_type'], $quantity, $price);
                else sell_asset($pdo, $user['id'], $symbol, $quantity, $price);
                $q = rtrim(rtrim(number_format($quantity, 8, '.', ''), '0'), '.');
                flash_set('success', ($action === 'buy' ? 'Bought ' : 'Sold ') . "{$q} {$symbol} at " . fmt_instrument_price($price) . '.');
                redirect('/user/instrument.php?symbol=' . urlencode($symbol));
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }
    }
}

$user = current_user();
$history = get_price_history($pdo, $symbol, 700);
[$changeAmount, $changePct] = get_24h_change($instrument);
$live = instrument_is_live($instrument);
$price = (float)$instrument['current_price'];

$stmt = $pdo->prepare('SELECT * FROM holdings WHERE user_id = ? AND symbol = ?');
$stmt->execute([$user['id'], $symbol]);
$holding = $stmt->fetch();

$prices = array_map(fn($h) => (float)$h['price'], $history);
$hi = $prices ? max($prices) : null;
$lo = $prices ? min($prices) : null;
$categoryLabels = ['stocks' => 'Stock', 'metals' => 'Metal', 'commodities' => 'Commodity', 'crypto' => 'Crypto', 'forex' => 'Forex', 'bonds' => 'Bond'];
$src = instrument_live_source($instrument['symbol'], $instrument['asset_type'], $instrument['live_symbol'] ?? null);
$up = $changePct === null ? true : $changePct >= 0;

$pageTitle = $instrument['name'] . ' (' . $symbol . ')';
$navContext = 'user';
require __DIR__ . '/../includes/header.php';
?>
<link rel="stylesheet" href="/assets/css/plans.css?v=3">
<link rel="stylesheet" href="/assets/css/markets.css?v=3">
<div class="container" style="max-width:1220px;">
  <a class="lp-back" href="/user/markets.php">&larr; All markets</a>
  <?php if ($msg = flash_get('success')): ?><div class="alert success"><?= e($msg) ?></div><?php endif; ?>
  <?php foreach ($errors as $err): ?><div class="alert error"><?= e($err) ?></div><?php endforeach; ?>

  <div class="ix-grid">
    <div class="lp-panel">
      <div class="ix-top">
        <?= asset_icon_instrument($instrument, 54) ?>
        <div style="min-width:0;">
          <h1><?= e($instrument['name']) ?></h1>
          <div class="meta">
            <span class="lp-pill"><?= e($symbol) ?></span>
            <span class="lp-pill"><?= e($categoryLabels[$instrument['asset_type']] ?? ucfirst($instrument['asset_type'])) ?></span>
            <span class="lp-live <?= $live ? '' : 'off' ?>"><i></i><?= $live ? 'Live price' : 'Price unavailable' ?></span>
          </div>
        </div>
      </div>

      <div class="ix-price">
        <span class="big"><?= fmt_instrument_price($price) ?></span>
        <?php if ($changePct !== null && $live): ?>
          <span class="lp-chg <?= $up ? 'up' : 'down' ?>" style="font-size:14px;"><?= fmt_pct($changePct) ?></span>
          <span class="mono-num <?= $up ? 'pos' : 'neg' ?>" style="font-size:14px;"><?= ($changeAmount >= 0 ? '+' : '-') . '$' . number_format(abs($changeAmount), abs($changeAmount) < 1 ? 5 : 2) ?> today</span>
        <?php endif; ?>
      </div>

      <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-top:12px;">
        <div class="lp-section-title" style="margin:0;">Price chart</div>
        <div class="ix-range" id="ixRange">
          <button type="button" data-h="24" class="on">24H</button>
          <button type="button" data-h="168">7D</button>
          <button type="button" data-h="0">All</button>
        </div>
      </div>
      <div class="ix-chart">
        <?php if (count($history) < 2): ?>
          <div class="lp-empty" style="height:100%;display:grid;place-items:center;">The chart fills in as live prices arrive.</div>
        <?php else: ?>
          <canvas id="ixChart"></canvas>
        <?php endif; ?>
      </div>

      <div class="ix-stats">
        <div class="lp-stat"><span>Prev. close</span><b><?= $instrument['price_24h_ago'] ? fmt_instrument_price((float)$instrument['price_24h_ago']) : '—' ?></b></div>
        <div class="lp-stat"><span>Chart high</span><b><?= $hi !== null ? fmt_instrument_price($hi) : '—' ?></b></div>
        <div class="lp-stat"><span>Chart low</span><b><?= $lo !== null ? fmt_instrument_price($lo) : '—' ?></b></div>
        <div class="lp-stat"><span>Source</span><b style="font-size:12.5px;"><?= e(ucfirst($instrument['live_source'] ?? '—')) ?><?= $src['proxy'] ? ' &middot; ' . e($src['yahoo']) : '' ?></b></div>
      </div>
      <?php if ($src['proxy']): ?>
        <p class="lp-fine">This bond's price follows the real daily % moves of the <?= e($src['yahoo']) ?> US Treasury ETF.</p>
      <?php endif; ?>
    </div>

    <div style="display:flex;flex-direction:column;gap:18px;position:sticky;top:90px;">
      <div class="lp-panel">
        <div class="ix-tabs" id="ixSide">
          <button type="button" class="on buy" data-side="buy">Buy</button>
          <button type="button" class="sell" data-side="sell">Sell</button>
        </div>
        <div class="lp-form-balance"><span>Wallet</span><b><?= fmt_money($user['wallet_balance']) ?></b></div>

        <form method="post" id="ixForm">
          <?= csrf_field() ?>
          <input type="hidden" name="action" id="ixAction" value="buy">
          <input type="hidden" name="mode" id="ixModeField" value="usd">
          <div class="ix-mode" id="ixMode">
            <button type="button" class="on" data-mode="usd">Amount in $</button>
            <button type="button" data-mode="qty">Quantity</button>
          </div>
          <div class="lp-amount">
            <span class="cur" id="ixCur">$</span>
            <input type="number" id="ixInput" step="any" min="0" inputmode="decimal" placeholder="0.00" required>
          </div>
          <input type="hidden" name="amount_usd" id="ixUsd">
          <input type="hidden" name="quantity" id="ixQty">
          <div class="lp-chips" style="margin-bottom:0;">
            <button type="button" data-frac="0.25">25%</button>
            <button type="button" data-frac="0.5">50%</button>
            <button type="button" data-frac="0.75">75%</button>
            <button type="button" data-frac="1">Max</button>
          </div>
          <div class="ix-summary">
            <div><span>Price</span><b><?= fmt_instrument_price($price) ?></b></div>
            <div><span>Quantity</span><b id="ixSumQty">0</b></div>
            <div><span id="ixSumLabel">Total cost</span><b id="ixSumTotal">$0.00</b></div>
          </div>
          <button class="btn buy block" id="ixSubmit" type="submit" style="padding:14px;font-size:15px;" <?= $live ? '' : 'disabled' ?>>
            <?= $live ? 'Buy ' . e($symbol) : 'Trading paused' ?>
          </button>
          <?php if (!$live): ?><p class="lp-fine">No live price right now, so orders are paused to protect you from trading at an outdated price.</p><?php endif; ?>
        </form>
      </div>

      <div class="lp-panel">
        <div class="lp-section-title">Your position</div>
        <?php if (!$holding): ?>
          <p class="subtitle" style="margin:0;">You don't hold any <?= e($symbol) ?> yet.</p>
        <?php else:
            $qty = (float)$holding['quantity'];
            $mv = $price * $qty; $cb = (float)$holding['avg_cost'] * $qty; $pnl = $mv - $cb; $pp = $cb > 0 ? $pnl / $cb * 100 : 0; ?>
          <div class="ix-pos">
            <div class="lp-stat"><span>Quantity</span><b><?= e(rtrim(rtrim(number_format($qty, 8), '0'), '.')) ?></b></div>
            <div class="lp-stat"><span>Avg cost</span><b><?= fmt_instrument_price((float)$holding['avg_cost']) ?></b></div>
            <div class="lp-stat"><span>Value</span><b><?= fmt_money($mv) ?></b></div>
            <div class="lp-stat"><span>P&amp;L</span><b class="<?= $pnl >= 0 ? 'pos' : 'neg' ?>"><?= fmt_money($pnl) ?> <small style="font-size:11px;">(<?= fmt_pct($pp) ?>)</small></b></div>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php if (count($history) >= 2): ?>
<script src="/assets/js/chart.umd.min.js"></script>
<script>
(function () {
  var pts = <?= json_encode(array_map(fn($h) => [strtotime($h['recorded_at']) * 1000, (float)$h['price']], $history)) ?>;
  var up = <?= json_encode($up) ?>;
  var line = up ? '#34d399' : '#f87171';
  var ctx = document.getElementById('ixChart').getContext('2d');
  function grad() { var g = ctx.createLinearGradient(0, 0, 0, ctx.canvas.clientHeight || 300); g.addColorStop(0, up ? 'rgba(52,211,153,.28)' : 'rgba(248,113,113,.28)'); g.addColorStop(1, 'rgba(0,0,0,0)'); return g; }
  function slice(h) { if (!h) return pts; var cut = pts[pts.length - 1][0] - h * 3600e3; var s = pts.filter(function (p) { return p[0] >= cut; }); return s.length >= 2 ? s : pts; }
  function fmtT(ms, long) { var d = new Date(ms); return long ? d.toLocaleDateString(undefined, {month: 'short', day: 'numeric'}) + ' ' + d.toLocaleTimeString(undefined, {hour: '2-digit', minute: '2-digit'}) : d.toLocaleTimeString(undefined, {hour: '2-digit', minute: '2-digit'}); }
  function money(v) { var d = v >= 100 ? 2 : (v >= 1 ? 3 : 5); return '$' + v.toLocaleString(undefined, {minimumFractionDigits: d, maximumFractionDigits: d}); }
  var chart;
  function draw(h) {
    var s = slice(h), long = (s[s.length - 1][0] - s[0][0]) > 36 * 3600e3;
    var cfg = {
      type: 'line',
      data: { labels: s.map(function (p) { return fmtT(p[0], long); }), datasets: [{ data: s.map(function (p) { return p[1]; }), borderColor: line, backgroundColor: grad(), fill: true, tension: .35, pointRadius: 0, pointHoverRadius: 4, pointHoverBackgroundColor: line, borderWidth: 2 }] },
      options: {
        responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
        plugins: { legend: { display: false }, tooltip: { backgroundColor: '#0f1830', borderColor: '#2d4270', borderWidth: 1, titleColor: '#8592ad', bodyColor: '#e8edf7', displayColors: false, padding: 10, callbacks: { label: function (c) { return money(c.parsed.y); } } } },
        scales: {
          x: { ticks: { color: '#8592ad', maxTicksLimit: window.innerWidth < 600 ? 3 : 6, maxRotation: 0, autoSkipPadding: 18, font: { size: 11 } }, grid: { display: false }, border: { display: false } },
          y: { position: 'right', ticks: { color: '#8592ad', maxTicksLimit: window.innerWidth < 600 ? 4 : 5, font: { size: 11 }, callback: function (v) { return money(v); } }, grid: { color: 'rgba(34,50,82,.55)' }, border: { display: false } }
        }
      }
    };
    if (chart) chart.destroy();
    chart = new Chart(ctx, cfg);
  }
  draw(24);
  document.querySelectorAll('#ixRange button').forEach(function (b) {
    b.addEventListener('click', function () {
      document.querySelectorAll('#ixRange button').forEach(function (x) { x.classList.remove('on'); });
      b.classList.add('on'); draw(parseInt(b.getAttribute('data-h'), 10));
    });
  });
})();
</script>
<?php endif; ?>

<script>
(function () {
  var PRICE = <?= json_encode($price) ?>, WALLET = <?= json_encode((float)$user['wallet_balance']) ?>;
  var HELD = <?= json_encode($holding ? (float)$holding['quantity'] : 0) ?>, SYM = <?= json_encode($symbol) ?>, LIVE = <?= json_encode($live) ?>;
  var side = 'buy', mode = 'usd';
  var input = document.getElementById('ixInput');
  function fmtQ(q) { return (Math.floor(q * 1e8) / 1e8).toLocaleString(undefined, {maximumFractionDigits: 8}); }
  function money(v) { return '$' + v.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}); }
  function calc() {
    var v = parseFloat(input.value) || 0;
    var qty = mode === 'usd' ? (PRICE > 0 ? v / PRICE : 0) : v;
    var total = qty * PRICE;
    document.getElementById('ixSumQty').textContent = fmtQ(qty) + ' ' + SYM;
    document.getElementById('ixSumTotal').textContent = money(total);
    document.getElementById('ixSumLabel').textContent = side === 'buy' ? 'Total cost' : 'You receive';
    document.getElementById('ixUsd').value = mode === 'usd' ? v : '';
    document.getElementById('ixQty').value = mode === 'qty' ? v : '';
    var over = side === 'buy' ? total > WALLET + 0.001 : qty > HELD + 1e-9;
    document.getElementById('ixSumTotal').style.color = over ? 'var(--negative)' : '';
  }
  document.querySelectorAll('#ixSide button').forEach(function (b) {
    b.addEventListener('click', function () {
      side = b.getAttribute('data-side');
      document.querySelectorAll('#ixSide button').forEach(function (x) { x.classList.toggle('on', x === b); });
      document.getElementById('ixAction').value = side;
      var s = document.getElementById('ixSubmit');
      s.className = 'btn block ' + side;
      if (LIVE) s.textContent = (side === 'buy' ? 'Buy ' : 'Sell ') + SYM;
      calc();
    });
  });
  document.querySelectorAll('#ixMode button').forEach(function (b) {
    b.addEventListener('click', function () {
      mode = b.getAttribute('data-mode');
      document.querySelectorAll('#ixMode button').forEach(function (x) { x.classList.toggle('on', x === b); });
      document.getElementById('ixModeField').value = mode;
      document.getElementById('ixCur').textContent = mode === 'usd' ? '$' : '#';
      input.value = ''; calc();
    });
  });
  document.querySelectorAll('#ixForm .lp-chips button').forEach(function (b) {
    b.addEventListener('click', function () {
      var f = parseFloat(b.getAttribute('data-frac'));
      if (side === 'buy') { var usd = Math.floor(WALLET * f * 100) / 100; input.value = mode === 'usd' ? usd.toFixed(2) : (Math.floor(usd / PRICE * 1e8) / 1e8); }
      else { var q = Math.floor(HELD * f * 1e8) / 1e8; input.value = mode === 'qty' ? q : (Math.floor(q * PRICE * 100) / 100).toFixed(2); }
      calc();
    });
  });
  input.addEventListener('input', calc);
  calc();
})();
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
