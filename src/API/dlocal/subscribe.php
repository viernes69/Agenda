<?php
/**
 * Agenduy - API: Generar URL de checkout para que un cliente se suscriba
 *
 * POST /src/API/dlocal/subscribe.php
 *   - slug
 *   - _csrf
 *   - plan_internal_id   (string, id dentro de planes_cliente del tenant)
 *   - customer_email     (string, requerido)
 *   - customer_name      (string, opcional)
 *   - customer_document  (string, opcional)
 *
 * Devuelve la subscribe_url con email + external_id ya seteados para que
 * dLocal prellene el checkout y devuelva el external_id al success_url.
 */
declare(strict_types=1);

use Agenduy\Core\CSRF;
use Agenduy\Core\Database;
use Agenduy\Core\Dlocal;
use Agenduy\Core\TenantLocalDb;

require_once __DIR__ . '/../../Core/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

if (!isset($_SERVER['REQUEST_METHOD']) || strtoupper((string)$_SERVER['REQUEST_METHOD']) !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Metodo no permitido. Usa POST.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $raw = (string)file_get_contents('php://input');
    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        $payload = $_POST;
    }

    $csrf = (string)($payload['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!CSRF::check('public_booking', $csrf)) {
        http_response_code(428);
        echo json_encode([
            'ok'   => false,
            'error' => 'csrf_retry',
            'csrf' => CSRF::generate('public_booking'),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $slug = trim((string)($payload['slug'] ?? ''));
    if ($slug === '') {
        throw new InvalidArgumentException('Falta slug del comercio.');
    }

    $planInternalId = trim((string)($payload['plan_internal_id'] ?? ''));
    if ($planInternalId === '') {
        throw new InvalidArgumentException('Falta plan_internal_id.');
    }

    $customerEmail = trim((string)($payload['customer_email'] ?? ''));
    if ($customerEmail === '' || !filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Email del cliente invalido.');
    }

    $customerName = trim((string)($payload['customer_name'] ?? ''));
    $customerDoc  = trim((string)($payload['customer_document'] ?? ''));

    $commerce = Database::getInstance()->fetchOne('SELECT * FROM commerces WHERE slug = :s', [':s' => $slug]);
    if (!$commerce) {
        throw new RuntimeException('Comercio no encontrado.');
    }
    if (in_array((string)$commerce['status'], ['cancelled', 'suspended'], true)) {
        throw new RuntimeException('Este comercio no esta aceptando pagos.');
    }

    if (!TenantLocalDb::exists($slug)) {
        throw new RuntimeException('Este comercio no tiene base local.');
    }

    $tenantDb = TenantLocalDb::read($slug);
    $dlocalCfg = is_array($tenantDb) && isset($tenantDb['dlocal']) && is_array($tenantDb['dlocal'])
        ? $tenantDb['dlocal']
        : null;
    if ($dlocalCfg === null) {
        throw new RuntimeException('Este comercio no tiene dLocal configurado.');
    }
    $client = Dlocal::fromConfig(['dlocal' => $dlocalCfg]);

    $planes = is_array($tenantDb) && isset($tenantDb['planes_cliente']) && is_array($tenantDb['planes_cliente'])
        ? $tenantDb['planes_cliente']
        : [];
    $plan = null;
    foreach ($planes as $row) {
        if (is_array($row) && (string)($row['id'] ?? '') === $planInternalId) {
            $plan = $row;
            break;
        }
    }
    if (!$plan) {
        throw new RuntimeException('Plan no encontrado.');
    }
    if (empty($plan['dlocal_plan_token'])) {
        throw new RuntimeException('Plan sin plan_token de dLocal.');
    }
    if (empty($plan['active'])) {
        throw new RuntimeException('Plan inactivo.');
    }

    // external_id: lo usamos para, en el success_url / webhook, identificar al cliente y al plan.
    $externalId = sprintf(
        'c%d_p%s_%s',
        (int)$commerce['id_commerce'],
        (string)($plan['dlocal_plan_id'] ?? '0'),
        substr(bin2hex(random_bytes(4)), 0, 8)
    );

    $subscribeUrl = $client->subscribeUrl(
        (string)$plan['dlocal_plan_token'],
        $customerEmail,
        $externalId
    );

    $subInternalId = bin2hex(random_bytes(6));
    TenantLocalDb::mutate($slug, function (array $db) use ($subInternalId, $commerce, $slug, $planInternalId, $plan, $externalId, $customerEmail, $customerName, $customerDoc) {
        if (!isset($db['suscripciones_cliente']) || !is_array($db['suscripciones_cliente'])) {
            $db['suscripciones_cliente'] = [];
        }
        $db['suscripciones_cliente'][$subInternalId] = [
            'id'                  => $subInternalId,
            'id_commerce'         => (int)$commerce['id_commerce'],
            'slug'                => $slug,
            'plan_internal_id'    => $planInternalId,
            'dlocal_plan_id'      => (int)($plan['dlocal_plan_id'] ?? 0),
            'plan_token'          => (string)$plan['dlocal_plan_token'],
            'external_id'         => $externalId,
            'customer_email'      => $customerEmail,
            'customer_name'       => $customerName,
            'customer_document'   => $customerDoc,
            'status'              => 'CREATED',
            'created_at'          => date('Y-m-d H:i:s'),
            'updated_at'          => date('Y-m-d H:i:s'),
        ];
        return [$db, null];
    });

    echo json_encode([
        'ok'             => true,
        'subscribe_url'  => $subscribeUrl,
        'external_id'    => $externalId,
        'suscripcion_id' => $subInternalId,
        'plan_name'      => (string)($plan['name'] ?? ''),
        'amount'         => (float)($plan['amount'] ?? 0),
        'currency'       => (string)($plan['currency'] ?? 'UYU'),
        'frequency_type' => (string)($plan['frequency_type'] ?? 'MONTHLY'),
        'sandbox'        => $client->isSandbox(),
    ], JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (RuntimeException $e) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[dlocal_subscribe] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error interno.'], JSON_UNESCAPED_UNICODE);
}
