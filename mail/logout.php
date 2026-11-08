<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/mail_client.php';

mail_logout();
redirect('/mail/login.php');
