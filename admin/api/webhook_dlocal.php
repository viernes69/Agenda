<?php
/**
 * Agenduy - Webhook: dLocal Go
 *
 * URL: https://agenduy.uy/admin/api/webhook_dlocal.php?slug={slug}&source=plan
 *
 * dLocal notifica con HTTP POST y un body JSON. La firma va en el header
 * `Authorization: V2-HMAC-SHA256, Signature: <hex>`. La firma se calcula como
 *   HMAC-SHA256(api_key + raw_body, secret_key)
 *
 * Tipos de evento que recibimos (basado en la doc):
 *   - {"payment_id": "DP-XXX"}     -> pago individual (cobro recurrente o setup)
 *   - {"subscription_id": "..."}   -> cambio de estado de la suscripcion
 *
 * Estrategia:
 *   1. Leer raw body.
 *   2. Validar firma HMAC contra la config dLocal del tenant (?slug=...).
 *   3. Si hay payment_id, llamar a GET /v1/payments/{id} para obtener detalles.
 *   4. Matchear la suscripcion local por external_id (que pasamos en subscribe.php).
 *   5. Actualizar estado, fechas, y registrar audit.
 *
 * Importante: dLocal reintenta cada 10 min por 30 dias si no devolvemos 200.
 * Por eso, ante cualquier error devolvemos 200 (con ok=false) para que NO reintente.
 */
declare(strict_types=1);

use Agenduy\Core\Database;
use Agenduy\Core\Dlocal;
use Agenduy\Core\TenantLocalDb;

require_once __DIR__ . '/../../src/Core/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SERVER['REQUEST_METHOD']) || strtoupper((string)$_SERVER['REQUEST_METHOD']) !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
    exit;
}

$rawBody = (string)file_get_contents('php://input');
$auth    = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

try {
    $slug = trim((string)($_GET['slug'] ?? $_POST['slug'] ?? ''));
    if ($slug === '') {
        throw new RuntimeException('Missing slug query param.');
    }

    if (!TenantLocalDb::exists($slug)) {
        throw new RuntimeException('Tenant dLocal config not found for slug=' . $slug);
    }

    $tenantDb = TenantLocalDb::read($slug);
    $dlocalCfg = is_array($tenantDb) && isset($tenantDb['dlocal']) && is_array($tenantDb['dlocal'])
        ? $tenantDb['dlocal']
        : null;
    if ($dlocalCfg === null) {
        throw new RuntimeException('Tenant dLocal config not found for slug=' . $slug);
    }

    $client = Dlocal::fromConfig(['dlocal' => $dlocalCfg]);

    if (!$client->verifyWebhookSignature($rawBody, $auth)) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Invalid signature.']);
        exit;
    }

    $decoded = json_decode($rawBody, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Body is not valid JSON.');
    }

    $paymentId      = (string)($decoded['payment_id']      ?? '');
    $subscriptionId = (string)($decoded['subscription_id'] ?? '');
    $status         = (string)($decoded['status']          ?? '');
    $externalId     = (string)($decoded['external_id']     ?? '');

    // Si dLocal nos manda solo payment_id, vamos a buscar el detalle del pago.
    $paymentDetails = null;
    if ($paymentId !== '') {
        try {
            $paymentDetails = $client->request('GET', '/v1/payments/' . $paymentId);
        } catch (Throwable $e) {
            error_log('[dlocal_webhook] no se pudo recuperar pago ' . $paymentId . ': ' . $e->getMessage());
        }
    }

    // Determinar external_id (prioridad: del body -> de paymentDetails).
    if ($externalId === '' && is_array($paymentDetails) && !empty($paymentDetails['order_id'])) {
        $externalId = (string)$paymentDetails['order_id'];
    }

    $finalStatus = $status !== '' ? strtoupper($status) : strtoupper((string)($paymentDetails['status'] ?? 'UNKNOWN'));

    // Mapear status de dLocal a estado interno.
    $internalStatus = match (true) {
        $finalStatus === 'PAID'     => 'CONFIRMED',
        $finalStatus === 'CONFIRMED'=> 'CONFIRMED',
        $finalStatus === 'PENDING'  => 'CREATED',
        $finalStatus === 'CREATED'  => 'CREATED',
        $finalStatus === 'REJECTED' => 'REJECTED',
        $finalStatus === 'CANCELLED'=> 'CANCELLED',
        $finalStatus === 'CANCELED' => 'CANCELLED',
        $finalStatus === 'EXPIRED'  => 'EXPIRED',
        default                     => 'PENDING',
    };

    $matched = false;
    $matchedId = null;
    if ($externalId !== '') {
        [$matched, $matchedId] = TenantLocalDb::mutate($slug, function (array $db) use ($externalId, $internalStatus, $paymentId, $subscriptionId, $paymentDetails) {
            $subs = isset($db['suscripciones_cliente']) && is_array($db['suscripciones_cliente'])
                ? $db['suscripciones_cliente']
                : [];
            $matched = false;
            $matchedId = null;
            foreach ($subs as $sid => $row) {
                if (!is_array($row)) {
                    continue;
                }
                if (($row['external_id'] ?? '') === $externalId) {
                    $row['status']              = $internalStatus;
                    $row['dlocal_payment_id']   = $paymentId !== '' ? $paymentId : ($row['dlocal_payment_id'] ?? null);
                    $row['dlocal_subscription_id'] = $subscriptionId !== '' ? $subscriptionId : ($row['dlocal_subscription_id'] ?? null);
                    $row['updated_at']          = date('Y-m-d H:i:s');
                    if ($internalStatus === 'CONFIRMED' && empty($row['confirmed_at'])) {
                        $row['confirmed_at'] = date('Y-m-d H:i:s');
                    }
                    if ($internalStatus === 'CANCELLED' && empty($row['cancelled_at'])) {
                        $row['cancelled_at'] = date('Y-m-d H:i:s');
                    }
                    if (is_array($paymentDetails) && !empty($paymentDetails['approved_date'])) {
                        $row['last_payment_at'] = (string)$paymentDetails['approved_date'];
                    }
                    $db['suscripciones_cliente'][$sid] = $row;
                    $matched = true;
                    $matchedId = $sid;
                    break;
                }
            }
            return [$db, [$matched, $matchedId]];
        });
    }

    // Log auditable en SQLite central.
    $commerce = Database::getInstance()->fetchOne('SELECT id_commerce FROM commerces WHERE slug = :s', [':s' => $slug]);
    if ($commerce) {
        Database::getInstance()->insert('audit_log', [
            'id_user'     => null,
            'action'      => 'dlocal_webhook',
            'target_type' => 'subscription',
            'target_id'   => (int)$commerce['id_commerce'],
            'meta'        => json_encode([
                'payment_id'      => $paymentId,
                'subscription_id' => $subscriptionId,
                'status'          => $finalStatus,
                'internal_status' => $internalStatus,
                'external_id'     => $externalId,
                'matched_id'      => $matchedId,
                'matched'         => $matched,
                'sandbox'         => $client->isSandbox(),
            ], JSON_UNESCAPED_UNICODE),
            'ip'          => substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64),
            'user_agent'  => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ]);
    }

    http_response_code(200);
    echo json_encode([
        'ok'              => true,
        'matched'         => $matched,
        'matched_id'      => $matchedId,
        'internal_status' => $internalStatus,
        'external_id'     => $externalId,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[dlocal_webhook] ' . $e->getMessage());
    http_response_code(200);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
