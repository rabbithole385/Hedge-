<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$user = require_login();
require_kyc($user);
$pdo = get_db();
$errors = [];
$kycApproved = $user['kyc_status'] === 'approved';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors[] = 'Session expired, please try again.';
    } elseif (!$kycApproved) {
        $errors[] = 'Identity verification is required before withdrawing.';
    } else {
        $amount = (float)($_POST['amount'] ?? 0);
        if ($amount <= 0) {
            $errors[] = 'Enter a valid amount.';
        } elseif ($amount > $user['wallet_balance']) {
            $errors[] = 'Insufficient wallet balance.';
        } else {
            apply_wallet_delta($pdo, $user['id'], -$amount, 'withdraw', 'Withdrawal request');
            create_notification(
                $pdo, (int)$user['id'], 'withdrawal_processed', 'Withdrawal submitted',
                fmt_money($amount) . ' has been deducted from your wallet and is being processed.', '/user/transactions.php'
            );
            flash_set('success', fmt_money($amount) . ' withdrawal submitted.');
            redirect('/user/dashboard.php');
        }
    }
}

$pageTitle = 'Withdraw';
$navContext = 'user';
require __DIR__ . '/../includes/header.php';
?>
<div class="container narrow">
  <div class="card">
    <h2>Withdraw funds</h2>
    <?php if (!$kycApproved): ?>
      <div class="alert error">
        Identity verification is required before you can withdraw.
        <?php if ($user['kyc_status'] === 'pending'): ?>
          Your submission is currently under review.
        <?php else: ?>
          <a href="/user/kyc.php">Complete verification &rarr;</a>
        <?php endif; ?>
      </div>
    <?php endif; ?>
    <div class="alert info">Withdrawal amount is deducted from your wallet balance immediately. Payout to your external account/wallet is reviewed and processed by our team.</div>
    <p class="subtitle">Available balance: <strong><?= fmt_money($user['wallet_balance']) ?></strong></p>
    <?php foreach ($errors as $err): ?><div class="alert error"><?= e($err) ?></div><?php endforeach; ?>
    <form method="post">
      <?= csrf_field() ?>
      <div class="form-group">
        <label>Amount</label>
        <input type="number" step="0.01" min="1" name="amount" required>
      </div>
      <button class="btn block" type="submit" <?= $kycApproved ? '' : 'disabled' ?>>Withdraw</button>
    </form>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
