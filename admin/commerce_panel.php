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
use Agenduy\Core\Security;

Auth::start();
if (!Auth::check() || Auth::role() !== Auth::ROLE_LOCAL) {
    header('Location: ' . Auth::loginUrl());
    exit;
}
Security::sendNoStoreHeaders();

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

$canonical = CommercePanel::dashboardUrlForSlug($slug, $section, $query);
$reqPath = strtok((string)($_SERVER['REQUEST_URI'] ?? ''), '?') ?: '';
if (stripos($reqPath, '/admin/commerce_panel.php') !== false) {
    header('Location: ' . $canonical, true, 302);
    exit;
}

$templateIndex = dirname(__DIR__) . '/template/private/dashboard/admin/index.php';
if (!is_file($templateIndex)) {
    header('Location: ' . CommercePanel::centralDashboardUrl($section, $query), true, 302);
    exit;
}

if (!defined('AGENDUY_COMMERCE_PANEL_EMBED')) {
    define('AGENDUY_COMMERCE_PANEL_EMBED', true);
}
if (!defined('AGENDUY_PANEL_BASE_HREF')) {
    define('AGENDUY_PANEL_BASE_HREF', CommercePanel::templateDashboardAdminBase());
}

require $templateIndex;
exit;
