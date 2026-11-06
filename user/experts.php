<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$user = require_login();
require_kyc($user);
$pdo = get_db();

$stmt = $pdo->query("SELECT * FROM experts WHERE status = 'active' ORDER BY monthly_return DESC");
$experts = $stmt->fetchAll();

function get_expert_copier_count(PDO $pdo, int $expertId): int {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM copies WHERE expert_id = ? AND status = 'active'");
    $stmt->execute([$expertId]);
    return (int)$stmt->fetchColumn();
}

$pageTitle = 'Experts';
$navContext = 'user';
require __DIR__ . '/../includes/header.php';
?>
<div class="container">
  <h1>Expert traders</h1>
  <p class="subtitle">Allocate a portion of your wallet balance to copy a trader's results automatically.</p>

  <div class="grid auto-fill" style="margin-top:20px;">
    <?php foreach ($experts as $ex): ?>
      <div class="position-card">
        <div class="position-card-header">
          <?php if (!empty($ex['photo_url'])): ?>
            <img class="position-avatar" src="<?= e($ex['photo_url']) ?>" alt="<?= e($ex['name']) ?>">
          <?php else: ?>
            <div class="position-avatar-fallback"><?= e(strtoupper(substr($ex['name'], 0, 1))) ?></div>
          <?php endif; ?>
          <div class="position-name-block">
            <div class="position-name"><?= e($ex['name']) ?></div>
            <div class="position-sub">
              <span class="badge blue"><?= e(ucfirst($ex['asset_focus'])) ?></span>
              <span class="badge gray" style="text-transform:capitalize;"><?= e($ex['risk_level']) ?> risk</span>
            </div>
          </div>
        </div>

        <p class="subtitle" style="min-height:40px;"><?= e($ex['bio']) ?></p>

        <div class="stat-box-grid">
          <div class="stat-box">
            <div class="stat-label">Trades</div>
            <div class="stat-value"><?= get_expert_trade_count($pdo, $ex) ?></div>
          </div>
          <div class="stat-box">
            <div class="stat-label">Min. copy amount</div>
            <div class="stat-value"><?= fmt_money($ex['min_copy_amount']) ?></div>
          </div>
        </div>
        <div class="stat-box-grid">
          <div class="stat-box">
            <div class="stat-label">Avg. monthly return</div>
            <div class="stat-value <?= $ex['monthly_return'] >= 0 ? 'pos' : 'neg' ?>"><?= fmt_pct($ex['monthly_return']) ?></div>
          </div>
          <div class="stat-box">
            <div class="stat-label">Active copiers</div>
            <div class="stat-value"><?= get_expert_copier_count($pdo, (int)$ex['id']) ?></div>
          </div>
        </div>

        <div class="winrate-row">
          <span>Win rate</span>
          <span><?= number_format((float)$ex['win_rate'], 1) ?>%</span>
        </div>
        <div class="winrate-bar-track">
          <div class="winrate-bar-fill" style="width: <?= max(0, min(100, (float)$ex['win_rate'])) ?>%;"></div>
        </div>

        <div class="position-actions">
          <a class="btn block" href="/user/copy.php?expert_id=<?= (int)$ex['id'] ?>">Copy this trader</a>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>