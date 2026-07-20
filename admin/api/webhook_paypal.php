<?php
/**
 * Agenduy - Webhook: PayPal
 *
 * URL para configurar en la app de PayPal:
 *   https://www.agenduy.uy/admin/api/webhook_paypal.php
 *
 * PayPal envía eventos de tipo:
 *   BILLING.SUBSCRIPTION.ACTIVATED
 *   BILLING.SUBSCRIPTION.CANCELLED
 *   BILLING.SUBSCRIPTION.SUSPENDED
 *   BILLING.SUBSCRIPTION.PAYMENT.FAILED
 *   PAYMENT.SALE.COMPLETED
 */
declare(strict_types=1);

$config = require __DIR__ . '/../../src/Core/bootstrap.php';

use Agenduy\Core\Database;

header('Content-Type: application/json; charset=utf-8');

$raw = file_get_contents('php://input');
$event = json_decode($raw, true);

if (!is_array($event) || empty($event['event_type'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'evento inválido']);
    exit;
}

try {
    $db = Database::getInstance();
    $type = (string)$event['event_type'];
    $subId = (string)($event['resource']['id'] ?? '');

    $sub = null;
    if ($subId !== '') {
        $sub = $db->fetchOne(
            'SELECT * FROM subscriptions WHERE gateway = :g AND gateway_id = :id',
            [':g' => 'paypal', ':id' => $subId]
        );
    }
    if (!$sub) {
        echo json_encode(['ok' => true, 'note' => 'no matching subscription']);
        exit;
    }

    $map = [
        'BILLING.SUBSCRIPTION.ACTIVATED'         => 'active',
        'BILLING.SUBSCRIPTION.RE-ACTIVATED'      => 'active',
        'BILLING.SUBSCRIPTION.SUSPENDED'         => 'past_due',
        'BILLING.SUBSCRIPTION.PAYMENT.FAILED'    => 'past_due',
        'BILLING.SUBSCRIPTION.CANCELLED'         => 'cancelled',
        'BILLING.SUBSCRIPTION.EXPIRED'           => 'cancelled',
    ];
    $newStatus = $map[$type] ?? null;
    if ($newStatus === null) {
        echo json_encode(['ok' => true, 'note' => 'event type ignored']);
        exit;
    }

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
        'action'  => 'paypal_webhook',
        'target_type' => 'subscription',
        'target_id'   => (int)$sub['id_subscription'],
        'meta'        => json_encode(['type' => $type, 'sub_id' => $subId, 'new_status' => $newStatus], JSON_UNESCAPED_UNICODE),
        'ip'          => $_SERVER['REMOTE_ADDR'] ?? '',
    ]);

    echo json_encode(['ok' => true, 'status' => $newStatus]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
