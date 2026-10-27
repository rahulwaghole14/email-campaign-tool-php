<?php
declare(strict_types=1);
namespace App\Services\Email;

use PDO;
use InvalidArgumentException;

final class EmailQueue
{
    public function __construct(private PDO $db) {}

    public function enqueue(array $input): int
    {
        $recipient = trim((string) ($input['recipient'] ?? ''));
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Enter a valid recipient email address.');
        $subject = trim((string) ($input['subject'] ?? ''));
        $body = trim((string) ($input['body'] ?? ''));
        if ($subject === '' || mb_strlen($subject) > 255 || preg_match('/[\r\n]/', $subject)) throw new InvalidArgumentException('Subject is required, must be a single line, and may contain up to 255 characters.');
        if ($body === '' || strlen($body) > 100000) throw new InvalidArgumentException('Message is required and must be under 100 KB.');
        $lists = [];
        foreach (['cc', 'bcc'] as $field) {
            $addresses = array_values(array_filter(array_map('trim', explode(',', (string) ($input[$field] ?? '')))));
            foreach ($addresses as $address) if (!filter_var($address, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException("Invalid $field email address.");
            $lists[$field] = implode(',', $addresses);
        }
        $attachments = null;
        if (!empty($input['attachments'])) {
            $attList = is_string($input['attachments']) ? json_decode($input['attachments'], true) : $input['attachments'];
            if (is_array($attList)) {
                $sanitized = [];
                foreach ($attList as $att) {
                    if (is_array($att) && !empty($att['path']) && !empty($att['name'])) {
                        $sanitized[] = [
                            'name' => (string) $att['name'],
                            'path' => (string) $att['path'],
                            'size' => (int) ($att['size'] ?? 0),
                            'type' => (string) ($att['type'] ?? 'application/octet-stream')
                        ];
                    }
                }
                if (!empty($sanitized)) {
                    $attachments = json_encode($sanitized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                }
            }
        }
        $key = (string) ($input['idempotency_key'] ?? '');
        if (!preg_match('/^[a-zA-Z0-9-]{16,64}$/', $key)) throw new InvalidArgumentException('A valid request identifier is required.');
        $existing = $this->db->prepare('SELECT id FROM emails WHERE idempotency_key = ?');
        $existing->execute([$key]);
        if ($id = $existing->fetchColumn()) return (int) $id;
        $now = gmdate('c');
        $inboxEmailId = !empty($input['inbox_email_id']) ? (int) $input['inbox_email_id'] : null;
        $threadId = !empty($input['thread_id']) ? trim((string) $input['thread_id']) : md5(mb_strtolower($recipient, 'UTF-8') . '|' . ConversationThread::normalizeSubject($subject));
        $this->db->beginTransaction();
        try {
            $query = $this->db->prepare('INSERT INTO emails (recipient, cc, bcc, subject, body, attachments, idempotency_key, inbox_email_id, thread_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $query->execute([$recipient, $lists['cc'], $lists['bcc'], $subject, $body, $attachments, $key, $inboxEmailId, $threadId, $now, $now]);
            $id = (int) $this->db->lastInsertId();
            $this->log($id, 'queued', 'Manual email queued for processing.');
            $this->db->commit();
            return $id;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            // Concurrent submissions with the same key resolve to the original job.
            $existing->execute([$key]);
            if ($id = $existing->fetchColumn()) return (int) $id;
            throw $e;
        }
    }

    public function log(?int $id, string $action, string $detail): void
    {
        $this->db->prepare('INSERT INTO activity (email_id, action, detail, created_at) VALUES (?, ?, ?, ?)')->execute([$id, $action, $detail, gmdate('c')]);
    }

    public function retry(int $id): void
    {
        $query = $this->db->prepare("UPDATE emails SET status = 'pending', error = NULL, updated_at = ? WHERE id = ? AND status IN ('failed', 'dry_run') AND attempts < 3");
        $query->execute([gmdate('c'), $id]);
        if (!$query->rowCount()) throw new InvalidArgumentException('Only failed or dry-run emails with fewer than three attempts can be retried.');
        $this->log($id, 'retry', 'Retry requested by user.');
    }
}
