<?php
ob_start();
?>
<div class="modal" role="dialog" aria-modal="true" aria-labelledby="auth-required-title" data-modal="auth_required">
  <div class="modal__backdrop" data-modal-close></div>
  <div class="modal__dialog">
    <header class="modal__header">
      <div class="modal__header-text">
        <p class="modal__eyebrow">Acceso requerido</p>
        <h2 id="auth-required-title">Necesitas una cuenta</h2>
      </div>
      <button type="button" class="modal__close" data-modal-close aria-label="Cerrar">&times;</button>
    </header>
    <div class="modal__body">
      <p style="margin:0 0 1rem; color: var(--muted);">
        Necesitas tener una cuenta de cliente para reservar.
      </p>
      <div class="auth-required__actions">
        <button type="button" class="btn btn-outline" data-auth-login>Iniciar Sesión</button>
        <button type="button" class="btn btn-accent" data-auth-register>Registrarme</button>
      </div>
    </div>
  </div>
  <style>
    .auth-required__actions { display:flex; gap:.6rem; justify-content:flex-end; }
    @media (max-width: 480px) {
      .auth-required__actions { flex-direction: column; }
      .auth-required__actions .btn { width: 100%; }
    }
  </style>
</div>
<?php
return trim(ob_get_clean());
?>

