<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/bootstrap.php';

try {
    if (in_array('--create', $argv, true) && getenv('DB_CONNECTION') === 'mysql') {
        $name = getenv('DB_DATABASE') ?: '';
        if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) throw new RuntimeException('Invalid database name.');
        $server = new PDO(
            'mysql:host=' . (getenv('DB_HOST') ?: '127.0.0.1') . ';port=' . (getenv('DB_PORT') ?: '3306') . ';charset=utf8mb4',
            getenv('DB_USERNAME') ?: '',
            getenv('DB_PASSWORD') ?: '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $server->exec('CREATE DATABASE IF NOT EXISTS `' . $name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    }
    $db = App\Support\Database::connect();
    App\Support\Database::migrate($db);
    echo "Database connected; application tables are ready.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Database setup failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
