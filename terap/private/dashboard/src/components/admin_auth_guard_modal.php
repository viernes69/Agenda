<?php
ob_start();
?>
<div class="modal" role="dialog" aria-modal="true" aria-labelledby="admin-auth-guard-title" data-admin-modal="auth-guard" hidden>
  <div class="modal__backdrop" data-admin-auth-guard-close></div>
  <div class="modal__dialog modal__dialog--sm">
    <header class="modal__header">
      <div class="modal__header-text">
        <p class="modal__eyebrow">Acceso restringido</p>
        <h2 id="admin-auth-guard-title">Autorizaci&oacute;n requerida</h2>
      </div>
      <button type="button" class="modal__close" data-admin-auth-guard-close aria-label="Cerrar">&times;</button>
    </header>
    <div class="modal__body admin-form" data-admin-auth-guard-body>
      <p class="admin-form__hint" data-admin-auth-guard-message>
        Solo puedes modificar esta secci&oacute;n con autorizaci&oacute;n de los desarrolladores de esta app. Para configurar comunicate con ellos.
      </p>
      <button type="button" class="btn btn-success" data-admin-auth-guard-contact>
        Comunicarme
      </button>
      <hr class="admin-form__divider">
      <label class="admin-form__field" for="admin-auth-guard-pin">
        <span class="admin-form__label">PIN de autorizaci&oacute;n elevada</span>
        <input id="admin-auth-guard-pin" type="password" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="Ingresa PIN" data-admin-auth-guard-pin required>
      </label>
      <p class="admin-form__error" data-admin-auth-guard-error hidden></p>
    </div>
    <footer class="modal__footer">
      <button type="button" class="btn btn-success" data-admin-auth-guard-confirm>Desbloquear</button>
    </footer>
  </div>
</div>
<?php
return trim(ob_get_clean());
?>
