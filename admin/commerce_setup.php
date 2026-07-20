<?php
/**
 * Agendarte - Completar datos del negocio (post-registro Google)
 */
declare(strict_types=1);

$config = require __DIR__ . '/../src/Core/bootstrap.php';

use Agenduy\Core\Auth;
use Agenduy\Core\CSRF;
use Agenduy\Core\CommercePanel;
use Agenduy\Core\CommerceSetup;
use Agenduy\Core\Database;

Auth::start();
if (!Auth::check() || Auth::role() !== 'commerce_admin') {
    header('Location: login.php');
    exit;
}

$idCommerce = (int)Auth::commerceId();
$db = Database::getInstance();
$commerce = $db->fetchOne('SELECT * FROM commerces WHERE id_commerce = :id LIMIT 1', [':id' => $idCommerce]);
if (!$commerce) {
    echo 'Comercio no encontrado.';
    exit;
}

if (!CommerceSetup::needsOnboarding($commerce)) {
    header('Location: ' . (Auth::dashboardUrl(Auth::user() ?? []) ?? 'commerce_dashboard.php'));
    exit;
}

$csrf = CSRF::generate('commerce_setup');
$snapshot = CommerceSetup::snapshot($idCommerce);
$flash = ['type' => '', 'msg' => ''];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    CSRF::checkRequest('commerce_setup');
    try {
        CommerceSetup::complete($idCommerce, [
            'nombre'   => $_POST['nombre'] ?? '',
            'telefono' => $_POST['telefono'] ?? '',
            'whatsapp' => $_POST['whatsapp'] ?? '',
            'ciudad'   => $_POST['ciudad'] ?? '',
            'calle'    => $_POST['calle'] ?? '',
            'servicio' => $_POST['servicio'] ?? '',
        ]);
        $slug = trim((string)($commerce['slug'] ?? ''));
        header('Location: ' . CommercePanel::dashboardUrlForSlug($slug, 'resumen', ['setup' => 'ok']));
        exit;
    } catch (Throwable $e) {
        $flash = ['type' => 'error', 'msg' => $e->getMessage()];
    }
}

$userName = trim((string)(Auth::user()['nombre'] ?? 'dueño'));
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Configurá tu negocio · Agendarte</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="assets/css/admin.css">
<link rel="stylesheet" href="<?= htmlspecialchars(\Agenduy\Core\AdminBrand::cssUrl(), ENT_QUOTES, 'UTF-8') ?>">
<link rel="icon" type="image/png" href="<?= htmlspecialchars(\Agenduy\Core\AdminBrand::faviconUrl(), ENT_QUOTES, 'UTF-8') ?>">
<meta name="theme-color" content="#7c3aed">
<style>
.setup-wrap { max-width: 640px; margin: 2rem auto; padding: 0 1rem 3rem; }
.setup-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 1.5rem;
    box-shadow: var(--shadow);
}
.setup-card h1 { margin: 0 0 .35rem; font-size: 1.45rem; }
.setup-card p.lead { color: var(--muted); margin: 0 0 1.25rem; }
.setup-steps {
    display: flex; gap: .5rem; margin-bottom: 1.25rem; flex-wrap: wrap;
}
.setup-step {
    flex: 1; min-width: 120px; padding: .55rem .75rem; border-radius: 8px;
    background: var(--surface-2); color: var(--muted); font-size: .85rem; text-align: center;
}
.setup-step.is-active { background: rgba(99,102,241,.18); color: #c7d2fe; border: 1px solid rgba(99,102,241,.35); }
.setup-step.is-done { color: var(--ok); }
.setup-panel[hidden] { display: none !important; }
.setup-grid { display: grid; gap: .9rem; }
.setup-grid label { display: grid; gap: .35rem; font-size: .9rem; }
.setup-grid input {
    width: 100%; padding: .65rem .75rem; border-radius: 8px;
    border: 1px solid var(--border); background: var(--bg); color: var(--text);
}
.setup-actions { display: flex; justify-content: space-between; gap: .75rem; margin-top: 1.25rem; }
.setup-toast {
    position: fixed; right: 1rem; bottom: 1rem; z-index: 50;
    min-width: 240px; max-width: 360px; padding: .85rem 1rem;
    border-radius: 10px; background: var(--surface-2); border: 1px solid var(--border);
    box-shadow: var(--shadow); transform: translateY(120%); opacity: 0;
    transition: transform .25s ease, opacity .25s ease;
}
.setup-toast.is-visible { transform: translateY(0); opacity: 1; }
.setup-toast.is-error { border-color: rgba(220,38,38,.45); }
.setup-toast.is-success { border-color: rgba(22,163,74,.45); }
.setup-hint { font-size: .82rem; color: var(--muted); margin-top: .25rem; }
</style>
</head>
<body>
<div class="setup-wrap">
    <?= \Agenduy\Core\AdminBrand::platformBrandHeaderHtml('Configuración inicial') ?>
    <div class="setup-card">
        <h1>Hola, <?= htmlspecialchars($userName, ENT_QUOTES, 'UTF-8') ?></h1>
        <p class="lead">Completá los datos de tu negocio para activar tu página pública y recibir reservas.</p>

        <div class="setup-steps" aria-hidden="true">
            <div class="setup-step is-active" data-step-indicator="1">1. Negocio</div>
            <div class="setup-step" data-step-indicator="2">2. Contacto</div>
            <div class="setup-step" data-step-indicator="3">3. Servicio</div>
        </div>

        <form id="setup-form" method="post" novalidate>
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">

            <div class="setup-panel" data-step="1">
                <div class="setup-grid">
                    <label>
                        <span>Nombre del negocio *</span>
                        <input type="text" name="nombre" required maxlength="120"
                               value="<?= htmlspecialchars((string)$snapshot['nombre'], ENT_QUOTES, 'UTF-8') ?>"
                               placeholder="Ej: Consultorio Lucas Iglesias">
                    </label>
                    <label>
                        <span>Ciudad *</span>
                        <input type="text" name="ciudad" required maxlength="80"
                               value="<?= htmlspecialchars((string)$snapshot['ciudad'], ENT_QUOTES, 'UTF-8') ?>"
                               placeholder="Montevideo">
                    </label>
                    <label>
                        <span>Dirección *</span>
                        <input type="text" name="calle" required maxlength="160"
                               value="<?= htmlspecialchars((string)$snapshot['calle'], ENT_QUOTES, 'UTF-8') ?>"
                               placeholder="Calle y número">
                    </label>
                </div>
            </div>

            <div class="setup-panel" data-step="2" hidden>
                <div class="setup-grid">
                    <label>
                        <span>Teléfono / WhatsApp *</span>
                        <input type="tel" name="telefono" required maxlength="30"
                               value="<?= htmlspecialchars((string)$snapshot['telefono'], ENT_QUOTES, 'UTF-8') ?>"
                               placeholder="099 123 456">
                    </label>
                    <label>
                        <span>WhatsApp (opcional)</span>
                        <input type="tel" name="whatsapp" maxlength="30"
                               value="<?= htmlspecialchars((string)$snapshot['whatsapp'], ENT_QUOTES, 'UTF-8') ?>"
                               placeholder="Si es distinto al teléfono">
                        <span class="setup-hint">Si lo dejás vacío usamos el mismo teléfono.</span>
                    </label>
                </div>
            </div>

            <div class="setup-panel" data-step="3" hidden>
                <div class="setup-grid">
                    <label>
                        <span>Primer servicio *</span>
                        <input type="text" name="servicio" required maxlength="80"
                               value="<?= htmlspecialchars((string)$snapshot['servicio'], ENT_QUOTES, 'UTF-8') ?>"
                               placeholder="Ej: Consulta, Corte, Sesión">
                    </label>
                    <p class="setup-hint">Rubro actual: <strong><?= htmlspecialchars((string)$snapshot['rubro'], ENT_QUOTES, 'UTF-8') ?></strong>.
                        Podés agregar más servicios desde el panel después.</p>
                    <p class="setup-hint">Tu página pública: <strong><?= htmlspecialchars((string)$snapshot['slug'], ENT_QUOTES, 'UTF-8') ?></strong></p>
                </div>
            </div>

            <div class="setup-actions">
                <button type="button" class="btn btn-ghost btn-sm" id="setup-back" hidden>Atrás</button>
                <div style="margin-left:auto; display:flex; gap:.5rem;">
                    <button type="button" class="btn btn-primary btn-sm" id="setup-next">Siguiente</button>
                    <button type="submit" class="btn btn-primary btn-sm" id="setup-save" hidden>Guardar y entrar al panel</button>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="setup-toast" id="setup-toast" role="status" aria-live="polite"></div>

<script>
(function () {
  var step = 1;
  var maxStep = 3;
  var panels = Array.prototype.slice.call(document.querySelectorAll('[data-step]'));
  var indicators = Array.prototype.slice.call(document.querySelectorAll('[data-step-indicator]'));
  var backBtn = document.getElementById('setup-back');
  var nextBtn = document.getElementById('setup-next');
  var saveBtn = document.getElementById('setup-save');
  var form = document.getElementById('setup-form');
  var toast = document.getElementById('setup-toast');

  function notify(msg, type) {
    if (!toast) return;
    toast.textContent = msg || '';
    toast.className = 'setup-toast is-visible' + (type === 'error' ? ' is-error' : type === 'success' ? ' is-success' : '');
    clearTimeout(notify._t);
    notify._t = setTimeout(function () {
      toast.classList.remove('is-visible');
    }, 4200);
  }

  window.AdminNotify = notify;

  function visiblePanel(num) {
    panels.forEach(function (panel) {
      panel.hidden = panel.getAttribute('data-step') !== String(num);
    });
    indicators.forEach(function (el) {
      var n = Number(el.getAttribute('data-step-indicator'));
      el.classList.toggle('is-active', n === num);
      el.classList.toggle('is-done', n < num);
    });
    backBtn.hidden = num <= 1;
    nextBtn.hidden = num >= maxStep;
    saveBtn.hidden = num < maxStep;
  }

  function validateStep(num) {
    var panel = document.querySelector('[data-step="' + num + '"]');
    if (!panel) return true;
    var fields = panel.querySelectorAll('input[required]');
    for (var i = 0; i < fields.length; i++) {
      if (!fields[i].value.trim()) {
        fields[i].focus();
        notify('Completá los campos obligatorios.', 'error');
        return false;
      }
    }
    if (num === 2) {
      var tel = form.querySelector('[name="telefono"]');
      var digits = (tel.value || '').replace(/\D+/g, '');
      if (digits.length < 8) {
        tel.focus();
        notify('Ingresá un teléfono válido.', 'error');
        return false;
      }
    }
    return true;
  }

  nextBtn.addEventListener('click', function () {
    if (!validateStep(step)) return;
    step = Math.min(maxStep, step + 1);
    visiblePanel(step);
    notify('Paso ' + step + ' de ' + maxStep, 'success');
  });

  backBtn.addEventListener('click', function () {
    step = Math.max(1, step - 1);
    visiblePanel(step);
  });

  form.addEventListener('submit', function (e) {
    if (!validateStep(1) || !validateStep(2) || !validateStep(3)) {
      e.preventDefault();
    }
  });

  visiblePanel(step);
  notify('Completá tu negocio en 3 pasos rápidos.', 'success');

  <?php if ($flash['type'] === 'error' && $flash['msg'] !== ''): ?>
  notify(<?= json_encode($flash['msg'], JSON_UNESCAPED_UNICODE) ?>, 'error');
  <?php endif; ?>
})();
</script>
</body>
</html>
