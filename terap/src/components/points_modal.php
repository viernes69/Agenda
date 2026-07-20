<?php
ob_start();
?>
<div class="modal" role="dialog" aria-modal="true" aria-labelledby="points-modal-title" data-modal="points">
  <div class="modal__backdrop" data-modal-close></div>
  <div class="modal__dialog">
    <header class="modal__header">
      <div class="modal__header-text">
        <p class="modal__eyebrow">Recompensas</p>
        <h2 id="points-modal-title">Mis Puntos</h2>
      </div>
      <button type="button" class="modal__close" data-modal-close aria-label="Cerrar">&times;</button>
    </header>
    <div class="modal__body">
      <p><strong>Total:</strong> <span data-points-total>-</span></p>
      <div class="points-bar" data-points-bar>
        <div class="points-bar__fill" data-points-fill style="width:0%"></div>
      </div>
      <div class="points-rewards" data-points-rewards>
        <p class="muted" data-points-empty hidden>No encontramos recompensas disponibles por el momento.</p>
      </div>
    </div>
  </div>
</div>
<style>
  .points-bar { position: relative; height: 12px; border-radius: 999px; background: rgba(148,163,184,0.18); border: 1px solid rgba(148,163,184,0.25); overflow: hidden; margin-top: .6rem; }
  .points-bar__fill { height: 100%; width: 0%; border-radius: inherit; transition: width .35s ease; background: linear-gradient(90deg, #ef4444, #f87171); }
  .points-bar__fill.is-low { background: linear-gradient(90deg, #ef4444, #f87171); }
  .points-bar__fill.is-mid { background: linear-gradient(90deg, #f59e0b, #fbbf24); }
  .points-bar__fill.is-high { background: linear-gradient(90deg, var(--success), var(--success-dark)); }
  .points-rewards { margin-top: 1.5rem; display: grid; gap: .75rem; }
  .points-reward-card { display: grid; gap: .4rem; padding: .8rem; border-radius: .75rem; border: 1px solid var(--border); background: var(--surface); }
  .points-reward-card.is-locked { opacity: .55; filter: grayscale(.3); }
  .points-reward-head { display:flex; justify-content: space-between; align-items:center; gap:.75rem; }
  .points-reward-title { margin:0; font-size: 1rem; font-weight:700; color: var(--text); }
  .points-reward-points { font-weight:600; color: var(--muted); }
  .points-reward-body { margin:0; color: var(--muted); font-size:.9rem; }
  .points-reward-actions { display:flex; justify-content:flex-end; }
  .points-reward-btn { border-radius:.6rem; padding:.45rem .75rem; font-weight:600; border:1px solid var(--success); background: var(--success); color:#0f172a; cursor:pointer; transition:opacity .2s ease; }
  .points-reward-btn:hover { opacity:.85; }
  .points-reward-btn:disabled { cursor:not-allowed; background: rgba(148,163,184,.25); border-color: rgba(148,163,184,.35); color: rgba(148,163,184,.8); }
</style>
<?php
return trim(ob_get_clean());
?>
