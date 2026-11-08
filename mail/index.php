<?php
/**
 * Mail list: inbox, starred, sent and trash, with search.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/mail_client.php';
require_once __DIR__ . '/partials.php';

$account = mail_require_login();
$pdo     = get_db();

$folder = strtolower((string)($_GET['folder'] ?? 'inbox'));
if (!in_array($folder, ['inbox', 'starred', 'sent', 'trash'], true)) {
    $folder = 'inbox';
}
$q = trim((string)($_GET['q'] ?? ''));

$params = [];
if ($folder === 'starred') {
    $where = 'is_starred = 1 AND folder <> "trash"';
} else {
    $where    = 'folder = ?';
    $params[] = $folder;
}
if ($q !== '') {
    $where .= ' AND (subject LIKE ? OR from_email LIKE ? OR from_name LIKE ? OR to_email LIKE ? OR snippet LIKE ?)';
    $like = '%' . $q . '%';
    for ($i = 0; $i < 5; $i++) $params[] = $like;
}

$stmt = $pdo->prepare("SELECT * FROM mail_messages WHERE $where ORDER BY created_at DESC, id DESC LIMIT 200");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$titles = ['inbox' => 'Inbox', 'starred' => 'Starred', 'sent' => 'Sent', 'trash' => 'Trash'];
$heading = $titles[$folder];
$sub = count($rows) . ' ' . (count($rows) === 1 ? 'message' : 'messages') . ($q !== '' ? ' matching "' . $q . '"' : '');

$back = '/mail/?folder=' . rawurlencode($folder) . ($q !== '' ? '&q=' . rawurlencode($q) : '');

mail_layout_top($account, $folder, $heading, $sub, $q);
?>
<?php if ($msg = flash_get('success')): ?><div class="mail-alert ok"><?= e($msg) ?></div><?php endif; ?>
<?php if ($msg = flash_get('error')): ?><div class="mail-alert err"><?= e($msg) ?></div><?php endif; ?>

<?php if (!$rows): ?>
  <div class="mail-empty">
    <?= $q !== '' ? 'No messages match that search.' : ($folder === 'trash' ? 'Trash is empty.' : 'Nothing here yet.') ?>
  </div>
<?php else: ?>
  <div class="mail-list">
    <?php foreach ($rows as $m):
        $isSent   = ($m['direction'] === 'out');
        $name     = $isSent ? (string)($m['to_email'] ?: 'recipient') : ((string)($m['from_name'] ?: $m['from_email']));
        $initial  = strtoupper(substr(trim($name !== '' ? $name : 'N'), 0, 1));
        $unread   = (!$isSent && (int)$m['is_read'] === 0);
    ?>
    <div class="mail-row<?= $unread ? ' unread' : '' ?>">
      <div class="av"><?= e($initial) ?></div>
      <div class="lines">
        <a class="open" href="/mail/message.php?id=<?= (int)$m['id'] ?>">
          <div class="l1">
            <span class="from"><?= e($name) ?></span>
            <span class="subj"><?= e((string)$m['subject']) ?></span>
          </div>
          <div class="snip"><?= e((string)$m['snippet']) ?></div>
        </a>
      </div>
      <div class="meta">
        <span class="time"><?= e(time_ago((string)$m['created_at'])) ?></span>
        <div class="flags">
          <?php if ($isSent): ?>
            <span class="mail-chip<?= $m['send_error'] ? ' fail' : ' sent' ?>"><?= $m['send_error'] ? 'failed' : 'sent' ?></span>
          <?php endif; ?>
          <?php if (!empty($m['has_attachments'])): ?><span class="mail-chip">file</span><?php endif; ?>
          <form method="post" action="/mail/action.php">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="star">
            <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
            <input type="hidden" name="back" value="<?= e($back) ?>">
            <button class="star-btn<?= (int)$m['is_starred'] ? ' on' : '' ?>" type="submit" title="<?= (int)$m['is_starred'] ? 'Unstar' : 'Star' ?>"><?= mail_icon('star', 15) ?></button>
          </form>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php
mail_layout_bottom($folder);
