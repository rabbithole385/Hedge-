<?php
/**
 * Standalone mail client used by the /mail app (its own login, separate from
 * the admin console). Outgoing mail goes through the Resend send API; incoming
 * mail arrives via the Resend receiving webhook (mail/webhook.php) and is kept
 * in the mail_messages table.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';

/* ---------------- mailbox login (its own session key) ---------------- */

function mail_account_current(): ?array {
    if (empty($_SESSION['mail_account_id'])) return null;
    $stmt = get_db()->prepare('SELECT * FROM mail_accounts WHERE id = ?');
    $stmt->execute([(int)$_SESSION['mail_account_id']]);
    $a = $stmt->fetch();
    return $a ?: null;
}

function mail_require_login(): array {
    $a = mail_account_current();
    if (!$a) {
        header('Location: /mail/login.php');
        exit;
    }
    return $a;
}

function mail_login(int $id): void {
    session_regenerate_id(true);
    $_SESSION['mail_account_id'] = $id;
}

function mail_logout(): void {
    unset($_SESSION['mail_account_id']);
}

/* ---------------- config + HTTP ---------------- */

function mail_api_key(): string {
    $key = '';
    if (function_exists('email_transport_config')) {
        $c = email_transport_config();
        $key = trim((string)($c['apiKey'] ?? ''));
    }
    if ($key === '') {
        $v = getenv('RESEND_API_KEY');
        $key = $v === false ? '' : trim($v);
    }
    return $key;
}

function mail_webhook_secret(): string {
    $s = '';
    try {
        $s = trim(get_setting(get_db(), 'resend_webhook_secret'));
    } catch (Throwable $e) {}
    if ($s === '') {
        $v = getenv('RESEND_WEBHOOK_SECRET');
        $s = $v === false ? '' : trim($v);
    }
    return $s;
}

function mail_http(string $method, string $url, array $headers = [], ?string $body = null): array {
    $headers = array_values($headers);
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $res    = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);
        return ['status' => $status, 'body' => $res === false ? '' : (string)$res, 'error' => $res === false ? $err : ''];
    }

    $ctx = stream_context_create(['http' => [
        'method'        => $method,
        'header'        => implode("\r\n", $headers),
        'content'       => $body ?? '',
        'timeout'       => 20,
        'ignore_errors' => true,
    ]]);
    $res    = @file_get_contents($url, false, $ctx);
    $status = 0;
    if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) {
        $status = (int)$m[1];
    }
    return ['status' => $status, 'body' => $res === false ? '' : (string)$res, 'error' => $res === false ? 'request failed' : ''];
}

/* ---------------- send ---------------- */

/** Returns ['ok' => bool, 'id' => ?string, 'error' => string]. */
function mail_send(string $fromEmail, string $fromName, string $to, string $subject, string $html, string $text = '', ?string $inReplyTo = null): array {
    $apiKey = mail_api_key();
    if ($apiKey === '') {
        return ['ok' => false, 'id' => null, 'error' => 'No Resend API key configured (Admin -> Settings, or the RESEND_API_KEY env var).'];
    }
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'id' => null, 'error' => 'That recipient address is not valid.'];
    }

    $payload = [
        'from'    => $fromName !== '' ? $fromName . ' <' . $fromEmail . '>' : $fromEmail,
        'to'      => [$to],
        'subject' => $subject,
        'html'    => $html,
    ];
    if ($text !== '') $payload['text'] = $text;
    if ($inReplyTo !== null && $inReplyTo !== '') {
        $payload['headers'] = ['In-Reply-To' => $inReplyTo, 'References' => $inReplyTo];
    }

    $r    = mail_http('POST', 'https://api.resend.com/emails',
        ['Authorization: Bearer ' . $apiKey, 'Content-Type: application/json'],
        (string)json_encode($payload));
    $data = json_decode($r['body'], true);

    if ($r['status'] >= 200 && $r['status'] < 300 && is_array($data) && !empty($data['id'])) {
        return ['ok' => true, 'id' => (string)$data['id'], 'error' => ''];
    }
    $msg = is_array($data) && isset($data['message']) ? (string)$data['message'] : trim($r['body'] . ' ' . $r['error']);
    return ['ok' => false, 'id' => null, 'error' => 'Resend error (' . $r['status'] . '): ' . $msg];
}

function mail_store_outbound(string $fromEmail, string $fromName, string $to, string $subject, string $html, ?string $resendId, string $error = ''): void {
    get_db()->prepare(
        'INSERT INTO mail_messages
            (resend_id, direction, folder, from_name, from_email, to_email, subject, body_html, body_text, snippet, is_read, send_error)
         VALUES (?, "out", "sent", ?, ?, ?, ?, ?, ?, ?, 1, ?)'
    )->execute([
        $resendId ?: null,
        $fromName, $fromEmail, $to, $subject, $html, strip_tags($html),
        mail_snippet($html, ''), $error !== '' ? mail_text_cut($error, 250) : null,
    ]);
}

/* ---------------- receive ---------------- */

function mail_fetch_received(string $emailId): ?array {
    $apiKey = mail_api_key();
    if ($apiKey === '') return null;
    $r = mail_http('GET', 'https://api.resend.com/emails/receiving/' . rawurlencode($emailId),
        ['Authorization: Bearer ' . $apiKey]);
    if ($r['status'] < 200 || $r['status'] >= 300) return null;
    $d = json_decode($r['body'], true);
    return is_array($d) ? $d : null;
}

/**
 * Verify the Svix signature Resend sends with every webhook.
 * Returns true/false, or null when no signing secret is configured yet.
 */
function mail_verify_webhook(string $payload, array $headers): ?bool {
    $secret = mail_webhook_secret();
    if ($secret === '') return null;

    $id  = (string)($headers['svix-id'] ?? '');
    $ts  = (string)($headers['svix-timestamp'] ?? '');
    $sig = (string)($headers['svix-signature'] ?? '');
    if ($id === '' || $ts === '' || $sig === '') return false;

    // Secret looks like "whsec_<base64>"; the HMAC key is the decoded part.
    $raw = strpos($secret, '_') !== false ? substr($secret, strpos($secret, '_') + 1) : $secret;
    $key = base64_decode($raw, true);
    if ($key === false) return false;

    $expected = base64_encode(hash_hmac('sha256', $id . '.' . $ts . '.' . $payload, $key, true));
    foreach (preg_split('/\s+/', trim($sig)) as $part) {
        if (strpos($part, 'v1,') === 0 && hash_equals($expected, substr($part, 3))) {
            return true;
        }
    }
    return false;
}

function mail_parse_from(string $raw): array {
    $raw = trim($raw);
    if ($raw === '') return ['', ''];
    if (preg_match('/^(.*)<([^>]+)>\s*$/s', $raw, $m)) {
        return [trim($m[1], " \t\"'"), trim($m[2])];
    }
    return ['', $raw];
}

/** UTF-8-safe substring that does not depend on the mbstring extension. */
function mail_text_cut(string $s, int $len): string {
    if ($len <= 0) return '';
    if (function_exists('mb_substr')) return mb_substr($s, 0, $len);
    $out = substr($s, 0, $len);
    return (string)preg_replace('/[\x80-\xBF]+$/', '', $out);
}

function mail_snippet(string $html, string $text, int $len = 180): string {
    $src = $text !== '' ? $text : strip_tags((string)preg_replace('#<(br|/p|/div|/tr|/h[1-6]|/li)[^>]*>#i', ' ', $html));
    $src = html_entity_decode($src, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $src = trim((string)preg_replace('/\s+/', ' ', $src));
    return mail_text_cut($src, $len);
}

/**
 * Persist a Resend "email.received" webhook payload. The webhook only carries
 * metadata, so the body is fetched from the receiving API. Idempotent.
 */
function mail_store_inbound(array $data): bool {
    $pdo     = get_db();
    $emailId = (string)($data['email_id'] ?? '');
    if ($emailId === '') return false;

    $exists = $pdo->prepare('SELECT id FROM mail_messages WHERE resend_id = ?');
    $exists->execute([$emailId]);
    if ($exists->fetchColumn()) return true;

    $full = mail_fetch_received($emailId);

    [$fromName, $fromEmail] = mail_parse_from((string)($data['from'] ?? ''));
    $to = array_map('strval', (array)($data['to'] ?? []));
    $cc = array_map('strval', (array)($data['cc'] ?? []));

    $subject = trim((string)($data['subject'] ?? ''));
    if ($subject === '') $subject = '(no subject)';

    $bodyHtml = is_array($full) ? (string)($full['html'] ?? '') : '';
    $bodyText = is_array($full) ? (string)($full['text'] ?? '') : '';
    $snippet  = mail_snippet($bodyHtml, $bodyText);
    if ($snippet === '') {
        $snippet = is_array($full) ? '(empty message)' : '(message body unavailable)';
    }

    $pdo->prepare(
        'INSERT IGNORE INTO mail_messages
            (resend_id, direction, folder, from_name, from_email, to_email, cc, subject, body_html, body_text, snippet, message_id, has_attachments, is_read)
         VALUES (?, "in", "inbox", ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)'
    )->execute([
        $emailId, $fromName, $fromEmail, implode(', ', $to), implode(', ', $cc), $subject,
        $bodyHtml, $bodyText, $snippet, (string)($data['message_id'] ?? ''),
        empty($data['attachments']) ? 0 : 1,
    ]);
    return true;
}

/* ---------------- list helpers ---------------- */

function mail_unread_count(PDO $pdo): int {
    return (int)$pdo->query("SELECT COUNT(*) FROM mail_messages WHERE folder = 'inbox' AND is_read = 0")->fetchColumn();
}

function mail_folder_count(PDO $pdo, string $folder): int {
    if ($folder === 'starred') {
        return (int)$pdo->query("SELECT COUNT(*) FROM mail_messages WHERE is_starred = 1 AND folder <> 'trash'")->fetchColumn();
    }
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM mail_messages WHERE folder = ?');
    $stmt->execute([$folder]);
    return (int)$stmt->fetchColumn();
}
