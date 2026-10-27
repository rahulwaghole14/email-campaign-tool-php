-- ====================================================================
-- RSL Communication Desk & Email Automation Platform
-- Production Database Schema (Structure Only - No Data)
-- Ready for Hostinger MySQL / phpMyAdmin / cPanel / Linux Server
-- Character Set: utf8mb4 / Collation: utf8mb4_unicode_ci / Engine: InnoDB
-- ====================================================================

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

-- --------------------------------------------------------------------
-- 1. Table structure for `emails` (Outgoing email queue & logs)
-- --------------------------------------------------------------------
DROP TABLE IF EXISTS `emails`;
CREATE TABLE `emails` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `recipient` VARCHAR(254) NOT NULL,
  `cc` TEXT NOT NULL,
  `bcc` TEXT NOT NULL,
  `subject` VARCHAR(255) NOT NULL,
  `body` TEXT NOT NULL,
  `attachments` TEXT DEFAULT NULL,
  `status` VARCHAR(32) NOT NULL DEFAULT 'pending',
  `idempotency_key` VARCHAR(64) NOT NULL,
  `attempts` INT NOT NULL DEFAULT 0,
  `error` TEXT DEFAULT NULL,
  `message_id` VARCHAR(255) DEFAULT NULL,
  `inbox_email_id` INT DEFAULT NULL,
  `thread_id` VARCHAR(128) DEFAULT NULL,
  `sent_at` VARCHAR(32) DEFAULT NULL,
  `created_at` VARCHAR(32) NOT NULL,
  `updated_at` VARCHAR(32) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_idempotency_key` (`idempotency_key`),
  KEY `idx_emails_status` (`status`),
  KEY `idx_emails_recipient` (`recipient`),
  KEY `idx_emails_thread_id` (`thread_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------
-- 2. Table structure for `inbox_emails` (Hostinger IMAP incoming messages)
-- --------------------------------------------------------------------
DROP TABLE IF EXISTS `inbox_emails`;
CREATE TABLE `inbox_emails` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `uid` INT NOT NULL,
  `message_id` VARCHAR(255) DEFAULT NULL,
  `from_name` VARCHAR(255) DEFAULT NULL,
  `from_email` VARCHAR(254) NOT NULL,
  `to_email` VARCHAR(254) NOT NULL,
  `subject` VARCHAR(255) NOT NULL,
  `body` LONGTEXT NOT NULL,
  `html_body` LONGTEXT DEFAULT NULL,
  `snippet` VARCHAR(255) DEFAULT NULL,
  `is_read` INT NOT NULL DEFAULT 0,
  `received_at` VARCHAR(64) NOT NULL,
  `created_at` VARCHAR(32) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_inbox_uid` (`uid`),
  KEY `idx_inbox_from_email` (`from_email`),
  KEY `idx_inbox_is_read` (`is_read`),
  KEY `idx_inbox_received_at` (`received_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------
-- 3. Table structure for `activity` (Audit events and delivery timeline)
-- --------------------------------------------------------------------
DROP TABLE IF EXISTS `activity`;
CREATE TABLE `activity` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `email_id` INT DEFAULT NULL,
  `action` VARCHAR(80) NOT NULL,
  `detail` TEXT NOT NULL,
  `created_at` VARCHAR(32) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_activity_email_id` (`email_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------
-- 4. Table structure for `google_connection` (OAuth tokens for Google Sheets)
-- --------------------------------------------------------------------
DROP TABLE IF EXISTS `google_connection`;
CREATE TABLE `google_connection` (
  `id` INT NOT NULL,
  `token_data` TEXT NOT NULL,
  `updated_at` VARCHAR(32) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------
-- 5. Table structure for `sheet_sources` (Connected Google Sheets & mappings)
-- --------------------------------------------------------------------
DROP TABLE IF EXISTS `sheet_sources`;
CREATE TABLE `sheet_sources` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `spreadsheet_id` VARCHAR(160) NOT NULL,
  `sheet_id` INT NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `header_row` INT NOT NULL,
  `mapping` TEXT NOT NULL,
  `created_at` VARCHAR(32) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_sheet_source` (`spreadsheet_id`, `sheet_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------
-- 6. Table structure for `uploaded_sheets` (Direct Excel/CSV imports)
-- --------------------------------------------------------------------
DROP TABLE IF EXISTS `uploaded_sheets`;
CREATE TABLE `uploaded_sheets` (
  `id` VARCHAR(80) NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `workbook` MEDIUMTEXT NOT NULL,
  `created_at` VARCHAR(32) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
COMMIT;

