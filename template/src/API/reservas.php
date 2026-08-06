<?php
date_default_timezone_set('America/Montevideo');
session_start();
require_once __DIR__ . '/Autoload.php';
require_once __DIR__ . '/mail_helpers.php';

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . '/src/Core/bootstrap.php';

use Agenduy\Core\MembershipPlan;
use Agenduy\Core\NotificationOutbox;

header('Content-Type: application/json; charset=utf-8');

// Merge JSON body into request
$raw = file_get_contents('php://input');
if ($raw) {
    $json = json_decode($raw, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
        $_REQUEST = array_merge($_REQUEST, $json);
    }
}

$action = strtolower((string)($_REQUEST['action'] ?? 'create'));

function respond($payload, int $code = 200) {
    http_response_code($code);
    echo json_encode($payload);
    exit;
}

function formatReservaHour(string $hora): string {
    $hora = trim($hora);
    if ($hora === '') {
        return '';
    }
    $hora = str_replace('.', ':', $hora);
    if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $hora)) {
        return substr($hora, 0, 5);
    }
    return $hora;
}

function buildClienteDisplayName(array $session): string {
    $display = trim((string)($session['display_name'] ?? ''));
    if ($display !== '') {
        return $display;
    }
    $parts = array_filter([
        trim((string)($session['nombre'] ?? '')),
        trim((string)($session['apellido'] ?? '')),
    ]);
    if ($parts) {
        return trim(implode(' ', $parts));
    }
    $fallbacks = [
        trim((string)($session['email'] ?? '')),
        trim((string)($session['cedula'] ?? '')),
    ];
    foreach ($fallbacks as $fallback) {
        if ($fallback !== '') {
            return $fallback;
        }
    }
    return 'Cliente';
}

function findClienteIdByCedula(string $cedula): ?int {
    $clientes = AutoloadDB::all('clientes');
    $needle = strtolower(trim($cedula));
    foreach ($clientes as $c) {
        if (!isset($c['Cedula'])) continue;
        if (strtolower(trim((string)$c['Cedula'])) === $needle) {
            return isset($c['ID_Cliente']) ? (int)$c['ID_Cliente'] : null;
        }
    }
    return null;
}

try {
    if ($action !== 'create') {
        respond(['ok' => false, 'error' => 'Accion no soportada'], 400);
    }

    if (!isset($_SESSION['cliente'])) {
        respond(['ok' => false, 'error' => 'No autenticado'], 401);
    }
    if (isset($_SESSION['cliente']['expires_at']) && time() > (int)$_SESSION['cliente']['expires_at']) {
        unset($_SESSION['cliente']);
        respond(['ok' => false, 'error' => 'Sesion expirada'], 401);
    }

    $session = $_SESSION['cliente'];

    $serviceId = $_REQUEST['service_id'] ?? null;
    $barberId = $_REQUEST['barber_id'] ?? null;
    $fecha = $_REQUEST['fecha'] ?? null;
    $hora = $_REQUEST['hora'] ?? null;
    $status = trim((string)($_REQUEST['status'] ?? 'Pendiente'));
    if ($status === '') {
        $status = 'Pendiente';
    }

    if ($serviceId === null || $barberId === null || !$fecha || !$hora) {
        respond(['ok' => false, 'error' => 'Faltan parametros'], 400);
    }

    // Normalizar hora a HH:MM:SS
    if (preg_match('/^(\d{2}):(\d{2})$/', (string)$hora)) {
        $hora = $hora . ':00';
    }

    // Validaciones basicas
    $clienteId = findClienteIdByCedula((string)($session['cedula'] ?? ''));
    if ($clienteId === null) {
        respond(['ok' => false, 'error' => 'Cliente no encontrado para la sesion'], 404);
    }

    $tenantSlug = basename(dirname(__DIR__, 2));
    $plan = MembershipPlan::forCommerceSlug($tenantSlug);
    $waitlist = false;
    $maxAppts = null;
    $currentAppts = null;
    if (is_array($plan)) {
        $maxAppts = MembershipPlan::maxAppointmentsMonth($plan);
        if ($maxAppts !== null) {
            $currentAppts = MembershipPlan::countLocalReservasThisMonth(AutoloadDB::all('reservas'));
            // Over monthly quota: still accept as Pendiente waitlist; Atender/Finalizar is blocked later.
            if ($currentAppts >= $maxAppts) {
                $waitlist = true;
                $status = 'Pendiente';
            }
        }
    }

    // Crear reserva
    $row = AutoloadDB::insert('reservas', [
        'ID_Cliente' => (int)$clienteId,
        'ID_Barber' => (int)$barberId,
        'ID_Servicio' => (int)$serviceId,
        'Hora_Reserva' => (string)$hora,
        'Fecha_Reserva' => (string)$fecha,
        'Status' => $status,
    ]);

    // Persist reservation snapshot in current session for quick access
    if (!isset($_SESSION['cliente']['reservas']) || !is_array($_SESSION['cliente']['reservas'])) {
        $_SESSION['cliente']['reservas'] = [];
    }
    $_SESSION['cliente']['reservas'][] = $row;
    $_SESSION['cliente']['cliente_id'] = $_SESSION['cliente']['cliente_id'] ?? (int)$clienteId;

    // Enqueue email + WhatsApp notifications via NotificationOutbox
    try {
        NotificationOutbox::enqueueLocalReservaCreated(
            $tenantSlug,
            $row,
            [
                'cliente_nombre' => buildClienteDisplayName($session),
                'cliente_email' => $session['email'] ?? '',
                'cliente_telefono' => $session['telefono'] ?? '',
                'cedula' => $session['cedula'] ?? '',
            ],
            (int)$serviceId
        );
    } catch (Throwable $e) {
        error_log('[reservas] NotificationOutbox enqueue failed: ' . $e->getMessage());
    }

    $payload = ['ok' => true, 'data' => $row, 'session' => $_SESSION['cliente'] ?? null];
    if ($waitlist) {
        $payload['waitlist'] = true;
        $payload['over_plan'] = true;
        $payload['max_appointments_month'] = $maxAppts;
        $payload['current'] = $currentAppts;
    }
    respond($payload);
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
?>
