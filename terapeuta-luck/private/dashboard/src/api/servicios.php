<?php
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/src/API/Autoload.php';

$projectRoot = dirname(__DIR__, 5);
require_once $projectRoot . '/src/Core/bootstrap.php';

use Agenduy\Core\Database;
use Agenduy\Core\MembershipPlan;
use Agenduy\Core\TenantApiGuard;

header('Content-Type: application/json; charset=utf-8');

$tenantStaff = TenantApiGuard::requireStaff(dirname(__DIR__, 4));

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Metodo no permitido']);
    exit;
}

$action = strtolower((string)($_POST['action'] ?? 'create'));
$allowedActions = ['create', 'update', 'delete'];
if ($action === 'insert') {
    $action = 'create';
}
if (!in_array($action, $allowedActions, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Accion no soportada']);
    exit;
}

$uploadDir = dirname(__DIR__, 4) . '/src/img/services';
if (!is_dir($uploadDir)) {
    @mkdir($uploadDir, 0775, true);
}

/**
 * Resuelve el id_commerce del tenant actual a partir de la carpeta.
 */
function tenantCommerceId(): ?int
{
    $slug = basename(dirname(__DIR__, 4));
    if ($slug === '' || $slug === 'template') {
        return null;
    }
    try {
        $db = Database::getInstance();
        $row = $db->fetchOne('SELECT id_commerce FROM commerces WHERE slug = :s', [':s' => $slug]);
        return $row ? (int)$row['id_commerce'] : null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Sincroniza un servicio local con la tabla central `services` (tienda pública).
 */
function syncServiceToCentral(array $localRow, ?array $previous = null): void
{
    $commerceId = tenantCommerceId();
    if ($commerceId === null || $commerceId <= 0) {
        return;
    }
    try {
        $db = Database::getInstance();
        $nombre = trim((string)($localRow['Nombre'] ?? ''));
        if ($nombre === '') {
            return;
        }
        $payload = [
            'nombre' => $nombre,
            'duracion_min' => max(15, (int)($localRow['Duracion'] ?? 30)),
            'precio' => (float)($localRow['Precio'] ?? 0),
            'estado' => (string)($localRow['Estado'] ?? 'Activo') === 'Inactivo' ? 'Inactivo' : 'Activo',
            'imagen' => trim((string)($localRow['Img_Link'] ?? '')),
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        $prevName = trim((string)($previous['Nombre'] ?? $nombre));
        $existing = $db->fetchOne(
            'SELECT id_service FROM services WHERE id_commerce = :c AND lower(nombre) = lower(:n) LIMIT 1',
            [':c' => $commerceId, ':n' => $prevName !== '' ? $prevName : $nombre]
        );
        if ($existing) {
            $db->update('services', $payload, 'id_service = :id AND id_commerce = :c', [
                ':id' => (int)$existing['id_service'],
                ':c' => $commerceId,
            ]);
            return;
        }
        $db->insert('services', array_merge($payload, [
            'id_commerce' => $commerceId,
            'descripcion' => '',
            'created_at' => date('Y-m-d H:i:s'),
        ]));
    } catch (Throwable $e) {
        // No bloquear el CRUD local si falla la sync pública.
    }
}

/**
 * Elimina el servicio espejado en la tabla central.
 */
function deleteServiceFromCentral(array $localRow): void
{
    $commerceId = tenantCommerceId();
    if ($commerceId === null || $commerceId <= 0) {
        return;
    }
    $nombre = trim((string)($localRow['Nombre'] ?? ''));
    if ($nombre === '') {
        return;
    }
    try {
        $db = Database::getInstance();
        $db->delete(
            'services',
            'id_commerce = :c AND lower(nombre) = lower(:n)',
            [':c' => $commerceId, ':n' => $nombre]
        );
    } catch (Throwable $e) {
        // ignore
    }
}

/**
 * Guarda la imagen subida y devuelve la ruta relativa.
 */
function handleServiceImage(string $fieldName, array &$errors, string $current = ''): string
{
    $file = $_FILES[$fieldName] ?? null;
    if ($file === null || !isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return $current;
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'No se pudo subir la imagen del servicio.';
        return $current;
    }

    $maxSize = 5 * 1024 * 1024;
    $size = isset($file['size']) ? (int)$file['size'] : 0;
    if ($size <= 0 || $size > $maxSize) {
        $errors[] = 'La imagen supera el tamano maximo permitido (5 MB).';
        return $current;
    }

    $ext = strtolower((string)pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'webp'];
    if (!in_array($ext, $allowed, true)) {
        $errors[] = 'Formato de imagen invalido. Usa JPG, PNG o WebP.';
        return $current;
    }

    $targetDir = dirname(__DIR__, 4) . '/src/img/services';
    if (!is_dir($targetDir)) {
        @mkdir($targetDir, 0775, true);
    }
    try {
        $token = bin2hex(random_bytes(4));
    } catch (Throwable $e) {
        $token = substr(str_replace('.', '', (string)microtime(true)), -8);
    }
    $filename = 'Servicio_' . date('Ymd_His') . '_' . $token . '.' . $ext;
    $destPath = rtrim($targetDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;

    if (!@move_uploaded_file($file['tmp_name'], $destPath)) {
        $errors[] = 'No se pudo guardar la imagen seleccionada.';
        return $current;
    }

    return 'src/img/services/' . $filename;
}

/**
 * Valida y prepara los datos comunes de un servicio.
 *
 * @return array{errors:string[], data:array}
 */
function collectServiceData(): array
{
    $errors = [];
    $nombre = trim((string)($_POST['Nombre'] ?? ''));
    if ($nombre === '') {
        $errors[] = 'El nombre es obligatorio.';
    }

    $durationRaw = trim((string)($_POST['Duracion'] ?? ''));
    $allowedDurations = ['15', '30', '60', '75', '90'];
    if (!in_array($durationRaw, $allowedDurations, true)) {
        $errors[] = 'Selecciona una duracion valida.';
    }

    $estadoRaw = strtolower(trim((string)($_POST['Estado'] ?? 'activo')));
    $estado = $estadoRaw === 'inactivo' ? 'Inactivo' : 'Activo';

    $precioRaw = trim((string)($_POST['Precio'] ?? ''));
    if ($precioRaw === '' || !is_numeric($precioRaw)) {
        $errors[] = 'Ingresa un precio valido.';
    }
    $precio = (float)$precioRaw;
    if ($precio < 0 || $precio > 99999) {
        $errors[] = 'El precio debe ser mayor o igual a 0 y menor a 100000.';
    }

    $puntosRaw = trim((string)($_POST['Puntos'] ?? ''));
    $puntos = null;
    if ($puntosRaw !== '') {
        if (!is_numeric($puntosRaw)) {
        $errors[] = 'Los puntos deben ser un numero entero.';
        } else {
            $puntosVal = (int)$puntosRaw;
            if ($puntosVal < 0 || $puntosVal > 99999) {
                $errors[] = 'Los puntos deben estar entre 0 y 99999.';
            } else {
                $puntos = $puntosVal;
            }
        }
    }

    $data = [
        'Nombre' => $nombre,
        'Duracion' => (int)$durationRaw,
        'Estado' => $estado,
        'Precio' => $precio,
        'Puntos' => $puntos,
    ];

    return ['errors' => $errors, 'data' => $data];
}

/**
 * Elimina una imagen física si pertenece al directorio de servicios.
 */
function deleteServiceImage(string $relativePath): void
{
    if ($relativePath === '') {
        return;
    }
    $clean = str_replace(['..', '\\'], ['','/'], $relativePath);
    if (strpos($clean, 'src/img/services/') !== 0) {
        return;
    }
    $full = dirname(__DIR__, 4) . '/' . $clean;
    if (is_file($full)) {
        @unlink($full);
    }
}

if ($action === 'delete') {
    $id = $_POST['ID_Servicio'] ?? $_POST['id'] ?? null;
    if ($id === null || $id === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Falta el identificador del servicio.']);
        exit;
    }
    $service = AutoloadDB::find('servicios', $id);
    if (!$service) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Servicio no encontrado.']);
        exit;
    }
    try {
        $deleted = AutoloadDB::deleteById('servicios', $id);
        if ($deleted) {
            $imgPath = trim((string)($service['Img_Link'] ?? ''));
            deleteServiceImage($imgPath);
            deleteServiceFromCentral($service);
            echo json_encode(['ok' => true]);
        } else {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'No se pudo eliminar el servicio.']);
        }
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'No se pudo eliminar el servicio.']);
    }
    exit;
}

$payload = collectServiceData();
$errors = $payload['errors'];
$data = $payload['data'];

$currentImg = trim((string)($_POST['Img_Actual'] ?? ''));
$data['Img_Link'] = handleServiceImage('Imagen', $errors, $currentImg);

if (!empty($errors)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'errors' => $errors]);
    exit;
}

if ($action === 'create') {
    try {
        $tenantSlug = basename(dirname(__DIR__, 4));
        $plan = MembershipPlan::forCommerceSlug($tenantSlug);
        if (is_array($plan)) {
            $maxServices = MembershipPlan::maxServices($plan);
            if ($maxServices !== null) {
                $currentCount = count(AutoloadDB::all('servicios'));
                if ($currentCount >= $maxServices) {
                    http_response_code(403);
                    echo json_encode(MembershipPlan::denialPayload('PLAN_LIMIT_MAX_SERVICES', [
                        'max_services' => $maxServices,
                        'current' => $currentCount,
                    ]));
                    exit;
                }
            }
        }
        $row = AutoloadDB::insert('servicios', $data);
        syncServiceToCentral($row);
        echo json_encode(['ok' => true, 'data' => $row]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'No se pudo crear el servicio.']);
    }
    exit;
}

$id = $_POST['ID_Servicio'] ?? $_POST['id'] ?? null;
if ($id === null || $id === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Falta el identificador del servicio.']);
    exit;
}

$current = AutoloadDB::find('servicios', $id);
if (!$current) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Servicio no encontrado.']);
    exit;
}

try {
    $row = AutoloadDB::updateById('servicios', $id, $data);
    if (!$row) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'No se pudo actualizar el servicio.']);
        exit;
    }
    $newImg = trim((string)($row['Img_Link'] ?? ''));
    $oldImg = trim((string)($current['Img_Link'] ?? ''));
    if ($newImg !== '' && $oldImg !== '' && $newImg !== $oldImg) {
        deleteServiceImage($oldImg);
    }
    syncServiceToCentral($row, $current);
    echo json_encode(['ok' => true, 'data' => $row]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'No se pudo actualizar el servicio.']);
}
