<?php
/**
 * Agenduy - Commerce · Appointments (gestión de turnos)
 */
declare(strict_types=1);

$config = require __DIR__ . '/../src/Core/bootstrap.php';

use Agenduy\Core\Auth;
use Agenduy\Core\CSRF;
use Agenduy\Core\Database;

Auth::start();
if (!Auth::check() || Auth::role() !== 'commerce_admin') { header('Location: login.php'); exit; }
$idCommerce = (int)Auth::commerceId();

$db = Database::getInstance();
$flash = ['type' => '', 'msg' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::checkRequest('commerce_appts');
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id_appointment'] ?? 0);
    if ($action === 'confirm') {
        $db->update('appointments', ['status' => 'confirmed', 'updated_at' => date('Y-m-d H:i:s')],
            'id_appointment = :a AND id_commerce = :c', [':a' => $id, ':c' => $idCommerce]);
        $flash = ['type' => 'ok', 'msg' => 'Turno confirmado.'];
    } elseif ($action === 'cancel') {
        $db->update('appointments', ['status' => 'cancelled', 'updated_at' => date('Y-m-d H:i:s')],
            'id_appointment = :a AND id_commerce = :c', [':a' => $id, ':c' => $idCommerce]);
        $flash = ['type' => 'ok', 'msg' => 'Turno cancelado.'];
    } elseif ($action === 'done') {
        $db->update('appointments', ['status' => 'done', 'updated_at' => date('Y-m-d H:i:s')],
            'id_appointment = :a AND id_commerce = :c', [':a' => $id, ':c' => $idCommerce]);
        $flash = ['type' => 'ok', 'msg' => 'Turno marcado como atendido.'];
    } elseif ($action === 'delete') {
        $db->delete('appointments', 'id_appointment = :a AND id_commerce = :c', [':a' => $id, ':c' => $idCommerce]);
        $flash = ['type' => 'ok', 'msg' => 'Turno eliminado.'];
    } elseif ($action === 'create') {
        $fecha = trim((string)($_POST['fecha'] ?? ''));
        $hora  = trim((string)($_POST['hora_inicio'] ?? ''));
        $nombre= trim((string)($_POST['cliente_nombre'] ?? ''));
        $email = trim((string)($_POST['cliente_email'] ?? ''));
        $tel   = trim((string)($_POST['cliente_telefono'] ?? ''));
        $notas = trim((string)($_POST['notas'] ?? ''));
        if ($fecha === '' || $hora === '' || $nombre === '') {
            $flash = ['type' => 'error', 'msg' => 'Faltan datos.'];
        } else {
            $idAppt = (int)$db->insert('appointments', [
                'id_commerce'      => $idCommerce,
                'fecha'            => $fecha,
                'hora_inicio'      => $hora,
                'hora_fin'         => date('H:i:s', strtotime($hora . ' +30 minutes')),
                'cliente_nombre'   => $nombre,
                'cliente_email'    => $email,
                'cliente_telefono' => $tel,
                'notas'            => $notas,
                'status'           => 'pending',
            ]);
            // Best-effort google calendar
            @file_get_contents(((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '') . dirname($_SERVER['SCRIPT_NAME']) . '/api/google_calendar.php?action=create_event', false, stream_context_create([
                'http' => [
                    'method'  => 'POST',
                    'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
                    'content' => http_build_query(['id_commerce' => $idCommerce, 'id_appointment' => $idAppt]),
                    'timeout' => 5,
                ]
            ]));
            $flash = ['type' => 'ok', 'msg' => 'Turno creado.'];
        }
    }
}

$status = (string)($_GET['status'] ?? '');
$where = 'id_commerce = :c';
$params = [':c' => $idCommerce];
if (in_array($status, ['pending','confirmed','done','cancelled','no_show'], true)) {
    $where .= ' AND status = :s';
    $params[':s'] = $status;
}
$appts = $db->fetchAll(
    "SELECT * FROM appointments WHERE $where ORDER BY fecha DESC, hora_inicio DESC LIMIT 300",
    $params
);
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Turnos · Agendarte</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="assets/css/admin.css">
</head>
<body>
<header class="topbar">
    <div class="topbar__brand"><a href="commerce_dashboard.php"><strong>Agendarte</strong></a></div>
    <nav class="topbar__nav">
        <a href="commerce_dashboard.php">Resumen</a>
        <a href="commerce_appointments.php" class="is-active">Turnos</a>
        <a href="commerce_clients.php">Clientes</a>
        <a href="commerce_services.php">Servicios</a>
        <a href="commerce_settings.php">Configuración</a>
    </nav>
    <div class="topbar__user">
        <a class="btn btn-ghost btn-sm" href="logout.php">Salir</a>
    </div>
</header>
<main class="admin-main">
    <section class="page-header">
        <h1>Turnos</h1>
        <p>Gestioná todas las reservas de tu negocio.</p>
    </section>

    <?php if ($flash['msg']): ?>
        <div class="alert alert-<?= $flash['type'] === 'error' ? 'error' : 'ok' ?>"><?= htmlspecialchars($flash['msg'], ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <article class="card">
        <h2>Cargar turno manualmente</h2>
        <form method="post">
            <?= CSRF::field('commerce_appts') ?>
            <input type="hidden" name="action" value="create">
            <div class="form-grid">
                <div class="field">
                    <label>Cliente</label>
                    <input type="text" name="cliente_nombre" required>
                </div>
                <div class="field">
                    <label>Email</label>
                    <input type="email" name="cliente_email">
                </div>
                <div class="field">
                    <label>Teléfono</label>
                    <input type="text" name="cliente_telefono">
                </div>
                <div class="field">
                    <label>Fecha</label>
                    <input type="date" name="fecha" required>
                </div>
                <div class="field">
                    <label>Hora</label>
                    <input type="time" name="hora_inicio" required>
                </div>
                <div class="field col-2">
                    <label>Notas</label>
                    <textarea name="notas"></textarea>
                </div>
            </div>
            <div class="actions">
                <button class="btn btn-primary" type="submit">Crear turno</button>
            </div>
        </form>
    </article>

    <form class="card" method="get">
        <div class="form-grid">
            <div class="field">
                <label>Status</label>
                <select name="status">
                    <option value="">Todos</option>
                    <?php foreach (['pending','confirmed','done','cancelled','no_show'] as $opt): ?>
                        <option value="<?= $opt ?>" <?= $status === $opt ? 'selected' : '' ?>><?= $opt ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="actions" style="align-self:end">
                <button class="btn btn-primary" type="submit">Filtrar</button>
                <a class="btn" href="commerce_appointments.php">Limpiar</a>
            </div>
        </div>
    </form>

    <article class="card">
        <h2><?= count($appts) ?> turnos</h2>
        <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Fecha</th><th>Hora</th><th>Cliente</th><th>Email</th>
                    <th>Teléfono</th><th>Status</th><th>Calendar</th><th>Acciones</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($appts as $a): ?>
                <tr>
                    <td><?= htmlspecialchars($a['fecha'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars(substr((string)$a['hora_inicio'], 0, 5), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($a['cliente_nombre'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($a['cliente_email'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($a['cliente_telefono'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><span class="badge badge--<?= htmlspecialchars($a['status'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($a['status'], ENT_QUOTES, 'UTF-8') ?></span></td>
                    <td><?= $a['google_event_id'] ? '✓' : '—' ?></td>
                    <td>
                        <?php if ($a['status'] === 'pending'): ?>
                            <form method="post" style="display:inline">
                                <?= CSRF::field('commerce_appts') ?>
                                <input type="hidden" name="action" value="confirm">
                                <input type="hidden" name="id_appointment" value="<?= (int)$a['id_appointment'] ?>">
                                <button class="btn btn-sm btn-ok" type="submit">Confirmar</button>
                            </form>
                        <?php endif; ?>
                        <?php if ($a['status'] !== 'done' && $a['status'] !== 'cancelled'): ?>
                            <form method="post" style="display:inline">
                                <?= CSRF::field('commerce_appts') ?>
                                <input type="hidden" name="action" value="done">
                                <input type="hidden" name="id_appointment" value="<?= (int)$a['id_appointment'] ?>">
                                <button class="btn btn-sm" type="submit">Atendido</button>
                            </form>
                            <form method="post" style="display:inline">
                                <?= CSRF::field('commerce_appts') ?>
                                <input type="hidden" name="action" value="cancel">
                                <input type="hidden" name="id_appointment" value="<?= (int)$a['id_appointment'] ?>">
                                <button class="btn btn-sm btn-warn" type="submit">Cancelar</button>
                            </form>
                        <?php endif; ?>
                        <form method="post" style="display:inline" onsubmit="return confirm('¿Eliminar?');">
                            <?= CSRF::field('commerce_appts') ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id_appointment" value="<?= (int)$a['id_appointment'] ?>">
                            <button class="btn btn-sm btn-danger" type="submit">×</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </article>
</main>
</body>
</html>
