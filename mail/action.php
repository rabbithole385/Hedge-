<?php
/** Small POST endpoint for message actions: star, read/unread, trash, delete. */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/mail_client.php';

mail_require_login();

$back = (string)($_POST['back'] ?? '/mail/');
if ($back === '' || strpos($back, '/mail/') !== 0) $back = '/mail/';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
    redirect($back);
}

$action = (string)($_POST['action'] ?? '');
$id     = (int)($_POST['id'] ?? 0);
if ($id <= 0) redirect($back);

$pdo = get_db();

switch ($action) {
    case 'star':
        $pdo->prepare('UPDATE mail_messages SET is_starred = IF(is_starred = 1, 0, 1) WHERE id = ?')->execute([$id]);
        break;
    case 'read':
        $pdo->prepare('UPDATE mail_messages SET is_read = 1 WHERE id = ?')->execute([$id]);
        break;
    case 'unread':
        $pdo->prepare('UPDATE mail_messages SET is_read = 0 WHERE id = ?')->execute([$id]);
        break;
    case 'trash':
        $pdo->prepare('UPDATE mail_messages SET folder = "trash" WHERE id = ?')->execute([$id]);
        flash_set('success', 'Message moved to trash.');
        break;
    case 'untrash':
        $pdo->prepare('UPDATE mail_messages SET folder = "inbox" WHERE id = ?')->execute([$id]);
        break;
    case 'delete':
        $pdo->prepare('DELETE FROM mail_messages WHERE id = ?')->execute([$id]);
        flash_set('success', 'Message deleted.');
        break;
}

redirect($back);
