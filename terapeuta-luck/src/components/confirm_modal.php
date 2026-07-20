<?php
ob_start();
?>
<div class="modal" role="dialog" aria-modal="true" aria-labelledby="confirm-modal-title" data-modal="confirm">
  <div class="modal__backdrop" data-modal-close></div>
  <div class="modal__dialog">
    <header class="modal__header">
      <div class="modal__header-text">
        <h2 id="confirm-modal-title">
          <span class="confirm-status is-success" data-confirm-status-wrapper>
            <span class="confirm-status__icon" aria-hidden="true">&#10003;</span>
            <span data-confirm-status>Reserva Finalizada</span>
          </span>
        </h2>
      </div>
      <button type="button" class="modal__close" data-modal-close aria-label="Cerrar">&times;</button>
    </header>
    <div class="modal__body">
      <div class="confirm-details">
        <p><strong>Servicio:</strong> <span data-confirm-service>-</span></p>
        <p><strong>Profesional:</strong> <span data-confirm-barber>-</span></p>
        <p><strong>Fecha:</strong> <span data-confirm-date>-</span></p>
        <p><strong>Hora:</strong> <span data-confirm-slot>-</span></p>
        <p><strong>Pago:</strong> <span data-confirm-payment>-</span></p>
      </div>
      <p class="muted" data-confirm-error hidden></p>
      <a href="#" class="btn btn-whatsapp" target="_blank" rel="noopener" data-confirm-whatsapp hidden>Ir al WhatsApp para Avisar</a>
      <button type="button" class="btn btn-accent" data-modal-close>Aceptar</button>
    </div>
  </div>
  <style>
    .confirm-details { display:grid; gap:.25rem; color:var(--text); }
    .confirm-details strong { color: var(--muted); font-weight:600; }
    .confirm-status {
      display: inline-flex;
      align-items: center;
      gap: .5rem;
      font-size: 1.15rem;
      font-weight: 700;
      color: #d1fae5;
    }
    .confirm-status__icon {
      width: 34px;
      height: 34px;
      border-radius: 999px;
      background: #22c55e;
      color: #05130a;
      display: grid;
      place-items: center;
      font-size: 1.1rem;
      box-shadow: 0 0 18px rgba(34, 197, 94, 0.45);
    }
    .confirm-status.is-error {
      color: #fecaca;
    }
    .confirm-status.is-error .confirm-status__icon {
      background: #ef4444;
      color: #fff;
      box-shadow: 0 0 14px rgba(239, 68, 68, 0.4);
    }
    .btn-whatsapp {
      display:block;
      text-align:center;
      margin-bottom:0.75rem;
      background: #16a34a;
      color:#041407;
      font-weight:600;
      border-radius:12px;
      padding:0.85rem 1rem;
      border:0;
      animation: confirm-whatsapp-pulse 1.4s ease-in-out infinite;
      text-decoration:none;
      box-shadow:0 12px 24px rgba(22,163,74,.35);
    }
    .btn-whatsapp:hover{
      filter:brightness(1.05);
    }
    @keyframes confirm-whatsapp-pulse{
      0%,100%{ box-shadow:0 0 0 rgba(16,185,129,0.5); transform:translateY(0); }
      50%{ box-shadow:0 0 18px rgba(16,185,129,0.65); transform:translateY(-1px); }
    }
  </style>
  </div>
<?php
return trim(ob_get_clean());
?>

