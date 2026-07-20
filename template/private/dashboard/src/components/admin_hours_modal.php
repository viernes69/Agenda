<?php
ob_start();
$days = [
  'lunes' => 'Lunes',
  'martes' => 'Martes',
  'miercoles' => 'Miercoles',
  'jueves' => 'Jueves',
  'viernes' => 'Viernes',
  'sabado' => 'Sabado',
  'domingo' => 'Domingo',
];
?>
<div class="modal" role="dialog" aria-modal="true" aria-labelledby="admin-config-hours-title" data-admin-modal="config-hours" hidden>
  <div class="modal__backdrop" data-admin-config-hours-close></div>
  <div class="modal__dialog modal__dialog--lg">
    <header class="modal__header">
      <div class="modal__header-text">
        <p class="modal__eyebrow">Configuracion</p>
        <h2 id="admin-config-hours-title">Horarios del negocio</h2>
      </div>
      <button type="button" class="modal__close" data-admin-config-hours-close aria-label="Cerrar">&times;</button>
    </header>
    <form class="modal__body admin-form" data-admin-config-hours-form autocomplete="off" novalidate>
      <section class="admin-form__group">
        <h3 class="admin-form__title">Zona horaria</h3>
        <div class="admin-form__grid" data-admin-config-hours-tz-selectors>
          <label class="admin-form__field" for="admin-config-hours-region">
            <span class="admin-form__label">Region</span>
            <select id="admin-config-hours-region" data-admin-config-hours-tz-region required>
              <option value="">Selecciona una region</option>
            </select>
          </label>
          <label class="admin-form__field" for="admin-config-hours-timezone-select">
            <span class="admin-form__label">Zona horaria</span>
            <select id="admin-config-hours-timezone-select" data-admin-config-hours-tz-zone required>
              <option value="">Selecciona una zona</option>
            </select>
          </label>
        </div>
        <p class="admin-form__hint" data-admin-config-hours-tz-summary>Selecciona una region para ver las zonas disponibles.</p>
        <label class="admin-form__field" for="admin-config-hours-timezone-manual" data-admin-config-hours-timezone-manual hidden>
          <span class="admin-form__label">Timezone (IANA)</span>
          <input id="admin-config-hours-timezone-manual" type="text" maxlength="60" placeholder="America/Montevideo">
        </label>
        <input type="hidden" data-admin-config-hours-field="timezone">
      </section>
      <section class="admin-form__group">
        <h3 class="admin-form__title">Horarios por dia</h3>
        <div class="admin-hours-days">
          <?php foreach ($days as $key => $label): ?>
          <fieldset class="admin-hours-day" data-admin-config-hours-day="<?php echo e($key); ?>">
            <legend><?php echo e($label); ?></legend>
            <label class="admin-hours-toggle">
              <input type="checkbox" data-admin-config-hours-open>
              <span>Abierto</span>
            </label>
            <div class="admin-hours-slots">
              <label>
                <span>Inicio</span>
                <input type="time" step="300" data-admin-config-hours-start>
              </label>
              <label>
                <span>Fin</span>
                <input type="time" step="300" data-admin-config-hours-end>
              </label>
            </div>
            <div class="admin-hours-break">
              <label class="admin-hours-toggle">
                <input type="checkbox" data-admin-config-hours-break-toggle>
                <span>Agregar descanso</span>
              </label>
              <div class="admin-hours-slots">
                <label>
                  <span>Inicio descanso</span>
                  <input type="time" step="300" data-admin-config-hours-break-start>
                </label>
                <label>
                  <span>Fin descanso</span>
                  <input type="time" step="300" data-admin-config-hours-break-end>
                </label>
              </div>
            </div>
          </fieldset>
          <?php endforeach; ?>
        </div>
      </section>
      <section class="admin-form__group">
        <h3 class="admin-form__title">Feriados</h3>
        <div class="admin-hours-holidays">
          <div class="admin-hours-holidays__input">
            <input type="date" data-admin-config-hours-holiday-input>
            <button type="button" class="btn btn-outline" data-admin-config-hours-holiday-add>
              <i class="bx bx-plus"></i>
              Agregar feriado
            </button>
          </div>
          <ul class="admin-hours-holidays__list" data-admin-config-hours-holiday-list></ul>
          <p class="admin-hours-holidays__empty" data-admin-config-hours-holiday-empty>Sin feriados configurados.</p>
        </div>
      </section>
      <p class="admin-form__error" data-admin-config-hours-error hidden></p>
      <footer class="modal__footer">
        <button type="submit" class="btn btn-success" data-admin-config-hours-submit>Guardar horarios</button>
      </footer>
    </form>
  </div>
</div>
<?php
return trim(ob_get_clean());
?>
