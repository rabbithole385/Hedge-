<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

if (current_user()) redirect('/user/dashboard.php');

$errors = [];
if (isset($_GET['error']) && $_GET['error'] === 'suspended') {
    $errors[] = 'Your account has been suspended. Contact support.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors[] = 'Session expired, please try again.';
    } else {
        $email = trim(strtolower($_POST['email'] ?? ''));
        $password = $_POST['password'] ?? '';

        $stmt = get_db()->prepare('SELECT * FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            $errors[] = 'Invalid email or password.';
        } elseif ($user['status'] !== 'active') {
            $errors[] = 'Your account has been suspended. Contact support.';
        } else {
            login_user((int)$user['id']);
            redirect('/user/dashboard.php');
        }
    }
}

$pageTitle = 'Login';
$navContext = 'guest';
require __DIR__ . '/../includes/header.php';
?>
<div class="auth-shell">
  <div class="auth-brand">
    <div class="brand-mark nx-logo nx-logo-lg"><svg class="nx-ico" viewBox="0 0 40 40" aria-hidden="true"><defs><linearGradient id="nxg" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#f3dca6"/><stop offset=".55" stop-color="#d4af6a"/><stop offset="1" stop-color="#a77f3c"/></linearGradient></defs><rect x="1" y="1" width="38" height="38" rx="11" fill="#0e1729" stroke="url(#nxg)" stroke-opacity=".55"/><path d="M12 29V11l16 18V11" fill="none" stroke="url(#nxg)" stroke-width="3.4" stroke-linecap="round" stroke-linejoin="round"/><circle cx="28" cy="11" r="2.6" fill="#34d399"/></svg><span class="nx-word">Nexora</span></div>
    <div class="tagline">Professional trading, bots, and copy trade.</div>
  </div>
  <div class="auth-card">
    <h2 style="margin-top:0;">Log in</h2>
    <p class="subtitle">Access your portfolio.</p>

    <?php foreach ($errors as $err): ?>
      <div class="alert error"><?= e($err) ?></div>
    <?php endforeach; ?>

    <form method="post" novalidate>
      <?= csrf_field() ?>
      <div class="form-group">
        <label>Email</label>
        <input type="email" name="email" required>
      </div>
      <div class="form-group">
        <label>Password</label>
        <input type="password" name="password" required>
      </div>
      <button class="btn block" type="submit">Log in</button>
    </form>
    <p class="subtitle" style="margin-top:16px;">No account yet? <a href="/auth/register.php">Register</a></p>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>