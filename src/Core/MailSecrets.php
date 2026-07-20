<?php
declare(strict_types=1);

namespace Agenduy\Core;

/**
 * Resuelve la contraseña SMTP desde env o archivos privados (fuera de Git).
 * Misma lógica que template/src/config/mail.php en tenants.
 */
final class MailSecrets
{
    public static function smtpPassword(): string
    {
        $env = getenv('AGENDUY_SMTP_PASSWORD');
        if (is_string($env) && trim($env) !== '') {
            return trim($env);
        }

        $root = dirname(__DIR__, 2);
        $candidates = [
            $root . DIRECTORY_SEPARATOR . 'Private' . DIRECTORY_SEPARATOR . 'mail_secret.php',
            $root . DIRECTORY_SEPARATOR . 'Private' . DIRECTORY_SEPARATOR . '.mail_secret',
        ];

        foreach ($candidates as $candidate) {
            if (!is_file($candidate)) {
                continue;
            }
            $value = null;
            if (substr($candidate, -4) === '.php') {
                $included = include $candidate;
                $value = is_array($included) ? ($included['password'] ?? null) : $included;
            } else {
                $value = file_get_contents($candidate);
            }
            if (is_string($value)) {
                $value = trim($value);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return '';
    }
}
