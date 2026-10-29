<?php
declare(strict_types=1);
namespace App\Services\Email;

use PHPMailer\PHPMailer\PHPMailer;

final class Mailer
{
    public function __construct(private ?array $account = null) {}
    public function send(array $email): ?string
    {
        if ((getenv('MAIL_DRY_RUN') ?: 'true') !== 'false') return null;
        $mail = $this->configuredMailer();
        $mail->addAddress($email['recipient']);
        foreach (['cc' => 'addCC', 'bcc' => 'addBCC'] as $field => $method) {
            foreach (array_filter(explode(',', $email[$field])) as $address) $mail->$method($address);
        }
        $mail->Subject = $email['subject'];
        $mail->Body = $email['body'];
        if (!empty($email['attachments'])) {
            $attachments = is_string($email['attachments']) ? json_decode($email['attachments'], true) : $email['attachments'];
            if (is_array($attachments)) {
                foreach ($attachments as $att) {
                    if (is_array($att) && !empty($att['path']) && is_file($att['path'])) {
                        $mail->addAttachment($att['path'], $att['name'] ?? basename($att['path']));
                    }
                }
            }
        }
        $mail->send();
        return $mail->getLastMessageID();
    }

    /**
     * Send multiple emails over a single persistent SMTP connection (10x faster bulk dispatch).
     */
    public function sendBatch(array $emails): array
    {
        if ((getenv('MAIL_DRY_RUN') ?: 'true') !== 'false') {
            $results = [];
            foreach ($emails as $email) {
                $results[$email['id']] = ['status' => 'dry_run', 'message_id' => null];
            }
            return $results;
        }

        $mail = $this->configuredMailer();
        $mail->SMTPKeepAlive = true;
        $results = [];

        try {
            foreach ($emails as $email) {
                $id = (int) $email['id'];
                try {
                    $mail->clearAllRecipients();
                    $mail->clearAttachments();
                    $mail->clearCustomHeaders();

                    $mail->addAddress($email['recipient']);
                    foreach (['cc' => 'addCC', 'bcc' => 'addBCC'] as $field => $method) {
                        if (!empty($email[$field])) {
                            foreach (array_filter(explode(',', (string) $email[$field])) as $address) {
                                $mail->$method(trim($address));
                            }
                        }
                    }
                    $mail->Subject = (string) ($email['subject'] ?? '');
                    $mail->Body = (string) ($email['body'] ?? '');

                    if (!empty($email['attachments'])) {
                        $attachments = is_string($email['attachments']) ? json_decode($email['attachments'], true) : $email['attachments'];
                        if (is_array($attachments)) {
                            foreach ($attachments as $att) {
                                if (is_array($att) && !empty($att['path']) && is_file($att['path'])) {
                                    $mail->addAttachment($att['path'], $att['name'] ?? basename($att['path']));
                                }
                            }
                        }
                    }

                    $mail->send();
                    $results[$id] = ['status' => 'sent', 'message_id' => $mail->getLastMessageID()];
                } catch (\Throwable $e) {
                    $results[$id] = ['status' => 'failed', 'error' => $e->getMessage()];
                }
            }
        } finally {
            $mail->smtpClose();
        }

        return $results;
    }

    /**
     * Send a transactional / administrative email directly (e.g. password reset OTP).
     */
    public function sendRaw(string $recipient, string $subject, string $htmlBody, string $textBody = ''): bool
    {
        if ((getenv('MAIL_DRY_RUN') ?: 'true') !== 'false') {
            return true;
        }
        $mail = $this->configuredMailer();
        $mail->addAddress($recipient);
        $mail->Subject = $subject;
        $mail->isHTML(true);
        $mail->Body = $htmlBody;
        if ($textBody !== '') {
            $mail->AltBody = $textBody;
        }
        return (bool) $mail->send();
    }

    /** Connect and authenticate only; never submit a message or change dry-run mode. */
    public function checkConnection(): void
    {
        $mail = $this->configuredMailer();
        try {
            if (!$mail->smtpConnect()) throw new \RuntimeException('SMTP connection failed.');
        } finally {
            $mail->smtpClose();
        }
    }

    private function configuredMailer(): PHPMailer
    {
        if (!class_exists(PHPMailer::class)) throw new \RuntimeException('Install Composer dependencies before sending.');
        $account = $this->account;

        $mail = new PHPMailer(true);
        $mail->isSMTP();

        if ($account && !empty($account['smtp_host']) && !empty($account['smtp_user'])) {
            $mail->Host = $account['smtp_host'];
            $mail->Port = (int) ($account['smtp_port'] ?? 465);
            $mail->SMTPAuth = true;
            $mail->Username = $account['smtp_user'];
            $mail->Password = $account['smtp_pass'] ?? '';
            $mail->SMTPSecure = $mail->Port === 465 ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Timeout = 30;
            $mail->CharSet = 'UTF-8';
            $fromName = !empty($account['name']) ? $account['name'] : (getenv('MAIL_FROM_NAME') ?: 'RSL Communications');
            $mail->setFrom($account['email'], $fromName);
            return $mail;
        }

        foreach (['SMTP_HOST', 'SMTP_USERNAME', 'SMTP_PASSWORD', 'MAIL_FROM_ADDRESS'] as $key) {
            if (!getenv($key)) throw new \RuntimeException('Mail configuration is incomplete.');
        }
        $mail->Host = getenv('SMTP_HOST');
        $mail->Port = (int) (getenv('SMTP_PORT') ?: 587);
        $mail->SMTPAuth = true;
        $mail->Username = getenv('SMTP_USERNAME');
        $mail->Password = getenv('SMTP_PASSWORD');
        $mail->SMTPSecure = $mail->Port === 465 ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Timeout = 30;
        $mail->CharSet = 'UTF-8';
        $mail->setFrom(getenv('MAIL_FROM_ADDRESS'), getenv('MAIL_FROM_NAME') ?: 'RSL Communications');
        return $mail;
    }
}
