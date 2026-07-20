<?php
declare(strict_types=1);

/**
 * Migra assets de {slug}/src/img/* → src/media/commerce/{id}/ (fase 2).
 * Uso: php bin/migrate-commerce-assets.php [slug|--all]
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$projectRoot = dirname(__DIR__);
require $projectRoot . '/src/Core/bootstrap.php';

use Agenduy\Core\CommerceStorage;
use Agenduy\Core\Database;

$arg = $argv[1] ?? '--all';
$db = Database::getInstance();

$slugs = [];
if ($arg === '--all') {
    foreach ($db->fetchAll('SELECT id_commerce, slug FROM commerces ORDER BY slug') as $row) {
        $slugs[] = ['id' => (int)$row['id_commerce'], 'slug' => (string)$row['slug']];
    }
} else {
    if (!preg_match('/^[a-z0-9][a-z0-9-]*$/', $arg)) {
        fwrite(STDERR, "Slug inválido.\n");
        exit(1);
    }
    $row = $db->fetchOne('SELECT id_commerce, slug FROM commerces WHERE slug = :s', [':s' => $arg]);
    if (!$row) {
        fwrite(STDERR, "Comercio no encontrado: {$arg}\n");
        exit(1);
    }
    $slugs[] = ['id' => (int)$row['id_commerce'], 'slug' => (string)$row['slug']];
}

$totalCopied = 0;
$totalUpdated = 0;

foreach ($slugs as $item) {
    echo "Migrando {$item['slug']} (#{$item['id']})..." . PHP_EOL;
    $stats = CommerceStorage::migrateFromTenantFolder($item['id'], $item['slug']);
    echo "  copiados: {$stats['copied']}, actualizados en DB: {$stats['updated']}" . PHP_EOL;
    foreach ($stats['errors'] as $err) {
        echo "  ERROR: {$err}" . PHP_EOL;
    }
    $totalCopied += $stats['copied'];
    $totalUpdated += $stats['updated'];
}

echo PHP_EOL . "Total: {$totalCopied} archivos copiados, {$totalUpdated} registros DB actualizados." . PHP_EOL;
exit(0);
