<?php
/**
 * Agenduy - Super Admin: Rubros (tipos de locales/negocios)
 * CRUD para el carousel del index, registro y marketplace.
 */
declare(strict_types=1);

$config = require __DIR__ . '/../src/Core/bootstrap.php';
use Agenduy\Core\Auth;
use Agenduy\Core\CSRF;
use Agenduy\Core\Database;
use Agenduy\Core\Keys;

Auth::start();
if (!Auth::check() || Auth::role() !== 'super_admin') { header('Location: ' . Auth::loginUrl()); exit; }

$db = Database::getInstance();
$flash = ['type' => '', 'msg' => ''];
$root = dirname(__DIR__);

$carouselDir = $root . '/src/media/carousel';
$imagenOptions = [];
if (is_dir($carouselDir)) {
    foreach (['jpg', 'jpeg', 'png', 'webp'] as $ext) {
        foreach (glob($carouselDir . '/*.' . $ext) ?: [] as $file) {
            $rel = 'src/media/carousel/' . basename($file);
            $imagenOptions[$rel] = basename($file);
        }
    }
    asort($imagenOptions, SORT_NATURAL | SORT_FLAG_CASE);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::checkRequest('rubros_admin');
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id = (int)($_POST['id_rubro'] ?? 0);
        $nombre = trim((string)($_POST['nombre'] ?? ''));
        $tipoIn = trim((string)($_POST['tipo'] ?? ''));
        $tipo = $tipoIn !== '' ? Keys::slug($tipoIn) : Keys::slug($nombre);
        $descripcion = trim((string)($_POST['descripcion'] ?? ''));
        $imagen = trim((string)($_POST['imagen'] ?? ''));
        $imagenCustom = trim((string)($_POST['imagen_custom'] ?? ''));
        if ($imagen === '__custom__' && $imagenCustom !== '') {
            $imagen = ltrim(str_replace('\\', '/', $imagenCustom), '/');
        }
        if ($imagen === '__custom__') {
            $imagen = '';
        }
        $orden = (int)($_POST['orden'] ?? 0);
        $idPlanDef = (int)($_POST['id_plan_def'] ?? 0);
        $activo = isset($_POST['activo']) ? 1 : 0;

        if ($nombre === '') {
            $flash = ['type' => 'error', 'msg' => 'El nombre es obligatorio.'];
        } elseif ($tipo === '') {
            $flash = ['type' => 'error', 'msg' => 'El slug (tipo) es obligatorio.'];
        } else {
            $dup = $db->fetchOne(
                'SELECT id_rubro FROM rubros WHERE tipo = :t AND id_rubro != :id',
                [':t' => $tipo, ':id' => $id]
            );
            if ($dup) {
                $flash = ['type' => 'error', 'msg' => "Ya existe un rubro con el slug «{$tipo}»."];
            } else {
                $data = [
                    'nombre'      => $nombre,
                    'tipo'        => $tipo,
                    'descripcion' => $descripcion,
                    'imagen'      => $imagen,
                    'orden'       => $orden,
                    'activo'      => $activo,
                    'updated_at'  => date('Y-m-d H:i:s'),
                ];
                if ($idPlanDef > 0) {
                    $data['id_plan_def'] = $idPlanDef;
                } else {
                    $data['id_plan_def'] = null;
                }
                if ($id > 0) {
                    $db->update('rubros', $data, 'id_rubro = :id', [':id' => $id]);
                    Auth::audit('save_rubro', 'rubro', $id);
                    $flash = ['type' => 'ok', 'msg' => 'Rubro actualizado. Aparece en el index si está activo.'];
                } else {
                    if ($orden <= 0) {
                        $max = (int)($db->fetchValue('SELECT COALESCE(MAX(orden), 0) FROM rubros') ?? 0);
                        $data['orden'] = $max + 10;
                    }
                    $id = $db->insert('rubros', $data);
                    Auth::audit('create_rubro', 'rubro', $id);
                    $flash = ['type' => 'ok', 'msg' => 'Rubro creado. Visible en el index y en el registro.'];
                }
                header('Location: rubros.php?saved=1&id=' . $id);
                exit;
            }
        }
    } elseif ($action === 'toggle') {
        $id = (int)($_POST['id_rubro'] ?? 0);
        if ($id > 0) {
            $row = $db->fetchOne('SELECT activo FROM rubros WHERE id_rubro = :id', [':id' => $id]);
            if ($row) {
                $next = (int)$row['activo'] === 1 ? 0 : 1;
                $db->update('rubros', [
                    'activo'     => $next,
                    'updated_at' => date('Y-m-d H:i:s'),
                ], 'id_rubro = :id', [':id' => $id]);
                Auth::audit('toggle_rubro', 'rubro', $id, null, ['activo' => $next]);
                $flash = ['type' => 'ok', 'msg' => $next === 1 ? 'Rubro activado.' : 'Rubro desactivado (ya no aparece en el index).'];
            }
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id_rubro'] ?? 0);
        if ($id > 0) {
            $used = (int)$db->fetchValue('SELECT COUNT(*) FROM commerces WHERE id_rubro = :id', [':id' => $id]);
            if ($used > 0) {
                $flash = ['type' => 'error', 'msg' => "No se puede borrar: {$used} comercio(s) lo usan. Desactivá en su lugar."];
            } else {
                $db->delete('rubros', 'id_rubro = :id', [':id' => $id]);
                Auth::audit('delete_rubro', 'rubro', $id);
                $flash = ['type' => 'ok', 'msg' => 'Rubro eliminado.'];
            }
        }
    }
}

if (isset($_GET['saved']) && $flash['msg'] === '') {
    $flash = ['type' => 'ok', 'msg' => 'Rubro guardado.'];
}

$rubros = $db->fetchAll(
    "SELECT r.*,
            (SELECT COUNT(*) FROM commerces c WHERE c.id_rubro = r.id_rubro) AS commerces_count
     FROM rubros r
     ORDER BY r.orden ASC, r.nombre COLLATE NOCASE ASC"
);

$memberships = $db->fetchAll(
    'SELECT id_membership, nombre FROM memberships WHERE activo = 1 ORDER BY nombre'
);

$edit = null;
if (isset($_GET['id'])) {
    $edit = $db->fetchOne('SELECT * FROM rubros WHERE id_rubro = :id', [':id' => (int)$_GET['id']]);
}

$pageTitle = 'Rubros';
$activeSection = 'rubros';
require __DIR__ . '/partials/header.php';

$editImagen = (string)($edit['imagen'] ?? '');
$imagenIsListed = $editImagen !== '' && isset($imagenOptions[$editImagen]);
?>

<?php if ($flash['msg']): ?>
    <div class="alert alert-<?= $flash['type'] === 'error' ? 'error' : 'ok' ?>"><?= htmlspecialchars($flash['msg'], ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<section class="page-header">
    <h1>Rubros</h1>
    <p>Tipos de locales/negocios del index, registro y marketplace. Activá o creá «Tienda», «Comercio», etc. sin tocar código.</p>
</section>

<article class="card">
    <h2><?= $edit ? 'Editar rubro' : 'Nuevo rubro' ?></h2>
    <form method="post">
        <?= CSRF::field('rubros_admin') ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id_rubro" value="<?= (int)($edit['id_rubro'] ?? 0) ?>">
        <div class="form-grid">
            <div class="field">
                <label>Nombre</label>
                <input type="text" name="nombre" required maxlength="120"
                       value="<?= htmlspecialchars($edit['nombre'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                       placeholder="Ej. Tienda">
            </div>
            <div class="field">
                <label>Slug (tipo)</label>
                <input type="text" name="tipo" maxlength="80"
                       value="<?= htmlspecialchars($edit['tipo'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                       placeholder="Auto desde el nombre (ej. tienda)">
                <span class="hint">Identificador único. Si lo dejás vacío se genera del nombre.</span>
            </div>
            <div class="field">
                <label>Orden</label>
                <input type="number" name="orden" step="1"
                       value="<?= (int)($edit['orden'] ?? 0) ?>"
                       placeholder="0 = al final">
                <span class="hint">Menor número = aparece antes en el carousel del index.</span>
            </div>
            <div class="field">
                <label>Activo</label>
                <label style="display:flex; align-items:center; gap:.5rem; font-size: 1rem">
                    <input type="checkbox" name="activo" <?= !isset($edit) || (int)($edit['activo'] ?? 1) === 1 ? 'checked' : '' ?>>
                    Visible en index y registro
                </label>
            </div>
            <div class="field">
                <label>Imagen (carousel)</label>
                <select name="imagen" id="rubro-imagen-select">
                    <option value="">— Sin imagen (usa default) —</option>
                    <?php foreach ($imagenOptions as $path => $label): ?>
                        <option value="<?= htmlspecialchars($path, ENT_QUOTES, 'UTF-8') ?>"
                            <?= $imagenIsListed && $editImagen === $path ? 'selected' : '' ?>>
                            <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                    <option value="__custom__" <?= $editImagen !== '' && !$imagenIsListed ? 'selected' : '' ?>>Ruta personalizada…</option>
                </select>
            </div>
            <div class="field" id="rubro-imagen-custom-wrap" style="<?= $editImagen !== '' && !$imagenIsListed ? '' : 'display:none' ?>">
                <label>Ruta personalizada</label>
                <input type="text" name="imagen_custom"
                       value="<?= htmlspecialchars($imagenIsListed ? '' : $editImagen, ENT_QUOTES, 'UTF-8') ?>"
                       placeholder="src/media/carousel/mi-imagen.jpg">
            </div>
            <div class="field">
                <label>Plan por defecto</label>
                <select name="id_plan_def">
                    <option value="0">— Ninguno —</option>
                    <?php foreach ($memberships as $m): ?>
                        <option value="<?= (int)$m['id_membership'] ?>"
                            <?= (int)($edit['id_plan_def'] ?? 0) === (int)$m['id_membership'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($m['nombre'], ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field col-2">
                <label>Descripción</label>
                <textarea name="descripcion" rows="3" placeholder="Texto corto para modales y registro"><?= htmlspecialchars($edit['descripcion'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
            </div>
        </div>
        <div class="actions">
            <button class="btn btn-primary" type="submit"><?= $edit ? 'Guardar' : 'Crear' ?></button>
            <?php if ($edit): ?><a class="btn" href="rubros.php">Cancelar</a><?php endif; ?>
        </div>
    </form>
</article>

<article class="card">
    <h2>Rubros existentes (<?= count($rubros) ?>)</h2>
    <div class="table-wrap table-wrap--scroll">
    <table class="table">
        <thead>
            <tr>
                <th>Orden</th>
                <th>Nombre</th>
                <th>Slug</th>
                <th>Imagen</th>
                <th>Activo</th>
                <th>Comercios</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($rubros)): ?>
            <tr><td colspan="7">No hay rubros. Creá el primero arriba.</td></tr>
        <?php else: ?>
            <?php foreach ($rubros as $r):
                $img = trim((string)($r['imagen'] ?? ''));
                $imgOk = $img !== '' && is_file($root . '/' . ltrim(str_replace('\\', '/', $img), '/'));
            ?>
            <tr>
                <td><?= (int)$r['orden'] ?></td>
                <td><strong><?= htmlspecialchars($r['nombre'], ENT_QUOTES, 'UTF-8') ?></strong>
                    <?php if (trim((string)($r['descripcion'] ?? '')) !== ''): ?>
                        <div class="hint"><?= htmlspecialchars(function_exists('mb_strimwidth') ? mb_strimwidth((string)$r['descripcion'], 0, 80, '…') : (strlen((string)$r['descripcion']) > 80 ? substr((string)$r['descripcion'], 0, 77) . '…' : (string)$r['descripcion']), ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>
                </td>
                <td><code class="code"><?= htmlspecialchars($r['tipo'], ENT_QUOTES, 'UTF-8') ?></code></td>
                <td>
                    <?php if ($imgOk): ?>
                        <span title="<?= htmlspecialchars($img, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(basename($img), ENT_QUOTES, 'UTF-8') ?></span>
                    <?php elseif ($img !== ''): ?>
                        <span class="hint" title="<?= htmlspecialchars($img, ENT_QUOTES, 'UTF-8') ?>">archivo faltante</span>
                    <?php else: ?>
                        —
                    <?php endif; ?>
                </td>
                <td><?= (int)$r['activo'] === 1 ? '✓' : '✕' ?></td>
                <td><?= (int)$r['commerces_count'] ?></td>
                <td>
                    <a class="btn btn-sm" href="rubros.php?id=<?= (int)$r['id_rubro'] ?>">editar</a>
                    <form method="post" style="display:inline">
                        <?= CSRF::field('rubros_admin') ?>
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="id_rubro" value="<?= (int)$r['id_rubro'] ?>">
                        <button class="btn btn-sm" type="submit"><?= (int)$r['activo'] === 1 ? 'desactivar' : 'activar' ?></button>
                    </form>
                    <form method="post" style="display:inline" onsubmit="return confirm('¿Eliminar este rubro?');">
                        <?= CSRF::field('rubros_admin') ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id_rubro" value="<?= (int)$r['id_rubro'] ?>">
                        <button class="btn btn-sm btn-danger" type="submit" <?= (int)$r['commerces_count'] > 0 ? 'disabled title="En uso por comercios"' : '' ?>>×</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</article>

<script>
(function () {
    var sel = document.getElementById('rubro-imagen-select');
    var wrap = document.getElementById('rubro-imagen-custom-wrap');
    if (!sel || !wrap) return;
    function sync() {
        wrap.style.display = sel.value === '__custom__' ? '' : 'none';
    }
    sel.addEventListener('change', sync);
    sync();
})();
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
