<?php
declare(strict_types=1);
namespace App\Services;

final class AdminSetup
{
    public static function isLocal(array $server): bool
    {
        $host = strtolower($server['HTTP_HOST'] ?? '');
        return in_array($server['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)
            && preg_match('/^(localhost|127\.0\.0\.1|\[::1\])(:[0-9]+)?$/D', $host) === 1;
    }

    public static function create(string $file, string $password, string $confirmation): void
    {
        if ($password !== $confirmation) throw new \InvalidArgumentException('Passwords do not match.');
        if (mb_strlen($password, 'UTF-8') < 6 || mb_strlen($password, 'UTF-8') > 8) throw new \InvalidArgumentException('Use a password between 6 and 8 characters.');
        $handle = fopen($file, 'r+');
        if (!$handle) throw new \RuntimeException('Backend environment file is not available.');
        try {
            if (!flock($handle, LOCK_EX)) throw new \RuntimeException('Unable to lock configuration.');
            $contents = stream_get_contents($handle);
            preg_match('/^ADMIN_PASSWORD_HASH=(.*)$/m', $contents, $match);
            if (trim($match[1] ?? '', " \t\r\n\"'") !== '' || getenv('ADMIN_PASSWORD_HASH')) {
                throw new \InvalidArgumentException('Administrator is already configured.');
            }
            $line = 'ADMIN_PASSWORD_HASH=' . password_hash($password, PASSWORD_DEFAULT);
            $updated = isset($match[0]) ? str_replace($match[0], $line, $contents) : $contents . "\n" . $line . "\n";
            rewind($handle);
            if (fwrite($handle, $updated) !== strlen($updated)) throw new \RuntimeException('Unable to save configuration.');
            ftruncate($handle, strlen($updated));
            fflush($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
