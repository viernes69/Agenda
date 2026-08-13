<?php
/**
 * Agenduy - API: cancelar reserva publica por cedula.
 */
declare(strict_types=1);

$config = require __DIR__ . '/../../src/Core/bootstrap.php';

use Agenduy\Core\CSRF;
use Agenduy\Core\Database;
use Agenduy\Core\MagicLink;
use Agenduy\Core\NotificationOutbox;
use Agenduy\Core\RateLimiter;
use Agenduy\Core\Security;
use Agenduy\Core\TenantLocalDb;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Metodo no permitido']);
    exit;
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw ?: '', true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$csrf = $payload['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
if (!CSRF::validate(is_string($csrf) ? $csrf : null, 'public_booking', false)) {
    http_response_code(428);
    echo json_encode([
        'ok' => false,
        'error' => 'csrf_retry',
        'csrf' => CSRF::generate('public_booking'),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

RateLimiter::enforce('public_cancel_ip', Security::clientIp(), 3600, 30);

try {
    $db = Database::getInstance();
    $slug = trim((string)($payload['slug'] ?? ''));
    $idAppointment = (int)($payload['id_appointment'] ?? $payload['id_reserva'] ?? $payload['reservation_id'] ?? 0);
    $cedula = MagicLink::normalizeCedula($payload['cedula'] ?? $payload['cliente_cedula'] ?? null);

    if ($slug === '') {
        throw new InvalidArgumentException('Falta slug del comercio.');
    }
    if ($idAppointment <= 0) {
        throw new InvalidArgumentException('Ingresá el número de reserva.');
    }
    if ($cedula === '') {
        throw new InvalidArgumentException('Ingresá tu cédula.');
    }

    $commerce = $db->fetchOne('SELECT * FROM commerces WHERE slug = :s LIMIT 1', [':s' => $slug]);
    if (!$commerce) {
        throw new RuntimeException('Comercio no encontrado.');
    }

    $appointment = $db->fetchOne(
        "SELECT a.*, cl.cedula AS client_cedula_db
         FROM appointments a
         LEFT JOIN clients cl ON cl.id_client = a.id_client
         WHERE a.id_commerce = :c AND a.id_appointment = :id
         LIMIT 1",
        [':c' => (int)$commerce['id_commerce'], ':id' => $idAppointment]
    );
    if (!$appointment) {
        throw new RuntimeException('No encontramos una reserva con esos datos.');
    }

    $storedCedulaRaw = trim((string)($appointment['cliente_cedula'] ?? ''));
    if ($storedCedulaRaw === '') {
        $storedCedulaRaw = (string)($appointment['client_cedula_db'] ?? '');
    }
    $storedCedula = MagicLink::normalizeCedula($storedCedulaRaw);
    if ($storedCedula === '' || $storedCedula !== $cedula) {
        throw new RuntimeException('No encontramos una reserva con esos datos.');
    }

    $status = strtolower(trim((string)($appointment['status'] ?? 'pending')));
    if (in_array($status, ['cancelled', 'done', 'no_show'], true)) {
        throw new RuntimeException('Esta reserva ya no se puede cancelar.');
    }

    $db->update(
        'appointments',
        ['status' => 'cancelled', 'updated_at' => date('Y-m-d H:i:s')],
        'id_appointment = :id AND id_commerce = :c',
        [':id' => $idAppointment, ':c' => (int)$commerce['id_commerce']]
    );

    try {
        if (TenantLocalDb::exists($slug)) {
            TenantLocalDb::mirrorAppointment($slug, array_replace($appointment, [
                'id_appointment' => $idAppointment,
                'id_commerce' => (int)$commerce['id_commerce'],
                'status' => 'cancelled',
            ]));
        }
    } catch (Throwable $e) {
        error_log('[cancel_appointment] mirror local: ' . $e->getMessage());
    }

    NotificationOutbox::enqueueAppointmentStatusNotifications($idAppointment, 'cancelled');

    echo json_encode([
        'ok' => true,
        'id_appointment' => $idAppointment,
        'status' => 'cancelled',
        'message' => 'Reserva cancelada.',
    ], JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
