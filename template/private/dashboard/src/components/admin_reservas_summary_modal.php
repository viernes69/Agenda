<?php
ob_start();
?>
<div class="modal" role="dialog" aria-modal="true" aria-labelledby="admin-reservas-ledger-title" data-admin-modal="reservas-summary" hidden>
  <div class="modal__backdrop" data-admin-reservas-summary-close></div>
  <div class="modal__dialog modal__dialog--xl reservas-ledger">
    <header class="modal__header reservas-ledger__header">
      <div class="modal__header-text">
        <p class="modal__eyebrow">Reservas</p>
        <h2 id="admin-reservas-ledger-title">Panel contable de reservas</h2>
        <p class="modal__subtitle">Analiza ingresos, cancelaciones y rendimiento por profesional o servicio en un solo lugar.</p>
      </div>
      <button type="button" class="modal__close" data-admin-reservas-summary-close aria-label="Cerrar">&times;</button>
    </header>
    <div class="modal__body reservas-ledger__body">
      <section class="reservas-ledger__filters" aria-label="Filtros del panel">
        <div class="reservas-ledger__filters-group">
          <label class="reservas-ledger__field">
            <span>D&iacute;a (vac&iacute;o = todos)</span>
            <input type="date" data-ledger-date>
          </label>
          <label class="reservas-ledger__field">
            <span>Profesional</span>
            <select data-ledger-barber>
              <option value="all">Todos los profesionales</option>
            </select>
          </label>
          <label class="reservas-ledger__field">
            <span>Vista</span>
            <select data-ledger-view>
              <option value="overview">Vision general</option>
              <option value="barbers">Profesionales</option>
              <option value="services">Servicios</option>
            </select>
          </label>
        </div>
        <div class="reservas-ledger__filters-actions">
          <button type="button" class="btn btn-outline" data-ledger-reset>Reiniciar filtros</button>
        </div>
      </section>
      <section class="reservas-ledger__meta">
        <p class="reservas-ledger__period" data-ledger-period-label>Selecciona un periodo para comenzar.</p>
        <div class="reservas-ledger__meta-stats">
          <article class="reservas-ledger__meta-card">
            <span>Mejor d&iacute;a</span>
            <strong data-ledger-top-day>-</strong>
            <small data-ledger-top-day-amount>-</small>
          </article>
          <article class="reservas-ledger__meta-card">
            <span>Profesional destacado</span>
            <strong data-ledger-top-barber>-</strong>
            <small data-ledger-top-barber-amount>-</small>
          </article>
          <article class="reservas-ledger__meta-card">
            <span>Servicio estrella</span>
            <strong data-ledger-top-service>-</strong>
            <small data-ledger-top-service-amount>-</small>
          </article>
        </div>
      </section>
      <section class="reservas-ledger__notice" data-ledger-empty hidden>
        <p>No hay reservas registradas para los filtros seleccionados. Ajusta el periodo o los filtros para ver informacion.</p>
      </section>
      <section class="reservas-ledger__panel" data-ledger-section="overview">
        <header class="reservas-ledger__section-header">
          <h3>Indicadores del periodo</h3>
          <p class="reservas-ledger__hint">Los montos consideran el precio configurado de cada servicio. Las proyecciones se basan en reservas confirmadas o en progreso.</p>
        </header>
        <div class="reservas-ledger__kpis">
          <article class="reservas-ledger__kpi" data-ledger-kpi-card>
            <span class="reservas-ledger__kpi-label">Ingresos cobrados</span>
            <strong class="reservas-ledger__kpi-value" data-ledger-kpi="revenue">-</strong>
            <small data-ledger-kpi-sub="revenue">Importe finalizado.</small>
          </article>
          <article class="reservas-ledger__kpi" data-ledger-kpi-card>
            <span class="reservas-ledger__kpi-label">Reservas atendidas</span>
            <strong class="reservas-ledger__kpi-value" data-ledger-kpi="attended">-</strong>
            <small data-ledger-kpi-sub="attended">Finalizadas correctamente.</small>
          </article>
          <article class="reservas-ledger__kpi" data-ledger-kpi-card>
            <span class="reservas-ledger__kpi-label">Reservas canceladas</span>
            <strong class="reservas-ledger__kpi-value" data-ledger-kpi="cancelled">-</strong>
            <small data-ledger-kpi-sub="cancelled">Cancelaciones registradas.</small>
          </article>
          <article class="reservas-ledger__kpi" data-ledger-kpi-card>
            <span class="reservas-ledger__kpi-label">Reservas activas</span>
            <strong class="reservas-ledger__kpi-value" data-ledger-kpi="active">-</strong>
            <small data-ledger-kpi-sub="active">Pendientes o en progreso.</small>
          </article>
          <article class="reservas-ledger__kpi" data-ledger-kpi-card>
            <span class="reservas-ledger__kpi-label">Ticket promedio</span>
            <strong class="reservas-ledger__kpi-value" data-ledger-kpi="ticket">-</strong>
            <small data-ledger-kpi-sub="ticket">Sobre reservas atendidas.</small>
          </article>
          <article class="reservas-ledger__kpi" data-ledger-kpi-card>
            <span class="reservas-ledger__kpi-label">Comisiones devengadas</span>
            <strong class="reservas-ledger__kpi-value" data-ledger-kpi="commission">-</strong>
            <small data-ledger-kpi-sub="commission">Total a liquidar.</small>
          </article>
          <article class="reservas-ledger__kpi" data-ledger-kpi-card>
            <span class="reservas-ledger__kpi-label">Margen neto</span>
            <strong class="reservas-ledger__kpi-value" data-ledger-kpi="net">-</strong>
            <small data-ledger-kpi-sub="net">Ingresos menos comisiones.</small>
          </article>
          <article class="reservas-ledger__kpi" data-ledger-kpi-card>
            <span class="reservas-ledger__kpi-label">Ingresos proyectados</span>
            <strong class="reservas-ledger__kpi-value" data-ledger-kpi="projected">-</strong>
            <small data-ledger-kpi-sub="projected">Reservas confirmadas por cobrar.</small>
          </article>
          <article class="reservas-ledger__kpi" data-ledger-kpi-card>
            <span class="reservas-ledger__kpi-label">Tasa de cancelacion</span>
            <strong class="reservas-ledger__kpi-value" data-ledger-kpi="cancel-rate">-</strong>
            <small data-ledger-kpi-sub="cancel-rate">Canceladas sobre el total.</small>
          </article>
        </div>
        <div class="reservas-ledger__tables">
          <article class="reservas-ledger__table-card">
            <header>
              <h4>Detalle diario</h4>
              <p class="reservas-ledger__hint">Incluye estado y promedios por fecha.</p>
            </header>
            <div class="table-wrap">
              <table class="table table--dense">
                <thead>
                  <tr>
                    <th>Fecha</th>
                    <th>Total</th>
                    <th>Atendidas</th>
                    <th>Canceladas</th>
                    <th>Ingresos</th>
                    <th>Comisiones</th>
                    <th>Margen</th>
                    <th>Ticket prom.</th>
                    <th>Activas</th>
                  </tr>
                </thead>
                <tbody data-ledger-daily-body>
                  <tr><td colspan="9" class="muted">Sin datos para el periodo seleccionado.</td></tr>
                </tbody>
              </table>
            </div>
          </article>
          <article class="reservas-ledger__table-card">
            <header>
              <h4>Servicios mas vendidos</h4>
              <p class="reservas-ledger__hint">Ordenado por ingresos cobrados.</p>
            </header>
            <div class="table-wrap">
              <table class="table table--dense">
                <thead>
                  <tr>
                    <th>Servicio</th>
                    <th>Reservas</th>
                    <th>Ingresos</th>
                    <th>Ticket prom.</th>
                    <th>% ingreso</th>
                  </tr>
                </thead>
                <tbody data-ledger-service-body>
                  <tr><td colspan="5" class="muted">Sin datos para el periodo seleccionado.</td></tr>
                </tbody>
              </table>
            </div>
          </article>
        </div>
      </section>
      <section class="reservas-ledger__panel" data-ledger-section="barbers" hidden>
        <header class="reservas-ledger__section-header">
          <h3>Desempeno por profesional</h3>
          <p class="reservas-ledger__hint">Analiza el aporte por profesional y detecta oportunidades de mejora.</p>
        </header>
        <div class="reservas-ledger__tables">
          <article class="reservas-ledger__table-card">
            <header>
              <h4>Resumen mensual por profesional</h4>
              <p class="reservas-ledger__hint">Solo incluye reservas finalizadas.</p>
            </header>
            <div class="table-wrap">
              <table class="table table--dense">
                <thead>
                  <tr>
                    <th>Profesional</th>
                    <th>Atendidas</th>
                    <th>Canceladas</th>
                    <th>Ingresos</th>
                    <th>Comisiones</th>
                    <th>Margen</th>
                    <th>Ticket prom.</th>
                    <th>% del total</th>
                  </tr>
                </thead>
                <tbody data-ledger-barber-month-body>
                  <tr><td colspan="8" class="muted">Sin informacion disponible.</td></tr>
                </tbody>
              </table>
            </div>
          </article>
          <article class="reservas-ledger__table-card">
            <header>
              <h4>Rendimiento diario por profesional</h4>
              <p class="reservas-ledger__hint">Comparacion cruzada por fecha.</p>
            </header>
            <div class="table-wrap">
              <table class="table table--dense">
                <thead>
                  <tr>
                    <th>Fecha</th>
                    <th>Profesional</th>
                    <th>Atendidas</th>
                    <th>Canceladas</th>
                    <th>Ingresos</th>
                    <th>Comisiones</th>
                    <th>Margen</th>
                  </tr>
                </thead>
                <tbody data-ledger-barber-daily-body>
                  <tr><td colspan="7" class="muted">Elige un periodo para ver resultados.</td></tr>
                </tbody>
              </table>
            </div>
          </article>
        </div>
      </section>
      <section class="reservas-ledger__panel" data-ledger-section="services" hidden>
        <header class="reservas-ledger__section-header">
          <h3>Detalle por servicio</h3>
          <p class="reservas-ledger__hint">Contrasta reservas atendidas y canceladas por cada prestacion.</p>
        </header>
        <div class="reservas-ledger__tables">
          <article class="reservas-ledger__table-card reservas-ledger__table-card--full">
            <div class="table-wrap">
              <table class="table table--dense">
                <thead>
                  <tr>
                    <th>Servicio</th>
                    <th>Total reservas</th>
                    <th>Atendidas</th>
                    <th>Canceladas</th>
                    <th>Ingresos</th>
                    <th>Ticket prom.</th>
                    <th>% de cancelacion</th>
                  </tr>
                </thead>
                <tbody data-ledger-service-detail-body>
                  <tr><td colspan="7" class="muted">No existen reservas para el periodo.</td></tr>
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
