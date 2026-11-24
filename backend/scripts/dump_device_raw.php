<?php
declare(strict_types=1);

$base = rtrim((string) ($argv[1] ?? ''), '/');
$token = (string) ($argv[2] ?? '');
$ids = array_slice($argv, 3);
if ($base === '' || $token === '' || $ids === []) {
    fwrite(STDERR, "Usage: php dump_device_raw.php <base> <token> <id> [id...]\n");
    exit(1);
}

foreach ($ids as $id) {
    foreach (['', '/qr'] as $suf) {
        $url = $base . '/api/devices/' . rawurlencode($id) . $suf;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Authorization: Bearer ' . $token,
            ],
            CURLOPT_TIMEOUT => 20,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        echo "\n=== {$id}{$suf} HTTP {$code} ===\n";
        $j = json_decode((string) $body, true);
        if (!is_array($j)) {
            echo substr((string) $body, 0, 500) . "\n";
            continue;
        }
        $data = isset($j['data']) && is_array($j['data']) ? $j['data'] : $j;
        if (isset($data['qr']) && is_string($data['qr']) && strlen($data['qr']) > 80) {
            $data['qr'] = '[qr ' . strlen($data['qr']) . ' chars]';
        }
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    }
}
