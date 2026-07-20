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

if (CommercePanel::hasLegacyPanel($slug)) {
    header('Location: ' . CommercePanel::legacyUrl($slug));
    exit;
}

CommercePanel::bootstrapCentralAccess($idCommerce, $slug);

$target = url(CommercePanel::CENTRAL_TEMPLATE_PATH);
$hash = trim((string)($_GET['section'] ?? ''));
if ($hash !== '') {
    $target .= '#' . rawurlencode(ltrim($hash, '#'));
}
header('Location: ' . $target);
exit;
