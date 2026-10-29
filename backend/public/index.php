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
if (str_starts_with($path, '/assets/') && is_file($root . '/public' . $path)) {
    $ext = pathinfo($path, PATHINFO_EXTENSION);
    $mime = match ($ext) {
        'css' => 'text/css',
        'js' => 'application/javascript',
        'png' => 'image/png',
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
        default => 'application/octet-stream',
    };
    header('Content-Type: ' . $mime);
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
    $adminEmail = getenv('MAIL_FROM_ADDRESS') ?: getenv('SMTP_USERNAME') ?: '';
    respond([
        'csrf' => $_SESSION['csrf'],
        'authenticated' => !empty($_SESSION['authenticated']),
        'configured' => $passwordHash !== '',
        'setup_allowed' => $passwordHash === '' && AdminSetup::isLocal($_SERVER),
        'admin_email' => $adminEmail,
        'is_local' => AdminSetup::isLocal($_SERVER),
        'google_notice' => $googleNotice
    ]);
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
if ($path === '/api/password-reset/request' && $method === 'POST') {
    try {
        $input = json_decode(file_get_contents('php://input'), true, 8, JSON_THROW_ON_ERROR);
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Please enter a valid email address.');
        }

        $adminEmail = strtolower(trim((string) (getenv('MAIL_FROM_ADDRESS') ?: getenv('SMTP_USERNAME') ?: '')));
        if ($adminEmail !== '' && $email !== $adminEmail) {
            throw new InvalidArgumentException('Email does not match the configured administrator address.');
        }

        $code = sprintf('%06d', random_int(100000, 999999));
        $_SESSION['password_reset'] = [
            'email' => $email,
            'code_hash' => password_hash($code, PASSWORD_DEFAULT),
            'expires_at' => time() + 900,
            'attempts' => 0
        ];

        $subject = 'RSL Communication Desk — Password Reset Code';
        $html = '<div style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Arial,sans-serif;max-width:540px;margin:20px auto;padding:28px;border:1px solid #e0e6df;border-radius:12px;background:#ffffff;color:#202124;">'
            . '<div style="display:flex;align-items:center;gap:10px;margin-bottom:16px;">'
            . '<div style="background:#142e25;color:#ffffff;font-weight:700;font-size:18px;width:36px;height:36px;border-radius:8px;text-align:center;line-height:36px;">R</div>'
            . '<div style="font-size:14px;font-weight:700;color:#142e25;letter-spacing:1px;">RSL WORKSPACE</div>'
            . '</div>'
            . '<h2 style="font-size:22px;color:#202124;margin:0 0 12px;font-weight:600;">Password Reset Request</h2>'
            . '<p style="color:#5f6368;font-size:14px;line-height:1.6;margin:0 0 20px;">You requested to reset your administrator password for <strong>' . htmlspecialchars($email) . '</strong>.</p>'
            . '<div style="background:#f4f8f5;border:1px dashed #285c43;padding:20px;text-align:center;border-radius:8px;margin:24px 0;">'
            . '<div style="font-size:11px;color:#5f6368;text-transform:uppercase;letter-spacing:1.5px;margin-bottom:8px;font-weight:600;">6-Digit Verification Code</div>'
            . '<span style="font-size:36px;font-weight:700;letter-spacing:8px;color:#142e25;font-family:monospace;">' . $code . '</span>'
            . '</div>'
            . '<p style="color:#5f6368;font-size:13px;line-height:1.6;margin:0 0 16px;">This code is valid for <strong>15 minutes</strong>. If you did not make this request, please disregard this email.</p>'
            . '<hr style="border:0;border-top:1px solid #edf0eb;margin:24px 0;">'
            . '<div style="font-size:11px;color:#8a968d;text-align:center;">RSL Solution Pvt Ltd · Communication Desk</div>'
            . '</div>';
        $text = "RSL Communication Desk - Password Reset Code\n\nYour 6-digit verification code is: $code\n\nValid for 15 minutes. If you did not request this, please ignore.";

        $sent = false;
        try {
            $mailer = new \App\Services\Email\Mailer();
            $sent = $mailer->sendRaw($email, $subject, $html, $text);
        } catch (\Throwable $e) {
            if ((getenv('APP_DEBUG') ?: 'false') === 'true' || AdminSetup::isLocal($_SERVER)) {
                respond(['ok' => true, 'message' => 'Verification code generated.', 'dev_code' => $code]);
            }
            throw new RuntimeException('Failed to send reset email. Verify SMTP settings in backend/.env: ' . $e->getMessage());
        }

        $res = ['ok' => true, 'message' => 'Verification code sent to ' . $email . '.'];
        if ((getenv('APP_DEBUG') ?: 'false') === 'true' || AdminSetup::isLocal($_SERVER)) {
            $res['dev_code'] = $code;
        }
        respond($res);
    } catch (InvalidArgumentException|JsonException $e) {
        respond(['error' => $e->getMessage()], 422);
    } catch (Throwable $e) {
        respond(['error' => $e->getMessage() ?: 'Failed to send reset code.'], 500);
    }
}
if ($path === '/api/password-reset/verify' && $method === 'POST') {
    try {
        $input = json_decode(file_get_contents('php://input'), true, 8, JSON_THROW_ON_ERROR);
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $code = trim((string) ($input['code'] ?? ''));
        $newPass = (string) ($input['password'] ?? '');
        $confirm = (string) ($input['confirmation'] ?? '');

        if ($newPass !== $confirm) throw new InvalidArgumentException('Passwords do not match.');
        if (mb_strlen($newPass, 'UTF-8') < 6) throw new InvalidArgumentException('Use a password of at least 6 characters.');
        if (mb_strlen($newPass, 'UTF-8') > 64) throw new InvalidArgumentException('Password cannot exceed 64 characters.');

        $reset = $_SESSION['password_reset'] ?? null;
        if (!$reset || empty($reset['code_hash'])) {
            throw new InvalidArgumentException('No active password reset request. Please request a new code.');
        }
        if (time() > ($reset['expires_at'] ?? 0)) {
            unset($_SESSION['password_reset']);
            throw new InvalidArgumentException('Verification code expired. Please request a new code.');
        }
        if (($reset['attempts'] ?? 0) >= 5) {
            unset($_SESSION['password_reset']);
            throw new InvalidArgumentException('Too many incorrect attempts. Please request a new code.');
        }
        if ($email !== '' && $email !== ($reset['email'] ?? '')) {
            throw new InvalidArgumentException('Email address mismatch.');
        }
        if (!password_verify($code, $reset['code_hash'])) {
            $_SESSION['password_reset']['attempts'] = ($reset['attempts'] ?? 0) + 1;
            throw new InvalidArgumentException('Invalid 6-digit verification code.');
        }

        $envFile = dirname(__DIR__) . '/.env';
        $contents = file_get_contents($envFile);
        $newHash = password_hash($newPass, PASSWORD_DEFAULT);
        if (preg_match('/^ADMIN_PASSWORD_HASH=/m', $contents)) {
            $updated = preg_replace('/^ADMIN_PASSWORD_HASH=.*$/m', 'ADMIN_PASSWORD_HASH=' . $newHash, $contents);
        } else {
            $updated = $contents . "\nADMIN_PASSWORD_HASH=" . $newHash . "\n";
        }
        file_put_contents($envFile, $updated);

        unset($_SESSION['password_reset']);
        session_regenerate_id(true);
        $_SESSION['authenticated'] = true;
        respond(['ok' => true]);
    } catch (InvalidArgumentException|JsonException $e) {
        respond(['error' => $e->getMessage()], 422);
    } catch (Throwable $e) {
        respond(['error' => 'Failed to reset administrator password: ' . $e->getMessage()], 500);
    }
}
if ($path === '/api/reset-password' && $method === 'POST') {
    if (!AdminSetup::isLocal($_SERVER)) respond(['error' => 'Local password reset is only available on this computer. Use email verification instead.'], 403);
    try {
        $input = json_decode(file_get_contents('php://input'), true, 8, JSON_THROW_ON_ERROR);
        $newPass = (string) ($input['password'] ?? '');
        $confirm = (string) ($input['confirmation'] ?? '');
        if ($newPass !== $confirm) throw new InvalidArgumentException('Passwords do not match.');
        if (mb_strlen($newPass, 'UTF-8') < 6) throw new InvalidArgumentException('Use a password of at least 6 characters.');
        if (mb_strlen($newPass, 'UTF-8') > 64) throw new InvalidArgumentException('Password cannot exceed 64 characters.');

        $envFile = dirname(__DIR__) . '/.env';
        $contents = file_get_contents($envFile);
        $newHash = password_hash($newPass, PASSWORD_DEFAULT);
        if (preg_match('/^ADMIN_PASSWORD_HASH=/m', $contents)) {
            $updated = preg_replace('/^ADMIN_PASSWORD_HASH=.*$/m', 'ADMIN_PASSWORD_HASH=' . $newHash, $contents);
        } else {
            $updated = $contents . "\nADMIN_PASSWORD_HASH=" . $newHash . "\n";
        }
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
    if ($path === '/api/validate-recipient' && $method === 'GET') {
        $email = trim((string) ($_GET['email'] ?? ''));
        if ($email === '') {
            respond(['valid' => false, 'error' => 'Email is required.'], 400);
        }
        $cleanEmail = trim(preg_replace('/^[`\'"\s\x{00a0}\x{200b}]+|[`\'"\s\x{00a0}\x{200b}]+$/u', '', $email));
        if (!filter_var($cleanEmail, FILTER_VALIDATE_EMAIL)) {
            respond(['valid' => false, 'error' => 'Invalid email format (e.g. name@domain.com)'], 200);
        }
        $parts = explode('@', $cleanEmail);
        $domain = end($parts);
        if (!$domain || !str_contains($domain, '.')) {
            respond(['valid' => false, 'error' => 'Incomplete domain.'], 200);
        }
        $hasMx = false;
        try {
            $hasMx = @checkdnsrr($domain, 'MX') || @checkdnsrr($domain, 'A');
        } catch (\Throwable) {
            $hasMx = true;
        }
        if (!$hasMx) {
            respond([
                'valid' => false,
                'domain' => $domain,
                'has_mx' => false,
                'error' => "No mail server found on @{$domain} (will bounce)"
            ], 200);
        }
        respond([
            'valid' => true,
            'email' => $cleanEmail,
            'domain' => $domain,
            'has_mx' => true,
            'message' => "Mail server active on @{$domain}"
        ], 200);
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
        
        $matchedReplies = (int) ($db->query("
            SELECT COUNT(DISTINCT LOWER(TRIM(e.recipient)))
            FROM emails e
            INNER JOIN inbox_emails i ON LOWER(TRIM(e.recipient)) = LOWER(TRIM(i.from_email))
            WHERE e.status = 'sent'
        ")->fetchColumn() ?: 0);
        $totalRecipients = (int) ($db->query("SELECT COUNT(DISTINCT LOWER(TRIM(recipient))) FROM emails WHERE status = 'sent'")->fetchColumn() ?: 0);
        $responseRate = $totalRecipients > 0 ? min(100.0, round(($matchedReplies / $totalRecipients) * 100, 1)) : 0.0;

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

        // Dynamic peak communication window
        $peakHourRow = $db->query("
            SELECT HOUR(COALESCE(received_at, created_at)) as h, COUNT(*) as c
            FROM inbox_emails
            WHERE COALESCE(received_at, created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
            GROUP BY h
            ORDER BY c DESC
            LIMIT 1
        ")->fetch(PDO::FETCH_ASSOC);

        $peakWindow = '3:00 PM – 5:00 PM';
        if ($peakHourRow && isset($peakHourRow['h']) && (int) $peakHourRow['c'] > 0) {
            $h = (int) $peakHourRow['h'];
            $startPeriod = $h >= 12 ? 'PM' : 'AM';
            $h12 = $h % 12 ?: 12;
            $hEnd = ($h + 2) % 24;
            $endPeriod = $hEnd >= 12 ? 'PM' : 'AM';
            $hEnd12 = $hEnd % 12 ?: 12;
            $peakWindow = sprintf('%d:00 %s – %d:00 %s', $h12, $startPeriod, $hEnd12, $endPeriod);
        }

        // Top Engaged Contacts (Prioritize genuine leads over automated newsletters/jobalerts)
        $hotContacts = $db->query("
            SELECT i.id, i.from_name, i.from_email, i.snippet, i.body, i.html_body, i.received_at,
                   (SELECT COUNT(*) FROM emails e WHERE LOWER(TRIM(e.recipient)) = LOWER(TRIM(i.from_email))) +
                   (SELECT COUNT(*) FROM inbox_emails i2 WHERE LOWER(TRIM(i2.from_email)) = LOWER(TRIM(i.from_email))) AS thread_count
            FROM inbox_emails i
            INNER JOIN (
                SELECT MAX(id) as max_id FROM inbox_emails GROUP BY LOWER(TRIM(from_email))
            ) latest ON i.id = latest.max_id
            WHERE i.from_email NOT LIKE '%noreply%'
              AND i.from_email NOT LIKE '%no-reply%'
              AND i.from_email NOT LIKE '%jobalerts%'
              AND i.from_email NOT LIKE '%donotreply%'
              AND i.from_email NOT LIKE '%notification%'
              AND i.from_email NOT LIKE '%mailer-daemon%'
            ORDER BY i.id DESC
            LIMIT 5
        ")->fetchAll(PDO::FETCH_ASSOC);

        // If fewer than 5 contacts, backfill with most recent activity so list isn't empty
        if (count($hotContacts) < 5) {
            $excludeIds = !empty($hotContacts) ? implode(',', array_map('intval', array_column($hotContacts, 'id'))) : '0';
            $backfill = $db->query("
                SELECT i.id, i.from_name, i.from_email, i.snippet, i.body, i.html_body, i.received_at,
                       (SELECT COUNT(*) FROM emails e WHERE LOWER(TRIM(e.recipient)) = LOWER(TRIM(i.from_email))) +
                       (SELECT COUNT(*) FROM inbox_emails i2 WHERE LOWER(TRIM(i2.from_email)) = LOWER(TRIM(i.from_email))) AS thread_count
                FROM inbox_emails i
                INNER JOIN (
                    SELECT MAX(id) as max_id FROM inbox_emails GROUP BY LOWER(TRIM(from_email))
                ) latest ON i.id = latest.max_id
                WHERE i.id NOT IN ($excludeIds)
                ORDER BY i.id DESC
                LIMIT " . (5 - count($hotContacts))
            )->fetchAll(PDO::FETCH_ASSOC);
            $hotContacts = array_merge($hotContacts, $backfill);
        }

        // Clean snippets using ConversationThread quote separation & cleanSnippet
        foreach ($hotContacts as &$c) {
            $rawSource = !empty($c['snippet']) ? $c['snippet'] : (!empty($c['body']) ? $c['body'] : ($c['html_body'] ?? ''));
            $split = ConversationThread::splitQuotedText((string) $rawSource);
            $cleanText = !empty($split['main']) ? $split['main'] : (string) $rawSource;
            $cleaned = ImapClient::cleanSnippet($cleanText, 85);
            $c['snippet'] = ($cleaned !== '' && $cleaned !== '.') ? $cleaned : 'Message received';
            $c['from_name'] = $c['from_name'] ?: $c['from_email'];
            unset($c['body'], $c['html_body']);
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
            'peak_window' => $peakWindow,
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
