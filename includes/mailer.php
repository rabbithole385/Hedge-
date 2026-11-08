<?php
require_once __DIR__ . '/../config/smtp_credentials.php';

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
    if (SMTP_HOST === '' || SMTP_HOST === 'smtp.example.com') {
        return false; // not configured yet - silent no-op
    }

    try {
        $transport = SMTP_ENCRYPTION === 'ssl' ? 'ssl://' : '';
        $socket = @stream_socket_client($transport . SMTP_HOST . ':' . SMTP_PORT, $errno, $errstr, 10);
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
