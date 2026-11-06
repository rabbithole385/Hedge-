<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$user = require_login();
require_kyc($user);
$pdo = get_db();

$stmt = $pdo->prepare('SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 100');
$stmt->execute([$user['id']]);
$notifications = $stmt->fetchAll();

// Mark all as read now that the user has viewed this page.
$pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0')->execute([$user['id']]);

$pageTitle = 'Notifications';
$navContext = 'user';
require __DIR__ . '/../includes/header.php';
?>
<div class="container">
  <h1>Notifications</h1>
  <div class="card">
    <?php if (!$notifications): ?>
      <p class="subtitle">No notifications yet. You'll see updates here about deposits, withdrawals, verification, and referral bonuses.</p>
    <?php else: ?>
      <?php foreach ($notifications as $n): ?>
        <div style="padding:14px 0; border-bottom:1px solid var(--border);">
          <div style="display:flex; justify-content:space-between; gap:12px;">
            <strong style="color:var(--text);"><?= e($n['title']) ?></strong>
            <span class="subtitle" style="white-space:nowrap;"><?= e(time_ago($n['created_at'])) ?></span>
          </div>
          <?php if ($n['message']): ?><p class="subtitle" style="margin:6px 0 0;"><?= e($n['message']) ?></p><?php endif; ?>
          <?php if ($n['link_url']): ?><a href="<?= e($n['link_url']) ?>" style="font-size:13px;">View &rarr;</a><?php endif; ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
