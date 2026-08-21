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
use Agenduy\Core\Security;

Auth::start();
if (!Auth::check() || Auth::role() !== 'commerce_admin') {
    header('Location: ' . Auth::loginUrl());
    exit;
}
Security::sendNoStoreHeaders();

$idCommerce = (int)Auth::commerceId();
$db = Database::getInstance();
$commerce = $db->fetchOne('SELECT * FROM commerces WHERE id_commerce = :id LIMIT 1', [':id' => $idCommerce]);
if (!$commerce) {
    echo 'Comercio no encontrado.';
    exit;
}

if (!CommerceSetup::needsOnboarding($commerce)) {
    header('Location: ' . (Auth::dashboardUrl(Auth::user() ?? []) ?? 'commerce_panel.php'));
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
            'servicios' => $_POST['servicios'] ?? [],
        ]);
        $slug = trim((string)($commerce['slug'] ?? ''));
        header('Location: ' . CommercePanel::dashboardUrlForSlug($slug, 'resumen', ['setup' => 'ok']));
        exit;
    } catch (Throwable $e) {
        $flash = ['type' => 'error', 'msg' => $e->getMessage()];
    }
}

$userName = trim((string)(Auth::user()['nombre'] ?? 'dueño'));
$departamentosUy = CommerceSetup::URUGUAY_DEPARTMENTS;

$isStore = false;
$rubro = $db->fetchOne('SELECT tipo FROM rubros WHERE id_rubro = :id', [':id' => $commerce['id_rubro'] ?? 0]);
if ($rubro && strtolower($rubro['tipo']) === 'tienda') {
    $isStore = true;
}
$itemName = $isStore ? 'Producto' : 'Servicio';
$itemsName = $isStore ? 'Productos' : 'Servicios';
$selectedDepartamento = (string)($snapshot['ciudad'] ?? '');
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
.setup-wrap { max-width: 760px; margin: 2rem auto; padding: 0 1rem 3rem; }
.setup-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 1.5rem;
    box-shadow: var(--shadow);
}
.setup-card h1 { margin: 0 0 .35rem; font-size: 1.45rem; }
.setup-card p.lead { color: var(--muted); margin: 0 0 1.25rem; }
.setup-accordion {
    background: var(--surface-2);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    margin-bottom: 1rem;
    overflow: hidden;
}
.setup-accordion summary {
    padding: 1rem 1.25rem;
    font-weight: 600;
    cursor: pointer;
    background: rgba(0,0,0,.02);
    list-style: none;
    display: flex;
    justify-content: space-between;
    align-items: center;
    color: var(--text);
}
.setup-accordion summary::-webkit-details-marker { display: none; }
.setup-accordion summary::after {
    content: '+';
    font-size: 1.2rem;
    font-weight: normal;
    color: var(--muted);
    transition: transform 0.2s;
}
.setup-accordion[open] summary {
    border-bottom: 1px solid var(--border);
    background: rgba(99,102,241,.08);
    color: #6366f1;
}
.setup-accordion[open] summary::after {
    content: '−';
}
.setup-accordion-content {
    padding: 1.25rem;
}
.setup-grid { display: grid; gap: .9rem; }
.setup-grid label { display: grid; gap: .35rem; font-size: .9rem; }
.setup-grid input,
.setup-grid select,
.service-card input {
    width: 100%; padding: .65rem .75rem; border-radius: 8px;
    border: 1px solid var(--border); background: var(--bg); color: var(--text);
    min-height: 42px;
}
.setup-grid select { appearance: auto; }
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

/* Servicios list styles */
.services-list { display: flex; flex-direction: column; gap: .75rem; margin-bottom: .85rem; }
.service-card {
    background: var(--surface-2);
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: .85rem;
    display: grid;
    grid-template-columns: minmax(220px, 1.35fr) minmax(120px, .65fr) minmax(150px, .8fr) 40px;
    gap: .65rem;
    align-items: end;
    position: relative;
}
.service-col { min-width: 0; display: flex; flex-direction: column; gap: .25rem; }
.service-col.name-col { min-width: 0; }
.setup-sublabel { font-size: .78rem; font-weight: 600; color: var(--muted); text-transform: uppercase; letter-spacing: .03em; }
.btn-remove-service {
    background: transparent;
    border: 1px solid var(--border);
    color: var(--muted);
    border-radius: 6px;
    width: 32px;
    height: 36px;
    font-size: 1.2rem;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: all .2s ease;
}
.btn-remove-service:hover { background: rgba(220,38,38,.15); color: #f87171; border-color: rgba(220,38,38,.3); }
@media (max-width: 540px) {
    .service-card { grid-template-columns: 1fr; }
    .btn-remove-service { width: 100%; }
}
@media (min-width: 541px) and (max-width: 700px) {
    .service-card { grid-template-columns: 1fr 1fr 40px; }
    .service-col.name-col { grid-column: 1 / -1; }
}
</style>
</head>
<body>
<div class="setup-wrap">
    <?= \Agenduy\Core\AdminBrand::platformBrandHeaderHtml('Configuración inicial') ?>
    <div class="setup-card">
        <h1>Hola, <?= htmlspecialchars($userName, ENT_QUOTES, 'UTF-8') ?></h1>
        <p class="lead">Completá los datos de tu negocio para activar tu página pública y recibir reservas.</p>

        <form id="setup-form" method="post" novalidate>
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">

            <details class="setup-accordion" open data-step="1">
                <summary>1. Info del Negocio</summary>
                <div class="setup-accordion-content">
                    <div class="setup-grid">
                        <label>
                            <span>Nombre del negocio *</span>
                            <input type="text" name="nombre" required maxlength="120"
                                   value="<?= htmlspecialchars((string)$snapshot['nombre'], ENT_QUOTES, 'UTF-8') ?>"
                                   placeholder="Ej: Consultorio Lucas Iglesias">
                        </label>
                        <label>
                            <span>Departamento *</span>
                            <select name="ciudad" required>
                                <option value="">Seleccioná un departamento</option>
                                <?php foreach ($departamentosUy as $departamento): ?>
                                    <option value="<?= htmlspecialchars($departamento, ENT_QUOTES, 'UTF-8') ?>" <?= $selectedDepartamento === $departamento ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($departamento, ENT_QUOTES, 'UTF-8') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>
                            <span>Dirección *</span>
                            <input type="text" name="calle" required maxlength="160"
                                   value="<?= htmlspecialchars((string)$snapshot['calle'], ENT_QUOTES, 'UTF-8') ?>"
                                   placeholder="Calle y número">
                        </label>
                    </div>
                </div>
            </details>

            <details class="setup-accordion" data-step="2">
                <summary>2. Contacto</summary>
                <div class="setup-accordion-content">
                    <div class="setup-grid">
                        <label>
                            <span>Teléfono / WhatsApp *</span>
                            <input type="tel" name="telefono" required maxlength="30"
                                   value="<?= htmlspecialchars((string)$snapshot['telefono'], ENT_QUOTES, 'UTF-8') ?>"
                                   placeholder="099 123 456">
                        </label>
                    </div>
                </div>
            </details>

            <details class="setup-accordion" data-step="3">
                <summary>3. <?= htmlspecialchars($itemsName, ENT_QUOTES, 'UTF-8') ?></summary>
                <div class="setup-accordion-content">
                    <div style="margin-bottom: 1rem;">
                        <p class="setup-hint" style="margin: 0;">Configurá los <?= htmlspecialchars(strtolower($itemsName), ENT_QUOTES, 'UTF-8') ?> que ofrecerá tu comercio (nombre, duración y precio).</p>
                    </div>

                    <div class="services-list" id="services-container">
                        <?php 
                        $servicios = is_array($snapshot['servicios'] ?? null) ? $snapshot['servicios'] : [];
                        if (empty($servicios)) {
                            $servicios = [['id_service' => 0, 'nombre' => ($isStore ? 'Producto de ejemplo' : 'Consulta'), 'duracion_min' => ($isStore ? 0 : 30), 'precio' => 0]];
                        }
                        foreach ($servicios as $idx => $srv):
                        ?>
                        <div class="service-card" data-service-card>
                            <input type="hidden" name="servicios[<?= $idx ?>][id_service]" value="<?= (int)($srv['id_service'] ?? 0) ?>">
                            <div class="service-col name-col">
                                <label class="setup-sublabel">Nombre del <?= htmlspecialchars(strtolower($itemName), ENT_QUOTES, 'UTF-8') ?> *</label>
                                <input type="text" name="servicios[<?= $idx ?>][nombre]" required maxlength="80"
                                       value="<?= htmlspecialchars((string)($srv['nombre'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                       placeholder="<?= $isStore ? 'Ej: Camiseta, Taza, etc.' : 'Ej: Consulta, Corte, Masaje' ?>">
                            </div>
                            <div class="service-col" <?= $isStore ? 'hidden' : '' ?>>
                                <label class="setup-sublabel">Duración (min) *</label>
                                <input type="number" name="servicios[<?= $idx ?>][duracion_min]" required min="<?= $isStore ? '0' : '5' ?>" max="480"
                                       value="<?= (int)($srv['duracion_min'] ?? ($isStore ? 0 : 30)) ?>" placeholder="<?= $isStore ? '0' : '30' ?>">
                            </div>
                            <div class="service-col">
                                <label class="setup-sublabel">Precio ($) *</label>
                                <input type="number" name="servicios[<?= $idx ?>][precio]" required min="0" step="0.01"
                                       value="<?= (float)($srv['precio'] ?? 0) ?>" placeholder="0">
                            </div>
                            <button type="button" class="btn-remove-service" title="Eliminar <?= htmlspecialchars(strtolower($itemName), ENT_QUOTES, 'UTF-8') ?>" style="align-self: flex-end; margin-bottom: 0.35rem;">&times;</button>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <button type="button" class="btn btn-ghost btn-sm" id="add-service-btn" style="margin-bottom: 1rem;">+ Agregar otro <?= htmlspecialchars(strtolower($itemName), ENT_QUOTES, 'UTF-8') ?></button>

                    <div style="border-top: 1px dashed var(--border); padding-top: 0.75rem;">
                        <p class="setup-hint" style="margin: 0 0 0.2rem;">Rubro actual: <strong><?= htmlspecialchars((string)$snapshot['rubro'], ENT_QUOTES, 'UTF-8') ?></strong></p>
                        <p class="setup-hint" style="margin: 0;">Tu página pública: <strong><?= htmlspecialchars((string)$snapshot['slug'], ENT_QUOTES, 'UTF-8') ?></strong></p>
                    </div>
                </div>
            </details>

            <div class="setup-actions">
                <div style="margin-left:auto; display:flex; gap:.5rem;">
                    <button type="submit" class="btn btn-primary" id="setup-save">Finalizar Configuración</button>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="setup-toast" id="setup-toast" role="status" aria-live="polite"></div>

<script>
(function () {
  var form = document.getElementById('setup-form');
  var toast = document.getElementById('setup-toast');

  var servicesContainer = document.getElementById('services-container');
  var addServiceBtn = document.getElementById('add-service-btn');
  var serviceIndex = servicesContainer ? servicesContainer.querySelectorAll('.service-card').length : 0;

  if (addServiceBtn && servicesContainer) {
    addServiceBtn.addEventListener('click', function () {
      serviceIndex++;
      var card = document.createElement('div');
      card.className = 'service-card';
      card.setAttribute('data-service-card', '');
      card.innerHTML = 
        '<input type="hidden" name="servicios[' + serviceIndex + '][id_service]" value="0">' +
        '<div class="service-col name-col">' +
          '<label class="setup-sublabel">Nombre del servicio *</label>' +
          '<input type="text" name="servicios[' + serviceIndex + '][nombre]" required maxlength="80" placeholder="Ej: Servicio adicional">' +
        '</div>' +
        '<div class="service-col">' +
          '<label class="setup-sublabel">Duración (min) *</label>' +
          '<input type="number" name="servicios[' + serviceIndex + '][duracion_min]" required min="5" max="480" value="30" placeholder="30">' +
        '</div>' +
        '<div class="service-col">' +
          '<label class="setup-sublabel">Precio ($) *</label>' +
          '<input type="number" name="servicios[' + serviceIndex + '][precio]" required min="0" step="0.01" value="0" placeholder="0">' +
        '</div>' +
        '<button type="button" class="btn-remove-service" title="Eliminar servicio">&times;</button>';
      servicesContainer.appendChild(card);
    });

    servicesContainer.addEventListener('click', function (e) {
      if (e.target && e.target.classList.contains('btn-remove-service')) {
        var cards = servicesContainer.querySelectorAll('.service-card');
        if (cards.length <= 1) {
          notify('Debes mantener al menos 1 servicio.', 'error');
          return;
        }
        var card = e.target.closest('.service-card');
        if (card) card.remove();
      }
    });
  }

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

  function validateAll() {
    var accordions = document.querySelectorAll('.setup-accordion');
    var isValid = true;

    for (var i = 0; i < accordions.length; i++) {
      var acc = accordions[i];
      var fields = acc.querySelectorAll('input[required], select[required], textarea[required]');
      
      for (var j = 0; j < fields.length; j++) {
        var field = fields[j];
        if (!field.value.trim()) {
          acc.open = true; // Open the accordion with the error
          field.focus();
          notify('Completá los campos obligatorios.', 'error');
          return false;
        }

        if (field.name === 'telefono') {
          var digits = (field.value || '').replace(/\D+/g, '');
          if (digits.length < 8) {
            acc.open = true;
            field.focus();
            notify('Ingresá un teléfono válido.', 'error');
            return false;
          }
        }
      }
    }
    return true;
  }

  form.addEventListener('submit', function (e) {
    if (!validateAll()) {
      e.preventDefault();
    }
  });

  // Only open first accordion by default, close others if JS is active, 
  // though we set open on the first in HTML already.
  notify('Completá tu negocio rápidamente.', 'success');

  <?php if ($flash['type'] === 'error' && $flash['msg'] !== ''): ?>
  notify(<?= json_encode($flash['msg'], JSON_UNESCAPED_UNICODE) ?>, 'error');
  <?php endif; ?>
})();
</script>
</body>
</html>
