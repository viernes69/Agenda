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
        <p><strong>Tipo de pago:</strong> <span data-admin-res-payment>-</span></p>
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
            <button type="button" class="btn btn-secondary" data-admin-res-guardar-fecha disabled>Guardar cambios</button>
            <p class="admin-reserva-reprogram__feedback" data-admin-res-reprogram-feedback role="status" aria-live="polite" hidden></p>
          </div>
        </div>
        <div class="admin-reserva-actions">
          <button type="button" class="btn btn-secondary" data-admin-res-atender>Atendiendo</button>
          <button type="button" class="btn btn-success" data-admin-res-finalizar>Finalizado</button>
          <button type="button" class="btn btn-danger" data-admin-res-rechazar>Cancelar</button>
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
  .admin-reserva-reprogram__fields [data-admin-res-guardar-fecha] {
    min-width: 10rem;
  }
  .admin-reserva-reprogram__fields [data-admin-res-guardar-fecha].is-ready {
    background: linear-gradient(120deg, #7c3aed, #6366f1);
    border-color: transparent;
    color: #fff;
    box-shadow: 0 14px 28px rgba(124, 58, 237, .24);
  }
  .admin-reserva-reprogram__fields [data-admin-res-guardar-fecha]:disabled {
    background: rgba(148, 163, 184, .18);
    border-color: rgba(148, 163, 184, .3);
    color: var(--muted, #64748b);
  }
  .admin-reserva-reprogram__feedback {
    flex-basis: 100%;
    margin: .2rem 0 0;
    padding: .55rem .7rem;
    border-radius: .7rem;
    font-size: .84rem;
    font-weight: 700;
  }
  .admin-reserva-reprogram__feedback[hidden] {
    display: none !important;
  }
  .admin-reserva-reprogram__feedback.is-success {
    background: rgba(34, 197, 94, .14);
    border: 1px solid rgba(34, 197, 94, .34);
    color: #bbf7d0;
  }
  :root[data-admin-theme="light"] .admin-reserva-reprogram__feedback.is-success {
    color: #14532d;
  }
  .admin-reserva-reprogram__feedback.is-error {
    background: rgba(239, 68, 68, .14);
    border: 1px solid rgba(239, 68, 68, .34);
    color: #fecaca;
  }
  :root[data-admin-theme="light"] .admin-reserva-reprogram__feedback.is-error {
    color: #7f1d1d;
  }
  .admin-reserva-reprogram__feedback.is-info {
    background: rgba(99, 102, 241, .12);
    border: 1px solid rgba(99, 102, 241, .28);
    color: var(--text, #e5e7eb);
  }
  .admin-reserva-actions {
    display: flex;
    flex-wrap: wrap;
    justify-content: flex-end;
    gap: .5rem;
    margin-top: 1rem;
  }
</style>
<?php
return trim(ob_get_clean());
?>
