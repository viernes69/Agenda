<?php

if (!function_exists('agenduy_mail_secret')) {
    function agenduy_mail_secret(): string {
        $envPassword = getenv('AGENDUY_SMTP_PASSWORD');
        if (is_string($envPassword) && trim($envPassword) !== '') {
            return trim($envPassword);
        }

        $root = dirname(__DIR__, 3);
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
                $value = include $candidate;
            } else {
                $value = file_get_contents($candidate);
            }
            if (is_array($value) && isset($value['password'])) {
                $value = $value['password'];
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

return [
    'host' => 'mail.appsuy.net',
    'port' => 465,
    'encryption' => 'ssl', // ssl (465) o tls (587)
    'username' => 'notificaciones@agenduy.uy',
    'password' => agenduy_mail_secret(),
    'from_email' => 'notificaciones@agenduy.uy',
    'from_name' => 'Agenduy Notificaciones',
    'timeout' => 15,
];
