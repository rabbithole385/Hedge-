<?php
/**
 * HedgeInv Support Bot Knowledge Engine
 * Provides automated responses, FAQ routing, and real-time handoff to human administrators.
 */

if (!defined('HEDGEINV_BOT_LOADED')) {
    define('HEDGEINV_BOT_LOADED', true);
}

function bot_welcome_payload(?string $userName = null): array {
    $name = $userName ? ' ' . htmlspecialchars($userName) : '';
    return [
        'message' => "Hello{$name}! 👋 Welcome to HedgeInv Support.\n\nI am your automated assistant. I can guide you through deposits, withdrawals, KYC verification, copy trading, and AI bots, or connect you directly with a live human specialist.\n\nHow can I help you today?",
        'chips' => [
            ['label' => '💳 Deposit Help', 'action' => 'deposit'],
            ['label' => '💰 Withdrawal Info', 'action' => 'withdraw'],
            ['label' => '🆔 KYC Verification', 'action' => 'kyc'],
            ['label' => '📈 Copy Trading & Bots', 'action' => 'trading'],
            ['label' => '👤 Speak to Live Agent', 'action' => 'human'],
        ],
        'is_bot' => true,
        'sender_label' => 'HedgeInv Assistant (AI)',
    ];
}

function process_bot_reply(PDO $pdo, int $threadId, string $userMessage, ?array $user = null): array {
    $clean = mb_strtolower(trim($userMessage));

    // Check for human / agent request
    if (
        preg_match('/\b(human|agent|representative|admin|administrator|real person|person|support team|talk to someone|speak to human|live support)\b/i', $clean) ||
        $clean === 'human'
    ) {
        // Disengage bot, notify admin
        $pdo->prepare("UPDATE support_threads SET bot_active = 0, unread_by_admin = 1, last_message_at = NOW() WHERE id = ?")->execute([$threadId]);

        return [
            'reply' => "I have routed your conversation to our live support desk. 🔔\n\nAn administrator has been notified and will respond directly in this chat. You can leave additional details or questions below while an agent joins.",
            'chips' => [
                ['label' => '💳 Deposit Question', 'action' => 'deposit'],
                ['label' => '💰 Withdrawal Question', 'action' => 'withdraw'],
                ['label' => '🤖 Re-enable Bot', 'action' => 'keep_bot'],
            ],
            'handoff' => true,
        ];
    }

    // Check for keep_bot request
    if ($clean === 'keep_bot' || preg_match('/\b(re-enable bot|resume bot|keep bot)\b/i', $clean)) {
        $pdo->prepare("UPDATE support_threads SET bot_active = 1 WHERE id = ?")->execute([$threadId]);
        return [
            'reply' => "The HedgeInv automated assistant is back active! 🤖 What platform feature can I guide you through?",
            'chips' => [
                ['label' => '💳 Deposit Help', 'action' => 'deposit'],
                ['label' => '💰 Withdrawal Info', 'action' => 'withdraw'],
                ['label' => '🆔 KYC Verification', 'action' => 'kyc'],
                ['label' => '📈 Copy Trading', 'action' => 'trading'],
                ['label' => '👤 Speak to Live Agent', 'action' => 'human'],
            ],
            'handoff' => false,
        ];
    }

    // Check for deposit query
    if (
        preg_match('/\b(deposit|fund|funds|top up|topup|crypto|bitcoin|btc|eth|ethereum|usdt|tether|bank wire|wire transfer)\b/i', $clean) ||
        $clean === 'deposit'
    ) {
        return [
            'reply' => "💳 Funding Your HedgeInv Account:\n\n1. Go to **Wallet & Funds → Deposit** in your dashboard.\n2. **Crypto (BTC, ETH, USDT)**: Send funds to the platform wallet address shown and submit your transaction hash for swift confirmation.\n3. **Bank Wire**: Follow the bank instructions provided on the page and upload proof of payment.\n\nTransactions are typically credited within 10–30 minutes once confirmed on the blockchain.",
            'chips' => [
                ['label' => 'Open Deposit Page', 'url' => '/user/deposit.php'],
                ['label' => '💰 Withdrawal Info', 'action' => 'withdraw'],
                ['label' => '👤 Speak with Live Agent', 'action' => 'human'],
            ],
            'handoff' => false,
        ];
    }

    // Check for withdrawal query
    if (
        preg_match('/\b(withdraw|withdrawal|cash out|cashout|payout|send money back|wallet balance)\b/i', $clean) ||
        $clean === 'withdraw'
    ) {
        return [
            'reply' => "💰 Withdrawing Funds:\n\n1. Ensure your identity is verified (**Wallet & Funds → Verification**).\n2. Navigate to **Wallet & Funds → Withdraw**.\n3. Enter your receiving wallet address (or bank details) and desired amount.\n\nAll withdrawal requests undergo rapid security compliance auditing and are dispatched within 1–2 hours during operating market sessions.",
            'chips' => [
                ['label' => 'Open Withdraw Page', 'url' => '/user/withdraw.php'],
                ['label' => '🆔 KYC Verification', 'action' => 'kyc'],
                ['label' => '👤 Ask Agent for Expedited Review', 'action' => 'human'],
            ],
            'handoff' => false,
        ];
    }

    // Check for KYC / Verification query
    if (
        preg_match('/\b(kyc|verify|verification|identity|passport|id|id card|license|national id|compliance)\b/i', $clean) ||
        $clean === 'kyc'
    ) {
        return [
            'reply' => "🆔 Identity Verification (KYC):\n\nTo safeguard all user accounts and satisfy global AML compliance, identity verification is required before making withdrawals:\n\n1. Go to **Wallet & Funds → Verification**.\n2. Upload a clear, uncropped photo of your passport, driver's license, or national ID.\n3. Most verification reviews are completed in under 15 minutes by our compliance team.",
            'chips' => [
                ['label' => 'Submit Verification', 'url' => '/user/kyc.php'],
                ['label' => '💳 Deposit Help', 'action' => 'deposit'],
                ['label' => '👤 Live Agent Assistance', 'action' => 'human'],
            ],
            'handoff' => false,
        ];
    }

    // Check for Copy Trading & AI Bots query
    if (
        preg_match('/\b(copy|trader|expert|experts|follow trader|leaderboard|bot|bots|ai bot|algo|algorithmic)\b/i', $clean) ||
        $clean === 'trading'
    ) {
        return [
            'reply' => "📈 Copy Trading & AI Bots Overview:\n\n• **Copy Trading**: Choose vetted professional traders from our Leaderboard. Your portfolio will mirror their open and closed trades in direct proportion to your allocated capital.\n• **AI Trading Bots**: Automated quantitative algorithmic bots running round-the-clock strategies at specific risk/reward intervals.\n\nYou can start, pause, or adjust allocations at any time from your Portfolio dashboard.",
            'chips' => [
                ['label' => 'Explore Traders', 'url' => '/user/experts.php'],
                ['label' => 'Explore AI Bots', 'url' => '/user/bots.php'],
                ['label' => 'Portfolio Overview', 'url' => '/user/portfolio.php'],
                ['label' => '👤 Connect with Agent', 'action' => 'human'],
            ],
            'handoff' => false,
        ];
    }

    // Check greetings
    if (preg_match('/^(hi|hello|hey|good day|good morning|good evening|yo|hola)\b/i', $clean)) {
        return [
            'reply' => "Hello! 👋 I'm ready to assist. You can tap any of the quick topics below, or type your question directly.",
            'chips' => [
                ['label' => '💳 Deposit Help', 'action' => 'deposit'],
                ['label' => '💰 Withdrawal Info', 'action' => 'withdraw'],
                ['label' => '🆔 KYC Verification', 'action' => 'kyc'],
                ['label' => '📈 Copy Trading & Bots', 'action' => 'trading'],
                ['label' => '👤 Speak to Live Agent', 'action' => 'human'],
            ],
            'handoff' => false,
        ];
    }

    // Fallback response
    return [
        'reply' => "Thank you for reaching out! I want to ensure you get the most accurate and personalized help.\n\nWould you like me to connect you to a live support agent, or would you like to explore our main account topics?",
        'chips' => [
            ['label' => '👤 Connect with Live Agent', 'action' => 'human'],
            ['label' => '💳 Deposit Information', 'action' => 'deposit'],
            ['label' => '💰 Withdrawal Information', 'action' => 'withdraw'],
            ['label' => '🆔 KYC Verification', 'action' => 'kyc'],
        ],
        'handoff' => false,
    ];
}
