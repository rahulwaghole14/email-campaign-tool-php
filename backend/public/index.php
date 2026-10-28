<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

use App\Support\Database;
use App\Services\Email\EmailQueue;
use App\Services\Email\EmailHistory;
use App\Services\Email\ImapClient;
use App\Services\Email\ConversationThread;
use App\Services\AdminSetup;
use App\Services\Google\GoogleClient;
use App\Services\Sheets\SheetService;

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$root = dirname(__DIR__, 2) . '/frontend';
if (in_array($path, ['/assets/app.css', '/assets/app.js', '/assets/sheets.js', '/assets/inbox.js'], true)) {
    header('Content-Type: ' . (str_ends_with($path, '.css') ? 'text/css' : 'application/javascript'));
    readfile($root . '/public' . $path); exit;
}
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
session_start();
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; frame-src 'self' data: about:; object-src 'none'; base-uri 'self'; frame-ancestors 'none'");
header('Cache-Control: no-store');
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));

if ($path === '/auth/google/callback') {
    $pending = $_SESSION['google_oauth'] ?? []; unset($_SESSION['google_oauth']);
    try {
        if (empty($_SESSION['authenticated']) || empty($pending['state']) || ($pending['expires'] ?? 0) < time()
            || !hash_equals($pending['state'], (string) ($_GET['state'] ?? ''))) throw new RuntimeException('Google connection expired. Sign in and try again.');
        if (!empty($_GET['error']) || empty($_GET['code'])) throw new RuntimeException('Google access was not granted.');
        $db = Database::connect(); Database::migrate($db);
        (new GoogleClient($db))->exchange((string) $_GET['code'], $pending['redirect_uri'] ?? null);
        $_SESSION['google_notice'] = ['message' => 'Google connected. You can now add and edit sheets.', 'error' => false];
    } catch (Throwable $e) {
        $_SESSION['google_notice'] = ['message' => $e instanceof RuntimeException ? $e->getMessage() : 'Google connection failed. Check configuration and try again.', 'error' => true];
    }
    header('Location: /#sheets', true, 303); exit;
}

if (!str_starts_with($path, '/api/')) {
    if ($path !== '/') { http_response_code(404); exit('Not found'); }
    require $root . '/resources/views/dashboard/index.php'; exit;
}

header('Content-Type: application/json; charset=utf-8');
function respond(array $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($data, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
$method = $_SERVER['REQUEST_METHOD'];
$passwordHash = getenv('ADMIN_PASSWORD_HASH') ?: '';
if ($method !== 'GET' && !hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) respond(['error' => 'Session expired. Refresh the page.'], 403);
if ($path === '/api/session' && $method === 'GET') {
    $googleNotice = $_SESSION['google_notice'] ?? null; unset($_SESSION['google_notice']);
    respond(['csrf' => $_SESSION['csrf'], 'authenticated' => !empty($_SESSION['authenticated']), 'configured' => $passwordHash !== '', 'setup_allowed' => $passwordHash === '' && AdminSetup::isLocal($_SERVER), 'google_notice' => $googleNotice]);
}
if ($path === '/api/setup' && $method === 'POST') {
    if ($passwordHash !== '' || !AdminSetup::isLocal($_SERVER)) respond(['error' => 'Setup is only available locally before an administrator is configured.'], 403);
    try {
        $input = json_decode(file_get_contents('php://input'), true, 8, JSON_THROW_ON_ERROR);
        AdminSetup::create(dirname(__DIR__) . '/.env', (string) ($input['password'] ?? ''), (string) ($input['confirmation'] ?? ''));
        session_regenerate_id(true);
        $_SESSION['authenticated'] = true;
        respond(['ok' => true]);
    } catch (InvalidArgumentException|JsonException $e) {
        respond(['error' => $e->getMessage()], 422);
    } catch (Throwable $e) {
        respond(['error' => 'Unable to save administrator configuration. Check backend/.env permissions.'], 500);
    }
}
if ($path === '/api/login' && $method === 'POST') {
    if (!$passwordHash) respond(['error' => 'Set ADMIN_PASSWORD_HASH in backend/.env first.'], 503);
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    if (!password_verify((string) ($input['password'] ?? ''), $passwordHash)) { usleep(500000); respond(['error' => 'Invalid password.'], 401); }
    session_regenerate_id(true);
    $_SESSION['authenticated'] = true;
    respond(['ok' => true]);
}
if ($path === '/api/reset-password' && $method === 'POST') {
    if (!AdminSetup::isLocal($_SERVER)) respond(['error' => 'Password reset is only available locally on this computer.'], 403);
    try {
        $input = json_decode(file_get_contents('php://input'), true, 8, JSON_THROW_ON_ERROR);
        $newPass = (string) ($input['password'] ?? '');
        $confirm = (string) ($input['confirmation'] ?? '');
        if ($newPass !== $confirm) throw new InvalidArgumentException('Passwords do not match.');
        if (mb_strlen($newPass, 'UTF-8') < 6 || mb_strlen($newPass, 'UTF-8') > 8) throw new InvalidArgumentException('Use a password between 6 and 8 characters.');

        $envFile = dirname(__DIR__) . '/.env';
        $contents = file_get_contents($envFile);
        $newHash = password_hash($newPass, PASSWORD_DEFAULT);
        $updated = preg_replace('/^ADMIN_PASSWORD_HASH=.*$/m', 'ADMIN_PASSWORD_HASH=' . $newHash, $contents);
        file_put_contents($envFile, $updated);

        session_regenerate_id(true);
        $_SESSION['authenticated'] = true;
        respond(['ok' => true]);
    } catch (InvalidArgumentException|JsonException $e) {
        respond(['error' => $e->getMessage()], 422);
    } catch (Throwable $e) {
        respond(['error' => 'Failed to reset administrator password.'], 500);
    }
}
if (empty($_SESSION['authenticated'])) respond(['error' => 'Please sign in.'], 401);
if ($path === '/api/logout' && $method === 'POST') { $_SESSION = []; session_destroy(); respond(['ok' => true]); }
try {
    $db = Database::connect();
    Database::migrate($db);
    $queue = new EmailQueue($db);
    $history = new EmailHistory($db);
    $imap = new ImapClient($db);
    if ($path === '/api/inbox' && $method === 'GET') respond($imap->listing($_GET));
    if ($path === '/api/inbox/sync' && $method === 'POST') respond($imap->sync(50));
    if (preg_match('#^/api/inbox/(\d+)$#', $path, $match) && $method === 'GET') {
        $detail = $imap->detail((int) $match[1]);
        if (!$detail) respond(['error' => 'Email not found.'], 404);
        respond($detail);
    }
    if (preg_match('#^/api/inbox/(\d+)/toggle-read$#', $path, $match) && $method === 'POST') {
        respond(['ok' => $imap->toggleRead((int) $match[1])]);
    }
    if ($path === '/api/emails' && $method === 'GET') respond($history->listing($_GET));
    if (preg_match('#^/api/emails/(\d+)$#', $path, $match) && $method === 'GET') {
        $detail = $history->detail((int) $match[1]);
        if (!$detail) respond(['error'=>'Email not found.'], 404);
        respond($detail);
    }
    if (str_starts_with($path, '/api/google/') || str_starts_with($path, '/api/sheets')) {
        require dirname(__DIR__) . '/routes/sheets.php';
    }
    if ($path === '/api/dashboard' && $method === 'GET') {
        $counts = ['pending' => 0, 'sent' => 0, 'failed' => 0, 'dry_run' => 0, 'processing' => 0];
        foreach ($db->query('SELECT status, COUNT(*) AS total FROM emails GROUP BY status') as $row) $counts[$row['status']] = (int) $row['total'];
        $inboxTotal = (int) ($db->query('SELECT COUNT(*) FROM inbox_emails')->fetchColumn() ?: 0);
        $inboxUnread = (int) ($db->query('SELECT COUNT(*) FROM inbox_emails WHERE is_read = 0')->fetchColumn() ?: 0);
        
        $totalSent = $counts['sent'];
        $totalFailed = $counts['failed'];
        $deliveryRate = ($totalSent + $totalFailed) > 0 ? round(($totalSent / ($totalSent + $totalFailed)) * 100, 1) : 100.0;
        
        $repliedContacts = (int) ($db->query("SELECT COUNT(DISTINCT LOWER(from_email)) FROM inbox_emails")->fetchColumn() ?: 0);
        $totalRecipients = (int) ($db->query("SELECT COUNT(DISTINCT LOWER(recipient)) FROM emails WHERE status = 'sent'")->fetchColumn() ?: 0);
        $responseRate = $totalRecipients > 0 ? min(100.0, round(($repliedContacts / $totalRecipients) * 100, 1)) : 0.0;

        // 7-day trend
        $days = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-$i days"));
            $days[$date] = [
                'day' => date('D', strtotime($date)),
                'date' => $date,
                'sent' => 0,
                'received' => 0
            ];
        }
        $sentRows = $db->query("SELECT DATE(created_at) as d, COUNT(*) as c FROM emails WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) GROUP BY d")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($sentRows as $r) {
            if (isset($days[$r['d']])) $days[$r['d']]['sent'] = (int) $r['c'];
        }
        $inboxRows = $db->query("SELECT DATE(COALESCE(received_at, created_at)) as d, COUNT(*) as c FROM inbox_emails WHERE COALESCE(received_at, created_at) >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) GROUP BY d")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($inboxRows as $r) {
            if (isset($days[$r['d']])) $days[$r['d']]['received'] = (int) $r['c'];
        }

        // Top Engaged / Hot Contacts
        $hotContacts = $db->query("
            SELECT i.id, i.from_name, i.from_email, i.snippet, i.received_at,
                   (SELECT COUNT(*) FROM emails e WHERE LOWER(e.recipient) = LOWER(i.from_email)) +
                   (SELECT COUNT(*) FROM inbox_emails i2 WHERE LOWER(i2.from_email) = LOWER(i.from_email)) AS thread_count
            FROM inbox_emails i
            INNER JOIN (
                SELECT MAX(id) as max_id FROM inbox_emails GROUP BY LOWER(from_email)
            ) latest ON i.id = latest.max_id
            ORDER BY i.id DESC
            LIMIT 5
        ")->fetchAll(PDO::FETCH_ASSOC);

        // Clean snippets using ConversationThread quote separation
        foreach ($hotContacts as &$c) {
            $split = ConversationThread::splitQuotedText((string) ($c['snippet'] ?? ''));
            $cleanText = !empty($split['main']) ? $split['main'] : (string) ($c['snippet'] ?? '');
            $c['snippet'] = mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags($cleanText))), 0, 85);
            $c['from_name'] = $c['from_name'] ?: $c['from_email'];
        }
        unset($c);

        $sheetsCount = (int) ($db->query("SELECT (SELECT COUNT(*) FROM sheet_sources) + (SELECT COUNT(*) FROM uploaded_sheets)")->fetchColumn() ?: 0);

        respond([
            'counts' => array_merge($counts, [
                'inbox_total' => $inboxTotal,
                'inbox_unread' => $inboxUnread,
                'delivery_rate' => $deliveryRate,
                'response_rate' => $responseRate
            ]),
            'inbox_unread' => $inboxUnread,
            'traffic_7d' => array_values($days),
            'hot_contacts' => $hotContacts,
            'system_health' => [
                'smtp_host' => 'smtp.hostinger.com:465',
                'smtp_user' => getenv('SMTP_USERNAME') ?: 'ray@rslsolution.com',
                'imap_host' => 'imap.hostinger.com:993',
                'imap_total' => $inboxTotal,
                'sheets_count' => $sheetsCount
            ],
            'dry_run' => (getenv('MAIL_DRY_RUN') ?: 'true') !== 'false'
        ]);
    }
    if ($path === '/api/attachments' && $method === 'POST') {
        if (empty($_FILES['file'])) {
            respond(['error' => 'No file was uploaded.'], 400);
        }
        $file = $_FILES['file'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            respond(['error' => 'File upload failed with error code ' . $file['error']], 400);
        }
        $maxBytes = 25 * 1024 * 1024;
        if ($file['size'] > $maxBytes) {
            respond(['error' => 'File exceeds maximum allowed size of 25 MB.'], 413);
        }
        $originalName = (string) ($file['name'] ?? 'attachment');
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $blocked = ['php', 'phtml', 'php3', 'php4', 'php5', 'phps', 'phar', 'exe', 'bat', 'cmd', 'sh', 'bash', 'vbs', 'ps1', 'msi'];
        if (in_array($extension, $blocked, true)) {
            respond(['error' => 'This file type is not allowed for security reasons.'], 400);
        }
        $storageDir = dirname(__DIR__) . '/storage/app/attachments';
        if (!is_dir($storageDir)) {
            mkdir($storageDir, 0755, true);
        }
        $safeBase = preg_replace('/[^a-zA-Z0-9._-]/', '_', $originalName);
        $uniqueName = bin2hex(random_bytes(8)) . '_' . $safeBase;
        $destPath = $storageDir . '/' . $uniqueName;
        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            respond(['error' => 'Failed to save uploaded file.'], 500);
        }
        respond([
            'id' => $uniqueName,
            'name' => $originalName,
            'size' => (int) $file['size'],
            'type' => (string) ($file['type'] ?? 'application/octet-stream'),
            'path' => $destPath
        ], 201);
    }
    if ($path === '/api/emails' && $method === 'POST') {
        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 500000) respond(['error' => 'Request is too large.'], 413);
        $input = json_decode(file_get_contents('php://input'), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($input)) throw new InvalidArgumentException('Invalid request.');
        $id = $queue->enqueue($input);
        // Dispatch immediately over SMTP
        $processor = new \App\Services\Email\EmailProcessor($db);
        $result = $processor->processOne($id);
        respond(['id' => $id, 'status' => $result['status'] ?? 'sent'], 201);
    }
    if (preg_match('#^/api/emails/(\d+)/retry$#', $path, $match) && $method === 'POST') {
        $queue->retry((int) $match[1]); respond(['ok' => true]);
    }
    respond(['error' => 'Endpoint not found.'], 404);
} catch (InvalidArgumentException|JsonException $e) {
    respond(['error' => $e->getMessage()], 422);
} catch (Throwable $e) {
    error_log($e->getMessage() . "\n" . $e->getTraceAsString());
    $isDebug = (getenv('APP_DEBUG') === 'true' || getenv('APP_ENV') === 'local');
    $errMsg = $isDebug
        ? $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')'
        : 'Unable to complete the request. Check the backend configuration and server logs.';
    respond(['error' => $errMsg], 500);
}
