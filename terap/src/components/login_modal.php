<?php
ob_start();
?>
<div class="modal" role="dialog" aria-modal="true" aria-labelledby="login-modal-title" data-modal="login">
  <div class="modal__backdrop" data-modal-close></div>
  <div class="modal__dialog">
    <header class="modal__header">
      <div class="modal__header-text">
        <p class="modal__eyebrow">Clientes registrados</p>
        <h2 id="login-modal-title">Iniciar sesion</h2>
      </div>
      <button type="button" class="modal__close" data-modal-close aria-label="Cerrar">&times;</button>
    </header>
    <div class="modal__body">
      <p class="login-modal__message" data-login-message hidden></p>
      <form class="login-form" data-login-form>
        <label>
          Cedula
          <input type="text" name="cedula" required>
        </label>
        <button type="submit" class="login-form__submit">Iniciar sesion</button>
        <button type="button" class="client-form__link" data-login-register>Necesito Registrarme</button>
        <button type="button" class="client-form__link" data-login-staff style="display:block; margin-top:.6rem;">Login para Funcionarios</button>
      </form>
    </div>
  </div>
</div>
<?php
return trim(ob_get_clean());
?>
