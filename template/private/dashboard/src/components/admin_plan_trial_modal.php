<?php
$renewPlanUrl = \Agenduy\Core\PlatformSettings::whatsappUrl('Hola, Deseo activar mi plan mensual para Agenduy');
ob_start();
?>
<div class="modal modal--locked" role="dialog" aria-modal="true" aria-labelledby="plan-trial-expired-title" data-plan-expired-modal hidden>
  <div class="modal__backdrop"></div>
  <div class="modal__dialog modal__dialog--sm plan-lock-modal">
    <header class="modal__header">
      <div class="modal__header-text">
        <p class="modal__eyebrow">Suscripcion</p>
        <h2 id="plan-trial-expired-title">Tu prueba ha finalizado</h2>
      </div>
    </header>
    <div class="modal__body plan-lock-modal__body">
      <p>Tu prueba ha finalizado. Si quieres seguir trabajando con nuestros servicios te invitamos a renovar tu plan mensual a traves de Mercado Pago.</p>
      <p>Por tan solo $800 al mes podras acceder a las mejores funcionalidades de nuestro sistema de agendas.</p>
      <?php if ($renewPlanUrl !== ''): ?>
      <div class="plan-lock-modal__actions">
        <a class="btn btn-success plan-lock-modal__btn" href="<?php echo htmlspecialchars($renewPlanUrl, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">Renovar</a>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php
return trim(ob_get_clean());
?>
