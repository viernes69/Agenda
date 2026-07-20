<?php
ob_start();
?>
<div class="modal" role="dialog" aria-modal="true" aria-labelledby="cart-added-title" data-modal="cart_added">
  <div class="modal__backdrop" data-modal-close></div>
  <div class="modal__dialog">
    <header class="modal__header">
      <div class="modal__header-text">
        <p class="modal__eyebrow">Carrito</p>
        <h2 id="cart-added-title">
          <span class="confirm-status is-success">
            <span class="confirm-status__icon" aria-hidden="true">&#10003;</span>
            <span>Producto agregado</span>
          </span>
        </h2>
      </div>
      <button type="button" class="modal__close" data-modal-close aria-label="Cerrar">&times;</button>
    </header>
    <div class="modal__body">
      <p>¿Deseas agregar otro producto al carrito?</p>
      <div class="cart-added__actions">
        <button type="button" class="btn btn-outline" data-cart-continue>Agregar otro</button>
        <button type="button" class="btn btn-accent" data-cart-go>Ir al carrito para finalizar</button>
      </div>
    </div>
  </div>
  <style>
    .cart-added__actions { display:flex; gap:.6rem; justify-content:flex-end; }
    @media (max-width: 480px) { .cart-added__actions { flex-direction:column; } .cart-added__actions .btn { width:100%; } }
  </style>
</div>
<?php
return trim(ob_get_clean());
?>
