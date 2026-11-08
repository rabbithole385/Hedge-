<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

if (current_user()) redirect('/user/dashboard.php');

// Capture a referral code from the URL (?ref=CODE) and hold it in session
// across the visit so it survives any redirects before the form is submitted.
if (isset($_GET['ref'])) {
    $_SESSION['referral_code'] = strtoupper(trim($_GET['ref']));
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors[] = 'Session expired, please try again.';
    } else {
        $fullName = trim($_POST['full_name'] ?? '');
        $email = trim(strtolower($_POST['email'] ?? ''));
        $phone = trim($_POST['phone'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if ($fullName === '' || $email === '' || $password === '') {
            $errors[] = 'Please fill in all required fields.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        }
        if (strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        }
        if ($password !== $confirm) {
            $errors[] = 'Passwords do not match.';
        }

        if (!$errors) {
            $pdo = get_db();
            $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $errors[] = 'An account with that email already exists.';
            } else {
                $referredBy = null;
                if (!empty($_SESSION['referral_code'])) {
                    $stmt = $pdo->prepare('SELECT id FROM users WHERE referral_code = ?');
                    $stmt->execute([$_SESSION['referral_code']]);
                    $referrer = $stmt->fetch();
                    if ($referrer) $referredBy = (int)$referrer['id'];
                }

                $newReferralCode = generate_referral_code($pdo);

                $stmt = $pdo->prepare(
                    'INSERT INTO users (full_name, email, phone, password_hash, wallet_balance, referral_code, referred_by)
                     VALUES (?, ?, ?, ?, 0, ?, ?)'
                );
                $stmt->execute([$fullName, $email, $phone, password_hash($password, PASSWORD_DEFAULT), $newReferralCode, $referredBy]);
                $userId = (int)$pdo->lastInsertId();

                // Welcome bonus so new users can start allocating right away
                apply_wallet_delta($pdo, $userId, 3.00, 'deposit', 'Welcome bonus credit');

                unset($_SESSION['referral_code']);
                login_user($userId);
                redirect('/user/dashboard.php');
            }
        }
    }
}

$pageTitle = 'Register';
$navContext = 'guest';
$bodyClass = 'auth-page-body';
require __DIR__ . '/../includes/header.php';
?>
<main class="auth-shell auth-register">
  <section class="auth-brand auth-visual auth-visual-register" aria-labelledby="auth-visual-title">
    <img class="auth-visual-image" src="/assets/images/nexora-institutional.jpg" alt="">
    <div class="auth-visual-shade"></div>
    <div class="auth-visual-top">
      <a class="brand-mark nx-logo nx-logo-lg" href="/en/" aria-label="NexoraCopy home"><svg class="nx-ico" viewBox="0 0 40 40" aria-hidden="true"><defs><linearGradient id="nxg" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#f3dca6"/><stop offset=".55" stop-color="#d4af6a"/><stop offset="1" stop-color="#a77f3c"/></linearGradient></defs><rect x="1" y="1" width="38" height="38" rx="11" fill="#0e1729" stroke="url(#nxg)" stroke-opacity=".55"/><path d="M12 29V11l16 18V11" fill="none" stroke="url(#nxg)" stroke-width="3.4" stroke-linecap="round" stroke-linejoin="round"/></svg><span class="nx-word">NexoraCopy</span></a>
      <span class="auth-overline">OPEN YOUR ACCOUNT</span>
    </div>
    <div class="auth-visual-copy">
      <span class="auth-kicker"><i></i> YOUR MARKET WORKSPACE</span>
      <h1 id="auth-visual-title">A broader view.<br><em>A clearer start.</em></h1>
      <p>Bring your watchlist, trading activity, copy positions and support together in one account.</p>
      <div class="auth-proof-row" aria-label="Platform highlights">
        <div><strong>01</strong><span>Markets in one place</span></div>
        <div><strong>02</strong><span>Tools that fit your style</span></div>
        <div><strong>03</strong><span>Support when you need it</span></div>
      </div>
    </div>
    <div class="auth-visual-footer"><span>MARKETS / INSIGHT / CONTROL</span><span>NEXOCOPY.NET</span></div>
  </section>

  <section class="auth-form-side" aria-labelledby="auth-form-title">
    <div class="auth-card">
      <div class="auth-card-meta"><span>NEW CLIENT</span><span>01 / 03</span></div>
      <h2 id="auth-form-title">Create your account<span class="auth-period">.</span></h2>
      <p class="subtitle">A few details to get your workspace ready.</p>

      <?php if (!empty($_SESSION['referral_code'])): ?>
        <div class="alert info">Referral code <strong><?= e($_SESSION['referral_code']) ?></strong> applied.</div>
      <?php endif; ?>

      <?php foreach ($errors as $err): ?>
        <div class="alert error"><?= e($err) ?></div>
      <?php endforeach; ?>

      <form method="post" novalidate>
        <?= csrf_field() ?>
        <div class="form-group">
          <label for="register-name">Full name</label>
          <input id="register-name" type="text" name="full_name" value="<?= e($_POST['full_name'] ?? '') ?>" autocomplete="name" required>
        </div>
        <div class="form-group">
          <label for="register-email">Email address</label>
          <input id="register-email" type="email" name="email" value="<?= e($_POST['email'] ?? '') ?>" autocomplete="email" autocapitalize="none" spellcheck="false" required>
        </div>
        <div class="form-group">
          <label for="register-phone">Phone <span class="auth-optional">OPTIONAL</span></label>
          <input id="register-phone" type="tel" name="phone" value="<?= e($_POST['phone'] ?? '') ?>" autocomplete="tel">
        </div>
        <div class="form-group">
          <label for="register-password">Password</label>
          <input id="register-password" type="password" name="password" autocomplete="new-password" required>
        </div>
        <div class="form-group">
          <label for="register-confirm-password">Confirm password</label>
          <input id="register-confirm-password" type="password" name="confirm_password" autocomplete="new-password" required>
        </div>
        <button class="btn block auth-submit" type="submit">Create account <span aria-hidden="true">&rarr;</span></button>
      </form>
      <div class="auth-switch"><span>Already registered?</span><a href="/auth/login.php">Sign in <span aria-hidden="true">&rarr;</span></a></div>
      <div class="auth-footnote"><span class="auth-footnote-dot"></span> Your account, positions and support in one place.</div>
    </div>
  </section>
</main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
