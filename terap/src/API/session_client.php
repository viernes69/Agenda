<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

date_default_timezone_set('America/Montevideo');
require_once __DIR__ . '/Autoload.php';

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function client_session_response($payload, int $status = 200): void {
    while (ob_get_level()) {
        ob_end_clean();
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function client_find_by_cedula(string $cedula): ?array {
    $needle = trim($cedula);
    if ($needle === '') {
        return null;
    }
    try {
        $clientes = AutoloadDB::all('clientes');
    } catch (Throwable $e) {
        error_log('[client_session] No se pudo leer tabla clientes: ' . $e->getMessage());
        return null;
    }
    foreach ($clientes as $cliente) {
        $value = trim((string)($cliente['Cedula'] ?? ''));
        if ($value !== '' && strcasecmp($value, $needle) === 0) {
            return $cliente;
        }
    }
    return null;
}

function client_find_by_id($id): ?array {
    if ($id === null || $id === '') {
        return null;
    }
    try {
        return AutoloadDB::find('clientes', $id) ?: null;
    } catch (Throwable $e) {
        error_log('[client_session] No se pudo leer cliente por ID: ' . $e->getMessage());
        return null;
    }
}

function client_reservas_by_cliente($clienteId): array {
    if ($clienteId === null || $clienteId === '') {
        return [];
    }
    try {
        $reservas = AutoloadDB::all('reservas');
    } catch (Throwable $e) {
        error_log('[client_session] No se pudo leer reservas: ' . $e->getMessage());
        return [];
    }
    $matches = [];
    foreach ($reservas as $reserva) {
        if ((string)($reserva['ID_Cliente'] ?? '') === (string)$clienteId) {
            $matches[] = $reserva;
        }
    }
    return $matches;
}

function client_normalize_profile($path): string {
    $value = trim((string)$path);
    if ($value === '') {
        return '';
    }
    return str_replace('\\', '/', $value);
}

function client_build_session(array $cliente, array $baseline = []): array {
    $now = time();
    $sessionStarted = isset($baseline['session_started_at']) ? (int)$baseline['session_started_at'] : $now;
    if ($sessionStarted <= 0) {
        $sessionStarted = $now;
    }
    $expiresAt = isset($baseline['expires_at']) ? (int)$baseline['expires_at'] : ($sessionStarted + 24 * 60 * 60);
    if ($expiresAt <= $now) {
        $expiresAt = $now + 24 * 60 * 60;
    }

    $clienteId = $cliente['ID_Cliente'] ?? $baseline['cliente_id'] ?? null;
    $payload = [
        'cliente_id' => $clienteId !== null && $clienteId !== '' ? (int)$clienteId : null,
        'nombre' => trim((string)($cliente['Nombre'] ?? $baseline['nombre'] ?? '')),
        'apellido' => trim((string)($cliente['Apellido'] ?? $baseline['apellido'] ?? '')),
        'cedula' => trim((string)($cliente['Cedula'] ?? $baseline['cedula'] ?? '')),
        'telefono' => trim((string)($cliente['Telefono'] ?? $baseline['telefono'] ?? '')),
        'email' => trim((string)($cliente['Email'] ?? $baseline['email'] ?? '')),
        'perfil' => client_normalize_profile($cliente['Perfil'] ?? $baseline['perfil'] ?? ''),
        'session_started_at' => $sessionStarted,
        'expires_at' => $expiresAt,
    ];

    $display = trim((string)($cliente['display_name'] ?? $baseline['display_name'] ?? ''));
    if ($display === '') {
        $nombre = $payload['nombre'];
        $apellido = trim((string)($cliente['Apellido'] ?? $baseline['apellido'] ?? ''));
        $parts = array_filter([ $nombre, $apellido ], static fn ($part) => trim((string)$part) !== '');
        $display = trim(implode(' ', $parts));
        if ($display === '') {
            $display = $payload['email'] !== '' ? $payload['email'] : ($payload['cedula'] !== '' ? $payload['cedula'] : 'Cliente');
        }
    }
    $payload['display_name'] = $display;

    $payload['reservas'] = client_reservas_by_cliente($payload['cliente_id']);

    // Conservar otros campos de baseline (por ejemplo, flags internos)
    foreach ($baseline as $key => $value) {
        if (!is_string($key)) {
            continue;
        }
        if (array_key_exists($key, $payload)) {
            continue;
        }
        // Evitar reintroducir duplicados en mayúsculas
        if (preg_match('/^[A-Z]/', $key)) {
            continue;
        }
        $payload[$key] = $value;
    }

    return $payload;
}

$rawBody = file_get_contents('php://input');
$jsonBody = json_decode($rawBody, true);
if (!is_array($jsonBody)) {
    $jsonBody = [];
}
$params = array_merge($_GET, $_POST, $jsonBody);
$action = strtolower((string)($params['action'] ?? 'status'));

switch ($action) {
    case 'start':
        $cedula = trim((string)($params['cedula'] ?? ''));
        if ($cedula === '') {
            client_session_response(['ok' => false, 'error' => 'Cedula requerida'], 400);
        }
        $cliente = client_find_by_cedula($cedula);
        if (!$cliente) {
            client_session_response(['ok' => false, 'error' => 'Cliente no encontrado'], 404);
        }
        $now = time();
        $sessionPayload = client_build_session($cliente, [
            'session_started_at' => $now,
            'expires_at' => $now + 24 * 60 * 60,
        ]);
        $_SESSION['cliente'] = $sessionPayload;
        client_session_response(['ok' => true, 'data' => $_SESSION['cliente']]);
        break;

    case 'refresh':
    case 'status':
        if (!isset($_SESSION['cliente']) || !is_array($_SESSION['cliente'])) {
            client_session_response(['ok' => false, 'data' => null]);
        }

        $session = $_SESSION['cliente'];
        $expires = isset($session['expires_at']) ? (int)$session['expires_at'] : 0;
        if ($expires > 0 && time() > $expires) {
            unset($_SESSION['cliente']);
            client_session_response(['ok' => false, 'data' => null, 'error' => 'Sesion expirada'], 401);
        }

        $clienteRow = null;
        if (!empty($session['cliente_id'])) {
            $clienteRow = client_find_by_id($session['cliente_id']);
        }
        if (!$clienteRow && !empty($session['cedula'])) {
            $clienteRow = client_find_by_cedula($session['cedula']);
        }
        if ($clienteRow) {
            $_SESSION['cliente'] = client_build_session($clienteRow, $session);
        } else {
            // Mantener sesión existente, pero garantizar formato consistente
            $_SESSION['cliente'] = client_build_session([], $session);
        }

        client_session_response(['ok' => true, 'data' => $_SESSION['cliente']]);
        break;

    case 'logout':
        unset($_SESSION['cliente']);
        client_session_response(['ok' => true]);
        break;

    default:
        client_session_response(['ok' => false, 'error' => 'Accion no soportada'], 400);
        break;
}
