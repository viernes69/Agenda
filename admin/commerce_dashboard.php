<?php
/**
 * Agenduy - Dashboard del comercio
 * (Para el dueño del negocio, logueado con role=commerce_admin)
 */
declare(strict_types=1);

$config = require __DIR__ . '/../src/Core/bootstrap.php';

use Agenduy\Core\Auth;
use Agenduy\Core\CSRF;
use Agenduy\Core\CommercePanel;
use Agenduy\Core\Database;

Auth::start();
if (!Auth::check()) { header('Location: login.php'); exit; }
$role = Auth::role();
if ($role === 'super_admin') { header('Location: index.php'); exit; }
if ($role !== 'commerce_admin') { header('Location: login.php'); exit; }

$idCommerce = (int)Auth::commerceId();
if ($idCommerce <= 0) {
    echo 'Cuenta sin comercio asignado. Contactá al super admin.';
    exit;
}

$db = Database::getInstance();
$commerce = $db->fetchOne('SELECT * FROM commerces WHERE id_commerce = :id', [':id' => $idCommerce]);
if (!$commerce) { echo 'Comercio no encontrado.'; exit; }

$plan = $db->fetchOne('SELECT * FROM memberships WHERE id_membership = :id', [':id' => $commerce['id_membership']]);
$subscription = $db->fetchOne('SELECT * FROM subscriptions WHERE id_commerce = :c ORDER BY id_subscription DESC LIMIT 1', [':c' => $idCommerce]);

// Métricas
$totalAppts    = (int)$db->fetchValue('SELECT COUNT(*) FROM appointments WHERE id_commerce = :c', [':c' => $idCommerce]);
$pendingAppts  = (int)$db->fetchValue("SELECT COUNT(*) FROM appointments WHERE id_commerce = :c AND status='pending'", [':c' => $idCommerce]);
$totalClients  = (int)$db->fetchValue('SELECT COUNT(*) FROM clients WHERE id_commerce = :c', [':c' => $idCommerce]);
$totalServices = (int)$db->fetchValue("SELECT COUNT(*) FROM services WHERE id_commerce = :c AND estado='Activo'", [':c' => $idCommerce]);

$nextAppts = $db->fetchAll(
    "SELECT * FROM appointments WHERE id_commerce = :c
     AND date(fecha) >= date('now')
     ORDER BY fecha ASC, hora_inicio ASC
     LIMIT 20",
    [':c' => $idCommerce]
);

$pageTitle = 'Mi Panel';
$activeSection = '';
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Mi Panel · Agendarte</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="assets/css/admin.css">
</head>
<body>
<header class="topbar">
    <div class="topbar__brand">
        <a href="commerce_dashboard.php"><strong><?= htmlspecialchars($commerce['nombre'], ENT_QUOTES, 'UTF-8') ?></strong></a>
    </div>
    <nav class="topbar__nav">
        <a href="commerce_dashboard.php" class="is-active">Resumen</a>
        <a href="<?= htmlspecialchars(CommercePanel::urlForSlug((string)$commerce['slug']), ENT_QUOTES, 'UTF-8') ?>">Panel del negocio</a>
        <a href="commerce_appointments.php">Turnos</a>
        <a href="commerce_clients.php">Clientes</a>
        <a href="commerce_services.php">Servicios</a>
        <a href="commerce_plan.php">Mi Plan</a>
        <a href="commerce_settings.php">Configuración</a>
    </nav>
    <div class="topbar__user">
        <span class="topbar__hello"><?= htmlspecialchars(Auth::user()['nombre'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
        <a class="btn btn-ghost btn-sm" href="logout.php">Salir</a>
    </div>
</header>
<main class="admin-main">
    <section class="page-header">
        <h1>Hola, <?= htmlspecialchars(Auth::user()['nombre'] ?? 'dueño', ENT_QUOTES, 'UTF-8') ?></h1>
        <p>Acá ves el estado de tu negocio y tus próximas reservas.</p>
    </section>

    <?php
        $now = new DateTimeImmutable('now');
        $trialEnd = !empty($commerce['trial_expires_at']) ? new DateTimeImmutable((string)$commerce['trial_expires_at']) : null;
        $daysLeft = $trialEnd instanceof DateTimeImmutable ? (int)$now->diff($trialEnd)->format('%r%a') : null;
        $planName = $plan['nombre'] ?? 'Sin plan';
        $planPrice = $plan ? (float)$plan['precio'] : 0;
        $planCurrency = $plan['moneda'] ?? 'UYU';
        $status = (string)$commerce['status'];
        $statusLabel = match ($status) {
            'trial'     => 'En prueba',
            'active'    => 'Activo',
            'past_due'  => 'Pago pendiente',
            'cancelled' => 'Cancelado',
            'suspended' => 'Suspendido',
            default     => $status,
        };
        $showTrialBanner = $status === 'trial' && $daysLeft !== null && $daysLeft <= 7;
    ?>
    <article class="card" style="background:linear-gradient(135deg, var(--primary,#6366f1), var(--primary-2,#4f46e5));color:#fff;border:none;box-shadow:0 12px 32px rgba(99,102,241,.25);">
        <div style="display:grid;grid-template-columns:1fr auto;gap:1.5rem;align-items:center">
            <div>
                <div style="text-transform:uppercase;letter-spacing:.08em;font-size:.72rem;font-weight:700;opacity:.85;margin-bottom:.25rem">Tu plan</div>
                <h2 style="margin:0;font-size:1.6rem;font-weight:800"><?= htmlspecialchars($planName, ENT_QUOTES, 'UTF-8') ?></h2>
                <div style="font-size:1.8rem;font-weight:800;margin:.25rem 0">
                    <?php if ($planPrice > 0): ?>
                        $<?= number_format($planPrice, 0, ',', '.') ?>
                        <span style="font-size:.85rem;font-weight:500;opacity:.85">/ <?= htmlspecialchars($planCurrency, ENT_QUOTES, 'UTF-8') ?> cada <?= (int)($plan['duracion_dias'] ?? 30) ?> días</span>
                    <?php else: ?>
                        Gratis
                    <?php endif; ?>
                </div>
                <div style="font-size:.9rem;display:flex;flex-wrap:wrap;gap:1rem;margin-top:.5rem">
                    <span>Estado: <b><?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') ?></b></span>
                    <?php if ($trialEnd): ?>
                        <span>Trial hasta: <b><?= htmlspecialchars($trialEnd->format('d/m/Y'), ENT_QUOTES, 'UTF-8') ?></b></span>
                    <?php endif; ?>
                    <?php if ($daysLeft !== null && $daysLeft > 0 && $status === 'trial'): ?>
                        <span>Días restantes: <b><?= $daysLeft ?></b></span>
                    <?php endif; ?>
                </div>
                <?php if ($showTrialBanner): ?>
                    <div style="margin-top:.75rem;padding:.6rem .9rem;background:rgba(255,255,255,.15);border-radius:8px;font-size:.85rem">
                        ⏰ Tu período de prueba termina pronto. <a href="commerce_plan.php" style="color:#fff;text-decoration:underline;font-weight:700">Elegí un plan</a> para no perder tu cuenta.
                    </div>
                <?php endif; ?>
            </div>
            <div>
                <a href="commerce_plan.php" class="btn" style="background:#fff;color:var(--primary,#6366f1);font-weight:700">
                    <?= $status === 'trial' || $status === 'cancelled' ? 'Elegir plan' : 'Cambiar plan' ?>
                </a>
            </div>
        </div>
    </article>

    <div class="kpi-grid">
        <div class="kpi"><span class="kpi__label">Turnos totales</span><span class="kpi__value"><?= $totalAppts ?></span></div>
        <div class="kpi kpi--warn"><span class="kpi__label">Pendientes</span><span class="kpi__value"><?= $pendingAppts ?></span></div>
        <div class="kpi"><span class="kpi__label">Clientes</span><span class="kpi__value"><?= $totalClients ?></span></div>
        <div class="kpi"><span class="kpi__label">Servicios activos</span><span class="kpi__value"><?= $totalServices ?></span></div>
    </div>

    <article class="card">
        <h2>Administrá tu negocio</h2>
        <p>Abrí el panel completo para editar horarios, SEO, legal, tema, redes y el resto de la configuración que ven tus clientes.</p>
        <p>
            <a class="btn btn-primary" href="<?= htmlspecialchars(CommercePanel::urlForSlug((string)$commerce['slug']) . '#config', ENT_QUOTES, 'UTF-8') ?>">
                Ir al panel de configuración
            </a>
        </p>
    </article>

    <article class="card">
        <h2>Tu link público</h2>
        <p>Compartí este link para que tus clientes puedan reservar:</p>
        <p><code class="code"><?= htmlspecialchars(url($commerce['slug']), ENT_QUOTES, 'UTF-8') ?></code></p>
        <p><a href="<?= htmlspecialchars(url($commerce['slug']), ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">Ver sitio público</a></p>
    </article>

    <article class="card">
        <h2>Próximos turnos</h2>
        <?php if (empty($nextAppts)): ?>
            <p class="muted">Sin turnos próximos.</p>
        <?php else: ?>
            <table class="table">
                <thead><tr><th>Fecha</th><th>Hora</th><th>Cliente</th><th>Email</th><th>Teléfono</th><th>Status</th><th>Calendar</th></tr></thead>
                <tbody>
                <?php foreach ($nextAppts as $a): ?>
                    <tr>
                        <td><?= htmlspecialchars($a['fecha'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars(substr((string)$a['hora_inicio'], 0, 5), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($a['cliente_nombre'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($a['cliente_email'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($a['cliente_telefono'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                        <td><span class="badge badge--<?= htmlspecialchars($a['status'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($a['status'], ENT_QUOTES, 'UTF-8') ?></span></td>
                        <td><?= $a['google_event_id'] ? '<span class="muted">✓</span>' : '<span class="muted">—</span>' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </article>
</main>
</body>
</html>
