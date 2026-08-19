<?php
ob_start();
$seoBusinessName = trim((string)($infoBarberia['nombre'] ?? ($businessName ?? '')));
if ($seoBusinessName === '') {
  $seoBusinessName = 'Tu negocio';
}
$seoRubroName = trim((string)($infoBarberia['rubro_nombre'] ?? ''));
$seoTitlePlaceholder = $seoRubroName !== ''
  ? ($seoBusinessName . ' | ' . $seoRubroName)
  : ($seoBusinessName . ' | Agenda online');
$seoWebsite = trim((string)($infoBarberia['contacto']['website'] ?? ''));
if ($seoWebsite === '') {
  $seoWebsite = 'https://www.tunegocio.com/';
}
$seoWebsite = rtrim($seoWebsite, '/') . '/';
$seoOgPlaceholder = rtrim($seoWebsite, '/') . '/og-image.jpg';
?>
<div class="modal" role="dialog" aria-modal="true" aria-labelledby="admin-config-seo-title" data-admin-modal="config-seo" hidden>
  <div class="modal__backdrop" data-admin-config-seo-close></div>
  <div class="modal__dialog modal__dialog--lg">
    <header class="modal__header">
      <div class="modal__header-text">
        <p class="modal__eyebrow">Posicionamiento</p>
        <h2 id="admin-config-seo-title">Configurar SEO del sitio</h2>
      </div>
      <button type="button" class="modal__close" data-admin-config-seo-close aria-label="Cerrar">&times;</button>
    </header>
    <form class="modal__body admin-form" data-admin-config-seo-form autocomplete="off" novalidate>
      <section class="admin-form__group">
        <h3 class="admin-form__title">Contenido principal</h3>
        <label class="admin-form__field" for="admin-config-seo-title-input">
          <span class="admin-form__label">Título (máx. 60 caracteres)</span>
          <input id="admin-config-seo-title-input" type="text" maxlength="60" data-admin-config-seo-field="seo.title" placeholder="<?php echo htmlspecialchars($seoTitlePlaceholder, ENT_QUOTES, 'UTF-8'); ?>" required>
        </label>
        <label class="admin-form__field" for="admin-config-seo-description">
          <span class="admin-form__label">Descripción (máx. 160 caracteres)</span>
          <textarea id="admin-config-seo-description" maxlength="160" data-admin-config-seo-field="seo.description" rows="3" placeholder="Reservá turnos, descubrí servicios y más." required></textarea>
          <small class="admin-form__hint" data-admin-config-seo-desc-count>0 / 160</small>
        </label>
        <label class="admin-form__field" for="admin-config-seo-canonical">
          <span class="admin-form__label">URL canónica</span>
          <input id="admin-config-seo-canonical" type="url" data-admin-config-seo-field="seo.canonical" placeholder="<?php echo htmlspecialchars($seoWebsite, ENT_QUOTES, 'UTF-8'); ?>">
        </label>
      </section>

      <section class="admin-form__group">
        <h3 class="admin-form__title">Palabras clave</h3>
        <div class="admin-keywords">
          <div class="admin-keywords__input">
            <input type="text" placeholder="Agregar palabra clave" data-admin-config-seo-keyword-input>
            <button type="button" class="btn btn-outline" data-admin-config-seo-keyword-add>Agregar</button>
          </div>
          <div class="admin-keywords__list" data-admin-config-seo-keyword-list></div>
        </div>
      </section>

      <section class="admin-form__group">
        <h3 class="admin-form__title">Indexación</h3>
        <label class="admin-checkbox">
          <input type="checkbox" data-admin-config-seo-robots="index">
          <span>Permitir indexación (index)</span>
        </label>
        <label class="admin-checkbox">
          <input type="checkbox" data-admin-config-seo-robots="follow">
          <span>Seguir enlaces (follow)</span>
        </label>
      </section>

      <section class="admin-form__group">
        <h3 class="admin-form__title">Social / Open Graph</h3>
        <label class="admin-checkbox">
          <input type="checkbox" data-admin-config-seo-sync>
          <span>Usar mismo título y descripción para redes sociales</span>
        </label>
        <label class="admin-form__field" for="admin-config-seo-og-image">
          <span class="admin-form__label">Imagen para compartir (1200x630 recomendado)</span>
          <input id="admin-config-seo-og-image" type="url" data-admin-config-seo-field="seo.og_image" placeholder="<?php echo htmlspecialchars($seoOgPlaceholder, ENT_QUOTES, 'UTF-8'); ?>">
        </label>
      </section>

      <section class="admin-form__group">
        <h3 class="admin-form__title">Vista previa</h3>
        <div class="admin-seo-preview" data-admin-config-seo-preview>
          <p class="admin-seo-preview__url" data-admin-config-seo-preview-url><?php echo htmlspecialchars($seoWebsite, ENT_QUOTES, 'UTF-8'); ?></p>
          <p class="admin-seo-preview__title" data-admin-config-seo-preview-title><?php echo htmlspecialchars($seoTitlePlaceholder, ENT_QUOTES, 'UTF-8'); ?></p>
          <p class="admin-seo-preview__description" data-admin-config-seo-preview-description>Reservá turnos, descubrí servicios y más.</p>
        </div>
      </section>

      <p class="admin-form__error" data-admin-config-seo-error hidden></p>

      <footer class="modal__footer">
        <button type="submit" class="btn btn-success" data-admin-config-seo-submit>Guardar cambios</button>
      </footer>
    </form>
  </div>
</div>
<?php
return trim(ob_get_clean());
?>
