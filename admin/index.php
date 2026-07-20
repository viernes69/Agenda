<?php
/**
 * Agenduy - Super Admin: Overview
 */
declare(strict_types=1);

$config = require __DIR__ . '/../src/Core/bootstrap.php';
use Agenduy\Core\Database;
use Agenduy\Core\Auth;

$db = Database::getInstance();

// Métricas globales
$totalCommerces   = (int)$db->fetchValue('SELECT COUNT(*) FROM commerces');
$trialCommerces   = (int)$db->fetchValue("SELECT COUNT(*) FROM commerces WHERE status='trial'");
$activeCommerces  = (int)$db->fetchValue("SELECT COUNT(*) FROM commerces WHERE status='active'");
$pastDueCommerces = (int)$db->fetchValue("SELECT COUNT(*) FROM commerces WHERE status='past_due'");
$cancelled        = (int)$db->fetchValue("SELECT COUNT(*) FROM commerces WHERE status='cancelled'");

$totalUsers       = (int)$db->fetchValue('SELECT COUNT(*) FROM users');
$totalAppointments= (int)$db->fetchValue('SELECT COUNT(*) FROM appointments');
$totalClients     = (int)$db->fetchValue('SELECT COUNT(*) FROM clients');
$pendingTransfers = (int)$db->fetchValue("SELECT COUNT(*) FROM payment_transfers WHERE status='pending'");

$totalRevenue = (float)$db->fetchValue(
    "SELECT COALESCE(SUM(monto), 0)
     FROM payment_transfers
     WHERE status='approved'"
);

$mrr = (float)$db->fetchValue(
    "SELECT COALESCE(SUM(m.precio), 0)
     FROM subscriptions s
     JOIN memberships m ON m.id_membership = s.id_membership
     WHERE s.status='active'"
);

// Comercios que entran al trial por vencer (próximos 7 días)
$soonExpiring = $db->fetchAll(
    "SELECT id_commerce, slug, nombre, trial_expires_at, status
     FROM commerces
     WHERE status='trial'
       AND trial_expires_at IS NOT NULL
       AND date(trial_expires_at) BETWEEN date('now') AND date('now','+7 days')
     ORDER BY trial_expires_at ASC
     LIMIT 10"
);

$recentCommerces = $db->fetchAll(
    "SELECT id_commerce, slug, nombre, status, created_at
     FROM commerces
     ORDER BY created_at DESC
     LIMIT 10"
);

$pageTitle = 'Resumen';
$activeSection = 'overview';
require __DIR__ . '/partials/header.php';
?>
<section class="page-header">
    <h1>Resumen global</h1>
    <p>Estado de la plataforma y métricas clave.</p>
</section>

<div class="kpi-grid">
    <div class="kpi"><span class="kpi__label">Comercios totales</span><span class="kpi__value"><?= $totalCommerces ?></span></div>
    <div class="kpi kpi--ok"><span class="kpi__label">Activos</span><span class="kpi__value"><?= $activeCommerces ?></span></div>
    <div class="kpi kpi--trial"><span class="kpi__label">En trial</span><span class="kpi__value"><?= $trialCommerces ?></span></div>
    <div class="kpi kpi--warn"><span class="kpi__label">Pagos vencidos</span><span class="kpi__value"><?= $pastDueCommerces ?></span></div>
    <div class="kpi kpi--danger"><span class="kpi__label">Cancelados</span><span class="kpi__value"><?= $cancelled ?></span></div>
    <div class="kpi"><span class="kpi__label">Usuarios</span><span class="kpi__value"><?= $totalUsers ?></span></div>
    <div class="kpi"><span class="kpi__label">Turnos totales</span><span class="kpi__value"><?= $totalAppointments ?></span></div>
    <div class="kpi"><span class="kpi__label">Clientes</span><span class="kpi__value"><?= $totalClients ?></span></div>
    <div class="kpi kpi--warn"><span class="kpi__label">Transferencias pendientes</span><span class="kpi__value"><?= $pendingTransfers ?></span></div>
    <div class="kpi kpi--ok"><span class="kpi__label">MRR estimado</span><span class="kpi__value">$<?= number_format($mrr, 2) ?></span></div>
    <div class="kpi kpi--ok"><span class="kpi__label">Ingresos por transferencia</span><span class="kpi__value">$<?= number_format($totalRevenue, 2) ?></span></div>
</div>

<div class="two-col">
    <article class="card">
        <h2>Trial por vencer (próximos 7 días)</h2>
        <?php if (empty($soonExpiring)): ?>
            <p class="muted">No hay trials por vencer en los próximos 7 días.</p>
        <?php else: ?>
            <table class="table">
                <thead><tr><th>Comercio</th><th>Slug</th><th>Vence</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($soonExpiring as $c): ?>
                    <tr>
                        <td><?= htmlspecialchars($c['nombre'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><code><?= htmlspecialchars($c['slug'], ENT_QUOTES, 'UTF-8') ?></code></td>
                        <td><?= htmlspecialchars($c['trial_expires_at'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                        <td><span class="badge badge--trial">trial</span></td>
                        <td><a class="btn btn-sm" href="commerces.php?id=<?= (int)$c['id_commerce'] ?>">ver</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </article>

    <article class="card">
        <h2>Últimos comercios registrados</h2>
        <table class="table">
            <thead><tr><th>Nombre</th><th>Slug</th><th>Status</th><th>Creado</th></tr></thead>
            <tbody>
            <?php foreach ($recentCommerces as $c): ?>
                <tr>
                    <td><a href="commerces.php?id=<?= (int)$c['id_commerce'] ?>"><?= htmlspecialchars($c['nombre'], ENT_QUOTES, 'UTF-8') ?></a></td>
                    <td><code><?= htmlspecialchars($c['slug'], ENT_QUOTES, 'UTF-8') ?></code></td>
                    <td><span class="badge badge--<?= htmlspecialchars($c['status'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($c['status'], ENT_QUOTES, 'UTF-8') ?></span></td>
                    <td><?= htmlspecialchars($c['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </article>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
