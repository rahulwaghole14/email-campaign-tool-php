<?php
declare(strict_types=1);

use App\Services\WhatsApp\DeviceLink;
use App\Services\WhatsApp\WhatsAppChatService;
use App\Services\WhatsApp\WhatsAppSentService;
use App\Services\WhatsApp\WhatsAppTables;

$sent = new WhatsAppSentService($db);
try {
if ($path === '/api/whatsapp/sent' && $method === 'GET') {
        respond($sent->listing($_GET));
    }
    // One-shot desk cleanup: today's sticky-test Sent rows + those chats only.
    if ($path === '/api/whatsapp/sent/cleanup-sticky-test' && $method === 'POST') {
        $phones = [
            '+918087889188', '+919881976526', '+919096041415', '+919941041415', '+919322971696',
            '+919503109635', '+919130934689', '+918767433828', '+919552237869', '+918830853419',
            '+919420603964', '+917353287875', '+919309836856', '+917028332218', '+919834391592',
            '+918805854727', '+917057975781', '+919579228277',
        ];
        $digits = [];
        foreach ($phones as $phone) {
            $d = preg_replace('/\D+/', '', $phone) ?? '';
            if ($d !== '') $digits[$d] = $phone;
        }
        $day = gmdate('Y-m-d');
        $input = json_decode(file_get_contents('php://input') ?: '', true);
        if (is_array($input) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($input['day'] ?? ''))) {
            $day = (string) $input['day'];
        }

        $rows = $db->query('SELECT id, phone, message, source_id, created_at, status FROM whatsapp_sends')->fetchAll(PDO::FETCH_ASSOC);
        $sendIds = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $created = (string) ($row['created_at'] ?? '');
            if (!str_starts_with($created, $day)) continue;
            $phoneDigits = preg_replace('/\D+/', '', (string) ($row['phone'] ?? '')) ?? '';
            $msg = (string) ($row['message'] ?? '');
            $fromStickySheet = (int) ($row['source_id'] ?? 0) === 12;
            $stickyPhone = isset($digits[$phoneDigits]);
            $stickyText = (bool) preg_match('/Mailpilot WhatsApp|internal check of RSL|RSL Tech/i', $msg);
            // Sticky-test only: sheet 12, test phones, or internal test copy. Thailand campaign (+66) stays.
            if (!$fromStickySheet && !$stickyPhone && !$stickyText) continue;
            $sendIds[] = (int) $row['id'];
        }

        $deletedSends = 0;
        if ($sendIds !== []) {
            $chunk = array_chunk($sendIds, 200);
            foreach ($chunk as $ids) {
                $marks = implode(',', array_fill(0, count($ids), '?'));
                $stmt = $db->prepare("DELETE FROM whatsapp_sends WHERE id IN ($marks)");
                $stmt->execute($ids);
                $deletedSends += $stmt->rowCount();
            }
        }

        $chats = new WhatsAppChatService($db);
        $deletedChats = 0;
        $deletedMessages = 0;
        foreach ($phones as $phone) {
            $result = $chats->deleteChat($phone);
            $deletedChats += (int) ($result['chats'] ?? 0);
            $deletedMessages += (int) ($result['messages'] ?? 0);
        }

        respond([
            'ok' => true,
            'day' => $day,
            'deleted_sends' => $deletedSends,
            'deleted_chats' => $deletedChats,
            'deleted_chat_messages' => $deletedMessages,
        ]);
    }
    // Delete Sent + Chats activity for one calendar day only (earlier days untouched).
    if ($path === '/api/whatsapp/sent/cleanup-day' && $method === 'POST') {
        $input = json_decode(file_get_contents('php://input') ?: '', true);
        $day = is_array($input) ? trim((string) ($input['day'] ?? '')) : '';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
            throw new InvalidArgumentException('Pass day as YYYY-MM-DD.');
        }
        $onDay = static function (string $raw) use ($day): bool {
            // Accept 2026-11-19T… / 2026-11-19 … / …Z
            return str_starts_with(str_replace(' ', 'T', $raw), $day);
        };

        $rows = $db->query('SELECT id, phone, account_name, created_at FROM whatsapp_sends')->fetchAll(PDO::FETCH_ASSOC);
        $sendIds = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            if (!$onDay((string) ($row['created_at'] ?? ''))) continue;
            $sendIds[] = (int) $row['id'];
        }
        $deletedSends = 0;
        if ($sendIds !== []) {
            foreach (array_chunk($sendIds, 200) as $ids) {
                $marks = implode(',', array_fill(0, count($ids), '?'));
                $stmt = $db->prepare("DELETE FROM whatsapp_sends WHERE id IN ($marks)");
                $stmt->execute($ids);
                $deletedSends += $stmt->rowCount();
            }
        }

        $chats = new WhatsAppChatService($db);
        $msgRows = $db->query(
            'SELECT m.id, m.chat_id, m.direction, m.body, m.created_at, c.phone
             FROM whatsapp_chat_messages m
             INNER JOIN whatsapp_chats c ON c.id = m.chat_id'
        )->fetchAll(PDO::FETCH_ASSOC);
        $messageIds = [];
        $phonesToMaybeDrop = [];
        $chatIdsTouched = [];
        $hide = $db->prepare(
            'INSERT INTO whatsapp_deleted_messages (phone_key, direction, body, created_at) VALUES (?, ?, ?, ?)'
        );
        foreach ($msgRows as $row) {
            if (!is_array($row)) continue;
            $at = (string) ($row['created_at'] ?? '');
            if (!$onDay($at)) continue;
            $messageIds[] = (int) $row['id'];
            $chatIdsTouched[(int) $row['chat_id']] = true;
            $phone = (string) ($row['phone'] ?? '');
            $phonesToMaybeDrop[$phone] = true;
            $digits = preg_replace('/\D+/', '', $phone) ?? '';
            $key = strlen($digits) > 10 ? substr($digits, -10) : $digits;
            if ($key === '') continue;
            $direction = ((string) ($row['direction'] ?? '')) === 'in' ? 'in' : 'out';
            $hide->execute([$key, $direction, trim((string) ($row['body'] ?? '')), $at]);
        }
        // Also chats whose preview is still stuck on that day (messages may already be gone).
        foreach ($db->query('SELECT id, phone, last_at FROM whatsapp_chats')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row)) continue;
            if (!$onDay((string) ($row['last_at'] ?? ''))) continue;
            $chatIdsTouched[(int) $row['id']] = true;
            $phonesToMaybeDrop[(string) ($row['phone'] ?? '')] = true;
        }

        $deletedMessages = 0;
        if ($messageIds !== []) {
            foreach (array_chunk($messageIds, 200) as $ids) {
                $marks = implode(',', array_fill(0, count($ids), '?'));
                $stmt = $db->prepare("DELETE FROM whatsapp_chat_messages WHERE id IN ($marks)");
                $stmt->execute($ids);
                $deletedMessages += $stmt->rowCount();
            }
        }

        $deletedChats = 0;
        foreach (array_keys($phonesToMaybeDrop) as $phone) {
            $phone = trim((string) $phone);
            if ($phone === '') continue;
            // Drop the chat from the desk when nothing older remains; hide yesterday's lines from live re-import.
            $left = 0;
            foreach ($db->query('SELECT id, phone FROM whatsapp_chats')->fetchAll(PDO::FETCH_ASSOC) as $chat) {
                if (!is_array($chat)) continue;
                $a = preg_replace('/\D+/', '', $phone) ?? '';
                $b = preg_replace('/\D+/', '', (string) ($chat['phone'] ?? '')) ?? '';
                if ($a === '' || $b === '') continue;
                $short = strlen($a) <= strlen($b) ? $a : $b;
                $long = $short === $a ? $b : $a;
                if ($a !== $b && !(strlen($short) >= 10 && str_ends_with($long, $short))) continue;
                $count = $db->prepare('SELECT COUNT(*) FROM whatsapp_chat_messages WHERE chat_id = ?');
                $count->execute([(int) $chat['id']]);
                $left += (int) $count->fetchColumn();
            }
            if ($left === 0) {
                $result = $chats->deleteChat($phone);
                $deletedChats += (int) ($result['chats'] ?? 0);
            } else {
                // Keep older history; refresh preview to the latest remaining message.
                foreach ($db->query('SELECT id, phone FROM whatsapp_chats')->fetchAll(PDO::FETCH_ASSOC) as $chat) {
                    if (!is_array($chat)) continue;
                    $a = preg_replace('/\D+/', '', $phone) ?? '';
                    $b = preg_replace('/\D+/', '', (string) ($chat['phone'] ?? '')) ?? '';
                    if ($a === '' || $b === '') continue;
                    $short = strlen($a) <= strlen($b) ? $a : $b;
                    $long = $short === $a ? $b : $a;
                    if ($a !== $b && !(strlen($short) >= 10 && str_ends_with($long, $short))) continue;
                    $last = $db->prepare('SELECT body, created_at FROM whatsapp_chat_messages WHERE chat_id = ? ORDER BY created_at DESC, id DESC LIMIT 1');
                    $last->execute([(int) $chat['id']]);
                    $row = $last->fetch(PDO::FETCH_ASSOC);
                    if (is_array($row)) {
                        $db->prepare('UPDATE whatsapp_chats SET last_message = ?, last_at = ? WHERE id = ?')
                            ->execute([(string) $row['body'], (string) $row['created_at'], (int) $chat['id']]);
                    }
                }
            }
        }

        respond([
            'ok' => true,
            'day' => $day,
            'matched_sends' => count($sendIds),
            'deleted_sends' => $deletedSends,
            'matched_chat_messages' => count($messageIds),
            'deleted_chat_messages' => $deletedMessages,
            'deleted_chats' => $deletedChats,
        ]);
    }
    // Drop Linked-device local rows that have no remaining Sent history and are not live/blocked.
    if ($path === '/api/whatsapp/sent/cleanup-orphan-devices' && $method === 'POST') {
        WhatsAppTables::ensure($db);
        $keep = [];
        foreach ($db->query(
            "SELECT DISTINCT device_id FROM whatsapp_sends WHERE status = 'sent' AND device_id IS NOT NULL AND device_id <> ''"
        )->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = trim((string) ($row['device_id'] ?? ''));
            if ($id !== '') $keep[$id] = true;
        }
        try {
            $link = new DeviceLink($db);
            foreach ($link->roster()['devices'] as $device) {
                if (!is_array($device)) continue;
                $id = trim((string) ($device['id'] ?? ''));
                if ($id !== '') $keep[$id] = true;
            }
        } catch (Throwable) {
            // Phone service down: still prune against Sent history only.
        }
        $removed = [];
        foreach ($db->query('SELECT device_id, name, status FROM whatsapp_devices')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row)) continue;
            $id = trim((string) ($row['device_id'] ?? ''));
            if ($id === '' || isset($keep[$id])) continue;
            if ((string) ($row['status'] ?? '') === 'blocked') continue;
            $db->prepare('DELETE FROM whatsapp_devices WHERE device_id = ?')->execute([$id]);
            $removed[] = ['id' => $id, 'name' => (string) ($row['name'] ?? '')];
        }
        respond(['ok' => true, 'removed' => count($removed), 'devices' => $removed]);
    }
} catch (InvalidArgumentException $e) {
    respond(['error' => $e->getMessage()], 422);
}
respond(['error' => 'Endpoint not found.'], 404);
