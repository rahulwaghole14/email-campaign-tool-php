<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';
use App\Support\Database;
use App\Services\Sheets\SheetService;

putenv('DB_CONNECTION=sqlite'); putenv('DB_DATABASE=:memory:');
$db = Database::connect(); Database::migrate($db);
$headers = ['ID','Agency name','Email','Email Subject','Email Content','Send Enabled','Unmapped'];
$rows = [['OM-1','Agency One','one@example.com','Hello','Message','YES','=1+2'], ['OM-2','Agency Two','two@example.com','Hi','Other message','NO','keep']];
$requests = []; $checks = 0;
function check(bool $value, string $message): void { global $checks; if (!$value) throw new RuntimeException($message); $checks++; }
function rejects(callable $call, string $message): void { global $checks; try { $call(); } catch (InvalidArgumentException|RuntimeException $e) { $checks++; return; } throw new Exception($message); }
$transport = function ($method, $path, $body) use (&$rows, &$headers, &$requests) {
    $requests[] = [$method,$path,$body];
    if (str_contains($path, ':batchUpdate')) {
        foreach ($body['requests'] as $request) {
            $edit = $request['updateCells'];
            $rows[$edit['range']['startRowIndex'] - 6][$edit['range']['startColumnIndex']] = $edit['rows'][0]['values'][0]['userEnteredValue']['stringValue'];
        }
        return ['replies' => []];
    }
    if (str_contains($path, '/values/')) return ['values' => array_merge([$headers], $rows)];
    return ['properties'=>['title'=>'Agencies'], 'sheets'=>[['properties'=>['sheetId'=>0,'title'=>"Travel agencies",'gridProperties'=>['rowCount'=>1000,'columnCount'=>26]]]]];
};
$service = new SheetService($db,$transport);
$url = 'https://docs.google.com/spreadsheets/d/abcdefghijk12345/edit?usp=sharing';
check(SheetService::spreadsheetId($url) === 'abcdefghijk12345','URL parsing failed');
rejects(fn()=>SheetService::spreadsheetId('https://evil.example/spreadsheets/d/abcdefghijk12345/edit'),'External host accepted');
rejects(fn()=>SheetService::spreadsheetId('http://docs.google.com/spreadsheets/d/abcdefghijk12345'),'HTTP link accepted');
$mapping = ['id'=>'ID','name'=>'Agency name','email'=>'Email','subject'=>'Email Subject','body'=>'Email Content','enabled'=>'Send Enabled'];
rejects(fn()=>SheetService::mapping(['id'=>'ID'],$headers),'Missing mappings accepted');
rejects(fn()=>SheetService::mapping($mapping,array_merge($headers,['Email'])),'Duplicate headers accepted');
$input = ['url'=>$url,'sheet_id'=>0,'header_row'=>6,'mapping'=>$mapping];
$id = $service->save($input);
rejects(fn()=>$service->save($input),'Duplicate source accepted');
$preview = $service->preview($id); $row = $preview['records'][0];
check($row['row'] === 7 && $row['record_id'] === 'OM-1','Header offset incorrect');
check($row['issues'] === [],'Unmapped formula should be preserved and ignored');
$rows = array_reverse($rows);
$service->edit($id,['record_id'=>'OM-1','version'=>$row['version'],'changes'=>['body'=>'Updated text']]);
check($rows[1][4] === 'Updated text' && $rows[0][4] === 'Other message','Sorted row edit targeted wrong ID');
check($rows[1][6] === '=1+2','Unmapped formula overwritten');
rejects(fn()=>$service->edit($id,['record_id'=>'OM-1','version'=>$row['version'],'changes'=>['body'=>'Stale']]),'Stale edit accepted');
$preview = $service->preview($id); $row = $preview['records'][1];
rejects(fn()=>$service->edit($id,['record_id'=>'OM-1','version'=>$row['version'],'changes'=>['id'=>'new-id']]),'ID change accepted');
$emailId = $service->enqueue($id,['record_id'=>'OM-1','version'=>$row['version']]);
check($emailId === $service->enqueue($id,['record_id'=>'OM-1','version'=>$row['version']]),'Duplicate queue created');
$service->edit($id,['record_id'=>'OM-1','version'=>$row['version'],'changes'=>['subject'=>'Edited after queue']]);
check($db->query('SELECT subject FROM emails')->fetchColumn() === 'Hello','Queued content rewritten');
$preview = $service->preview($id);
check($preview['records'][1]['send_status'] === 'pending','Queue status missing');
rejects(fn()=>$service->enqueue($id,['record_id'=>'OM-2','version'=>$preview['records'][0]['version']]),'Disabled record queued');
$rows[0][0] = 'OM-1';
$preview = $service->preview($id);
check(!$preview['records'][0]['editable'] && !$preview['records'][1]['editable'],'Duplicate ID editable');
$rows[0][0] = 'OM-2'; $rows[1][4] = '=CONCAT("formula")';
$preview = $service->preview($id); $row = $preview['records'][1];
rejects(fn()=>$service->edit($id,['record_id'=>'OM-1','version'=>$row['version'],'changes'=>['body'=>'Overwrite']]),'Mapped formula overwritten');
rejects(fn()=>$service->enqueue($id,['record_id'=>'OM-1','version'=>$row['version']]),'Formula row queued');
$rows[1][4] = 'Plain text'; $preview = $service->preview($id); $row = $preview['records'][1];
$service->edit($id,['record_id'=>'OM-1','version'=>$row['version'],'changes'=>['body'=>'=literal text']]);
$write = end($requests);
check($write[2]['requests'][0]['updateCells']['rows'][0]['values'][0]['userEnteredValue']['stringValue'] === '=literal text','Input not written as literal text');
$rows[1][4] = 'Valid message';
$rows[0][5] = 'YES';
putenv('MAIL_DRY_RUN=true');
$batchRes = $service->batchSend($id, ['record_ids' => ['OM-1', 'OM-2']]);
check($batchRes['total'] === 2 && $batchRes['sent'] === 2 && $batchRes['failed'] === 0, 'Batch send failed');
$rows[1][2] = '';
$preview = $service->preview($id);
$blank = null;
foreach ($preview['records'] as $record) if ($record['record_id'] === 'OM-1') $blank = $record;
check($blank && $blank['issues'] === ['Missing email'], 'Blank email should say Missing email');
$rows[1][2] = 'not-an-email';
$preview = $service->preview($id);
$bad = null;
foreach ($preview['records'] as $record) if ($record['record_id'] === 'OM-1') $bad = $record;
check($bad && $bad['issues'] === ['Invalid email'], 'Malformed email should say Invalid email');
$rows[1][2] = 'one@example.com';
$rows[1][3] = '';
$rows[1][4] = '   ';
$preview = $service->preview($id);
$emptyCopy = null;
foreach ($preview['records'] as $record) if ($record['record_id'] === 'OM-1') $emptyCopy = $record;
check($emptyCopy && $emptyCopy['issues'] === ['Missing subject', 'Missing message'], 'Blank subject and message mislabeled');
echo "$checks Google Sheets checks passed.\n";
