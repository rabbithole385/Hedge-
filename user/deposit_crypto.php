<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$user = require_login();
require_kyc($user);
$pdo = get_db();
$errors = [];

$cryptoOptions = [
    'BTC' => ['label' => 'Bitcoin (BTC)', 'address' => get_setting($pdo, 'crypto_address_btc')],
    'USDT_TRC20' => ['label' => 'USDT (TRC-20)', 'address' => get_setting($pdo, 'crypto_address_usdt_trc20')],
    'USDT_ERC20' => ['label' => 'USDT (ERC-20)', 'address' => get_setting($pdo, 'crypto_address_usdt_erc20')],
    'ETH' => ['label' => 'Ethereum (ETH)', 'address' => get_setting($pdo, 'crypto_address_eth')],
];

$selected = $_GET['type'] ?? $_POST['crypto_type'] ?? 'BTC';
if (!isset($cryptoOptions[$selected])) $selected = 'BTC';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors[] = 'Session expired, please try again.';
    } else {
        $amount = (float)($_POST['amount_usd'] ?? 0);
        $txHash = trim($_POST['tx_hash'] ?? '');

        if (!isset($cryptoOptions[$selected])) {
            $errors[] = 'Select a valid cryptocurrency.';
        } elseif ($amount <= 0) {
            $errors[] = 'Enter a valid USD amount.';
        } elseif ($txHash === '') {
            $errors[] = 'Enter the transaction hash / reference so admin can verify it.';
        } else {
            [$proofPath, $uploadErrors] = handle_deposit_proof_upload();
            $errors = array_merge($errors, $uploadErrors);

            if (!$errors) {
                $pdo->prepare(
                    'INSERT INTO crypto_deposits (user_id, crypto_type, amount_usd, receiving_address, tx_hash, proof_path, status)
                     VALUES (?, ?, ?, ?, ?, ?, "pending")'
                )->execute([$user['id'], $selected, $amount, $cryptoOptions[$selected]['address'], $txHash, $proofPath]);

                flash_set('success', 'Deposit request submitted. Admin will review and credit your wallet once verified.');
                redirect('/user/dashboard.php');
            }
        }
    }
}

$stmt = $pdo->prepare('SELECT * FROM crypto_deposits WHERE user_id = ? ORDER BY created_at DESC LIMIT 10');
$stmt->execute([$user['id']]);
$myRequests = $stmt->fetchAll();

$pageTitle = 'Crypto Deposit';
$navContext = 'user';
require __DIR__ . '/../includes/header.php';
?>
<div class="container narrow">
  <div class="card">
    <h2>Deposit via crypto</h2>
    <div class="alert info">Test flow: send to the address below, then submit your transaction hash. Admin reviews and credits your wallet manually &mdash; nothing is auto-verified on-chain yet.</div>

    <?php foreach ($errors as $err): ?><div class="alert error"><?= e($err) ?></div><?php endforeach; ?>

    <form method="get" style="margin-bottom:16px;">
      <div class="form-group">
        <label>Choose cryptocurrency</label>
        <select name="type" onchange="this.form.submit()">
          <?php foreach ($cryptoOptions as $key => $opt): ?>
            <option value="<?= e($key) ?>" <?= $selected === $key ? 'selected' : '' ?>><?= e($opt['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </form>

    <div class="form-group">
      <label>Send to this address</label>
      <input type="text" readonly value="<?= e($cryptoOptions[$selected]['address']) ?>" onclick="this.select()">
    </div>

    <form method="post" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="crypto_type" value="<?= e($selected) ?>">
      <div class="form-group">
        <label>Amount sent (USD equivalent)</label>
        <input type="number" step="0.01" min="1" name="amount_usd" required>
      </div>
      <div class="form-group">
        <label>Transaction hash / reference</label>
        <input type="text" name="tx_hash" required>
      </div>
      <div class="form-group">
        <label>Upload payment screenshot / proof (optional, JPG/PNG/PDF, max 5MB)</label>
        <input type="file" name="proof" accept=".jpg,.jpeg,.png,.pdf">
      </div>
      <button class="btn block" type="submit">Submit deposit request</button>
    </form>
  </div>

  <?php if ($myRequests): ?>
  <div class="card" style="margin-top:20px;">
    <h3>Your recent requests</h3>
    <table>
      <thead><tr><th>Crypto</th><th>Amount</th><th>Status</th><th>Date</th></tr></thead>
      <tbody>
      <?php foreach ($myRequests as $r): ?>
        <tr>
          <td><?= e($r['crypto_type']) ?></td>
          <td><?= fmt_money($r['amount_usd']) ?></td>
          <td>
            <?php if ($r['status'] === 'pending'): ?><span class="badge blue">Pending review</span>
            <?php elseif ($r['status'] === 'approved'): ?><span class="badge green">Approved</span>
            <?php else: ?><span class="badge red">Rejected</span><?php endif; ?>
          </td>
          <td><?= e(date('M j, g:ia', strtotime($r['created_at']))) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>