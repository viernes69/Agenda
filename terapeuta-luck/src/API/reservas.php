<?php
date_default_timezone_set('America/Montevideo');
session_start();
require_once __DIR__ . '/Autoload.php';
require_once __DIR__ . '/mail_helpers.php';

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . '/src/Core/bootstrap.php';

use Agenduy\Core\MembershipPlan;
use PHPMailer\PHPMailer\PHPMailer;

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

function sendReservationEmail(array $reservation, array $session, int $serviceId): void {
    try {
        $infoBarberia = AutoloadDB::getConfigSection('info_barberia');
    } catch (Throwable $e) {
        error_log('[reservas] No se pudo obtener info_barberia: ' . $e->getMessage());
        return;
    }

    $targetEmail = trim((string)($infoBarberia['contacto']['email'] ?? $infoBarberia['email'] ?? ''));
    if ($targetEmail === '') {
        agenduy_mail_log('No hay correo de contacto configurado en info_barberia; no se envía notificación.');
        return;
    }

    $mailConfig = agenduy_mail_get_config();
    $host = trim((string)($mailConfig['host'] ?? ''));
    $username = trim((string)($mailConfig['username'] ?? ''));
    $password = (string)($mailConfig['password'] ?? '');
    if ($host === '' || $username === '' || $password === '') {
        agenduy_mail_log('Configuración SMTP incompleta; falta host, usuario o contraseña.');
        return;
    }

    if (!agenduy_mail_require_phpmailer()) {
        agenduy_mail_log('PHPMailer no está disponible; verifica la instalación de composer.');
        return;
    }

    $serviceName = 'Servicio';
    try {
        $service = AutoloadDB::find('servicios', $serviceId);
        if (is_array($service)) {
            $candidate = trim((string)($service['Nombre'] ?? ''));
            if ($candidate !== '') {
                $serviceName = $candidate;
            }
        }
    } catch (Throwable $e) {
        error_log('[reservas] No se pudo leer servicio para notificación: ' . $e->getMessage());
    }

    $fecha = trim((string)($reservation['Fecha_Reserva'] ?? ''));
    $hora = formatReservaHour((string)($reservation['Hora_Reserva'] ?? ''));
    $cliente = buildClienteDisplayName($session);

    $subject = 'Tienes una nueva reserva';
    $summary = sprintf(
        "Tienes Una Nueva reserva!! para el día %s %s, con %s, Servicio: %s.",
        $fecha !== '' ? $fecha : 'sin fecha',
        $hora !== '' ? $hora : 'sin hora',
        $cliente,
        $serviceName
    );

    $fromEmail = trim((string)($mailConfig['from_email'] ?? $username));
    $fromName = trim((string)($mailConfig['from_name'] ?? 'Agenduy Reservas'));
    $port = (int)($mailConfig['port'] ?? 465);
    $timeout = max(5, (int)($mailConfig['timeout'] ?? 15));
    $encryption = strtolower(trim((string)($mailConfig['encryption'] ?? 'ssl')));

    $mailer = new PHPMailer(true);
    try {
        $mailer->CharSet = 'UTF-8';
        $mailer->isSMTP();
        $mailer->Host = $host;
        $mailer->SMTPAuth = true;
        $mailer->Username = $username;
        $mailer->Password = $password;
        $mailer->Port = $port;
        $mailer->Timeout = $timeout;
        if ($encryption === 'tls') {
            $mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        }

        $mailer->setFrom($fromEmail !== '' ? $fromEmail : $username, $fromName);
        $mailer->addAddress($targetEmail);

        $mailer->isHTML(false);
        $mailer->Subject = $subject;
        $mailer->Body = $summary;
        $mailer->AltBody = $summary;

        $mailer->send();
        agenduy_mail_log(sprintf(
            'Correo enviado a %s para reserva %s %s (%s).',
            $targetEmail,
            $fecha ?: 's/f',
            $hora ?: 's/h',
            $serviceName
        ));
    } catch (Throwable $e) {
        $message = '[reservas] No se pudo enviar notificación de reserva: ' . $e->getMessage();
        error_log($message);
        agenduy_mail_log($message);
    }
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
    if (is_array($plan)) {
        $maxAppts = MembershipPlan::maxAppointmentsMonth($plan);
        if ($maxAppts !== null) {
            $currentAppts = MembershipPlan::countLocalReservasThisMonth(AutoloadDB::all('reservas'));
            if ($currentAppts >= $maxAppts) {
                respond(MembershipPlan::denialPayload('PLAN_LIMIT_MAX_APPOINTMENTS_MONTH', [
                    'max_appointments_month' => $maxAppts,
                    'current' => $currentAppts,
                ]), 403);
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

    sendReservationEmail($row, $_SESSION['cliente'], (int)$serviceId);

    respond(['ok' => true, 'data' => $row, 'session' => $_SESSION['cliente'] ?? null]);
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
?>
