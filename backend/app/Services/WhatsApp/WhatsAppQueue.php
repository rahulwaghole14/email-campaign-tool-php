<?php
declare(strict_types=1);

namespace App\Services\WhatsApp;

use App\Services\Sheets\WhatsAppSheetService;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PDO;
use RuntimeException;

/** Shares a WhatsApp sheet's ready rows across its phones and sends each row when it is due. */
final class WhatsAppQueue
{
    /** Used when a sheet has no daily limit saved. */
    public const DAILY = 20;
    private const DEFAULT_TIME = '10:00';
    /** Minutes to wait before trying a row again after the phone service did not answer. Five tries in all. */
    private const RETRY_MINUTES = [2, 5, 10, 20];
    /** After a failed try, that phone's other waiting rows wait this long, so a short outage does not use up every row's tries. */
    private const COOLDOWN_SECONDS = 60;
    private const HELD_NOTE = 'Held: this number has no country code the phone service can read, so it was not sent. Check the number in the sheet.';

    private DateTimeZone $zone;

    public function __construct(private PDO $db, private DeviceLink $link, private ?WhatsAppSheetService $sheets = null)
    {
        new WhatsAppSentService($db);
        WhatsAppTables::ensure($db);
        $this->zone = new DateTimeZone('Asia/Kolkata');
    }

    public function plan(int $sourceId, ?array $recordIds = null): array
    {
        return $this->summary($this->build($sourceId, $recordIds));
    }

    public function queue(int $sourceId, ?array $recordIds = null): array
    {
        $built = $this->build($sourceId, $recordIds);
        $insert = $this->db->prepare(
            'INSERT INTO whatsapp_sends (phone, name, message, status, error, account_name, device_id, due_at, source_id, record_id, created_at) VALUES (?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?)'
        );
        $this->db->beginTransaction();
        try {
            foreach ($built['slots'] as $slot) {
                $row = $slot['row'];
                $due = gmdate('c', $slot['at']);
                $insert->execute([
                    (string) $row['values']['phone'],
                    mb_substr(trim((string) ($row['values']['name'] ?? '')), 0, 160),
                    (string) $row['values']['message'],
                    'waiting',
                    $slot['phone']['name'],
                    $slot['phone']['id'],
                    $due,
                    $sourceId,
                    mb_substr((string) $row['record_id'], 0, 160),
                    $due,
                ]);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        // Send means start delivery for this sheet only: clear pause on this source, not every sheet.
        $resumed = $this->isPaused($sourceId);
        if ($resumed) $this->setPaused(false, $sourceId);
        return $this->summary($built) + ['queued' => count($built['slots']), 'resumed' => $resumed];
    }

    /** Whether this sheet's sending is paused. Waiting rows stay queued. */
    public function isPaused(int $sourceId): bool
    {
        if ($sourceId < 1) return false;
        $query = $this->db->prepare('SELECT paused FROM whatsapp_sheet_sources WHERE id = ?');
        $query->execute([$sourceId]);
        return (int) $query->fetchColumn() === 1;
    }

    /** Pause or resume one sheet. Other sheets keep their own pause state. */
    public function setPaused(bool $paused, int $sourceId): array
    {
        if ($sourceId < 1) throw new InvalidArgumentException('Choose a sheet before pausing or resuming.');
        $exists = $this->db->prepare('SELECT id FROM whatsapp_sheet_sources WHERE id = ?');
        $exists->execute([$sourceId]);
        if (!$exists->fetchColumn()) throw new InvalidArgumentException('That sheet was not found.');
        $this->db->prepare('UPDATE whatsapp_sheet_sources SET paused = ? WHERE id = ?')->execute([$paused ? 1 : 0, $sourceId]);
        return ['paused' => $paused, 'source_id' => $sourceId];
    }

    /** Cancel waiting/sending rows whose sheet was deleted so they cannot keep going out. */
    public function cancelOrphaned(): int
    {
        $rows = $this->db->query(
            "SELECT s.id FROM whatsapp_sends s
             LEFT JOIN whatsapp_sheet_sources src ON src.id = s.source_id
             WHERE s.source_id IS NOT NULL AND src.id IS NULL AND s.status IN ('waiting', 'sending')"
        )->fetchAll(PDO::FETCH_COLUMN);
        if (!$rows) return 0;
        $mark = $this->db->prepare(
            "UPDATE whatsapp_sends SET status = 'cancelled', error = ? WHERE id = ? AND status IN ('waiting', 'sending')"
        );
        $count = 0;
        foreach ($rows as $id) {
            $mark->execute(['Sheet removed', (int) $id]);
            $count += $mark->rowCount();
        }
        return $count;
    }

    public function run(): array
    {
        $now = time();
        $result = ['sent' => 0, 'failed' => 0, 'retried' => 0, 'not_ready' => [], 'error' => null, 'paused' => false];
        // Older deletes left waiting rows with no sheet; those kept sending until devices were cut.
        $this->cancelOrphaned();
        try {
            $ready = [];
            foreach ($this->link->roster()['devices'] as $device) {
                if (($device['status'] ?? '') === 'ready') $ready[(string) $device['id']] = true;
            }
        } catch (RuntimeException $e) {
            $result['error'] = $e->getMessage();
            return $result + $this->waitingTotals();
        }
        // INNER JOIN: a deleted sheet must never send. Paused sheets stay out of the due set.
        $waitingSql = "SELECT s.id, s.phone, s.message, s.device_id, s.account_name, s.due_at, s.attempts, s.source_id, src.delay_minutes, src.delay_max_minutes, src.daily_limit, COALESCE(src.paused, 0) AS sheet_paused FROM whatsapp_sends s INNER JOIN whatsapp_sheet_sources src ON src.id = s.source_id WHERE s.status = 'waiting' AND s.due_at IS NOT NULL AND s.device_id IS NOT NULL ORDER BY s.due_at ASC, s.id ASC";
        $rows = $this->db->query($waitingSql)->fetchAll();
        if (!$rows) return $result + $this->waitingTotals();
        // Rows stuck on a disconnected phone move onto ready phones before this run sends.
        if ($ready) {
            foreach ($rows as $row) {
                if (!is_array($row)) continue;
                if ((int) ($row['sheet_paused'] ?? 0) === 1) continue;
                if (!isset($ready[(string) ($row['device_id'] ?? '')])) {
                    $this->compact();
                    $rows = $this->db->query($waitingSql)->fetchAll();
                    break;
                }
            }
        }
        $due = array_filter($rows, static function ($row) use ($now) {
            if (!is_array($row)) return false;
            // Paused sheets stay waiting; other sheets keep sending on the same phones.
            if ((int) ($row['sheet_paused'] ?? 0) === 1) return false;
            return (strtotime((string) $row['due_at']) ?: PHP_INT_MAX) <= $now;
        });
        if (!$due) return $result + $this->waitingTotals();
        $chats = new WhatsAppChatService($this->db);
        $seen = [];
        $queue = array_values($due);
        while ($queue) {
            $row = array_shift($queue);
            $deviceId = (string) $row['device_id'];
            if (isset($seen[$deviceId])) continue;
            $seen[$deviceId] = true;
            if (!isset($ready[$deviceId])) {
                $result['not_ready'][] = (string) $row['account_name'];
                continue;
            }
            // Shortest gap is the floor: never send earlier than the sheet's minimum wait.
            $gap = $this->gapRange($row['delay_minutes'] ?? 1, $row['delay_max_minutes'] ?? null)[0] * 60;
            [$last, $today] = $this->sentStats($deviceId, $now);
            if ($last !== 0 && $last + $gap > $now) continue;
            if ($today >= $this->dailyLimit($row['daily_limit'] ?? null)) continue;
            $claim = $this->db->prepare("UPDATE whatsapp_sends SET status = 'sending' WHERE id = ? AND status = 'waiting'");
            $claim->execute([(int) $row['id']]);
            if ($claim->rowCount() !== 1) continue;
            $digits = preg_replace('/\D+/', '', (string) $row['phone']) ?? '';
            try {
                $from = $this->link->sendText($digits, (string) $row['message'], $deviceId);
                $at = gmdate('c');
                $this->db->prepare("UPDATE whatsapp_sends SET status = 'sent', error = NULL, created_at = ?, account_name = ? WHERE id = ?")
                    ->execute([$at, $from['name'] !== '' ? $from['name'] : (string) $row['account_name'], (int) $row['id']]);
                $chats->noteOutgoing((string) $row['phone'], (string) $row['message'], $at, $deviceId);
                $result['sent']++;
            } catch (PhoneServiceUnavailable $e) {
                $attempt = (int) ($row['attempts'] ?? 0) + 1;
                if ($attempt <= count(self::RETRY_MINUTES)) {
                    // The service did not answer: keep the row waiting and try again later.
                    $retryAt = $now + self::RETRY_MINUTES[$attempt - 1] * 60;
                    $this->db->prepare("UPDATE whatsapp_sends SET status = 'waiting', attempts = ?, error = ?, due_at = ? WHERE id = ?")
                        ->execute([$attempt, mb_substr($e->getMessage(), 0, 500), gmdate('c', $retryAt), (int) $row['id']]);
                    $this->coolDown($deviceId, $now + self::COOLDOWN_SECONDS, (int) $row['id']);
                    $result['retried']++;
                } else {
                    $this->db->prepare("UPDATE whatsapp_sends SET status = 'failed', attempts = ?, error = ?, created_at = ? WHERE id = ?")
                        ->execute([$attempt, mb_substr($e->getMessage(), 0, 500), gmdate('c'), (int) $row['id']]);
                    $result['failed']++;
                }
            } catch (RuntimeException $e) {
                $this->db->prepare("UPDATE whatsapp_sends SET status = 'failed', error = ?, created_at = ? WHERE id = ?")
                    ->execute([mb_substr($e->getMessage(), 0, 500), gmdate('c'), (int) $row['id']]);
                $result['failed']++;
                if (self::noWhatsapp($e->getMessage())) {
                    // The number cannot get the message, so the phone's slot is free. Its next row is pulled up to now and
                    // is tried in this same run. The gap since the last sent message still applies, so nothing is sent early.
                    $next = $this->pullNext($deviceId, $now);
                    if ($next !== null) {
                        unset($seen[$deviceId]);
                        $queue[] = $next;
                    }
                }
            }
        }
        $result['not_ready'] = array_values(array_unique($result['not_ready']));
        return $result + $this->waitingTotals();
    }

    /** The phone's next waiting row, moved up to now if it is later. Returns null when the phone has no such row. Retrying rows keep their time. */
    private function pullNext(string $deviceId, int $now): ?array
    {
        $find = $this->db->prepare(
            "SELECT s.id, s.phone, s.message, s.device_id, s.account_name, s.due_at, s.attempts, src.delay_minutes, src.daily_limit FROM whatsapp_sends s INNER JOIN whatsapp_sheet_sources src ON src.id = s.source_id WHERE s.status = 'waiting' AND s.device_id = ? AND s.due_at IS NOT NULL AND (s.attempts IS NULL OR s.attempts = 0) AND COALESCE(src.paused, 0) = 0 ORDER BY s.due_at ASC, s.id ASC LIMIT 1"
        );
        $find->execute([$deviceId]);
        $row = $find->fetch();
        if (!is_array($row)) return null;
        if ((strtotime((string) $row['due_at']) ?: PHP_INT_MAX) > $now) {
            $this->db->prepare("UPDATE whatsapp_sends SET due_at = ? WHERE id = ? AND status = 'waiting'")
                ->execute([gmdate('c', $now), (int) $row['id']]);
            $row['due_at'] = gmdate('c', $now);
        }
        return $row;
    }

    /** Hold this phone's other waiting rows until $until, keeping their order. */
    private function coolDown(string $deviceId, int $until, int $exceptId): void
    {
        $mark = gmdate('c', $until);
        $this->db->prepare("UPDATE whatsapp_sends SET due_at = ? WHERE device_id = ? AND status = 'waiting' AND id <> ? AND due_at < ?")
            ->execute([$mark, $deviceId, $exceptId, $mark]);
    }

    public function progress(int $sourceId): array
    {
        $rows = $this->latestRows($sourceId);
        $totals = ['total' => 0, 'waiting' => 0, 'sent' => 0, 'failed' => 0];
        $phones = [];
        $log = [];
        foreach ($rows as $row) {
            $state = $this->state((string) $row['status']);
            $totals['total']++;
            $totals[$state]++;
            $key = (string) ($row['device_id'] ?? '');
            $phones[$key] ??= ['id' => $key, 'name' => (string) $row['account_name'], 'waiting' => 0, 'sent' => 0, 'failed' => 0, 'next_at' => null];
            $phones[$key][$state]++;
            if ($state === 'waiting') {
                $at = (string) ($row['due_at'] ?? '');
                if ($at !== '' && ($phones[$key]['next_at'] === null || strtotime($at) < strtotime($phones[$key]['next_at']))) {
                    $phones[$key]['next_at'] = $at;
                }
            } else {
                $log[] = [
                    'name' => (string) $row['name'],
                    'phone' => (string) $row['phone'],
                    'from' => (string) $row['account_name'],
                    'status' => $state,
                    'error' => $state === 'failed' ? (string) ($row['error'] ?? '') : '',
                    'at' => (string) $row['created_at'],
                ];
            }
        }
        usort($log, static fn ($a, $b) => (strtotime($b['at']) ?: 0) <=> (strtotime($a['at']) ?: 0));
        return $totals + ['phones' => array_values($phones), 'log' => array_slice($log, 0, 12)];
    }

    /**
     * Move waiting rows forward into the slots left by held or removed rows.
     * Each phone keeps its own rows in order, its gap and its daily limit. Retrying rows keep their time.
     */
    public function compact(): array
    {
        $now = (int) (ceil(time() / 60) * 60);
        $sentDays = [];
        $sentLast = [];
        foreach ($this->db->query("SELECT device_id, created_at FROM whatsapp_sends WHERE status = 'sent' AND device_id IS NOT NULL")->fetchAll() as $row) {
            $id = (string) $row['device_id'];
            $at = strtotime((string) $row['created_at']) ?: 0;
            if (!$at) continue;
            $day = $this->day($at);
            $sentDays[$id][$day] = ($sentDays[$id][$day] ?? 0) + 1;
            $sentLast[$id] = max($sentLast[$id] ?? 0, $at);
        }

        $rows = $this->db->query(
            "SELECT s.id, s.phone, s.device_id, s.due_at, s.attempts, s.source_id, src.start_date, src.start_time, src.delay_minutes, src.delay_max_minutes, src.daily_limit FROM whatsapp_sends s INNER JOIN whatsapp_sheet_sources src ON src.id = s.source_id WHERE s.status = 'waiting' AND s.device_id IS NOT NULL AND s.source_id IS NOT NULL ORDER BY s.due_at ASC, s.id ASC"
        )->fetchAll();
        $bySource = [];
        foreach ($rows as $row) $bySource[(int) $row['source_id']][] = $row;

        // Read the connected phones before the transaction. Reading the roster runs table DDL, and in MySQL DDL ends a transaction early.
        $readyBySource = [];
        foreach ($bySource as $sourceId => $sourceRows) {
            $readyBySource[$sourceId] = array_map(static fn (array $phone) => $phone['id'], $this->phones($this->sourceSetting((int) $sourceRows[0]['source_id'])));
        }

        $update = $this->db->prepare("UPDATE whatsapp_sends SET device_id = ?, due_at = ? WHERE id = ? AND status = 'waiting'");
        $moved = 0;
        $finish = 0;
        $this->db->beginTransaction();
        try {
            foreach ($bySource as $sourceId => $sourceRows) {
                $first = $sourceRows[0];
                [$gapMin] = $this->gapRange($first['delay_minutes'] ?? 1, $first['delay_max_minutes'] ?? null);
                $daily = $this->dailyLimit($first['daily_limit'] ?? null);
                $time = $this->startTime(['start_time' => (string) ($first['start_time'] ?? '')]);
                $from = trim((string) ($first['start_date'] ?? ''));
                $floor = $from !== '' ? (new DateTimeImmutable($from . ' ' . $time, $this->zone))->getTimestamp() : 0;

                // Retrying rows keep their phone and time; they only use up a slot on their day.
                $days = [];
                $movable = [];
                foreach ($sourceRows as $row) {
                    if ((int) ($row['attempts'] ?? 0) > 0) {
                        $id = (string) $row['device_id'];
                        $day = $this->day(strtotime((string) $row['due_at']) ?: $now);
                        $days[$id][$day] = ($days[$id][$day] ?? 0) + 1;
                        continue;
                    }
                    $movable[] = $row;
                }
                if (!$movable) continue;

                // Ready phones take first-time rows left on a disconnected phone.
                // Follow-ups stay on their owner phone; if that owner is not ready they are left alone (no fallback).
                $phones = $readyBySource[$sourceId];
                $owners = $this->ownersByDigits();
                $cursor = [];
                foreach ($phones as $id) {
                    foreach ($sentDays[$id] ?? [] as $day => $count) $days[$id][$day] = ($days[$id][$day] ?? 0) + $count;
                    $last = $sentLast[$id] ?? 0;
                    $at = max($now, $floor, $last !== 0 ? $last + $gapMin * 60 : 0);
                    $cursor[$id] = $this->fit($at, $days[$id] ?? [], $time, $daily);
                }

                foreach ($movable as $row) {
                    $ownerId = $owners[self::phoneDigits((string) ($row['phone'] ?? ''))] ?? null;
                    $pick = $this->pickPhoneId($phones, $cursor, $ownerId);
                    if ($pick === null) continue;
                    $at = $cursor[$pick];
                    $day = $this->day($at);
                    $days[$pick][$day] = ($days[$pick][$day] ?? 0) + 1;
                    $update->execute([$pick, gmdate('c', $at), (int) $row['id']]);
                    $moved++;
                    $finish = max($finish, $at);
                    $next = $at + $this->gapSeconds($first['delay_minutes'] ?? 1, $first['delay_max_minutes'] ?? null);
                    if ($this->day($next) !== $day) $next = $this->dayStart($day, 1, $time);
                    $cursor[$pick] = $this->fit($next, $days[$pick], $time, $daily);
                }
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return ['moved' => $moved, 'last_at' => $finish ? gmdate('c', $finish) : null];
    }

    /** A number like +66 2 538 2203 has 10 digits with no country code the service can read; it is sent as an Indian number and can reach the wrong person. */
    private static function unsafeNumber(string $phone): bool
    {
        return str_starts_with(trim($phone), '+') && strlen(preg_replace('/\D+/', '', $phone) ?? '') === 10;
    }

    /** Hold every waiting row with an unsafe number so it is never sent. Returns how many were held. */
    public function holdUnsafe(): int
    {
        $rows = $this->db->query("SELECT id, phone FROM whatsapp_sends WHERE status = 'waiting' AND source_id IS NOT NULL")->fetchAll();
        $update = $this->db->prepare("UPDATE whatsapp_sends SET status = 'held', error = ? WHERE id = ? AND status = 'waiting'");
        $count = 0;
        foreach ($rows as $row) {
            if (!self::unsafeNumber((string) $row['phone'])) continue;
            $update->execute([self::HELD_NOTE, (int) $row['id']]);
            $count += $update->rowCount();
        }
        return $count;
    }
    /**
     * Today's picture: what went out, what is still due today, and when it should finish.
     * Pass a sheet id to limit the panel to that sheet only; null = every sheet.
     */
    public function today(?int $sourceId = null): array
    {
        $now = time();
        $day = $this->day($now);
        $dayEnd = (new DateTimeImmutable($day . ' 00:00', $this->zone))->modify('+1 day')->getTimestamp();
        // The sending phone's name comes from its device now. The saved account_name can be stale after a row is moved to another phone.
        $columns = 's.id, s.phone, s.name, s.status, s.error, COALESCE(NULLIF(dv.name, \'\'), s.account_name) AS account_name, s.device_id, s.due_at, s.created_at, s.attempts, src.title, src.delay_minutes, src.delay_max_minutes, src.daily_limit';
        $join = 'FROM whatsapp_sends s LEFT JOIN whatsapp_sheet_sources src ON src.id = s.source_id LEFT JOIN whatsapp_devices dv ON dv.device_id = s.device_id';
        $margin = gmdate('c', $now - 2 * 86400);
        $sheetClause = $sourceId !== null ? ' AND s.source_id = ?' : '';
        $query = $this->db->prepare("SELECT $columns $join WHERE ((s.status IN ('waiting', 'sending')) OR (s.status = 'sent' AND s.created_at >= ?))$sheetClause ORDER BY s.id ASC");
        $query->execute($sourceId !== null ? [$margin, $sourceId] : [$margin]);
        $rows = $query->fetchAll();
        $failedSql = "SELECT $columns $join WHERE s.status IN ('failed', 'blocked') AND s.source_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM whatsapp_sends n WHERE n.source_id = s.source_id AND n.record_id = s.record_id AND n.id > s.id)$sheetClause ORDER BY s.id DESC";
        if ($sourceId !== null) {
            $failedQuery = $this->db->prepare($failedSql);
            $failedQuery->execute([$sourceId]);
            $failedRows = $failedQuery->fetchAll();
        } else {
            $failedRows = $this->db->query($failedSql)->fetchAll();
        }

        $phones = [];
        $touch = function (array $row) use (&$phones): string {
            $id = (string) ($row['device_id'] ?? '');
            [$gapMin, $gapMax] = $this->gapRange($row['delay_minutes'] ?? 1, $row['delay_max_minutes'] ?? null);
            $phones[$id] ??= ['id' => $id, 'name' => (string) $row['account_name'], 'sent' => 0, 'left' => 0, 'later' => 0, 'failed' => 0, 'cap' => $this->dailyLimit($row['daily_limit'] ?? null), 'gap' => $gapMin, 'gap_min' => $gapMin, 'gap_max' => $gapMax, 'next_at' => null, 'last_at' => null];
            if ((string) $row['account_name'] !== '') $phones[$id]['name'] = (string) $row['account_name'];
            return $id;
        };
        $sent = [];
        $upcoming = [];
        $dayCounts = [];
        $later = 0;
        $retrying = 0;
        $overdue = 0;
        $allDone = 0;
        foreach ($rows as $row) {
            $id = $touch($row);
            if ($row['status'] === 'sent') {
                $at = strtotime((string) $row['created_at']) ?: 0;
                if ($this->day($at) !== $day) continue;
                $phones[$id]['sent']++;
                $phones[$id]['last_at'] = max($phones[$id]['last_at'] ?? 0, $at);
                // Today's sent messages also count in the day-by-day list, next to what is still waiting.
                $dayCounts[$day] ??= ['date' => $day, 'count' => 0, 'first' => null, 'last' => null];
                $dayCounts[$day]['count']++;
                $dayCounts[$day]['first'] = min($dayCounts[$day]['first'] ?? PHP_INT_MAX, $at);
                $dayCounts[$day]['last'] = max($dayCounts[$day]['last'] ?? 0, $at);
                $sent[] = $this->todayItem($row, (string) $row['created_at']);
                continue;
            }
            $due = strtotime((string) ($row['due_at'] ?: $row['created_at'])) ?: $now;
            if ($due > $allDone) $allDone = $due;
            $bucket = $this->day(max($due, $now));
            $dayCounts[$bucket] ??= ['date' => $bucket, 'count' => 0, 'first' => null, 'last' => null];
            $dayCounts[$bucket]['count']++;
            $dayCounts[$bucket]['first'] = min($dayCounts[$bucket]['first'] ?? PHP_INT_MAX, max($due, $now));
            $dayCounts[$bucket]['last'] = max($dayCounts[$bucket]['last'] ?? 0, max($due, $now));
            if ($due >= $dayEnd) {
                $later++;
                $phones[$id]['later']++;
                continue;
            }
            $phones[$id]['left']++;
            if ((int) ($row['attempts'] ?? 0) > 0) $retrying++;
            if ($due < $now - 300) $overdue++;
            $shown = max($due, $now);
            if ($phones[$id]['next_at'] === null || $shown < $phones[$id]['next_at']) $phones[$id]['next_at'] = $shown;
            if ($phones[$id]['last_at'] === null || $shown > $phones[$id]['last_at']) $phones[$id]['last_at'] = $shown;
            $upcoming[] = $this->todayItem($row, gmdate('c', $shown)) + ['sort' => $shown];
        }
        $failed = [];
        $noWhatsapp = [];
        $failedToday = 0;
        foreach ($failedRows as $row) {
            // The number is not on WhatsApp: sending again cannot help, so it is listed apart from real failures.
            if (self::noWhatsapp((string) ($row['error'] ?? ''))) {
                $noWhatsapp[] = $this->todayItem($row, (string) $row['created_at']);
                continue;
            }
            $id = $touch($row);
            $phones[$id]['failed']++;
            $at = strtotime((string) $row['created_at']) ?: 0;
            if ($this->day($at) === $day) $failedToday++;
            $failed[] = $this->todayItem($row, (string) $row['created_at']);
        }
        usort($upcoming, static fn ($a, $b) => $a['sort'] <=> $b['sort']);
        usort($sent, static fn ($a, $b) => (strtotime($b['at']) ?: 0) <=> (strtotime($a['at']) ?: 0));
        $left = count($upcoming);
        $next = $left ? $upcoming[0]['sort'] : null;
        $last = $left ? end($upcoming)['sort'] : null;
        foreach ($upcoming as &$item) unset($item['sort']);
        unset($item);
        // Name each phone from the device list. Hide a phone that is not connected and has nothing left today.
        $devices = [];
        foreach ($this->db->query('SELECT device_id, name, status FROM whatsapp_devices')->fetchAll(PDO::FETCH_ASSOC) as $device) {
            $devices[(string) $device['device_id']] = $device;
        }
        foreach ($phones as $key => $phone) {
            $device = $devices[$key] ?? null;
            if ($device !== null && trim((string) $device['name']) !== '') $phones[$key]['name'] = (string) $device['name'];
            $connected = $device !== null && (string) $device['status'] === 'ready';
            if (!$connected && $phone['left'] === 0 && $phone['later'] === 0) unset($phones[$key]);
        }
        foreach ($phones as &$phone) {
            foreach (['next_at', 'last_at'] as $key) $phone[$key] = $phone[$key] !== null ? gmdate('c', $phone[$key]) : null;
        }
        unset($phone);
        ksort($phones);
        ksort($dayCounts);
        $days = [];
        foreach (array_slice(array_values($dayCounts), 0, 120) as $item) {
            $days[] = ['date' => $item['date'], 'count' => $item['count'], 'first_at' => gmdate('c', $item['first']), 'last_at' => gmdate('c', $item['last'])];
        }
        $held = 0;
        if ($sourceId !== null) {
            $heldQuery = $this->db->prepare("SELECT COUNT(*) FROM whatsapp_sends WHERE status = 'held' AND source_id = ?");
            $heldQuery->execute([$sourceId]);
            $held = (int) $heldQuery->fetchColumn();
        } else {
            $held = (int) $this->db->query("SELECT COUNT(*) FROM whatsapp_sends WHERE status = 'held'")->fetchColumn();
        }
        $sheetTitle = '';
        if ($sourceId !== null) {
            $titleQuery = $this->db->prepare('SELECT title FROM whatsapp_sheet_sources WHERE id = ?');
            $titleQuery->execute([$sourceId]);
            $sheetTitle = trim((string) ($titleQuery->fetchColumn() ?: ''));
        }
        return [
            'now' => gmdate('c', $now),
            'date' => $day,
            'source_id' => $sourceId,
            'sheet' => $sheetTitle,
            'paused' => $sourceId !== null ? $this->isPaused($sourceId) : false,
            'days' => $days,
            'sent' => count($sent),
            'left' => $left,
            'later' => $later,
            'failed' => count($failed),
            'failed_today' => $failedToday,
            'held' => $held,
            'no_whatsapp' => count($noWhatsapp),
            'no_whatsapp_list' => array_slice($noWhatsapp, 0, 60),
            'retrying' => $retrying,
            'overdue' => $overdue,
            'next_at' => $next !== null ? gmdate('c', $next) : null,
            'finish_today_at' => $last !== null ? gmdate('c', $last) : null,
            'all_done_at' => $allDone > 0 ? gmdate('c', $allDone) : null,
            'phones' => array_values($phones),
            'upcoming' => array_slice($upcoming, 0, 60),
            'sent_list' => array_slice($sent, 0, 60),
            'failed_list' => array_slice($failed, 0, 60),
        ];
    }

    /**
     * Put failed sheet sends back in the queue. Pass a source id to retry only that sheet.
     * Returns how many were queued.
     */
    public function retryFailed(?int $sourceId = null): int
    {
        $sql = "SELECT s.id, s.error FROM whatsapp_sends s WHERE s.status = 'failed' AND s.source_id IS NOT NULL AND s.device_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM whatsapp_sends n WHERE n.source_id = s.source_id AND n.record_id = s.record_id AND n.id > s.id)";
        if ($sourceId !== null && $sourceId > 0) {
            $sql .= ' AND s.source_id = ?';
            $query = $this->db->prepare($sql . ' ORDER BY s.id ASC');
            $query->execute([$sourceId]);
            $found = $query->fetchAll();
        } else {
            $found = $this->db->query($sql . ' ORDER BY s.id ASC')->fetchAll();
        }
        if (!$found) return 0;
        $now = gmdate('c');
        $update = $this->db->prepare("UPDATE whatsapp_sends SET status = 'waiting', attempts = 0, error = NULL, due_at = ? WHERE id = ? AND status = 'failed'");
        $count = 0;
        foreach ($found as $row) {
            if (self::noWhatsapp((string) ($row['error'] ?? ''))) continue;
            $update->execute([$now, (int) $row['id']]);
            $count += $update->rowCount();
        }
        return $count;
    }

    /** True when the phone service said the number has no WhatsApp account. */
    private static function noWhatsapp(string $error): bool
    {
        return stripos($error, 'not registered on whatsapp') !== false;
    }

    private function todayItem(array $row, string $at): array
    {
        return [
            'name' => (string) $row['name'],
            'phone' => (string) $row['phone'],
            'from' => (string) $row['account_name'],
            'sheet' => (string) ($row['title'] ?? ''),
            'attempts' => (int) ($row['attempts'] ?? 0),
            'error' => (string) ($row['error'] ?? ''),
            'at' => $at,
        ];
    }

    /** What happened to one sheet row: its latest send, when it is due, and why it failed or waits. */
    public function rowStatus(int $sourceId, string $recordId): array
    {
        $query = $this->db->prepare('SELECT id, name, phone, message, status, error, attempts, account_name, device_id, due_at, created_at FROM whatsapp_sends WHERE source_id = ? AND record_id = ? ORDER BY id DESC LIMIT 1');
        $query->execute([$sourceId, $recordId]);
        $row = $query->fetch();
        if (!$row) {
            return ['label' => 'Not queued', 'detail' => 'This row has not been queued yet. Select it and press Send.', 'phone' => null, 'from' => null, 'queued_at' => null, 'due_at' => null, 'sent_at' => null, 'attempts' => 0, 'error' => null, 'wave' => 0, 'kind' => 'message'];
        }
        $waveQuery = $this->db->prepare('SELECT COUNT(*) FROM whatsapp_sends WHERE source_id = ? AND record_id = ?');
        $waveQuery->execute([$sourceId, $recordId]);
        $wave = max(1, (int) $waveQuery->fetchColumn());
        $kind = $wave > 1 ? 'follow_up' : 'message';
        $now = time();
        $attempts = (int) ($row['attempts'] ?? 0);
        $maxTries = count(self::RETRY_MINUTES) + 1;
        $due = $row['due_at'] ? gmdate('c', strtotime((string) $row['due_at']) ?: $now) : null;
        $from = (string) $row['account_name'];
        $devId = !empty($row['device_id']) ? (string) $row['device_id'] : null;
        $devices = [];
        try {
            $devices = $this->db->query('SELECT device_id, name FROM whatsapp_devices')->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
        } catch (\Throwable) {}
        $devName = $devId !== null ? ($devices[$devId] ?? ($from ?: $devId)) : null;

        $digits = self::phoneDigits((string) ($row['phone'] ?? ''));
        $owners = $this->ownersByDigits();
        $stickyId = ($digits !== '' && isset($owners[$digits])) ? (string) $owners[$digits] : null;
        $stickyName = $stickyId !== null ? ($devices[$stickyId] ?? $stickyId) : null;

        $info = [
            'label' => self::waveLabel('sent', $wave),
            'detail' => '',
            'phone' => (string) $row['phone'],
            'from' => $from !== '' ? $from : ($devName ?: null),
            'device_id' => $devId,
            'device_name' => $devName,
            'sticky_device_id' => $stickyId,
            'sticky_device_name' => $stickyName,
            'sticky_phone' => $stickyName,
            'queued_at' => (string) $row['created_at'],
            'due_at' => null,
            'sent_at' => null,
            'attempts' => $attempts,
            'error' => null,
            'wave' => $wave,
            'kind' => $kind,
        ];
        switch ((string) $row['status']) {
            case 'sent':
                $info['sent_at'] = (string) $row['created_at'];
                $info['detail'] = ($kind === 'follow_up'
                    ? 'Follow-up ' . ($wave - 1) . ' delivered to WhatsApp from '
                    : 'First message delivered to WhatsApp from ')
                    . ($from !== '' ? $from : 'a phone') . '.';
                break;
            case 'sending':
                $info['label'] = self::waveLabel('sending', $wave);
                $info['detail'] = $kind === 'follow_up' ? 'Follow-up is being sent right now.' : 'First message is being sent right now.';
                break;
            case 'held':
                $info['label'] = 'Held';
                $info['detail'] = 'Not sent. This number has no country code the phone service can read, so a message could reach the wrong person. Fix the number in the sheet, then it can be queued again.';
                $info['error'] = self::HELD_NOTE;
                break;
            case 'waiting':
                $info['label'] = self::waveLabel('waiting', $wave);
                $info['due_at'] = $due;
                if ($attempts > 0) {
                    $info['label'] = 'Retrying';
                    $info['detail'] = 'The phone service did not answer. Try ' . ($attempts + 1) . ' of ' . $maxTries . ' is scheduled for the time below.';
                    $info['error'] = (string) ($row['error'] ?? '') ?: null;
                } elseif ($due !== null && strtotime($due) <= $now) {
                    $info['detail'] = 'It is due now. It goes out on the next run from this phone; keep the page open.';
                } else {
                    $info['detail'] = ($kind === 'follow_up' ? 'Follow-up scheduled from ' : 'First message scheduled from ')
                        . ($from !== '' ? $from : 'the chosen phone') . ' at the time below.';
                }
                break;
            case 'failed':
            case 'blocked':
                $info['label'] = 'Failed';
                $info['error'] = (string) ($row['error'] ?? '') ?: null;
                if (self::noWhatsapp((string) ($row['error'] ?? ''))) {
                    $info['label'] = 'Not on WhatsApp';
                    $info['detail'] = 'This number has no WhatsApp account, so sending again cannot help. Check the number in the sheet.';
                } else {
                    $info['detail'] = $attempts > 0
                        ? 'Failed after ' . $attempts . ' tries. Use "Retry failed" in Today to send it again.'
                        : 'Failed on the first try. Use "Retry failed" in Today to send it again.';
                }
                break;
            default:
                $info['label'] = (string) $row['status'];
        }
        return $info;
    }

    /** Human label for the latest wave: first message vs follow-up N. */
    public static function waveLabel(string $status, int $wave): string
    {
        $wave = max(1, $wave);
        $isFollowUp = $wave > 1;
        $name = $isFollowUp ? ('Follow-up ' . ($wave - 1)) : 'Message';
        return match ($status) {
            'sent' => $name . ' sent',
            'waiting', 'pending' => $name . ' waiting',
            'sending' => $name . ' sending',
            default => $name,
        };
    }

    /**
     * Latest send state per sheet row.
     * @return array<string, array{status: string, error: string, message: string, wave: int, kind: string, device_id: ?string, device_name: ?string}>
     */
    public function statuses(int $sourceId): array
    {
        $map = [];
        $devices = [];
        try {
            $devices = $this->db->query('SELECT device_id, name FROM whatsapp_devices')->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
        } catch (\Throwable) {}
        foreach ($this->latestRows($sourceId) as $row) {
            $state = $this->state((string) $row['status']);
            $wave = max(1, (int) ($row['wave'] ?? 1));
            $devId = !empty($row['device_id']) ? (string) $row['device_id'] : null;
            $devName = $devId !== null ? ($devices[$devId] ?? (!empty($row['account_name']) ? (string) $row['account_name'] : $devId)) : null;
            $map[(string) $row['record_id']] = [
                'status' => $state === 'waiting' ? 'pending' : $state,
                'error' => (string) ($row['error'] ?? ''),
                'message' => (string) ($row['message'] ?? ''),
                'wave' => $wave,
                'kind' => $wave > 1 ? 'follow_up' : 'message',
                'device_id' => $devId,
                'device_name' => $devName,
            ];
        }
        return $map;
    }

    /**
     * Whether this sheet row can be queued again.
     * Waiting/sending stays blocked. Sent or failed with the same mapped message stays blocked.
     * A different mapped message starts a new follow-up wave.
     *
     * @param array{status?: string, message?: string}|null $info
     */
    public static function canQueue(?array $info, string $mappedMessage): bool
    {
        if ($info === null) return true;
        $status = (string) ($info['status'] ?? '');
        if ($status === '' || $status === 'not_queued') return true;
        if ($status === 'pending') return false;
        return trim($mappedMessage) !== trim((string) ($info['message'] ?? ''));
    }

    /** @param array<int, string>|null $recordIds null = every ready row; otherwise only these record IDs */
    private function build(int $sourceId, ?array $recordIds = null): array
    {
        if ($this->sheets === null) throw new RuntimeException('Open the sheet before sending.');
        if ($recordIds !== null && !$recordIds) throw new InvalidArgumentException('Select at least one row first.');
        $only = $recordIds === null ? null : array_flip(array_map('strval', $recordIds));
        $preview = $this->sheets->preview($sourceId);
        $source = $preview['source'];
        $taken = $this->statuses($sourceId);
        $owners = $this->ownersByDigits();
        $allowed = $this->allowedDeviceIds($source);
        $rows = [];
        $skipped = 0;
        $unsafe = 0;
        foreach ($preview['records'] as $row) {
            if (!empty($row['issues'])) continue;
            if ($only !== null && !isset($only[(string) $row['record_id']])) continue;
            $info = $taken[(string) $row['record_id']] ?? null;
            $mapped = trim((string) ($row['values']['message'] ?? ''));
            if (!self::canQueue($info, $mapped)) {
                $skipped++;
                continue;
            }
            if (self::unsafeNumber((string) ($row['values']['phone'] ?? ''))) {
                $unsafe++;
                continue;
            }
            $ownerId = $owners[self::phoneDigits((string) ($row['values']['phone'] ?? ''))] ?? null;
            // Sticky follow-up: owner must stay on this sheet's chosen phones; never send from another phone.
            if ($ownerId !== null && $allowed !== null && !in_array($ownerId, $allowed, true)) {
                $skipped++;
                continue;
            }
            $rows[] = $row;
        }
        $gapMin = (int) ($source['delay_minutes'] ?? 0);
        $gapMax = (int) ($source['delay_max_minutes'] ?? 0);
        if ($gapMin < 1 || $gapMin > 30) throw new InvalidArgumentException('Choose the shortest gap between messages first.');
        if ($gapMax === 0) $gapMax = $gapMin;
        if ($gapMax < $gapMin || $gapMax > 30) throw new InvalidArgumentException('Choose the longest gap from the shortest gap up to 30 minutes.');
        $phones = $this->phones($source);
        $needsReady = false;
        foreach ($rows as $row) {
            $digits = self::phoneDigits((string) ($row['values']['phone'] ?? ''));
            if ($digits === '' || !isset($owners[$digits])) {
                $needsReady = true;
                break;
            }
        }
        if ($needsReady && !$phones) throw new InvalidArgumentException('None of the chosen phones is ready. Pair one on Linked device.');
        if (!$rows) throw new InvalidArgumentException('Every ready row is already waiting, or already sent with this message.');
        $time = $this->startTime($source);
        $start = $this->startAt($source, $time);
        $daily = $this->dailyLimit($source['daily_limit'] ?? null);
        $slots = $this->assign($rows, $phones, $start, $gapMin, $gapMax, $time, $daily);
        $used = [];
        foreach ($phones as $phone) $used[$phone['id']] = $phone;
        foreach ($slots as $slot) $used[$slot['phone']['id']] = $slot['phone'];
        return [
            'slots' => $slots,
            'phones' => array_values($used),
            'phones' => $phones,
            'start' => $start,
            'gap' => $gapMin,
            'gap_min' => $gapMin,
            'gap_max' => $gapMax,
            'daily' => $daily,
            'skipped' => $skipped,
            'unsafe' => $unsafe,
        ];
    }

    /** The saved per-sheet limit, or the default when none is saved or it is out of range. */
    private function dailyLimit(mixed $value): int
    {
        $limit = (int) $value;
        return $limit >= 1 && $limit <= WhatsAppSheetService::DAILY_LIMIT_MAX ? $limit : self::DAILY;
    }

    /** @return array{0: int, 1: int} */
    private function gapRange(mixed $min, mixed $max): array
    {
        $lo = max(1, min(30, (int) ($min ?? 1)));
        $hi = (int) ($max ?? 0);
        if ($hi < $lo) $hi = $lo;
        if ($hi > 30) $hi = 30;
        return [$lo, $hi];
    }

    private function gapSeconds(mixed $min, mixed $max): int
    {
        [$lo, $hi] = $this->gapRange($min, $max);
        return random_int($lo, $hi) * 60;
    }

    /** The sheet's phone choice read from its row, in the same shape phones() expects. */
    private function sourceSetting(int $sourceId): array
    {
        $stmt = $this->db->prepare('SELECT sender_mode, device_id, device_ids FROM whatsapp_sheet_sources WHERE id = ?');
        $stmt->execute([$sourceId]);
        $row = $stmt->fetch() ?: [];
        $ids = json_decode((string) ($row['device_ids'] ?? ''), true);
        $ids = is_array($ids) ? array_values(array_filter($ids, 'is_string')) : [];
        if (!$ids && trim((string) ($row['device_id'] ?? '')) !== '') $ids = [trim((string) $row['device_id'])];
        $mode = (($row['sender_mode'] ?? 'all') === 'pick') ? 'pick' : 'all';
        return ['sender_mode' => $mode, 'device_ids' => $mode === 'pick' ? $ids : []];
    }

    /** @return list<array{id: string, name: string}> */
    private function phones(array $source): array
    {
        $picked = $this->allowedDeviceIds($source);
        $phones = [];
        foreach ($this->link->roster()['devices'] as $device) {
            if (($device['status'] ?? '') !== 'ready') continue;
            $id = (string) ($device['id'] ?? '');
            if ($id === '' || ($picked !== null && !in_array($id, $picked, true))) continue;
            $phones[] = ['id' => $id, 'name' => (string) ($device['name'] ?? 'Phone')];
        }
        return $phones;
    }

    /** Picked device ids for the sheet, or null when every phone on the account may send. */
    private function allowedDeviceIds(array $source): ?array
    {
        if (($source['sender_mode'] ?? 'all') !== 'pick') return null;
        $ids = $source['device_ids'] ?? [];
        return is_array($ids) ? array_values(array_filter($ids, 'is_string')) : [];
    }

    /** @return array{id: string, name: string} */
    private function deviceInfo(string $deviceId): array
    {
        foreach ($this->link->roster()['devices'] as $device) {
            if ((string) ($device['id'] ?? '') === $deviceId) {
                return ['id' => $deviceId, 'name' => (string) ($device['name'] ?? 'Phone')];
            }
        }
        $stmt = $this->db->prepare('SELECT name FROM whatsapp_devices WHERE device_id = ? LIMIT 1');
        $stmt->execute([$deviceId]);
        $name = trim((string) ($stmt->fetchColumn() ?: ''));
        return ['id' => $deviceId, 'name' => $name !== '' ? $name : 'Phone'];
    }

    private function startTime(array $source): string
    {
        $time = trim((string) ($source['start_time'] ?? ''));
        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) ? $time : self::DEFAULT_TIME;
    }

    private function startAt(array $source, string $time): int
    {
        $today = (new DateTimeImmutable('now', $this->zone))->format('Y-m-d');
        $date = trim((string) ($source['start_date'] ?? '')) ?: $today;
        if ($date < $today) throw new InvalidArgumentException('The start day has passed. Choose today or a later day.');
        $at = (new DateTimeImmutable($date . ' ' . $time, $this->zone))->getTimestamp();
        $now = (int) (ceil(time() / 60) * 60);
        return max($at, $now);
    }

    /** @param list<array{id: string, name: string}> $phones ready phones on this sheet */
    private function assign(array $rows, array $phones, int $start, int $gapMin, int $gapMax, string $time, int $daily): array
    {
        $owners = $this->ownersByDigits();
        $byId = [];
        foreach ($phones as $phone) $byId[$phone['id']] = $phone;
        $readyIds = array_column($phones, 'id');
        $usageIds = $readyIds;
        foreach ($rows as $row) {
            $ownerId = $owners[self::phoneDigits((string) ($row['values']['phone'] ?? ''))] ?? null;
            if ($ownerId !== null && !isset($byId[$ownerId])) {
                $byId[$ownerId] = $this->deviceInfo($ownerId);
                $usageIds[] = $ownerId;
            }
        }
        $usageIds = array_values(array_unique($usageIds));
        $usage = $this->usage($usageIds);
        $cursor = [];
        foreach ($usageIds as $id) {
            $at = $start;
            $last = $usage[$id]['last'] ?? 0;
            if ($last !== 0 && $last + $gapMin * 60 > $at) $at = $last + $gapMin * 60;
            $cursor[$id] = $this->fit($at, $usage[$id]['days'], $time, $daily);
        }
        $slots = [];
        foreach ($rows as $row) {
            $ownerId = $owners[self::phoneDigits((string) ($row['values']['phone'] ?? ''))] ?? null;
            if ($ownerId !== null) {
                // Sticky: always the owner phone, even when it is not ready. Never fall back.
                $pickId = $ownerId;
            } else {
                $pickId = $this->pickPhoneId($readyIds, $cursor, null);
                if ($pickId === null) continue;
            }
            $pick = $byId[$pickId] ?? $this->deviceInfo($pickId);
            $at = $cursor[$pickId];
            $day = $this->day($at);
            $usage[$pickId]['days'][$day] = ($usage[$pickId]['days'][$day] ?? 0) + 1;
            $slots[] = ['row' => $row, 'phone' => $pick, 'at' => $at];
            $next = $at + $this->gapSeconds($gapMin, $gapMax);
            if ($this->day($next) !== $day) $next = $this->dayStart($day, 1, $time);
            $cursor[$pickId] = $this->fit($next, $usage[$pickId]['days'], $time, $daily);
        }
        return $slots;
    }

    /** Digits only, so +66 9800 and 669800 match the same customer. */
    public static function phoneDigits(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?? '';
    }

    /**
     * Last successful sending phone per customer number (any sheet).
     * @return array<string, string> digits => device_id
     */
    public function ownersByDigits(): array
    {
        $map = [];
        foreach ($this->db->query(
            "SELECT phone, device_id FROM whatsapp_sends WHERE status = 'sent' AND device_id IS NOT NULL AND device_id <> '' ORDER BY id ASC"
        )->fetchAll() as $row) {
            $digits = self::phoneDigits((string) ($row['phone'] ?? ''));
            if ($digits === '') continue;
            $map[$digits] = (string) $row['device_id'];
        }
        return $map;
    }

    /**
     * Sticky owner when that phone is ready; otherwise null (leave the row â no fallback).
     * With no owner, the earliest free ready phone.
     * @param list<string> $phoneIds
     * @param array<string, int> $cursor
     */
    private function pickPhoneId(array $phoneIds, array $cursor, ?string $ownerId): ?string
    {
        if ($ownerId !== null) {
            if (in_array($ownerId, $phoneIds, true) && isset($cursor[$ownerId])) return $ownerId;
            return null;
        }
        if (!$phoneIds) return null;
        $pick = null;
        foreach ($phoneIds as $id) {
            if (!isset($cursor[$id])) continue;
            if ($pick === null || $cursor[$id] < $cursor[$pick]) $pick = $id;
        }
        return $pick;
        return $slots;
    }

    private function fit(int $at, array $days, string $time, int $daily): int
    {
        for ($guard = 0; $guard < 400; $guard++) {
            $day = $this->day($at);
            if (($days[$day] ?? 0) < $daily) return $at;
            $at = $this->dayStart($day, 1, $time);
        }
        return $at;
    }

    /** @param list<string> $ids */
    private function usage(array $ids): array
    {
        $usage = [];
        foreach ($ids as $id) $usage[$id] = ['days' => [], 'last' => 0];
        if (!$ids) return $usage;
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $query = $this->db->prepare("SELECT device_id, status, due_at, created_at FROM whatsapp_sends WHERE device_id IN ($marks) AND status IN ('waiting', 'sending', 'sent')");
        $query->execute($ids);
        foreach ($query->fetchAll() as $row) {
            $id = (string) $row['device_id'];
            $at = strtotime((string) ($row['status'] === 'sent' ? $row['created_at'] : ($row['due_at'] ?: $row['created_at'])));
            if (!$at) continue;
            $day = $this->day($at);
            $usage[$id]['days'][$day] = ($usage[$id]['days'][$day] ?? 0) + 1;
            if ($at > $usage[$id]['last']) $usage[$id]['last'] = $at;
        }
        return $usage;
    }

    /** @return array{0: int, 1: int} last sent time, sent today */
    private function sentStats(string $deviceId, int $now): array
    {
        $query = $this->db->prepare("SELECT created_at FROM whatsapp_sends WHERE device_id = ? AND status = 'sent'");
        $query->execute([$deviceId]);
        $last = 0;
        $today = 0;
        $day = $this->day($now);
        foreach ($query->fetchAll(PDO::FETCH_COLUMN) as $value) {
            $at = strtotime((string) $value);
            if (!$at) continue;
            if ($at > $last) $last = $at;
            if ($this->day($at) === $day) $today++;
        }
        return [$last, $today];
    }

    private function latestRows(int $sourceId): array
    {
        $query = $this->db->prepare('SELECT id, record_id, phone, name, message, status, error, account_name, device_id, due_at, created_at FROM whatsapp_sends WHERE source_id = ? ORDER BY id ASC');
        $query->execute([$sourceId]);
        $latest = [];
        $waves = [];
        foreach ($query->fetchAll() as $row) {
            $key = (string) $row['record_id'];
            $waves[$key] = ($waves[$key] ?? 0) + 1;
            $row['wave'] = $waves[$key];
            $latest[$key] = $row;
        }
        foreach ($latest as $key => &$row) {
            $row['wave'] = $waves[$key] ?? 1;
        }
        unset($row);
        return array_values($latest);
    }

    private function waitingTotals(): array
    {
        $rows = $this->db->query("SELECT due_at FROM whatsapp_sends WHERE status IN ('waiting', 'sending')")->fetchAll(PDO::FETCH_COLUMN);
        $next = null;
        foreach ($rows as $value) {
            $at = strtotime((string) $value);
            if ($at && ($next === null || $at < $next)) $next = $at;
        }
        return ['waiting' => count($rows), 'next_at' => $next !== null ? gmdate('c', $next) : null];
    }

    private function summary(array $built): array
    {
        $phones = [];
        foreach ($built['phones'] as $phone) {
            $phones[$phone['id']] = ['id' => $phone['id'], 'name' => $phone['name'], 'count' => 0, 'first_at' => null, 'last_at' => null];
        }
        $days = [];
        foreach ($built['slots'] as $slot) {
            $id = $slot['phone']['id'];
            $at = gmdate('c', $slot['at']);
            $phones[$id]['count']++;
            $phones[$id]['first_at'] ??= $at;
            $phones[$id]['last_at'] = $at;
            $day = $this->day($slot['at']);
            $days[$day] = ($days[$day] ?? 0) + 1;
        }
        ksort($days);
        $dayList = [];
        foreach ($days as $date => $count) $dayList[] = ['date' => $date, 'count' => $count];
        return [
            'total' => count($built['slots']),
            'start_at' => gmdate('c', $built['start']),
            'gap' => $built['gap'],
            'gap_min' => $built['gap_min'] ?? $built['gap'],
            'gap_max' => $built['gap_max'] ?? $built['gap'],
            'daily' => $built['daily'],
            'skipped' => $built['skipped'],
            'unsafe' => $built['unsafe'],
            'phones' => array_values($phones),
            'days' => $dayList,
        ];
    }

    private function state(string $status): string
    {
        return match ($status) {
            'sent' => 'sent',
            'failed', 'blocked' => 'failed',
            default => 'waiting', // waiting, sending and held all count as not yet sent
        };
    }

    private function day(int $at): string
    {
        return (new DateTimeImmutable('@' . $at))->setTimezone($this->zone)->format('Y-m-d');
    }

    private function dayStart(string $day, int $offset, string $time): int
    {
        return (new DateTimeImmutable($day . ' ' . $time, $this->zone))->modify('+' . $offset . ' day')->getTimestamp();
    }
}
