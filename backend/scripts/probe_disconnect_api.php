<?php
declare(strict_types=1);

$base = rtrim((string) ($argv[1] ?? ''), '/');
$token = (string) ($argv[2] ?? '');
$id = (string) ($argv[3] ?? '');
if ($base === '' || $token === '' || $id === '') {
    fwrite(STDERR, "Usage: php probe_disconnect_api.php <base> <token> <device_id>\n");
    exit(1);
}

$paths = [
    ['POST', '/api/devices/' . rawurlencode($id) . '/logout', null],
    ['POST', '/api/devices/' . rawurlencode($id) . '/disconnect', null],
    ['POST', '/api/devices/' . rawurlencode($id) . '/unlink', null],
    ['POST', '/api/devices/' . rawurlencode($id) . '/reset', null],
    ['POST', '/api/devices/' . rawurlencode($id) . '/restart', null],
    ['POST', '/api/devices/' . rawurlencode($id) . '/stop', null],
    ['POST', '/api/devices/' . rawurlencode($id) . '/close', null],
    ['POST', '/api/devices/' . rawurlencode($id) . '/session/logout', null],
    ['DELETE', '/api/devices/' . rawurlencode($id) . '/session', null],
    ['DELETE', '/api/devices/' . rawurlencode($id) . '/connection', null],
    ['POST', '/api/device/logout', ['device_uuid' => $id]],
    ['POST', '/api/device/disconnect', ['device_uuid' => $id]],
    ['POST', '/api/logout-device', ['device_uuid' => $id]],
    ['POST', '/unofficial/logout', ['device_id' => $id]],
    ['POST', '/unofficial/disconnect', ['device_id' => $id]],
    ['POST', '/unofficial/logout-device', ['device_id' => $id, 'device_uuid' => $id]],
    ['PUT', '/api/devices/' . rawurlencode($id), ['status' => 'disconnected']],
    ['PATCH', '/api/devices/' . rawurlencode($id), ['status' => 'disconnected']],
    ['POST', '/api/devices/' . rawurlencode($id) . '/status', ['status' => 'disconnected']],
];

foreach ($paths as [$method, $path, $body]) {
    $ch = curl_init($base . $path);
    $headers = [
        'Accept: application/json',
        'Authorization: Bearer ' . $token,
    ];
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_TIMEOUT => 15,
    ];
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        $opts[CURLOPT_POSTFIELDS] = json_encode($body);
    }
    $opts[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($ch, $opts);
    $raw = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $snip = substr(preg_replace('/\s+/', ' ', $raw) ?? $raw, 0, 160);
    echo "{$method} {$path} => HTTP {$code} {$snip}\n";
}
