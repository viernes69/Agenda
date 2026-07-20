<?php
/**
 * Banner de membresía/suscripción desde SQLite (fuente de verdad).
 * Espera: $tenantSlug, $businessName, $infoBarberia
 * Define: $planBannerData, $planMembershipPlans, $planMembershipCsrf,
 *         $planMembershipSelectUrl, $canSelectPlan,
 *         $publicShareUrl, $publicShareUrlDisplay, $tenantNegocioId
 */
declare(strict_types=1);

use Agenduy\Core\Auth;
use Agenduy\Core\CSRF;
use Agenduy\Core\CommercePanel;
use Agenduy\Core\Database;
use Agenduy\Core\ProviderConfig;

$planBannerData = null;
$planMembershipPlans = [];
$planMembershipCsrf = '';
$planMembershipSelectUrl = url('admin/api/commerce_select_plan.php');
$planMembershipPaypalUrl = url('admin/api/paypal.php');
$planMembershipTransferUrl = url('admin/api/transfer_upload.php');
$planMembershipMpUrl = url('admin/api/mercadopago.php');
$planMembershipPayMethods = [
    'paypal' => false,
    'transfer' => false,
    'mercadopago' => false,
];
$planMembershipTransferInfo = [
    'banco' => '',
    'titular' => '',
    'cuenta' => '',
    'moneda' => 'UYU',
    'instrucciones' => '',
];
$canSelectPlan = Auth::check() && Auth::role() === 'commerce_admin';
$publicShareUrl = '';
$publicShareUrlDisplay = '';
$tenantNegocioId = isset($infoBarberia['ID_Negocio']) ? (int)$infoBarberia['ID_Negocio'] : 0;
$tenantSlug = isset($tenantSlug) ? (string)$tenantSlug : basename(dirname(__DIR__, 3));

$buildBanner = static function (
    string $class,
    string $title,
    string $message,
    string $badge,
    array $details,
    string $status,
    ?int $daysRemaining,
    string $renewalIso,
    int $businessId,
    string $ctaLabel,
    int $membershipId = 0
): array {
    return [
        'class' => $class,
        'title' => $title,
        'message' => $message,
        'badge' => $badge,
        'details' => $details,
        'status' => $status,
        'days_remaining' => $daysRemaining,
        'renewal_iso' => $renewalIso,
        'business_id' => $businessId,
        'membership_id' => $membershipId,
        'cta_label' => $ctaLabel,
        'cta_modal' => true,
    ];
};

try {
    $db = Database::getInstance();
    $planMembershipPlans = $db->fetchAll(
        'SELECT id_membership, nombre, precio, moneda, duracion_dias, trial_dias, descripcion,
                features, limits, precio_anual, descuento_anual_pct, anual_habilitado,
                paypal_plan_id, mp_preapproval_id
         FROM memberships WHERE activo = 1 ORDER BY precio ASC, id_membership ASC'
    );
    if ($canSelectPlan) {
        $planMembershipCsrf = CSRF::generate('commerce_plan_select');
    }

    $ppPaypal = ProviderConfig::get('paypal');
    $ppTransfer = ProviderConfig::get('transfer');
    $ppMp = ProviderConfig::get('mercadopago');
    $planMembershipPayMethods = [
        'paypal' => $ppPaypal['is_enabled'] && trim((string)($ppPaypal['config']['client_id'] ?? '')) !== '',
        'transfer' => $ppTransfer['is_enabled'] && (
            trim((string)($ppTransfer['config']['cuenta'] ?? '')) !== ''
            || trim((string)($ppTransfer['config']['instrucciones'] ?? '')) !== ''
        ),
        'mercadopago' => $ppMp['is_enabled'] && trim((string)($ppMp['config']['access_token'] ?? '')) !== '',
    ];
    $planMembershipTransferInfo = [
        'banco' => (string)($ppTransfer['config']['banco'] ?? ''),
        'titular' => (string)($ppTransfer['config']['titular'] ?? ''),
        'cuenta' => (string)($ppTransfer['config']['cuenta'] ?? ''),
        'moneda' => (string)($ppTransfer['config']['moneda'] ?? 'UYU'),
        'instrucciones' => (string)($ppTransfer['config']['instrucciones'] ?? ''),
    ];

    $commerce = $db->fetchOne(
        'SELECT c.*, m.nombre AS plan_nombre, m.precio AS plan_precio, m.moneda AS plan_moneda, r.nombre AS rubro_nombre
         FROM commerces c
         LEFT JOIN memberships m ON m.id_membership = c.id_membership
         LEFT JOIN rubros r ON r.id_rubro = c.id_rubro
         WHERE c.slug = :s
         LIMIT 1',
        [':s' => $tenantSlug]
    );

    if (!$commerce && $tenantNegocioId > 0) {
        $commerce = $db->fetchOne(
            'SELECT c.*, m.nombre AS plan_nombre, m.precio AS plan_precio, m.moneda AS plan_moneda, r.nombre AS rubro_nombre
             FROM commerces c
             LEFT JOIN memberships m ON m.id_membership = c.id_membership
             LEFT JOIN rubros r ON r.id_rubro = c.id_rubro
             WHERE c.id_commerce = :id
             LIMIT 1',
            [':id' => $tenantNegocioId]
        );
    }

    if (!$commerce) {
        $planBannerData = $buildBanner(
            'admin-plan-banner--neutral',
            'Suscripción pendiente',
            'No encontramos el negocio en la plataforma. Volvé a iniciar sesión.',
            'Sin datos',
            $businessName !== '' ? [['label' => 'Negocio', 'value' => $businessName]] : [],
            'sin-datos',
            null,
            '',
            $tenantNegocioId,
            'Ver planes'
        );
        return;
    }

    $tenantNegocioId = (int)$commerce['id_commerce'];
    $membershipId = (int)($commerce['id_membership'] ?? 0);
    $publicShareUrl = CommercePanel::publicUrlForSlug((string)$commerce['slug']);
    $publicShareUrlParts = parse_url($publicShareUrl);
    if (is_array($publicShareUrlParts) && !empty($publicShareUrlParts['host'])) {
        $displayHost = (string)$publicShareUrlParts['host'];
        if (isset($publicShareUrlParts['port'])) {
            $displayHost .= ':' . (string)$publicShareUrlParts['port'];
        }
        $displayPath = rtrim((string)($publicShareUrlParts['path'] ?? ''), '/');
        $displayQuery = isset($publicShareUrlParts['query']) ? '?' . (string)$publicShareUrlParts['query'] : '';
        $publicShareUrlDisplay = $displayHost . $displayPath . $displayQuery;
    } else {
        $publicShareUrlDisplay = $publicShareUrl;
    }
    if (!empty($commerce['rubro_nombre'])) {
        $infoBarberia['rubro_nombre'] = (string)$commerce['rubro_nombre'];
    }

    $sub = $db->fetchOne(
        'SELECT * FROM subscriptions WHERE id_commerce = :c ORDER BY id_subscription DESC LIMIT 1',
        [':c' => $tenantNegocioId]
    );

    $status = strtolower(trim((string)($commerce['status'] ?? ($sub['status'] ?? 'trial'))));
    $planName = trim((string)($commerce['plan_nombre'] ?? 'Sin plan'));
    $trialEndRaw = (string)($commerce['trial_expires_at'] ?? ($sub['trial_expires_at'] ?? ''));
    $periodEndRaw = (string)($sub['current_period_end'] ?? ($commerce['next_billing_at'] ?? ''));

    $daysRemaining = null;
    $renewalDisplay = '';
    $renewalIso = '';
    $endRaw = $status === 'trial' ? $trialEndRaw : $periodEndRaw;
    if ($endRaw !== '') {
        try {
            $endDate = new DateTimeImmutable(substr($endRaw, 0, 10));
            $renewalDisplay = $endDate->format('d/m/Y');
            $renewalIso = $endDate->format('Y-m-d');
            $today = new DateTimeImmutable('today');
            $daysRemaining = (int)$today->diff($endDate)->format('%r%a');
        } catch (Throwable $e) {
            $renewalDisplay = $endRaw;
        }
    }

    $details = [];
    if ($planName !== '') {
        $details[] = ['label' => 'Plan', 'value' => $planName];
    }
    if ($renewalDisplay !== '') {
        $details[] = [
            'label' => $status === 'trial' ? 'Fin de prueba' : 'Renovación',
            'value' => $renewalDisplay,
        ];
    }

    $ctaLabel = 'Ver planes';

    if ($status === 'trial') {
        $message = 'Tu periodo de prueba está activo.';
        if ($daysRemaining !== null) {
            if ($daysRemaining > 1) {
                $message = 'Quedan ' . $daysRemaining . ' días de prueba.';
            } elseif ($daysRemaining === 1) {
                $message = 'Queda 1 día de prueba.';
            } elseif ($daysRemaining === 0) {
                $message = 'Último día de prueba.';
            } else {
                $daysAgo = abs($daysRemaining);
                $message = 'La prueba venció hace ' . $daysAgo . ' día' . ($daysAgo === 1 ? '' : 's') . '.';
                $ctaLabel = 'Elegir plan';
            }
        }
        $planBannerData = $buildBanner(
            'admin-plan-banner--trial',
            'Plan en prueba',
            $message,
            'Prueba',
            $details,
            'prueba',
            $daysRemaining,
            $renewalIso,
            $tenantNegocioId,
            $ctaLabel,
            $membershipId
        );
    } elseif ($status === 'active') {
        $planBannerData = $buildBanner(
            'admin-plan-banner--active',
            $planName !== '' ? $planName : 'Plan activo',
            $renewalDisplay !== '' ? ('Próxima renovación: ' . $renewalDisplay) : 'Tu plan está activo.',
            'Activo',
            $details,
            'activo',
            $daysRemaining,
            $renewalIso,
            $tenantNegocioId,
            'Gestionar plan',
            $membershipId
        );
    } else {
        $labels = [
            'past_due' => 'Pago pendiente',
            'cancelled' => 'Cancelado',
            'suspended' => 'Suspendido',
        ];
        $planBannerData = $buildBanner(
            'admin-plan-banner--neutral',
            $labels[$status] ?? ucfirst($status !== '' ? $status : 'Pendiente'),
            'Revisá o seleccioná una membresía para tu negocio.',
            $labels[$status] ?? 'Pendiente',
            $details,
            $status !== '' ? $status : 'sin-datos',
            $daysRemaining,
            $renewalIso,
            $tenantNegocioId,
            'Elegir plan',
            $membershipId
        );
    }
} catch (Throwable $e) {
    $planBannerData = $buildBanner(
        'admin-plan-banner--neutral',
        'Datos no disponibles',
        'No pudimos consultar la suscripción en este momento.',
        'Sin datos',
        $businessName !== '' ? [['label' => 'Negocio', 'value' => $businessName]] : [],
        'sin-datos',
        null,
        '',
        $tenantNegocioId,
        'Ver planes'
    );
}
