<?php
declare(strict_types=1);

/**
 * Verifica que un commerce_admin con sesión central entra al dashboard tenant
 * y no es rebotado a la página pública.
 */
require dirname(__DIR__) . '/src/Core/bootstrap.php';

use Agenduy\Core\Auth;
use Agenduy\Core\Database;

$db = Database::getInstance();
$user = $db->fetchOne(
    "SELECT u.*, c.slug
     FROM users u
     JOIN commerces c ON c.id_commerce = u.id_commerce
     WHERE u.role = 'commerce_admin' AND u.activo = 1
     ORDER BY u.id_user DESC LIMIT 1"
);
if (!$user) {
    fwrite(STDERR, "No hay commerce_admin para probar.\n");
    exit(1);
}

$originalHash = (string)$user['password_hash'];
$testPassword = 'TempAccess-' . bin2hex(random_bytes(4));
$db->update('users', [
    'password_hash' => password_hash($testPassword, PASSWORD_BCRYPT, ['cost' => 12]),
    'failed_attempts' => 0,
    'locked_until' => null,
], 'id_user = :id', [':id' => $user['id_user']]);

$cookieFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'agenduy_auth_test.txt';
@unlink($cookieFile);

function http(string $url, string $cookieFile, array $opts = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_COOKIEFILE => $cookieFile,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 20,
    ] + $opts);
    $raw = curl_exec($ch);
    if ($raw === false) {
        throw new RuntimeException(curl_error($ch));
    }
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return [
        'code' => $code,
        'headers' => substr($raw, 0, $headerSize),
        'body' => substr($raw, $headerSize),
    ];
}

$failures = 0;
try {
    $loginPage = http('http://localhost/agenduy.uy/admin/login.php', $cookieFile);
    if (!preg_match('/name="_csrf"\s+value="([a-f0-9]+)"/', $loginPage['body'], $m)) {
        throw new RuntimeException('No se encontró CSRF en login');
    }
    $csrf = $m[1];
    $post = http('http://localhost/agenduy.uy/admin/login.php', $cookieFile, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            '_csrf' => $csrf,
            'email' => $user['email'],
            'password' => $testPassword,
        ]),
    ]);
    if ($post['code'] !== 303) {
        throw new RuntimeException('Login no redirigió 303, code=' . $post['code'] . ' body=' . substr($post['body'], 0, 200));
    }
    if (!preg_match('/^Location:\s*(.+)$/mi', $post['headers'], $loc)) {
        throw new RuntimeException('Login sin Location');
    }
    $destination = trim($loc[1]);
    $expectedSuffix = '/' . $user['slug'] . '/private/dashboard/admin/index.php';
    if (!str_ends_with($destination, $expectedSuffix) && !str_contains($destination, $expectedSuffix)) {
        throw new RuntimeException('Destino inesperado: ' . $destination);
    }

    $dashUrl = str_starts_with($destination, 'http')
        ? $destination
        : 'http://localhost' . $destination;
    $dash = http($dashUrl, $cookieFile);
    if ($dash['code'] !== 200) {
        throw new RuntimeException('Dashboard code=' . $dash['code'] . ' headers=' . $dash['headers']);
    }
    if (str_contains($dash['headers'], 'Location:')) {
        throw new RuntimeException('Dashboard redirigió: ' . $dash['headers']);
    }
    // No debe ser la landing pública del comercio.
    if (preg_match('/Reservá tu turno/i', $dash['body']) && !preg_match('/admin-layout|data-admin|Configuración|Reservas/i', $dash['body'])) {
        throw new RuntimeException('Parece la página pública, no el panel admin');
    }

    echo "[PASS] commerce_admin entra al panel de {$user['slug']}\n";
    echo "destination={$destination}\n";
} catch (Throwable $e) {
    $failures++;
    echo '[FAIL] ' . $e->getMessage() . "\n";
} finally {
    $db->update('users', [
        'password_hash' => $originalHash,
        'failed_attempts' => 0,
        'locked_until' => null,
    ], 'id_user = :id', [':id' => $user['id_user']]);
    @unlink($cookieFile);
}

exit($failures > 0 ? 1 : 0);
