<?php
/**
 * Agenduy - Instalación / Migración
 *
 * Por seguridad, este archivo se ELIMINA automáticamente la primera
 * vez que la instalación termina con éxito.
 */
declare(strict_types=1);

$config = require __DIR__ . '/../src/Core/bootstrap.php';

$installKey = getenv('AGENDUY_INSTALL_KEY') ?: 'INSTALL_AGENDUY_2026';
if (($_GET['key'] ?? '') !== $installKey) {
    http_response_code(403);
    echo "Acceso denegado.\n";
    exit;
}

// Si la BD ya está inicializada, avisar
$dbPath = (string)$config['db']['path'];
if (file_exists($dbPath)) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "La base de datos ya existe: {$dbPath}\n";
    echo "Para reinstalar, primero eliminá ese archivo.\n";
    echo "Borrá también /admin/install.php después de usarlo.\n";
    exit;
}

require_once dirname(__DIR__) . '/src/Core/Database.php';
require_once dirname(__DIR__) . '/src/Core/db/seed.php';

use Agenduy\Core\Database;
use Agenduy\Core\db\Seed;

$db = Database::getInstance();
$out = [];

try {
    $out['seed'] = Seed::run(
        $_POST['super_email']    ?? null,
        $_POST['super_password'] ?? null
    );
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Error en seed: " . $e->getMessage();
    exit;
}

header('Content-Type: text/plain; charset=utf-8');
echo "Agenduy - Instalación completada\n";
echo "================================\n\n";
foreach ($out as $key => $val) {
    echo "[$key]\n";
    if (is_array($val)) {
        foreach ($val as $k => $v) {
            if (is_array($v)) {
                echo "  $k: " . json_encode($v, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
            } else {
                echo "  $k: $v\n";
            }
        }
    }
    echo "\n";
}

echo "IMPORTANTE:\n";
echo "  1. Guardá la contraseña del super admin que aparece arriba.\n";
echo "  2. Cambiá la variable de entorno AGENDUY_INSTALL_KEY (o eliminala) y borrá /admin/install.php.\n";
echo "  3. Para migrar datos desde el viejo database.php, ejecutá el script de migración por separado.\n";
