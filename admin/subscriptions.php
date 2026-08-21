<?php
/**
 * Agenduy - Super Admin: Suscripciones
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::checkRequest('subs_admin');
    $action = $_POST['action'] ?? '';
    if ($action === 'activate') {
        $id = (int)($_POST['id_subscription'] ?? 0);
        $sub = $db->fetchOne('SELECT * FROM subscriptions WHERE id_subscription = :id', [':id' => $id]);
        if ($sub) {
            $days = (int)$db->fetchValue('SELECT duracion_dias FROM memberships WHERE id_membership = :id', [':id' => $sub['id_membership']]);
            $newEnd = date('Y-m-d', strtotime("+{$days} days"));
            $db->update('subscriptions', [
                'status'              => 'active',
                'current_period_start'=> date('Y-m-d'),
                'current_period_end'  => $newEnd,
                'updated_at'          => date('Y-m-d H:i:s'),
            ], 'id_subscription = :id', [':id' => $id]);
            $db->update('commerces', [
                'status'             => 'active',
                'next_billing_at'    => $newEnd,
                'updated_at'         => date('Y-m-d H:i:s'),
            ], 'id_commerce = :id', [':id' => $sub['id_commerce']]);
            Auth::audit('activate_subscription', 'subscription', $id, null, ['days' => $days]);
            $flash = ['type' => 'ok', 'msg' => "Suscripción activada. Próximo cobro: {$newEnd}."];
        }
    } elseif ($action === 'cancel') {
        $id = (int)($_POST['id_subscription'] ?? 0);
        $sub = $db->fetchOne('SELECT * FROM subscriptions WHERE id_subscription = :id', [':id' => $id]);
        if ($sub) {
            $db->update('subscriptions', [
                'status'       => 'cancelled',
                'cancelled_at' => date('Y-m-d H:i:s'),
                'updated_at'   => date('Y-m-d H:i:s'),
            ], 'id_subscription = :id', [':id' => $id]);
            $db->update('commerces', [
                'status'       => 'cancelled',
                'cancelled_at' => date('Y-m-d H:i:s'),
                'updated_at'   => date('Y-m-d H:i:s'),
            ], 'id_commerce = :id', [':id' => $sub['id_commerce']]);
            Auth::audit('cancel_subscription', 'subscription', $id);
            $flash = ['type' => 'ok', 'msg' => 'Suscripción cancelada.'];
        }
    } elseif ($action === 'change_membership') {
        $id = (int)($_POST['id_subscription'] ?? 0);
        $mid = (int)($_POST['id_membership'] ?? 0);
        if ($id && $mid) {
            $db->update('subscriptions', [
                'id_membership' => $mid,
                'updated_at'    => date('Y-m-d H:i:s'),
            ], 'id_subscription = :id', [':id' => $id]);
            $sub = $db->fetchOne('SELECT id_commerce FROM subscriptions WHERE id_subscription = :id', [':id' => $id]);
            if ($sub) {
                $db->update('commerces', [
                    'id_membership' => $mid,
                    'updated_at'    => date('Y-m-d H:i:s'),
                ], 'id_commerce = :id', [':id' => $sub['id_commerce']]);
            }
            Auth::audit('change_membership', 'subscription', $id, null, ['new_membership' => $mid]);
            $flash = ['type' => 'ok', 'msg' => 'Membresía actualizada.'];
        }
    }
}

$status = trim((string)($_GET['status'] ?? ''));
$where = '1=1';
$params = [];
if (in_array($status, ['trial','active','past_due','cancelled'], true)) {
    $where .= ' AND s.status = :st';
    $params[':st'] = $status;
}

$subs = $db->fetchAll(
    "SELECT s.*, c.nombre AS commerce, c.slug, m.nombre AS plan
     FROM subscriptions s
     LEFT JOIN commerces c ON c.id_commerce = s.id_commerce
     LEFT JOIN memberships m ON m.id_membership = s.id_membership
     WHERE $where
     ORDER BY s.updated_at DESC
     LIMIT 300",
    $params
);

$memberships = $db->fetchAll('SELECT id_membership, nombre, precio, moneda FROM memberships ORDER BY nombre');

$pageTitle = 'Suscripciones';
$activeSection = 'subscriptions';
require __DIR__ . '/partials/header.php';
?>

<?php if ($flash['msg']): ?>
    <div class="alert alert-<?= $flash['type'] === 'error' ? 'error' : 'ok' ?>"><?= htmlspecialchars($flash['msg'], ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<section class="page-header">
    <h1>Suscripciones</h1>
    <p>Activá, cancelá o cambiá la membresía de cada comercio. El trial se extiende hasta que se apruebe el pago.</p>
</section>

<form class="card" method="get">
    <div class="form-grid">
        <div class="field">
            <label>Status</label>
            <select name="status">
                <option value="">Todos</option>
                <?php foreach (['trial','active','past_due','cancelled'] as $opt): ?>
                    <option value="<?= $opt ?>" <?= $status === $opt ? 'selected' : '' ?>><?= $opt ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="actions" style="align-self:end">
            <button class="btn btn-primary" type="submit">Filtrar</button>
            <a class="btn" href="subscriptions.php">Limpiar</a>
        </div>
    </div>
</form>

<article class="card">
    <h2><?= count($subs) ?> suscripciones</h2>
    <div class="table-wrap table-wrap--scroll">
    <table class="table">
        <thead>
            <tr>
                <th>Comercio</th>
                <th>Plan</th>
                <th>Status</th>
                <th>Gateway</th>
                <th>Trial vence</th>
                <th>Periodo actual</th>
                <th>Acciones</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($subs as $s): ?>
            <tr>
                <td>
                    <a href="commerces.php?id=<?= (int)$s['id_commerce'] ?>"><?= htmlspecialchars($s['commerce'] ?? '—', ENT_QUOTES, 'UTF-8') ?></a>
                    <br><code class="code"><?= htmlspecialchars($s['slug'] ?? '', ENT_QUOTES, 'UTF-8') ?></code>
                </td>
                <td><?= htmlspecialchars($s['plan'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                <td><span class="badge badge--<?= htmlspecialchars($s['status'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($s['status'], ENT_QUOTES, 'UTF-8') ?></span></td>
                <td><?= htmlspecialchars((string)($s['gateway'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                <td>
                    <?php if (strtolower($s['plan'] ?? '') === 'free'): ?>
                        —
                    <?php else: ?>
                        <?= htmlspecialchars((string)($s['trial_expires_at'] ?? '—'), ENT_QUOTES, 'UTF-8') ?>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if (strtolower($s['plan'] ?? '') === 'free'): ?>
                        —
                    <?php else: ?>
                        <?= htmlspecialchars((string)($s['current_period_start'] ?? '—'), ENT_QUOTES, 'UTF-8') ?>
                        →
                        <?= htmlspecialchars((string)($s['current_period_end'] ?? '—'), ENT_QUOTES, 'UTF-8') ?>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if (strtolower($s['plan'] ?? '') !== 'free'): ?>
                        <form method="post" style="display:inline">
                            <?= CSRF::field('subs_admin') ?>
                            <input type="hidden" name="action" value="activate">
                            <input type="hidden" name="id_subscription" value="<?= (int)$s['id_subscription'] ?>">
                            <button class="btn btn-sm btn-ok" type="submit">Activar</button>
                        </form>
                        <form method="post" style="display:inline" onsubmit="return confirm('¿Cancelar?');">
                            <?= CSRF::field('subs_admin') ?>
                            <input type="hidden" name="action" value="cancel">
                            <input type="hidden" name="id_subscription" value="<?= (int)$s['id_subscription'] ?>">
                            <button class="btn btn-sm btn-danger" type="submit">Cancelar</button>
                        </form>
                    <?php endif; ?>
                    <details style="display:inline-block">
                        <summary class="btn btn-sm btn-ghost" style="display:inline-block; cursor:pointer">cambiar plan</summary>
                        <form method="post" style="margin-top:.5rem">
                            <?= CSRF::field('subs_admin') ?>
                            <input type="hidden" name="action" value="change_membership">
                            <input type="hidden" name="id_subscription" value="<?= (int)$s['id_subscription'] ?>">
                            <select name="id_membership">
                                <?php foreach ($memberships as $m): ?>
                                    <option value="<?= (int)$m['id_membership'] ?>" <?= (int)$s['id_membership'] === (int)$m['id_membership'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($m['nombre'], ENT_QUOTES, 'UTF-8') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button class="btn btn-sm btn-primary" type="submit">OK</button>
                        </form>
                    </details>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</article>

<?php require __DIR__ . '/partials/footer.php'; ?>
