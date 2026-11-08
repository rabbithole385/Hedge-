<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$user = require_login();
require_kyc($user);
$pdo = get_db();

$bonusAmount = get_setting($pdo, 'referral_bonus_amount', '10');
$siteUrl = rtrim(getenv('SITE_URL') ?: 'https://nexocopy.net', '/');
$referralLink = $siteUrl . '/auth/register.php?ref=' . $user['referral_code'];

$stmt = $pdo->prepare(
    "SELECT u.full_name, u.created_at,
            (SELECT COUNT(*) FROM crypto_deposits WHERE user_id = u.id AND status = 'approved') +
            (SELECT COUNT(*) FROM bank_deposits WHERE user_id = u.id AND status = 'approved') as approved_deposits,
            (SELECT amount FROM referral_rewards WHERE referred_user_id = u.id) as reward_amount
     FROM users u WHERE u.referred_by = ? ORDER BY u.created_at DESC"
);
$stmt->execute([$user['id']]);
$referredUsers = $stmt->fetchAll();

$stmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM referral_rewards WHERE referrer_user_id = ?');
$stmt->execute([$user['id']]);
$totalEarned = (float)$stmt->fetchColumn();

$pageTitle = 'Referrals';
$navContext = 'user';
require __DIR__ . '/../includes/header.php';
?>
<div class="container">
  <h1>Referrals</h1>
  <p class="subtitle">Share your link. When someone you referred makes their first approved deposit, you're credited <?= fmt_money((float)$bonusAmount) ?> automatically.</p>

  <div class="grid cols-3" style="margin: 20px 0;">
    <div class="stat-card">
      <div class="label">Total earned</div>
      <div class="value"><?= fmt_money($totalEarned) ?></div>
    </div>
    <div class="stat-card">
      <div class="label">People referred</div>
      <div class="value"><?= count($referredUsers) ?></div>
    </div>
    <div class="stat-card">
      <div class="label">Bonus per referral</div>
      <div class="value"><?= fmt_money((float)$bonusAmount) ?></div>
    </div>
  </div>

  <div class="card" style="margin-bottom:20px;">
    <h3>Your referral link</h3>
    <div class="form-group">
      <input type="text" readonly value="<?= e($referralLink) ?>" onclick="this.select()" id="refLinkInput">
    </div>
    <button class="btn secondary" type="button" onclick="navigator.clipboard.writeText(document.getElementById('refLinkInput').value); this.textContent='Copied!'; setTimeout(() => this.textContent='Copy link', 1500);">Copy link</button>
    <p class="subtitle" style="margin-top:10px;">Your referral code: <strong class="mono-num"><?= e($user['referral_code']) ?></strong></p>
  </div>

  <div class="card">
    <h3>People you've referred</h3>
    <?php if (!$referredUsers): ?>
      <p class="subtitle">No referrals yet. Share your link to get started.</p>
    <?php else: ?>
      <table>
        <thead><tr><th>Name</th><th>Joined</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($referredUsers as $r): ?>
          <tr>
            <td data-label="Name"><?= e($r['full_name']) ?></td>
            <td data-label="Joined"><?= e(date('M j, Y', strtotime($r['created_at']))) ?></td>
            <td data-label="Status">
              <?php if ($r['reward_amount']): ?>
                <span class="badge green">Rewarded (<?= fmt_money((float)$r['reward_amount']) ?>)</span>
              <?php elseif ($r['approved_deposits'] > 0): ?>
                <span class="badge blue">Deposited</span>
              <?php else: ?>
                <span class="badge gray">Awaiting first deposit</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
