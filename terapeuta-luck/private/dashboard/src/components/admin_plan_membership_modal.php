<?php
/**
 * Modal: ver / cambiar membresía (reusa admin/api/commerce_select_plan.php).
 * Espera: $planBannerData, $planMembershipPlans, $planMembershipCsrf,
 *         $planMembershipSelectUrl, $canSelectPlan,
 *         $planMembershipPayMethods, $planMembershipTransferInfo,
 *         $planMembershipPaypalUrl, $planMembershipTransferUrl, $planMembershipMpUrl,
 *         $tenantSlug, $tenantNegocioId
 */
ob_start();

use Agenduy\Core\MembershipPlan;

$currentMembershipId = isset($planBannerData['membership_id']) ? (int)$planBannerData['membership_id'] : 0;
$bannerTitle = isset($planBannerData['title']) ? (string)$planBannerData['title'] : 'Tu plan';
$bannerMessage = isset($planBannerData['message']) ? (string)$planBannerData['message'] : '';
$bannerBadge = isset($planBannerData['badge']) ? (string)$planBannerData['badge'] : '';
$plans = isset($planMembershipPlans) && is_array($planMembershipPlans) ? $planMembershipPlans : [];
$csrf = isset($planMembershipCsrf) ? (string)$planMembershipCsrf : '';
$selectUrl = isset($planMembershipSelectUrl) ? (string)$planMembershipSelectUrl : '';
$paypalUrl = isset($planMembershipPaypalUrl) ? (string)$planMembershipPaypalUrl : url('admin/api/paypal.php');
$transferUrl = isset($planMembershipTransferUrl) ? (string)$planMembershipTransferUrl : url('admin/api/transfer_upload.php');
$mpUrl = isset($planMembershipMpUrl) ? (string)$planMembershipMpUrl : url('admin/api/mercadopago.php');
$canSelect = !empty($canSelectPlan);
$payMethods = isset($planMembershipPayMethods) && is_array($planMembershipPayMethods)
    ? $planMembershipPayMethods
    : ['paypal' => false, 'transfer' => false, 'mercadopago' => false];
$transferInfo = isset($planMembershipTransferInfo) && is_array($planMembershipTransferInfo)
    ? $planMembershipTransferInfo
    : ['banco' => '', 'titular' => '', 'cuenta' => '', 'moneda' => 'UYU', 'instrucciones' => ''];
$commerceSlug = isset($tenantSlug) ? (string)$tenantSlug : '';
$commerceId = isset($tenantNegocioId) ? (int)$tenantNegocioId : 0;

if (!function_exists('e')) {
    function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

$plans = array_values(array_filter($plans, static function ($p) {
    return is_array($p);
}));
$anyAnnual = false;
foreach ($plans as $ap) {
    if (MembershipPlan::isAnnualEnabled($ap)) {
        $anyAnnual = true;
        break;
    }
}
$hasPaypal = !empty($payMethods['paypal']);
$hasTransfer = !empty($payMethods['transfer']);
$hasMp = !empty($payMethods['mercadopago']);
$hasAnyPayMethod = $hasPaypal || $hasTransfer || $hasMp;
$enabledPayLabels = [];
if ($hasPaypal) {
    $enabledPayLabels[] = 'PayPal';
}
if ($hasTransfer) {
    $enabledPayLabels[] = 'transferencia';
}
if ($hasMp) {
    $enabledPayLabels[] = 'Mercado Pago';
}
$planTotal = count($plans);
$useCarousel = $planTotal > 3;
?>
<div
  class="modal"
  role="dialog"
  aria-modal="true"
  aria-labelledby="plan-membership-title"
  data-admin-modal="plan-membership"
  data-plan-membership-modal
  data-plan-select-url="<?php echo e($selectUrl); ?>"
  data-plan-paypal-url="<?php echo e($paypalUrl); ?>"
  data-plan-transfer-url="<?php echo e($transferUrl); ?>"
  data-plan-mp-url="<?php echo e($mpUrl); ?>"
  data-plan-commerce-slug="<?php echo e($commerceSlug); ?>"
  data-plan-commerce-id="<?php echo e((string)$commerceId); ?>"
  data-plan-pay-paypal="<?php echo !empty($payMethods['paypal']) ? '1' : '0'; ?>"
  data-plan-pay-transfer="<?php echo !empty($payMethods['transfer']) ? '1' : '0'; ?>"
  data-plan-pay-mp="<?php echo !empty($payMethods['mercadopago']) ? '1' : '0'; ?>"
  hidden
>
  <div class="modal__backdrop" data-plan-membership-close></div>
  <div class="modal__dialog modal__dialog--lg plan-membership-modal">
    <header class="modal__header">
      <div class="modal__header-text">
        <p class="modal__eyebrow">Membresía</p>
        <h2 id="plan-membership-title"><?php echo e($bannerTitle); ?></h2>
        <?php if ($bannerMessage !== ''): ?>
          <p class="modal__subtitle">
            <?php if ($bannerBadge !== ''): ?>
              <span class="plan-membership-modal__status"><?php echo e($bannerBadge); ?></span>
            <?php endif; ?>
            <?php echo e($bannerMessage); ?>
          </p>
        <?php endif; ?>
      </div>
      <button type="button" class="modal__close" data-plan-membership-close aria-label="Cerrar">&times;</button>
    </header>
    <div class="modal__body plan-membership-modal__body">
      <p class="plan-membership-modal__hint" data-plan-membership-feedback hidden></p>

      <div data-plan-membership-plans>
      <?php if ($plans === []): ?>
        <p class="plan-membership-modal__empty">No hay planes activos. Contactá al soporte de Agenduy.</p>
      <?php else: ?>
        <?php if ($anyAnnual): ?>
          <div class="plan-membership-billing" data-plan-membership-billing>
            <button type="button" class="is-active" data-billing="monthly">Mensual</button>
            <button type="button" data-billing="yearly">Anual</button>
          </div>
        <?php endif; ?>
        <?php if ($useCarousel): ?>
        <div class="plan-membership-carousel" data-plan-membership-carousel>
          <button type="button" class="plan-membership-carousel__nav plan-membership-carousel__nav--prev" data-plan-carousel-prev aria-label="Planes anteriores" disabled>&lsaquo;</button>
          <div class="plan-membership-carousel__viewport" data-plan-carousel-viewport>
        <?php endif; ?>
        <div class="plan-membership-grid<?php echo $useCarousel ? ' plan-membership-grid--carousel' : ''; ?>"
             data-plan-membership-grid
             data-plan-count="<?php echo e((string)$planTotal); ?>"
             <?php echo $useCarousel ? 'data-plan-carousel-track' : ''; ?>>
          <?php
            $planIndex = 0;
            foreach ($plans as $p):
            $pid = (int)($p['id_membership'] ?? 0);
            $isCurrent = $pid > 0 && $pid === $currentMembershipId;
            $precio = (float)($p['precio'] ?? 0);
            $nombre = trim((string)($p['nombre'] ?? 'Plan'));
            $desc = trim((string)($p['descripcion'] ?? ''));
            $moneda = strtoupper(trim((string)($p['moneda'] ?? 'UYU')));
            $trialDias = (int)($p['trial_dias'] ?? 0);
            $displayDesc = MembershipPlan::displayDescription($p);
            $comparisonRows = MembershipPlan::comparisonRows($p);
            $yearly = MembershipPlan::yearlyPrice($p);
            $discount = MembershipPlan::annualDiscountPct($p);
            $hasAnnual = MembershipPlan::isAnnualEnabled($p);
            $isFeatured = !$isCurrent && $planTotal >= 3 && $planIndex === 1;
            $planIndex++;
          ?>
            <article class="plan-membership-card<?php echo $isCurrent ? ' is-current' : ''; ?><?php echo $isFeatured ? ' is-featured' : ''; ?>"
                     data-plan-membership-card
                     data-monthly="<?php echo e((string)$precio); ?>"
                     data-yearly="<?php echo e($yearly !== null ? (string)$yearly : ''); ?>"
                     data-has-annual="<?php echo $hasAnnual ? '1' : '0'; ?>"
                     data-plan-currency="<?php echo e($moneda); ?>"
                     data-plan-name="<?php echo e($nombre); ?>">
              <?php if ($isCurrent): ?>
                <span class="plan-membership-card__ribbon">Actual</span>
              <?php elseif ($isFeatured): ?>
                <span class="plan-membership-card__ribbon plan-membership-card__ribbon--soft">Recomendado</span>
              <?php endif; ?>
              <h3 class="plan-membership-card__name"><?php echo e($nombre); ?></h3>
              <p class="plan-membership-card__price">
                <?php if ($precio > 0): ?>
                  <span data-plan-membership-price>$<?php echo e(number_format($precio, 0, ',', '.')); ?></span>
                  <small data-plan-membership-period>/ mes</small>
                <?php else: ?>
                  Gratis
                  <?php if ($trialDias > 0): ?>
                    <small><?php echo e((string)$trialDias); ?> días</small>
                  <?php endif; ?>
                <?php endif; ?>
              </p>
              <?php if ($hasAnnual && $discount > 0): ?>
                <p class="plan-membership-card__annual" data-plan-membership-annual hidden>
                  <?php echo e((string)(int)$discount); ?>% off anual
                </p>
              <?php endif; ?>
              <?php if ($displayDesc !== '' || $desc !== ''): ?>
                <p class="plan-membership-card__desc"><?php echo e($displayDesc !== '' ? $displayDesc : $desc); ?></p>
              <?php endif; ?>
              <?php if (false): ?>
                <p class="plan-membership-card__limit"><?php echo e(implode(' · ', $limitBits)); ?></p>
              <?php endif; ?>
              <?php if ($comparisonRows !== []): ?>
                <ul class="plan-membership-card__features" aria-label="Comparativa de <?php echo e($nombre); ?>">
                  <?php foreach ($comparisonRows as $row): ?>
                    <?php $included = !empty($row['included']); ?>
                    <li class="<?php echo $included ? 'is-included' : 'is-excluded'; ?>">
                      <span class="plan-membership-card__feature-icon" aria-hidden="true"><?php echo $included ? '&#10003;' : '&#10005;'; ?></span>
                      <span class="plan-membership-card__feature-copy">
                        <span class="plan-membership-card__feature-label"><?php echo e((string)($row['label'] ?? '')); ?></span>
                        <span class="plan-membership-card__feature-value"><?php echo e((string)($row['value'] ?? '')); ?></span>
                      </span>
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>
              <div class="plan-membership-card__cta">
                <?php if ($isCurrent): ?>
                  <?php if ($precio > 0): ?>
                    <form method="post" action="<?php echo e($selectUrl); ?>" data-plan-membership-form
                          data-plan-price="<?php echo e((string)$precio); ?>"
                          data-plan-name="<?php echo e($nombre); ?>"
                          data-plan-currency="<?php echo e($moneda); ?>"
                          data-plan-id="<?php echo e((string)$pid); ?>">
                      <input type="hidden" name="_csrf" value="<?php echo e($csrf); ?>">
                      <input type="hidden" name="id_membership" value="<?php echo e((string)$pid); ?>">
                      <input type="hidden" name="billing_period" value="monthly" data-plan-membership-billing-input>
                      <button type="submit" class="btn btn-success">Pagar plan</button>
                    </form>
                  <?php else: ?>
                    <button type="button" class="btn btn-outline" disabled>Plan actual</button>
                  <?php endif; ?>
                <?php elseif ($canSelect && $csrf !== '' && $pid > 0): ?>
                  <form method="post" action="<?php echo e($selectUrl); ?>" data-plan-membership-form
                        data-plan-price="<?php echo e((string)$precio); ?>"
                        data-plan-name="<?php echo e($nombre); ?>"
                        data-plan-currency="<?php echo e($moneda); ?>"
                        data-plan-id="<?php echo e((string)$pid); ?>">
                    <input type="hidden" name="_csrf" value="<?php echo e($csrf); ?>">
                    <input type="hidden" name="id_membership" value="<?php echo e((string)$pid); ?>">
                    <input type="hidden" name="billing_period" value="monthly" data-plan-membership-billing-input>
                    <button type="submit" class="btn btn-success">
                      <?php echo $precio > 0 ? 'Elegir este plan' : 'Activar gratis'; ?>
                    </button>
                  </form>
                <?php else: ?>
                  <button type="button" class="btn btn-outline" disabled>Solo el admin puede cambiar</button>
                <?php endif; ?>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
        <?php if ($useCarousel): ?>
          </div>
          <button type="button" class="plan-membership-carousel__nav plan-membership-carousel__nav--next" data-plan-carousel-next aria-label="Planes siguientes">&rsaquo;</button>
          <div class="plan-membership-carousel__dots" data-plan-carousel-dots role="tablist" aria-label="Páginas de planes"></div>
        </div>
        <?php endif; ?>
        <p class="plan-membership-modal__note">
          <?php if ($hasAnyPayMethod): ?>
            <?php
              $payList = '';
              $n = count($enabledPayLabels);
              if ($n === 1) {
                  $payList = $enabledPayLabels[0];
              } elseif ($n === 2) {
                  $payList = $enabledPayLabels[0] . ' o ' . $enabledPayLabels[1];
              } else {
                  $payList = $enabledPayLabels[0] . ', ' . $enabledPayLabels[1] . ' o ' . $enabledPayLabels[2];
              }
            ?>
            Al elegir un plan pago vas a poder pagar con <?php echo e($payList); ?>. Tu cuenta sigue con el plan actual hasta confirmar el cobro.
          <?php else: ?>
            Al elegir un plan pago, el cobro queda pendiente hasta confirmarse. Contactá a soporte si no ves métodos de pago.
          <?php endif; ?>
          <?php if ($anyAnnual): ?>
            Si elegis anual, el checkout usa el total anual con el descuento configurado.
          <?php endif; ?>
        </p>
      <?php endif; ?>
      </div>

      <div class="plan-membership-pay" data-plan-membership-pay hidden>
        <button type="button" class="plan-membership-pay__back" data-plan-membership-pay-back>&larr; Volver a planes</button>
        <h3 class="plan-membership-pay__title">Pagar <span data-plan-pay-name>plan</span></h3>
        <p class="plan-membership-pay__amount">
          <strong data-plan-pay-amount>$0</strong>
          <span data-plan-pay-period-label>/ mes</span>
        </p>
        <p class="plan-membership-pay__intro">Elegí cómo querés pagar. El plan se activa cuando el pago se confirma.</p>

        <div class="plan-membership-pay__methods" data-plan-pay-methods>
          <?php if ($hasPaypal): ?>
            <button type="button" class="btn btn-success plan-membership-pay__btn" data-plan-pay-method="paypal">
              Pagar con PayPal
            </button>
          <?php endif; ?>
          <?php if ($hasTransfer): ?>
            <button type="button" class="btn btn-outline plan-membership-pay__btn" data-plan-pay-method="transfer">
              Transferencia bancaria
            </button>
          <?php endif; ?>
          <?php if ($hasMp): ?>
            <button type="button" class="btn btn-outline plan-membership-pay__btn" data-plan-pay-method="mercadopago">
              Mercado Pago
            </button>
          <?php endif; ?>
          <?php if (!$hasAnyPayMethod): ?>
            <p class="plan-membership-modal__empty">No hay métodos de pago habilitados. Contactá a soporte.</p>
          <?php endif; ?>
        </div>

        <div class="plan-membership-transfer" data-plan-pay-transfer hidden>
          <h4>Datos para transferir</h4>
          <dl class="plan-membership-transfer__dl">
            <?php if (trim((string)$transferInfo['banco']) !== ''): ?>
              <div><dt>Banco</dt><dd><?php echo e($transferInfo['banco']); ?></dd></div>
            <?php endif; ?>
            <?php if (trim((string)$transferInfo['titular']) !== ''): ?>
              <div><dt>Titular</dt><dd><?php echo e($transferInfo['titular']); ?></dd></div>
            <?php endif; ?>
            <?php if (trim((string)$transferInfo['cuenta']) !== ''): ?>
              <div><dt>Cuenta</dt><dd><code><?php echo e($transferInfo['cuenta']); ?></code></dd></div>
            <?php endif; ?>
            <div><dt>Moneda</dt><dd data-plan-transfer-currency><?php echo e($transferInfo['moneda'] ?: 'UYU'); ?></dd></div>
            <div><dt>Monto</dt><dd data-plan-transfer-amount>$0</dd></div>
          </dl>
          <?php if (trim((string)$transferInfo['instrucciones']) !== ''): ?>
            <p class="plan-membership-transfer__hint"><?php echo e($transferInfo['instrucciones']); ?></p>
          <?php endif; ?>
          <form class="plan-membership-transfer__form" data-plan-transfer-form enctype="multipart/form-data">
            <input type="hidden" name="_csrf" value="<?php echo e($csrf); ?>">
            <input type="hidden" name="id_membership" value="" data-plan-transfer-membership>
            <input type="hidden" name="billing_period" value="monthly" data-plan-transfer-billing>
            <input type="hidden" name="monto" value="" data-plan-transfer-monto>
            <input type="hidden" name="moneda" value="<?php echo e($transferInfo['moneda'] ?: 'UYU'); ?>">
            <input type="hidden" name="slug" value="<?php echo e($commerceSlug); ?>">
            <label class="plan-membership-transfer__field">
              <span>Referencia / concepto</span>
              <input type="text" name="referencia" placeholder="Ej. Agenduy + nombre del negocio" maxlength="120">
            </label>
            <label class="plan-membership-transfer__field">
              <span>Fecha de transferencia</span>
              <input type="text" name="fecha_transferencia" inputmode="numeric" autocomplete="off" placeholder="dd/mm/aaaa" maxlength="10" title="Formato: dd/mm/aaaa">
            </label>
            <label class="plan-membership-transfer__field">
              <span>Banco de origen (opcional)</span>
              <input type="text" name="banco_origen" maxlength="80">
            </label>
            <label class="plan-membership-transfer__field">
              <span>Comprobante (JPG, PNG, WebP o PDF)</span>
              <input type="file" name="comprobante" accept="image/jpeg,image/png,image/webp,application/pdf" required>
            </label>
            <button type="submit" class="btn btn-success">Enviar comprobante</button>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>
<script>
(function () {
  var root = document.querySelector('[data-plan-membership-modal]');
  if (!root) return;
  var toggle = root.querySelector('[data-plan-membership-billing]');
  if (!toggle) return;
  var period = 'monthly';
  function apply() {
    toggle.querySelectorAll('button').forEach(function (btn) {
      btn.classList.toggle('is-active', btn.getAttribute('data-billing') === period);
    });
    root.querySelectorAll('[data-plan-membership-card]').forEach(function (card) {
      var monthly = parseFloat(card.getAttribute('data-monthly') || '0');
      var yearly = card.getAttribute('data-yearly');
      var hasAnnual = card.getAttribute('data-has-annual') === '1';
      var priceEl = card.querySelector('[data-plan-membership-price]');
      var periodEl = card.querySelector('[data-plan-membership-period]');
      var note = card.querySelector('[data-plan-membership-annual]');
      var input = card.querySelector('[data-plan-membership-billing-input]');
      var useYearly = period === 'yearly' && hasAnnual && yearly !== '';
      if (priceEl && periodEl && monthly > 0) {
        var val = useYearly ? parseFloat(yearly) : monthly;
        priceEl.textContent = '$' + Math.round(val).toLocaleString('es-UY');
        periodEl.textContent = useYearly ? '/ año' : '/ mes';
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
<?php
return trim(ob_get_clean());
?>
