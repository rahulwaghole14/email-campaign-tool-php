<?php
declare(strict_types=1);

namespace App\Services;

use App\Services\Email\ImapClient;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

final class DashboardService
{
    public static function snapshot(PDO $db, ?int $accountId): array
    {
        $tz = new DateTimeZone(getenv('APP_TIMEZONE') ?: 'Asia/Kolkata');
        $today = new DateTimeImmutable('today', $tz);
        $windowStart = $today->modify('-6 days');

        $counts = ['pending' => 0, 'sent' => 0, 'failed' => 0, 'dry_run' => 0, 'processing' => 0];
        $emailSql = 'SELECT status, recipient, created_at FROM emails';
        $inboxSql = 'SELECT id, from_name, from_email, subject, snippet, is_read, received_at, created_at FROM inbox_emails';
        $params = [];
        if ($accountId !== null && $accountId > 0) {
            $emailSql .= ' WHERE mail_account_id = ?';
            $inboxSql .= ' WHERE mail_account_id = ?';
            $params[] = $accountId;
        }

        $emailStmt = $db->prepare($emailSql);
        $emailStmt->execute($params);
        $emails = $emailStmt->fetchAll(PDO::FETCH_ASSOC);

        $inboxStmt = $db->prepare($inboxSql);
        $inboxStmt->execute($params);
        $inbox = $inboxStmt->fetchAll(PDO::FETCH_ASSOC);

        $sentRecipients = [];
        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $day = $windowStart->modify('+' . $i . ' days');
            $key = $day->format('Y-m-d');
            $days[$key] = [
                'day' => $day->format('D'),
                'date' => $key,
                'sent' => 0,
                'received' => 0,
            ];
        }

        foreach ($emails as $row) {
            $status = (string) ($row['status'] ?? '');
            if (!isset($counts[$status])) {
                $counts[$status] = 0;
            }
            $counts[$status]++;
            if ($status !== 'sent') {
                continue;
            }
            $recipient = self::address((string) ($row['recipient'] ?? ''));
            if ($recipient !== '') {
                $sentRecipients[$recipient] = true;
            }
            $when = self::instant((string) ($row['created_at'] ?? ''), $tz);
            if ($when !== null) {
                $key = $when->format('Y-m-d');
                if (isset($days[$key])) {
                    $days[$key]['sent']++;
                }
            }
        }

        $inboxTotal = count($inbox);
        $inboxUnread = 0;
        $clientReplies = 0;
        $clientUnread = 0;
        $bounces = 0;
        $replied = [];
        $hours = array_fill(0, 24, 0);
        $latestBySender = [];

        foreach ($inbox as $row) {
            $fromEmail = self::address((string) ($row['from_email'] ?? ''));
            $fromName = trim((string) ($row['from_name'] ?? ''));
            $subject = trim((string) ($row['subject'] ?? ''));
            $isBounce = ImapClient::isBounceEmail($fromName, $fromEmail, $subject);
            $unread = (int) ($row['is_read'] ?? 0) === 0;
            if ($unread) {
                $inboxUnread++;
            }
            if ($isBounce) {
                $bounces++;
                continue;
            }

            $clientReplies++;
            if ($unread) {
                $clientUnread++;
            }
            if ($fromEmail !== '' && isset($sentRecipients[$fromEmail])) {
                $replied[$fromEmail] = true;
            }

            $when = self::instant((string) (($row['received_at'] ?? '') ?: ($row['created_at'] ?? '')), $tz);
            if ($when !== null) {
                $key = $when->format('Y-m-d');
                if (isset($days[$key])) {
                    $days[$key]['received']++;
                    if (!self::isNoiseSender($fromEmail, $fromName)) {
                        $hours[(int) $when->format('G')]++;
                    }
                }
            }

            if ($fromEmail === '' || self::isNoiseSender($fromEmail, $fromName)) {
                continue;
            }
            $current = $latestBySender[$fromEmail] ?? null;
            $id = (int) ($row['id'] ?? 0);
            if ($current === null || $id >= $current['id']) {
                $latestBySender[$fromEmail] = [
                    'id' => $id,
                    'from_name' => $fromName !== '' ? $fromName : $fromEmail,
                    'from_email' => (string) ($row['from_email'] ?? $fromEmail),
                    'snippet' => self::preview((string) ($row['snippet'] ?? ''), $subject),
                    'received_at' => (string) (($row['received_at'] ?? '') ?: ($row['created_at'] ?? '')),
                    'inbox_count' => ($current['inbox_count'] ?? 0) + 1,
                ];
            } else {
                $latestBySender[$fromEmail]['inbox_count']++;
            }
        }

        $sentCount = [];
        foreach ($emails as $row) {
            if ((string) ($row['status'] ?? '') !== 'sent') {
                continue;
            }
            $recipient = self::address((string) ($row['recipient'] ?? ''));
            if ($recipient === '') {
                continue;
            }
            $sentCount[$recipient] = ($sentCount[$recipient] ?? 0) + 1;
        }

        $hot = array_values($latestBySender);
        usort($hot, static function (array $a, array $b) use ($sentCount): int {
            $aMessages = $a['inbox_count'] + ($sentCount[self::address($a['from_email'])] ?? 0);
            $bMessages = $b['inbox_count'] + ($sentCount[self::address($b['from_email'])] ?? 0);
            return $bMessages <=> $aMessages ?: $b['id'] <=> $a['id'];
        });
        $hot = array_slice($hot, 0, 5);
        $hotContacts = [];
        foreach ($hot as $contact) {
            $email = self::address($contact['from_email']);
            $hotContacts[] = [
                'id' => $contact['id'],
                'from_name' => $contact['from_name'],
                'from_email' => $contact['from_email'],
                'snippet' => $contact['snippet'],
                'received_at' => $contact['received_at'],
                'thread_count' => $contact['inbox_count'] + ($sentCount[$email] ?? 0),
            ];
        }

        $sentTotal = (int) $counts['sent'];
        $failedTotal = (int) $counts['failed'];
        $deliveryRate = ($sentTotal + $failedTotal) > 0
            ? round(($sentTotal / ($sentTotal + $failedTotal)) * 100, 1)
            : 100.0;
        $healthBase = $sentTotal + $failedTotal + $bounces;
        $healthRate = $healthBase > 0 ? round(($sentTotal / $healthBase) * 100, 1) : 100.0;
        $recipientTotal = count($sentRecipients);
        $responseRate = $recipientTotal > 0
            ? min(100.0, round((count($replied) / $recipientTotal) * 100, 1))
            : 0.0;

        $peakHour = null;
        $peakCount = 0;
        foreach ($hours as $hour => $count) {
            if ($count > $peakCount) {
                $peakCount = $count;
                $peakHour = $hour;
            }
        }
        $peakWindow = $peakHour === null ? 'No client replies in the last 7 days' : self::hourWindow((int) $peakHour);

        $weekSent = 0;
        $weekReceived = 0;
        foreach ($days as $day) {
            $weekSent += $day['sent'];
            $weekReceived += $day['received'];
        }

        $lastSync = null;
        try {
            $lastSync = $db->query("SELECT created_at FROM activity WHERE action = 'inbox_synced' ORDER BY id DESC LIMIT 1")->fetchColumn() ?: null;
        } catch (Throwable) {
            $lastSync = null;
        }

        $sheetsCount = (int) ($db->query('SELECT (SELECT COUNT(*) FROM sheet_sources) + (SELECT COUNT(*) FROM uploaded_sheets)')->fetchColumn() ?: 0);
        $activeAccountEmail = getenv('SMTP_USERNAME') ?: 'ray@rslsolution.com';
        if ($accountId !== null && $accountId > 0) {
            $account = MailAccountService::getById($db, $accountId, false);
            if ($account) {
                $activeAccountEmail = (string) $account['email'];
            }
        }

        return [
            'counts' => array_merge($counts, [
                'inbox_total' => $inboxTotal,
                'inbox_replies' => $clientReplies,
                'inbox_unread' => $inboxUnread,
                'client_unread' => $clientUnread,
                'bounces' => $bounces,
                'delivery_rate' => $deliveryRate,
                'health_rate' => $healthRate,
                'response_rate' => $responseRate,
                'replied_recipients' => count($replied),
                'sent_recipients' => $recipientTotal,
            ]),
            'inbox_unread' => $inboxUnread,
            'traffic_7d' => array_values($days),
            'week_totals' => ['sent' => $weekSent, 'received' => $weekReceived],
            'peak_window' => $peakWindow,
            'last_sync' => $lastSync,
            'hot_contacts' => $hotContacts,
            'system_health' => [
                'smtp_host' => 'smtp.hostinger.com:465',
                'smtp_user' => $activeAccountEmail,
                'imap_host' => 'imap.hostinger.com:993',
                'imap_total' => $inboxTotal,
                'sheets_count' => $sheetsCount,
            ],
            'dry_run' => (getenv('MAIL_DRY_RUN') ?: 'true') !== 'false',
        ];
    }

    private static function address(string $value): string
    {
        return strtolower(trim($value));
    }

    private static function instant(string $value, DateTimeZone $tz): ?DateTimeImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        try {
            return (new DateTimeImmutable($value))->setTimezone($tz);
        } catch (Throwable) {
            return null;
        }
    }

    private static function hourWindow(int $hour): string
    {
        $format = static function (int $value): string {
            $period = $value >= 12 ? 'PM' : 'AM';
            $clock = $value % 12;
            if ($clock === 0) {
                $clock = 12;
            }
            return $clock . ':00 ' . $period;
        };
        return $format($hour) . ' – ' . $format(($hour + 2) % 24);
    }

    private static function isNoiseSender(string $email, string $name): bool
    {
        if (ImapClient::isBounceEmail($name, $email, '')) {
            return true;
        }
        $haystack = strtolower($email . ' ' . $name);
        foreach (['noreply', 'no-reply', 'donotreply', 'do-not-reply', 'jobalerts', 'newsletter', 'notification', 'mailer-daemon', 'postmaster', 'linkedin'] as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }
        return false;
    }

    private static function preview(string $snippet, string $subject): string
    {
        $snippet = trim($snippet);
        $looksTechnical = $snippet === ''
            || ImapClient::isMimeStub($snippet)
            || preg_match('/boundary=|\{|\}|span,\s*td|<style|stylesheet/i', $snippet) === 1;
        if (!$looksTechnical) {
            $cleaned = ImapClient::cleanSnippet($snippet, 90);
            if ($cleaned !== '' && $cleaned !== '.' && !ImapClient::isMimeStub($cleaned)) {
                return $cleaned;
            }
        }
        $subject = trim($subject);
        return $subject !== '' ? $subject : 'Reply received';
    }
}
