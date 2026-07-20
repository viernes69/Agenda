<?php
declare(strict_types=1);

namespace Agenduy\Core;

use PHPMailer\PHPMailer\PHPMailer;
use RuntimeException;

final class Mail
{
    public static function send(
        string $to,
        string $subject,
        string $html,
        ?string $altText = null,
        ?int $idCommerce = null,
        array $attachments = []
    ): bool {
        $mailCfg = ProviderConfig::mailConfig();
        $fromEmail = $mailCfg['from_email'];
        $fromName = $mailCfg['from_name'];

        if ($fromEmail === '') {
            self::log($to, $subject, 'failed', 'SMTP no configurado', $idCommerce);
            return false;
        }

        if (class_exists(PHPMailer::class) && $mailCfg['host'] !== '' && $mailCfg['username'] !== '') {
            try {
                $mailer = new PHPMailer(true);
                $mailer->isSMTP();
                $mailer->Host = $mailCfg['host'];
                $mailer->SMTPAuth = true;
                $mailer->Username = $mailCfg['username'];
                $mailer->Password = $mailCfg['password'];
                $mailer->Port = $mailCfg['port'];
                $mailer->Timeout = $mailCfg['timeout'];
                $enc = strtolower($mailCfg['encryption']);
                if ($enc === 'ssl') {
                    $mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                } elseif ($enc === 'tls') {
                    $mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                }
                $mailer->CharSet = 'UTF-8';
                $mailer->setFrom($fromEmail, $fromName);
                $mailer->addAddress($to);
                $mailer->isHTML(true);
                $mailer->Subject = $subject;
                $mailer->Body = $html;
                $mailer->AltBody = $altText ?? strip_tags($html);
                foreach ($attachments as $att) {
                    $name = (string)($att['name'] ?? 'archivo');
                    $data = (string)($att['data'] ?? '');
                    $mime = (string)($att['mime'] ?? 'application/octet-stream');
                    if ($data !== '') {
                        $mailer->addStringAttachment($data, $name, PHPMailer::ENCODING_BASE64, $mime);
                    }
                }
                $mailer->send();
                self::log($to, $subject, 'sent', null, $idCommerce);
                return true;
            } catch (\Throwable $e) {
                self::log($to, $subject, 'failed', $e->getMessage(), $idCommerce);
                return false;
            }
        }

        $fromHeader = sprintf("From: %s <%s>\r\n", $fromName, $fromEmail);
        $headers = $fromHeader . "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n";
        $sent = @mail($to, $subject, $html, $headers);
        self::log($to, $subject, $sent ? 'sent' : 'failed', $sent ? null : 'mail() returned false', $idCommerce);
        return $sent;
    }

    public static function log(string $to, string $subject, string $status, ?string $error, ?int $idCommerce = null): void
    {
        try {
            $db = Database::getInstance();
            $db->insert('notifications_log', [
                'id_commerce'   => $idCommerce,
                'channel'       => 'email',
                'recipient'     => $to,
                'subject'       => $subject,
                'body'          => '',
                'status'        => $status,
                'error_message' => $error,
                'sent_at'       => $status === 'sent' ? date('Y-m-d H:i:s') : null,
            ]);
        } catch (\Throwable $e) {
            error_log('[Agenduy.Mail.log] ' . $e->getMessage());
        }
    }
}
