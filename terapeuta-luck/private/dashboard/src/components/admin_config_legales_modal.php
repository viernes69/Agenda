<?php
ob_start();
?>
<div class="modal" role="dialog" aria-modal="true" aria-labelledby="admin-config-legales-title" data-admin-modal="config-legales" hidden>
  <div class="modal__backdrop" data-admin-config-legales-close></div>
  <div class="modal__dialog modal__dialog--lg">
    <header class="modal__header">
      <div class="modal__header-text">
        <p class="modal__eyebrow">Documentos legales</p>
        <h2 id="admin-config-legales-title">Términos, Privacidad y Reembolsos</h2>
      </div>
      <button type="button" class="modal__close" data-admin-config-legales-close aria-label="Cerrar">&times;</button>
    </header>
    <form class="modal__body admin-form" data-admin-config-legales-form autocomplete="off" novalidate>
      <section class="admin-form__group">
        <h3 class="admin-form__title">Términos y condiciones</h3>
        <label class="admin-form__field" for="admin-config-legales-terminos">
          <span class="admin-form__label">Contenido</span>
          <textarea id="admin-config-legales-terminos" rows="8" data-admin-config-legales-field="legales.terminos" placeholder="Describe tus términos y condiciones" required></textarea>
        </label>
      </section>

      <section class="admin-form__group">
        <h3 class="admin-form__title">Política de privacidad</h3>
        <label class="admin-form__field" for="admin-config-legales-privacidad">
          <span class="admin-form__label">Contenido</span>
          <textarea id="admin-config-legales-privacidad" rows="8" data-admin-config-legales-field="legales.privacidad" placeholder="Describe tu política de privacidad" required></textarea>
        </label>
      </section>

      <section class="admin-form__group">
        <h3 class="admin-form__title">Política de reembolsos</h3>
        <label class="admin-form__field" for="admin-config-legales-reembolsos">
          <span class="admin-form__label">Contenido</span>
          <textarea id="admin-config-legales-reembolsos" rows="8" data-admin-config-legales-field="legales.reembolsos" placeholder="Describe la política de reembolsos" required></textarea>
        </label>
      </section>

      <p class="admin-form__hint">Estos textos se utilizarán en la sección pública del sitio. Podés adaptarlos a las regulaciones de tu país.</p>
      <p class="admin-form__error" data-admin-config-legales-error hidden></p>

      <footer class="modal__footer">
        <button type="submit" class="btn btn-success" data-admin-config-legales-submit>Guardar cambios</button>
      </footer>
    </form>
  </div>
</div>
<?php
return trim(ob_get_clean());
?>
