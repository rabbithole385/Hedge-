<?php
/** Compose and send a new message (or a reply). */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/mail_client.php';
require_once __DIR__ . '/partials.php';

$account = mail_require_login();
$pdo     = get_db();

$fromEmail = (string)$account['email'];
$fromName  = (string)($account['display_name'] ?: 'NexoraCopy');

$to        = '';
$subject   = '';
$body      = '';
$inReplyTo = '';
$error     = '';

$replyId = (int)($_GET['reply'] ?? $_POST['reply'] ?? 0);
if ($replyId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM mail_messages WHERE id = ?');
    $stmt->execute([$replyId]);
    $src = $stmt->fetch();
    if ($src) {
        $to        = $src['direction'] === 'in' ? (string)$src['from_email'] : (string)$src['to_email'];
        $subject   = (string)$src['subject'];
        $inReplyTo = (string)($src['message_id'] ?? '');
        if (stripos($subject, 'Re:') !== 0) $subject = 'Re: ' . $subject;
        $quote = trim((string)$src['body_text']);
        if ($quote === '') $quote = trim(strip_tags((string)$src['body_html']));
        if ($quote !== '') {
            $body = "\n\n\nOn " . date('M j, Y H:i', strtotime((string)$src['created_at'])) . ", "
                  . ($src['direction'] === 'in' ? (string)$src['from_email'] : 'you') . " wrote:\n> "
                  . str_replace("\n", "\n> ", trim($quote));
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = 'Your session expired. Please try again.';
    } else {
        $to        = trim((string)($_POST['to'] ?? ''));
        $subject   = trim((string)($_POST['subject'] ?? ''));
        $body      = (string)($_POST['body'] ?? '');
        $inReplyTo = trim((string)($_POST['in_reply_to'] ?? ''));

        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $error = 'Enter a valid recipient address.';
        } elseif ($body === '') {
            $error = 'Write something in the message body first.';
        } else {
            if ($subject === '') $subject = '(no subject)';
            $html = '<div style="font-family:Inter,Arial,sans-serif;font-size:14px;line-height:1.6;color:#1a1a1a;">'
                  . nl2br(e($body)) . '</div>';

            $res = mail_send($fromEmail, $fromName, $to, $subject, $html, $body, $inReplyTo !== '' ? $inReplyTo : null);
            mail_store_outbound($fromEmail, $fromName, $to, $subject, $html, $res['id'], $res['ok'] ? '' : $res['error']);

            if ($res['ok']) {
                flash_set('success', 'Message sent to ' . $to . '.');
                redirect('/mail/?folder=sent');
            }
            $error = $res['error'];
        }
    }
}

mail_layout_top($account, 'compose', 'New message', 'From ' . $fromEmail);
?>
<?php if ($error !== ''): ?><div class="mail-alert err"><?= e($error) ?></div><?php endif; ?>

<form class="mail-form" method="post" action="/mail/compose.php" autocomplete="off">
  <?= csrf_field() ?>
  <input type="hidden" name="in_reply_to" value="<?= e($inReplyTo) ?>">
  <?php if ($replyId > 0): ?><input type="hidden" name="reply" value="<?= (int)$replyId ?>"><?php endif; ?>

  <div class="row">
    <label>To</label>
    <input type="email" name="to" value="<?= e($to) ?>" placeholder="name@example.com" required>
  </div>
  <div class="row">
    <label>Subject</label>
    <input type="text" name="subject" value="<?= e($subject) ?>" placeholder="Subject">
  </div>
  <div class="row">
    <label>Message</label>
    <textarea name="body" placeholder="Write your message..."><?= e($body) ?></textarea>
  </div>

  <div class="mail-actions" style="margin-top:0;">
    <button class="mail-btn primary" type="submit"><?= mail_icon('send', 16) ?> Send message</button>
    <a class="mail-btn ghost" href="/mail/">Discard</a>
  </div>
  <div class="hint" style="margin-top:10px;">Sending from <?= e($fromEmail) ?> through Resend. Replies land in the Inbox.</div>
</form>
<?php
mail_layout_bottom('compose');
