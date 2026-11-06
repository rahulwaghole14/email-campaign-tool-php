<?php
declare(strict_types=1);
namespace App\Services\Sheets;

use PDO;
use InvalidArgumentException;

/** Saved WhatsApp workbooks. Rows never enter the email queue. */
final class WhatsAppSheetService
{
    public const FIELDS = ['id', 'name', 'phone', 'message'];

    public function __construct(private PDO $db, private SheetService $sheets, private \Closure $request)
    {
        $this->ensure();
    }

    public function sources(): array
    {
        $rows = $this->db->query('SELECT * FROM whatsapp_sheet_sources ORDER BY id DESC')->fetchAll();
        foreach ($rows as &$row) {
            $row['mapping'] = json_decode((string) $row['mapping'], true);
            $row['tab_title'] = '';
            try {
                $meta = $this->sheets->metadata((string) $row['spreadsheet_id']);
                foreach ($meta['sheets'] ?? [] as $item) {
                    $props = $item['properties'] ?? [];
                    if ((int) ($props['sheetId'] ?? -1) === (int) $row['sheet_id']) {
                        $row['tab_title'] = trim((string) ($props['title'] ?? ''));
                    }
                }
            } catch (\Throwable) {}
        }
        return $rows;
    }

    public function source(int $id): array
    {
        $query = $this->db->prepare('SELECT * FROM whatsapp_sheet_sources WHERE id = ?');
        $query->execute([$id]);
        $source = $query->fetch();
        if (!$source) throw new InvalidArgumentException('Saved WhatsApp sheet not found.');
        $source['mapping'] = json_decode((string) $source['mapping'], true);
        $ids = json_decode((string) ($source['device_ids'] ?? ''), true);
        $source['device_ids'] = is_array($ids) ? array_values(array_filter($ids, 'is_string')) : [];
        if (!$source['device_ids'] && trim((string) ($source['device_id'] ?? '')) !== '') {
            $source['device_ids'] = [trim((string) $source['device_id'])];
        }
        $source['sender_mode'] = (($source['sender_mode'] ?? 'all') === 'pick') ? 'pick' : 'all';
        if ($source['sender_mode'] === 'all') $source['device_ids'] = [];
        return $source;
    }

    public function delete(int $id): void
    {
        $this->db->prepare('DELETE FROM whatsapp_sheet_sources WHERE id = ?')->execute([$id]);
    }

    public function save(array $input, ?int $sourceId = null): int
    {
        $spreadsheetId = SheetService::inputId($input);
        $tabId = (int) ($input['sheet_id'] ?? -1);
        $header = (int) ($input['header_row'] ?? 1);
        $data = $this->sheets->inspect($spreadsheetId, $tabId, $header);
        $mapping = self::mapping(is_array($input['mapping'] ?? null) ? $input['mapping'] : [], $data['headers']);
        if ($sourceId !== null) {
            $old = $this->source($sourceId);
            if ($old['spreadsheet_id'] !== $spreadsheetId || (int) $old['sheet_id'] !== $tabId) {
                throw new InvalidArgumentException('A saved sheet must keep its workbook and tab.');
            }
            $this->db->prepare('UPDATE whatsapp_sheet_sources SET title = ?, header_row = ?, mapping = ? WHERE id = ?')
                ->execute([$data['title'], $header, json_encode($mapping), $sourceId]);
            return $sourceId;
        }
        $existing = $this->db->prepare('SELECT id FROM whatsapp_sheet_sources WHERE spreadsheet_id = ? AND sheet_id = ?');
        $existing->execute([$spreadsheetId, $tabId]);
        if ($existing->fetchColumn()) throw new InvalidArgumentException('This tab is already saved. Open it from the list.');
        $this->db->prepare('INSERT INTO whatsapp_sheet_sources (spreadsheet_id, sheet_id, title, header_row, mapping, created_at) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$spreadsheetId, $tabId, $data['title'], $header, json_encode($mapping), gmdate('c')]);
        return (int) $this->db->lastInsertId();
    }

    public function preview(int $sourceId): array
    {
        $source = $this->source($sourceId);
        $data = $this->sheets->inspect($source['spreadsheet_id'], (int) $source['sheet_id'], (int) $source['header_row']);
        return [
            'source' => $source,
            'headers' => $data['headers'],
            'tab' => $data['tab'],
            'records' => self::records($data, $source['mapping'], (int) $source['header_row']),
        ];
    }

    public static function mapping(array $input, array $headers): array
    {
        $mapping = [];
        $used = [];
        foreach (self::FIELDS as $field) {
            $label = trim((string) ($input[$field] ?? ''));
            if ($label === '') {
                if ($field === 'phone' || $field === 'message') throw new InvalidArgumentException('Map the phone and WhatsApp message columns.');
                continue;
            }
            if (count(array_keys($headers, $label, true)) !== 1) throw new InvalidArgumentException('Mapped headers must exist and have unique names.');
            if (in_array($label, $used, true)) throw new InvalidArgumentException('Use a different column for each field.');
            $mapping[$field] = $label;
            $used[] = $label;
        }
        return $mapping;
    }

    public static function normalizePhone(string $value): ?string
    {
        $value = trim($value);
        $value = preg_replace('/^=+\s*/', '', $value) ?? $value;
        if (!str_starts_with($value, '+')) return null;
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if (strlen($digits) < 8 || strlen($digits) > 15) return null;
        return '+' . $digits;
    }

    /** @return array{numbers: list<string>, skipped: int, empty: bool} */
    public static function phoneList(string $raw): array
    {
        if (trim($raw) === '') return ['numbers' => [], 'skipped' => 0, 'empty' => true];
        $parts = preg_split('/[;,|\n\r]+/u', $raw) ?: [];
        $numbers = [];
        $skipped = 0;
        foreach ($parts as $part) {
            if (trim((string) $part) === '') continue;
            $normalized = self::normalizePhone((string) $part);
            if ($normalized === null) {
                $skipped++;
                continue;
            }
            $numbers[$normalized] = $normalized;
        }
        return ['numbers' => array_values($numbers), 'skipped' => $skipped, 'empty' => false];
    }

    public static function records(array $data, array $mapping, int $header): array
    {
        self::mapping($mapping, $data['headers']);
        $rows = [];
        $counts = [];
        foreach ($data['values'] as $offset => $cells) {
            if (!array_filter($cells, fn($value) => trim((string) $value) !== '')) continue;
            $values = [];
            foreach ($mapping as $field => $label) {
                if (!in_array($field, self::FIELDS, true)) continue;
                $index = array_search($label, $data['headers'], true);
                $values[$field] = (string) ($cells[$index] ?? '');
            }
            $sheetRow = $header + $offset + 1;
            $explicitId = trim((string) ($values['id'] ?? ''));
            $baseId = $explicitId !== '' ? $explicitId : 'row' . $sheetRow;
            $parsed = self::phoneList((string) ($values['phone'] ?? ''));
            $numbers = $parsed['numbers'];
            $message = trim((string) ($values['message'] ?? ''));
            $make = function (string $recordId, string $phone, int $skipped) use ($values, $sheetRow, $explicitId, $message, $data, $cells, &$counts, &$rows): void {
                $counts[$recordId] = ($counts[$recordId] ?? 0) + 1;
                $rowValues = $values;
                $rowValues['phone'] = $phone;
                $rowValues['name'] = trim((string) ($values['name'] ?? ''));
                $rowValues['message'] = $message;
                $rows[] = [
                    'row' => $sheetRow,
                    'record_id' => $recordId,
                    'parent_id' => $explicitId,
                    'values' => $rowValues,
                    'skipped_numbers' => $skipped,
                    'formulas' => str_starts_with($message, '=') ? ['message'] : [],
                    'version' => hash('sha256', json_encode([$data['headers'], $cells, $recordId], JSON_THROW_ON_ERROR)),
                    'send_status' => 'not_queued',
                    'email_id' => null,
                    'editable' => true,
                    'issues' => [],
                ];
            };
            if (count($numbers) > 1) {
                foreach ($numbers as $index => $phone) {
                    $make($baseId . '.' . ($index + 1), $phone, 0);
                }
                if ($parsed['skipped'] > 0 && isset($rows[array_key_last($rows)])) {
                    $rows[array_key_last($rows)]['skipped_numbers'] = $parsed['skipped'];
                }
            } else {
                $make($baseId, $numbers[0] ?? '', $parsed['skipped']);
            }
        }
        foreach ($rows as &$row) {
            $issues = [];
            if ($row['parent_id'] !== '' && ($counts[$row['record_id']] ?? 0) !== 1) $issues[] = 'Duplicate ID';
            if (trim((string) $row['values']['message']) === '') $issues[] = 'Missing message';
            elseif (str_starts_with((string) $row['values']['message'], '=')) $issues[] = 'Message is a formula';
            if ($row['values']['phone'] === '') {
                $issues[] = $row['skipped_numbers'] > 0 ? 'Missing country code' : 'Missing phone';
            }
            $row['issues'] = $issues;
            $row['editable'] = !in_array('Duplicate ID', $issues, true);
        }
        unset($row);
        return $rows;
    }

    public function edit(int $sourceId, array $input): void
    {
        $preview = $this->preview($sourceId);
        $matches = array_values(array_filter($preview['records'], fn($row) => $row['record_id'] === ($input['record_id'] ?? '')));
        if (count($matches) !== 1 || empty($matches[0]['editable'])) throw new InvalidArgumentException('Record ID is missing or duplicated. Correct it in the original spreadsheet.');
        $row = $matches[0];
        if (!hash_equals((string) $row['version'], (string) ($input['version'] ?? ''))) throw new \RuntimeException('This row changed since you opened it. Refresh before editing.');
        $changes = $input['changes'] ?? [];
        if (!is_array($changes) || !$changes) throw new InvalidArgumentException('No changes to save.');
        $source = $preview['source'];
        $requests = [];
        foreach ($changes as $field => $value) {
            if ($field === 'id' || $field === 'email' || !isset($source['mapping'][$field]) || !is_string($value)) throw new InvalidArgumentException('Invalid editable field. Record IDs cannot be changed here.');
            if (in_array($field, $row['formulas'], true)) throw new InvalidArgumentException('Edit formula cells in the original spreadsheet.');
            if (strlen($value) > 45000) throw new InvalidArgumentException('Cell text must be under 45 KB.');
            $column = array_search($source['mapping'][$field], $preview['headers'], true);
            if ($column === false) throw new InvalidArgumentException('That column is no longer in the sheet. Refresh and map it again.');
            $requests[] = ['updateCells' => ['range' => ['sheetId' => (int) $source['sheet_id'], 'startRowIndex' => $row['row'] - 1, 'endRowIndex' => $row['row'], 'startColumnIndex' => $column, 'endColumnIndex' => $column + 1], 'rows' => [['values' => [['userEnteredValue' => ['stringValue' => $value]]]]], 'fields' => 'userEnteredValue']];
        }
        ($this->request)('POST', rawurlencode($source['spreadsheet_id']) . ':batchUpdate', ['requests' => $requests]);
    }

    public function setSender(int $id, string $deviceId): void
    {
        $this->source($id);
        if ($deviceId !== '' && !preg_match('/^[A-Za-z0-9-]{8,80}$/', $deviceId)) {
            throw new InvalidArgumentException('Choose a linked phone.');
        }
        $this->setSenders($id, $deviceId === '' ? [] : [$deviceId], $deviceId === '' ? 'all' : 'pick');
    }

    /** @param list<string> $deviceIds */
    public function setSenders(int $id, array $deviceIds, string $mode = 'pick'): void
    {
        $this->source($id);
        $mode = $mode === 'all' ? 'all' : 'pick';
        $clean = [];
        foreach ($deviceIds as $deviceId) {
            $deviceId = trim((string) $deviceId);
            if ($deviceId === '' || !preg_match('/^[A-Za-z0-9-]{8,80}$/', $deviceId)) {
                throw new InvalidArgumentException('Choose a linked phone.');
            }
            if (!in_array($deviceId, $clean, true)) $clean[] = $deviceId;
        }
        if ($mode === 'pick' && !$clean) throw new InvalidArgumentException('Choose at least one phone.');
        if (count($clean) > 5) throw new InvalidArgumentException('This account can use 5 phones.');
        if ($mode === 'all') {
            $this->db->prepare('UPDATE whatsapp_sheet_sources SET sender_mode = ?, device_id = NULL, device_ids = NULL WHERE id = ?')
                ->execute(['all', $id]);
            return;
        }
        $this->db->prepare('UPDATE whatsapp_sheet_sources SET sender_mode = ?, device_id = ?, device_ids = ? WHERE id = ?')
            ->execute(['pick', $clean[0], json_encode(array_values($clean)), $id]);
    }

    public function setSchedule(int $id, string $startDate, ?int $delayMinutes): void
    {
        $this->source($id);
        if ($startDate !== '') {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $startDate);
            $errors = \DateTimeImmutable::getLastErrors() ?: ['warning_count' => 0, 'error_count' => 0];
            if (!$parsed || ($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0 || $parsed->format('Y-m-d') !== $startDate) {
                throw new InvalidArgumentException('Choose the day these rows start.');
            }
        }
        if ($delayMinutes !== null && ($delayMinutes < 1 || $delayMinutes > 30)) {
            throw new InvalidArgumentException('Set the gap from 1 to 30 minutes.');
        }
        $this->db->prepare('UPDATE whatsapp_sheet_sources SET start_date = ?, delay_minutes = ? WHERE id = ?')
            ->execute([$startDate !== '' ? $startDate : null, $delayMinutes, $id]);
    }

    private function ensure(): void
    {
        $id = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
            ? 'INTEGER PRIMARY KEY AUTO_INCREMENT'
            : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $this->db->exec("CREATE TABLE IF NOT EXISTS whatsapp_sheet_sources (
            id $id,
            spreadsheet_id VARCHAR(160) NOT NULL,
            sheet_id INTEGER NOT NULL,
            title VARCHAR(255) NOT NULL,
            header_row INTEGER NOT NULL,
            mapping TEXT NOT NULL,
            created_at VARCHAR(32) NOT NULL,
            UNIQUE(spreadsheet_id, sheet_id)
        )");
        $this->addColumn('device_id', 'VARCHAR(80) NULL');
        $this->addColumn('device_ids', 'TEXT NULL');
        $this->addColumn('sender_mode', 'VARCHAR(8) NULL');
        $this->addColumn('start_date', 'VARCHAR(10) NULL');
        $this->addColumn('delay_minutes', 'INTEGER NULL');
    }

    private function addColumn(string $name, string $definition): void
    {
        if (!preg_match('/^[a-z_]+$/', $name)) return;
        $driver = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $exists = false;
        if ($driver === 'mysql') {
            $exists = (bool) $this->db->query('SHOW COLUMNS FROM whatsapp_sheet_sources LIKE ' . $this->db->quote($name))->fetch();
        } else {
            foreach ($this->db->query('PRAGMA table_info(whatsapp_sheet_sources)')->fetchAll() as $column) {
                if (($column['name'] ?? '') === $name) $exists = true;
            }
        }
        if (!$exists) {
            $this->db->exec('ALTER TABLE whatsapp_sheet_sources ADD COLUMN ' . $name . ' ' . $definition);
        }
    }
}
