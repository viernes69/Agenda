<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

date_default_timezone_set('America/Montevideo');
require_once __DIR__ . '/Autoload.php';

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function barber_session_destroy(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'] ?? '/',
            $params['domain'] ?? '',
            !empty($params['secure']),
            !empty($params['httponly'])
        );
    }
    session_destroy();
}

function barber_session_response($payload, int $status = 200): void {
    while (ob_get_level()) {
        ob_end_clean();
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function barber_find_by_id($id): ?array {
    if ($id === null || $id === '') {
        return null;
    }
    try {
        return AutoloadDB::find('barberos', $id) ?: null;
    } catch (Throwable $e) {
        error_log('[barber_session] No se pudo leer barbero por ID: ' . $e->getMessage());
        return null;
    }
}

function barber_verify_credentials($barberData, string $password) {
    if (!is_array($barberData)) {
        return false;
    }
    $password = trim($password);
    if ($password === '') {
        return false;
    }
    $targetId = $barberData['ID_Barber'] ?? null;
    try {
        $barberos = AutoloadDB::all('barberos');
    } catch (Throwable $e) {
        error_log('[barber_session] Error leyendo barberos: ' . $e->getMessage());
        return false;
    }
    foreach ($barberos as $barbero) {
        $matches = false;
        if ($targetId !== null && $targetId !== '') {
            $matches = (string)($barbero['ID_Barber'] ?? '') === (string)$targetId;
        } elseif (!empty($barberData['Cedula'])) {
            $matches = strcasecmp((string)($barbero['Cedula'] ?? ''), (string)$barberData['Cedula']) === 0;
        }
        if (!$matches) {
            continue;
        }
        if (!isset($barbero['Psw']) || $barbero['Psw'] !== $password) {
            return false;
        }
        $sessionRow = $barbero;
        // Mantener campos auxiliares aportados por front-end (por ejemplo, foto temporal)
        foreach ($barberData as $key => $value) {
            if (!array_key_exists($key, $sessionRow)) {
                $sessionRow[$key] = $value;
            }
        }
        return $sessionRow;
    }
    return false;
}

function barber_build_session(array $barbero, array $baseline = []): array {
    $now = time();
    $started = isset($baseline['session_started_at']) ? (int)$baseline['session_started_at'] : $now;
    if ($started <= 0) {
        $started = $now;
    }
    $expires = isset($baseline['expires_at']) ? (int)$baseline['expires_at'] : ($started + 24 * 60 * 60);
    if ($expires <= $now) {
        $expires = $now + 24 * 60 * 60;
    }

    $payload = [
        'ID_Barber' => $barbero['ID_Barber'] ?? ($baseline['ID_Barber'] ?? null),
        'Nombre' => $barbero['Nombre'] ?? ($baseline['Nombre'] ?? ''),
        'Apellido' => $barbero['Apellido'] ?? ($baseline['Apellido'] ?? ''),
        'Cedula' => $barbero['Cedula'] ?? ($baseline['Cedula'] ?? ''),
        'Rol' => $barbero['Rol'] ?? ($baseline['Rol'] ?? ''),
        'Disponibilidad' => $barbero['Disponibilidad'] ?? ($baseline['Disponibilidad'] ?? ''),
        'Status' => $barbero['Status'] ?? ($baseline['Status'] ?? ''),
        'Psw' => $barbero['Psw'] ?? ($baseline['Psw'] ?? ''),
        'session_started_at' => $started,
        'expires_at' => $expires,
    ];

    foreach ($baseline as $key => $value) {
        if (!array_key_exists($key, $payload)) {
            $payload[$key] = $value;
        }
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
    case 'barber_login':
    case 'login': {
        $cedula = trim((string)($params['barber_data']['Cedula'] ?? $params['cedula'] ?? ''));
        $password = trim((string)($params['password'] ?? ''));
        if ($cedula === '' || $password === '') {
            barber_session_response(['ok' => false, 'error' => 'Datos incompletos'], 400);
        }
        // Buscar barbero por cédula
        $barberos = AutoloadDB::all('barberos');
        $found = null;
        foreach ($barberos as $barbero) {
            if (strcasecmp((string)($barbero['Cedula'] ?? ''), $cedula) === 0) {
                $found = $barbero;
                break;
            }
        }
        if (!$found) {
            barber_session_response(['ok' => false, 'error' => 'Barbero no encontrado'], 404);
        }
        $hash = $found['Psw'] ?? '';
        $valid = false;
        if ($hash !== '' && strlen($hash) > 20 && preg_match('/^\$2[ayb]\$/', $hash)) {
            // bcrypt hash
            $valid = password_verify($password, $hash);
        } else {
            // texto plano legacy
            $valid = ($password === $hash);
        }
        if (!$valid) {
            barber_session_response(['ok' => false, 'error' => 'Credenciales invalidas'], 401);
        }
        // Guardar datos en SESSION['user']
        $now = time();
        $fecha = date('Y-m-d');
        $hora = date('H:i:s');
        $_SESSION['user'] = [
            'ID_Barber' => $found['ID_Barber'] ?? null,
            'Rol' => $found['Rol'] ?? '',
            'FechaInicio' => $fecha,
            'HoraInicio' => $hora,
        ];
        // Cambiar Status a Online si estaba Offline
        if (isset($found['Status']) && strtolower($found['Status']) === 'offline') {
            AutoloadDB::updateById('barberos', $found['ID_Barber'], ['Status' => 'Online']);
            $found['Status'] = 'Online';
        }
        // Redirigir según rol
        $rol = strtolower((string)($found['Rol'] ?? ''));
        $redirect = './private/dashboard/empleado/';
        if ($rol === 'admin') {
            $redirect = './private/dashboard/admin/';
        }
        barber_session_response([
            'ok' => true,
            'data' => [
                'ID_Barber' => $found['ID_Barber'] ?? null,
                'Rol' => $found['Rol'] ?? '',
                'FechaInicio' => $fecha,
                'HoraInicio' => $hora,
                'redirect' => $redirect,
            ]
        ]);
        break;
    }

    case 'barber_logout':
    case 'logout':
        unset($_SESSION['barbero'], $_SESSION['user'], $_SESSION['admin']);
        barber_session_destroy();
        barber_session_response(['ok' => true]);
        break;

    case 'status':
    default:
        if (!isset($_SESSION['barbero']) || !is_array($_SESSION['barbero'])) {
            barber_session_response(['ok' => false, 'data' => null]);
        }
        $session = $_SESSION['barbero'];
        $expires = isset($session['expires_at']) ? (int)$session['expires_at'] : 0;
        if ($expires > 0 && time() > $expires) {
            unset($_SESSION['barbero']);
            barber_session_response(['ok' => false, 'data' => null, 'error' => 'Sesion expirada'], 401);
        }
        $barbero = null;
        if (!empty($session['ID_Barber'])) {
            $barbero = barber_find_by_id($session['ID_Barber']);
        }
        if ($barbero) {
            $_SESSION['barbero'] = barber_build_session($barbero, $session);
        } else {
            $_SESSION['barbero'] = barber_build_session([], $session);
        }
        barber_session_response(['ok' => true, 'data' => $_SESSION['barbero']]);
        break;
}
