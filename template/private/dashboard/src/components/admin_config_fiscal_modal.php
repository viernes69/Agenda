<?php
ob_start();
?>
<div class="modal" role="dialog" aria-modal="true" aria-labelledby="admin-config-fiscal-title" data-admin-modal="config-fiscal" hidden>
  <div class="modal__backdrop" data-admin-config-fiscal-close></div>
  <div class="modal__dialog modal__dialog--sm">
    <header class="modal__header">
      <div class="modal__header-text">
        <p class="modal__eyebrow">Configuraci&oacute;n</p>
        <h2 id="admin-config-fiscal-title">Config. Fiscal</h2>
      </div>
      <button type="button" class="modal__close" data-admin-config-fiscal-close aria-label="Cerrar">&times;</button>
    </header>
    <form class="modal__body admin-form" data-admin-config-fiscal-form autocomplete="off" novalidate>
      <section class="admin-form__group">
        <h3 class="admin-form__title">Par&aacute;metros fiscales</h3>
        <div class="admin-form__grid">
          <label class="admin-form__field" for="admin-config-fiscal-iva">
            <span class="admin-form__label">IVA (%)</span>
            <input id="admin-config-fiscal-iva" type="number" min="0" max="100" step="0.01" placeholder="22" data-admin-config-fiscal-field="fiscal.iva_porcentaje" required>
          </label>
          <label class="admin-form__field" for="admin-config-fiscal-comprobante">
            <span class="admin-form__label">Comprobante</span>
            <select id="admin-config-fiscal-comprobante" data-admin-config-fiscal-field="fiscal.comprobante" required>
              <option value="" disabled>Selecciona comprobante</option>
              <option value="ticket">Ticket</option>
              <option value="factura">Factura</option>
              <option value="boleta">Boleta</option>
              <option value="recibo">Recibo</option>
              <option value="otro">Otro</option>
            </select>
          </label>
        </div>
        <label class="admin-form__field" for="admin-config-fiscal-enabled">
          <span class="admin-form__label">Estado</span>
          <select id="admin-config-fiscal-enabled" data-admin-config-fiscal-field="fiscal.enabled" required>
            <option value="1">Activado</option>
            <option value="0">Desactivado</option>
          </select>
        </label>
      </section>

      <p class="admin-form__hint">Los cambios impactar&aacute;n en c&aacute;lculos de impuestos y tipo de comprobante emitido desde el sistema.</p>
      <p class="admin-form__error" data-admin-config-fiscal-error hidden></p>

      <footer class="modal__footer">
        <button type="submit" class="btn btn-success" data-admin-config-fiscal-submit>Guardar cambios</button>
      </footer>
    </form>
  </div>
</div>
<?php
return trim(ob_get_clean());
?>
