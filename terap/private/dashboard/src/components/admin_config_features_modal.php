<?php
ob_start();
?>
<div class="modal" role="dialog" aria-modal="true" aria-labelledby="admin-config-features-title" data-admin-modal="config-features" hidden>
  <div class="modal__backdrop" data-admin-config-features-close></div>
  <div class="modal__dialog modal__dialog--sm">
    <header class="modal__header">
      <div class="modal__header-text">
        <p class="modal__eyebrow">Funciones del panel</p>
        <h2 id="admin-config-features-title">Activar / desactivar módulos</h2>
      </div>
      <button type="button" class="modal__close" data-admin-config-features-close aria-label="Cerrar">&times;</button>
    </header>
    <form class="modal__body admin-form" data-admin-config-features-form autocomplete="off" novalidate>
      <section class="admin-form__group">
        <label class="admin-checkbox">
          <input type="checkbox" data-admin-config-features-toggle="productos">
          <span>Productos</span>
        </label>
        <p class="admin-form__hint">Habilita el catálogo de productos y la sección de ventas.</p>
      </section>

      <section class="admin-form__group">
        <label class="admin-checkbox">
          <input type="checkbox" data-admin-config-features-toggle="servicios">
          <span>Servicios</span>
        </label>
        <p class="admin-form__hint">Controla la visibilidad de la gestión de servicios y reservas asociadas.</p>
      </section>

      <section class="admin-form__group">
        <label class="admin-checkbox">
          <input type="checkbox" data-admin-config-features-toggle="barberos">
          <span>Profesionales</span>
        </label>
        <p class="admin-form__hint">Activa la administración del equipo de profesionales.</p>
      </section>

      <p class="admin-form__error" data-admin-config-features-error hidden></p>

      <footer class="modal__footer">
        <button type="submit" class="btn btn-success" data-admin-config-features-submit>Guardar cambios</button>
      </footer>
    </form>
  </div>
</div>
<?php
return trim(ob_get_clean());
?>
