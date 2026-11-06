<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$user = require_login();
require_kyc($user);

$pageTitle = 'Deposit';
$navContext = 'user';
require __DIR__ . '/../includes/header.php';
?>
<div class="container narrow">
  <div class="card">
    <h2>Deposit funds</h2>
    <p class="subtitle">Choose how you'd like to fund your wallet. Crypto is reviewed and approved automatically once confirmed; bank transfer is arranged directly with our support team.</p>

    <div style="display:flex; flex-direction:column; gap:12px; margin-top:16px;">
      <a class="btn block" href="/user/deposit_crypto.php">Deposit via Crypto</a>
      <a class="btn block secondary" href="/user/deposit_bank.php">Deposit via Bank Transfer (Contact Support)</a>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
