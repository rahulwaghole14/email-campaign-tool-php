<?php
declare(strict_types=1);

namespace App\Services\Email;

use PDO;
use RuntimeException;
use InvalidArgumentException;

final class ImapClient
{
    private PDO $db;
    private string $host;
    private int $port;
    private string $username;
    private string $password;
    private ?int $accountId = null;
    private int $tagIndex = 0;

    public function __construct(PDO $db, ?array $account = null)
    {
        $this->db = $db;
        if ($account && !empty($account['imap_user'])) {
            $this->host = $account['imap_host'] ?? 'imap.hostinger.com';
            $this->port = (int) ($account['imap_port'] ?? 993);
            $this->username = $account['imap_user'];
            $this->password = $account['imap_pass'] ?? '';
            $this->accountId = !empty($account['id']) ? (int) $account['id'] : null;
        } else {
            $this->host = getenv('IMAP_HOST') ?: 'imap.hostinger.com';
            $this->port = (int) (getenv('IMAP_PORT') ?: 993);
            $this->username = getenv('IMAP_USERNAME') ?: (getenv('SMTP_USERNAME') ?: '');
            $this->password = getenv('IMAP_PASSWORD') ?: (getenv('SMTP_PASSWORD') ?: '');
            $this->accountId = 1;
        }
    }

    /**
     * Synchronize emails from IMAP inbox into database.
     * Fetches up to $limit new emails (newest first).
     */
    public function sync(int $limit = 30): array
    {
        if (empty($this->username) || empty($this->password)) {
            throw new RuntimeException('IMAP credentials not configured in backend/.env');
        }

        $connection = $this->connect();
        try {
            $this->sendCommand($connection, "SELECT INBOX");

            // Find max UID already in database
            $stmt = $this->db->query("SELECT MAX(uid) AS max_uid FROM inbox_emails");
            $maxUid = (int) ($stmt->fetchColumn() ?: 0);

            $searchRes = $this->sendCommand($connection, "UID SEARCH ALL");
            $uids = [];
            foreach ($searchRes as $line) {
                if (preg_match('/^\*\s+SEARCH\s+(.*)$/i', trim($line), $m)) {
                    $uids = array_merge($uids, array_filter(explode(' ', trim($m[1])), 'is_numeric'));
                }
            }

            $uids = array_map('intval', $uids);
            sort($uids, SORT_NUMERIC);

            if ($maxUid > 0) {
                // Fetch only UIDs newer than max UID
                $pendingUids = array_filter($uids, fn($u) => $u > $maxUid);
            } else {
                // First sync: fetch the latest $limit messages
                $pendingUids = array_slice($uids, -$limit);
            }

            $pendingUids = array_values($pendingUids);
            $newCount = 0;

            if (!empty($pendingUids)) {
                // Fetch in reverse order (newest first)
                rsort($pendingUids, SORT_NUMERIC);
                // Cap to $limit per sync batch to keep response snappy
                $batchUids = array_slice($pendingUids, 0, $limit);

                foreach ($batchUids as $uid) {
                    $emailData = $this->fetchMessage($connection, $uid);
                    if ($emailData) {
                        $this->saveEmail($emailData);
                        $newCount++;
                    }
                }
            }

            if ($newCount > 0) {
                $activityStmt = $this->db->prepare("INSERT INTO activity (action, detail, created_at) VALUES (?, ?, ?)");
                $activityStmt->execute([
                    'inbox_synced',
                    "Synchronized {$newCount} new incoming " . ($newCount === 1 ? 'email' : 'emails') . " from Hostinger mailbox.",
                    gmdate('c')
                ]);
            }

            return [
                'new_count' => $newCount,
                'total_inbox' => count($uids),
                'synced_at' => gmdate('c')
            ];
        } finally {
            $this->close($connection);
        }
    }

    /**
     * Fetch a single message envelope, headers, and body by UID.
     */
    private function fetchMessage($fp, int $uid): ?array
    {
        $res = $this->sendCommand($fp, "UID FETCH {$uid} (BODY.PEEK[HEADER] BODY.PEEK[TEXT])");
        $raw = implode("\n", $res);

        // Separate header and body text from IMAP response
        $headerPart = '';
        $bodyPart = '';

        if (preg_match('/BODY\[HEADER\]\s+\{(\d+)\}\r?\n/i', $raw, $hm, PREG_OFFSET_CAPTURE)) {
            $len = (int) $hm[1][0];
            $start = $hm[0][1] + strlen($hm[0][0]);
            $headerPart = substr($raw, $start, $len);
        }

        if (preg_match('/BODY\[TEXT\]\s+\{(\d+)\}\r?\n/i', $raw, $bm, PREG_OFFSET_CAPTURE)) {
            $len = (int) $bm[1][0];
            $start = $bm[0][1] + strlen($bm[0][0]);
            $bodyPart = substr($raw, $start, $len);
        }

        if (empty($headerPart)) {
            // Fallback: parse headers from raw lines
            $headerPart = $raw;
        }

        $headers = $this->parseHeaders($headerPart);

        $fromRaw = $headers['from'] ?? 'Unknown Sender';
        [$fromName, $fromEmail] = $this->parseAddress($fromRaw);

        $toRaw = $headers['to'] ?? $this->username;
        [, $toEmail] = $this->parseAddress($toRaw);

        $subject = $this->decodeMime($headers['subject'] ?? '(No subject)');
        $date = !empty($headers['date']) ? date('c', strtotime($headers['date'])) : gmdate('c');
        $messageId = trim($headers['message-id'] ?? '');

        // Decode and sanitize body
        $mime = self::extractMime($bodyPart, $headers['content-type'] ?? '', $headers['content-transfer-encoding'] ?? '');
        $body = $mime['plain'];
        $htmlBody = $mime['html'];
        $snippet = self::cleanSnippet($body, 140);
        if ($snippet === '' && !empty($htmlBody)) {
            $snippet = self::cleanSnippet($htmlBody, 140);
        }

        return [
            'uid' => $uid,
            'message_id' => $messageId,
            'from_name' => $fromName,
            'from_email' => $fromEmail,
            'to_email' => $toEmail ?: $this->username,
            'subject' => $subject,
            'body' => $body,
            'html_body' => $htmlBody,
            'snippet' => $snippet,
            'received_at' => $date,
            'created_at' => gmdate('c')
        ];
    }

    private function saveEmail(array $email): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO inbox_emails (mail_account_id, uid, message_id, from_name, from_email, to_email, subject, body, html_body, snippet, is_read, received_at, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?)
            ON DUPLICATE KEY UPDATE
                subject = VALUES(subject),
                body = VALUES(body),
                html_body = VALUES(html_body),
                snippet = VALUES(snippet)
        ");
        $stmt->execute([
            $this->accountId,
            $email['uid'],
            $email['message_id'],
            $email['from_name'],
            $email['from_email'],
            $email['to_email'],
            $email['subject'],
            $email['body'],
            $email['html_body'] ?? '',
            $email['snippet'],
            $email['received_at'],
            $email['created_at']
        ]);
    }

    public function listing(array $query = []): array
    {
        $page = max(1, (int) ($query['page'] ?? 1));
        $size = max(5, min(100, (int) ($query['page_size'] ?? 25)));
        $search = trim((string) ($query['search'] ?? ''));
        $status = trim((string) ($query['status'] ?? ''));

        $conditions = [];
        $params = [];

        if ($search !== '') {
            $conditions[] = "(from_name LIKE ? OR from_email LIKE ? OR subject LIKE ? OR snippet LIKE ?)";
            $term = "%{$search}%";
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
        }

        if ($status === 'unread') {
            $conditions[] = "is_read = 0";
        } elseif ($status === 'read') {
            $conditions[] = "is_read = 1";
        }

        $accountId = !empty($query['account_id']) ? (int) $query['account_id'] : null;
        $toEmail = !empty($query['to_email']) ? trim((string) $query['to_email']) : null;
        if ($accountId !== null && $accountId > 0 && $toEmail !== null && $toEmail !== '') {
            $conditions[] = "(mail_account_id = ? OR LOWER(to_email) = ?)";
            $params[] = $accountId;
            $params[] = strtolower($toEmail);
        } elseif ($accountId !== null && $accountId > 0) {
            $conditions[] = "mail_account_id = ?";
            $params[] = $accountId;
        } elseif ($toEmail !== null && $toEmail !== '') {
            $conditions[] = "LOWER(to_email) = ?";
            $params[] = strtolower($toEmail);
        }

        $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM inbox_emails {$where}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        // Calculate unread count strictly within the scoped mailbox criteria
        $unreadConditions = ["is_read = 0"];
        $unreadParams = [];
        if ($accountId !== null && $accountId > 0 && $toEmail !== null && $toEmail !== '') {
            $unreadConditions[] = "(mail_account_id = ? OR LOWER(to_email) = ?)";
            $unreadParams[] = $accountId;
            $unreadParams[] = strtolower($toEmail);
        } elseif ($accountId !== null && $accountId > 0) {
            $unreadConditions[] = "mail_account_id = ?";
            $unreadParams[] = $accountId;
        } elseif ($toEmail !== null && $toEmail !== '') {
            $unreadConditions[] = "LOWER(to_email) = ?";
            $unreadParams[] = strtolower($toEmail);
        }
        $unreadWhere = 'WHERE ' . implode(' AND ', $unreadConditions);
        $unreadStmt = $this->db->prepare("SELECT COUNT(*) FROM inbox_emails {$unreadWhere}");
        $unreadStmt->execute($unreadParams);
        $unreadCount = (int) $unreadStmt->fetchColumn();

        $pages = max(1, (int) ceil($total / $size));
        $page = min($page, $pages);
        $offset = ($page - 1) * $size;

        $selectSql = "SELECT id, mail_account_id, uid, message_id, from_name, from_email, to_email, subject, body, snippet, is_read, received_at, created_at FROM inbox_emails {$where} ORDER BY received_at DESC, id DESC LIMIT {$size} OFFSET {$offset}";
        $stmt = $this->db->prepare($selectSql);
        $stmt->execute($params);
        $emails = $stmt->fetchAll();

        // Ensure snippets don't show raw MIME headers, boundary markers, or CSS rules
        foreach ($emails as &$em) {
            $snip = (string) $em['snippet'];
            if (str_contains($snip, '--') || str_contains($snip, 'Content-Type:') || str_contains($snip, '{') || str_contains($snip, '-webkit-') || str_contains($snip, 'font-smoothing')) {
                $cleaned = self::cleanMimeBody($em['body']);
                $cleanSnippet = self::cleanSnippet($cleaned, 140);
                if ($cleanSnippet === '' && !empty($em['html_body'])) {
                    $cleanSnippet = self::cleanSnippet($em['html_body'], 140);
                }
                $em['snippet'] = $cleanSnippet !== '' ? $cleanSnippet : 'Message content received.';
                try {
                    $this->db->prepare("UPDATE inbox_emails SET body = ?, snippet = ? WHERE id = ?")->execute([$cleaned, $em['snippet'], $em['id']]);
                } catch (\Throwable $t) {}
            }
            unset($em['body']);
        }

        $threadCounts = ConversationThread::getThreadCountsForInbox($this->db, $emails);
        foreach ($emails as &$em) {
            $em['thread_count'] = $threadCounts[(int) $em['id']] ?? 1;
        }

        return [
            'emails' => $emails,
            'unread_count' => $unreadCount,
            'pagination' => [
                'page' => $page,
                'pages' => $pages,
                'page_size' => $size,
                'total' => $total,
                'from' => $total === 0 ? 0 : $offset + 1,
                'to' => min($offset + $size, $total)
            ]
        ];
    }

    public function detail(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM inbox_emails WHERE id = ?");
        $stmt->execute([$id]);
        $email = $stmt->fetch();
        if (!$email) {
            return null;
        }

        // Auto-fetch HTML from IMAP if missing
        if (empty($email['html_body']) && !empty($email['uid'])) {
            try {
                $conn = $this->connect();
                $this->sendCommand($conn, "SELECT INBOX");
                $fetched = $this->fetchMessage($conn, (int) $email['uid']);
                $this->close($conn);
                if ($fetched && !empty($fetched['html_body'])) {
                    $email['html_body'] = $fetched['html_body'];
                    $email['body'] = $fetched['body'];
                    $email['snippet'] = $fetched['snippet'];
                    $this->db->prepare("UPDATE inbox_emails SET body = ?, html_body = ?, snippet = ? WHERE id = ?")
                        ->execute([$email['body'], $email['html_body'], $email['snippet'], $id]);
                }
            } catch (\Throwable $t) {}
        }

        // Auto-clean body and extract HTML if it was stored with raw MIME headers or boundaries
        if (str_contains($email['body'], '--') || str_contains($email['body'], 'Content-Transfer-Encoding:')) {
            $mime = self::extractMime($email['body']);
            $updated = false;
            if (!empty($mime['plain']) && $mime['plain'] !== $email['body']) {
                $email['body'] = $mime['plain'];
                $email['snippet'] = self::cleanSnippet($mime['plain'], 140);
                $updated = true;
            }
            if (!empty($mime['html']) && empty($email['html_body'])) {
                $email['html_body'] = $mime['html'];
                $updated = true;
            }
            if ($updated) {
                try {
                    $this->db->prepare("UPDATE inbox_emails SET body = ?, html_body = ?, snippet = ? WHERE id = ?")->execute([$email['body'], $email['html_body'] ?? '', $email['snippet'] ?? '', $id]);
                } catch (\Throwable $e) {}
            }
        }

        if ((int) $email['is_read'] === 0) {
            $this->db->prepare("UPDATE inbox_emails SET is_read = 1 WHERE id = ?")->execute([$id]);
            $email['is_read'] = 1;
        }

        foreach ($email as $k => $v) {
            if (is_string($v)) {
                $email[$k] = function_exists('mb_scrub') ? mb_scrub($v, 'UTF-8') : @mb_convert_encoding($v, 'UTF-8', 'UTF-8');
            }
        }

        $email['thread'] = ConversationThread::getThreadForInbox($this->db, $id);
        return $email;
    }

    public function toggleRead(int $id, ?bool $isRead = null): bool
    {
        if ($isRead === null) {
            $stmt = $this->db->prepare("UPDATE inbox_emails SET is_read = CASE WHEN is_read = 1 THEN 0 ELSE 1 END WHERE id = ?");
            $stmt->execute([$id]);
        } else {
            $stmt = $this->db->prepare("UPDATE inbox_emails SET is_read = ? WHERE id = ?");
            $stmt->execute([$isRead ? 1 : 0, $id]);
        }
        return $stmt->rowCount() > 0;
    }

    /**
     * Connect to IMAP server over SSL.
     */
    private function connect()
    {
        $uri = "ssl://{$this->host}:{$this->port}";
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ]
        ]);

        $fp = @stream_socket_client($uri, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $context);
        if (!$fp) {
            throw new RuntimeException("Could not connect to IMAP server {$this->host}:{$this->port}: {$errstr} ({$errno})");
        }

        stream_set_timeout($fp, 20);
        // Read greeting
        fgets($fp);

        // Login
        $this->sendCommand($fp, "LOGIN {$this->username} {$this->password}");

        return $fp;
    }

    /**
     * Send IMAP command with unique tag and wait for tagged response.
     */
    private function sendCommand($fp, string $command): array
    {
        $tag = 'A' . str_pad((string) (++$this->tagIndex), 4, '0', STR_PAD_LEFT);
        fwrite($fp, "{$tag} {$command}\r\n");

        $lines = [];
        while (!feof($fp)) {
            $line = fgets($fp);
            if ($line === false) break;
            $lines[] = rtrim($line, "\r\n");

            if (str_starts_with($line, "{$tag} OK")) {
                break;
            }
            if (str_starts_with($line, "{$tag} NO") || str_starts_with($line, "{$tag} BAD")) {
                throw new RuntimeException("IMAP command failed [{$command}]: " . trim($line));
            }
        }

        return $lines;
    }

    private function close($fp): void
    {
        if ($fp && is_resource($fp)) {
            try {
                $tag = 'A' . str_pad((string) (++$this->tagIndex), 4, '0', STR_PAD_LEFT);
                @fwrite($fp, "{$tag} LOGOUT\r\n");
                @fgets($fp);
            } catch (\Throwable $e) {}
            @fclose($fp);
        }
    }

    private function parseHeaders(string $headerText): array
    {
        $headers = [];
        $lines = explode("\n", str_replace("\r\n", "\n", $headerText));
        $currentKey = '';

        foreach ($lines as $line) {
            if (preg_match('/^([a-zA-Z0-9\-]+):\s*(.*)$/', $line, $m)) {
                $currentKey = strtolower($m[1]);
                $headers[$currentKey] = $m[2];
            } elseif ($currentKey !== '' && (str_starts_with($line, ' ') || str_starts_with($line, "\t"))) {
                $headers[$currentKey] .= ' ' . trim($line);
            }
        }

        return $headers;
    }

    private function parseAddress(string $raw): array
    {
        $raw = trim($raw);
        if (preg_match('/^(.*?)\s*<([^>]+)>$/', $raw, $m)) {
            $name = trim($m[1], " \t\n\r\0\x0B\"'");
            return [$this->decodeMime($name) ?: $m[2], trim($m[2])];
        }
        if (filter_var($raw, FILTER_VALIDATE_EMAIL)) {
            return ['', $raw];
        }
        return [$this->decodeMime($raw), $raw];
    }

    private function decodeMime(string $text): string
    {
        if (!str_contains($text, '=?')) {
            return $text;
        }
        if (function_exists('mb_decode_mime_header')) {
            return mb_decode_mime_header($text);
        }
        if (function_exists('iconv_mime_decode')) {
            return (string) iconv_mime_decode($text, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
        }
        return $text;
    }

    private function decodeBody(string $rawBody, array $headers): string
    {
        return self::cleanMimeBody(
            $rawBody,
            $headers['content-type'] ?? '',
            $headers['content-transfer-encoding'] ?? ''
        );
    }

    public static function cleanMimeBody(string $raw, string $contentType = '', string $topEncoding = ''): string
    {
        return self::extractMime($raw, $contentType, $topEncoding)['plain'];
    }

    public static function cleanSnippet(string $text, int $limit = 140): string
    {
        // Strip style, script and head tags and their entire contents
        $text = preg_replace('/<style\b[^>]*>.*?<\/style>/is', ' ', $text);
        $text = preg_replace('/<script\b[^>]*>.*?<\/script>/is', ' ', $text);
        $text = preg_replace('/<head\b[^>]*>.*?<\/head>/is', ' ', $text);
        // Strip XML/HTML comments
        $text = preg_replace('/<!--.*?-->/s', ' ', $text);
        // Strip remaining tags
        $text = strip_tags($text);
        // Decode HTML entities
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Replace non-breaking spaces and collapse whitespace
        $text = str_replace("\xc2\xa0", ' ', $text);
        // Remove rogue CSS blocks if any still linger
        $text = preg_replace('/\{[^}]*\}/s', ' ', $text);
        // Strip reply quote headers like "On Wed, Oct 28, 2026... wrote: > ..."
        $text = preg_replace('/(?:\.|\n|\r|^|\s)\s*On\s+[A-Za-z]+,\s+[A-Za-z0-9,:\s]+wrote:.*$/is', '.', $text);
        $text = preg_replace('/(?:\.|\n|\r|^|\s)\s*On\s+(?:Mon|Tue|Wed|Thu|Fri|Sat|Sun).*?wrote:.*$/is', '.', $text);
        $text = preg_replace('/(?:\n|\r|^)\s*>+.*$/m', '', $text);
        $text = preg_replace('/\s*>\s+.*$/s', '', $text);
        $text = trim(preg_replace('/\s+/', ' ', $text));
        return mb_substr($text, 0, $limit);
    }

    public static function extractMime(string $raw, string $contentType = '', string $topEncoding = ''): array
    {
        $body = str_replace(["\r\n", "\r"], "\n", $raw);

        // Collect all boundaries
        $boundaries = [];
        if (preg_match_all('/boundary="?([^";\r\n\s]+)"?/i', $contentType, $m)) {
            $boundaries = array_merge($boundaries, $m[1]);
        }
        // Also discover boundary markers directly in the body
        if (preg_match_all('/^--([A-Za-z0-9_=\-\.\/]{8,120}?)(?:--)?$/m', $body, $m)) {
            $discovered = array_unique(array_map(fn($b) => rtrim($b, '-'), $m[1]));
            $boundaries = array_merge($boundaries, $discovered);
        }

        $plainText = '';
        $htmlText = '';

        if (!empty($boundaries)) {
            usort($boundaries, fn($a, $b) => strlen($b) <=> strlen($a));
            $parts = [$body];
            foreach ($boundaries as $b) {
                if ($b === '') continue;
                $newParts = [];
                foreach ($parts as $p) {
                    if (str_contains($p, '--' . $b)) {
                        $split = explode('--' . $b, $p);
                        foreach ($split as $s) {
                            $s = trim($s);
                            if ($s !== '' && $s !== '--') {
                                $newParts[] = $s;
                            }
                        }
                    } else {
                        $newParts[] = $p;
                    }
                }
                $parts = $newParts;
            }

            foreach ($parts as $part) {
                $part = trim($part);
                if ($part === '' || $part === '--') continue;

                $sections = preg_split('/\r?\n\s*\r?\n/', $part, 2);
                $pHeader = count($sections) === 2 ? $sections[0] : '';
                $pBody = count($sections) === 2 ? $sections[1] : $part;

                // Strip any leading Content-... header lines from body
                $pBody = preg_replace('/^(?:Content-[A-Za-z\-]+:[^\n]*\n+)+/mi', '', $pBody);

                $encoding = '';
                if (preg_match('/content-transfer-encoding:\s*([a-z0-9\-]+)/i', $pHeader, $em)) {
                    $encoding = strtolower(trim($em[1]));
                }
                if ($encoding === 'quoted-printable') {
                    $pBody = quoted_printable_decode($pBody);
                } elseif ($encoding === 'base64') {
                    $decoded = base64_decode($pBody);
                    if ($decoded !== false) $pBody = $decoded;
                }

                // Detect charset and convert to valid UTF-8
                $charset = 'UTF-8';
                if (preg_match('/charset=["\']?([a-zA-Z0-9\-]+)/i', $pHeader ?: $contentType, $cm)) {
                    $candidate = strtoupper(trim($cm[1]));
                    $isValid = false;
                    try {
                        $isValid = in_array($candidate, mb_list_encodings(), true) || mb_encoding_aliases($candidate) !== false;
                    } catch (\Throwable) {
                        $isValid = false;
                    }
                    if ($isValid) {
                        $charset = $candidate;
                    }
                }
                if ($charset !== 'UTF-8' && $charset !== 'UTF8') {
                    try {
                        $pBody = @mb_convert_encoding($pBody, 'UTF-8', $charset);
                    } catch (\Throwable $t) {
                        $pBody = function_exists('mb_scrub') ? mb_scrub($pBody, 'UTF-8') : @mb_convert_encoding($pBody, 'UTF-8', 'UTF-8');
                    }
                } else {
                    $pBody = function_exists('mb_scrub') ? mb_scrub($pBody, 'UTF-8') : @mb_convert_encoding($pBody, 'UTF-8', 'UTF-8');
                }

                $isHtml = (bool) preg_match('/content-type:\s*text\/html/i', $pHeader);
                $isPlain = (bool) preg_match('/content-type:\s*text\/plain/i', $pHeader);

                if (!$isHtml && !$isPlain) {
                    if (str_contains($pBody, '<html') || str_contains($pBody, '<body') || str_contains($pBody, '</div>')) {
                        $isHtml = true;
                    } else {
                        $isPlain = true;
                    }
                }

                if ($isPlain && empty($plainText)) {
                    $plainText = trim($pBody);
                } elseif ($isHtml && empty($htmlText)) {
                    $htmlText = trim($pBody);
                }
            }
        }

        if ($plainText === '' && $htmlText === '') {
            $single = $body;
            $enc = strtolower($topEncoding);
            if ($enc === 'quoted-printable' || str_contains($single, '=20') || preg_match('/=[0-9A-F]{2}/i', $single)) {
                $single = quoted_printable_decode($single);
            } elseif ($enc === 'base64') {
                $decoded = base64_decode($single);
                if ($decoded !== false) $single = $decoded;
            }
            if (stripos($contentType, 'text/html') !== false || str_contains($single, '<html')) {
                $htmlText = $single;
            } else {
                $plainText = $single;
            }
        }

        $final = $plainText;
        if ($final === '' && $htmlText !== '') {
            $clean = str_ireplace(['<br>', '<br/>', '<br />', '</p>', '</div>', '</tr>', '</li>'], "\n", $htmlText);
            $clean = strip_tags($clean);
            $clean = html_entity_decode($clean, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $final = trim(preg_replace('/\n{3,}/', "\n\n", $clean));
        }

        // Clean any residual MIME boundaries or headers
        $final = preg_replace('/^--[A-Za-z0-9_=\-\.\/]+(?:--)?$/m', '', $final);
        $final = preg_replace('/^Content-(?:Type|Transfer-Encoding|ID|Disposition):[^\n]*\n?/mi', '', $final);
        if (str_contains($final, '=20') || preg_match('/=[0-9A-F]{2}/i', $final)) {
            $final = quoted_printable_decode($final);
        }

        // Clean trailing IMAP socket envelope responses like `)\nA0004 OK Fetch completed` or `)\nA0004`
        $final = preg_replace('/\s*\)\s*\r?\n\s*A\d{4}(?:\s+[A-Za-z0-9_ ]*)?\s*$/i', '', $final);
        $htmlText = preg_replace('/\s*\)\s*\r?\n\s*A\d{4}(?:\s+[A-Za-z0-9_ ]*)?\s*$/i', '', $htmlText);

        $final = trim($final);
        return [
            'plain' => function_exists('mb_scrub') ? mb_scrub($final, 'UTF-8') : @mb_convert_encoding($final, 'UTF-8', 'UTF-8'),
            'html' => function_exists('mb_scrub') ? mb_scrub(trim($htmlText), 'UTF-8') : @mb_convert_encoding(trim($htmlText), 'UTF-8', 'UTF-8')
        ];
    }
}

