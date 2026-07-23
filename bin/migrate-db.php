<?php
/**
 * Migración de base de datos para producción.
 *
 * Aplica cambios que se hicieron localmente y que no están en git:
 *   1. Crea registro 'funciones' en commerce_settings para comercios que no lo tengan
 *   2. Migra servicios de la DB local (database.php) a la tabla central 'services'
 *
 * USO:
 *   CLI:    php bin/migrate-db.php
 *   Web:    https://agendarte.oficiosya.net/data/bin/migrate-db.php?token=...
 *
 * Es IDEMPOTENTE: se puede ejecutar múltiples veces sin efectos secundarios.
 *
 * IMPORTANTE: Cambiá el token antes de usar en producción.
 */

// --- Configuración ---
define('MIGRATE_TOKEN', getenv('MIGRATE_TOKEN') ?: 'cambiar-en-produccion');
define('DEFAULT_TOKEN', 'cambiar-en-produccion');

// --- Boot ---
$isCLI = php_sapi_name() === 'cli';
$br = $isCLI ? "\n" : "<br>\n";

// Protección web: requiere token personalizado
if (!$isCLI) {
    if (MIGRATE_TOKEN === DEFAULT_TOKEN) {
        http_response_code(500);
        echo "ERROR: Cambiá el token en bin/migrate-db.php (constante MIGRATE_TOKEN) antes de ejecutar por web.$br";
        echo "O ejecutá por CLI: php bin/migrate-db.php$br";
        exit;
    }
    $reqToken = $_GET['token'] ?? '';
    if ($reqToken !== MIGRATE_TOKEN) {
        http_response_code(403);
        echo "Acceso denegado.$br";
        exit;
    }
}

function logMsg(string $msg, bool $isError = false): void {
    global $br;
    $prefix = $isError ? '❌' : '✅';
    echo "$prefix $msg$br";
    if (php_sapi_name() !== 'cli') {
        if (ob_get_level()) ob_flush();
        flush();
    }
}

$dbPath = __DIR__ . '/../storage/agenduy.db';
if (!file_exists($dbPath)) {
    logMsg("Base de datos no encontrada en: $dbPath", true);
    exit(1);
}

$db = new PDO('sqlite:' . $dbPath);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

echo ($isCLI ? '' : '<pre>');
echo "=== Migracion de BD: " . date('Y-m-d H:i:s') . " ===" . $br . $br;

// ============================================================
// 1. Asegurar registro 'funciones' para todos los comercios
// ============================================================
echo "--- PASO 1: Funciones ---" . $br;

$commerces = $db->query('SELECT id_commerce, slug, id_rubro FROM commerces ORDER BY id_commerce')->fetchAll();
$funcionesCount = 0;

foreach ($commerces as $c) {
    $existsStmt = $db->prepare("SELECT COUNT(*) FROM commerce_settings WHERE id_commerce = ? AND section = 'funciones'");
    $existsStmt->execute([$c['id_commerce']]);
    $exists = (int)$existsStmt->fetchColumn() > 0;

    if ($exists) {
        $row = $db->prepare("SELECT config_json FROM commerce_settings WHERE id_commerce = ? AND section = 'funciones'");
        $row->execute([$c['id_commerce']]);
        $val = json_decode($row->fetchColumn(), true);
        if (!empty($val['tipo_comercio'])) {
            logMsg("#{$c['id_commerce']} '{$c['slug']}': ya tiene funciones ok");
            continue;
        }
    }

    // Determinar tipo de comercio segun rubro
    $isStore = false;
    if (!empty($c['id_rubro'])) {
        $rubroStmt = $db->prepare("SELECT tipo, nombre FROM rubros WHERE id_rubro = ?");
        $rubroStmt->execute([$c['id_rubro']]);
        $rubro = $rubroStmt->fetch();
        if ($rubro) {
            $label = strtolower(trim(($rubro['tipo'] ?? '') . ' ' . ($rubro['nombre'] ?? '')));
            $isStore = strpos($label, 'tienda') !== false
                || strpos($label, 'comercio') !== false
                || strpos($label, 'retail') !== false;
        }
    }

    $features = $isStore ? [
        'tipo_comercio' => 'tienda',
        'productos' => true,
        'servicios' => false,
        'barberos' => false,
        'reservas' => false,
        'carrito' => true,
    ] : [
        'tipo_comercio' => 'servicios',
        'productos' => true,
        'servicios' => true,
        'barberos' => true,
        'reservas' => true,
        'carrito' => true,
    ];

    $json = json_encode($features, JSON_UNESCAPED_UNICODE);

    if ($exists) {
        $upd = $db->prepare("UPDATE commerce_settings SET config_json = ?, updated_at = datetime('now') WHERE id_commerce = ? AND section = 'funciones'");
        $upd->execute([$json, $c['id_commerce']]);
        logMsg("#{$c['id_commerce']} '{$c['slug']}': funciones actualizado ({$features['tipo_comercio']})");
    } else {
        $ins = $db->prepare("INSERT INTO commerce_settings (id_commerce, section, config_json, updated_at) VALUES (?, 'funciones', ?, datetime('now'))");
        $ins->execute([$c['id_commerce'], $json]);
        logMsg("#{$c['id_commerce']} '{$c['slug']}': funciones CREADO ({$features['tipo_comercio']})");
    }
    $funcionesCount++;
}

echo $br;

// ============================================================
// 2. Migrar servicios de DB local a central
// ============================================================
echo "--- PASO 2: Servicios (local > central) ---" . $br;

$projectRoot = realpath(__DIR__ . '/..');
$migratedCount = 0;

foreach ($commerces as $c) {
    $localDbPath = $projectRoot . '/' . $c['slug'] . '/src/db/database.php';
    if (!file_exists($localDbPath)) {
        logMsg("#{$c['id_commerce']} '{$c['slug']}': no tiene database.php local -- skip");
        continue;
    }

    // Cargar DB local
    $localData = include $localDbPath;
    if (!is_array($localData)) {
        logMsg("#{$c['id_commerce']} '{$c['slug']}': database.php no devolvio array -- skip", true);
        continue;
    }

    $localServices = $localData['servicios'] ?? [];
    if (!is_array($localServices)) {
        logMsg("#{$c['id_commerce']} '{$c['slug']}': no hay servicios en local -- skip");
        continue;
    }

    // Filtrar servicios con nombre real
    $realServices = [];
    foreach ($localServices as $svc) {
        if (is_array($svc) && !empty($svc['Nombre'])) {
            $realServices[] = $svc;
        }
    }

    if (empty($realServices)) {
        logMsg("#{$c['id_commerce']} '{$c['slug']}': 0 servicios con nombre -- skip");
        continue;
    }

    // Obtener servicios ya existentes en central
    $existingStmt = $db->prepare("SELECT nombre, precio FROM services WHERE id_commerce = ?");
    $existingStmt->execute([$c['id_commerce']]);
    $existingKeys = [];
    foreach ($existingStmt->fetchAll() as $e) {
        // Usar precio en centesimas para evitar falsos duplicados por float
        $priceCents = (int)round((float)$e['precio'] * 100);
        $existingKeys[strtolower(trim($e['nombre'])) . '|' . $priceCents] = true;
    }

    $inserted = 0;
    foreach ($realServices as $svc) {
        $name = trim((string)($svc['Nombre'] ?? ''));
        $priceCents = (int)round((float)($svc['Precio'] ?? 0) * 100);
        $precio = (float)($svc['Precio'] ?? 0);
        $duracion = (int)($svc['Duracion'] ?? 30);
        $estado = (string)($svc['Estado'] ?? 'Activo');
        $imagen = (string)($svc['Img_Link'] ?? '');

        if ($name === '') continue;
        $key = strtolower($name) . '|' . $priceCents;

        if (isset($existingKeys[$key])) {
            continue; // ya migrado
        }

        $ins = $db->prepare(
            "INSERT INTO services (id_commerce, nombre, descripcion, duracion_min, precio, estado, imagen, created_at, updated_at)
             VALUES (?, ?, '', ?, ?, ?, ?, datetime('now'), datetime('now'))"
        );
        $ins->execute([$c['id_commerce'], $name, $duracion, $precio, $estado, $imagen]);
        $inserted++;
    }

    if ($inserted > 0) {
        logMsg("#{$c['id_commerce']} '{$c['slug']}': {$inserted} servicios migrados");
    } else {
        logMsg("#{$c['id_commerce']} '{$c['slug']}': todos los servicios ya estaban en central");
    }
    $migratedCount += $inserted;
}

echo $br;
echo "=== Migracion completada ===" . $br;
echo "Funciones creadas/actualizadas: {$funcionesCount}" . $br;
echo "Servicios migrados: {$migratedCount}" . $br;
echo ($isCLI ? '' : '</pre>');
