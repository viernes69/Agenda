<?php
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/src/API/Autoload.php';

$projectRoot = dirname(__DIR__, 5);
require_once $projectRoot . '/src/Core/bootstrap.php';

use Agenduy\Core\CommerceStorage;
use Agenduy\Core\MembershipPlan;
use Agenduy\Core\ProductCatalog;
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

function productUploadAt(string $field, int $index): ?array
{
    $files = $_FILES[$field] ?? null;
    if (!is_array($files) || !isset($files['error'])) {
        return null;
    }
    if (is_array($files['error'])) {
        if (!array_key_exists($index, $files['error'])) {
            return null;
        }
        return [
            'name' => $files['name'][$index] ?? '',
            'type' => $files['type'][$index] ?? '',
            'tmp_name' => $files['tmp_name'][$index] ?? '',
            'error' => $files['error'][$index],
            'size' => $files['size'][$index] ?? 0,
        ];
    }
    return $index === 0 ? $files : null;
}

function cleanProductPath(string $path): string
{
    $path = trim(str_replace('\\', '/', $path));
    $path = str_replace('..', '', $path);
    return ltrim($path, '/');
}

/**
 * Guarda una imagen subida y devuelve su ruta relativa.
 */
function saveProductUpload(array $file, array &$errors): string
{
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return '';
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'No se pudo subir la imagen del producto.';
        return '';
    }
    $max = 5 * 1024 * 1024;
    $size = isset($file['size']) ? (int)$file['size'] : 0;
    if ($size <= 0 || $size > $max) {
        $errors[] = 'La imagen supera el tamano maximo permitido (5 MB).';
        return '';
    }
    $ext = strtolower((string)pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'webp'];
    if (!in_array($ext, $allowed, true)) {
        $errors[] = 'Formato de imagen invalido. Usa JPG, PNG o WebP.';
        return '';
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
        return '';
    }
    return assetStoredPath('products', $filename);
}

/**
 * @return array{Img_src:string,Img_Gallery:string,Imagenes:string}
 */
function collectProductImages(array $current, array &$errors): array
{
    $currentPosted = $_POST['Imagenes_Actuales'] ?? [];
    $pricesPosted = $_POST['Imagenes_Precios'] ?? [];
    $labelsPosted = $_POST['Imagenes_Titulos'] ?? [];
    $removePosted = $_POST['Imagenes_Quitar'] ?? [];
    $currentPosted = is_array($currentPosted) ? $currentPosted : [];
    $pricesPosted = is_array($pricesPosted) ? $pricesPosted : [];
    $labelsPosted = is_array($labelsPosted) ? $labelsPosted : [];
    $removePosted = is_array($removePosted) ? $removePosted : [];
    $removeSet = [];
    foreach ($removePosted as $slot) {
        if (is_numeric($slot)) {
            $removeSet[(int)$slot] = true;
        }
    }

    if ($currentPosted === [] && $current !== []) {
        foreach (ProductCatalog::mediaForRow($current) as $slot => $media) {
            $currentPosted[$slot] = (string)($media['src'] ?? '');
            if (($media['price'] ?? null) !== null) {
                $pricesPosted[$slot] = (string)$media['price'];
            }
            if (($media['label'] ?? '') !== '') {
                $labelsPosted[$slot] = (string)$media['label'];
            }
        }
    }

    $media = [];
    for ($slot = 0; $slot < ProductCatalog::MAX_IMAGES; $slot++) {
        $src = cleanProductPath((string)($currentPosted[$slot] ?? ''));
        $file = productUploadAt('Imagenes_Nuevas', $slot);
        $newSrc = $file !== null ? saveProductUpload($file, $errors) : '';
        if ($newSrc !== '') {
            $src = $newSrc;
        } elseif (isset($removeSet[$slot])) {
            $src = '';
        }

        if ($src === '') {
            continue;
        }

        $priceRaw = trim((string)($pricesPosted[$slot] ?? ''));
        $price = null;
        if ($priceRaw !== '') {
            if (!is_numeric($priceRaw)) {
                $errors[] = 'El precio de cada imagen debe ser numerico.';
            } else {
                $price = round(max(0.0, min(999999.0, (float)$priceRaw)), 2);
            }
        }

        $label = trim((string)($labelsPosted[$slot] ?? ''));
        if ($label === '') {
            $label = 'Imagen ' . ($slot + 1);
        }
        if (mb_strlen($label, 'UTF-8') > 80) {
            $label = mb_substr($label, 0, 80, 'UTF-8');
        }

        $media[] = [
            '_slot' => $slot,
            'src' => $src,
            'price' => $price,
            'label' => $label,
            'cover' => false,
        ];
    }

    $coverSlot = isset($_POST['Portada_Index']) && is_numeric($_POST['Portada_Index'])
        ? (int)$_POST['Portada_Index']
        : 0;
    $coverPos = 0;
    foreach ($media as $pos => $item) {
        if ((int)($item['_slot'] ?? -1) === $coverSlot) {
            $coverPos = $pos;
            break;
        }
    }

    foreach ($media as $pos => &$item) {
        $item['cover'] = $pos === $coverPos;
    }
    unset($item);

    if ($media !== [] && $coverPos > 0) {
        $coverItem = $media[$coverPos];
        array_splice($media, $coverPos, 1);
        array_unshift($media, $coverItem);
    }
    foreach ($media as &$item) {
        unset($item['_slot']);
    }
    unset($item);

    $cover = $media[0]['src'] ?? '';
    $gallery = [];
    foreach (array_slice($media, 1) as $item) {
        $gallery[] = (string)$item['src'];
    }

    return [
        'Img_src' => (string)$cover,
        'Img_Gallery' => implode('|', $gallery),
        'Imagenes' => json_encode($media, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]',
    ];
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
    $discountRaw = trim((string)($_POST['Descuento_Porcentaje'] ?? $_POST['Descuento'] ?? ''));
    $discount = '';
    if ($discountRaw !== '') {
        if (!is_numeric($discountRaw)) {
            $errors[] = 'El descuento debe ser un porcentaje valido.';
        } else {
            $discountValue = (float)$discountRaw;
            if ($discountValue < 0 || $discountValue > 100) {
                $errors[] = 'El descuento debe estar entre 0 y 100.';
            } else {
                $discount = rtrim(rtrim(number_format($discountValue, 2, '.', ''), '0'), '.');
            }
        }
    }
    $saleLabel = trim((string)($_POST['Etiqueta_Venta'] ?? ''));
    if (mb_strlen($saleLabel, 'UTF-8') > 60) {
        $saleLabel = mb_substr($saleLabel, 0, 60, 'UTF-8');
    }

    $data = [
        'Nombre' => $nombre,
        'Tipo' => $tipo,
        'Precio' => $precio,
        'Descripcion' => $descripcion,
        'Puntos' => $puntos,
        'Descuento_Porcentaje' => $discount,
        'Etiqueta_Venta' => $saleLabel,
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

/**
 * @return list<string>
 */
function productImagePaths(array $row): array
{
    $paths = [];
    foreach (ProductCatalog::mediaForRow($row) as $media) {
        $src = cleanProductPath((string)($media['src'] ?? ''));
        if ($src !== '' && !in_array($src, $paths, true)) {
            $paths[] = $src;
        }
    }
    foreach (['Img_src', 'Img_Gallery'] as $key) {
        $raw = $row[$key] ?? '';
        $parts = is_array($raw) ? $raw : explode('|', (string)$raw);
        foreach ($parts as $part) {
            $path = cleanProductPath((string)$part);
            if ($path !== '' && !in_array($path, $paths, true)) {
                $paths[] = $path;
            }
        }
    }
    return $paths;
}

function deleteProductImages(array $row, array $except = []): void
{
    $keep = array_flip(array_map('cleanProductPath', $except));
    foreach (productImagePaths($row) as $path) {
        if (isset($keep[$path])) {
            continue;
        }
        deleteProductImage($path);
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
            deleteProductImages($product);
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

if (!empty($errors)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'errors' => $errors]);
    exit;
}

if ($action === 'create') {
function formatProductRowForResponse(array $row): array
{
    $tenantSlug = \Agenduy\Core\CommercePanel::resolveEffectiveSlug(dirname(__DIR__, 4));
    $commerceId = tenantCommerceId();
    $rawCover = (string)($row['Img_src'] ?? '');
    $coverUrl = '';
    if ($rawCover !== '') {
        if (preg_match('#^https?://#i', $rawCover)) {
            $coverUrl = $rawCover;
        } elseif ($commerceId !== null && $commerceId > 0) {
            $coverUrl = \Agenduy\Core\CommerceStorage::publicUrl($commerceId, $tenantSlug, $rawCover);
        } else {
            $coverUrl = url($tenantSlug . '/' . ltrim($rawCover, '/'));
        }
    }
    $row['Img_src'] = $rawCover;
    $row['Img_src_url'] = $coverUrl;

    $media = ProductCatalog::mediaForRow($row);
    foreach ($media as &$item) {
        $src = (string)($item['src'] ?? '');
        if ($src !== '') {
            if (preg_match('#^https?://#i', $src)) {
                $item['url'] = $src;
            } elseif ($commerceId !== null && $commerceId > 0) {
                $item['url'] = \Agenduy\Core\CommerceStorage::publicUrl($commerceId, $tenantSlug, $src);
            } else {
                $item['url'] = url($tenantSlug . '/' . ltrim($src, '/'));
            }
        } else {
            $item['url'] = '';
        }
    }
    unset($item);
    $row['Imagenes'] = json_encode($media, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return $row;
}

    $imageData = collectProductImages([], $errors);
    if (!empty($errors)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'errors' => $errors]);
        exit;
    }
    $data = array_merge($data, $imageData);
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
        echo json_encode(['ok' => true, 'data' => formatProductRowForResponse($row)]);
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

$imageData = collectProductImages($current, $errors);
if (!empty($errors)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'errors' => $errors]);
    exit;
}
$data = array_merge($data, $imageData);

try {
    $row = AutoloadDB::updateById('productos', $id, $data);
    if (!$row) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'No se pudo actualizar el producto.']);
        exit;
    }
    deleteProductImages($current, productImagePaths($row));
    echo json_encode(['ok' => true, 'data' => formatProductRowForResponse($row)]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'No se pudo actualizar el producto.']);
}
