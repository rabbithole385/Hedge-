<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/live_prices.php';

function fmt_money(float $amount): string {
    return '$' . number_format($amount, 2);
}

function fmt_pct(float $pct): string {
    $sign = $pct > 0 ? '+' : '';
    return $sign . number_format($pct, 2) . '%';
}

function redirect(string $path): void {
    header('Location: ' . $path);
    exit;
}

function flash_set(string $key, string $message): void {
    $_SESSION['flash'][$key] = $message;
}

function flash_get(string $key): ?string {
    if (empty($_SESSION['flash'][$key])) return null;
    $msg = $_SESSION['flash'][$key];
    unset($_SESSION['flash'][$key]);
    return $msg;
}

/**
 * Block access to any account feature until identity verification is approved.
 * New users must submit and pass KYC before doing anything else on the site.
 */
function require_kyc(array $user): void {
    if ($user['kyc_status'] !== 'approved') {
        flash_set('error', 'Please complete identity verification (KYC) before using your account.');
        redirect('/user/kyc.php');
    }
}

/**
 * Record a wallet transaction and update the user's balance atomically.
 * $delta can be positive (credit) or negative (debit).
 */
function apply_wallet_delta(PDO $pdo, int $userId, float $delta, string $type, string $reference = ''): float {
    $stmt = $pdo->prepare('SELECT wallet_balance FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $before = (float)$stmt->fetchColumn();
    $after = $before + $delta;

    $pdo->prepare('UPDATE users SET wallet_balance = ? WHERE id = ?')->execute([$after, $userId]);
    $pdo->prepare(
        'INSERT INTO transactions (user_id, type, amount, balance_after, status, reference) VALUES (?, ?, ?, ?, "completed", ?)'
    )->execute([$userId, $type, $delta, $after, $reference]);

    return $after;
}

/**
 * Core copy-trading engine: when admin posts a trade result for an expert,
 * every active copier's allocation is adjusted by profit_percent, and the
 * resulting gain/loss is credited/debited to their wallet + current_value.
 */
function distribute_trade_result(PDO $pdo, int $tradeId, int $expertId, float $profitPercent): int {
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT * FROM copies WHERE expert_id = ? AND status = 'active'");
        $stmt->execute([$expertId]);
        $copies = $stmt->fetchAll();

        $count = 0;
        foreach ($copies as $copy) {
            $profitAmount = round($copy['current_value'] * ($profitPercent / 100), 2);
            $newValue = round($copy['current_value'] + $profitAmount, 2);

            $pdo->prepare('UPDATE copies SET current_value = ? WHERE id = ?')
                ->execute([$newValue, $copy['id']]);

            $stmtBal = $pdo->prepare('SELECT wallet_balance FROM users WHERE id = ?');
            $stmtBal->execute([$copy['user_id']]);
            $balBefore = (float)$stmtBal->fetchColumn();
            $balAfter = round($balBefore + $profitAmount, 2);

            $pdo->prepare('UPDATE users SET wallet_balance = ? WHERE id = ?')
                ->execute([$balAfter, $copy['user_id']]);

            $pdo->prepare(
                'INSERT INTO trade_distributions (trade_id, copy_id, user_id, profit_amount, balance_before, balance_after)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([$tradeId, $copy['id'], $copy['user_id'], $profitAmount, $balBefore, $balAfter]);

            $pdo->prepare(
                'INSERT INTO transactions (user_id, type, amount, balance_after, status, reference) VALUES (?, ?, ?, ?, "completed", ?)'
            )->execute([
                $copy['user_id'],
                $profitAmount >= 0 ? 'profit' : 'loss',
                $profitAmount,
                $balAfter,
                'Trade #' . $tradeId,
            ]);

            create_notification(
                $pdo, (int)$copy['user_id'], 'trade_posted',
                $profitAmount >= 0 ? 'Trade profit posted' : 'Trade loss posted',
                fmt_money($profitAmount) . ' applied to your copy position.', '/user/portfolio.php',
                false // in-app only - avoid emailing on every single trade
            );

            $count++;
        }

        // Update expert's rolling stats
        recalc_expert_stats($pdo, $expertId);

        $pdo->commit();
        return $count;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** The trade count shown for an expert - manual override if set, else the real posted-trade count. */
function get_expert_trade_count(PDO $pdo, array $expert): int {
    if (!empty($expert['trade_count_manual'])) {
        return (int)$expert['trade_count_override'];
    }
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM trades WHERE expert_id = ?');
    $stmt->execute([$expert['id']]);
    return (int)$stmt->fetchColumn();
}

function recalc_expert_stats(PDO $pdo, int $expertId): void {
    $stmt = $pdo->prepare('SELECT profit_percent FROM trades WHERE expert_id = ?');
    $stmt->execute([$expertId]);
    $rows = $stmt->fetchAll();
    if (!$rows) return;

    $wins = 0;
    $sum = 0.0;
    foreach ($rows as $r) {
        $p = (float)$r['profit_percent'];
        $sum += $p;
        if ($p > 0) $wins++;
    }
    $winRate = round(($wins / count($rows)) * 100, 1);
    $avgReturn = round($sum / count($rows), 2);

    $stmt = $pdo->prepare('SELECT win_rate_manual, monthly_return_manual FROM experts WHERE id = ?');
    $stmt->execute([$expertId]);
    $flags = $stmt->fetch();
    $winRateManual = (int)($flags['win_rate_manual'] ?? 0);
    $returnManual = (int)($flags['monthly_return_manual'] ?? 0);

    if (!$winRateManual && !$returnManual) {
        $pdo->prepare('UPDATE experts SET win_rate = ?, monthly_return = ? WHERE id = ?')
            ->execute([$winRate, $avgReturn, $expertId]);
    } elseif (!$winRateManual) {
        $pdo->prepare('UPDATE experts SET win_rate = ? WHERE id = ?')->execute([$winRate, $expertId]);
    } elseif (!$returnManual) {
        $pdo->prepare('UPDATE experts SET monthly_return = ? WHERE id = ?')->execute([$avgReturn, $expertId]);
    }
}

/**
 * Admin-side direct adjustment to one user's copy position - separate from
 * distribute_trade_result() which broadcasts a trade to every copier of an
 * expert. This nudges a single copy's value and the user's wallet directly.
 */
function adjust_copy_value(PDO $pdo, int $copyId, float $amount, string $note = ''): void {
    $stmt = $pdo->prepare("SELECT * FROM copies WHERE id = ? AND status = 'active'");
    $stmt->execute([$copyId]);
    $copy = $stmt->fetch();
    if (!$copy) {
        throw new RuntimeException('Copy position not found or not active.');
    }

    $newValue = round((float)$copy['current_value'] + $amount, 2);
    if ($newValue < 0) {
        throw new RuntimeException('Adjustment would make the copy value negative.');
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE copies SET current_value = ? WHERE id = ?')->execute([$newValue, $copyId]);
        apply_wallet_delta(
            $pdo, (int)$copy['user_id'], $amount, $amount >= 0 ? 'profit' : 'loss',
            $note !== '' ? $note : ('Manual admin adjustment on copy #' . $copyId)
        );
        $pdo->prepare('INSERT INTO copy_adjustments (copy_id, amount, note) VALUES (?, ?, ?)')
            ->execute([$copyId, $amount, $note ?: null]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function time_ago(string $datetime): string {
    $diff = time() - strtotime($datetime);
    if ($diff < 60) return 'just now';
    if ($diff < 3600) { $m = floor($diff / 60); return $m . ' minute' . ($m == 1 ? '' : 's') . ' ago'; }
    if ($diff < 86400) { $h = floor($diff / 3600); return $h . ' hour' . ($h == 1 ? '' : 's') . ' ago'; }
    $d = floor($diff / 86400);
    return $d . ' day' . ($d == 1 ? '' : 's') . ' ago';
}

/* ---------------- NOTIFICATIONS ---------------- */

function create_notification(PDO $pdo, int $userId, string $type, string $title, string $message = '', ?string $linkUrl = null, bool $sendEmail = true): void {
    $pdo->prepare(
        'INSERT INTO notifications (user_id, type, title, message, link_url) VALUES (?, ?, ?, ?, ?)'
    )->execute([$userId, $type, $title, $message, $linkUrl]);

    if ($sendEmail) {
        $stmt = $pdo->prepare('SELECT email FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $email = $stmt->fetchColumn();
        if ($email) {
            try {
                send_notification_email($email, $title, $message);
            } catch (Throwable $e) {
                error_log('create_notification: email send failed - ' . $e->getMessage());
            }
        }
    }
}

function get_unread_notification_count(PDO $pdo, int $userId): int {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
    $stmt->execute([$userId]);
    return (int)$stmt->fetchColumn();
}

/* ---------------- REFERRALS ---------------- */

function generate_referral_code(PDO $pdo): string {
    do {
        $code = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        $stmt = $pdo->prepare('SELECT id FROM users WHERE referral_code = ?');
        $stmt->execute([$code]);
    } while ($stmt->fetch());
    return $code;
}

/**
 * Called after a deposit (crypto or bank) is approved. If this is the
 * referred user's first-ever approved deposit and they were referred by
 * someone, credit the referrer once (enforced by a unique key on
 * referred_user_id so it can only ever pay out a single time per referral).
 */
function process_referral_reward(PDO $pdo, int $referredUserId): void {
    $stmt = $pdo->prepare('SELECT referred_by FROM users WHERE id = ?');
    $stmt->execute([$referredUserId]);
    $referrerId = $stmt->fetchColumn();
    if (!$referrerId) return;

    // Already rewarded for this referral? Unique key prevents duplicates,
    // but check first so we don't rely solely on catching a DB error.
    $stmt = $pdo->prepare('SELECT id FROM referral_rewards WHERE referred_user_id = ?');
    $stmt->execute([$referredUserId]);
    if ($stmt->fetch()) return;

    // Only reward on the referred user's FIRST approved deposit.
    $stmt = $pdo->prepare(
        "SELECT
            (SELECT COUNT(*) FROM crypto_deposits WHERE user_id = ? AND status = 'approved') +
            (SELECT COUNT(*) FROM bank_deposits WHERE user_id = ? AND status = 'approved') as total"
    );
    $stmt->execute([$referredUserId, $referredUserId]);
    $totalApproved = (int)$stmt->fetchColumn();
    if ($totalApproved !== 1) return; // not their first approved deposit

    $bonusAmount = (float)get_setting($pdo, 'referral_bonus_amount', '10');
    if ($bonusAmount <= 0) return;

    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'INSERT INTO referral_rewards (referrer_user_id, referred_user_id, amount, trigger_type) VALUES (?, ?, ?, "first_deposit")'
        )->execute([$referrerId, $referredUserId, $bonusAmount]);

        apply_wallet_delta($pdo, (int)$referrerId, $bonusAmount, 'referral_bonus', 'Referral bonus');

        $stmt = $pdo->prepare('SELECT full_name FROM users WHERE id = ?');
        $stmt->execute([$referredUserId]);
        $referredName = $stmt->fetchColumn();

        create_notification(
            $pdo, (int)$referrerId, 'referral_bonus',
            'Referral bonus earned',
            fmt_money($bonusAmount) . ' credited for referring ' . $referredName . '.',
            '/user/referrals.php'
        );

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
    }
}

/**
 * Handles an optional payment-proof upload (screenshot/slip) for deposit
 * requests. Returns [filename_or_null, errors_array].
 */
function handle_deposit_proof_upload(): array {
    $errors = [];
    if (empty($_FILES['proof']['name']) || $_FILES['proof']['error'] === UPLOAD_ERR_NO_FILE) {
        return [null, $errors]; // optional - no file provided is fine
    }
    if ($_FILES['proof']['error'] !== UPLOAD_ERR_OK) {
        return [null, ['Upload failed. Please try again.']];
    }

    $allowed = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'pdf' => 'application/pdf'];
    $ext = strtolower(pathinfo($_FILES['proof']['name'], PATHINFO_EXTENSION));
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $_FILES['proof']['tmp_name']);
    finfo_close($finfo);

    if (!isset($allowed[$ext]) || $allowed[$ext] !== $mime) {
        return [null, ['Proof must be a JPG, PNG, or PDF file.']];
    }
    if ($_FILES['proof']['size'] > 5 * 1024 * 1024) {
        return [null, ['Proof file must be under 5MB.']];
    }

    $newName = 'proof_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $destDir = __DIR__ . '/../assets/uploads/deposit_proofs/';
    if (!move_uploaded_file($_FILES['proof']['tmp_name'], $destDir . $newName)) {
        return [null, ['Upload failed. Please try again.']];
    }

    return [$newName, $errors];
}

function log_admin_action(PDO $pdo, int $adminId, string $action, string $details = ''): void {
    $pdo->prepare('INSERT INTO admin_audit_log (admin_id, action, details) VALUES (?, ?, ?)')
        ->execute([$adminId, $action, $details]);
}

function e(string $str): string {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

/* ---------------- AUTOMATED TICK ENGINE (bots + plans) ----------------
 * Unlike copy trading (admin posts real results), bots and investment plans
 * are fully automated: on a timed interval, each active subscription's value
 * shifts by a random percentage within its product's configured range. It
 * can go up or down at any tick - nothing here is fixed or guaranteed.
 */

function random_tick_percent(float $min, float $max): float {
    $r = mt_rand() / mt_getrandmax(); // 0..1
    return round($min + $r * ($max - $min), 4);
}

/**
 * AI BOT TICKS - LIVE MARKET STRATEGIES
 * Each bot watches real markets (bots.tracked_markets) and holds a position
 * in each one, decided by a fixed rule:
 *   trend     - long if the market is up over 24h, short if it is down
 *   reversion - the opposite: leans against the 24h move
 *   long      - always long
 * Markets moving less than 0.05% over 24h are left flat (no position).
 *
 * On every tick the bot's value moves by the real price change since the
 * previous tick, in the direction of the positions it held:
 *     move % = exposure x average( direction x (price_now / price_then - 1) )
 * and then positions are re-decided for the next interval.
 *
 * Like plans, P/L stays in the bot and reaches the wallet once, on deactivation.
 */
function bot_strategy_labels(): array {
    return [
        'trend' => ['Trend following', 'Goes long rising markets and short falling ones.'],
        'reversion' => ['Mean reversion', 'Leans against short-term moves, expecting a pull-back.'],
        'long' => ['Long only', 'Always holds every market long, like a basket investor.'],
    ];
}

function bot_decide_positions(string $strategy, array $keys, array $quotes): array {
    $pos = [];
    foreach ($keys as $k) {
        $chg = $quotes[$k]['change_pct'] ?? null;
        if ($strategy === 'long') { $pos[$k] = 1; continue; }
        if ($chg === null || abs($chg) < 0.05) { $pos[$k] = 0; continue; }
        $dir = $chg > 0 ? 1 : -1;
        $pos[$k] = $strategy === 'reversion' ? -$dir : $dir;
    }
    return $pos;
}

function process_due_bot_ticks(PDO $pdo): int {
    $stmt = $pdo->query(
        "SELECT s.*, b.tick_interval_minutes, b.tracked_markets, b.strategy, b.exposure,
                TIMESTAMPDIFF(SECOND, s.last_tick_at, NOW()) AS secs_since_tick
         FROM bot_subscriptions s JOIN bots b ON b.id = s.bot_id
         WHERE s.status = 'active' AND b.status = 'active'
           AND b.tracked_markets IS NOT NULL AND b.tracked_markets <> ''"
    );
    $due = []; $allKeys = [];
    foreach ($stmt->fetchAll() as $s) {
        $interval = max(1, (int)$s['tick_interval_minutes']) * 60;
        if ($s['secs_since_tick'] !== null && (int)$s['secs_since_tick'] < $interval && $s['ref_prices']) continue;
        $keys = parse_market_keys($s['tracked_markets']);
        if (!$keys) continue;
        $s['_keys'] = $keys;
        $due[] = $s;
        foreach ($keys as $k) $allKeys[$k] = true;
    }
    if (!$due) return 0;

    $quotes = get_live_quotes($pdo, array_keys($allKeys));
    $processed = 0;

    foreach ($due as $s) {
        $now = [];
        foreach ($s['_keys'] as $k) {
            $q = $quotes[$k] ?? null;
            if (!$q || $q['price'] === null || $q['age'] === null || $q['age'] > LIVE_QUOTE_TICK_MAX_AGE) { $now = null; break; }
            $now[$k] = (float)$q['price'];
        }
        if ($now === null) continue;

        $strategy = array_key_exists($s['strategy'], bot_strategy_labels()) ? $s['strategy'] : 'trend';
        $newPos = bot_decide_positions($strategy, array_keys($now), $quotes);
        $ref = $s['ref_prices'] ? json_decode($s['ref_prices'], true) : null;
        $pos = $s['positions'] ? json_decode($s['positions'], true) : null;
        $same = is_array($ref) && is_array($pos) && !array_diff(array_keys($now), array_keys($ref)) && !array_diff(array_keys($ref), array_keys($now));

        if (!$same) {
            $pdo->prepare("UPDATE bot_subscriptions SET ref_prices = ?, positions = ?, last_tick_at = NOW() WHERE id = ?")
                ->execute([json_encode($now), json_encode($newPos), $s['id']]);
            continue;
        }

        $moves = [];
        foreach ($now as $k => $p) {
            if ((float)$ref[$k] > 0) $moves[] = (int)($pos[$k] ?? 0) * ($p / (float)$ref[$k] - 1);
        }
        $exposure = max(0.1, (float)$s['exposure']);
        $pct = $moves ? max(-100.0, round($exposure * array_sum($moves) / count($moves) * 100, 4)) : 0.0;
        $before = (float)$s['current_value'];
        $after = max(0, round($before * (1 + $pct / 100), 2));
        $profitAmount = round($after - $before, 2);

        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE bot_subscriptions SET current_value = ?, ref_prices = ?, positions = ?, last_tick_at = NOW() WHERE id = ?")
                ->execute([$after, json_encode($now), json_encode($newPos), $s['id']]);
            if ($profitAmount != 0.0) {
                // balance_before / balance_after record the BOT value, not the wallet.
                $pdo->prepare(
                    'INSERT INTO bot_ticks (subscription_id, user_id, bot_id, profit_percent, profit_amount, balance_before, balance_after)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                )->execute([$s['id'], $s['user_id'], $s['bot_id'], $pct, $profitAmount, $before, $after]);
            }
            $pdo->commit();
            $processed++;
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('Bot tick failed for subscription #' . $s['id'] . ': ' . $e->getMessage());
        }
    }
    return $processed;
}

/**
 * INVESTMENT PLAN TICKS - LIVE MARKET LINKED
 * Each plan tracks a basket of real markets (investment_plans.tracked_markets).
 * On every tick the subscription value moves by the real % change of that
 * basket since the previous tick, multiplied by the plan's exposure:
 *
 *     move % = exposure x average( price_now / price_at_last_tick - 1 )
 *
 * No random numbers. If any live price is unavailable or stale, the tick is
 * simply skipped and retried next time - nothing is guessed.
 *
 * Profit/loss stays inside the plan (current_value). It reaches the wallet
 * only once, when the client withdraws from the plan (portfolio.php).
 */
function process_due_plan_ticks(PDO $pdo): int {
    $stmt = $pdo->query(
        "SELECT s.*, p.tick_interval_minutes, p.tracked_markets, p.exposure, p.status as plan_status,
                TIMESTAMPDIFF(SECOND, s.last_tick_at, NOW()) AS secs_since_tick
         FROM plan_subscriptions s JOIN investment_plans p ON p.id = s.plan_id
         WHERE s.status = 'active' AND p.status = 'active'
           AND p.tracked_markets IS NOT NULL AND p.tracked_markets <> ''"
    );
    $due = [];
    $allKeys = [];
    foreach ($stmt->fetchAll() as $s) {
        $interval = max(1, (int)$s['tick_interval_minutes']) * 60;
        if ($s['secs_since_tick'] !== null && (int)$s['secs_since_tick'] < $interval && $s['ref_prices']) continue;
        $keys = parse_market_keys($s['tracked_markets']);
        if (!$keys) continue;
        $s['_keys'] = $keys;
        $due[] = $s;
        foreach ($keys as $k) $allKeys[$k] = true;
    }
    if (!$due) return 0;

    $quotes = get_live_quotes($pdo, array_keys($allKeys));
    $processed = 0;

    foreach ($due as $s) {
        $now = [];
        foreach ($s['_keys'] as $k) {
            $q = $quotes[$k] ?? null;
            if (!$q || $q['price'] === null || $q['age'] === null || $q['age'] > LIVE_QUOTE_TICK_MAX_AGE) { $now = null; break; }
            $now[$k] = (float)$q['price'];
        }
        if ($now === null) continue; // a market is unavailable right now - try again later

        $ref = $s['ref_prices'] ? json_decode($s['ref_prices'], true) : null;
        $sameBasket = is_array($ref) && !array_diff(array_keys($now), array_keys($ref)) && !array_diff(array_keys($ref), array_keys($now));

        if (!$sameBasket) {
            // First tick (or the admin changed the plan's markets): lock in reference prices, no P/L yet.
            $pdo->prepare("UPDATE plan_subscriptions SET ref_prices = ?, last_tick_at = NOW() WHERE id = ?")
                ->execute([json_encode($now), $s['id']]);
            continue;
        }

        $moves = [];
        foreach ($now as $k => $p) {
            if ((float)$ref[$k] > 0) $moves[] = $p / (float)$ref[$k] - 1;
        }
        $exposure = max(0.1, (float)$s['exposure']);
        $pct = $moves ? round($exposure * array_sum($moves) / count($moves) * 100, 4) : 0.0;
        $pct = max(-100.0, $pct);

        $before = (float)$s['current_value'];
        $after = max(0, round($before * (1 + $pct / 100), 2));
        $profitAmount = round($after - $before, 2);

        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE plan_subscriptions SET current_value = ?, ref_prices = ?, last_tick_at = NOW() WHERE id = ?")
                ->execute([$after, json_encode($now), $s['id']]);
            if ($profitAmount != 0.0) {
                // balance_before / balance_after record the PLAN value, not the wallet.
                $pdo->prepare(
                    'INSERT INTO plan_ticks (subscription_id, user_id, plan_id, profit_percent, profit_amount, balance_before, balance_after)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                )->execute([$s['id'], $s['user_id'], $s['plan_id'], $pct, $profitAmount, $before, $after]);
            }
            $pdo->commit();
            $processed++;
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('Plan tick failed for subscription #' . $s['id'] . ': ' . $e->getMessage());
        }
    }
    return $processed;
}

function process_all_due_ticks(PDO $pdo): void {
    // One runner at a time, even across several servers sharing this database.
    if ((int)$pdo->query("SELECT GET_LOCK('hedgeinv_position_ticks', 0)")->fetchColumn() !== 1) return;
    try {
        process_due_bot_ticks($pdo);
        process_due_plan_ticks($pdo);
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('hedgeinv_position_ticks')");
    }
}

/* ---------------- DIRECT BUY/SELL TRADING (real live prices) ----------------
 * Unlike copy trading/bots/plans, this is a direct position in a real
 * instrument at its current live market price. No random
 * generator, no admin-typed result - value moves only because the real
 * market price moved.
 */

function buy_asset(PDO $pdo, int $userId, string $symbol, string $apiSymbol, string $assetType, float $quantity, float $price): void {
    $cost = round($quantity * $price, 2);

    $stmt = $pdo->prepare('SELECT wallet_balance FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $balance = (float)$stmt->fetchColumn();
    if ($cost > $balance) {
        throw new RuntimeException(
            "Insufficient wallet balance: buying {$quantity} {$symbol} at " . fmt_money($price)
            . ' costs ' . fmt_money($cost) . ', but your wallet balance is only ' . fmt_money($balance) . '.'
        );
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM holdings WHERE user_id = ? AND symbol = ?');
        $stmt->execute([$userId, $symbol]);
        $existing = $stmt->fetch();

        if ($existing) {
            $newQty = (float)$existing['quantity'] + $quantity;
            $newAvgCost = (((float)$existing['quantity'] * (float)$existing['avg_cost']) + ($quantity * $price)) / $newQty;
            $pdo->prepare('UPDATE holdings SET quantity = ?, avg_cost = ? WHERE id = ?')
                ->execute([$newQty, $newAvgCost, $existing['id']]);
        } else {
            $pdo->prepare(
                'INSERT INTO holdings (user_id, symbol, api_symbol, asset_type, quantity, avg_cost) VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([$userId, $symbol, $apiSymbol, $assetType, $quantity, $price]);
        }

        apply_wallet_delta($pdo, $userId, -$cost, 'trade_buy', "Bought {$quantity} {$symbol} @ " . fmt_money($price));
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function sell_asset(PDO $pdo, int $userId, string $symbol, float $quantity, float $price): void {
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM holdings WHERE user_id = ? AND symbol = ? FOR UPDATE');
        $stmt->execute([$userId, $symbol]);
        $holding = $stmt->fetch();

        if (!$holding) {
            throw new RuntimeException("You don't own any {$symbol} to sell.");
        }
        if ($quantity > (float)$holding['quantity']) {
            $owned = rtrim(rtrim(number_format((float)$holding['quantity'], 8), '0'), '.');
            throw new RuntimeException("You only own {$owned} {$symbol}, but tried to sell {$quantity}.");
        }

        $proceeds = round($quantity * $price, 2);
        $costBasis = round($quantity * (float)$holding['avg_cost'], 2);
        $pnl = $proceeds - $costBasis;

        $remainingQty = (float)$holding['quantity'] - $quantity;
        if ($remainingQty <= 0.00000001) {
            $pdo->prepare('DELETE FROM holdings WHERE id = ?')->execute([$holding['id']]);
        } else {
            $pdo->prepare('UPDATE holdings SET quantity = ? WHERE id = ?')->execute([$remainingQty, $holding['id']]);
        }

        apply_wallet_delta(
            $pdo, $userId, $proceeds, 'trade_sell',
            "Sold {$quantity} {$symbol} @ " . fmt_money($price) . ' (' . ($pnl >= 0 ? '+' : '') . fmt_money($pnl) . ' P&L)'
        );
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
/* ---------------- SUPPORT MESSAGING ---------------- */

function get_or_create_open_support_thread(PDO $pdo, int $userId, string $subject, string $firstMessage): int {
    // Reuse an already-open thread with the same subject so repeat clicks
    // (e.g. the bank-transfer deposit shortcut) don't spawn duplicate tickets.
    $stmt = $pdo->prepare(
        "SELECT id FROM support_threads WHERE user_id = ? AND subject = ? AND status = 'open' ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([$userId, $subject]);
    $existing = $stmt->fetchColumn();
    if ($existing) {
        return (int)$existing;
    }

    $pdo->prepare(
        'INSERT INTO support_threads (user_id, subject, status, unread_by_admin, unread_by_user) VALUES (?, ?, "open", 1, 0)'
    )->execute([$userId, $subject]);
    $threadId = (int)$pdo->lastInsertId();

    post_support_message($pdo, $threadId, 'user', $firstMessage, false);

    return $threadId;
}

function post_support_message(PDO $pdo, int $threadId, string $senderType, string $message, bool $reopen = true): void {
    $stmt = $pdo->prepare('SELECT * FROM support_threads WHERE id = ?');
    $stmt->execute([$threadId]);
    $thread = $stmt->fetch();
    if (!$thread) return;

    $senderLabel = $senderType === 'admin' ? 'Support Team' : 'You';

    $pdo->prepare(
        'INSERT INTO support_messages (thread_id, sender_type, sender_label, message) VALUES (?, ?, ?, ?)'
    )->execute([$threadId, $senderType, $senderLabel, $message]);

    if ($senderType === 'admin') {
        $pdo->prepare(
            "UPDATE support_threads SET unread_by_user = 1, unread_by_admin = 0, last_message_at = NOW()" .
            ($reopen ? ", status = 'open'" : '') . " WHERE id = ?"
        )->execute([$threadId]);

        create_notification(
            $pdo, (int)$thread['user_id'], 'support_reply', 'New reply from support',
            mb_strimwidth($message, 0, 140, '...'), '/user/support_thread.php?id=' . $threadId
        );
    } else {
        $pdo->prepare(
            "UPDATE support_threads SET unread_by_admin = 1, unread_by_user = 0, last_message_at = NOW()" .
            ($reopen ? ", status = 'open'" : '') . " WHERE id = ?"
        )->execute([$threadId]);
    }
}

function get_unread_support_count_for_admin(PDO $pdo): int {
    return (int)$pdo->query("SELECT COUNT(*) FROM support_threads WHERE unread_by_admin = 1")->fetchColumn();
}

function get_unread_support_count_for_user(PDO $pdo, int $userId): int {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM support_threads WHERE user_id = ? AND unread_by_user = 1');
    $stmt->execute([$userId]);
    return (int)$stmt->fetchColumn();
}
