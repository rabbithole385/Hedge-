<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$user = require_login();
require_kyc($user);
$pdo = get_db();
process_all_due_ticks($pdo);

$experts = $pdo->query(
    "SELECT e.*, COUNT(t.id) as trade_count, COUNT(DISTINCT c.id) as copier_count
     FROM experts e
     LEFT JOIN trades t ON t.expert_id = e.id
     LEFT JOIN copies c ON c.expert_id = e.id AND c.status = 'active'
     WHERE e.status = 'active'
     GROUP BY e.id
     ORDER BY e.monthly_return DESC"
)->fetchAll();

$pageTitle = 'Leaderboard';
$navContext = 'user';
require __DIR__ . '/../includes/header.php';
?>
<div class="container">
  <h1>Leaderboard</h1>
  <p class="subtitle">Expert traders ranked by average monthly return. Figures shown are the same win rate, return, and trade count visible on each expert's profile.</p>

  <div class="card">
    <div class="table-scroll" style="max-height:600px;">
      <table>
        <thead><tr><th>#</th><th>Expert</th><th>Focus</th><th>Win rate</th><th>Avg return</th><th>Trades</th><th>Copiers</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($experts as $i => $ex): ?>
          <tr>
            <td data-label="#"><?= $i + 1 ?></td>
            <td data-label="Expert" style="display:flex; align-items:center; gap:10px;">
              <?php if (!empty($ex['photo_url'])): ?>
                <img src="<?= e($ex['photo_url']) ?>" alt="" style="width:32px;height:32px;border-radius:50%;object-fit:cover;">
              <?php else: ?>
                <div class="position-avatar-fallback" style="width:32px;height:32px;font-size:13px;"><?= e(strtoupper(substr($ex['name'], 0, 1))) ?></div>
              <?php endif; ?>
              <?= e($ex['name']) ?>
            </td>
            <td data-label="Focus"><span class="badge blue"><?= e(ucfirst($ex['asset_focus'])) ?></span></td>
            <td data-label="Win rate"><?= number_format((float)$ex['win_rate'], 1) ?>%</td>
            <td data-label="Avg return" class="<?= $ex['monthly_return'] >= 0 ? 'pos' : 'neg' ?>"><?= fmt_pct($ex['monthly_return']) ?></td>
            <td data-label="Trades"><?= get_expert_trade_count($pdo, $ex) ?></td>
            <td data-label="Copiers"><?= (int)$ex['copier_count'] ?></td>
            <td><a class="btn small secondary" href="/user/copy.php?expert_id=<?= (int)$ex['id'] ?>">Copy</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>