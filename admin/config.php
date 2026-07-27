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
use Agenduy\Core\PlatformSettings;
use Agenduy\Core\ProviderConfig;

Auth::start();
if (!Auth::check() || Auth::role() !== 'super_admin') { header('Location: login.php'); exit; }

$db = Database::getInstance();
$encKey = (string)$db->config()['security']['encryption_key'];
$crypto = new Crypto($encKey);
$flash = ['type' => '', 'msg' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::checkRequest('config_admin');
    $action = $_POST['action'] ?? '';
    if ($action === 'save_platform_contact') {
        try {
            PlatformSettings::saveContact([
                'instagram' => (string)($_POST['platform_instagram'] ?? ''),
                'whatsapp'  => (string)($_POST['platform_whatsapp'] ?? ''),
            ]);
            Auth::audit('save_platform_contact', 'platform_settings', null);
            $flash = ['type' => 'ok', 'msg' => 'Contacto publico guardado.'];
        } catch (Throwable $e) {
            error_log('[admin/config.php] save_platform_contact: ' . $e->getMessage());
            $flash = ['type' => 'error', 'msg' => 'No se pudo guardar el contacto publico.'];
        }
    } elseif ($action === 'save_provider') {
        try {
        $provider = (string)($_POST['provider'] ?? '');
        $isEnabled = !empty($_POST['is_enabled']) ? 1 : 0;
        $notes = trim((string)($_POST['notes'] ?? ''));
        $current = $db->fetchOne('SELECT * FROM payment_provider_config WHERE provider = :p', [':p' => $provider]);
        $cfg = $current ? json_decode((string)$current['config_json'], true) : [];
        if (!is_array($cfg)) $cfg = [];

        // Recolectar campos dinámicos según provider
        $fields = [
            'mercadopago' => ['public_key','access_token','sandbox','notification_url','public_base_url','integrator_id'],
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
$platformContact = PlatformSettings::contact();

// Asegurar que smtp y ultramsg aparezcan aunque aún no tengan fila guardada
$known = array_column($providers, 'provider');
foreach (['smtp', 'ultramsg', 'google_oauth'] as $extra) {
    if (!in_array($extra, $known, true)) {
        $providers[] = ['provider' => $extra, 'is_enabled' => 0, 'config_json' => '{}', 'notes' => ''];
    }
}

$mailDiagnostics = ProviderConfig::mailDiagnostics();
$mailFailures = $db->fetchAll(
    "SELECT recipient, subject, error_message, created_at
     FROM notifications_log
     WHERE channel = 'email' AND status = 'failed'
     ORDER BY id_notification DESC LIMIT 5"
);
$configCsrf = CSRF::generate('config_admin');

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

<article class="card">
    <h2>Contacto publico de la plataforma</h2>
    <form method="post">
        <?= CSRF::field('config_admin') ?>
        <input type="hidden" name="action" value="save_platform_contact">
        <div class="form-grid">
            <div class="field">
                <label>Instagram</label>
                <input type="text" name="platform_instagram" value="<?= htmlspecialchars($platformContact['instagram'], ENT_QUOTES, 'UTF-8') ?>" placeholder="@agendarte.uy o https://instagram.com/agendarte.uy">
                <?php if ($platformContact['instagram_url'] !== ''): ?>
                    <span class="hint">Actual: <a href="<?= htmlspecialchars($platformContact['instagram_url'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener"><?= htmlspecialchars($platformContact['instagram_url'], ENT_QUOTES, 'UTF-8') ?></a></span>
                <?php endif; ?>
            </div>
            <div class="field">
                <label>WhatsApp</label>
                <input type="text" name="platform_whatsapp" value="<?= htmlspecialchars($platformContact['whatsapp'], ENT_QUOTES, 'UTF-8') ?>" placeholder="+598 99 000 000">
                <?php if ($platformContact['whatsapp_url'] !== ''): ?>
                    <span class="hint">Actual: <a href="<?= htmlspecialchars($platformContact['whatsapp_url'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener"><?= htmlspecialchars($platformContact['whatsapp_url'], ENT_QUOTES, 'UTF-8') ?></a></span>
                <?php endif; ?>
            </div>
            <div class="field col-2">
                <span class="hint">Estos enlaces se usan en la landing publica y en los botones de contacto/renovacion del panel.</span>
            </div>
        </div>
        <div class="actions">
            <button class="btn btn-primary" type="submit">Guardar contacto</button>
        </div>
    </form>
</article>

<?php foreach ($providers as $p):
    $cfg = json_decode((string)$p['config_json'], true) ?: [];
    if ($p['provider'] === 'smtp') {
        $effective = ProviderConfig::mailConfig();
        foreach (['host','port','encryption','username','from_email','from_name'] as $smtpField) {
            if (($cfg[$smtpField] ?? '') === '' && ($effective[$smtpField] ?? '') !== '') {
                $cfg[$smtpField] = $effective[$smtpField];
            }
        }
        $smtpHasPassword = ($cfg['password'] ?? '') !== '' || $mailDiagnostics['has_password'];
    }
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
                    <span class="hint">Se usa solo para cobrar membresias de la plataforma. Las tiendas usan sus propias credenciales.</span>
                </div>
                <div class="field">
                    <label>Modo</label>
                    <select name="sandbox">
                        <option value="1" <?= !empty($cfg['sandbox']) ? 'selected' : '' ?>>Sandbox (pruebas)</option>
                        <option value="0" <?= empty($cfg['sandbox']) ? 'selected' : '' ?>>Producción</option>
                    </select>
                </div>
                <div class="field">
                    <label>Notification URL</label>
                    <input type="url" name="notification_url" value="<?= htmlspecialchars($cfg['notification_url'] ?? url('admin/api/webhook_mercadopago.php'), ENT_QUOTES, 'UTF-8') ?>">
                    <span class="hint">Webhook para cambios de pagos y suscripciones.</span>
                </div>
                <div class="field">
                    <label>URL publica de Agendarte</label>
                    <input type="url" name="public_base_url" value="<?= htmlspecialchars($cfg['public_base_url'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="https://www.agenduy.uy">
                    <span class="hint">Usala si estas probando en localhost: Mercado Pago no acepta localhost como back_url.</span>
                </div>
                <div class="field">
                    <label>Integrator ID</label>
                    <input type="text" name="integrator_id" value="<?= htmlspecialchars($cfg['integrator_id'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
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
                <?php if (empty($mailDiagnostics['enabled']) && !empty($mailDiagnostics['configured'])): ?>
                <div class="field col-2">
                    <div class="alert" style="border-color:#854d0e;background:#422006;color:#fde68a">
                        SMTP configurado pero deshabilitado. Marca <strong>SMTP habilitado</strong> y guarda para que se envien emails.
                    </div>
                </div>
                <?php elseif (!$mailDiagnostics['configured']): ?>
                <div class="field col-2">
                    <div class="alert alert-error">
                        SMTP incompleto: faltan datos o PHPMailer no está instalado (<code>composer install</code>).
                        Los links por email y notificaciones no se enviarán hasta configurarlo.
                    </div>
                </div>
                <?php else: ?>
                <div class="field col-2">
                    <div class="alert alert-ok">SMTP listo y habilitado: <?= htmlspecialchars($mailDiagnostics['host'], ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars($mailDiagnostics['from_email'], ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <?php endif; ?>
                <div class="field">
                    <label>Host</label>
                    <input type="text" name="host" value="<?= htmlspecialchars((string)($cfg['host'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="mail.appsuy.net">
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
                    <input type="password" name="password" value="" placeholder="<?= !empty($smtpHasPassword) ? '•••••••• (dejar vacío para conservar)' : 'Obligatoria para enviar emails' ?>" autocomplete="new-password">
                    <span class="hint">También podés usar <code>AGENDUY_SMTP_PASSWORD</code> o <code>Private/mail_secret.php</code> (ver mail_secret.example.php).</span>
                </div>
                <div class="field">
                    <label>Email remitente (From)</label>
                    <input type="email" name="from_email" value="<?= htmlspecialchars($cfg['from_email'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="field col-2">
                    <label>Nombre remitente</label>
                    <input type="text" name="from_name" value="<?= htmlspecialchars($cfg['from_name'] ?? 'Agenduy', ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="field col-2">
                    <label>Probar envío</label>
                    <div style="display:flex; gap:.5rem; flex-wrap:wrap; align-items:center">
                        <input type="email" id="smtp-test-email" value="<?= htmlspecialchars((string)(Auth::user()['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="tu@email.com" style="flex:1; min-width:220px">
                        <button type="button" class="btn btn-secondary" id="smtp-test-btn">Enviar email de prueba</button>
                    </div>
                    <p class="hint" id="smtp-test-msg" role="status"></p>
                </div>
                <?php if ($mailFailures): ?>
                <div class="field col-2">
                    <label>Últimos fallos de email</label>
                    <ul class="hint" style="margin:0; padding-left:1.1rem">
                        <?php foreach ($mailFailures as $fail): ?>
                        <li><?= htmlspecialchars($fail['created_at'] . ' → ' . $fail['recipient'] . ': ' . ($fail['error_message'] ?? ''), ENT_QUOTES, 'UTF-8') ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>
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
                    <?= $p['provider'] === 'smtp' ? 'SMTP habilitado' : 'Habilitado para clientes' ?>
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

<script>
(function () {
    var btn = document.getElementById('smtp-test-btn');
    var emailInput = document.getElementById('smtp-test-email');
    var msg = document.getElementById('smtp-test-msg');
    if (!btn || !emailInput || !msg) return;
    btn.addEventListener('click', function () {
        var email = String(emailInput.value || '').trim();
        if (!email) {
            msg.textContent = 'Ingresá un email de prueba.';
            msg.style.color = '#b91c1c';
            return;
        }
        btn.disabled = true;
        msg.textContent = 'Enviando...';
        msg.style.color = '#475569';
        fetch('api/test_mail.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'include',
            body: JSON.stringify({ email: email, _csrf: <?= json_encode($configCsrf) ?> })
        }).then(function (res) {
            return res.json().catch(function () { return null; }).then(function (data) {
                btn.disabled = false;
                if (!res.ok || !data || !data.ok) {
                    msg.textContent = (data && data.error) ? data.error : 'No se pudo enviar.';
                    msg.style.color = '#b91c1c';
                    return;
                }
                msg.textContent = data.message || 'Enviado.';
                msg.style.color = '#15803d';
            });
        }).catch(function () {
            btn.disabled = false;
            msg.textContent = 'Error de conexión.';
            msg.style.color = '#b91c1c';
        });
    });
})();
</script>

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
