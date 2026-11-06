<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$user = require_login();
require_kyc($user);
$pdo = get_db();
process_all_due_ticks($pdo);

$plans = $pdo->query(
    "SELECT * FROM investment_plans WHERE status = 'active' AND tracked_markets IS NOT NULL AND tracked_markets <> ''
     ORDER BY min_investment ASC"
)->fetchAll();

$allKeys = [];
foreach ($plans as $p) foreach (parse_market_keys($p['tracked_markets']) as $k) $allKeys[$k] = true;
$quotes = get_live_quotes($pdo, array_keys($allKeys));
$anyLive = (bool)array_filter($quotes);

function lp_chg_class(?float $c): string {
    if ($c === null || abs($c) < 0.005) return 'flat';
    return $c > 0 ? 'up' : 'down';
}
function lp_icon_label(string $short): string {
    return strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $short), 0, 4));
}

$pageTitle = 'Investment Plans';
$navContext = 'user';
require __DIR__ . '/../includes/header.php';
?>
<link rel="stylesheet" href="/assets/css/plans.css?v=3">
<div class="container">
  <?php if ($msg = flash_get('error')): ?><div class="alert error"><?= e($msg) ?></div><?php endif; ?>

  <section class="lp-hero">
    <div class="lp-eyebrow">
      <span class="lp-live <?= $anyLive ? '' : 'off' ?>"><i></i></span>
      Live market-linked plans
    </div>
    <h1>Invest alongside the <em>real markets</em>.</h1>
    <p class="lead">Every plan follows a basket of real-world markets such as gold, global indices, leading stocks and crypto. When those markets move, your plan moves with them, using live prices you can check anywhere.</p>
    <div class="lp-steps">
      <div class="lp-step"><div class="n">01</div><div><b>Choose a basket</b><span>Pick the plan whose markets match your outlook and risk appetite.</span></div></div>
      <div class="lp-step"><div class="n">02</div><div><b>Prices update live</b><span>Your plan value follows the real price change of its markets.</span></div></div>
      <div class="lp-step"><div class="n">03</div><div><b>Withdraw any time</b><span>Exit whenever you like and the current value returns to your wallet.</span></div></div>
    </div>
  </section>

  <?php if ($quotes): ?>
    <div class="lp-tape" id="lpTape">
      <?php foreach ($quotes as $k => $q): if (!$q) continue; $cls = lp_chg_class($q['change_pct']); ?>
        <div class="lp-tick" data-key="<?= e($k) ?>">
          <?= asset_icon_market($k, 22) ?><span class="sym"><?= e($q['short']) ?></span>
          <span class="px" data-px><?= fmt_market_price($q['price'], $q['decimals']) ?></span>
          <span class="lp-chg <?= $cls ?>" data-chg><?= $q['change_pct'] === null ? '—' : fmt_pct($q['change_pct']) ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if (!$plans): ?>
    <div class="lp-empty">
      <h3 style="color:var(--text);">New plans are on the way</h3>
      <p>There are no market-linked plans open right now. Please check back shortly.</p>
    </div>
  <?php else: ?>
    <div class="lp-grid">
      <?php foreach ($plans as $plan):
        $b = plan_basket_summary($plan, $quotes);
        $cls = lp_chg_class($b['change_24h']);
        $risk = in_array($plan['risk_level'], ['low','medium','high'], true) ? $plan['risk_level'] : 'medium';
      ?>
        <article class="lp-card risk-<?= e($risk) ?>">
          <div class="lp-card-top">
            <?php if (!empty($plan['photo_url'])): ?>
              <img class="lp-avatar" src="<?= e($plan['photo_url']) ?>" alt="">
            <?php else: ?>
              <div class="lp-avatar fallback"><?= e(strtoupper(substr($plan['name'], 0, 1))) ?></div>
            <?php endif; ?>
            <div class="lp-card-title">
              <h3><?= e($plan['name']) ?></h3>
              <div class="lp-pills">
                <span class="lp-pill risk"><?= e(ucfirst($risk)) ?> risk</span>
                <span class="lp-pill"><?= count($b['keys']) ?> market<?= count($b['keys']) === 1 ? '' : 's' ?></span>
                <?php if ($b['exposure'] != 1.0): ?><span class="lp-pill"><?= rtrim(rtrim(number_format($b['exposure'], 2), '0'), '.') ?>x exposure</span><?php endif; ?>
              </div>
            </div>
          </div>

          <div class="lp-chart">
            <div class="lp-chart-head">
              <div class="lp-chart-label">
                <div class="big <?= $cls ?>"><?= $b['change_24h'] === null ? '—' : fmt_pct($b['change_24h']) ?></div>
                <small>Basket &middot; 24h</small>
              </div>
              <div class="lp-chart-live"><span class="lp-live <?= $b['complete'] ? '' : 'off' ?>"><i></i><?= $b['complete'] ? 'Live' : 'Waiting for prices' ?></span></div>
            </div>
            <?= render_sparkline_svg($b['series'], 320, 74, $b['change_24h'] === null ? null : $b['change_24h'] >= 0, 'p' . $plan['id']) ?>
          </div>

          <div class="lp-card-body">
            <?php if (!empty($plan['description'])): ?><p class="lp-desc"><?= e($plan['description']) ?></p><?php endif; ?>

            <div class="lp-markets">
              <?php foreach ($b['items'] as $it): $q = $it['quote']; $m = $it['meta']; ?>
                <div class="lp-mrow" data-key="<?= e($it['key']) ?>">
                  <div class="lp-mname">
                    <?= asset_icon_market($it['key'], 30) ?>
                    <div style="min-width:0;"><b><?= e($m['name']) ?></b><small><?= e($m['short']) ?></small></div>
                  </div>
                  <div class="lp-mpx" data-px><?= $q ? fmt_market_price($q['price'], $q['decimals']) : '—' ?></div>
                  <span class="lp-chg <?= lp_chg_class($q['change_pct'] ?? null) ?>" data-chg><?= ($q && $q['change_pct'] !== null) ? fmt_pct($q['change_pct']) : '—' ?></span>
                </div>
              <?php endforeach; ?>
            </div>

            <div class="lp-stats">
              <div class="lp-stat"><span>Minimum</span><b><?= fmt_money($plan['min_investment']) ?></b></div>
              <div class="lp-stat"><span>Updates</span><b><?= (int)$plan['tick_interval_minutes'] ?>m</b></div>
              <div class="lp-stat"><span>Exposure</span><b><?= rtrim(rtrim(number_format($b['exposure'], 2), '0'), '.') ?>x</b></div>
            </div>

            <a class="btn gold block" href="/user/plan_invest.php?plan_id=<?= (int)$plan['id'] ?>">Invest in <?= e($plan['name']) ?></a>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="lp-note">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 8v5M12 16h.01"/></svg>
    <div>Plan values follow live prices from public market-data providers (Binance, Yahoo Finance, Stooq). Markets can fall as well as rise, so your plan can lose value. Exposure multiplies both gains and losses. Prices for stocks, indices and commodities only move while their markets are open. Past performance is not a reliable indicator of future results.</div>
  </div>
</div>

<script src="/assets/js/plans-live.js?v=2" defer></script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
