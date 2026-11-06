<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$user = require_login();
require_kyc($user);
$pdo = get_db();

$stmt = $pdo->prepare('SELECT * FROM transactions WHERE user_id = ? ORDER BY created_at DESC LIMIT 200');
$stmt->execute([$user['id']]);
$txns = $stmt->fetchAll();

$typeLabels = [
    'deposit' => ['Deposit', 'blue'],
    'withdraw' => ['Withdrawal', 'gray'],
    'copy_allocate' => ['Copy allocation', 'blue'],
    'copy_stop_return' => ['Copy stopped (return)', 'blue'],
    'profit' => ['Trade profit', 'green'],
    'loss' => ['Trade loss', 'red'],
    'bot_allocate' => ['Bot activated', 'blue'],
    'bot_stop_return' => ['Bot deactivated (return)', 'blue'],
    'bot_profit' => ['Bot profit', 'green'],
    'bot_loss' => ['Bot loss', 'red'],
    'plan_allocate' => ['Plan investment', 'blue'],
    'plan_stop_return' => ['Plan withdrawn (return)', 'blue'],
    'plan_profit' => ['Plan profit', 'green'],
    'plan_loss' => ['Plan loss', 'red'],
];

$pageTitle = 'Transactions';
$navContext = 'user';
require __DIR__ . '/../includes/header.php';
?>
<div class="container">
  <h1>Transaction history</h1>
  <div class="card">
    <?php if (!$txns): ?>
      <p class="subtitle">No transactions yet.</p>
    <?php else: ?>
      <table>
        <thead><tr><th>Date</th><th>Type</th><th>Amount</th><th>Balance after</th></tr></thead>
        <tbody>
        <?php foreach ($txns as $t):
            [$label, $badge] = $typeLabels[$t['type']] ?? [$t['type'], 'gray'];
        ?>
          <tr>
            <td><?= e(date('M j, Y g:ia', strtotime($t['created_at']))) ?></td>
            <td><span class="badge <?= $badge ?>"><?= e($label) ?></span></td>
            <td class="<?= $t['amount'] >= 0 ? 'pos' : 'neg' ?>"><?= fmt_money($t['amount']) ?></td>
            <td><?= fmt_money($t['balance_after']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
