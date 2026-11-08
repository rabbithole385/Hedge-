<?php
/** Mailbox account: change the password for the signed-in mailbox. */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/mail_client.php';
require_once __DIR__ . '/partials.php';

$account = mail_require_login();
$pdo     = get_db();
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = 'Your session expired. Please try again.';
    } else {
        $current = (string)($_POST['current_password'] ?? '');
        $new     = (string)($_POST['new_password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');

        if (!password_verify($current, (string)$account['password_hash'])) {
            $error = 'Your current password is not correct.';
        } elseif (strlen($new) < 8) {
            $error = 'The new password must be at least 8 characters.';
        } elseif ($new !== $confirm) {
            $error = 'The two new passwords do not match.';
        } else {
            $pdo->prepare('UPDATE mail_accounts SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($new, PASSWORD_DEFAULT), (int)$account['id']]);
            flash_set('success', 'Password updated.');
            redirect('/mail/account.php');
        }
    }
}

mail_layout_top($account, 'account', 'Account', 'Mailbox settings');
?>
<?php if ($msg = flash_get('success')): ?><div class="mail-alert ok"><?= e($msg) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="mail-alert err"><?= e($error) ?></div><?php endif; ?>

<div class="mail-msg-head">
  <div class="mail-msg-meta">
    <span><b>Mailbox:</b> <span class="em"><?= e((string)$account['email']) ?></span></span>
    <span><b>Last sign-in:</b> <?= $account['last_login_at'] ? e(date('M j, Y H:i', strtotime((string)$account['last_login_at']))) : 'first sign-in' ?></span>
  </div>
</div>

<form class="mail-form" method="post" style="margin-top:14px;" autocomplete="off">
  <?= csrf_field() ?>
  <div class="row">
    <label>Current password</label>
    <input type="password" name="current_password" required>
  </div>
  <div class="row">
    <label>New password</label>
    <input type="password" name="new_password" required>
  </div>
  <div class="row">
    <label>Confirm new password</label>
    <input type="password" name="confirm_password" required>
  </div>
  <button class="mail-btn primary" type="submit">Update password</button>
</form>
<?php
mail_layout_bottom('account');
