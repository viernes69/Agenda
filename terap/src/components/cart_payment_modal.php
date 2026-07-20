<?php
ob_start();
?>
<div class="modal" role="dialog" aria-modal="true" aria-labelledby="cart-payment-title" data-modal="cart_payment">
  <div class="modal__backdrop" data-modal-close></div>
  <div class="modal__dialog">
    <header class="modal__header">
      <div class="modal__header-text">
        <p class="modal__eyebrow">Pago</p>
        <h2 id="cart-payment-title">Elige el medio de pago</h2>
      </div>
      <button type="button" class="modal__close" data-modal-close aria-label="Cerrar">&times;</button>
    </header>
    <div class="modal__body">
      <div class="payment-options" style="display:grid; gap:.6rem;">
        <button type="button" class="btn btn-accent" data-cart-pay-mp>Mercado Pago / Tarjeta de Credito</button>
        <button type="button" class="btn btn-outline" data-cart-pay-cash>Efectivo al momento de la entrega</button>
      </div>
    </div>
  </div>
</div>
<?php
return trim(ob_get_clean());
?>

