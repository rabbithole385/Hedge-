<?php
/**
 * One-time import of a phpMyAdmin / mysqldump export into the configured database.
 *   php import.php /etc/secrets/import.sql
 */
require '/var/www/html/config/database.php';
require '/var/www/html/includes/sql_import.php';
$file = $argv[1] ?? '/etc/secrets/import.sql';
$sql = @file_get_contents($file);
if ($sql === false) { echo "[hedgeinv] cannot read {$file}\n"; exit(1); }
if (substr($sql, 0, 2) === "\x1f\x8b") $sql = gzdecode($sql);

$pdo = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, db_pdo_options());
[$ok, $errors] = hedgeinv_import_sql($pdo, $sql);
foreach ($errors as $e) echo "[hedgeinv] import error: {$e}\n";
echo "[hedgeinv] import finished: {$ok} statements, " . count($errors) . ' errors, ' . hedgeinv_user_count($pdo) . " users now in the database\n";
exit($errors ? 1 : 0);
