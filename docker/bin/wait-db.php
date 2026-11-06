<?php
// Exit 0 once the database accepts connections, 1 after ~120s.
require '/var/www/html/config/database.php';
if (!getenv('DB_HOST')) { fwrite(STDERR, "[hedgeinv] DB_HOST is not set - add your database details in Render > Environment\n"); exit(1); }
$dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
for ($i = 0; $i < 60; $i++) {
    try {
        new PDO($dsn, DB_USER, DB_PASS, db_pdo_options());
        echo "[hedgeinv] database is ready (" . DB_HOST . (DB_SSL ? ', encrypted' : '') . ")\n";
        exit(0);
    } catch (Throwable $e) {
        if ($i % 5 === 0) echo "[hedgeinv] waiting for database... ({$e->getMessage()})\n";
        sleep(2);
    }
}
exit(1);
