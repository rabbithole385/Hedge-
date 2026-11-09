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

$emailFields = [
    'resend_api_key' => 'Resend API key',
    'resend_from_email' => 'From email address (must be a verified Resend domain)',
    'resend_from_name' => 'From name',
    'resend_webhook_secret' => 'Resend webhook signing secret (inbound mail)',
];

$fields = array_merge($cryptoFields, $bankFields, $referralFields, $emailFields);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    foreach ($fields as $key => $label) {
        if (array_key_exists($key, $_POST)) {
            set_setting($pdo, $key, trim($_POST[$key]));
        }
    }
    if (array_key_exists('resend_enabled', $_POST)) {
        set_setting($pdo, 'resend_enabled', $_POST['resend_enabled'] === '1' ? '1' : '0');
    }

    if (($_POST['action'] ?? '') === 'resend_test') {
        $testTo = trim($_POST['test_email_to'] ?? '');
        if (!filter_var($testTo, FILTER_VALIDATE_EMAIL)) {
            flash_set('error', 'Enter a valid email address to receive the test.');
        } else {
            $sent = send_email($testTo, 'NexoraCopy test email', '<p>This is a test email from NexoraCopy. If you received it, email delivery is configured correctly.</p>');
            flash_set($sent ? 'success' : 'error', $sent ? 'Test email sent to ' . $testTo . '.' : 'Test email failed - check the API key, the from address, and the server error log.');
        }
    } else {
        flash_set('success', 'Settings updated.');
    }
    redirect('/admin/settings.php');
}

$values = [];
foreach ($fields as $key => $label) {
    $values[$key] = get_setting($pdo, $key);
}
$resendEnabled = get_setting($pdo, 'resend_enabled', '1');
$cronSecret = get_setting($pdo, 'cron_secret');

$pageTitle = 'Settings';
$navContext = 'admin';
require __DIR__ . '/../includes/header.php';
?>
<div class="container">
  <?php if ($msg = flash_get('success')): ?><div class="alert success"><?= e($msg) ?></div><?php endif; ?>
  <?php if ($msg = flash_get('error')): ?><div class="alert error"><?= e($msg) ?></div><?php endif; ?>
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
        <input type="text" readonly value="curl -s '<?= e((isset($_SERVER['HTTPS']) ? 'https' : 'http')) ?>://<?= e($_SERVER['HTTP_HOST'] ?? 'nexoracopy.net') ?>/cron/tick.php?key=<?= e($cronSecret) ?>' > /dev/null" onclick="this.select()">
      </div>
      <p class="subtitle">This command already has your real domain and secret filled in - just copy it into the cPanel Cron Job command box.</p>
    </div>
  </div>

  <div class="grid cols-2" style="margin-top:20px;">
    <div class="card">
      <h3>Email delivery (Resend)</h3>
      <p class="subtitle">All notification email (deposit approvals, KYC decisions, referral bonuses) is sent through Resend. Create an API key at resend.com and paste it below &mdash; it takes effect immediately, no redeploy needed.</p>
      <form method="post">
        <?= csrf_field() ?>
        <div class="form-group">
          <label>Status</label>
          <label style="display:flex; align-items:center; gap:8px;">
            <input type="hidden" name="resend_enabled" value="0">
            <input type="checkbox" name="resend_enabled" value="1"<?= $resendEnabled === '1' ? ' checked' : '' ?>> Send email through Resend
          </label>
        </div>
        <div class="form-group">
          <label><?= e($emailFields['resend_api_key']) ?></label>
          <input type="text" name="resend_api_key" value="<?= e($values['resend_api_key']) ?>" autocomplete="off" spellcheck="false" placeholder="re_xxxxxxxxxxxxxxxxxxxxxxxx">
        </div>
        <div class="form-group">
          <label><?= e($emailFields['resend_from_email']) ?></label>
          <input type="text" name="resend_from_email" value="<?= e($values['resend_from_email']) ?>" autocomplete="off" spellcheck="false" placeholder="no-reply@nexoracopy.net">
        </div>
        <div class="form-group">
          <label><?= e($emailFields['resend_from_name']) ?></label>
          <input type="text" name="resend_from_name" value="<?= e($values['resend_from_name']) ?>" placeholder="NexoraCopy">
        </div>
        <div class="form-group">
          <label><?= e($emailFields['resend_webhook_secret']) ?></label>
          <input type="text" name="resend_webhook_secret" value="<?= e($values['resend_webhook_secret']) ?>" autocomplete="off" spellcheck="false" placeholder="whsec_xxxxxxxxxxxxxxxx">
          <p class="subtitle" style="margin-top:6px;">In Resend, add a webhook for the <b>email.received</b> event pointing at <span class="mono-num">https://<?= e($_SERVER['HTTP_HOST'] ?? 'nexoracopy.net') ?>/mail/webhook.php</span> and paste its signing secret here.</p>
        </div>
        <div class="form-group">
          <label>Standalone webmail</label>
          <input type="text" readonly value="https://<?= e($_SERVER['HTTP_HOST'] ?? 'nexoracopy.net') ?>/mail/" onclick="this.select()">
          <p class="subtitle" style="margin-top:6px;">Your private mailbox login. It uses its own sign-in, separate from the admin console.</p>
        </div>
        <div class="form-group">
          <label>Send a test to</label>
          <input type="email" name="test_email_to" value="" placeholder="you@example.com">
        </div>
        <button class="btn block" type="submit" name="action" value="save">Save email settings</button>
        <button class="btn block" type="submit" name="action" value="resend_test" style="margin-top:10px;">Save &amp; send test email</button>
      </form>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
