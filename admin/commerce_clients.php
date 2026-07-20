<?php
/**
 * Agenduy - Commerce · Clients
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
    CSRF::checkRequest('commerce_clients');
    $action = $_POST['action'] ?? '';
    if ($action === 'delete') {
        $id = (int)($_POST['id_client'] ?? 0);
        $db->delete('clients', 'id_client = :a AND id_commerce = :c', [':a' => $id, ':c' => $idCommerce]);
        $flash = ['type' => 'ok', 'msg' => 'Cliente eliminado.'];
    }
}

$clients = $db->fetchAll(
    'SELECT * FROM clients WHERE id_commerce = :c ORDER BY created_at DESC LIMIT 500',
    [':c' => $idCommerce]
);
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Clientes · Agendarte</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="assets/css/admin.css">
</head>
<body>
<header class="topbar">
    <div class="topbar__brand"><a href="commerce_dashboard.php"><strong>Agendarte</strong></a></div>
    <nav class="topbar__nav">
        <a href="commerce_dashboard.php">Resumen</a>
        <a href="commerce_appointments.php">Turnos</a>
        <a href="commerce_clients.php" class="is-active">Clientes</a>
        <a href="commerce_services.php">Servicios</a>
        <a href="commerce_settings.php">Configuración</a>
    </nav>
    <div class="topbar__user">
        <a class="btn btn-ghost btn-sm" href="logout.php">Salir</a>
    </div>
</header>
<main class="admin-main">
    <section class="page-header"><h1>Clientes</h1></section>
    <?php if ($flash['msg']): ?>
        <div class="alert alert-<?= $flash['type'] === 'error' ? 'error' : 'ok' ?>"><?= htmlspecialchars($flash['msg'], ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <article class="card">
        <h2><?= count($clients) ?> clientes</h2>
        <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Nombre</th><th>Apellido</th><th>Email</th><th>Teléfono</th><th>Cédula</th><th>Visitas</th><th>Última visita</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($clients as $c): ?>
                <tr>
                    <td><?= htmlspecialchars($c['nombre'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($c['apellido'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($c['email'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($c['telefono'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($c['cedula'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= (int)$c['total_visits'] ?></td>
                    <td><?= htmlspecialchars($c['last_visit_at'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                    <td>
                        <form method="post" style="display:inline" onsubmit="return confirm('¿Eliminar?');">
                            <?= CSRF::field('commerce_clients') ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id_client" value="<?= (int)$c['id_client'] ?>">
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
