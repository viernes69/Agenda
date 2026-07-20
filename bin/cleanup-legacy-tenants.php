<?php
declare(strict_types=1);

/**
 * Limpia carpetas tenant legacy de prueba (sin tocar SQLite central).
 *
 * Uso:
 *   php bin/cleanup-legacy-tenants.php              # listar
 *   php bin/cleanup-legacy-tenants.php --delete=terap,terapeuta-luck,template_curso
 *   php bin/cleanup-legacy-tenants.php --delete=terap --yes
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$projectRoot = dirname(__DIR__);
require $projectRoot . '/src/Core/bootstrap.php';

use Agenduy\Core\Database;
use Agenduy\Core\TenantAudit;

$deleteArg = null;
$confirmed = in_array('--yes', $argv ?? [], true);
foreach ($argv ?? [] as $arg) {
    if (str_starts_with($arg, '--delete=')) {
        $deleteArg = substr($arg, strlen('--delete='));
    }
}

$db = Database::getInstance();
$registered = $db->fetchAll('SELECT slug, id_commerce, nombre FROM commerces ORDER BY slug COLLATE NOCASE ASC');
$registeredSlugs = [];
foreach ($registered as $row) {
    $registeredSlugs[(string)$row['slug']] = $row;
}

$skip = ['template', 'template_curso', 'admin', 'src', 'public', 'assets', 'Private', 'storage', 'bin', 'tests', 'categorias', 'ubicaciones', 'vendor', 'docs', 'tools', 'cgi-bin', '.well-known'];
$entries = scandir($projectRoot);
$legacyFolders = [];
if ($entries !== false) {
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..' || in_array($entry, $skip, true)) {
            continue;
        }
        if (\Agenduy\Core\TenantConfig::isIgnoredTenantSlug($entry)) {
            continue;
        }
        $path = $projectRoot . DIRECTORY_SEPARATOR . $entry;
        if (!is_dir($path)) {
            continue;
        }
        $panel = $path . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'dashboard' . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'index.php';
        $dbFile = $path . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'db' . DIRECTORY_SEPARATOR . 'database.php';
        if (!is_file($panel) && !is_file($dbFile)) {
            continue;
        }
        $legacyFolders[] = [
            'slug' => $entry,
            'registered' => isset($registeredSlugs[$entry]),
            'id_commerce' => (int)($registeredSlugs[$entry]['id_commerce'] ?? 0),
            'nombre' => (string)($registeredSlugs[$entry]['nombre'] ?? ''),
            'path' => $path,
        ];
    }
}

echo "=== Carpetas tenant legacy detectadas ===" . PHP_EOL;
if ($legacyFolders === []) {
    echo "No hay carpetas legacy en la raíz del proyecto." . PHP_EOL;
    exit(0);
}

foreach ($legacyFolders as $folder) {
    $tag = $folder['registered'] ? 'SQLite OK' : 'sin registro SQLite';
    echo sprintf(
        "  - %s (%s) #%d %s\n",
        $folder['slug'],
        $tag,
        $folder['id_commerce'],
        $folder['nombre']
    );
}

echo PHP_EOL . "Nota: borrar la carpeta NO elimina el comercio en SQLite. El panel central seguirá funcionando." . PHP_EOL;

if ($deleteArg === null) {
    echo PHP_EOL . "Para borrar: php bin/cleanup-legacy-tenants.php --delete=slug1,slug2 --yes" . PHP_EOL;
    exit(0);
}

$targets = array_values(array_filter(array_map('trim', explode(',', $deleteArg))));
if ($targets === []) {
    fwrite(STDERR, "Ningún slug válido en --delete.\n");
    exit(1);
}

if (!$confirmed) {
    fwrite(STDERR, "Agregá --yes para confirmar la eliminación.\n");
    exit(1);
}

function deleteDirectory(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }
    $items = scandir($directory);
    if ($items === false) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $directory . DIRECTORY_SEPARATOR . $item;
        is_dir($path) ? deleteDirectory($path) : @unlink($path);
    }
    @rmdir($directory);
}

$deleted = 0;
foreach ($targets as $slug) {
    if (!preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug)) {
        echo "  ! slug inválido: {$slug}" . PHP_EOL;
        continue;
    }
    $path = $projectRoot . DIRECTORY_SEPARATOR . $slug;
    if (!is_dir($path)) {
        echo "  - {$slug}: no existe, omitido" . PHP_EOL;
        continue;
    }
    deleteDirectory($path);
    echo "  ✓ {$slug}: carpeta eliminada" . PHP_EOL;
    $deleted++;
}

echo PHP_EOL . "Eliminadas: {$deleted}" . PHP_EOL;
echo "Recomendado: php bin/audit-tenants.php" . PHP_EOL;
