<?php
ob_start();
?>
<div class="modal" role="dialog" aria-modal="true" aria-labelledby="admin-notifications-log-title" data-admin-modal="notifications-log" hidden>
  <div class="modal__backdrop" data-admin-notif-log-close></div>
  <div class="modal__dialog modal__dialog--wide">
    <header class="modal__header">
      <div class="modal__header-text">
        <p class="modal__eyebrow">Notificaciones</p>
        <h2 id="admin-notifications-log-title">Historial de notificaciones</h2>
      </div>
      <button type="button" class="modal__close" data-admin-notif-log-close aria-label="Cerrar">&times;</button>
    </header>
    <div class="modal__body">

      <!-- Stats cards -->
      <div class="notif-stats" data-admin-notif-log-stats>
        <div class="notif-stat-card notif-stat-total">
          <span class="notif-stat-num" data-notif-stat-total>0</span>
          <span class="notif-stat-label">Total</span>
        </div>
        <div class="notif-stat-card notif-stat-sent">
          <span class="notif-stat-num" data-notif-stat-sent>0</span>
          <span class="notif-stat-label">Enviados</span>
        </div>
        <div class="notif-stat-card notif-stat-failed">
          <span class="notif-stat-num" data-notif-stat-failed>0</span>
          <span class="notif-stat-label">Fallidos</span>
        </div>
        <div class="notif-stat-card notif-stat-email">
          <span class="notif-stat-num" data-notif-stat-email>0</span>
          <span class="notif-stat-label">✉️ Email</span>
        </div>
        <div class="notif-stat-card notif-stat-wa">
          <span class="notif-stat-num" data-notif-stat-wa>0</span>
          <span class="notif-stat-label">💬 WhatsApp</span>
        </div>
      </div>

      <!-- Filters -->
      <div class="notif-filters">
        <label class="notif-filter">
          <span class="notif-filter__label">Canal</span>
          <select data-admin-notif-log-channel>
            <option value="">Todos</option>
            <option value="email">✉️ Email</option>
            <option value="whatsapp">💬 WhatsApp</option>
          </select>
        </label>
        <label class="notif-filter">
          <span class="notif-filter__label">Estado</span>
          <select data-admin-notif-log-status>
            <option value="">Todos</option>
            <option value="sent">Enviado</option>
            <option value="failed">Fallido</option>
            <option value="queued">En cola</option>
            <option value="cancelled">Cancelado</option>
          </select>
        </label>
        <label class="notif-filter">
          <span class="notif-filter__label">Desde</span>
          <input type="date" data-admin-notif-log-date-from>
        </label>
        <label class="notif-filter">
          <span class="notif-filter__label">Hasta</span>
          <input type="date" data-admin-notif-log-date-to>
        </label>
        <button type="button" class="btn btn-sm" data-admin-notif-log-apply>Filtrar</button>
        <button type="button" class="btn btn-sm btn-ghost" data-admin-notif-log-clear>Limpiar</button>
      </div>

      <!-- Table -->
      <div class="notif-table-wrap" data-admin-notif-log-table-wrap>
        <table class="notif-table">
          <thead>
            <tr>
              <th>Canal</th>
              <th>Destinatario</th>
              <th>Asunto</th>
              <th>Estado</th>
              <th>Fecha</th>
              <th></th>
            </tr>
          </thead>
          <tbody data-admin-notif-log-tbody></tbody>
        </table>
        <p class="notif-empty" data-admin-notif-log-empty hidden>Cargando...</p>
      </div>

      <!-- Pagination -->
      <div class="notif-pagination" data-admin-notif-log-pagination hidden>
        <button type="button" class="btn btn-sm btn-ghost" data-admin-notif-log-prev>&laquo; Anterior</button>
        <span data-admin-notif-log-page-info>Página 1 de 1</span>
        <button type="button" class="btn btn-sm btn-ghost" data-admin-notif-log-next>Siguiente &raquo;</button>
      </div>

    </div>
  </div>
</div>
<?php
return trim(ob_get_clean());
?>
