<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$admin = require_admin();
$pdo = get_db();
process_all_due_ticks($pdo);

$totalUsers = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$totalExperts = (int)$pdo->query("SELECT COUNT(*) FROM experts WHERE status='active'")->fetchColumn();
$totalWallets = (float)$pdo->query('SELECT COALESCE(SUM(wallet_balance),0) FROM users')->fetchColumn();
$totalCopiedActive = (float)$pdo->query("SELECT COALESCE(SUM(current_value),0) FROM copies WHERE status='active'")->fetchColumn();
$totalBotsActive = (float)$pdo->query("SELECT COALESCE(SUM(current_value),0) FROM bot_subscriptions WHERE status='active'")->fetchColumn();
$totalPlansActive = (float)$pdo->query("SELECT COALESCE(SUM(current_value),0) FROM plan_subscriptions WHERE status='active'")->fetchColumn();
$pendingDeposits = (int)$pdo->query("SELECT COUNT(*) FROM crypto_deposits WHERE status='pending'")->fetchColumn()
    + (int)$pdo->query("SELECT COUNT(*) FROM bank_deposits WHERE status='pending'")->fetchColumn();
$pendingKyc = (int)$pdo->query("SELECT COUNT(*) FROM kyc_submissions WHERE status='pending'")->fetchColumn();
$totalTrades = (int)$pdo->query('SELECT COUNT(*) FROM trades')->fetchColumn();

$recentTrades = $pdo->query(
    "SELECT t.*, e.name as expert_name FROM trades t JOIN experts e ON e.id = t.expert_id ORDER BY t.trade_date DESC LIMIT 10"
)->fetchAll();

$recentUsers = $pdo->query('SELECT * FROM users ORDER BY created_at DESC LIMIT 8')->fetchAll();

$pageTitle = 'Admin Dashboard';
$navContext = 'admin';
require __DIR__ . '/../includes/header.php';
?>
<div class="container">
  <div class="nx-page-head">
    <div>
      <div class="nx-eyebrow">Admin Console</div>
      <h1>Overview</h1>
      <p class="sub">Platform health, funds and activity at a glance.</p>
    </div>
    <div class="nx-head-actions">
      <a class="btn secondary" href="/admin/livechat.php">Live support</a>
      <a class="btn" href="/admin/deposits.php">Review deposits</a>
    </div>
  </div>

  <?php if ($pendingDeposits > 0): ?>
    <div class="alert info">
      You have <strong><?= $pendingDeposits ?></strong> deposit request(s) awaiting review.
      <a href="/admin/deposits.php">Review now &rarr;</a>
    </div>
  <?php endif; ?>
  <?php if ($pendingKyc > 0): ?>
    <div class="alert info">
      You have <strong><?= $pendingKyc ?></strong> identity verification submission(s) awaiting review.
      <a href="/admin/kyc.php">Review now &rarr;</a>
    </div>
  <?php endif; ?>

  <div class="grid cols-4" style="margin: 24px 0;">
    <div class="stat-card"><div class="label">Total users</div><div class="value"><?= $totalUsers ?></div></div>
    <div class="stat-card"><div class="label">Total wallet balances</div><div class="value"><?= fmt_money($totalWallets) ?></div></div>
    <div class="stat-card"><div class="label">Active experts</div><div class="value"><?= $totalExperts ?></div></div>
    <div class="stat-card"><div class="label">Pending deposits</div><div class="value"><?= $pendingDeposits ?></div></div>
  </div>
  <div class="grid cols-3" style="margin-bottom: 24px;">
    <div class="stat-card"><div class="label">Active copy-trade value</div><div class="value"><?= fmt_money($totalCopiedActive) ?></div></div>
    <div class="stat-card"><div class="label">Active bot value</div><div class="value"><?= fmt_money($totalBotsActive) ?></div></div>
    <div class="stat-card"><div class="label">Active plan value</div><div class="value"><?= fmt_money($totalPlansActive) ?></div></div>
  </div>

  <div class="nx-ai" style="margin-bottom: 24px;">
    <h3><span class="nx-live-dot"></span> Nova AI Assistant &amp; Live Support</h3>
    <p>The in-app assistant answers client questions 24/7 with a live language model, then hands the conversation to your team the moment a customer asks for a human. Step into any thread from the live support desk.</p>
    <div class="nx-ai-actions">
      <a class="btn" href="/admin/livechat.php">Open live support</a>
      <a class="btn secondary" href="/admin/support.php">Support threads</a>
    </div>
  </div>

  <div class="grid cols-2">
    <div class="card">
      <h3>Recent trades posted</h3>
      <?php if (!$recentTrades): ?>
        <p class="subtitle">No trades posted yet. <a href="/admin/trades.php">Post the first trade</a>.</p>
      <?php else: ?>
        <table>
          <thead><tr><th>Expert</th><th>Symbol</th><th>Result</th><th>Date</th></tr></thead>
          <tbody>
          <?php foreach ($recentTrades as $t): ?>
            <tr>
              <td><?= e($t['expert_name']) ?></td>
              <td><?= e($t['asset_symbol']) ?></td>
              <td class="<?= $t['profit_percent'] >= 0 ? 'pos' : 'neg' ?>"><?= fmt_pct($t['profit_percent']) ?></td>
              <td><?= e(date('M j, g:ia', strtotime($t['trade_date']))) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
    <div class="card">
      <h3>Recent users</h3>
      <table>
        <thead><tr><th>Name</th><th>Email</th><th>Balance</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($recentUsers as $u): ?>
          <tr>
            <td><?= e($u['full_name']) ?></td>
            <td><?= e($u['email']) ?></td>
            <td><?= fmt_money($u['wallet_balance']) ?></td>
            <td><span class="badge <?= $u['status']==='active'?'green':'red' ?>"><?= e(ucfirst($u['status'])) ?></span></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <p style="margin-top:14px;"><a href="/admin/users.php">Manage all users &rarr;</a></p>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
