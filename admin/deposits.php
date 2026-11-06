<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$admin = require_admin();
$pdo = get_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $id = (int)($_POST['id'] ?? 0);
    $method = $_POST['method'] ?? '';
    $action = $_POST['action'] ?? '';
    $note = trim($_POST['admin_note'] ?? '');
    $table = $method === 'bank' ? 'bank_deposits' : 'crypto_deposits';

    $stmt = $pdo->prepare("SELECT * FROM {$table} WHERE id = ? AND status = 'pending'");
    $stmt->execute([$id]);
    $req = $stmt->fetch();

    if ($req) {
        if ($action === 'approve') {
            $pdo->beginTransaction();
            try {
                $ref = $method === 'bank' ? 'Bank transfer deposit approved' : 'Crypto deposit approved (' . $req['crypto_type'] . ')';
                apply_wallet_delta($pdo, (int)$req['user_id'], (float)$req['amount_usd'], 'deposit', $ref);
                $pdo->prepare("UPDATE {$table} SET status = 'approved', admin_note = ?, reviewed_at = NOW() WHERE id = ?")
                    ->execute([$note, $id]);
                $pdo->commit();

                create_notification(
                    $pdo, (int)$req['user_id'], 'deposit_approved', 'Deposit approved',
                    fmt_money((float)$req['amount_usd']) . ' has been credited to your wallet.', '/user/transactions.php'
                );
                process_referral_reward($pdo, (int)$req['user_id']);
                log_admin_action($pdo, (int)$admin['id'], 'approve_deposit', ucfirst($method) . ' deposit #' . $id . ' approved for user #' . $req['user_id'] . ' (' . fmt_money((float)$req['amount_usd']) . ')');

                flash_set('success', 'Deposit approved and credited to user wallet.');
            } catch (Throwable $e) {
                $pdo->rollBack();
                flash_set('error', 'Could not approve deposit.');
            }
        } elseif ($action === 'reject') {
            $pdo->prepare("UPDATE {$table} SET status = 'rejected', admin_note = ?, reviewed_at = NOW() WHERE id = ?")
                ->execute([$note, $id]);

            create_notification(
                $pdo, (int)$req['user_id'], 'deposit_rejected', 'Deposit rejected',
                $note ?: 'Your deposit request could not be verified.', '/user/deposit.php'
            );
            log_admin_action($pdo, (int)$admin['id'], 'reject_deposit', ucfirst($method) . ' deposit #' . $id . ' rejected for user #' . $req['user_id']);

            flash_set('success', 'Deposit request rejected.');
        }
    }
    redirect('/admin/deposits.php');
}

$pendingCrypto = $pdo->query(
    "SELECT cd.*, u.full_name, u.email FROM crypto_deposits cd JOIN users u ON u.id = cd.user_id
     WHERE cd.status = 'pending' ORDER BY cd.created_at ASC"
)->fetchAll();

$pendingBank = $pdo->query(
    "SELECT bd.*, u.full_name, u.email FROM bank_deposits bd JOIN users u ON u.id = bd.user_id
     WHERE bd.status = 'pending' ORDER BY bd.created_at ASC"
)->fetchAll();

$reviewedCrypto = $pdo->query(
    "SELECT cd.*, u.full_name, u.email FROM crypto_deposits cd JOIN users u ON u.id = cd.user_id
     WHERE cd.status != 'pending' ORDER BY cd.reviewed_at DESC LIMIT 20"
)->fetchAll();

$reviewedBank = $pdo->query(
    "SELECT bd.*, u.full_name, u.email FROM bank_deposits bd JOIN users u ON u.id = bd.user_id
     WHERE bd.status != 'pending' ORDER BY bd.reviewed_at DESC LIMIT 20"
)->fetchAll();

$pageTitle = 'Deposit Requests';
$navContext = 'admin';
require __DIR__ . '/../includes/header.php';
?>
<div class="container">
  <?php if ($msg = flash_get('success')): ?><div class="alert success"><?= e($msg) ?></div><?php endif; ?>
  <?php if ($msg = flash_get('error')): ?><div class="alert error"><?= e($msg) ?></div><?php endif; ?>

  <h1>Deposit requests</h1>

  <div class="card" style="margin-bottom:20px;">
    <h3>Crypto &mdash; pending review (<?= count($pendingCrypto) ?>)</h3>
    <?php if (!$pendingCrypto): ?>
      <p class="subtitle">Nothing pending.</p>
    <?php else: ?>
      <table>
        <thead><tr><th>User</th><th>Crypto</th><th>Amount</th><th>Tx hash</th><th>Proof</th><th>Submitted</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach ($pendingCrypto as $r): ?>
          <tr>
            <td data-label="User"><?= e($r['full_name']) ?><br><span class="subtitle"><?= e($r['email']) ?></span></td>
            <td data-label="Crypto"><?= e($r['crypto_type']) ?></td>
            <td data-label="Amount"><?= fmt_money($r['amount_usd']) ?></td>
            <td data-label="Tx hash" style="max-width:180px; word-break:break-all;"><?= e($r['tx_hash']) ?></td>
            <td data-label="Proof">
              <?php if (!empty($r['proof_path'])): ?>
                <a class="btn small secondary" href="/admin/deposit_proof_file.php?method=crypto&id=<?= (int)$r['id'] ?>" target="_blank" rel="noopener">View</a>
              <?php else: ?>
                <span class="subtitle">None</span>
              <?php endif; ?>
            </td>
            <td data-label="Submitted"><?= e(date('M j, g:ia', strtotime($r['created_at']))) ?></td>
            <td data-label="Action" style="white-space:nowrap;">
              <form method="post" style="display:inline-block; margin-bottom:4px;">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <input type="hidden" name="method" value="crypto">
                <input type="hidden" name="action" value="approve">
                <button class="btn small" type="submit">Approve</button>
              </form>
              <form method="post" style="display:inline-block;" onsubmit="return confirm('Reject this deposit request?');">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <input type="hidden" name="method" value="crypto">
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

  <div class="card" style="margin-bottom:20px;">
    <h3>Bank transfer &mdash; pending review (<?= count($pendingBank) ?>)</h3>
    <?php if (!$pendingBank): ?>
      <p class="subtitle">Nothing pending.</p>
    <?php else: ?>
      <table>
        <thead><tr><th>User</th><th>Amount</th><th>Depositor name</th><th>Reference</th><th>Proof</th><th>Submitted</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach ($pendingBank as $r): ?>
          <tr>
            <td data-label="User"><?= e($r['full_name']) ?><br><span class="subtitle"><?= e($r['email']) ?></span></td>
            <td data-label="Amount"><?= fmt_money($r['amount_usd']) ?></td>
            <td data-label="Depositor"><?= e($r['depositor_name']) ?></td>
            <td data-label="Reference"><?= e($r['reference'] ?: '—') ?></td>
            <td data-label="Proof">
              <?php if (!empty($r['proof_path'])): ?>
                <a class="btn small secondary" href="/admin/deposit_proof_file.php?method=bank&id=<?= (int)$r['id'] ?>" target="_blank" rel="noopener">View</a>
              <?php else: ?>
                <span class="subtitle">None</span>
              <?php endif; ?>
            </td>
            <td data-label="Submitted"><?= e(date('M j, g:ia', strtotime($r['created_at']))) ?></td>
            <td data-label="Action" style="white-space:nowrap;">
              <form method="post" style="display:inline-block; margin-bottom:4px;">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <input type="hidden" name="method" value="bank">
                <input type="hidden" name="action" value="approve">
                <button class="btn small" type="submit">Approve</button>
              </form>
              <form method="post" style="display:inline-block;" onsubmit="return confirm('Reject this deposit request?');">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <input type="hidden" name="method" value="bank">
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

  <div class="grid cols-2">
    <div class="card">
      <h3>Crypto &mdash; recently reviewed</h3>
      <?php if (!$reviewedCrypto): ?>
        <p class="subtitle">No history yet.</p>
      <?php else: ?>
        <table>
          <thead><tr><th>User</th><th>Amount</th><th>Status</th></tr></thead>
          <tbody>
          <?php foreach ($reviewedCrypto as $r): ?>
            <tr>
              <td data-label="User"><?= e($r['full_name']) ?></td>
              <td data-label="Amount"><?= fmt_money($r['amount_usd']) ?></td>
              <td data-label="Status"><span class="badge <?= $r['status']==='approved'?'green':'red' ?>"><?= e(ucfirst($r['status'])) ?></span></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
    <div class="card">
      <h3>Bank &mdash; recently reviewed</h3>
      <?php if (!$reviewedBank): ?>
        <p class="subtitle">No history yet.</p>
      <?php else: ?>
        <table>
          <thead><tr><th>User</th><th>Amount</th><th>Status</th></tr></thead>
          <tbody>
          <?php foreach ($reviewedBank as $r): ?>
            <tr>
              <td data-label="User"><?= e($r['full_name']) ?></td>
              <td data-label="Amount"><?= fmt_money($r['amount_usd']) ?></td>
              <td data-label="Status"><span class="badge <?= $r['status']==='approved'?'green':'red' ?>"><?= e(ucfirst($r['status'])) ?></span></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>