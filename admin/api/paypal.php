<?php
/**
 * Agenduy - API: PayPal (suscripciones SaaS del comercio)
 *
 * POST /admin/api/paypal.php?action=create_subscription
 * POST /admin/api/paypal.php?action=create_order
 * POST /admin/api/paypal.php?action=capture_order
 * POST /admin/api/paypal.php?action=create_plan (super admin)
 *
 * Requiere sesión commerce_admin (o super_admin para create_plan).
 */
declare(strict_types=1);

$config = require __DIR__ . '/../../src/Core/bootstrap.php';

use Agenduy\Core\Auth;
use Agenduy\Core\CSRF;
use Agenduy\Core\Database;
use Agenduy\Core\Crypto;
use Agenduy\Core\MembershipPlan;
use Agenduy\Core\Paypal;
use Agenduy\Core\ProviderConfig;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido']);
    exit;
}

Auth::start();

$raw = file_get_contents('php://input');
$payload = json_decode($raw ?: '[]', true);
if (!is_array($payload)) {
    $payload = $_POST;
}
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'JSON inválido']);
    exit;
}

$action = (string)($_GET['action'] ?? $payload['action'] ?? 'create_order');

try {
    if (!class_exists(Paypal::class)) {
        throw new RuntimeException('Módulo PayPal no disponible (Paypal class).');
    }

    $db = Database::getInstance();
    $encKey = (string)$db->config()['security']['encryption_key'];
    $crypto = new Crypto($encKey);
    // config.app.url_base is often empty (only set via AGENDUY_URL_BASE).
    // PayPal requires absolute return/cancel URLs — fall back to request-aware url_base().
    $appUrl = rtrim((string)($db->config()['app']['url_base'] ?? ''), '/');
    if ($appUrl === '' && function_exists('url_base')) {
        $appUrl = rtrim((string)url_base(), '/');
    }

    $pp = ProviderConfig::get('paypal');
    if (!$pp['is_enabled'] && $action !== 'create_plan') {
        throw new RuntimeException('PayPal no está habilitado.');
    }

    $clientId = (string)($pp['config']['client_id'] ?? '');
    $secret = (string)($pp['config']['secret'] ?? '');
    $sandbox = !empty($pp['config']['sandbox']);

    $role = Auth::check() ? (string)Auth::role() : '';
    $sessionCommerceId = Auth::check() ? (int)Auth::commerceId() : 0;

    if ($action === 'create_plan') {
        if ($role !== 'super_admin') {
            http_response_code(401);
            echo json_encode(['ok' => false, 'error' => 'No autorizado.']);
            exit;
        }
    } else {
        if ($role !== 'commerce_admin' || $sessionCommerceId <= 0) {
            http_response_code(401);
            echo json_encode(['ok' => false, 'error' => 'No autorizado.']);
            exit;
        }
        $csrf = $payload['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
        if (!CSRF::check('commerce_plan_select', is_string($csrf) ? $csrf : null, false)) {
            http_response_code(419);
            echo json_encode(['ok' => false, 'error' => 'Sesión expirada, recargá la página.']);
            exit;
        }
    }

    $idCommerce = $sessionCommerceId > 0 ? $sessionCommerceId : (int)($payload['id_commerce'] ?? 0);

    if ($idCommerce > 0) {
        $rows = $db->fetchAll(
            "SELECT key_name, key_value FROM api_keys
             WHERE provider = 'paypal' AND id_commerce = :c AND is_active = 1",
            [':c' => $idCommerce]
        );
        foreach ($rows as $r) {
            $val = $crypto->decrypt((string)$r['key_value']);
            if ($r['key_name'] === 'PAYPAL_CLIENT_ID') {
                $clientId = $val;
            }
            if ($r['key_name'] === 'PAYPAL_SECRET') {
                $secret = $val;
            }
        }
    }

    if ($clientId === '' || $secret === '') {
        throw new RuntimeException('Faltan credenciales PayPal. Configuralas en Config.');
    }

    $baseUrl = $sandbox ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';

    $accessToken = Paypal::accessToken($baseUrl, $clientId, $secret);

    if ($action === 'create_subscription') {
        $idMembership = (int)($payload['id_membership'] ?? 0);
        if ($idMembership <= 0) {
            throw new InvalidArgumentException('Falta id_membership.');
        }

        $membership = $db->fetchOne('SELECT * FROM memberships WHERE id_membership = :id AND activo = 1', [':id' => $idMembership]);
        if (!$membership) {
            throw new RuntimeException('Membresía inexistente.');
        }
        if (empty($membership['paypal_plan_id'])) {
            // Sin Plan ID de PayPal: cobro único del período elegido
            $action = 'create_order';
        } else {
            $commerce = $db->fetchOne('SELECT * FROM commerces WHERE id_commerce = :id', [':id' => $idCommerce]);
            if (!$commerce) {
                throw new RuntimeException('Comercio inexistente.');
            }

            $returnUrls = Paypal::returnUrls($appUrl, (string)$commerce['slug']);
            $body = [
                'plan_id' => (string)$membership['paypal_plan_id'],
                'subscriber' => [
                    'name' => ['given_name' => (string)$commerce['nombre']],
                    'email_address' => (string)($payload['payer']['email'] ?? $commerce['email'] ?? ''),
                ],
                'application_context' => [
                    'brand_name' => 'Agenduy',
                    'user_action' => 'SUBSCRIBE_NOW',
                    'return_url' => $returnUrls['return'],
                    'cancel_url' => $returnUrls['cancel'],
                ],
            ];

            $decoded = Paypal::json($baseUrl . '/v1/billing/subscriptions', $accessToken, $body);
            $subscriptionId = (string)($decoded['id'] ?? '');
            $status = (string)($decoded['status'] ?? 'APPROVAL_PENDING');
            // Never write 'pending' — CHECK only allows trial|active|past_due|cancelled.
            $localStatus = $status === 'ACTIVE'
                ? 'active'
                : paypalKeepSubscriptionStatus($db, $idCommerce);
            $newEnd = date('Y-m-d', strtotime('+' . (int)$membership['duracion_dias'] . ' days'));
            $effectiveMembershipId = (int)($commerce['id_membership'] ?? 0);
            if ($effectiveMembershipId <= 0) {
                $effectiveMembershipId = $idMembership;
            }

            if ($status === 'ACTIVE') {
                paypalUpsertSubscription(
                    $db,
                    $idCommerce,
                    $idMembership,
                    'active',
                    'paypal',
                    $subscriptionId,
                    $newEnd,
                    null,
                    ''
                );
                $db->update('commerces', [
                    'id_membership' => $idMembership,
                    'status' => 'active',
                    'next_billing_at' => $newEnd,
                    'trial_expires_at' => null,
                    'updated_at' => date('Y-m-d H:i:s'),
                ], 'id_commerce = :id', [':id' => $idCommerce]);
            } else {
                // Pending approval: keep effective plan; store target in notes only.
                $notes = MembershipPlan::encodePendingMembershipNote(
                    $idMembership,
                    $effectiveMembershipId,
                    'PayPal subscription pendiente de aprobación: ' . $subscriptionId
                );
                paypalUpsertSubscription(
                    $db,
                    $idCommerce,
                    $effectiveMembershipId,
                    $localStatus,
                    'paypal',
                    $subscriptionId,
                    $newEnd,
                    null,
                    $notes
                );
                $db->update('commerces', [
                    'updated_at' => date('Y-m-d H:i:s'),
                ], 'id_commerce = :id', [':id' => $idCommerce]);
            }

            echo json_encode([
                'ok' => true,
                'mode' => 'subscription',
                'subscription_id' => $subscriptionId,
                'status' => $status,
                'approve_link' => Paypal::linkHref($decoded['links'] ?? [], 'approve'),
                'local_status' => $localStatus,
                'payment_pending' => $status !== 'ACTIVE',
                'pending_membership_id' => $status === 'ACTIVE' ? null : $idMembership,
                'effective_membership_id' => $status === 'ACTIVE' ? $idMembership : $effectiveMembershipId,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    if ($action === 'create_order') {
        $idMembership = (int)($payload['id_membership'] ?? 0);
        if ($idMembership <= 0) {
            throw new InvalidArgumentException('Falta id_membership.');
        }
        $membership = $db->fetchOne('SELECT * FROM memberships WHERE id_membership = :id AND activo = 1', [':id' => $idMembership]);
        if (!$membership) {
            throw new RuntimeException('Membresía inexistente.');
        }
        $commerce = $db->fetchOne('SELECT * FROM commerces WHERE id_commerce = :id', [':id' => $idCommerce]);
        if (!$commerce) {
            throw new RuntimeException('Comercio inexistente.');
        }

        $billingPeriod = strtolower(trim((string)($payload['billing_period'] ?? 'monthly')));
        if ($billingPeriod !== 'yearly') {
            $billingPeriod = 'monthly';
        }
        if ($billingPeriod === 'yearly' && !MembershipPlan::isAnnualEnabled($membership)) {
            $billingPeriod = 'monthly';
        }

        $amount = $billingPeriod === 'yearly'
            ? (float)(MembershipPlan::yearlyPrice($membership) ?? $membership['precio'])
            : (float)$membership['precio'];
        if ($amount <= 0) {
            throw new InvalidArgumentException('Este plan no requiere pago.');
        }

        $paypalCurrency = Paypal::currencyCode((string)($membership['moneda'] ?? 'USD'));
        $amountValue = Paypal::amountValue($amount, $paypalCurrency);

        $returnUrls = Paypal::returnUrls($appUrl, (string)$commerce['slug']);
        if ($returnUrls['return'] === '' || $returnUrls['cancel'] === ''
            || !preg_match('#^https?://#i', $returnUrls['return'])
            || !preg_match('#^https?://#i', $returnUrls['cancel'])) {
            throw new RuntimeException(
                'No se pudo armar la URL de retorno de PayPal. Configurá AGENDUY_URL_BASE o revisá la URL del sitio.'
            );
        }

        $customId = sprintf('agenduy_c%d_m%d_%s', $idCommerce, $idMembership, $billingPeriod);
        $body = [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => $customId,
                'custom_id' => $customId,
                'description' => 'Agenduy - ' . (string)$membership['nombre'] . ($billingPeriod === 'yearly' ? ' (anual)' : ' (mensual)'),
                'amount' => [
                    'currency_code' => $paypalCurrency,
                    'value' => $amountValue,
                ],
            ]],
            'application_context' => [
                'brand_name' => 'Agenduy',
                'landing_page' => 'NO_PREFERENCE',
                'user_action' => 'PAY_NOW',
                'return_url' => $returnUrls['return'],
                'cancel_url' => $returnUrls['cancel'],
            ],
        ];

        $decoded = Paypal::json($baseUrl . '/v2/checkout/orders', $accessToken, $body);
        $orderId = (string)($decoded['id'] ?? '');
        $approve = Paypal::linkHref($decoded['links'] ?? [], 'approve');
        if ($orderId === '' || $approve === null) {
            throw new RuntimeException('PayPal no devolvió un enlace de aprobación.');
        }

        $periodDays = $billingPeriod === 'yearly' ? 365 : max(1, (int)$membership['duracion_dias']);
        $newEnd = date('Y-m-d', strtotime("+{$periodDays} days"));
        // Keep trial/active/past_due/cancelled until capture — never write 'pending' (CHECK).
        $keepStatus = paypalKeepSubscriptionStatus($db, $idCommerce);
        $effectiveMembershipId = (int)($commerce['id_membership'] ?? 0);
        if ($effectiveMembershipId <= 0) {
            $effectiveMembershipId = $idMembership;
        }
        $notes = MembershipPlan::encodePendingMembershipNote(
            $idMembership,
            $effectiveMembershipId,
            'PayPal order pendiente de aprobación: ' . $orderId
        );
        // Keep effective plan on commerce + subscription; target plan lives in notes until capture.
        paypalUpsertSubscription(
            $db,
            $idCommerce,
            $effectiveMembershipId,
            $keepStatus,
            'paypal',
            $orderId,
            $newEnd,
            $billingPeriod,
            $notes
        );
        $db->update('commerces', [
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id_commerce = :id', [':id' => $idCommerce]);

        echo json_encode([
            'ok' => true,
            'mode' => 'order',
            'order_id' => $orderId,
            'approve_link' => $approve,
            'amount' => $amount,
            'currency' => $paypalCurrency,
            'billing_period' => $billingPeriod,
            'local_status' => $keepStatus,
            'payment_pending' => true,
            'pending_membership_id' => $idMembership,
            'effective_membership_id' => $effectiveMembershipId,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'capture_order') {
        $orderId = trim((string)($payload['order_id'] ?? $payload['token'] ?? ''));
        if ($orderId === '') {
            throw new InvalidArgumentException('Falta order_id.');
        }

        $decoded = Paypal::json($baseUrl . '/v2/checkout/orders/' . rawurlencode($orderId) . '/capture', $accessToken, new stdClass());
        $status = (string)($decoded['status'] ?? '');
        if ($status !== 'COMPLETED') {
            throw new RuntimeException('El pago PayPal no se completó (' . ($status ?: 'sin estado') . ').');
        }

        $custom = (string)($decoded['purchase_units'][0]['payments']['captures'][0]['custom_id']
            ?? $decoded['purchase_units'][0]['custom_id']
            ?? '');
        $idMembership = 0;
        $billingPeriod = 'monthly';
        if (preg_match('/agenduy_c(\d+)_m(\d+)_(monthly|yearly)/', $custom, $m)) {
            $idMembership = (int)$m[2];
            $billingPeriod = $m[3];
        }

        $sub = $db->fetchOne(
            'SELECT * FROM subscriptions WHERE id_commerce = :c AND gateway = :g AND gateway_id = :gid ORDER BY id_subscription DESC LIMIT 1',
            [':c' => $idCommerce, ':g' => 'paypal', ':gid' => $orderId]
        );
        if (!$sub) {
            $sub = $db->fetchOne(
                'SELECT * FROM subscriptions WHERE id_commerce = :c ORDER BY id_subscription DESC LIMIT 1',
                [':c' => $idCommerce]
            );
        }
        if ($sub) {
            $pending = MembershipPlan::parsePendingMembershipNote($sub['notes'] ?? null);
            if ($idMembership <= 0 && $pending) {
                $idMembership = (int)$pending['pending_id'];
            }
            if ($idMembership <= 0) {
                $idMembership = (int)$sub['id_membership'];
            }
            $billingPeriod = (string)($sub['billing_period'] ?? $billingPeriod);
        }

        $membership = $idMembership > 0
            ? $db->fetchOne('SELECT * FROM memberships WHERE id_membership = :id', [':id' => $idMembership])
            : null;
        $periodDays = ($billingPeriod === 'yearly') ? 365 : max(1, (int)($membership['duracion_dias'] ?? 30));
        $newEnd = date('Y-m-d', strtotime("+{$periodDays} days"));

        if ($idMembership > 0) {
            // Capture success: apply pending membership and activate.
            paypalUpsertSubscription(
                $db,
                $idCommerce,
                $idMembership,
                'active',
                'paypal',
                $orderId,
                $newEnd,
                $billingPeriod,
                ''
            );
            $db->update('commerces', [
                'id_membership' => $idMembership,
                'status' => 'active',
                'trial_expires_at' => null,
                'next_billing_at' => $newEnd,
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'id_commerce = :id', [':id' => $idCommerce]);
        }

        echo json_encode([
            'ok' => true,
            'status' => $status,
            'order_id' => $orderId,
            'local_status' => 'active',
            'period_end' => $newEnd,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'create_plan') {
        $idMembership = (int)($payload['id_membership'] ?? 0);
        $membership = $db->fetchOne('SELECT * FROM memberships WHERE id_membership = :id', [':id' => $idMembership]);
        if (!$membership) {
            throw new RuntimeException('Membresía inexistente.');
        }

        $productId = 'PROD-AGENDUY-' . $idMembership;
        try {
            Paypal::json($baseUrl . '/v1/catalogs/products', $accessToken, [
                'id' => $productId,
                'name' => 'Agenduy - ' . $membership['nombre'],
                'type' => 'SERVICE',
                'category' => 'SOFTWARE',
            ]);
        } catch (Throwable $e) {
            // Producto puede existir
        }

        $planCurrency = Paypal::currencyCode((string)($membership['moneda'] ?? 'USD'));
        $planAmount = Paypal::amountValue((float)$membership['precio'], $planCurrency);
        $body = [
            'product_id' => $productId,
            'name' => $membership['nombre'],
            'description' => $membership['descripcion'] ?: 'Suscripción Agenduy',
            'status' => 'ACTIVE',
            'billing_cycles' => [[
                'frequency' => ['interval_unit' => 'MONTH', 'interval_count' => 1],
                'tenure_type' => 'REGULAR',
                'sequence' => 1,
                'total_cycles' => 0,
                'pricing_scheme' => [
                    'fixed_price' => [
                        'value' => $planAmount,
                        'currency_code' => $planCurrency,
                    ],
                ],
            ]],
            'payment_preferences' => [
                'auto_bill_outstanding' => true,
                'setup_fee' => [
                    'value' => Paypal::amountValue(0.0, $planCurrency),
                    'currency_code' => $planCurrency,
                ],
                'setup_fee_failure_action' => 'CANCEL',
            ],
        ];

        $decoded = Paypal::json($baseUrl . '/v1/billing/plans', $accessToken, $body);
        $planId = (string)($decoded['id'] ?? '');
        if ($planId === '') {
            throw new RuntimeException('PayPal rechazó la creación del plan.');
        }
        $db->update('memberships', [
            'paypal_plan_id' => $planId,
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id_membership = :id', [':id' => $idMembership]);

        echo json_encode(['ok' => true, 'plan_id' => $planId], JSON_UNESCAPED_UNICODE);
        exit;
    }

    throw new RuntimeException('Acción no soportada.');
} catch (Throwable $e) {
    $code = 500;
    if ($e instanceof InvalidArgumentException) {
        $code = 400;
    } elseif (!$e instanceof Error) {
        $code = 422;
    }
    http_response_code($code);
    $msg = $e->getMessage();
    // Never leak raw English fatals like "Call to undefined function …" to the membership UI.
    if ($e instanceof Error || stripos($msg, 'Call to undefined') !== false) {
        $msg = 'Error interno al iniciar PayPal. Recargá e intentá de nuevo.';
    }
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
}

/**
 * subscriptions.status CHECK: trial|active|past_due|cancelled — never pending.
 */
function paypalKeepSubscriptionStatus(Database $db, int $idCommerce): string
{
    $existing = $db->fetchOne(
        'SELECT status FROM subscriptions WHERE id_commerce = :c ORDER BY id_subscription DESC LIMIT 1',
        [':c' => $idCommerce]
    );
    $allowed = ['trial', 'active', 'past_due', 'cancelled'];
    if ($existing && in_array((string)($existing['status'] ?? ''), $allowed, true)) {
        return (string)$existing['status'];
    }
    return 'trial';
}

function paypalUpsertSubscription(
    Database $db,
    int $idCommerce,
    int $idMembership,
    string $status,
    string $gateway,
    string $gatewayId,
    string $periodEnd,
    ?string $billingPeriod,
    ?string $notes = null
): void {
    $allowed = ['trial', 'active', 'past_due', 'cancelled'];
    if (!in_array($status, $allowed, true)) {
        $status = 'trial';
    }
    $now = date('Y-m-d H:i:s');
    $existing = $db->fetchOne(
        'SELECT id_subscription FROM subscriptions WHERE id_commerce = :c ORDER BY id_subscription DESC LIMIT 1',
        [':c' => $idCommerce]
    );
    $row = [
        'id_membership' => $idMembership,
        'status' => $status,
        'gateway' => $gateway,
        'gateway_id' => $gatewayId,
        'current_period_start' => date('Y-m-d'),
        'current_period_end' => $periodEnd,
        'updated_at' => $now,
    ];
    if ($billingPeriod !== null) {
        $row['billing_period'] = $billingPeriod;
    }
    if ($notes !== null) {
        $row['notes'] = $notes;
    }
    if ($existing) {
        $db->update('subscriptions', $row, 'id_subscription = :id', [':id' => $existing['id_subscription']]);
    } else {
        $row['id_commerce'] = $idCommerce;
        $row['started_at'] = $now;
        unset($row['updated_at']);
        $db->insert('subscriptions', $row);
    }
}
