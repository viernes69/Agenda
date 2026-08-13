<?php
/**
 * Agenduy - Mi Plan (vista del comercio)
 * El comercio ve su plan actual, los planes disponibles, y puede
 * seleccionar uno. La activación real depende del método de pago
 * (MercadoPago / PayPal / transferencia) y de la aprobación del super admin.
 */
declare(strict_types=1);

$config = require __DIR__ . '/../src/Core/bootstrap.php';

use Agenduy\Core\Auth;
use Agenduy\Core\CSRF;
use Agenduy\Core\Database;
use Agenduy\Core\MembershipPlan;
use Agenduy\Core\Security;

Auth::start();
if (!Auth::check()) { header('Location: ' . Auth::loginUrl()); exit; }
$role = Auth::role();
if ($role === 'super_admin') { header('Location: index.php'); exit; }
if ($role !== 'commerce_admin') { header('Location: ' . Auth::loginUrl()); exit; }
Security::sendNoStoreHeaders();

$idCommerce = (int)Auth::commerceId();
if ($idCommerce <= 0) {
    echo 'Cuenta sin comercio asignado. Contactá al super admin.';
    exit;
}

$db = Database::getInstance();
$commerce = $db->fetchOne('SELECT * FROM commerces WHERE id_commerce = :id', [':id' => $idCommerce]);
if (!$commerce) { echo 'Comercio no encontrado.'; exit; }

$currentPlan = null;
if (!empty($commerce['id_membership'])) {
    $currentPlan = $db->fetchOne('SELECT * FROM memberships WHERE id_membership = :id', [':id' => $commerce['id_membership']]);
}
$currentSub = $db->fetchOne('SELECT * FROM subscriptions WHERE id_commerce = :c ORDER BY id_subscription DESC LIMIT 1', [':c' => $idCommerce]);

// Cálculos
$now = new DateTimeImmutable('now');
$trialEnd = !empty($commerce['trial_expires_at']) ? new DateTimeImmutable((string)$commerce['trial_expires_at']) : null;
$nextBill = !empty($commerce['next_billing_at']) ? new DateTimeImmutable((string)$commerce['next_billing_at']) : null;
$daysLeft = null;
if ($trialEnd instanceof DateTimeImmutable) {
    $diff = (int)$now->diff($trialEnd)->format('%r%a');
    $daysLeft = $diff;
}

$status = (string)$commerce['status'];
$statusLabel = match ($status) {
    'trial'     => 'En prueba',
    'active'    => 'Activo',
    'past_due'  => 'Pago pendiente',
    'cancelled' => 'Cancelado',
    'suspended' => 'Suspendido',
    default     => $status,
};

$availablePlans = $db->fetchAll(
    'SELECT * FROM memberships WHERE activo = 1 ORDER BY precio ASC, id_membership ASC'
);

// CSRF para el form de selección
$csrfPlan = CSRF::generate('commerce_plan_select');
$message = $_GET['msg'] ?? '';
$error   = $_GET['err'] ?? '';
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Mi Plan · Agendarte</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="assets/css/admin.css">
<style>
.plan-hero {
  background: linear-gradient(135deg, var(--primary, #6366f1), var(--primary-2, #4f46e5));
  color: #fff;
  border-radius: var(--radius-lg, 20px);
  padding: 2rem 1.8rem;
  margin: 1.5rem 0;
  display: grid;
  grid-template-columns: 1fr auto;
  gap: 1.5rem;
  align-items: center;
  box-shadow: 0 12px 32px rgba(99, 102, 241, .25);
}
.plan-hero__eyebrow {
  text-transform: uppercase;
  letter-spacing: .08em;
  font-size: .75rem;
  font-weight: 700;
  opacity: .85;
}
.plan-hero__name {
  font-size: 2rem;
  font-weight: 800;
  margin: .2rem 0 .4rem;
}
.plan-hero__price {
  font-size: 2.5rem;
  font-weight: 800;
  line-height: 1;
}
.plan-hero__price small {
  font-size: 1rem;
  font-weight: 500;
  opacity: .85;
}
.plan-hero__meta {
  margin-top: .75rem;
  font-size: .95rem;
  display: flex;
  flex-wrap: wrap;
  gap: 1rem 1.5rem;
}
.plan-hero__meta b {
  font-weight: 700;
}
.plan-hero__status {
  background: rgba(255, 255, 255, .18);
  padding: .45rem .9rem;
  border-radius: 999px;
  font-size: .8rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .05em;
  display: inline-block;
}
.plan-hero__cta {
  display: flex;
  flex-direction: column;
  gap: .5rem;
  align-items: flex-end;
}

.plans-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
  gap: 1.2rem;
  margin-top: 1.5rem;
}
.plan-card {
  background: var(--surface, #fff);
  border: 1px solid var(--border, #e6e8ec);
  border-radius: var(--radius, 14px);
  padding: 1.5rem;
  display: flex;
  flex-direction: column;
  gap: .8rem;
  position: relative;
  transition: transform .15s, box-shadow .15s, border-color .15s;
}
.plan-card:hover {
  transform: translateY(-3px);
  box-shadow: 0 12px 28px rgba(0, 0, 0, .08);
  border-color: var(--primary, #6366f1);
}
.plan-card.is-current {
  border-color: var(--primary, #6366f1);
  box-shadow: 0 0 0 3px rgba(99, 102, 241, .15);
}
.plan-card__ribbon {
  position: absolute;
  top: -10px;
  right: 1rem;
  background: var(--primary, #6366f1);
  color: #fff;
  padding: .25rem .7rem;
  border-radius: 999px;
  font-size: .7rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .04em;
}
.plan-card__name {
  font-size: 1.25rem;
  font-weight: 700;
  margin: 0;
}
.plan-card__price {
  font-size: 2rem;
  font-weight: 800;
  color: var(--primary, #6366f1);
}
.plan-card__price small {
  font-size: .85rem;
  font-weight: 500;
  color: var(--muted, #6b7280);
}
.plan-card__desc {
  color: var(--text-soft, #5b6271);
  font-size: .9rem;
  flex: 1;
}
.plan-card__features {
  list-style: none;
  padding: 0;
  margin: .5rem 0;
  display: grid;
  gap: .35rem;
  font-size: .85rem;
  color: var(--text-soft, #5b6271);
}
.plan-card__features li::before {
  content: "✓";
  color: var(--success, #10b981);
  font-weight: 800;
  margin-right: .5rem;
}
.plan-billing-toggle {
  display: inline-flex;
  gap: .35rem;
  padding: .25rem;
  border-radius: 999px;
  background: var(--surface-2, #f3f4f6);
  border: 1px solid var(--border, #e6e8ec);
  margin: 1rem 0 .5rem;
}
.plan-billing-toggle button {
  border: 0;
  background: transparent;
  padding: .45rem 1rem;
  border-radius: 999px;
  font-weight: 600;
  cursor: pointer;
  color: var(--muted, #6b7280);
}
.plan-billing-toggle button.is-active {
  background: var(--primary, #6366f1);
  color: #fff;
}
.plan-card__annual-note {
  font-size: .8rem;
  color: var(--success, #059669);
  font-weight: 600;
  margin: 0;
}
.plan-card__limit {
  font-size: .8rem;
  color: var(--muted, #6b7280);
  margin: 0;
}
.plan-card__cta {
  margin-top: auto;
}
.plan-card__cta button {
  width: 100%;
}

.alert {
  padding: .8rem 1rem;
  border-radius: var(--radius-sm, 8px);
  margin-bottom: 1rem;
  font-size: .9rem;
}
.alert--ok { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }
.alert--error { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
.alert--info { background: #eef0ff; color: #4338ca; border: 1px solid #c7d2fe; }
</style>
</head>
<body>
<header class="topbar">
    <div class="topbar__brand">
        <a href="commerce_dashboard.php"><strong><?= htmlspecialchars($commerce['nombre'], ENT_QUOTES, 'UTF-8') ?></strong></a>
    </div>
    <nav class="topbar__nav">
        <a href="commerce_dashboard.php">Resumen</a>
        <a href="commerce_appointments.php">Turnos</a>
        <a href="commerce_clients.php">Clientes</a>
        <a href="commerce_services.php">Servicios</a>
        <a href="commerce_plan.php" class="is-active">Mi Plan</a>
        <a href="commerce_settings.php">Configuración</a>
    </nav>
    <div class="topbar__user">
        <span class="topbar__hello"><?= htmlspecialchars(Auth::user()['nombre'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
        <a class="btn btn-ghost btn-sm" href="logout.php">Salir</a>
    </div>
</header>
<main class="admin-main">
    <section class="page-header">
        <h1>Mi Plan</h1>
        <p>Gestioná tu suscripción y elegí el plan que mejor se adapte a tu negocio.</p>
    </section>

    <?php if ($message === 'ok'): ?>
        <div class="alert alert--ok">✓ Cambiaste de plan. Te avisaremos cuando se active.</div>
    <?php elseif ($error !== ''): ?>
        <div class="alert alert--error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <?php if ($currentPlan): ?>
    <div class="plan-hero">
        <div>
            <span class="plan-hero__eyebrow">Tu plan actual</span>
            <h2 class="plan-hero__name"><?= htmlspecialchars($currentPlan['nombre'], ENT_QUOTES, 'UTF-8') ?></h2>
            <div class="plan-hero__price">
                <?php if ((float)$currentPlan['precio'] > 0): ?>
                    $<?= number_format((float)$currentPlan['precio'], 0, ',', '.') ?>
                    <small>/ <?= htmlspecialchars($currentPlan['moneda'] ?? 'UYU', ENT_QUOTES, 'UTF-8') ?> cada <?= (int)$currentPlan['duracion_dias'] ?> días</small>
                <?php else: ?>
                    Gratis
                    <small>período de prueba</small>
                <?php endif; ?>
            </div>
            <div class="plan-hero__meta">
                <?php if ($trialEnd): ?>
                    <span>⏱ Trial hasta: <b><?= htmlspecialchars($trialEnd->format('d/m/Y'), ENT_QUOTES, 'UTF-8') ?></b></span>
                <?php endif; ?>
                <?php if ($daysLeft !== null): ?>
                    <?php if ($daysLeft > 0): ?>
                        <span>📅 Días restantes: <b><?= $daysLeft ?></b></span>
                    <?php else: ?>
                        <span style="color:#fef3c7">⚠ Trial vencido hace <?= abs($daysLeft) ?> día(s)</span>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if ($nextBill && $status === 'active'): ?>
                    <span>💳 Próximo cobro: <b><?= htmlspecialchars($nextBill->format('d/m/Y'), ENT_QUOTES, 'UTF-8') ?></b></span>
                <?php endif; ?>
            </div>
        </div>
        <div class="plan-hero__cta">
            <span class="plan-hero__status"><?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') ?></span>
        </div>
    </div>
    <?php else: ?>
    <div class="alert alert--info">
        Aún no tenés un plan asignado. Elegí uno abajo para empezar a usar Agendarte.
    </div>
    <?php endif; ?>

    <h2 style="margin-top:2rem">Planes disponibles</h2>
    <?php
    $anyAnnual = false;
    foreach ($availablePlans as $ap) {
        if (MembershipPlan::isAnnualEnabled($ap)) {
            $anyAnnual = true;
            break;
        }
    }
    ?>
    <?php if ($anyAnnual): ?>
        <div class="plan-billing-toggle" data-plan-billing-toggle>
            <button type="button" class="is-active" data-billing="monthly">Mensual</button>
            <button type="button" data-billing="yearly">Anual</button>
        </div>
        <p class="muted" style="font-size:.85rem;margin:0 0 1rem">Pagá anualmente y ahorrá cuando el plan tenga descuento configurado.</p>
    <?php endif; ?>
    <?php if (empty($availablePlans)): ?>
        <p class="muted">No hay planes activos. Contactá al super admin.</p>
    <?php else: ?>
        <div class="plans-grid">
            <?php foreach ($availablePlans as $p):
                $isCurrent = $currentPlan && (int)$currentPlan['id_membership'] === (int)$p['id_membership'];
                $displayDesc = MembershipPlan::displayDescription($p);
                $comparisonRows = MembershipPlan::comparisonRows($p);
                $maxProducts = MembershipPlan::maxProducts($p);
                $maxServices = MembershipPlan::maxServices($p);
                $maxAppts = MembershipPlan::maxAppointmentsMonth($p);
                $yearly = MembershipPlan::yearlyPrice($p);
                $discount = MembershipPlan::annualDiscountPct($p);
                $savings = MembershipPlan::annualSavings($p);
                $hasAnnual = MembershipPlan::isAnnualEnabled($p);
            ?>
            <article class="plan-card<?= $isCurrent ? ' is-current' : '' ?>"
                     data-plan-card
                     data-monthly="<?= (float)$p['precio'] ?>"
                     data-yearly="<?= $yearly !== null ? (float)$yearly : '' ?>"
                     data-has-annual="<?= $hasAnnual ? '1' : '0' ?>">
                <?php if ($isCurrent): ?>
                    <span class="plan-card__ribbon">Plan actual</span>
                <?php endif; ?>
                <h3 class="plan-card__name"><?= htmlspecialchars($p['nombre'], ENT_QUOTES, 'UTF-8') ?></h3>
                <div class="plan-card__price">
                    <?php if ((float)$p['precio'] > 0): ?>
                        <span data-plan-price-amount>$<?= number_format((float)$p['precio'], 0, ',', '.') ?></span>
                        <small data-plan-price-period>/ mes</small>
                    <?php else: ?>
                        Gratis
                        <small><?= (int)$p['trial_dias'] ?> días</small>
                    <?php endif; ?>
                </div>
                <?php if ($hasAnnual && $discount > 0): ?>
                    <p class="plan-card__annual-note" data-plan-annual-note hidden>
                        <?= (int)$discount ?>% off anual
                        <?php if ($savings !== null && $savings > 0): ?>
                            · ahorrás $<?= number_format($savings, 0, ',', '.') ?>
                        <?php endif; ?>
                    </p>
                <?php endif; ?>
                <?php
                    $limitBits = [];
                    if ($maxAppts !== null) {
                        $limitBits[] = 'Hasta ' . (int)$maxAppts . ' reservas/mes';
                    }
                    if ($maxServices !== null) {
                        $limitBits[] = (int)$maxServices . ' servicios';
                    }
                    if ($maxProducts !== null) {
                        $limitBits[] = ((int)$maxProducts === 0) ? '0 productos' : ('Hasta ' . (int)$maxProducts . ' productos');
                    }
                    if ($limitBits === [] && MembershipPlan::settingsTier($p) === MembershipPlan::SETTINGS_TIER_FULL) {
                        $limitBits[] = 'Ilimitado';
                    }
                ?>
                <?php if (false): ?>
                    <p class="plan-card__limit"><?= htmlspecialchars(implode(' · ', $limitBits), ENT_QUOTES, 'UTF-8') ?></p>
                <?php endif; ?>
                <?php if ($displayDesc !== '' || !empty($p['descripcion'])): ?>
                    <p class="plan-card__desc"><?= htmlspecialchars($displayDesc !== '' ? $displayDesc : (string)$p['descripcion'], ENT_QUOTES, 'UTF-8') ?></p>
                <?php endif; ?>
                <?php if ($comparisonRows !== []): ?>
                    <ul class="plan-card__features" aria-label="Comparativa de <?= htmlspecialchars((string)$p['nombre'], ENT_QUOTES, 'UTF-8') ?>">
                        <?php foreach ($comparisonRows as $row): ?>
                            <?php $included = !empty($row['included']); ?>
                            <li class="<?= $included ? 'is-included' : 'is-excluded' ?>">
                                <span class="plan-card__feature-icon" aria-hidden="true"><?= $included ? '&#10003;' : '&#10005;' ?></span>
                                <span class="plan-card__feature-copy">
                                    <span class="plan-card__feature-label"><?= htmlspecialchars((string)($row['label'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                                    <span class="plan-card__feature-value"><?= htmlspecialchars((string)($row['value'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <div class="plan-card__cta">
                    <?php if ($isCurrent): ?>
                        <button class="btn btn-ghost" disabled>Ya estás en este plan</button>
                    <?php else: ?>
                        <form method="post" action="api/commerce_select_plan.php">
                            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfPlan, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="id_membership" value="<?= (int)$p['id_membership'] ?>">
                            <input type="hidden" name="billing_period" value="monthly" data-billing-period-input>
                            <button type="submit" class="btn btn-primary">
                                <?= ((float)$p['precio'] > 0) ? 'Elegir este plan' : 'Activar gratis' ?>
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
        <?php if ($anyAnnual): ?>
        <script>
        (function () {
          var toggle = document.querySelector('[data-plan-billing-toggle]');
          if (!toggle) return;
          var period = 'monthly';
          function fmt(n) {
            return '$' + Math.round(n).toLocaleString('es-UY');
          }
          function apply() {
            toggle.querySelectorAll('button').forEach(function (btn) {
              btn.classList.toggle('is-active', btn.getAttribute('data-billing') === period);
            });
            document.querySelectorAll('[data-plan-card]').forEach(function (card) {
              var monthly = parseFloat(card.getAttribute('data-monthly') || '0');
              var yearly = card.getAttribute('data-yearly');
              var hasAnnual = card.getAttribute('data-has-annual') === '1';
              var amountEl = card.querySelector('[data-plan-price-amount]');
              var periodEl = card.querySelector('[data-plan-price-period]');
              var note = card.querySelector('[data-plan-annual-note]');
              var input = card.querySelector('[data-billing-period-input]');
              var useYearly = period === 'yearly' && hasAnnual && yearly !== '';
              if (amountEl && periodEl && monthly > 0) {
                if (useYearly) {
                  amountEl.textContent = fmt(parseFloat(yearly));
                  periodEl.textContent = '/ año';
                } else {
                  amountEl.textContent = fmt(monthly);
                  periodEl.textContent = '/ mes';
                }
              }
              if (note) note.hidden = !useYearly;
              if (input) input.value = useYearly ? 'yearly' : 'monthly';
            });
          }
          toggle.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-billing]');
            if (!btn) return;
            period = btn.getAttribute('data-billing') || 'monthly';
            apply();
          });
          apply();
        })();
        </script>
        <?php endif; ?>
    <?php endif; ?>

    <article class="card" style="margin-top:2rem">
        <h2>¿Cómo funciona el pago?</h2>
        <p>Una vez que elegís un plan, podés pagar con:</p>
        <ul>
            <li><b>MercadoPago</b> — suscripción automática con tarjeta o dinero en cuenta</li>
            <li><b>PayPal</b> — tarjeta internacional</li>
            <li><b>Transferencia bancaria</b> — subís el comprobante y el super admin lo aprueba</li>
        </ul>
        <p class="muted">El pago queda en estado "pendiente" hasta que se confirme. Mientras tanto, tu cuenta sigue funcionando con las funciones del plan actual.</p>
    </article>
</main>
</body>
</html>
