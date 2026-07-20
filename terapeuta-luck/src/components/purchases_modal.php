<?php
ob_start();
?>
<div class="modal" role="dialog" aria-modal="true" aria-labelledby="purchases-modal-title" data-modal="purchases">
  <div class="modal__backdrop" data-modal-close></div>
  <div class="modal__dialog modal__dialog--lg">
    <header class="modal__header">
      <div class="modal__header-text">
        <p class="modal__eyebrow">Historial</p>
        <h2 id="purchases-modal-title">Mis Compras</h2>
      </div>
      <button type="button" class="modal__close" data-modal-close aria-label="Cerrar">&times;</button>
    </header>
    <div class="modal__body">
      <div class="purchases-summary">
        <span class="purchases-total" data-purchases-count>0 compras</span>
        <span class="purchases-status" data-purchases-status></span>
      </div>
      <div class="purchases-content" data-purchases-content>
        <div class="purchases-empty" data-purchases-empty>
          <p>No registramos compras completas en tu cuenta.</p>
        </div>
        <div class="purchases-list" data-purchases-list hidden></div>
      </div>
    </div>
  </div>
</div>
<style>
  .purchases-summary {
    display:flex;
    justify-content:space-between;
    align-items:center;
    margin-bottom:1rem;
    font-size:.95rem;
    color:var(--muted);
  }
  .purchases-list {
    display:grid;
    gap:.9rem;
  }
  .purchase-card {
    border:1px solid rgba(148,163,184,.25);
    border-radius:1rem;
    padding:1rem 1.1rem;
    background:rgba(15,23,42,.6);
    box-shadow:0 10px 24px rgba(15,23,42,.4);
  }
  .purchase-head {
    display:flex;
    justify-content:space-between;
    gap:1rem;
    margin-bottom:.65rem;
  }
  .purchase-title {
    font-size:1.05rem;
    font-weight:600;
    margin:0;
    color:var(--text);
  }
  .purchase-meta {
    font-size:.85rem;
    color:var(--muted);
    margin:0;
  }
  .purchase-items {
    display:grid;
    gap:.5rem;
    margin:.75rem 0 0;
    padding:0;
    list-style:none;
  }
  .purchase-item {
    display:flex;
    justify-content:space-between;
    gap:1rem;
    font-size:.9rem;
  }
  .purchase-item-name {
    font-weight:500;
  }
  .purchase-item-qty {
    color:var(--muted);
  }
  .purchase-status {
    display:inline-flex;
    align-items:center;
    gap:.4rem;
    border-radius:999px;
    padding:.3rem .75rem;
    font-size:.8rem;
    text-transform:capitalize;
    background:rgba(59,130,246,.18);
    color:#93c5fd;
  }
  .purchase-status.status-pendiente {
    background:rgba(248,113,113,.2);
    color:#fca5a5;
  }
  .purchase-status.status-finalizado {
    background:rgba(34,197,94,.18);
    color:#86efac;
  }
  .purchase-status.status-cancelado {
    background:rgba(148,163,184,.2);
    color:#cbd5f5;
  }
  .purchases-empty {
    text-align:center;
    padding:2rem 1rem;
    border:1px dashed rgba(148,163,184,.25);
    border-radius:1rem;
    color:var(--muted);
    font-size:.95rem;
  }
</style>
<?php
return trim(ob_get_clean());
?>
