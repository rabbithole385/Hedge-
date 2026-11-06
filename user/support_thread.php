<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$user = require_login();
require_kyc($user);
$pdo = get_db();
$errors = [];

$threadId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

$stmt = $pdo->prepare('SELECT * FROM support_threads WHERE id = ? AND user_id = ?');
$stmt->execute([$threadId, $user['id']]);
$thread = $stmt->fetch();

if (!$thread) {
    flash_set('error', 'Conversation not found.');
    redirect('/user/support.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors[] = 'Session expired, please try again.';
    } else {
        $message = trim($_POST['message'] ?? '');
        if ($message === '') {
            $errors[] = 'Enter a message.';
        } else {
            post_support_message($pdo, $threadId, 'user', $message);
            redirect('/user/support_thread.php?id=' . $threadId);
        }
    }
}

// Viewing the thread marks admin replies as read.
$pdo->prepare('UPDATE support_threads SET unread_by_user = 0 WHERE id = ?')->execute([$threadId]);

$stmt = $pdo->prepare('SELECT * FROM support_messages WHERE thread_id = ? ORDER BY created_at ASC');
$stmt->execute([$threadId]);
$messages = $stmt->fetchAll();

$pageTitle = $thread['subject'];
$navContext = 'user';
require __DIR__ . '/../includes/header.php';
?>
<div class="container narrow">
  <p><a href="/user/support.php">&larr; Back to support</a></p>
  <div class="card">
    <h2><?= e($thread['subject']) ?></h2>
    <p class="subtitle">
      <?php if ($thread['status'] === 'open'): ?><span class="badge green">Open</span>
      <?php else: ?><span class="badge">Closed</span><?php endif; ?>
    </p>

    <?php foreach ($errors as $err): ?><div class="alert error"><?= e($err) ?></div><?php endforeach; ?>

    <div class="chat-thread" style="display:flex; flex-direction:column; gap:12px; margin:16px 0;">
      <?php foreach ($messages as $m): ?>
        <div style="max-width:80%; <?= $m['sender_type'] === 'admin' ? 'align-self:flex-start;' : 'align-self:flex-end;' ?> background:<?= $m['sender_type'] === 'admin' ? 'var(--card)' : 'var(--accent-soft, #1c2a3a)' ?>; border:1px solid var(--border); border-radius:10px; padding:10px 14px;">
          <div class="subtitle" style="font-weight:600; margin-bottom:4px;"><?= e($m['sender_label']) ?></div>
          <div style="white-space:pre-wrap;"><?= e($m['message']) ?></div>
          <div class="subtitle" style="font-size:11px; margin-top:6px;"><?= e(time_ago($m['created_at'])) ?></div>
        </div>
      <?php endforeach; ?>
    </div>

    <?php if ($thread['status'] === 'open'): ?>
      <form method="post" style="border-top:1px solid var(--border); padding-top:16px;">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int)$thread['id'] ?>">
        <div class="form-group">
          <label>Reply</label>
          <textarea name="message" rows="4" required></textarea>
        </div>
        <button class="btn block" type="submit">Send</button>
      </form>
    <?php else: ?>
      <p class="subtitle">This conversation is closed. Start a new one from the support page if you need further help.</p>
    <?php endif; ?>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
