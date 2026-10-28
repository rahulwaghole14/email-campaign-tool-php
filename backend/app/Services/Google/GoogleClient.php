<?php
declare(strict_types=1);
namespace App\Services\Google;

use PDO;
use RuntimeException;

final class GoogleClient
{
    public const SCOPE = 'https://www.googleapis.com/auth/spreadsheets';
    public function __construct(private PDO $db) {}

    public function configured(): bool
    {
        return (bool) (getenv('GOOGLE_CLIENT_ID') && getenv('GOOGLE_CLIENT_SECRET') && getenv('GOOGLE_REDIRECT_URI'));
    }

    public function connected(): bool
    {
        try {
            return (bool) $this->db->query('SELECT id FROM google_connection WHERE id = 1')->fetchColumn();
        } catch (\Throwable) {
            return false;
        }
    }

    public function authorizationUrl(string $state, ?string $redirectUri = null): string
    {
        if (!$this->configured()) throw new RuntimeException('Google connection needs an OAuth client ID and secret. See docs/GOOGLE_SHEETS.md.');
        $redirect = $redirectUri ?: (getenv('GOOGLE_REDIRECT_URI') ?: 'http://127.0.0.1:8085/auth/google/callback');
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
            'client_id' => getenv('GOOGLE_CLIENT_ID'), 'redirect_uri' => $redirect,
            'response_type' => 'code', 'scope' => self::SCOPE, 'access_type' => 'offline',
            'prompt' => 'consent', 'state' => $state,
        ]);
    }

    private function key(): string
    {
        $path = dirname(__DIR__, 3) . '/storage/app/private/google/token.key';
        $file = fopen($path, 'c+b');
        if (!$file || !flock($file, LOCK_EX)) throw new RuntimeException('Cannot access token encryption key.');
        try {
            $key = stream_get_contents($file);
            if ($key === '') { $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES); fwrite($file, $key); fflush($file); @chmod($path, 0600); }
            if (strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) throw new RuntimeException('Invalid token encryption key.');
            return $key;
        } finally { flock($file, LOCK_UN); fclose($file); }
    }

    private function save(array $token): void
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $value = base64_encode($nonce . sodium_crypto_secretbox(json_encode($token, JSON_THROW_ON_ERROR), $nonce, $this->key()));
        // REPLACE is supported by both MySQL and SQLite; this app owns one connection.
        $this->db->prepare('REPLACE INTO google_connection (id, token_data, updated_at) VALUES (1, ?, ?)')->execute([$value, gmdate('c')]);
    }

    private function token(): array
    {
        $stored = $this->db->query('SELECT token_data FROM google_connection WHERE id = 1')->fetchColumn();
        if (!$stored) throw new RuntimeException('Connect your Google account first.');
        $bytes = base64_decode($stored, true);
        if ($bytes === false || strlen($bytes) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) throw new RuntimeException('Reconnect your Google account.');
        $plain = sodium_crypto_secretbox_open(substr($bytes, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($bytes, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $this->key());
        if ($plain === false) throw new RuntimeException('Google tokens could not be decrypted. Reconnect your account.');
        return json_decode($plain, true, 32, JSON_THROW_ON_ERROR);
    }

    public function exchange(string $code, ?string $redirectUri = null): void
    {
        $redirect = $redirectUri ?: (getenv('GOOGLE_REDIRECT_URI') ?: 'http://127.0.0.1:8085/auth/google/callback');
        $token = $this->http('POST', 'https://oauth2.googleapis.com/token', [
            'code' => $code, 'client_id' => getenv('GOOGLE_CLIENT_ID'), 'client_secret' => getenv('GOOGLE_CLIENT_SECRET'),
            'redirect_uri' => $redirect, 'grant_type' => 'authorization_code',
        ], null, true);
        if (empty($token['refresh_token']) || empty($token['access_token'])) throw new RuntimeException('Offline access was not granted. Reconnect and allow access.');
        if (!in_array(self::SCOPE, explode(' ', $token['scope'] ?? ''), true)) throw new RuntimeException('Google Sheets editing permission was not granted.');
        $token['expires_at'] = time() + (int) ($token['expires_in'] ?? 3600);
        $this->save($token);
    }

    public function disconnect(): void
    {
        if ($this->connected()) {
            $token = $this->token();
            $this->http('POST', 'https://oauth2.googleapis.com/revoke', ['token' => $token['refresh_token'] ?? $token['access_token']], null, true);
            $this->db->exec('DELETE FROM google_connection WHERE id = 1');
        }
    }

    public function request(string $method, string $path, ?array $body = null): array
    {
        $token = $this->token();
        if (($token['expires_at'] ?? 0) < time() + 60) {
            $fresh = $this->http('POST', 'https://oauth2.googleapis.com/token', [
                'client_id' => getenv('GOOGLE_CLIENT_ID'), 'client_secret' => getenv('GOOGLE_CLIENT_SECRET'),
                'refresh_token' => $token['refresh_token'], 'grant_type' => 'refresh_token',
            ], null, true);
            $token = array_merge($token, $fresh, ['expires_at' => time() + (int) ($fresh['expires_in'] ?? 3600)]);
            $this->save($token);
        }
        return $this->http($method, 'https://sheets.googleapis.com/v4/spreadsheets/' . $path, $body, $token['access_token']);
    }

    private function http(string $method, string $url, ?array $body, ?string $token, bool $form = false): array
    {
        $curl = curl_init($url);
        $headers = ['Accept: application/json'];
        if ($token) $headers[] = 'Authorization: Bearer ' . $token;
        if ($body !== null) $headers[] = 'Content-Type: ' . ($form ? 'application/x-www-form-urlencoded' : 'application/json');
        curl_setopt_array($curl, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 30, CURLOPT_HTTPHEADER => $headers]);
        if ($body !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, $form ? http_build_query($body) : json_encode($body, JSON_THROW_ON_ERROR));
        $raw = curl_exec($curl); $status = curl_getinfo($curl, CURLINFO_HTTP_CODE); curl_close($curl);
        if ($raw === false) throw new RuntimeException('Google could not be reached. Try again.');
        if ($status < 200 || $status >= 300) {
            $errData = json_decode((string) $raw, true);
            $googleMsg = $errData['error']['message'] ?? '';
            if (stripos($googleMsg, 'Office file') !== false || stripos($googleMsg, 'not supported for this document') !== false) {
                throw new RuntimeException('This link is an Excel (.xlsx) file in Google Drive. Click "File > Save as Google Sheets" in that tab, then copy and paste the new Google Sheet URL.');
            }
            throw new RuntimeException(match ($status) {
                400 => 'Google rejected the request. Check that the link is a native Google Sheet (File > Save as Google Sheets).',
                401 => 'Google authorization expired. Reconnect your account.',
                403 => 'Google denied access. Enable the Sheets API and check account permissions or protected cells.',
                404 => 'Sheet not found or not accessible to the connected account.',
                429 => 'Google request limit reached. Wait and try again.',
                default => 'Google request failed. Refresh the sheet before retrying a change.',
            });
        }
        return $raw === '' ? [] : json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
    }
}
