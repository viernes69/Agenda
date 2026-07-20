<?php
ob_start();
?>
<div class="modal" role="dialog" aria-modal="true" aria-labelledby="admin-config-moneda-title" data-admin-modal="config-moneda" hidden>
  <div class="modal__backdrop" data-admin-config-moneda-close></div>
  <div class="modal__dialog modal__dialog--sm">
    <header class="modal__header">
      <div class="modal__header-text">
        <p class="modal__eyebrow">Configuraci&oacute;n</p>
        <h2 id="admin-config-moneda-title">Config. de Moneda</h2>
      </div>
      <button type="button" class="modal__close" data-admin-config-moneda-close aria-label="Cerrar">&times;</button>
    </header>
    <form class="modal__body admin-form" data-admin-config-moneda-form autocomplete="off" novalidate>
      <section class="admin-form__group">
        <h3 class="admin-form__title">Moneda</h3>
        <div class="admin-form__grid">
          <label class="admin-form__field" for="admin-config-moneda-codigo">
            <span class="admin-form__label">C&oacute;digo</span>
            <select id="admin-config-moneda-codigo" data-admin-config-moneda-field="moneda.codigo" required>
              <option value="" disabled>Selecciona moneda</option>
              <option value="UYU">UYU - Peso Uruguayo</option>
              <option value="USD">USD - Dólar estadounidense</option>
              <option value="EUR">EUR - Euro</option>
              <option value="ARS">ARS - Peso Argentino</option>
              <option value="BRL">BRL - Real Brasileño</option>
              <option value="GBP">GBP - Libra Esterlina</option>
            </select>
          </label>
          <label class="admin-form__field" for="admin-config-moneda-simbolo">
            <span class="admin-form__label">S&iacute;mbolo</span>
            <input id="admin-config-moneda-simbolo" type="text" maxlength="4" data-admin-config-moneda-field="moneda.simbolo" readonly placeholder="Ej: $">
          </label>
        </div>
        <div class="admin-form__grid">
          <label class="admin-form__field" for="admin-config-moneda-sep-dec">
            <span class="admin-form__label">Separador decimal</span>
            <input id="admin-config-moneda-sep-dec" type="text" maxlength="2" data-admin-config-moneda-field="moneda.separador_decimal" readonly placeholder="," >
          </label>
          <label class="admin-form__field" for="admin-config-moneda-sep-mil">
            <span class="admin-form__label">Separador miles</span>
            <input id="admin-config-moneda-sep-mil" type="text" maxlength="2" data-admin-config-moneda-field="moneda.separador_miles" readonly placeholder=".">
          </label>
        </div>
      </section>

      <section class="admin-form__group">
        <h3 class="admin-form__title">Localizaci&oacute;n & formatos</h3>
        <div class="admin-form__grid">
          <label class="admin-form__field" for="admin-config-moneda-locale">
            <span class="admin-form__label">Locale</span>
            <input id="admin-config-moneda-locale" type="text" maxlength="20" data-admin-config-moneda-field="locale" readonly placeholder="Ej: es_UY">
          </label>
          <label class="admin-form__field" for="admin-config-moneda-formato-fecha">
            <span class="admin-form__label">Formato fecha</span>
            <select id="admin-config-moneda-formato-fecha" data-admin-config-moneda-field="formatos.fecha" disabled>
              <option value="Y-m-d">Y-m-d (2025-10-14)</option>
              <option value="d/m/Y">d/m/Y (14/10/2025)</option>
              <option value="d-m-Y">d-m-Y (14-10-2025)</option>
              <option value="m/d/Y">m/d/Y (10/14/2025)</option>
              <option value="d M Y">d M Y (14 Oct 2025)</option>
              <option value=" jS F Y">jS F Y (14th October 2025)</option>
            </select>
          </label>
        </div>
        <div class="admin-form__grid">
          <label class="admin-form__field" for="admin-config-moneda-formato-hora">
            <span class="admin-form__label">Formato hora</span>
            <select id="admin-config-moneda-formato-hora" data-admin-config-moneda-field="formatos.hora" disabled>
              <option value="H:i">H:i (14:30)</option>
              <option value="g:i A">g:i A (2:30 PM)</option>
              <option value="h:i A">h:i A (02:30 PM)</option>
              <option value="H:i:s">H:i:s (14:30:00)</option>
            </select>
          </label>
        </div>
      </section>

      <p class="admin-form__error" data-admin-config-moneda-error hidden></p>

      <footer class="modal__footer">
        <button type="submit" class="btn btn-success" data-admin-config-moneda-submit>Guardar cambios</button>
      </footer>
    </form>
  </div>
</div>
<?php
return trim(ob_get_clean());
?>
