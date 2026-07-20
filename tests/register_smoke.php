<?php
declare(strict_types=1);

/**
 * Prueba de alta + compensación (crea y limpia un tenant temporal).
 * Uso: php tests/register_smoke.php
 */

$root = dirname(__DIR__);
require $root . '/src/Core/bootstrap.php';

use Agenduy\Core\CommerceRegistrar;
use Agenduy\Core\Database;

$db = Database::getInstance();
$rubroId = (int)$db->fetchValue('SELECT id_rubro FROM rubros ORDER BY id_rubro ASC LIMIT 1');
$planId = (int)$db->fetchValue('SELECT id_membership FROM memberships WHERE precio = 0 AND activo = 1 LIMIT 1');
$email = 'qa+' . bin2hex(random_bytes(3)) . '@example.test';
$payload = [
    'owner' => [
        'nombre' => 'QA',
        'apellido' => 'Tester',
        'cedula' => '12345678',
        'email' => $email,
        'password' => 'Password123!',
    ],
    'negocio' => [
        'nombre' => 'QA Smoke ' . substr(bin2hex(random_bytes(2)), 0, 4),
        'pais' => 'UY',
        'ciudad' => 'Montevideo',
        'calle' => 'Calle 123',
        'telefono' => '099123456',
        'rubroId' => $rubroId,
    ],
    'horarios' => ['timezone' => 'America/Montevideo'],
    'servicios' => [
        ['nombre' => 'Consulta QA', 'duracion' => 30, 'precio' => 100],
    ],
    'planId' => $planId,
    'rubroId' => $rubroId,
];

$result = CommerceRegistrar::register($payload);
$slug = $result['slug'];
$dir = $root . DIRECTORY_SEPARATOR . $slug;
$commerce = $db->fetchOne('SELECT * FROM commerces WHERE slug = :s', [':s' => $slug]);
$services = $db->fetchAll('SELECT * FROM services WHERE id_commerce = :c', [':c' => $commerce['id_commerce']]);
$sub = $db->fetchOne('SELECT * FROM subscriptions WHERE id_commerce = :c ORDER BY id_subscription DESC LIMIT 1', [':c' => $commerce['id_commerce']]);

$ok = is_dir($dir)
    && $commerce
    && $commerce['status'] === 'trial'
    && count($services) >= 1
    && $sub
    && $sub['status'] === 'trial';

echo $ok ? "[PASS] register smoke {$slug}\n" : "[FAIL] register smoke\n";
echo json_encode([
    'slug' => $slug,
    'status' => $commerce['status'] ?? null,
    'services' => count($services),
    'sub' => $sub['status'] ?? null,
    'dir' => is_dir($dir),
], JSON_PRETTY_PRINT) . "\n";

// Cleanup
$db->delete('commerce_settings', 'id_commerce = :c', [':c' => $commerce['id_commerce']]);
$db->delete('services', 'id_commerce = :c', [':c' => $commerce['id_commerce']]);
$db->delete('subscriptions', 'id_commerce = :c', [':c' => $commerce['id_commerce']]);
$db->delete('users', 'id_commerce = :c', [':c' => $commerce['id_commerce']]);
$db->delete('commerces', 'id_commerce = :c', [':c' => $commerce['id_commerce']]);

$it = new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS);
$files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
foreach ($files as $file) {
    $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
}
@rmdir($dir);

echo is_dir($dir) ? "[WARN] no se pudo borrar {$slug}\n" : "[PASS] cleanup\n";
exit($ok ? 0 : 1);
