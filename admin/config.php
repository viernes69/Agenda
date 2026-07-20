<?php
/**
 * Agenduy - Super Admin: Configuración global
 * Habilita/deshabilita gateways y configura datos bancarios.
 */
declare(strict_types=1);

$config = require __DIR__ . '/../src/Core/bootstrap.php';
use Agenduy\Core\Auth;
use Agenduy\Core\CSRF;
use Agenduy\Core\Database;
use Agenduy\Core\Crypto;

Auth::start();
if (!Auth::check() || Auth::role() !== 'super_admin') { header('Location: login.php'); exit; }

$db = Database::getInstance();
$encKey = (string)$db->config()['security']['encryption_key'];
$crypto = new Crypto($encKey);
$flash = ['type' => '', 'msg' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::checkRequest('config_admin');
    $action = $_POST['action'] ?? '';
    if ($action === 'save_provider') {
        try {
        $provider = (string)($_POST['provider'] ?? '');
        $isEnabled = !empty($_POST['is_enabled']) ? 1 : 0;
        $notes = trim((string)($_POST['notes'] ?? ''));
        $current = $db->fetchOne('SELECT * FROM payment_provider_config WHERE provider = :p', [':p' => $provider]);
        $cfg = $current ? json_decode((string)$current['config_json'], true) : [];
        if (!is_array($cfg)) $cfg = [];

        // Recolectar campos dinámicos según provider
        $fields = [
            'mercadopago' => ['public_key','access_token','sandbox'],
            'paypal'      => ['client_id','secret','sandbox'],
            'transfer'    => ['banco','titular','cuenta','moneda','instrucciones'],
            'smtp'        => ['host','port','encryption','username','password','from_email','from_name'],
            'ultramsg'    => ['instance_id','token'],
            'google_oauth'=> ['client_id','client_secret'],
        ];
        // Campos sensibles: si vienen vacíos, se conserva el valor guardado
        $secretFields = ['password','token','client_secret','secret'];
        $list = $fields[$provider] ?? [];
        foreach ($list as $f) {
            if (!isset($_POST[$f])) continue;
            $val = $_POST[$f];
            if (is_string($val)) $val = trim($val);
            if ($val === '' && in_array($f, $secretFields, true) && ($cfg[$f] ?? '') !== '') continue;
            $cfg[$f] = $val;
        }

        $json = json_encode($cfg, JSON_UNESCAPED_UNICODE);
        if ($current) {
            $db->update('payment_provider_config', [
                'is_enabled'  => $isEnabled,
                'config_json' => $json,
                'notes'       => $notes,
                'updated_by'  => Auth::id(),
                'updated_at'  => date('Y-m-d H:i:s'),
            ], 'provider = :p', [':p' => $provider]);
        } else {
            $db->insert('payment_provider_config', [
                'provider'    => $provider,
                'is_enabled'  => $isEnabled,
                'config_json' => $json,
                'notes'       => $notes,
                'updated_by'  => Auth::id(),
            ]);
        }
        Auth::audit('save_provider_config', 'payment_provider', null, null, ['provider' => $provider]);
        $flash = ['type' => 'ok', 'msg' => 'Configuración guardada.'];
        } catch (Throwable $e) {
            error_log('[admin/config.php] save_provider ' . ($provider ?? '') . ': ' . $e->getMessage());
            $flash = ['type' => 'error', 'msg' => 'No se pudo guardar la configuración. Recargá la página e intentá de nuevo.'];
        }
    }
}

$providers = $db->fetchAll('SELECT * FROM payment_provider_config ORDER BY provider');

// Asegurar que smtp y ultramsg aparezcan aunque aún no tengan fila guardada
$known = array_column($providers, 'provider');
foreach (['smtp', 'ultramsg', 'google_oauth'] as $extra) {
    if (!in_array($extra, $known, true)) {
        $providers[] = ['provider' => $extra, 'is_enabled' => 0, 'config_json' => '{}', 'notes' => ''];
    }
}

$pageTitle = 'Configuración';
$activeSection = 'config';
require __DIR__ . '/partials/header.php';
?>

<?php if ($flash['msg']): ?>
    <div class="alert alert-<?= $flash['type'] === 'error' ? 'error' : 'ok' ?>"><?= htmlspecialchars($flash['msg'], ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<section class="page-header">
    <h1>Configuración global</h1>
    <p>Pasarelas de pago, datos bancarios y ajustes generales.</p>
</section>

<?php foreach ($providers as $p):
    $cfg = json_decode((string)$p['config_json'], true) ?: [];
?>
<article class="card">
    <h2>
        <?= htmlspecialchars(ucfirst($p['provider']), ENT_QUOTES, 'UTF-8') ?>
        <span class="badge" style="margin-left:.5rem"><?= (int)$p['is_enabled'] === 1 ? 'habilitado' : 'deshabilitado' ?></span>
    </h2>
    <form method="post">
        <?= CSRF::field('config_admin') ?>
        <input type="hidden" name="action" value="save_provider">
        <input type="hidden" name="provider" value="<?= htmlspecialchars($p['provider'], ENT_QUOTES, 'UTF-8') ?>">
        <div class="form-grid">
            <?php if ($p['provider'] === 'mercadopago'): ?>
                <div class="field">
                    <label>Public Key</label>
                    <input type="text" name="public_key" value="<?= htmlspecialchars($cfg['public_key'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="field">
                    <label>Access Token</label>
                    <input type="text" name="access_token" value="<?= htmlspecialchars($cfg['access_token'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                    <span class="hint">Guardá el token también en una key por comercio (MP_ACCESS_TOKEN) para overrides.</span>
                </div>
                <div class="field">
                    <label>Modo</label>
                    <select name="sandbox">
                        <option value="1" <?= !empty($cfg['sandbox']) ? 'selected' : '' ?>>Sandbox (pruebas)</option>
                        <option value="0" <?= empty($cfg['sandbox']) ? 'selected' : '' ?>>Producción</option>
                    </select>
                </div>
            <?php elseif ($p['provider'] === 'paypal'): ?>
                <div class="field">
                    <label>Client ID</label>
                    <input type="text" name="client_id" value="<?= htmlspecialchars($cfg['client_id'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="field">
                    <label>Secret</label>
                    <input type="text" name="secret" value="<?= htmlspecialchars($cfg['secret'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="field">
                    <label>Modo</label>
                    <select name="sandbox">
                        <option value="1" <?= !empty($cfg['sandbox']) ? 'selected' : '' ?>>Sandbox</option>
                        <option value="0" <?= empty($cfg['sandbox']) ? 'selected' : '' ?>>Producción</option>
                    </select>
                </div>
            <?php elseif ($p['provider'] === 'transfer'): ?>
                <div class="field">
                    <label>Banco</label>
                    <input type="text" name="banco" value="<?= htmlspecialchars($cfg['banco'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="field">
                    <label>Titular</label>
                    <input type="text" name="titular" value="<?= htmlspecialchars($cfg['titular'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="field">
                    <label>Cuenta / IBAN</label>
                    <input type="text" name="cuenta" value="<?= htmlspecialchars($cfg['cuenta'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="field">
                    <label>Moneda</label>
                    <select name="moneda">
                        <?php foreach (['UYU','USD','ARS','BRL','CLP','PYG'] as $m): ?>
                            <option value="<?= $m ?>" <?= ($cfg['moneda'] ?? 'UYU') === $m ? 'selected' : '' ?>><?= $m ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field col-2">
                    <label>Instrucciones para el cliente</label>
                    <textarea name="instrucciones" placeholder="Transferí a la cuenta X y subí el comprobante."><?= htmlspecialchars($cfg['instrucciones'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                </div>
            <?php elseif ($p['provider'] === 'smtp'): ?>
                <div class="field">
                    <label>Host</label>
                    <input type="text" name="host" value="<?= htmlspecialchars($cfg['host'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="smtp.ejemplo.com">
                </div>
                <div class="field">
                    <label>Puerto</label>
                    <input type="number" name="port" value="<?= htmlspecialchars((string)($cfg['port'] ?? '465'), ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="field">
                    <label>Cifrado</label>
                    <select name="encryption">
                        <?php foreach (['ssl' => 'SSL (465)', 'tls' => 'TLS/STARTTLS (587)', 'none' => 'Sin cifrado'] as $encVal => $encLabel): ?>
                            <option value="<?= $encVal ?>" <?= ($cfg['encryption'] ?? 'ssl') === $encVal ? 'selected' : '' ?>><?= $encLabel ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label>Usuario</label>
                    <input type="text" name="username" value="<?= htmlspecialchars($cfg['username'] ?? '', ENT_QUOTES, 'UTF-8') ?>" autocomplete="off">
                </div>
                <div class="field">
                    <label>Contraseña</label>
                    <input type="password" name="password" value="" placeholder="<?= ($cfg['password'] ?? '') !== '' ? '•••••••• (dejar vacío para conservar)' : '' ?>" autocomplete="new-password">
                </div>
                <div class="field">
                    <label>Email remitente (From)</label>
                    <input type="email" name="from_email" value="<?= htmlspecialchars($cfg['from_email'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="field">
                    <label>Nombre remitente</label>
                    <input type="text" name="from_name" value="<?= htmlspecialchars($cfg['from_name'] ?? 'Agenduy', ENT_QUOTES, 'UTF-8') ?>">
                </div>
            <?php elseif ($p['provider'] === 'ultramsg'): ?>
                <div class="field">
                    <label>Instance ID</label>
                    <input type="text" name="instance_id" value="<?= htmlspecialchars($cfg['instance_id'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="instance12345">
                </div>
                <div class="field">
                    <label>Token</label>
                    <input type="password" name="token" value="" placeholder="<?= ($cfg['token'] ?? '') !== '' ? '•••••••• (dejar vacío para conservar)' : '' ?>" autocomplete="new-password">
                    <span class="hint">Se obtiene en el panel de UltraMsg (docs.ultramsg.com).</span>
                </div>
            <?php elseif ($p['provider'] === 'google_oauth'): ?>
                <div class="field col-2">
                    <label>Google Client ID (OAuth 2.0)</label>
                    <input type="text" name="client_id" value="<?= htmlspecialchars($cfg['client_id'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="123456789.apps.googleusercontent.com">
                    <span class="hint">Creá credenciales en Google Cloud Console → APIs &amp; Services → Credentials → OAuth client ID (Web). Autorizá <?= htmlspecialchars(function_exists('url_base') ? url_base() : (string)(Database::getInstance()->config()['app']['url_base'] ?? ''), ENT_QUOTES, 'UTF-8') ?> y localhost.</span>
                </div>
                <div class="field">
                    <label>Client Secret (opcional)</label>
                    <input type="password" name="client_secret" value="" placeholder="<?= ($cfg['client_secret'] ?? '') !== '' ? '•••••••• (dejar vacío para conservar)' : '' ?>" autocomplete="new-password">
                    <span class="hint">No es necesario para el botón "Continuar con Google" (GIS). Solo si integrás flujos server-side.</span>
                </div>
            <?php endif; ?>
            <div class="field">
                <label>Estado</label>
                <label style="display:flex; align-items:center; gap:.5rem; font-size: 1rem">
                    <input type="checkbox" name="is_enabled" value="1" <?= (int)$p['is_enabled'] === 1 ? 'checked' : '' ?>>
                    Habilitado para clientes
                </label>
            </div>
            <div class="field col-2">
                <label>Notas internas</label>
                <textarea name="notes"><?= htmlspecialchars($p['notes'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
            </div>
        </div>
        <div class="actions">
            <button class="btn btn-primary" type="submit">Guardar</button>
        </div>
    </form>
</article>
<?php endforeach; ?>

<article class="card">
    <h2>Auditoría reciente</h2>
    <div class="table-wrap">
    <table class="table">
        <thead>
            <tr><th>Fecha</th><th>Acción</th><th>Target</th><th>Usuario</th><th>IP</th></tr>
        </thead>
        <tbody>
        <?php foreach ($db->fetchAll('SELECT * FROM audit_log ORDER BY id_audit DESC LIMIT 30') as $row): ?>
            <tr>
                <td><?= htmlspecialchars($row['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><code class="code"><?= htmlspecialchars($row['action'], ENT_QUOTES, 'UTF-8') ?></code></td>
                <td><?= htmlspecialchars($row['target_type'] . ' #' . $row['target_id'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= $row['id_user'] ? '#' . (int)$row['id_user'] : '—' ?></td>
                <td><code class="code"><?= htmlspecialchars($row['ip'] ?? '', ENT_QUOTES, 'UTF-8') ?></code></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</article>

<?php require __DIR__ . '/partials/footer.php'; ?>
