<?php
/**
 * Agenduy - Modal de registro
 * Apunta al nuevo endpoint unificado: /admin/api/register.php
 */
$config = require dirname(__DIR__, 2) . '/Core/bootstrap.php';
use Agenduy\Core\Database;

$db = Database::getInstance();
$rubros = $db->fetchAll('SELECT id_rubro, nombre, tipo FROM rubros WHERE activo = 1 ORDER BY orden ASC, nombre COLLATE NOCASE ASC');
$plans = $db->fetchAll('SELECT * FROM memberships WHERE activo = 1 ORDER BY precio ASC, id_membership ASC');
$planDestacado = $plans[0] ?? null;
$registerDays = [
  'lunes' => 'Lunes', 'martes' => 'Martes', 'miercoles' => 'Mi&eacute;rcoles',
  'jueves' => 'Jueves', 'viernes' => 'Viernes', 'sabado' => 'S&aacute;bado', 'domingo' => 'Domingo',
];
$serviceDurations = [15, 30, 45, 60, 75, 90];
?>
<div id="modal-registro" class="u-modal hidden" role="dialog" aria-modal="true" aria-labelledby="modal-registro-title">
  <div class="u-modal__overlay" data-registro-dismiss></div>
  <div class="u-modal__dialog reg-modal">
      <div class="u-modal__content">
        <header class="reg-modal__header">
          <div>
            <p class="reg-badge"><?= (int)($planDestacado['trial_dias'] ?? 30) ?> d&iacute;as gratis</p>
            <h3 id="modal-registro-title">Registra tu negocio con nosotros y comienza a agendar a tus clientes</h3>
          <p class="reg-subtitle">Completa los datos en cuatro pasos. No cobramos el primer mes.</p>
          <p class="reg-plan" data-reg-plan><?= $planDestacado ? 'Plan ' . htmlspecialchars($planDestacado['nombre'], ENT_QUOTES, 'UTF-8') . ' - ' . htmlspecialchars($planDestacado['moneda'], ENT_QUOTES, 'UTF-8') . ' ' . number_format((float)$planDestacado['precio'], 2) . '/mes' : 'Selecciona un rubro para ver el plan disponible.' ?></p>
          <p class="reg-plan-note"><i class="bx bx-credit-card"></i>No necesitas tarjeta de credito</p>
        </div>
        <button type="button" class="reg-close" aria-label="Cerrar registro" data-registro-dismiss>&times;</button>
      </header>

      <ol class="reg-stepper" data-registro-stepper>
        <li data-step-indicator="0" class="active"><span>1</span><small>Dueño</small></li>
        <li data-step-indicator="1"><span>2</span><small>Negocio</small></li>
        <li data-step-indicator="2"><span>3</span><small>Servicios</small></li>
        <li data-step-indicator="3"><span>4</span><small>Horarios</small></li>
      </ol>

      <form id="registro-form" class="reg-form" novalidate>
        <input type="hidden" name="rubro_id" />
        <input type="hidden" name="plan_id" value="<?= (int)($planDestacado['id_membership'] ?? 0) ?>" />
        <input type="hidden" name="plan_nombre" value="<?= htmlspecialchars($planDestacado['nombre'] ?? '', ENT_QUOTES, 'UTF-8') ?>" />

        <section class="reg-step" data-step-panel="0" aria-labelledby="reg-step-1-title">
          <h4 id="reg-step-1-title">Datos del Dueño del Negocio</h4>
          <div class="reg-grid">
            <label class="reg-field"><span>Nombre</span><input type="text" name="owner_name" required></label>
            <label class="reg-field"><span>Apellido</span><input type="text" name="owner_lastname" required></label>
            <label class="reg-field"><span>Correo electr&oacute;nico</span><input type="email" name="owner_email" required></label>
            <label class="reg-field"><span>C&eacute;dula</span><input type="text" name="owner_cedula" required></label>
            <label class="reg-field"><span>Contrase&ntilde;a</span><input type="password" name="owner_password" minlength="8" required></label>
          </div>
        </section>

        <section class="reg-step" data-step-panel="1" aria-labelledby="reg-step-2-title" hidden>
          <h4 id="reg-step-2-title">Datos de tu Negocio</h4>
          <div class="reg-grid">
            <label class="reg-field reg-field--full">
              <span>Tel&eacute;fono</span>
              <div class="reg-phone-field">
                <select name="business_phone_country" data-reg-phone-country required>
                  <option value="UY" selected>Uruguay (+598)</option>
                  <option value="AR">Argentina (+54)</option>
                  <option value="BR">Brasil (+55)</option>
                  <option value="CL">Chile (+56)</option>
                  <option value="PY">Paraguay (+595)</option>
                </select>
                <input type="tel" name="business_phone" inputmode="numeric" data-reg-phone-input
                  pattern="0?9[0-9]{7}" placeholder="Ej: 092365135" required>
              </div>
              <small data-reg-phone-hint>Ingresá un celular uruguayo (09 + 7 d&iacute;gitos)</small>
            </label>
            <label class="reg-field"><span>Nombre del Negocio</span><input type="text" name="business_name" required></label>
            <label class="reg-field"><span>RUT <small>(opcional)</small></span><input type="text" name="business_rut"></label>
            <label class="reg-field"><span>Pa&iacute;s</span>
              <select name="business_country" required>
                <option value="">Selecciona un pa&iacute;s</option>
                <option value="UY">Uruguay</option>
                <option value="AR">Argentina</option>
                <option value="BR">Brasil</option>
                <option value="CL">Chile</option>
                <option value="PY">Paraguay</option>
              </select>
            </label>
            <label class="reg-field"><span>Ciudad</span><input type="text" name="business_city" required></label>
            <label class="reg-field"><span>Calle y n&uacute;mero</span><input type="text" name="business_street" required></label>
            <label class="reg-field reg-field--full">
              <span>Rubro</span>
              <select name="business_rubro" required>
                <option value="">Selecciona un rubro</option>
                <?php foreach ($rubros as $r): ?>
                  <option value="<?= (int)$r['id_rubro'] ?>"><?= htmlspecialchars($r['nombre'], ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <?php if (count($plans) > 1): ?>
            <label class="reg-field reg-field--full">
              <span>Plan</span>
              <select name="business_plan" required>
                <?php foreach ($plans as $plan): ?>
                  <option value="<?= (int)$plan['id_membership'] ?>">
                    <?= htmlspecialchars($plan['nombre'], ENT_QUOTES, 'UTF-8') ?>
                    — <?= htmlspecialchars($plan['moneda'], ENT_QUOTES, 'UTF-8') ?>
                    <?= number_format((float)$plan['precio'], 2) ?>/mes
                  </option>
                <?php endforeach; ?>
              </select>
            </label>
            <?php endif; ?>
          </div>
        </section>

        <section class="reg-step" data-step-panel="2" aria-labelledby="reg-step-3-title" hidden>
          <h4 id="reg-step-3-title">Servicios de tu Negocio</h4>
          <p class="reg-hint">Agreg&aacute; los servicios que ofrecer&aacute;s. Podr&aacute;s sumar m&aacute;s desde el panel.</p>
          <div class="reg-collection-card">
            <div class="admin-form reg-service-form" data-reg-service-form>
              <div class="admin-form__grid">
                <label class="admin-form__field"><span class="admin-form__label">Nombre</span>
                  <input type="text" data-reg-service-field="Nombre" maxlength="120" placeholder="Ej: Corte cl&aacute;sico" required>
                </label>
                <label class="admin-form__field"><span class="admin-form__label">Duraci&oacute;n</span>
                  <select data-reg-service-field="Duracion" required>
                    <option value="">Selecciona duraci&oacute;n</option>
                    <?php foreach ($serviceDurations as $min): ?>
                      <option value="<?= (int)$min ?>"><?= (int)$min ?> min</option>
                    <?php endforeach; ?>
                  </select>
                </label>
              </div>
              <div class="admin-form__grid">
                <label class="admin-form__field"><span class="admin-form__label">Precio</span>
                  <input type="number" inputmode="decimal" min="0" max="99999" step="0.01"
                    data-reg-service-field="Precio" placeholder="Ej: 450" required>
                </label>
              </div>
              <footer class="reg-collection-actions">
                <button type="button" class="reg-btn reg-btn--accent" data-reg-service-add>Agregar servicio</button>
                <button type="button" class="reg-btn reg-btn--ghost" data-reg-service-reset>Limpiar</button>
              </footer>
            </div>
            <p class="admin-form__error" data-reg-service-error hidden></p>
            <div class="reg-collection" data-reg-service-list aria-live="polite"></div>
          </div>
        </section>

        <section class="reg-step" data-step-panel="3" aria-labelledby="reg-step-4-title" hidden>
          <div class="reg-hours" data-reg-hours>
            <section class="reg-hours__group">
              <h5 class="reg-hours__title">Zona horaria</h5>
              <p class="reg-hours__hint" data-reg-hours-summary>Detectando la zona horaria de tu dispositivo...</p>
              <input type="hidden" name="hours_timezone" data-reg-hours-timezone>
            </section>
            <section class="reg-hours__group">
              <h5 class="reg-hours__title">Configura los Horarios de tu Negocio</h5>
              <div class="reg-hours-days">
                <?php foreach ($registerDays as $key => $label): ?>
                <fieldset class="reg-hours-day" data-reg-hours-day="<?= h($key) ?>">
                  <legend><?= $label ?></legend>
                  <label class="reg-hours-toggle">
                    <input type="checkbox" data-reg-hours-open>
                    <span>Abierto</span>
                  </label>
                  <div class="reg-hours-slots">
                    <label><span>Inicio</span><input type="time" step="300" data-reg-hours-start></label>
                    <label><span>Fin</span><input type="time" step="300" data-reg-hours-end></label>
                  </div>
                </fieldset>
                <?php endforeach; ?>
              </div>
            </section>
          </div>
          <label class="reg-checkbox">
            <input type="checkbox" name="terms" required />
            <span>Acepto los t&eacute;rminos y autorizo la creaci&oacute;n de mi cuenta.</span>
          </label>
          <div class="reg-status" data-reg-status hidden></div>
        </section>
        <footer class="reg-footer">
          <p class="reg-error" data-form-error hidden></p>
          <div class="reg-actions">
            <button type="button" class="reg-btn reg-btn--ghost" data-step-prev disabled>Anterior</button>
            <button type="button" class="reg-btn reg-btn--primary" data-step-next>Siguiente</button>
            <button type="button" class="reg-btn reg-btn--accent" data-reg-submit hidden>Finalizar registro</button>
          </div>
        </footer>
      </form>
    </div>
    <div class="reg-progress hidden" data-reg-progress role="status" aria-live="polite">
      <div class="reg-progress__card">
        <div class="reg-progress__bar" aria-hidden="true">
          <span class="reg-progress__bar-fill" data-reg-progress-bar></span>
        </div>
        <p class="reg-progress__message" data-reg-progress-message>Estamos Preparando el sitio para tu Negocio, Por Favor Espera</p>
      </div>
    </div>
  </div>
</div>
