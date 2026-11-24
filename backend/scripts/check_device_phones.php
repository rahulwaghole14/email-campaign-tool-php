<?php
/**
 * Compare Message API device phone numbers for current ready slots
 * vs historical device_ids that previously sent.
 *
 * Usage:
 *   php check_device_phones.php <base_url> <access_token>
 */

declare(strict_types=1);

$base = rtrim((string) ($argv[1] ?? ''), '/');
$token = (string) ($argv[2] ?? '');
if ($base === '' || $token === '') {
    fwrite(STDERR, "Usage: php check_device_phones.php <base_url> <access_token>\n");
    exit(1);
}

function api(string $base, string $token, string $path): array
{
    $url = $base . $path;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Authorization: Bearer ' . $token,
        ],
        CURLOPT_TIMEOUT => 30,
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false) {
        throw new RuntimeException('curl failed: ' . $err);
    }
    $json = json_decode((string) $body, true);
    return ['http' => $code, 'json' => is_array($json) ? $json : null, 'raw' => (string) $body];
}

function extractPhone(array $row): ?string
{
    foreach (['phone_number', 'phone', 'msisdn', 'mobile', 'number', 'jid', 'wid', 'user'] as $key) {
        $raw = $row[$key] ?? null;
        if (!is_string($raw) && !is_numeric($raw)) {
            continue;
        }
        $text = trim((string) $raw);
        if ($text === '') {
            continue;
        }
        if (str_contains($text, '@')) {
            $text = explode('@', $text, 2)[0];
        }
        $digits = preg_replace('/\D+/', '', $text) ?? '';
        if (strlen($digits) >= 8 && strlen($digits) <= 15) {
            return '+' . $digits;
        }
    }
    return null;
}

$histIds = [
    '834f4795-080c-40e3-b004-de62d0ae7969' => 'Whatsapp Campaign 1 (kept)',
    'b9b3ada7-0667-4af0-b187-cb1ce9e77748' => 'Whatsapp Campaign 2 (old)',
    '9b19a296-0402-4e46-9ea1-133d470f427e' => 'Whatsapp Campaign 3 (old)',
    'ea2b48a9-9f39-46bd-8d87-6e7643612e4b' => 'Whatsapp Campaign 4 (old)',
    '2f80cf83-bc41-4064-9630-0521344ca09a' => 'Whatsapp Campaign 5 (old)',
];

$newReady = [
    '834f4795-080c-40e3-b004-de62d0ae7969' => 'Whatsapp Campaign 1',
    'cef96f60-17d4-4f7e-8d64-81dcadb3945e' => 'Whatsapp Campaign 4',
    'd2bcf40a-c223-4011-a1b5-ac80e110ea70' => 'Whatsapp Campaign 5',
];

echo "=== Message API device list ===\n";
$list = api($base, $token, '/api/devices');
if ($list['http'] !== 200 || !is_array($list['json'])) {
    // try alternate paths
    foreach (['/devices', '/api/device', '/api/sessions', '/api/instances'] as $alt) {
        $try = api($base, $token, $alt);
        echo "TRY {$alt} => HTTP {$try['http']}\n";
        if ($try['http'] === 200 && is_array($try['json'])) {
            $list = $try;
            break;
        }
    }
}
echo 'HTTP ' . $list['http'] . "\n";

$devices = [];
$json = $list['json'] ?? [];
if (isset($json['data']) && is_array($json['data'])) {
    $devices = $json['data'];
} elseif (isset($json['devices']) && is_array($json['devices'])) {
    $devices = $json['devices'];
} elseif (is_array($json) && array_is_list($json)) {
    $devices = $json;
}

echo 'Devices in list: ' . count($devices) . "\n";
$rows = [];
foreach ($devices as $row) {
    if (!is_array($row)) {
        continue;
    }
    $id = (string) ($row['device_uuid'] ?? $row['uuid'] ?? $row['id'] ?? '');
    $name = (string) ($row['device_name'] ?? $row['name'] ?? '');
    $status = (string) ($row['status'] ?? '');
    $connected = !empty($row['connected']) || strtolower($status) === 'connected';
    $phone = extractPhone($row);
    $rows[$id] = [
        'name' => $name,
        'status' => $status,
        'connected' => $connected,
        'phone' => $phone,
        'raw_keys' => array_keys($row),
        'phone_number_field' => $row['phone_number'] ?? null,
        'jid' => $row['jid'] ?? ($row['wid'] ?? null),
    ];
    printf(
        "%s | %-22s | status=%-12s | connected=%s | phone=%s | phone_number=%s\n",
        $id !== '' ? $id : '(no-id)',
        $name,
        $status,
        $connected ? 'yes' : 'no',
        $phone ?? 'NULL',
        isset($row['phone_number']) ? json_encode($row['phone_number']) : 'absent'
    );
}

echo "\n=== Per-device status probe (ready + old hist) ===\n";
$probeIds = array_unique(array_merge(array_keys($newReady), array_keys($histIds)));
foreach ($probeIds as $id) {
    $label = $newReady[$id] ?? $histIds[$id] ?? $id;
    $paths = [
        '/api/devices/' . rawurlencode($id),
        '/api/devices/' . rawurlencode($id) . '/status',
        '/api/device/' . rawurlencode($id),
        '/api/sessions/' . rawurlencode($id),
    ];
    $found = null;
    foreach ($paths as $p) {
        $res = api($base, $token, $p);
        if ($res['http'] === 200 && is_array($res['json'])) {
            $found = ['path' => $p, 'json' => $res['json']];
            break;
        }
    }
    if ($found === null) {
        echo "{$id} ({$label}): no detail endpoint answered 200\n";
        continue;
    }
    $payload = $found['json'];
    $data = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;
    $phone = is_array($data) ? extractPhone($data) : null;
    echo sprintf(
        "%s (%s) via %s => phone=%s phone_number=%s connected=%s\n",
        $id,
        $label,
        $found['path'],
        $phone ?? 'NULL',
        isset($data['phone_number']) ? json_encode($data['phone_number']) : 'absent',
        !empty($data['connected']) || (($data['status'] ?? '') === 'connected') ? 'yes' : 'no'
    );
}

echo "\n=== Number match (by phone digits) ===\n";
$byPhone = [];
foreach ($rows as $id => $r) {
    if (($r['phone'] ?? null) === null) {
        continue;
    }
    $byPhone[$r['phone']][] = $id . ' (' . $r['name'] . ')';
}
if ($byPhone === []) {
    echo "Cannot verify same numbers: Message API returned no phone_number/jid for any device.\n";
    echo "Desk sticky still keys off device_id only — same SIM + new device_id = NEW owner for sticky.\n";
} else {
    foreach ($byPhone as $phone => $ids) {
        echo $phone . ' => ' . implode(', ', $ids) . "\n";
    }
}
