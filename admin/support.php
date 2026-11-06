<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$admin = require_admin();
$pdo = get_db();

$statusFilter = $_GET['status'] ?? 'open';
$where = $statusFilter === 'closed' ? "status = 'closed'" : "status = 'open'";

$threads = $pdo->query(
    "SELECT t.*, u.full_name, u.email
     FROM support_threads t JOIN users u ON u.id = t.user_id
     WHERE t.$where
     ORDER BY t.unread_by_admin DESC, t.last_message_at DESC"
)->fetchAll();

$pageTitle = 'Support Inbox';
$navContext = 'admin';
require __DIR__ . '/../includes/header.php';
?>
<div class="container">
  <h1>Support inbox</h1>
  <p class="subtitle">Messages from users. Reply here — they'll see it in their account and get notified.</p>

  <div style="margin:12px 0; display:flex; gap:8px; align-items:center; justify-content:space-between;"><div>
    <a class="btn small <?= $statusFilter === 'open' ? '' : 'secondary' ?>" href="/admin/support.php?status=open">Open</a>
    <a class="btn small <?= $statusFilter === 'closed' ? '' : 'secondary' ?>" href="/admin/support.php?status=closed">Closed</a></div><a class="btn small" style="background:#10b981;" href="/admin/livechat.php">⚡ Launch Live Chat Console &rarr;</a>
  </div>

  <div class="card">
    <?php if (!$threads): ?>
      <p class="subtitle">Nothing here.</p>
    <?php else: ?>
      <table>
        <thead><tr><th>User</th><th>Subject</th><th>Last activity</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($threads as $t): ?>
          <tr>
            <td data-label="User"><?= e($t['full_name']) ?><br><span class="subtitle"><?= e($t['email']) ?></span></td>
            <td data-label="Subject">
              <?= e($t['subject']) ?>
              <?php if ($t['unread_by_admin']): ?><span class="badge blue">Unread</span><?php endif; ?>
            </td>
            <td data-label="Last activity"><?= e(time_ago($t['last_message_at'])) ?></td>
            <td data-label=""><a class="btn small secondary" href="/admin/support_thread.php?id=<?= (int)$t['id'] ?>">Open</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
