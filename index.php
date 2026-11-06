<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

if (current_user()) redirect('/user/dashboard.php');

$pageTitle = 'Welcome';
$navContext = 'guest';
require __DIR__ . '/includes/header.php';
?>
<div class="container">
  <div class="card" style="text-align:center; padding:56px 28px;">
    <h1 style="font-size:32px;">Copy the best. Trade with confidence.</h1>
    <p class="subtitle" style="font-size:16px; max-width:560px; margin:12px auto 28px;">
      A copy-trading platform for stocks, gold and commodities.
      Allocate funds to expert traders, AI-driven bots, or self-directed plans,
      and track your portfolio's performance.
    </p>
    <div style="display:flex; gap:12px; justify-content:center;">
      <a class="btn" href="/auth/register.php">Get started free</a>
      <a class="btn secondary" href="/auth/login.php">Log in</a>
    </div>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
