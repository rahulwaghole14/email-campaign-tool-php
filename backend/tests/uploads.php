<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';
use App\Support\Database;
use App\Services\Sheets\UploadReader;
use App\Services\Sheets\UploadedWorkbook;
use App\Services\Sheets\SheetService;

putenv('DB_CONNECTION=sqlite'); putenv('DB_DATABASE=:memory:');
$db = Database::connect(); Database::migrate($db);
$uploads = new UploadedWorkbook($db);
$service = new SheetService($db, fn($method,$path,$body) => $uploads->request($method,$path,$body));
$csv = tempnam(sys_get_temp_dir(),'upload-csv-'); $xlsx = tempnam(sys_get_temp_dir(),'upload-xlsx-'); $checks = 0;
function check(bool $value, string $message): void { global $checks; if (!$value) throw new RuntimeException($message); $checks++; }
function rejects(callable $call): void { global $checks; try { $call(); } catch (InvalidArgumentException|RuntimeException $e) { $checks++; return; } throw new Exception('Expected rejection.'); }
try {
    file_put_contents($csv, "\xEF\xBB\xBFID,Email,Subject,Message,Enabled\nA1,test@example.com,Hello,\"First line\nSecond line\",YES\n");
    $data = UploadReader::read($csv,'contacts.csv');
    check($data[0]['rows'][0][0] === 'ID','BOM not removed');
    check($data[0]['rows'][1][3] === "First line\nSecond line",'Quoted multiline CSV not preserved');
    $id = $uploads->import($csv,'contacts.csv');
    check($id === $uploads->import($csv,'contacts.csv'),'Identical file did not reuse import');
    $input = ['upload_id'=>$id,'sheet_id'=>0,'header_row'=>1,'mapping'=>['id'=>'ID','email'=>'Email','subject'=>'Subject','body'=>'Message','enabled'=>'Enabled']];
    $sourceId = $service->save($input); $preview = $service->preview($sourceId); $row = $preview['records'][0];
    check($row['values']['email'] === 'test@example.com','Import mapping failed');
    $service->edit($sourceId,['record_id'=>'A1','version'=>$row['version'],'changes'=>['body'=>'Updated imported copy']]);
    $updated = $service->preview($sourceId)['records'][0];
    check($updated['values']['body'] === 'Updated imported copy','Uploaded edit not persisted');
    check(str_contains(file_get_contents($csv),'Second line'),'Original file modified');
    rejects(fn()=>$service->edit($sourceId,['record_id'=>'A1','version'=>$row['version'],'changes'=>['body'=>'stale']]));
    $job = $service->enqueue($sourceId,['record_id'=>'A1','version'=>$updated['version']]);
    check($job === $service->enqueue($sourceId,['record_id'=>'A1','version'=>$updated['version']]),'Duplicate imported job');
    check($id === $uploads->import($csv,'renamed.csv'),'File rename created another import');
    check($service->preview($sourceId)['records'][0]['values']['body'] === 'Updated imported copy','Reupload overwrote edits');
    rejects(fn()=>UploadReader::read($csv,'contacts.xls'));
    rejects(fn()=>UploadReader::read($csv,'contacts.xlsx'));
    file_put_contents($csv,str_repeat('column,',101)); rejects(fn()=>UploadReader::read($csv,'wide.csv'));
    file_put_contents($csv,"ID,Email\n1,\xff\xfe"); rejects(fn()=>UploadReader::read($csv,'invalid.csv'));

    $zip = new ZipArchive(); $zip->open($xlsx, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('xl/workbook.xml','<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Travel agencies" sheetId="1" r:id="r1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels','<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="r1" Target="worksheets/sheet1.xml"/></Relationships>');
    $zip->addFromString('xl/sharedStrings.xml','<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>ID</t></si><si><r><t>Agency</t></r><r><t> name</t></r></si></sst>');
    $zip->addFromString('xl/worksheets/sheet1.xml','<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="6"><c r="A6" t="s"><v>0</v></c><c r="B6" t="s"><v>1</v></c><c r="C6" t="inlineStr"><is><t>Email</t></is></c></row><row r="7"><c r="A7"><v>1</v></c><c r="C7"><f>CONCAT("test")</f><v>test</v></c></row></sheetData></worksheet>');
    $zip->close();
    $data = UploadReader::read($xlsx,'agencies.xlsx');
    check($data[0]['title'] === 'Travel agencies','Tab title missing');
    check(count($data[0]['rows']) === 7 && $data[0]['rows'][5][0] === 'ID','Header row offset lost');
    check($data[0]['rows'][5][1] === 'Agency name','Rich shared strings broken');
    check($data[0]['rows'][6][1] === '' && $data[0]['rows'][6][2] === '=CONCAT("test")','Sparse cells or formulas lost');
    $zip->open($xlsx); $zip->addFromString('xl/sharedStrings.xml','<!DOCTYPE x [<!ENTITY test SYSTEM "file:///private">]><sst/>'); $zip->close();
    rejects(fn()=>UploadReader::read($xlsx,'unsafe.xlsx'));
    echo "$checks upload checks passed.\n";
} finally { unlink($csv); unlink($xlsx); }
