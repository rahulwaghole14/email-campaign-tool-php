<?php
declare(strict_types=1);
ini_set('display_errors', '0');
error_reporting(E_ALL);
require dirname(__DIR__) . '/bootstrap.php';

use App\Support\Database;
use App\Services\Email\EmailQueue;
use App\Services\Email\EmailHistory;
use App\Services\Email\ImapClient;
use App\Services\Email\ConversationThread;
use App\Services\AdminSetup;
use App\Services\Google\GoogleClient;
use App\Services\Sheets\SheetService;
use App\Services\MailAccountService;
use App\Services\DashboardService;
use App\Services\UserService;

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
    $currentUser = null;
    if (!empty($_SESSION['authenticated'])) {
        $currentUser = [
            'id' => (int) ($_SESSION['user_id'] ?? 1),
            'name' => $_SESSION['user_name'] ?? 'Administrator',
            'email' => $_SESSION['user_email'] ?? $adminEmail,
            'role' => $_SESSION['user_role'] ?? 'admin',
            'assigned_account_id' => !empty($_SESSION['assigned_account_id']) ? (int) $_SESSION['assigned_account_id'] : null
        ];
    }
    respond([
        'csrf' => $_SESSION['csrf'],
        'authenticated' => !empty($_SESSION['authenticated']),
        'user' => $currentUser,
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
        $_SESSION['user_id'] = 1;
        $_SESSION['user_role'] = 'admin';
        $_SESSION['user_name'] = 'Administrator';
        $_SESSION['user_email'] = getenv('ADMIN_EMAIL') ?: (getenv('SMTP_USERNAME') ?: 'admin@rslsolution.com');
        respond(['ok' => true]);
    } catch (InvalidArgumentException|JsonException $e) {
        respond(['error' => $e->getMessage()], 422);
    } catch (Throwable $e) {
        respond(['error' => 'Unable to save administrator configuration. Check backend/.env permissions.'], 500);
    }
}
if ($path === '/api/login' && $method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $identifier = (string) ($input['email'] ?? ($input['username'] ?? ''));
    $password = (string) ($input['password'] ?? '');

    $user = null;
    if ($passwordHash && password_verify($password, $passwordHash)) {
        $user = [
            'id' => 1,
            'name' => 'Workspace Admin',
            'email' => getenv('ADMIN_EMAIL') ?: (getenv('SMTP_USERNAME') ?: 'admin@rslsolution.com'),
            'role' => 'admin',
            'assigned_account_id' => 1
        ];
    } else {
        try {
            $db = Database::connect();
            Database::migrate($db);
            $user = UserService::authenticate($db, $identifier, $password);
        } catch (\Throwable) {
            $user = null;
        }
    }

    if (!$user) {
        usleep(500000);
        respond(['error' => 'Invalid password.'], 401);
    }

    session_regenerate_id(true);
    $_SESSION['authenticated'] = true;
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['user_role'] = $user['role'];
    $_SESSION['user_name'] = $user['name'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['assigned_account_id'] = !empty($user['assigned_account_id']) ? (int) $user['assigned_account_id'] : null;

    respond([
        'ok' => true,
        'user' => [
            'id' => (int) $user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'role' => $user['role'],
            'assigned_account_id' => $_SESSION['assigned_account_id']
        ]
    ]);
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

        $subject = 'RSL Communication Desk â€” Password Reset Code';
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
            . '<div style="font-size:11px;color:#8a968d;text-align:center;">RSL Solution Pvt Ltd Â· Communication Desk</div>'
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
    $currentUser = [
        'id' => (int) ($_SESSION['user_id'] ?? 1),
        'name' => $_SESSION['user_name'] ?? 'Administrator',
        'email' => $_SESSION['user_email'] ?? (getenv('SMTP_USERNAME') ?: 'admin@rslsolution.com'),
        'role' => $_SESSION['user_role'] ?? 'admin',
        'assigned_account_id' => !empty($_SESSION['assigned_account_id']) ? (int) $_SESSION['assigned_account_id'] : null
    ];
    $queue = new EmailQueue($db);
    $history = new EmailHistory($db);
    $defaultMailAccount = MailAccountService::getDefault($db);
    $imap = new ImapClient($db, $defaultMailAccount);

    // Mail Accounts & Multi-Inbox Management
    if ($path === '/api/mail-accounts' && $method === 'GET') {
        respond(MailAccountService::getAll($db, false));
    }
    if ($path === '/api/mail-accounts' && $method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true, 16, JSON_THROW_ON_ERROR);
        $id = !empty($input['id']) ? (int) $input['id'] : null;
        respond(MailAccountService::save($db, $input, $id));
    }
    if ($path === '/api/mail-accounts/test' && $method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true, 16, JSON_THROW_ON_ERROR);
        if (!empty($input['id']) && empty($input['smtp_pass'])) {
            $existing = MailAccountService::getById($db, (int) $input['id'], true);
            if ($existing) {
                $input['smtp_pass'] = $existing['smtp_pass'];
                $input['imap_pass'] = $existing['imap_pass'];
            }
        }
        respond(MailAccountService::testConnection($input));
    }
    if (preg_match('#^/api/mail-accounts/(\d+)$#', $path, $match) && $method === 'DELETE') {
        MailAccountService::delete($db, (int) $match[1]);
        respond(['ok' => true]);
    }

    if ($path === '/api/inbox' && $method === 'GET') {
        respond($imap->listing($_GET));
    }
    if ($path === '/api/inbox/sync' && $method === 'POST') {
        $allAccounts = MailAccountService::getAll($db, true);
        $totalNew = 0;
        if (!empty($allAccounts)) {
            foreach ($allAccounts as $acct) {
                if ($acct['status'] !== 'active') continue;
                try {
                    $client = new ImapClient($db, $acct);
                    $res = $client->sync(30);
                    $totalNew += (int) ($res['new_count'] ?? 0);
                } catch (\Throwable $t) {
                    error_log("Inbox sync error for account {$acct['id']}: " . $t->getMessage());
                }
            }
            respond(['new_count' => $totalNew]);
        } else {
            respond($imap->sync(50));
        }
    }
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
    if ($path === '/api/emails' && $method === 'GET') {
        respond($history->listing($_GET));
    }
    if (preg_match('#^/api/emails/(\d+)$#', $path, $match) && $method === 'GET') {
        $detail = $history->detail((int) $match[1]);
        if (!$detail) respond(['error'=>'Email not found.'], 404);
        respond($detail);
    }
    if (str_starts_with($path, '/api/google/') || str_starts_with($path, '/api/sheets')) {
        require dirname(__DIR__) . '/routes/sheets.php';
    }
    if ($path === '/api/dashboard' && $method === 'GET') {
        $filterAccountId = !empty($_GET['account_id']) ? (int) $_GET['account_id'] : null;
        respond(DashboardService::snapshot($db, $filterAccountId));
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

        // Sender Mailbox Authorization
        $mailAccountId = !empty($input['mail_account_id']) ? (int) $input['mail_account_id'] : null;
        if ($currentUser['role'] === 'member') {
            if (!empty($currentUser['assigned_account_id'])) {
                $mailAccountId = (int) $currentUser['assigned_account_id'];
            }
        }
        if ($mailAccountId === null) {
            $defaultAcct = MailAccountService::getDefault($db);
            $mailAccountId = (int) ($defaultAcct['id'] ?? 1);
        }
        $input['mail_account_id'] = $mailAccountId;

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
