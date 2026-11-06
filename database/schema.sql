-- HedgeInv base schema (structure only, no data).
-- Generated from the live database structure. The app creates this automatically on first run.
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `admin_audit_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `admin_id` int(11) NOT NULL,
  `action` varchar(100) NOT NULL,
  `details` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_audit_admin` (`admin_id`),
  CONSTRAINT `admin_audit_log_ibfk_1` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `admins` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(100) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `bank_deposits` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `amount_usd` decimal(18,2) NOT NULL,
  `depositor_name` varchar(191) NOT NULL,
  `reference` varchar(191) DEFAULT NULL,
  `proof_path` varchar(255) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `admin_note` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `reviewed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_bank_deposits_user` (`user_id`),
  CONSTRAINT `bank_deposits_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `bot_subscriptions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `bot_id` int(11) NOT NULL,
  `amount_allocated` decimal(18,2) NOT NULL,
  `current_value` decimal(18,2) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `started_at` datetime NOT NULL DEFAULT current_timestamp(),
  `stopped_at` datetime DEFAULT NULL,
  `last_tick_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_bot_subs_user` (`user_id`),
  KEY `idx_bot_subs_bot` (`bot_id`),
  CONSTRAINT `bot_subscriptions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `bot_subscriptions_ibfk_2` FOREIGN KEY (`bot_id`) REFERENCES `bots` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `bot_ticks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `subscription_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `bot_id` int(11) NOT NULL,
  `profit_percent` decimal(8,4) NOT NULL,
  `profit_amount` decimal(18,2) NOT NULL,
  `balance_before` decimal(18,2) NOT NULL,
  `balance_after` decimal(18,2) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `bots` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(191) NOT NULL,
  `photo_url` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `risk_level` varchar(20) NOT NULL DEFAULT 'medium',
  `min_return_pct` decimal(8,4) NOT NULL DEFAULT -2.0000,
  `max_return_pct` decimal(8,4) NOT NULL DEFAULT 4.0000,
  `tick_interval_minutes` int(11) NOT NULL DEFAULT 60,
  `min_investment` decimal(18,2) NOT NULL DEFAULT 50.00,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `chat_canned_responses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(100) NOT NULL,
  `message` text NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `copies` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `expert_id` int(11) NOT NULL,
  `amount_allocated` decimal(18,2) NOT NULL,
  `current_value` decimal(18,2) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `started_at` datetime NOT NULL DEFAULT current_timestamp(),
  `stopped_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_copies_user` (`user_id`),
  KEY `idx_copies_expert` (`expert_id`),
  CONSTRAINT `copies_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `copies_ibfk_2` FOREIGN KEY (`expert_id`) REFERENCES `experts` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `copy_adjustments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `copy_id` int(11) NOT NULL,
  `amount` decimal(18,2) NOT NULL,
  `note` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `copy_id` (`copy_id`),
  CONSTRAINT `copy_adjustments_ibfk_1` FOREIGN KEY (`copy_id`) REFERENCES `copies` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `crypto_deposits` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `crypto_type` varchar(30) NOT NULL,
  `amount_usd` decimal(18,2) NOT NULL,
  `receiving_address` varchar(255) NOT NULL,
  `tx_hash` varchar(255) DEFAULT NULL,
  `proof_path` varchar(255) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `admin_note` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `reviewed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_crypto_deposits_user` (`user_id`),
  CONSTRAINT `crypto_deposits_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `experts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(191) NOT NULL,
  `photo_url` varchar(255) DEFAULT NULL,
  `bio` text DEFAULT NULL,
  `asset_focus` varchar(20) NOT NULL DEFAULT 'stocks',
  `risk_level` varchar(20) NOT NULL DEFAULT 'medium',
  `win_rate` decimal(6,2) NOT NULL DEFAULT 0.00,
  `win_rate_manual` tinyint(1) NOT NULL DEFAULT 0,
  `monthly_return` decimal(6,2) NOT NULL DEFAULT 0.00,
  `monthly_return_manual` tinyint(1) NOT NULL DEFAULT 0,
  `min_copy_amount` decimal(18,2) NOT NULL DEFAULT 50.00,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `trade_count_manual` tinyint(1) NOT NULL DEFAULT 0,
  `trade_count_override` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `holdings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `symbol` varchar(20) NOT NULL,
  `api_symbol` varchar(20) NOT NULL,
  `asset_type` varchar(20) NOT NULL,
  `quantity` decimal(18,8) NOT NULL,
  `avg_cost` decimal(18,4) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_symbol` (`user_id`,`symbol`),
  KEY `idx_holdings_user` (`user_id`),
  CONSTRAINT `holdings_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `instrument_price_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `symbol` varchar(20) NOT NULL,
  `price` decimal(18,4) NOT NULL,
  `recorded_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `instrument_prices` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `symbol` varchar(20) NOT NULL,
  `name` varchar(191) NOT NULL,
  `asset_type` varchar(20) NOT NULL,
  `current_price` decimal(18,4) NOT NULL,
  `price_24h_ago` decimal(18,4) DEFAULT NULL,
  `price_24h_updated_at` datetime DEFAULT NULL,
  `last_tick_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `symbol` (`symbol`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `investment_plans` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(191) NOT NULL,
  `photo_url` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `risk_level` varchar(20) NOT NULL DEFAULT 'medium',
  `min_return_pct` decimal(8,4) NOT NULL DEFAULT -3.0000,
  `max_return_pct` decimal(8,4) NOT NULL DEFAULT 5.0000,
  `tick_interval_minutes` int(11) NOT NULL DEFAULT 60,
  `min_investment` decimal(18,2) NOT NULL DEFAULT 100.00,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `kyc_submissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `full_legal_name` varchar(191) NOT NULL,
  `id_type` varchar(30) NOT NULL,
  `id_number` varchar(100) NOT NULL,
  `document_path` varchar(255) NOT NULL,
  `next_of_kin_name` varchar(191) DEFAULT NULL,
  `next_of_kin_phone` varchar(40) DEFAULT NULL,
  `next_of_kin_address` text DEFAULT NULL,
  `capital_range` varchar(40) DEFAULT NULL,
  `net_worth_range` varchar(40) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `admin_note` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `reviewed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `kyc_submissions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `type` varchar(40) NOT NULL,
  `title` varchar(191) NOT NULL,
  `message` text DEFAULT NULL,
  `link_url` varchar(255) DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_notifications_user` (`user_id`,`is_read`),
  CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `plan_subscriptions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `plan_id` int(11) NOT NULL,
  `amount_allocated` decimal(18,2) NOT NULL,
  `current_value` decimal(18,2) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `started_at` datetime NOT NULL DEFAULT current_timestamp(),
  `stopped_at` datetime DEFAULT NULL,
  `last_tick_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_plan_subs_user` (`user_id`),
  KEY `idx_plan_subs_plan` (`plan_id`),
  CONSTRAINT `plan_subscriptions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `plan_subscriptions_ibfk_2` FOREIGN KEY (`plan_id`) REFERENCES `investment_plans` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `plan_ticks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `subscription_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `plan_id` int(11) NOT NULL,
  `profit_percent` decimal(8,4) NOT NULL,
  `profit_amount` decimal(18,2) NOT NULL,
  `balance_before` decimal(18,2) NOT NULL,
  `balance_after` decimal(18,2) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `referral_rewards` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `referrer_user_id` int(11) NOT NULL,
  `referred_user_id` int(11) NOT NULL,
  `amount` decimal(18,2) NOT NULL,
  `trigger_type` varchar(40) NOT NULL DEFAULT 'first_deposit',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_referred_reward` (`referred_user_id`),
  KEY `referrer_user_id` (`referrer_user_id`),
  CONSTRAINT `referral_rewards_ibfk_1` FOREIGN KEY (`referrer_user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `referral_rewards_ibfk_2` FOREIGN KEY (`referred_user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `settings` (
  `setting_key` varchar(100) NOT NULL,
  `value` text DEFAULT NULL,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `support_messages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `thread_id` int(11) NOT NULL,
  `sender_type` varchar(10) NOT NULL,
  `sender_label` varchar(100) NOT NULL,
  `is_bot` tinyint(1) NOT NULL DEFAULT 0,
  `message` text NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `thread_id` (`thread_id`),
  CONSTRAINT `support_messages_ibfk_1` FOREIGN KEY (`thread_id`) REFERENCES `support_threads` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `support_threads` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `session_token` varchar(64) DEFAULT NULL,
  `guest_name` varchar(100) DEFAULT NULL,
  `guest_email` varchar(191) DEFAULT NULL,
  `subject` varchar(191) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'open',
  `bot_active` tinyint(1) NOT NULL DEFAULT 1,
  `unread_by_admin` tinyint(1) NOT NULL DEFAULT 1,
  `unread_by_user` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_message_at` datetime NOT NULL DEFAULT current_timestamp(),
  `user_typing_at` datetime DEFAULT NULL,
  `admin_typing_at` datetime DEFAULT NULL,
  `user_last_seen` datetime DEFAULT NULL,
  `admin_last_seen` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `idx_session_token` (`session_token`),
  CONSTRAINT `support_threads_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `trade_distributions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `trade_id` int(11) NOT NULL,
  `copy_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `profit_amount` decimal(18,2) NOT NULL,
  `balance_before` decimal(18,2) NOT NULL,
  `balance_after` decimal(18,2) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `trade_id` (`trade_id`),
  KEY `copy_id` (`copy_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `trade_distributions_ibfk_1` FOREIGN KEY (`trade_id`) REFERENCES `trades` (`id`),
  CONSTRAINT `trade_distributions_ibfk_2` FOREIGN KEY (`copy_id`) REFERENCES `copies` (`id`),
  CONSTRAINT `trade_distributions_ibfk_3` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `trades` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `expert_id` int(11) NOT NULL,
  `asset_symbol` varchar(30) NOT NULL,
  `asset_type` varchar(20) NOT NULL,
  `direction` varchar(10) NOT NULL,
  `entry_price` decimal(18,4) NOT NULL,
  `exit_price` decimal(18,4) NOT NULL,
  `profit_percent` decimal(8,4) NOT NULL,
  `trade_date` datetime NOT NULL DEFAULT current_timestamp(),
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_trades_expert` (`expert_id`),
  CONSTRAINT `trades_ibfk_1` FOREIGN KEY (`expert_id`) REFERENCES `experts` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `transactions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `type` varchar(30) NOT NULL,
  `amount` decimal(18,2) NOT NULL,
  `balance_after` decimal(18,2) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'completed',
  `reference` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_txn_user` (`user_id`),
  CONSTRAINT `transactions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `full_name` varchar(191) NOT NULL,
  `email` varchar(191) NOT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `wallet_balance` decimal(18,2) NOT NULL DEFAULT 0.00,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `kyc_status` varchar(20) NOT NULL DEFAULT 'none',
  `referral_code` varchar(20) DEFAULT NULL,
  `referred_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `referral_code` (`referral_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

SET FOREIGN_KEY_CHECKS = 1;
