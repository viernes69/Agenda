<?php
/**
 * Agenduy - Webhook: Mercado Pago
 *
 * URL para configurar en Mercado Pago:
 *   https://www.agenduy.uy/admin/api/webhook_mercadopago.php
 *
 * Para tiendas, el sistema agrega ?store_slug=... al crear la preferencia.
 * Para reservas, agrega ?appointment_slug=... al crear la preferencia.
 */
declare(strict_types=1);

$config = require __DIR__ . '/../../src/Core/bootstrap.php';

use Agenduy\Core\Database;
use Agenduy\Core\MercadoPago;
use Agenduy\Core\NotificationOutbox;
use Agenduy\Core\TenantLocalDb;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$raw = file_get_contents('php://input');
$data = json_decode($raw ?: '', true);
if (!is_array($data)) {
    $data = $_POST;
}

$type = (string)($data['type'] ?? $data['topic'] ?? $_GET['type'] ?? $_GET['topic'] ?? '');
$id = $data['data']['id']
    ?? $data['id']
    ?? $_GET['data_id']
    ?? $_GET['id']
    ?? null;

if ($type === '' || $id === null || $id === '') {
    echo json_encode(['ok' => false, 'error' => 'Notificacion sin tipo/id']);
    exit;
}

try {
    $db = Database::getInstance();

    $appointmentResult = handleAppointmentPaymentWebhook($db, $type, (string)$id, $data);
    if ($appointmentResult !== null) {
        echo json_encode($appointmentResult, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $storeResult = handleStorePaymentWebhook($db, $type, (string)$id, $data);
    if ($storeResult !== null) {
        echo json_encode($storeResult, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $sub = $db->fetchOne(
        'SELECT * FROM subscriptions WHERE gateway = :g AND gateway_id = :id',
        [':g' => 'mercadopago', ':id' => (string)$id]
    );
    if (!$sub) {
        echo json_encode(['ok' => true, 'note' => 'no matching subscription']);
        exit;
    }

    $newStatus = subscriptionStatusFromNotification($type);
    $db->update('subscriptions', [
        'status' => $newStatus,
        'updated_at' => date('Y-m-d H:i:s'),
    ], 'id_subscription = :id', [':id' => $sub['id_subscription']]);

    $db->update('commerces', [
        'status' => $newStatus === 'cancelled' ? 'cancelled' : ($newStatus === 'active' ? 'active' : 'past_due'),
        'updated_at' => date('Y-m-d H:i:s'),
    ], 'id_commerce = :id', [':id' => $sub['id_commerce']]);

    $db->insert('audit_log', [
        'id_user' => null,
        'action' => 'mp_webhook',
        'target_type' => 'subscription',
        'target_id' => (int)$sub['id_subscription'],
        'meta' => json_encode(['type' => $type, 'mp_id' => $id, 'new_status' => $newStatus], JSON_UNESCAPED_UNICODE),
        'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
    ]);

    echo json_encode(['ok' => true, 'status' => $newStatus], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

/**
 * @param array<string,mixed> $data
 * @return array<string,mixed>|null
 */
function handleAppointmentPaymentWebhook(Database $db, string $type, string $paymentId, array $data): ?array
{
    $appointmentSlug = trim((string)($_GET['appointment_slug'] ?? $data['appointment_slug'] ?? ''));
    $externalReference = trim((string)($data['external_reference'] ?? $data['externalReference'] ?? ''));
    $typeNorm = strtolower(trim($type));
    $payment = [];
    $row = null;
    $commerce = null;

    if ($externalReference !== '') {
        $row = appointmentPaymentByExternalReference($db, $externalReference);
    }
    if (!$row && $paymentId !== '') {
        $row = $db->fetchOne(
            'SELECT * FROM appointment_payments WHERE payment_id = :p LIMIT 1',
            [':p' => $paymentId]
        );
    }
    if ($row && $appointmentSlug === '') {
        $appointmentSlug = (string)$row['slug'];
    }

    $looksAppointment = $appointmentSlug !== ''
        || str_starts_with($externalReference, 'agenduy_appt_')
        || $row !== null;
    if (!$looksAppointment) {
        return null;
    }

    $canFetchPayment = $paymentId !== ''
        && ($typeNorm === 'payment' || str_contains($typeNorm, 'payment'));
    if ($appointmentSlug !== '' && $canFetchPayment) {
        $commerce = $db->fetchOne('SELECT * FROM commerces WHERE slug = :s LIMIT 1', [':s' => $appointmentSlug]);
        if ($commerce) {
            $mp = MercadoPago::commerceConfig((int)$commerce['id_commerce'], $appointmentSlug);
            if (trim((string)($mp['access_token'] ?? '')) !== '') {
                $payment = MercadoPago::getPayment($mp, $paymentId);
            }
        }
    }

    if ($payment !== []) {
        $externalReference = trim((string)($payment['external_reference'] ?? $externalReference));
    }
    if (!$row && $externalReference !== '') {
        $row = appointmentPaymentByExternalReference($db, $externalReference);
    }

    $parsed = parseAppointmentExternalReference($externalReference);
    if (!$row && $parsed === null) {
        return ['ok' => true, 'kind' => 'appointment_payment', 'note' => 'no matching appointment payment'];
    }

    $commerceId = $row ? (int)$row['id_commerce'] : (int)$parsed['id_commerce'];
    $appointmentId = $row ? (int)$row['id_appointment'] : (int)$parsed['id_appointment'];
    if ($appointmentSlug === '') {
        if ($row) {
            $appointmentSlug = (string)$row['slug'];
        } else {
            $slugRow = $db->fetchOne('SELECT slug FROM commerces WHERE id_commerce = :id LIMIT 1', [':id' => $commerceId]);
            $appointmentSlug = trim((string)($slugRow['slug'] ?? ''));
        }
    }

    $mpStatus = trim((string)($payment['status'] ?? $data['status'] ?? 'pending'));
    if ($mpStatus === '') {
        $mpStatus = 'pending';
    }
    $paymentStatus = MercadoPago::paymentStatusToStoreStatus($mpStatus);
    $statusDetail = trim((string)($payment['status_detail'] ?? $data['status_detail'] ?? ''));
    $merchantOrderId = '';
    if (isset($payment['order']) && is_array($payment['order'])) {
        $merchantOrderId = trim((string)($payment['order']['id'] ?? ''));
    }
    $amount = isset($payment['transaction_amount']) && is_numeric($payment['transaction_amount'])
        ? (float)$payment['transaction_amount']
        : (float)($row['amount'] ?? 0);
    $currency = trim((string)($payment['currency_id'] ?? $row['currency'] ?? 'UYU')) ?: 'UYU';
    $payerEmail = '';
    if (isset($payment['payer']) && is_array($payment['payer'])) {
        $payerEmail = trim((string)($payment['payer']['email'] ?? ''));
    }
    if ($payerEmail === '') {
        $payerEmail = trim((string)($row['payer_email'] ?? ''));
    }

    $previousPaymentStatus = trim((string)($row['status'] ?? ''));
    $update = [
        'payment_id' => $paymentId,
        'merchant_order_id' => $merchantOrderId,
        'status' => $paymentStatus,
        'status_detail' => mb_substr($statusDetail, 0, 250, 'UTF-8'),
        'amount' => $amount,
        'currency' => $currency,
        'payer_email' => $payerEmail,
        'updated_at' => date('Y-m-d H:i:s'),
    ];
    if ($row) {
        $db->update('appointment_payments', $update, 'id_appointment_payment = :id', [':id' => $row['id_appointment_payment']]);
    } elseif ($externalReference !== '') {
        $db->insert('appointment_payments', array_merge($update, [
            'id_commerce' => $commerceId,
            'slug' => $appointmentSlug,
            'id_appointment' => $appointmentId,
            'external_reference' => $externalReference,
        ]));
    }

    $centralStatus = appointmentCentralStatusFromPayment($paymentStatus);
    if ($centralStatus !== null && $appointmentId > 0) {
        $appointment = $db->fetchOne('SELECT notas FROM appointments WHERE id_appointment = :id LIMIT 1', [':id' => $appointmentId]);
        $patch = [
            'status' => $centralStatus,
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        if ($centralStatus === 'confirmed' && $appointment) {
            $patch['notas'] = cleanAppointmentPaymentMarkers((string)($appointment['notas'] ?? ''));
        } elseif ($centralStatus === 'cancelled' && $appointment) {
            $notes = cleanAppointmentPaymentMarkers((string)($appointment['notas'] ?? ''));
            $marker = 'mp-payment-' . $paymentStatus;
            $patch['notas'] = $notes !== '' ? ($marker . ' | ' . $notes) : $marker;
        }
        $db->update('appointments', $patch, 'id_appointment = :id AND id_commerce = :c', [
            ':id' => $appointmentId,
            ':c' => $commerceId,
        ]);
    }

    if (!$commerce && $appointmentSlug !== '') {
        $commerce = $db->fetchOne('SELECT * FROM commerces WHERE slug = :s LIMIT 1', [':s' => $appointmentSlug]);
    }
    $appointmentRow = appointmentRowForNotification($db, $appointmentId);
    if ($appointmentRow && $appointmentSlug !== '') {
        mirrorAppointmentPaymentStatus($appointmentSlug, array_replace($appointmentRow, [
            'Metodo_Pago' => 'Mercado Pago',
            'Payment_Status' => $paymentStatus,
            'MP_Payment_ID' => $paymentId,
            'MP_External_Reference' => $externalReference,
            'MP_Status_Detail' => mb_substr($statusDetail, 0, 250, 'UTF-8'),
        ]));
    }

    $db->insert('audit_log', [
        'id_user' => null,
        'action' => 'mp_appointment_webhook',
        'target_type' => 'appointment',
        'target_id' => $appointmentId,
        'meta' => json_encode([
            'type' => $type,
            'mp_id' => $paymentId,
            'external_reference' => $externalReference,
            'status' => $paymentStatus,
            'slug' => $appointmentSlug,
        ], JSON_UNESCAPED_UNICODE),
        'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
    ]);

    if ($commerce && $appointmentRow && $paymentStatus === 'approved' && $previousPaymentStatus !== 'approved') {
        try {
            $service = null;
            if (!empty($appointmentRow['id_service'])) {
                $service = $db->fetchOne('SELECT * FROM services WHERE id_service = :id LIMIT 1', [
                    ':id' => (int)$appointmentRow['id_service'],
                ]);
            }
            NotificationOutbox::enqueueAppointmentNotifications($appointmentRow, $commerce, $service ?: null);
        } catch (Throwable $notifyError) {
            error_log('[webhook_mercadopago] outbox appointment paid: ' . $notifyError->getMessage());
        }
    } elseif ($paymentStatus !== 'approved'
        && $previousPaymentStatus === 'approved'
        && in_array($paymentStatus, ['cancelled', 'rejected', 'refunded', 'charged_back'], true)) {
        try {
            NotificationOutbox::enqueueAppointmentStatusNotifications($appointmentId, 'cancelled');
        } catch (Throwable $notifyError) {
            error_log('[webhook_mercadopago] outbox appointment cancelled: ' . $notifyError->getMessage());
        }
    }

    return [
        'ok' => true,
        'kind' => 'appointment_payment',
        'id_appointment' => $appointmentId,
        'status' => $paymentStatus,
    ];
}

function appointmentPaymentByExternalReference(Database $db, string $externalReference): ?array
{
    return $db->fetchOne(
        'SELECT * FROM appointment_payments WHERE external_reference = :r LIMIT 1',
        [':r' => $externalReference]
    );
}

/**
 * @return array{id_commerce:int,id_appointment:int}|null
 */
function parseAppointmentExternalReference(string $externalReference): ?array
{
    if (!preg_match('/^agenduy_appt_c(\d+)_a(\d+)_/i', $externalReference, $matches)) {
        return null;
    }
    return [
        'id_commerce' => (int)$matches[1],
        'id_appointment' => (int)$matches[2],
    ];
}

function appointmentCentralStatusFromPayment(string $paymentStatus): ?string
{
    return match ($paymentStatus) {
        'approved' => 'confirmed',
        'rejected', 'cancelled', 'refunded', 'charged_back' => 'cancelled',
        default => null,
    };
}

function cleanAppointmentPaymentMarkers(string $notes): string
{
    $parts = array_map('trim', explode('|', $notes));
    $parts = array_filter($parts, static function (string $part): bool {
        return $part !== ''
            && $part !== 'mp-payment-pending'
            && $part !== 'mp-payment-failed'
            && !preg_match('/^mp-payment-(approved|rejected|cancelled|refunded|charged_back|unknown)$/', $part);
    });
    return implode(' | ', $parts);
}

function appointmentRowForNotification(Database $db, int $appointmentId): ?array
{
    if ($appointmentId <= 0) {
        return null;
    }
    return $db->fetchOne(
        "SELECT a.*, cl.avatar AS client_avatar
         FROM appointments a
         LEFT JOIN clients cl ON cl.id_client = a.id_client
         WHERE a.id_appointment = :id
         LIMIT 1",
        [':id' => $appointmentId]
    );
}

function mirrorAppointmentPaymentStatus(string $slug, array $appointment): void
{
    if ($slug === '' || !TenantLocalDb::exists($slug)) {
        return;
    }
    try {
        $mirror = TenantLocalDb::mirrorAppointment($slug, $appointment);
        if (is_array($mirror['row'] ?? null)) {
            $notifier = dirname(__DIR__, 2) . '/template/src/API/AdminPushNotifier.php';
            if (is_file($notifier)) {
                require_once $notifier;
                if (class_exists('AdminPushNotifier')) {
                    AdminPushNotifier::notifyReservation($mirror['row']);
                }
            }
        }
    } catch (Throwable $e) {
        error_log('[webhook_mercadopago] mirror appointment: ' . $e->getMessage());
    }
}

/**
 * @param array<string,mixed> $data
 * @return array<string,mixed>|null
 */
function handleStorePaymentWebhook(Database $db, string $type, string $paymentId, array $data): ?array
{
    $storeSlug = trim((string)($_GET['store_slug'] ?? $data['store_slug'] ?? ''));
    $externalReference = trim((string)($data['external_reference'] ?? $data['externalReference'] ?? ''));
    $typeNorm = strtolower(trim($type));
    $looksStore = $storeSlug !== ''
        || str_starts_with($externalReference, 'agenduy_store_')
        || $typeNorm === 'payment';

    if (!$looksStore) {
        return null;
    }

    $payment = [];
    $row = null;
    $commerce = null;
    if ($externalReference !== '') {
        $row = storePaymentByExternalReference($db, $externalReference);
    }
    if (!$row && $paymentId !== '') {
        $row = $db->fetchOne(
            'SELECT * FROM store_order_payments WHERE payment_id = :p LIMIT 1',
            [':p' => $paymentId]
        );
    }
    if ($row && $storeSlug === '') {
        $storeSlug = (string)$row['slug'];
    }
    if ($storeSlug !== '') {
        $commerce = $db->fetchOne('SELECT * FROM commerces WHERE slug = :s LIMIT 1', [':s' => $storeSlug]);
        if ($commerce) {
            $mp = MercadoPago::commerceConfig((int)$commerce['id_commerce'], $storeSlug);
            if (trim((string)($mp['access_token'] ?? '')) !== '') {
                $payment = MercadoPago::getPayment($mp, $paymentId);
            }
        }
    }

    if ($payment !== []) {
        $externalReference = trim((string)($payment['external_reference'] ?? $externalReference));
    }
    if (!$row && $externalReference !== '') {
        $row = storePaymentByExternalReference($db, $externalReference);
    }

    $parsed = parseStoreExternalReference($externalReference);
    if (!$row && $parsed === null) {
        return ['ok' => true, 'kind' => 'store_order', 'note' => 'no matching store order'];
    }

    $commerceId = $row ? (int)$row['id_commerce'] : (int)$parsed['id_commerce'];
    $orderId = $row ? (int)$row['local_order_id'] : (int)$parsed['local_order_id'];
    if ($storeSlug === '') {
        if ($row) {
            $storeSlug = (string)$row['slug'];
        } else {
            $slugRow = $db->fetchOne('SELECT slug FROM commerces WHERE id_commerce = :id LIMIT 1', [':id' => $commerceId]);
            $storeSlug = trim((string)($slugRow['slug'] ?? ''));
        }
    }

    $mpStatus = trim((string)($payment['status'] ?? $data['status'] ?? 'pending'));
    if ($mpStatus === '') {
        $mpStatus = 'pending';
    }
    $storeStatus = MercadoPago::paymentStatusToStoreStatus($mpStatus);
    $statusDetail = trim((string)($payment['status_detail'] ?? $data['status_detail'] ?? ''));
    $merchantOrderId = '';
    if (isset($payment['order']) && is_array($payment['order'])) {
        $merchantOrderId = trim((string)($payment['order']['id'] ?? ''));
    }
    $amount = isset($payment['transaction_amount']) && is_numeric($payment['transaction_amount'])
        ? (float)$payment['transaction_amount']
        : (float)($row['amount'] ?? 0);
    $currency = trim((string)($payment['currency_id'] ?? $row['currency'] ?? 'UYU')) ?: 'UYU';

    $update = [
        'payment_id' => $paymentId,
        'merchant_order_id' => $merchantOrderId,
        'status' => $storeStatus,
        'status_detail' => mb_substr($statusDetail, 0, 250, 'UTF-8'),
        'amount' => $amount,
        'currency' => $currency,
        'updated_at' => date('Y-m-d H:i:s'),
    ];
    if ($row) {
        $db->update('store_order_payments', $update, 'id_store_payment = :id', [':id' => $row['id_store_payment']]);
    } elseif ($externalReference !== '') {
        $db->insert('store_order_payments', array_merge($update, [
            'id_commerce' => $commerceId,
            'slug' => $storeSlug,
            'local_order_id' => $orderId,
            'external_reference' => $externalReference,
            'items_json' => '[]',
        ]));
    }

    $localOrderRow = null;
    if ($storeSlug !== '' && $orderId > 0 && TenantLocalDb::exists($storeSlug)) {
        $localOrderRow = TenantLocalDb::updateCartOrder($storeSlug, $orderId, [
            'Status' => MercadoPago::paymentStatusToLocalCartStatus($mpStatus),
            'Metodo_Pago' => 'Mercado Pago',
            'Payment_Status' => $storeStatus,
            'MP_Payment_ID' => $paymentId,
            'MP_External_Reference' => $externalReference,
            'MP_Status_Detail' => mb_substr($statusDetail, 0, 250, 'UTF-8'),
        ]);
    }

    $db->insert('audit_log', [
        'id_user' => null,
        'action' => 'mp_store_webhook',
        'target_type' => 'store_order',
        'target_id' => $orderId,
        'meta' => json_encode([
            'type' => $type,
            'mp_id' => $paymentId,
            'external_reference' => $externalReference,
            'status' => $storeStatus,
            'slug' => $storeSlug,
        ], JSON_UNESCAPED_UNICODE),
        'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
    ]);

    if ($storeStatus === 'approved') {
        try {
            if (!$commerce && $storeSlug !== '') {
                $commerce = $db->fetchOne('SELECT * FROM commerces WHERE slug = :s LIMIT 1', [':s' => $storeSlug]);
            }
            if ($commerce) {
                $paymentRow = is_array($row) ? $row : [];
                $items = json_decode((string)($paymentRow['items_json'] ?? '[]'), true);
                if (!is_array($items)) {
                    $items = [];
                }
                $payerEmail = '';
                if (isset($payment['payer']) && is_array($payment['payer'])) {
                    $payerEmail = trim((string)($payment['payer']['email'] ?? ''));
                }
                if ($payerEmail === '') {
                    $payerEmail = trim((string)($paymentRow['payer_email'] ?? ''));
                }
                $orderForNotify = is_array($localOrderRow) ? $localOrderRow : [];
                $orderForNotify = array_replace($orderForNotify, [
                    'ID_Carrito' => $orderId,
                    'Status' => MercadoPago::paymentStatusToLocalCartStatus($mpStatus),
                    'Payment_Status' => $storeStatus,
                    'MP_Payment_ID' => $paymentId,
                    'MP_External_Reference' => $externalReference,
                    'Total' => number_format($amount, 2, '.', ''),
                    'currency' => $currency,
                    'checkout_url' => trim((string)($paymentRow['checkout_url'] ?? '')),
                ]);
                NotificationOutbox::enqueueStoreOrderNotifications($commerce, $orderForNotify, $items, [
                    'cliente_email' => $payerEmail,
                    'pago_url' => trim((string)($paymentRow['checkout_url'] ?? '')),
                ], 'paid');
            }
        } catch (Throwable $notifyError) {
            error_log('[webhook_mercadopago] outbox paid: ' . $notifyError->getMessage());
        }
    }

    return [
        'ok' => true,
        'kind' => 'store_order',
        'order_id' => $orderId,
        'status' => $storeStatus,
    ];
}

function storePaymentByExternalReference(Database $db, string $externalReference): ?array
{
    return $db->fetchOne(
        'SELECT * FROM store_order_payments WHERE external_reference = :r LIMIT 1',
        [':r' => $externalReference]
    );
}

/**
 * @return array{id_commerce:int,local_order_id:int}|null
 */
function parseStoreExternalReference(string $externalReference): ?array
{
    if (!preg_match('/^agenduy_store_c(\d+)_o(\d+)_/i', $externalReference, $matches)) {
        return null;
    }
    return [
        'id_commerce' => (int)$matches[1],
        'local_order_id' => (int)$matches[2],
    ];
}

function subscriptionStatusFromNotification(string $type): string
{
    $type = strtolower(trim($type));
    if (in_array($type, ['preapproval', 'subscription_authorized_payment'], true)) {
        return 'active';
    }
    if (in_array($type, ['subscription_cancelled', 'cancelled', 'canceled'], true)) {
        return 'cancelled';
    }
    if ($type === 'subscription_paused') {
        return 'past_due';
    }
    return 'pending';
}
