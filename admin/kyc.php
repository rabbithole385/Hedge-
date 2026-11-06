<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$admin = require_admin();
$pdo = get_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $id = (int)($_POST['id'] ?? 0);
    $action = $_POST['action'] ?? '';
    $note = trim($_POST['admin_note'] ?? '');

    $stmt = $pdo->prepare("SELECT * FROM kyc_submissions WHERE id = ? AND status = 'pending'");
    $stmt->execute([$id]);
    $sub = $stmt->fetch();

    if ($sub) {
        if ($action === 'approve') {
            $pdo->beginTransaction();
            try {
                $pdo->prepare("UPDATE kyc_submissions SET status = 'approved', admin_note = ?, reviewed_at = NOW() WHERE id = ?")
                    ->execute([$note, $id]);
                $pdo->prepare("UPDATE users SET kyc_status = 'approved' WHERE id = ?")->execute([$sub['user_id']]);
                $pdo->commit();

                create_notification(
                    $pdo, (int)$sub['user_id'], 'kyc_approved', 'Identity verified',
                    'Your identity verification was approved. Withdrawals are now unlocked.', '/user/withdraw.php'
                );
                log_admin_action($pdo, (int)$admin['id'], 'approve_kyc', 'KYC #' . $id . ' approved for user #' . $sub['user_id']);

                flash_set('success', 'Identity verification approved. User can now withdraw.');
            } catch (Throwable $e) {
                $pdo->rollBack();
                flash_set('error', 'Could not approve submission.');
            }
        } elseif ($action === 'reject') {
            $pdo->beginTransaction();
            try {
                $pdo->prepare("UPDATE kyc_submissions SET status = 'rejected', admin_note = ?, reviewed_at = NOW() WHERE id = ?")
                    ->execute([$note, $id]);
                $pdo->prepare("UPDATE users SET kyc_status = 'rejected' WHERE id = ?")->execute([$sub['user_id']]);
                $pdo->commit();

                create_notification(
                    $pdo, (int)$sub['user_id'], 'kyc_rejected', 'Identity verification rejected',
                    $note ?: 'Please review and resubmit your documents.', '/user/kyc.php'
                );
                log_admin_action($pdo, (int)$admin['id'], 'reject_kyc', 'KYC #' . $id . ' rejected for user #' . $sub['user_id']);

                flash_set('success', 'Submission rejected. User can resubmit.');
            } catch (Throwable $e) {
                $pdo->rollBack();
                flash_set('error', 'Could not reject submission.');
            }
        }
    }
    redirect('/admin/kyc.php');
}

$pending = $pdo->query(
    "SELECT k.*, u.full_name, u.email FROM kyc_submissions k JOIN users u ON u.id = k.user_id
     WHERE k.status = 'pending' ORDER BY k.created_at ASC"
)->fetchAll();

$reviewed = $pdo->query(
    "SELECT k.*, u.full_name, u.email FROM kyc_submissions k JOIN users u ON u.id = k.user_id
     WHERE k.status != 'pending' ORDER BY k.reviewed_at DESC LIMIT 30"
)->fetchAll();

$idTypeLabels = ['passport' => 'Passport', 'national_id' => 'National ID', 'drivers_license' => "Driver's license"];
$capitalRangeLabels = [
    'under_20k' => 'Less than $20,000', '20k_30k' => '$20,000 - $30,000', '30k_50k' => '$30,000 - $50,000',
    '50k_100k' => '$50,000 - $100,000', '100k_500k' => '$100,000 - $500,000', '500k_1m' => '$500,000 - $1,000,000',
    'over_1m' => '$1,000,000+',
];
$netWorthRangeLabels = [
    'under_100k' => 'Less than $100,000', '100k_250k' => '$100,000 - $250,000', '250k_500k' => '$250,000 - $500,000',
    '500k_1m' => '$500,000 - $1,000,000', '1m_5m' => '$1,000,000 - $5,000,000', 'over_5m' => '$5,000,000+',
];

$pageTitle = 'Identity Verification';
$navContext = 'admin';
require __DIR__ . '/../includes/header.php';
?>
<div class="container">
  <?php if ($msg = flash_get('success')): ?><div class="alert success"><?= e($msg) ?></div><?php endif; ?>
  <?php if ($msg = flash_get('error')): ?><div class="alert error"><?= e($msg) ?></div><?php endif; ?>

  <h1>Identity verification (KYC)</h1>
  <p class="subtitle">Required before a user can use any part of their account. Review the uploaded document, confirm it matches the submitted details, then approve or reject.</p>

  <div class="card" style="margin-bottom:20px;">
    <h3>Pending review (<?= count($pending) ?>)</h3>
    <?php if (!$pending): ?>
      <p class="subtitle">Nothing pending.</p>
    <?php else: ?>
      <table>
        <thead><tr><th>User</th><th>Legal name</th><th>ID type / number</th><th>Next of kin</th><th>Trading profile</th><th>Document</th><th>Submitted</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach ($pending as $k): ?>
          <tr>
            <td data-label="User"><?= e($k['full_name']) ?><br><span class="subtitle"><?= e($k['email']) ?></span></td>
            <td data-label="Legal name"><?= e($k['full_legal_name']) ?></td>
            <td data-label="ID"><?= e($idTypeLabels[$k['id_type']] ?? $k['id_type']) ?> &middot; <?= e($k['id_number']) ?></td>
            <td data-label="Next of kin">
              <?php if ($k['next_of_kin_name']): ?>
                <?= e($k['next_of_kin_name']) ?><br>
                <span class="subtitle"><?= e($k['next_of_kin_phone']) ?></span><br>
                <span class="subtitle"><?= e($k['next_of_kin_address']) ?></span>
              <?php else: ?>
                <span class="subtitle">&mdash;</span>
              <?php endif; ?>
            </td>
            <td data-label="Trading profile">
              <?php if ($k['capital_range']): ?>
                Capital: <?= e($capitalRangeLabels[$k['capital_range']] ?? $k['capital_range']) ?><br>
                <span class="subtitle">Net worth: <?= e($netWorthRangeLabels[$k['net_worth_range']] ?? $k['net_worth_range']) ?></span>
              <?php else: ?>
                <span class="subtitle">&mdash;</span>
              <?php endif; ?>
            </td>
            <td data-label="Document"><a class="btn small secondary" href="/admin/kyc_file.php?id=<?= (int)$k['id'] ?>" target="_blank" rel="noopener">View document</a></td>
            <td data-label="Submitted"><?= e(date('M j, g:ia', strtotime($k['created_at']))) ?></td>
            <td data-label="Action" style="white-space:nowrap;">
              <form method="post" style="display:inline-block; margin-bottom:4px;">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int)$k['id'] ?>">
                <input type="hidden" name="action" value="approve">
                <button class="btn small" type="submit">Approve</button>
              </form>
              <form method="post" style="display:inline-block;" onsubmit="return confirm('Reject this submission?');">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int)$k['id'] ?>">
                <input type="hidden" name="action" value="reject">
                <button class="btn small danger" type="submit">Reject</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <div class="card">
    <h3>Recently reviewed</h3>
    <?php if (!$reviewed): ?>
      <p class="subtitle">No history yet.</p>
    <?php else: ?>
      <table>
        <thead><tr><th>User</th><th>Status</th><th>Reviewed</th></tr></thead>
        <tbody>
        <?php foreach ($reviewed as $k): ?>
          <tr>
            <td data-label="User"><?= e($k['full_name']) ?></td>
            <td data-label="Status"><span class="badge <?= $k['status']==='approved'?'green':'red' ?>"><?= e(ucfirst($k['status'])) ?></span></td>
            <td data-label="Reviewed"><?= e(date('M j, g:ia', strtotime($k['reviewed_at']))) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
