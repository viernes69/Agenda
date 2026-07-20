<?php
/**
 * Agenduy - API: Crear plan recurrente en dLocal Go
 *
 * POST /src/API/dlocal/create_plan.php
 *   - _csrf
 *   - name           (string, requerido)
 *   - description    (string, requerido)
 *   - currency       (ISO 4217, default UYU)
 *   - amount         (numeric, requerido)
 *   - frequency_type (DAILY|WEEKLY|MONTHLY|YEARLY, default MONTHLY)
 *   - frequency_value (int, default 1)
 *   - max_periods    (int, opcional)
 *   - free_trial_days (int, opcional)
 *
 * Crea el plan en dLocal, guarda la respuesta en la DB del tenant bajo
 * `planes_cliente`, y devuelve el plan_token + subscribe_url al admin.
 */
declare(strict_types=1);

use Agenduy\Core\Auth;
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
    Auth::start();
    if (!Auth::check() || Auth::role() !== 'commerce_admin') {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'No autorizado.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $idCommerce = (int)Auth::commerceId();
    if ($idCommerce <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Cuenta sin comercio asignado.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $raw = (string)file_get_contents('php://input');
    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        $payload = $_POST;
    }

    if (!CSRF::check('dlocal_plan', (string)($payload['_csrf'] ?? ''))) {
        http_response_code(419);
        echo json_encode(['ok' => false, 'error' => 'Sesion expirada, recarga la pagina.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $commerce = Database::getInstance()->fetchOne(
        'SELECT id_commerce, slug, pais FROM commerces WHERE id_commerce = :id',
        [':id' => $idCommerce]
    );
    if (!$commerce) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Comercio no encontrado.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $slug = (string)$commerce['slug'];

    if (!TenantLocalDb::exists($slug)) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Base local del comercio no encontrada.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $tenantDb = TenantLocalDb::read($slug);
    $dlocalCfg = is_array($tenantDb) && isset($tenantDb['dlocal']) && is_array($tenantDb['dlocal'])
        ? $tenantDb['dlocal']
        : null;
    if ($dlocalCfg === null) {
        throw new RuntimeException('dLocal no esta configurado. Carga tus credenciales en Config > dLocal.');
    }
    $client = Dlocal::fromConfig(['dlocal' => $dlocalCfg]);

    // Validar inputs
    $name        = trim((string)($payload['name'] ?? ''));
    $description = trim((string)($payload['description'] ?? ''));
    $currency    = strtoupper(trim((string)($payload['currency'] ?? 'UYU')));
    $amount      = (float)($payload['amount'] ?? 0);
    $freqType    = strtoupper(trim((string)($payload['frequency_type'] ?? 'MONTHLY')));
    $freqValue   = max(1, (int)($payload['frequency_value'] ?? 1));
    $maxPeriods  = (int)($payload['max_periods'] ?? 0);
    $trialDays   = (int)($payload['free_trial_days'] ?? 0);
    $country     = strtoupper(trim((string)($payload['country'] ?? ($commerce['pais'] ?? 'UY'))));

    if ($name === '' || $description === '') {
        throw new InvalidArgumentException('Nombre y descripcion son obligatorios.');
    }
    if ($amount <= 0) {
        throw new InvalidArgumentException('Monto debe ser mayor a 0.');
    }
    if (!in_array($freqType, ['DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY'], true)) {
        throw new InvalidArgumentException('Frecuencia invalida.');
    }

    $webhookBase   = rtrim((string)url_base(), '/') . '/admin/api/webhook_dlocal.php';
    $notificationUrl = $webhookBase . '?slug=' . rawurlencode($slug) . '&source=plan';
    $successUrl    = rtrim((string)url_base(), '/') . '/' . rawurlencode($slug) . '/?dlocal_plan_success=1';
    $backUrl       = rtrim((string)url_base(), '/') . '/' . rawurlencode($slug) . '/?dlocal_plan_back=1';
    $errorUrl      = rtrim((string)url_base(), '/') . '/' . rawurlencode($slug) . '/?dlocal_plan_error=1';

    $planPayload = [
        'name'             => $name,
        'description'      => $description,
        'country'          => $country,
        'currency'         => $currency,
        'amount'           => round($amount, 2),
        'frequency_type'   => $freqType,
        'frequency_value'  => $freqValue,
        'notification_url' => $notificationUrl,
        'success_url'      => $successUrl,
        'back_url'         => $backUrl,
        'error_url'        => $errorUrl,
    ];
    if ($maxPeriods > 0) {
        $planPayload['max_periods'] = $maxPeriods;
    }
    if ($trialDays > 0) {
        $planPayload['free_trial_days'] = $trialDays;
    }

    $created = $client->createPlan($planPayload);

    $planToken    = (string)($created['plan_token'] ?? '');
    $planId       = (int)($created['id'] ?? 0);
    $subscribeUrl = (string)($created['subscribe_url'] ?? $client->subscribeUrl($planToken));

    if ($planToken === '' || $planId === 0) {
        throw new RuntimeException('dLocal devolvio un plan sin plan_token o id.');
    }

    $internalId = bin2hex(random_bytes(6));
    TenantLocalDb::mutate($slug, function (array $db) use ($internalId, $idCommerce, $slug, $planId, $planToken, $name, $description, $currency, $amount, $freqType, $freqValue, $maxPeriods, $trialDays, $country, $created) {
        if (!isset($db['planes_cliente']) || !is_array($db['planes_cliente'])) {
            $db['planes_cliente'] = [];
        }
        $db['planes_cliente'][$internalId] = [
            'id'                => $internalId,
            'id_commerce'       => $idCommerce,
            'slug'              => $slug,
            'dlocal_plan_id'    => $planId,
            'dlocal_plan_token' => $planToken,
            'name'              => $name,
            'description'       => $description,
            'currency'          => $currency,
            'amount'            => round($amount, 2),
            'frequency_type'    => $freqType,
            'frequency_value'   => $freqValue,
            'max_periods'       => $maxPeriods,
            'free_trial_days'   => $trialDays,
            'country'           => $country,
            'active'            => (bool)($created['active'] ?? true),
            'created_at'        => date('Y-m-d H:i:s'),
            'updated_at'        => date('Y-m-d H:i:s'),
        ];
        return [$db, null];
    });

    Database::getInstance()->insert('audit_log', [
        'id_user'     => (int)Auth::id(),
        'action'      => 'dlocal_plan_create',
        'target_type' => 'plan_cliente',
        'target_id'   => $idCommerce,
        'meta'        => json_encode([
            'plan_id'   => $planId,
            'name'      => $name,
            'amount'    => $amount,
            'currency'  => $currency,
            'frequency' => $freqType,
            'sandbox'   => $client->isSandbox(),
        ], JSON_UNESCAPED_UNICODE),
        'ip'          => substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64),
        'user_agent'  => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
    ]);

    echo json_encode([
        'ok'                => true,
        'internal_id'       => $internalId,
        'dlocal_plan_id'    => $planId,
        'plan_token'        => $planToken,
        'subscribe_url'     => $subscribeUrl,
        'active'            => (bool)($created['active'] ?? true),
        'frequency_type'    => $freqType,
        'frequency_value'   => $freqValue,
        'amount'            => round($amount, 2),
        'currency'          => $currency,
        'sandbox'           => $client->isSandbox(),
    ], JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (RuntimeException $e) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[dlocal_create_plan] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error interno.'], JSON_UNESCAPED_UNICODE);
}
