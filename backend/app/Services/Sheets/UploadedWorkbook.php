<?php
declare(strict_types=1);
namespace App\Services\Sheets;

use PDO;
use InvalidArgumentException;

/** Adapts uploaded cell data to the same operations as the linked-sheet service. */
final class UploadedWorkbook
{
    public function __construct(private PDO $db) {}

    public function import(string $path, string $name): string
    {
        $tabs = UploadReader::read($path, $name);
        $data = json_encode($tabs, JSON_THROW_ON_ERROR);
        if (strlen($data) > 10 * 1024 * 1024) throw new InvalidArgumentException('Imported data is too large. Split the workbook.');
        // Identical file uploads reuse their source, protecting against accidental repeat sends.
        $id = 'upload:' . hash_file('sha256', $path);
        $query = $this->db->prepare('SELECT id FROM uploaded_sheets WHERE id = ?'); $query->execute([$id]);
        if (!$query->fetchColumn()) {
            $this->db->prepare('INSERT INTO uploaded_sheets (id, title, workbook, created_at) VALUES (?, ?, ?, ?)')->execute([$id, mb_substr(basename(str_replace('\\', '/', $name)), 0, 240), $data, gmdate('c')]);
        }
        return $id;
    }

    public function request(string $method, string $path, ?array $body): array
    {
        $decoded = rawurldecode($path);
        if (!preg_match('/^(upload:[a-f0-9]{64})/', $decoded, $match)) throw new InvalidArgumentException('Invalid upload ID.');
        $id = $match[1];
        $query = $this->db->prepare('SELECT * FROM uploaded_sheets WHERE id = ?'); $query->execute([$id]); $stored = $query->fetch();
        if (!$stored) throw new InvalidArgumentException('Uploaded workbook not found.');
        $tabs = json_decode($stored['workbook'], true, 512, JSON_THROW_ON_ERROR);
        if ($method === 'GET' && str_contains($decoded, '/values/')) {
            $range = explode('?valueRenderOption=', explode('/values/', $decoded, 2)[1])[0];
            if (!preg_match("/^'((?:[^']|'')*)'!([0-9]+):([0-9]+)$/", $range, $parts)) throw new InvalidArgumentException('Invalid upload range.');
            $title = str_replace("''", "'", $parts[1]);
            foreach ($tabs as $tab) if ($tab['title'] === $title) return ['values' => array_slice($tab['rows'], (int) $parts[2] - 1, (int) $parts[3] - (int) $parts[2] + 1)];
            throw new InvalidArgumentException('Uploaded tab not found.');
        }
        if ($method === 'POST' && str_ends_with($decoded, ':batchUpdate')) {
            foreach ($body['requests'] ?? [] as $request) {
                $edit = $request['updateCells']; $range = $edit['range'];
                $tabs[$range['sheetId']]['rows'][$range['startRowIndex']][$range['startColumnIndex']] = $edit['rows'][0]['values'][0]['userEnteredValue']['stringValue'];
            }
            $update = $this->db->prepare('UPDATE uploaded_sheets SET workbook = ? WHERE id = ? AND workbook = ?');
            $update->execute([json_encode($tabs, JSON_THROW_ON_ERROR), $id, $stored['workbook']]);
            if (!$update->rowCount()) throw new \RuntimeException('Uploaded data changed. Refresh before saving again.');
            return ['replies' => []];
        }
        $sheets = [];
        foreach ($tabs as $index => $tab) $sheets[] = ['properties' => ['sheetId' => $index, 'title' => $tab['title'], 'gridProperties' => ['rowCount' => max(1, count($tab['rows'])), 'columnCount' => max(array_merge([1], array_map('count', $tab['rows'])))]]];
        return ['spreadsheetId' => $id, 'properties' => ['title' => $stored['title']], 'sheets' => $sheets];
    }
}
