<?php
declare(strict_types=1);

/**
 * Auditoría multi-tenant (fase 0).
 * Uso: php bin/audit-tenants.php [--json]
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$projectRoot = dirname(__DIR__);
require $projectRoot . '/src/Core/bootstrap.php';

use Agenduy\Core\TenantAudit;

$asJson = in_array('--json', $argv ?? [], true);
$report = TenantAudit::run($projectRoot);

if ($asJson) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

echo "=== Agendarte — Auditoría multi-tenant ===" . PHP_EOL;
echo 'Generado: ' . ($report['generated_at'] ?? '') . PHP_EOL;
echo 'Carpetas legacy en registro: ' . (!empty($report['legacy_folders_enabled']) ? 'SÍ' : 'NO') . PHP_EOL;
echo 'Archivos en template/: ' . (int)($report['template_files'] ?? 0) . PHP_EOL . PHP_EOL;

echo "--- Carpetas vs SQLite ---" . PHP_EOL;
foreach ($report['folder_scan'] ?? [] as $row) {
    $slug = (string)($row['slug'] ?? '');
    $folder = !empty($row['folder']) ? 'carpeta' : 'sin carpeta';
    $sqlite = !empty($row['registered']) ? 'SQLite' : 'sin SQLite';
    echo "  {$slug}: {$folder}, {$sqlite}" . PHP_EOL;
}

echo PHP_EOL . "--- Storage central ---" . PHP_EOL;
foreach ($report['storage'] ?? [] as $s) {
    echo sprintf(
        "  #%d %s: central=%s legacy=%s\n",
        (int)($s['id_commerce'] ?? 0),
        (string)($s['slug'] ?? ''),
        !empty($s['has_central']) ? 'sí' : 'no',
        !empty($s['has_legacy_folder']) ? 'sí' : 'no'
    );
}

echo PHP_EOL . "--- Recomendaciones ---" . PHP_EOL;
foreach ($report['recommendations'] ?? [] as $tip) {
    echo "  • {$tip}" . PHP_EOL;
}

exit(0);
