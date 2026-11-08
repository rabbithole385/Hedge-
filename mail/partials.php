<?php
/** Shared layout chrome for the standalone mail client (/mail). */

function mail_icon(string $name, int $size = 18): string {
    $paths = [
        'inbox'   => '<path d="M3 13h5l1.5 3h5L16 13h5M3 13 5 5h14l2 8v6H3z"/>',
        'star'    => '<path d="M12 3.5l2.6 5.3 5.9.9-4.3 4.2 1 5.8L12 17l-5.2 2.7 1-5.8L3.5 9.7l5.9-.9z"/>',
        'send'    => '<path d="M4 12l16-8-6 16-3-6-7-2z"/>',
        'trash'   => '<path d="M4 7h16M9 7V5h6v2M6 7l1 13h10l1-13"/>',
        'compose' => '<path d="M4 20h16M14 4l4 4-9 9H5v-4z"/>',
        'user'    => '<circle cx="12" cy="8" r="3.5"/><path d="M5 20c0-3.5 3-5.5 7-5.5s7 2 7 5.5"/>',
        'search'  => '<circle cx="11" cy="11" r="6.5"/><path d="M20 20l-4-4"/>',
        'back'    => '<path d="M15 5l-7 7 7 7"/>',
        'reply'   => '<path d="M9 8L4 12l5 4M4 12h9a7 7 0 017 7"/>',
    ];
    return '<svg class="ico" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($paths[$name] ?? '') . '</svg>';
}

function mail_layout_top(array $account, string $active, string $heading, string $sub = '', string $query = ''): void {
    $pdo   = get_db();
    $email = (string)$account['email'];
    $initial = strtoupper(substr($email, 0, 1) ?: 'N');
    $nav = [
        'inbox'   => ['Inbox',   'inbox',  mail_folder_count($pdo, 'inbox'),   mail_unread_count($pdo)],
        'starred' => ['Starred', 'star',   mail_folder_count($pdo, 'starred'), 0],
        'sent'    => ['Sent',    'send',   mail_folder_count($pdo, 'sent'),    0],
        'trash'   => ['Trash',   'trash',  mail_folder_count($pdo, 'trash'),   0],
    ];
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title><?= e($heading) ?> | NexoraCopy Mail</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Chakra+Petch:wght@500;600;700&family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css">
<link rel="stylesheet" href="/mail/assets/mail.css">
</head>
<body class="mail-body">
<div class="mail-shell">
  <aside class="mail-side">
    <div class="mail-brand">NexoraCopy <span class="nx-tag">Mail</span></div>
    <a class="mail-compose" href="/mail/compose.php"><?= mail_icon('compose', 17) ?> New message</a>

    <nav class="mail-nav">
      <div class="mail-nav-title">Mailbox</div>
      <?php foreach ($nav as $key => $item): ?>
        <a class="mail-link <?= $active === $key ? 'active' : '' ?>" href="/mail/?folder=<?= e($key) ?>">
          <?= mail_icon($item[1]) ?><span><?= e($item[0]) ?></span>
          <?php if ($item[3] > 0): ?><span class="count"><?= (int)$item[3] ?></span>
          <?php elseif ($item[2] > 0): ?><span class="count"><?= (int)$item[2] ?></span><?php endif; ?>
        </a>
      <?php endforeach; ?>
    </nav>

    <div class="mail-side-foot">
      <div class="mail-account">
        <div class="mail-avatar"><?= e($initial) ?></div>
        <div class="who">
          <div class="em"><?= e($email) ?></div>
          <div class="sub">Signed in</div>
        </div>
      </div>
      <div class="mail-side-actions">
        <a href="/mail/account.php">Account</a>
        <a href="/mail/logout.php">Sign out</a>
      </div>
    </div>
  </aside>

  <main class="mail-main">
    <div class="mail-topbar">
      <h1><?= e($heading) ?></h1>
      <?php if ($sub !== ''): ?><span class="sub"><?= e($sub) ?></span><?php endif; ?>
      <div class="spacer"></div>
      <form class="mail-search" method="get" action="/mail/">
        <span class="ico"><?= mail_icon('search', 15) ?></span>
        <?php if (in_array($active, ['starred', 'sent', 'trash'], true)): ?><input type="hidden" name="folder" value="<?= e($active) ?>"><?php endif; ?>
        <input type="search" name="q" value="<?= e($query) ?>" placeholder="Search mail">
      </form>
      <div class="tools"><a class="mail-btn primary" href="/mail/compose.php"><?= mail_icon('compose', 16) ?> Compose</a></div>
    </div>
    <div class="mail-content">
    <?php
}

function mail_layout_bottom(string $active = ''): void {
    ?>
    </div>
  </main>
</div>
<nav class="mail-tabbar">
  <a class="<?= $active === 'inbox' ? 'active' : '' ?>" href="/mail/?folder=inbox"><?= mail_icon('inbox', 18) ?>Inbox</a>
  <a class="<?= $active === 'starred' ? 'active' : '' ?>" href="/mail/?folder=starred"><?= mail_icon('star', 18) ?>Starred</a>
  <a class="new" href="/mail/compose.php"><?= mail_icon('compose', 18) ?>New</a>
  <a class="<?= $active === 'sent' ? 'active' : '' ?>" href="/mail/?folder=sent"><?= mail_icon('send', 18) ?>Sent</a>
  <a class="<?= $active === 'account' ? 'active' : '' ?>" href="/mail/account.php"><?= mail_icon('user', 18) ?>Account</a>
</nav>
</body>
</html>
    <?php
}
