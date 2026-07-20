<?php
ob_start();
$durations = [15, 30, 60, 75, 90];
?>
<div class="modal" role="dialog" aria-modal="true" aria-labelledby="admin-service-form-title" data-admin-modal="service-form" hidden>
  <div class="modal__backdrop" data-admin-service-close></div>
  <div class="modal__dialog modal__dialog--lg">
    <header class="modal__header">
      <div class="modal__header-text">
        <p class="modal__eyebrow">Servicios</p>
        <h2 id="admin-service-form-title" data-admin-service-form-title>Registrar nuevo servicio</h2>
      </div>
      <button type="button" class="modal__close" data-admin-service-close aria-label="Cerrar">&times;</button>
    </header>
    <form class="modal__body admin-form" data-admin-service-form enctype="multipart/form-data" autocomplete="off" novalidate>
      <input type="hidden" name="ID_Servicio" value="" data-admin-service-field="id">
      <input type="hidden" name="Img_Actual" value="" data-admin-service-field="img-current">
      <div class="admin-form__grid">
        <label class="admin-form__field" for="admin-service-name">
          <span class="admin-form__label">Nombre</span>
          <input id="admin-service-name" name="Nombre" type="text" required maxlength="120" placeholder="Ej: Corte clasico">
        </label>
        <label class="admin-form__field" for="admin-service-duration">
          <span class="admin-form__label">Duracion</span>
          <select id="admin-service-duration" name="Duracion" required data-admin-service-field="duration">
            <option value="" disabled selected>Selecciona duracion</option>
            <?php foreach ($durations as $minutes): ?>
              <option value="<?php echo e((string)$minutes); ?>"><?php echo e($minutes . ' min'); ?></option>
            <?php endforeach; ?>
          </select>
        </label>
      </div>
      <div class="admin-form__grid">
        <label class="admin-form__field" for="admin-service-status">
          <span class="admin-form__label">Estado</span>
          <select id="admin-service-status" name="Estado" required data-admin-service-field="estado">
            <option value="Activo" selected>Activo</option>
            <option value="Inactivo">Inactivo</option>
          </select>
        </label>
        <label class="admin-form__field" for="admin-service-price">
          <span class="admin-form__label">Precio</span>
          <input id="admin-service-price" name="Precio" type="number" inputmode="decimal" min="0" max="99999" step="0.01" required placeholder="Ej: 450">
        </label>
      </div>
      <div class="admin-form__grid">
        <label class="admin-form__field" for="admin-service-points">
          <span class="admin-form__label">Puntos (opcional)</span>
          <input id="admin-service-points" name="Puntos" type="number" inputmode="numeric" min="0" max="99999" step="1" placeholder="Ej: 200">
        </label>
        <label class="admin-form__field" for="admin-service-image">
          <span class="admin-form__label">Imagen</span>
          <input id="admin-service-image" name="Imagen" type="file" accept="image/*" data-admin-service-field="image-input">
          <span class="admin-form__hint">Formatos permitidos: JPG, PNG o WebP (max 5&nbsp;MB).</span>
          <div class="admin-form__current" data-admin-service-current hidden>
            <img src="" alt="Vista previa" data-admin-service-preview>
            <span class="admin-form__hint">Imagen actual</span>
          </div>
        </label>
      </div>
      <p class="admin-form__error" data-admin-service-error hidden></p>
      <footer class="modal__footer">
        <button type="submit" class="btn btn-success" data-admin-service-submit>Guardar</button>
      </footer>
    </form>
  </div>
</div>
<?php
return trim(ob_get_clean());
?>
