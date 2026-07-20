<?php
ob_start();
?>
<div class="modal" role="dialog" aria-modal="true" aria-labelledby="barber-login-modal-title" data-modal="barber_login">
  <div class="modal__backdrop" data-modal-close></div>
  <div class="modal__dialog">
    <header class="modal__header">
      <div class="modal__header-text">
        <p class="modal__eyebrow">Acceso de personal</p>
        <h2 id="barber-login-modal-title">Introduce la contrasena del profesional</h2>
      </div>
      <button type="button" class="modal__close" data-modal-close aria-label="Cerrar">&times;</button>
    </header>
    <div class="modal__body">
      <p class="muted" style="margin:0 0 .6rem;">Profesional seleccionado: <strong data-barber-login-name></strong></p>
      <p class="login-modal__message" data-barber-login-message hidden></p>
      <form class="login-form" data-barber-login-form>
        <label>
          Contrasena
          <input type="password" name="password" required autocomplete="current-password">
        </label>
        <button type="submit" class="login-form__submit">Ingresar</button>
      </form>
    </div>
  </div>
</div>
<?php
return trim(ob_get_clean());
?>

