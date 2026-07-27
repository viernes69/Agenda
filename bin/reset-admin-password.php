<?php
/**
 * Reset seguro de contraseña para el super admin.
 *
 * Uso:
 *   AGENDUY_ADMIN_PASSWORD='NuevaClave' php bin/reset-admin-password.php admin@agenduy.uy
 *
 * En Windows PowerShell:
 *   $env:AGENDUY_ADMIN_PASSWORD='NuevaClave'
 *   C:\xampp\php\php.exe bin\reset-admin-password.php admin@agenduy.uy
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Este script solo puede ejecutarse por CLI.\n";
    exit(1);
}

require __DIR__ . '/../src/Core/bootstrap.php';

use Agenduy\Core\Auth;
use Agenduy\Core\Database;

$email = strtolower(trim((string)($argv[1] ?? 'admin@agenduy.uy')));
$password = (string)(getenv('AGENDUY_ADMIN_PASSWORD') ?: '');

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Email invalido.\n");
    exit(1);
}

if (strlen($password) < 8) {
    fwrite(STDERR, "Define AGENDUY_ADMIN_PASSWORD con al menos 8 caracteres.\n");
    exit(1);
}

$db = Database::getInstance();
$user = $db->fetchOne(
    'SELECT id_user, email FROM users WHERE role = :role AND lower(email) = :email LIMIT 1',
    [':role' => Auth::ROLE_SUPER, ':email' => $email]
);

if (!$user) {
    fwrite(STDERR, "No existe un super admin con email {$email}.\n");
    exit(1);
}

$db->update('users', [
    'password_hash' => Auth::hash($password),
    'failed_attempts' => 0,
    'locked_until' => null,
    'activo' => 1,
    'updated_at' => date('Y-m-d H:i:s'),
], 'id_user = :id', [':id' => (int)$user['id_user']]);

echo "Password actualizado para {$email}.\n";
