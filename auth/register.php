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
require __DIR__ . '/../includes/header.php';
?>
<div class="auth-shell">
  <div class="auth-brand">
    <div class="brand-mark">Hedge<span>Inv</span></div>
    <div class="tagline">Professional trading, bots, and investment plans.</div>
  </div>
  <div class="auth-card">
      <h2 style="margin-top:0;">Create your account</h2>

      <?php if (!empty($_SESSION['referral_code'])): ?>
        <div class="alert info">Referral code <strong><?= e($_SESSION['referral_code']) ?></strong> applied.</div>
      <?php endif; ?>

      <?php foreach ($errors as $err): ?>
        <div class="alert error"><?= e($err) ?></div>
      <?php endforeach; ?>

      <form method="post" novalidate>
        <?= csrf_field() ?>
        <div class="form-group">
          <label>Full name</label>
          <input type="text" name="full_name" value="<?= e($_POST['full_name'] ?? '') ?>" required>
        </div>
        <div class="form-group">
          <label>Email</label>
          <input type="email" name="email" value="<?= e($_POST['email'] ?? '') ?>" required>
        </div>
        <div class="form-group">
          <label>Phone (optional)</label>
          <input type="text" name="phone" value="<?= e($_POST['phone'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label>Password</label>
          <input type="password" name="password" required>
        </div>
        <div class="form-group">
          <label>Confirm password</label>
          <input type="password" name="confirm_password" required>
        </div>
        <button class="btn block" type="submit">Create account</button>
      </form>
      <p class="subtitle" style="margin-top:16px;">Already have an account? <a href="/auth/login.php">Log in</a></p>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>