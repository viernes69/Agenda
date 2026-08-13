<?php
/**
 * Agenduy - API: registra retornos publicos de Mercado Pago.
 */
declare(strict_types=1);

$config = require __DIR__ . '/../../src/Core/bootstrap.php';

use Agenduy\Core\CSRF;
use Agenduy\Core\Database;
use Agenduy\Core\MercadoPago;
use Agenduy\Core\RateLimiter;
use Agenduy\Core\Security;
use Agenduy\Core\TenantLocalDb;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Metodo no permitido'], JSON_UNESCAPED_UNICODE);
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

try {
    RateLimiter::enforce('public_mp_return_ip', Security::clientIp(), 3600, 80);

    $db = Database::getInstance();
    $slug = trim((string)($payload['slug'] ?? ''));
    $kind = strtolower(trim((string)($payload['kind'] ?? '')));
    $paymentStatus = normalizeMercadoPagoReturnStatus($payload);
    $isFailure = in_array($paymentStatus, ['rejected', 'cancelled', 'charged_back'], true);

    if ($slug === '') {
        throw new InvalidArgumentException('Falta slug del comercio.');
    }
    if (!in_array($kind, ['appointment', 'store'], true)) {
        throw new InvalidArgumentException('Tipo de retorno invalido.');
    }

    $commerce = $db->fetchOne('SELECT * FROM commerces WHERE slug = :s LIMIT 1', [':s' => $slug]);
    if (!$commerce) {
        throw new RuntimeException('Comercio no encontrado.');
    }

    if (!$isFailure) {
        echo json_encode(['ok' => true, 'status' => $paymentStatus, 'updated' => false], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $commerceId = (int)($commerce['id_commerce'] ?? 0);
    $externalReference = trim((string)($payload['external_reference'] ?? $payload['mp_ref'] ?? ''));
    $preferenceId = trim((string)($payload['preference_id'] ?? $payload['pref_id'] ?? ''));
    $paymentId = trim((string)($payload['payment_id'] ?? $payload['collection_id'] ?? ''));
    $statusDetail = trim((string)($payload['status_detail'] ?? ''));
    if ($statusDetail === '') {
        $statusDetail = 'return_failure';
    }

    if ($externalReference === '' && $preferenceId === '' && $paymentId === '') {
        throw new InvalidArgumentException('No se pudo validar la referencia del pago.');
    }

    if ($kind === 'appointment') {
        $appointmentId = (int)($payload['appointment_id'] ?? $payload['mp_appointment'] ?? 0);
        if ($appointmentId <= 0) {
            throw new InvalidArgumentException('Falta reserva.');
        }
        $paymentRow = findMercadoPagoPaymentRow($db, 'appointment_payments', 'id_appointment', $commerceId, $appointmentId, $externalReference, $preferenceId, $paymentId);
        if (!$paymentRow) {
            throw new RuntimeException('No se encontro el pago de la reserva.');
        }
        if (trim((string)($paymentRow['status'] ?? '')) === 'approved') {
            echo json_encode(['ok' => true, 'status' => 'approved', 'updated' => false], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $paymentUpdate = mercadoPagoPaymentReturnUpdate($paymentStatus, $statusDetail, $preferenceId, $paymentId);
        $db->update('appointment_payments', $paymentUpdate, 'id_appointment_payment = :id', [
            ':id' => (int)$paymentRow['id_appointment_payment'],
        ]);

        $appointment = $db->fetchOne(
            'SELECT * FROM appointments WHERE id_commerce = :c AND id_appointment = :id LIMIT 1',
            [':c' => $commerceId, ':id' => $appointmentId]
        );
        if (!$appointment) {
            throw new RuntimeException('No se encontro la reserva asociada al pago.');
        }

        $notes = appendMercadoPagoPaymentMarker((string)($appointment['notas'] ?? ''), 'mp-payment-' . $paymentStatus);
        $db->update('appointments', [
            'status' => 'cancelled',
            'notas' => $notes,
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id_commerce = :c AND id_appointment = :id', [
            ':c' => $commerceId,
            ':id' => $appointmentId,
        ]);

        if (TenantLocalDb::exists($slug)) {
            TenantLocalDb::mirrorAppointment($slug, array_replace($appointment, [
                'status' => 'cancelled',
                'notas' => $notes,
                'Metodo_Pago' => 'Mercado Pago',
                'Payment_Status' => $paymentStatus,
                'MP_Preference_ID' => $preferenceId !== '' ? $preferenceId : (string)($paymentRow['preference_id'] ?? ''),
                'MP_Payment_ID' => $paymentId !== '' ? $paymentId : (string)($paymentRow['payment_id'] ?? ''),
                'MP_External_Reference' => $externalReference !== '' ? $externalReference : (string)($paymentRow['external_reference'] ?? ''),
                'MP_Status_Detail' => mb_substr($statusDetail, 0, 250, 'UTF-8'),
            ]));
        }

        echo json_encode(['ok' => true, 'status' => $paymentStatus, 'updated' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $orderId = (int)($payload['order_id'] ?? $payload['mp_order'] ?? 0);
    if ($orderId <= 0) {
        throw new InvalidArgumentException('Falta pedido.');
    }
    $paymentRow = findMercadoPagoPaymentRow($db, 'store_order_payments', 'local_order_id', $commerceId, $orderId, $externalReference, $preferenceId, $paymentId);
    if (!$paymentRow) {
        throw new RuntimeException('No se encontro el pago del pedido.');
    }
    if (trim((string)($paymentRow['status'] ?? '')) === 'approved') {
        echo json_encode(['ok' => true, 'status' => 'approved', 'updated' => false], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $paymentUpdate = mercadoPagoPaymentReturnUpdate($paymentStatus, $statusDetail, $preferenceId, $paymentId);
    $db->update('store_order_payments', $paymentUpdate, 'id_store_payment = :id', [
        ':id' => (int)$paymentRow['id_store_payment'],
    ]);

    if (TenantLocalDb::exists($slug)) {
        TenantLocalDb::updateCartOrder($slug, $orderId, [
            'Status' => TenantLocalDb::MP_PAYMENT_CANCEL_STATUS,
            'Metodo_Pago' => 'Mercado Pago',
            'Payment_Status' => $paymentStatus,
            'MP_Preference_ID' => $preferenceId !== '' ? $preferenceId : (string)($paymentRow['preference_id'] ?? ''),
            'MP_Payment_ID' => $paymentId !== '' ? $paymentId : (string)($paymentRow['payment_id'] ?? ''),
            'MP_External_Reference' => $externalReference !== '' ? $externalReference : (string)($paymentRow['external_reference'] ?? ''),
            'MP_Status_Detail' => mb_substr($statusDetail, 0, 250, 'UTF-8'),
        ]);
    }

    echo json_encode(['ok' => true, 'status' => $paymentStatus, 'updated' => true], JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    error_log('[mercadopago_return] ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'No se pudo registrar el retorno de Mercado Pago.'], JSON_UNESCAPED_UNICODE);
}

/**
 * @param array<string,mixed> $payload
 */
function normalizeMercadoPagoReturnStatus(array $payload): string
{
    $raw = strtolower(trim((string)($payload['mp_status']
        ?? $payload['collection_status']
        ?? $payload['status']
        ?? '')));
    $raw = str_replace(['_', '-'], ' ', $raw);
    $raw = preg_replace('/\s+/', ' ', $raw) ?? $raw;
    return match ($raw) {
        'success', 'approved', 'accredited' => 'approved',
        'pending', 'authorized', 'in process', 'in mediation' => 'pending',
        'failure', 'failed', 'error', 'rejected' => 'rejected',
        'cancelled', 'canceled', 'cancelado' => 'cancelled',
        'charged back' => 'charged_back',
        default => MercadoPago::paymentStatusToStoreStatus($raw),
    };
}

/**
 * @return array<string,mixed>|null
 */
function findMercadoPagoPaymentRow(
    Database $db,
    string $table,
    string $entityColumn,
    int $commerceId,
    int $entityId,
    string $externalReference,
    string $preferenceId,
    string $paymentId
): ?array {
    if ($externalReference !== '') {
        return $db->fetchOne(
            "SELECT * FROM {$table} WHERE id_commerce = :c AND {$entityColumn} = :entity AND external_reference = :ref LIMIT 1",
            [':c' => $commerceId, ':entity' => $entityId, ':ref' => $externalReference]
        ) ?: null;
    }
    if ($preferenceId !== '') {
        return $db->fetchOne(
            "SELECT * FROM {$table} WHERE id_commerce = :c AND {$entityColumn} = :entity AND preference_id = :pref LIMIT 1",
            [':c' => $commerceId, ':entity' => $entityId, ':pref' => $preferenceId]
        ) ?: null;
    }
    if ($paymentId !== '') {
        return $db->fetchOne(
            "SELECT * FROM {$table} WHERE id_commerce = :c AND {$entityColumn} = :entity AND payment_id = :pay LIMIT 1",
            [':c' => $commerceId, ':entity' => $entityId, ':pay' => $paymentId]
        ) ?: null;
    }
    return null;
}

/**
 * @return array<string,string>
 */
function mercadoPagoPaymentReturnUpdate(string $status, string $statusDetail, string $preferenceId, string $paymentId): array
{
    $update = [
        'status' => $status,
        'status_detail' => mb_substr($statusDetail, 0, 250, 'UTF-8'),
        'updated_at' => date('Y-m-d H:i:s'),
    ];
    if ($preferenceId !== '') {
        $update['preference_id'] = $preferenceId;
    }
    if ($paymentId !== '') {
        $update['payment_id'] = $paymentId;
    }
    return $update;
}

function appendMercadoPagoPaymentMarker(string $notes, string $marker): string
{
    $parts = array_values(array_filter(array_map('trim', explode('|', $notes)), static function (string $part) use ($marker): bool {
        return $part !== '' && $part !== $marker;
    }));
    array_unshift($parts, $marker);
    return implode(' | ', $parts);
}
