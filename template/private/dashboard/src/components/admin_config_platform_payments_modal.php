<?php
/**
 * Modal: Cobro de Suscripciones (PayPal, Mercado Pago, Transferencia Bancaria).
 */
ob_start();
?>
<div class="modal" role="dialog" aria-modal="true" aria-labelledby="admin-config-platform-payments-title" data-admin-modal="config-platform-payments" hidden>
  <div class="modal__backdrop" data-admin-config-platform-payments-close></div>
  <div class="modal__dialog modal__dialog--lg">
    <header class="modal__header">
      <div class="modal__header-text">
        <p class="modal__eyebrow">Cobro de Suscripciones</p>
        <h2 id="admin-config-platform-payments-title">PayPal, Mercado Pago y Transferencia</h2>
      </div>
      <button type="button" class="modal__close" data-admin-config-platform-payments-close aria-label="Cerrar">&times;</button>
    </header>
    <form class="modal__body admin-form" data-admin-config-platform-payments-form autocomplete="off" novalidate>
      
      <!-- Section 1: PayPal -->
      <section class="admin-form__group">
        <h3 class="admin-form__title"><i class="bx bxl-paypal"></i> PayPal (Suscripciones)</h3>
        <div class="admin-form__grid">
          <label class="admin-form__field">
            <span class="admin-form__label">Estado</span>
            <select data-admin-config-pp-field="paypal.is_enabled">
              <option value="1">Habilitado</option>
              <option value="0">Deshabilitado</option>
            </select>
          </label>
          <label class="admin-form__field">
            <span class="admin-form__label">Entorno</span>
            <select data-admin-config-pp-field="paypal.sandbox">
              <option value="1">Sandbox (Pruebas)</option>
              <option value="0">Live (Producci&oacute;n)</option>
            </select>
          </label>
        </div>
        <div class="admin-form__grid">
          <label class="admin-form__field">
            <span class="admin-form__label">Client ID</span>
            <input type="text" data-admin-config-pp-field="paypal.client_id" placeholder="Client ID de PayPal Developer">
          </label>
          <label class="admin-form__field">
            <span class="admin-form__label">Client Secret</span>
            <input type="password" data-admin-config-pp-field="paypal.client_secret" placeholder="Secret Key de PayPal Developer">
          </label>
        </div>
      </section>

      <!-- Section 2: Mercado Pago -->
      <section class="admin-form__group">
        <h3 class="admin-form__title"><i class="bx bx-credit-card"></i> Mercado Pago (Suscripciones)</h3>
        <div class="admin-form__grid">
          <label class="admin-form__field">
            <span class="admin-form__label">Estado</span>
            <select data-admin-config-pp-field="mercadopago.is_enabled">
              <option value="1">Habilitado</option>
              <option value="0">Deshabilitado</option>
            </select>
          </label>
        </div>
        <div class="admin-form__grid">
          <label class="admin-form__field">
            <span class="admin-form__label">Public Key (Plataforma)</span>
            <input type="text" data-admin-config-pp-field="mercadopago.public_key" placeholder="APP_USR-xxxxxxxx">
          </label>
          <label class="admin-form__field">
            <span class="admin-form__label">Access Token (Plataforma)</span>
            <input type="password" data-admin-config-pp-field="mercadopago.access_token" placeholder="APP_USR-xxxxxxxx">
          </label>
        </div>
      </section>

      <!-- Section 3: Transferencia Bancaria -->
      <section class="admin-form__group">
        <h3 class="admin-form__title"><i class="bx bx-building-house"></i> Transferencia Bancaria</h3>
        <div class="admin-form__grid">
          <label class="admin-form__field">
            <span class="admin-form__label">Estado</span>
            <select data-admin-config-pp-field="transfer.is_enabled">
              <option value="1">Habilitado</option>
              <option value="0">Deshabilitado</option>
            </select>
          </label>
          <label class="admin-form__field">
            <span class="admin-form__label">Moneda de cobro</span>
            <select data-admin-config-pp-field="transfer.moneda">
              <option value="UYU">UYU ($)</option>
              <option value="USD">USD (US$)</option>
            </select>
          </label>
        </div>
        <div class="admin-form__grid">
          <label class="admin-form__field">
            <span class="admin-form__label">Banco</span>
            <input type="text" data-admin-config-pp-field="transfer.banco" placeholder="Ej: BROU / Ita&uacute; / Santander">
          </label>
          <label class="admin-form__field">
            <span class="admin-form__label">Titular de la cuenta</span>
            <input type="text" data-admin-config-pp-field="transfer.titular" placeholder="Ej: Agendarte UY SRL">
          </label>
        </div>
        <div class="admin-form__grid">
          <label class="admin-form__field">
            <span class="admin-form__label">N&deg; de Cuenta / CBU / Alias</span>
            <input type="text" data-admin-config-pp-field="transfer.cuenta" placeholder="Ej: CA 000-123456789">
          </label>
        </div>
        <label class="admin-form__field">
          <span class="admin-form__label">Instrucciones para el cliente</span>
          <textarea rows="2" data-admin-config-pp-field="transfer.instrucciones" placeholder="Instrucciones adicionales para la transferencia..."></textarea>
        </label>
      </section>

      <p class="admin-form__error" data-admin-config-pp-error hidden></p>

      <footer class="modal__footer">
        <button type="submit" class="btn btn-primary" data-admin-config-pp-submit>Guardar cambios</button>
      </footer>
    </form>
  </div>
</div>
<?php
return trim(ob_get_clean());
?>
