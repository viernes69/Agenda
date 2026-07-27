<?php
/**
 * Agenduy - API: MercadoPago
 *
 * POST /admin/api/mercadopago.php?action=create_preapproval
 * Crea una suscripción (preapproval) en MercadoPago usando
 * las credenciales de la membresía y el comercio.
 */
declare(strict_types=1);

$config = require __DIR__ . '/../../src/Core/bootstrap.php';

use Agenduy\Core\Auth;
use Agenduy\Core\CSRF;
use Agenduy\Core\Database;
use Agenduy\Core\MercadoPago;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido']);
    exit;
}

Auth::start();
if (!Auth::check() || Auth::role() !== 'commerce_admin' || (int)Auth::commerceId() <= 0) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'No autorizado.']);
    exit;
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw ?: '[]', true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'JSON inválido']);
    exit;
}

$csrf = $payload['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
if (!CSRF::check('commerce_plan_select', is_string($csrf) ? $csrf : null, false)) {
    http_response_code(419);
    echo json_encode(['ok' => false, 'error' => 'Sesión expirada, recargá la página.']);
    exit;
}

$action = $_GET['action'] ?? $payload['action'] ?? 'create_preapproval';

try {
    $db = Database::getInstance();
    $mpConfig = MercadoPago::platformConfig();

    if (empty($mpConfig['enabled']) || trim((string)($mpConfig['access_token'] ?? '')) === '') {
        throw new RuntimeException('Mercado Pago de membresias no esta configurado por el super admin.');
    }

    $idCommerce = (int)Auth::commerceId();
    $idMembership = (int)($payload['id_membership'] ?? 0);

    if ($idCommerce <= 0) throw new InvalidArgumentException('Falta id_commerce.');
    if ($idMembership <= 0) throw new InvalidArgumentException('Falta id_membership.');

    $commerce = $db->fetchOne('SELECT * FROM commerces WHERE id_commerce = :id', [':id' => $idCommerce]);
    $membership = $db->fetchOne('SELECT * FROM memberships WHERE id_membership = :id', [':id' => $idMembership]);
    if (!$commerce) throw new RuntimeException('Comercio inexistente.');
    if (!$membership) throw new RuntimeException('Membresía inexistente.');

    $payerEmail = (string)($payload['payer']['email'] ?? $commerce['email'] ?? '');
    if ($payerEmail === '' || !filter_var($payerEmail, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Email de pagador inválido.');
    }

    $cardToken = trim((string)($payload['card']['token'] ?? ''));
    $paymentMethodId = trim((string)($payload['card']['paymentMethodId'] ?? ''));
    $installments = (int)($payload['card']['installments'] ?? 0);
    $issuerId = trim((string)($payload['card']['issuerId'] ?? ''));

    $autoRecurring = [
        'frequency'          => 1,
        'frequency_type'     => 'months',
        'transaction_amount' => (float)$membership['precio'],
        'currency_id'        => (string)$membership['moneda'],
    ];

    $trialDias = (int)($membership['trial_dias'] ?? 0);
    if ($trialDias > 0) {
        $autoRecurring['free_trial'] = [
            'frequency'      => $trialDias,
            'frequency_type' => 'days',
        ];
    }

    $notificationUrl = trim((string)($mpConfig['notification_url'] ?? ''));
    if ($notificationUrl === '' || !MercadoPago::isPublicCallbackUrl($notificationUrl)) {
        $notificationUrl = MercadoPago::callbackUrl($mpConfig, 'admin/api/webhook_mercadopago.php');
    }
    $backPath = trim((string)($commerce['slug'] ?? '')) !== ''
        ? trim((string)$commerce['slug'], '/') . '/private/dashboard/admin/'
        : 'admin/commerce_plan.php';
    $backUrl = MercadoPago::callbackUrl($mpConfig, $backPath, ['pay' => 'mercadopago']);
    $request = [
        'reason'             => 'Suscripción ' . $membership['nombre'] . ' · ' . $commerce['nombre'],
        'external_reference' => sprintf('agenduy_c%d_m%d_%s', $idCommerce, $idMembership, uniqid()),
        'payer_email'        => $payerEmail,
        'auto_recurring'     => $autoRecurring,
        'status'             => $cardToken !== '' ? 'authorized' : 'pending',
        'back_url'           => $backUrl,
        'notification_url'   => $notificationUrl,
    ];
    if (!empty($membership['mp_preapproval_id'])) {
        $request['preapproval_plan_id'] = (string)$membership['mp_preapproval_id'];
    }
    if ($cardToken !== '') {
        $request['card_token_id'] = $cardToken;
        if ($paymentMethodId) $request['payment_method_id'] = $paymentMethodId;
        if ($issuerId)        $request['issuer_id'] = $issuerId;
        if ($installments > 0) $request['installments'] = $installments;
    }

    $decoded = MercadoPago::createPreapproval($mpConfig, $request);
    // Si quedó autorizada o pending, registramos la subscription local
    $mpStatus = (string)($decoded['status'] ?? 'pending');
    $localStatus = $mpStatus === 'authorized' ? 'active' : 'past_due';
    $newEnd = date('Y-m-d', strtotime('+' . (int)$membership['duracion_dias'] . ' days'));
    $trialEnd = $trialDias > 0 ? date('Y-m-d', strtotime("+{$trialDias} days")) : null;

    // Insertar/actualizar subscription
    $existing = $db->fetchOne(
        'SELECT id_subscription FROM subscriptions WHERE id_commerce = :c ORDER BY id_subscription DESC LIMIT 1',
        [':c' => $idCommerce]
    );
    if ($existing) {
        $db->update('subscriptions', [
            'id_membership'        => $idMembership,
            'status'               => $localStatus,
            'gateway'              => 'mercadopago',
            'gateway_id'           => (string)($decoded['id'] ?? ''),
            'current_period_start' => date('Y-m-d'),
            'current_period_end'   => $newEnd,
            'trial_expires_at'     => $trialEnd,
            'updated_at'           => date('Y-m-d H:i:s'),
        ], 'id_subscription = :id', [':id' => $existing['id_subscription']]);
    } else {
        $db->insert('subscriptions', [
            'id_commerce'        => $idCommerce,
            'id_membership'      => $idMembership,
            'status'             => $localStatus,
            'gateway'            => 'mercadopago',
            'gateway_id'         => (string)($decoded['id'] ?? ''),
            'current_period_start'=> date('Y-m-d'),
            'current_period_end' => $newEnd,
            'trial_expires_at'   => $trialEnd,
        ]);
    }
    if ($localStatus === 'active' || $trialEnd) {
        $db->update('commerces', [
            'status'           => $trialEnd ? 'trial' : 'active',
            'id_membership'    => $idMembership,
            'next_billing_at'  => $newEnd,
            'trial_expires_at' => $trialEnd,
            'updated_at'       => date('Y-m-d H:i:s'),
        ], 'id_commerce = :id', [':id' => $idCommerce]);
    }

    echo json_encode([
        'ok'                 => true,
        'preapproval_id'     => $decoded['id'] ?? null,
        'status'             => $mpStatus,
        'init_point'         => $decoded['init_point'] ?? null,
        'sandbox_init_point' => $decoded['sandbox_init_point'] ?? null,
        'public_key'         => (string)($mpConfig['public_key'] ?? ''),
        'local_status'       => $localStatus,
        'trial_expires_at'   => $trialEnd,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    $code = $e instanceof InvalidArgumentException ? 400 : 422;
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
