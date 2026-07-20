<?php
ob_start();
?>
<div class="modal" role="dialog" aria-modal="true" aria-labelledby="admin-qr-title" data-admin-modal="qr" hidden>
  <div class="modal__backdrop" data-admin-qr-close></div>
  <div class="modal__dialog">
    <header class="modal__header">
      <div class="modal__header-text">
        <p class="modal__eyebrow">Escaneo</p>
        <h2 id="admin-qr-title">Escanear cdigo QR</h2>
      </div>
      <button type="button" class="modal__close" data-admin-qr-close aria-label="Cerrar">&times;</button>
    </header>
    <div class="modal__body">
      <div style="display:grid; gap:.6rem">
        <video data-qr-video playsinline style="width:100%; max-height:320px; background:#0b0f17; border:1px solid var(--border); border-radius:.8rem;"></video>
        <p class="muted">Apunta la cmara al cdigo QR del cliente para validarlo.</p>
      </div>
    </div>
  </div>
</div>
<?php
return trim(ob_get_clean());
?>
