<?php
declare(strict_types=1);

namespace App\Services\WhatsApp;

use PDO;

/** Local tables for linked phones, per-phone chats, and when a send is due. */
final class WhatsAppTables
{
    public static function ensure(PDO $db): void
    {
        $pk = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
            ? 'INTEGER PRIMARY KEY AUTO_INCREMENT'
            : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $db->exec("CREATE TABLE IF NOT EXISTS whatsapp_devices (
            id $pk,
            device_id VARCHAR(80) NOT NULL UNIQUE,
            name VARCHAR(80) NOT NULL,
            phone VARCHAR(32) NULL,
            status VARCHAR(16) NOT NULL,
            updated_at VARCHAR(32) NOT NULL
        )");
        // Small key-value state for the queue, such as whether sending is paused.
        $db->exec("CREATE TABLE IF NOT EXISTS whatsapp_queue_state (
            name VARCHAR(40) NOT NULL PRIMARY KEY,
            value VARCHAR(255) NOT NULL
        )");
        self::addColumn($db, 'whatsapp_sends', 'due_at', 'VARCHAR(32) NULL');
        self::addColumn($db, 'whatsapp_sends', 'source_id', 'INTEGER NULL');
        self::addColumn($db, 'whatsapp_sends', 'record_id', 'VARCHAR(160) NULL');
        self::addColumn($db, 'whatsapp_sends', 'device_id', 'VARCHAR(80) NULL');
        self::addColumn($db, 'whatsapp_sends', 'attempts', 'INTEGER NULL');
        self::addColumn($db, 'whatsapp_chats', 'device_id', 'VARCHAR(80) NULL');
        // The queue reads each sheet's per-day limit and random gap max, so the cron job needs these columns too.
        self::addColumn($db, 'whatsapp_sheet_sources', 'daily_limit', 'INTEGER NULL');
        self::addColumn($db, 'whatsapp_sheet_sources', 'delay_max_minutes', 'INTEGER NULL');
        // Per-sheet pause: Resume on one sheet must not start another sheet's waiting rows.
        self::addColumn($db, 'whatsapp_sheet_sources', 'paused', 'INTEGER NOT NULL DEFAULT 0');
        // Desk Disconnect holds the slot (same device_id) until Pair — never DELETE the Message API device.
        self::addColumn($db, 'whatsapp_devices', 'held', 'INTEGER NOT NULL DEFAULT 0');
        self::migrateGlobalPause($db);
    }

    /** Mark a phone disconnected on this desk without deleting its Message API slot. */
    public static function holdDevice(PDO $db, string $deviceId): void
    {
        self::ensure($db);
        $id = trim($deviceId);
        if ($id === '' || !preg_match('/^[A-Za-z0-9-]{8,80}$/', $id)) return;
        $now = gmdate('c');
        $upd = $db->prepare("UPDATE whatsapp_devices SET held = 1, status = 'disconnected', updated_at = ? WHERE device_id = ?");
        $upd->execute([$now, $id]);
        if ($upd->rowCount() > 0) return;
        $db->prepare("INSERT INTO whatsapp_devices (device_id, name, phone, status, updated_at, held) VALUES (?, 'Phone', NULL, 'disconnected', ?, 1)")
            ->execute([$id, $now]);
    }

    /** Clear a desk hold so Pair / reconnect can show Ready again on the same device_id. */
    public static function releaseHold(PDO $db, string $deviceId): void
    {
        self::ensure($db);
        $id = trim($deviceId);
        if ($id === '') return;
        $db->prepare('UPDATE whatsapp_devices SET held = 0, updated_at = ? WHERE device_id = ?')
            ->execute([gmdate('c'), $id]);
    }

    /** @return array<string, true> device_id => true for phones held by desk Disconnect */
    public static function heldIds(PDO $db): array
    {
        self::ensure($db);
        $out = [];
        foreach ($db->query('SELECT device_id FROM whatsapp_devices WHERE held = 1')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = trim((string) ($row['device_id'] ?? ''));
            if ($id !== '') $out[$id] = true;
        }
        return $out;
    }

    /** Move the old account-wide pause onto every sheet once, then drop the global flag. */
    private static function migrateGlobalPause(PDO $db): void
    {
        if (!self::tableExists($db, 'whatsapp_queue_state') || !self::tableExists($db, 'whatsapp_sheet_sources')) return;
        $global = $db->query("SELECT value FROM whatsapp_queue_state WHERE name = 'paused'")->fetchColumn();
        if ($global === false) return;
        if ((string) $global === '1') {
            $db->exec('UPDATE whatsapp_sheet_sources SET paused = 1');
        }
        $db->exec("DELETE FROM whatsapp_queue_state WHERE name = 'paused'");
    }

    /** @param list<array<string, mixed>> $devices */
    public static function saveRoster(PDO $db, array $devices): void
    {
        self::ensure($db);
        $now = gmdate('c');
        $seen = [];
        $find = $db->prepare('SELECT status, phone, held FROM whatsapp_devices WHERE device_id = ?');
        $insert = $db->prepare('INSERT INTO whatsapp_devices (device_id, name, phone, status, updated_at, held) VALUES (?, ?, ?, ?, ?, 0)');
        $update = $db->prepare('UPDATE whatsapp_devices SET name = ?, phone = ?, status = ?, updated_at = ? WHERE device_id = ?');
        foreach ($devices as $device) {
            if (!is_array($device)) continue;
            $id = trim((string) ($device['id'] ?? ''));
            $name = trim((string) ($device['name'] ?? ''));
            if (!preg_match('/^[A-Za-z0-9-]{8,80}$/', $id) || $name === '') continue;
            if (mb_strlen($name) > 80) $name = mb_substr($name, 0, 80);
            $status = (string) ($device['status'] ?? '');
            if (!in_array($status, ['ready', 'disconnected', 'blocked'], true)) $status = 'disconnected';
            $phone = trim((string) ($device['phone'] ?? ''));
            $phone = $phone !== '' ? $phone : null;
            $find->execute([$id]);
            $current = $find->fetch(PDO::FETCH_ASSOC);
            if ($current === false) {
                $insert->execute([$id, $name, $phone, $status, $now]);
            } else {
                if ((string) ($current['status'] ?? '') === 'blocked') $status = 'blocked';
                // Desk Disconnect holds the slot: keep Not linked until Pair, even if the phone service still says connected.
                if ((int) ($current['held'] ?? 0) === 1 && $status === 'ready') $status = 'disconnected';
                // Keep the last known number when the live list omits it (common for blocked/idle phones).
                $kept = trim((string) ($current['phone'] ?? ''));
                if ($phone === null && $kept !== '') $phone = $kept;
                $update->execute([$name, $phone, $status, $now, $id]);
            }
            $seen[] = $id;
        }
        if ($seen !== []) {
            $rows = $db->query('SELECT device_id, status FROM whatsapp_devices')->fetchAll();
            $mark = $db->prepare("UPDATE whatsapp_devices SET status = 'disconnected', updated_at = ? WHERE device_id = ? AND status <> 'blocked'");
            foreach ($rows as $row) {
                if (!is_array($row)) continue;
                $id = (string) ($row['device_id'] ?? '');
                if ($id === '' || in_array($id, $seen, true) || (string) ($row['status'] ?? '') === 'blocked') continue;
                $mark->execute([$now, $id]);
            }
        }
        self::backfill($db);
    }

    public static function backfill(PDO $db): void
    {
        self::ensure($db);
        $names = [];
        foreach ($db->query('SELECT device_id, name FROM whatsapp_devices')->fetchAll() as $row) {
            if (!is_array($row)) continue;
            $name = strtolower(trim((string) ($row['name'] ?? '')));
            $id = trim((string) ($row['device_id'] ?? ''));
            if ($name === '' || $id === '') continue;
            $names[$name] = isset($names[$name]) ? '' : $id;
        }
        $setSend = $db->prepare('UPDATE whatsapp_sends SET device_id = ? WHERE id = ?');
        foreach ($db->query("SELECT id, account_name, device_id FROM whatsapp_sends")->fetchAll() as $row) {
            if (!is_array($row) || trim((string) ($row['device_id'] ?? '')) !== '') continue;
            $key = strtolower(trim((string) ($row['account_name'] ?? '')));
            $id = $names[$key] ?? '';
            if ($id !== '') $setSend->execute([$id, (int) $row['id']]);
        }
        $setChat = $db->prepare('UPDATE whatsapp_chats SET device_id = ? WHERE id = ?');
        $chats = $db->query('SELECT id, phone, device_id FROM whatsapp_chats')->fetchAll();
        $sends = $db->query("SELECT phone, device_id FROM whatsapp_sends WHERE device_id IS NOT NULL AND device_id <> ''")->fetchAll();
        foreach ($chats as $chat) {
            if (!is_array($chat) || trim((string) ($chat['device_id'] ?? '')) !== '') continue;
            $digits = preg_replace('/\D+/', '', (string) ($chat['phone'] ?? '')) ?? '';
            $ids = [];
            foreach ($sends as $send) {
                if (!is_array($send)) continue;
                $other = preg_replace('/\D+/', '', (string) ($send['phone'] ?? '')) ?? '';
                if ($digits === '' || $other === '') continue;
                $short = strlen($digits) <= strlen($other) ? $digits : $other;
                $long = $short === $digits ? $other : $digits;
                if ($digits !== $other && !(strlen($short) >= 10 && str_ends_with($long, $short))) continue;
                $ids[(string) $send['device_id']] = true;
            }
            if (count($ids) === 1) $setChat->execute([array_key_first($ids), (int) $chat['id']]);
        }
    }

    private static function addColumn(PDO $db, string $table, string $name, string $definition): void
    {
        if (!preg_match('/^[a-z_]+$/', $table) || !preg_match('/^[a-z_]+$/', $name)) return;
        if (!self::tableExists($db, $table)) return;
        $exists = false;
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $exists = (bool) $db->query('SHOW COLUMNS FROM ' . $table . ' LIKE ' . $db->quote($name))->fetch();
        } else {
            foreach ($db->query('PRAGMA table_info(' . $table . ')')->fetchAll() as $column) {
                if (($column['name'] ?? '') === $name) $exists = true;
            }
        }
        if (!$exists) $db->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $name . ' ' . $definition);
    }

    private static function tableExists(PDO $db, string $table): bool
    {
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            return (bool) $db->query('SHOW TABLES LIKE ' . $db->quote($table))->fetch();
        }
        return (bool) $db->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = " . $db->quote($table))->fetch();
    }
}
