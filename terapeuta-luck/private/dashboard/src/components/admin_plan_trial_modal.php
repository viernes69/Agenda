<?php
ob_start();
$renewPlanUrl = \Agenduy\Core\PlatformSettings::whatsappUrl('Hola, quiero contratar un plan de Agendarte UY');

// Obtener el plan Free y su ID para el botón de activación directa
$freePlanIdForModal = 0;
$freePlanCsrfForModal = '';
if (isset($planMembershipPlans) && is_array($planMembershipPlans)) {
    foreach ($planMembershipPlans as $mp) {
        if (is_array($mp) && (float)($mp['precio'] ?? 1) <= 0) {
            $freePlanIdForModal = (int)$mp['id_membership'];
            break;
        }
    }
}
if (isset($planMembershipCsrf)) {
    $freePlanCsrfForModal = (string)$planMembershipCsrf;
}
$selectUrlForModal = isset($planMembershipSelectUrl) ? (string)$planMembershipSelectUrl : '';
?>
<div class="modal modal--locked" role="dialog" aria-modal="true" aria-labelledby="plan-trial-expired-title" data-plan-expired-modal hidden>
  <div class="modal__backdrop"></div>
  <div class="modal__dialog modal__dialog--sm plan-lock-modal">
    <header class="modal__header" style="border-bottom: none; padding-bottom: 0;">
      <div class="modal__header-text" style="text-align: center; padding-top: 0.5rem;">
        <div style="font-size: 3rem; margin-bottom: 0.5rem;">⏰</div>
        <p class="modal__eyebrow" style="color: var(--warn, #f59e0b);">Tu prueba gratuita finalizó</p>
        <h2 id="plan-trial-expired-title" style="font-size: 1.25rem;">¿Seguís con Agendarte UY?</h2>
      </div>
    </header>
    <div class="modal__body plan-lock-modal__body" style="padding-top: 0.75rem; text-align: center;">
      <p style="margin-bottom: 0.75rem; color: var(--text-muted, #666);">
        Tu <strong>prueba de 24 horas del Plan Profesional</strong> ha finalizado.
        Elegí un plan de pago para mantener todas las funciones, o continuá gratis.
      </p>

      <div style="background: var(--surface-2, #f9f9f9); border-radius: 12px; padding: 1rem; margin-bottom: 1.25rem; border: 1px solid var(--border, #e5e7eb);">
        <p style="font-weight: 700; font-size: 0.85rem; margin-bottom: 0.5rem; color: var(--text, #111);">Incluido en el Plan Profesional:</p>
        <ul style="list-style: none; padding: 0; margin: 0; font-size: 0.82rem; color: var(--text-muted, #555);">
          <li>✓ Profesionales y reservas ilimitadas</li>
          <li>✓ Notificaciones WhatsApp &amp; Email</li>
          <li>✓ Recordatorio Google Calendar</li>
          <li>✓ Sistema de puntos para clientes</li>
          <li>✓ Pago por servicios online</li>
        </ul>
      </div>

      <div class="plan-lock-modal__actions" style="display: flex; flex-direction: column; gap: 0.5rem;">
        <!-- Ver planes de pago -->
        <button type="button"
                class="btn btn-success plan-lock-modal__btn"
                data-plan-membership-open
                style="width: 100%; font-size: 0.95rem; font-weight: 700;">
          🚀 Ver planes y elegir
        </button>

        <?php if ($renewPlanUrl !== ''): ?>
        <a class="btn btn-outline plan-lock-modal__btn"
           href="<?php echo htmlspecialchars($renewPlanUrl, ENT_QUOTES, 'UTF-8'); ?>"
           target="_blank" rel="noopener"
           style="width: 100%; font-size: 0.85rem; text-align: center;">
          Consultar por WhatsApp
        </a>
        <?php endif; ?>

        <!-- Activar Plan Free directamente -->
        <?php if ($freePlanIdForModal > 0 && $selectUrlForModal !== ''): ?>
        <form method="post"
              action="<?php echo htmlspecialchars($selectUrlForModal, ENT_QUOTES, 'UTF-8'); ?>"
              data-plan-membership-form
              data-plan-price="0"
              data-plan-id="<?php echo $freePlanIdForModal; ?>"
              data-plan-currency="UYU"
              data-plan-name="Free"
              style="margin: 0;">
          <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars($freePlanCsrfForModal, ENT_QUOTES, 'UTF-8'); ?>">
          <input type="hidden" name="id_membership" value="<?php echo $freePlanIdForModal; ?>">
          <input type="hidden" name="billing_period" value="monthly">
          <button type="submit"
                  class="btn plan-lock-modal__btn"
                  style="width: 100%;
                         font-size: 0.82rem;
                         color: var(--text-muted, #888);
                         background: transparent;
                         border: 1px solid var(--border, #e5e7eb);
                         cursor: pointer;
                         padding: 0.5rem;">
            Me quedo con el Plan Free (sin costo)
          </button>
        </form>
        <?php endif; ?>
      </div>

      <p style="font-size: 0.75rem; color: var(--text-muted, #aaa); margin-top: 0.75rem;">
        El Plan Free incluye hasta 25 reservas/mes y 1 profesional.
      </p>
    </div>
  </div>
</div>
<?php
return trim(ob_get_clean());
?>
