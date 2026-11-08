<?php
/** Single message view with reply / star / trash actions. */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/mail_client.php';
require_once __DIR__ . '/partials.php';

$account = mail_require_login();
$pdo     = get_db();

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM mail_messages WHERE id = ?');
$stmt->execute([$id]);
$m = $stmt->fetch();

if (!$m) {
    flash_set('error', 'That message no longer exists.');
    redirect('/mail/');
}

if ((int)$m['is_read'] === 0) {
    $pdo->prepare('UPDATE mail_messages SET is_read = 1 WHERE id = ?')->execute([$id]);
    $m['is_read'] = 1;
}

$isSent = ($m['direction'] === 'out');
$backFolder = $isSent ? 'sent' : ($m['folder'] === 'trash' ? 'trash' : 'inbox');
$back = '/mail/?folder=' . $backFolder;

$who   = $isSent ? (string)($m['to_email'] ?: '-') : ((string)($m['from_name'] !== '' ? $m['from_name'] . ' <' . $m['from_email'] . '>' : $m['from_email']));
$bodyHtml = trim((string)$m['body_html']);
$bodyText = trim((string)$m['body_text']);

mail_layout_top($account, $backFolder, (string)$m['subject'], $isSent ? 'Sent' : 'Inbox');
?>
<?php if ($msg = flash_get('success')): ?><div class="mail-alert ok"><?= e($msg) ?></div><?php endif; ?>
<?php if ($msg = flash_get('error')): ?><div class="mail-alert err"><?= e($msg) ?></div><?php endif; ?>

<a class="mail-btn ghost" href="<?= e($back) ?>" style="margin-bottom:14px;"><?= mail_icon('back', 16) ?> Back</a>

<div class="mail-msg-head">
  <h1><?= e((string)$m['subject']) ?></h1>
  <div class="mail-msg-meta">
    <span><b><?= $isSent ? 'To' : 'From' ?>:</b> <span class="em"><?= e($who) ?></span></span>
    <?php if ($isSent && $m['send_error']): ?><span class="mail-chip fail">failed</span><?php endif; ?>
    <?php if (!$isSent && $m['to_email']): ?><span><b>To:</b> <span class="em"><?= e((string)$m['to_email']) ?></span></span><?php endif; ?>
    <?php if ($m['cc']): ?><span><b>Cc:</b> <span class="em"><?= e((string)$m['cc']) ?></span></span><?php endif; ?>
    <span><b>Date:</b> <?= e(date('M j, Y H:i', strtotime((string)$m['created_at']))) ?></span>
  </div>
</div>

<?php if ($isSent && $m['send_error']): ?>
  <div class="mail-alert err" style="margin-top:14px;">Delivery failed: <?= e((string)$m['send_error']) ?></div>
<?php endif; ?>

<?php if ($bodyHtml !== ''): ?>
  <iframe class="mail-body-frame" src="/mail/raw.php?id=<?= (int)$m['id'] ?>" sandbox referrerpolicy="no-referrer" title="Message body"></iframe>
<?php elseif ($bodyText !== ''): ?>
  <div class="mail-plain"><?= e($bodyText) ?></div>
<?php else: ?>
  <div class="mail-plain" style="color:var(--muted);">This message has no body.</div>
<?php endif; ?>

<div class="mail-actions">
  <a class="mail-btn primary" href="/mail/compose.php?reply=<?= (int)$m['id'] ?>"><?= mail_icon('reply', 16) ?> Reply</a>
  <form method="post" action="/mail/action.php">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="star">
    <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
    <input type="hidden" name="back" value="/mail/message.php?id=<?= (int)$m['id'] ?>">
    <button class="mail-btn ghost" type="submit"><?= mail_icon('star', 16) ?> <?= (int)$m['is_starred'] ? 'Unstar' : 'Star' ?></button>
  </form>
  <?php if ($m['folder'] === 'trash'): ?>
    <form method="post" action="/mail/action.php">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
      <input type="hidden" name="back" value="/mail/?folder=trash">
      <button class="mail-btn danger" type="submit"><?= mail_icon('trash', 16) ?> Delete forever</button>
    </form>
  <?php else: ?>
    <form method="post" action="/mail/action.php">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="trash">
      <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
      <input type="hidden" name="back" value="<?= e($back) ?>">
      <button class="mail-btn danger" type="submit"><?= mail_icon('trash', 16) ?> Move to trash</button>
    </form>
  <?php endif; ?>
</div>
<?php
mail_layout_bottom($backFolder);
