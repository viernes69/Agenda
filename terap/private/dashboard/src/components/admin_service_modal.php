<?php
ob_start();
?>
<div class="modal" role="dialog" aria-modal="true" aria-labelledby="admin-service-title" data-admin-modal="service" hidden>
  <div class="modal__backdrop" data-admin-service-close></div>
  <div class="modal__dialog">
    <header class="modal__header">
      <div class="modal__header-text">
        <p class="modal__eyebrow">Atención</p>
        <h2 id="admin-service-title">Estás atendiendo este servicio</h2>
      </div>
      <button type="button" class="modal__close" data-admin-service-close aria-label="Cerrar">&times;</button>
    </header>
    <div class="modal__body">
      <p class="muted">Cuando finalices, la reserva se marcará como Finalizado.</p>
      <div style="display:flex; gap:.6rem; justify-content:center; margin-top:1rem;">
        <button type="button" class="btn btn-success" data-service-finish>Finalizar</button>
      </div>
    </div>
  </div>
</div>
<?php
return trim(ob_get_clean());
?>
