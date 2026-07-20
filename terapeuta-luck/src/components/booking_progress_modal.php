<?php
ob_start();
?>
<div
  class="modal modal--loading"
  role="dialog"
  aria-modal="true"
  aria-live="polite"
  aria-labelledby="booking-progress-title"
  data-modal="booking-progress"
>
  <div class="modal__backdrop modal__backdrop--static"></div>
  <div class="modal__dialog modal__dialog--sm loading-modal">
    <div class="loading-modal__content">
      <div class="loading-modal__spinner" aria-hidden="true"></div>
      <p id="booking-progress-title" class="loading-modal__title" data-loading-title>Guardando Reserva</p>
      <p class="loading-modal__subtitle" data-loading-subtitle>Por Favor Espere.</p>
    </div>
  </div>
  <style>
    .modal--loading .modal__dialog {
      background: rgba(9, 14, 28, 0.92);
      border-radius: 1.25rem;
      border: 1px solid rgba(148, 163, 184, 0.25);
      box-shadow: 0 20px 60px rgba(3, 4, 12, 0.65);
      max-width: 320px;
      width: calc(100% - 32px);
      padding: 2rem 1.5rem;
    }
    .modal--loading .modal__backdrop--static {
      pointer-events: none;
    }
    .loading-modal__content {
      display: grid;
      place-items: center;
      gap: 0.9rem;
      text-align: center;
      color: #e2e8f0;
    }
    .loading-modal__spinner {
      width: 54px;
      height: 54px;
      border-radius: 50%;
      border: 3px solid rgba(226, 232, 240, 0.2);
      border-top-color: #38bdf8;
      animation: booking-spin 0.9s linear infinite;
    }
    .loading-modal__title {
      font-size: 1.1rem;
      font-weight: 600;
      color: #f8fafc;
      margin: 0;
    }
    .loading-modal__subtitle {
      margin: 0;
      font-size: 0.95rem;
      color: rgba(226, 232, 240, 0.75);
    }
    @keyframes booking-spin {
      from { transform: rotate(0deg); }
      to { transform: rotate(360deg); }
    }
  </style>
</div>
<?php
return trim(ob_get_clean());
