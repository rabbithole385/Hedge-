<?php
require_once __DIR__ . '/db_credentials.php';

define('SCHEMA_PATH', __DIR__ . '/../database/schema.sql');

/** PDO connection options, with TLS when DB_SSL is on (cloud MySQL providers). */
function db_pdo_options(): array {
    $o = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::MYSQL_ATTR_MULTI_STATEMENTS => true, // needed once, to run schema.sql in one shot
        PDO::ATTR_TIMEOUT => 10,
    ];
    // Behave like the original MariaDB server, whatever MySQL the database runs on
    // (MySQL 8 adds ONLY_FULL_GROUP_BY etc. by default). Times in Lagos time.
    $o[PDO::MYSQL_ATTR_INIT_COMMAND] = "SET SESSION sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION', time_zone = '+01:00'";
    if (defined('DB_SSL') && DB_SSL) {
        // CA: Render Secret File "db-ca.pem" (e.g. Aiven's CA certificate), DB_SSL_CA, or the system bundle.
        $cas = array_filter([getenv('DB_SSL_CA') ?: null, '/etc/secrets/db-ca.pem',
                             '/etc/ssl/certs/ca-certificates.crt', '/etc/pki/tls/certs/ca-bundle.crt', '/etc/ssl/cert.pem']);
        foreach ($cas as $ca) {
            if (is_file($ca)) { $o[PDO::MYSQL_ATTR_SSL_CA] = $ca; break; }
        }
        $verify = getenv('DB_SSL_VERIFY');
        $o[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = !in_array(strtolower((string)$verify), ['0', 'false', 'no', 'off'], true);
    }
    return $o;
}

function get_db(): PDO {
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, db_pdo_options());

    ensure_schema($pdo);
    ensure_copy_adjustments_schema($pdo);
    ensure_kyc_schema($pdo);
    ensure_holdings_schema($pdo);
    ensure_bank_deposits_schema($pdo);
    ensure_deposit_proof_columns($pdo);
    ensure_expert_win_rate_manual($pdo);
    ensure_instrument_prices_schema($pdo);
    ensure_notifications_schema($pdo);
    ensure_referral_schema($pdo);
    ensure_audit_log_schema($pdo);
    ensure_support_schema($pdo);
    ensure_livechat_schema($pdo);
    ensure_live_plan_schema($pdo);
    seed_if_needed($pdo);
    ensure_brand_name($pdo);

    return $pdo;
}

function ensure_copy_adjustments_schema(PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS copy_adjustments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            copy_id INT NOT NULL,
            amount DECIMAL(18,2) NOT NULL,
            note VARCHAR(255),
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (copy_id) REFERENCES copies(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function ensure_kyc_schema(PDO $pdo): void {
    // Safe to run every request: CREATE TABLE IF NOT EXISTS is a no-op once it exists.
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS kyc_submissions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            full_legal_name VARCHAR(191) NOT NULL,
            id_type VARCHAR(30) NOT NULL,
            id_number VARCHAR(100) NOT NULL,
            document_path VARCHAR(255) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            admin_note TEXT,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            reviewed_at DATETIME NULL,
            FOREIGN KEY (user_id) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    // users.kyc_status may not exist yet on installs deployed before this feature.
    $col = $pdo->query("SHOW COLUMNS FROM users LIKE 'kyc_status'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE users ADD COLUMN kyc_status VARCHAR(20) NOT NULL DEFAULT 'none' AFTER status");
    }

    // Onboarding was expanded from a single ID-upload step to a 3-step wizard
    // (verification, next of kin, trading profile). Add the extra columns
    // in place for installs created before this change.
    $kycExtraColumns = [
        'next_of_kin_name' => "ALTER TABLE kyc_submissions ADD COLUMN next_of_kin_name VARCHAR(191) NULL AFTER document_path",
        'next_of_kin_phone' => "ALTER TABLE kyc_submissions ADD COLUMN next_of_kin_phone VARCHAR(40) NULL AFTER next_of_kin_name",
        'next_of_kin_address' => "ALTER TABLE kyc_submissions ADD COLUMN next_of_kin_address TEXT NULL AFTER next_of_kin_phone",
        'capital_range' => "ALTER TABLE kyc_submissions ADD COLUMN capital_range VARCHAR(40) NULL AFTER next_of_kin_address",
        'net_worth_range' => "ALTER TABLE kyc_submissions ADD COLUMN net_worth_range VARCHAR(40) NULL AFTER capital_range",
    ];
    foreach ($kycExtraColumns as $colName => $alterSql) {
        $exists = $pdo->query("SHOW COLUMNS FROM kyc_submissions LIKE '$colName'")->fetch();
        if (!$exists) {
            $pdo->exec($alterSql);
        }
    }
}

function ensure_holdings_schema(PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS holdings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            symbol VARCHAR(20) NOT NULL,
            api_symbol VARCHAR(20) NOT NULL,
            asset_type VARCHAR(20) NOT NULL,
            quantity DECIMAL(18,8) NOT NULL,
            avg_cost DECIMAL(18,4) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id),
            UNIQUE KEY uniq_user_symbol (user_id, symbol)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function ensure_bank_deposits_schema(PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS bank_deposits (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            amount_usd DECIMAL(18,2) NOT NULL,
            depositor_name VARCHAR(191) NOT NULL,
            reference VARCHAR(191),
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            admin_note TEXT,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            reviewed_at DATETIME NULL,
            FOREIGN KEY (user_id) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function ensure_deposit_proof_columns(PDO $pdo): void {
    $col = $pdo->query("SHOW COLUMNS FROM crypto_deposits LIKE 'proof_path'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE crypto_deposits ADD COLUMN proof_path VARCHAR(255) AFTER tx_hash");
    }
    $col2 = $pdo->query("SHOW COLUMNS FROM bank_deposits LIKE 'proof_path'")->fetch();
    if (!$col2) {
        $pdo->exec("ALTER TABLE bank_deposits ADD COLUMN proof_path VARCHAR(255) AFTER reference");
    }
}

function ensure_expert_win_rate_manual(PDO $pdo): void {
    $col = $pdo->query("SHOW COLUMNS FROM experts LIKE 'win_rate_manual'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE experts ADD COLUMN win_rate_manual TINYINT(1) NOT NULL DEFAULT 0 AFTER win_rate");
    }
    $col2 = $pdo->query("SHOW COLUMNS FROM experts LIKE 'monthly_return_manual'")->fetch();
    if (!$col2) {
        $pdo->exec("ALTER TABLE experts ADD COLUMN monthly_return_manual TINYINT(1) NOT NULL DEFAULT 0 AFTER monthly_return");
    }
    $extraExpertCols = [
        'trade_count_manual'   => "ALTER TABLE experts ADD COLUMN trade_count_manual TINYINT(1) NOT NULL DEFAULT 0",
        'trade_count_override' => "ALTER TABLE experts ADD COLUMN trade_count_override INT NOT NULL DEFAULT 0",
        'photo_url'            => "ALTER TABLE experts ADD COLUMN photo_url VARCHAR(255) NULL",
        'bio'                  => "ALTER TABLE experts ADD COLUMN bio TEXT NULL",
        'min_copy_amount'      => "ALTER TABLE experts ADD COLUMN min_copy_amount DECIMAL(18,2) NOT NULL DEFAULT 50",
        'status'               => "ALTER TABLE experts ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'active'",
        'created_at'           => "ALTER TABLE experts ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
    ];
    foreach ($extraExpertCols as $colName => $sql) {
        $exists = $pdo->query("SHOW COLUMNS FROM experts LIKE '{$colName}'")->fetch();
        if (!$exists) {
            $pdo->exec($sql);
        }
    }
}

function ensure_live_plan_schema(PDO $pdo): void {
    // Investment plans now track real market prices (see includes/live_prices.php).
    $planCols = [
        'tracked_markets' => "ALTER TABLE investment_plans ADD COLUMN tracked_markets VARCHAR(500) NULL",
        'exposure'        => "ALTER TABLE investment_plans ADD COLUMN exposure DECIMAL(6,2) NOT NULL DEFAULT 1.00",
    ];
    foreach ($planCols as $c => $sql) {
        if (!$pdo->query("SHOW COLUMNS FROM investment_plans LIKE '{$c}'")->fetch()) $pdo->exec($sql);
    }
    if (!$pdo->query("SHOW COLUMNS FROM plan_subscriptions LIKE 'ref_prices'")->fetch()) {
        $pdo->exec("ALTER TABLE plan_subscriptions ADD COLUMN ref_prices TEXT NULL");
    }
    // Moment the live engine took over: ticks before this credited the wallet directly (legacy).
    $pdo->exec("INSERT IGNORE INTO settings (setting_key, value) VALUES ('plans_live_since', DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:%s'))");
    if (!$pdo->query("SHOW COLUMNS FROM plan_subscriptions LIKE 'legacy_reconciled'")->fetch()) {
        $pdo->exec("ALTER TABLE plan_subscriptions ADD COLUMN legacy_reconciled TINYINT(1) NOT NULL DEFAULT 0");
    }

    // AI bots: trade real markets with a rule-based strategy.
    $botCols = [
        'tracked_markets' => "ALTER TABLE bots ADD COLUMN tracked_markets VARCHAR(500) NULL",
        'strategy'        => "ALTER TABLE bots ADD COLUMN strategy VARCHAR(20) NOT NULL DEFAULT 'trend'",
        'exposure'        => "ALTER TABLE bots ADD COLUMN exposure DECIMAL(6,2) NOT NULL DEFAULT 1.00",
    ];
    foreach ($botCols as $c => $sql) {
        if (!$pdo->query("SHOW COLUMNS FROM bots LIKE '{$c}'")->fetch()) $pdo->exec($sql);
    }
    $botSubCols = [
        'ref_prices'        => "ALTER TABLE bot_subscriptions ADD COLUMN ref_prices TEXT NULL",
        'positions'         => "ALTER TABLE bot_subscriptions ADD COLUMN positions TEXT NULL",
        'legacy_reconciled' => "ALTER TABLE bot_subscriptions ADD COLUMN legacy_reconciled TINYINT(1) NOT NULL DEFAULT 0",
    ];
    foreach ($botSubCols as $c => $sql) {
        if (!$pdo->query("SHOW COLUMNS FROM bot_subscriptions LIKE '{$c}'")->fetch()) $pdo->exec($sql);
    }
    $pdo->exec("INSERT IGNORE INTO settings (setting_key, value) VALUES ('bots_live_since', DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:%s'))");
    foreach (['bot_ticks', 'plan_ticks'] as $t) {
        if (!$pdo->query("SHOW COLUMNS FROM {$t} LIKE 'created_at'")->fetch()) {
            $pdo->exec("ALTER TABLE {$t} ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
        }
    }
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS live_market_quotes (
            market_key VARCHAR(40) NOT NULL PRIMARY KEY,
            price DECIMAL(24,8) NULL,
            ref_24h DECIMAL(24,8) NULL,
            series TEXT NULL,
            source VARCHAR(20) NULL,
            fetched_at DATETIME NULL,
            last_error VARCHAR(255) NULL,
            last_error_at DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function ensure_instrument_prices_schema(PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS instrument_prices (
            id INT AUTO_INCREMENT PRIMARY KEY,
            symbol VARCHAR(20) NOT NULL UNIQUE,
            name VARCHAR(191) NOT NULL,
            asset_type VARCHAR(20) NOT NULL,
            current_price DECIMAL(18,4) NOT NULL,
            last_tick_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $col = $pdo->query("SHOW COLUMNS FROM instrument_prices LIKE 'price_24h_ago'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE instrument_prices ADD COLUMN price_24h_ago DECIMAL(18,4) NULL AFTER current_price");
        $pdo->exec("ALTER TABLE instrument_prices ADD COLUMN price_24h_updated_at DATETIME NULL AFTER price_24h_ago");
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS instrument_price_history (
            id INT AUTO_INCREMENT PRIMARY KEY,
            symbol VARCHAR(20) NOT NULL,
            price DECIMAL(18,4) NOT NULL,
            recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $count = (int)$pdo->query('SELECT COUNT(*) FROM instrument_prices')->fetchColumn();
    if ($count === 0) {
        seed_instrument_prices($pdo);
    }

    seed_forex_and_bonds($pdo);
}

function ensure_notifications_schema(PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            type VARCHAR(40) NOT NULL,
            title VARCHAR(191) NOT NULL,
            message TEXT,
            link_url VARCHAR(255),
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function ensure_referral_schema(PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS referral_rewards (
            id INT AUTO_INCREMENT PRIMARY KEY,
            referrer_user_id INT NOT NULL,
            referred_user_id INT NOT NULL,
            amount DECIMAL(18,2) NOT NULL,
            trigger_type VARCHAR(40) NOT NULL DEFAULT 'first_deposit',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (referrer_user_id) REFERENCES users(id),
            FOREIGN KEY (referred_user_id) REFERENCES users(id),
            UNIQUE KEY uniq_referred_reward (referred_user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $col = $pdo->query("SHOW COLUMNS FROM users LIKE 'referral_code'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE users ADD COLUMN referral_code VARCHAR(20) UNIQUE AFTER kyc_status");
        $pdo->exec("ALTER TABLE users ADD COLUMN referred_by INT NULL AFTER referral_code");
    }

    // Backfill referral codes for any existing users that don't have one yet.
    $stmt = $pdo->query('SELECT id FROM users WHERE referral_code IS NULL');
    foreach ($stmt->fetchAll() as $row) {
        $pdo->prepare('UPDATE users SET referral_code = ? WHERE id = ?')
            ->execute([generate_referral_code($pdo), $row['id']]);
    }
}

function ensure_audit_log_schema(PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS admin_audit_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            admin_id INT NOT NULL,
            action VARCHAR(100) NOT NULL,
            details TEXT,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (admin_id) REFERENCES admins(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function ensure_support_schema(PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS support_threads (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            subject VARCHAR(191) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'open',
            unread_by_admin TINYINT(1) NOT NULL DEFAULT 1,
            unread_by_user TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_message_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS support_messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            thread_id INT NOT NULL,
            sender_type VARCHAR(10) NOT NULL,
            sender_label VARCHAR(100) NOT NULL,
            message TEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (thread_id) REFERENCES support_threads(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}


function ensure_livechat_schema(PDO $pdo): void {
    // 1. Allow support_threads.user_id to be NULL for guest visitor chats
    try {
        $pdo->exec("ALTER TABLE support_threads MODIFY user_id INT NULL");
    } catch (Throwable $e) {
        // Ignored if host does not allow modifying or already null
    }

    // 2. Extra columns on support_threads
    $threadCols = [
        'session_token'  => "ALTER TABLE support_threads ADD COLUMN session_token VARCHAR(64) NULL AFTER user_id",
        'guest_name'     => "ALTER TABLE support_threads ADD COLUMN guest_name VARCHAR(100) NULL AFTER session_token",
        'guest_email'    => "ALTER TABLE support_threads ADD COLUMN guest_email VARCHAR(191) NULL AFTER guest_name",
        'bot_active'     => "ALTER TABLE support_threads ADD COLUMN bot_active TINYINT(1) NOT NULL DEFAULT 1 AFTER status",
        'user_typing_at' => "ALTER TABLE support_threads ADD COLUMN user_typing_at DATETIME NULL",
        'admin_typing_at'=> "ALTER TABLE support_threads ADD COLUMN admin_typing_at DATETIME NULL",
        'user_last_seen' => "ALTER TABLE support_threads ADD COLUMN user_last_seen DATETIME NULL",
        'admin_last_seen'=> "ALTER TABLE support_threads ADD COLUMN admin_last_seen DATETIME NULL",
    ];

    foreach ($threadCols as $colName => $alterSql) {
        $check = $pdo->query("SHOW COLUMNS FROM support_threads LIKE '$colName'")->fetch();
        if (!$check) {
            try {
                $pdo->exec($alterSql);
            } catch (Throwable $e) {}
        }
    }

    // 3. Index on session_token for fast guest lookups
    try {
        $idx = $pdo->query("SHOW INDEX FROM support_threads WHERE Key_name = 'idx_session_token'")->fetch();
        if (!$idx) {
            $pdo->exec("CREATE INDEX idx_session_token ON support_threads(session_token)");
        }
    } catch (Throwable $e) {}

    // 4. support_messages extra columns: is_bot
    $msgCol = $pdo->query("SHOW COLUMNS FROM support_messages LIKE 'is_bot'")->fetch();
    if (!$msgCol) {
        try {
            $pdo->exec("ALTER TABLE support_messages ADD COLUMN is_bot TINYINT(1) NOT NULL DEFAULT 0 AFTER sender_label");
        } catch (Throwable $e) {}
    }

    // 5. chat_canned_responses table
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS chat_canned_responses (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(100) NOT NULL,
            message TEXT NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    // 6. Seed canned responses if empty
    try {
        $count = (int)$pdo->query("SELECT COUNT(*) FROM chat_canned_responses")->fetchColumn();
        if ($count === 0) {
            $stmt = $pdo->prepare("INSERT INTO chat_canned_responses (title, message, sort_order) VALUES (?, ?, ?)");
            $seeds = [
                ['Greeting', 'Hello! Welcome to NexoraCopy Support. How can I assist you with your account today?', 1],
                ['Live Agent Joined', 'A live support specialist has joined the chat. I have reviewed your conversation history with our automated assistant and am happy to help you right now.', 2],
                ['Crypto Deposit Info', 'To deposit crypto, go to Wallet & Funds -> Deposit -> Crypto. Select your coin (BTC, ETH, USDT), send the exact amount to the address, and enter your transaction hash for swift confirmation.', 3],
                ['Bank Deposit Details', 'Bank transfer deposits require reference numbers matching your registered full name. Please submit the bank deposit notification on the Deposit page so our accounts desk can credit your balance.', 4],
                ['KYC Verification Help', 'To complete KYC verification, please head to Wallet & Funds -> Verification and upload a clear photo of your government ID or passport. Most reviews are approved in under 15 minutes during trading hours.', 5],
                ['Withdrawal Timeline', 'Withdrawals are audited and processed by our compliance desk typically within 1 to 2 hours during market hours. You will receive an on-platform notification as soon as the transfer is broadcast.', 6],
                ['Copy Trading & Bots', 'You can allocate funds to any vetted trader under Copy Trading or to our algorithmic strategies under AI Bots. You can track performance and stop copying at any time from your Portfolio.', 7],
                ['Ticket Resolution', 'Is there anything else I can help you with today? If you need further assistance later, feel free to message us back anytime!', 8],
            ];
            foreach ($seeds as $s) {
                $stmt->execute($s);
            }
        }
    } catch (Throwable $e) {}
}


function seed_forex_and_bonds(PDO $pdo): void {
    $stmt = $pdo->prepare('SELECT id FROM instrument_prices WHERE symbol = ?');
    $stmt->execute(['EURUSD']);
    if ($stmt->fetch()) return; // already seeded

    $forex = [
        ['EURUSD', 'Euro / US Dollar', 1.0850],
        ['GBPUSD', 'British Pound / US Dollar', 1.2680],
        ['USDJPY', 'US Dollar / Japanese Yen', 157.20],
        ['USDCAD', 'US Dollar / Canadian Dollar', 1.3720],
        ['AUDUSD', 'Australian Dollar / US Dollar', 0.6650],
        ['USDCHF', 'US Dollar / Swiss Franc', 0.8980],
    ];
    $bonds = [
        ['US02Y', 'US 2-Year Treasury Note', 104.20],
        ['US10Y', 'US 10-Year Treasury Note', 98.60],
        ['US30Y', 'US 30-Year Treasury Bond', 95.40],
    ];

    $insert = $pdo->prepare('INSERT INTO instrument_prices (symbol, name, asset_type, current_price) VALUES (?, ?, ?, ?)');
    foreach ($forex as $f) $insert->execute([$f[0], $f[1], 'forex', $f[2]]);
    foreach ($bonds as $b) $insert->execute([$b[0], $b[1], 'bonds', $b[2]]);
}

function seed_instrument_prices(PDO $pdo): void {
    // Real, recognizable tickers across sectors. Starting prices are
    // reasonable reference points, not live quotes - the internal tick
    // engine moves them from here on (see includes/market_data.php).
    $stocks = [
        ['AAPL', 'Apple Inc.', 195.50], ['MSFT', 'Microsoft Corp.', 425.20], ['GOOGL', 'Alphabet Inc. (A)', 175.80],
        ['GOOG', 'Alphabet Inc. (C)', 177.10], ['AMZN', 'Amazon.com Inc.', 185.40], ['META', 'Meta Platforms Inc.', 505.60],
        ['TSLA', 'Tesla Inc.', 245.30], ['NVDA', 'NVIDIA Corp.', 875.90], ['JPM', 'JPMorgan Chase & Co.', 198.70],
        ['BAC', 'Bank of America Corp.', 39.80], ['WFC', 'Wells Fargo & Co.', 58.20], ['GS', 'Goldman Sachs Group', 445.10],
        ['MS', 'Morgan Stanley', 96.40], ['C', 'Citigroup Inc.', 62.30], ['V', 'Visa Inc.', 275.60],
        ['MA', 'Mastercard Inc.', 465.90], ['PYPL', 'PayPal Holdings', 62.80], ['DIS', 'Walt Disney Co.', 112.40],
        ['NFLX', 'Netflix Inc.', 625.30], ['CMCSA', 'Comcast Corp.', 40.90], ['KO', 'Coca-Cola Co.', 62.10],
        ['PEP', 'PepsiCo Inc.', 172.50], ['MCD', "McDonald's Corp.", 289.70], ['SBUX', 'Starbucks Corp.', 92.40],
        ['NKE', 'Nike Inc.', 78.60], ['WMT', 'Walmart Inc.', 68.90], ['TGT', 'Target Corp.', 152.30],
        ['COST', 'Costco Wholesale Corp.', 725.40], ['HD', 'Home Depot Inc.', 345.80], ['LOW', "Lowe's Companies", 232.10],
        ['PG', 'Procter & Gamble Co.', 165.70], ['JNJ', 'Johnson & Johnson', 152.90], ['PFE', 'Pfizer Inc.', 28.40],
        ['MRK', 'Merck & Co.', 128.60], ['ABBV', 'AbbVie Inc.', 172.30], ['UNH', 'UnitedHealth Group', 495.20],
        ['CVS', 'CVS Health Corp.', 58.70], ['XOM', 'Exxon Mobil Corp.', 118.40], ['CVX', 'Chevron Corp.', 158.90],
        ['COP', 'ConocoPhillips', 108.20], ['SLB', 'Schlumberger NV', 45.60], ['BA', 'Boeing Co.', 178.30],
        ['LMT', 'Lockheed Martin Corp.', 465.80], ['RTX', 'RTX Corp.', 118.40], ['GE', 'General Electric Co.', 168.90],
        ['CAT', 'Caterpillar Inc.', 345.60], ['DE', 'Deere & Co.', 395.20], ['HON', 'Honeywell International', 205.40],
        ['MMM', '3M Co.', 128.60], ['IBM', 'International Business Machines', 188.30], ['INTC', 'Intel Corp.', 32.40],
        ['AMD', 'Advanced Micro Devices', 158.70], ['QCOM', 'Qualcomm Inc.', 168.90], ['ORCL', 'Oracle Corp.', 128.50],
        ['CRM', 'Salesforce Inc.', 285.60], ['ADBE', 'Adobe Inc.', 545.30], ['CSCO', 'Cisco Systems Inc.', 48.70],
        ['TXN', 'Texas Instruments', 195.40], ['AVGO', 'Broadcom Inc.', 1385.20], ['UBER', 'Uber Technologies', 72.60],
        ['LYFT', 'Lyft Inc.', 15.80], ['ABNB', 'Airbnb Inc.', 148.30], ['SPOT', 'Spotify Technology', 315.60],
        ['SNAP', 'Snap Inc.', 11.40], ['PINS', 'Pinterest Inc.', 32.70], ['SQ', 'Block Inc.', 68.90],
        ['SHOP', 'Shopify Inc.', 78.40], ['ZM', 'Zoom Video Communications', 62.30], ['DOCU', 'DocuSign Inc.', 58.60],
        ['ROKU', 'Roku Inc.', 65.20], ['T', 'AT&T Inc.', 18.90], ['VZ', 'Verizon Communications', 40.60],
        ['TMUS', 'T-Mobile US Inc.', 168.40], ['F', 'Ford Motor Co.', 11.80], ['GM', 'General Motors Co.', 45.30],
        ['RIVN', 'Rivian Automotive', 12.40], ['LCID', 'Lucid Group Inc.', 3.20], ['NIO', 'NIO Inc.', 5.60],
        ['BABA', 'Alibaba Group', 78.90], ['JD', 'JD.com Inc.', 28.40], ['PDD', 'PDD Holdings', 128.60],
        ['TSM', 'Taiwan Semiconductor', 145.30], ['ASML', 'ASML Holding NV', 925.40], ['SONY', 'Sony Group Corp.', 88.60],
        ['NVAX', 'Novavax Inc.', 8.40], ['MRNA', 'Moderna Inc.', 78.30], ['BNTX', 'BioNTech SE', 98.60],
        ['GILD', 'Gilead Sciences', 78.90], ['BMY', 'Bristol-Myers Squibb', 48.60], ['LLY', 'Eli Lilly and Co.', 785.40],
        ['ABT', 'Abbott Laboratories', 112.30], ['TMO', 'Thermo Fisher Scientific', 545.60], ['DHR', 'Danaher Corp.', 245.30],
        ['LIN', 'Linde plc', 445.60], ['APD', 'Air Products and Chemicals', 268.40], ['FCX', 'Freeport-McMoRan', 45.60],
        ['NEM', 'Newmont Corp.', 42.30], ['GOLD', 'Barrick Gold Corp.', 17.80], ['AA', 'Alcoa Corp.', 36.40],
        ['NUE', 'Nucor Corp.', 158.60], ['X', 'United States Steel Corp.', 38.90], ['CLF', 'Cleveland-Cliffs Inc.', 16.40],
    ];

    $metals = [
        ['XAUUSD', 'Gold Spot / USD', 2385.60], ['XAGUSD', 'Silver Spot / USD', 28.40],
        ['XPTUSD', 'Platinum Spot / USD', 985.30], ['XPDUSD', 'Palladium Spot / USD', 1025.60],
    ];

    $commodities = [
        ['WTI', 'Crude Oil (WTI)', 78.40], ['BRENT', 'Crude Oil (Brent)', 82.60], ['NATGAS', 'Natural Gas', 2.85],
        ['COPPER', 'Copper', 4.35], ['WHEAT', 'Wheat', 585.40], ['CORN', 'Corn', 445.60],
        ['SOYBEAN', 'Soybean', 1185.30], ['COFFEE', 'Coffee', 218.40], ['SUGAR', 'Sugar', 19.60], ['COTTON', 'Cotton', 72.30],
    ];

    $crypto = [
        ['BTC', 'Bitcoin', 68500.00], ['ETH', 'Ethereum', 3650.00], ['USDT', 'Tether', 1.00],
        ['BNB', 'BNB', 585.40], ['XRP', 'XRP', 0.62], ['ADA', 'Cardano', 0.58], ['SOL', 'Solana', 168.40],
        ['DOGE', 'Dogecoin', 0.16], ['DOT', 'Polkadot', 6.85], ['MATIC', 'Polygon', 0.72],
        ['LTC', 'Litecoin', 82.60], ['AVAX', 'Avalanche', 35.40], ['LINK', 'Chainlink', 14.60],
        ['UNI', 'Uniswap', 8.90], ['ATOM', 'Cosmos', 8.40],
    ];

    $stmt = $pdo->prepare(
        'INSERT INTO instrument_prices (symbol, name, asset_type, current_price) VALUES (?, ?, ?, ?)'
    );
    foreach ($stocks as $s) $stmt->execute([$s[0], $s[1], 'stocks', $s[2]]);
    foreach ($metals as $s) $stmt->execute([$s[0], $s[1], 'metals', $s[2]]);
    foreach ($commodities as $s) $stmt->execute([$s[0], $s[1], 'commodities', $s[2]]);
    foreach ($crypto as $s) $stmt->execute([$s[0], $s[1], 'crypto', $s[2]]);
}

function ensure_schema(PDO $pdo): void {
    $stmt = $pdo->query("SHOW TABLES LIKE 'users'");
    if ($stmt->fetch()) {
        return; // schema already exists
    }
    $schema = file_get_contents(SCHEMA_PATH);
    $pdo->exec($schema);
}

function table_is_empty(PDO $pdo, string $table): bool {
    $count = (int)$pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    return $count === 0;
}

function seed_if_needed(PDO $pdo): void {
    seed_default_admin($pdo);          // has its own internal guard
    seed_default_settings($pdo);       // uses INSERT IGNORE, safe to call every time

    if (table_is_empty($pdo, 'experts')) seed_demo_experts($pdo);
    if (table_is_empty($pdo, 'bots')) seed_demo_bots($pdo);
    if (table_is_empty($pdo, 'investment_plans')) seed_demo_plans($pdo);
}

function seed_default_admin(PDO $pdo): void {
    $stmt = $pdo->query('SELECT COUNT(*) FROM admins');
    if ((int)$stmt->fetchColumn() > 0) return;

    $stmt = $pdo->prepare('INSERT INTO admins (username, password_hash) VALUES (?, ?)');
    $initial = getenv('ADMIN_INITIAL_PASSWORD') ?: 'ChangeMe123!';
    $stmt->execute(['admin', password_hash($initial, PASSWORD_DEFAULT)]);
}

function seed_demo_experts(PDO $pdo): void {
    $experts = [
        ['Marcus Chen', 'stocks', 'medium', 68.5, 4.2, 100, 'Momentum swing trader focused on large-cap US tech and growth stocks.'],
        ['Amara Nwosu', 'gold', 'low', 74.0, 2.8, 50, 'Conservative gold and precious metals hedge strategist.'],
        ['Viktor Kowalski', 'commodities', 'high', 61.2, 6.5, 200, 'Aggressive oil, gas and agricultural commodities trader.'],
        ['Sarah Whitfield', 'mixed', 'medium', 70.1, 3.9, 100, 'Diversified strategy blending stocks, gold and commodities.'],
    ];
    $stmt = $pdo->prepare(
        'INSERT INTO experts (name, asset_focus, risk_level, win_rate, monthly_return, min_copy_amount, bio, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, \'active\')'
    );
    foreach ($experts as $e) {
        $stmt->execute([$e[0], $e[1], $e[2], $e[3], $e[4], $e[5], $e[6]]);
    }
}

function seed_demo_bots(PDO $pdo): void {
    $bots = [
        ['Nova AI', 'medium', 15, 100, 'NDX,AAPL,NVDA,MSFT', 'trend', 1.00, 'Momentum strategy on leading US tech. Goes long what is rising and short what is falling, re-checked every 15 minutes.'],
        ['Quantum Edge', 'high', 15, 250, 'BTC,ETH,SOL,OIL', 'trend', 2.00, 'Aggressive 2x momentum across crypto and crude oil. Bigger swings in both directions.'],
        ['SteadyFlow', 'low', 30, 50, 'GOLD,EURUSD,SPX', 'reversion', 1.00, 'Calm mean-reversion strategy: leans against short-term moves in gold, the euro and the S&P 500.'],
    ];
    $stmt = $pdo->prepare(
        'INSERT INTO bots (name, risk_level, min_return_pct, max_return_pct, tick_interval_minutes, min_investment, tracked_markets, strategy, exposure, description, status)
         VALUES (?, ?, 0, 0, ?, ?, ?, ?, ?, ?, \'active\')'
    );
    foreach ($bots as $b) $stmt->execute($b);
}

function seed_demo_plans(PDO $pdo): void {
    $plans = [
        ['Gold Reserve', 'low', 15, 100, 'GOLD,SILVER', 1.00, 'Tracks the live price of gold and silver. A steady, defensive plan for preserving value.'],
        ['Global Equities', 'medium', 15, 250, 'SPX,NDX,AAPL,MSFT,NVDA', 1.00, 'Follows a basket of leading US indices and blue-chip technology stocks in real time.'],
        ['Crypto Momentum', 'high', 15, 500, 'BTC,ETH,SOL', 1.00, 'Moves with Bitcoin, Ethereum and Solana. Higher volatility for investors comfortable with larger swings.'],
    ];
    $stmt = $pdo->prepare(
        'INSERT INTO investment_plans (name, risk_level, min_return_pct, max_return_pct, tick_interval_minutes, min_investment, tracked_markets, exposure, description, status)
         VALUES (?, ?, 0, 0, ?, ?, ?, ?, ?, \'active\')'
    );
    foreach ($plans as $p) $stmt->execute($p);
}

function seed_default_settings(PDO $pdo): void {
    $defaults = [
        'crypto_address_btc' => 'bc1q-test-placeholder-replace-me',
        'crypto_address_usdt_trc20' => 'T-test-placeholder-replace-me',
        'crypto_address_usdt_erc20' => '0x-test-placeholder-replace-me',
        'crypto_address_eth' => '0x-test-placeholder-replace-me',
        'bank_name' => 'Replace with real bank name',
        'bank_account_name' => 'Replace with real account holder name',
        'bank_account_number' => 'Replace with real account number',
        'bank_routing_ach' => 'Replace with real ACH routing number',
        'bank_routing_wire' => 'Replace with real wire routing number',
        'bank_beneficiary_address' => 'Replace with real beneficiary address',
        'bank_address' => 'Replace with real bank address',
        'bank_instructions' => 'Include your name and email as the transfer reference/narration.',
        'referral_bonus_amount' => '10.00',
        'cron_secret' => bin2hex(random_bytes(16)),
    ];
    $stmt = $pdo->prepare('INSERT IGNORE INTO settings (setting_key, value) VALUES (?, ?)');
    foreach ($defaults as $k => $v) {
        $stmt->execute([$k, $v]);
    }
}

function get_setting(PDO $pdo, string $key, string $default = ''): string {
    $stmt = $pdo->prepare('SELECT value FROM settings WHERE setting_key = ?');
    $stmt->execute([$key]);
    $val = $stmt->fetchColumn();
    return $val !== false ? $val : $default;
}

function set_setting(PDO $pdo, string $key, string $value): void {
    $pdo->prepare(
        'INSERT INTO settings (setting_key, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)'
    )->execute([$key, $value]);
}

/**
 * One-time rename of the old brand name in data already stored in the database
 * (chat replies, plan/bot texts, settings). Runs once, guarded by a settings flag.
 */
function ensure_brand_name(PDO $pdo): void {
    $new = 'NexoraCopy';
    try {
        $done = $pdo->query("SELECT value FROM settings WHERE setting_key = 'brand_name_applied'")->fetchColumn();
        if ($done === $new) return;
    } catch (Throwable $e) { return; }
    $targets = [
        'chat_canned_responses' => ['message', 'title'],
        'investment_plans' => ['name', 'description'],
        'bots' => ['name', 'description'],
        'experts' => ['bio'],
        'settings' => ['value'],
        'notifications' => ['message', 'title'],
    ];
    foreach ($targets as $table => $cols) {
        foreach ($cols as $col) {
            try {
                $pdo->exec("UPDATE `{$table}` SET `{$col}` = REPLACE(REPLACE(REPLACE(`{$col}`, 'Hedge Pty Ltd', '{$new}'), 'HedgeInv', '{$new}'), 'hedgeinv.com', 'nexocopy.net') WHERE `{$col}` LIKE '%edge%'");
            } catch (Throwable $e) { /* table or column not present - fine */ }
        }
    }
    try {
        $pdo->prepare("INSERT INTO settings (setting_key, value) VALUES ('brand_name_applied', ?) ON DUPLICATE KEY UPDATE value = VALUES(value)")->execute([$new]);
    } catch (Throwable $e) {}
}
