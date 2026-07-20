<?php
ob_start();
?>
<div class="modal" role="dialog" aria-modal="true" aria-labelledby="staff-login-modal-title" data-modal="staff_login">
  <div class="modal__backdrop" data-modal-close></div>
  <div class="modal__dialog">
    <header class="modal__header">
      <div class="modal__header-text">
        <p class="modal__eyebrow">Acceso de Funcionarios</p>
        <h2 id="staff-login-modal-title">Ingreso para personal</h2>
      </div>
      <button type="button" class="modal__close" data-modal-close aria-label="Cerrar">&times;</button>
    </header>
    <div class="modal__body">
      <p class="login-modal__message" data-staff-login-message hidden></p>
      <form class="login-form" data-staff-login-form>
        <label>
          Cédula
          <input type="text" name="cedula" required autocomplete="username">
        </label>
        <label>
          Contraseña
          <input type="password" name="password" required autocomplete="current-password">
        </label>
        <div style="display:flex; gap:.5rem; justify-content:flex-end; margin-top:.6rem;">
          <button type="submit" class="btn btn-accent">Ingresar</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php
return trim(ob_get_clean());
?>
