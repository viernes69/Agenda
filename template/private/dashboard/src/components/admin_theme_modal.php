<?php
ob_start();
?>
<div class="modal" role="dialog" aria-modal="true" aria-labelledby="admin-theme-modal-title" data-admin-modal="config-temas" hidden>
  <div class="modal__backdrop" data-admin-theme-close></div>
  <div class="modal__dialog modal__dialog--sm">
    <header class="modal__header">
      <div class="modal__header-text">
        <p class="modal__eyebrow">Apariencia</p>
        <h2 id="admin-theme-modal-title">Tema visual</h2>
      </div>
      <button type="button" class="modal__close" data-admin-theme-close aria-label="Cerrar">&times;</button>
    </header>
    <div class="modal__body">
      <p class="admin-form__hint">Elegí cómo querés ver el panel de administración.</p>

      <div style="display: flex; gap: 1rem; margin-top: 1.5rem;">
        <button type="button" class="btn btn-outline" data-admin-theme-set="light" style="flex: 1;">
          <i class="bx bx-sun" aria-hidden="true" style="margin-right: 0.35rem;"></i> Modo claro
        </button>
        <button type="button" class="btn btn-outline" data-admin-theme-set="dark" style="flex: 1;">
          <i class="bx bx-moon" aria-hidden="true" style="margin-right: 0.35rem;"></i> Modo oscuro
        </button>
      </div>
    </div>
  </div>
</div>
<?php
return trim(ob_get_clean());
?>
