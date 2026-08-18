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
          <button type="button" class="is-active" data-billing="monthly">Facturación Mensual</button>
          <button type="button" data-billing="yearly">Facturación Anual <span class="plan-badge-discount">-20% OFF</span></button>
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
          $displayDesc = MembershipPlan::displayDescription($p);
          $comparisonRows = MembershipPlan::comparisonRows($p);
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
                   data-plan-id="<?= (int)$p['id_membership'] ?>"
                   data-plan-nombre="<?= h((string)$p['nombre']) ?>"
                   data-monthly="<?= $precio ?>"
                   data-yearly="<?= $yearly !== null ? $yearly : '' ?>"
                   data-has-annual="<?= $hasAnnual ? '1' : '0' ?>"
                   data-discount-pct="<?= (int)$discount ?>"
                   data-currency="<?= h((string)$p['moneda']) ?>">
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
              
              $combinedFeatures = [];
              foreach (array_merge($limitBits, $features) as $f) {
                  $f = trim($f);
                  if ($f !== '' && !in_array($f, $combinedFeatures, true)) {
                      $combinedFeatures[] = $f;
                  }
              }
            ?>
            <?php if (!$isFree && $trialDias > 0): ?>
              <p class="plan-card__trial"><?= $trialDias ?> días de prueba gratis</p>
            <?php endif; ?>
            <?php if ($displayDesc !== '' || $descripcion !== ''): ?>
              <p class="plan-card__desc"><?= h($displayDesc !== '' ? $displayDesc : $descripcion) ?></p>
            <?php endif; ?>
            <?php if ($comparisonRows !== []): ?>
              <ul class="plan-card__features" aria-label="Comparativa de <?= h((string)$p['nombre']) ?>">
                <?php foreach ($comparisonRows as $row): ?>
                  <?php $included = !empty($row['included']); ?>
                  <li class="<?= $included ? 'is-included' : 'is-excluded' ?>">
                    <span class="plan-card__feature-icon" aria-hidden="true"><?= $included ? '&#10003;' : '&#10005;' ?></span>
                    <span class="plan-card__feature-copy">
                      <span class="plan-card__feature-label"><?= h((string)($row['label'] ?? '')) ?></span>
                      <span class="plan-card__feature-value"><?= h((string)($row['value'] ?? '')) ?></span>
                    </span>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
            <button type="button" class="plan-btn plan-card__cta"
              data-plan-id="<?= (int)$p['id_membership'] ?>"
              data-plan-nombre="<?= h((string)$p['nombre']) ?>"
              data-billing-period="monthly"
              data-monthly-price="<?= $precio ?>"
              data-yearly-price="<?= $yearly !== null ? $yearly : '' ?>"><?= h($ctaLabel) ?></button>
          </article>
        <?php endforeach; ?>
      </div>

    <?php endif; ?>
  </div>
</div>
