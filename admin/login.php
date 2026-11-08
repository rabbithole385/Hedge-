<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

if (current_admin()) redirect('/admin/dashboard.php');

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors[] = 'Session expired, please try again.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        $stmt = get_db()->prepare('SELECT * FROM admins WHERE username = ?');
        $stmt->execute([$username]);
        $admin = $stmt->fetch();

        if (!$admin || !password_verify($password, $admin['password_hash'])) {
            $errors[] = 'Invalid credentials.';
        } else {
            login_admin((int)$admin['id']);
            redirect('/admin/dashboard.php');
        }
    }
}

$pageTitle = 'Admin Login';
$navContext = 'guest';
require __DIR__ . '/../includes/header.php';
?>
<div class="auth-shell">
  <div class="auth-brand">
    <div class="brand-mark">Nexora<span>Copy</span></div>
    <div class="tagline">Admin console</div>
  </div>
  <div class="auth-card">
    <h2 style="margin-top:0;">Admin login</h2>
    <?php foreach ($errors as $err): ?><div class="alert error"><?= e($err) ?></div><?php endforeach; ?>
    <form method="post">
      <?= csrf_field() ?>
      <div class="form-group">
        <label>Username</label>
        <input type="text" name="username" required>
      </div>
      <div class="form-group">
        <label>Password</label>
        <input type="password" name="password" required>
      </div>
      <button class="btn block" type="submit">Log in</button>
    </form>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>