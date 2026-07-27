<?php
declare(strict_types=1);

namespace Agenduy\Core;

/**
 * Configuración global de proveedores (SMTP, UltraMsg, etc.).
 */
final class ProviderConfig
{
    public static function get(string $provider): array
    {
        $db = Database::getInstance();
        $row = $db->fetchOne(
            'SELECT * FROM payment_provider_config WHERE provider = :p',
            [':p' => $provider]
        );
        if (!$row) {
            return ['is_enabled' => false, 'config' => []];
        }
        $cfg = json_decode((string)$row['config_json'], true);
        return [
            'is_enabled' => (int)($row['is_enabled'] ?? 0) === 1,
            'config'     => is_array($cfg) ? $cfg : [],
            'notes'      => (string)($row['notes'] ?? ''),
        ];
    }

    public static function save(string $provider, array $config, bool $enabled, ?int $updatedBy = null, string $notes = ''): void
    {
        $db = Database::getInstance();
        $json = json_encode($config, JSON_UNESCAPED_UNICODE);
        $now = date('Y-m-d H:i:s');
        $existing = $db->fetchOne(
            'SELECT id_config FROM payment_provider_config WHERE provider = :p',
            [':p' => $provider]
        );
        if ($existing) {
            $db->update('payment_provider_config', [
                'is_enabled'  => $enabled ? 1 : 0,
                'config_json' => $json,
                'notes'       => $notes,
                'updated_by'  => $updatedBy,
                'updated_at'  => $now,
            ], 'provider = :p', [':p' => $provider]);
        } else {
            $db->insert('payment_provider_config', [
                'provider'    => $provider,
                'is_enabled'  => $enabled ? 1 : 0,
                'config_json' => $json,
                'notes'       => $notes,
                'updated_by'  => $updatedBy,
            ]);
        }
    }

    public static function mailConfig(): array
    {
        $global = self::get('smtp');
        $cfg = Database::getInstance()->config()['mail'];
        $smtp = $global['config'];
        $password = (string)($smtp['password'] ?? '');
        if ($password === '') {
            $password = MailSecrets::smtpPassword();
        }
        if ($password === '') {
            $password = (string)($cfg['password'] ?? '');
        }
        $enabled = (int)($global['is_enabled'] ?? 0) === 1;

        return [
            'host'       => (string)($smtp['host'] ?? $cfg['host'] ?? ''),
            'port'       => (int)($smtp['port'] ?? $cfg['port'] ?? 465),
            'encryption' => (string)($smtp['encryption'] ?? $cfg['encryption'] ?? 'ssl'),
            'username'   => (string)($smtp['username'] ?? $cfg['username'] ?? ''),
            'password'   => $password,
            'from_email' => (string)($smtp['from_email'] ?? $cfg['from_email'] ?? ''),
            'from_name'  => (string)($smtp['from_name'] ?? $cfg['from_name'] ?? 'Agenduy'),
            'timeout'    => (int)($smtp['timeout'] ?? $cfg['timeout'] ?? 15),
            'enabled'    => $enabled,
        ];
    }

    /** @return array{ok:bool, configured:bool, enabled:bool, host:string, username:string, from_email:string, has_password:bool, phpmailer:bool} */
    public static function mailDiagnostics(): array
    {
        $mail = self::mailConfig();
        $configured = self::mailHasRequiredConfig($mail);
        return [
            'ok'           => !empty($mail['enabled']) && $configured,
            'configured'   => $configured,
            'enabled'      => !empty($mail['enabled']),
            'host'         => $mail['host'],
            'username'     => $mail['username'],
            'from_email'   => $mail['from_email'],
            'has_password' => ($mail['password'] ?? '') !== '',
            'phpmailer'    => class_exists(\PHPMailer\PHPMailer\PHPMailer::class),
        ];
    }

    public static function mailIsConfigured(?array $mail = null): bool
    {
        $mail ??= self::mailConfig();
        if (empty($mail['enabled'])) {
            return false;
        }
        return self::mailHasRequiredConfig($mail);
    }

    private static function mailHasRequiredConfig(array $mail): bool
    {
        if (($mail['from_email'] ?? '') === '') {
            return false;
        }
        if (($mail['host'] ?? '') === '' || ($mail['username'] ?? '') === '') {
            return false;
        }
        if (($mail['password'] ?? '') === '') {
            return false;
        }
        return class_exists(\PHPMailer\PHPMailer\PHPMailer::class);
    }

    public static function ultraMsgConfig(): array
    {
        $global = self::get('ultramsg');
        $cfg = $global['config'];
        return [
            'enabled'    => $global['is_enabled'],
            'instance_id'=> (string)($cfg['instance_id'] ?? ''),
            'token'      => (string)($cfg['token'] ?? ''),
        ];
    }
}
