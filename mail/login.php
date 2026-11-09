<?php
/**
 * Standalone mailbox login for /mail. Completely separate from the admin
 * console and the user dashboard: it uses its own session key
 * ($_SESSION['mail_account_id']) and its own mail_accounts table.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/mail_client.php';

if (mail_account_current()) {
    redirect('/mail/');
}

$errors = [];
$email  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $email    = strtolower(trim((string)($_POST['email'] ?? '')));
        $password = (string)($_POST['password'] ?? '');

        $stmt = get_db()->prepare('SELECT * FROM mail_accounts WHERE email = ?');
        $stmt->execute([$email]);
        $acct = $stmt->fetch();

        if (!$acct || !password_verify($password, $acct['password_hash'])) {
            $errors[] = 'Invalid email or password.';
        } else {
            mail_login((int)$acct['id']);
            get_db()->prepare('UPDATE mail_accounts SET last_login_at = NOW() WHERE id = ?')->execute([(int)$acct['id']]);
            redirect('/mail/');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Mail sign in | NexoraCopy</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Chakra+Petch:wght@500;600;700&family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css">
<link rel="stylesheet" href="/mail/assets/mail.css">
</head>
<body class="mail-body">
<div class="mail-login">
  <div class="mail-login-card">
    <div class="mail-brand">NexoraCopy <span class="nx-tag">Mail</span></div>
    <h1>Sign in to your mailbox</h1>
    <p class="lead">Private webmail console for info@nexoracopy.net.</p>

    <?php foreach ($errors as $err): ?>
      <div class="mail-alert err"><?= e($err) ?></div>
    <?php endforeach; ?>

    <form class="mail-form" method="post" autocomplete="off">
      <?= csrf_field() ?>
      <div class="row">
        <label>Email address</label>
        <input type="email" name="email" value="<?= e($email) ?>" placeholder="info@nexoracopy.net" required autofocus>
      </div>
      <div class="row">
        <label>Password</label>
        <input type="password" name="password" placeholder="Your mailbox password" required>
      </div>
      <button class="mail-btn primary block" type="submit">Sign in</button>
    </form>

    <div class="foot"><a href="/">nexoracopy.net</a></div>
  </div>
</div>
</body>
</html>
