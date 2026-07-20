<?php
ob_start();
?>
<div class="modal" role="dialog" aria-modal="true" aria-labelledby="admin-productos-ledger-title" data-admin-modal="productos-summary" hidden>
  <div class="modal__backdrop" data-admin-productos-summary-close></div>
  <div class="modal__dialog modal__dialog--xl reservas-ledger productos-ledger">
    <header class="modal__header reservas-ledger__header">
      <div class="modal__header-text">
        <p class="modal__eyebrow">Productos</p>
        <h2 id="admin-productos-ledger-title">Panel de ventas de productos</h2>
        <p class="modal__subtitle">Controla ingresos, clientes y puntos generados por la venta de productos.</p>
      </div>
      <button type="button" class="modal__close" data-admin-productos-summary-close aria-label="Cerrar">&times;</button>
    </header>
    <div class="modal__body reservas-ledger__body">
      <section class="reservas-ledger__filters" aria-label="Filtros de productos">
        <div class="reservas-ledger__filters-group">
          <label class="reservas-ledger__field">
            <span>Periodo</span>
            <select data-product-ledger-period-mode>
              <option value="month">Mes calendario</option>
              <option value="custom">Rango personalizado</option>
            </select>
          </label>
          <label class="reservas-ledger__field" data-product-ledger-month-wrapper>
            <span>Mes</span>
            <select data-product-ledger-month></select>
          </label>
          <label class="reservas-ledger__field" data-product-ledger-start-wrapper hidden>
            <span>Desde</span>
            <input type="date" data-product-ledger-start>
          </label>
          <label class="reservas-ledger__field" data-product-ledger-end-wrapper hidden>
            <span>Hasta</span>
            <input type="date" data-product-ledger-end>
          </label>
          <label class="reservas-ledger__field">
            <span>Tipo</span>
            <select data-product-ledger-type>
              <option value="all">Todos los tipos</option>
            </select>
          </label>
          <label class="reservas-ledger__field">
            <span>Cliente</span>
            <select data-product-ledger-client>
              <option value="all">Todos los clientes</option>
            </select>
          </label>
        </div>
        <div class="reservas-ledger__filters-actions">
          <button type="button" class="btn btn-outline" data-product-ledger-reset>Reiniciar filtros</button>
        </div>
      </section>
      <section class="reservas-ledger__meta">
        <p class="reservas-ledger__period" data-product-ledger-period-label>Selecciona un periodo para comenzar.</p>
        <div class="reservas-ledger__meta-stats">
          <article class="reservas-ledger__meta-card">
            <span>Mejor d&iacute;a</span>
            <strong data-product-ledger-top-day>-</strong>
            <small data-product-ledger-top-day-revenue>-</small>
          </article>
          <article class="reservas-ledger__meta-card">
            <span>Producto estrella</span>
            <strong data-product-ledger-top-product>-</strong>
            <small data-product-ledger-top-product-revenue>-</small>
          </article>
          <article class="reservas-ledger__meta-card">
            <span>Cliente destacado</span>
            <strong data-product-ledger-top-client>-</strong>
            <small data-product-ledger-top-client-revenue>-</small>
          </article>
        </div>
      </section>
      <section class="reservas-ledger__notice" data-product-ledger-empty hidden>
        <p>No se registran ventas para los filtros seleccionados. Ajusta el periodo o los filtros para ver informaci&oacute;n.</p>
      </section>
      <section class="reservas-ledger__panel" data-product-ledger-section>
        <header class="reservas-ledger__section-header">
          <h3>Indicadores comerciales</h3>
          <p class="reservas-ledger__hint">Los importes se calculan con el precio de cada producto y consideran ventas finalizadas.</p>
        </header>
        <div class="reservas-ledger__kpis">
          <article class="reservas-ledger__kpi" data-product-ledger-kpi-card>
            <span class="reservas-ledger__kpi-label">Ingresos</span>
            <strong class="reservas-ledger__kpi-value" data-product-ledger-kpi="revenue">-</strong>
            <small data-product-ledger-kpi-sub="revenue">Monto finalizado.</small>
          </article>
          <article class="reservas-ledger__kpi" data-product-ledger-kpi-card>
            <span class="reservas-ledger__kpi-label">Pedidos completados</span>
            <strong class="reservas-ledger__kpi-value" data-product-ledger-kpi="orders">-</strong>
            <small data-product-ledger-kpi-sub="orders">Ventas finalizadas.</small>
          </article>
          <article class="reservas-ledger__kpi" data-product-ledger-kpi-card>
            <span class="reservas-ledger__kpi-label">Pedidos pendientes</span>
            <strong class="reservas-ledger__kpi-value" data-product-ledger-kpi="pending">-</strong>
            <small data-product-ledger-kpi-sub="pending">Pr&oacute;ximos a cerrar.</small>
          </article>
          <article class="reservas-ledger__kpi" data-product-ledger-kpi-card>
            <span class="reservas-ledger__kpi-label">Unidades vendidas</span>
            <strong class="reservas-ledger__kpi-value" data-product-ledger-kpi="units">-</strong>
            <small data-product-ledger-kpi-sub="units">Productos entregados.</small>
          </article>
          <article class="reservas-ledger__kpi" data-product-ledger-kpi-card>
            <span class="reservas-ledger__kpi-label">Clientes activos</span>
            <strong class="reservas-ledger__kpi-value" data-product-ledger-kpi="clients">-</strong>
            <small data-product-ledger-kpi-sub="clients">Compradores &uacute;nicos.</small>
          </article>
          <article class="reservas-ledger__kpi" data-product-ledger-kpi-card>
            <span class="reservas-ledger__kpi-label">Ticket promedio</span>
            <strong class="reservas-ledger__kpi-value" data-product-ledger-kpi="ticket">-</strong>
            <small data-product-ledger-kpi-sub="ticket">Por pedido finalizado.</small>
          </article>
          <article class="reservas-ledger__kpi" data-product-ledger-kpi-card>
            <span class="reservas-ledger__kpi-label">Puntos generados</span>
            <strong class="reservas-ledger__kpi-value" data-product-ledger-kpi="points">-</strong>
            <small data-product-ledger-kpi-sub="points">Acumulados para clientes.</small>
          </article>
          <article class="reservas-ledger__kpi" data-product-ledger-kpi-card>
            <span class="reservas-ledger__kpi-label">Items por pedido</span>
            <strong class="reservas-ledger__kpi-value" data-product-ledger-kpi="avg-items">-</strong>
            <small data-product-ledger-kpi-sub="avg-items">Promedio de unidades.</small>
          </article>
        </div>
        <div class="reservas-ledger__tables">
          <article class="reservas-ledger__table-card">
            <header>
              <h4>Ventas por d&iacute;a</h4>
              <p class="reservas-ledger__hint">Resumen por fecha con pedidos y unidades.</p>
            </header>
            <div class="table-wrap">
              <table class="table table--dense">
                <thead>
                  <tr>
                    <th>Fecha</th>
                    <th>Pedidos</th>
                    <th>Unidades</th>
                    <th>Ingresos</th>
                    <th>Ticket prom.</th>
                    <th>Puntos</th>
                    <th>Clientes</th>
                  </tr>
                </thead>
                <tbody data-product-ledger-daily-body>
                  <tr><td colspan="7" class="muted">Sin datos para el periodo seleccionado.</td></tr>
                </tbody>
              </table>
            </div>
          </article>
          <article class="reservas-ledger__table-card">
            <header>
              <h4>Productos m&aacute;s vendidos</h4>
              <p class="reservas-ledger__hint">Ordenado por ingresos generados.</p>
            </header>
            <div class="table-wrap">
              <table class="table table--dense">
                <thead>
                  <tr>
                    <th>Producto</th>
                    <th>Tipo</th>
                    <th>Unidades</th>
                    <th>Ingresos</th>
                    <th>Puntos</th>
                    <th>% ingreso</th>
                  </tr>
                </thead>
                <tbody data-product-ledger-product-body>
                  <tr><td colspan="6" class="muted">Sin datos para el periodo seleccionado.</td></tr>
                </tbody>
              </table>
            </div>
          </article>
        </div>
        <div class="reservas-ledger__tables">
          <article class="reservas-ledger__table-card">
            <header>
              <h4>Clientes por ingresos</h4>
              <p class="reservas-ledger__hint">Seg&uacute;n pedidos finalizados.</p>
            </header>
            <div class="table-wrap">
              <table class="table table--dense">
                <thead>
                  <tr>
                    <th>Cliente</th>
                    <th>Pedidos</th>
                    <th>Unidades</th>
                    <th>Ingresos</th>
                    <th>Puntos</th>
                  </tr>
                </thead>
                <tbody data-product-ledger-client-body>
                  <tr><td colspan="5" class="muted">Sin clientes en el periodo seleccionado.</td></tr>
                </tbody>
              </table>
            </div>
          </article>
          <article class="reservas-ledger__table-card">
            <header>
              <h4>Ventas por tipo de producto</h4>
              <p class="reservas-ledger__hint">Compara el desempeño por categoria.</p>
            </header>
            <div class="table-wrap">
              <table class="table table--dense">
                <thead>
                  <tr>
                    <th>Tipo</th>
                    <th>Pedidos</th>
                    <th>Unidades</th>
                    <th>Ingresos</th>
                    <th>% ingreso</th>
                  </tr>
                </thead>
                <tbody data-product-ledger-type-body>
                  <tr><td colspan="5" class="muted">Sin datos para el periodo seleccionado.</td></tr>
                </tbody>
              </table>
            </div>
          </article>
        </div>
      </section>
    </div>
  </div>
</div>
<?php
return trim(ob_get_clean());
?>


