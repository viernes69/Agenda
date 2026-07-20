<?php
ob_start();

$config = isset($paymentMethodsConfig) && is_array($paymentMethodsConfig) ? $paymentMethodsConfig : null;
$definitions = [
    'POS' => ['value' => 'POS', 'label' => 'POS'],
    'Efectivo' => ['value' => 'Efectivo', 'label' => 'Efectivo'],
    'MP' => ['value' => 'Mercado Pago', 'label' => 'Mercado Pago'],
];
$enabledMethods = [];
foreach ($definitions as $key => $definition) {
    if ($config !== null && empty($config[$key])) {
        continue;
    }
    $enabledMethods[] = $definition;
}
?>
<div class="modal" role="dialog" aria-modal="true" aria-labelledby="payment-modal-title" data-modal="payment">
  <div class="modal__backdrop" data-modal-close></div>
  <div class="modal__dialog">
    <header class="modal__header">
      <h2 id="payment-modal-title">Selecciona como deseas pagar</h2>
      <button type="button" class="modal__close" data-modal-close aria-label="Cerrar">&times;</button>
    </header>
    <div class="modal__body">
      <div class="payment-options">
        <?php if (!empty($enabledMethods)): ?>
          <?php foreach ($enabledMethods as $method): ?>
            <button type="button" class="payment-btn" data-payment="<?php echo htmlspecialchars($method['value'], ENT_QUOTES, 'UTF-8'); ?>">
              <?php echo htmlspecialchars($method['label'], ENT_QUOTES, 'UTF-8'); ?>
            </button>
          <?php endforeach; ?>
        <?php else: ?>
          <p class="payment-options__empty">No hay metodos de pago disponibles.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php
return trim(ob_get_clean());
?>
