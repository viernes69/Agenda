<?php
ob_start();
?>
<div class="modal" role="dialog" aria-modal="true" aria-labelledby="admin-config-reservas-title" data-admin-modal="config-reservas" hidden>
  <div class="modal__backdrop" data-admin-config-reservas-close></div>
  <div class="modal__dialog modal__dialog--lg">
    <header class="modal__header">
      <div class="modal__header-text">
        <p class="modal__eyebrow">Configuraci&oacute;n</p>
        <h2 id="admin-config-reservas-title">Config. de Reservas</h2>
      </div>
      <button type="button" class="modal__close" data-admin-config-reservas-close aria-label="Cerrar">&times;</button>
    </header>
    <form class="modal__body admin-form" data-admin-config-reservas-form autocomplete="off" novalidate>
      <section class="admin-form__group">
        <h3 class="admin-form__title">Par&aacute;metros de reserva</h3>
        <div class="admin-form__grid">
          <label class="admin-form__field" for="admin-config-reservas-anticipacion">
            <span class="admin-form__label">Anticipaci&oacute;n (minutos)</span>
            <input id="admin-config-reservas-anticipacion"
                   type="number"
                   min="0"
                   step="1"
                   inputmode="numeric"
                   placeholder="Ej: 60"
                   required
                   data-admin-config-reservas-field="anticipacion_minutos">
          </label>
          <label class="admin-form__field" for="admin-config-reservas-max-dias">
            <span class="admin-form__label">D&iacute;as hacia adelante</span>
            <input id="admin-config-reservas-max-dias"
                   type="number"
                   min="0"
                   step="1"
                   inputmode="numeric"
                   placeholder="Ej: 30"
                   required
                   data-admin-config-reservas-field="max_dias_adelante">
          </label>
        </div>
        <div class="admin-form__grid">
          <label class="admin-form__field" for="admin-config-reservas-cancelacion">
            <span class="admin-form__label">Cancelaci&oacute;n (horas)</span>
            <input id="admin-config-reservas-cancelacion"
                   type="number"
                   min="0"
                   step="1"
                   inputmode="numeric"
                   placeholder="Ej: 1"
                   required
                   data-admin-config-reservas-field="politica_cancelacion_horas">
          </label>
          <label class="admin-form__field" for="admin-config-reservas-max-por-dia">
            <span class="admin-form__label">Max. reservas por d&iacute;a / cliente</span>
            <input id="admin-config-reservas-max-por-dia"
                   type="number"
                   min="1"
                   step="1"
                   inputmode="numeric"
                   placeholder="Ej: 1"
                   required
                   data-admin-config-reservas-field="max_reservas_por_dia_por_cliente">
          </label>
        </div>
        <label class="admin-form__field" for="admin-config-reservas-login">
          <span class="admin-form__label">Requiere login</span>
          <select id="admin-config-reservas-login"
                  data-admin-config-reservas-field="requiere_login"
                  required>
            <option value="0">No (reserva como invitado)</option>
            <option value="1">S&iacute; (obligatorio)</option>
          </select>
        </label>
      </section>
      <section class="admin-form__group">
        <h3 class="admin-form__title">Pagos online</h3>
        <div class="admin-form__switch-grid admin-form__switch-grid--compact">
          <label class="admin-form__switch">
            <input type="checkbox" data-admin-config-reservas-toggle="mercado_pago_enabled">
            <span class="admin-form__switch-control" aria-hidden="true"></span>
            <span>
              <strong>Mercado Pago en reservas</strong>
              <small>Permite que el cliente pague el servicio desde la web si tu cuenta y plan lo habilitan.</small>
            </span>
          </label>
          <label class="admin-form__switch">
            <input type="checkbox" data-admin-config-reservas-toggle="mercado_pago_required">
            <span class="admin-form__switch-control" aria-hidden="true"></span>
            <span>
              <strong>Pago obligatorio</strong>
              <small>Para servicios con precio, la reserva se confirma cuando Mercado Pago aprueba el cobro.</small>
            </span>
          </label>
        </div>
      </section>
      <p class="admin-form__error" data-admin-config-reservas-error hidden></p>
      <footer class="modal__footer">
        <button type="submit" class="btn btn-success" data-admin-config-reservas-submit>Guardar configuraci&oacute;n</button>
      </footer>
    </form>
  </div>
</div>
<?php
return trim(ob_get_clean());
?>
