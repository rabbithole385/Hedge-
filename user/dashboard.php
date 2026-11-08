<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/market_data.php';

$user = require_login();
require_kyc($user);
$pdo = get_db();
process_due_instrument_ticks($pdo);
process_all_due_ticks($pdo);
$user = current_user(); // refresh balance after any ticks just processed

$stmt = $pdo->prepare(
    "SELECT c.*, e.name as expert_name, e.asset_focus FROM copies c
     JOIN experts e ON e.id = c.expert_id
     WHERE c.user_id = ? AND c.status = 'active'"
);
$stmt->execute([$user['id']]);
$copies = $stmt->fetchAll();

$stmt = $pdo->prepare(
    "SELECT s.*, b.name as bot_name FROM bot_subscriptions s
     JOIN bots b ON b.id = s.bot_id WHERE s.user_id = ? AND s.status = 'active'"
);
$stmt->execute([$user['id']]);
$botSubs = $stmt->fetchAll();

$stmt = $pdo->prepare(
    "SELECT s.*, p.name as plan_name FROM plan_subscriptions s
     JOIN investment_plans p ON p.id = s.plan_id WHERE s.user_id = ? AND s.status = 'active'"
);
$stmt->execute([$user['id']]);
$planSubs = $stmt->fetchAll();

$totalInvested = array_sum(array_column($copies, 'amount_allocated'))
    + array_sum(array_column($botSubs, 'amount_allocated'))
    + array_sum(array_column($planSubs, 'amount_allocated'));
$totalCurrent = array_sum(array_column($copies, 'current_value'))
    + array_sum(array_column($botSubs, 'current_value'))
    + array_sum(array_column($planSubs, 'current_value'));
$totalPnl = $totalCurrent - $totalInvested;
$pnlPct = $totalInvested > 0 ? ($totalPnl / $totalInvested) * 100 : 0;

// Watchlist: the best-known live markets this site actually lists, mixed across categories.
$watch = [];
$liveAll = array_values(array_filter(get_all_instruments($pdo), 'instrument_is_live'));
usort($liveAll, fn($a, $b) => [instrument_priority($a['symbol']), $a['symbol']] <=> [instrument_priority($b['symbol']), $b['symbol']]);
$perType = [];
foreach ($liveAll as $i) {
    if (count($watch) >= 8) break;
    $t = $i['asset_type'];
    if (($perType[$t] ?? 0) >= ($t === 'crypto' || $t === 'stocks' ? 3 : 1)) continue;
    $perType[$t] = ($perType[$t] ?? 0) + 1;
    $watch[] = $i;
}
$positions = [];
foreach ($copies as $c) $positions[] = ['Copy trade', $c['expert_name'], $c['amount_allocated'], $c['current_value'], '/user/portfolio.php'];
foreach ($botSubs as $b) $positions[] = ['AI bot', $b['bot_name'], $b['amount_allocated'], $b['current_value'], '/user/portfolio.php'];
foreach ($planSubs as $p) $positions[] = ['Plan', $p['plan_name'], $p['amount_allocated'], $p['current_value'], '/user/portfolio.php'];
$netWorth = (float)$user['wallet_balance'] + $totalCurrent;
$first = explode(' ', trim($user['full_name']))[0] ?: 'there';
$hour = (int)date('G');
$greet = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');

$pageTitle = 'Dashboard';
$navContext = 'user';
require __DIR__ . '/../includes/header.php';
?>
<link rel="stylesheet" href="/assets/css/plans.css?v=3">
<link rel="stylesheet" href="/assets/css/markets.css?v=3">
<style>
.db-hero { display:grid; grid-template-columns:minmax(0,1.4fr) minmax(0,1fr); gap:22px; align-items:center; }
@media (max-width:900px){ .db-hero { grid-template-columns:1fr; } }
.db-net { font:700 44px var(--font-mono); letter-spacing:-.03em; margin:6px 0 4px; }
@media (max-width:600px){ .db-net { font-size:32px; } }
.db-split { display:grid; grid-template-columns:repeat(3,1fr); gap:10px; }
.db-actions { display:grid; grid-template-columns:repeat(3,1fr); gap:10px; }
.db-act { display:flex; flex-direction:column; gap:8px; padding:14px; border-radius:14px; border:1px solid var(--border); background:rgba(11,18,32,.45); text-decoration:none; color:var(--text); transition:all .2s; }
.db-act:hover { border-color:rgba(212,175,106,.5); transform:translateY(-2px); }
.db-act .ic { width:36px; height:36px; border-radius:10px; display:grid; place-items:center; background:rgba(212,175,106,.12); color:var(--gold-bright); }
.db-act b { font-size:13.5px; } .db-act span { font-size:11.5px; color:var(--muted); }
.db-watch { display:grid; grid-template-columns:repeat(auto-fill,minmax(210px,1fr)); gap:12px; }
.db-cols { display:grid; grid-template-columns:minmax(0,1.3fr) minmax(0,1fr); gap:22px; margin-top:22px; }
@media (max-width:900px){ .db-cols { grid-template-columns:1fr; } }
.db-pos { display:grid; grid-template-columns:minmax(0,1fr) auto; gap:4px 12px; padding:12px 4px; border-top:1px solid var(--border); text-decoration:none; color:inherit; }
.db-pos:first-child { border-top:0; }
.db-pos b { font-size:14px; } .db-pos small { color:var(--muted); font-size:11.5px; }
.db-pos .r { text-align:right; font-family:var(--font-mono); font-size:13.5px; }
</style>
<div class="container" style="max-width:1220px;">
  <?php if ($msg = flash_get('success')): ?><div class="alert success"><?= e($msg) ?></div><?php endif; ?>
  <?php if ($msg = flash_get('error')): ?><div class="alert error"><?= e($msg) ?></div><?php endif; ?>

  <section class="lp-hero">
    <div class="db-hero">
      <div>
        <div class="lp-eyebrow"><span class="lp-live"><i></i></span> <?= e($greet) ?>, <?= e($first) ?></div>
        <div class="lp-section-title" style="margin:4px 0 0;">Total account value</div>
        <div class="db-net"><?= fmt_money($netWorth) ?></div>
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
          <span class="lp-chg <?= $totalPnl > 0.004 ? 'up' : ($totalPnl < -0.004 ? 'down' : 'flat') ?>" style="font-size:13px;"><?= fmt_pct($pnlPct) ?></span>
          <span class="mono-num <?= $totalPnl >= 0 ? 'pos' : 'neg' ?>"><?= fmt_money($totalPnl) ?> on investments</span>
        </div>
        <div class="db-split" style="margin-top:18px;">
          <div class="lp-stat"><span>Wallet</span><b><?= fmt_money($user['wallet_balance']) ?></b></div>
          <div class="lp-stat"><span>Invested</span><b><?= fmt_money($totalInvested) ?></b></div>
          <div class="lp-stat"><span>Value now</span><b><?= fmt_money($totalCurrent) ?></b></div>
        </div>
      </div>
      <div class="db-actions">
        <a class="db-act" href="/user/deposit.php"><div class="ic"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12l7 7 7-7"/></svg></div><b>Deposit</b><span>Fund wallet</span></a>
        <a class="db-act" href="/user/withdraw.php"><div class="ic"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 19V5M5 12l7-7 7 7"/></svg></div><b>Withdraw</b><span>Cash out</span></a>
        <a class="db-act" href="/user/trade.php"><div class="ic"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M7 17 17 7M8 7h9v9"/></svg></div><b>Trade</b><span>140+ markets</span></a>
        <a class="db-act" href="/user/plans.php"><div class="ic"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 17l6-6 4 4 8-8"/></svg></div><b>Plans</b><span>Market-linked</span></a>
        <a class="db-act" href="/user/bots.php"><div class="ic"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="7" width="16" height="12" rx="3"/><path d="M12 3v4M9 12h.01M15 12h.01"/></svg></div><b>AI bots</b><span>Rule-based</span></a>
        <a class="db-act" href="/user/experts.php"><div class="ic"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="8" r="3"/><path d="M3 20c0-3.3 2.7-6 6-6s6 2.7 6 6M16 11a3 3 0 1 0 0-6M21 20c0-2.6-1.6-4.8-4-5.6"/></svg></div><b>Copy</b><span>Expert traders</span></a>
      </div>
    </div>
  </section>

  <?php if ($watch): ?>
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
      <div class="lp-section-title" style="margin:0;">Watchlist</div>
      <a href="/user/markets.php" style="font-size:13px;">All markets &rarr;</a>
    </div>
    <div class="db-watch">
      <?php foreach ($watch as $w): [, $wp] = get_24h_change($w); ?>
        <a class="mx-mover" href="/user/instrument.php?symbol=<?= urlencode($w['symbol']) ?>">
          <?= asset_icon_instrument($w, 38) ?>
          <div class="t"><div class="n"><?= e($w['name']) ?></div><div class="p"><?= fmt_instrument_price((float)$w['current_price']) ?></div></div>
          <span class="lp-chg <?= $wp === null || abs($wp) < .005 ? 'flat' : ($wp > 0 ? 'up' : 'down') ?>"><?= $wp === null ? '—' : fmt_pct($wp) ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="nx-ai" style="margin-top:22px;">
    <h3><span class="nx-live-dot"></span> Need a hand? Ask Nova</h3>
    <p>Nova is your NexoraCopy AI assistant. Ask about deposits, withdrawals, KYC or copy trading at any hour, or request a live specialist and a human joins the same chat.</p>
    <div class="nx-ai-actions">
      <a class="btn" href="/user/support.php">Open support chat</a>
      <a class="btn secondary" href="/user/kyc.php">Verify identity</a>
    </div>
  </div>

  <div class="db-cols">
    <div class="lp-panel">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
        <h3 style="margin:0;">Active investments</h3>
        <a href="/user/portfolio.php" style="font-size:13px;">Portfolio &rarr;</a>
      </div>
      <?php if (!$positions): ?>
        <div class="lp-empty" style="padding:34px 16px;"><p style="margin:0 0 14px;">You have no active investments yet.</p><a class="btn gold" href="/user/plans.php">Explore investment plans</a></div>
      <?php else: foreach ($positions as [$type, $name, $alloc, $val, $link]): $pl = $val - $alloc; ?>
        <a class="db-pos" href="<?= e($link) ?>">
          <div><b><?= e($name) ?></b><br><small><?= e($type) ?> &middot; invested <?= fmt_money((float)$alloc) ?></small></div>
          <div class="r"><?= fmt_money((float)$val) ?><br><small class="<?= $pl >= 0 ? 'pos' : 'neg' ?>"><?= fmt_money($pl) ?> (<?= fmt_pct($alloc > 0 ? $pl / $alloc * 100 : 0) ?>)</small></div>
        </a>
      <?php endforeach; endif; ?>
    </div>
    <div class="lp-panel">
      <h3 style="margin:0 0 12px;">Allocation</h3>
      <?php
        $parts = [['Wallet', (float)$user['wallet_balance'], '#d4af6a'], ['Copy trades', array_sum(array_column($copies, 'current_value')), '#6d94f5'],
                  ['AI bots', array_sum(array_column($botSubs, 'current_value')), '#a78bfa'], ['Plans', array_sum(array_column($planSubs, 'current_value')), '#34d399']];
        $tot = max(0.01, array_sum(array_column($parts, 1)));
      ?>
      <div class="nx-bar" style="margin-bottom:16px;">
        <?php foreach ($parts as [$l, $v, $c]): if ($v <= 0) continue; ?><div style="width:<?= round($v / $tot * 100, 2) ?>%;background:<?= $c ?>;"></div><?php endforeach; ?>
      </div>
      <?php foreach ($parts as [$l, $v, $c]): ?>
        <div style="display:flex;justify-content:space-between;align-items:center;padding:9px 0;border-top:1px solid var(--border);font-size:13.5px;">
          <span style="display:flex;align-items:center;gap:9px;"><i style="width:10px;height:10px;border-radius:3px;background:<?= $c ?>;display:inline-block;"></i><?= e($l) ?></span>
          <span class="mono-num"><?= fmt_money($v) ?> <small style="color:var(--muted);margin-left:6px;"><?= number_format($v / $tot * 100, 1) ?>%</small></span>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
