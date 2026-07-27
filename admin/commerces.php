<?php
/**
 * Agenduy - Super Admin: Comercios
 * Lista, crea, edita, suspende comercios.
 */
declare(strict_types=1);

$config = require __DIR__ . '/../src/Core/bootstrap.php';
use Agenduy\Core\Auth;
use Agenduy\Core\CSRF;
use Agenduy\Core\Database;
use Agenduy\Core\Keys;
use Agenduy\Core\TenantMigrator;
use Agenduy\Core\TenantAudit;
use Agenduy\Core\TenantConfig;

Auth::start();
if (!Auth::check() || Auth::role() !== 'super_admin') { header('Location: ' . Auth::loginUrl()); exit; }

$db   = Database::getInstance();
$flash = ['type' => '', 'msg' => ''];

// Acciones POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::checkRequest('commerces_admin');
    $action = $_POST['action'] ?? '';

    if ($action === 'save_commerce') {
        $id = (int)($_POST['id_commerce'] ?? 0);
        $slug = Keys::slug((string)($_POST['slug'] ?? ''));
        if ($slug === '') {
            $flash = ['type' => 'error', 'msg' => 'El slug es obligatorio.'];
        } else {
            $data = [
                'slug'           => $slug,
                'id_rubro'       => (int)($_POST['id_rubro'] ?? 0),
                'id_membership'  => (int)($_POST['id_membership'] ?? 0) ?: null,
                'nombre'         => trim((string)($_POST['nombre'] ?? '')),
                'razon_social'   => trim((string)($_POST['razon_social'] ?? '')),
                'rut_ruc'        => trim((string)($_POST['rut_ruc'] ?? '')),
                'email'          => trim((string)($_POST['email'] ?? '')),
                'telefono'       => trim((string)($_POST['telefono'] ?? '')),
                'whatsapp'       => trim((string)($_POST['whatsapp'] ?? '')),
                'pais'           => strtoupper(trim((string)($_POST['pais'] ?? 'UY'))),
                'ciudad'         => trim((string)($_POST['ciudad'] ?? '')),
                'calle'          => trim((string)($_POST['calle'] ?? '')),
                'slogan'         => trim((string)($_POST['slogan'] ?? '')),
                'descripcion'    => trim((string)($_POST['descripcion'] ?? '')),
                'status'         => in_array($_POST['status'] ?? '', ['trial','active','past_due','cancelled','suspended'], true)
                                   ? $_POST['status'] : 'trial',
                'updated_at'     => date('Y-m-d H:i:s'),
            ];
            if ($data['id_rubro'] <= 0) {
                $firstRubro = $db->fetchOne('SELECT id_rubro FROM rubros ORDER BY id_rubro ASC LIMIT 1');
                $data['id_rubro'] = $firstRubro ? (int)$firstRubro['id_rubro'] : 1;
            }
            if ($id > 0) {
                $db->update('commerces', $data, 'id_commerce = :id', [':id' => $id]);
                $flash = ['type' => 'ok', 'msg' => 'Comercio actualizado.'];
            } else {
                $data['serial'] = Keys::serial();
                $data['trial_expires_at'] = date('Y-m-d', strtotime('+30 days'));
                $id = $db->insert('commerces', $data);
                // Crear usuario admin inicial si se dio email
                $emailAdmin = trim((string)($_POST['admin_email'] ?? ''));
                $pwdAdmin   = trim((string)($_POST['admin_password'] ?? ''));
                if ($emailAdmin !== '' && $pwdAdmin !== '') {
                    $exists = $db->fetchOne('SELECT id_user FROM users WHERE email = :e', [':e' => $emailAdmin]);
                    if (!$exists) {
                        $db->insert('users', [
                            'role'          => 'commerce_admin',
                            'id_commerce'   => $id,
                            'nombre'        => trim((string)($_POST['admin_nombre'] ?? 'Admin')),
                            'apellido'      => trim((string)($_POST['admin_apellido'] ?? '')),
                            'email'         => $emailAdmin,
                            'password_hash' => password_hash($pwdAdmin, PASSWORD_BCRYPT, ['cost' => 12]),
                            'activo'        => 1,
                        ]);
                    }
                }
                // Crear subscription trial
                if (!empty($data['id_membership'])) {
                    $db->insert('subscriptions', [
                        'id_commerce'        => $id,
                        'id_membership'      => $data['id_membership'],
                        'status'             => 'trial',
                        'trial_expires_at'   => $data['trial_expires_at'],
                        'current_period_start'=> date('Y-m-d'),
                        'current_period_end' => $data['trial_expires_at'],
                    ]);
                }
                $flash = ['type' => 'ok', 'msg' => 'Comercio creado.'];
            }
            Auth::audit('save_commerce', 'commerce', $id ?: null, null, ['slug' => $slug]);
        }
    } elseif ($action === 'delete_commerce') {
        $id = (int)($_POST['id_commerce'] ?? 0);
        if ($id > 0) {
            $db->delete('commerces', 'id_commerce = :id', [':id' => $id]);
            Auth::audit('delete_commerce', 'commerce', $id);
            $flash = ['type' => 'ok', 'msg' => 'Comercio eliminado.'];
        }
    } elseif ($action === 'extend_trial') {
        $id = (int)($_POST['id_commerce'] ?? 0);
        $days = max(1, (int)($_POST['days'] ?? 7));
        $c = $db->fetchOne('SELECT trial_expires_at, status FROM commerces WHERE id_commerce = :id', [':id' => $id]);
        if ($c) {
            $base = !empty($c['trial_expires_at']) ? strtotime((string)$c['trial_expires_at']) : time();
            if ($base < time()) $base = time();
            $new = date('Y-m-d', $base + $days * 86400);
            $db->update('commerces', [
                'trial_expires_at' => $new,
                'status'           => 'trial',
                'updated_at'       => date('Y-m-d H:i:s'),
            ], 'id_commerce = :id', [':id' => $id]);
            Auth::audit('extend_trial', 'commerce', $id, null, ['days' => $days, 'new_date' => $new]);
            $flash = ['type' => 'ok', 'msg' => "Trial extendido +{$days} días (vence {$new})."];
        }
    } elseif ($action === 'reset_password') {
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $u = $db->fetchOne('SELECT id_user FROM users WHERE email = :e', [':e' => $email]);
        if ($u) {
            $newPwd = bin2hex(random_bytes(6));
            $db->update('users', [
                'password_hash'  => password_hash($newPwd, PASSWORD_BCRYPT, ['cost' => 12]),
                'failed_attempts'=> 0,
                'locked_until'   => null,
            ], 'id_user = :id', [':id' => $u['id_user']]);
            Auth::audit('reset_password', 'user', (int)$u['id_user']);
            $flash = ['type' => 'ok', 'msg' => "Nueva contraseña para {$email}: {$newPwd}"];
        } else {
            $flash = ['type' => 'error', 'msg' => "No se encontró el usuario {$email}."];
        }
    } elseif ($action === 'import_tenant_folder') {
        $slug = Keys::slug((string)($_POST['slug'] ?? ''));
        if ($slug === '') {
            $flash = ['type' => 'error', 'msg' => 'Slug inválido.'];
        } else {
            try {
                $result = TenantMigrator::import($slug, dirname(__DIR__));
                Auth::audit('import_tenant_folder', 'commerce', $result['id_commerce'], null, ['slug' => $slug]);
                $flash = [
                    'type' => 'ok',
                    'msg'  => $result['message'] . ' Servicios nuevos: ' . (int)$result['services_added'] . '.',
                ];
            } catch (Throwable $e) {
                $flash = ['type' => 'error', 'msg' => $e->getMessage()];
            }
        }
    }
}

// Datos para el listado
$q = trim((string)($_GET['q'] ?? ''));
$status = trim((string)($_GET['status'] ?? ''));

$where = '1=1';
$params = [];
if ($q !== '') {
    $where .= ' AND (c.nombre LIKE :q OR c.slug LIKE :q OR c.email LIKE :q)';
    $params[':q'] = '%' . $q . '%';
}
if ($status !== '' && in_array($status, ['trial','active','past_due','cancelled','suspended'], true)) {
    $where .= ' AND c.status = :st';
    $params[':st'] = $status;
}
$commerces = $db->fetchAll(
    "SELECT c.*, r.nombre AS rubro_nombre, m.nombre AS plan_nombre,
            (SELECT COUNT(*) FROM users u WHERE u.id_commerce = c.id_commerce) AS admins_count,
            (SELECT COUNT(*) FROM appointments a WHERE a.id_commerce = c.id_commerce) AS appt_count
     FROM commerces c
     LEFT JOIN rubros r ON r.id_rubro = c.id_rubro
     LEFT JOIN memberships m ON m.id_membership = c.id_membership
     WHERE $where
     ORDER BY c.created_at DESC
     LIMIT 200",
    $params
);

// Si vamos a editar uno
$edit = null;
if (isset($_GET['id'])) {
    $edit = $db->fetchOne('SELECT * FROM commerces WHERE id_commerce = :id', [':id' => (int)$_GET['id']]);
}

$rubros      = $db->fetchAll('SELECT id_rubro, nombre FROM rubros WHERE activo = 1 ORDER BY orden ASC, nombre COLLATE NOCASE ASC');
$memberships = $db->fetchAll('SELECT id_membership, nombre, precio, moneda FROM memberships WHERE activo=1 ORDER BY nombre');
$tenantFolders = TenantMigrator::scanFoldersForAdmin(dirname(__DIR__));
$tenantAudit = TenantAudit::run(dirname(__DIR__));

$pageTitle = 'Comercios';
$activeSection = 'commerces';
require __DIR__ . '/partials/header.php';
?>

<?php if ($flash['msg']): ?>
    <div class="alert alert-<?= $flash['type'] === 'error' ? 'error' : 'ok' ?>"><?= htmlspecialchars($flash['msg'], ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<section class="page-header">
    <h1>Comercios</h1>
    <p>Gestioná los negocios registrados, su membresía, status y administradores.</p>
    <p class="hint">Modo registro: <strong><?= TenantConfig::useLegacyFolders() ? 'carpetas legacy (template/)' : 'central sin carpetas' ?></strong>
        — variable <code>AGENDUY_TENANT_FOLDERS</code>
        · Ignorados del escaneo: <code><?= htmlspecialchars(implode(', ', TenantConfig::ignoredTenantSlugs()), ENT_QUOTES, 'UTF-8') ?></code></p>
</section>

<article class="card">
    <h2>Auditoría multi-tenant</h2>
    <p class="muted">Generado: <?= htmlspecialchars((string)($tenantAudit['generated_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
        · Template: <?= (int)($tenantAudit['template_files'] ?? 0) ?> archivos</p>
    <ul class="hint" style="margin:0 0 1rem; padding-left:1.1rem">
        <?php foreach ($tenantAudit['recommendations'] ?? [] as $tip): ?>
            <li><?= htmlspecialchars((string)$tip, ENT_QUOTES, 'UTF-8') ?></li>
        <?php endforeach; ?>
    </ul>
    <?php if (!empty($tenantAudit['storage'])): ?>
    <div class="table-wrap">
    <table class="table">
        <thead><tr><th>Comercio</th><th>Assets central</th><th>Carpeta legacy</th></tr></thead>
        <tbody>
        <?php foreach ($tenantAudit['storage'] as $st): ?>
            <tr>
                <td><code class="code"><?= htmlspecialchars((string)$st['slug'], ENT_QUOTES, 'UTF-8') ?></code></td>
                <td><?= !empty($st['has_central']) ? 'Sí' : 'No' ?></td>
                <td><?= !empty($st['has_legacy_folder']) ? 'Sí' : 'No' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
    <p class="hint">CLI: <code>php bin/audit-tenants.php</code> · Migrar imágenes: <code>php bin/migrate-commerce-assets.php --all</code></p>
</article>

<?php if ($tenantFolders !== []): ?>
<article class="card">
    <h2>Carpetas tenant vs base central</h2>
    <p class="muted">La web pública (<code>/slug/</code>) solo funciona si el comercio existe en SQLite. Una carpeta en disco no alcanza.</p>
    <div class="table-wrap">
    <table class="table">
        <thead>
            <tr><th>Slug</th><th>Nombre</th><th>Carpeta</th><th>En SQLite</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($tenantFolders as $tf): ?>
            <tr>
                <td><code class="code"><?= htmlspecialchars($tf['slug'], ENT_QUOTES, 'UTF-8') ?></code></td>
                <td><?= htmlspecialchars($tf['nombre'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= !empty($tf['folder']) ? 'Sí' : 'No' ?></td>
                <td><?= !empty($tf['registered']) ? 'Sí' : '<strong style="color:var(--danger,#dc2626)">No</strong>' ?></td>
                <td>
                    <?php if (!empty($tf['folder'])): ?>
                    <form method="post" style="display:inline">
                        <?= CSRF::field('commerces_admin') ?>
                        <input type="hidden" name="action" value="import_tenant_folder">
                        <input type="hidden" name="slug" value="<?= htmlspecialchars($tf['slug'], ENT_QUOTES, 'UTF-8') ?>">
                        <button class="btn btn-primary" type="submit">
                            <?= !empty($tf['registered']) ? 'Sincronizar' : 'Importar' ?>
                        </button>
                    </form>
                    <?php if (!empty($tf['registered'])): ?>
                        <a class="btn" href="<?= htmlspecialchars(url($tf['slug']), ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">Ver web</a>
                    <?php endif; ?>
                    <?php else: ?>
                        <span class="muted">Falta carpeta en servidor</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</article>
<?php endif; ?>

<form class="card filters" method="get" action="">
    <div class="form-grid">
        <div class="field">
            <label>Buscar</label>
            <input type="text" name="q" value="<?= htmlspecialchars($q, ENT_QUOTES, 'UTF-8') ?>" placeholder="Nombre, slug o email">
        </div>
        <div class="field">
            <label>Status</label>
            <select name="status">
                <option value="">Todos</option>
                <?php foreach (['trial','active','past_due','cancelled','suspended'] as $opt): ?>
                    <option value="<?= $opt ?>" <?= $status === $opt ? 'selected' : '' ?>><?= $opt ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="actions" style="grid-column: span 2">
            <button class="btn btn-primary" type="submit">Filtrar</button>
            <a class="btn" href="commerces.php">Limpiar</a>
            <a class="btn btn-ghost" href="commerces.php?new=1">+ Nuevo comercio</a>
        </div>
    </div>
</form>

<article class="card">
    <h2><?= $edit ? 'Editar comercio' : 'Nuevo comercio' ?></h2>
    <form method="post">
        <?= CSRF::field('commerces_admin') ?>
        <input type="hidden" name="action" value="save_commerce">
        <input type="hidden" name="id_commerce" value="<?= (int)($edit['id_commerce'] ?? 0) ?>">
        <div class="form-grid">
            <div class="field">
                <label>Slug (URL)</label>
                <input type="text" name="slug" required pattern="[a-z0-9-]{2,40}" value="<?= htmlspecialchars($edit['slug'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                <span class="hint">a-z, 0-9 y guiones. Identifica al comercio en la URL.</span>
            </div>
            <div class="field">
                <label>Nombre</label>
                <input type="text" name="nombre" required value="<?= htmlspecialchars($edit['nombre'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="field">
                <label>Rubro</label>
                <select name="id_rubro">
                    <?php foreach ($rubros as $r): ?>
                        <option value="<?= (int)$r['id_rubro'] ?>" <?= (int)($edit['id_rubro'] ?? 0) === (int)$r['id_rubro'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($r['nombre'], ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>Membresía</label>
                <select name="id_membership">
                    <option value="">— sin membresía —</option>
                    <?php foreach ($memberships as $m): ?>
                        <option value="<?= (int)$m['id_membership'] ?>" <?= (int)($edit['id_membership'] ?? 0) === (int)$m['id_membership'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($m['nombre'], ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars($m['moneda'], ENT_QUOTES, 'UTF-8') ?> <?= number_format((float)$m['precio'], 2) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>Status</label>
                <select name="status">
                    <?php foreach (['trial','active','past_due','cancelled','suspended'] as $opt): ?>
                        <option value="<?= $opt ?>" <?= ($edit['status'] ?? 'trial') === $opt ? 'selected' : '' ?>><?= $opt ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>Email</label>
                <input type="email" name="email" value="<?= htmlspecialchars($edit['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="field">
                <label>Teléfono</label>
                <input type="text" name="telefono" value="<?= htmlspecialchars($edit['telefono'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="field">
                <label>WhatsApp</label>
                <input type="text" name="whatsapp" value="<?= htmlspecialchars($edit['whatsapp'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="field">
                <label>País</label>
                <input type="text" name="pais" maxlength="3" value="<?= htmlspecialchars($edit['pais'] ?? 'UY', ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="field">
                <label>Ciudad</label>
                <input type="text" name="ciudad" value="<?= htmlspecialchars($edit['ciudad'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="field col-2">
                <label>Calle y número</label>
                <input type="text" name="calle" value="<?= htmlspecialchars($edit['calle'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="field col-2">
                <label>Slogan</label>
                <input type="text" name="slogan" value="<?= htmlspecialchars($edit['slogan'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="field col-2">
                <label>Descripción</label>
                <textarea name="descripcion"><?= htmlspecialchars($edit['descripcion'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
            </div>
            <?php if (!$edit): ?>
                <div class="field">
                    <label>Email del admin inicial</label>
                    <input type="email" name="admin_email" placeholder="dueño@negocio.com">
                </div>
                <div class="field">
                    <label>Contraseña del admin inicial</label>
                    <input type="text" name="admin_password" placeholder="mín. 8 chars">
                </div>
                <div class="field">
                    <label>Nombre del admin</label>
                    <input type="text" name="admin_nombre">
                </div>
                <div class="field">
                    <label>Apellido del admin</label>
                    <input type="text" name="admin_apellido">
                </div>
            <?php endif; ?>
        </div>
        <div class="actions">
            <button class="btn btn-primary" type="submit"><?= $edit ? 'Guardar cambios' : 'Crear comercio' ?></button>
            <?php if ($edit): ?>
                <a class="btn" href="commerces.php">Cancelar</a>
            <?php endif; ?>
        </div>
    </form>
</article>

<article class="card">
    <h2>Listado de comercios (<?= count($commerces) ?>)</h2>
    <div class="table-wrap">
    <table class="table">
        <thead>
            <tr>
                <th>Nombre</th>
                <th>Slug</th>
                <th>Rubro</th>
                <th>Plan</th>
                <th>Status</th>
                <th>Trial vence</th>
                <th>Admins</th>
                <th>Turnos</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($commerces as $c): ?>
            <tr>
                <td><strong><?= htmlspecialchars($c['nombre'], ENT_QUOTES, 'UTF-8') ?></strong></td>
                <td><code><?= htmlspecialchars($c['slug'], ENT_QUOTES, 'UTF-8') ?></code></td>
                <td><?= htmlspecialchars($c['rubro_nombre'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($c['plan_nombre'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                <td><span class="badge badge--<?= htmlspecialchars($c['status'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($c['status'], ENT_QUOTES, 'UTF-8') ?></span></td>
                <td><?= htmlspecialchars($c['trial_expires_at'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= (int)$c['admins_count'] ?></td>
                <td><?= (int)$c['appt_count'] ?></td>
                <td>
                    <a class="btn btn-sm" href="commerces.php?id=<?= (int)$c['id_commerce'] ?>">editar</a>
                    <details style="display:inline-block">
                        <summary class="btn btn-sm btn-ghost" style="display:inline-block; cursor:pointer">⋯</summary>
                        <div style="position:absolute; background:var(--surface); border:1px solid var(--border); padding:.5rem; border-radius:8px; margin-top:.25rem">
                            <form method="post" style="display:inline">
                                <?= CSRF::field('commerces_admin') ?>
                                <input type="hidden" name="action" value="extend_trial">
                                <input type="hidden" name="id_commerce" value="<?= (int)$c['id_commerce'] ?>">
                                <input type="number" name="days" value="7" min="1" max="365" style="width:60px">
                                <button class="btn btn-sm" type="submit">+ días trial</button>
                            </form>
                            <form method="post" style="display:inline" onsubmit="return confirm('¿Eliminar comercio?');">
                                <?= CSRF::field('commerces_admin') ?>
                                <input type="hidden" name="action" value="delete_commerce">
                                <input type="hidden" name="id_commerce" value="<?= (int)$c['id_commerce'] ?>">
                                <button class="btn btn-sm btn-danger" type="submit">eliminar</button>
                            </form>
                        </div>
                    </details>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</article>

<article class="card">
    <h2>Resetear contraseña de un admin de comercio</h2>
    <form method="post">
        <?= CSRF::field('commerces_admin') ?>
        <input type="hidden" name="action" value="reset_password">
        <div class="form-grid">
            <div class="field">
                <label>Email del usuario</label>
                <input type="email" name="email" required>
            </div>
            <div class="actions" style="align-self:end">
                <button class="btn btn-warn" type="submit">Generar nueva contraseña</button>
            </div>
        </div>
    </form>
</article>

<?php require __DIR__ . '/partials/footer.php'; ?>
