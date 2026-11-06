<?php
/**
 * Real-Time Live Chat API for HedgeInv
 * High-performance, lightweight AJAX polling & presence API optimized for cPanel (PHP + MySQL).
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/chat_bot.php';

$pdo = get_db();
$admin = current_admin();
$user = current_user();

$action = $_REQUEST['action'] ?? 'init';

function json_out(array $data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function get_client_session_token(): string {
    $token = trim($_REQUEST['token'] ?? '');
    if ($token !== '' && preg_match('/^[a-f0-9]{32,64}$/i', $token)) {
        return $token;
    }
    if (!empty($_COOKIE['hedgeinv_chat_token']) && preg_match('/^[a-f0-9]{32,64}$/i', $_COOKIE['hedgeinv_chat_token'])) {
        return $_COOKIE['hedgeinv_chat_token'];
    }
    $newToken = bin2hex(random_bytes(16));
    @setcookie('hedgeinv_chat_token', $newToken, [
        'expires' => time() + (86400 * 90),
        'path' => '/',
        'httponly' => false,
        'samesite' => 'Lax',
    ]);
    return $newToken;
}

try {
    /* ---------------- ACTION: INIT ---------------- */
    if ($action === 'init') {
        $threadId = (int)($_REQUEST['thread_id'] ?? 0);
        $thread = null;
        $role = 'guest';

        if ($admin) {
            $role = 'admin';
            if ($threadId > 0) {
                $stmt = $pdo->prepare('SELECT t.*, u.full_name, u.email, u.wallet_balance, u.kyc_status, u.created_at AS user_created_at FROM support_threads t LEFT JOIN users u ON u.id = t.user_id WHERE t.id = ?');
                $stmt->execute([$threadId]);
                $thread = $stmt->fetch();
            }
            if (!$thread) {
                // Pick most recent active or unread thread
                $thread = $pdo->query('SELECT t.*, u.full_name, u.email, u.wallet_balance, u.kyc_status, u.created_at AS user_created_at FROM support_threads t LEFT JOIN users u ON u.id = t.user_id ORDER BY t.unread_by_admin DESC, t.last_message_at DESC LIMIT 1')->fetch();
            }
            if ($thread) {
                $pdo->prepare('UPDATE support_threads SET admin_last_seen = NOW(), unread_by_admin = 0 WHERE id = ?')->execute([$thread['id']]);
            }
        } elseif ($user) {
            $role = 'user';
            // Find latest open thread for this user
            $stmt = $pdo->prepare("SELECT * FROM support_threads WHERE user_id = ? AND status = 'open' ORDER BY id DESC LIMIT 1");
            $stmt->execute([$user['id']]);
            $thread = $stmt->fetch();

            if (!$thread) {
                // Create a new thread
                $pdo->prepare("INSERT INTO support_threads (user_id, subject, status, bot_active, unread_by_admin, unread_by_user, user_last_seen) VALUES (?, 'Live Support Chat', 'open', 1, 0, 0, NOW())")->execute([$user['id']]);
                $newId = (int)$pdo->lastInsertId();
                $stmt = $pdo->prepare("SELECT * FROM support_threads WHERE id = ?");
                $stmt->execute([$newId]);
                $thread = $stmt->fetch();

                // Seed welcome message
                $welcome = bot_welcome_payload($user['full_name']);
                $pdo->prepare("INSERT INTO support_messages (thread_id, sender_type, sender_label, is_bot, message) VALUES (?, 'admin', ?, 1, ?)")->execute([$newId, $welcome['sender_label'], $welcome['message']]);
            } else {
                $pdo->prepare('UPDATE support_threads SET user_last_seen = NOW(), unread_by_user = 0 WHERE id = ?')->execute([$thread['id']]);
            }
        } else {
            // Guest visitor
            $token = get_client_session_token();
            $stmt = $pdo->prepare("SELECT * FROM support_threads WHERE session_token = ? AND status = 'open' ORDER BY id DESC LIMIT 1");
            $stmt->execute([$token]);
            $thread = $stmt->fetch();

            if (!$thread) {
                $pdo->prepare("INSERT INTO support_threads (session_token, subject, status, bot_active, unread_by_admin, unread_by_user, user_last_seen) VALUES (?, 'Guest Live Chat', 'open', 1, 0, 0, NOW())")->execute([$token]);
                $newId = (int)$pdo->lastInsertId();
                $stmt = $pdo->prepare("SELECT * FROM support_threads WHERE id = ?");
                $stmt->execute([$newId]);
                $thread = $stmt->fetch();

                // Seed welcome message
                $welcome = bot_welcome_payload('Visitor');
                $pdo->prepare("INSERT INTO support_messages (thread_id, sender_type, sender_label, is_bot, message) VALUES (?, 'admin', ?, 1, ?)")->execute([$newId, $welcome['sender_label'], $welcome['message']]);
            } else {
                $pdo->prepare('UPDATE support_threads SET user_last_seen = NOW(), unread_by_user = 0 WHERE id = ?')->execute([$thread['id']]);
            }
        }

        if (!$thread) {
            json_out(['ok' => true, 'role' => $role, 'thread' => null, 'messages' => []]);
        }

        // Fetch messages for thread
        $stmt = $pdo->prepare('SELECT id, thread_id, sender_type, sender_label, is_bot, message, created_at FROM support_messages WHERE thread_id = ? ORDER BY id ASC');
        $stmt->execute([$thread['id']]);
        $messages = $stmt->fetchAll();

        // Calculate typing & presence
        $now = time();
        $userTyping = !empty($thread['user_typing_at']) && ($now - strtotime($thread['user_typing_at'])) <= 4;
        $adminTyping = !empty($thread['admin_typing_at']) && ($now - strtotime($thread['admin_typing_at'])) <= 4;
        $userOnline = !empty($thread['user_last_seen']) && ($now - strtotime($thread['user_last_seen'])) <= 35;
        $adminOnline = !empty($thread['admin_last_seen']) && ($now - strtotime($thread['admin_last_seen'])) <= 35;

        $userInfo = null;
        if ($admin && !empty($thread['user_id'])) {
            $userInfo = [
                'user_id' => (int)$thread['user_id'],
                'full_name' => $thread['full_name'] ?? 'User #' . $thread['user_id'],
                'email' => $thread['email'] ?? 'N/A',
                'balance' => fmt_money((float)($thread['wallet_balance'] ?? 0)),
                'kyc_status' => $thread['kyc_status'] ?? 'none',
                'created_at' => !empty($thread['user_created_at']) ? date('M j, Y', strtotime($thread['user_created_at'])) : 'N/A',
            ];
        }

        json_out([
            'ok' => true,
            'role' => $role,
            'token' => $thread['session_token'] ?? null,
            'thread' => [
                'id' => (int)$thread['id'],
                'subject' => $thread['subject'],
                'status' => $thread['status'],
                'bot_active' => (bool)$thread['bot_active'],
                'user_typing' => $userTyping,
                'admin_typing' => $adminTyping,
                'user_online' => $userOnline,
                'admin_online' => $adminOnline,
                'user_name' => !empty($thread['full_name']) ? $thread['full_name'] : ($thread['guest_name'] ?? 'Guest Visitor'),
            ],
            'user_info' => $userInfo,
            'messages' => $messages,
        ]);
    }

    /* ---------------- ACTION: POLL ---------------- */
    if ($action === 'poll') {
        $threadId = (int)($_REQUEST['thread_id'] ?? 0);
        $afterId = (int)($_REQUEST['after_id'] ?? 0);

        if ($threadId <= 0) {
            json_out(['ok' => false, 'error' => 'Missing thread_id'], 400);
        }

        $stmt = $pdo->prepare('SELECT id, status, bot_active, user_typing_at, admin_typing_at, user_last_seen, admin_last_seen, unread_by_admin, unread_by_user FROM support_threads WHERE id = ?');
        $stmt->execute([$threadId]);
        $thread = $stmt->fetch();

        if (!$thread) {
            json_out(['ok' => false, 'error' => 'Thread not found'], 404);
        }

        $now = time();
        $isTyping = false;
        $otherOnline = false;

        if ($admin) {
            // Admin is polling: check user typing & presence
            $pdo->prepare('UPDATE support_threads SET admin_last_seen = NOW(), unread_by_admin = 0 WHERE id = ?')->execute([$threadId]);
            $isTyping = !empty($thread['user_typing_at']) && ($now - strtotime($thread['user_typing_at'])) <= 4;
            $otherOnline = !empty($thread['user_last_seen']) && ($now - strtotime($thread['user_last_seen'])) <= 35;
        } else {
            // User / Guest is polling: check admin typing & presence
            $pdo->prepare('UPDATE support_threads SET user_last_seen = NOW(), unread_by_user = 0 WHERE id = ?')->execute([$threadId]);
            $isTyping = !empty($thread['admin_typing_at']) && ($now - strtotime($thread['admin_typing_at'])) <= 4;
            $otherOnline = !empty($thread['admin_last_seen']) && ($now - strtotime($thread['admin_last_seen'])) <= 35;
        }

        // Fetch new messages
        $stmt = $pdo->prepare('SELECT id, thread_id, sender_type, sender_label, is_bot, message, created_at FROM support_messages WHERE thread_id = ? AND id > ? ORDER BY id ASC');
        $stmt->execute([$threadId, $afterId]);
        $newMessages = $stmt->fetchAll();

        json_out([
            'ok' => true,
            'messages' => $newMessages,
            'is_typing' => $isTyping,
            'other_online' => $otherOnline,
            'bot_active' => (bool)$thread['bot_active'],
            'status' => $thread['status'],
        ]);
    }

    /* ---------------- ACTION: SEND ---------------- */
    if ($action === 'send') {
        $threadId = (int)($_REQUEST['thread_id'] ?? 0);
        $message = trim($_REQUEST['message'] ?? '');

        if ($threadId <= 0 || $message === '') {
            json_out(['ok' => false, 'error' => 'Message or thread_id cannot be blank'], 400);
        }

        $stmt = $pdo->prepare('SELECT * FROM support_threads WHERE id = ?');
        $stmt->execute([$threadId]);
        $thread = $stmt->fetch();

        if (!$thread) {
            json_out(['ok' => false, 'error' => 'Thread not found'], 404);
        }

        if ($admin) {
            // Admin sending message
            $senderLabel = 'Support Specialist';
            $pdo->prepare("INSERT INTO support_messages (thread_id, sender_type, sender_label, is_bot, message) VALUES (?, 'admin', ?, 0, ?)")->execute([$threadId, $senderLabel, $message]);
            $msgId = (int)$pdo->lastInsertId();

            // Clear admin typing, disengage bot so admin takes direct control
            $pdo->prepare("UPDATE support_threads SET admin_typing_at = NULL, last_message_at = NOW(), unread_by_user = 1, unread_by_admin = 0, bot_active = 0 WHERE id = ?")->execute([$threadId]);

            // Notify user if registered
            if (!empty($thread['user_id'])) {
                create_notification($pdo, (int)$thread['user_id'], 'support_reply', 'New reply from Live Support', mb_strimwidth($message, 0, 140, '...'), '/user/support_thread.php?id=' . $threadId);
            }

            json_out([
                'ok' => true,
                'sent' => [
                    'id' => $msgId,
                    'thread_id' => $threadId,
                    'sender_type' => 'admin',
                    'sender_label' => $senderLabel,
                    'is_bot' => 0,
                    'message' => $message,
                    'created_at' => date('Y-m-d H:i:s'),
                ],
                'bot_reply' => null,
            ]);
        } else {
            // User or Guest sending message
            $senderLabel = $user ? $user['full_name'] : ($thread['guest_name'] ?? 'You');
            $pdo->prepare("INSERT INTO support_messages (thread_id, sender_type, sender_label, is_bot, message) VALUES (?, 'user', ?, 0, ?)")->execute([$threadId, $senderLabel, $message]);
            $msgId = (int)$pdo->lastInsertId();

            // Clear user typing, mark unread for admin
            $pdo->prepare("UPDATE support_threads SET user_typing_at = NULL, last_message_at = NOW(), unread_by_admin = 1, unread_by_user = 0 WHERE id = ?")->execute([$threadId]);

            $botReply = null;
            $chips = [];

            // If bot is active, process automatic response
            if ((int)$thread['bot_active'] === 1) {
                $botResult = process_bot_reply($pdo, $threadId, $message, $user);
                $botText = $botResult['reply'];
                $chips = $botResult['chips'] ?? [];

                $pdo->prepare("INSERT INTO support_messages (thread_id, sender_type, sender_label, is_bot, message) VALUES (?, 'admin', 'HedgeInv Assistant (AI)', 1, ?)")->execute([$threadId, $botText]);
                $botMsgId = (int)$pdo->lastInsertId();

                $botReply = [
                    'id' => $botMsgId,
                    'thread_id' => $threadId,
                    'sender_type' => 'admin',
                    'sender_label' => 'HedgeInv Assistant (AI)',
                    'is_bot' => 1,
                    'message' => $botText,
                    'chips' => $chips,
                    'created_at' => date('Y-m-d H:i:s'),
                ];
            }

            json_out([
                'ok' => true,
                'sent' => [
                    'id' => $msgId,
                    'thread_id' => $threadId,
                    'sender_type' => 'user',
                    'sender_label' => $senderLabel,
                    'is_bot' => 0,
                    'message' => $message,
                    'created_at' => date('Y-m-d H:i:s'),
                ],
                'bot_reply' => $botReply,
            ]);
        }
    }

    /* ---------------- ACTION: TYPING ---------------- */
    if ($action === 'typing') {
        $threadId = (int)($_REQUEST['thread_id'] ?? 0);
        if ($threadId <= 0) {
            json_out(['ok' => false], 400);
        }

        if ($admin) {
            $pdo->prepare('UPDATE support_threads SET admin_typing_at = NOW() WHERE id = ?')->execute([$threadId]);
        } else {
            $pdo->prepare('UPDATE support_threads SET user_typing_at = NOW() WHERE id = ?')->execute([$threadId]);
        }

        json_out(['ok' => true]);
    }

    /* ---------------- ACTION: TOGGLE_BOT (Admin Only) ---------------- */
    if ($action === 'toggle_bot') {
        if (!$admin) {
            json_out(['ok' => false, 'error' => 'Unauthorized'], 403);
        }
        $threadId = (int)($_REQUEST['thread_id'] ?? 0);
        $state = (int)($_REQUEST['bot_active'] ?? 0);

        $pdo->prepare('UPDATE support_threads SET bot_active = ? WHERE id = ?')->execute([$state, $threadId]);
        json_out(['ok' => true, 'bot_active' => (bool)$state]);
    }

    /* ---------------- ACTION: ADMIN_INBOX (Admin Only) ---------------- */
    if ($action === 'admin_inbox') {
        if (!$admin) {
            json_out(['ok' => false, 'error' => 'Unauthorized'], 403);
        }

        $now = time();
        $threads = $pdo->query(
            "SELECT t.*, u.full_name, u.email,
             (SELECT message FROM support_messages WHERE thread_id = t.id ORDER BY id DESC LIMIT 1) AS last_message,
             (SELECT is_bot FROM support_messages WHERE thread_id = t.id ORDER BY id DESC LIMIT 1) AS last_is_bot
             FROM support_threads t
             LEFT JOIN users u ON u.id = t.user_id
             ORDER BY t.unread_by_admin DESC, t.last_message_at DESC"
        )->fetchAll();

        $list = [];
        $totalUnread = 0;
        $needsHuman = 0;

        foreach ($threads as $t) {
            $isTyping = !empty($t['user_typing_at']) && ($now - strtotime($t['user_typing_at'])) <= 4;
            $isOnline = !empty($t['user_last_seen']) && ($now - strtotime($t['user_last_seen'])) <= 35;
            $unread = (int)$t['unread_by_admin'];

            if ($unread > 0) $totalUnread++;
            if ($unread > 0 && !(bool)$t['bot_active']) $needsHuman++;

            $list[] = [
                'id' => (int)$t['id'],
                'user_id' => $t['user_id'] ? (int)$t['user_id'] : null,
                'name' => !empty($t['full_name']) ? $t['full_name'] : ($t['guest_name'] ?? 'Guest Visitor #' . $t['id']),
                'email' => $t['email'] ?? 'N/A',
                'subject' => $t['subject'],
                'status' => $t['status'],
                'bot_active' => (bool)$t['bot_active'],
                'unread' => $unread,
                'is_typing' => $isTyping,
                'is_online' => $isOnline,
                'last_message' => $t['last_message'] ?? '',
                'last_is_bot' => (bool)($t['last_is_bot'] ?? false),
                'last_message_at' => $t['last_message_at'],
                'time_ago' => time_ago($t['last_message_at']),
            ];
        }

        json_out([
            'ok' => true,
            'threads' => $list,
            'total_unread' => $totalUnread,
            'needs_human' => $needsHuman,
        ]);
    }

    /* ---------------- ACTION: CANNED_RESPONSES (Admin Only) ---------------- */
    if ($action === 'canned_responses') {
        if (!$admin) {
            json_out(['ok' => false, 'error' => 'Unauthorized'], 403);
        }
        $canned = $pdo->query('SELECT id, title, message FROM chat_canned_responses ORDER BY sort_order ASC')->fetchAll();
        json_out(['ok' => true, 'canned' => $canned]);
    }

    /* ---------------- ACTION: CLOSE / REOPEN THREAD (Admin Only) ---------------- */
    if ($action === 'close_thread' || $action === 'reopen_thread') {
        if (!$admin) {
            json_out(['ok' => false, 'error' => 'Unauthorized'], 403);
        }
        $threadId = (int)($_REQUEST['thread_id'] ?? 0);
        $newStatus = ($action === 'close_thread') ? 'closed' : 'open';
        $pdo->prepare('UPDATE support_threads SET status = ? WHERE id = ?')->execute([$newStatus, $threadId]);
        json_out(['ok' => true, 'status' => $newStatus]);
    }

    json_out(['ok' => false, 'error' => 'Invalid action'], 400);

} catch (Throwable $e) {
    json_out(['ok' => false, 'error' => $e->getMessage()], 500);
}
