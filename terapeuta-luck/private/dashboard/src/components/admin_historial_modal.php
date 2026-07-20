<?php
ob_start();
?>
<div class="modal" role="dialog" aria-modal="true" aria-labelledby="admin-historial-title" data-admin-modal="historial" hidden>
  <div class="modal__backdrop" data-admin-historial-close></div>
  <div class="modal__dialog">
    <header class="modal__header">
      <div class="modal__header-text">
        <p class="modal__eyebrow">Historial</p>
        <h2 id="admin-historial-title">Historial de reservas</h2>
      </div>
      <button type="button" class="modal__close" data-admin-historial-close aria-label="Cerrar">&times;</button>
    </header>
    <div class="modal__body">
      <div data-admin-historial-list style="display:grid; gap:.5rem;">
        <p class="muted">Cargando historial...</p>
      </div>
    </div>
  </div>
</div>
<?php
return trim(ob_get_clean());
?>

