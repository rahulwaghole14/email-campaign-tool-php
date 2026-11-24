<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use App\Services\WhatsApp\DeviceLink;
use App\Services\WhatsApp\WhatsAppTables;

function check(bool $ok, string $msg): void
{
    if (!$ok) {
        throw new RuntimeException('FAILED: ' . $msg);
    }
}

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
App\Support\Database::migrate($db);
WhatsAppTables::ensure($db);

$db->exec("INSERT INTO whatsapp_devices (device_id, name, phone, status, updated_at, held) VALUES ('keep-dev-1', 'Whatsapp Campaign 1', NULL, 'ready', '2026-01-01T00:00:00+00:00', 0)");

$link = new class($db) extends DeviceLink {
    /** @var list<string> */
    public array $deleted = [];

    public function __construct(PDO $db)
    {
        parent::__construct($db);
    }

    public function roster(): array
    {
        $held = WhatsAppTables::heldIds($this->db);
        $rows = [[
            'id' => 'keep-dev-1',
            'name' => 'Whatsapp Campaign 1',
            'phone' => null,
            'status' => isset($held['keep-dev-1']) ? 'disconnected' : 'ready',
            'seen_at' => null,
            'linked_at' => null,
        ]];
        WhatsAppTables::saveRoster($this->db, $rows);
        return ['devices' => $rows, 'slots' => 5];
    }

    public function disconnect(?string $id = null): array
    {
        // Mirror production: hold the slot, never DELETE.
        $id = $id !== null && $id !== '' ? $id : 'keep-dev-1';
        $this->roster();
        WhatsAppTables::holdDevice($this->db, $id);
        return $this->roster();
    }
};

$before = $link->roster();
check($before['devices'][0]['status'] === 'ready', 'starts ready');
check((int) $db->query("SELECT held FROM whatsapp_devices WHERE device_id = 'keep-dev-1'")->fetchColumn() === 0, 'not held yet');

$after = $link->disconnect('keep-dev-1');
check($after['devices'][0]['id'] === 'keep-dev-1', 'same device_id after disconnect');
check($after['devices'][0]['status'] === 'disconnected', 'shows disconnected after disconnect');
check((int) $db->query("SELECT held FROM whatsapp_devices WHERE device_id = 'keep-dev-1'")->fetchColumn() === 1, 'held flag set');
check($link->deleted === [], 'must not DELETE the Message API device');

// Live roster still "connected" would flip to ready without hold — hold must win.
WhatsAppTables::saveRoster($db, [[
    'id' => 'keep-dev-1',
    'name' => 'Whatsapp Campaign 1',
    'phone' => null,
    'status' => 'ready',
]]);
check((string) $db->query("SELECT status FROM whatsapp_devices WHERE device_id = 'keep-dev-1'")->fetchColumn() === 'disconnected', 'held blocks ready overwrite');

WhatsAppTables::releaseHold($db, 'keep-dev-1');
WhatsAppTables::saveRoster($db, [[
    'id' => 'keep-dev-1',
    'name' => 'Whatsapp Campaign 1',
    'phone' => null,
    'status' => 'ready',
]]);
check((int) $db->query("SELECT held FROM whatsapp_devices WHERE device_id = 'keep-dev-1'")->fetchColumn() === 0, 'hold cleared');
check((string) $db->query("SELECT status FROM whatsapp_devices WHERE device_id = 'keep-dev-1'")->fetchColumn() === 'ready', 'ready again after Pair release');

echo "OK: disconnect keeps device_id and holds until Pair\n";
