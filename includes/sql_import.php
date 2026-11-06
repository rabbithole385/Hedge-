<?php
/**
 * Runs a phpMyAdmin / mysqldump export against a PDO connection.
 * Works with MySQL 8, MariaDB and TiDB. Returns [statements_ok, errors[]].
 */
function hedgeinv_split_sql(string $sql): array {
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
        if (($c === '-' && substr($sql, $i, 3) === '-- ') || $c === '#') { $e = strpos($sql, "\n", $i); $i = $e === false ? $len : $e; $buf .= "\n"; continue; }
        if ($c === '/' && ($sql[$i + 1] ?? '') === '*' && ($sql[$i + 2] ?? '') !== '!') {
            $e = strpos($sql, '*/', $i + 2); $i = $e === false ? $len : $e + 1; continue; // plain or MariaDB-only (/*M!) comment
        }
        if ($c === ';') { $stmts[] = $buf; $buf = ''; continue; }
        $buf .= $c;
    }
    if (trim($buf) !== '') $stmts[] = $buf;
    return $stmts;
}

function hedgeinv_import_sql(PDO $pdo, string $sql): array {
    $pdo->exec("SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION', time_zone = '+00:00'");
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    $pdo->exec('SET UNIQUE_CHECKS = 0');
    $ok = 0; $errors = [];
    foreach (hedgeinv_split_sql($sql) as $s) {
        $s = trim($s);
        if ($s === '' || preg_match('/^(CREATE DATABASE|USE\s)/i', $s)) continue;
        try { $pdo->exec($s); $ok++; }
        catch (Throwable $e) {
            // Version-specific /*!...*/ SET lines may not apply on every server; data statements must.
            if (strpos($s, '/*!') === 0) continue;
            $errors[] = substr($e->getMessage(), 0, 200) . ' | ' . substr(preg_replace('/\s+/', ' ', $s), 0, 120);
        }
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    $pdo->exec('SET UNIQUE_CHECKS = 1');
    return [$ok, $errors];
}

/** Drops every table (used only when the database holds no users, i.e. a fresh install). */
function hedgeinv_drop_all_tables(PDO $pdo): void {
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM) as $t) {
        $pdo->exec(($t[1] === 'VIEW' ? 'DROP VIEW IF EXISTS `' : 'DROP TABLE IF EXISTS `') . str_replace('`', '``', $t[0]) . '`');
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
}

function hedgeinv_user_count(PDO $pdo): int {
    if (!$pdo->query("SHOW TABLES LIKE 'users'")->fetch()) return 0;
    return (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
}
