<?php
ob_start();
?>
<div class="modal" role="dialog" aria-modal="true" aria-labelledby="admin-config-mercadopago-title" data-admin-modal="config-mercadopago" hidden>
  <div class="modal__backdrop" data-admin-config-mercadopago-close></div>
  <div class="modal__dialog modal__dialog--lg">
    <header class="modal__header">
      <div class="modal__header-text">
        <p class="modal__eyebrow">Integraciones</p>
        <h2 id="admin-config-mercadopago-title">Mercado Pago</h2>
      </div>
      <button type="button" class="modal__close" data-admin-config-mercadopago-close aria-label="Cerrar">&times;</button>
    </header>
    <form class="modal__body admin-form" data-admin-config-mercadopago-form autocomplete="off" novalidate>
      <section class="admin-form__group">
        <h3 class="admin-form__title">Estado y modo</h3>
        <div class="admin-form__grid">
          <label class="admin-form__field" for="admin-config-mercadopago-enabled">
            <span class="admin-form__label">Integraci&oacute;n</span>
            <select id="admin-config-mercadopago-enabled" data-admin-config-mercadopago-field="mercado_pago.enabled" required>
              <option value="1">Activada</option>
              <option value="0">Desactivada</option>
            </select>
          </label>
          <label class="admin-form__field" for="admin-config-mercadopago-mode">
            <span class="admin-form__label">Modo</span>
            <select id="admin-config-mercadopago-mode" data-admin-config-mercadopago-field="mercado_pago.modo" required>
              <option value="test">Test</option>
              <option value="live">Producci&oacute;n</option>
            </select>
          </label>
        </div>
      </section>

      <section class="admin-form__group">
        <h3 class="admin-form__title">Credenciales</h3>
        <div class="admin-form__grid admin-form__grid--2cols">
          <label class="admin-form__field admin-form__field--secret" for="admin-config-mercadopago-public-key">
            <span class="admin-form__label">Public Key</span>
            <div class="admin-form__secret">
              <input id="admin-config-mercadopago-public-key" type="password" placeholder="APP_USR-xxxxxxxx" data-admin-secret-input data-admin-config-mercadopago-field="mercado_pago.public_key" required>
              <button type="button" class="admin-form__secret-toggle" data-admin-secret-toggle aria-label="Mostrar Public Key">Mostrar</button>
            </div>
          </label>
          <label class="admin-form__field admin-form__field--secret" for="admin-config-mercadopago-access-token">
            <span class="admin-form__label">Access Token</span>
            <div class="admin-form__secret">
              <input id="admin-config-mercadopago-access-token" type="password" placeholder="APP_USR-xxxxxxxx" data-admin-secret-input data-admin-config-mercadopago-field="mercado_pago.access_token" required>
              <button type="button" class="admin-form__secret-toggle" data-admin-secret-toggle aria-label="Mostrar Access Token">Mostrar</button>
            </div>
          </label>
          <label class="admin-form__field admin-form__field--secret" for="admin-config-mercadopago-integrator-id">
            <span class="admin-form__label">Integrator ID</span>
            <div class="admin-form__secret">
              <input id="admin-config-mercadopago-integrator-id" type="password" placeholder="Dev_XXXX" data-admin-secret-input data-admin-config-mercadopago-field="mercado_pago.integrator_id">
              <button type="button" class="admin-form__secret-toggle" data-admin-secret-toggle aria-label="Mostrar Integrator ID">Mostrar</button>
            </div>
          </label>
          <label class="admin-form__field" for="admin-config-mercadopago-statement">
            <span class="admin-form__label">Statement descriptor</span>
            <input id="admin-config-mercadopago-statement" type="text" maxlength="22" placeholder="Nombre en resumen bancario" data-admin-config-mercadopago-field="mercado_pago.statement_descriptor">
          </label>
        </div>
      </section>

      <section class="admin-form__group">
        <h3 class="admin-form__title">Ubicaci&oacute;n y moneda</h3>
        <div class="admin-form__grid">
          <label class="admin-form__field" for="admin-config-mercadopago-country">
            <span class="admin-form__label">Pa&iacute;s</span>
            <select id="admin-config-mercadopago-country" data-admin-config-mercadopago-field="mercado_pago.country" required></select>
          </label>
          <label class="admin-form__field" for="admin-config-mercadopago-currency">
            <span class="admin-form__label">Moneda</span>
            <input id="admin-config-mercadopago-currency" type="text" maxlength="5" placeholder="UYU" data-admin-config-mercadopago-field="mercado_pago.currency" required>
          </label>
        </div>
      </section>

      <section class="admin-form__group">
        <h3 class="admin-form__title">URL de callbacks</h3>
        <div class="admin-form__grid admin-form__grid--2cols">
          <label class="admin-form__field" for="admin-config-mercadopago-public-base-url">
            <span class="admin-form__label">URL publica de la tienda</span>
            <input id="admin-config-mercadopago-public-base-url" type="url" placeholder="https://agendarte.oficiosya.net" data-admin-config-mercadopago-field="mercado_pago.public_base_url">
            <small class="admin-form__hint">Necesaria si probas Mercado Pago desde localhost.</small>
          </label>
          <label class="admin-form__field" for="admin-config-mercadopago-success-url">
            <span class="admin-form__label">Success URL</span>
            <input id="admin-config-mercadopago-success-url" type="url" placeholder="https://..." data-admin-config-mercadopago-field="mercado_pago.success_url">
          </label>
          <label class="admin-form__field" for="admin-config-mercadopago-failure-url">
            <span class="admin-form__label">Failure URL</span>
            <input id="admin-config-mercadopago-failure-url" type="url" placeholder="https://..." data-admin-config-mercadopago-field="mercado_pago.failure_url">
          </label>
          <label class="admin-form__field" for="admin-config-mercadopago-pending-url">
            <span class="admin-form__label">Pending URL</span>
            <input id="admin-config-mercadopago-pending-url" type="url" placeholder="https://..." data-admin-config-mercadopago-field="mercado_pago.pending_url">
          </label>
          <label class="admin-form__field" for="admin-config-mercadopago-notification-url">
            <span class="admin-form__label">Notification URL</span>
            <input id="admin-config-mercadopago-notification-url" type="url" placeholder="https://..." data-admin-config-mercadopago-field="mercado_pago.notification_url">
          </label>
        </div>
      </section>

      <section class="admin-form__group">
        <h3 class="admin-form__title">Metodos de pago permitidos</h3>
        <div class="admin-form__grid">
          <label class="admin-form__field" for="admin-config-mercadopago-methods">
            <span class="admin-form__label">Metodos</span>
            <select id="admin-config-mercadopago-methods" data-admin-config-mercadopago-methods multiple size="6">
              <option value="credit_card">Tarjeta de credito</option>
              <option value="debit_card">Tarjeta de debito</option>
              <option value="prepaid_card">Tarjeta prepaga</option>
              <option value="account_money">Dinero en cuenta</option>
              <option value="bank_transfer">Transferencia bancaria</option>
              <option value="ticket">Pago en efectivo/pago en punto</option>
            </select>
            <small class="admin-form__hint">Manten CTRL (Windows) o CMD (Mac) para seleccionar varios.</small>
          </label>
        </div>
      </section>

      <p class="admin-form__error" data-admin-config-mercadopago-error hidden></p>

      <footer class="modal__footer">
        <button type="submit" class="btn btn-success" data-admin-config-mercadopago-submit>Guardar cambios</button>
      </footer>
    </form>
  </div>
</div>
<?php
return trim(ob_get_clean());
?>
