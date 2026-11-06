<?php
declare(strict_types=1);

namespace App\Services\WhatsApp;

use InvalidArgumentException;
use PDO;

/** The WhatsApp campaign account saved on the desk. */
final class PlatformSettings
{
    public function __construct(private PDO $db)
    {
        $this->ensure();
    }

    public function read(): array
    {
        $row = $this->row();
        if ($row === null) {
            $this->seedFromEnv();
            $row = $this->row();
        }
        return $this->shape($row);
    }

    public function save(string $name, string $base, string $token): array
    {
        $name = trim($name);
        $base = rtrim(trim($base), '/');
        $token = trim($token);
        if ($name === '' || mb_strlen($name) > 160) {
            throw new InvalidArgumentException('Enter the account name.');
        }
        $parts = parse_url($base);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || !empty($parts['user']) || !empty($parts['pass']) || !empty($parts['query']) || !empty($parts['fragment'])) {
            throw new InvalidArgumentException('Enter a base URL that starts with https://.');
        }
        if ($token === '' || strlen($token) < 20 || strlen($token) > 4000 || preg_match('/\s/', $token)) {
            throw new InvalidArgumentException('Paste the access token from the Message API.');
        }
        $now = gmdate('c');
        if ($this->row() === null) {
            $stmt = $this->db->prepare('INSERT INTO whatsapp_account (id, name, base_url, access_token, updated_at) VALUES (1, ?, ?, ?, ?)');
        } else {
            $stmt = $this->db->prepare('UPDATE whatsapp_account SET name = ?, base_url = ?, access_token = ?, updated_at = ? WHERE id = 1');
        }
        $stmt->execute([$name, $base, $token, $now]);
        return $this->read();
    }

    private function shape(?array $row): array
    {
        return [
            'name' => (string) ($row['name'] ?? ''),
            'base_url' => (string) ($row['base_url'] ?? ''),
            'access_token' => (string) ($row['access_token'] ?? ''),
        ];
    }

    private function seedFromEnv(): void
    {
        $base = rtrim(trim((string) getenv('WA_API_BASE')), '/');
        $token = trim((string) getenv('WA_ACCESS_TOKEN'));
        $name = trim((string) getenv('WA_DEVICE_NAME')) ?: 'Desk phone';
        if ($base === '' || $token === '' || !str_starts_with($base, 'https://')) return;
        $stmt = $this->db->prepare('INSERT INTO whatsapp_account (id, name, base_url, access_token, updated_at) VALUES (1, ?, ?, ?, ?)');
        $stmt->execute([$name, $base, $token, gmdate('c')]);
    }

    private function row(): ?array
    {
        $row = $this->db->query('SELECT id, name, base_url, access_token, updated_at FROM whatsapp_account WHERE id = 1')->fetch();
        return is_array($row) ? $row : null;
    }

    private function ensure(): void
    {
        $this->db->exec("CREATE TABLE IF NOT EXISTS whatsapp_account (
            id INTEGER PRIMARY KEY,
            name VARCHAR(160) NOT NULL,
            base_url VARCHAR(255) NOT NULL,
            access_token TEXT NOT NULL,
            updated_at VARCHAR(32) NOT NULL
        )");
    }
}
