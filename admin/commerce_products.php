<?php
/**
 * Agenduy - Super Admin: Gestión de Productos de Comercios
 * Permite seleccionar cualquier comercio y agregar, editar o eliminar productos.
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
    'SELECT c.id_commerce, c.nombre, c.slug, c.status, r.nombre AS rubro_nombre
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
        $imgSrc = trim((string)($_POST['img_src_existing'] ?? ''));

        // Carga de archivo de imagen principal
        if (isset($_FILES['imagen']) && is_array($_FILES['imagen']) && ($_FILES['imagen']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $file = $_FILES['imagen'];
            $maxBytes = 5 * 1024 * 1024;
            $allowedExts = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
            $ext = strtolower((string)pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));

            if ($file['size'] > $maxBytes) {
                products_flash_redirect($selectedId, ['type' => 'error', 'msg' => 'La imagen supera el límite de 5 MB.']);
            } elseif (!in_array($ext, $allowedExts, true)) {
                products_flash_redirect($selectedId, ['type' => 'error', 'msg' => 'Formato no válido (usá JPG, PNG, WebP o GIF).']);
            } else {
                $uploadDir = CommerceStorage::kindDir($selectedId, 'products');
                $token = substr(str_replace('.', '', (string)microtime(true)), -6);
                $filename = 'producto_' . date('Ymd_His') . '_' . $token . '.' . $ext;
                $dest = rtrim($uploadDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;
                if (move_uploaded_file((string)$file['tmp_name'], $dest)) {
                    @chmod($dest, 0644);
                    $imgSrc = CommerceStorage::relativePath($selectedId, 'products', $filename);
                }
            }
        }

        if ($nombre === '') {
            products_flash_redirect($selectedId, ['type' => 'error', 'msg' => 'El nombre del producto es obligatorio.']);
        }

        // Construir registro
        $productData = [
            'Nombre' => $nombre,
            'Tipo' => $tipo !== '' ? $tipo : 'General',
            'Precio' => $precio,
            'Descripcion' => $descripcion,
            'Puntos' => $puntos,
            'Img_src' => $imgSrc,
            'Img_Gallery' => '',
            'Imagenes' => json_encode([
                [
                    'src' => $imgSrc,
                    'price' => $precio,
                    'label' => $nombre,
                    'cover' => true,
                ]
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]',
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
            $flashMsg = 'Producto "' . $nombre . '" actualizado correctamente.';
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
            $flashMsg = 'Producto "' . $nombre . '" agregado al comercio exitosamente.';
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
    $img = trim((string)($prodRow['Img_src'] ?? ''));

    $totalValor += $precio;
    if ($descuento > 0) $conDescuento++;
    if ($img !== '') $conImagen++;

    // Resolver URL de imagen
    $imgUrl = '';
    if ($img !== '') {
        $imgUrl = CommerceStorage::publicUrl($selectedId, $selectedSlug, $img);
        if ($imgUrl === '' && !preg_match('#^https?://#i', $img)) {
            $imgUrl = url($img);
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
        'img_src' => $img,
        'img_url' => $imgUrl,
    ];
}

// Producto a editar si vino por GET
$editProduct = null;
if (isset($_GET['edit_id'])) {
    $editId = (int)$_GET['edit_id'];
    foreach ($products as $p) {
        if ($p['id'] === $editId) {
            $editProduct = $p;
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
            <div style="flex:1; min-width:280px">
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
            <span class="muted" style="font-size:0.85rem; font-weight:600; text-transform:uppercase">Con Imagen</span>
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
                <input type="hidden" name="img_src_existing" value="<?= htmlspecialchars((string)($editProduct['img_src'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">

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
                        <label style="font-weight:600">Precio base ($)</label>
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

                    <div class="field col-2">
                        <label style="font-weight:600">Imagen del producto</label>
                        <?php if (!empty($editProduct['img_url'])): ?>
                            <div style="display:flex; align-items:center; gap:1rem; margin-bottom:0.75rem; background:rgba(0,0,0,0.03); padding:0.5rem 0.75rem; border-radius:8px">
                                <img src="<?= htmlspecialchars($editProduct['img_url'], ENT_QUOTES, 'UTF-8') ?>" alt="Preview" style="width:50px; height:50px; object-fit:cover; border-radius:6px; border:1px solid #ddd">
                                <div style="font-size:0.85rem">
                                    <span class="muted">Imagen actual cargada</span><br>
                                    <small>Subí una nueva abajo para reemplazarla.</small>
                                </div>
                            </div>
                        <?php endif; ?>
                        <input type="file" name="imagen" accept="image/jpeg,image/png,image/webp,image/gif" style="width:100%; padding:0.4rem; border:1px dashed var(--border, #ccc); border-radius:6px">
                        <span class="hint">Formatos: JPG, PNG, WebP, GIF. Máximo 5 MB.</span>
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
                                    <th style="width:50px">Foto</th>
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
                                            <?php if (!empty($prod['img_url'])): ?>
                                                <img src="<?= htmlspecialchars($prod['img_url'], ENT_QUOTES, 'UTF-8') ?>" alt="" style="width:42px; height:42px; object-fit:cover; border-radius:6px; border:1px solid var(--border, #ddd)">
                                            <?php else: ?>
                                                <div style="width:42px; height:42px; border-radius:6px; background:#f3f4f6; display:flex; align-items:center; justify-content:center; color:#9ca3af; font-size:1.2rem">
                                                    📦
                                                </div>
                                            <?php endif; ?>
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

<?php require __DIR__ . '/partials/footer.php'; ?>
