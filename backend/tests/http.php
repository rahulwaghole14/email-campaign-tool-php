<?php
declare(strict_types=1);
// Isolated smoke test: never uses the application database or changes its password.
$root = dirname(__DIR__);
$database = tempnam(sys_get_temp_dir(), 'email-http-');
$cookies = tempnam(sys_get_temp_dir(), 'email-cookie-');
$log = tempnam(sys_get_temp_dir(), 'email-server-');
$uploadFile = tempnam(sys_get_temp_dir(), 'email-upload-');
file_put_contents($uploadFile, "ID,Email,Subject,Message\nU1,upload@example.com,Upload test,Original body\n");
$password = bin2hex(random_bytes(16));
$env = array_merge(getenv(), ['ADMIN_PASSWORD_HASH' => password_hash($password, PASSWORD_DEFAULT), 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database, 'MAIL_DRY_RUN' => 'true']);
$server = proc_open([PHP_BINARY, '-S', '127.0.0.1:18085', '-t', $root . '/public', $root . '/public/index.php'], [0 => ['pipe','r'], 1 => ['file',$log,'a'], 2 => ['file',$log,'a']], $pipes, $root, $env);
if (!is_resource($server)) throw new RuntimeException('Could not start test server.');
$csrf = '';
function request(string $path, ?array $body = null, int $expected = 200): array {
    global $cookies, $csrf;
    $curl = curl_init('http://127.0.0.1:18085/api/' . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $cookies, CURLOPT_COOKIEJAR => $cookies, CURLOPT_TIMEOUT => 5]);
    if ($body !== null) curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body), CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf]]);
    $raw = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    if ($status !== $expected) throw new RuntimeException("Unexpected HTTP status for $path: $status");
    return json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
}
function uploadRequest(int $expected = 201): array {
    global $cookies, $csrf, $uploadFile;
    $curl = curl_init('http://127.0.0.1:18085/api/sheets/upload');
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $cookies, CURLOPT_COOKIEJAR => $cookies,
        CURLOPT_TIMEOUT => 5, CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['X-CSRF-Token: ' . $csrf],
        CURLOPT_POSTFIELDS => ['file' => new CURLFile($uploadFile, 'text/csv', 'contacts.csv')]]);
    $raw = curl_exec($curl); $status = curl_getinfo($curl, CURLINFO_HTTP_CODE); curl_close($curl);
    if ($status !== $expected) throw new RuntimeException('Unexpected upload HTTP status: ' . $status);
    return json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
}
try {
    $ready = false;
    for ($i = 0; $i < 30; $i++) {
        $socket = @fsockopen('127.0.0.1', 18085);
        if ($socket) { fclose($socket); $ready = true; break; }
        usleep(100000);
    }
    if (!$ready) throw new RuntimeException('Test server did not start.');
    $session = request('session'); $csrf = $session['csrf'];
    request('dashboard', null, 401);
    request('sheets', null, 401);
    request('google/status', null, 401);
    uploadRequest(401);
    request('login', ['password' => 'wrong-password'], 401);
    request('login', ['password' => $password]);
    $google = request('google/status');
    if ($google['connected'] !== false) throw new RuntimeException('Isolated database unexpectedly has Google credentials.');
    $sources = request('sheets');
    if ($sources['sources'] !== []) throw new RuntimeException('Isolated database unexpectedly has sheet sources.');
    $inbox = request('inbox');
    if (!isset($inbox['emails']) || !isset($inbox['pagination'])) throw new RuntimeException('Inbox endpoint failed.');
    $uploaded = uploadRequest();
    $imported = request('sheets', ['upload_id'=>$uploaded['upload_id'], 'sheet_id'=>0, 'header_row'=>1,
        'mapping'=>['id'=>'ID','email'=>'Email','subject'=>'Subject','body'=>'Message']], 201);
    $preview = request('sheets/' . $imported['id']); $record = $preview['records'][0];
    request('sheets/' . $imported['id'] . '/edit', ['record_id'=>'U1','version'=>$record['version'],'changes'=>['body'=>'Edited upload']]);
    $preview = request('sheets/' . $imported['id']);
    if ($preview['records'][0]['values']['body'] !== 'Edited upload') throw new RuntimeException('Uploaded edit not saved through HTTP.');
    $sent = request('sheets/' . $imported['id'] . '/send', ['record_id'=>'U1','version'=>$preview['records'][0]['version']]);
    if ($sent['status'] !== 'dry_run') throw new RuntimeException('Single sheet email was not processed immediately.');
    $detail = request('emails/' . $sent['id']);
    if ($detail['email']['status'] !== 'dry_run' || $detail['email']['body'] !== 'Edited upload') throw new RuntimeException('Sent email detail is incorrect.');
    request('sheets/inspect', ['url' => 'https://example.com/not-a-sheet'], 422);
    request('sheets/999/edit', ['record_id' => 'test', 'version' => 'invalid', 'changes' => ['body' => 'test']], 422);
    request('setup', ['password' => $password, 'confirmation' => $password], 403);
    $input = ['recipient' => 'test@example.com', 'subject' => 'Isolated smoke test', 'body' => 'Not sent.', 'idempotency_key' => bin2hex(random_bytes(16))];
    $first = request('emails', $input, 201);
    $second = request('emails', $input, 201);
    if ($first['id'] !== $second['id']) throw new RuntimeException('Duplicate submission created another email.');
    $worker = proc_open([PHP_BINARY, $root . '/scripts/cron/process-emails.php'], [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $workerPipes, $root, $env);
    fclose($workerPipes[0]);
    $output = stream_get_contents($workerPipes[1]); fclose($workerPipes[1]);
    $error = stream_get_contents($workerPipes[2]); fclose($workerPipes[2]);
    if (proc_close($worker) !== 0) throw new RuntimeException('Worker failed: ' . $error);
    $dashboard = request('dashboard');
    if ($dashboard['counts']['dry_run'] !== 2 || $dashboard['counts']['sent'] !== 0) throw new RuntimeException('Dry-run email status is incorrect.');
    $savedCsrf = $csrf; $csrf = 'invalid';
    request('emails', $input, 403); $csrf = $savedCsrf;
    $csrf = 'invalid'; request('google/connect', [], 403); request('sheets/999/edit', [], 403); $csrf = $savedCsrf;
    $csrf = 'invalid'; uploadRequest(403); $csrf = $savedCsrf;
    request('logout', []);
    request('dashboard', null, 401);
    echo "HTTP sign-in, authorization, CSRF, duplicate submission, dry-run worker, and logout checks passed.\n";
} finally {
    proc_terminate($server);
    foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
    proc_close($server);
    foreach ([$database, $cookies, $log, $uploadFile] as $file) if (is_file($file)) unlink($file);
}
