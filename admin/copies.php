<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$admin = require_admin();
$pdo = get_db();
$errors = [];
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors[] = 'Session expired, please try again.';
    } else {
        $copyId = (int)($_POST['copy_id'] ?? 0);
        $amount = (float)($_POST['amount'] ?? 0);
        $note = trim($_POST['note'] ?? '');

        if ($amount == 0) {
            $errors[] = 'Enter a non-zero amount (positive to add profit, negative to subtract).';
        } else {
            try {
                adjust_copy_value($pdo, $copyId, $amount, $note ?: null);
                log_admin_action($pdo, (int)$admin['id'], 'adjust_copy', 'Copy #' . $copyId . ' adjusted by ' . fmt_money($amount) . ($note ? ' - ' . $note : ''));
                $success = 'Adjustment applied: ' . fmt_money($amount) . ' on copy #' . $copyId . '. Reflected in the user\'s P&L immediately.';
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }
    }
}

$copies = $pdo->query(
    "SELECT c.*, e.name as expert_name, u.full_name, u.email FROM copies c
     JOIN experts e ON e.id = c.expert_id
     JOIN users u ON u.id = c.user_id
     WHERE c.status = 'active'
     ORDER BY c.started_at DESC"
)->fetchAll();

$pageTitle = 'Adjust Copy P&L';
$navContext = 'admin';
require __DIR__ . '/../includes/header.php';
?>
<div class="container">
  <h1>Adjust copy P&amp;L</h1>
  <p class="subtitle">
    This nudges one specific user's copy position directly &mdash; different from "Post Trade", which broadcasts a result to every copier of an expert.
    Win rate and average return still update automatically as trades are posted for the expert; this only affects the individual copy's value and that user's wallet.
  </p>

  <?php if ($success): ?><div class="alert success"><?= e($success) ?></div><?php endif; ?>
  <?php foreach ($errors as $err): ?><div class="alert error"><?= e($err) ?></div><?php endforeach; ?>

  <div class="card">
    <table>
      <thead><tr><th>User</th><th>Expert</th><th>Allocated</th><th>Current value</th><th>Adjustment</th></tr></thead>
      <tbody>
      <?php foreach ($copies as $c): ?>
        <tr>
          <td data-label="User"><?= e($c['full_name']) ?><br><span class="subtitle"><?= e($c['email']) ?></span></td>
          <td data-label="Expert"><?= e($c['expert_name']) ?></td>
          <td data-label="Allocated"><?= fmt_money($c['amount_allocated']) ?></td>
          <td data-label="Current value" class="mono-num"><?= fmt_money($c['current_value']) ?></td>
          <td data-label="Adjustment">
            <form method="post" style="display:flex; gap:6px; flex-wrap:wrap; align-items:center;">
              <?= csrf_field() ?>
              <input type="hidden" name="copy_id" value="<?= (int)$c['id'] ?>">
              <input type="number" step="0.01" name="amount" placeholder="+/- amount" required style="width:120px; padding:6px 8px; border:1px solid var(--border); border-radius:8px;">
              <input type="text" name="note" placeholder="Note (optional)" style="width:140px; padding:6px 8px; border:1px solid var(--border); border-radius:8px;">
              <button class="btn small" type="submit">Apply</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$copies): ?>
        <tr><td colspan="5" class="subtitle">No active copy positions yet.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
