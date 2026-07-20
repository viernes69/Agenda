<?php
ob_start();
?>
<div class="modal" role="dialog" aria-modal="true" aria-labelledby="admin-config-notifications-title" data-admin-modal="config-notificaciones" hidden>
  <div class="modal__backdrop" data-admin-config-notificaciones-close></div>
  <div class="modal__dialog">
    <header class="modal__header">
      <div class="modal__header-text">
        <p class="modal__eyebrow">Notificaciones</p>
        <h2 id="admin-config-notifications-title">Configurar notificaciones por WhatsApp</h2>
      </div>
      <button type="button" class="modal__close" data-admin-config-notificaciones-close aria-label="Cerrar">&times;</button>
    </header>
    <form class="modal__body admin-form" data-admin-config-notificaciones-form autocomplete="off" novalidate>
      <section class="admin-form__group">
        <h3 class="admin-form__title">Email del negocio</h3>
        <label class="admin-form__field" for="admin-config-notificaciones-owner-email">
          <span class="admin-form__label">Recibir reservas en</span>
          <input id="admin-config-notificaciones-owner-email" type="email" placeholder="admin@tunegocio.com" data-admin-config-notificaciones-owner-email required>
        </label>
        <p class="admin-form__hint">Las nuevas reservas se envían a este correo usando el SMTP global de Agendarte.</p>
      </section>

      <section class="admin-form__group">
        <h3 class="admin-form__title">WhatsApp</h3>
        <label class="admin-checkbox">
          <input type="checkbox" data-admin-config-notificaciones-enabled>
          <span>Habilitar notificaciones vía WhatsApp</span>
        </label>
        <p class="admin-form__hint">Se utilizará este número para enviar avisos de reservas, recordatorios y novedades a tus clientes.</p>
      </section>

      <section class="admin-form__group">
        <h3 class="admin-form__title">Número de contacto</h3>
        <div class="admin-form__grid">
          <label class="admin-form__field" for="admin-config-notificaciones-country">
            <span class="admin-form__label">Código de país</span>
            <select id="admin-config-notificaciones-country" data-admin-config-notificaciones-country required>
              <option value="+598">🇺🇾 Uruguay (+598)</option>
              <option value="+54">🇦🇷 Argentina (+54)</option>
              <option value="+55">🇧🇷 Brasil (+55)</option>
              <option value="+56">🇨🇱 Chile (+56)</option>
              <option value="+57">🇨🇴 Colombia (+57)</option>
              <option value="+51">🇵🇪 Perú (+51)</option>
              <option value="+52">🇲🇽 México (+52)</option>
              <option value="+1">🇺🇸 Estados Unidos (+1)</option>
              <option value="+34">🇪🇸 España (+34)</option>
              <option value="+33">🇫🇷 Francia (+33)</option>
            </select>
          </label>
          <label class="admin-form__field" for="admin-config-notificaciones-number">
            <span class="admin-form__label">Número (sin código de país)</span>
            <input id="admin-config-notificaciones-number" type="tel" inputmode="numeric" pattern="[0-9]{5,15}" placeholder="912345678" data-admin-config-notificaciones-number required>
          </label>
        </div>
        <small class="admin-form__hint">Ingresa solo números. No utilices espacios, guiones ni signos (+).</small>
      </section>

      <p class="admin-form__error" data-admin-config-notificaciones-error hidden></p>

      <footer class="modal__footer">
        <button type="submit" class="btn btn-success" data-admin-config-notificaciones-submit>Guardar cambios</button>
      </footer>
    </form>
  </div>
</div>
<?php
return trim(ob_get_clean());
?>
