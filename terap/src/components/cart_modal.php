<?php
ob_start();
?>
<div class="modal" role="dialog" aria-modal="true" aria-labelledby="cart-modal-title" data-modal="modal_cart">
  <div class="modal__backdrop" data-modal-close></div>
  <div class="modal__dialog">
    <header class="modal__header">
      <div class="modal__header-text">
        <p class="modal__eyebrow">Carrito</p>
        <h2 id="cart-modal-title">Tu pedido</h2>
      </div>
      <button type="button" class="modal__close" data-modal-close aria-label="Cerrar">&times;</button>
    </header>
    <div class="modal__body">
      <div class="cart-list" data-cart-list>
        <p class="muted">Tu carrito está vacío.</p>
      </div>
      <div class="cart-address">
        <label class="cart-address-label" for="cart-address-input">Dirección de entrega</label>
        <input id="cart-address-input" type="text" class="cart-address-input" data-cart-address placeholder="Ingresa la dirección completa">
        <label class="cart-pickup-option">
          <input type="checkbox" data-cart-pickup>
          <span data-cart-pickup-text>Retiro en el local</span>
        </label>
        <p class="cart-address-error muted" data-cart-address-error hidden>Ingresa una dirección para la entrega o marca "Retiro en el local".</p>
      </div>
      <div class="cart-total" data-cart-total style="margin-top:.75rem; font-weight:700;"></div>
      <div class="cart-actions">
        <button type="button" class="btn btn-outline" data-cart-clear>Vaciar Carrito</button>
        <button type="button" class="btn btn-success" data-cart-checkout>Finalizar Pedido</button>
      </div>
    </div>
  </div>
  <style>
    .cart-list { display:grid; gap:.5rem; }
    .cart-item { display:grid; grid-template-columns: 1fr auto auto; gap:.5rem; align-items:center; }
    .cart-item__name { font-weight:600; }
    .cart-item__qty, .cart-item__subtotal { color: var(--muted); }
    .cart-address { margin-top:1rem; display:grid; gap:.45rem; }
    .cart-address-label { font-size:.85rem; font-weight:600; color:var(--muted); }
    .cart-address-input { width:100%; padding:.6rem .75rem; border-radius:.6rem; border:1px solid rgba(148,163,184,.35); background:rgba(15,23,42,.45); color:var(--text); }
    .cart-address-input:disabled { opacity:.65; cursor:not-allowed; }
    .cart-pickup-option { display:inline-flex; align-items:center; gap:.45rem; font-size:.9rem; color:var(--muted); cursor:pointer; }
    .cart-pickup-option input { width:18px; height:18px; }
    .cart-address-error { font-size:.8rem; color:#fca5a5; }
    .cart-actions { display:flex; gap:.6rem; justify-content:flex-end; margin-top:1rem; }
  </style>
</div>
<?php
return trim(ob_get_clean());
?>
