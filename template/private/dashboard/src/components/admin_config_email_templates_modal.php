<?php
ob_start();
?>
<div class="modal" role="dialog" aria-modal="true" aria-labelledby="admin-config-email-templates-title" data-admin-modal="config-email-templates" hidden>
  <div class="modal__backdrop" data-admin-config-email-templates-close></div>
  <div class="modal__dialog modal__dialog--lg">
    <header class="modal__header">
      <div class="modal__header-text">
        <p class="modal__eyebrow">Comunicaciones</p>
        <h2 id="admin-config-email-templates-title">Plantillas de email</h2>
      </div>
      <button type="button" class="modal__close" data-admin-config-email-templates-close aria-label="Cerrar">&times;</button>
    </header>
    <form class="modal__body admin-form" data-admin-config-email-templates-form autocomplete="off" novalidate>
      <p class="admin-form__hint">Variables disponibles: <code>{cliente}</code>, <code>{telefono}</code>, <code>{servicio}</code>, <code>{negocio}</code>, <code>{fecha}</code>, <code>{hora}</code></p>

      <section class="admin-form__group">
        <h3 class="admin-form__title">Confirmación al cliente</h3>
        <label class="admin-form__field" for="admin-config-email-client-subject">
          <span class="admin-form__label">Asunto</span>
          <input id="admin-config-email-client-subject" type="text" maxlength="120" data-admin-config-email-field="client_subject" required>
        </label>
        <label class="admin-form__field" for="admin-config-email-client-body">
          <span class="admin-form__label">Mensaje</span>
          <textarea id="admin-config-email-client-body" rows="5" data-admin-config-email-field="client_body" required></textarea>
        </label>
      </section>

      <section class="admin-form__group">
        <h3 class="admin-form__title">Aviso al negocio (admin)</h3>
        <label class="admin-form__field" for="admin-config-email-owner-subject">
          <span class="admin-form__label">Asunto</span>
          <input id="admin-config-email-owner-subject" type="text" maxlength="120" data-admin-config-email-field="owner_subject" required>
        </label>
        <label class="admin-form__field" for="admin-config-email-owner-body">
          <span class="admin-form__label">Mensaje</span>
          <textarea id="admin-config-email-owner-body" rows="5" data-admin-config-email-field="owner_body" required></textarea>
        </label>
      </section>

      <p class="admin-form__error" data-admin-config-email-templates-error hidden></p>
      <footer class="modal__footer">
        <button type="submit" class="btn btn-success" data-admin-config-email-templates-submit>Guardar plantillas</button>
      </footer>
    </form>
  </div>
</div>
<?php
return trim(ob_get_clean());
