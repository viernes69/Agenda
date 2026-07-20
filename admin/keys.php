<?php
/**
 * Agenduy - Super Admin: API Keys por comercio y por provider
 * Soporta autogenerar keys seguras. Los valores se guardan cifrados.
 */
declare(strict_types=1);

$config = require __DIR__ . '/../src/Core/bootstrap.php';
use Agenduy\Core\Auth;
use Agenduy\Core\CSRF;
use Agenduy\Core\Database;
use Agenduy\Core\Crypto;
use Agenduy\Core\Keys;

Auth::start();
if (!Auth::check() || Auth::role() !== 'super_admin') { header('Location: login.php'); exit; }

$db = Database::getInstance();
$cfg = $db->config();
$encKey = (string)$cfg['security']['encryption_key'];
$crypto = new Crypto($encKey);

$flash = ['type' => '', 'msg' => '', 'plain' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::checkRequest('keys_admin');
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        $provider = (string)($_POST['provider'] ?? '');
        $keyName  = trim((string)($_POST['key_name'] ?? ''));
        $label    = trim((string)($_POST['label'] ?? ''));
        $commerce = (int)($_POST['id_commerce'] ?? 0) ?: null;
        $autogen  = !empty($_POST['autogen']);
        $value    = (string)($_POST['key_value'] ?? '');

        $validProviders = ['mercadopago','paypal','google_calendar','google_service_account','smtp','generic'];
        if (!in_array($provider, $validProviders, true)) {
            $flash = ['type' => 'error', 'msg' => 'Provider inválido.'];
        } elseif ($keyName === '') {
            $flash = ['type' => 'error', 'msg' => 'El nombre de la key es obligatorio.'];
        } else {
            if ($autogen) {
                $value = Keys::generateApiKey();
            } elseif ($value === '') {
                $flash = ['type' => 'error', 'msg' => 'Pegá el valor o tildá "autogenerar".'];
            }
            if ($value !== '') {
                $preview = substr($value, -4);
                $db->insert('api_keys', [
                    'id_commerce' => $commerce,
                    'provider'    => $provider,
                    'key_name'    => $keyName,
                    'key_value'   => $crypto->encrypt($value),
                    'key_preview' => $preview,
                    'label'       => $label,
                    'is_active'   => 1,
                ]);
                $flash = ['type' => 'ok', 'msg' => 'Key guardada.', 'plain' => $value];
                Auth::audit('create_api_key', 'api_key', null, null, ['provider' => $provider, 'name' => $keyName, 'commerce' => $commerce]);
            }
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id_key'] ?? 0);
        if ($id > 0) {
            $db->delete('api_keys', 'id_key = :id', [':id' => $id]);
            Auth::audit('delete_api_key', 'api_key', $id);
            $flash = ['type' => 'ok', 'msg' => 'Key eliminada.'];
        }
    } elseif ($action === 'toggle') {
        $id = (int)($_POST['id_key'] ?? 0);
        $row = $db->fetchOne('SELECT is_active FROM api_keys WHERE id_key = :id', [':id' => $id]);
        if ($row) {
            $db->update('api_keys', [
                'is_active' => (int)$row['is_active'] === 1 ? 0 : 1,
                'updated_at'=> date('Y-m-d H:i:s'),
            ], 'id_key = :id', [':id' => $id]);
            Auth::audit('toggle_api_key', 'api_key', $id);
        }
    } elseif ($action === 'reveal') {
        // Solo se puede revelar UNA VEZ tras crear; por seguridad
        // vamos a evitar el reveal por GET. Lo dejamos preparado
        // para una vista "show once" en versiones futuras.
        $flash = ['type' => 'error', 'msg' => 'Por seguridad, las keys no se pueden volver a mostrar.'];
    }
}

$providerFilter = (string)($_GET['provider'] ?? '');
$commerceFilter = (int)($_GET['id_commerce'] ?? 0);

$where = '1=1';
$params = [];
if ($providerFilter !== '' && in_array($providerFilter, ['mercadopago','paypal','google_calendar','google_service_account','smtp','generic'], true)) {
    $where .= ' AND k.provider = :p';
    $params[':p'] = $providerFilter;
}
if ($commerceFilter > 0) {
    $where .= ' AND k.id_commerce = :c';
    $params[':c'] = $commerceFilter;
}

$keys = $db->fetchAll(
    "SELECT k.*, c.nombre AS commerce, c.slug
     FROM api_keys k
     LEFT JOIN commerces c ON c.id_commerce = k.id_commerce
     WHERE $where
     ORDER BY k.created_at DESC
     LIMIT 300",
    $params
);

$commerces = $db->fetchAll('SELECT id_commerce, nombre, slug FROM commerces ORDER BY nombre');

$pageTitle = 'API Keys';
$activeSection = 'keys';
require __DIR__ . '/partials/header.php';
?>

<?php if ($flash['msg']): ?>
    <div class="alert alert-<?= $flash['type'] === 'error' ? 'error' : 'ok' ?>">
        <?= htmlspecialchars($flash['msg'], ENT_QUOTES, 'UTF-8') ?>
        <?php if ($flash['plain']): ?>
            <br><strong>Valor (guárdalo, no se mostrará de nuevo):</strong>
            <code class="code"><?= htmlspecialchars($flash['plain'], ENT_QUOTES, 'UTF-8') ?></code>
        <?php endif; ?>
    </div>
<?php endif; ?>

<section class="page-header">
    <h1>API Keys</h1>
    <p>Guardá las credenciales por comercio y provider. Los valores se almacenan cifrados (AES-256-GCM).</p>
</section>

<article class="card">
    <h2>Nueva key</h2>
    <form method="post">
        <?= CSRF::field('keys_admin') ?>
        <input type="hidden" name="action" value="create">
        <div class="form-grid">
            <div class="field">
                <label>Comercio</label>
                <select name="id_commerce">
                    <option value="">(Global · super admin)</option>
                    <?php foreach ($commerces as $c): ?>
                        <option value="<?= (int)$c['id_commerce'] ?>"><?= htmlspecialchars($c['nombre'] . ' (' . $c['slug'] . ')', ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>Provider</label>
                <select name="provider" required>
                    <?php foreach (['mercadopago','paypal','google_calendar','google_service_account','smtp','generic'] as $p): ?>
                        <option value="<?= $p ?>"><?= $p ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>Nombre de la key</label>
                <input type="text" name="key_name" required placeholder="MP_ACCESS_TOKEN">
                <span class="hint">Identifica el slot. Ej. MP_ACCESS_TOKEN, PAYPAL_CLIENT_ID, GOOGLE_CALENDAR_ID</span>
            </div>
            <div class="field">
                <label>Etiqueta (opcional)</label>
                <input type="text" name="label" placeholder="Producción 2026">
            </div>
            <div class="field col-2">
                <label>
                    <input type="checkbox" name="autogen" value="1" id="autogen-cb" checked>
                    Autogenerar valor (botón mágico)
                </label>
                <input type="text" name="key_value" id="key-value-input" placeholder="Si no autogenerás, pegá el valor acá" disabled>
            </div>
        </div>
        <div class="actions">
            <button class="btn btn-primary" type="submit">Crear key</button>
        </div>
    </form>
    <script>
    (function(){
        var cb = document.getElementById('autogen-cb');
        var inp = document.getElementById('key-value-input');
        if (!cb || !inp) return;
        cb.addEventListener('change', function(){
            inp.disabled = cb.checked;
            if (cb.checked) inp.value = '';
        });
    })();
    </script>
</article>

<form class="card" method="get">
    <div class="form-grid">
        <div class="field">
            <label>Provider</label>
            <select name="provider">
                <option value="">Todos</option>
                <?php foreach (['mercadopago','paypal','google_calendar','google_service_account','smtp','generic'] as $p): ?>
                    <option value="<?= $p ?>" <?= $providerFilter === $p ? 'selected' : '' ?>><?= $p ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label>Comercio</label>
            <select name="id_commerce">
                <option value="">Todos</option>
                <?php foreach ($commerces as $c): ?>
                    <option value="<?= (int)$c['id_commerce'] ?>" <?= $commerceFilter === (int)$c['id_commerce'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($c['nombre'] . ' (' . $c['slug'] . ')', ENT_QUOTES, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="actions" style="align-self:end">
            <button class="btn btn-primary" type="submit">Filtrar</button>
            <a class="btn" href="keys.php">Limpiar</a>
        </div>
    </div>
</form>

<article class="card">
    <h2>Keys registradas (<?= count($keys) ?>)</h2>
    <div class="table-wrap">
    <table class="table">
        <thead>
            <tr>
                <th>Comercio</th>
                <th>Provider</th>
                <th>Nombre</th>
                <th>Etiqueta</th>
                <th>Preview</th>
                <th>Activo</th>
                <th>Creado</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($keys as $k): ?>
            <tr>
                <td>
                    <?php if ($k['id_commerce']): ?>
                        <a href="commerces.php?id=<?= (int)$k['id_commerce'] ?>"><?= htmlspecialchars($k['commerce'] ?? '', ENT_QUOTES, 'UTF-8') ?></a>
                        <br><code class="code"><?= htmlspecialchars($k['slug'] ?? '', ENT_QUOTES, 'UTF-8') ?></code>
                    <?php else: ?>
                        <span class="muted">Global</span>
                    <?php endif; ?>
                </td>
                <td><?= htmlspecialchars($k['provider'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><code class="code"><?= htmlspecialchars($k['key_name'], ENT_QUOTES, 'UTF-8') ?></code></td>
                <td><?= htmlspecialchars($k['label'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                <td><code class="code">…<?= htmlspecialchars($k['key_preview'], ENT_QUOTES, 'UTF-8') ?></code></td>
                <td><?= (int)$k['is_active'] === 1 ? '✓' : '✕' ?></td>
                <td><?= htmlspecialchars($k['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                <td>
                    <form method="post" style="display:inline">
                        <?= CSRF::field('keys_admin') ?>
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="id_key" value="<?= (int)$k['id_key'] ?>">
                        <button class="btn btn-sm" type="submit"><?= (int)$k['is_active'] === 1 ? 'desactivar' : 'activar' ?></button>
                    </form>
                    <form method="post" style="display:inline" onsubmit="return confirm('¿Eliminar key?');">
                        <?= CSRF::field('keys_admin') ?>
                        <input type="hidden" name="action" value="delete">
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

<?php require __DIR__ . '/partials/footer.php'; ?>
