<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 3);
require_once $projectRoot . '/src/Core/bootstrap.php';

use Agenduy\Core\AdminBrand;

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=3600');

$scriptDir = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '')));
$adminPath = rtrim($scriptDir, '/') . '/admin/index.php';
if ($adminPath === '/admin/index.php') {
    $adminPath = 'admin/index.php';
}

echo json_encode(AdminBrand::manifestData($adminPath), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
