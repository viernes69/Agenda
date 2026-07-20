<?php
ob_start();
?>
<div class="modal" role="dialog" aria-modal="true" aria-labelledby="barber-modal-title" data-modal="barber">
  <div class="modal__backdrop" data-modal-close></div>
  <div class="modal__dialog modal__dialog--barber">
    <header class="modal__header">
      <div class="barber-modal__header">
        <button type="button" class="barber-modal__avatar-button" data-barber-avatar-trigger aria-label="Ver foto de perfil">
          <span class="barber-card__avatar barber-modal__avatar" data-barber-avatar>
            <span class="barber-card__avatar-inner" data-barber-avatar-inner></span>
          </span>
        </button>
        <div class="barber-modal__titles">
          <h2 id="barber-modal-title" data-barber-name>Profesional</h2>
          <p class="barber-modal__turns" data-barber-turns></p>
        </div>
      </div>
      <button type="button" class="modal__close" data-modal-close aria-label="Cerrar">&times;</button>
    </header>
    <div class="modal__body barber-modal__body">
      <p class="barber-modal__description">Elegi fecha, servicio y un horario disponible para continuar.</p>
      <p class="barber-modal__alert" data-barber-warning hidden></p>

      <div class="barber-modal__form">
        <label>
          <span>Fecha</span>
          <input type="date" data-barber-date>
        </label>
        <label>
          <span>Servicio</span>
          <select data-barber-service></select>
        </label>
        <label>
          <span>Horario</span>
          <select data-barber-slot></select>
        </label>
      </div>

      <div class="barber-modal__slots" data-barber-slots hidden></div>
      <button type="button" class="schedule-cta barber-modal__cta" data-barber-book disabled>Reservar Ahora</button>
    </div>
    <div class="barber-modal__photo-overlay" data-barber-photo-overlay hidden aria-hidden="true" tabindex="-1">
      <div class="barber-modal__photo-frame">
        <button type="button" class="barber-modal__photo-close" data-barber-photo-close aria-label="Cerrar vista de perfil">&times;</button>
        <div class="barber-modal__photo-preview">
          <img class="barber-modal__photo-img" data-barber-photo-img alt="Foto del profesional" hidden>
          <span class="barber-card__avatar barber-modal__photo-fallback" data-barber-photo-fallback hidden>
            <span class="barber-card__avatar-inner" data-barber-photo-fallback-inner></span>
          </span>
        </div>
        <div class="barber-modal__photo-meta">
          <h3 data-barber-photo-name>Profesional</h3>
          <p data-barber-photo-turns></p>
        </div>
      </div>
    </div>
  </div>
</div>
<?php
return trim(ob_get_clean());
?>

