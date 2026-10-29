<?php
declare(strict_types=1);
namespace App\Services;

use PDO;
use RuntimeException;
use PHPMailer\PHPMailer\PHPMailer;

final class MailAccountService
{
    /**
     * Get all mail accounts.
     * Pass $includeSecrets = false for client API responses to strip passwords.
     */
    public static function getAll(PDO $db, bool $includeSecrets = false): array
    {
        $stmt = $db->query("SELECT * FROM mail_accounts ORDER BY is_default DESC, id ASC");
        $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$includeSecrets) {
            foreach ($accounts as &$a) {
                unset($a['smtp_pass'], $a['imap_pass']);
            }
            unset($a);
        }
        return $accounts;
    }

    /**
     * Get accessible accounts for a given user session/role.
     * Admins get all active accounts. Members get their assigned account.
     */
    public static function getForUser(PDO $db, array $user, bool $includeSecrets = false): array
    {
        $role = $user['role'] ?? 'member';
        if ($role === 'admin') {
            return self::getAll($db, $includeSecrets);
        }

        $assignedId = (int) ($user['assigned_account_id'] ?? 0);
        if ($assignedId > 0) {
            $stmt = $db->prepare("SELECT * FROM mail_accounts WHERE id = ? AND status = 'active'");
            $stmt->execute([$assignedId]);
            $acct = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($acct) {
                if (!$includeSecrets) unset($acct['smtp_pass'], $acct['imap_pass']);
                return [$acct];
            }
        }

        // For members, do not fallback to admin's default mailbox
        if ($role === 'member') {
            return [];
        }

        // Fallback to default active account if no assigned account
        $stmt = $db->query("SELECT * FROM mail_accounts WHERE is_default = 1 AND status = 'active' LIMIT 1");
        $default = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($default) {
            if (!$includeSecrets) unset($default['smtp_pass'], $default['imap_pass']);
            return [$default];
        }

        return [];
    }

    /**
     * Get a single mail account by ID.
     */
    public static function getById(PDO $db, int $id, bool $includeSecrets = false): ?array
    {
        $stmt = $db->prepare("SELECT * FROM mail_accounts WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        if (!$includeSecrets) {
            unset($row['smtp_pass'], $row['imap_pass']);
        }
        return $row;
    }

    /**
     * Get default mail account for outgoing/incoming operations.
     */
    public static function getDefault(PDO $db): array
    {
        $stmt = $db->query("SELECT * FROM mail_accounts WHERE is_default = 1 LIMIT 1");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) return $row;

        // Fallback to environment configuration
        $email = getenv('SMTP_USERNAME') ?: 'ray@rslsolution.com';
        return [
            'id' => 1,
            'name' => getenv('MAIL_FROM_NAME') ?: 'RSL Communications',
            'email' => $email,
            'smtp_host' => getenv('SMTP_HOST') ?: 'smtp.hostinger.com',
            'smtp_port' => (int) (getenv('SMTP_PORT') ?: 465),
            'smtp_user' => $email,
            'smtp_pass' => getenv('SMTP_PASSWORD') ?: '',
            'imap_host' => getenv('IMAP_HOST') ?: 'imap.hostinger.com',
            'imap_port' => (int) (getenv('IMAP_PORT') ?: 993),
            'imap_user' => getenv('IMAP_USERNAME') ?: $email,
            'imap_pass' => getenv('IMAP_PASSWORD') ?: (getenv('SMTP_PASSWORD') ?: ''),
            'is_default' => 1,
            'status' => 'active'
        ];
    }

    /**
     * Create or update a mail account.
     */
    public static function save(PDO $db, array $data, ?int $id = null): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        $email = strtolower(trim((string) ($data['email'] ?? '')));
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Valid name and email address are required.');
        }

        $smtpHost = trim((string) ($data['smtp_host'] ?? 'smtp.hostinger.com'));
        $smtpPort = (int) ($data['smtp_port'] ?? 465);
        $smtpUser = trim((string) ($data['smtp_user'] ?? $email));
        $smtpPass = (string) ($data['smtp_pass'] ?? '');

        $imapHost = trim((string) ($data['imap_host'] ?? 'imap.hostinger.com'));
        $imapPort = (int) ($data['imap_port'] ?? 993);
        $imapUser = trim((string) ($data['imap_user'] ?? $email));
        $imapPass = (string) ($data['imap_pass'] ?? '');

        $isDefault = !empty($data['is_default']) ? 1 : 0;
        $statusInput = (string) ($data['status'] ?? 'active');
        $status = in_array($statusInput, ['active', 'inactive', 'error'], true) ? $statusInput : 'active';
        $now = gmdate('c');

        // If updating and password fields are left blank, preserve existing passwords
        if ($id !== null) {
            $existing = self::getById($db, $id, true);
            if (!$existing) throw new RuntimeException('Mail account not found.');
            if ($smtpPass === '') $smtpPass = $existing['smtp_pass'];
            if ($imapPass === '') $imapPass = $existing['imap_pass'];

            if ($isDefault) {
                $db->exec("UPDATE mail_accounts SET is_default = 0");
            }

            $stmt = $db->prepare("
                UPDATE mail_accounts
                SET name = ?, email = ?, smtp_host = ?, smtp_port = ?, smtp_user = ?, smtp_pass = ?,
                    imap_host = ?, imap_port = ?, imap_user = ?, imap_pass = ?, is_default = ?, status = ?, updated_at = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $name, $email, $smtpHost, $smtpPort, $smtpUser, $smtpPass,
                $imapHost, $imapPort, $imapUser, $imapPass, $isDefault, $status, $now, $id
            ]);
            return self::getById($db, $id, false);
        }

        if ($smtpPass === '' || $imapPass === '') {
            throw new RuntimeException('SMTP and IMAP passwords are required for a new mail account.');
        }

        if ($isDefault) {
            $db->exec("UPDATE mail_accounts SET is_default = 0");
        }

        $stmt = $db->prepare("
            INSERT INTO mail_accounts (name, email, smtp_host, smtp_port, smtp_user, smtp_pass, imap_host, imap_port, imap_user, imap_pass, is_default, status, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $name, $email, $smtpHost, $smtpPort, $smtpUser, $smtpPass,
            $imapHost, $imapPort, $imapUser, $imapPass, $isDefault, $status, $now, $now
        ]);
        $newId = (int) $db->lastInsertId();
        return self::getById($db, $newId, false);
    }

    /**
     * Delete a mail account.
     */
    public static function delete(PDO $db, int $id): void
    {
        $existing = self::getById($db, $id, false);
        if (!$existing) throw new RuntimeException('Mail account not found.');
        if ((int) $existing['is_default'] === 1) {
            throw new RuntimeException('Cannot delete the default mail account. Set another account as default first.');
        }
        $db->prepare("DELETE FROM mail_accounts WHERE id = ?")->execute([$id]);
    }

    /**
     * Test SMTP and IMAP connections with live sockets.
     */
    public static function testConnection(array $account): array
    {
        $results = ['smtp' => ['ok' => false, 'message' => ''], 'imap' => ['ok' => false, 'message' => '']];

        // 1. Test SMTP
        try {
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = $account['smtp_host'] ?? 'smtp.hostinger.com';
            $mail->Port = (int) ($account['smtp_port'] ?? 465);
            $mail->SMTPAuth = true;
            $mail->Username = $account['smtp_user'] ?? ($account['email'] ?? '');
            $mail->Password = $account['smtp_pass'] ?? '';
            $mail->SMTPSecure = $mail->Port === 465 ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Timeout = 10;
            if (!$mail->smtpConnect()) {
                throw new RuntimeException('Failed to establish SMTP handshake.');
            }
            $mail->smtpClose();
            $results['smtp'] = ['ok' => true, 'message' => 'SMTP connected & authenticated successfully.'];
        } catch (\Throwable $e) {
            $results['smtp'] = ['ok' => false, 'message' => 'SMTP error: ' . $e->getMessage()];
        }

        // 2. Test IMAP
        try {
            $host = $account['imap_host'] ?? 'imap.hostinger.com';
            $port = (int) ($account['imap_port'] ?? 993);
            $user = $account['imap_user'] ?? ($account['email'] ?? '');
            $pass = $account['imap_pass'] ?? '';

            $fp = @stream_socket_client("ssl://{$host}:{$port}", $errno, $errstr, 10);
            if (!$fp) {
                throw new RuntimeException("Cannot connect to IMAP socket ({$errstr})");
            }
            stream_set_timeout($fp, 10);
            fgets($fp); // Read banner

            $loginCmd = "A001 LOGIN " . self::escapeImapString($user) . " " . self::escapeImapString($pass) . "\r\n";
            fwrite($fp, $loginCmd);
            $resp = '';
            while (!feof($fp)) {
                $line = fgets($fp);
                if ($line === false) break;
                $resp .= $line;
                if (str_starts_with($line, 'A001 ')) break;
            }
            fwrite($fp, "A002 LOGOUT\r\n");
            fclose($fp);

            if (str_contains($resp, 'A001 OK')) {
                $results['imap'] = ['ok' => true, 'message' => 'IMAP connected & authenticated successfully.'];
            } else {
                throw new RuntimeException('IMAP credentials rejected by server.');
            }
        } catch (\Throwable $e) {
            $results['imap'] = ['ok' => false, 'message' => 'IMAP error: ' . $e->getMessage()];
        }

        return $results;
    }

    private static function escapeImapString(string $str): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $str) . '"';
    }
}
