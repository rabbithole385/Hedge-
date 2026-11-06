<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$admin = require_admin();
$pdo = get_db();
$result = null;
$errors = [];

// Clearly-fake test domain so nothing ever accidentally emails a real inbox.
define('TEST_EMAIL_DOMAIN', 'test.hedgeinv.local');

$testUsers = [
    ['Emma Tremblay', 62, 'approved'], ['Liam MacDonald', 58, 'approved'], ['Olivia Gagnon', 51, 'pending'],
    ['Noah Campbell', 47, 'none'], ['Sophie Belanger', 44, 'approved'], ['Ethan Fraser', 40, 'none'],
    ['Chloe Leblanc', 36, 'approved'], ['William Sinclair', 33, 'pending'], ['Ava Bouchard', 29, 'approved'],
    ['Jacob Douglas', 26, 'none'], ['Mia Robichaud', 22, 'approved'], ['Lucas Whitfield', 19, 'none'],
    ['Charlotte Roy', 16, 'approved'], ['Benjamin Cormier', 13, 'pending'], ['Amelia Gauthier', 10, 'none'],
    ['Mason Fortin', 8, 'approved'], ['Harper Levesque', 6, 'none'], ['Logan Pelletier', 4, 'approved'],
    ['Isla Comeau', 2, 'none'], ['Henry Ouellet', 1, 'pending'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'seed') {
    if (!csrf_verify()) {
        $errors[] = 'Session expired, please try again.';
    } else {
        $expertIds = array_column($pdo->query("SELECT id FROM experts WHERE status = 'active'")->fetchAll(), 'id');
        $botIds = array_column($pdo->query("SELECT id FROM bots WHERE status = 'active'")->fetchAll(), 'id');
        $planIds = array_column($pdo->query("SELECT id FROM investment_plans WHERE status = 'active'")->fetchAll(), 'id');

        $created = 0;
        $skipped = 0;

        foreach ($testUsers as $i => [$name, $daysAgo, $kycStatus]) {
            $slug = strtolower(str_replace(' ', '.', $name));
            $email = $slug . '@' . TEST_EMAIL_DOMAIN;

            $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $skipped++;
                continue; // already seeded - safe to re-run this tool
            }

            $walletBalance = round(mt_rand(200, 500000) / 100, 2); // $2.00 - $5,000.00
            $createdAt = date('Y-m-d H:i:s', strtotime("-{$daysAgo} days"));
            $referralCode = generate_referral_code($pdo);

            $pdo->prepare(
                'INSERT INTO users (full_name, email, password_hash, wallet_balance, kyc_status, referral_code, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([$name, $email, password_hash('TestUser123!', PASSWORD_DEFAULT), $walletBalance, $kycStatus, $referralCode, $createdAt]);
            $userId = (int)$pdo->lastInsertId();

            // Roughly two-thirds of test users have some kind of active position.
            if ($expertIds && mt_rand(1, 100) <= 65) {
                $expertId = $expertIds[array_rand($expertIds)];
                $amount = round(mt_rand(5000, 200000) / 100, 2);
                $pdo->prepare(
                    "INSERT INTO copies (user_id, expert_id, amount_allocated, current_value, status, started_at)
                     VALUES (?, ?, ?, ?, 'active', ?)"
                )->execute([$userId, $expertId, $amount, $amount, $createdAt]);
            }
            if ($botIds && mt_rand(1, 100) <= 40) {
                $botId = $botIds[array_rand($botIds)];
                $amount = round(mt_rand(5000, 150000) / 100, 2);
                $pdo->prepare(
                    "INSERT INTO bot_subscriptions (user_id, bot_id, amount_allocated, current_value, status, started_at)
                     VALUES (?, ?, ?, ?, 'active', ?)"
                )->execute([$userId, $botId, $amount, $amount, $createdAt]);
            }
            if ($planIds && mt_rand(1, 100) <= 30) {
                $planId = $planIds[array_rand($planIds)];
                $amount = round(mt_rand(10000, 300000) / 100, 2);
                $pdo->prepare(
                    "INSERT INTO plan_subscriptions (user_id, plan_id, amount_allocated, current_value, status, started_at)
                     VALUES (?, ?, ?, ?, 'active', ?)"
                )->execute([$userId, $planId, $amount, $amount, $createdAt]);
            }

            $created++;
        }

        log_admin_action($pdo, (int)$admin['id'], 'seed_test_data', "Seeded {$created} test users, skipped {$skipped} already present.");
        $result = "Done. Created {$created} test user(s), skipped {$skipped} already present (safe to re-run).";
    }
}

$stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email LIKE ?');
$stmt->execute(['%@' . TEST_EMAIL_DOMAIN]);
$existingTestCount = (int)$stmt->fetchColumn();

$pageTitle = 'Seed Test Data';
$navContext = 'admin';
require __DIR__ . '/../includes/header.php';
?>
<div class="container">
  <h1>Seed test data</h1>
  <p class="subtitle">
    Internal QA tool only. Creates up to <?= count($testUsers) ?> test user accounts (Canadian names, staggered join dates over the
    last ~2 months, clearly-fake <code><?= e(TEST_EMAIL_DOMAIN) ?></code> emails, password <code>TestUser123!</code> for all of them)
    with a realistic mix of copy/bot/plan activity and KYC statuses. Safe to click more than once - already-seeded users are skipped.
  </p>

  <?php if ($result): ?><div class="alert success"><?= e($result) ?></div><?php endif; ?>
  <?php foreach ($errors as $err): ?><div class="alert error"><?= e($err) ?></div><?php endforeach; ?>

  <div class="card">
    <p class="subtitle">Test users currently in the database: <strong><?= $existingTestCount ?></strong> / <?= count($testUsers) ?></p>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="seed">
      <button class="btn block" type="submit">Seed test users now</button>
    </form>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
