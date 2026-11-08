<?php
require_once __DIR__ . '/../config/smtp_credentials.php';

/**
 * Resolve the active email transport configuration.
 * Priority: admin settings (DB) -> environment variables -> SMTP fallback.
 */
function email_transport_config(): array {
    static $cfg = null;
    if ($cfg !== null) return $cfg;

    $apiKey = $fromEmail = $fromName = '';
    $enabled = true;

    if (function_exists('get_db') && function_exists('get_setting')) {
        try {
            $pdo = get_db();
            $apiKey    = trim(get_setting($pdo, 'resend_api_key'));
            $fromEmail = trim(get_setting($pdo, 'resend_from_email'));
            $fromName  = trim(get_setting($pdo, 'resend_from_name'));
            $enabled   = !in_array(strtolower(trim(get_setting($pdo, 'resend_enabled', '1'))), ['0', 'false', 'no', 'off'], true);
        } catch (Throwable $e) {
            // DB not reachable - fall through to environment values below.
        }
    }

    $env = static function (string $key): string {
        $v = getenv($key);
        return $v === false ? '' : trim($v);
    };
    if ($apiKey === '')    $apiKey = $env('RESEND_API_KEY');
    if ($fromEmail === '') $fromEmail = $env('RESEND_FROM_EMAIL');
    if ($fromName === '')  $fromName = $env('RESEND_FROM_NAME');
    if ($fromName === '')  $fromName = SMTP_FROM_NAME;
    if ($fromEmail === '') $fromEmail = SMTP_FROM_EMAIL;

    $cfg = ['apiKey' => $apiKey, 'fromEmail' => $fromEmail, 'fromName' => $fromName, 'enabled' => $enabled];
    return $cfg;
}

/**
 * Send a single HTML email through Resend (https://resend.com).
 */
function resend_send_email(string $to, string $subject, string $bodyHtml, array $cfg): bool {
    $from = $cfg['fromName'] !== '' ? $cfg['fromName'] . ' <' . $cfg['fromEmail'] . '>' : $cfg['fromEmail'];
    $payload = json_encode([
        'from'    => $from,
        'to'      => [$to],
        'subject' => $subject,
        'html'    => $bodyHtml,
    ]);
    $headers  = ['Authorization: Bearer ' . $cfg['apiKey'], 'Content-Type: application/json'];
    $endpoint = 'https://api.resend.com/emails';

    if (function_exists('curl_init')) {
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 15,
        ]);
        $response = curl_exec($ch);
        $status   = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);
        if ($status >= 200 && $status < 300) return true;
        error_log('send_email/resend: HTTP ' . $status . ' - ' . ($response !== false && $response !== '' ? $response : $error));
        return false;
    }

    $context = stream_context_create(['http' => [
        'method'        => 'POST',
        'header'        => implode("\r\n", $headers),
        'content'       => $payload,
        'timeout'       => 15,
        'ignore_errors' => true,
    ]]);
    $response = @file_get_contents($endpoint, false, $context);
    if ($response === false) {
        error_log('send_email/resend: HTTP request failed (no cURL available).');
        return false;
    }
    $status = 0;
    if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) {
        $status = (int)$m[1];
    }
    if ($status >= 200 && $status < 300) return true;
    error_log('send_email/resend: HTTP ' . $status . ' - ' . $response);
    return false;
}

/**
 * Minimal SMTP client - no PHPMailer/Composer dependency, since this
 * environment has no package manager access. Supports AUTH LOGIN and
 * STARTTLS/implicit SSL, which covers the vast majority of SMTP providers
 * (Gmail, Mailgun, SendGrid, SMTP2GO, most cPanel mail accounts on port 587).
 *
 * Returns false (and logs to PHP's error log) on any failure - callers
 * should never let a failed email break the actual app action.
 */
function send_email(string $to, string $subject, string $bodyHtml): bool {
    $transport = email_transport_config();
    if ($transport['enabled'] && $transport['apiKey'] !== '') {
        return resend_send_email($to, $subject, $bodyHtml, $transport);
    }

    if (SMTP_HOST === '' || SMTP_HOST === 'smtp.example.com') {
        return false; // not configured yet - silent no-op
    }

    try {
        $scheme = SMTP_ENCRYPTION === 'ssl' ? 'ssl://' : '';
        $socket = @stream_socket_client($scheme . SMTP_HOST . ':' . SMTP_PORT, $errno, $errstr, 10);
        if (!$socket) {
            error_log('send_email: SMTP connect failed - ' . $errstr);
            return false;
        }
        stream_set_timeout($socket, 12);

        $readLine = function () use ($socket): string {
            $data = '';
            while (($line = fgets($socket, 515)) !== false) {
                $data .= $line;
                if (isset($line[3]) && $line[3] === ' ') break;
            }
            return $data;
        };
        $write = function (string $cmd) use ($socket) {
            fwrite($socket, $cmd . "\r\n");
        };

        $readLine(); // server greeting

        $write('EHLO hedgeinv.local');
        $readLine();

        if (SMTP_ENCRYPTION === 'tls') {
            $write('STARTTLS');
            $readLine();
            if (!@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                error_log('send_email: STARTTLS negotiation failed.');
                fclose($socket);
                return false;
            }
            $write('EHLO hedgeinv.local');
            $readLine();
        }

        if (SMTP_USERNAME !== '') {
            $write('AUTH LOGIN');
            $readLine();
            $write(base64_encode(SMTP_USERNAME));
            $readLine();
            $write(base64_encode(SMTP_PASSWORD));
            $authResponse = $readLine();
            if (strpos($authResponse, '235') === false) {
                error_log('send_email: SMTP auth failed - ' . trim($authResponse));
                fclose($socket);
                return false;
            }
        }

        $write('MAIL FROM:<' . SMTP_FROM_EMAIL . '>');
        $readLine();
        $write('RCPT TO:<' . $to . '>');
        $rcptResponse = $readLine();
        if (strpos($rcptResponse, '250') === false) {
            error_log('send_email: recipient rejected - ' . trim($rcptResponse));
            fclose($socket);
            return false;
        }

        $write('DATA');
        $readLine();

        $headers = 'From: ' . SMTP_FROM_NAME . ' <' . SMTP_FROM_EMAIL . ">\r\n";
        $headers .= 'To: <' . $to . ">\r\n";
        $headers .= 'Subject: ' . $subject . "\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";

        // Basic dot-stuffing per SMTP spec: a line starting with '.' must be escaped.
        $safeBody = preg_replace('/^\./m', '..', $bodyHtml);

        $write($headers . "\r\n" . $safeBody . "\r\n.");
        $finalResponse = $readLine();

        $write('QUIT');
        fclose($socket);

        return strpos($finalResponse, '250') !== false;
    } catch (Throwable $e) {
        error_log('send_email: exception - ' . $e->getMessage());
        return false;
    }
}

function send_notification_email(string $to, string $title, string $message): bool {
    $html = '<div style="font-family: Arial, sans-serif; max-width: 480px; margin: 0 auto;">'
        . '<h2 style="color: #0d2b5e;">' . htmlspecialchars($title) . '</h2>'
        . '<p style="color: #333; font-size: 15px;">' . nl2br(htmlspecialchars($message)) . '</p>'
        . '<p style="color: #999; font-size: 12px; margin-top: 24px;">NexoraCopy &mdash; this is an automated notification.</p>'
        . '</div>';
    return send_email($to, $title . ' - NexoraCopy', $html);
}
