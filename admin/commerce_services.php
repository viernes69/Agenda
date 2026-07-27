<?php
/**
 * Agenduy - Commerce · Services
 */
declare(strict_types=1);

$config = require __DIR__ . '/../src/Core/bootstrap.php';

use Agenduy\Core\Auth;
use Agenduy\Core\CSRF;
use Agenduy\Core\Database;
use Agenduy\Core\Security;

Auth::start();
if (!Auth::check() || Auth::role() !== 'commerce_admin') { header('Location: ' . Auth::loginUrl()); exit; }
Security::sendNoStoreHeaders();
$idCommerce = (int)Auth::commerceId();

$db = Database::getInstance();
$flash = ['type' => '', 'msg' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::checkRequest('commerce_services');
    $action = $_POST['action'] ?? '';
    if ($action === 'save') {
        $id = (int)($_POST['id_service'] ?? 0);
        $data = [
            'nombre'      => trim((string)($_POST['nombre'] ?? '')),
            'descripcion' => trim((string)($_POST['descripcion'] ?? '')),
            'duracion_min'=> max(5, (int)($_POST['duracion_min'] ?? 30)),
            'precio'      => (float)($_POST['precio'] ?? 0),
            'estado'      => in_array($_POST['estado'] ?? '', ['Activo','Inactivo'], true) ? $_POST['estado'] : 'Activo',
            'updated_at'  => date('Y-m-d H:i:s'),
        ];
        if ($data['nombre'] === '') {
            $flash = ['type' => 'error', 'msg' => 'Falta el nombre.'];
        } else {
            if ($id > 0) {
                $db->update('services', $data, 'id_service = :a AND id_commerce = :c', [':a' => $id, ':c' => $idCommerce]);
                $flash = ['type' => 'ok', 'msg' => 'Servicio actualizado.'];
            } else {
                $data['id_commerce'] = $idCommerce;
                $db->insert('services', $data);
                $flash = ['type' => 'ok', 'msg' => 'Servicio creado.'];
            }
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id_service'] ?? 0);
        $db->delete('services', 'id_service = :a AND id_commerce = :c', [':a' => $id, ':c' => $idCommerce]);
        $flash = ['type' => 'ok', 'msg' => 'Servicio eliminado.'];
    }
}

$services = $db->fetchAll('SELECT * FROM services WHERE id_commerce = :c ORDER BY nombre', [':c' => $idCommerce]);
$edit = null;
if (isset($_GET['id'])) {
    $edit = $db->fetchOne('SELECT * FROM services WHERE id_service = :a AND id_commerce = :c', [':a' => (int)$_GET['id'], ':c' => $idCommerce]);
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Servicios · Agendarte</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="assets/css/admin.css">
</head>
<body>
<header class="topbar">
    <div class="topbar__brand"><a href="commerce_dashboard.php"><strong>Agendarte</strong></a></div>
    <nav class="topbar__nav">
        <a href="commerce_dashboard.php">Resumen</a>
        <a href="commerce_appointments.php">Turnos</a>
        <a href="commerce_clients.php">Clientes</a>
        <a href="commerce_services.php" class="is-active">Servicios</a>
        <a href="commerce_plan.php">Mi Plan</a>
        <a href="commerce_settings.php">Configuración</a>
    </nav>
    <div class="topbar__user">
        <a class="btn btn-ghost btn-sm" href="logout.php">Salir</a>
    </div>
</header>
<main class="admin-main">
    <section class="page-header"><h1>Servicios</h1></section>
    <?php if ($flash['msg']): ?>
        <div class="alert alert-<?= $flash['type'] === 'error' ? 'error' : 'ok' ?>"><?= htmlspecialchars($flash['msg'], ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <article class="card">
        <h2><?= $edit ? 'Editar servicio' : 'Nuevo servicio' ?></h2>
        <form method="post">
            <?= CSRF::field('commerce_services') ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id_service" value="<?= (int)($edit['id_service'] ?? 0) ?>">
            <div class="form-grid">
                <div class="field">
                    <label>Nombre</label>
                    <input type="text" name="nombre" required value="<?= htmlspecialchars($edit['nombre'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="field">
                    <label>Duración (min)</label>
                    <input type="number" name="duracion_min" min="5" value="<?= (int)($edit['duracion_min'] ?? 30) ?>">
                </div>
                <div class="field">
                    <label>Precio</label>
                    <input type="number" name="precio" min="0" step="0.01" value="<?= htmlspecialchars((string)($edit['precio'] ?? '0'), ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="field">
                    <label>Estado</label>
                    <select name="estado">
                        <option value="Activo" <?= ($edit['estado'] ?? 'Activo') === 'Activo' ? 'selected' : '' ?>>Activo</option>
                        <option value="Inactivo" <?= ($edit['estado'] ?? '') === 'Inactivo' ? 'selected' : '' ?>>Inactivo</option>
                    </select>
                </div>
                <div class="field col-2">
                    <label>Descripción</label>
                    <textarea name="descripcion"><?= htmlspecialchars($edit['descripcion'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                </div>
            </div>
            <div class="actions">
                <button class="btn btn-primary" type="submit"><?= $edit ? 'Guardar' : 'Crear' ?></button>
                <?php if ($edit): ?><a class="btn" href="commerce_services.php">Cancelar</a><?php endif; ?>
            </div>
        </form>
    </article>

    <article class="card">
        <h2>Listado</h2>
        <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Nombre</th><th>Duración</th><th>Precio</th><th>Estado</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($services as $s): ?>
                <tr>
                    <td><?= htmlspecialchars($s['nombre'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= (int)$s['duracion_min'] ?> min</td>
                    <td><?= number_format((float)$s['precio'], 2) ?></td>
                    <td><span class="badge badge--<?= htmlspecialchars($s['estado'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($s['estado'], ENT_QUOTES, 'UTF-8') ?></span></td>
                    <td>
                        <a class="btn btn-sm" href="commerce_services.php?id=<?= (int)$s['id_service'] ?>">editar</a>
                        <form method="post" style="display:inline" onsubmit="return confirm('¿Eliminar?');">
                            <?= CSRF::field('commerce_services') ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id_service" value="<?= (int)$s['id_service'] ?>">
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
