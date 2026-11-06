<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$user = require_login();
require_kyc($user);
$pdo = get_db();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors[] = 'Session expired, please try again.';
    } else {
        $subject = trim($_POST['subject'] ?? '');
        $message = trim($_POST['message'] ?? '');

        if ($subject === '') {
            $errors[] = 'Enter a subject.';
        } elseif ($message === '') {
            $errors[] = 'Enter a message.';
        } else {
            $pdo->prepare(
                'INSERT INTO support_threads (user_id, subject, status, unread_by_admin, unread_by_user) VALUES (?, ?, "open", 1, 0)'
            )->execute([$user['id'], $subject]);
            $threadId = (int)$pdo->lastInsertId();
            post_support_message($pdo, $threadId, 'user', $message, false);

            redirect('/user/support_thread.php?id=' . $threadId);
        }
    }
}

$stmt = $pdo->prepare(
    'SELECT * FROM support_threads WHERE user_id = ? ORDER BY last_message_at DESC'
);
$stmt->execute([$user['id']]);
$threads = $stmt->fetchAll();

$pageTitle = 'Support';
$navContext = 'user';
require __DIR__ . '/../includes/header.php';
?>
<div class="container narrow">
  <?php if ($msg = flash_get('success')): ?><div class="alert success"><?= e($msg) ?></div><?php endif; ?>
  <?php foreach ($errors as $err): ?><div class="alert error"><?= e($err) ?></div><?php endforeach; ?>

  <div class="card">
    <div style="display:flex; justify-content:space-between; align-items:center;"><h2>Support</h2><button type="button" class="btn small" style="background:#2563eb;" onclick="if(window.document.getElementById('hinvLauncher')) window.document.getElementById('hinvLauncher').click();">💬 Live Chat Widget</button></div>
    <p class="subtitle">Message our team directly. We'll reply here, and you'll get a notification when we do.</p>

    <?php if (!$threads): ?>
      <p class="subtitle" style="margin-top:12px;">No conversations yet — start one below.</p>
    <?php else: ?>
      <table style="margin-top:12px;">
        <thead><tr><th>Subject</th><th>Status</th><th>Last activity</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($threads as $t): ?>
          <tr>
            <td data-label="Subject">
              <?= e($t['subject']) ?>
              <?php if ($t['unread_by_user']): ?><span class="badge blue">New reply</span><?php endif; ?>
            </td>
            <td data-label="Status">
              <?php if ($t['status'] === 'open'): ?><span class="badge green">Open</span>
              <?php else: ?><span class="badge">Closed</span><?php endif; ?>
            </td>
            <td data-label="Last activity"><?= e(time_ago($t['last_message_at'])) ?></td>
            <td data-label=""><a class="btn small secondary" href="/user/support_thread.php?id=<?= (int)$t['id'] ?>">Open</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <div class="card" style="margin-top:20px;">
    <h3>Start a new conversation</h3>
    <form method="post">
      <?= csrf_field() ?>
      <div class="form-group">
        <label>Subject</label>
        <input type="text" name="subject" maxlength="191" required>
      </div>
      <div class="form-group">
        <label>Message</label>
        <textarea name="message" rows="5" required></textarea>
      </div>
      <button class="btn block" type="submit">Send to support</button>
    </form>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
