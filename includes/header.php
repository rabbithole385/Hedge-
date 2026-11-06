<?php
/** Expects optional $pageTitle and $navContext ('user'|'admin'|'guest') set before include. */
$pageTitle = $pageTitle ?? 'HedgeInv';
$navContext = $navContext ?? 'guest';

$headerPdo = null;
$headerUser = null;
$headerAdmin = null;
$headerUnreadCount = 0;
$headerRecentNotifications = [];
$headerBtc = null;
$headerEth = null;
$headerPendingDeposits = 0;
$headerPendingKyc = 0;
$headerUnreadSupportUser = 0;
$headerUnreadSupportAdmin = 0;

if ($navContext === 'user') {
    require_once __DIR__ . '/market_data.php';
    $headerPdo = get_db();
    $headerUser = current_user();
    if ($headerUser) {
        $headerUnreadCount = get_unread_notification_count($headerPdo, (int)$headerUser['id']);
        $stmt = $headerPdo->prepare('SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 6');
        $stmt->execute([$headerUser['id']]);
        $headerRecentNotifications = $stmt->fetchAll();
        $headerUnreadSupportUser = get_unread_support_count_for_user($headerPdo, (int)$headerUser['id']);
    }
    // Top-bar BTC/ETH use the same live feed as the investment plans,
    // falling back to the internal reference price if the feed is unavailable.
    // Same live source as the Markets page; plan quote cache as a fallback.
    $headerBtc = get_instrument_by_symbol($headerPdo, 'BTC');
    $headerEth = get_instrument_by_symbol($headerPdo, 'ETH');
    if (!$headerBtc || !instrument_is_live($headerBtc) || !$headerEth || !instrument_is_live($headerEth)) {
        $headerLive = get_live_quotes($headerPdo, ['BTC', 'ETH']);
        if ((!$headerBtc || !instrument_is_live($headerBtc)) && !empty($headerLive['BTC'])) $headerBtc = ['current_price' => $headerLive['BTC']['price']];
        if ((!$headerEth || !instrument_is_live($headerEth)) && !empty($headerLive['ETH'])) $headerEth = ['current_price' => $headerLive['ETH']['price']];
    }
} elseif ($navContext === 'admin') {
    $headerPdo = get_db();
    $headerAdmin = current_admin();
    if ($headerAdmin) {
        $headerPendingDeposits = (int)$headerPdo->query("SELECT COUNT(*) FROM crypto_deposits WHERE status='pending'")->fetchColumn()
            + (int)$headerPdo->query("SELECT COUNT(*) FROM bank_deposits WHERE status='pending'")->fetchColumn();
        $headerPendingKyc = (int)$headerPdo->query("SELECT COUNT(*) FROM kyc_submissions WHERE status='pending'")->fetchColumn();
        $headerUnreadSupportAdmin = get_unread_support_count_for_admin($headerPdo);
    }
}

$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
function nav_active(string $path, string $currentPath): string {
    return $currentPath === $path ? 'active' : '';
}

$showSidebarShell = ($navContext === 'user' && $headerUser) || ($navContext === 'admin' && $headerAdmin);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle) ?> | HedgeInv</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<?php if ($showSidebarShell && $navContext === 'user'): ?>
<div class="app-shell">
  <div class="sidebar-overlay" id="sidebarOverlay" onclick="document.getElementById('sidebarNav').classList.remove('open'); this.classList.remove('open');"></div>
  <aside class="sidebar" id="sidebarNav">
    <div class="sidebar-brand">Hedge<span>Inv</span></div>

    <div class="sidebar-section">
      <div class="sidebar-section-title">Overview</div>
      <a class="sidebar-link <?= nav_active('/user/dashboard.php', $currentPath) ?>" href="/user/dashboard.php">Dashboard</a>
      <a class="sidebar-link <?= nav_active('/user/transactions.php', $currentPath) ?>" href="/user/transactions.php">Account Statement</a>
      <a class="sidebar-link <?= nav_active('/user/portfolio.php', $currentPath) ?>" href="/user/portfolio.php">Portfolio</a>
    </div>

    <div class="sidebar-section">
      <div class="sidebar-section-title"><span class="live-dot"></span> Trading &amp; Markets</div>
      <a class="sidebar-link <?= nav_active('/user/markets.php', $currentPath) ?>" href="/user/markets.php">Live Markets <span class="mini-badge live">Live</span></a>
      <a class="sidebar-link <?= nav_active('/user/experts.php', $currentPath) ?>" href="/user/experts.php">Copy Trading <span class="mini-badge pro">Pro</span></a>
      <a class="sidebar-link <?= nav_active('/user/bots.php', $currentPath) ?>" href="/user/bots.php">AI Trading Bots <span class="mini-badge ai">AI</span></a>
      <a class="sidebar-link <?= nav_active('/user/plans.php', $currentPath) ?>" href="/user/plans.php">Investment Plans</a>
      <a class="sidebar-link <?= nav_active('/user/trade.php', $currentPath) ?>" href="/user/trade.php">Trade</a>
    </div>

    <div class="sidebar-section">
      <div class="sidebar-section-title">Market Intelligence</div>
      <a class="sidebar-link <?= nav_active('/user/leaderboard.php', $currentPath) ?>" href="/user/leaderboard.php">Leaderboard</a>
    </div>

    <div class="sidebar-section">
      <div class="sidebar-section-title">Wallet &amp; Funds</div>
      <a class="sidebar-link <?= nav_active('/user/deposit.php', $currentPath) ?>" href="/user/deposit.php">Deposit</a>
      <a class="sidebar-link <?= nav_active('/user/withdraw.php', $currentPath) ?>" href="/user/withdraw.php">Withdraw</a>
      <a class="sidebar-link <?= nav_active('/user/referrals.php', $currentPath) ?>" href="/user/referrals.php">Referrals</a>
      <a class="sidebar-link <?= nav_active('/user/kyc.php', $currentPath) ?>" href="/user/kyc.php">Verification</a>
    </div>

    <div class="sidebar-section">
      <div class="sidebar-section-title">Help</div>
      <a class="sidebar-link <?= nav_active('/user/support.php', $currentPath) ?>" href="/user/support.php">
        Support
        <?php if ($headerUnreadSupportUser > 0): ?><span class="mini-badge live"><?= $headerUnreadSupportUser ?></span><?php endif; ?>
      </a>
    </div>

    <div class="sidebar-section">
      <a class="sidebar-link" href="/auth/logout.php">Logout</a>
    </div>
  </aside>

  <div class="app-main">
    <div class="topbar">
      <button class="sidebar-toggle" type="button" onclick="document.getElementById('sidebarNav').classList.toggle('open'); document.getElementById('sidebarOverlay').classList.toggle('open');" aria-label="Menu">&#9776;</button>
      <span class="ticker-live"><span class="dot"></span> Live</span>
      <?php if ($headerBtc): ?><span class="ticker-item">BTC: <strong><?= fmt_money((float)$headerBtc['current_price']) ?></strong></span><?php endif; ?>
      <?php if ($headerEth): ?><span class="ticker-item">ETH: <strong><?= fmt_money((float)$headerEth['current_price']) ?></strong></span><?php endif; ?>
      <div class="topbar-spacer"></div>

      <div class="notif-bell-wrap">
        <button type="button" class="notif-bell" onclick="document.getElementById('notifDropdown').classList.toggle('open')" aria-label="Notifications">
          &#128276;
          <?php if ($headerUnreadCount > 0): ?><span class="notif-badge"><?= $headerUnreadCount > 9 ? '9+' : $headerUnreadCount ?></span><?php endif; ?>
        </button>
        <div id="notifDropdown" class="notif-dropdown">
          <div class="notif-dropdown-header">Notifications</div>
          <?php if (!$headerRecentNotifications): ?>
            <div class="notif-empty">No notifications yet.</div>
          <?php else: ?>
            <?php foreach ($headerRecentNotifications as $n): ?>
              <div class="notif-item <?= $n['is_read'] ? '' : 'unread' ?>">
                <div class="notif-title"><?= e($n['title']) ?></div>
                <?php if ($n['message']): ?><div class="notif-message"><?= e($n['message']) ?></div><?php endif; ?>
                <div class="notif-time"><?= e(time_ago($n['created_at'])) ?></div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
          <a href="/user/notifications.php" class="notif-view-all">View all</a>
        </div>
      </div>

      <div class="ticker-balance">
        <div class="label">Account Balance</div>
        <div class="value"><?= fmt_money((float)$headerUser['wallet_balance']) ?></div>
      </div>
    </div>
    <div class="app-content">
<?php elseif ($showSidebarShell && $navContext === 'admin'): ?>
<div class="app-shell">
  <div class="sidebar-overlay" id="sidebarOverlay" onclick="document.getElementById('sidebarNav').classList.remove('open'); this.classList.remove('open');"></div>
  <aside class="sidebar" id="sidebarNav">
    <div class="sidebar-brand">Hedge<span>Inv</span> <span style="font-size:11px; color:var(--muted); font-weight:500;">Admin</span></div>

    <div class="sidebar-section">
      <div class="sidebar-section-title">Overview</div>
      <a class="sidebar-link <?= nav_active('/admin/dashboard.php', $currentPath) ?>" href="/admin/dashboard.php">Dashboard</a>
      <a class="sidebar-link <?= nav_active('/admin/audit_log.php', $currentPath) ?>" href="/admin/audit_log.php">Audit Log</a>
    </div>

    <div class="sidebar-section">
      <div class="sidebar-section-title"><span class="live-dot"></span> Trading Tools</div>
      <a class="sidebar-link <?= nav_active('/admin/experts.php', $currentPath) ?>" href="/admin/experts.php">Experts</a>
      <a class="sidebar-link <?= nav_active('/admin/trades.php', $currentPath) ?>" href="/admin/trades.php">Post Trade</a>
      <a class="sidebar-link <?= nav_active('/admin/copies.php', $currentPath) ?>" href="/admin/copies.php">Adjust Copies</a>
      <a class="sidebar-link <?= nav_active('/admin/bots.php', $currentPath) ?>" href="/admin/bots.php">Bots <span class="mini-badge ai">AI</span></a>
      <a class="sidebar-link <?= nav_active('/admin/plans.php', $currentPath) ?>" href="/admin/plans.php">Plans</a>
      <a class="sidebar-link <?= nav_active('/admin/instruments.php', $currentPath) ?>" href="/admin/instruments.php">Instruments</a>
      <a class="sidebar-link <?= nav_active('/admin/reconcile.php', $currentPath) ?>" href="/admin/reconcile.php">Balance Correction</a>
      <a class="sidebar-link <?= nav_active('/admin/storage_sync.php', $currentPath) ?>" href="/admin/storage_sync.php">Uploads Backup</a>
    </div>

    <div class="sidebar-section">
      <div class="sidebar-section-title">Wallet &amp; Compliance</div>
      <a class="sidebar-link <?= nav_active('/admin/deposits.php', $currentPath) ?>" href="/admin/deposits.php">
        Deposits
        <?php if ($headerPendingDeposits > 0): ?><span class="mini-badge live"><?= $headerPendingDeposits ?></span><?php endif; ?>
      </a>
      <a class="sidebar-link <?= nav_active('/admin/kyc.php', $currentPath) ?>" href="/admin/kyc.php">
        KYC
        <?php if ($headerPendingKyc > 0): ?><span class="mini-badge live"><?= $headerPendingKyc ?></span><?php endif; ?>
      </a>
      <a class="sidebar-link <?= nav_active('/admin/users.php', $currentPath) ?>" href="/admin/users.php">Users</a>
    </div>

    <div class="sidebar-section">
      <div class="sidebar-section-title">Help</div>
      <a class="sidebar-link <?= nav_active('/admin/livechat.php', $currentPath) ?>" href="/admin/livechat.php">
        Live Chat Console <span class="mini-badge live" style="background:#10b981;">Live</span>
      </a>
      <a class="sidebar-link <?= nav_active('/admin/support.php', $currentPath) ?>" href="/admin/support.php">
        Support Inbox
        <?php if ($headerUnreadSupportAdmin > 0): ?><span class="mini-badge live"><?= $headerUnreadSupportAdmin ?></span><?php endif; ?>
      </a>
    </div>

    <div class="sidebar-section">
      <div class="sidebar-section-title">System</div>
      <a class="sidebar-link <?= nav_active('/admin/settings.php', $currentPath) ?>" href="/admin/settings.php">Settings</a>
      <a class="sidebar-link <?= nav_active('/admin/seed_test_data.php', $currentPath) ?>" href="/admin/seed_test_data.php">Seed Test Data</a>
    </div>

    <div class="sidebar-section">
      <a class="sidebar-link" href="/admin/logout.php">Logout</a>
    </div>
  </aside>

  <div class="app-main">
    <div class="topbar">
      <button class="sidebar-toggle" type="button" onclick="document.getElementById('sidebarNav').classList.toggle('open'); document.getElementById('sidebarOverlay').classList.toggle('open');" aria-label="Menu">&#9776;</button>
      <span class="ticker-live"><span class="dot"></span> Admin console</span>
      <div class="topbar-spacer"></div>
      <?php if ($headerPendingDeposits > 0): ?>
        <a href="/admin/deposits.php" class="ticker-item"><span class="mini-badge live"><?= $headerPendingDeposits ?></span> deposits pending</a>
      <?php endif; ?>
      <?php if ($headerPendingKyc > 0): ?>
        <a href="/admin/kyc.php" class="ticker-item"><span class="mini-badge live"><?= $headerPendingKyc ?></span> KYC pending</a>
      <?php endif; ?>
      <?php if ($headerUnreadSupportAdmin > 0): ?>
        <a href="/admin/support.php" class="ticker-item"><span class="mini-badge live"><?= $headerUnreadSupportAdmin ?></span> support unread</a>
      <?php endif; ?>
      <div class="ticker-balance">
        <div class="label">Signed in as</div>
        <div class="value" style="font-size:14px;"><?= e($headerAdmin['username']) ?></div>
      </div>
    </div>
    <div class="app-content">
<?php else: ?>
<header class="navbar">
  <div class="brand">Hedge<span>Inv</span></div>
  <button class="nav-toggle" type="button" onclick="document.getElementById('mainNav').classList.toggle('open')" aria-label="Menu">&#9776;</button>
  <nav id="mainNav">
    <a href="/auth/login.php">Login</a>
    <a href="/auth/register.php">Register</a>
  </nav>
</header>
<?php endif; ?>