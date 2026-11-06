<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$admin = require_admin();
$pdo = get_db();

$logs = $pdo->query(
    "SELECT l.*, a.username FROM admin_audit_log l
     JOIN admins a ON a.id = l.admin_id
     ORDER BY l.created_at DESC LIMIT 300"
)->fetchAll();

$pageTitle = 'Audit Log';
$navContext = 'admin';
require __DIR__ . '/../includes/header.php';
?>
<div class="container">
  <h1>Admin audit log</h1>
  <p class="subtitle">Every deposit approval/rejection, KYC decision, and manual copy adjustment is recorded here with who did it and when.</p>

  <div class="card">
    <?php if (!$logs): ?>
      <p class="subtitle">No actions logged yet.</p>
    <?php else: ?>
      <div class="table-scroll" style="max-height:600px;">
        <table>
          <thead><tr><th>Admin</th><th>Action</th><th>Details</th><th>Date</th></tr></thead>
          <tbody>
          <?php foreach ($logs as $l): ?>
            <tr>
              <td data-label="Admin"><?= e($l['username']) ?></td>
              <td data-label="Action"><span class="badge blue"><?= e(str_replace('_', ' ', $l['action'])) ?></span></td>
              <td data-label="Details"><?= e($l['details']) ?></td>
              <td data-label="Date"><?= e(date('M j, Y g:ia', strtotime($l['created_at']))) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
