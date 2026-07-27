<?php
declare(strict_types=1);

namespace Agenduy\Core;

use PHPMailer\PHPMailer\PHPMailer;
use RuntimeException;

final class Mail
{
    private static ?string $lastError = null;

    public static function lastError(): ?string
    {
        return self::$lastError;
    }

    public static function isConfigured(): bool
    {
        return ProviderConfig::mailIsConfigured();
    }

    public static function send(
        string $to,
        string $subject,
        string $html,
        ?string $altText = null,
        ?int $idCommerce = null,
        array $attachments = []
    ): bool {
        self::$lastError = null;
        $mailCfg = ProviderConfig::mailConfig();
        $fromEmail = $mailCfg['from_email'];
        $fromName = $mailCfg['from_name'];

        if (empty($mailCfg['enabled'])) {
            self::$lastError = 'SMTP esta deshabilitado en Configuracion global.';
            self::log($to, $subject, 'failed', self::$lastError, $idCommerce);
            return false;
        }

        if ($fromEmail === '') {
            self::$lastError = 'Falta el email remitente (From).';
            self::log($to, $subject, 'failed', self::$lastError, $idCommerce);
            return false;
        }

        if (!class_exists(PHPMailer::class)) {
            self::$lastError = 'PHPMailer no instalado. Ejecutá composer install en el servidor.';
            self::log($to, $subject, 'failed', self::$lastError, $idCommerce);
            return false;
        }

        if ($mailCfg['host'] === '' || $mailCfg['username'] === '') {
            self::$lastError = 'SMTP incompleto: host o usuario vacío.';
            self::log($to, $subject, 'failed', self::$lastError, $idCommerce);
            return false;
        }

        if (($mailCfg['password'] ?? '') === '') {
            self::$lastError = 'Falta la contraseña SMTP. Configurala en Admin → SMTP, AGENDUY_SMTP_PASSWORD o Private/mail_secret.php';
            self::log($to, $subject, 'failed', self::$lastError, $idCommerce);
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
                } else {
                    $mailer->SMTPSecure = '';
                    $mailer->SMTPAutoTLS = false;
                }
                $mailer->SMTPOptions = [
                    'ssl' => [
                        'verify_peer'       => true,
                        'verify_peer_name'  => true,
                        'allow_self_signed' => false,
                    ],
                ];
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
                self::$lastError = $e->getMessage();
                self::log($to, $subject, 'failed', self::$lastError, $idCommerce);
                return false;
            }
        }

        self::$lastError = 'No se pudo inicializar el envío SMTP.';
        self::log($to, $subject, 'failed', self::$lastError, $idCommerce);
        return false;
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
