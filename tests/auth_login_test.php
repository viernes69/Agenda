<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/Core/bootstrap.php';

use Agenduy\Core\Auth;
use Agenduy\Core\Database;

$failures = 0;

function authTest(string $name, callable $test): void
{
    global $failures;
    try {
        $test();
        echo "[PASS] {$name}\n";
    } catch (Throwable $e) {
        $failures++;
        echo "[FAIL] {$name}: {$e->getMessage()}\n";
    }
}

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

authTest('login commerce crea sesión compatible y aislada', function (): void {
    $db = Database::getInstance();
    $commerce = $db->fetchOne(
        'SELECT id_commerce FROM commerces WHERE slug = :slug',
        [':slug' => 'terapeuta-luck']
    );
    expect(is_array($commerce), 'falta fixture terapeuta-luck');

    $email = 'auth-test-' . bin2hex(random_bytes(4)) . '@example.test';
    $userId = (int)$db->insert('users', [
        'role' => Auth::ROLE_LOCAL,
        'id_commerce' => (int)$commerce['id_commerce'],
        'nombre' => 'Auth',
        'apellido' => 'Test',
        'cedula' => 'auth-' . bin2hex(random_bytes(4)),
        'email' => $email,
        'telefono' => '',
        'whatsapp' => '',
        'password_hash' => Auth::hash('TestPassword123!'),
        'activo' => 1,
    ]);

    try {
        $result = Auth::login($email, 'TestPassword123!', '127.0.0.1');
        expect($result['ok'] === true, 'credenciales válidas fueron rechazadas');
        expect(($result['user']['role'] ?? '') === Auth::ROLE_LOCAL, 'rol central incorrecto');
        expect(($result['user']['Rol'] ?? '') === 'Admin', 'falta compatibilidad con dashboard tenant');
        expect((int)($result['user']['id_commerce'] ?? 0) === (int)$commerce['id_commerce'], 'sesión cruzó de tenant');
        expect(
            str_ends_with((string)Auth::dashboardUrl($result['user']), '/terapeuta-luck/private/dashboard/admin/index.php'),
            'login no resolvió el dashboard tenant'
        );
    } finally {
        Auth::logout();
        $db->delete('users', 'id_user = :id', [':id' => $userId]);
    }
});

authTest('super admin obtiene el panel global', function (): void {
    expect(method_exists(Auth::class, 'dashboardUrl'), 'falta Auth::dashboardUrl');
    $url = Auth::dashboardUrl(['role' => Auth::ROLE_SUPER, 'id_commerce' => null]);
    expect(is_string($url) && str_ends_with($url, '/admin/index.php'), 'destino super admin incorrecto');
});

authTest('commerce admin obtiene solamente el dashboard de su tenant', function (): void {
    $commerce = Database::getInstance()->fetchOne(
        'SELECT id_commerce, slug FROM commerces WHERE slug = :slug',
        [':slug' => 'terapeuta-luck']
    );
    expect(is_array($commerce), 'falta fixture terapeuta-luck');

    $url = Auth::dashboardUrl([
        'role' => Auth::ROLE_LOCAL,
        'id_commerce' => (int)$commerce['id_commerce'],
    ]);
    expect(
        is_string($url) && str_ends_with($url, '/terapeuta-luck/private/dashboard/admin/index.php'),
        'destino tenant incorrecto'
    );
    expect($url !== Auth::dashboardUrl([
        'role' => Auth::ROLE_SUPER,
        'id_commerce' => null,
    ]), 'commerce admin fue enviado al panel global');
});

authTest('rol desconocido no obtiene destino', function (): void {
    $url = Auth::dashboardUrl(['role' => 'client', 'id_commerce' => null]);
    expect($url === null, 'un rol no administrativo obtuvo acceso');
});

authTest('commerce admin sin comercio válido no obtiene destino', function (): void {
    $url = Auth::dashboardUrl(['role' => Auth::ROLE_LOCAL, 'id_commerce' => 999999]);
    expect($url === null, 'cuenta sin tenant válido obtuvo acceso');
});

exit($failures > 0 ? 1 : 0);
