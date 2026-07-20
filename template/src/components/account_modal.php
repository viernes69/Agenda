<?php
ob_start();

$escape = static function ($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
};

$computeInitials = static function ($value) {
    $value = strtoupper(trim((string)$value));
    if ($value === '') {
        return 'CL';
    }
    $parts = preg_split('/\s+/', $value) ?: [];
    $letters = '';
    foreach ($parts as $piece) {
        if ($piece === '') {
            continue;
        }
        $letters .= substr($piece, 0, 1);
        if (strlen($letters) >= 2) {
            break;
        }
    }
    if ($letters === '') {
        $letters = strtoupper(substr($value, 0, 2));
    }
    if (strlen($letters) === 1) {
        $letters .= $letters;
    }
    return substr($letters, 0, 2);
};

$sessionCliente = $sessionUser ?? ($_SESSION['cliente'] ?? null);
$sessionCliente = is_array($sessionCliente) ? $sessionCliente : null;

$clienteNombre = '-';
$clienteCedula = '-';
$clienteTelefono = '-';
$clienteEmail = '-';
$clienteStarted = '-';
$clientePerfil = '';
$clienteInitials = 'CL';

if ($sessionCliente) {
    $clienteNombre = trim((string)($sessionCliente['display_name'] ?? $sessionCliente['nombre'] ?? $sessionCliente['Nombre'] ?? ''));
    if ($clienteNombre === '') {
        $clienteNombre = '-';
    }

    $clienteCedula = trim((string)($sessionCliente['cedula'] ?? $sessionCliente['Cedula'] ?? ''));
    if ($clienteCedula === '') {
        $clienteCedula = '-';
    }

    $clienteTelefono = trim((string)($sessionCliente['telefono'] ?? $sessionCliente['Telefono'] ?? ''));
    if ($clienteTelefono === '') {
        $clienteTelefono = '-';
    }

    $clienteEmail = trim((string)($sessionCliente['email'] ?? $sessionCliente['Email'] ?? ''));
    if ($clienteEmail === '') {
        $clienteEmail = '-';
    }

    $clientePerfil = trim((string)($sessionCliente['perfil'] ?? $sessionCliente['Perfil'] ?? ''));
    if ($clientePerfil !== '') {
        $clientePerfil = str_replace('\\', '/', $clientePerfil);
    }

    $startedTs = $sessionCliente['session_started_at'] ?? $sessionCliente['started_at'] ?? null;
    if (!$startedTs && !empty($sessionCliente['expires_at'])) {
        $startedTs = (int)$sessionCliente['expires_at'] - (24 * 60 * 60);
    }
    if ($startedTs) {
        $startedTs = (int)$startedTs;
        if ($startedTs > 0) {
            $clienteStarted = date('d/m/Y H:i', $startedTs);
        }
    }

    $seed = $clienteNombre !== '-' ? $clienteNombre : ($clienteEmail !== '-' ? $clienteEmail : $clienteCedula);
    $clienteInitials = $computeInitials($seed);
}
?>
<div class="modal" role="dialog" aria-modal="true" aria-labelledby="account-modal-title" data-modal="account">
  <div class="modal__backdrop" data-modal-close></div>
  <div class="modal__dialog">
    <header class="modal__header">
      <div class="modal__header-text">
        <p class="modal__eyebrow">Perfil</p>
        <h2 id="account-modal-title">Mi Cuenta</h2>
      </div>
      <button type="button" class="modal__close" data-modal-close aria-label="Cerrar">&times;</button>
    </header>
    <div class="modal__body">
      <div class="account-header">
        <span class="account-avatar" aria-hidden="true">
          <?php if ($clientePerfil !== ''): ?>
            <img data-account-photo alt="Foto de perfil" src="<?php echo $escape($clientePerfil); ?>">
            <span class="account-avatar-fallback" data-account-initials hidden></span>
          <?php else: ?>
            <img data-account-photo alt="Foto de perfil" hidden>
            <span class="account-avatar-fallback" data-account-initials><?php echo $escape($clienteInitials); ?></span>
          <?php endif; ?>
          <button type="button" class="account-avatar-edit" data-account-photo-edit title="Cambiar foto">Editar</button>
          <input type="file" accept="image/*" data-account-file hidden>
        </span>
      </div>
      <div class="account-status" data-account-status hidden></div>
      <div class="account-details">
        <div class="account-detail">
          <div class="account-detail-text">
            <strong>Nombre:</strong>
            <span data-account-name><?php echo $escape($clienteNombre); ?></span>
          </div>
          <button type="button" class="account-detail-edit" data-account-field-trigger="nombre">Editar</button>
        </div>
        <div class="account-detail">
          <div class="account-detail-text">
            <strong>Cedula:</strong>
            <span data-account-cedula><?php echo $escape($clienteCedula); ?></span>
          </div>
          <button type="button" class="account-detail-edit" data-account-field-trigger="cedula">Editar</button>
        </div>
        <div class="account-detail">
          <div class="account-detail-text">
            <strong>Telefono:</strong>
            <span data-account-telefono><?php echo $escape($clienteTelefono); ?></span>
          </div>
          <button type="button" class="account-detail-edit" data-account-field-trigger="telefono">Editar</button>
        </div>
        <div class="account-detail">
          <div class="account-detail-text">
            <strong>Email:</strong>
            <span data-account-email><?php echo $escape($clienteEmail); ?></span>
          </div>
          <button type="button" class="account-detail-edit" data-account-field-trigger="email">Editar</button>
        </div>
        <div class="account-detail">
          <div class="account-detail-text">
            <strong>Iniciado:</strong>
            <span data-account-started><?php echo $escape($clienteStarted); ?></span>
          </div>
        </div>
      </div>
      <div class="account-field-modal" data-account-field-modal hidden>
        <div class="account-field-backdrop" data-account-field-backdrop data-account-field-close></div>
        <div class="account-field-dialog" role="dialog" aria-modal="true">
          <header class="account-field-header">
            <h3 data-account-field-label>Editar dato</h3>
            <button type="button" class="account-field-close" data-account-field-close aria-label="Cerrar">&times;</button>
          </header>
          <form class="account-field-form" data-account-field-form novalidate>
            <label class="account-field-input">
              <span data-account-field-label-text>Valor</span>
              <input type="text" data-account-field-input required>
            </label>
            <div class="account-field-status" data-account-field-status hidden></div>
            <div class="account-field-actions">
              <button type="button" class="btn btn-outline" data-account-field-cancel>Cancelar</button>
              <button type="submit" class="btn btn-accent" data-account-field-save>Guardar</button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>
<style>
  .account-header { display:flex; justify-content:center; margin-bottom:.6rem; }
  .account-avatar { position:relative; width:84px; height:84px; border-radius:50%; overflow:hidden; display:inline-grid; place-items:center; background:#1f2937; border:1px solid var(--border); box-shadow:0 6px 18px rgba(0,0,0,.25); }
  .account-avatar img { width:100%; height:100%; object-fit:cover; display:block; }
  .account-avatar-fallback { width:100%; height:100%; display:grid; place-items:center; color:#e5e7eb; font-weight:700; letter-spacing:.5px; }
  .account-avatar-edit { position:absolute; left:50%; top:80%; transform:translate(-50%, -50%); background:#111827; color:#e5e7eb; border:1px solid var(--border); padding:.2rem .45rem; font-size:.8rem; border-radius:999px; cursor:pointer; box-shadow:0 6px 16px rgba(0,0,0,.35); }
  .account-avatar-edit:hover { background:#0b1220; color:#c7d2fe; border-color:#4f46e5; }
  .account-details { display:grid; gap:.5rem; margin-bottom:.65rem; }
  .account-detail { display:flex; justify-content:space-between; align-items:center; gap:1rem; padding:.45rem .6rem; background:rgba(15,23,42,.45); border:1px solid rgba(148,163,184,.18); border-radius:.65rem; }
  .account-detail-text { display:flex; gap:.5rem; align-items:center; flex-wrap:wrap; font-size:.95rem; }
  .account-detail strong { color: var(--muted); font-weight:600; }
  .account-detail span { color: var(--text); }
  .account-detail-edit { background:none; border:1px solid rgba(148,163,184,.4); color:#cbd5f5; padding:.25rem .65rem; border-radius:.5rem; cursor:pointer; font-size:.8rem; }
  .account-detail-edit:hover { border-color:#4f46e5; color:#e0e7ff; }
  .account-status { margin-bottom:.5rem; font-size:.85rem; padding:.4rem .5rem; border-radius:.45rem; background:rgba(79,70,229,.12); color:#c7d2fe; }
  .account-status[data-variant="error"] { background:rgba(248,113,113,.15); color:#fca5a5; }
  .account-field-modal { position:fixed; inset:0; display:grid; place-items:center; z-index:10; }
  .account-field-modal[hidden] { display:none; }
  .account-field-backdrop { position:absolute; inset:0; background:rgba(15,23,42,.75); }
  .account-field-dialog { position:relative; width:min(320px, 90vw); background:#0f172a; border:1px solid rgba(148,163,184,.3); border-radius:.75rem; box-shadow:0 20px 50px rgba(15,23,42,.5); padding:1rem; display:grid; gap:.75rem; }
  .account-field-header { display:flex; justify-content:space-between; align-items:center; }
  .account-field-header h3 { margin:0; font-size:1.05rem; }
  .account-field-close { background:none; border:none; color:#cbd5f5; font-size:1.2rem; cursor:pointer; }
  .account-field-close:hover { color:#e0e7ff; }
  .account-field-form { display:grid; gap:.6rem; }
  .account-field-input { display:grid; gap:.35rem; }
  .account-field-input span { font-size:.85rem; color:var(--muted); }
  .account-field-input input { width:100%; padding:.55rem .65rem; border-radius:.55rem; border:1px solid rgba(148,163,184,.35); background:rgba(15,23,42,.6); color:var(--text); }
  .account-field-input input:focus { outline:none; border-color:#4f46e5; box-shadow:0 0 0 1px rgba(79,70,229,.35); }
  .account-field-status { font-size:.82rem; padding:.35rem .4rem; border-radius:.45rem; background:rgba(79,70,229,.12); color:#c7d2fe; }
  .account-field-status[data-variant="error"] { background:rgba(248,113,113,.12); color:#fca5a5; }
  .account-field-actions { display:flex; justify-content:flex-end; gap:.5rem; }
</style>
<?php
return trim(ob_get_clean());
?>
