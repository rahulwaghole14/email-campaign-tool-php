<?php
require dirname(__DIR__) . '/bootstrap.php';
$db = App\Support\Database::connect();
$rows = $db->query('SELECT mail_account_id, to_email, COUNT(*) as cnt FROM inbox_emails GROUP BY mail_account_id, to_email')->fetchAll(PDO::FETCH_ASSOC);
print_r($rows);

