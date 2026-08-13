<?php
ob_start();
?>
<div class="modal" role="dialog" aria-modal="true" aria-labelledby="admin-reserva-title" data-admin-modal="reserva" hidden>
  <div class="modal__backdrop" data-admin-reserva-close></div>
  <div class="modal__dialog">
    <header class="modal__header">
      <div class="modal__header-text">
        <p class="modal__eyebrow">Reserva</p>
        <h2 id="admin-reserva-title">Detalle de la reserva</h2>
      </div>
      <button type="button" class="modal__close" data-admin-reserva-close aria-label="Cerrar">&times;</button>
    </header>
      <div class="modal__body">
        <div class="resumen">
        <p><strong>Cliente:</strong> <span data-admin-res-cliente>-</span></p>
        <p><strong>Profesional:</strong> <span data-admin-res-barbero>-</span></p>
        <p><strong>Servicio:</strong> <span data-admin-res-servicio>-</span></p>
        <p><strong>Precio:</strong> <span data-admin-res-precio>-</span></p>
        <p><strong>Fecha:</strong> <span data-admin-res-fecha>-</span></p>
        <p><strong>Hora:</strong> <span data-admin-res-hora>-</span></p>
          <p><strong>Status:</strong> <span class="status-pill" data-admin-res-status>-</span><button type="button" class="pill-action" data-admin-res-retomar hidden>Retomar</button></p>
        </div>
        <div class="admin-reserva-reprogram" data-admin-res-reprogram-wrap>
          <p class="admin-reserva-reprogram__label">Reprogramar</p>
          <div class="admin-reserva-reprogram__fields">
            <label>
              <span>Nueva fecha</span>
              <input type="date" data-admin-res-edit-fecha>
            </label>
            <label>
              <span>Nueva hora</span>
              <input type="time" data-admin-res-edit-hora step="60">
            </label>
            <button type="button" class="btn btn-secondary" data-admin-res-guardar-fecha>Guardar cambio</button>
          </div>
        </div>
      </div>
    </div>
  </div>
<style>
  [data-admin-modal="reserva"] .modal__dialog {
    max-height: none !important;
  }
  [data-admin-modal="reserva"] .modal__body {
    overflow-y: visible !important;
    max-height: none !important;
  }
  .admin-reserva-reprogram {
    margin-top: 1rem;
    padding-top: .75rem;
    border-top: 1px solid rgba(0,0,0,.08);
  }
  .admin-reserva-reprogram__label {
    margin: 0 0 .5rem;
    font-size: .85rem;
    font-weight: 600;
  }
  .admin-reserva-reprogram__fields {
    display: flex;
    flex-wrap: wrap;
    gap: .5rem;
    align-items: flex-end;
  }
  .admin-reserva-reprogram__fields label {
    display: flex;
    flex-direction: column;
    gap: .25rem;
    font-size: .8rem;
  }
  .admin-reserva-reprogram__fields input {
    padding: .35rem .5rem;
    border: 1px solid rgba(0,0,0,.15);
    border-radius: 6px;
    min-width: 9rem;
  }
</style>
<?php
return trim(ob_get_clean());
?>
