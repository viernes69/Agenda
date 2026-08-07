<?php
ob_start();
?>
<div class="modal" role="dialog" aria-modal="true" aria-labelledby="reservas-modal-title" data-modal="reservas">
  <div class="modal__backdrop" data-modal-close></div>
  <div class="modal__dialog">
    <header class="modal__header">
      <div class="modal__header-text">
        <p class="modal__eyebrow">Cliente</p>
        <h2 id="reservas-modal-title">Mis Reservas</h2>
      </div>
      <button type="button" class="modal__close" data-modal-close aria-label="Cerrar">&times;</button>
    </header>
    <div class="modal__body">
      <div class="reservas-filters">
        <label class="reservas-filter-field">
          <span class="reservas-filter-label">Estado</span>
          <select data-reservas-filter-status>
            <option value="">Todos los estados</option>
            <option value="pendiente">Pendiente</option>
            <option value="aprobado">Reservado</option>
            <option value="cancelado">Cancelado</option>
            <option value="finalizado">Finalizado</option>
            <option value="rechazado">Rechazado</option>
          </select>
        </label>
        <label class="reservas-filter-field">
          <span class="reservas-filter-label">Fecha</span>
          <input type="date" data-reservas-filter-date>
        </label>
      </div>
      <div class="reservas-list" data-reservas-list>
        <p class="muted" data-reservas-empty>Sin reservas por el momento.</p>
      </div>
    </div>
  </div>
  <style>
    .reservas-filters { display:flex; gap:.75rem; flex-wrap:wrap; margin-bottom:1rem; }
    .reservas-filter-field { display:flex; flex-direction:column; gap:.3rem; min-width:150px; color:var(--muted); font-size:.85rem; }
    .reservas-filter-field select,
    .reservas-filter-field input { background: var(--panel); border:1px solid var(--border); border-radius:.6rem; padding:.45rem .65rem; color:var(--text); font-size:.9rem; }
    .reservas-list { display: grid; gap: .6rem; }
    .res-item { display: grid; gap: .35rem; padding: .75rem; background: var(--panel); border: 1px solid var(--border); border-radius: .75rem; }
    .res-head { display:flex; align-items:center; justify-content: space-between; gap: .5rem; }
    .res-title { margin:0; font-weight: 700; font-size: 1rem; }
    .res-meta { margin:0; color: var(--muted); font-size: .9rem; }
    .res-actions { display:flex; gap: .5rem; }
    .btn-danger { background: #ef4444; border: 1px solid #ef4444; color: #fff; border-radius: .6rem; padding: .45rem .7rem; font-weight: 700; cursor: pointer; }
    .btn-danger:hover { background: #dc2626; border-color: #dc2626; }
    .status-badge { padding: .15rem .5rem; border-radius: 999px; font-size: .78rem; font-weight: 700; }
    .status-pendiente { background: rgba(234,179,8,.18); color: #fde68a; border:1px solid rgba(234,179,8,.4); }
    .status-aprobado { background: rgba(34,197,94,.18); color: #bbf7d0; border:1px solid rgba(34,197,94,.45); }
    .status-en-progreso { background: rgba(59,130,246,.18); color: #bfdbfe; border:1px solid rgba(59,130,246,.42); }
    .status-cancelado { background: rgba(239,68,68,.18); color: #fecaca; border:1px solid rgba(239,68,68,.4); }
    .status-finalizado { background: rgba(148,163,184,.18); color: #e2e8f0; border:1px solid rgba(148,163,184,.35); }
    .status-rechazado { background: rgba(248,113,113,.2); color: #fecdd3; border:1px solid rgba(248,113,113,.4); }
  </style>
</div>
<?php
return trim(ob_get_clean());
?>
