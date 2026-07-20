<?php
/**
 * Panel de negocio central (sin carpeta tenant).
 * Las URLs /{slug}/private/dashboard/admin/ se reescriben aquí cuando no hay carpeta legacy.
 */
declare(strict_types=1);

$config = require __DIR__ . '/../src/Core/bootstrap.php';

use Agenduy\Core\Auth;
use Agenduy\Core\CentralCommerceData;
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

if (!CommercePanel::localDatabaseExists($idCommerce)) {
    $owner = $db->fetchOne(
        'SELECT * FROM users WHERE id_commerce = :c AND role = :r ORDER BY id_user ASC LIMIT 1',
        [':c' => $idCommerce, ':r' => Auth::ROLE_LOCAL]
    );
    $services = $db->fetchAll(
        'SELECT nombre, duracion_min AS duracion, precio FROM services WHERE id_commerce = :c ORDER BY id_service ASC',
        [':c' => $idCommerce]
    );
    $schedule = \Agenduy\Core\CommerceSettings::get(
        $idCommerce,
        'horarios',
        \Agenduy\Core\CommerceSettings::defaultsForSection('horarios')
    );
    CentralCommerceData::provision(
        $idCommerce,
        (int)($owner['id_user'] ?? Auth::id() ?? 0),
        [
            'nombre' => (string)($owner['nombre'] ?? ''),
            'apellido' => (string)($owner['apellido'] ?? ''),
            'cedula' => (string)($owner['cedula'] ?? ''),
            'email' => (string)($owner['email'] ?? $commerce['email'] ?? ''),
        ],
        [
            'nombre' => (string)$commerce['nombre'],
            'rut' => (string)($commerce['rut_ruc'] ?? ''),
            'pais' => (string)($commerce['pais'] ?? 'UY'),
            'ciudad' => (string)($commerce['ciudad'] ?? ''),
            'calle' => (string)($commerce['calle'] ?? ''),
            'telefono' => (string)($commerce['telefono'] ?? ''),
        ],
        $schedule,
        $services,
        (int)($commerce['id_rubro'] ?? 0)
    );
}

CommercePanel::activateCentralSession($slug);
header('Location: ' . url(CommercePanel::CENTRAL_TEMPLATE_PATH));
exit;
