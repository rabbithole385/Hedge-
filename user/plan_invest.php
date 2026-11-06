<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$user = require_login();
require_kyc($user);
$pdo = get_db();

$planId = (int)($_GET['plan_id'] ?? $_POST['plan_id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM investment_plans WHERE id = ? AND status = 'active'");
$stmt->execute([$planId]);
$plan = $stmt->fetch();

if (!$plan || !parse_market_keys($plan['tracked_markets'] ?? '')) {
    flash_set('error', 'That plan is not available right now.');
    redirect('/user/plans.php');
}

$keys = parse_market_keys($plan['tracked_markets']);
$quotes = get_live_quotes($pdo, $keys);
$basket = plan_basket_summary($plan, $quotes);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors[] = 'Session expired, please try again.';
    } else {
        $amount = round((float)($_POST['amount'] ?? 0), 2);
        // Re-read the balance inside the request so a stale page can't overspend.
        $bal = $pdo->prepare('SELECT wallet_balance FROM users WHERE id = ?');
        $bal->execute([$user['id']]);
        $walletNow = (float)$bal->fetchColumn();

        if ($amount < (float)$plan['min_investment']) {
            $errors[] = 'Minimum investment for this plan is ' . fmt_money($plan['min_investment']) . '.';
        } elseif ($amount > $walletNow) {
            $errors[] = 'Insufficient wallet balance.';
        } else {
            // Lock in the live entry prices now, if we have fresh ones.
            $entry = [];
            foreach ($keys as $k) {
                $q = $quotes[$k] ?? null;
                if (!$q || $q['price'] === null || $q['age'] === null || $q['age'] > LIVE_QUOTE_TICK_MAX_AGE) { $entry = null; break; }
                $entry[$k] = (float)$q['price'];
            }

            $pdo->beginTransaction();
            try {
                $pdo->prepare(
                    "INSERT INTO plan_subscriptions (user_id, plan_id, amount_allocated, current_value, status, ref_prices, last_tick_at)
                     VALUES (?, ?, ?, ?, 'active', ?, " . ($entry ? 'NOW()' : 'NULL') . ")"
                )->execute([$user['id'], $plan['id'], $amount, $amount, $entry ? json_encode($entry) : null]);

                apply_wallet_delta($pdo, $user['id'], -$amount, 'plan_allocate', 'Invested in: ' . $plan['name']);
                $pdo->commit();

                flash_set('success', fmt_money($amount) . ' invested in ' . $plan['name'] . '. Your plan now moves with live market prices.');
                redirect('/user/portfolio.php');
            } catch (Throwable $e) {
                $pdo->rollBack();
                error_log('Plan invest failed: ' . $e->getMessage());
                $errors[] = 'Something went wrong. Please try again.';
            }
        }
    }
}

function lpi_chg_class(?float $c): string {
    if ($c === null || abs($c) < 0.005) return 'flat';
    return $c > 0 ? 'up' : 'down';
}

$exposure = $basket['exposure'];
$expLabel = rtrim(rtrim(number_format($exposure, 2), '0'), '.') . 'x';
$weight = $keys ? 100 / count($keys) : 0;
$hi = $basket['series'] ? max($basket['series']) : null;
$lo = $basket['series'] ? min($basket['series']) : null;
$risk = in_array($plan['risk_level'], ['low','medium','high'], true) ? $plan['risk_level'] : 'medium';
$cls = lpi_chg_class($basket['change_24h']);

$pageTitle = 'Invest in ' . $plan['name'];
$navContext = 'user';
require __DIR__ . '/../includes/header.php';
?>
<link rel="stylesheet" href="/assets/css/plans.css?v=3">
<div class="container" style="max-width:1180px;">
  <a class="lp-back" href="/user/plans.php">&larr; All plans</a>

  <div class="lp-invest">
    <!-- LEFT: plan + live basket -->
    <div class="lp-panel lp-card risk-<?= e($risk) ?>" style="padding:26px;">
      <div class="lp-headline">
        <?php if (!empty($plan['photo_url'])): ?>
          <img class="lp-avatar" src="<?= e($plan['photo_url']) ?>" alt="" style="width:60px;height:60px;">
        <?php else: ?>
          <div class="lp-avatar fallback" style="width:60px;height:60px;"><?= e(strtoupper(substr($plan['name'], 0, 1))) ?></div>
        <?php endif; ?>
        <div style="flex:1;min-width:0;">
          <h1><?= e($plan['name']) ?></h1>
          <div class="lp-pills">
            <span class="lp-pill risk"><?= e(ucfirst($risk)) ?> risk</span>
            <span class="lp-pill"><?= e($expLabel) ?> exposure</span>
            <span class="lp-live <?= $basket['complete'] ? '' : 'off' ?>" style="margin-left:4px;"><i></i><?= $basket['complete'] ? 'Live prices' : 'Waiting for prices' ?></span>
          </div>
        </div>
      </div>
      <?php if (!empty($plan['description'])): ?><p class="lp-desc"><?= e($plan['description']) ?></p><?php endif; ?>

      <div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;margin-top:20px;flex-wrap:wrap;">
        <div>
          <div class="lp-section-title" style="margin:0 0 4px;">Basket performance &middot; last 24h</div>
          <div class="lp-chart-label" style="position:static;"><div class="big <?= $cls ?>" style="font-size:34px;"><?= $basket['change_24h'] === null ? '—' : fmt_pct($basket['change_24h']) ?></div></div>
        </div>
        <?php if ($hi !== null): ?>
          <div style="text-align:right;font:500 12px var(--font-mono);color:var(--muted);line-height:1.7;">
            High <span class="pos"><?= fmt_pct($hi - 100) ?></span><br>Low <span class="neg"><?= fmt_pct($lo - 100) ?></span>
          </div>
        <?php endif; ?>
      </div>
      <div class="lp-bigchart"><?= render_sparkline_svg($basket['series'], 700, 230, $basket['change_24h'] === null ? null : $basket['change_24h'] >= 0, 'big') ?></div>
      <div class="lp-axis"><span>24h ago</span><span>12h</span><span>Now</span></div>

      <div class="lp-kpis">
        <div class="lp-stat"><span>Markets</span><b><?= count($keys) ?></b></div>
        <div class="lp-stat"><span>Exposure</span><b><?= e($expLabel) ?></b></div>
        <div class="lp-stat"><span>Updates</span><b>Every <?= (int)$plan['tick_interval_minutes'] ?>m</b></div>
        <div class="lp-stat"><span>Minimum</span><b><?= fmt_money($plan['min_investment']) ?></b></div>
      </div>

      <div class="lp-section-title">What this plan tracks</div>
      <div class="lp-markets">
        <?php foreach ($basket['items'] as $it): $q = $it['quote']; $m = $it['meta']; ?>
          <div class="lp-mrow wide" data-key="<?= e($it['key']) ?>">
            <div class="lp-mname">
              <?= asset_icon_market($it['key'], 34) ?>
              <div style="min-width:0;"><b><?= e($m['name']) ?></b><small><?= e($m['group']) ?> &middot; <?= e($m['short']) ?></small></div>
            </div>
            <div class="lp-mpx" data-px><?= $q ? fmt_market_price($q['price'], $q['decimals']) : '—' ?></div>
            <span class="lp-chg <?= lpi_chg_class($q['change_pct'] ?? null) ?>" data-chg><?= ($q && $q['change_pct'] !== null) ? fmt_pct($q['change_pct']) : '—' ?></span>
            <div class="lp-weight"><?= number_format($weight, $weight == (int)$weight ? 0 : 1) ?>%</div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- RIGHT: invest form -->
    <div class="lp-panel" style="position:sticky;top:90px;">
      <h3 style="margin-bottom:4px;">Invest in this plan</h3>
      <p class="subtitle" style="margin:0 0 18px;">Funds move from your wallet into the plan straight away.</p>

      <div class="lp-form-balance"><span>Wallet balance</span><b><?= fmt_money($user['wallet_balance']) ?></b></div>

      <?php foreach ($errors as $err): ?><div class="alert error"><?= e($err) ?></div><?php endforeach; ?>

      <form method="post" id="lpInvestForm">
        <?= csrf_field() ?>
        <input type="hidden" name="plan_id" value="<?= (int)$plan['id'] ?>">
        <label class="lp-section-title" for="lpAmount" style="display:block;">Amount (min <?= fmt_money($plan['min_investment']) ?>)</label>
        <div class="lp-amount">
          <span class="cur">$</span>
          <input type="number" step="0.01" id="lpAmount" name="amount" inputmode="decimal"
                 min="<?= (float)$plan['min_investment'] ?>" max="<?= (float)$user['wallet_balance'] ?>"
                 value="<?= e((string)($_POST['amount'] ?? number_format((float)$plan['min_investment'], 2, '.', ''))) ?>" required>
        </div>
        <div class="lp-chips">
          <button type="button" data-frac="0.25">25%</button>
          <button type="button" data-frac="0.5">50%</button>
          <button type="button" data-frac="0.75">75%</button>
          <button type="button" data-frac="1">Max</button>
        </div>

        <div class="lp-sense">
          <div class="lp-sense-row lp-sense-head"><span>If the basket moves</span><span>Your plan moves</span></div>
          <div class="lp-sense-row"><span>+1%</span><b class="pos" id="lpUp">—</b></div>
          <div class="lp-sense-row"><span>&minus;1%</span><b class="neg" id="lpDown">—</b></div>
          <div class="lp-sense-row"><span>Last 24h (<?= $basket['change_24h'] === null ? '—' : e(fmt_pct($basket['change_24h'])) ?>)</span><b id="lp24" class="<?= $cls === 'down' ? 'neg' : ($cls === 'up' ? 'pos' : '') ?>">—</b></div>
        </div>

        <button class="btn gold block" type="submit" style="padding:15px;font-size:15px;">Confirm investment</button>
      </form>

      <p class="lp-fine">These figures illustrate how real price moves would affect your plan. They are not a forecast or a guaranteed return. Your plan value can rise or fall with the markets it tracks, and <?= e($expLabel) ?> exposure applies to both gains and losses.</p>
    </div>
  </div>
</div>

<script>
(function () {
  var input = document.getElementById('lpAmount');
  var wallet = <?= json_encode((float)$user['wallet_balance']) ?>;
  var minInv = <?= json_encode((float)$plan['min_investment']) ?>;
  var exposure = <?= json_encode($exposure) ?>;
  var change24 = <?= json_encode($basket['change_24h']) ?>;
  function money(v) { var s = v < 0 ? '-' : '+'; return s + '$' + Math.abs(v).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}); }
  function update() {
    var a = parseFloat(input.value) || 0;
    document.getElementById('lpUp').textContent = money(a * exposure * 0.01);
    document.getElementById('lpDown').textContent = money(-a * exposure * 0.01);
    document.getElementById('lp24').textContent = change24 === null ? '—' : money(a * change24 / 100);
  }
  document.querySelectorAll('.lp-chips button').forEach(function (b) {
    b.addEventListener('click', function () {
      var v = Math.floor(wallet * parseFloat(b.getAttribute('data-frac')) * 100) / 100;
      input.value = Math.max(v, 0).toFixed(2);
      update();
    });
  });
  input.addEventListener('input', update);
  update();
})();
</script>
<script src="/assets/js/plans-live.js?v=2" defer></script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
