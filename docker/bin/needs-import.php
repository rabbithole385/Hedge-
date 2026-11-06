<?php
// Exit 0 (= import) only when the database has no users yet. Never overwrites real data.
require '/var/www/html/config/database.php';
try {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME, DB_USER, DB_PASS, db_pdo_options());
    if (!$pdo->query("SHOW TABLES LIKE 'users'")->fetch()) exit(0);
    exit((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0 ? 0 : 1);
} catch (Throwable $e) {
    exit(1);
}
