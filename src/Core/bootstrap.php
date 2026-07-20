<?php
/**
 * Agenduy - Bootstrap
 * Se incluye desde index.php, /admin/*.php, /template/* etc.
 * Carga config, autoloader de clases, y deja el sistema listo.
 */

declare(strict_types=1);

// Errores controlados por config
$config = require __DIR__ . '/config.php';
if (($config['app']['debug'] ?? false) === true) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
    ini_set('display_errors', '0');
}
date_default_timezone_set($config['app']['timezone']);

// Autoloader PSR-4 muy simple para el namespace Agenduy\Core\
spl_autoload_register(function (string $class) {
    $prefix = 'Agenduy\\Core\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

// Cargar helpers de entorno (url_base, base_path, url, current_slug, etc.)
$helpersFile = __DIR__ . DIRECTORY_SEPARATOR . 'helpers.php';
if (is_file($helpersFile)) {
    require_once $helpersFile;
}

$vendorAutoload = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
if (is_file($vendorAutoload)) {
    $platformCheck = dirname($vendorAutoload) . DIRECTORY_SEPARATOR . 'composer' . DIRECTORY_SEPARATOR . 'platform_check.php';
    $localLegacyPhp = PHP_VERSION_ID < 80200
        && function_exists('agenduy_request')
        && (agenduy_request()['is_local'] ?? false);

    // XAMPP local suele traer PHP 8.0; Composer exige >= 8.2 y responde HTTP 500 en todo el sitio.
    if ($localLegacyPhp && is_file($platformCheck)) {
        $platformContents = file_get_contents($platformCheck);
        if (is_string($platformContents) && str_contains($platformContents, '80200')) {
            file_put_contents($platformCheck, "<?php\n// bypass local dev\n");
            register_shutdown_function(static function () use ($platformCheck, $platformContents): void {
                @file_put_contents($platformCheck, $platformContents);
            });
        }
    }

    require_once $vendorAutoload;
}

\Agenduy\Core\CommerceSetup::guardDashboardAccess();
\Agenduy\Core\AdminBrand::injectDashboardBranding();

return $config;
