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
$bodyClass = 'auth-page-body';
require __DIR__ . '/../includes/header.php';
?>
<main class="auth-shell auth-login">
  <section class="auth-brand auth-visual" aria-labelledby="auth-visual-title">
    <img class="auth-visual-image" src="/assets/images/nexora-market-data.jpg" alt="">
    <div class="auth-visual-shade"></div>
    <div class="auth-visual-top">
      <a class="brand-mark nx-logo nx-logo-lg" href="/en/" aria-label="NexoraCopy home"><svg class="nx-ico" viewBox="0 0 40 40" aria-hidden="true"><defs><linearGradient id="nxg" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#f3dca6"/><stop offset=".55" stop-color="#d4af6a"/><stop offset="1" stop-color="#a77f3c"/></linearGradient></defs><rect x="1" y="1" width="38" height="38" rx="11" fill="#0e1729" stroke="url(#nxg)" stroke-opacity=".55"/><path d="M12 29V11l16 18V11" fill="none" stroke="url(#nxg)" stroke-width="3.4" stroke-linecap="round" stroke-linejoin="round"/></svg><span class="nx-word">NexoraCopy</span></a>
      <span class="auth-overline">PRIVATE CLIENT PORTAL</span>
    </div>
    <div class="auth-visual-copy">
      <span class="auth-kicker"><i></i> MARKETS IN MOTION</span>
      <h1 id="auth-visual-title">One clear view.<br><em>Many ways forward.</em></h1>
      <p>Trade live markets, follow experienced traders, and manage every position from one focused workspace.</p>
      <div class="auth-proof-row" aria-label="Platform highlights">
        <div><strong>01</strong><span>Live market access</span></div>
        <div><strong>02</strong><span>One clear portfolio</span></div>
        <div><strong>03</strong><span>People-first support</span></div>
      </div>
    </div>
    <div class="auth-visual-footer"><span>TRADE / COPY / AUTOMATE</span><span>NEXORACOPY.NET</span></div>
  </section>

  <section class="auth-form-side" aria-labelledby="auth-form-title">
    <div class="auth-card">
      <div class="auth-card-meta"><span>ACCOUNT ACCESS</span><span>01 / 02</span></div>
      <h2 id="auth-form-title">Welcome back<span class="auth-period">.</span></h2>
      <p class="subtitle">Sign in to pick up where you left off.</p>

      <?php foreach ($errors as $err): ?>
        <div class="alert error"><?= e($err) ?></div>
      <?php endforeach; ?>

      <form method="post" novalidate>
        <?= csrf_field() ?>
        <div class="form-group">
          <label for="login-email">Email address</label>
          <input id="login-email" type="email" name="email" autocomplete="email" autocapitalize="none" spellcheck="false" required>
        </div>
        <div class="form-group">
          <label for="login-password">Password</label>
          <input id="login-password" type="password" name="password" autocomplete="current-password" required>
        </div>
        <button class="btn block auth-submit" type="submit">Sign in <span aria-hidden="true">&rarr;</span></button>
      </form>
      <div class="auth-switch"><span>New to NexoraCopy?</span><a href="/auth/register.php">Create an account <span aria-hidden="true">&rarr;</span></a></div>
      <div class="auth-footnote"><span class="auth-footnote-dot"></span> Your account, positions and support in one place.</div>
    </div>
  </section>
</main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
