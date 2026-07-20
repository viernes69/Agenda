<?php
/**
 * Agenduy - Configuración central
 *
 * IMPORTANTE: las credenciales SMTP, llaves de encriptación y similares
 * se leen PRIMERO desde variables de entorno. Solo si no están se
 * recurren a valores por defecto (pensados para desarrollo local).
 */

declare(strict_types=1);

return [

    'app' => [
        'name'        => 'Agenduy',
        'env'         => getenv('AGENDUY_ENV') ?: 'production', // production | development
        'debug'       => filter_var(getenv('AGENDUY_DEBUG') ?: 'false', FILTER_VALIDATE_BOOLEAN),
        'url_base'    => getenv('AGENDUY_URL_BASE') ?: '',
        'timezone'    => 'America/Montevideo',
        'session_name'=> 'AGENDUY_SESSID',
        'csrf_lifetime_min' => 240, // 4 horas
    ],

    'db' => [
        // Ruta ABSOLUTA al archivo SQLite. Reside FUERA del document root
        // para que no sea descargable por la web.
        'path' => getenv('AGENDUY_DB_PATH')
            ?: dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'agenduy.db',
    ],

    'security' => [
        // Llave de 32 bytes (bin2hex(random_bytes(32))) para encriptar API keys.
        // Cámbiala en producción. Si no se define, se autogenera y se guarda.
        'encryption_key' => getenv('AGENDUY_ENCRYPTION_KEY') ?: '',
        'session_secure_cookie' => filter_var(
            getenv('AGENDUY_SESSION_SECURE') ?: 'false',
            FILTER_VALIDATE_BOOLEAN
        ),
        'session_httponly' => true,
        'session_samesite' => 'Lax',
        'login_max_attempts' => 5,
        'login_lockout_minutes' => 15,
    ],

    'mail' => [
        'host'       => getenv('AGENDUY_SMTP_HOST') ?: 'mail.appsuy.net',
        'port'       => (int) (getenv('AGENDUY_SMTP_PORT') ?: 465),
        'encryption' => getenv('AGENDUY_SMTP_ENCRYPTION') ?: 'ssl',
        'username'   => getenv('AGENDUY_SMTP_USER') ?: 'notificaciones@agenduy.uy',
        'password'   => getenv('AGENDUY_SMTP_PASSWORD') ?: '',
        'from_email' => getenv('AGENDUY_SMTP_FROM_EMAIL') ?: 'notificaciones@agenduy.uy',
        'from_name'  => getenv('AGENDUY_SMTP_FROM_NAME')  ?: 'Agenduy Notificaciones',
        'timeout'    => 15,
    ],

    'uploads' => [
        'base_dir'     => dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'uploads',
        'receipts_dir' => 'receipts',
        'max_size_mb'  => 8,
    ],

    'paths' => [
        'storage'   => dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage',
        'logs'      => dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs',
        'backups'   => dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'backups',
        'templates' => dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'template',
    ],
];
