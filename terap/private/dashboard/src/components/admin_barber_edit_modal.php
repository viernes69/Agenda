<?php
ob_start();
$serviciosList = isset($servicios) && is_array($servicios) ? $servicios : [];
?>
<div class="modal" role="dialog" aria-modal="true" aria-labelledby="admin-barber-edit-title" data-admin-modal="barber-edit" hidden>
  <div class="modal__backdrop" data-admin-barber-edit-close></div>
  <div class="modal__dialog modal__dialog--lg">
    <header class="modal__header">
      <div class="modal__header-text">
        <p class="modal__eyebrow">Profesionales</p>
        <h2 id="admin-barber-edit-title">Editar profesional</h2>
      </div>
      <button type="button" class="modal__close" data-admin-barber-edit-close aria-label="Cerrar">&times;</button>
    </header>
    <form class="modal__body admin-form" data-admin-barber-edit-form enctype="multipart/form-data" autocomplete="off" novalidate>
      <input type="hidden" name="ID_Barber" value="" data-admin-barber-edit-id>
      <input type="hidden" name="PerfilActual" value="" data-admin-barber-edit-perfil-actual>
      <div class="admin-form__grid">
        <label class="admin-form__field" for="admin-barber-edit-nombre">
          <span class="admin-form__label">Nombre</span>
          <input id="admin-barber-edit-nombre" name="Nombre" type="text" required maxlength="80" placeholder="Ej: Diego" />
        </label>
        <label class="admin-form__field" for="admin-barber-edit-apellido">
          <span class="admin-form__label">Apellido</span>
          <input id="admin-barber-edit-apellido" name="Apellido" type="text" maxlength="80" placeholder="Ej: Pintos" />
        </label>
      </div>
      <div class="admin-form__grid">
        <label class="admin-form__field" for="admin-barber-edit-cedula">
          <span class="admin-form__label">Cedula</span>
          <input id="admin-barber-edit-cedula" name="Cedula" type="text" inputmode="numeric" pattern="[0-9]{7,}" required maxlength="20" minlength="7" placeholder="Solo numeros sin puntos ni guiones" />
        </label>
        <label class="admin-form__field" for="admin-barber-edit-psw">
          <span class="admin-form__label">Contrasena</span>
          <input id="admin-barber-edit-psw" name="Psw" type="password" required minlength="8" maxlength="120" pattern="^(?=.*[A-Z])(?=.*[0-9]).{8,}$" placeholder="Minimo 8 caracteres, 1 mayuscula y 1 numero" />
        </label>
      </div>
      <div class="admin-form__grid">
        <label class="admin-form__field" for="admin-barber-edit-rol">
          <span class="admin-form__label">Rol</span>
          <select id="admin-barber-edit-rol" name="Rol" required>
            <option value="Func">Func</option>
            <option value="Admin">Admin</option>
          </select>
        </label>
      <label class="admin-form__field" for="admin-barber-edit-perfil">
        <span class="admin-form__label">Foto de perfil</span>
        <input id="admin-barber-edit-perfil" name="Perfil" type="file" accept="image/*" />
        <span class="admin-form__hint">Si no eliges una nueva imagen se conservara la actual (max 5&nbsp;MB).</span>
        <div class="admin-form__current" data-admin-barber-edit-preview hidden>
          <img src="" alt="Foto actual" data-admin-barber-edit-preview-img>
          <span class="admin-form__hint">Foto actual</span>
        </div>
      </label>
    </div>
      <label class="admin-form__field" for="admin-barber-edit-comision">
        <span class="admin-form__label">Comisi&oacute;n (%)</span>
        <input id="admin-barber-edit-comision" name="Comision" type="number" inputmode="decimal" min="0" max="100" step="0.1" placeholder="Opcional: porcentaje de comisi&oacute;n">
        <span class="admin-form__hint">Deja vac&iacute;o si el profesional no tiene comisi&oacute;n configurada.</span>
      </label>
      <?php $dayOptions = isset($scheduleDays) && is_array($scheduleDays) ? $scheduleDays : []; ?>
      <div class="admin-form__group">
        <span class="admin-form__label admin-form__label--block">D&iacute;as de trabajo</span>
        <?php if (!empty($dayOptions)): ?>
          <p class="admin-form__hint">Actualiza los d&iacute;as en los que el profesional est&aacute; disponible.</p>
          <?php foreach ($dayOptions as $dayKey => $meta): ?>
            <?php
              $dayValue = strtolower((string)$dayKey);
              $dayLabel = isset($meta['label']) ? (string)$meta['label'] : ucwords($dayValue);
              $isOpen = !empty($meta['abierto']);
            ?>
            <label class="admin-checkbox">
              <input type="checkbox" name="DiasTrabajo[]" value="<?php echo e($dayValue); ?>" data-admin-barber-day>
              <span><?php echo e($dayLabel); ?><?php if (!$isOpen) { echo ' (cerrado)'; } ?></span>
            </label>
          <?php endforeach; ?>
        <?php else: ?>
          <p class="admin-form__hint">Configura los horarios del negocio para poder asignar d&iacute;as de trabajo.</p>
        <?php endif; ?>
      </div>
      <div class="admin-form__group">
        <span class="admin-form__label admin-form__label--block">Habilidades (servicios que realiza)</span>
        <?php
          $skillsRendered = 0;
          foreach ($serviciosList as $srv):
            $sid = $srv['ID_Servicio'] ?? null;
            if ($sid === null || $sid === '' || !is_numeric($sid)) { continue; }
            $skillsRendered++;
            $sidStr = (string)$sid;
            $sname = trim((string)($srv['Nombre'] ?? ('Servicio ' . $sidStr)));
        ?>
          <label class="admin-checkbox">
            <input type="checkbox" name="Habilidades[]" value="<?php echo e($sidStr); ?>" data-admin-barber-edit-skill>
            <span><?php echo e($sname); ?></span>
          </label>
        <?php endforeach; ?>
        <?php if ($skillsRendered === 0): ?>
          <p class="admin-form__hint">No hay servicios disponibles para asignar.</p>
        <?php endif; ?>
      </div>
      <p class="admin-form__error" data-admin-barber-edit-error hidden></p>
      <footer class="modal__footer">
        <button type="submit" class="btn btn-success" data-admin-barber-edit-submit>Guardar cambios</button>
      </footer>
    </form>
  </div>
</div>
<?php
return trim(ob_get_clean());
?>
