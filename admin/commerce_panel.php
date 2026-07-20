<?php
/**
 * Panel de negocio central (sin carpeta tenant).
 * Las URLs /{slug}/private/dashboard/admin/ se reescriben aquí cuando no hay carpeta legacy.
 */
declare(strict_types=1);

$config = require __DIR__ . '/../src/Core/bootstrap.php';

use Agenduy\Core\Auth;
use Agenduy\Core\CommercePanel;
use Agenduy\Core\Database;

Auth::start();
if (!Auth::check() || Auth::role() !== Auth::ROLE_LOCAL) {
    header('Location: login.php');
    exit;
}

$idCommerce = (int)Auth::commerceId();
if ($idCommerce <= 0) {
    echo 'Cuenta sin comercio asignado.';
    exit;
}

$db = Database::getInstance();
$commerce = $db->fetchOne('SELECT * FROM commerces WHERE id_commerce = :id', [':id' => $idCommerce]);
if (!$commerce) {
    echo 'Comercio no encontrado.';
    exit;
}

$slug = trim((string)($commerce['slug'] ?? ''));
$requestedSlug = trim((string)($_GET['slug'] ?? ''));
if ($requestedSlug !== '' && !hash_equals($slug, $requestedSlug)) {
    http_response_code(403);
    echo 'No autorizado para este comercio.';
    exit;
}

$section = CommercePanel::normalizeDashboardSection((string)($_GET['section'] ?? 'resumen'));
$query = [];
if (!empty($_GET['setup'])) {
    $query['setup'] = 'ok';
}

if (CommercePanel::hasLegacyPanel($slug)) {
    header('Location: ' . CommercePanel::dashboardUrlForSlug($slug, $section, $query));
    exit;
}

CommercePanel::bootstrapCentralAccess($idCommerce, $slug);

$target = CommercePanel::centralDashboardUrl($section, $query);
header('Location: ' . $target);
exit;
