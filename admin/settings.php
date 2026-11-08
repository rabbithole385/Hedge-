<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$admin = require_admin();
$pdo = get_db();

$cryptoFields = [
    'crypto_address_btc' => 'Bitcoin (BTC) receiving address',
    'crypto_address_usdt_trc20' => 'USDT (TRC-20) receiving address',
    'crypto_address_usdt_erc20' => 'USDT (ERC-20) receiving address',
    'crypto_address_eth' => 'Ethereum (ETH) receiving address',
];

$bankFields = [
    'bank_name' => 'Bank name',
    'bank_account_name' => 'Account holder name',
    'bank_account_number' => 'Account number',
    'bank_routing_ach' => 'Routing number (ACH)',
    'bank_routing_wire' => 'Routing number (Wire)',
    'bank_beneficiary_address' => 'Beneficiary address',
    'bank_address' => 'Bank address',
    'bank_instructions' => 'Additional instructions shown to users',
];

$referralFields = [
    'referral_bonus_amount' => 'Referral bonus (USD) per successful first deposit',
];

$fields = array_merge($cryptoFields, $bankFields, $referralFields);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    foreach ($fields as $key => $label) {
        set_setting($pdo, $key, trim($_POST[$key] ?? ''));
    }
    flash_set('success', 'Settings updated.');
    redirect('/admin/settings.php');
}

$values = [];
foreach ($fields as $key => $label) {
    $values[$key] = get_setting($pdo, $key);
}
$cronSecret = get_setting($pdo, 'cron_secret');

$pageTitle = 'Settings';
$navContext = 'admin';
require __DIR__ . '/../includes/header.php';
?>
<div class="container">
  <?php if ($msg = flash_get('success')): ?><div class="alert success"><?= e($msg) ?></div><?php endif; ?>
  <h1>Platform settings</h1>

  <div class="grid cols-2">
    <div class="card">
      <h3>Crypto receiving addresses</h3>
      <p class="subtitle">Shown to users on the crypto deposit page. Remember this flow is manual-approval only, not on-chain verified.</p>
      <form method="post">
        <?= csrf_field() ?>
        <?php foreach ($cryptoFields as $key => $label): ?>
          <div class="form-group">
            <label><?= e($label) ?></label>
            <input type="text" name="<?= e($key) ?>" value="<?= e($values[$key]) ?>">
          </div>
        <?php endforeach; ?>
        <?php foreach ($bankFields as $key => $label): ?>
          <input type="hidden" name="<?= e($key) ?>" value="<?= e($values[$key]) ?>">
        <?php endforeach; ?>
        <?php foreach ($referralFields as $key => $label): ?>
          <input type="hidden" name="<?= e($key) ?>" value="<?= e($values[$key]) ?>">
        <?php endforeach; ?>
        <button class="btn block" type="submit">Save crypto addresses</button>
      </form>
    </div>

    <div class="card">
      <h3>Bank account details</h3>
      <p class="subtitle">Shown to users on the bank transfer deposit page. Manual-approval only, same as crypto.</p>
      <form method="post">
        <?= csrf_field() ?>
        <?php foreach ($bankFields as $key => $label): ?>
          <div class="form-group">
            <label><?= e($label) ?></label>
            <?php if ($key === 'bank_instructions'): ?>
              <textarea name="<?= e($key) ?>" rows="2"><?= e($values[$key]) ?></textarea>
            <?php else: ?>
              <input type="text" name="<?= e($key) ?>" value="<?= e($values[$key]) ?>">
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
        <?php foreach ($cryptoFields as $key => $label): ?>
          <input type="hidden" name="<?= e($key) ?>" value="<?= e($values[$key]) ?>">
        <?php endforeach; ?>
        <?php foreach ($referralFields as $key => $label): ?>
          <input type="hidden" name="<?= e($key) ?>" value="<?= e($values[$key]) ?>">
        <?php endforeach; ?>
        <button class="btn block" type="submit">Save bank details</button>
      </form>
    </div>
  </div>

  <div class="grid cols-2" style="margin-top:20px;">
    <div class="card">
      <h3>Referral program</h3>
      <p class="subtitle">Paid automatically the moment a referred user's first deposit is approved.</p>
      <form method="post">
        <?= csrf_field() ?>
        <?php foreach ($referralFields as $key => $label): ?>
          <div class="form-group">
            <label><?= e($label) ?></label>
            <input type="number" step="0.01" name="<?= e($key) ?>" value="<?= e($values[$key]) ?>">
          </div>
        <?php endforeach; ?>
        <?php foreach (array_merge($cryptoFields, $bankFields) as $key => $label): ?>
          <input type="hidden" name="<?= e($key) ?>" value="<?= e($values[$key]) ?>">
        <?php endforeach; ?>
        <button class="btn block" type="submit">Save referral bonus</button>
      </form>
    </div>
    <div class="card">
      <h3>Automation (cron)</h3>
      <p class="subtitle">Bots and investment plans tick automatically whenever someone visits the site. For reliable ticking even with no visitors, add a cPanel Cron Job that runs every 5&ndash;15 minutes:</p>
      <div class="form-group">
        <label>Cron command</label>
        <input type="text" readonly value="curl -s '<?= e((isset($_SERVER['HTTPS']) ? 'https' : 'http')) ?>://<?= e($_SERVER['HTTP_HOST'] ?? 'nexocopy.net') ?>/cron/tick.php?key=<?= e($cronSecret) ?>' > /dev/null" onclick="this.select()">
      </div>
      <p class="subtitle">This command already has your real domain and secret filled in - just copy it into the cPanel Cron Job command box.</p>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
