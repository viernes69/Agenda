<?php
ob_start();
?>
<div class="modal" role="dialog" aria-modal="true" aria-labelledby="admin-client-form-title" data-admin-modal="cliente-form" hidden>
  <div class="modal__backdrop" data-admin-client-form-close></div>
  <div class="modal__dialog modal__dialog--md">
    <header class="modal__header">
      <div class="modal__header-text">
        <p class="modal__eyebrow">Clientes</p>
        <h2 id="admin-client-form-title" data-admin-client-form-title>Registrar cliente</h2>
      </div>
      <button type="button" class="modal__close" data-admin-client-form-close aria-label="Cerrar">&times;</button>
    </header>
    <form class="modal__body admin-form" data-admin-client-form autocomplete="off" novalidate>
      <input type="hidden" name="ID_Cliente" value="">
      <div class="admin-form__grid">
        <label class="admin-form__field" for="admin-client-name">
          <span class="admin-form__label">Nombre completo</span>
          <input id="admin-client-name" name="Nombre" type="text" required maxlength="120" placeholder="Ej: Juan Pérez">
        </label>
        <label class="admin-form__field" for="admin-client-email">
          <span class="admin-form__label">Email</span>
          <input id="admin-client-email" name="Email" type="email" maxlength="160" placeholder="Ej: cliente@email.com">
          <span class="admin-form__hint">Opcional, se usará para enviar notificaciones.</span>
        </label>
      </div>
      <div class="admin-form__grid">
        <label class="admin-form__field" for="admin-client-phone">
          <span class="admin-form__label">Teléfono</span>
          <input id="admin-client-phone" name="Telefono" type="tel" maxlength="40" placeholder="Ej: +598 99 000 000">
        </label>
        <label class="admin-form__field" for="admin-client-document">
          <span class="admin-form__label">Documento</span>
          <input id="admin-client-document" name="Cedula" type="text" maxlength="40" placeholder="Ej: 4.123.456-7">
        </label>
      </div>
      <p class="admin-form__error" data-admin-client-form-error hidden></p>
      <footer class="modal__footer">
        <button type="button" class="btn btn-outline" data-admin-client-form-close>Cancelar</button>
        <button type="submit" class="btn btn-success" data-admin-client-submit>Guardar</button>
      </footer>
    </form>
  </div>
</div>
<?php
return trim(ob_get_clean());
?>
