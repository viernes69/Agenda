<?php
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/src/API/Autoload.php';

$projectRoot = dirname(__DIR__, 5);
require_once $projectRoot . '/src/Core/bootstrap.php';

use Agenduy\Core\CommerceStorage;
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
if ($action === 'insert') {
    $action = 'create';
}
$allowed = ['create', 'update', 'delete'];
if (!in_array($action, $allowed, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Accion no soportada']);
    exit;
}

$uploadDir = assetUploadDir('products');

function tenantCommerceId(): ?int
{
    return \Agenduy\Core\CommercePanel::commerceIdForTenantRoot(dirname(__DIR__, 4));
}

function assetUploadDir(string $kind): string
{
    $commerceId = tenantCommerceId();
    if ($commerceId !== null && $commerceId > 0) {
        return CommerceStorage::kindDir($commerceId, $kind);
    }
    $dir = dirname(__DIR__, 4) . '/src/img/' . $kind;
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

function assetStoredPath(string $kind, string $filename): string
{
    $commerceId = tenantCommerceId();
    if ($commerceId !== null && $commerceId > 0) {
        return CommerceStorage::relativePath($commerceId, $kind, $filename);
    }
    return 'src/img/' . $kind . '/' . $filename;
}

/**
 * Maneja la subida de imagen del producto.
 */
function handleProductImage(string $field, array &$errors, string $current = ''): string
{
    $file = $_FILES[$field] ?? null;
    if ($file === null || !isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return $current;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'No se pudo subir la imagen del producto.';
        return $current;
    }
    $max = 5 * 1024 * 1024;
    $size = isset($file['size']) ? (int)$file['size'] : 0;
    if ($size <= 0 || $size > $max) {
        $errors[] = 'La imagen supera el tamano maximo permitido (5 MB).';
        return $current;
    }
    $ext = strtolower((string)pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'webp'];
    if (!in_array($ext, $allowed, true)) {
        $errors[] = 'Formato de imagen invalido. Usa JPG, PNG o WebP.';
        return $current;
    }
    $dir = assetUploadDir('products');
    try {
        $token = bin2hex(random_bytes(4));
    } catch (Throwable $e) {
        $token = substr(str_replace('.', '', (string)microtime(true)), -8);
    }
    $filename = 'Producto_' . date('Ymd_His') . '_' . $token . '.' . $ext;
    $dest = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;
    if (!@move_uploaded_file($file['tmp_name'], $dest)) {
        $errors[] = 'No se pudo guardar la imagen seleccionada.';
        return $current;
    }
    return assetStoredPath('products', $filename);
}

/**
 * Valida la informacion del producto y devuelve los datos listos.
 *
 * @return array{errors:string[], data:array}
 */
function collectProductData(): array
{
    $errors = [];
    $nombre = trim((string)($_POST['Nombre'] ?? ''));
    if ($nombre === '') {
        $errors[] = 'El nombre es obligatorio.';
    }
    $tipo = trim((string)($_POST['Tipo'] ?? ''));
    if ($tipo === '') {
        $errors[] = 'Selecciona un tipo de producto.';
    } elseif (mb_strtolower($tipo, 'UTF-8') === 'otros') {
        $errors[] = 'Escribe el tipo de producto personalizado.';
    } else {
        // Capitalizar primera letra (p. ej. "libro" → "Libro").
        $tipo = mb_strtoupper(mb_substr($tipo, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($tipo, 1, null, 'UTF-8');
    }
    $precioRaw = trim((string)($_POST['Precio'] ?? ''));
    if ($precioRaw === '' || !is_numeric($precioRaw)) {
        $errors[] = 'Ingresa un precio valido.';
    }
    $precio = (float)$precioRaw;
    if ($precio < 0 || $precio > 999999) {
        $errors[] = 'El precio debe ser mayor o igual a 0 y menor a 1.000.000.';
    }
    $descripcion = trim((string)($_POST['Descripcion'] ?? ''));
    if ($descripcion === '') {
        // Fallback: usar el nombre para no bloquear el alta con un campo vacío.
        $descripcion = $nombre !== '' ? $nombre : '';
    }
    if ($descripcion === '') {
        $errors[] = 'La descripcion es obligatoria.';
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
        'Tipo' => $tipo,
        'Precio' => $precio,
        'Descripcion' => $descripcion,
        'Puntos' => $puntos,
    ];

    return ['errors' => $errors, 'data' => $data];
}

/**
 * Elimina un archivo de imagen previa si pertenece al directorio de productos.
 */
function deleteProductImage(string $relative): void
{
    if ($relative === '') {
        return;
    }
    $clean = str_replace(['..', '\\'], ['', '/'], $relative);
    $commerceId = tenantCommerceId();
    $slug = \Agenduy\Core\CommercePanel::resolveEffectiveSlug(dirname(__DIR__, 4));
    if ($commerceId !== null && $commerceId > 0 && CommerceStorage::isCentralPath($clean)) {
        $full = CommerceStorage::absolutePath($commerceId, $slug, $clean);
        if ($full !== null) {
            @unlink($full);
        }
        return;
    }
    if (strpos($clean, 'src/img/products/') !== 0) {
        return;
    }
    $full = dirname(__DIR__, 4) . '/' . $clean;
    if (is_file($full)) {
        @unlink($full);
    }
}

if ($action === 'delete') {
    $id = $_POST['ID_Product'] ?? $_POST['id'] ?? null;
    if ($id === null || $id === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Falta el identificador del producto.']);
        exit;
    }
    $product = AutoloadDB::find('productos', $id);
    if (!$product) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Producto no encontrado.']);
        exit;
    }
    try {
        $deleted = AutoloadDB::deleteById('productos', $id);
        if ($deleted) {
            $img = trim((string)($product['Img_src'] ?? ''));
            deleteProductImage($img);
            echo json_encode(['ok' => true]);
        } else {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'No se pudo eliminar el producto.']);
        }
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'No se pudo eliminar el producto.']);
    }
    exit;
}

$payload = collectProductData();
$errors = $payload['errors'];
$data = $payload['data'];
$currentImg = trim((string)($_POST['Img_Actual'] ?? ''));
$data['Img_src'] = handleProductImage('Imagen', $errors, $currentImg);

if (!empty($errors)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'errors' => $errors]);
    exit;
}

if ($action === 'create') {
    try {
        $tenantSlug = \Agenduy\Core\CommercePanel::resolveEffectiveSlug(dirname(__DIR__, 4));
        $plan = MembershipPlan::forCommerceSlug($tenantSlug);
        if (is_array($plan)) {
            $maxProducts = MembershipPlan::maxProducts($plan);
            if ($maxProducts !== null) {
                $currentCount = count(AutoloadDB::all('productos'));
                if ($currentCount >= $maxProducts) {
                    http_response_code(403);
                    echo json_encode(MembershipPlan::denialPayload('PLAN_LIMIT_MAX_PRODUCTS', [
                        'max_products' => $maxProducts,
                        'current' => $currentCount,
                    ]));
                    exit;
                }
            }
        }
        $row = AutoloadDB::insert('productos', $data);
        echo json_encode(['ok' => true, 'data' => $row]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'No se pudo crear el producto.']);
    }
    exit;
}

$id = $_POST['ID_Product'] ?? $_POST['id'] ?? null;
if ($id === null || $id === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Falta el identificador del producto.']);
    exit;
}

$current = AutoloadDB::find('productos', $id);
if (!$current) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Producto no encontrado.']);
    exit;
}

try {
    $row = AutoloadDB::updateById('productos', $id, $data);
    if (!$row) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'No se pudo actualizar el producto.']);
        exit;
    }
    $newImg = trim((string)($row['Img_src'] ?? ''));
    $oldImg = trim((string)($current['Img_src'] ?? ''));
    if ($newImg !== '' && $oldImg !== '' && $newImg !== $oldImg) {
        deleteProductImage($oldImg);
    }
    echo json_encode(['ok' => true, 'data' => $row]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'No se pudo actualizar el producto.']);
}
