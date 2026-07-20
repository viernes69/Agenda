(function adminReservasLedgerModal() {
  const modal = document.querySelector('[data-admin-modal="reservas-summary"]');
  if (!modal) return;

  const modalLoading = window.AdminModalLoading;

  const triggers = Array.from(document.querySelectorAll('[data-admin-summary-modal="reservas-summary"]'));
  if (!triggers.length) return;

  const closeButtons = Array.from(modal.querySelectorAll('[data-admin-reservas-summary-close]'));

  const dayInput = modal.querySelector('[data-ledger-date]');
  const barberSelect = modal.querySelector('[data-ledger-barber]');
  const viewSelect = modal.querySelector('[data-ledger-view]');
  const resetBtn = modal.querySelector('[data-ledger-reset]');

  const periodLabel = modal.querySelector('[data-ledger-period-label]');
  const emptyNotice = modal.querySelector('[data-ledger-empty]');

  const metaTopDay = modal.querySelector('[data-ledger-top-day]');
  const metaTopDayAmount = modal.querySelector('[data-ledger-top-day-amount]');
  const metaTopBarber = modal.querySelector('[data-ledger-top-barber]');
  const metaTopBarberAmount = modal.querySelector('[data-ledger-top-barber-amount]');
  const metaTopService = modal.querySelector('[data-ledger-top-service]');
  const metaTopServiceAmount = modal.querySelector('[data-ledger-top-service-amount]');

  const kpiEls = {
    revenue: modal.querySelector('[data-ledger-kpi="revenue"]'),
    attended: modal.querySelector('[data-ledger-kpi="attended"]'),
    cancelled: modal.querySelector('[data-ledger-kpi="cancelled"]'),
    active: modal.querySelector('[data-ledger-kpi="active"]'),
    ticket: modal.querySelector('[data-ledger-kpi="ticket"]'),
    commission: modal.querySelector('[data-ledger-kpi="commission"]'),
    net: modal.querySelector('[data-ledger-kpi="net"]'),
    projected: modal.querySelector('[data-ledger-kpi="projected"]'),
    cancelRate: modal.querySelector('[data-ledger-kpi="cancel-rate"]'),
  };

  const kpiSubs = {
    revenue: modal.querySelector('[data-ledger-kpi-sub="revenue"]'),
    attended: modal.querySelector('[data-ledger-kpi-sub="attended"]'),
    cancelled: modal.querySelector('[data-ledger-kpi-sub="cancelled"]'),
    active: modal.querySelector('[data-ledger-kpi-sub="active"]'),
    ticket: modal.querySelector('[data-ledger-kpi-sub="ticket"]'),
    commission: modal.querySelector('[data-ledger-kpi-sub="commission"]'),
    net: modal.querySelector('[data-ledger-kpi-sub="net"]'),
    projected: modal.querySelector('[data-ledger-kpi-sub="projected"]'),
    cancelRate: modal.querySelector('[data-ledger-kpi-sub="cancel-rate"]'),
  };

  const sections = Array.from(modal.querySelectorAll('[data-ledger-section]'));
  const dailyBody = modal.querySelector('[data-ledger-daily-body]');
  const serviceTopBody = modal.querySelector('[data-ledger-service-body]');
  const serviceDetailBody = modal.querySelector('[data-ledger-service-detail-body]');
  const barberMonthBody = modal.querySelector('[data-ledger-barber-month-body]');
  const barberDailyBody = modal.querySelector('[data-ledger-barber-daily-body]');

  const rawMetrics = (typeof window.ADMIN_RESERVAS_METRICS === 'object' && window.ADMIN_RESERVAS_METRICS) || {};
  const locale = (typeof rawMetrics.locale === 'string' ? rawMetrics.locale : 'es-UY').replace('_', '-');
  const currencyCode = typeof rawMetrics.currency_code === 'string' && rawMetrics.currency_code ? rawMetrics.currency_code : 'USD';
  const currencySymbol = typeof rawMetrics.currency_symbol === 'string' && rawMetrics.currency_symbol ? rawMetrics.currency_symbol : '$';

  const rawEntries = Array.isArray(rawMetrics.entries) ? rawMetrics.entries : [];
  const rawBarbers = Array.isArray(rawMetrics.barbers) ? rawMetrics.barbers : [];

  const STATUS_ATTENDED = new Set(['finalizado', 'finalizada', 'completado', 'completada', 'atendido', 'atendida']);
  const STATUS_CANCELLED = new Set(['cancelado', 'cancelada', 'rechazado', 'rechazada']);
  const STATUS_ACTIVE = new Set(['pendiente', 'aprobado', 'aprobada', 'en progreso', 'confirmado', 'confirmada', 'aceptado', 'aceptada']);
  const STATUS_PROJECTED = new Set(['aprobado', 'aprobada', 'en progreso', 'confirmado', 'confirmada']);

  const toNumber = (value) => {
    if (typeof value === 'number' && Number.isFinite(value)) return value;
    if (typeof value === 'string' && value.trim() !== '') {
      const normalized = Number(value.replace(',', '.'));
      if (Number.isFinite(normalized)) return normalized;
    }
    return 0;
  };

  const normalizeStatus = (value) => {
    if (typeof value !== 'string') return '';
    return value.trim().toLowerCase();
  };

  const parseCommission = (value) => {
    if (value === null || value === undefined || value === '') return null;
    const normalized = Number(String(value).replace(',', '.'));
    if (!Number.isFinite(normalized)) return null;
    if (normalized < 0 || normalized > 100) return null;
    return Math.round(normalized * 1000) / 1000;
  };

  const normalizedEntries = rawEntries
    .map((entry) => {
      const date = typeof entry.date === 'string' ? entry.date.slice(0, 10) : '';
      const month = typeof entry.month === 'string' && entry.month ? entry.month : (date ? date.slice(0, 7) : '');
      const status = normalizeStatus(entry.status);
      const price = toNumber(entry.price);
      const serviceId = entry.serviceId !== undefined && entry.serviceId !== null ? String(entry.serviceId) : null;
      const serviceName = typeof entry.serviceName === 'string' && entry.serviceName.trim() !== '' ? entry.serviceName.trim() : (serviceId ? 'Servicio ' + serviceId : 'Servicio');
      const serviceKey = serviceId || serviceName;
      const barberId = entry.barberId !== undefined && entry.barberId !== null && entry.barberId !== '' ? String(entry.barberId) : null;
      const barberKey = typeof entry.barberKey === 'string' && entry.barberKey.trim() !== '' ? entry.barberKey.trim() : (barberId || 'unassigned');
      const barberName = typeof entry.barberName === 'string' && entry.barberName.trim() !== '' ? entry.barberName.trim() : (barberId ? 'Profesional ' + barberId : 'Sin asignar');
      const commissionRate = parseCommission(entry.barberCommission);
      const time = typeof entry.time === 'string' ? entry.time : '';
      if (!date) return null;
      return {
        id: entry.id || null,
        date,
        month,
        status,
        price,
        serviceId,
        serviceName,
        serviceKey,
        barberId,
        barberKey,
        barberName,
        commissionRate,
        time,
      };
    })
    .filter(Boolean);

  const allDates = Array.from(new Set(normalizedEntries.map((item) => item.date))).sort();
  // Empty date = periodo completo. Defaulting to the latest day hid finalized
  // reservations on earlier dates and made cancel-rate/ingresos look empty.
  const defaultDate = '';

  const barberDirectory = new Map();
  rawBarbers.forEach((barber) => {
    if (!barber || barber.id === undefined || barber.id === null) return;
    const key = String(barber.id);
    const name = typeof barber.name === 'string' && barber.name.trim() !== '' ? barber.name.trim() : 'Profesional ' + key;
    barberDirectory.set(key, name);
  });
  normalizedEntries.forEach((entry) => {
    if (!barberDirectory.has(entry.barberKey)) {
      barberDirectory.set(entry.barberKey, entry.barberName);
    }
  });
  if (!barberDirectory.has('unassigned')) {
    barberDirectory.set('unassigned', 'Sin asignar');
  }

  const formatCurrency = (() => {
    try {
      const formatter = new Intl.NumberFormat(locale, { style: 'currency', currency: currencyCode, maximumFractionDigits: 2 });
      return (value) => formatter.format(Number.isFinite(value) ? value : toNumber(value));
    } catch (error) {
      return (value) => {
        const numeric = Number.isFinite(value) ? value : toNumber(value);
        return currencySymbol + numeric.toFixed(2);
      };
    }
  })();

  const formatNumber = (value) => new Intl.NumberFormat(locale, { maximumFractionDigits: 0 }).format(Number.isFinite(value) ? value : toNumber(value));
  const formatDecimal = (value) => new Intl.NumberFormat(locale, { minimumFractionDigits: 1, maximumFractionDigits: 1 }).format(Number.isFinite(value) ? value : toNumber(value));

  const formatPercent = (value) => {
    const numeric = Number.isFinite(value) ? value : toNumber(value);
    try {
      const formatter = new Intl.NumberFormat(locale, { style: 'percent', maximumFractionDigits: 1 });
      return formatter.format(numeric);
    } catch (error) {
      return (numeric * 100).toFixed(1) + '%';
    }
  };

  const formatDateShort = (dateStr) => {
    const date = new Date(dateStr + 'T00:00:00');
    if (Number.isNaN(date.getTime())) return dateStr;
    return new Intl.DateTimeFormat(locale, { day: '2-digit', month: '2-digit', year: 'numeric' }).format(date);
  };

  const formatDateLabel = (dateStr) => {
    const date = new Date(dateStr + 'T00:00:00');
    if (Number.isNaN(date.getTime())) return dateStr;
    return new Intl.DateTimeFormat(locale, { weekday: 'short', day: 'numeric', month: 'short' }).format(date);
  };

  const setText = (node, value) => {
    if (!node) return;
    node.textContent = value;
  };

  const state = {
    date: defaultDate,
    barber: 'all',
    view: 'overview',
  };

  if (dayInput) {
    dayInput.min = allDates[0] || '';
    dayInput.max = allDates[allDates.length - 1] || '';
  }

  const getBarberLabel = (key) => {
    if (key === 'all' || !key) return 'Todos los profesionales';
    return barberDirectory.get(key) || 'Profesional';
  };

  const buildBarberOptions = () => {
    if (!barberSelect) return;
    const items = Array.from(barberDirectory.entries())
      .filter(([key]) => key !== 'all')
      .sort((a, b) => a[1].localeCompare(b[1], locale, { sensitivity: 'base' }));
    let options = '<option value="all">Todos los profesionales</option>';
    items.forEach(([key, label]) => {
      options += `<option value="${key}">${label}</option>`;
    });
    barberSelect.innerHTML = options;
    if (!state.barber || (state.barber !== 'all' && !barberDirectory.has(state.barber))) {
      state.barber = 'all';
    }
    barberSelect.value = state.barber;
  };

  const syncDayInput = () => {
    if (!dayInput) return;
    dayInput.value = state.date || '';
  };

  const updateViewVisibility = () => {
    sections.forEach((section) => {
      if (!section || !section.dataset) return;
      const sectionView = section.dataset.ledgerSection;
      section.hidden = sectionView !== state.view;
    });
  };

  const updateResetState = () => {
    if (!resetBtn) return;
    const isDefault =
      (state.date === defaultDate || (!state.date && !defaultDate)) &&
      state.barber === 'all' &&
      state.view === 'overview';
    resetBtn.disabled = isDefault;
  };

  const getFilteredEntries = () => {
    let list = normalizedEntries;
    if (state.date) {
      list = list.filter((entry) => entry.date === state.date);
    }
    if (state.barber && state.barber !== 'all') {
      list = list.filter((entry) => entry.barberKey === state.barber);
    }
    return list;
  };

  const buildStats = (list) => {
    const totals = { total: 0, attended: 0, cancelled: 0, active: 0 };
    let revenue = 0;
    let commissionTotal = 0;
    let projected = 0;

    const dailyMap = new Map();
    const barberMap = new Map();
    const barberDailyMap = new Map();
    const serviceMap = new Map();

    list.forEach((entry) => {
      totals.total += 1;
      const status = entry.status;
      const isAttended = STATUS_ATTENDED.has(status);
      const isCancelled = STATUS_CANCELLED.has(status);
      const isProjected = STATUS_PROJECTED.has(status);
      const isActive = STATUS_ACTIVE.has(status) && !isAttended && !isCancelled;
      const price = entry.price;
      const commissionRate = entry.commissionRate;
      const commissionAmount = isAttended && commissionRate !== null ? price * (commissionRate / 100) : 0;

      if (isAttended) {
        totals.attended += 1;
        revenue += price;
        commissionTotal += commissionAmount;
      }
      if (isCancelled) totals.cancelled += 1;
      if (isActive) totals.active += 1;
      if (isProjected) projected += price;

      const dayKey = entry.date;
      if (dayKey) {
        let day = dailyMap.get(dayKey);
        if (!day) {
          day = { date: dayKey, total: 0, attended: 0, cancelled: 0, active: 0, revenue: 0, commission: 0 };
          dailyMap.set(dayKey, day);
        }
        day.total += 1;
        if (isAttended) {
          day.attended += 1;
          day.revenue += price;
          day.commission += commissionAmount;
        }
        if (isCancelled) day.cancelled += 1;
        if (isActive) day.active += 1;
      }

      const serviceKey = entry.serviceKey;
      if (serviceKey) {
        let service = serviceMap.get(serviceKey);
        if (!service) {
          service = { key: serviceKey, name: entry.serviceName, total: 0, attended: 0, cancelled: 0, revenue: 0 };
          serviceMap.set(serviceKey, service);
        }
        service.total += 1;
        if (isAttended) {
          service.attended += 1;
          service.revenue += price;
        }
        if (isCancelled) service.cancelled += 1;
      }

      const barberKey = entry.barberKey;
      const barberName = entry.barberName;
      let barber = barberMap.get(barberKey);
      if (!barber) {
        barber = { key: barberKey, name: barberName, attended: 0, cancelled: 0, active: 0, revenue: 0, commission: 0 };
        barberMap.set(barberKey, barber);
      }
      if (isAttended) {
        barber.attended += 1;
        barber.revenue += price;
        barber.commission += commissionAmount;
      }
      if (isCancelled) barber.cancelled += 1;
      if (isActive) barber.active += 1;

      if (isAttended || isCancelled || isActive) {
        const barberDailyKey = `${entry.date}__${barberKey}`;
        let barberDaily = barberDailyMap.get(barberDailyKey);
        if (!barberDaily) {
          barberDaily = { date: entry.date, barberKey, barberName, attended: 0, cancelled: 0, active: 0, revenue: 0, commission: 0 };
          barberDailyMap.set(barberDailyKey, barberDaily);
        }
        if (isAttended) {
          barberDaily.attended += 1;
          barberDaily.revenue += price;
          barberDaily.commission += commissionAmount;
        }
        if (isCancelled) barberDaily.cancelled += 1;
        if (isActive) barberDaily.active += 1;
      }
    });

    const net = revenue - commissionTotal;
    const averageTicket = totals.attended > 0 ? revenue / totals.attended : 0;
    const cancelRate = totals.total > 0 ? totals.cancelled / totals.total : 0;

    const dailyList = Array.from(dailyMap.values())
      .map((day) => ({
        ...day,
        margin: day.revenue - day.commission,
        ticket: day.attended > 0 ? day.revenue / day.attended : 0,
      }))
      .sort((a, b) => {
        if (a.date === b.date) return 0;
        return a.date < b.date ? 1 : -1;
      });

    const barberList = Array.from(barberMap.values())
      .filter((item) => item.attended || item.cancelled || item.revenue || item.active)
      .map((item) => ({
        ...item,
        margin: item.revenue - item.commission,
        ticket: item.attended > 0 ? item.revenue / item.attended : 0,
        share: revenue > 0 ? item.revenue / revenue : 0,
      }))
      .sort((a, b) => {
        if (b.revenue !== a.revenue) return b.revenue - a.revenue;
        if (b.attended !== a.attended) return b.attended - a.attended;
        return a.name.localeCompare(b.name, locale, { sensitivity: 'base' });
      });

    const barberDailyList = Array.from(barberDailyMap.values())
      .filter((item) => item.attended || item.cancelled || item.active || item.revenue)
      .map((item) => ({
        ...item,
        margin: item.revenue - item.commission,
      }))
      .sort((a, b) => {
        if (a.date === b.date) {
          if (b.revenue !== a.revenue) return b.revenue - a.revenue;
          return a.barberName.localeCompare(b.barberName, locale, { sensitivity: 'base' });
        }
        return a.date < b.date ? 1 : -1;
      });

    const serviceList = Array.from(serviceMap.values())
      .map((service) => ({
        ...service,
        ticket: service.attended > 0 ? service.revenue / service.attended : 0,
        cancelRate: service.total > 0 ? service.cancelled / service.total : 0,
        share: revenue > 0 ? service.revenue / revenue : 0,
      }))
      .sort((a, b) => {
        if (b.revenue !== a.revenue) return b.revenue - a.revenue;
        if (b.attended !== a.attended) return b.attended - a.attended;
        return a.name.localeCompare(b.name, locale, { sensitivity: 'base' });
      });

    const topDay = dailyList.reduce((best, item) => {
      if (!best) return item;
      if (item.revenue > best.revenue) return item;
      if (item.revenue === best.revenue && item.attended > best.attended) return item;
      return best;
    }, null);

    const topBarber = barberList.length ? barberList[0] : null;
    const topService = serviceList.length ? serviceList[0] : null;

    return {
      totals,
      revenue,
      commissionTotal,
      net,
      projected,
      averageTicket,
      cancelRate,
      dailyList,
      barberList,
      barberDailyList,
      serviceList,
      topDay,
      topBarber,
      topService,
    };
  };

  const resetKpis = () => {
    Object.values(kpiEls).forEach((node) => setText(node, '-'));
    Object.values(kpiSubs).forEach((node) => setText(node, ''));
  };

  const resetMeta = () => {
    setText(metaTopDay, '-');
    setText(metaTopDayAmount, '-');
    setText(metaTopBarber, '-');
    setText(metaTopBarberAmount, '-');
    setText(metaTopService, '-');
    setText(metaTopServiceAmount, '-');
  };

  const updateKpis = (stats) => {
    const { totals } = stats;
    setText(kpiEls.revenue, formatCurrency(stats.revenue));
    setText(kpiSubs.revenue, totals.attended > 0 ? `Sobre ${formatNumber(totals.attended)} reservas finalizadas.` : 'Importe finalizado.');

    setText(kpiEls.attended, formatNumber(totals.attended));
    setText(kpiSubs.attended, totals.total > 0 ? `${formatDecimal((totals.attended / totals.total) * 100)}% del total.` : 'Finalizadas correctamente.');

    setText(kpiEls.cancelled, formatNumber(totals.cancelled));
    setText(kpiSubs.cancelled, totals.total > 0 ? `${formatDecimal((totals.cancelled / totals.total) * 100)}% del total.` : 'Cancelaciones registradas.');

    setText(kpiEls.active, formatNumber(totals.active));
    setText(kpiSubs.active, totals.active > 0 ? 'Reservas pendientes o en progreso.' : 'Sin reservas activas.');

    setText(kpiEls.ticket, stats.averageTicket > 0 ? formatCurrency(stats.averageTicket) : '-');
    setText(kpiSubs.ticket, totals.attended > 0 ? `Promedio sobre ${formatNumber(totals.attended)} atenciones.` : 'Promedio sin datos.');

    setText(kpiEls.commission, stats.commissionTotal > 0 ? formatCurrency(stats.commissionTotal) : formatCurrency(0));
    setText(kpiSubs.commission, stats.commissionTotal > 0 ? 'Total a liquidar a profesionales.' : 'Sin comisiones devengadas.');

    setText(kpiEls.net, formatCurrency(stats.net));
    setText(kpiSubs.net, stats.net >= 0 ? 'Ingresos netos del periodo.' : 'Margen negativo.');

    setText(kpiEls.projected, stats.projected > 0 ? formatCurrency(stats.projected) : formatCurrency(0));
    setText(kpiSubs.projected, stats.projected > 0 ? 'Reservas confirmadas por cobrar.' : 'Sin proyecciones pendientes.');

    setText(kpiEls.cancelRate, formatPercent(stats.cancelRate));
    setText(kpiSubs.cancelRate, totals.total > 0 ? 'Canceladas sobre el total.' : 'Sin reservas registradas.');
  };

  const updateMeta = (stats) => {
    const day = stats ? stats.topDay : null;
    const barber = stats ? stats.topBarber : null;
    const service = stats ? stats.topService : null;

    setText(metaTopDay, day ? formatDateLabel(day.date) : '-');
    setText(metaTopDayAmount, day ? formatCurrency(day.revenue) : '-');

    setText(metaTopBarber, barber ? barber.name : '-');
    setText(metaTopBarberAmount, barber ? formatCurrency(barber.revenue) : '-');

    setText(metaTopService, service ? service.name : '-');
    setText(metaTopServiceAmount, service ? formatCurrency(service.revenue) : '-');
  };

  const renderDailyTable = (rows) => {
    if (!dailyBody) return;
    if (!rows || !rows.length) {
      dailyBody.innerHTML = '<tr><td colspan="9" class="muted">Sin datos para el periodo seleccionado.</td></tr>';
      return;
    }
    const html = rows
      .map((row) => {
        const ticketDisplay = row.attended > 0 ? formatCurrency(row.ticket) : '-';
        return `
          <tr>
            <td>${formatDateShort(row.date)}</td>
            <td>${formatNumber(row.total)}</td>
            <td>${formatNumber(row.attended)}</td>
            <td>${formatNumber(row.cancelled)}</td>
            <td>${formatCurrency(row.revenue)}</td>
            <td>${formatCurrency(row.commission)}</td>
            <td>${formatCurrency(row.margin)}</td>
            <td>${ticketDisplay}</td>
            <td>${formatNumber(row.active)}</td>
          </tr>
        `;
      })
      .join('');
    dailyBody.innerHTML = html;
  };

  const renderServiceTopTable = (rows) => {
    if (!serviceTopBody) return;
    if (!rows || !rows.length) {
      serviceTopBody.innerHTML = '<tr><td colspan="5" class="muted">Sin datos para el periodo seleccionado.</td></tr>';
      return;
    }
    const topRows = rows.slice(0, 6);
    const html = topRows
      .map((row) => {
        const ticketDisplay = row.attended > 0 ? formatCurrency(row.ticket) : '-';
        return `
          <tr>
            <td>${row.name}</td>
            <td>${formatNumber(row.attended)}</td>
            <td>${formatCurrency(row.revenue)}</td>
            <td>${ticketDisplay}</td>
            <td>${formatPercent(row.share)}</td>
          </tr>
        `;
      })
      .join('');
    serviceTopBody.innerHTML = html;
  };

  const renderServiceDetailTable = (rows) => {
    if (!serviceDetailBody) return;
    if (!rows || !rows.length) {
      serviceDetailBody.innerHTML = '<tr><td colspan="7" class="muted">No existen reservas para el periodo.</td></tr>';
      return;
    }
    const html = rows
      .map((row) => {
        const ticketDisplay = row.attended > 0 ? formatCurrency(row.ticket) : '-';
        return `
          <tr>
            <td>${row.name}</td>
            <td>${formatNumber(row.total)}</td>
            <td>${formatNumber(row.attended)}</td>
            <td>${formatNumber(row.cancelled)}</td>
            <td>${formatCurrency(row.revenue)}</td>
            <td>${ticketDisplay}</td>
            <td>${formatPercent(row.cancelRate)}</td>
          </tr>
        `;
      })
      .join('');
    serviceDetailBody.innerHTML = html;
  };

  const renderBarberMonthlyTable = (rows) => {
    if (!barberMonthBody) return;
    if (!rows || !rows.length) {
      barberMonthBody.innerHTML = '<tr><td colspan="8" class="muted">Sin informacion disponible.</td></tr>';
      return;
    }
    const html = rows
      .map((row) => {
        const ticketDisplay = row.attended > 0 ? formatCurrency(row.ticket) : '-';
        return `
          <tr>
            <td>${row.name}</td>
            <td>${formatNumber(row.attended)}</td>
            <td>${formatNumber(row.cancelled)}</td>
            <td>${formatCurrency(row.revenue)}</td>
            <td>${formatCurrency(row.commission)}</td>
            <td>${formatCurrency(row.margin)}</td>
            <td>${ticketDisplay}</td>
            <td>${formatPercent(row.share)}</td>
          </tr>
        `;
      })
      .join('');
    barberMonthBody.innerHTML = html;
  };

  const renderBarberDailyTable = (rows) => {
    if (!barberDailyBody) return;
    if (!rows || !rows.length) {
      barberDailyBody.innerHTML = '<tr><td colspan="7" class="muted">Elige un periodo para ver resultados.</td></tr>';
      return;
    }
    const html = rows
      .map((row) => `
        <tr>
          <td>${formatDateShort(row.date)}</td>
          <td>${row.barberName}</td>
          <td>${formatNumber(row.attended)}</td>
          <td>${formatNumber(row.cancelled)}</td>
          <td>${formatCurrency(row.revenue)}</td>
          <td>${formatCurrency(row.commission)}</td>
          <td>${formatCurrency(row.margin)}</td>
        </tr>
      `)
      .join('');
    barberDailyBody.innerHTML = html;
  };

  const updatePeriodLabel = (stats) => {
    if (!periodLabel) return;
    const parts = [];
    if (state.date) {
      parts.push('Dia ' + formatDateLabel(state.date));
    } else if (allDates.length) {
      parts.push('Periodo completo (' + formatDateShort(allDates[0]) + ' – ' + formatDateShort(allDates[allDates.length - 1]) + ')');
    } else {
      parts.push('Sin reservas registradas');
    }
    if (state.barber !== 'all') {
      parts.push('Profesional: ' + getBarberLabel(state.barber));
    }
    if (stats && stats.totals.total > 0) {
      parts.push(formatNumber(stats.totals.total) + ' reservas');
    }
    periodLabel.textContent = parts.length ? parts.join(' | ') : 'Selecciona un dia para comenzar.';
  };

  const renderTables = (stats) => {
    renderDailyTable(stats.dailyList);
    renderServiceTopTable(stats.serviceList);
    renderServiceDetailTable(stats.serviceList);
    renderBarberMonthlyTable(stats.barberList);
    renderBarberDailyTable(stats.barberDailyList);
  };

  const renderEmpty = () => {
    resetKpis();
    resetMeta();
    updatePeriodLabel(null);
    renderDailyTable([]);
    renderServiceTopTable([]);
    renderServiceDetailTable([]);
    renderBarberMonthlyTable([]);
    renderBarberDailyTable([]);
  };

  const render = () => {
    syncDayInput();
    updateViewVisibility();
    updateResetState();

    const filtered = getFilteredEntries();
    const hasData = filtered.length > 0;
    if (emptyNotice) emptyNotice.hidden = hasData;

    if (!hasData) {
      renderEmpty();
      return;
    }

    const stats = buildStats(filtered);
    updateKpis(stats);
    updateMeta(stats);
    updatePeriodLabel(stats);
    renderTables(stats);
  };

  const resetFilters = () => {
    state.date = defaultDate;
    state.barber = 'all';
    state.view = 'overview';

    buildBarberOptions();
    syncDayInput();
    if (viewSelect) viewSelect.value = state.view;
    if (barberSelect) barberSelect.value = state.barber;

    updateViewVisibility();
    updateResetState();
    render();
  };

  const openModal = () => {
    if (modalLoading) modalLoading.show(modal);
    // Show first so a render error cannot leave the CTA looking dead.
    modal.hidden = false;
    requestAnimationFrame(() => {
      modal.classList.add('is-visible');
    });
    try {
      resetFilters();
    } catch (error) {
      console.error('[reservas-summary] No se pudo renderizar el panel:', error);
    } finally {
      if (modalLoading) modalLoading.hide(modal);
    }
  };

  const closeModal = () => {
    modal.classList.remove('is-visible');
    modal.hidden = true;
    if (modalLoading) modalLoading.hide(modal);
  };

  triggers.forEach((trigger) => {
    trigger.addEventListener('click', (event) => {
      event.preventDefault();
      openModal();
    });
  });

  closeButtons.forEach((button) => {
    button.addEventListener('click', (event) => {
      event.preventDefault();
      closeModal();
    });
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && !modal.hidden) {
      closeModal();
    }
  });

  if (dayInput) {
    dayInput.addEventListener('change', () => {
      state.date = dayInput.value || '';
      render();
    });
  }

  if (barberSelect) {
    barberSelect.addEventListener('change', () => {
      state.barber = barberSelect.value || 'all';
      render();
    });
  }

  if (viewSelect) {
    viewSelect.addEventListener('change', () => {
      const value = viewSelect.value;
      if (value === 'barbers' || value === 'services' || value === 'overview') {
        state.view = value;
      } else {
        state.view = 'overview';
      }
      updateViewVisibility();
      updateResetState();
    });
  }

  if (resetBtn) {
    resetBtn.addEventListener('click', () => {
      resetFilters();
    });
  }

  if (!allDates.length) {
    buildBarberOptions();
    syncDayInput();
    updateViewVisibility();
    updateResetState();
    render();
  }
})();
