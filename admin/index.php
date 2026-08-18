<?php
/**
 * Agenduy - Super Admin: Overview / Resumen Global
 * Métricas clave, historial de notificaciones (WhatsApp/UltraMsg & Email) y auditoría.
 */
declare(strict_types=1);

$config = require __DIR__ . '/../src/Core/bootstrap.php';
use Agenduy\Core\Database;
use Agenduy\Core\Auth;
use Agenduy\Core\CommerceStorage;

Auth::start();
if (!Auth::check() || Auth::role() !== 'super_admin') {
    header('Location: ' . Auth::loginUrl());
    exit;
}

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

// Métricas de notificaciones
$totalNotifs      = (int)$db->fetchValue('SELECT COUNT(*) FROM notifications_log');
$whatsappNotifs   = (int)$db->fetchValue("SELECT COUNT(*) FROM notifications_log WHERE channel='whatsapp'");
$emailNotifs      = (int)$db->fetchValue("SELECT COUNT(*) FROM notifications_log WHERE channel='email'");
$failedNotifs     = (int)$db->fetchValue("SELECT COUNT(*) FROM notifications_log WHERE status='failed'");
$sentNotifs       = (int)$db->fetchValue("SELECT COUNT(*) FROM notifications_log WHERE status='sent'");

// Historial de Notificaciones (últimas 50)
$notifications = $db->fetchAll(
    "SELECT n.*, c.nombre AS comercio_nombre, c.slug AS comercio_slug
     FROM notifications_log n
     LEFT JOIN commerces c ON c.id_commerce = n.id_commerce
     ORDER BY n.id_notification DESC
     LIMIT 50"
);

// Comercios con trial por vencer (próximos 7 días)
$soonExpiring = $db->fetchAll(
    "SELECT id_commerce, slug, nombre, trial_expires_at, status
     FROM commerces
     WHERE status='trial'
       AND trial_expires_at IS NOT NULL
       AND date(trial_expires_at) BETWEEN date('now') AND date('now','+7 days')
     ORDER BY trial_expires_at ASC
     LIMIT 10"
);

// Últimos comercios registrados
$recentCommerces = $db->fetchAll(
    "SELECT c.id_commerce, c.slug, c.nombre, c.status, c.logo, c.created_at, r.nombre AS rubro_nombre
     FROM commerces c
     LEFT JOIN rubros r ON r.id_rubro = c.id_rubro
     ORDER BY c.created_at DESC
     LIMIT 10"
);

// Auditoría reciente
$recentAudit = $db->fetchAll('SELECT * FROM audit_log ORDER BY id_audit DESC LIMIT 15');

$pageTitle = 'Resumen Global';
$activeSection = 'overview';
require __DIR__ . '/partials/header.php';
?>

<div class="admin-main">

    <!-- Header Principal con Acciones Rápidas -->
    <header class="page-header" style="display:flex; flex-wrap:wrap; justify-content:space-between; align-items:center; gap:1rem; margin-bottom:1.5rem">
        <div>
            <h1 style="margin:0 0 0.25rem 0; font-size:1.75rem">Panel de Control Global</h1>
            <p class="muted" style="margin:0">Métricas clave en tiempo real, gestión de catálogo y estado del sistema.</p>
        </div>
        <div style="display:flex; flex-wrap:wrap; gap:0.5rem">
            <a href="commerce_products.php" class="btn btn-primary">
                📦 Catálogo de Productos
            </a>
            <a href="commerces.php?new=1" class="btn btn-ghost" style="border:1px solid var(--border)">
                ➕ Nuevo Comercio
            </a>
            <a href="config.php" class="btn btn-ghost" style="border:1px solid var(--border)">
                ⚙️ Configuración &amp; UltraMsg
            </a>
        </div>
    </header>

    <!-- KPIs del Sistema -->
    <div class="kpi-grid">
        <div class="kpi">
            <span class="kpi__label">Comercios Totales</span>
            <span class="kpi__value"><?= $totalCommerces ?></span>
            <span class="muted" style="font-size:0.75rem">Plataforma activa</span>
        </div>
        <div class="kpi kpi--ok">
            <span class="kpi__label">Comercios Activos</span>
            <span class="kpi__value"><?= $activeCommerces ?></span>
            <span class="muted" style="font-size:0.75rem">Con suscripción regular</span>
        </div>
        <div class="kpi kpi--trial">
            <span class="kpi__label">En Periodo de Prueba</span>
            <span class="kpi__value"><?= $trialCommerces ?></span>
            <span class="muted" style="font-size:0.75rem">Trial de 30 días</span>
        </div>
        <div class="kpi <?= $pastDueCommerces > 0 ? 'kpi--warn' : '' ?>">
            <span class="kpi__label">Pagos Vencidos</span>
            <span class="kpi__value" style="<?= $pastDueCommerces > 0 ? 'color:var(--warn)' : '' ?>"><?= $pastDueCommerces ?></span>
            <span class="muted" style="font-size:0.75rem">Requieren atención</span>
        </div>
        <div class="kpi kpi--ok">
            <span class="kpi__label">MRR Estimado</span>
            <span class="kpi__value">$<?= number_format($mrr, 0) ?></span>
            <span class="muted" style="font-size:0.75rem">Ingreso recurrente mensual</span>
        </div>
        <div class="kpi">
            <span class="kpi__label">Turnos Agendados</span>
            <span class="kpi__value"><?= $totalAppointments ?></span>
            <span class="muted" style="font-size:0.75rem">En todos los negocios</span>
        </div>
        <div class="kpi">
            <span class="kpi__label">Clientes Registrados</span>
            <span class="kpi__value"><?= $totalClients ?></span>
            <span class="muted" style="font-size:0.75rem">Usuarios finales</span>
        </div>
        <div class="kpi <?= $pendingTransfers > 0 ? 'kpi--warn' : '' ?>">
            <span class="kpi__label">Transferencias x Aprobar</span>
            <span class="kpi__value" style="<?= $pendingTransfers > 0 ? 'color:var(--warn)' : '' ?>"><?= $pendingTransfers ?></span>
            <span class="muted" style="font-size:0.75rem">
                <a href="payments.php" style="color:inherit; text-decoration:underline">Ver pagos pendientes →</a>
            </span>
        </div>
    </div>

    <!-- SECCIÓN: Historial de Notificaciones (UltraMsg WhatsApp & Email) -->
    <article class="card" style="margin-bottom:2rem; border-top:3px solid #25d366">
        <div style="display:flex; flex-wrap:wrap; justify-content:space-between; align-items:center; gap:1rem; margin-bottom:1.25rem; border-bottom:1px solid var(--border); padding-bottom:0.75rem">
            <div>
                <h2 style="margin:0 0 0.25rem 0; font-size:1.3rem; display:flex; align-items:center; gap:0.5rem">
                    <span>📱 Historial de Mensajes &amp; Notificaciones</span>
                </h2>
                <p class="muted" style="margin:0; font-size:0.85rem">
                    Registro de envíos realizados por WhatsApp (UltraMsg) y correos electrónicos (SMTP).
                </p>
            </div>

            <!-- Filtros de Pestañas -->
            <div style="display:flex; flex-wrap:wrap; gap:0.35rem" id="notif-filters">
                <button type="button" class="btn btn-sm is-active" onclick="filterNotifs('all', this)" style="font-weight:600">
                    Todos (<?= count($notifications) ?>)
                </button>
                <button type="button" class="btn btn-sm" onclick="filterNotifs('whatsapp', this)">
                    <span style="color:#25d366">📱 WhatsApp</span> (<?= $whatsappNotifs ?>)
                </button>
                <button type="button" class="btn btn-sm" onclick="filterNotifs('email', this)">
                    <span style="color:#38bdf8">✉️ Email</span> (<?= $emailNotifs ?>)
                </button>
                <button type="button" class="btn btn-sm" onclick="filterNotifs('sent', this)">
                    <span style="color:var(--ok)">✅ Enviados</span> (<?= $sentNotifs ?>)
                </button>
                <button type="button" class="btn btn-sm" onclick="filterNotifs('failed', this)">
                    <span style="color:var(--danger)">❌ Fallidos</span> (<?= $failedNotifs ?>)
                </button>
            </div>
        </div>

        <?php if (empty($notifications)): ?>
            <div style="text-align:center; padding:2.5rem 1rem; color:var(--muted)">
                <div style="font-size:2.5rem; margin-bottom:0.5rem">📬</div>
                <h3 style="margin:0 0 0.25rem 0; color:var(--text)">Sin registros de notificaciones</h3>
                <p style="margin:0; font-size:0.85rem">Aún no se han registrado envíos de WhatsApp ni emails en la plataforma.</p>
                <a href="config.php" class="btn btn-sm btn-primary" style="margin-top:1rem">Configurar y Probar UltraMsg / SMTP</a>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table" id="notif-table" style="font-size:0.88rem">
                    <thead>
                        <tr>
                            <th style="width:110px">Canal</th>
                            <th>Destinatario</th>
                            <th>Comercio</th>
                            <th>Mensaje / Asunto</th>
                            <th>Estado</th>
                            <th>Fecha</th>
                            <th style="text-align:right">Detalle</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($notifications as $n): ?>
                            <?php
                            $isWa = $n['channel'] === 'whatsapp';
                            $isSent = $n['status'] === 'sent';
                            $bodySnippet = !empty($n['body']) ? mb_substr(strip_tags((string)$n['body']), 0, 80, 'UTF-8') : (string)($n['subject'] ?? '—');
                            if (mb_strlen((string)$n['body'], 'UTF-8') > 80) $bodySnippet .= '...';
                            
                            $cleanPhone = $isWa ? preg_replace('/\D+/', '', (string)$n['recipient']) : '';
                            ?>
                            <tr class="notif-row" data-channel="<?= htmlspecialchars($n['channel'], ENT_QUOTES, 'UTF-8') ?>" data-status="<?= htmlspecialchars($n['status'], ENT_QUOTES, 'UTF-8') ?>">
                                <td>
                                    <?php if ($isWa): ?>
                                        <span class="badge" style="background:rgba(37,211,102,0.15); color:#25d366; border-color:rgba(37,211,102,0.4)">
                                            📱 WhatsApp
                                        </span>
                                    <?php else: ?>
                                        <span class="badge" style="background:rgba(56,189,248,0.15); color:#38bdf8; border-color:rgba(56,189,248,0.4)">
                                            ✉️ Email
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($isWa && $cleanPhone !== ''): ?>
                                        <a href="https://wa.me/<?= $cleanPhone ?>" target="_blank" rel="noopener" style="font-weight:600; color:var(--text)" title="Abrir chat en WhatsApp Web">
                                            <?= htmlspecialchars($n['recipient'], ENT_QUOTES, 'UTF-8') ?> ↗
                                        </a>
                                    <?php elseif (!$isWa): ?>
                                        <a href="mailto:<?= htmlspecialchars($n['recipient'], ENT_QUOTES, 'UTF-8') ?>" style="font-weight:600; color:var(--text)">
                                            <?= htmlspecialchars($n['recipient'], ENT_QUOTES, 'UTF-8') ?>
                                        </a>
                                    <?php else: ?>
                                        <strong><?= htmlspecialchars($n['recipient'], ENT_QUOTES, 'UTF-8') ?></strong>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($n['comercio_nombre'])): ?>
                                        <a href="commerces.php?id=<?= (int)$n['id_commerce'] ?>">
                                            <?= htmlspecialchars((string)$n['comercio_nombre'], ENT_QUOTES, 'UTF-8') ?>
                                        </a>
                                    <?php else: ?>
                                        <span class="muted">Plataforma / Sistema</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div style="max-width:280px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap" title="<?= htmlspecialchars((string)($n['body'] ?: $n['subject']), ENT_QUOTES, 'UTF-8') ?>">
                                        <?= htmlspecialchars($bodySnippet, ENT_QUOTES, 'UTF-8') ?>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($isSent): ?>
                                        <span class="badge badge--active">Enviado</span>
                                    <?php elseif ($n['status'] === 'failed'): ?>
                                        <span class="badge badge--cancelled" title="<?= htmlspecialchars((string)($n['error_message'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">Fallido</span>
                                    <?php else: ?>
                                        <span class="badge badge--trial"><?= htmlspecialchars((string)$n['status'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php endif; ?>
                                </td>
                                <td style="white-space:nowrap; color:var(--muted); font-size:0.82rem">
                                    <?= htmlspecialchars((string)($n['sent_at'] ?: $n['created_at']), ENT_QUOTES, 'UTF-8') ?>
                                </td>
                                <td style="text-align:right; white-space:nowrap">
                                    <button type="button" class="btn btn-sm btn-ghost" onclick='verDetalleNotif(<?= json_encode($n, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)' title="Ver mensaje completo">
                                        🔍 Ver
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </article>

    <!-- Layout 2 Columnas: Trials por Vencer & Últimos Registrados -->
    <div class="two-col" style="margin-bottom:2rem">
        <!-- Trials por Vencer -->
        <article class="card">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem">
                <h2 style="margin:0; font-size:1.15rem">⏰ Trials por Vencer (7 días)</h2>
                <a href="commerces.php?status=trial" class="btn btn-sm btn-ghost">Ver todos</a>
            </div>
            <?php if (empty($soonExpiring)): ?>
                <p class="muted" style="font-size:0.9rem">No hay comercios con periodo de prueba por vencer en los próximos 7 días.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table" style="font-size:0.88rem">
                        <thead><tr><th>Comercio</th><th>Vence</th><th>Acción</th></tr></thead>
                        <tbody>
                        <?php foreach ($soonExpiring as $c): ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($c['nombre'], ENT_QUOTES, 'UTF-8') ?></strong><br>
                                    <code class="code"><?= htmlspecialchars($c['slug'], ENT_QUOTES, 'UTF-8') ?></code>
                                </td>
                                <td>
                                    <span style="color:var(--warn); font-weight:600"><?= htmlspecialchars($c['trial_expires_at'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
                                </td>
                                <td>
                                    <a class="btn btn-sm" href="commerces.php?id=<?= (int)$c['id_commerce'] ?>">Gestionar</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </article>

        <!-- Últimos Comercios Registrados -->
        <article class="card">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem">
                <h2 style="margin:0; font-size:1.15rem">🏢 Últimos Comercios Registrados</h2>
                <a href="commerces.php" class="btn btn-sm btn-ghost">Ver todos (<?= $totalCommerces ?>)</a>
            </div>
            <div class="table-wrap">
                <table class="table" style="font-size:0.88rem">
                    <thead><tr><th>Comercio</th><th>Rubro</th><th>Estado</th><th>Acciones</th></tr></thead>
                    <tbody>
                    <?php foreach ($recentCommerces as $c): ?>
                        <tr>
                            <td>
                                <div style="display:flex; align-items:center; gap:0.6rem">
                                    <?php
                                    $cLogo = trim((string)($c['logo'] ?? ''));
                                    $cLogoUrl = '';
                                    if ($cLogo !== '') {
                                        $cLogoUrl = CommerceStorage::publicUrl((int)$c['id_commerce'], (string)$c['slug'], $cLogo);
                                        if ($cLogoUrl === '' && !preg_match('#^https?://#i', $cLogo)) $cLogoUrl = url($cLogo);
                                    }
                                    ?>
                                    <?php if ($cLogoUrl !== ''): ?>
                                        <img src="<?= htmlspecialchars($cLogoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="" style="width:30px; height:30px; object-fit:contain; background:#fff; border-radius:6px; padding:2px">
                                    <?php endif; ?>
                                    <div>
                                        <a href="commerces.php?id=<?= (int)$c['id_commerce'] ?>" style="font-weight:600">
                                            <?= htmlspecialchars($c['nombre'], ENT_QUOTES, 'UTF-8') ?>
                                        </a><br>
                                        <code class="code"><?= htmlspecialchars($c['slug'], ENT_QUOTES, 'UTF-8') ?></code>
                                    </div>
                                </div>
                            </td>
                            <td><span class="muted"><?= htmlspecialchars($c['rubro_nombre'] ?? '—', ENT_QUOTES, 'UTF-8') ?></span></td>
                            <td><span class="badge badge--<?= htmlspecialchars($c['status'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($c['status'], ENT_QUOTES, 'UTF-8') ?></span></td>
                            <td style="white-space:nowrap">
                                <a class="btn btn-sm btn-primary" href="commerce_products.php?id_commerce=<?= (int)$c['id_commerce'] ?>" title="Productos">📦</a>
                                <a class="btn btn-sm" href="<?= htmlspecialchars(url($c['slug']), ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener" title="Web con lápiz">✏️</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </article>
    </div>

    <!-- SECCIÓN: Auditoría Reciente de la Plataforma -->
    <article class="card">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem">
            <div>
                <h2 style="margin:0 0 0.2rem 0; font-size:1.15rem">🛡️ Registro de Auditoría Reciente</h2>
                <p class="muted" style="margin:0; font-size:0.85rem">Operaciones y cambios de configuración realizados por administradores.</p>
            </div>
            <a href="config.php" class="btn btn-sm btn-ghost">Configuración global</a>
        </div>
        <div class="table-wrap">
            <table class="table" style="font-size:0.85rem">
                <thead>
                    <tr><th>Fecha y Hora</th><th>Acción</th><th>Objetivo</th><th>Usuario</th><th>Dirección IP</th></tr>
                </thead>
                <tbody>
                <?php foreach ($recentAudit as $row): ?>
                    <tr>
                        <td style="white-space:nowrap; color:var(--muted)"><?= htmlspecialchars($row['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><code class="code" style="color:#7c3aed; font-weight:600"><?= htmlspecialchars($row['action'], ENT_QUOTES, 'UTF-8') ?></code></td>
                        <td><?= htmlspecialchars($row['target_type'] . ' #' . ($row['target_id'] ?: '0'), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= $row['id_user'] ? '#' . (int)$row['id_user'] : '—' ?></td>
                        <td><code class="code"><?= htmlspecialchars($row['ip'] ?? '', ENT_QUOTES, 'UTF-8') ?></code></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </article>

</div>

<!-- Modal Detalle de Notificación -->
<div id="modal-notif-detalle" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.75); z-index:9999; align-items:center; justify-content:center; padding:1rem">
    <div style="background:var(--surface, #161b22); border:1px solid var(--border, #2a313c); border-radius:14px; width:100%; max-width:600px; padding:1.5rem; max-height:90vh; overflow-y:auto; box-shadow:var(--shadow)">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem; border-bottom:1px solid var(--border); padding-bottom:0.75rem">
            <h3 style="margin:0; font-size:1.2rem" id="m-notif-title">Detalle de la Notificación</h3>
            <button type="button" onclick="cerrarModalNotif()" class="btn btn-sm btn-ghost" style="font-size:1.2rem; line-height:1">✕</button>
        </div>

        <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.75rem; margin-bottom:1rem; font-size:0.88rem">
            <div>
                <span class="muted">Canal:</span>
                <div id="m-notif-channel" style="font-weight:600"></div>
            </div>
            <div>
                <span class="muted">Estado:</span>
                <div id="m-notif-status"></div>
            </div>
            <div>
                <span class="muted">Destinatario:</span>
                <div id="m-notif-recipient" style="font-weight:600"></div>
            </div>
            <div>
                <span class="muted">Fecha de envío:</span>
                <div id="m-notif-date" class="muted"></div>
            </div>
            <div style="grid-column:span 2">
                <span class="muted">Asunto:</span>
                <div id="m-notif-subject" style="font-weight:600"></div>
            </div>
        </div>

        <div style="margin-bottom:1rem">
            <span class="muted" style="font-size:0.85rem">Contenido del mensaje:</span>
            <div id="m-notif-body" style="margin-top:0.35rem; padding:0.85rem; background:var(--surface-2, #1f2530); border-radius:8px; border:1px solid var(--border); font-size:0.9rem; line-height:1.5; white-space:pre-wrap; max-height:220px; overflow-y:auto"></div>
        </div>

        <div id="m-notif-error-container" style="display:none; margin-bottom:1rem; padding:0.75rem; background:rgba(220,38,38,0.1); border:1px solid rgba(220,38,38,0.3); border-radius:8px; color:#fca5a5; font-size:0.85rem">
            <strong>Error reportado:</strong>
            <div id="m-notif-error" style="margin-top:0.25rem"></div>
        </div>

        <div style="display:flex; justify-content:flex-end">
            <button type="button" onclick="cerrarModalNotif()" class="btn btn-primary">Cerrar</button>
        </div>
    </div>
</div>

<script>
function filterNotifs(filter, btn) {
    var buttons = document.querySelectorAll('#notif-filters button');
    buttons.forEach(function(b) { b.classList.remove('is-active'); });
    if (btn) btn.classList.add('is-active');

    var rows = document.querySelectorAll('.notif-row');
    rows.forEach(function(row) {
        var ch = row.getAttribute('data-channel');
        var st = row.getAttribute('data-status');
        if (filter === 'all') {
            row.style.display = '';
        } else if (filter === 'whatsapp') {
            row.style.display = (ch === 'whatsapp') ? '' : 'none';
        } else if (filter === 'email') {
            row.style.display = (ch === 'email') ? '' : 'none';
        } else if (filter === 'sent') {
            row.style.display = (st === 'sent') ? '' : 'none';
        } else if (filter === 'failed') {
            row.style.display = (st === 'failed') ? '' : 'none';
        }
    });
}

function verDetalleNotif(notif) {
    var modal = document.getElementById('modal-notif-detalle');
    if (!modal || !notif) return;

    var isWa = notif.channel === 'whatsapp';
    document.getElementById('m-notif-channel').innerHTML = isWa
        ? '<span style="color:#25d366">📱 WhatsApp (UltraMsg)</span>'
        : '<span style="color:#38bdf8">✉️ Email (SMTP)</span>';

    var statusHtml = (notif.status === 'sent')
        ? '<span class="badge badge--active">✅ Enviado</span>'
        : (notif.status === 'failed')
            ? '<span class="badge badge--cancelled">❌ Fallido</span>'
            : '<span class="badge badge--trial">' + (notif.status || 'En cola') + '</span>';
    document.getElementById('m-notif-status').innerHTML = statusHtml;

    document.getElementById('m-notif-recipient').textContent = notif.recipient || '—';
    document.getElementById('m-notif-date').textContent = notif.sent_at || notif.created_at || '—';
    document.getElementById('m-notif-subject').textContent = notif.subject || 'Sin asunto';
    document.getElementById('m-notif-body').textContent = notif.body || 'Sin contenido de texto.';

    var errContainer = document.getElementById('m-notif-error-container');
    var errText = document.getElementById('m-notif-error');
    if (notif.error_message) {
        errText.textContent = notif.error_message;
        errContainer.style.display = 'block';
    } else {
        errContainer.style.display = 'none';
    }

    modal.style.display = 'flex';
}

function cerrarModalNotif() {
    var modal = document.getElementById('modal-notif-detalle');
    if (modal) modal.style.display = 'none';
}
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
