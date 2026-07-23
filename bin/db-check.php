<?php
/**
 * Diagnóstico de base de datos central.
 *
 * USO:
 *   CLI:    php bin/db-check.php
 *   Web:    https://agendarte.oficiosya.net/data/bin/db-check.php?token=...
 *
 * Requiere token de seguridad (mismo que migrate-db.php).
 */

define('CHECK_TOKEN', getenv('MIGRATE_TOKEN') ?: 'cambiar-en-produccion');

$isCLI = php_sapi_name() === 'cli';
$br = $isCLI ? "\n" : "<br>\n";

if (!$isCLI) {
    $reqToken = $_GET['token'] ?? '';
    if ($reqToken !== CHECK_TOKEN) {
        http_response_code(403);
        echo "Acceso denegado.$br";
        exit;
    }
}

$dbPath = __DIR__ . '/../storage/agenduy.db';
if (!file_exists($dbPath)) {
    echo "❌ Base de datos no encontrada en: $dbPath$br";
    exit(1);
}

$db = new PDO('sqlite:' . $dbPath);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

echo ($isCLI ? '' : '<pre>');

echo "=== COMMERCES ===" . $br;
$commerces = $db->query('SELECT id_commerce, slug, nombre FROM commerces ORDER BY id_commerce')->fetchAll();
foreach ($commerces as $c) {
    echo "  #{$c['id_commerce']} '{$c['slug']}' - {$c['nombre']}" . $br;
}

echo $br . "=== COMMERCE SETTINGS: funciones ===" . $br;
foreach ($commerces as $c) {
    $row = $db->prepare("SELECT config_json FROM commerce_settings WHERE id_commerce = ? AND section = 'funciones' LIMIT 1");
    $row->execute([$c['id_commerce']]);
    $val = $row->fetchColumn();
    if ($val) {
        $data = json_decode($val, true);
        $tipo = $data['tipo_comercio'] ?? 'NO TIENE tipo_comercio';
        echo "  ✅ #{$c['id_commerce']} '{$c['slug']}': tipo_comercio='{$tipo}'" . $br;
    } else {
        echo "  ❌ #{$c['id_commerce']} '{$c['slug']}': NO TIENE registro 'funciones'" . $br;
    }
}

echo $br . "=== SERVICES en BD central ===" . $br;
foreach ($commerces as $c) {
    $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM services WHERE id_commerce = ?");
    $stmt->execute([$c['id_commerce']]);
    $cnt = $stmt->fetchColumn();
    echo "  '{$c['slug']}' (id={$c['id_commerce']}): {$cnt} servicios en central" . $br;
    if ($cnt > 0) {
        $svcs = $db->prepare("SELECT id_service, nombre, precio, estado FROM services WHERE id_commerce = ?");
        $svcs->execute([$c['id_commerce']]);
        foreach ($svcs as $s) {
            echo "    - #{$s['id_service']} {$s['nombre']} \${$s['precio']} ({$s['estado']})" . $br;
        }
    }
}

echo $br . "=== APPOINTMENTS ===" . $br;
foreach ($commerces as $c) {
    $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM appointments WHERE id_commerce = ?");
    $stmt->execute([$c['id_commerce']]);
    echo "  '{$c['slug']}': {$stmt->fetchColumn()} turnos" . $br;
}

echo $br . "=== CLIENTES ===" . $br;
foreach ($commerces as $c) {
    $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM clients WHERE id_commerce = ?");
    $stmt->execute([$c['id_commerce']]);
    echo "  '{$c['slug']}': {$stmt->fetchColumn()} clientes" . $br;
}

echo ($isCLI ? '' : '</pre>');
