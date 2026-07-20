<?php
ob_start();
?>
<div class="modal" role="dialog" aria-modal="true" aria-labelledby="client-modal-title" data-modal="client">
  <div class="modal__backdrop" data-modal-close></div>
  <div class="modal__dialog">
    <header class="modal__header">
      <div class="modal__header-text">
        <h2 id="client-modal-title">Registro de Clientes</h2>
      </div>
      <button type="button" class="modal__close" data-modal-close aria-label="Cerrar">&times;</button>
    </header>
    <div class="modal__body">
      <form class="client-form" data-client-form>
        <fieldset>
          <legend>Datos para registro del cliente</legend>
          <label>
            Nombre
            <input type="text" name="nombre" required>
          </label>
          <label>
            Cedula
            <input type="text" name="cedula" required>
          </label>
          <label>
            Telefono
            <input type="tel" name="telefono" required>
          </label>
          <label>
            Email
            <input type="email" name="email" required>
          </label>
        </fieldset>
        <div class="client-form__actions">
          <button type="button" class="client-form__link" data-client-login>Ya tienes usuario de cliente? Iniciar sesion</button>
          <button type="submit" class="client-form__submit">Registrar y enviar reserva</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php
return trim(ob_get_clean());
?>
