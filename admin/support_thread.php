<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$admin = require_admin();
$pdo = get_db();
$errors = [];

$threadId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

$stmt = $pdo->prepare(
    'SELECT t.*, u.full_name, u.email, u.id AS user_id FROM support_threads t JOIN users u ON u.id = t.user_id WHERE t.id = ?'
);
$stmt->execute([$threadId]);
$thread = $stmt->fetch();

if (!$thread) {
    flash_set('error', 'Conversation not found.');
    redirect('/admin/support.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $action = $_POST['action'] ?? 'reply';

    if ($action === 'reply') {
        $message = trim($_POST['message'] ?? '');
        if ($message === '') {
            $errors[] = 'Enter a message.';
        } else {
            post_support_message($pdo, $threadId, 'admin', $message);
            log_admin_action($pdo, (int)$admin['id'], 'support_reply', 'Replied on support thread #' . $threadId);
            redirect('/admin/support_thread.php?id=' . $threadId);
        }
    } elseif ($action === 'close') {
        $pdo->prepare("UPDATE support_threads SET status = 'closed' WHERE id = ?")->execute([$threadId]);
        log_admin_action($pdo, (int)$admin['id'], 'support_close', 'Closed support thread #' . $threadId);
        redirect('/admin/support_thread.php?id=' . $threadId);
    } elseif ($action === 'reopen') {
        $pdo->prepare("UPDATE support_threads SET status = 'open' WHERE id = ?")->execute([$threadId]);
        log_admin_action($pdo, (int)$admin['id'], 'support_reopen', 'Reopened support thread #' . $threadId);
        redirect('/admin/support_thread.php?id=' . $threadId);
    }
}

// Viewing the thread marks user messages as read by admin.
$pdo->prepare('UPDATE support_threads SET unread_by_admin = 0 WHERE id = ?')->execute([$threadId]);

$stmt = $pdo->prepare('SELECT * FROM support_messages WHERE thread_id = ? ORDER BY created_at ASC');
$stmt->execute([$threadId]);
$messages = $stmt->fetchAll();

$pageTitle = $thread['subject'];
$navContext = 'admin';
require __DIR__ . '/../includes/header.php';
?>
<div class="container">
  <p style="display:flex; justify-content:space-between; align-items:center;">
    <a href="/admin/support.php">&larr; Back to inbox</a>
    <a href="/admin/livechat.php" class="btn small" style="background:#10b981;">Open in Live Chat Console &rarr;</a>
  </p>
  <div class="card">
    <h2><?= e($thread['subject']) ?></h2>
    <p class="subtitle">
      <?= e($thread['full_name']) ?> &middot; <?= e($thread['email']) ?> &middot;
      <?php if ($thread['status'] === 'open'): ?><span class="badge green">Open</span>
      <?php else: ?><span class="badge">Closed</span><?php endif; ?>
    </p>

    <?php foreach ($errors as $err): ?><div class="alert error"><?= e($err) ?></div><?php endforeach; ?>

    <div class="chat-thread" style="display:flex; flex-direction:column; gap:12px; margin:16px 0;">
      <?php foreach ($messages as $m): ?>
        <div style="max-width:80%; <?= $m['sender_type'] === 'user' ? 'align-self:flex-start;' : 'align-self:flex-end;' ?> background:<?= $m['sender_type'] === 'user' ? 'var(--card)' : 'var(--accent-soft, #1c2a3a)' ?>; border:1px solid var(--border); border-radius:10px; padding:10px 14px;">
          <div class="subtitle" style="font-weight:600; margin-bottom:4px;"><?= e($m['sender_type'] === 'user' ? $thread['full_name'] : $m['sender_label']) ?></div>
          <div style="white-space:pre-wrap;"><?= e($m['message']) ?></div>
          <div class="subtitle" style="font-size:11px; margin-top:6px;"><?= e(time_ago($m['created_at'])) ?></div>
        </div>
      <?php endforeach; ?>
    </div>

    <?php if ($thread['status'] === 'open'): ?>
      <form method="post" style="border-top:1px solid var(--border); padding-top:16px;">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int)$thread['id'] ?>">
        <input type="hidden" name="action" value="reply">
        <div class="form-group">
          <label>Reply</label>
          <textarea name="message" rows="4" required></textarea>
        </div>
        <button class="btn block" type="submit">Send reply</button>
      </form>
      <form method="post" style="margin-top:10px;" onsubmit="return confirm('Mark this conversation as closed?');">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int)$thread['id'] ?>">
        <input type="hidden" name="action" value="close">
        <button class="btn small secondary" type="submit">Close conversation</button>
      </form>
    <?php else: ?>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int)$thread['id'] ?>">
        <input type="hidden" name="action" value="reopen">
        <button class="btn small secondary" type="submit">Reopen conversation</button>
      </form>
    <?php endif; ?>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
