<?php
declare(strict_types=1);
namespace App\Services\Sheets;

use PDO;
use InvalidArgumentException;
use RuntimeException;
use App\Services\Email\EmailQueue;

final class SheetService
{
    public const FIELDS = ['id', 'name', 'email', 'subject', 'body', 'link', 'cc', 'bcc', 'enabled'];
    public function __construct(private PDO $db, private \Closure $request) {}

    public static function spreadsheetId(string $url): string
    {
        $parts = parse_url(trim($url));
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== 'docs.google.com'
            || !preg_match('#^/spreadsheets/d/([A-Za-z0-9_-]{10,160})(?:/|$)#', $parts['path'] ?? '', $match)) {
            throw new InvalidArgumentException('Paste a valid https://docs.google.com/spreadsheets/d/... link.');
        }
        return $match[1];
    }

    public function metadata(string $id): array
    {
        return ($this->request)('GET', rawurlencode($id) . '?fields=spreadsheetId,properties(title),sheets(properties(sheetId,title,gridProperties))', null);
    }

    public static function inputId(array $input): string
    {
        if (isset($input['upload_id'])) {
            if (!is_string($input['upload_id']) || !preg_match('/^upload:[a-f0-9]{64}$/D', $input['upload_id'])) throw new InvalidArgumentException('Invalid upload identifier.');
            return $input['upload_id'];
        }
        return self::spreadsheetId((string) ($input['url'] ?? ''));
    }

    public function inspect(string $id, int $tabId, int $header): array
    {
        if ($header < 1 || $header > 100) throw new InvalidArgumentException('Header row must be between 1 and 100.');
        $meta = $this->metadata($id); $tab = null;
        foreach ($meta['sheets'] ?? [] as $item) if ((int) $item['properties']['sheetId'] === $tabId) $tab = $item['properties'];
        if (!$tab) throw new InvalidArgumentException('Choose an existing sheet tab.');
        if (($tab['gridProperties']['columnCount'] ?? 0) > 100 || ($tab['gridProperties']['rowCount'] ?? 0) > 10000) {
            throw new InvalidArgumentException('This version supports tabs up to 100 columns and 10,000 rows. Reduce unused rows or columns first.');
        }
        $range = "'" . str_replace("'", "''", $tab['title']) . "'!" . $header . ':' . ($tab['gridProperties']['rowCount'] ?? 10000);
        $result = ($this->request)('GET', rawurlencode($id) . '/values/' . rawurlencode($range) . '?valueRenderOption=FORMULA', null);
        $values = $result['values'] ?? []; $headers = array_shift($values) ?? [];
        $headers = array_map(fn($v) => trim((string) $v), $headers);
        if (!$headers) throw new InvalidArgumentException('No headers found on the selected row.');
        return ['title' => $meta['properties']['title'], 'tab' => $tab, 'headers' => $headers, 'values' => $values];
    }

    public function sources(): array
    {
        $rows = $this->db->query('SELECT * FROM sheet_sources ORDER BY id DESC')->fetchAll();
        foreach ($rows as &$row) $row['mapping'] = json_decode($row['mapping'], true);
        return $rows;
    }

    public function source(int $id): array
    {
        $query = $this->db->prepare('SELECT * FROM sheet_sources WHERE id = ?'); $query->execute([$id]);
        $source = $query->fetch();
        if (!$source) throw new InvalidArgumentException('Saved source not found.');
        $source['mapping'] = json_decode($source['mapping'], true);
        return $source;
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM sheet_sources WHERE id = ?');
        return $stmt->execute([$id]);
    }

    public static function mapping(array $input, array $headers): array
    {
        $mapping = []; $used = [];
        foreach (self::FIELDS as $field) {
            $label = trim((string) ($input[$field] ?? ''));
            if ($label === '') {
                if (in_array($field, ['id','email','subject','body'], true)) throw new InvalidArgumentException('Map ID, email, subject, and message columns.');
                continue;
            }
            if (count(array_keys($headers, $label, true)) !== 1) throw new InvalidArgumentException('Mapped headers must exist and have unique names.');
            if (in_array($label, $used, true)) throw new InvalidArgumentException('Use a different column for each field.');
            $mapping[$field] = $label; $used[] = $label;
        }
        return $mapping;
    }

    public function save(array $input, ?int $sourceId = null): int
    {
        $id = self::inputId($input);
        $tabId = (int) ($input['sheet_id'] ?? -1); $header = (int) ($input['header_row'] ?? 1);
        $data = $this->inspect($id, $tabId, $header);
        $mapping = self::mapping($input['mapping'] ?? [], $data['headers']);
        if ($sourceId !== null) {
            $old = $this->source($sourceId);
            if ($old['spreadsheet_id'] !== $id || (int) $old['sheet_id'] !== $tabId || $old['mapping']['id'] !== $mapping['id']) throw new InvalidArgumentException('A saved source must retain its spreadsheet, tab, and ID mapping.');
            $this->db->prepare('UPDATE sheet_sources SET title = ?, header_row = ?, mapping = ? WHERE id = ?')->execute([$data['title'], $header, json_encode($mapping), $sourceId]);
            return $sourceId;
        }
        $existing = $this->db->prepare('SELECT id FROM sheet_sources WHERE spreadsheet_id = ? AND sheet_id = ?');
        $existing->execute([$id, $tabId]);
        if ($existing->fetchColumn()) throw new InvalidArgumentException('This tab is already saved. Open its existing source.');
        $this->db->prepare('INSERT INTO sheet_sources (spreadsheet_id, sheet_id, title, header_row, mapping, created_at) VALUES (?, ?, ?, ?, ?, ?)')->execute([$id, $tabId, $data['title'], $header, json_encode($mapping), gmdate('c')]);
        return (int) $this->db->lastInsertId();
    }

    public static function records(array $data, array $mapping, int $header): array
    {
        self::mapping($mapping, $data['headers']);
        $rows = []; $counts = [];
        foreach ($data['values'] as $offset => $cells) {
            if (!array_filter($cells, fn($value) => trim((string) $value) !== '')) continue;
            $values = []; $formulas = [];
            foreach ($mapping as $field => $label) {
                $value = (string) ($cells[array_search($label, $data['headers'], true)] ?? '');
                $values[$field] = $value;
                if (str_starts_with($value, '=')) $formulas[] = $field;
            }
            $key = trim($values['id']); $counts[$key] = ($counts[$key] ?? 0) + 1;
            $rows[] = ['row' => $header + $offset + 1, 'record_id' => $key, 'values' => $values, 'formulas' => $formulas,
                'version' => hash('sha256', json_encode([$data['headers'], $cells], JSON_THROW_ON_ERROR))];
        }
        foreach ($rows as &$row) {
            $issues = [];
            if ($row['record_id'] === '') $issues[] = 'Missing ID';
            elseif ($counts[$row['record_id']] !== 1) $issues[] = 'Duplicate ID';
            if ($row['formulas']) $issues[] = 'Mapped formula cells require review in the original spreadsheet';
            $cleanEmail = trim(preg_replace('/^[`\'"\s\x{00a0}\x{200b}]+|[`\'"\s\x{00a0}\x{200b}]+$/u', '', (string) $row['values']['email']));
            if (!filter_var($cleanEmail, FILTER_VALIDATE_EMAIL)) $issues[] = 'Invalid email';
            if (trim($row['values']['subject']) === '') $issues[] = 'Missing subject';
            if (trim($row['values']['body']) === '') $issues[] = 'Missing message';
            $row['editable'] = $row['record_id'] !== '' && $counts[$row['record_id']] === 1 && !in_array('id', $row['formulas'], true);
            $row['issues'] = $issues;
        }
        return $rows;
    }

    private function key(array $source, string $id): string
    {
        return hash('sha256', json_encode([$source['spreadsheet_id'], (int) $source['sheet_id'], $id]));
    }

    public function preview(int $sourceId): array
    {
        $source = $this->source($sourceId);
        $data = $this->inspect($source['spreadsheet_id'], (int) $source['sheet_id'], (int) $source['header_row']);
        $records = self::records($data, $source['mapping'], (int) $source['header_row']);
        $query = $this->db->prepare('SELECT id, status FROM emails WHERE idempotency_key = ?');
        foreach ($records as &$row) {
            $query->execute([$this->key($source, $row['record_id'])]);
            $email = $query->fetch();
            $row['send_status'] = $email ? $email['status'] : 'not_queued';
            $row['email_id'] = $email ? (int) $email['id'] : null;
        }
        return ['source' => $source, 'headers' => $data['headers'], 'tab' => $data['tab'], 'records' => $records];
    }

    private function current(array $preview, array $input): array
    {
        $matches = array_values(array_filter($preview['records'], fn($row) => $row['record_id'] === ($input['record_id'] ?? '')));
        if (count($matches) !== 1 || !$matches[0]['editable']) throw new InvalidArgumentException('Record ID is missing or duplicated. Correct it in the original spreadsheet.');
        $row = $matches[0];
        if (!hash_equals($row['version'], (string) ($input['version'] ?? ''))) throw new RuntimeException('This row changed since you opened it. Refresh before editing or queueing.');
        return $row;
    }

    public function edit(int $sourceId, array $input): void
    {
        $preview = $this->preview($sourceId); $row = $this->current($preview, $input);
        $source = $preview['source']; $changes = $input['changes'] ?? [];
        if (!is_array($changes) || !$changes) throw new InvalidArgumentException('No changes to save.');
        $requests = [];
        foreach ($changes as $field => $value) {
            if ($field === 'id' || !isset($source['mapping'][$field]) || !is_string($value)) throw new InvalidArgumentException('Invalid editable field. Record IDs cannot be changed here.');
            if (in_array($field, $row['formulas'], true)) throw new InvalidArgumentException('Edit formula cells in the original spreadsheet.');
            if (strlen($value) > 45000) throw new InvalidArgumentException('Cell text must be under 45 KB.');
            $column = array_search($source['mapping'][$field], $preview['headers'], true);
            $requests[] = ['updateCells' => ['range' => ['sheetId' => (int) $source['sheet_id'], 'startRowIndex' => $row['row'] - 1, 'endRowIndex' => $row['row'], 'startColumnIndex' => $column, 'endColumnIndex' => $column + 1], 'rows' => [['values' => [['userEnteredValue' => ['stringValue' => $value]]]]], 'fields' => 'userEnteredValue']];
        }
        ($this->request)('POST', rawurlencode($source['spreadsheet_id']) . ':batchUpdate', ['requests' => $requests]);
        (new EmailQueue($this->db))->log(null, 'sheet_updated', str_starts_with($source['spreadsheet_id'], 'upload:') ? 'Updated a row in an uploaded sheet.' : 'Updated a row in Google Sheets.');
    }

    public function enqueue(int $sourceId, array $input): int
    {
        $preview = $this->preview($sourceId); $row = $this->current($preview, $input);
        if ($row['issues']) throw new InvalidArgumentException(implode('. ', $row['issues']));
        $values = $row['values'];
        if (isset($values['enabled']) && strtoupper(trim($values['enabled'])) !== 'YES') throw new InvalidArgumentException('Set Send Enabled to YES before sending this row.');
        $cleanEmail = trim(preg_replace('/^[`\'"\s\x{00a0}\x{200b}]+|[`\'"\s\x{00a0}\x{200b}]+$/u', '', (string) ($values['email'] ?? '')));
        return (new EmailQueue($this->db))->enqueue([
            'recipient' => $cleanEmail, 'subject' => $values['subject'],
            'body' => $values['body'] . (empty($values['link']) ? '' : "\n\n" . $values['link']),
            'cc' => $values['cc'] ?? '', 'bcc' => $values['bcc'] ?? '',
            'mail_account_id' => !empty($input['mail_account_id']) ? (int) $input['mail_account_id'] : null,
            'idempotency_key' => $this->key($preview['source'], $row['record_id']),
        ]);
    }

    public function send(int $sourceId, array $input): array
    {
        $id = $this->enqueue($sourceId, $input);
        return (new \App\Services\Email\EmailProcessor($this->db))->processOne($id);
    }

    /**
     * High-speed bulk email dispatch:
     * 1. Fetches sheet preview only ONCE for all records.
     * 2. Enqueues all valid rows into database.
     * 3. Dispatches all emails across a single persistent Hostinger SMTP connection (SMTPKeepAlive).
     */
    public function batchSend(int $sourceId, array $input): array
    {
        $recordIds = $input['record_ids'] ?? [];
        if (!is_array($recordIds) || empty($recordIds)) {
            throw new InvalidArgumentException('No records selected for sending.');
        }

        // Fetch sheet preview once for the entire batch
        $preview = $this->preview($sourceId);
        $recordsById = [];
        foreach ($preview['records'] as $r) {
            $recordsById[$r['record_id']] = $r;
        }

        $emailQueue = new EmailQueue($this->db);
        $emailIds = [];
        $skipped = [];

        foreach ($recordIds as $recId) {
            $row = $recordsById[$recId] ?? null;
            if (!$row) {
                $skipped[] = ['record_id' => $recId, 'reason' => 'Record not found'];
                continue;
            }
            if (!empty($row['issues'])) {
                $skipped[] = ['record_id' => $recId, 'reason' => implode('; ', $row['issues'])];
                continue;
            }
            $values = $row['values'];
            if (isset($values['enabled']) && strtoupper(trim((string) $values['enabled'])) !== 'YES') {
                $skipped[] = ['record_id' => $recId, 'reason' => 'Send Enabled is not YES'];
                continue;
            }
            $cleanEmail = trim(preg_replace('/^[`\'"\s\x{00a0}\x{200b}]+|[`\'"\s\x{00a0}\x{200b}]+$/u', '', (string) ($values['email'] ?? '')));
            if (!filter_var($cleanEmail, FILTER_VALIDATE_EMAIL)) {
                $skipped[] = ['record_id' => $recId, 'reason' => 'Invalid email address'];
                continue;
            }

            try {
                $emailId = $emailQueue->enqueue([
                    'recipient' => $cleanEmail,
                    'subject' => (string) ($values['subject'] ?? 'No subject'),
                    'body' => (string) ($values['body'] ?? '') . (empty($values['link']) ? '' : "\n\n" . $values['link']),
                    'cc' => (string) ($values['cc'] ?? ''),
                    'bcc' => (string) ($values['bcc'] ?? ''),
                    'mail_account_id' => !empty($input['mail_account_id']) ? (int) $input['mail_account_id'] : null,
                    'idempotency_key' => $this->key($preview['source'], $row['record_id']),
                ]);
                $emailIds[$emailId] = $recId;
            } catch (\Throwable $e) {
                $skipped[] = ['record_id' => $recId, 'reason' => $e->getMessage()];
            }
        }

        if (empty($emailIds)) {
            return [
                'sent' => 0,
                'failed' => count($skipped),
                'total' => count($recordIds),
                'skipped' => $skipped,
            ];
        }

        $processor = new \App\Services\Email\EmailProcessor($this->db);
        $batchResults = $processor->processBatch(array_keys($emailIds));

        $sentCount = 0;
        $failedCount = count($skipped);
        foreach ($batchResults as $res) {
            if ($res['status'] === 'sent' || $res['status'] === 'dry_run') {
                $sentCount++;
            } else {
                $failedCount++;
            }
        }

        return [
            'sent' => $sentCount,
            'failed' => $failedCount,
            'total' => count($recordIds),
            'skipped' => $skipped,
        ];
    }
}
