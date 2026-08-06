<?php
declare(strict_types=1);

header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/../Core/bootstrap.php';

use Agenduy\Core\LandingContent;
?>
<div class="benefits-modal" role="document" aria-live="polite">
  <header class="benefits-modal__header">
    <span class="benefits-modal__eyebrow">Beneficios</span>
    <h3 class="benefits-modal__title" id="modal-beneficios-title">Llevá tu agenda al siguiente nivel</h3>
    <button type="button" class="benefits-modal__close" aria-label="Cerrar">&times;</button>
  </header>
  <div class="benefits-modal__body">
    <p>
      Organizá tus horarios, recibí reservas online y permití que tus clientes agenden cuando quieran.
      Con Agendarte UY, tu negocio puede estar disponible las 24 horas.
    </p>
    <ul class="benefits-modal__list">
      <?php foreach (LandingContent::benefits() as $benefit): ?>
      <li><?= htmlspecialchars($benefit, ENT_QUOTES, 'UTF-8') ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
  <footer class="benefits-modal__footer">
    <button type="button" class="benefits-modal__cta plan-btn" data-rubro-id="" data-rubro-nombre="">Registrar mi negocio</button>
  </footer>
</div>
