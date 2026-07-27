<?php
ob_start();
?>
<div class="modal" role="dialog" aria-modal="true" aria-labelledby="admin-config-cart-title" data-admin-modal="config-carrito" hidden>
  <div class="modal__backdrop" data-admin-config-cart-close></div>
  <div class="modal__dialog modal__dialog--lg">
    <header class="modal__header">
      <div class="modal__header-text">
        <p class="modal__eyebrow">Tienda</p>
        <h2 id="admin-config-cart-title">Carrito y pedidos</h2>
        <p class="modal__subtitle">Defini como compran tus clientes desde la tienda publica.</p>
      </div>
      <button type="button" class="modal__close" data-admin-config-cart-close aria-label="Cerrar">&times;</button>
    </header>
    <form class="modal__body admin-form" data-admin-config-cart-form autocomplete="off" novalidate>
      <section class="admin-form__group">
        <h3 class="admin-form__title">Canales de venta</h3>
        <div class="admin-form__switch-grid">
          <label class="admin-form__switch">
            <input type="checkbox" data-admin-config-cart-toggle="enabled">
            <span class="admin-form__switch-control" aria-hidden="true"></span>
            <span>
              <strong>Carrito activo</strong>
              <small>Muestra botones para agregar productos y crear pedidos.</small>
            </span>
          </label>
          <label class="admin-form__switch">
            <input type="checkbox" data-admin-config-cart-toggle="whatsapp_enabled">
            <span class="admin-form__switch-control" aria-hidden="true"></span>
            <span>
              <strong>Pedidos por WhatsApp</strong>
              <small>Abre WhatsApp con el detalle del pedido.</small>
            </span>
          </label>
          <label class="admin-form__switch">
            <input type="checkbox" data-admin-config-cart-toggle="mercado_pago_enabled">
            <span class="admin-form__switch-control" aria-hidden="true"></span>
            <span>
              <strong>Mercado Pago en tienda</strong>
              <small>Permite pagar online si la cuenta esta configurada y el plan lo habilita.</small>
            </span>
          </label>
        </div>
      </section>

      <section class="admin-form__group">
        <h3 class="admin-form__title">Entrega</h3>
        <div class="admin-form__switch-grid admin-form__switch-grid--compact">
          <label class="admin-form__switch">
            <input type="checkbox" data-admin-config-cart-toggle="pickup_enabled">
            <span class="admin-form__switch-control" aria-hidden="true"></span>
            <span>
              <strong>Retiro en local</strong>
              <small>El cliente puede coordinar retiro.</small>
            </span>
          </label>
          <label class="admin-form__switch">
            <input type="checkbox" data-admin-config-cart-toggle="delivery_enabled">
            <span class="admin-form__switch-control" aria-hidden="true"></span>
            <span>
              <strong>Entrega</strong>
              <small>El cliente puede coordinar envio o entrega.</small>
            </span>
          </label>
        </div>
      </section>

      <section class="admin-form__group">
        <label class="admin-form__field" for="admin-config-cart-instructions">
          <span class="admin-form__label">Mensaje para coordinar</span>
          <textarea id="admin-config-cart-instructions" rows="3" maxlength="220" data-admin-config-cart-field="instructions" placeholder="Ej: Coordinamos entrega o retiro por este medio. Gracias!"></textarea>
          <small class="admin-form__hint">Se agrega al mensaje de WhatsApp y al detalle del pedido.</small>
        </label>
      </section>

      <p class="admin-form__error" data-admin-config-cart-error hidden></p>

      <footer class="modal__footer">
        <button type="button" class="btn btn-outline" data-admin-config-cart-close>Cancelar</button>
        <button type="submit" class="btn btn-success" data-admin-config-cart-submit>Guardar cambios</button>
      </footer>
    </form>
  </div>
</div>
<?php
return trim(ob_get_clean());
?>
