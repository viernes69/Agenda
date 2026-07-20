<?php
ob_start();
?>
<div class="modal" role="dialog" aria-modal="true" aria-labelledby="admin-config-theme-title" data-admin-modal="config-theme" hidden>
  <div class="modal__backdrop" data-admin-config-theme-close></div>
  <div class="modal__dialog modal__dialog--sm">
    <header class="modal__header">
      <div class="modal__header-text">
        <p class="modal__eyebrow">Personalizaci&oacute;n</p>
        <h2 id="admin-config-theme-title">Elegir tema de colores</h2>
        <p class="modal__subtitle">Aplica una paleta clara u oscura para el sitio p&uacute;blico y el panel privado.</p>
      </div>
      <button type="button" class="modal__close" data-admin-config-theme-close aria-label="Cerrar">&times;</button>
    </header>
    <form class="modal__body admin-form" data-admin-config-theme-form autocomplete="off">
      <section class="admin-form__group">
        <span class="admin-form__label admin-form__label--block">Sitio p&uacute;blico</span>
        <p class="admin-form__hint">Afecta los estilos visibles para tus clientes.</p>
        <label class="admin-form__field">
          <span class="admin-form__label">Selecci&oacute;n</span>
          <select data-admin-config-theme-public>
            <option value="oscuro">Modo oscuro</option>
            <option value="claro">Modo claro</option>
          </select>
        </label>
      </section>

      <section class="admin-form__group">
        <span class="admin-form__label admin-form__label--block">Panel privado</span>
        <p class="admin-form__hint">Define el tema utilizado en el dashboard de administraci&oacute;n.</p>
        <label class="admin-form__field">
          <span class="admin-form__label">Selecci&oacute;n</span>
          <select data-admin-config-theme-private>
            <option value="oscuro">Modo oscuro</option>
            <option value="claro">Modo claro</option>
          </select>
        </label>
      </section>

      <p class="admin-form__error" data-admin-config-theme-error hidden></p>

      <footer class="modal__footer">
        <button type="button" class="btn btn-outline" data-admin-config-theme-close>Cancelar</button>
        <button type="submit" class="btn btn-success" data-admin-config-theme-submit>Aplicar tema</button>
      </footer>
    </form>
  </div>
</div>
<?php
return trim(ob_get_clean());
?>
