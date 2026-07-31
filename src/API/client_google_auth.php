<?php
/**
 * Agendarte - Storefront: Login/registro de cliente con Google (GIS ID token)
 *
 * POST /src/API/client_google_auth.php
 *   body: { credential, slug, _csrf }
 */
declare(strict_types=1);

require __DIR__ . '/../Core/bootstrap.php';

use Agenduy\Core\CSRF;
use Agenduy\Core\Database;
use Agenduy\Core\GoogleAuth;
use Agenduy\Core\RateLimiter;
use Agenduy\Core\Security;
use Agenduy\Core\TenantLocalDb;

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido']);
    exit;
}

RateLimiter::enforce('client_google_auth_ip', Security::clientIp(), 60, 20);

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$csrf = $payload['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
if (!CSRF::validate(is_string($csrf) ? $csrf : null, 'public_booking')) {
    http_response_code(419);
    echo json_encode(['ok' => false, 'error' => 'CSRF inválido.']);
    exit;
}

if (!GoogleAuth::isEnabled()) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'Google no está configurado en el panel.']);
    exit;
}

$slug = trim((string)($payload['slug'] ?? ''));
if ($slug === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Falta el comercio.']);
    exit;
}

$token = trim((string)($payload['credential'] ?? $payload['id_token'] ?? ''));
if ($token === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Falta el token de Google.']);
    exit;
}

try {
    $profile = GoogleAuth::verifyIdToken($token);
} catch (Throwable $e) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}

$db = Database::getInstance();
$commerce = $db->fetchOne(
    'SELECT id_commerce, slug, nombre FROM commerces WHERE slug = :s LIMIT 1',
    [':s' => $slug]
);
if (!$commerce) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Comercio no encontrado.']);
    exit;
}

$idCommerce = (int)$commerce['id_commerce'];
$email = strtolower(trim((string)$profile['email']));
$nombre = trim((string)($profile['given_name'] ?? $profile['name'] ?? ''));
$apellido = trim((string)($profile['family_name'] ?? ''));
if ($nombre === '') {
    $nombre = $email;
}
$picture = trim((string)($profile['picture'] ?? ''));

// Buscar cliente existente del comercio por email (misma estrategia que appointments.php).
$client = $db->fetchOne(
    'SELECT id_client, nombre, apellido, email, telefono, avatar
     FROM clients
     WHERE id_commerce = :c AND lower(trim(email)) = :e
     ORDER BY updated_at DESC, id_client DESC LIMIT 1',
    [':c' => $idCommerce, ':e' => $email]
);

$found = false;
$idClient = 0;
if ($client) {
    $found = true;
    $idClient = (int)$client['id_client'];
    // Completar datos que falten con el perfil de Google.
    $patch = [];
    if (trim((string)$client['nombre']) === '' && $nombre !== '') {
        $patch['nombre'] = $nombre;
    }
    if (trim((string)$client['apellido']) === '' && $apellido !== '') {
        $patch['apellido'] = $apellido;
    }
    if (trim((string)$client['avatar']) === '' && $picture !== '') {
        $patch['avatar'] = $picture;
    }
    if ($patch) {
        $patch['updated_at'] = date('Y-m-d H:i:s');
        $db->update('clients', $patch, 'id_client = :id', [':id' => $idClient]);
        $client = $db->fetchOne(
            'SELECT id_client, nombre, apellido, email, telefono, avatar FROM clients WHERE id_client = :id',
            [':id' => $idClient]
        );
    }
} else {
    $idClient = (int)$db->insert('clients', [
        'id_commerce' => $idCommerce,
        'nombre'      => $nombre,
        'apellido'    => $apellido,
        'email'       => $email,
        'telefono'    => '',
        'cedula'      => '',
        'avatar'      => $picture,
    ]);
    $client = [
        'id_client' => $idClient,
        'nombre'    => $nombre,
        'apellido'  => $apellido,
        'email'     => $email,
        'telefono'  => '',
        'avatar'    => $picture,
    ];
}

// Sincronizar avatar de Google hacia la DB local del tenant
// (la lista de clientes del panel lee `clientes.Perfil` local).
try {
    if (TenantLocalDb::exists($slug)) {
        TenantLocalDb::findOrCreateCliente(
            $slug,
            trim($nombre . ' ' . $apellido),
            trim((string)($client['telefono'] ?? '')),
            $email,
            $picture
        );
    }
} catch (Throwable $e) {
    error_log('[client_google_auth] sync local avatar: ' . $e->getMessage());
}

// Historial del cliente (reservas centrales + pedidos locales del tenant).
$historial = ['reservas' => [], 'pedidos' => []];
if ($found) {
    try {
        $reservas = $db->fetchAll(
            "SELECT a.id_appointment, a.fecha, a.hora_inicio, a.status, a.precio,
                    COALESCE(s.nombre, '') AS servicio
             FROM appointments a
             LEFT JOIN services s ON s.id_service = a.id_service
             WHERE a.id_commerce = :c AND lower(trim(a.cliente_email)) = :e
             ORDER BY a.fecha DESC, a.hora_inicio DESC
             LIMIT 8",
            [':c' => $idCommerce, ':e' => $email]
        );
        foreach ($reservas as $r) {
            $historial['reservas'][] = [
                'id'       => (int)$r['id_appointment'],
                'fecha'    => (string)$r['fecha'],
                'hora'     => (string)$r['hora_inicio'],
                'status'   => (string)$r['status'],
                'precio'   => (float)$r['precio'],
                'servicio' => (string)$r['servicio'],
            ];
        }
    } catch (Throwable $e) {
        error_log('[client_google_auth] historial reservas: ' . $e->getMessage());
    }

    try {
        if (TenantLocalDb::exists($slug)) {
            $local = TenantLocalDb::read($slug);
            $idLocalCliente = null;
            if (isset($local['clientes']) && is_array($local['clientes'])) {
                foreach ($local['clientes'] as $i => $row) {
                    if ($i === 0 || !is_array($row)) continue;
                    $rowEmail = mb_strtolower(trim((string)($row['Email'] ?? '')), 'UTF-8');
                    if ($rowEmail !== '' && $rowEmail === $email) {
                        $idLocalCliente = $row['ID_Cliente'] ?? null;
                        break;
                    }
                }
            }
            if ($idLocalCliente !== null && isset($local['carrito']) && is_array($local['carrito'])) {
                $orders = [];
                foreach ($local['carrito'] as $i => $row) {
                    if ($i === 0 || !is_array($row)) continue;
                    if ((string)($row['ID_Cliente'] ?? '') !== (string)$idLocalCliente) continue;
                    $orders[] = [
                        'id'      => $row['ID_Carrito'] ?? null,
                        'fecha'   => trim((string)($row['Fecha'] ?? '')),
                        'hora'    => trim((string)($row['Hora'] ?? '')),
                        'status'  => trim((string)($row['Status'] ?? '')),
                        'total'   => trim((string)($row['Total'] ?? '')),
                        'metodo'  => trim((string)($row['Metodo_Pago'] ?? '')),
                        'resumen' => trim((string)($row['ID_Producto + Cantidad'] ?? '')),
                    ];
                }
                usort($orders, static fn($a, $b) => strcmp(
                    (string)($b['fecha'] ?? '') . ' ' . (string)($b['hora'] ?? ''),
                    (string)($a['fecha'] ?? '') . ' ' . (string)($a['hora'] ?? '')
                ));
                $historial['pedidos'] = array_slice($orders, 0, 8);
            }
        }
    } catch (Throwable $e) {
        error_log('[client_google_auth] historial pedidos: ' . $e->getMessage());
    }
}

echo json_encode([
    'ok'         => true,
    'found'      => $found,
    'registered' => !$found,
    'client_id'  => $idClient,
    'historial'  => $historial,
    'profile'    => [
        'nombre'   => trim((string)($client['nombre'] ?? $nombre)),
        'apellido' => trim((string)($client['apellido'] ?? '')),
        'email'    => $email,
        'telefono' => trim((string)($client['telefono'] ?? '')),
        'avatar'   => trim((string)($client['avatar'] ?? '')),
    ],
], JSON_UNESCAPED_UNICODE);
