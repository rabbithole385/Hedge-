<?php
/**
 * Resend inbound webhook (event: email.received).
 * Point the Resend webhook at https://nexoracopy.net/mail/webhook.php
 * and paste the signing secret into Admin -> Settings -> Email delivery.
 *
 * The webhook payload only carries metadata; the message body is fetched
 * from the Resend receiving API and stored in mail_messages.
 */
require_once __DIR__ . '/../includes/mail_client.php';

header('Content-Type: application/json');

$payload = (string)file_get_contents('php://input');

$headers = [];
foreach ($_SERVER as $key => $value) {
    if (strpos($key, 'HTTP_') === 0) {
        $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = (string)$value;
    }
}

$verified = mail_verify_webhook($payload, $headers);
if ($verified === false) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'invalid signature']);
    exit;
}

$data = json_decode($payload, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid payload']);
    exit;
}

$type = (string)($data['type'] ?? '');
if ($type !== 'email.received') {
    echo json_encode(['ok' => true, 'skipped' => $type]);
    exit;
}

$body = $data['data'] ?? [];
$ok   = is_array($body) && mail_store_inbound($body);

echo json_encode(['ok' => $ok]);
