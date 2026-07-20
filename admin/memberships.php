<?php
/**
 * Agenduy - Super Admin: Membresías (planes)
 * Edita título, features, precios mensual/anual, límites y gateways.
 */
declare(strict_types=1);

$config = require __DIR__ . '/../src/Core/bootstrap.php';
use Agenduy\Core\Auth;
use Agenduy\Core\CSRF;
use Agenduy\Core\Database;
use Agenduy\Core\MembershipPlan;

Auth::start();
if (!Auth::check() || Auth::role() !== 'super_admin') { header('Location: login.php'); exit; }

$db = Database::getInstance();
$flash = ['type' => '', 'msg' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::checkRequest('memberships_admin');
    $action = $_POST['action'] ?? '';
    if ($action === 'save') {
        $id = (int)($_POST['id_membership'] ?? 0);
        $features = MembershipPlan::featuresFromText((string)($_POST['features_text'] ?? ''));
        $limits = [];
        $maxProductsRaw = trim((string)($_POST['max_products'] ?? ''));
        $maxServicesRaw = trim((string)($_POST['max_services'] ?? ''));
        $maxApptsRaw = trim((string)($_POST['max_appointments_month'] ?? ''));
        $maxProfessionalsRaw = trim((string)($_POST['max_professionals'] ?? ''));
        $maxClientsRaw = trim((string)($_POST['max_clients'] ?? ''));
        if ($maxProductsRaw !== '') {
            $limits[MembershipPlan::LIMIT_MAX_PRODUCTS] = max(0, (int)$maxProductsRaw);
        }
        if ($maxServicesRaw !== '') {
            $limits[MembershipPlan::LIMIT_MAX_SERVICES] = max(0, (int)$maxServicesRaw);
        }
        if ($maxApptsRaw !== '') {
            $limits[MembershipPlan::LIMIT_MAX_APPOINTMENTS_MONTH] = max(0, (int)$maxApptsRaw);
        }
        if ($maxProfessionalsRaw !== '') {
            $limits[MembershipPlan::LIMIT_MAX_PROFESSIONALS] = max(0, (int)$maxProfessionalsRaw);
        }
        if ($maxClientsRaw !== '') {
            $limits[MembershipPlan::LIMIT_MAX_CLIENTS] = max(0, (int)$maxClientsRaw);
        }
        $settingsTier = strtolower(trim((string)($_POST['settings_tier'] ?? MembershipPlan::SETTINGS_TIER_FULL)));
        $limits[MembershipPlan::LIMIT_SETTINGS_TIER] = $settingsTier === MembershipPlan::SETTINGS_TIER_BASIC
            ? MembershipPlan::SETTINGS_TIER_BASIC
            : MembershipPlan::SETTINGS_TIER_FULL;
        $precioAnualRaw = trim((string)($_POST['precio_anual'] ?? ''));
        $data = [
            'nombre'               => trim((string)($_POST['nombre'] ?? '')),
            'descripcion'          => trim((string)($_POST['descripcion'] ?? '')),
            'features'             => MembershipPlan::featuresToJson($features),
            'limits'               => MembershipPlan::limitsToJson($limits),
            'precio'               => (float)($_POST['precio'] ?? 0),
            'precio_anual'         => $precioAnualRaw === '' ? null : (float)$precioAnualRaw,
            'descuento_anual_pct'  => max(0, min(100, (float)($_POST['descuento_anual_pct'] ?? 0))),
            'anual_habilitado'     => isset($_POST['anual_habilitado']) ? 1 : 0,
            'moneda'               => strtoupper(trim((string)($_POST['moneda'] ?? 'UYU'))),
            'duracion_dias'        => max(1, (int)($_POST['duracion_dias'] ?? 30)),
            'trial_dias'           => max(0, (int)($_POST['trial_dias'] ?? 30)),
            'mp_preapproval_id'    => trim((string)($_POST['mp_preapproval_id'] ?? '')),
            'paypal_plan_id'       => trim((string)($_POST['paypal_plan_id'] ?? '')),
            'activo'               => isset($_POST['activo']) ? 1 : 0,
            'updated_at'           => date('Y-m-d H:i:s'),
        ];
        if ($data['nombre'] === '') {
            $flash = ['type' => 'error', 'msg' => 'El nombre es obligatorio.'];
        } elseif ($id > 0) {
            $db->update('memberships', $data, 'id_membership = :id', [':id' => $id]);
            Auth::audit('save_membership', 'membership', $id);
            $flash = ['type' => 'ok', 'msg' => 'Membresía actualizada.'];
        } else {
            $id = $db->insert('memberships', $data);
            Auth::audit('create_membership', 'membership', $id);
            $flash = ['type' => 'ok', 'msg' => 'Membresía creada.'];
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id_membership'] ?? 0);
        if ($id > 0) {
            $used = (int)$db->fetchValue('SELECT COUNT(*) FROM subscriptions WHERE id_membership = :id', [':id' => $id]);
            if ($used > 0) {
                $flash = ['type' => 'error', 'msg' => "No se puede borrar: {$used} suscripción(es) la usan. Desactivá en su lugar."];
            } else {
                $db->delete('memberships', 'id_membership = :id', [':id' => $id]);
                Auth::audit('delete_membership', 'membership', $id);
                $flash = ['type' => 'ok', 'msg' => 'Membresía eliminada.'];
            }
        }
    }
}

$memberships = $db->fetchAll(
    "SELECT m.*, (SELECT COUNT(*) FROM subscriptions s WHERE s.id_membership = m.id_membership) AS subs_count
     FROM memberships m ORDER BY m.precio ASC, m.id_membership ASC"
);

$edit = null;
if (isset($_GET['id'])) {
    $edit = $db->fetchOne('SELECT * FROM memberships WHERE id_membership = :id', [':id' => (int)$_GET['id']]);
}

$pageTitle = 'Membresías';
$activeSection = 'memberships';
require __DIR__ . '/partials/header.php';

$editMaxProducts = '';
$editMaxServices = '';
$editMaxAppts = '';
$editMaxProfessionals = '';
$editMaxClients = '';
$editSettingsTier = MembershipPlan::SETTINGS_TIER_FULL;
$editFeaturesText = '';
$editPrecioAnual = '';
if (is_array($edit)) {
    $editMax = MembershipPlan::maxProducts($edit);
    $editMaxProducts = $editMax === null ? '' : (string)$editMax;
    $editSvc = MembershipPlan::maxServices($edit);
    $editMaxServices = $editSvc === null ? '' : (string)$editSvc;
    $editAppt = MembershipPlan::maxAppointmentsMonth($edit);
    $editMaxAppts = $editAppt === null ? '' : (string)$editAppt;
    $editProf = MembershipPlan::maxProfessionals($edit);
    $editMaxProfessionals = $editProf === null ? '' : (string)$editProf;
    $editCli = MembershipPlan::maxClients($edit);
    $editMaxClients = $editCli === null ? '' : (string)$editCli;
    $editSettingsTier = MembershipPlan::settingsTier($edit);
    $editFeaturesText = MembershipPlan::featuresToText($edit);
    if (isset($edit['precio_anual']) && $edit['precio_anual'] !== null && $edit['precio_anual'] !== '') {
        $editPrecioAnual = (string)$edit['precio_anual'];
    }
}
?>

<?php if ($flash['msg']): ?>
    <div class="alert alert-<?= $flash['type'] === 'error' ? 'error' : 'ok' ?>"><?= htmlspecialchars($flash['msg'], ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<section class="page-header">
    <h1>Membresías</h1>
    <p>Definí planes con límites, contenidos y precios mensuales o anuales.</p>
</section>

<article class="card">
    <h2><?= $edit ? 'Editar membresía' : 'Nueva membresía' ?></h2>
    <form method="post">
        <?= CSRF::field('memberships_admin') ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id_membership" value="<?= (int)($edit['id_membership'] ?? 0) ?>">

        <h3 class="membership-form__section">Identidad</h3>
        <div class="form-grid">
            <div class="field">
                <label>Título del plan</label>
                <input type="text" name="nombre" required value="<?= htmlspecialchars($edit['nombre'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="Ej. Free, Básico, Profesional">
            </div>
            <div class="field">
                <label>Activo</label>
                <label style="display:flex; align-items:center; gap:.5rem; font-size: 1rem">
                    <input type="checkbox" name="activo" <?= !isset($edit) || (int)($edit['activo'] ?? 1) === 1 ? 'checked' : '' ?>>
                    Visible para nuevos comercios
                </label>
            </div>
            <div class="field col-2">
                <label>Resumen corto</label>
                <textarea name="descripcion" placeholder="Texto breve bajo el precio"><?= htmlspecialchars($edit['descripcion'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
            </div>
            <div class="field col-2">
                <label>Qué incluye (una línea por ítem)</label>
                <textarea name="features_text" rows="6" placeholder="Hasta 3 productos&#10;Agenda online&#10;Soporte por email"><?= htmlspecialchars($editFeaturesText, ENT_QUOTES, 'UTF-8') ?></textarea>
                <span class="hint">Se muestra como lista de beneficios en la landing y en “Mi Plan”.</span>
            </div>
        </div>

        <h3 class="membership-form__section">Límites</h3>
        <div class="form-grid">
            <div class="field">
                <label>Máx. productos</label>
                <input type="number" name="max_products" min="0" step="1" value="<?= htmlspecialchars($editMaxProducts, ENT_QUOTES, 'UTF-8') ?>" placeholder="Vacío = sin límite">
                <span class="hint">Free = 0, Básico = 6. Vacío = ilimitado.</span>
            </div>
            <div class="field">
                <label>Máx. servicios</label>
                <input type="number" name="max_services" min="0" step="1" value="<?= htmlspecialchars($editMaxServices, ENT_QUOTES, 'UTF-8') ?>" placeholder="Vacío = sin límite">
                <span class="hint">Free = 4, Básico = 8. Vacío = ilimitado.</span>
            </div>
            <div class="field">
                <label>Máx. reservas / mes</label>
                <input type="number" name="max_appointments_month" min="0" step="1" value="<?= htmlspecialchars($editMaxAppts, ENT_QUOTES, 'UTF-8') ?>" placeholder="Vacío = sin límite">
                <span class="hint">Free = 25, Básico = 100. Vacío = ilimitado.</span>
            </div>
            <div class="field">
                <label>Máx. profesionales</label>
                <input type="number" name="max_professionals" min="0" step="1" value="<?= htmlspecialchars($editMaxProfessionals, ENT_QUOTES, 'UTF-8') ?>" placeholder="Vacío = sin límite">
                <span class="hint">Free = 1, Básico = 3. Vacío = ilimitado (staff/barberos).</span>
            </div>
            <div class="field">
                <label>Máx. clientes</label>
                <input type="number" name="max_clients" min="0" step="1" value="<?= htmlspecialchars($editMaxClients, ENT_QUOTES, 'UTF-8') ?>" placeholder="Vacío = sin límite">
                <span class="hint">Free = 25, Básico = 100. Vacío = ilimitado.</span>
            </div>
            <div class="field">
                <label>Nivel de configuración</label>
                <select name="settings_tier">
                    <option value="full" <?= $editSettingsTier === 'full' ? 'selected' : '' ?>>Completa</option>
                    <option value="basic" <?= $editSettingsTier === 'basic' ? 'selected' : '' ?>>Básica (nombre, logo, redes)</option>
                </select>
                <span class="hint">Básica bloquea fiscal, Mercado Pago, SEO, legales, etc.</span>
            </div>
        </div>

        <h3 class="membership-form__section">Precios</h3>
        <div class="form-grid">
            <div class="field">
                <label>Precio mensual</label>
                <input type="number" name="precio" min="0" step="0.01" required value="<?= htmlspecialchars((string)($edit['precio'] ?? '0'), ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="field">
                <label>Moneda</label>
                <select name="moneda">
                    <?php foreach (['UYU','USD','ARS','BRL','CLP','PYG'] as $m): ?>
                        <option value="<?= $m ?>" <?= ($edit['moneda'] ?? 'UYU') === $m ? 'selected' : '' ?>><?= $m ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>Duración ciclo mensual (días)</label>
                <input type="number" name="duracion_dias" min="1" value="<?= (int)($edit['duracion_dias'] ?? 30) ?>">
            </div>
            <div class="field">
                <label>Trial (días gratis)</label>
                <input type="number" name="trial_dias" min="0" value="<?= (int)($edit['trial_dias'] ?? 30) ?>">
            </div>
            <div class="field col-2">
                <label style="display:flex; align-items:center; gap:.5rem; font-size: 1rem">
                    <input type="checkbox" name="anual_habilitado" <?= (int)($edit['anual_habilitado'] ?? 0) === 1 ? 'checked' : '' ?>>
                    Ofrecer pago anual con descuento
                </label>
                <span class="hint">El cobro real vía MercadoPago/PayPal sigue usando el plan mensual hasta que se creen planes anuales en el gateway. Se guarda la intención “yearly” en la suscripción.</span>
            </div>
            <div class="field">
                <label>Descuento anual (%)</label>
                <input type="number" name="descuento_anual_pct" min="0" max="100" step="0.01" value="<?= htmlspecialchars((string)($edit['descuento_anual_pct'] ?? '0'), ENT_QUOTES, 'UTF-8') ?>">
                <span class="hint">Si no fijás precio anual, se calcula: mensual × 12 × (1 − %).</span>
            </div>
            <div class="field">
                <label>Precio anual (opcional)</label>
                <input type="number" name="precio_anual" min="0" step="0.01" value="<?= htmlspecialchars($editPrecioAnual, ENT_QUOTES, 'UTF-8') ?>" placeholder="Auto desde descuento">
                <span class="hint">Dejá vacío para calcular con el % de descuento.</span>
            </div>
        </div>

        <h3 class="membership-form__section">Pasarelas (opcional)</h3>
        <div class="form-grid">
            <div class="field">
                <label>MP Preapproval Plan ID</label>
                <input type="text" name="mp_preapproval_id" value="<?= htmlspecialchars($edit['mp_preapproval_id'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="2c9381..">
            </div>
            <div class="field">
                <label>PayPal Plan ID</label>
                <input type="text" name="paypal_plan_id" value="<?= htmlspecialchars($edit['paypal_plan_id'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="P-5..">
            </div>
        </div>

        <div class="actions">
            <button class="btn btn-primary" type="submit"><?= $edit ? 'Guardar' : 'Crear' ?></button>
            <?php if ($edit): ?><a class="btn" href="memberships.php">Cancelar</a><?php endif; ?>
        </div>
    </form>
</article>

<article class="card">
    <h2>Planes existentes</h2>
    <div class="table-wrap">
    <table class="table">
        <thead>
            <tr>
                <th>Plan</th>
                <th>Mensual</th>
                <th>Anual</th>
                <th>Límites</th>
                <th>Incluye</th>
                <th>Activo</th>
                <th>Suscripciones</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($memberships as $m):
            $features = MembershipPlan::features($m);
            $maxP = MembershipPlan::maxProducts($m);
            $maxS = MembershipPlan::maxServices($m);
            $maxA = MembershipPlan::maxAppointmentsMonth($m);
            $maxProf = MembershipPlan::maxProfessionals($m);
            $maxCli = MembershipPlan::maxClients($m);
            $tier = MembershipPlan::settingsTier($m);
            $yearly = MembershipPlan::yearlyPrice($m);
            $discount = MembershipPlan::annualDiscountPct($m);
            $fmtLimit = static function (?int $n): string {
                return $n === null ? '∞' : (string)$n;
            };
        ?>
            <tr>
                <td>
                    <strong><?= htmlspecialchars($m['nombre'], ENT_QUOTES, 'UTF-8') ?></strong>
                    <?php if (trim((string)($m['descripcion'] ?? '')) !== ''): ?>
                        <div class="muted" style="font-size:.8rem;margin-top:.2rem"><?= htmlspecialchars((string)$m['descripcion'], ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ((float)$m['precio'] > 0): ?>
                        <?= number_format((float)$m['precio'], 0, ',', '.') ?> <?= htmlspecialchars($m['moneda'], ENT_QUOTES, 'UTF-8') ?>
                        <span class="muted">/mes</span>
                    <?php else: ?>
                        Gratis
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($yearly !== null): ?>
                        <?= number_format($yearly, 0, ',', '.') ?> <?= htmlspecialchars($m['moneda'], ENT_QUOTES, 'UTF-8') ?>
                        <?php if ($discount > 0): ?>
                            <span class="muted"> (−<?= rtrim(rtrim(number_format($discount, 1, ',', '.'), '0'), ',') ?>%)</span>
                        <?php endif; ?>
                    <?php else: ?>
                        —
                    <?php endif; ?>
                </td>
                <td style="font-size:.82rem">
                    Prod: <strong><?= htmlspecialchars($fmtLimit($maxP), ENT_QUOTES, 'UTF-8') ?></strong>
                    · Serv: <strong><?= htmlspecialchars($fmtLimit($maxS), ENT_QUOTES, 'UTF-8') ?></strong>
                    · Res/mes: <strong><?= htmlspecialchars($fmtLimit($maxA), ENT_QUOTES, 'UTF-8') ?></strong>
                    · Prof: <strong><?= htmlspecialchars($fmtLimit($maxProf), ENT_QUOTES, 'UTF-8') ?></strong>
                    · Cli: <strong><?= htmlspecialchars($fmtLimit($maxCli), ENT_QUOTES, 'UTF-8') ?></strong>
                    · Config: <strong><?= $tier === 'basic' ? 'básica' : 'completa' ?></strong>
                </td>
                <td style="max-width:220px;font-size:.82rem">
                    <?php if ($features === []): ?>
                        <span class="muted">—</span>
                    <?php else: ?>
                        <?= htmlspecialchars(implode(' · ', array_slice($features, 0, 3)), ENT_QUOTES, 'UTF-8') ?>
                        <?= count($features) > 3 ? '…' : '' ?>
                    <?php endif; ?>
                </td>
                <td><?= (int)$m['activo'] === 1 ? '✓' : '✕' ?></td>
                <td><?= (int)$m['subs_count'] ?></td>
                <td>
                    <a class="btn btn-sm" href="memberships.php?id=<?= (int)$m['id_membership'] ?>">editar</a>
                    <form method="post" style="display:inline" onsubmit="return confirm('¿Eliminar?');">
                        <?= CSRF::field('memberships_admin') ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id_membership" value="<?= (int)$m['id_membership'] ?>">
                        <button class="btn btn-sm btn-danger" type="submit">×</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</article>

<style>
.membership-form__section {
  margin: 1.4rem 0 .6rem;
  font-size: .95rem;
  font-weight: 700;
  color: var(--text, #e8eaed);
  border-bottom: 1px solid var(--border, #2a2f3a);
  padding-bottom: .35rem;
}
.membership-form__section:first-of-type { margin-top: .4rem; }
</style>

<?php require __DIR__ . '/partials/footer.php'; ?>
