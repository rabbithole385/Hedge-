<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$admin = require_admin();
$pdo = get_db();

$method = $_GET['method'] ?? '';
$id = (int)($_GET['id'] ?? 0);
$table = $method === 'bank' ? 'bank_deposits' : 'crypto_deposits';

$stmt = $pdo->prepare("SELECT proof_path FROM {$table} WHERE id = ?");
$stmt->execute([$id]);
$proofPath = $stmt->fetchColumn();

if (!$proofPath) {
    http_response_code(404);
    exit('No proof file for this deposit.');
}

$path = __DIR__ . '/../assets/uploads/deposit_proofs/' . basename($proofPath);
if (!file_exists($path)) {
    http_response_code(404);
    exit('File not found.');
}

$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$mimeMap = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'pdf' => 'application/pdf'];
header('Content-Type: ' . ($mimeMap[$ext] ?? 'application/octet-stream'));
header('Content-Disposition: inline; filename="' . basename($path) . '"');
header('X-Content-Type-Options: nosniff');
readfile($path);