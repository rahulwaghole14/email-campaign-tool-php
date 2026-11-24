<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use App\Services\Sheets\SheetService;
use App\Services\Sheets\UploadedWorkbook;
use App\Services\Sheets\WhatsAppSheetService;
use App\Services\WhatsApp\WhatsAppTables;

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
}

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
App\Support\Database::migrate($db);
WhatsAppTables::ensure($db);

$csv = tempnam(sys_get_temp_dir(), 'wa-sheet-');
file_put_contents($csv, "ID,Name,Phone,Message\n1,Aditya,+918087889188,Hello\n2,Other,+919999999999,Hi\n");
$uploads = new UploadedWorkbook($db);
$uploadId = $uploads->import($csv, 'sticky-test.csv');
@unlink($csv);

$fetch = static function ($method, $path, $body) use ($uploads) {
    return $uploads->request($method, $path, $body);
};
$reader = new SheetService($db, $fetch);
$sheets = new WhatsAppSheetService($db, $reader, $fetch);
$sourceId = $sheets->save([
    'upload_id' => $uploadId,
    'sheet_id' => 0,
    'header_row' => 1,
    'mapping' => ['id' => 'ID', 'name' => 'Name', 'phone' => 'Phone', 'message' => 'Message'],
]);

$file = $sheets->download($sourceId, [
    '1' => ['status' => 'sent', 'error' => ''],
    '2' => ['status' => 'failed', 'error' => 'not registered on WhatsApp'],
]);

check(str_ends_with($file['filename'], '-status.csv'), 'download filename ends with -status.csv');
check(str_contains($file['body'], 'Status'), 'download adds a Status column');
check(str_contains($file['body'], 'Message sent'), 'sent rows are labelled Message sent');
check(str_contains($file['body'], 'Not on WhatsApp'), 'no-WhatsApp failures are labelled');
check(str_starts_with($file['body'], "\xEF\xBB\xBF"), 'download is UTF-8 with BOM');

echo "OK: WhatsApp sheet download includes send status\n";
