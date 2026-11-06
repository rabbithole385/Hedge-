<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$user = require_login();
require_kyc($user);
$pdo = get_db();

// Bank transfer deposits are handled manually: route the user straight into
// a support conversation instead of a self-serve form. Admin verifies the
// transfer with them there and credits the wallet by hand.
$threadId = get_or_create_open_support_thread(
    $pdo,
    (int)$user['id'],
    'Bank Transfer Deposit',
    "Hi, I'd like to make a deposit via bank transfer. Please send me the account details and next steps."
);

redirect('/user/support_thread.php?id=' . $threadId);
