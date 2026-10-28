<?php
declare(strict_types=1);

namespace App\Services\Email;

use PDO;

final class ConversationThread
{
    /**
     * Normalize an email subject by stripping prefixes like "Re:", "Fwd:", "Fw:",
     * trimming whitespace, and normalizing case.
     */
    public static function normalizeSubject(string $subject): string
    {
        $sub = trim($subject);
        while (preg_match('/^(re|fwd|fw|aw|antw):\s*/i', $sub)) {
            $sub = preg_replace('/^(re|fwd|fw|aw|antw):\s*/i', '', $sub);
        }
        $sub = preg_replace('/[\s\x{00A0}]+/u', ' ', $sub);
        return mb_strtolower(trim($sub), 'UTF-8');
    }

    /**
     * Clean protocol and envelope artifacts (such as IMAP socket tags `)\nA0004` or residual MIME bounds).
     */
    public static function cleanArtifacts(string $text): string
    {
        // Strip trailing IMAP fetch envelope and tag (e.g. )\nA0004 OK Fetch completed or )\nA0004)
        $text = preg_replace('/\s*\)\s*\r?\n\s*A\d{4}(?:\s+[A-Za-z0-9_ ]*)?\s*$/i', '', $text);
        // Strip trailing boundary closing markers
        $text = preg_replace('/--[A-Za-z0-9_=\-\.\/]{8,120}--?\s*$/m', '', $text);
        // Normalize excessive consecutive blank lines
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        return trim($text);
    }

    /**
     * Split an email body into the newly written message text and any automatically quoted reply history.
     */
    public static function splitQuotedText(string $text): array
    {
        $text = self::cleanArtifacts($text);
        $patterns = [
            '/(?:\r?\n|^)\s*-{2,}\s*On\s+.+?\s+wrote:\s*-{2,}\s*(?:\r?\n|$)/is',
            '/(?:\r?\n|^)\s*On\s+.+?\s+wrote\s*:\s*(?:\r?\n|$)/is',
            '/(?:\r?\n|^)\s*-{3,}\s*Original Message\s*-{3,}\s*(?:\r?\n|$)/i',
            '/(?:\r?\n|^)\s*_{5,}\s*(?:\r?\n|$)/',
            '/(?:\r?\n|^)\s*From:\s*.+?\s*Sent:\s*.+?\s*To:\s*.+?\s*Subject:\s*/is',
        ];

        $main = $text;
        $quoted = '';

        foreach ($patterns as $pat) {
            if (preg_match($pat, $text, $m, PREG_OFFSET_CAPTURE)) {
                $offset = $m[0][1];
                $main = substr($text, 0, $offset);
                $quoted = substr($text, $offset);
                break;
            }
        }

        if ($quoted === '') {
            $lines = preg_split('/\r?\n/', $text);
            $cleanLines = [];
            $quoteLines = [];
            $inQuote = false;
            foreach ($lines as $line) {
                if (preg_match('/^\s*(?:>|-{2,}\s*On\s+.+wrote)/i', $line)) {
                    $inQuote = true;
                    $quoteLines[] = $line;
                } elseif ($inQuote) {
                    $quoteLines[] = $line;
                } else {
                    $cleanLines[] = $line;
                }
            }
            if (!empty($quoteLines)) {
                $main = implode("\n", $cleanLines);
                $quoted = implode("\n", $quoteLines);
            }
        }

        $main = self::cleanArtifacts($main);
        $quoted = self::cleanArtifacts($quoted);

        return ['main' => $main, 'quoted' => $quoted];
    }

    /**
     * Fetch all messages in a conversation thread for an outgoing email (emails.id).
     */
    public static function getThreadForEmail(PDO $db, int $emailId): array
    {
        $stmt = $db->prepare("SELECT * FROM emails WHERE id = ?");
        $stmt->execute([$emailId]);
        $email = $stmt->fetch();
        if (!$email) return [];

        $recipient = mb_strtolower(trim((string) $email['recipient']), 'UTF-8');
        $normSub = self::normalizeSubject((string) $email['subject']);
        $inboxEmailId = !empty($email['inbox_email_id']) ? (int) $email['inbox_email_id'] : null;

        return self::collectThreadMessages($db, $recipient, $normSub, $emailId, $inboxEmailId);
    }

    /**
     * Fetch all messages in a conversation thread for an incoming email (inbox_emails.id).
     */
    public static function getThreadForInbox(PDO $db, int $inboxId): array
    {
        $stmt = $db->prepare("SELECT * FROM inbox_emails WHERE id = ?");
        $stmt->execute([$inboxId]);
        $inbox = $stmt->fetch();
        if (!$inbox) return [];

        $senderEmail = mb_strtolower(trim((string) $inbox['from_email']), 'UTF-8');
        $normSub = self::normalizeSubject((string) $inbox['subject']);

        return self::collectThreadMessages($db, $senderEmail, $normSub, null, $inboxId);
    }

    /**
     * Find and combine all outgoing and incoming messages matching the contact email and normalized subject.
     */
    private static function collectThreadMessages(PDO $db, string $contactEmail, string $normSubject, ?int $activeEmailId = null, ?int $activeInboxId = null): array
    {
        $messages = [];
        $defaultDeskEmail = getenv('SMTP_USERNAME') ?: 'ray@rslsolution.com';

        // 1. Fetch outgoing emails to this recipient
        $stmt = $db->prepare("SELECT id, recipient, cc, bcc, subject, body, attachments, status, attempts, error, created_at, updated_at FROM emails WHERE LOWER(recipient) = ? ORDER BY id ASC");
        $stmt->execute([$contactEmail]);
        $outList = $stmt->fetchAll();

        foreach ($outList as $row) {
            $isSameSubject = ($normSubject !== '' && self::normalizeSubject((string) $row['subject']) === $normSubject);
            $isTarget = ($activeEmailId !== null && (int) $row['id'] === $activeEmailId);

            if ($isSameSubject || $isTarget) {
                $atts = !empty($row['attachments']) ? (json_decode((string) $row['attachments'], true) ?: []) : [];
                $timestamp = $row['created_at'];
                $split = self::splitQuotedText((string) $row['body']);
                $snippet = mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags($split['main']))), 0, 140);
                if ($snippet === '') $snippet = mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags($row['body']))), 0, 140);

                $messages[] = [
                    'id' => (int) $row['id'],
                    'type' => 'outgoing',
                    'sender_name' => 'Me (RSL Desk)',
                    'sender_email' => $defaultDeskEmail,
                    'recipient' => $row['recipient'],
                    'cc' => $row['cc'] ?? '',
                    'bcc' => $row['bcc'] ?? '',
                    'subject' => $row['subject'],
                    'body' => $split['main'],
                    'quoted_history' => $split['quoted'],
                    'html_body' => null,
                    'snippet' => $snippet,
                    'attachments' => $atts,
                    'status' => $row['status'],
                    'created_at' => $timestamp,
                    'timestamp' => strtotime($timestamp) ?: 0,
                    'is_active' => ($activeEmailId !== null && (int) $row['id'] === $activeEmailId)
                ];
            }
        }

        // 2. Fetch incoming emails from this sender
        $stmt = $db->prepare("SELECT id, uid, from_name, from_email, to_email, subject, body, html_body, snippet, is_read, received_at, created_at FROM inbox_emails WHERE LOWER(from_email) = ? ORDER BY id ASC");
        $stmt->execute([$contactEmail]);
        $inList = $stmt->fetchAll();

        foreach ($inList as $row) {
            $isSameSubject = ($normSubject !== '' && self::normalizeSubject((string) $row['subject']) === $normSubject);
            $isTarget = ($activeInboxId !== null && (int) $row['id'] === $activeInboxId);

            if ($isSameSubject || $isTarget) {
                $timestamp = $row['received_at'] ?: $row['created_at'];
                $split = self::splitQuotedText((string) $row['body']);
                $snippet = mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags($split['main']))), 0, 140);
                if ($snippet === '') $snippet = mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags($row['body']))), 0, 140);

                $messages[] = [
                    'id' => (int) $row['id'],
                    'type' => 'incoming',
                    'sender_name' => $row['from_name'] ?: $row['from_email'],
                    'sender_email' => $row['from_email'],
                    'recipient' => $row['to_email'] ?: $defaultDeskEmail,
                    'cc' => '',
                    'bcc' => '',
                    'subject' => $row['subject'],
                    'body' => $split['main'],
                    'quoted_history' => $split['quoted'],
                    'html_body' => $row['html_body'] ?? null,
                    'snippet' => $snippet,
                    'attachments' => [],
                    'status' => 'received',
                    'is_read' => (int) $row['is_read'],
                    'created_at' => $timestamp,
                    'timestamp' => strtotime($timestamp) ?: 0,
                    'is_active' => ($activeInboxId !== null && (int) $row['id'] === $activeInboxId)
                ];
            }
        }

        // Sort messages chronologically by timestamp (oldest first -> latest reply last)
        usort($messages, function ($a, $b) {
            return $a['timestamp'] <=> $b['timestamp'];
        });

        return $messages;
    }

    /**
     * Compute conversation counts for a list of emails (batch lookup).
     */
    public static function getThreadCountsForEmails(PDO $db, array $emailRows): array
    {
        $counts = [];
        foreach ($emailRows as $row) {
            $id = (int) $row['id'];
            $recipient = mb_strtolower(trim((string) ($row['recipient'] ?? '')), 'UTF-8');
            $norm = self::normalizeSubject((string) ($row['subject'] ?? ''));
            if ($recipient === '' || $norm === '') {
                $counts[$id] = 1;
                continue;
            }
            $matchCount = 0;
            $inboxStmt = $db->prepare("SELECT subject FROM inbox_emails WHERE LOWER(from_email) = ?");
            $inboxStmt->execute([$recipient]);
            foreach ($inboxStmt->fetchAll() as $in) {
                if (self::normalizeSubject((string) $in['subject']) === $norm) {
                    $matchCount++;
                }
            }
            $outStmt = $db->prepare("SELECT subject FROM emails WHERE LOWER(recipient) = ?");
            $outStmt->execute([$recipient]);
            foreach ($outStmt->fetchAll() as $out) {
                if (self::normalizeSubject((string) $out['subject']) === $norm) {
                    $matchCount++;
                }
            }
            $counts[$id] = max(1, $matchCount);
        }
        return $counts;
    }

    /**
     * Compute conversation counts for a list of inbox emails (batch lookup).
     */
    public static function getThreadCountsForInbox(PDO $db, array $inboxRows): array
    {
        $counts = [];
        foreach ($inboxRows as $row) {
            $id = (int) $row['id'];
            $sender = mb_strtolower(trim((string) ($row['from_email'] ?? '')), 'UTF-8');
            $norm = self::normalizeSubject((string) ($row['subject'] ?? ''));
            if ($sender === '' || $norm === '') {
                $counts[$id] = 1;
                continue;
            }
            $matchCount = 0;
            $inboxStmt = $db->prepare("SELECT subject FROM inbox_emails WHERE LOWER(from_email) = ?");
            $inboxStmt->execute([$sender]);
            foreach ($inboxStmt->fetchAll() as $in) {
                if (self::normalizeSubject((string) $in['subject']) === $norm) {
                    $matchCount++;
                }
            }
            $outStmt = $db->prepare("SELECT subject FROM emails WHERE LOWER(recipient) = ?");
            $outStmt->execute([$sender]);
            foreach ($outStmt->fetchAll() as $out) {
                if (self::normalizeSubject((string) $out['subject']) === $norm) {
                    $matchCount++;
                }
            }
            $counts[$id] = max(1, $matchCount);
        }
        return $counts;
    }
}

