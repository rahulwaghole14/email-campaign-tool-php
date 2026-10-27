<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';
use App\Support\Database;
use App\Services\Email\EmailQueue;
use App\Services\Email\Mailer;
putenv('DB_CONNECTION=sqlite');
putenv('DB_DATABASE=:memory:');
putenv('MAIL_DRY_RUN=true');
$db = Database::connect();
Database::migrate($db);
$queue = new EmailQueue($db);
$checks = 0;
function check(bool $condition, string $message): void {
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
$input = ['recipient' => 'recipient@example.com', 'subject' => 'Test message', 'body' => 'Test body', 'idempotency_key' => 'test-request-123456'];
$id = $queue->enqueue($input);
check($id === $queue->enqueue($input), 'Repeated submission must return the same email.');
check((int) $db->query('SELECT COUNT(*) FROM emails')->fetchColumn() === 1, 'Duplicate email created.');
check((int) $db->query('SELECT COUNT(*) FROM activity')->fetchColumn() === 1, 'Duplicate queue event created.');
foreach ([['recipient' => 'invalid'], ['cc' => 'bad-address'], ['subject' => "Injected\r\nHeader"], ['body' => ''], ['idempotency_key' => 'bad']] as $change) {
    try { $queue->enqueue(array_replace($input, $change)); throw new RuntimeException('Invalid input accepted.'); }
    catch (InvalidArgumentException $e) { $checks++; }
}
try { $queue->retry($id); throw new RuntimeException('Pending email accepted for retry.'); }
catch (InvalidArgumentException $e) { $checks++; }
check((new Mailer())->send($input) === null, 'Dry run must not send mail.');
$db->exec("UPDATE emails SET status = 'failed', attempts = 1");
$queue->retry($id);
check($db->query('SELECT status FROM emails')->fetchColumn() === 'pending', 'Retry did not queue email.');
$db->exec("UPDATE emails SET status = 'failed', attempts = 3");
try { $queue->retry($id); throw new RuntimeException('Attempt limit bypassed.'); }
catch (InvalidArgumentException $e) { $checks++; }
use App\Services\AdminSetup;
check(AdminSetup::isLocal(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'localhost:8085']), 'Local setup rejected.');
check(!AdminSetup::isLocal(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'untrusted.example']), 'Untrusted hostname accepted.');
check(!AdminSetup::isLocal(['REMOTE_ADDR' => '192.168.1.5', 'HTTP_HOST' => 'localhost']), 'Remote setup accepted.');
$file = tempnam(sys_get_temp_dir(), 'admin-test-');
$oldHash = getenv('ADMIN_PASSWORD_HASH');
putenv('ADMIN_PASSWORD_HASH=');
try {
    file_put_contents($file, "DB_PASSWORD=preserved\nADMIN_PASSWORD_HASH=\n");
    foreach ([['short', 'short'], ['123456789', '123456789'], ['abcdef', 'different']] as [$password, $confirmation]) {
        try { AdminSetup::create($file, $password, $confirmation); throw new RuntimeException('Invalid password accepted.'); }
        catch (InvalidArgumentException $e) { $checks++; }
    }
    foreach (['abcdef', 'abcdefg', 'abcdefgh', 'éééééé'] as $validPassword) {
        file_put_contents($file, "DB_PASSWORD=preserved\nADMIN_PASSWORD_HASH=\n");
        AdminSetup::create($file, $validPassword, $validPassword);
        preg_match('/^ADMIN_PASSWORD_HASH=(.*)$/m', file_get_contents($file), $validMatch);
        check(password_verify($validPassword, trim($validMatch[1])), 'Valid password rejected.');
    }
    $saved = file_get_contents($file);
    preg_match('/^ADMIN_PASSWORD_HASH=(.*)$/m', $saved, $match);
    check(password_verify($validPassword, trim($match[1])), 'Password hash invalid.');
    check(str_contains($saved, 'DB_PASSWORD=preserved'), 'Existing settings overwritten.');
    try { AdminSetup::create($file, 'newpass', 'newpass'); throw new RuntimeException('Administrator overwritten.'); }
    catch (InvalidArgumentException $e) { $checks++; }
} finally {
    unlink($file);
    $oldHash === false ? putenv('ADMIN_PASSWORD_HASH') : putenv('ADMIN_PASSWORD_HASH=' . $oldHash);
}
echo "$checks checks passed.\n";
