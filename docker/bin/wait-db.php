<?php
// Exit 0 once the database accepts connections, 1 after ~120s.
$host = getenv('DB_HOST'); $port = getenv('DB_PORT') ?: '3306';
if (!$host) { fwrite(STDERR, "[hedgeinv] DB_HOST not set\n"); exit(1); }
for ($i = 0; $i < 60; $i++) {
    try {
        new PDO("mysql:host={$host};port={$port};dbname=" . getenv('DB_NAME'), getenv('DB_USER'), getenv('DB_PASS'), [PDO::ATTR_TIMEOUT => 3]);
        echo "[hedgeinv] database is ready\n";
        exit(0);
    } catch (Throwable $e) {
        if ($i % 5 === 0) echo "[hedgeinv] waiting for database... ({$e->getMessage()})\n";
        sleep(2);
    }
}
exit(1);
