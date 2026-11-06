<?php
/**
 * Fill these in with your real SMTP details, then email sending will work
 * automatically - every notification (deposit approved, KYC decision,
 * referral bonus, etc.) already triggers an email via includes/mailer.php.
 *
 * Common values:
 * - Port 587 with SMTP_ENCRYPTION = 'tls' (STARTTLS) - most widely supported
 * - Port 465 with SMTP_ENCRYPTION = 'ssl' (implicit SSL)
 * - Port 25 with SMTP_ENCRYPTION = 'none' - rarely usable, often blocked by hosts
 *
 * Until these are filled in with a real host, email sending is skipped
 * silently (notifications still work fine in-app either way).
 */

define('SMTP_HOST', getenv('SMTP_HOST') !== false && getenv('SMTP_HOST') !== '' ? getenv('SMTP_HOST') : 'smtp.example.com');
define('SMTP_PORT', getenv('SMTP_PORT') !== false && getenv('SMTP_PORT') !== '' ? (int)getenv('SMTP_PORT') : 587);
define('SMTP_ENCRYPTION', getenv('SMTP_ENCRYPTION') !== false && getenv('SMTP_ENCRYPTION') !== '' ? getenv('SMTP_ENCRYPTION') : 'tls'); // 'tls' | 'ssl' | 'none'
define('SMTP_USERNAME', getenv('SMTP_USERNAME') !== false && getenv('SMTP_USERNAME') !== '' ? getenv('SMTP_USERNAME') : '');
define('SMTP_PASSWORD', getenv('SMTP_PASSWORD') !== false && getenv('SMTP_PASSWORD') !== '' ? getenv('SMTP_PASSWORD') : '');
define('SMTP_FROM_EMAIL', getenv('SMTP_FROM_EMAIL') !== false && getenv('SMTP_FROM_EMAIL') !== '' ? getenv('SMTP_FROM_EMAIL') : 'no-reply@hedgeinv.com');
define('SMTP_FROM_NAME', getenv('SMTP_FROM_NAME') !== false && getenv('SMTP_FROM_NAME') !== '' ? getenv('SMTP_FROM_NAME') : 'HedgeInv');
