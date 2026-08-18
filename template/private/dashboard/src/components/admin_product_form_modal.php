<?php
ob_start();
$tipos = [];

// 1. Cargar desde la base de datos local del comercio
try {
  if (class_exists('AutoloadDB')) {
    $rawDb = @include \AutoloadDB::dbPath();
    if (isset($rawDb['categorias']) && is_array($rawDb['categorias'])) {
      foreach ($rawDb['categorias'] as $c) {
        $cClean = mb_convert_case(trim((string)$c), MB_CASE_TITLE, 'UTF-8');
        if ($cClean !== '' && !in_array($cClean, $tipos, true)) {
          $tipos[] = $cClean;
        }
      }
    }
    // Extraer también de productos existentes
    $existingProds = \AutoloadDB::all('productos');
    if (is_array($existingProds)) {
      foreach ($existingProds as $ep) {
        $t = trim((string)($ep['Tipo'] ?? ''));
        if ($t !== '') {
          $tClean = mb_convert_case($t, MB_CASE_TITLE, 'UTF-8');
          if (!in_array($tClean, $tipos, true)) {
            $tipos[] = $tClean;
          }
        }
      }
    }
  }
} catch (\Throwable $e) {}

// 2. Cargar desde $db global si existe
if (empty($tipos) && isset($db['categorias']) && is_array($db['categorias'])) {
  foreach ($db['categorias'] as $c) {
    $cClean = mb_convert_case(trim((string)$c), MB_CASE_TITLE, 'UTF-8');
    if ($cClean !== '' && !in_array($cClean, $tipos, true)) {
      $tipos[] = $cClean;
    }
  }
}

// 3. Opción de Otros
if (!in_array('Otros', $tipos, true)) {
  $tipos[] = 'Otros';
}
$tipos = array_values(array_unique(array_map('trim', $tipos)));
?>
<div class="modal" role="dialog" aria-modal="true" aria-labelledby="admin-product-form-title" data-admin-modal="product-form" hidden>
  <div class="modal__backdrop" data-admin-product-close></div>
  <div class="modal__dialog modal__dialog--lg modal__dialog--product">
    <header class="modal__header">
      <div class="modal__header-text">
        <p class="modal__eyebrow">Productos</p>
        <h2 id="admin-product-form-title" data-admin-product-form-title>Registrar nuevo producto</h2>
      </div>
      <button type="button" class="modal__close" data-admin-product-close aria-label="Cerrar">&times;</button>
    </header>
    <form class="modal__body admin-form" data-admin-product-form enctype="multipart/form-data" autocomplete="off" novalidate>
      <input type="hidden" name="ID_Product" value="" data-admin-product-field="id">
      <input type="hidden" name="Portada_Index" value="0" data-admin-product-field="cover-index">
      <div class="admin-form__grid">
        <label class="admin-form__field" for="admin-product-name">
          <span class="admin-form__label">Nombre</span>
          <input id="admin-product-name" name="Nombre" type="text" required maxlength="140" placeholder="Ej: Cera en polvo">
        </label>
        <label class="admin-form__field" for="admin-product-type">
          <span class="admin-form__label">Tipo / Categoría</span>
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
          <span class="admin-form__label">Precio base</span>
          <input id="admin-product-price" name="Precio" type="number" inputmode="decimal" min="0" max="999999" step="0.01" required placeholder="Ej: 950">
        </label>
        <label class="admin-form__field" for="admin-product-points">
          <span class="admin-form__label">Puntos (opcional)</span>
          <input id="admin-product-points" name="Puntos" type="number" inputmode="numeric" min="0" max="99999" step="1" placeholder="Ej: 150">
        </label>
      </div>
      <div class="admin-form__grid">
        <label class="admin-form__field" for="admin-product-discount">
          <span class="admin-form__label">Descuento % (opcional)</span>
          <input id="admin-product-discount" name="Descuento_Porcentaje" type="number" inputmode="decimal" min="0" max="100" step="0.01" placeholder="Ej: 20">
        </label>
        <label class="admin-form__field" for="admin-product-sale-label">
          <span class="admin-form__label">Etiqueta de venta (opcional)</span>
          <input id="admin-product-sale-label" name="Etiqueta_Venta" type="text" maxlength="60" placeholder="Oferta, compra anticipada">
        </label>
      </div>
      <label class="admin-form__field" for="admin-product-description">
        <span class="admin-form__label">Descripcion</span>
        <textarea id="admin-product-description" name="Descripcion" rows="3" maxlength="400" placeholder="Describe el producto" required></textarea>
      </label>
      <section class="admin-product-images" data-admin-product-images>
        <div class="admin-product-images__head">
          <span class="admin-form__label">Imagenes del producto</span>
          <span class="admin-form__hint">Hasta 4 imagenes por producto. Marca una como portada. Cada imagen puede tener precio propio.</span>
        </div>
        <div class="admin-product-images__grid">
          <?php for ($i = 0; $i < 4; $i++): ?>
          <div class="admin-product-image-slot" data-admin-product-image-slot="<?php echo $i; ?>">
            <input type="hidden" name="Imagenes_Actuales[<?php echo $i; ?>]" value="" data-admin-product-image-current="<?php echo $i; ?>">
            <input type="hidden" name="Imagenes_Quitar[<?php echo $i; ?>]" value="" data-admin-product-image-remove-value="<?php echo $i; ?>">
            <label class="admin-product-image-slot__preview" for="admin-product-image-<?php echo $i; ?>" tabindex="0">
              <img src="" alt="" data-admin-product-image-preview="<?php echo $i; ?>" style="display:none;" hidden>
              <span data-admin-product-image-empty="<?php echo $i; ?>"><i class="bx bx-image-add"></i></span>
            </label>
            <input id="admin-product-image-<?php echo $i; ?>" class="admin-product-image-slot__file" name="Imagenes_Nuevas[<?php echo $i; ?>]" type="file" accept="image/*" data-admin-product-image-input="<?php echo $i; ?>">
            <div class="admin-product-image-slot__controls">
              <label>
                <input type="radio" name="admin_product_cover_radio" value="<?php echo $i; ?>" data-admin-product-cover-radio <?php echo $i === 0 ? 'checked' : ''; ?>>
                <span>Portada</span>
              </label>
              <button type="button" class="admin-product-image-slot__remove" data-admin-product-image-remove="<?php echo $i; ?>" aria-label="Quitar imagen <?php echo $i + 1; ?>">
                <i class="bx bx-trash"></i>
              </button>
            </div>
            <label class="admin-product-image-slot__label">
              <span>Nombre de imagen</span>
              <input name="Imagenes_Titulos[<?php echo $i; ?>]" type="text" maxlength="80" placeholder="Ej: Frasco grande" data-admin-product-image-label="<?php echo $i; ?>">
            </label>
            <label class="admin-product-image-slot__price">
              <span>Precio imagen</span>
              <input name="Imagenes_Precios[<?php echo $i; ?>]" type="number" inputmode="decimal" min="0" max="999999" step="0.01" placeholder="Usa precio base" data-admin-product-image-price="<?php echo $i; ?>">
            </label>
            <div class="admin-product-image-slot__caption">
              <strong data-admin-product-image-title-preview="<?php echo $i; ?>">Imagen <?php echo $i + 1; ?></strong>
              <span data-admin-product-image-price-preview="<?php echo $i; ?>">Precio base</span>
            </div>
          </div>
          <?php endfor; ?>
        </div>
        <span class="admin-form__hint">Formatos: JPG, PNG o WebP (max 5 MB por imagen).</span>
      </section>
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
