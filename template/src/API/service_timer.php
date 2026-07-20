<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$hasSession = isset($_SESSION['user']) || isset($_SESSION['barbero']) || isset($_SESSION['admin']);
if (!$hasSession) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Sesion no valida'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION['service_timers']) || !is_array($_SESSION['service_timers'])) {
    $_SESSION['service_timers'] = [];
}

function service_timer_response(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

$rawBody = file_get_contents('php://input');
$jsonBody = json_decode($rawBody, true);
$params = array_merge($_GET, $_POST, is_array($jsonBody) ? $jsonBody : []);

$action = strtolower(trim((string)($params['action'] ?? '')));
$reservationIdRaw = $params['id'] ?? $params['reserva'] ?? null;
$reservationId = is_numeric($reservationIdRaw)
    ? (string)(int)$reservationIdRaw
    : trim((string)$reservationIdRaw);

if ($reservationId === '') {
    service_timer_response(['ok' => false, 'error' => 'ID de reserva requerido'], 400);
}

$timers =& $_SESSION['service_timers'];

$nowMs = (int)round(microtime(true) * 1000);

switch ($action) {
    case 'start':
        if (!isset($timers[$reservationId]) || !is_numeric($timers[$reservationId])) {
            $timers[$reservationId] = $nowMs;
        }
        service_timer_response([
            'ok' => true,
            'started_at' => (int)$timers[$reservationId],
        ]);

    case 'get':
        if (isset($timers[$reservationId]) && is_numeric($timers[$reservationId])) {
            service_timer_response([
                'ok' => true,
                'started_at' => (int)$timers[$reservationId],
            ]);
        }
        service_timer_response(['ok' => true, 'started_at' => null]);

    case 'clear':
        if (isset($timers[$reservationId])) {
            unset($timers[$reservationId]);
        }
        service_timer_response(['ok' => true]);

    default:
        service_timer_response(['ok' => false, 'error' => 'Accion no soportada'], 400);
}
