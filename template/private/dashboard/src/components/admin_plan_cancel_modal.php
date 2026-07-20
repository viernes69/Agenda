<?php
$reactivatePlanUrl = \Agenduy\Core\PlatformSettings::whatsappUrl('Hola, Deseo reactivar mi plan para Agenduy');
ob_start();
?>
<div class="modal modal--locked" role="dialog" aria-modal="true" aria-labelledby="plan-cancelled-title" data-plan-cancel-modal hidden>
  <div class="modal__backdrop"></div>
  <div class="modal__dialog modal__dialog--sm plan-lock-modal">
    <header class="modal__header">
      <div class="modal__header-text">
        <p class="modal__eyebrow">Suscripcion</p>
        <h2 id="plan-cancelled-title">Servicio cancelado</h2>
      </div>
    </header>
    <div class="modal__body plan-lock-modal__body">
      <p>Su servicio ha sido cancelado por el desarrollador. Por favor comuniquese con nosotros para reactivar el servicio.</p>
      <?php if ($reactivatePlanUrl !== ''): ?>
      <div class="plan-lock-modal__actions">
        <a class="btn btn-success plan-lock-modal__btn" href="<?php echo htmlspecialchars($reactivatePlanUrl, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">Reactivar</a>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php
return trim(ob_get_clean());
?>
