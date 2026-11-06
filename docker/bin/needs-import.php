<?php
// Exit 0 (= import) only when the database has no users yet. Never overwrites real data.
try {
    $pdo = new PDO('mysql:host=' . getenv('DB_HOST') . ';port=' . (getenv('DB_PORT') ?: '3306') . ';dbname=' . getenv('DB_NAME'), getenv('DB_USER'), getenv('DB_PASS'));
    if (!$pdo->query("SHOW TABLES LIKE 'users'")->fetch()) exit(0);
    exit((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0 ? 0 : 1);
} catch (Throwable $e) {
    exit(1);
}
