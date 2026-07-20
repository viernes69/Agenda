<?php
declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');

require __DIR__ . '/../Core/bootstrap.php';

use Agenduy\Core\Database;
use Agenduy\Core\MembershipPlan;

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$db = Database::getInstance();
$planes = $db->fetchAll('SELECT * FROM memberships WHERE activo = 1 ORDER BY precio ASC, id_membership ASC');
$planCount = count($planes);
$anyAnnual = false;
foreach ($planes as $ap) {
    if (MembershipPlan::isAnnualEnabled($ap)) {
        $anyAnnual = true;
        break;
    }
}
?>
<div class="plan-modal">
  <header class="plan-header">
    <div class="plan-header__text">
      <h3 id="modal-planes-title">Planes disponibles</h3>
      <p class="plan-subtitle">Elegí el plan que mejor se adapte a tu negocio.</p>
    </div>
    <button type="button" class="plan-close" aria-label="Cerrar">&times;</button>
  </header>
  <div class="plan-body">
    <?php if (empty($planes)): ?>
      <div class="cat-empty"><p>No hay planes activos.</p></div>
    <?php else: ?>
      <?php if ($anyAnnual): ?>
        <div class="plan-billing-toggle" data-landing-billing-toggle>
          <button type="button" class="is-active" data-billing="monthly">Mensual</button>
          <button type="button" data-billing="yearly">Anual</button>
        </div>
      <?php endif; ?>
      <div class="plan-grid" role="list">
        <?php foreach ($planes as $i => $p):
          $precio = (float)$p['precio'];
          $isFree = $precio <= 0;
          $trialDias = (int)($p['trial_dias'] ?? 0);
          $descripcion = trim((string)($p['descripcion'] ?? ''));
          $isFeatured = $planCount >= 3 && $i === 1;
          $ctaLabel = $isFree ? 'Empezar gratis' : 'Elegir este plan';
          $features = MembershipPlan::features($p);
          $maxProducts = MembershipPlan::maxProducts($p);
          $maxServices = MembershipPlan::maxServices($p);
          $maxAppts = MembershipPlan::maxAppointmentsMonth($p);
          $maxProfessionals = MembershipPlan::maxProfessionals($p);
          $maxClients = MembershipPlan::maxClients($p);
          $yearly = MembershipPlan::yearlyPrice($p);
          $discount = MembershipPlan::annualDiscountPct($p);
          $hasAnnual = MembershipPlan::isAnnualEnabled($p);
        ?>
          <article class="plan-card<?= $isFeatured ? ' plan-card--featured' : '' ?>"
                   role="listitem"
                   data-landing-plan
                   data-monthly="<?= $precio ?>"
                   data-yearly="<?= $yearly !== null ? $yearly : '' ?>"
                   data-has-annual="<?= $hasAnnual ? '1' : '0' ?>">
            <?php if ($isFeatured): ?>
              <span class="plan-card__badge">Recomendado</span>
            <?php endif; ?>
            <h4 class="plan-card__name"><?= h((string)$p['nombre']) ?></h4>
            <div class="plan-card__price">
              <?php if ($isFree): ?>
                <span class="plan-card__amount">Gratis</span>
              <?php else: ?>
                <span class="plan-card__currency"><?= h((string)$p['moneda']) ?></span>
                <span class="plan-card__amount" data-landing-price-amount><?= number_format($precio, 0, ',', '.') ?></span>
                <span class="plan-card__period" data-landing-price-period>/ mes</span>
              <?php endif; ?>
            </div>
            <?php if ($hasAnnual && $discount > 0): ?>
              <p class="plan-card__annual-note" data-landing-annual-note hidden><?= (int)$discount ?>% off al pagar anual</p>
            <?php endif; ?>
            <?php
              $limitBits = [];
              if ($maxAppts !== null) {
                  $limitBits[] = 'Hasta ' . (int)$maxAppts . ' reservas/mes';
              }
              if ($maxProfessionals !== null) {
                  $limitBits[] = ((int)$maxProfessionals === 1)
                      ? '1 profesional'
                      : ('Hasta ' . (int)$maxProfessionals . ' profesionales');
              }
              if ($maxClients !== null) {
                  $limitBits[] = 'Hasta ' . (int)$maxClients . ' clientes';
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
            <?php if ($limitBits !== []): ?>
              <p class="plan-card__limit"><?= h(implode(' · ', $limitBits)) ?></p>
            <?php endif; ?>
            <?php if ($trialDias > 0): ?>
              <p class="plan-card__trial"><?= $trialDias ?> días gratis</p>
            <?php endif; ?>
            <?php if ($descripcion !== ''): ?>
              <p class="plan-card__desc"><?= h($descripcion) ?></p>
            <?php endif; ?>
            <?php if ($features !== []): ?>
              <ul class="plan-card__features">
                <?php foreach ($features as $feat): ?>
                  <li><?= h($feat) ?></li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
            <button type="button" class="plan-btn plan-card__cta"
              data-plan-id="<?= (int)$p['id_membership'] ?>"
              data-plan-nombre="<?= h((string)$p['nombre']) ?>"><?= h($ctaLabel) ?></button>
          </article>
        <?php endforeach; ?>
      </div>
      <?php if ($anyAnnual): ?>
      <script>
      (function () {
        var toggle = document.querySelector('[data-landing-billing-toggle]');
        if (!toggle) return;
        var period = 'monthly';
        function apply() {
          toggle.querySelectorAll('button').forEach(function (btn) {
            btn.classList.toggle('is-active', btn.getAttribute('data-billing') === period);
          });
          document.querySelectorAll('[data-landing-plan]').forEach(function (card) {
            var monthly = parseFloat(card.getAttribute('data-monthly') || '0');
            var yearly = card.getAttribute('data-yearly');
            var hasAnnual = card.getAttribute('data-has-annual') === '1';
            var amountEl = card.querySelector('[data-landing-price-amount]');
            var periodEl = card.querySelector('[data-landing-price-period]');
            var note = card.querySelector('[data-landing-annual-note]');
            var useYearly = period === 'yearly' && hasAnnual && yearly !== '';
            if (amountEl && periodEl && monthly > 0) {
              amountEl.textContent = Math.round(useYearly ? parseFloat(yearly) : monthly).toLocaleString('es-UY');
              periodEl.textContent = useYearly ? '/ año' : '/ mes';
            }
            if (note) note.hidden = !useYearly;
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
  </div>
</div>
