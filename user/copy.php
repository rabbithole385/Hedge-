<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$user = require_login();
require_kyc($user);
$pdo = get_db();

$expertId = (int)($_GET['expert_id'] ?? $_POST['expert_id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM experts WHERE id = ? AND status = 'active'");
$stmt->execute([$expertId]);
$expert = $stmt->fetch();

if (!$expert) {
    flash_set('error', 'Expert not found.');
    redirect('/user/experts.php');
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors[] = 'Session expired, please try again.';
    } else {
        $amount = (float)($_POST['amount'] ?? 0);

        if ($amount < $expert['min_copy_amount']) {
            $errors[] = 'Minimum copy amount for this trader is ' . fmt_money($expert['min_copy_amount']) . '.';
        } elseif ($amount > $user['wallet_balance']) {
            $errors[] = 'Insufficient wallet balance.';
        } else {
            $pdo->beginTransaction();
            try {
                $pdo->prepare(
                    'INSERT INTO copies (user_id, expert_id, amount_allocated, current_value, status) VALUES (?, ?, ?, ?, "active")'
                )->execute([$user['id'], $expert['id'], $amount, $amount]);

                apply_wallet_delta($pdo, $user['id'], -$amount, 'copy_allocate', 'Copy: ' . $expert['name']);
                $pdo->commit();

                flash_set('success', 'You are now copying ' . $expert['name'] . ' with ' . fmt_money($amount) . '.');
                redirect('/user/dashboard.php');
            } catch (Throwable $e) {
                $pdo->rollBack();
                $errors[] = 'Something went wrong. Please try again.';
            }
        }
    }
}

$pageTitle = 'Copy ' . $expert['name'];
$navContext = 'user';
require __DIR__ . '/../includes/header.php';
?>
<div class="container narrow">
  <div class="card">
    <h2>Copy <?= e($expert['name']) ?></h2>
    <p class="subtitle"><?= e($expert['bio']) ?></p>
    <p class="subtitle">Your wallet balance: <strong><?= fmt_money($user['wallet_balance']) ?></strong></p>

    <?php foreach ($errors as $err): ?>
      <div class="alert error"><?= e($err) ?></div>
    <?php endforeach; ?>

    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="expert_id" value="<?= (int)$expert['id'] ?>">
      <div class="form-group">
        <label>Amount to allocate (min <?= fmt_money($expert['min_copy_amount']) ?>)</label>
        <input type="number" step="0.01" name="amount" min="<?= (float)$expert['min_copy_amount'] ?>" required>
      </div>
      <button class="btn block" type="submit">Confirm copy</button>
    </form>
    <p class="subtitle" style="margin-top:14px;"><a href="/user/experts.php">&larr; Back to experts</a></p>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
