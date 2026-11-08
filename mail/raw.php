<?php
/** Renders a stored message body in an isolated iframe (sandboxed). */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/mail_client.php';

mail_require_login();

$id = (int)($_GET['id'] ?? 0);
$stmt = get_db()->prepare('SELECT body_html FROM mail_messages WHERE id = ?');
$stmt->execute([$id]);
$html = (string)$stmt->fetchColumn();

header('Content-Type: text/html; charset=UTF-8');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src data: https:; font-src data:;");
header('X-Content-Type-Options: nosniff');

echo $html !== '' ? $html : '<!DOCTYPE html><html><body style="font:13px sans-serif;color:#888;padding:18px;">(empty message)</body></html>';
