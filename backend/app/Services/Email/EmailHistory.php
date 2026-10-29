<?php
declare(strict_types=1);
namespace App\Services\Email;

use PDO;
use InvalidArgumentException;

final class EmailHistory
{
    public function __construct(private PDO $db) {}

    public function listing(array $input): array
    {
        $size = (int) ($input['page_size'] ?? 10);
        if (!in_array($size, [10,25,50,100], true)) throw new InvalidArgumentException('Choose 10, 25, 50, or 100 emails per page.');
        $page = max(1, (int) ($input['page'] ?? 1));
        $search = trim((string) ($input['search'] ?? ''));
        if (mb_strlen($search) > 254) throw new InvalidArgumentException('Search must be at most 254 characters.');
        $status = (string) ($input['status'] ?? '');
        if (!in_array($status, ['', 'pending','processing','sent','failed','dry_run'], true)) throw new InvalidArgumentException('Invalid status filter.');
        $conditions = []; $params = [];
        if ($search !== '') {
            $conditions[] = "(recipient LIKE ? ESCAPE '!' OR subject LIKE ? ESCAPE '!')";
            $pattern = '%' . str_replace(['!','%','_'], ['!!','!%','!_'], $search) . '%';
            $params = [$pattern,$pattern];
        }
        if ($status !== '') { $conditions[] = 'status = ?'; $params[] = $status; }
        if (isset($input['mail_account_id']) && $input['mail_account_id'] !== '') {
            $conditions[] = 'mail_account_id = ?';
            $params[] = (int) $input['mail_account_id'];
        }
        $where = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';
        $count = $this->db->prepare('SELECT COUNT(*) FROM emails' . $where); $count->execute($params);
        $total = (int) $count->fetchColumn(); $pages = max(1, (int) ceil($total / $size)); $page = min($page, $pages);
        $offset = ($page - 1) * $size;
        $query = $this->db->prepare('SELECT id, recipient, subject, status, attempts, error, created_at FROM emails' . $where . ' ORDER BY id DESC LIMIT ' . $size . ' OFFSET ' . $offset);
        $query->execute($params);
        $emails = $query->fetchAll();
        $threadCounts = ConversationThread::getThreadCountsForEmails($this->db, $emails);
        foreach ($emails as &$em) {
            $em['thread_count'] = $threadCounts[(int) $em['id']] ?? 1;
        }
        return ['emails'=>$emails, 'pagination'=>['page'=>$page,'page_size'=>$size,'pages'=>$pages,'total'=>$total,'from'=>$total ? $offset + 1 : 0,'to'=>min($offset + $size,$total)]];
    }

    public function detail(int $id): ?array
    {
        $query = $this->db->prepare('SELECT id, recipient, cc, bcc, subject, body, attachments, status, attempts, error, message_id, created_at, updated_at FROM emails WHERE id = ?');
        $query->execute([$id]); $email = $query->fetch();
        if (!$email) return null;
        if (!empty($email['attachments'])) {
            $email['attachments'] = json_decode((string) $email['attachments'], true) ?: [];
        } else {
            $email['attachments'] = [];
        }
        $events = $this->db->prepare('SELECT id, action, detail, created_at FROM activity WHERE email_id = ? ORDER BY id ASC');
        $events->execute([$id]); $history = $events->fetchAll();
        $email['sent_at'] = null;
        foreach ($history as $event) if ($event['action'] === 'sent') $email['sent_at'] = $event['created_at'];

        $thread = ConversationThread::getThreadForEmail($this->db, $id);

        return ['email'=>$email,'activity'=>$history,'thread'=>$thread];
    }
}
