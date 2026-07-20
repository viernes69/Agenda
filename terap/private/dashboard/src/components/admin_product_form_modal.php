<?php
ob_start();
$tipos = [
  'Cosmética',
  'Shampoo',
  'Acondicionador',
  'Cremas de tratamiento',
  'Cuidado de barba',
  'Cuidado facial',
  'Estilizado',
  'Coloración',
  'Baño de crema',
  'Accesorios',
  'Otros',
];
$tipos = array_values(array_unique(array_map('trim', $tipos)));
?>
<div class="modal" role="dialog" aria-modal="true" aria-labelledby="admin-product-form-title" data-admin-modal="product-form" hidden>
  <div class="modal__backdrop" data-admin-product-close></div>
  <div class="modal__dialog modal__dialog--lg">
    <header class="modal__header">
      <div class="modal__header-text">
        <p class="modal__eyebrow">Productos</p>
        <h2 id="admin-product-form-title" data-admin-product-form-title>Registrar nuevo producto</h2>
      </div>
      <button type="button" class="modal__close" data-admin-product-close aria-label="Cerrar">&times;</button>
    </header>
    <form class="modal__body admin-form" data-admin-product-form enctype="multipart/form-data" autocomplete="off" novalidate>
      <input type="hidden" name="ID_Product" value="" data-admin-product-field="id">
      <input type="hidden" name="Img_Actual" value="" data-admin-product-field="img-current">
      <div class="admin-form__grid">
        <label class="admin-form__field" for="admin-product-name">
          <span class="admin-form__label">Nombre</span>
          <input id="admin-product-name" name="Nombre" type="text" required maxlength="140" placeholder="Ej: Cera en polvo">
        </label>
        <label class="admin-form__field" for="admin-product-type">
          <span class="admin-form__label">Tipo</span>
          <select id="admin-product-type" name="Tipo" required data-admin-product-field="tipo-select">
            <option value="" disabled selected>Selecciona un tipo</option>
            <?php foreach ($tipos as $tipo): ?>
              <option value="<?php echo e($tipo); ?>"><?php echo e($tipo); ?></option>
            <?php endforeach; ?>
          </select>
        </label>
      </div>
      <label class="admin-form__field" for="admin-product-type-custom" data-admin-product-tipo-custom-wrap hidden>
        <span class="admin-form__label">Especificar tipo</span>
        <input id="admin-product-type-custom" type="text" maxlength="80" placeholder="Ej: Libro" data-admin-product-field="tipo-custom" autocomplete="off">
      </label>
      <div class="admin-form__grid">
        <label class="admin-form__field" for="admin-product-price">
          <span class="admin-form__label">Precio</span>
          <input id="admin-product-price" name="Precio" type="number" inputmode="decimal" min="0" max="999999" step="0.01" required placeholder="Ej: 950">
        </label>
        <label class="admin-form__field" for="admin-product-points">
          <span class="admin-form__label">Puntos (opcional)</span>
          <input id="admin-product-points" name="Puntos" type="number" inputmode="numeric" min="0" max="99999" step="1" placeholder="Ej: 150">
        </label>
      </div>
      <label class="admin-form__field" for="admin-product-description">
        <span class="admin-form__label">Descripción</span>
        <textarea id="admin-product-description" name="Descripcion" rows="3" maxlength="400" placeholder="Describe el producto" required></textarea>
      </label>
      <label class="admin-form__field" for="admin-product-image">
        <span class="admin-form__label">Imagen</span>
        <input id="admin-product-image" name="Imagen" type="file" accept="image/*" data-admin-product-field="image-input">
        <span class="admin-form__hint">Formatos: JPG, PNG o WebP (max 5&nbsp;MB).</span>
        <div class="admin-form__current" data-admin-product-current hidden>
          <img src="" alt="Vista previa" data-admin-product-preview>
          <span class="admin-form__hint">Imagen actual</span>
        </div>
      </label>
      <p class="admin-form__error" data-admin-product-error hidden></p>
      <footer class="modal__footer">
        <button type="submit" class="btn btn-success" data-admin-product-submit>Guardar</button>
      </footer>
    </form>
  </div>
</div>
<?php
return trim(ob_get_clean());
?>
