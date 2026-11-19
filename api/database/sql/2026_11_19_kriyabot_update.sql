-- =====================================================================
-- Kriyabot database update — 19 Nov 2026
-- For phpMyAdmin: select your Kriyabot database, open the SQL tab,
-- paste this whole file (or use Import) and click Go.
--
-- Equivalent to these Laravel migrations (do NOT also run `php artisan migrate`
-- for them — this script records them as already run):
--   2026_11_19_000000_default_india_timezone
--   2026_11_19_010000_create_finance_and_calendar_sync
--
-- Safe to run more than once: every step checks whether it is already done.
-- Take a backup first (phpMyAdmin → Export) as with any schema change.
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1. India time by default
-- ---------------------------------------------------------------------
ALTER TABLE `users` MODIFY `timezone` VARCHAR(64) NOT NULL DEFAULT 'Asia/Kolkata';

-- Accounts created earlier were saved as UTC by mistake.
UPDATE `users` SET `timezone` = 'Asia/Kolkata' WHERE `timezone` IN ('UTC', 'Asia/Calcutta');

-- ---------------------------------------------------------------------
-- 2. Finance: heads (categories) created by each user
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `finance_heads` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `workspace_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `name` VARCHAR(80) NOT NULL,
  `type` VARCHAR(10) NOT NULL COMMENT 'income | expense',
  `color` VARCHAR(16) NULL DEFAULT NULL,
  `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  `deleted_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `finance_heads_user_id_type_name_index` (`user_id`, `type`, `name`),
  KEY `finance_heads_workspace_id_foreign` (`workspace_id`),
  CONSTRAINT `finance_heads_workspace_id_foreign` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE,
  CONSTRAINT `finance_heads_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 3. Finance: income & expense entries (+ scanned bill copy)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `finance_entries` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `workspace_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `head_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `type` VARCHAR(10) NOT NULL COMMENT 'income | expense',
  `amount` DECIMAL(14,2) NOT NULL,
  `tax_amount` DECIMAL(14,2) NULL DEFAULT NULL,
  `currency` VARCHAR(3) NOT NULL DEFAULT 'INR',
  `entry_date` DATE NOT NULL,
  `party` VARCHAR(180) NULL DEFAULT NULL COMMENT 'vendor (expense) or payer (income)',
  `bill_no` VARCHAR(80) NULL DEFAULT NULL,
  `description` TEXT NULL,
  `payment_method` VARCHAR(24) NULL DEFAULT NULL,
  `source` VARCHAR(12) NOT NULL DEFAULT 'manual' COMMENT 'manual | scan | assistant',
  `needs_review` TINYINT(1) NOT NULL DEFAULT 0,
  `ai_suggested_head` VARCHAR(80) NULL DEFAULT NULL,
  `ai_confidence` TINYINT UNSIGNED NULL DEFAULT NULL COMMENT '0-100',
  `extraction` JSON NULL,
  `receipt_disk` VARCHAR(32) NULL DEFAULT NULL,
  `receipt_path` VARCHAR(255) NULL DEFAULT NULL,
  `receipt_original_name` VARCHAR(255) NULL DEFAULT NULL,
  `receipt_mime` VARCHAR(120) NULL DEFAULT NULL,
  `receipt_size` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  `deleted_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `finance_entries_user_id_entry_date_index` (`user_id`, `entry_date`),
  KEY `finance_entries_user_id_type_entry_date_index` (`user_id`, `type`, `entry_date`),
  KEY `finance_entries_user_id_bill_no_index` (`user_id`, `bill_no`),
  KEY `finance_entries_workspace_id_foreign` (`workspace_id`),
  KEY `finance_entries_head_id_foreign` (`head_id`),
  CONSTRAINT `finance_entries_workspace_id_foreign` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE,
  CONSTRAINT `finance_entries_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `finance_entries_head_id_foreign` FOREIGN KEY (`head_id`) REFERENCES `finance_heads` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 4. Calendar sync: imported Outlook / Google / iCloud calendars
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `calendar_sources` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `workspace_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `name` VARCHAR(80) NOT NULL,
  `url` TEXT NOT NULL COMMENT 'encrypted by Laravel',
  `color` VARCHAR(16) NULL DEFAULT NULL,
  `last_synced_at` DATETIME NULL DEFAULT NULL,
  `last_error` TEXT NULL,
  `events_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `calendar_sources_user_id_index` (`user_id`),
  KEY `calendar_sources_workspace_id_foreign` (`workspace_id`),
  CONSTRAINT `calendar_sources_workspace_id_foreign` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE,
  CONSTRAINT `calendar_sources_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `external_events` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `calendar_source_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `uid_hash` VARCHAR(64) NOT NULL,
  `title` VARCHAR(240) NOT NULL,
  `location` VARCHAR(240) NULL DEFAULT NULL,
  `starts_at` DATETIME NOT NULL,
  `ends_at` DATETIME NOT NULL,
  `all_day` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `external_events_user_id_starts_at_index` (`user_id`, `starts_at`),
  KEY `external_events_calendar_source_id_uid_hash_index` (`calendar_source_id`, `uid_hash`),
  CONSTRAINT `external_events_calendar_source_id_foreign` FOREIGN KEY (`calendar_source_id`) REFERENCES `calendar_sources` (`id`) ON DELETE CASCADE,
  CONSTRAINT `external_events_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 5. Private calendar-feed link per user (added only if missing)
-- ---------------------------------------------------------------------
SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'calendar_feed_token');
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE `users` ADD COLUMN `calendar_feed_token` VARCHAR(64) NULL DEFAULT NULL, ADD UNIQUE KEY `users_calendar_feed_token_unique` (`calendar_feed_token`)',
  'SELECT ''calendar_feed_token already exists'' AS info');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- 6. Tell Laravel these migrations are done (so `migrate` won't repeat them)
-- ---------------------------------------------------------------------
SET @batch := (SELECT COALESCE(MAX(`batch`), 0) + 1 FROM `migrations`);

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_11_19_000000_default_india_timezone', @batch FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = '2026_11_19_000000_default_india_timezone');

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_11_19_010000_create_finance_and_calendar_sync', @batch FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = '2026_11_19_010000_create_finance_and_calendar_sync');

-- Done. Check: SHOW TABLES LIKE 'finance%';
