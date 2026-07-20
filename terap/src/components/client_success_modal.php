<?php
ob_start();
?>
<div class="modal" role="dialog" aria-modal="true" aria-labelledby="client-success-title" data-modal="client_success">
  <div class="modal__backdrop" data-modal-close></div>
  <div class="modal__dialog modal__dialog--sm client-success">
    <header class="modal__header">
      <div class="modal__header-text">
        <p class="modal__eyebrow">Cliente</p>
        <h2 id="client-success-title">
          <span class="confirm-status is-success">
            <span class="confirm-status__icon" aria-hidden="true">&#10003;</span>
            <span>Registro completado</span>
          </span>
        </h2>
      </div>
      <button type="button" class="modal__close" data-modal-close aria-label="Cerrar">&times;</button>
    </header>
    <div class="modal__body">
      <p class="client-success__message">¡Cliente registrado correctamente! Ahora puedes iniciar sesión para continuar.</p>
    </div>
    <footer class="modal__footer client-success__footer">
      <button type="button" class="btn btn-accent" data-client-success-login>Iniciar Sesión</button>
    </footer>
  </div>
</div>
<style>
  .client-success__message {
    margin: 0;
    color: rgba(226, 232, 240, 0.9);
    font-size: 0.95rem;
    text-align: center;
  }
  .client-success__footer {
    display: flex;
    justify-content: center;
    padding-top: 0;
  }
</style>
<?php
return trim(ob_get_clean());
