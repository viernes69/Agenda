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
    require_once $vendorAutoload;
}

\Agenduy\Core\CommerceSetup::guardDashboardAccess();
\Agenduy\Core\AdminBrand::injectDashboardBranding();

return $config;
