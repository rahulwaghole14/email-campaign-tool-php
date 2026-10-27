<?php
declare(strict_types=1);
namespace App\Services\Email;

use PDO;
use RuntimeException;

final class EmailProcessor
{
    public function __construct(private PDO $db, private ?Mailer $mailer = null) {}

    public function processOne(int $id): array
    {
        $query = $this->db->prepare('SELECT * FROM emails WHERE id = ?');
        $query->execute([$id]);
        $email = $query->fetch();
        if (!$email) throw new RuntimeException('Email was not found.');
        if ($email['status'] !== 'pending') return ['id' => $id, 'status' => $email['status']];
        if ((int) $email['attempts'] >= 3) throw new RuntimeException('This email has reached the maximum number of attempts.');

        $claim = $this->db->prepare("UPDATE emails SET status = 'processing', attempts = attempts + 1, updated_at = ? WHERE id = ? AND status = 'pending' AND attempts < 3");
        $claim->execute([gmdate('c'), $id]);
        if (!$claim->rowCount()) throw new RuntimeException('This email is already being processed.');

        $queue = new EmailQueue($this->db);
        try {
            $messageId = ($this->mailer ?? new Mailer())->send($email);
            $status = $messageId === null ? 'dry_run' : 'sent';
            $this->db->prepare('UPDATE emails SET status = ?, message_id = ?, error = NULL, updated_at = ? WHERE id = ?')
                ->execute([$status, $messageId, gmdate('c'), $id]);
            $queue->log($id, $status, $status === 'sent' ? 'SMTP server accepted the email.' : 'Dry run completed; no email was sent.');
            return ['id' => $id, 'status' => $status];
        } catch (\Throwable $e) {
            $this->db->prepare("UPDATE emails SET status = 'failed', error = ?, updated_at = ? WHERE id = ?")
                ->execute(['Delivery could not be confirmed. Review mailbox and SMTP configuration before retrying.', gmdate('c'), $id]);
            $queue->log($id, 'failed', 'Delivery could not be confirmed; manual review required.');
            throw new RuntimeException('Delivery could not be confirmed. Review the email before retrying.');
        }
    }

    /**
     * Process multiple emails in batch using a single persistent SMTP connection.
     *
     * @param int[] $ids
     * @return array<int, array{id: int, status: string}>
     */
    public function processBatch(array $ids): array
    {
        if (empty($ids)) return [];
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (empty($ids)) return [];

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare("SELECT * FROM emails WHERE id IN ($placeholders) AND status = 'pending' AND attempts < 3");
        $stmt->execute($ids);
        $emails = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($emails)) return [];

        $claim = $this->db->prepare("UPDATE emails SET status = 'processing', attempts = attempts + 1, updated_at = ? WHERE id = ? AND status = 'pending'");
        $now = gmdate('c');
        $validEmails = [];
        foreach ($emails as $email) {
            $claim->execute([$now, $email['id']]);
            if ($claim->rowCount() > 0) {
                $validEmails[] = $email;
            }
        }

        if (empty($validEmails)) return [];

        $mailer = $this->mailer ?? new Mailer();
        $results = $mailer->sendBatch($validEmails);
        $queue = new EmailQueue($this->db);

        $updateSent = $this->db->prepare("UPDATE emails SET status = ?, message_id = ?, error = NULL, updated_at = ? WHERE id = ?");
        $updateFailed = $this->db->prepare("UPDATE emails SET status = 'failed', error = ?, updated_at = ? WHERE id = ?");

        $finalStatuses = [];
        foreach ($validEmails as $email) {
            $id = (int) $email['id'];
            $res = $results[$id] ?? ['status' => 'failed', 'error' => 'Unknown sending error'];
            $status = $res['status'] ?? 'failed';

            if ($status === 'sent' || $status === 'dry_run') {
                $updateSent->execute([$status, $res['message_id'] ?? null, gmdate('c'), $id]);
                $queue->log($id, $status, $status === 'sent' ? 'SMTP server accepted the email.' : 'Dry run completed; no email was sent.');
            } else {
                $err = $res['error'] ?? 'Delivery could not be confirmed.';
                $updateFailed->execute([$err, gmdate('c'), $id]);
                $queue->log($id, 'failed', $err);
            }
            $finalStatuses[$id] = ['id' => $id, 'status' => $status];
        }

        return $finalStatuses;
    }
}
