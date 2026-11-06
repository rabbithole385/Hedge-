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
    <div class="brand-mark">Hedge<span>Inv</span></div>
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