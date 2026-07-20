<?php
ob_start();
?>
<div class="modal" role="dialog" aria-modal="true" aria-labelledby="admin-cliente-title" data-admin-modal="cliente" hidden>
  <div class="modal__backdrop" data-admin-cliente-close></div>
  <div class="modal__dialog">
    <header class="modal__header">
      <div class="modal__header-text">
        <p class="modal__eyebrow">Cliente</p>
        <h2 id="admin-cliente-title">Datos del cliente</h2>
      </div>
      <button type="button" class="modal__close" data-admin-cliente-close aria-label="Cerrar">&times;</button>
    </header>
    <div class="modal__body">
      <div style="display:flex; justify-content:center; margin-bottom:.6rem;">
        <div class="avatar" style="width:84px; height:84px;" data-admin-cliente-avatar>
          <img class="avatar__img" data-admin-cliente-photo hidden>
          <span class="avatar__initials" data-admin-cliente-initials hidden></span>
        </div>
      </div>
      <div class="cliente-datos" style="display:grid; gap:.35rem;">
        <p><strong>Nombre:</strong> <span data-admin-cliente-nombre>-</span></p>
        <p><strong>Cédula:</strong> <span data-admin-cliente-cedula>-</span></p>
        <p><strong>Teléfono:</strong> <span data-admin-cliente-telefono>-</span></p>
        <p><strong>Email:</strong> <span data-admin-cliente-email>-</span></p>
      </div>
      <div style="display:flex; gap:.6rem; margin-top:.9rem;">
        <button type="button" class="btn btn-warning" data-admin-cliente-historial>Historial</button>
      </div>
    </div>
  </div>
</div>
<?php
return trim(ob_get_clean());
?>
