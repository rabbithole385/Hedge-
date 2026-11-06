<?php
/**
 * Copy uploads on THIS server (KYC documents, deposit proofs, photos) into the database,
 * so a server without a permanent disk (e.g. Render free plan) can restore them.
 * Run it once on your cPanel site, which shares the same database.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$admin = require_admin();
$pdo = get_db();
$dir = realpath(__DIR__ . '/../assets/uploads');
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS stored_files (
        path VARCHAR(255) NOT NULL PRIMARY KEY,
        data LONGBLOB NOT NULL,
        size INT NOT NULL,
        mtime INT NOT NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB"
);

$local = [];
if ($dir && is_dir($dir)) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile() || in_array($f->getFilename(), ['.htaccess', '.gitkeep'], true)) continue;
        $local[ltrim(str_replace('\\', '/', substr($f->getPathname(), strlen($dir))), '/')] = $f;
    }
}
$known = [];
foreach ($pdo->query('SELECT path, size FROM stored_files') as $r) $known[$r['path']] = (int)$r['size'];
$missing = array_filter($local, fn($f, $p) => !isset($known[$p]) || $known[$p] !== (int)$f->getSize(), ARRAY_FILTER_USE_BOTH);

$msg = $err = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $err = 'Session expired, please try again.';
    } else {
        $ins = $pdo->prepare('INSERT INTO stored_files (path, data, size, mtime) VALUES (?, ?, ?, ?)
                              ON DUPLICATE KEY UPDATE data = VALUES(data), size = VALUES(size), mtime = VALUES(mtime)');
        $n = 0; $skipped = 0;
        foreach ($missing as $rel => $f) {
            if ($f->getSize() > 15 * 1024 * 1024) { $skipped++; continue; }
            $ins->bindValue(1, $rel);
            $ins->bindValue(2, file_get_contents($f->getPathname()), PDO::PARAM_LOB);
            $ins->bindValue(3, (int)$f->getSize(), PDO::PARAM_INT);
            $ins->bindValue(4, (int)$f->getMTime(), PDO::PARAM_INT);
            try { $ins->execute(); $n++; } catch (Throwable $e) { $skipped++; }
        }
        log_admin_action($pdo, (int)$admin['id'], 'uploads_to_database', "Copied {$n} upload(s) into the database");
        $msg = "Copied {$n} file(s) into the database." . ($skipped ? " {$skipped} skipped (too large or rejected by the database)." : '');
        $missing = [];
    }
}

$pageTitle = 'Uploads Backup';
$navContext = 'admin';
require __DIR__ . '/../includes/header.php';
?>
<div class="container" style="max-width:900px;">
  <h1>Uploads backup</h1>
  <p class="subtitle">Copies the files uploaded to this server (KYC documents, deposit proofs and photos) into the database, so a server without a permanent disk, such as Render's free plan, can restore them. Run it once on your cPanel site. It's safe to run again: only new or changed files are copied.</p>
  <?php if ($msg): ?><div class="alert success"><?= e($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="alert error"><?= e($err) ?></div><?php endif; ?>
  <div class="grid cols-3" style="margin:18px 0;">
    <div class="stat-card"><div class="label">Files on this server</div><div class="value mono-num"><?= count($local) ?></div></div>
    <div class="stat-card"><div class="label">Already in database</div><div class="value mono-num"><?= count($known) ?></div></div>
    <div class="stat-card"><div class="label">To copy</div><div class="value mono-num"><?= count($missing) ?></div></div>
  </div>
  <form method="post">
    <?= csrf_field() ?>
    <button class="btn gold" type="submit" <?= $missing ? '' : 'disabled' ?>><?= $missing ? 'Copy ' . count($missing) . ' file(s) to the database' : 'Everything is already backed up' ?></button>
  </form>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
