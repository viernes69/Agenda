<?php
/**
 * Agenduy - Super Admin: Gestión de Productos de Comercios
 * Permite seleccionar cualquier comercio y agregar, editar o eliminar productos (hasta 4 fotos por producto).
 */
declare(strict_types=1);

$config = require __DIR__ . '/../src/Core/bootstrap.php';

use Agenduy\Core\Auth;
use Agenduy\Core\CSRF;
use Agenduy\Core\Database;
use Agenduy\Core\CentralCommerceData;
use Agenduy\Core\CommercePanel;
use Agenduy\Core\CommercePublic;
use Agenduy\Core\CommerceSettings;
use Agenduy\Core\CommerceStorage;
use Agenduy\Core\ProductCatalog;

Auth::start();
if (!Auth::check() || Auth::role() !== 'super_admin') {
    header('Location: ' . Auth::loginUrl());
    exit;
}

$db = Database::getInstance();
$flash = ['type' => '', 'msg' => ''];

if (isset($_SESSION['admin_products_flash']) && is_array($_SESSION['admin_products_flash'])) {
    $flash = [
        'type' => (string)($_SESSION['admin_products_flash']['type'] ?? ''),
        'msg'  => (string)($_SESSION['admin_products_flash']['msg'] ?? ''),
    ];
    unset($_SESSION['admin_products_flash']);
}

function products_flash_redirect(int $idCommerce, array $flash): void
{
    $_SESSION['admin_products_flash'] = [
        'type' => (string)($flash['type'] ?? ''),
        'msg'  => (string)($flash['msg'] ?? ''),
    ];
    header('Location: commerce_products.php?id_commerce=' . $idCommerce, true, 303);
    exit;
}

// Obtener todos los comercios
$commerces = $db->fetchAll(
    'SELECT c.id_commerce, c.nombre, c.slug, c.status, c.logo, r.nombre AS rubro_nombre
     FROM commerces c
     LEFT JOIN rubros r ON r.id_rubro = c.id_rubro
     ORDER BY c.nombre COLLATE NOCASE ASC'
);

if (empty($commerces)) {
    $pageTitle = 'Productos de Comercios';
    $activeSection = 'products';
    require __DIR__ . '/partials/header.php';
    echo '<section class="page-header"><h1>Gestión de Productos</h1><p>No hay comercios registrados aún.</p></section>';
    require __DIR__ . '/partials/footer.php';
    exit;
}

// Determinar el comercio seleccionado
$selectedId = (int)($_GET['id_commerce'] ?? 0);
$selectedSlug = trim((string)($_GET['slug'] ?? ''));

$selectedCommerce = null;
if ($selectedId > 0) {
    foreach ($commerces as $c) {
        if ((int)$c['id_commerce'] === $selectedId) {
            $selectedCommerce = $c;
            break;
        }
    }
} elseif ($selectedSlug !== '') {
    foreach ($commerces as $c) {
        if ($c['slug'] === $selectedSlug) {
            $selectedCommerce = $c;
            $selectedId = (int)$c['id_commerce'];
            break;
        }
    }
}

if (!$selectedCommerce) {
    $selectedCommerce = $commerces[0];
    $selectedId = (int)$selectedCommerce['id_commerce'];
}

$selectedSlug = (string)$selectedCommerce['slug'];

// Asegurar base de datos local
CommercePanel::ensureLocalDatabase($selectedId, $selectedSlug);
$dbPath = CommercePanel::localDatabasePath($selectedId);

$localDb = is_file($dbPath) ? @include $dbPath : null;
if (!is_array($localDb)) {
    $localDb = [
        'info_barberia' => [],
        'servicios' => [],
        'barberos' => [],
        'clientes' => [],
        'productos' => [
            0 => [
                'ID_Product' => null,
                'Nombre' => null,
                'Tipo' => null,
                'Precio' => null,
                'Descripcion' => null,
                'Puntos' => null,
                'Img_src' => null,
                'Img_Gallery' => '',
                'Imagenes' => '',
                'Descuento_Porcentaje' => '',
                'Etiqueta_Venta' => '',
            ]
        ],
        'reservas' => [],
        'carrito' => [],
    ];
}

if (!isset($localDb['productos']) || !is_array($localDb['productos'])) {
    $localDb['productos'] = [
        0 => [
            'ID_Product' => null,
            'Nombre' => null,
            'Tipo' => null,
            'Precio' => null,
            'Descripcion' => null,
            'Puntos' => null,
            'Img_src' => null,
            'Img_Gallery' => '',
            'Imagenes' => '',
            'Descuento_Porcentaje' => '',
            'Etiqueta_Venta' => '',
        ]
    ];
}

// Acciones POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::checkRequest('admin_products');
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save_product') {
        $idProduct = (int)($_POST['id_product'] ?? 0);
        $nombre = trim((string)($_POST['nombre'] ?? ''));
        $tipo = trim((string)($_POST['tipo'] ?? ''));
        $precio = max(0.0, (float)($_POST['precio'] ?? 0));
        $descuento = max(0.0, min(100.0, (float)($_POST['descuento_porcentaje'] ?? 0)));
        $etiqueta = trim((string)($_POST['etiqueta_venta'] ?? ''));
        $descripcion = trim((string)($_POST['descripcion'] ?? ''));
        $puntos = max(0, (int)($_POST['puntos'] ?? 0));

        if ($nombre === '') {
            products_flash_redirect($selectedId, ['type' => 'error', 'msg' => 'El nombre del producto es obligatorio.']);
        }

        // Recolectar hasta 4 imágenes
        $currentPosted = (array)($_POST['Imagenes_Actuales'] ?? []);
        $pricesPosted  = (array)($_POST['Imagenes_Precios'] ?? []);
        $labelsPosted  = (array)($_POST['Imagenes_Titulos'] ?? []);
        $removePosted  = (array)($_POST['Imagenes_Quitar'] ?? []);
        $coverSlot     = isset($_POST['Portada_Index']) && is_numeric($_POST['Portada_Index']) ? (int)$_POST['Portada_Index'] : 0;

        $mediaItems = [];
        $uploadDir = CommerceStorage::kindDir($selectedId, 'products');

        for ($slot = 0; $slot < ProductCatalog::MAX_IMAGES; $slot++) {
            $src = trim((string)($currentPosted[$slot] ?? ''));
            $isRemoved = !empty($removePosted[$slot]);

            // Comprobar archivo nuevo subido en este slot
            if (
                isset($_FILES['Imagenes_Nuevas']['name'][$slot]) &&
                ($_FILES['Imagenes_Nuevas']['error'][$slot] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK
            ) {
                $file = [
                    'name'     => $_FILES['Imagenes_Nuevas']['name'][$slot],
                    'type'     => $_FILES['Imagenes_Nuevas']['type'][$slot] ?? '',
                    'tmp_name' => $_FILES['Imagenes_Nuevas']['tmp_name'][$slot],
                    'error'    => $_FILES['Imagenes_Nuevas']['error'][$slot],
                    'size'     => $_FILES['Imagenes_Nuevas']['size'][$slot],
                ];
                $maxBytes = 5 * 1024 * 1024;
                $allowedExts = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
                $ext = strtolower((string)pathinfo((string)$file['name'], PATHINFO_EXTENSION));

                if ($file['size'] > $maxBytes) {
                    products_flash_redirect($selectedId, ['type' => 'error', 'msg' => 'La imagen ' . ($slot + 1) . ' supera los 5 MB.']);
                } elseif (!in_array($ext, $allowedExts, true)) {
                    products_flash_redirect($selectedId, ['type' => 'error', 'msg' => 'Formato no válido en imagen ' . ($slot + 1) . '. Usá JPG, PNG, WebP o GIF.']);
                } else {
                    $token = substr(str_replace('.', '', (string)microtime(true)), -6);
                    $filename = 'producto_' . date('Ymd_His') . '_slot' . $slot . '_' . $token . '.' . $ext;
                    $dest = rtrim($uploadDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;
                    if (move_uploaded_file((string)$file['tmp_name'], $dest)) {
                        @chmod($dest, 0644);
                        $src = CommerceStorage::relativePath($selectedId, 'products', $filename);
                    }
                }
            } elseif ($isRemoved) {
                $src = '';
            }

            if ($src === '') {
                continue;
            }

            $slotPrice = null;
            $rawPrice = trim((string)($pricesPosted[$slot] ?? ''));
            if ($rawPrice !== '' && is_numeric($rawPrice)) {
                $slotPrice = round(max(0.0, (float)$rawPrice), 2);
            }

            $slotLabel = trim((string)($labelsPosted[$slot] ?? ''));
            if ($slotLabel === '') {
                $slotLabel = count($mediaItems) === 0 ? $nombre : ('Opción ' . (count($mediaItems) + 1));
            }

            $mediaItems[] = [
                '_slot' => $slot,
                'src'   => $src,
                'price' => $slotPrice,
                'label' => $slotLabel,
                'cover' => false,
            ];
        }

        // Determinar portada
        $coverPos = 0;
        foreach ($mediaItems as $pos => $item) {
            if ((int)($item['_slot'] ?? -1) === $coverSlot) {
                $coverPos = $pos;
                break;
            }
        }

        foreach ($mediaItems as $pos => &$item) {
            $item['cover'] = ($pos === $coverPos);
        }
        unset($item);

        if ($mediaItems !== [] && $coverPos > 0) {
            $coverItem = $mediaItems[$coverPos];
            array_splice($mediaItems, $coverPos, 1);
            array_unshift($mediaItems, $coverItem);
        }

        foreach ($mediaItems as &$item) {
            unset($item['_slot']);
        }
        unset($item);

        $coverSrc = $mediaItems[0]['src'] ?? '';
        $gallery = [];
        foreach (array_slice($mediaItems, 1) as $item) {
            $gallery[] = (string)$item['src'];
        }

        // Construir registro
        $productData = [
            'Nombre' => $nombre,
            'Tipo' => $tipo !== '' ? $tipo : 'General',
            'Precio' => $precio,
            'Descripcion' => $descripcion,
            'Puntos' => $puntos,
            'Img_src' => (string)$coverSrc,
            'Img_Gallery' => implode('|', $gallery),
            'Imagenes' => json_encode($mediaItems, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]',
            'Descuento_Porcentaje' => $descuento > 0 ? $descuento : '',
            'Etiqueta_Venta' => $etiqueta,
        ];

        if ($idProduct > 0) {
            // Actualizar existente
            $found = false;
            foreach ($localDb['productos'] as $idx => $row) {
                if ($idx === 0 || !is_array($row)) continue;
                if ((int)($row['ID_Product'] ?? 0) === $idProduct) {
                    $productData['ID_Product'] = $idProduct;
                    $localDb['productos'][$idx] = array_merge($row, $productData);
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $productData['ID_Product'] = $idProduct;
                $localDb['productos'][] = $productData;
            }
            $flashMsg = 'Producto "' . $nombre . '" actualizado correctamente (' . count($mediaItems) . ' fotos).';
        } else {
            // Crear nuevo
            $maxId = 0;
            foreach ($localDb['productos'] as $idx => $row) {
                if ($idx === 0 || !is_array($row)) continue;
                $pid = (int)($row['ID_Product'] ?? 0);
                if ($pid > $maxId) $maxId = $pid;
            }
            $nextId = $maxId + 1;
            $productData['ID_Product'] = $nextId;
            $localDb['productos'][] = $productData;
            $flashMsg = 'Producto "' . $nombre . '" agregado al comercio exitosamente (' . count($mediaItems) . ' fotos).';
        }

        // Asegurar que la sección de productos esté activa en las funciones del comercio
        if (!isset($localDb['info_barberia']['features']) || !is_array($localDb['info_barberia']['features'])) {
            $localDb['info_barberia']['features'] = [];
        }
        $localDb['info_barberia']['features']['productos'] = true;
        $localDb['info_barberia']['features']['carrito'] = true;

        // Guardar base de datos
        CentralCommerceData::writeDatabase($selectedId, $localDb);

        // Actualizar funciones en commerce_settings
        $funciones = CommerceSettings::get($selectedId, 'funciones', CommerceSettings::defaultsForSection('funciones'));
        $funciones['productos'] = true;
        $funciones['carrito'] = true;
        CommerceSettings::set($selectedId, 'funciones', $funciones);

        products_flash_redirect($selectedId, ['type' => 'ok', 'msg' => $flashMsg]);
    }

    if ($action === 'delete_product') {
        $idProduct = (int)($_POST['id_product'] ?? 0);
        if ($idProduct > 0) {
            $newProducts = [];
            foreach ($localDb['productos'] as $idx => $row) {
                if ($idx === 0) {
                    $newProducts[] = $row;
                    continue;
                }
                if ((int)($row['ID_Product'] ?? 0) !== $idProduct) {
                    $newProducts[] = $row;
                }
            }
            $localDb['productos'] = $newProducts;
            CentralCommerceData::writeDatabase($selectedId, $localDb);
            products_flash_redirect($selectedId, ['type' => 'ok', 'msg' => 'Producto eliminado correctamente.']);
        }
    }
}

// Extraer lista de productos limpios
$rawProducts = $localDb['productos'] ?? [];
$products = [];
$tipos = [];
$totalValor = 0;
$conDescuento = 0;
$conImagen = 0;

foreach ($rawProducts as $idx => $prodRow) {
    if ($idx === 0 || !is_array($prodRow)) continue;
    $pId = $prodRow['ID_Product'] ?? null;
    $pName = trim((string)($prodRow['Nombre'] ?? ''));
    if ($pId === null || $pId === '' || $pName === '') continue;

    $tipo = trim((string)($prodRow['Tipo'] ?? 'General'));
    if ($tipo !== '' && !in_array($tipo, $tipos, true)) {
        $tipos[] = $tipo;
    }

    $precio = ProductCatalog::basePrice($prodRow);
    $descuento = ProductCatalog::discountPercent($prodRow);
    $precioEfectivo = ProductCatalog::effectivePrice($precio, $descuento);
    $mediaList = ProductCatalog::mediaForRow($prodRow);
    $imgCount = count($mediaList);

    $totalValor += $precio;
    if ($descuento > 0) $conDescuento++;
    if ($imgCount > 0) $conImagen++;

    // Resolver URL de portada
    $coverSrc = $prodRow['Img_src'] ?? ($mediaList[0]['src'] ?? '');
    $imgUrl = '';
    if ($coverSrc !== '') {
        $imgUrl = CommerceStorage::publicUrl($selectedId, $selectedSlug, $coverSrc);
        if ($imgUrl === '' && !preg_match('#^https?://#i', $coverSrc)) {
            $imgUrl = url($coverSrc);
        }
    }

    $products[] = [
        'id' => (int)$pId,
        'nombre' => $pName,
        'tipo' => $tipo,
        'precio' => $precio,
        'descuento' => $descuento,
        'precio_efectivo' => $precioEfectivo,
        'etiqueta' => ProductCatalog::saleLabel($prodRow),
        'descripcion' => trim((string)($prodRow['Descripcion'] ?? '')),
        'puntos' => (int)($prodRow['Puntos'] ?? 0),
        'img_src' => $coverSrc,
        'img_url' => $imgUrl,
        'img_count' => $imgCount,
        'media' => $mediaList,
        'raw' => $prodRow,
    ];
}

// Producto a editar si vino por GET
$editProduct = null;
$editMediaSlots = [];
for ($s = 0; $s < ProductCatalog::MAX_IMAGES; $s++) {
    $editMediaSlots[$s] = [
        'src' => '',
        'url' => '',
        'label' => '',
        'price' => '',
        'cover' => ($s === 0),
    ];
}

if (isset($_GET['edit_id'])) {
    $editId = (int)$_GET['edit_id'];
    foreach ($products as $p) {
        if ($p['id'] === $editId) {
            $editProduct = $p;
            $mediaRows = ProductCatalog::mediaForRow($p['raw']);
            foreach ($mediaRows as $idx => $m) {
                if ($idx < ProductCatalog::MAX_IMAGES) {
                    $src = (string)($m['src'] ?? '');
                    $mUrl = '';
                    if ($src !== '') {
                        $mUrl = CommerceStorage::publicUrl($selectedId, $selectedSlug, $src);
                        if ($mUrl === '' && !preg_match('#^https?://#i', $src)) {
                            $mUrl = url($src);
                        }
                    }
                    $editMediaSlots[$idx] = [
                        'src' => $src,
                        'url' => $mUrl,
                        'label' => (string)($m['label'] ?? ''),
                        'price' => isset($m['price']) && $m['price'] !== null ? (string)$m['price'] : '',
                        'cover' => !empty($m['cover']),
                    ];
                }
            }
            break;
        }
    }
}

$pageTitle = 'Productos · ' . $selectedCommerce['nombre'];
$activeSection = 'products';
require __DIR__ . '/partials/header.php';
?>

<div class="admin-main" style="max-width:1240px; margin:0 auto; padding:1.5rem 1rem">

    <?php if ($flash['msg']): ?>
        <div class="alert alert-<?= $flash['type'] === 'error' ? 'error' : 'ok' ?>" style="margin-bottom:1.5rem">
            <?= htmlspecialchars($flash['msg'], ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <!-- Selector de Comercio Superior -->
    <header class="card" style="margin-bottom:1.5rem; background:linear-gradient(135deg, rgba(124,58,237,0.06) 0%, rgba(59,130,246,0.04) 100%); border-left:4px solid #7c3aed">
        <div style="display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:1rem">
            <div style="display:flex; align-items:center; gap:1rem; flex:1; min-width:280px">
                <?php
                $commLogo = trim((string)($selectedCommerce['logo'] ?? ''));
                $commLogoUrl = '';
                if ($commLogo !== '') {
                    $commLogoUrl = CommerceStorage::publicUrl($selectedId, $selectedSlug, $commLogo);
                    if ($commLogoUrl === '' && !preg_match('#^https?://#i', $commLogo)) $commLogoUrl = url($commLogo);
                }
                ?>
                <?php if ($commLogoUrl !== ''): ?>
                    <img src="<?= htmlspecialchars($commLogoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="" style="width:54px; height:54px; object-fit:contain; background:#fff; border-radius:10px; padding:3px; border:1px solid rgba(0,0,0,0.1)">
                <?php endif; ?>
                <div>
                    <span class="hint" style="text-transform:uppercase; letter-spacing:0.05em; font-weight:700; color:#7c3aed">Super Admin · Catálogo Multi-Comercio</span>
                    <h1 style="margin:0.25rem 0 0.5rem; font-size:1.6rem; display:flex; align-items:center; gap:0.5rem">
                        <span>📦 Productos de</span>
                        <strong style="color:var(--text, #111827)"><?= htmlspecialchars((string)$selectedCommerce['nombre'], ENT_QUOTES, 'UTF-8') ?></strong>
                    </h1>
                    <p class="muted" style="margin:0; font-size:0.9rem">
                        Rubro: <strong><?= htmlspecialchars((string)($selectedCommerce['rubro_nombre'] ?? 'Sin rubro'), ENT_QUOTES, 'UTF-8') ?></strong>
                        · Slug: <code><?= htmlspecialchars($selectedSlug, ENT_QUOTES, 'UTF-8') ?></code>
                        · Estado: <span class="badge badge--<?= htmlspecialchars((string)$selectedCommerce['status'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string)$selectedCommerce['status'], ENT_QUOTES, 'UTF-8') ?></span>
                    </p>
                </div>
            </div>

            <!-- Selector desplegable -->
            <form method="get" action="commerce_products.php" style="display:flex; align-items:center; gap:0.5rem">
                <label for="select-commerce" style="font-weight:600; font-size:0.9rem; white-space:nowrap">Cambiar comercio:</label>
                <select name="id_commerce" id="select-commerce" onchange="this.form.submit()" style="min-width:220px; padding:0.55rem 0.85rem; border-radius:8px; border:1px solid var(--border, #d1d5db); background:var(--surface, #fff); font-weight:600">
                    <?php foreach ($commerces as $c): ?>
                        <option value="<?= (int)$c['id_commerce'] ?>" <?= (int)$c['id_commerce'] === $selectedId ? 'selected' : '' ?>>
                            <?= htmlspecialchars((string)$c['nombre'], ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars((string)$c['slug'], ENT_QUOTES, 'UTF-8') ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>

        <div style="margin-top:1.25rem; padding-top:1rem; border-top:1px solid rgba(0,0,0,0.06); display:flex; flex-wrap:wrap; gap:0.65rem; align-items:center">
            <a class="btn btn-primary" href="<?= htmlspecialchars(url($selectedSlug . '/#productos'), ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">
                <i class="bx bx-store"></i> Ver en Web Pública ↗
            </a>
            <a class="btn btn-ghost" href="<?= htmlspecialchars(url($selectedSlug . '/'), ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener" style="border:1px solid var(--border, #d1d5db)">
                <i class="bx bx-pencil"></i> Editar Web con Lápiz ✏️
            </a>
            <a class="btn btn-ghost" href="commerces.php?id=<?= $selectedId ?>" style="border:1px solid var(--border, #d1d5db)">
                <i class="bx bx-cog"></i> Configurar Comercio
            </a>
        </div>
    </header>

    <!-- KPIs del Catálogo -->
    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:1rem; margin-bottom:1.5rem">
        <div class="card" style="padding:1.1rem; text-align:center">
            <span class="muted" style="font-size:0.85rem; font-weight:600; text-transform:uppercase">Total Productos</span>
            <div style="font-size:2rem; font-weight:800; color:#7c3aed; margin-top:0.25rem"><?= count($products) ?></div>
        </div>
        <div class="card" style="padding:1.1rem; text-align:center">
            <span class="muted" style="font-size:0.85rem; font-weight:600; text-transform:uppercase">Categorías / Tipos</span>
            <div style="font-size:2rem; font-weight:800; color:#2563eb; margin-top:0.25rem"><?= count($tipos) ?></div>
        </div>
        <div class="card" style="padding:1.1rem; text-align:center">
            <span class="muted" style="font-size:0.85rem; font-weight:600; text-transform:uppercase">Con Descuento</span>
            <div style="font-size:2rem; font-weight:800; color:#16a34a; margin-top:0.25rem"><?= $conDescuento ?></div>
        </div>
        <div class="card" style="padding:1.1rem; text-align:center">
            <span class="muted" style="font-size:0.85rem; font-weight:600; text-transform:uppercase">Con Imágenes</span>
            <div style="font-size:2rem; font-weight:800; color:#ea580c; margin-top:0.25rem"><?= $conImagen ?></div>
        </div>
    </div>

    <!-- Layout Formulario + Listado -->
    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(360px, 1fr)); gap:1.5rem; align-items:start">

        <!-- Formulario Crear / Editar -->
        <article class="card" id="form-card" style="position:sticky; top:1rem">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem; border-bottom:1px solid var(--border, #e5e7eb); padding-bottom:0.75rem">
                <h2 style="margin:0; font-size:1.25rem">
                    <?= $editProduct ? '✏️ Editar Producto #' . $editProduct['id'] : '➕ Agregar Nuevo Producto' ?>
                </h2>
                <?php if ($editProduct): ?>
                    <a class="btn btn-ghost btn-sm" href="commerce_products.php?id_commerce=<?= $selectedId ?>">+ Nuevo</a>
                <?php endif; ?>
            </div>

            <form method="post" enctype="multipart/form-data" action="commerce_products.php?id_commerce=<?= $selectedId ?>">
                <?= CSRF::field('admin_products') ?>
                <input type="hidden" name="action" value="save_product">
                <input type="hidden" name="id_product" value="<?= (int)($editProduct['id'] ?? 0) ?>">

                <div class="form-grid" style="gap:1rem">
                    <div class="field col-2">
                        <label style="font-weight:600">Nombre del producto *</label>
                        <input type="text" name="nombre" required placeholder="Ej: Cera Modeladora Mate 100ml" value="<?= htmlspecialchars((string)($editProduct['nombre'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                    </div>

                    <div class="field">
                        <label style="font-weight:600">Tipo / Categoría</label>
                        <input type="text" name="tipo" list="tipos-list" placeholder="Ej: Cuidado Personal, Accesorios..." value="<?= htmlspecialchars((string)($editProduct['tipo'] ?? 'General'), ENT_QUOTES, 'UTF-8') ?>">
                        <datalist id="tipos-list">
                            <?php foreach ($tipos as $t): ?>
                                <option value="<?= htmlspecialchars($t, ENT_QUOTES, 'UTF-8') ?>">
                            <?php endforeach; ?>
                        </datalist>
                    </div>

                    <div class="field">
                        <label style="font-weight:600">Precio base ($) *</label>
                        <input type="number" name="precio" step="0.01" min="0" required placeholder="Ej: 450" value="<?= htmlspecialchars((string)($editProduct['precio'] ?? '0'), ENT_QUOTES, 'UTF-8') ?>">
                    </div>

                    <div class="field">
                        <label style="font-weight:600">Descuento (%)</label>
                        <input type="number" name="descuento_porcentaje" step="0.1" min="0" max="100" placeholder="0 para sin descuento" value="<?= htmlspecialchars((string)($editProduct['descuento'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                    </div>

                    <div class="field">
                        <label style="font-weight:600">Etiqueta de Venta</label>
                        <input type="text" name="etiqueta_venta" placeholder="Ej: 20% OFF, Nuevo, Destacado" value="<?= htmlspecialchars((string)($editProduct['etiqueta'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                    </div>

                    <div class="field col-2">
                        <label style="font-weight:600">Puntos de fidelidad (opcional)</label>
                        <input type="number" name="puntos" min="0" placeholder="Ej: 10" value="<?= htmlspecialchars((string)($editProduct['puntos'] ?? '0'), ENT_QUOTES, 'UTF-8') ?>">
                    </div>

                    <div class="field col-2">
                        <label style="font-weight:600">Descripción del producto</label>
                        <textarea name="descripcion" rows="3" placeholder="Detalles, modo de uso, beneficios..."><?= htmlspecialchars((string)($editProduct['descripcion'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
                    </div>

                    <!-- Grilla de Hasta 4 Fotos -->
                    <div class="field col-2" style="background:var(--surface-2, #1f2530); padding:1rem; border-radius:10px; border:1px solid var(--border, #2a313c)">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.75rem">
                            <div>
                                <strong style="font-size:0.95rem">📸 Fotos del Producto (Hasta 4 fotos)</strong>
                                <div class="muted" style="font-size:0.8rem">Marca una como Portada. Cada foto puede tener nombre o precio propio de variante.</div>
                            </div>
                        </div>

                        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(140px, 1fr)); gap:0.85rem">
                            <?php for ($i = 0; $i < ProductCatalog::MAX_IMAGES; $i++): ?>
                                <?php
                                $slotData = $editMediaSlots[$i] ?? ['src' => '', 'url' => '', 'label' => '', 'price' => '', 'cover' => ($i === 0)];
                                $hasImg = !empty($slotData['src']);
                                ?>
                                <div class="product-slot-card" id="slot-container-<?= $i ?>" style="background:var(--surface, #161b22); border:1px solid <?= !empty($slotData['cover']) ? '#7c3aed' : 'var(--border, #333)' ?>; border-radius:8px; padding:0.6rem; display:flex; flex-direction:column; gap:0.4rem; position:relative">
                                    <input type="hidden" name="Imagenes_Actuales[<?= $i ?>]" value="<?= htmlspecialchars((string)$slotData['src'], ENT_QUOTES, 'UTF-8') ?>" id="img-actual-<?= $i ?>">
                                    <input type="hidden" name="Imagenes_Quitar[<?= $i ?>]" value="" id="img-quitar-<?= $i ?>">

                                    <!-- Previsualización -->
                                    <div style="position:relative; width:100%; height:95px; background:rgba(0,0,0,0.06); border-radius:6px; overflow:hidden; display:flex; align-items:center; justify-content:center; border:1px dashed var(--border, #555)">
                                        <img src="<?= htmlspecialchars((string)$slotData['url'], ENT_QUOTES, 'UTF-8') ?>" id="preview-img-<?= $i ?>" alt="" style="width:100%; height:100%; object-fit:cover; <?= $hasImg ? '' : 'display:none;' ?>">
                                        <span id="preview-empty-<?= $i ?>" style="color:var(--muted, #888); font-size:1.5rem; <?= $hasImg ? 'display:none;' : '' ?>">
                                            📷
                                        </span>
                                        <?php if ($hasImg): ?>
                                            <button type="button" onclick="quitarFoto(<?= $i ?>)" title="Quitar foto" style="position:absolute; top:4px; right:4px; background:rgba(220,38,38,0.85); color:#fff; border:none; border-radius:4px; padding:2px 6px; cursor:pointer; font-size:0.75rem">
                                                ✕
                                            </button>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Selector Portada y Subida -->
                                    <div style="display:flex; align-items:center; justify-content:space-between; gap:0.25rem; font-size:0.75rem; margin-top:0.2rem">
                                        <label style="display:flex; align-items:center; gap:0.25rem; cursor:pointer; font-weight:600; color:<?= !empty($slotData['cover']) ? '#7c3aed' : 'var(--text)' ?>">
                                            <input type="radio" name="Portada_Index" value="<?= $i ?>" <?= !empty($slotData['cover']) ? 'checked' : '' ?> onchange="actualizarPortadaUI(<?= $i ?>)">
                                            <span>Portada</span>
                                        </label>
                                        <span class="muted">Foto <?= $i + 1 ?></span>
                                    </div>

                                    <!-- Input File -->
                                    <input type="file" name="Imagenes_Nuevas[<?= $i ?>]" accept="image/jpeg,image/png,image/webp,image/gif" onchange="previewFoto(this, <?= $i ?>)" style="font-size:0.75rem; width:100%; padding:0.2rem; border-radius:4px; border:1px solid var(--border, #444); background:var(--surface-2, #1f2530)">

                                    <!-- Nombre variante -->
                                    <input type="text" name="Imagenes_Titulos[<?= $i ?>]" placeholder="Nombre opción" value="<?= htmlspecialchars((string)$slotData['label'], ENT_QUOTES, 'UTF-8') ?>" style="font-size:0.75rem; padding:0.3rem 0.4rem; border-radius:4px; border:1px solid var(--border, #444); background:var(--surface-2, #1f2530)">

                                    <!-- Precio opcional -->
                                    <input type="number" step="0.01" min="0" name="Imagenes_Precios[<?= $i ?>]" placeholder="Precio variante" value="<?= htmlspecialchars((string)$slotData['price'], ENT_QUOTES, 'UTF-8') ?>" style="font-size:0.75rem; padding:0.3rem 0.4rem; border-radius:4px; border:1px solid var(--border, #444); background:var(--surface-2, #1f2530)">
                                </div>
                            <?php endfor; ?>
                        </div>
                        <span class="hint" style="display:block; margin-top:0.6rem">Formatos: JPG, PNG, WebP o GIF (máx. 5 MB por foto).</span>
                    </div>

                </div>

                <div class="actions" style="margin-top:1.25rem; display:flex; gap:0.75rem">
                    <button class="btn btn-primary" type="submit" style="flex:1">
                        <?= $editProduct ? '💾 Guardar Cambios' : '➕ Agregar Producto al Comercio' ?>
                    </button>
                    <?php if ($editProduct): ?>
                        <a class="btn" href="commerce_products.php?id_commerce=<?= $selectedId ?>">Cancelar</a>
                    <?php endif; ?>
                </div>
            </form>
        </article>

        <!-- Listado de Productos -->
        <div style="display:flex; flex-direction:column; gap:1rem">
            <article class="card">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem">
                    <h2 style="margin:0; font-size:1.25rem">
                        Catálogo Actual (<?= count($products) ?>)
                    </h2>
                    <span class="hint">Comercio: <?= htmlspecialchars((string)$selectedCommerce['nombre'], ENT_QUOTES, 'UTF-8') ?></span>
                </div>

                <?php if (empty($products)): ?>
                    <div style="text-align:center; padding:3rem 1rem; color:var(--muted, #6b7280)">
                        <div style="font-size:3rem; margin-bottom:0.5rem">📦</div>
                        <h3 style="margin:0 0 0.5rem; color:var(--text, #111827)">No hay productos registrados</h3>
                        <p style="margin:0; font-size:0.9rem">Usá el formulario de la izquierda para agregar el primer producto a este comercio.</p>
                    </div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="table" style="font-size:0.9rem">
                            <thead>
                                <tr>
                                    <th style="width:55px">Fotos</th>
                                    <th>Producto</th>
                                    <th>Categoría</th>
                                    <th>Precio</th>
                                    <th>Puntos</th>
                                    <th style="text-align:right">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($products as $prod): ?>
                                    <tr style="<?= ($editProduct && $editProduct['id'] === $prod['id']) ? 'background:rgba(124,58,237,0.06);' : '' ?>">
                                        <td>
                                            <div style="position:relative; width:46px; height:46px">
                                                <?php if (!empty($prod['img_url'])): ?>
                                                    <img src="<?= htmlspecialchars($prod['img_url'], ENT_QUOTES, 'UTF-8') ?>" alt="" style="width:46px; height:46px; object-fit:cover; border-radius:6px; border:1px solid var(--border, #ddd)">
                                                <?php else: ?>
                                                    <div style="width:46px; height:46px; border-radius:6px; background:#f3f4f6; display:flex; align-items:center; justify-content:center; color:#9ca3af; font-size:1.2rem">
                                                        📦
                                                    </div>
                                                <?php endif; ?>
                                                <?php if ($prod['img_count'] > 1): ?>
                                                    <span style="position:absolute; bottom:-3px; right:-3px; background:#7c3aed; color:#fff; font-size:0.65rem; font-weight:700; border-radius:10px; padding:1px 5px; border:1px solid #fff">
                                                        <?= $prod['img_count'] ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <strong><?= htmlspecialchars($prod['nombre'], ENT_QUOTES, 'UTF-8') ?></strong>
                                            <?php if ($prod['etiqueta'] !== ''): ?>
                                                <span class="badge" style="background:#ef4444; color:#fff; font-size:0.7rem; margin-left:0.35rem; padding:0.15rem 0.4rem; border-radius:4px"><?= htmlspecialchars($prod['etiqueta'], ENT_QUOTES, 'UTF-8') ?></span>
                                            <?php endif; ?>
                                            <?php if ($prod['descripcion'] !== ''): ?>
                                                <div class="muted" style="font-size:0.8rem; margin-top:0.15rem; max-width:240px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap">
                                                    <?= htmlspecialchars($prod['descripcion'], ENT_QUOTES, 'UTF-8') ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge" style="background:#e0e7ff; color:#3730a3; font-size:0.75rem"><?= htmlspecialchars($prod['tipo'], ENT_QUOTES, 'UTF-8') ?></span>
                                        </td>
                                        <td>
                                            <?php if ($prod['descuento'] > 0): ?>
                                                <div style="font-weight:700; color:#16a34a">$<?= number_format($prod['precio_efectivo'], 2) ?></div>
                                                <del class="muted" style="font-size:0.75rem">$<?= number_format($prod['precio'], 2) ?></del>
                                                <small style="color:#ef4444; font-weight:600">-<?= (float)$prod['descuento'] ?>%</small>
                                            <?php else: ?>
                                                <strong style="color:var(--text, #111827)">$<?= number_format($prod['precio'], 2) ?></strong>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?= $prod['puntos'] > 0 ? htmlspecialchars((string)$prod['puntos'], ENT_QUOTES, 'UTF-8') . ' pts' : '—' ?>
                                        </td>
                                        <td style="text-align:right; white-space:nowrap">
                                            <a class="btn btn-sm" href="commerce_products.php?id_commerce=<?= $selectedId ?>&edit_id=<?= $prod['id'] ?>#form-card">
                                                ✏️ Editar
                                            </a>
                                            <form method="post" action="commerce_products.php?id_commerce=<?= $selectedId ?>" style="display:inline" onsubmit="return confirm('¿Eliminar producto <?= htmlspecialchars(addslashes($prod['nombre']), ENT_QUOTES, 'UTF-8') ?>?');">
                                                <?= CSRF::field('admin_products') ?>
                                                <input type="hidden" name="action" value="delete_product">
                                                <input type="hidden" name="id_product" value="<?= $prod['id'] ?>">
                                                <button class="btn btn-sm btn-danger" type="submit" title="Eliminar">🗑️</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </article>
        </div>

    </div>

</div>

<script>
function previewFoto(input, slot) {
    if (input.files && input.files[0]) {
        var reader = new FileReader();
        reader.onload = function(e) {
            var img = document.getElementById('preview-img-' + slot);
            var empty = document.getElementById('preview-empty-' + slot);
            var quitar = document.getElementById('img-quitar-' + slot);
            if (img) {
                img.src = e.target.result;
                img.style.display = 'block';
            }
            if (empty) empty.style.display = 'none';
            if (quitar) quitar.value = '';
        };
        reader.readAsDataURL(input.files[0]);
    }
}

function quitarFoto(slot) {
    var img = document.getElementById('preview-img-' + slot);
    var empty = document.getElementById('preview-empty-' + slot);
    var actual = document.getElementById('img-actual-' + slot);
    var quitar = document.getElementById('img-quitar-' + slot);
    if (img) {
        img.src = '';
        img.style.display = 'none';
    }
    if (empty) empty.style.display = 'block';
    if (actual) actual.value = '';
    if (quitar) quitar.value = '1';
}

function actualizarPortadaUI(selectedSlot) {
    for (var i = 0; i < 4; i++) {
        var card = document.getElementById('slot-container-' + i);
        if (card) {
            if (i === selectedSlot) {
                card.style.borderColor = '#7c3aed';
            } else {
                card.style.borderColor = 'var(--border, #333)';
            }
        }
    }
}
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
