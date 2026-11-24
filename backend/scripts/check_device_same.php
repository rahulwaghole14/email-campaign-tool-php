<?php
/**
 * Compare current linked device_ids vs historical whatsapp_sends device_ids
 * from a captured CDP/API JSON dump, OR fetch live if --live with cookie jar.
 *
 * Usage:
 *   php check_device_same.php path/to/cdp.json
 *   php check_device_same.php --from-api-json path/to/devices-and-sent.json
 */

declare(strict_types=1);

$path = $argv[1] ?? '';
if ($path === '' || !is_file($path)) {
    fwrite(STDERR, "Usage: php check_device_same.php <cdp-or-api-json>\n");
    exit(1);
}

$raw = file_get_contents($path);
$data = json_decode((string) $raw, true);
if (!is_array($data)) {
    fwrite(STDERR, "Invalid JSON\n");
    exit(1);
}

// Support CDP Runtime.evaluate wrapper or flat {devices, sentSample}
$value = $data['result']['result']['value'] ?? $data['result']['value'] ?? $data;
$devices = $value['devices'] ?? $value['device']['devices'] ?? [];
$msgs = $value['sentSample']['messages'] ?? $value['messages'] ?? [];

if (!is_array($devices) || $devices === []) {
    fwrite(STDERR, "No devices found in JSON\n");
    exit(1);
}

echo "=== CURRENT LINKED DEVICES ===\n";
$current = [];
foreach ($devices as $d) {
    $id = (string) ($d['id'] ?? $d['device_id'] ?? '');
    if ($id === '') {
        continue;
    }
    $current[$id] = $d;
    printf(
        "%s | %-22s | %-14s | linked=%s\n",
        $id,
        (string) ($d['name'] ?? ''),
        (string) ($d['status'] ?? ''),
        (string) ($d['linked_at'] ?? '')
    );
}

echo "\n=== HISTORICAL device_id FROM SENT (unique) ===\n";
$hist = [];
foreach ($msgs as $m) {
    if (!is_array($m)) {
        continue;
    }
    $id = (string) ($m['device_id'] ?? '');
    if ($id === '') {
        continue;
    }
    if (!isset($hist[$id])) {
        $hist[$id] = [
            'account' => (string) ($m['account_name'] ?? ''),
            'count' => 0,
            'last' => (string) ($m['created_at'] ?? ''),
        ];
    }
    $hist[$id]['count']++;
    $created = (string) ($m['created_at'] ?? '');
    if ($created > $hist[$id]['last']) {
        $hist[$id]['last'] = $created;
    }
}

foreach ($hist as $id => $h) {
    $same = isset($current[$id]) ? 'SAME slot still present' : 'GONE (new id after reconnect)';
    printf(
        "%s | %-22s | sends=%d | last=%s | %s\n",
        $id,
        $h['account'],
        $h['count'],
        $h['last'],
        $same
    );
}

$overlap = array_values(array_intersect(array_keys($hist), array_keys($current)));
$ready = array_values(array_filter($current, static fn ($d) => ($d['status'] ?? '') === 'ready'));

echo "\n=== VERDICT ===\n";
echo 'Current ready count: ' . count($ready) . "\n";
echo 'Historical unique device_ids in sample: ' . count($hist) . "\n";
echo 'Overlap (same device_id still linked): ' . count($overlap) . "\n";

if ($overlap === []) {
    echo "NOT THE SAME slots. Reconnect created NEW device_ids.\n";
    echo "Sticky follow-ups will NOT continue on old owners unless those exact IDs return Ready.\n";
} else {
    echo "These same device_ids still exist:\n";
    foreach ($overlap as $id) {
        $name = (string) ($current[$id]['name'] ?? '');
        echo "  - {$id} ({$name})\n";
    }
}

echo "\nReady now:\n";
foreach ($ready as $d) {
    $id = (string) ($d['id'] ?? $d['device_id'] ?? '');
    printf("  %s  %s\n", $id, (string) ($d['name'] ?? ''));
}

echo "\nName-label match (Campaign N) does NOT mean same sticky owner — only device_id does.\n";
