<?php
declare(strict_types=1);

/**
 * Migra un tenant legacy (database.php) a SQLite sin duplicar.
 * Uso: php bin/migrate-tenant.php terapeuta-luck
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$projectRoot = dirname(__DIR__);
require $projectRoot . '/src/Core/bootstrap.php';

use Agenduy\Core\TenantMigrator;

$slug = $argv[1] ?? 'terapeuta-luck';
try {
    $result = TenantMigrator::import($slug, $projectRoot);
    echo $result['message'] . PHP_EOL;
    echo "Servicios añadidos: {$result['services_added']}" . PHP_EOL;
    echo "OK migrate {$slug}" . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
