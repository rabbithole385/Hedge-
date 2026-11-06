<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$admin = require_admin();
$pdo = get_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $action = $_POST['action'] ?? '';
    $userId = (int)($_POST['user_id'] ?? 0);

    if ($action === 'toggle_status') {
        $stmt = $pdo->prepare('SELECT status FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $current = $stmt->fetchColumn();
        $new = $current === 'active' ? 'suspended' : 'active';
        $pdo->prepare('UPDATE users SET status = ? WHERE id = ?')->execute([$new, $userId]);
        flash_set('success', 'User status updated.');
    } elseif ($action === 'adjust_balance') {
        $amount = (float)($_POST['amount'] ?? 0);
        if ($amount != 0) {
            apply_wallet_delta($pdo, $userId, $amount, $amount > 0 ? 'deposit' : 'withdraw', 'Admin manual adjustment');
            flash_set('success', 'Balance adjusted by ' . fmt_money($amount) . '.');
        }
    }
    redirect('/admin/users.php');
}

$users = $pdo->query('SELECT * FROM users ORDER BY created_at DESC')->fetchAll();

$pageTitle = 'Manage Users';
$navContext = 'admin';
require __DIR__ . '/../includes/header.php';
?>
<div class="container">
  <?php if ($msg = flash_get('success')): ?><div class="alert success"><?= e($msg) ?></div><?php endif; ?>
  <h1>Manage users</h1>
  <div class="card">
    <table>
      <thead><tr><th>Name</th><th>Email</th><th>Balance</th><th>Status</th><th>Joined</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach ($users as $u): ?>
        <tr>
          <td><?= e($u['full_name']) ?></td>
          <td><?= e($u['email']) ?></td>
          <td><?= fmt_money($u['wallet_balance']) ?></td>
          <td><span class="badge <?= $u['status']==='active'?'green':'red' ?>"><?= e(ucfirst($u['status'])) ?></span></td>
          <td><?= e(date('M j, Y', strtotime($u['created_at']))) ?></td>
          <td style="white-space:nowrap; display:flex; gap:6px; flex-wrap:wrap;">
            <form method="post" style="display:inline;">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="toggle_status">
              <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
              <button class="btn small secondary" type="submit"><?= $u['status']==='active' ? 'Suspend' : 'Activate' ?></button>
            </form>
            <form method="post" style="display:inline; display:flex; gap:4px;" onsubmit="return prepAmount(this);">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="adjust_balance">
              <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
              <input type="number" step="0.01" name="amount" placeholder="+/- amount" style="width:110px; padding:6px 8px; border:1px solid var(--border); border-radius:8px;">
              <button class="btn small secondary" type="submit">Adjust</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
