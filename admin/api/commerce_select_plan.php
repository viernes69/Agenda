<?php
/**
 * Agenduy - API: Seleccionar plan (commerce_admin)
 *
 * POST /admin/api/commerce_select_plan.php
 *   - _csrf
 *   - id_membership
 *   - billing_period (optional: monthly|yearly) — intent; gateways still bill monthly today
 *
 * Cambia el `id_membership` del comercio y crea/actualiza la suscripción
 * usando únicamente estados admitidos por el esquema.
 */
declare(strict_types=1);

$config = require __DIR__ . '/../../src/Core/bootstrap.php';

use Agenduy\Core\Auth;
use Agenduy\Core\CSRF;
use Agenduy\Core\Database;
use Agenduy\Core\MembershipPlan;

header('Content-Type: application/json; charset=utf-8');

Auth::start();
if (!Auth::check() || Auth::role() !== 'commerce_admin') {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'No autorizado.']);
    exit;
}

$idCommerce = (int)Auth::commerceId();
if ($idCommerce <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Cuenta sin comercio asignado.']);
    exit;
}

if (!CSRF::check('commerce_plan_select', $_POST['_csrf'] ?? '')) {
    http_response_code(419);
    echo json_encode(['ok' => false, 'error' => 'Sesión expirada, recargá la página.']);
    exit;
}

$idMembership = (int)($_POST['id_membership'] ?? 0);
if ($idMembership <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Plan inválido.']);
    exit;
}

$db = Database::getInstance();
$commerce = $db->fetchOne('SELECT * FROM commerces WHERE id_commerce = :id', [':id' => $idCommerce]);
if (!$commerce) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Comercio no encontrado.']);
    exit;
}
$plan = $db->fetchOne('SELECT * FROM memberships WHERE id_membership = :id AND activo = 1', [':id' => $idMembership]);
if (!$plan) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Plan no disponible.']);
    exit;
}

$billingPeriod = strtolower(trim((string)($_POST['billing_period'] ?? 'monthly')));
if ($billingPeriod !== 'yearly') {
    $billingPeriod = 'monthly';
}
if ($billingPeriod === 'yearly' && !MembershipPlan::isAnnualEnabled($plan)) {
    $billingPeriod = 'monthly';
}

try {
    $now = date('Y-m-d H:i:s');
    $isFree = (float)$plan['precio'] <= 0;

    // Paid plans must go through PayPal / transferencia / MercadoPago — never silent-activate.
    if (!$isFree) {
        if (!empty($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) {
            echo json_encode([
                'ok' => true,
                'requires_payment' => true,
                'id_membership' => $idMembership,
                'is_free' => false,
                'billing_period' => $billingPeriod,
                'amount' => $billingPeriod === 'yearly'
                    ? (float)(MembershipPlan::yearlyPrice($plan) ?? $plan['precio'])
                    : (float)$plan['precio'],
                'currency' => (string)($plan['moneda'] ?? 'UYU'),
                'name' => (string)($plan['nombre'] ?? 'Plan'),
            ]);
        } else {
            header('Location: ../commerce_plan.php?msg=pay&id_membership=' . $idMembership);
        }
        exit;
    }

    $trialDays = max(0, (int)($plan['trial_dias'] ?? 30));
    $trialEnd = date('Y-m-d H:i:s', strtotime("+{$trialDays} days"));
    $hasTrial = $trialDays > 0;
    $newStatus = $hasTrial ? 'trial' : 'active';
    $subscriptionStatus = $newStatus === 'active' ? 'active' : 'trial';
    $periodNote = 'Plan gratuito / prueba elegido desde el panel del comercio.';
    $db->update('commerces', [
        'id_membership'    => $idMembership,
        'status'           => $newStatus,
        'trial_expires_at' => $hasTrial ? $trialEnd : null,
        'next_billing_at'  => $trialEnd,
    ], 'id_commerce = :id', [':id' => $idCommerce]);

    // Crear/actualizar subscription
    $existing = $db->fetchOne(
        'SELECT * FROM subscriptions WHERE id_commerce = :c ORDER BY id_subscription DESC LIMIT 1',
        [':c' => $idCommerce]
    );
    if ($existing) {
        $db->update('subscriptions', [
            'id_membership'      => $idMembership,
            'status'             => $subscriptionStatus,
            'gateway'            => 'manual',
            'started_at'         => $now,
            'trial_expires_at'   => $hasTrial ? $trialEnd : null,
            'current_period_start' => $now,
            'current_period_end' => $trialEnd,
            'billing_period'     => $billingPeriod,
            'notes'              => $periodNote,
            'updated_at'         => $now,
        ], 'id_subscription = :id', [':id' => $existing['id_subscription']]);
    } else {
        $db->insert('subscriptions', [
            'id_commerce'           => $idCommerce,
            'id_membership'         => $idMembership,
            'status'                => $subscriptionStatus,
            'gateway'               => 'manual',
            'started_at'            => $now,
            'trial_expires_at'      => $hasTrial ? $trialEnd : null,
            'current_period_start'  => $now,
            'current_period_end'    => $trialEnd,
            'billing_period'        => $billingPeriod,
            'notes'                 => $periodNote,
        ]);
    }

    // Respuesta
    if (!empty($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) {
        echo json_encode([
            'ok' => true,
            'id_membership' => $idMembership,
            'is_free' => true,
            'requires_payment' => false,
            'billing_period' => $billingPeriod,
        ]);
    } else {
        // Form submission clásica: redirigir
        header('Location: ../commerce_plan.php?msg=ok');
    }
} catch (Throwable $e) {
    error_log('[commerce_select_plan] ' . $e->getMessage());
    if (!empty($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Error interno.']);
    } else {
        header('Location: ../commerce_plan.php?err=' . urlencode('Error al cambiar el plan.'));
    }
}
