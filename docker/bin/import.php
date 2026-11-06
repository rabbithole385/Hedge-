<?php
/**
 * One-time import of a phpMyAdmin / mysqldump export into the configured database.
 * Works with MySQL 8, MariaDB and TiDB, over encrypted connections, using PHP's own driver.
 *   php import.php /etc/secrets/import.sql
 */
require '/var/www/html/config/database.php';
$file = $argv[1] ?? '/etc/secrets/import.sql';
$sql = @file_get_contents($file);
if ($sql === false) { echo "[hedgeinv] cannot read {$file}\n"; exit(1); }

$opts = db_pdo_options();
$opts[PDO::MYSQL_ATTR_INIT_COMMAND] = "SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION', time_zone = '+00:00'";
$pdo = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, $opts);
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
$pdo->exec('SET UNIQUE_CHECKS = 0');

// Split into statements on ';' outside quotes and comments.
$stmts = []; $buf = ''; $len = strlen($sql); $q = null;
for ($i = 0; $i < $len; $i++) {
    $c = $sql[$i];
    if ($q !== null) {
        $buf .= $c;
        if ($c === '\\' && $q !== '`') { if ($i + 1 < $len) $buf .= $sql[++$i]; continue; }
        if ($c === $q) $q = null;
        continue;
    }
    if ($c === "'" || $c === '"' || $c === '`') { $q = $c; $buf .= $c; continue; }
    if ($c === '-' && substr($sql, $i, 3) === '-- ' || ($c === '#')) { $e = strpos($sql, "\n", $i); $i = $e === false ? $len : $e; $buf .= "\n"; continue; }
    if ($c === '/' && ($sql[$i + 1] ?? '') === '*' && !in_array($sql[$i + 2] ?? '', ['!'], true)) {
        $e = strpos($sql, '*/', $i + 2); $i = $e === false ? $len : $e + 1; continue; // plain or MariaDB-only (/*M!) comment
    }
    if ($c === ';') { $stmts[] = $buf; $buf = ''; continue; }
    $buf .= $c;
}
if (trim($buf) !== '') $stmts[] = $buf;

$ok = 0; $fail = 0;
foreach ($stmts as $s) {
    $s = trim($s);
    if ($s === '' || preg_match('/^(CREATE DATABASE|USE\s)/i', $s)) continue;
    try { $pdo->exec($s); $ok++; }
    catch (Throwable $e) {
        // Version-specific /*!...*/ SET lines may not apply on every server; data statements must.
        if (strpos($s, '/*!') === 0) continue;
        $fail++;
        echo '[hedgeinv] import error: ' . substr($e->getMessage(), 0, 200) . ' | ' . substr(preg_replace('/\s+/', ' ', $s), 0, 120) . "\n";
    }
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
$users = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
echo "[hedgeinv] import finished: {$ok} statements, {$fail} errors, {$users} users now in the database\n";
exit($fail ? 1 : 0);
