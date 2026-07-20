<?php
ob_start();
?>
<div class="modal" role="dialog" aria-modal="true" aria-labelledby="admin-config-redes-title" data-admin-modal="config-redes" hidden>
  <div class="modal__backdrop" data-admin-config-redes-close></div>
  <div class="modal__dialog modal__dialog--lg">
    <header class="modal__header">
      <div class="modal__header-text">
        <p class="modal__eyebrow">Presencia digital</p>
        <h2 id="admin-config-redes-title">Configurar redes sociales</h2>
      </div>
      <button type="button" class="modal__close" data-admin-config-redes-close aria-label="Cerrar">&times;</button>
    </header>
    <form class="modal__body admin-form" data-admin-config-redes-form autocomplete="off" novalidate>
      <section class="admin-form__group">
        <label class="admin-checkbox">
          <input type="checkbox" data-admin-config-redes-visible>
          <span>Mostrar redes a los clientes</span>
        </label>
        <p class="admin-form__hint">Ingresa solo el usuario o nombre de tu negocio. La URL base ya est&aacute; definida.</p>
      </section>

      <section class="admin-form__group" data-admin-config-redes-list>
        <?php
          $networks = [
            ['key' => 'instagram', 'label' => 'Instagram', 'base' => 'https://www.instagram.com/', 'placeholder' => 'usuario'],
            ['key' => 'facebook', 'label' => 'Facebook', 'base' => 'https://www.facebook.com/', 'placeholder' => 'paginanegocio'],
            ['key' => 'tiktok', 'label' => 'TikTok', 'base' => 'https://www.tiktok.com/@', 'placeholder' => 'usuario'],
            ['key' => 'twitter', 'label' => 'Twitter / X', 'base' => 'https://twitter.com/', 'placeholder' => 'usuario'],
            ['key' => 'youtube', 'label' => 'YouTube', 'base' => 'https://www.youtube.com/', 'placeholder' => 'channel/ID o @usuario'],
            ['key' => 'whatsapp', 'label' => 'WhatsApp', 'base' => 'https://wa.me/', 'placeholder' => '59812345678'],
          ];
          foreach ($networks as $network):
            $key = $network['key'];
            $label = $network['label'];
            $base = $network['base'];
            $placeholder = $network['placeholder'];
            $inputId = 'admin-config-redes-' . $key;
        ?>
        <div class="admin-social-field" data-admin-social-field="<?php echo $key; ?>">
          <label class="admin-form__field admin-form__field--social" for="<?php echo $inputId; ?>">
            <span class="admin-form__label"><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></span>
            <div class="admin-social-input">
              <span class="admin-social-prefix" data-admin-social-prefix><?php echo htmlspecialchars($base, ENT_QUOTES, 'UTF-8'); ?></span>
              <input id="<?php echo $inputId; ?>" type="text" placeholder="<?php echo htmlspecialchars($placeholder, ENT_QUOTES, 'UTF-8'); ?>" data-admin-config-redes-username="<?php echo $key; ?>" autocomplete="off">
            </div>
          </label>
        </div>
        <?php endforeach; ?>
      </section>

      <p class="admin-form__error" data-admin-config-redes-error hidden></p>

      <footer class="modal__footer">
        <button type="submit" class="btn btn-success" data-admin-config-redes-submit>Guardar cambios</button>
      </footer>
    </form>
  </div>
</div>
<?php
return trim(ob_get_clean());
?>
