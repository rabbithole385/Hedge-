<?php
/**
 * Keeps uploads (KYC documents, deposit proofs, expert/plan/bot photos) safe on hosts
 * without a persistent disk, such as Render's free plan, by mirroring them into the database.
 *   php files-sync.php down  -> restore every stored file onto this server (run at start-up)
 *   php files-sync.php up    -> save any new or changed upload into the database
 */
require '/var/www/html/config/database.php';
$dir = '/var/www/html/assets/uploads';
$mode = $argv[1] ?? 'up';
try {
    $pdo = get_db();
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS stored_files (
            path VARCHAR(255) NOT NULL PRIMARY KEY,
            data LONGBLOB NOT NULL,
            size INT NOT NULL,
            mtime INT NOT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB"
    );

    if ($mode === 'down') {
        $n = 0;
        $q = $pdo->query('SELECT path, data, mtime FROM stored_files');
        while ($r = $q->fetch()) {
            $rel = str_replace(['..', "\0"], '', $r['path']);
            $file = $dir . '/' . ltrim($rel, '/');
            if (is_file($file) && filesize($file) === strlen($r['data'])) continue;
            @mkdir(dirname($file), 0755, true);
            file_put_contents($file, $r['data']);
            @touch($file, (int)$r['mtime']);
            $n++;
        }
        @exec('chown -R www-data:www-data ' . escapeshellarg($dir));
        echo "[hedgeinv] restored {$n} uploaded file(s) from the database\n";
        exit(0);
    }

    // up: store new / changed files
    $known = [];
    foreach ($pdo->query('SELECT path, size, mtime FROM stored_files') as $r) $known[$r['path']] = [(int)$r['size'], (int)$r['mtime']];
    $ins = $pdo->prepare(
        'INSERT INTO stored_files (path, data, size, mtime) VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE data = VALUES(data), size = VALUES(size), mtime = VALUES(mtime)'
    );
    $n = 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile()) continue;
        $name = $f->getFilename();
        if ($name === '.htaccess' || $name === '.gitkeep' || $f->getSize() > 15 * 1024 * 1024) continue;
        $rel = ltrim(substr($f->getPathname(), strlen($dir)), '/');
        $sig = [(int)$f->getSize(), (int)$f->getMTime()];
        if (isset($known[$rel]) && $known[$rel] === $sig) continue;
        $data = file_get_contents($f->getPathname());
        if ($data === false) continue;
        $ins->bindValue(1, $rel);
        $ins->bindValue(2, $data, PDO::PARAM_LOB);
        $ins->bindValue(3, $sig[0], PDO::PARAM_INT);
        $ins->bindValue(4, $sig[1], PDO::PARAM_INT);
        $ins->execute();
        $n++;
    }
    if ($n) echo "[hedgeinv] saved {$n} new upload(s) to the database\n";
} catch (Throwable $e) {
    echo '[hedgeinv] file sync skipped: ' . $e->getMessage() . "\n";
}
