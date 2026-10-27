<?php
declare(strict_types=1);
namespace App\Support;

use PDO;

final class Database
{
    public static function connect(): PDO
    {
        if ((getenv('DB_CONNECTION') ?: 'sqlite') === 'mysql') {
            $db = new PDO('mysql:host=' . (getenv('DB_HOST') ?: '127.0.0.1') . ';port=' . (getenv('DB_PORT') ?: '3306') . ';dbname=' . (getenv('DB_DATABASE') ?: 'email_automation') . ';charset=utf8mb4', getenv('DB_USERNAME') ?: '', getenv('DB_PASSWORD') ?: '');
        } else {
            $path = getenv('DB_DATABASE') ?: dirname(__DIR__, 2) . '/storage/app/private/database.sqlite';
            $db = new PDO('sqlite:' . $path);
            $db->exec('PRAGMA busy_timeout = 5000');
        }
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return $db;
    }

    public static function migrate(PDO $db): void
    {
        $id = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'INTEGER PRIMARY KEY AUTO_INCREMENT' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $db->exec("CREATE TABLE IF NOT EXISTS emails (
            id $id, recipient VARCHAR(254) NOT NULL, cc TEXT NOT NULL, bcc TEXT NOT NULL,
            subject VARCHAR(255) NOT NULL, body TEXT NOT NULL, attachments TEXT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'pending', idempotency_key VARCHAR(64) NOT NULL UNIQUE,
            attempts INTEGER NOT NULL DEFAULT 0, error TEXT NULL, message_id VARCHAR(255) NULL,
            created_at VARCHAR(32) NOT NULL, updated_at VARCHAR(32) NOT NULL
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS activity (
            id $id, email_id INTEGER NULL, action VARCHAR(80) NOT NULL,
            detail TEXT NOT NULL, created_at VARCHAR(32) NOT NULL
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS google_connection (
            id INTEGER PRIMARY KEY, token_data TEXT NOT NULL, updated_at VARCHAR(32) NOT NULL
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS sheet_sources (
            id $id, spreadsheet_id VARCHAR(160) NOT NULL, sheet_id INTEGER NOT NULL,
            title VARCHAR(255) NOT NULL, header_row INTEGER NOT NULL, mapping TEXT NOT NULL,
            created_at VARCHAR(32) NOT NULL, UNIQUE(spreadsheet_id, sheet_id)
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS uploaded_sheets (
            id VARCHAR(80) PRIMARY KEY, title VARCHAR(255) NOT NULL, workbook MEDIUMTEXT NOT NULL,
            created_at VARCHAR(32) NOT NULL
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS inbox_emails (
            id $id, uid INTEGER NOT NULL UNIQUE, message_id VARCHAR(255) NULL,
            from_name VARCHAR(255) NULL, from_email VARCHAR(254) NOT NULL,
            to_email VARCHAR(254) NOT NULL, subject VARCHAR(255) NOT NULL,
            body LONGTEXT NOT NULL, html_body LONGTEXT NULL, snippet VARCHAR(255) NULL,
            is_read INTEGER NOT NULL DEFAULT 0, received_at VARCHAR(64) NOT NULL,
            created_at VARCHAR(32) NOT NULL
        )");
        try {
            $db->exec("ALTER TABLE inbox_emails ADD COLUMN html_body LONGTEXT NULL AFTER body");
        } catch (\Throwable $e) {}
        try {
            $db->exec("ALTER TABLE emails ADD COLUMN attachments TEXT NULL AFTER body");
        } catch (\Throwable $e) {}
        try {
            $db->exec("ALTER TABLE emails ADD COLUMN inbox_email_id INTEGER NULL");
        } catch (\Throwable $e) {}
        try {
            $db->exec("ALTER TABLE emails ADD COLUMN thread_id VARCHAR(128) NULL");
        } catch (\Throwable $e) {}
    }
}
