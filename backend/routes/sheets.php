<?php
// Included after authentication, CSRF validation, and database initialization.
declare(strict_types=1);
use App\Services\Google\GoogleClient;
use App\Services\Sheets\SheetService;
use App\Services\Sheets\UploadedWorkbook;
use App\Services\Sheets\UploadReader;

$google = new GoogleClient($db);
$uploads = new UploadedWorkbook($db);
$sheets = new SheetService($db, fn($method, $path, $body) => str_starts_with(rawurldecode($path), 'upload:') ? $uploads->request($method, $path, $body) : $google->request($method, $path, $body));
try {
    if ($path === '/api/sheets/upload' && $method === 'POST') {
        $file = $_FILES['file'] ?? null;
        if (!$file || !is_array($file) || !isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) throw new InvalidArgumentException('Upload failed. Choose a file within the server upload limit (maximum 5 MB).');
        if (!is_string($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) throw new InvalidArgumentException('Invalid file upload.');
        if ($file['size'] > UploadReader::MAX_BYTES) respond(['error' => 'Upload a file no larger than 5 MB.'], 413);
        $id = $uploads->import($file['tmp_name'], $file['name']);
        respond(['upload_id' => $id, 'metadata' => $sheets->metadata($id)], 201);
    }
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 300000) respond(['error' => 'Request too large.'], 413);
    $input = $method === 'POST' ? json_decode(file_get_contents('php://input'), true, 32, JSON_THROW_ON_ERROR) : [];
    if (!is_array($input)) throw new InvalidArgumentException('Invalid request.');
    if ($path === '/api/google/status' && $method === 'GET') respond(['configured' => $google->configured(), 'connected' => $google->connected(), 'upload_limit' => UploadReader::serverLimit()]);
    if ($path === '/api/google/connect' && $method === 'POST') {
        $state = bin2hex(random_bytes(32));
        $envRedirect = getenv('GOOGLE_REDIRECT_URI');
        if ($envRedirect && trim($envRedirect) !== '') {
            $redirectUri = trim($envRedirect);
        } else {
            $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
                || (isset($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on');
            $scheme = $isHttps ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'mailpilot.rslsolution.com';
            $redirectUri = $scheme . '://' . $host . '/auth/google/callback';
        }
        $url = $google->authorizationUrl($state, $redirectUri);
        $_SESSION['google_oauth'] = ['state' => $state, 'expires' => time() + 600, 'redirect_uri' => $redirectUri];
        respond(['url' => $url]);
    }
    if ($path === '/api/google/disconnect' && $method === 'POST') { $google->disconnect(); respond(['ok' => true]); }
    if ($path === '/api/sheets' && $method === 'GET') respond(['sources' => $sheets->sources()]);
    if ($path === '/api/sheets/inspect' && $method === 'POST') {
        $id = SheetService::inputId($input);
        if (!isset($input['sheet_id'])) respond($sheets->metadata($id));
        $data = $sheets->inspect($id, (int) $input['sheet_id'], (int) ($input['header_row'] ?? 1));
        respond(['title' => $data['title'], 'headers' => $data['headers'], 'sample' => array_slice($data['values'], 0, 3)]);
    }
    if ($path === '/api/sheets' && $method === 'POST') respond(['id' => $sheets->save($input)], 201);
    if (preg_match('#^/api/sheets/(\d+)(?:/(edit|queue|send|batch-send|mapping))?$#', $path, $matches)) {
        $sourceId = (int) $matches[1]; $action = $matches[2] ?? '';
        if ($method === 'DELETE') { $sheets->delete($sourceId); respond(['ok' => true]); }
        if ($method === 'GET' && $action === '') respond($sheets->preview($sourceId));
        if ($method === 'POST' && $action === 'edit') { $sheets->edit($sourceId, $input); respond(['ok' => true]); }
        if ($method === 'POST' && ($action === 'send' || $action === 'batch-send' || $action === 'queue')) {
            $sessionUser = getSessionUser();
            if (!empty($sessionUser) && ($sessionUser['role'] ?? '') === 'member' && !empty($sessionUser['assigned_account_id'])) {
                $input['mail_account_id'] = (int) $sessionUser['assigned_account_id'];
            }
        }
        if ($method === 'POST' && $action === 'queue') respond(['id' => $sheets->enqueue($sourceId, $input)]);
        if ($method === 'POST' && $action === 'send') respond($sheets->send($sourceId, $input));
        if ($method === 'POST' && $action === 'batch-send') respond($sheets->batchSend($sourceId, $input));
        if ($method === 'POST' && $action === 'mapping') respond(['id' => $sheets->save($input, $sourceId)]);
    }
    respond(['error' => 'Endpoint not found.'], 404);
} catch (PDOException $e) {
    error_log('Google Sheets database operation failed.');
    respond(['error' => 'Unable to save source settings. Check the database connection.'], 500);
} catch (RuntimeException $e) {
    respond(['error' => $e->getMessage()], 409);
}
