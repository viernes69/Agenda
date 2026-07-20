<?php
ob_start();
?>
<div class="modal" role="dialog" aria-modal="true" aria-labelledby="cart-summary-title" data-modal="cart_summary">
  <div class="modal__backdrop" data-modal-close></div>
  <div class="modal__dialog">
    <header class="modal__header">
      <div class="modal__header-text">
        <p class="modal__eyebrow">Pedido</p>
        <h2 id="cart-summary-title">
          <span class="confirm-status is-success">
            <span class="confirm-status__icon" aria-hidden="true">&#10003;</span>
            <span>Pedido confirmado</span>
          </span>
        </h2>
      </div>
      <button type="button" class="modal__close" data-modal-close aria-label="Cerrar">&times;</button>
    </header>
    <div class="modal__body">
      <div class="cart-summary">
        <p class="cart-summary__row"><strong>ID:</strong> <span data-order-id>-</span></p>
        <p class="cart-summary__row"><strong>Estado:</strong> <span data-order-status>-</span></p>
        <p class="cart-summary__row"><strong>Direccion:</strong> <span data-order-address>-</span></p>
        <p class="cart-summary__row"><strong>Fecha y hora:</strong> <span data-order-datetime>-</span></p>
        <div class="cart-summary__row">
          <strong>Productos:</strong>
          <div data-order-items class="cart-summary__items"></div>
        </div>
        <p class="cart-summary__row cart-summary__row--total"><strong>Total:</strong> <span data-order-total>-</span></p>
      </div>
    </div>
    <footer class="modal__footer cart-summary__footer">
      <button type="button" class="btn btn-accent" data-order-close>Cerrar</button>
    </footer>
  </div>
</div>
<style>
  .cart-summary { display:grid; gap:.6rem; }
  .cart-summary__row { margin:0; display:grid; gap:.35rem; font-size:.95rem; }
  .cart-summary__row strong { color:var(--muted); font-weight:600; }
  .cart-summary__items { padding-left:0; }
  .order-summary-list { list-style:none; padding:0; margin:0; display:grid; gap:.25rem; }
  .order-summary-list li { background:rgba(15,23,42,.45); border:1px solid rgba(148,163,184,.18); border-radius:.55rem; padding:.4rem .6rem; }
  .cart-summary__row--total { font-size:1.05rem; font-weight:600; display:flex; justify-content:space-between; align-items:center; }
  .cart-summary__footer { display:flex; justify-content:flex-end; padding:1rem; }
</style>
<?php
return trim(ob_get_clean());
?>
