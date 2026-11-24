<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use App\Support\Database;

try {
    $db = Database::connect();
    Database::migrate($db);

    $total = (int) $db->query("SELECT COUNT(*) FROM whatsapp_sends")->fetchColumn();
    $sent = (int) $db->query("SELECT COUNT(*) FROM whatsapp_sends WHERE status = 'sent'")->fetchColumn();
    echo "Current whatsapp_sends rows: total={$total}, sent={$sent}\n";

    $db->exec("DELETE FROM whatsapp_sends");
    $after = (int) $db->query("SELECT COUNT(*) FROM whatsapp_sends")->fetchColumn();
    echo "Cleared whatsapp_sends table. Remaining: {$after}\n";
    echo "All WhatsApp test history reset. All contacts will now be Free pick.\n";
} catch (\Throwable $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}
