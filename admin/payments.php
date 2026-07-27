<?php
/**
 * Agenduy - Super Admin: Pagos / Transferencias
 * Aprueba o rechaza comprobantes. Al aprobar activa la suscripción.
 */
declare(strict_types=1);

$config = require __DIR__ . '/../src/Core/bootstrap.php';
use Agenduy\Core\Auth;
use Agenduy\Core\CSRF;
use Agenduy\Core\Database;
use Agenduy\Core\Mail;
use Agenduy\Core\MembershipPlan;

Auth::start();
if (!Auth::check() || Auth::role() !== 'super_admin') { header('Location: ' . Auth::loginUrl()); exit; }

$db = Database::getInstance();
$flash = ['type' => '', 'msg' => ''];
/** @var string|null After approve/reject, force list filter so the row stays visible */
$forceStatusFilter = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::checkRequest('payments_admin');
    $action = $_POST['action'] ?? '';
    if ($action === 'approve' || $action === 'reject') {
        $id = (int)($_POST['id_transfer'] ?? 0);
        $notes = trim((string)($_POST['review_notes'] ?? ''));
        $t = $db->fetchOne('SELECT * FROM payment_transfers WHERE id_transfer = :id', [':id' => $id]);
        if ($t) {
            $newStatus = $action === 'approve' ? 'approved' : 'rejected';
            $db->update('payment_transfers', [
                'status'       => $newStatus,
                'reviewed_by'  => Auth::id(),
                'reviewed_at'  => date('Y-m-d H:i:s'),
                'review_notes' => $notes,
                'updated_at'   => date('Y-m-d H:i:s'),
            ], 'id_transfer = :id', [':id' => $id]);
            Auth::audit($newStatus . '_transfer', 'payment_transfer', $id, null, ['notes' => $notes]);

            if ($action === 'approve') {
                // Activar suscripción con el plan pendiente (notes), no el actual Free/trial.
                $sub = $db->fetchOne(
                    'SELECT * FROM subscriptions WHERE id_commerce = :c ORDER BY id_subscription DESC LIMIT 1',
                    [':c' => $t['id_commerce']]
                );
                $commerceRow = $db->fetchOne(
                    'SELECT * FROM commerces WHERE id_commerce = :c',
                    [':c' => $t['id_commerce']]
                );
                $pending = $sub
                    ? MembershipPlan::parsePendingMembershipNote($sub['notes'] ?? null)
                    : null;
                $idMembership = 0;
                if ($pending) {
                    $idMembership = (int)$pending['pending_id'];
                }
                if ($idMembership <= 0 && $sub) {
                    $idMembership = (int)($sub['id_membership'] ?? 0);
                }
                if ($idMembership <= 0 && $commerceRow) {
                    $idMembership = (int)($commerceRow['id_membership'] ?? 0);
                }
                $membership = $idMembership > 0
                    ? $db->fetchOne('SELECT * FROM memberships WHERE id_membership = :id', [':id' => $idMembership])
                    : null;
                $days = $membership ? (int)$membership['duracion_dias'] : 30;
                if ($sub && (($sub['billing_period'] ?? '') === 'yearly')) {
                    $days = 365;
                }
                $newEnd = date('Y-m-d', strtotime("+{$days} days"));
                $now = date('Y-m-d H:i:s');
                if ($sub) {
                    $subUpdate = [
                        'status'               => 'active',
                        'gateway'              => 'transfer',
                        'gateway_id'           => 'transfer_' . $id,
                        'current_period_start' => date('Y-m-d'),
                        'current_period_end'   => $newEnd,
                        'notes'                => '',
                        'updated_at'           => $now,
                    ];
                    if ($idMembership > 0) {
                        $subUpdate['id_membership'] = $idMembership;
                    }
                    $db->update('subscriptions', $subUpdate, 'id_subscription = :id', [':id' => $sub['id_subscription']]);
                } elseif ($idMembership > 0) {
                    $db->insert('subscriptions', [
                        'id_commerce'         => (int)$t['id_commerce'],
                        'id_membership'       => $idMembership,
                        'status'              => 'active',
                        'gateway'             => 'transfer',
                        'gateway_id'          => 'transfer_' . $id,
                        'current_period_start'=> date('Y-m-d'),
                        'current_period_end'  => $newEnd,
                        'notes'               => '',
                    ]);
                }
                $commerceUpdate = [
                    'status'          => 'active',
                    'next_billing_at' => $newEnd,
                    'trial_expires_at'=> null,
                    'updated_at'      => $now,
                ];
                if ($idMembership > 0) {
                    $commerceUpdate['id_membership'] = $idMembership;
                }
                $db->update('commerces', $commerceUpdate, 'id_commerce = :id', [':id' => $t['id_commerce']]);

                // Notificar al dueño del comercio
                $owner = $db->fetchOne(
                    'SELECT email, nombre FROM users WHERE id_commerce = :c AND role = :r LIMIT 1',
                    [':c' => $t['id_commerce'], ':r' => 'commerce_admin']
                );
                if ($owner && !empty($owner['email'])) {
                    $subject = 'Tu pago fue aprobado - Agenduy';
                    $body = '<p>Hola ' . htmlspecialchars($owner['nombre']) . ',</p>'
                          . '<p>Confirmamos tu pago por <strong>' . htmlspecialchars((string)$t['moneda']) . ' ' . number_format((float)$t['monto'], 2) . '</strong>.</p>'
                          . '<p>Tu suscripción está activa hasta el <strong>' . htmlspecialchars($newEnd) . '</strong>.</p>'
                          . '<p>Equipo Agenduy</p>';
                    Mail::send($owner['email'], $subject, $body, null, (int)$t['id_commerce']);
                }
            } elseif ($action === 'reject') {
                // Clear pending upgrade; never apply paid plan. Restore previous if wrongly switched.
                $sub = $db->fetchOne(
                    'SELECT * FROM subscriptions WHERE id_commerce = :c ORDER BY id_subscription DESC LIMIT 1',
                    [':c' => $t['id_commerce']]
                );
                $commerceRow = $db->fetchOne(
                    'SELECT * FROM commerces WHERE id_commerce = :c',
                    [':c' => $t['id_commerce']]
                );
                $pending = $sub
                    ? MembershipPlan::parsePendingMembershipNote($sub['notes'] ?? null)
                    : null;
                $previousId = $pending ? (int)$pending['previous_id'] : 0;
                if ($previousId <= 0 && $commerceRow) {
                    // If commerce was prematurely switched to the pending plan, prefer Free (precio=0) over paid.
                    $currentId = (int)($commerceRow['id_membership'] ?? 0);
                    $currentPlan = $currentId > 0
                        ? $db->fetchOne('SELECT precio FROM memberships WHERE id_membership = :id', [':id' => $currentId])
                        : null;
                    if ($currentPlan && (float)($currentPlan['precio'] ?? 0) > 0 && (string)($commerceRow['status'] ?? '') === 'trial') {
                        $freeId = (int)$db->fetchValue(
                            'SELECT id_membership FROM memberships WHERE activo = 1 AND precio <= 0 ORDER BY id_membership ASC LIMIT 1'
                        );
                        $previousId = $freeId > 0 ? $freeId : $currentId;
                    } else {
                        $previousId = $currentId;
                    }
                }
                $now = date('Y-m-d H:i:s');
                if ($sub) {
                    $subUpdate = [
                        'notes' => MembershipPlan::clearPendingMembershipNote($sub['notes'] ?? null),
                        'updated_at' => $now,
                    ];
                    if ($previousId > 0) {
                        $subUpdate['id_membership'] = $previousId;
                    }
                    // Keep gateway marker but drop pending order id if any.
                    if (($sub['gateway'] ?? '') === 'transfer' && empty($sub['gateway_id'])) {
                        // no-op
                    }
                    $db->update('subscriptions', $subUpdate, 'id_subscription = :id', [':id' => $sub['id_subscription']]);
                }
                if ($commerceRow && $previousId > 0) {
                    $db->update('commerces', [
                        'id_membership' => $previousId,
                        'updated_at' => $now,
                    ], 'id_commerce = :id', [':id' => $t['id_commerce']]);
                }
            }
            $flash = ['type' => 'ok', 'msg' => 'Pago ' . ($newStatus === 'approved' ? 'aprobado' : 'rechazado') . '.'];
            // Keep the reviewed row visible (was vanishing under default pending filter).
            $forceStatusFilter = $newStatus;
        }
    } elseif ($action === 'add_manual') {
        $idCommerce = (int)($_POST['id_commerce'] ?? 0);
        $monto = (float)($_POST['monto'] ?? 0);
        $moneda = strtoupper((string)($_POST['moneda'] ?? 'UYU'));
        $ref = trim((string)($_POST['referencia'] ?? 'manual'));
        if ($idCommerce > 0 && $monto > 0) {
            $db->insert('payment_transfers', [
                'id_commerce' => $idCommerce,
                'monto'       => $monto,
                'moneda'      => $moneda,
                'referencia'  => $ref,
                'status'      => 'pending',
                'review_notes'=> 'Cargado manualmente',
            ]);
            Auth::audit('create_manual_transfer', 'payment_transfer', null, null, ['commerce' => $idCommerce, 'monto' => $monto]);
            $flash = ['type' => 'ok', 'msg' => 'Pago manual registrado.'];
            $forceStatusFilter = 'pending';
        } else {
            $flash = ['type' => 'error', 'msg' => 'Datos incompletos.'];
        }
    }
}

$status = $forceStatusFilter ?? (string)($_GET['status'] ?? 'all');
if (!in_array($status, ['all', 'pending', 'approved', 'rejected'], true)) {
    $status = 'all';
}
$where = '1=1';
$params = [];
if (in_array($status, ['pending', 'approved', 'rejected'], true)) {
    $where .= ' AND t.status = :s';
    $params[':s'] = $status;
}

$transfers = $db->fetchAll(
    "SELECT t.*, c.nombre AS commerce, c.slug, u.email AS reviewed_by_email
     FROM payment_transfers t
     LEFT JOIN commerces c ON c.id_commerce = t.id_commerce
     LEFT JOIN users u ON u.id_user = t.reviewed_by
     WHERE $where
     ORDER BY t.created_at DESC
     LIMIT 200",
    $params
);

$commerces = $db->fetchAll('SELECT id_commerce, nombre, slug FROM commerces ORDER BY nombre');

$pageTitle = 'Pagos';
$activeSection = 'payments';
require __DIR__ . '/partials/header.php';
?>

<?php if ($flash['msg']): ?>
    <div class="alert alert-<?= $flash['type'] === 'error' ? 'error' : 'ok' ?>"><?= htmlspecialchars($flash['msg'], ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<section class="page-header">
    <h1>Pagos · Transferencias</h1>
    <p>Aprobá o rechazá los comprobantes. Al aprobar se activa la suscripción y se le notifica al comercio.</p>
</section>

<form class="card" method="get">
    <div class="form-grid">
        <div class="field">
            <label>Status</label>
            <select name="status">
                <?php
                $statusLabels = [
                    'all'      => 'Todos',
                    'pending'  => 'Pendientes',
                    'approved' => 'Aprobados',
                    'rejected' => 'Rechazados',
                ];
                foreach ($statusLabels as $opt => $label):
                ?>
                    <option value="<?= $opt ?>" <?= $status === $opt ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="actions" style="align-self:end">
            <button class="btn btn-primary" type="submit">Filtrar</button>
        </div>
    </div>
</form>

<article class="card">
    <h2>Cargar pago manual (offline)</h2>
    <form method="post">
        <?= CSRF::field('payments_admin') ?>
        <input type="hidden" name="action" value="add_manual">
        <div class="form-grid">
            <div class="field">
                <label>Comercio</label>
                <select name="id_commerce" required>
                    <option value="">— elegir —</option>
                    <?php foreach ($commerces as $c): ?>
                        <option value="<?= (int)$c['id_commerce'] ?>"><?= htmlspecialchars($c['nombre'] . ' (' . $c['slug'] . ')', ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>Monto</label>
                <input type="number" name="monto" min="0" step="0.01" required>
            </div>
            <div class="field">
                <label>Moneda</label>
                <select name="moneda">
                    <?php foreach (['UYU','USD','ARS','BRL','CLP','PYG'] as $m): ?>
                        <option value="<?= $m ?>"><?= $m ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>Referencia</label>
                <input type="text" name="referencia" placeholder="manual, recibo N°, etc.">
            </div>
        </div>
        <div class="actions">
            <button class="btn btn-primary" type="submit">Registrar pago</button>
        </div>
    </form>
</article>

<article class="card">
    <h2>Transferencias (<?= count($transfers) ?>)</h2>
    <div class="table-wrap">
    <table class="table">
        <thead>
            <tr>
                <th>Fecha</th>
                <th>Comercio</th>
                <th>Monto</th>
                <th>Referencia</th>
                <th>Comprobante</th>
                <th>Status</th>
                <th>Revisado por</th>
                <th>Acciones</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($transfers as $t): ?>
            <tr>
                <td><?= htmlspecialchars($t['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                <td>
                    <a href="commerces.php?id=<?= (int)$t['id_commerce'] ?>"><?= htmlspecialchars($t['commerce'] ?? '—', ENT_QUOTES, 'UTF-8') ?></a>
                    <br><code class="code"><?= htmlspecialchars($t['slug'] ?? '', ENT_QUOTES, 'UTF-8') ?></code>
                </td>
                <td><strong><?= htmlspecialchars($t['moneda'], ENT_QUOTES, 'UTF-8') ?> <?= number_format((float)$t['monto'], 2) ?></strong></td>
                <td><?= htmlspecialchars((string)($t['referencia'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                <td>
                    <?php if (!empty($t['comprobante_path'])): ?>
                        <a href="api/receipt.php?id=<?= (int)$t['id_transfer'] ?>" target="_blank" rel="noopener">ver</a>
                    <?php else: ?>—<?php endif; ?>
                </td>
                <td><span class="badge badge--<?= htmlspecialchars($t['status'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($t['status'], ENT_QUOTES, 'UTF-8') ?></span></td>
                <td>
                    <?= htmlspecialchars((string)($t['reviewed_by_email'] ?? '—'), ENT_QUOTES, 'UTF-8') ?>
                    <br><span class="muted"><?= htmlspecialchars((string)($t['reviewed_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                </td>
                <td>
                    <?php if ($t['status'] === 'pending'): ?>
                        <form method="post" style="display:inline">
                            <?= CSRF::field('payments_admin') ?>
                            <input type="hidden" name="action" value="approve">
                            <input type="hidden" name="id_transfer" value="<?= (int)$t['id_transfer'] ?>">
                            <button class="btn btn-sm btn-ok" type="submit">aprobar</button>
                        </form>
                        <form method="post" style="display:inline">
                            <?= CSRF::field('payments_admin') ?>
                            <input type="hidden" name="action" value="reject">
                            <input type="hidden" name="id_transfer" value="<?= (int)$t['id_transfer'] ?>">
                            <button class="btn btn-sm btn-danger" type="submit">rechazar</button>
                        </form>
                    <?php else: ?>
                        <span class="muted">cerrado</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</article>

<?php require __DIR__ . '/partials/footer.php'; ?>
