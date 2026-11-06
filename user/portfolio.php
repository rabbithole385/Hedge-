<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/market_data.php';

$user = require_login();
require_kyc($user);
$pdo = get_db();
process_all_due_ticks($pdo);
process_due_instrument_ticks($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'stop_copy') {
        $copyId = (int)$_POST['copy_id'];
        $stmt = $pdo->prepare("SELECT * FROM copies WHERE id = ? AND user_id = ? AND status = 'active'");
        $stmt->execute([$copyId, $user['id']]);
        $copy = $stmt->fetch();
        if ($copy) {
            $pdo->beginTransaction();
            try {
                $pdo->prepare("UPDATE copies SET status = 'stopped', stopped_at = NOW() WHERE id = ?")
                    ->execute([$copyId]);
                apply_wallet_delta($pdo, $user['id'], $copy['current_value'], 'copy_stop_return', 'Stopped copy #' . $copyId);
                $pdo->commit();
                flash_set('success', 'Copy stopped. ' . fmt_money($copy['current_value']) . ' returned to your wallet.');
            } catch (Throwable $e) {
                $pdo->rollBack();
                flash_set('error', 'Could not stop copy. Try again.');
            }
        }
    } elseif ($action === 'stop_bot') {
        $subId = (int)$_POST['sub_id'];
        $stmt = $pdo->prepare("SELECT * FROM bot_subscriptions WHERE id = ? AND user_id = ? AND status = 'active'");
        $stmt->execute([$subId, $user['id']]);
        $sub = $stmt->fetch();
        if ($sub) {
            $pdo->beginTransaction();
            try {
                $pdo->prepare("UPDATE bot_subscriptions SET status = 'stopped', stopped_at = NOW() WHERE id = ?")
                    ->execute([$subId]);
                apply_wallet_delta($pdo, $user['id'], $sub['current_value'], 'bot_stop_return', 'Stopped bot subscription #' . $subId);
                $pdo->commit();
                flash_set('success', 'Bot deactivated. ' . fmt_money($sub['current_value']) . ' returned to your wallet.');
            } catch (Throwable $e) {
                $pdo->rollBack();
                flash_set('error', 'Could not deactivate bot. Try again.');
            }
        }
    } elseif ($action === 'stop_plan') {
        $subId = (int)$_POST['sub_id'];
        $stmt = $pdo->prepare("SELECT * FROM plan_subscriptions WHERE id = ? AND user_id = ? AND status = 'active'");
        $stmt->execute([$subId, $user['id']]);
        $sub = $stmt->fetch();
        if ($sub) {
            $pdo->beginTransaction();
            try {
                $pdo->prepare("UPDATE plan_subscriptions SET status = 'stopped', stopped_at = NOW() WHERE id = ?")
                    ->execute([$subId]);
                apply_wallet_delta($pdo, $user['id'], $sub['current_value'], 'plan_stop_return', 'Stopped plan subscription #' . $subId);
                $pdo->commit();
                flash_set('success', 'Plan withdrawn. ' . fmt_money($sub['current_value']) . ' returned to your wallet.');
            } catch (Throwable $e) {
                $pdo->rollBack();
                flash_set('error', 'Could not withdraw from plan. Try again.');
            }
        }
    }
    redirect('/user/portfolio.php');
}

$stmt = $pdo->prepare(
    "SELECT c.*, e.name as expert_name, e.asset_focus, e.photo_url, e.win_rate FROM copies c
     JOIN experts e ON e.id = c.expert_id
     WHERE c.user_id = ? ORDER BY c.started_at DESC"
);
$stmt->execute([$user['id']]);
$copies = $stmt->fetchAll();

$stmt = $pdo->prepare(
    "SELECT s.*, b.name as bot_name, b.photo_url, b.tracked_markets, b.strategy, b.exposure FROM bot_subscriptions s
     JOIN bots b ON b.id = s.bot_id
     WHERE s.user_id = ? ORDER BY s.started_at DESC"
);
$stmt->execute([$user['id']]);
$botSubs = $stmt->fetchAll();

$stmt = $pdo->prepare(
    "SELECT s.*, p.name as plan_name, p.photo_url, p.tracked_markets, p.exposure FROM plan_subscriptions s
     JOIN investment_plans p ON p.id = s.plan_id
     WHERE s.user_id = ? ORDER BY s.started_at DESC"
);
$stmt->execute([$user['id']]);
$planSubs = $stmt->fetchAll();
$planQuoteKeys = [];
foreach ($botSubs as $bs) foreach (parse_market_keys($bs['tracked_markets'] ?? '') as $k) $planQuoteKeys[$k] = true;
foreach ($planSubs as $ps) foreach (parse_market_keys($ps['tracked_markets'] ?? '') as $k) $planQuoteKeys[$k] = true;
$planQuotes = get_live_quotes($pdo, array_keys($planQuoteKeys), false);

// A varied snapshot for the live market snippet - a handful of stocks, one
// metal, one commodity, a couple of crypto, so it reads as a real spread.
$snippetSymbols = ['AAPL', 'TSLA', 'NVDA', 'MSFT', 'XAUUSD', 'WTI', 'BTC', 'ETH'];
$marketSnippet = [];
foreach ($snippetSymbols as $sym) {
    $inst = get_instrument_by_symbol($pdo, $sym);
    if ($inst) $marketSnippet[] = $inst;
}

function render_avatar(?string $photoUrl, string $name): void {
    if ($photoUrl) {
        echo '<img class="position-avatar" src="' . e($photoUrl) . '" alt="">';
    } else {
        echo '<div class="position-avatar-fallback">' . e(strtoupper(substr($name, 0, 1))) . '</div>';
    }
}

function render_pnl_stat_box(float $allocated, float $current): void {
    $pnl = $current - $allocated;
    $pct = $allocated > 0 ? ($pnl / $allocated) * 100 : 0;
    $cls = $pnl >= 0 ? 'pos' : 'neg';
    ?>
    <div class="stat-box">
      <div class="stat-label">P&amp;L</div>
      <div class="stat-value <?= $cls ?>"><?= fmt_money($pnl) ?></div>
    </div>
    <div class="stat-box">
      <div class="stat-label">ROI</div>
      <div class="stat-value <?= $cls ?>"><?= fmt_pct($pct) ?></div>
    </div>
    <?php
}

$pageTitle = 'Portfolio';
$navContext = 'user';
require __DIR__ . '/../includes/header.php';
?>
<link rel="stylesheet" href="/assets/css/plans.css?v=3">
<div class="container">
  <?php if ($msg = flash_get('success')): ?><div class="alert success"><?= e($msg) ?></div><?php endif; ?>
  <?php if ($msg = flash_get('error')): ?><div class="alert error"><?= e($msg) ?></div><?php endif; ?>

  <h1>Your portfolio</h1>

  <?php if ($marketSnippet): ?>
    <div class="card" style="margin-top:20px;">
      <h3 style="display:flex; align-items:center; gap:8px; margin-top:0;"><span class="live-dot"></span> Live market</h3>
      <div class="market-widget-grid">
        <?php foreach ($marketSnippet as $m): ?>
          <a class="market-tile" href="/user/instrument.php?symbol=<?= urlencode($m['symbol']) ?>" style="display:flex;gap:10px;align-items:center;text-decoration:none;">
            <?= asset_icon_instrument($m, 32) ?>
            <div style="min-width:0;">
              <div class="symbol"><?= e($m['symbol']) ?></div>
              <div class="price"><?= fmt_instrument_price((float)$m['current_price']) ?></div>
              <?php [, $mp] = get_24h_change($m); if ($mp !== null): ?><div class="name <?= $mp >= 0 ? 'pos' : 'neg' ?>"><?= fmt_pct($mp) ?></div><?php else: ?><div class="name"><?= e($m['name']) ?></div><?php endif; ?>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <h3 style="margin-top:28px;">Copy trades</h3>
  <?php if (!$copies): ?>
    <p class="subtitle">No copy trades yet. <a href="/user/experts.php">Browse experts</a>.</p>
  <?php else: ?>
    <div class="grid auto-fill">
      <?php foreach ($copies as $c):
          $tcStmt = $pdo->prepare(
              'SELECT (SELECT COUNT(*) FROM trade_distributions WHERE copy_id = ?) +
                      (SELECT COUNT(*) FROM copy_adjustments WHERE copy_id = ?) AS total'
          );
          $tcStmt->execute([$c['id'], $c['id']]);
          $tradeCount = (int)$tcStmt->fetchColumn();
          $winRate = (float)$c['win_rate'];
      ?>
        <div class="position-card">
          <div class="position-card-header">
            <?php render_avatar($c['photo_url'], $c['expert_name']); ?>
            <div class="position-name-block">
              <div class="position-name"><?= e($c['expert_name']) ?></div>
              <div class="position-sub"><?= e(ucfirst($c['asset_focus'])) ?> <?php if ($c['status'] === 'active'): ?><span class="badge green">Active</span><?php else: ?><span class="badge gray">Stopped</span><?php endif; ?></div>
            </div>
            <div class="position-time"><?= e(time_ago($c['started_at'])) ?></div>
          </div>

          <div class="stat-box-grid">
            <div class="stat-box">
              <div class="stat-label">Investment</div>
              <div class="stat-value"><?= fmt_money($c['amount_allocated']) ?></div>
            </div>
            <div class="stat-box">
              <div class="stat-label">Current value</div>
              <div class="stat-value"><?= fmt_money($c['current_value']) ?></div>
            </div>
          </div>
          <div class="stat-box-grid">
            <?php render_pnl_stat_box((float)$c['amount_allocated'], (float)$c['current_value']); ?>
          </div>

          <div class="winrate-row">
            <span><?= $tradeCount ?> trade<?= $tradeCount === 1 ? '' : 's' ?></span>
            <span><?= number_format($winRate, 1) ?>% win rate</span>
          </div>
          <div class="winrate-bar-track">
            <div class="winrate-bar-fill" style="width: <?= max(0, min(100, $winRate)) ?>%;"></div>
          </div>

          <?php if ($c['status'] === 'active'): ?>
            <div class="position-actions">
              <form method="post" onsubmit="return confirm('Stop this copy and return funds to your wallet?');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="stop_copy">
                <input type="hidden" name="copy_id" value="<?= (int)$c['id'] ?>">
                <button class="btn block danger" type="submit">Stop copying</button>
              </form>
            </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <h3 style="margin-top:28px;">AI bots</h3>
  <?php if (!$botSubs): ?>
    <p class="subtitle">No bots activated yet. <a href="/user/bots.php">Browse AI bots</a>.</p>
  <?php else: ?>
    <div class="grid auto-fill">
      <?php foreach ($botSubs as $s): ?>
        <div class="position-card">
          <div class="position-card-header">
            <?php render_avatar($s['photo_url'], $s['bot_name']); ?>
            <div class="position-name-block">
              <div class="position-name"><?= e($s['bot_name']) ?></div>
              <div class="position-sub">AI Bot <?php if ($s['status'] === 'active'): ?><span class="badge green">Active</span><?php else: ?><span class="badge gray">Stopped</span><?php endif; ?></div>
            </div>
            <div class="position-time"><?= e(time_ago($s['started_at'])) ?></div>
          </div>
          <?php $bk = parse_market_keys($s['tracked_markets'] ?? ''); $bpos = $s['positions'] ? (json_decode($s['positions'], true) ?: []) : []; if ($bk): ?>
            <div class="lp-chiprow">
              <?php foreach ($bk as $k): $m = live_market_meta($k); $d = (int)($bpos[$k] ?? 0); ?>
                <span class="lp-chip"><?= asset_icon_market($k, 16) ?><?= e($m['short']) ?>
                  <?php if ($s['status'] === 'active' && $bpos): ?><span class="<?= $d > 0 ? 'pos' : ($d < 0 ? 'neg' : '') ?>"><?= $d > 0 ? '&#9650;' : ($d < 0 ? '&#9660;' : '&#8212;') ?></span><?php endif; ?>
                </span>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <div class="stat-box-grid">
            <div class="stat-box">
              <div class="stat-label">Investment</div>
              <div class="stat-value"><?= fmt_money($s['amount_allocated']) ?></div>
            </div>
            <div class="stat-box">
              <div class="stat-label">Current value</div>
              <div class="stat-value"><?= fmt_money($s['current_value']) ?></div>
            </div>
          </div>
          <div class="stat-box-grid">
            <?php render_pnl_stat_box((float)$s['amount_allocated'], (float)$s['current_value']); ?>
          </div>

          <?php if ($s['status'] === 'active'): ?>
            <div class="position-actions">
              <form method="post" onsubmit="return confirm('Deactivate this bot and return funds to your wallet?');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="stop_bot">
                <input type="hidden" name="sub_id" value="<?= (int)$s['id'] ?>">
                <button class="btn block danger" type="submit">Deactivate</button>
              </form>
            </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <h3 style="margin-top:28px;">Investment plans</h3>
  <?php if (!$planSubs): ?>
    <p class="subtitle">No investment plans yet. <a href="/user/plans.php">Browse plans</a>.</p>
  <?php else: ?>
    <div class="grid auto-fill">
      <?php foreach ($planSubs as $s): ?>
        <div class="position-card">
          <div class="position-card-header">
            <?php render_avatar($s['photo_url'], $s['plan_name']); ?>
            <div class="position-name-block">
              <div class="position-name"><?= e($s['plan_name']) ?></div>
              <div class="position-sub">Investment Plan <?php if ($s['status'] === 'active'): ?><span class="badge green">Active</span><?php else: ?><span class="badge gray">Stopped</span><?php endif; ?></div>
            </div>
            <div class="position-time"><?= e(time_ago($s['started_at'])) ?></div>
          </div>

          <?php $pk = parse_market_keys($s['tracked_markets'] ?? ''); if ($pk): ?>
            <div class="lp-chiprow">
              <?php foreach ($pk as $k): $q = $planQuotes[$k] ?? null; $m = live_market_meta($k); ?>
                <span class="lp-chip"><?= asset_icon_market($k, 16) ?><?= e($m['short']) ?>
                  <?php if ($q && $q['change_pct'] !== null): ?><span class="<?= $q['change_pct'] >= 0 ? 'pos' : 'neg' ?>"><?= fmt_pct($q['change_pct']) ?></span><?php endif; ?>
                </span>
              <?php endforeach; ?>
              <?php if ((float)($s['exposure'] ?? 1) != 1.0): ?><span class="lp-chip"><?= rtrim(rtrim(number_format((float)$s['exposure'], 2), '0'), '.') ?>x</span><?php endif; ?>
            </div>
          <?php endif; ?>
          <div class="stat-box-grid">
            <div class="stat-box">
              <div class="stat-label">Investment</div>
              <div class="stat-value"><?= fmt_money($s['amount_allocated']) ?></div>
            </div>
            <div class="stat-box">
              <div class="stat-label">Current value</div>
              <div class="stat-value"><?= fmt_money($s['current_value']) ?></div>
            </div>
          </div>
          <div class="stat-box-grid">
            <?php render_pnl_stat_box((float)$s['amount_allocated'], (float)$s['current_value']); ?>
          </div>
          <?php if ($s['status'] === 'active' && $pk): ?>
            <div class="position-sub" style="margin:-4px 0 4px;">
              <?= $s['last_tick_at'] ? 'Last market update ' . e(time_ago($s['last_tick_at'])) : 'Locking in live entry prices&hellip;' ?>
            </div>
          <?php endif; ?>

          <?php if ($s['status'] === 'active'): ?>
            <div class="position-actions">
              <form method="post" onsubmit="return confirm('Withdraw from this plan and return funds to your wallet?');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="stop_plan">
                <input type="hidden" name="sub_id" value="<?= (int)$s['id'] ?>">
                <button class="btn block danger" type="submit">Withdraw</button>
              </form>
            </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>