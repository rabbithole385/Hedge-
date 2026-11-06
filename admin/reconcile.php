<?php
/**
 * ONE-TIME BALANCE CORRECTION
 * Under the old engine every plan/bot tick credited its profit (or debited its
 * loss) to the client's WALLET and also added it to the plan/bot value. When the
 * client withdrew, that profit was paid a second time.
 *
 * For each still-active plan/bot this page finds what was already sent to the
 * wallet (transactions of type plan_profit/plan_loss, bot_profit/bot_loss) and
 * removes exactly that amount from the plan/bot value, so on withdrawal the
 * client receives each dollar of profit once. Wallets are not touched.
 * Nothing changes until an admin clicks Apply. Every change is audit-logged.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$admin = require_admin();
$pdo = get_db();

function reconcile_rows(PDO $pdo, string $kind): array {
    if ($kind === 'plan') {
        $sql = "SELECT s.id, s.user_id, s.amount_allocated, s.current_value, s.started_at, s.status, p.name AS item_name,
                       u.full_name, u.email,
                       COALESCE((SELECT SUM(t.amount) FROM transactions t WHERE t.user_id = s.user_id
                                 AND t.type IN ('plan_profit','plan_loss') AND t.reference = CONCAT('Investment plan #', s.id)), 0) AS credited
                FROM plan_subscriptions s JOIN investment_plans p ON p.id = s.plan_id JOIN users u ON u.id = s.user_id
                WHERE s.legacy_reconciled = 0";
    } else {
        $sql = "SELECT s.id, s.user_id, s.amount_allocated, s.current_value, s.started_at, s.status, b.name AS item_name,
                       u.full_name, u.email,
                       COALESCE((SELECT SUM(t.amount) FROM transactions t WHERE t.user_id = s.user_id
                                 AND t.type IN ('bot_profit','bot_loss') AND t.reference = CONCAT('Bot subscription #', s.id)), 0) AS credited
                FROM bot_subscriptions s JOIN bots b ON b.id = s.bot_id JOIN users u ON u.id = s.user_id
                WHERE s.legacy_reconciled = 0";
    }
    $out = ['active' => [], 'stopped' => []];
    foreach ($pdo->query($sql)->fetchAll() as $r) {
        if (abs((float)$r['credited']) < 0.005) continue;
        $r['kind'] = $kind;
        $r['corrected'] = max(0, round((float)$r['current_value'] - (float)$r['credited'], 2));
        $out[$r['status'] === 'active' ? 'active' : 'stopped'][] = $r;
    }
    return $out;
}

$msg = null; $err = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $err = 'Session expired, please try again.';
    } elseif (($_POST['confirm'] ?? '') !== 'CORRECT') {
        $err = 'Type CORRECT in the box to confirm.';
    } else {
        $done = 0; $total = 0.0;
        $pdo->beginTransaction();
        try {
            foreach (['plan', 'bot'] as $kind) {
                $table = $kind === 'plan' ? 'plan_subscriptions' : 'bot_subscriptions';
                foreach (reconcile_rows($pdo, $kind)['active'] as $r) {
                    $pdo->prepare("UPDATE {$table} SET current_value = ?, legacy_reconciled = 1 WHERE id = ? AND status = 'active' AND legacy_reconciled = 0")
                        ->execute([$r['corrected'], $r['id']]);
                    log_admin_action($pdo, (int)$admin['id'], 'legacy_balance_correction',
                        "{$kind} #{$r['id']} ({$r['item_name']}) user #{$r['user_id']}: value " . number_format((float)$r['current_value'], 2)
                        . ' -> ' . number_format($r['corrected'], 2) . ' (already credited to wallet: ' . number_format((float)$r['credited'], 2) . ')');
                    $done++; $total += (float)$r['current_value'] - $r['corrected'];
                }
            }
            $pdo->commit();
            $msg = "Corrected {$done} position" . ($done === 1 ? '' : 's') . '. Total removed from plan/bot values: ' . fmt_money($total) . '. All changes are in the Audit Log.';
        } catch (Throwable $e) {
            $pdo->rollBack();
            $err = 'Nothing was changed: ' . $e->getMessage();
        }
    }
}

$plans = reconcile_rows($pdo, 'plan');
$bots = reconcile_rows($pdo, 'bot');
$active = array_merge($plans['active'], $bots['active']);
$stopped = array_merge($plans['stopped'], $bots['stopped']);
$sumRemove = array_sum(array_map(fn($r) => (float)$r['current_value'] - $r['corrected'], $active));
$sumStoppedOverpaid = array_sum(array_map(fn($r) => max(0, (float)$r['credited']), $stopped));

$pageTitle = 'Balance Correction';
$navContext = 'admin';
require __DIR__ . '/../includes/header.php';
?>
<link rel="stylesheet" href="/assets/css/plans.css?v=3">
<div class="container" style="max-width:1240px;">
  <h1>Balance correction</h1>
  <p class="subtitle" style="max-width:820px;">Under the old engine, plan and bot profits were paid into the wallet on every update <em>and</em> kept inside the plan or bot value, so a withdrawal paid them a second time. This tool removes what each active position already paid into the wallet, so every dollar of profit is paid once. Wallet balances are not touched.</p>

  <?php if ($msg): ?><div class="alert success"><?= e($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="alert error"><?= e($err) ?></div><?php endif; ?>

  <div class="grid cols-3" style="margin:18px 0 22px;">
    <div class="stat-card"><div class="label">Active positions to correct</div><div class="value mono-num"><?= count($active) ?></div></div>
    <div class="stat-card"><div class="label">Total to remove from values</div><div class="value mono-num"><?= fmt_money($sumRemove) ?></div></div>
    <div class="stat-card"><div class="label">Already over-paid (closed)</div><div class="value mono-num"><?= fmt_money($sumStoppedOverpaid) ?></div></div>
  </div>

  <div class="card" style="margin-bottom:20px;">
    <h3>Preview</h3>
    <?php if (!$active): ?>
      <p class="subtitle">Nothing to correct. No active plan or bot has old wallet credits.</p>
    <?php else: ?>
      <div class="table-scroll">
        <table>
          <thead><tr><th>Client</th><th>Position</th><th>Invested</th><th>Value now</th><th>Already paid to wallet</th><th>Corrected value</th></tr></thead>
          <tbody>
          <?php foreach ($active as $r): ?>
            <tr>
              <td><b><?= e($r['full_name'] ?: 'User #' . $r['user_id']) ?></b><br><span class="subtitle"><?= e($r['email']) ?></span></td>
              <td><?= e($r['item_name']) ?><br><span class="badge <?= $r['kind'] === 'plan' ? 'gold' : 'blue' ?>"><?= $r['kind'] === 'plan' ? 'Plan' : 'Bot' ?> #<?= (int)$r['id'] ?></span></td>
              <td class="mono-num"><?= fmt_money((float)$r['amount_allocated']) ?></td>
              <td class="mono-num"><?= fmt_money((float)$r['current_value']) ?></td>
              <td class="mono-num <?= $r['credited'] >= 0 ? 'pos' : 'neg' ?>"><?= fmt_money((float)$r['credited']) ?></td>
              <td class="mono-num"><b><?= fmt_money($r['corrected']) ?></b></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <form method="post" style="margin-top:20px;display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;" onsubmit="return confirm('Apply the correction to <?= count($active) ?> positions? This cannot be undone automatically.');">
        <?= csrf_field() ?>
        <div class="form-group" style="margin:0;min-width:220px;">
          <label>Type CORRECT to confirm</label>
          <input type="text" name="confirm" autocomplete="off" required>
        </div>
        <button class="btn gold" type="submit">Apply correction</button>
      </form>
      <p class="subtitle" style="margin-top:12px;">Tip: let clients know before you apply this, as their plan and bot values will drop by the amounts shown. A negative "already paid" amount means past losses were taken from the wallet, so that position's value goes <em>up</em>.</p>
    <?php endif; ?>
  </div>

  <?php if ($stopped): ?>
    <div class="card">
      <h3>Already closed (information only)</h3>
      <p class="subtitle">These positions were withdrawn before the fix, so the profit was paid twice. Their value is already in the client's wallet, so this tool can't change them. Review them manually if you want to recover anything.</p>
      <div class="table-scroll">
        <table>
          <thead><tr><th>Client</th><th>Position</th><th>Invested</th><th>Paid twice</th></tr></thead>
          <tbody>
          <?php foreach ($stopped as $r): ?>
            <tr>
              <td><?= e($r['full_name'] ?: 'User #' . $r['user_id']) ?></td>
              <td><?= e($r['item_name']) ?> (<?= $r['kind'] ?> #<?= (int)$r['id'] ?>)</td>
              <td class="mono-num"><?= fmt_money((float)$r['amount_allocated']) ?></td>
              <td class="mono-num"><?= fmt_money(max(0, (float)$r['credited'])) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
