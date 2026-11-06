<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$user = require_login();
require_kyc($user);
$pdo = get_db();
process_all_due_ticks($pdo);

$bots = $pdo->query(
    "SELECT * FROM bots WHERE status = 'active' AND tracked_markets IS NOT NULL AND tracked_markets <> '' ORDER BY min_investment ASC"
)->fetchAll();
$allKeys = [];
foreach ($bots as $b) foreach (parse_market_keys($b['tracked_markets']) as $k) $allKeys[$k] = true;
$quotes = get_live_quotes($pdo, array_keys($allKeys));
$strategies = bot_strategy_labels();

// Real, realised performance per bot: average over its subscriptions of the
// summed tick returns in the last 7 days. No hindsight, only ticks that happened.
$realised = [];
foreach ($pdo->query(
    "SELECT bot_id, AVG(r) AS r, COUNT(*) AS n FROM (
        SELECT bot_id, subscription_id, SUM(profit_percent) AS r FROM bot_ticks
        WHERE created_at >= GREATEST(NOW() - INTERVAL 7 DAY,
              COALESCE((SELECT STR_TO_DATE(value, '%Y-%m-%d %H:%i:%s') FROM settings WHERE setting_key = 'bots_live_since'), NOW() - INTERVAL 7 DAY))
        GROUP BY bot_id, subscription_id
     ) x GROUP BY bot_id"
)->fetchAll() as $r) $realised[(int)$r['bot_id']] = (float)$r['r'];

function bt_pos_badge(int $d): string {
    if ($d > 0) return '<span class="bt-pos long">&#9650; LONG</span>';
    if ($d < 0) return '<span class="bt-pos short">&#9660; SHORT</span>';
    return '<span class="bt-pos flat">&#8212; FLAT</span>';
}

$pageTitle = 'AI Trading Bots';
$navContext = 'user';
require __DIR__ . '/../includes/header.php';
?>
<link rel="stylesheet" href="/assets/css/plans.css?v=3">
<link rel="stylesheet" href="/assets/css/markets.css?v=3">
<div class="container">
  <?php if ($msg = flash_get('error')): ?><div class="alert error"><?= e($msg) ?></div><?php endif; ?>

  <section class="lp-hero">
    <div class="lp-eyebrow"><span class="lp-live"><i></i></span> Rule-based bots on live markets</div>
    <h1>Let a bot <em>trade the trend</em> for you.</h1>
    <p class="lead">Each bot watches real markets and follows one clear rule: ride the trend, trade against it, or simply hold. Positions are re-checked on a fixed schedule, and every move in your balance comes from real price changes.</p>
    <div class="lp-steps">
      <div class="lp-step"><div class="n">01</div><div><b>Pick a strategy</b><span>Trend following, mean reversion or long-only.</span></div></div>
      <div class="lp-step"><div class="n">02</div><div><b>Bot takes positions</b><span>Long or short each market based on its 24h move.</span></div></div>
      <div class="lp-step"><div class="n">03</div><div><b>Real P&amp;L</b><span>Your bot's value moves with the real market, in the bot's direction.</span></div></div>
    </div>
  </section>

  <?php if (!$bots): ?>
    <div class="lp-empty"><h3 style="color:var(--text);">New bots are on the way</h3><p>No bots are open right now. Please check back shortly.</p></div>
  <?php else: ?>
    <div class="lp-grid">
      <?php foreach ($bots as $bot):
        $strat = isset($strategies[$bot['strategy']]) ? $bot['strategy'] : 'trend';
        $pos = bot_decide_positions($strat, parse_market_keys($bot['tracked_markets']), $quotes);
        $b = plan_basket_summary(['tracked_markets' => $bot['tracked_markets'], 'exposure' => 1], $quotes);
        $b['exposure'] = max(0.1, (float)$bot['exposure']);
        $bot24 = $realised[(int)$bot['id']] ?? null;
        $risk = in_array($bot['risk_level'], ['low','medium','high'], true) ? $bot['risk_level'] : 'medium';
        $cls = $bot24 === null || abs($bot24) < 0.005 ? 'flat' : ($bot24 > 0 ? 'up' : 'down');
      ?>
        <article class="lp-card risk-<?= e($risk) ?>">
          <div class="lp-card-top">
            <?php if (!empty($bot['photo_url'])): ?><img class="lp-avatar" src="<?= e($bot['photo_url']) ?>" alt="">
            <?php else: ?><div class="lp-avatar fallback"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="4" y="7" width="16" height="12" rx="3"/><path d="M12 3v4M9 12h.01M15 12h.01M9 16h6"/></svg></div><?php endif; ?>
            <div class="lp-card-title">
              <h3><?= e($bot['name']) ?></h3>
              <div class="lp-pills">
                <span class="lp-pill risk"><?= e(ucfirst($risk)) ?> risk</span>
                <span class="lp-pill"><?= e($strategies[$strat][0]) ?></span>
                <?php if ($b['exposure'] != 1.0): ?><span class="lp-pill"><?= rtrim(rtrim(number_format($b['exposure'], 2), '0'), '.') ?>x</span><?php endif; ?>
              </div>
            </div>
          </div>

          <div class="lp-chart">
            <div class="lp-chart-head">
              <div class="lp-chart-label">
                <div class="big <?= $cls ?>"><?= $bot24 === null ? 'New' : fmt_pct($bot24) ?></div>
                <small><?= $bot24 === null ? 'No realised trades yet' : 'Realised &middot; last 7 days' ?></small>
              </div>
              <div class="lp-chart-live"><span class="lp-live <?= $b['complete'] ? '' : 'off' ?>"><i></i><?= $b['complete'] ? 'Live' : 'Waiting for prices' ?></span></div>
            </div>
            <?= render_sparkline_svg($b['series'], 320, 74, null, 'b' . $bot['id']) ?>
            <div style="padding:0 22px;margin-top:-2px;font-size:10.5px;color:var(--muted);letter-spacing:.06em;text-transform:uppercase;">Markets watched &middot; 24h</div>
          </div>

          <div class="lp-card-body">
            <?php if (!empty($bot['description'])): ?><p class="lp-desc"><?= e($bot['description']) ?></p><?php endif; ?>
            <div class="lp-markets">
              <?php foreach ($b['items'] as $it): $q = $it['quote']; $m = $it['meta']; ?>
                <div class="lp-mrow" data-key="<?= e($it['key']) ?>">
                  <div class="lp-mname"><?= asset_icon_market($it['key'], 30) ?><div style="min-width:0;"><b><?= e($m['name']) ?></b><small><?= $q ? fmt_market_price($q['price'], $q['decimals']) : '—' ?></small></div></div>
                  <span class="lp-chg <?= ($q && $q['change_pct'] !== null) ? (abs($q['change_pct']) < .005 ? 'flat' : ($q['change_pct'] > 0 ? 'up' : 'down')) : 'flat' ?>" data-chg><?= ($q && $q['change_pct'] !== null) ? fmt_pct($q['change_pct']) : '—' ?></span>
                  <?= bt_pos_badge((int)($pos[$it['key']] ?? 0)) ?>
                </div>
              <?php endforeach; ?>
            </div>
            <div class="lp-stats">
              <div class="lp-stat"><span>Minimum</span><b><?= fmt_money($bot['min_investment']) ?></b></div>
              <div class="lp-stat"><span>Re-checks</span><b><?= (int)$bot['tick_interval_minutes'] ?>m</b></div>
              <div class="lp-stat"><span>Exposure</span><b><?= rtrim(rtrim(number_format($b['exposure'], 2), '0'), '.') ?>x</b></div>
            </div>
            <a class="btn gold block" href="/user/bot_subscribe.php?bot_id=<?= (int)$bot['id'] ?>">Activate <?= e($bot['name']) ?></a>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="lp-note">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 8v5M12 16h.01"/></svg>
    <div>Bots follow fixed rules on live prices from Binance, Yahoo Finance and Stooq. They can and do lose money. A short position loses when its market rises, and exposure multiplies gains and losses alike. The realised figure is the average actual result of this bot's live positions over the last 7 days. Past results are not a forecast.</div>
  </div>
</div>
<script src="/assets/js/plans-live.js?v=3" defer></script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
