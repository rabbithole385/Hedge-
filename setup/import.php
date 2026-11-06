<?php
/**
 * One-time data import for hosts without secret files (e.g. Railway).
 * Works ONLY when:
 *   1. the IMPORT_TOKEN variable is set on the server, and
 *   2. the database has no users yet (a fresh install).
 * After a successful import the page switches itself off, because users now exist.
 * Remove IMPORT_TOKEN afterwards for good measure.
 */
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/sql_import.php';

$token = (string)getenv('IMPORT_TOKEN');
if ($token === '' || strlen($token) < 12) { http_response_code(404); exit('Not found'); }

function setup_pdo(): PDO {
    return new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, db_pdo_options());
}
try { $pdo = setup_pdo(); $users = hedgeinv_user_count($pdo); }
catch (Throwable $e) { http_response_code(503); exit('Database not reachable yet. Check the DB_* variables.'); }
if ($users > 0) { http_response_code(404); exit('Not found'); }

$msg = ''; $errs = []; $done = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($token, (string)($_POST['token'] ?? ''))) {
        sleep(2);
        $msg = 'Wrong import token.';
    } elseif (empty($_FILES['dump']) || $_FILES['dump']['error'] !== UPLOAD_ERR_OK) {
        $msg = 'Upload failed (code ' . (int)($_FILES['dump']['error'] ?? -1) . '). The file must be under 16 MB; zip it as .sql.gz if larger.';
    } else {
        set_time_limit(0);
        $sql = file_get_contents($_FILES['dump']['tmp_name']);
        @unlink($_FILES['dump']['tmp_name']);
        if (substr($sql, 0, 2) === "\x1f\x8b") $sql = (string)@gzdecode($sql);
        if (stripos($sql, 'CREATE TABLE') === false) {
            $msg = 'That file does not look like a database export (.sql).';
        } else {
            hedgeinv_drop_all_tables($pdo);           // clear the empty starter tables
            [$ok, $errs] = hedgeinv_import_sql($pdo, $sql);
            unset($sql);
            try { get_db(); } catch (Throwable $e) {}  // apply the app's schema upgrades to the imported data
            $users = hedgeinv_user_count(setup_pdo());
            $done = $users > 0;
            $msg = $done
                ? "Import complete: {$ok} statements, {$users} users. This page is now switched off. You can delete IMPORT_TOKEN."
                : "Import ran ({$ok} statements) but no users were found. Check the errors below.";
        }
    }
}
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow"><title>Data import</title>
<style>
body{font-family:system-ui,sans-serif;background:#0b1220;color:#e5e7eb;display:flex;justify-content:center;padding:40px 16px;margin:0}
.c{background:#111a2e;border:1px solid #23314f;border-radius:14px;padding:28px;max-width:520px;width:100%}
h1{font-size:20px;margin:0 0 8px}p{color:#9aa4b8;font-size:14px;line-height:1.5}
label{display:block;font-size:13px;margin:16px 0 6px}input{width:100%;box-sizing:border-box;padding:10px;border-radius:8px;border:1px solid #2c3b5c;background:#0b1220;color:#e5e7eb}
button{margin-top:20px;width:100%;padding:12px;border:0;border-radius:8px;background:#22c55e;color:#04120a;font-weight:700;font-size:15px;cursor:pointer}
.m{padding:12px;border-radius:8px;margin-top:16px;font-size:14px}.ok{background:#052e16;color:#86efac}.bad{background:#3f1d1d;color:#fca5a5}
pre{white-space:pre-wrap;font-size:12px;color:#fca5a5;max-height:240px;overflow:auto}
</style></head><body><div class="c">
<h1>Load your existing data</h1>
<p>Upload your database export (.sql or .sql.gz). This works only once, on a fresh database. Nothing existing is overwritten.</p>
<?php if ($msg): ?><div class="m <?= $done ? 'ok' : 'bad' ?>"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($errs): ?><pre><?= htmlspecialchars(implode("\n", array_slice($errs, 0, 30))) ?></pre><?php endif; ?>
<?php if (!$done): ?>
<form method="post" enctype="multipart/form-data" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='Importing… keep this tab open'">
<label>Import token (the IMPORT_TOKEN value you set)</label><input type="password" name="token" required autocomplete="off">
<label>Database file</label><input type="file" name="dump" accept=".sql,.gz" required>
<button type="submit">Import</button>
</form>
<?php else: ?><p><a style="color:#86efac" href="/admin/">Go to admin login</a></p><?php endif; ?>
</div></body></html>
