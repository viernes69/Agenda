<?php
/**
 * Agenduy - Commerce · Settings
 * Configuración del comercio: info, Google Calendar, API keys propias.
 */
declare(strict_types=1);

$config = require __DIR__ . '/../src/Core/bootstrap.php';

use Agenduy\Core\Auth;
use Agenduy\Core\CommercePanel;
use Agenduy\Core\CSRF;
use Agenduy\Core\Database;
use Agenduy\Core\Crypto;
use Agenduy\Core\Security;
use Agenduy\Core\CommerceStorage;
use Agenduy\Core\CentralCommerceData;

Auth::start();
if (!Auth::check() || Auth::role() !== 'commerce_admin') { header('Location: ' . Auth::loginUrl()); exit; }
Security::sendNoStoreHeaders();
$idCommerce = (int)Auth::commerceId();

$db = Database::getInstance();
$commerceRoute = $db->fetchOne('SELECT slug FROM commerces WHERE id_commerce = :id LIMIT 1', [':id' => $idCommerce]);
$slug = trim((string)($commerceRoute['slug'] ?? ''));
if ($slug !== '' && preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug)) {
    header('Location: ' . CommercePanel::dashboardUrlForSlug($slug, 'config'), true, 302);
    exit;
}

$encKey = (string)$db->config()['security']['encryption_key'];
$crypto = new Crypto($encKey);
$flash = ['type' => '', 'msg' => '', 'plain' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::checkRequest('commerce_settings');
    $action = $_POST['action'] ?? '';

    if ($action === 'save_info') {
        $logoExisting = trim((string)($_POST['logo_existing'] ?? ''));
        $removeLogo = !empty($_POST['remove_logo']);
        $logoPath = $removeLogo ? '' : $logoExisting;

        if (isset($_FILES['logo']) && is_array($_FILES['logo']) && ($_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $file = $_FILES['logo'];
            $ext = strtolower((string)pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
            $allowed = ['jpg','jpeg','png','webp','svg','gif'];
            if (in_array($ext, $allowed, true) && $file['size'] <= 5*1024*1024) {
                $uploadDir = CommerceStorage::kindDir($idCommerce, 'logo');
                $token = substr(str_replace('.', '', (string)microtime(true)), -6);
                $filename = 'logo_' . date('Ymd_His') . '_' . $token . '.' . $ext;
                $dest = rtrim($uploadDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;
                if (move_uploaded_file((string)$file['tmp_name'], $dest)) {
                    @chmod($dest, 0644);
                    $logoPath = CommerceStorage::relativePath($idCommerce, 'logo', $filename);
                }
            }
        }

        $data = [
            'nombre'        => trim((string)($_POST['nombre'] ?? '')),
            'slogan'        => trim((string)($_POST['slogan'] ?? '')),
            'descripcion'   => trim((string)($_POST['descripcion'] ?? '')),
            'telefono'      => trim((string)($_POST['telefono'] ?? '')),
            'whatsapp'      => trim((string)($_POST['whatsapp'] ?? '')),
            'email'         => trim((string)($_POST['email'] ?? '')),
            'pais'          => strtoupper(trim((string)($_POST['pais'] ?? 'UY'))),
            'ciudad'        => trim((string)($_POST['ciudad'] ?? '')),
            'calle'         => trim((string)($_POST['calle'] ?? '')),
            'timezone'      => trim((string)($_POST['timezone'] ?? 'America/Montevideo')),
            'logo'          => $logoPath,
            'updated_at'    => date('Y-m-d H:i:s'),
        ];
        if ($data['nombre'] === '') {
            $flash = ['type' => 'error', 'msg' => 'El nombre es obligatorio.'];
        } else {
            $db->update('commerces', $data, 'id_commerce = :c', [':c' => $idCommerce]);

            try {
                if (CommercePanel::localDatabaseExists($idCommerce)) {
                    $localDb = @include CommercePanel::localDatabasePath($idCommerce);
                    if (is_array($localDb)) {
                        if (!isset($localDb['info_barberia']) || !is_array($localDb['info_barberia'])) {
                            $localDb['info_barberia'] = [];
                        }
                        $localDb['info_barberia']['logo'] = $logoPath;
                        CentralCommerceData::writeDatabase($idCommerce, $localDb);
                    }
                }
            } catch (\Throwable $e) {}

            $flash = ['type' => 'ok', 'msg' => 'Datos guardados correctamente.'];
        }
    } elseif ($action === 'add_key') {
        $provider = (string)($_POST['provider'] ?? '');
        $keyName  = trim((string)($_POST['key_name'] ?? ''));
        $label    = trim((string)($_POST['label'] ?? ''));
        $autogen  = !empty($_POST['autogen']);
        $value    = (string)($_POST['key_value'] ?? '');

        if (!in_array($provider, ['mercadopago','paypal','google_calendar','google_service_account','generic'], true)) {
            $flash = ['type' => 'error', 'msg' => 'Provider inválido.'];
        } elseif ($keyName === '') {
            $flash = ['type' => 'error', 'msg' => 'Falta nombre.'];
        } else {
            if ($autogen) $value = bin2hex(random_bytes(32));
            if ($value === '') {
                $flash = ['type' => 'error', 'msg' => 'Falta valor o autogen.'];
            } else {
                $preview = substr($value, -4);
                $db->insert('api_keys', [
                    'id_commerce' => $idCommerce,
                    'provider'    => $provider,
                    'key_name'    => $keyName,
                    'key_value'   => $crypto->encrypt($value),
                    'key_preview' => $preview,
                    'label'       => $label,
                    'is_active'   => 1,
                ]);
                $flash = ['type' => 'ok', 'msg' => 'Key guardada.', 'plain' => $autogen ? $value : null];
            }
        }
    } elseif ($action === 'delete_key') {
        $id = (int)($_POST['id_key'] ?? 0);
        $db->delete('api_keys', 'id_key = :a AND id_commerce = :c', [':a' => $id, ':c' => $idCommerce]);
        $flash = ['type' => 'ok', 'msg' => 'Key eliminada.'];
    }
}

$commerce = $db->fetchOne('SELECT * FROM commerces WHERE id_commerce = :c', [':c' => $idCommerce]);
$keys = $db->fetchAll('SELECT * FROM api_keys WHERE id_commerce = :c ORDER BY provider, key_name', [':c' => $idCommerce]);
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Configuración · Agendarte</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="assets/css/admin.css">
<link rel="stylesheet" href="../public/assets/css/dlocal-admin.css">
</head>
<body>
<header class="topbar">
    <div class="topbar__brand"><a href="commerce_dashboard.php"><strong>Agendarte</strong></a></div>
    <nav class="topbar__nav">
        <a href="commerce_dashboard.php">Resumen</a>
        <a href="<?= htmlspecialchars(CommercePanel::urlForSlug((string)$commerce['slug']), ENT_QUOTES, 'UTF-8') ?>">Panel del negocio</a>
        <a href="commerce_appointments.php">Turnos</a>
        <a href="commerce_clients.php">Clientes</a>
        <a href="commerce_services.php">Servicios</a>
        <a href="commerce_plan.php">Mi Plan</a>
        <a href="commerce_settings.php" class="is-active">Configuración</a>
    </nav>
    <div class="topbar__user">
        <a class="btn btn-ghost btn-sm" href="logout.php">Salir</a>
    </div>
</header>
<main class="admin-main">
    <section class="page-header"><h1>Configuración de tu negocio</h1></section>
    <?php if ($flash['msg']): ?>
        <div class="alert alert-<?= $flash['type'] === 'error' ? 'error' : 'ok' ?>">
            <?= htmlspecialchars($flash['msg'], ENT_QUOTES, 'UTF-8') ?>
            <?php if (!empty($flash['plain'])): ?>
                <br><strong>Valor generado (guardalo):</strong>
                <code class="code"><?= htmlspecialchars($flash['plain'], ENT_QUOTES, 'UTF-8') ?></code>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <article class="card">
        <h2>Datos del negocio</h2>
        <form method="post" enctype="multipart/form-data">
            <?= CSRF::field('commerce_settings') ?>
            <input type="hidden" name="action" value="save_info">
            <input type="hidden" name="logo_existing" value="<?= htmlspecialchars((string)($commerce['logo'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
            <div class="form-grid">
                <div class="field">
                    <label>Nombre</label>
                    <input type="text" name="nombre" required value="<?= htmlspecialchars($commerce['nombre'], ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="field">
                    <label>Email</label>
                    <input type="email" name="email" value="<?= htmlspecialchars($commerce['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="field">
                    <label>Teléfono</label>
                    <input type="text" name="telefono" value="<?= htmlspecialchars($commerce['telefono'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="field">
                    <label>WhatsApp</label>
                    <input type="text" name="whatsapp" value="<?= htmlspecialchars($commerce['whatsapp'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="field">
                    <label>País</label>
                    <input type="text" name="pais" maxlength="3" value="<?= htmlspecialchars($commerce['pais'] ?? 'UY', ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="field">
                    <label>Ciudad</label>
                    <input type="text" name="ciudad" value="<?= htmlspecialchars($commerce['ciudad'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="field col-2">
                    <label>Logo del comercio</label>
                    <?php
                    $currentLogo = trim((string)($commerce['logo'] ?? ''));
                    $currentLogoUrl = '';
                    if ($currentLogo !== '') {
                        $currentLogoUrl = CommerceStorage::publicUrl((int)$idCommerce, (string)($commerce['slug'] ?? ''), $currentLogo);
                        if ($currentLogoUrl === '' && !preg_match('#^https?://#i', $currentLogo)) {
                            $currentLogoUrl = url($currentLogo);
                        }
                    }
                    ?>
                    <?php if ($currentLogoUrl !== ''): ?>
                        <div style="display:flex; align-items:center; gap:1rem; margin-bottom:0.6rem; padding:0.6rem 0.85rem; background:var(--surface-2, #1f2530); border-radius:8px; border:1px solid var(--border, #2a313c)">
                            <img src="<?= htmlspecialchars($currentLogoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="Logo actual" style="width:52px; height:52px; object-fit:contain; background:#fff; border-radius:8px; padding:3px; border:1px solid var(--border, #444)">
                            <div style="flex:1">
                                <strong style="font-size:0.9rem">Logo actual asignado</strong>
                                <div class="muted" style="font-size:0.8rem">Subí un nuevo archivo abajo para reemplazarlo o marcalo para eliminarlo.</div>
                            </div>
                            <label style="display:flex; align-items:center; gap:0.35rem; font-size:0.85rem; color:var(--danger, #dc2626); cursor:pointer">
                                <input type="checkbox" name="remove_logo" value="1"> Quitar logo
                            </label>
                        </div>
                    <?php endif; ?>
                    <input type="file" name="logo" accept="image/jpeg,image/png,image/webp,image/svg+xml,image/gif">
                    <span class="hint">Formatos: PNG, JPG, WebP, SVG o GIF (máx. 5 MB).</span>
                </div>
                <div class="field col-2">
                    <label>Calle y número</label>
                    <input type="text" name="calle" value="<?= htmlspecialchars($commerce['calle'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="field">
                    <label>Zona horaria</label>
                    <input type="text" name="timezone" value="<?= htmlspecialchars($commerce['timezone'] ?? 'America/Montevideo', ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="field col-2">
                    <label>Slogan</label>
                    <input type="text" name="slogan" value="<?= htmlspecialchars($commerce['slogan'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="field col-2">
                    <label>Descripción</label>
                    <textarea name="descripcion"><?= htmlspecialchars($commerce['descripcion'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                </div>
            </div>
            <div class="actions">
                <button class="btn btn-primary" type="submit">Guardar</button>
            </div>
        </form>
    </article>

    <article class="card">
        <h2>Integraciones (API Keys)</h2>
        <p class="muted">Estas keys se usan SOLO para tu negocio. Mercado Pago de tienda usa tus credenciales; las membresias se cobran con la configuracion global del super admin.</p>
        <form method="post">
            <?= CSRF::field('commerce_settings') ?>
            <input type="hidden" name="action" value="add_key">
            <div class="form-grid">
                <div class="field">
                    <label>Provider</label>
                    <select name="provider">
                        <option value="mercadopago">mercadopago</option>
                        <option value="paypal">paypal</option>
                        <option value="google_calendar">google_calendar</option>
                        <option value="google_service_account">google_service_account</option>
                        <option value="generic">generic</option>
                    </select>
                </div>
                <div class="field">
                    <label>Nombre</label>
                    <input type="text" name="key_name" placeholder="MP_ACCESS_TOKEN" required>
                </div>
                <div class="field">
                    <label>Etiqueta</label>
                    <input type="text" name="label" placeholder="Producción 2026">
                </div>
                <div class="field col-2">
                    <label>
                        <input type="checkbox" name="autogen" value="1" id="autogen-cb"> Autogenerar
                    </label>
                    <textarea name="key_value" id="key-value" placeholder="Pegá el valor (si no autogenerás)" disabled></textarea>
                </div>
            </div>
            <div class="actions">
                <button class="btn btn-primary" type="submit">Guardar key</button>
            </div>
        </form>
        <script>
        (function(){
            var cb = document.getElementById('autogen-cb');
            var inp = document.getElementById('key-value');
            if (!cb || !inp) return;
            cb.addEventListener('change', function(){
                inp.disabled = cb.checked;
                if (cb.checked) inp.value = '';
            });
        })();
        </script>

        <h3 style="margin-top:1.5rem">Mis keys</h3>
        <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Provider</th><th>Nombre</th><th>Etiqueta</th><th>Preview</th><th>Activo</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($keys as $k): ?>
                <tr>
                    <td><?= htmlspecialchars($k['provider'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><code class="code"><?= htmlspecialchars($k['key_name'], ENT_QUOTES, 'UTF-8') ?></code></td>
                    <td><?= htmlspecialchars($k['label'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><code class="code">…<?= htmlspecialchars($k['key_preview'], ENT_QUOTES, 'UTF-8') ?></code></td>
                    <td><?= (int)$k['is_active'] === 1 ? '✓' : '✕' ?></td>
                    <td>
                        <form method="post" style="display:inline" onsubmit="return confirm('¿Eliminar?');">
                            <?= CSRF::field('commerce_settings') ?>
                            <input type="hidden" name="action" value="delete_key">
                            <input type="hidden" name="id_key" value="<?= (int)$k['id_key'] ?>">
                            <button class="btn btn-sm btn-danger" type="submit">×</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </article>

    <?php
    require_once __DIR__ . '/../src/components/dlocal/admin_plans.php';
    echo AgenduyDlocalAdmin::render();
    ?>
</main>
<script>window.__DLOCAL_ENDPOINTS__ = <?= json_encode([
    'config' => url('src/API/dlocal/config_save.php'),
    'createPlan' => url('src/API/dlocal/create_plan.php'),
], JSON_UNESCAPED_SLASHES) ?>;</script>
<script src="../public/assets/js/dlocal-admin.js"></script>
</body>
</html>
