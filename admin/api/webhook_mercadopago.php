<?php
/**
 * Agenduy - Webhook: MercadoPago
 *
 * URL para configurar en el panel de MercadoPago:
 *   https://www.agenduy.uy/admin/api/webhook_mercadopago.php
 *
 * Recibe notificaciones de pagos y actualiza el estado de la
 * suscripción correspondiente.
 */
declare(strict_types=1);

$config = require __DIR__ . '/../../src/Core/bootstrap.php';

use Agenduy\Core\Database;

header('Content-Type: application/json; charset=utf-8');

$raw = file_get_contents('php://input');
$data = json_decode($raw, true) ?: $_POST;

$type = $data['type'] ?? $data['topic'] ?? '';
$id   = $data['data']['id'] ?? $data['id'] ?? null;

if ($type === '' || $id === null) {
    echo json_encode(['ok' => false, 'error' => 'Notificación sin tipo/id']);
    exit;
}

try {
    $db = Database::getInstance();
    $sub = $db->fetchOne(
        'SELECT * FROM subscriptions WHERE gateway = :g AND gateway_id = :id',
        [':g' => 'mercadopago', ':id' => (string)$id]
    );
    if (!$sub) {
        // No encontramos la suscripción, aceptamos igual
        echo json_encode(['ok' => true, 'note' => 'no matching subscription']);
        exit;
    }

    $newStatus = 'pending';
    if ($type === 'preapproval' || $type === 'subscription_authorized_payment') {
        $newStatus = 'active';
    } elseif ($type === 'subscription_cancelled' || $type === 'cancelled') {
        $newStatus = 'cancelled';
    } elseif ($type === 'subscription_paused') {
        $newStatus = 'past_due';
    }

    $db->update('subscriptions', [
        'status' => $newStatus,
        'updated_at' => date('Y-m-d H:i:s'),
    ], 'id_subscription = :id', [':id' => $sub['id_subscription']]);

    $db->update('commerces', [
        'status' => $newStatus === 'cancelled' ? 'cancelled' : ($newStatus === 'active' ? 'active' : 'past_due'),
        'updated_at' => date('Y-m-d H:i:s'),
    ], 'id_commerce = :id', [':id' => $sub['id_commerce']]);

    // Audit
    $db->insert('audit_log', [
        'id_user' => null,
        'action'  => 'mp_webhook',
        'target_type' => 'subscription',
        'target_id'   => (int)$sub['id_subscription'],
        'meta'        => json_encode(['type' => $type, 'mp_id' => $id, 'new_status' => $newStatus], JSON_UNESCAPED_UNICODE),
        'ip'          => $_SERVER['REMOTE_ADDR'] ?? '',
    ]);

    echo json_encode(['ok' => true, 'status' => $newStatus]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
