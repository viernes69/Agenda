<?php
/**
 * Modal Info del Negocio. $rubrosDisponibles: lista SQLite opcional.
 */
ob_start();

if (!isset($rubrosDisponibles) || !is_array($rubrosDisponibles)) {
  $rubrosDisponibles = [];
  try {
    if (class_exists(\Agenduy\Core\Database::class)) {
      $rubrosDisponibles = \Agenduy\Core\Database::getInstance()->fetchAll(
        'SELECT id_rubro, nombre FROM rubros WHERE activo = 1 ORDER BY nombre'
      );
    }
  } catch (Throwable $e) {
    $rubrosDisponibles = [];
  }
}
$currentRubroId = isset($infoBarberia['ID_Rubro']) ? (int)$infoBarberia['ID_Rubro'] : 0;
?>
<div class="modal" role="dialog" aria-modal="true" aria-labelledby="admin-config-info-title" data-admin-modal="config-info" hidden>
  <div class="modal__backdrop" data-admin-config-info-close></div>
  <div class="modal__dialog modal__dialog--lg">
    <header class="modal__header">
      <div class="modal__header-text">
        <p class="modal__eyebrow">Configuraci&oacute;n</p>
        <h2 id="admin-config-info-title">Info del Negocio</h2>
      </div>
      <button type="button" class="modal__close" data-admin-config-info-close aria-label="Cerrar">&times;</button>
    </header>
    <form class="modal__body admin-form" data-admin-config-info-form autocomplete="off" novalidate>
      <section class="admin-form__group">
        <h3 class="admin-form__title">Identidad</h3>
        <div class="admin-form__grid">
          <label class="admin-form__field" for="admin-config-info-nombre">
            <span class="admin-form__label">Nombre del negocio</span>
            <input id="admin-config-info-nombre" type="text" maxlength="20" data-admin-config-info-field="nombre" required placeholder="Ej: Tu negocio">
          </label>
        </div>
        <div class="admin-form__grid">
          <div class="admin-form__field admin-config-info-logo">
            <span class="admin-form__label">Logo del negocio</span>
            <div class="admin-config-info-logo__preview" data-admin-config-info-logo-preview>
              <img src="" alt="Logo del negocio" data-admin-config-info-logo-img hidden>
              <span class="admin-config-info-logo__placeholder" data-admin-config-info-logo-placeholder>Sin logo</span>
            </div>
            <div class="admin-config-info-logo__actions">
              <label class="admin-config-info-logo__upload">
                <input type="file" accept="image/*" data-admin-config-info-logo-input hidden>
                <span>Cargar logo</span>
              </label>
            </div>
          </div>
        </div>
        <div class="admin-form__grid">
          <label class="admin-form__field" for="admin-config-info-slogan">
            <span class="admin-form__label">Slogan</span>
            <input id="admin-config-info-slogan" type="text" maxlength="50" data-admin-config-info-field="slogan" placeholder="Tu eslogan">
          </label>
        </div>
        <div class="admin-form__grid">
          <label class="admin-form__field" for="admin-config-info-razon-social">
            <span class="admin-form__label">Raz&oacute;n social</span>
            <input id="admin-config-info-razon-social" type="text" maxlength="30" data-admin-config-info-field="razon_social" placeholder="Ej: Tu Negocio SRL">
          </label>
          <label class="admin-form__field" for="admin-config-info-rubro">
            <span class="admin-form__label">Rubro</span>
            <select id="admin-config-info-rubro" data-admin-config-info-field="ID_Rubro" required>
              <option value="" disabled<?php echo $currentRubroId <= 0 ? ' selected' : ''; ?>>Selecciona rubro</option>
              <?php foreach ($rubrosDisponibles as $rubroRow): ?>
                <?php
                  $rid = (int)($rubroRow['id_rubro'] ?? 0);
                  $rname = (string)($rubroRow['nombre'] ?? '');
                  if ($rid <= 0 || $rname === '') continue;
                ?>
                <option value="<?php echo $rid; ?>"<?php echo $rid === $currentRubroId ? ' selected' : ''; ?>>
                  <?php echo htmlspecialchars($rname, ENT_QUOTES, 'UTF-8'); ?>
                </option>
              <?php endforeach; ?>
            </select>
            <span class="admin-form__hint">El rubro se elige al registrarte; pod&eacute;s corregirlo ac&aacute; si hace falta.</span>
          </label>
        </div>
        <div class="admin-form__grid">
          <label class="admin-form__field" for="admin-config-info-rut">
            <span class="admin-form__label">RUT / RUC</span>
            <input id="admin-config-info-rut" type="text" inputmode="numeric" pattern="^[0-9]{10,14}$" title="Ingresa entre 10 y 14 digitos sin puntos ni guiones." data-admin-config-info-field="rut_ruc" placeholder="Ej: 123456789012">
          </label>
        </div>
        <label class="admin-form__field" for="admin-config-info-descripcion">
          <span class="admin-form__label">Descripci&oacute;n</span>
          <textarea id="admin-config-info-descripcion" rows="3" maxlength="150" data-admin-config-info-field="descripcion" placeholder="Describe brevemente tu negocio"></textarea>
        </label>
      </section>

      <section class="admin-form__group">
        <h3 class="admin-form__title">Contacto</h3>
        <div class="admin-form__grid">
          <label class="admin-form__field" for="admin-config-info-telefono">
            <span class="admin-form__label">Telefono</span>
            <input id="admin-config-info-telefono" type="text" maxlength="40" data-admin-config-info-field="contacto.telefono" placeholder="+598 99 000 000">
          </label>
          <label class="admin-form__field" for="admin-config-info-whatsapp">
            <span class="admin-form__label">WhatsApp</span>
            <input id="admin-config-info-whatsapp" type="text" maxlength="40" data-admin-config-info-field="contacto.whatsapp" placeholder="+598 99 000 000">
          </label>
        </div>
        <div class="admin-form__grid">
          <label class="admin-form__field" for="admin-config-info-email">
            <span class="admin-form__label">Email</span>
            <input id="admin-config-info-email" type="email" maxlength="120" data-admin-config-info-field="contacto.email" placeholder="contacto@tunegocio.com">
            <span class="admin-form__hint">También se usa para recibir notificaciones de reservas.</span>
          </label>
          <div class="admin-form__field">
            <span class="admin-form__label">Sitio web</span>
            <div class="admin-info-website" data-admin-config-info-website>
              <span class="admin-info-website__url" data-admin-config-info-website-value></span>
              <div class="admin-info-website__actions">
                <button type="button" class="admin-info-website__btn" data-admin-config-info-website-copy aria-label="Copiar URL">
                  <i class="bx bx-copy"></i>
                </button>
              </div>
              <input type="hidden" data-admin-config-info-field="contacto.website">
            </div>
          </div>
        </div>
      </section>

      <section class="admin-form__group">
        <h3 class="admin-form__title">Direcci&oacute;n</h3>
        <div class="admin-form__grid">
          <label class="admin-form__field" for="admin-config-info-pais">
            <span class="admin-form__label">Pa&iacute;s</span>
            <select id="admin-config-info-pais" data-admin-config-info-field="direccion.pais" data-admin-config-info-country required>
              <option value="" disabled selected>Selecciona un pa&iacute;s</option>
            </select>
          </label>
          <label class="admin-form__field">
            <span class="admin-form__label">Regi&oacute;n</span>
            <select id="admin-config-info-region" data-admin-config-info-field="direccion.region" data-admin-config-info-region-select hidden disabled>
              <option value="" disabled selected>Selecciona una regi&oacute;n</option>
            </select>
            <input id="admin-config-info-region-text" type="text" maxlength="120" data-admin-config-info-field="direccion.region" data-admin-config-info-region-input placeholder="Ingresa la regi&oacute;n">
            <span class="admin-form__hint" data-admin-config-info-region-hint>Selecciona la regi&oacute;n disponible o ingresa el nombre manualmente.</span>
          </label>
          <label class="admin-form__field" for="admin-config-info-ciudad">
            <span class="admin-form__label">Ciudad</span>
            <input id="admin-config-info-ciudad" type="text" maxlength="120" data-admin-config-info-field="direccion.ciudad" placeholder="Montevideo">
          </label>
        </div>
        <div class="admin-form__grid">
          <label class="admin-form__field" for="admin-config-info-calle">
            <span class="admin-form__label">Calle</span>
            <input id="admin-config-info-calle" type="text" maxlength="120" data-admin-config-info-field="direccion.calle" placeholder="Av. Principal">
          </label>
          <label class="admin-form__field" for="admin-config-info-numero">
            <span class="admin-form__label">N&uacute;mero</span>
            <input id="admin-config-info-numero" type="text" maxlength="20" data-admin-config-info-field="direccion.numero" placeholder="1234">
          </label>
          <label class="admin-form__field" for="admin-config-info-referencia">
            <span class="admin-form__label">Referencia</span>
            <input id="admin-config-info-referencia" type="text" maxlength="160" data-admin-config-info-field="direccion.referencia" placeholder="Esquina Ejemplo">
          </label>
        </div>
        <div class="admin-form__grid">
          <label class="admin-form__field" for="admin-config-info-cp">
            <span class="admin-form__label">C&oacute;digo postal</span>
            <input id="admin-config-info-cp" type="text" maxlength="20" data-admin-config-info-field="direccion.codigo_postal" placeholder="11000">
          </label>
        </div>
      </section>

      <p class="admin-form__error" data-admin-config-info-error hidden></p>

      <footer class="modal__footer">
        <button type="submit" class="btn btn-success" data-admin-config-info-submit>Guardar cambios</button>
      </footer>
    </form>
  </div>
</div>
<?php
return trim(ob_get_clean());
?>
