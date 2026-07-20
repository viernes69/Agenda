<?php
if (!isset($siteLegalContent) || !is_array($siteLegalContent)) {
  $siteLegalContent = ['terminos' => '', 'privacidad' => '', 'reembolsos' => ''];
}
$formatLegal = static function ($value) {
  $clean = trim((string)$value);
  if ($clean === '') {
    return '<span class="muted">Contenido no disponible.</span>';
  }
  return nl2br(htmlspecialchars($clean, ENT_QUOTES, 'UTF-8'));
};
ob_start();
?>
<div class="modal" role="dialog" aria-modal="true" aria-labelledby="site-legal-title" data-modal="legal" hidden>
  <div class="modal__backdrop" data-modal-close></div>
  <div class="modal__dialog modal__dialog--lg">
    <header class="modal__header">
      <div class="modal__header-text">
        <p class="modal__eyebrow">Condiciones</p>
        <h2 id="site-legal-title">Condiciones del negocio</h2>
      </div>
      <button type="button" class="modal__close" data-modal-close aria-label="Cerrar">&times;</button>
    </header>
    <div class="modal__body site-legal-body">
      <section class="site-legal-section">
        <h3>Términos y condiciones</h3>
        <p><?php echo $formatLegal($siteLegalContent['terminos'] ?? ''); ?></p>
      </section>
      <section class="site-legal-section">
        <h3>Política de privacidad</h3>
        <p><?php echo $formatLegal($siteLegalContent['privacidad'] ?? ''); ?></p>
      </section>
      <section class="site-legal-section">
        <h3>Política de reembolsos</h3>
        <p><?php echo $formatLegal($siteLegalContent['reembolsos'] ?? ''); ?></p>
      </section>
    </div>
    <footer class="modal__footer">
      <button type="button" class="btn btn-outline" data-modal-close>Cerrar</button>
    </footer>
  </div>
</div>
<?php
return trim(ob_get_clean());
?>
