(function adminProductosSummaryModal() {
  const modal = document.querySelector('[data-admin-modal="productos-summary"]');
  if (!modal) return;

  const modalLoading = window.AdminModalLoading;

  const triggers = Array.from(document.querySelectorAll('[data-admin-summary-modal="productos-summary"]'));
  if (!triggers.length) return;

  const closeButtons = Array.from(modal.querySelectorAll('[data-admin-productos-summary-close]'));

  const periodModeSelect = modal.querySelector('[data-product-ledger-period-mode]');
  const monthSelect = modal.querySelector('[data-product-ledger-month]');
  const startInput = modal.querySelector('[data-product-ledger-start]');
  const endInput = modal.querySelector('[data-product-ledger-end]');
  const typeSelect = modal.querySelector('[data-product-ledger-type]');
  const clientSelect = modal.querySelector('[data-product-ledger-client]');
  const resetBtn = modal.querySelector('[data-product-ledger-reset]');

  const monthWrapper = modal.querySelector('[data-product-ledger-month-wrapper]');
  const startWrapper = modal.querySelector('[data-product-ledger-start-wrapper]');
  const endWrapper = modal.querySelector('[data-product-ledger-end-wrapper]');

  const periodLabel = modal.querySelector('[data-product-ledger-period-label]');
  const emptyNotice = modal.querySelector('[data-product-ledger-empty]');

  const metaTopDay = modal.querySelector('[data-product-ledger-top-day]');
  const metaTopDayRevenue = modal.querySelector('[data-product-ledger-top-day-revenue]');
  const metaTopProduct = modal.querySelector('[data-product-ledger-top-product]');
  const metaTopProductRevenue = modal.querySelector('[data-product-ledger-top-product-revenue]');
  const metaTopClient = modal.querySelector('[data-product-ledger-top-client]');
  const metaTopClientRevenue = modal.querySelector('[data-product-ledger-top-client-revenue]');

  const kpiEls = {
    revenue: modal.querySelector('[data-product-ledger-kpi="revenue"]'),
    orders: modal.querySelector('[data-product-ledger-kpi="orders"]'),
    pending: modal.querySelector('[data-product-ledger-kpi="pending"]'),
    units: modal.querySelector('[data-product-ledger-kpi="units"]'),
    clients: modal.querySelector('[data-product-ledger-kpi="clients"]'),
    ticket: modal.querySelector('[data-product-ledger-kpi="ticket"]'),
    points: modal.querySelector('[data-product-ledger-kpi="points"]'),
    avgItems: modal.querySelector('[data-product-ledger-kpi="avg-items"]'),
  };

  const kpiSubs = {
    revenue: modal.querySelector('[data-product-ledger-kpi-sub="revenue"]'),
    orders: modal.querySelector('[data-product-ledger-kpi-sub="orders"]'),
    pending: modal.querySelector('[data-product-ledger-kpi-sub="pending"]'),
    units: modal.querySelector('[data-product-ledger-kpi-sub="units"]'),
    clients: modal.querySelector('[data-product-ledger-kpi-sub="clients"]'),
    ticket: modal.querySelector('[data-product-ledger-kpi-sub="ticket"]'),
    points: modal.querySelector('[data-product-ledger-kpi-sub="points"]'),
    avgItems: modal.querySelector('[data-product-ledger-kpi-sub="avg-items"]'),
  };

  const dailyBody = modal.querySelector('[data-product-ledger-daily-body]');
  const productBody = modal.querySelector('[data-product-ledger-product-body]');
  const clientBody = modal.querySelector('[data-product-ledger-client-body]');
  const typeBody = modal.querySelector('[data-product-ledger-type-body]');

  const rawMetrics = (typeof window.ADMIN_PRODUCT_METRICS === 'object' && window.ADMIN_PRODUCT_METRICS) || {};
  const locale = (typeof rawMetrics.locale === 'string' ? rawMetrics.locale : 'es-UY').replace('_', '-');
  const currencyCode = typeof rawMetrics.currency_code === 'string' && rawMetrics.currency_code ? rawMetrics.currency_code : 'USD';
  const currencySymbol = typeof rawMetrics.currency_symbol === 'string' && rawMetrics.currency_symbol ? rawMetrics.currency_symbol : '$';

  const rawEntries = Array.isArray(rawMetrics.entries) ? rawMetrics.entries : [];
  const rawProducts = Array.isArray(rawMetrics.products) ? rawMetrics.products : [];
  const rawClients = Array.isArray(rawMetrics.clients) ? rawMetrics.clients : [];
  const rawTypes = Array.isArray(rawMetrics.types) ? rawMetrics.types : [];

  const STATUS_COMPLETED = new Set(['finalizado', 'finalizada', 'completado', 'completada', 'pagado', 'pagada', 'entregado', 'entregada', 'cerrado', 'cerrada']);
  const STATUS_PENDING = new Set(['pendiente', 'en progreso', 'procesando', 'aceptado', 'confirmado']);
  const STATUS_CANCELLED = new Set(['cancelado', 'cancelada', 'anulado', 'anulada']);

  const toNumber = (value) => {
    if (typeof value === 'number' && Number.isFinite(value)) return value;
    if (typeof value === 'string' && value.trim() !== '') {
      const numeric = Number(value.replace(',', '.'));
      if (Number.isFinite(numeric)) return numeric;
    }
    return 0;
  };

  const normalizeStatus = (value) => {
    if (typeof value !== 'string') return '';
    return value.trim().toLowerCase();
  };

  const normalizedEntries = rawEntries
    .map((entry) => {
      const orderId = entry.order_id !== undefined && entry.order_id !== null ? Number(entry.order_id) : NaN;
      if (!Number.isFinite(orderId)) return null;
      const date = typeof entry.date === 'string' ? entry.date.slice(0, 10) : '';
      if (!date) return null;
      const month = typeof entry.month === 'string' && entry.month ? entry.month : date.slice(0, 7);
      const status = normalizeStatus(entry.status);
      const quantity = Math.max(0, toNumber(entry.quantity));
      const unitPrice = toNumber(entry.unit_price);
      const unitPoints = toNumber(entry.unit_points);
      const productId = entry.product_id !== undefined && entry.product_id !== null ? Number(entry.product_id) : NaN;
      if (!Number.isFinite(productId) || quantity <= 0) return null;
      return {
        orderId: orderId,
        clientId: entry.client_id !== undefined && entry.client_id !== null ? Number(entry.client_id) : null,
        clientName: typeof entry.client_name === 'string' ? entry.client_name : null,
        productId: productId,
        productName: typeof entry.product_name === 'string' ? entry.product_name : 'Producto',
        productType: typeof entry.product_type === 'string' && entry.product_type.trim() !== '' ? entry.product_type.trim() : 'Otro',
        quantity: quantity,
        unitPrice: unitPrice,
        unitPoints: unitPoints,
        date: date,
        month: month,
        time: typeof entry.time === 'string' ? entry.time : '',
        status: status,
      };
    })
    .filter(Boolean);

  const months = Array.from(new Set(normalizedEntries.map((item) => item.month).filter((value) => value && value !== ''))).sort();
  const defaultMonth = months.length ? months[months.length - 1] : '';

  const allDates = Array.from(new Set(normalizedEntries.map((item) => item.date))).sort();

  const productDirectory = new Map();
  rawProducts.forEach((product) => {
    if (!product || product.id === undefined || product.id === null) return;
    const key = String(product.id);
    const name = typeof product.name === 'string' && product.name.trim() !== '' ? product.name.trim() : 'Producto ' + key;
    productDirectory.set(key, {
      name,
      type: typeof product.type === 'string' && product.type.trim() !== '' ? product.type.trim() : 'Otro',
      points: typeof product.points === 'number' ? product.points : toNumber(product.points),
    });
  });

  const clientDirectory = new Map();
  rawClients.forEach((client) => {
    if (!client || client.id === undefined || client.id === null) return;
    const key = String(client.id);
    const name = typeof client.name === 'string' && client.name.trim() !== '' ? client.name.trim() : 'Cliente ' + key;
    clientDirectory.set(key, name);
  });

  const uniqueTypes = new Set();
  rawTypes.forEach((type) => {
    if (typeof type === 'string' && type.trim() !== '') {
      uniqueTypes.add(type.trim());
    }
  });
  normalizedEntries.forEach((entry) => {
    if (entry.productType) uniqueTypes.add(entry.productType);
  });
  const typeOptions = Array.from(uniqueTypes);
  typeOptions.sort((a, b) => a.localeCompare(b, locale, { sensitivity: 'base' }));

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

  const formatMonthLabel = (monthStr) => {
    if (!monthStr) return '';
    const parts = monthStr.split('-');
    if (parts.length !== 2) return monthStr;
    const [year, month] = parts;
    const date = new Date(`${year}-${month}-01T00:00:00`);
    if (Number.isNaN(date.getTime())) return monthStr;
    const label = new Intl.DateTimeFormat(locale, { month: 'long', year: 'numeric' }).format(date);
    return label.charAt(0).toUpperCase() + label.slice(1);
  };

  const setText = (node, value) => {
    if (!node) return;
    node.textContent = value;
  };

  const state = {
    periodMode: 'month',
    month: defaultMonth,
    start: '',
    end: '',
    type: 'all',
    client: 'all',
  };

  if (startInput) {
    startInput.min = allDates[0] || '';
    startInput.max = allDates[allDates.length - 1] || '';
  }
  if (endInput) {
    endInput.min = allDates[0] || '';
    endInput.max = allDates[allDates.length - 1] || '';
  }

  const buildMonthOptions = () => {
    if (!monthSelect) return;
    if (!months.length) {
      monthSelect.innerHTML = '<option value="">Sin datos disponibles</option>';
      monthSelect.disabled = true;
      return;
    }
    let options = '';
    months.forEach((month) => {
      options += `<option value="${month}">${formatMonthLabel(month)}</option>`;
    });
    monthSelect.innerHTML = options;
    monthSelect.disabled = false;
    if (!state.month || !months.includes(state.month)) {
      state.month = defaultMonth || months[0];
    }
    monthSelect.value = state.month || '';
  };

  const buildTypeOptions = () => {
    if (!typeSelect) return;
    let options = '<option value="all">Todos los tipos</option>';
    typeOptions.forEach((type) => {
      const value = type;
      const label = type.charAt(0).toUpperCase() + type.slice(1);
      options += `<option value="${value}">${label}</option>`;
    });
    typeSelect.innerHTML = options;
    if (!typeOptions.includes(state.type)) {
      state.type = 'all';
    }
    typeSelect.value = state.type;
  };

  const buildClientOptions = () => {
    if (!clientSelect) return;
    let options = '<option value="all">Todos los clientes</option>';
    rawClients
      .filter((client) => client && client.id !== undefined && client.id !== null)
      .sort((a, b) => {
        const nameA = (typeof a.name === 'string' ? a.name : '').toLowerCase();
        const nameB = (typeof b.name === 'string' ? b.name : '').toLowerCase();
        return nameA.localeCompare(nameB, locale, { sensitivity: 'base' });
      })
      .forEach((client) => {
        const value = String(client.id);
        const label = typeof client.name === 'string' && client.name.trim() !== '' ? client.name.trim() : `Cliente ${value}`;
        options += `<option value="${value}">${label}</option>`;
      });
    clientSelect.innerHTML = options;
    if (state.client !== 'all' && !clientDirectory.has(state.client)) {
      state.client = 'all';
    }
    clientSelect.value = state.client;
  };

  const updatePeriodInputs = () => {
    const isMonth = state.periodMode === 'month';
    if (monthWrapper) monthWrapper.hidden = !isMonth;
    if (startWrapper) startWrapper.hidden = isMonth;
    if (endWrapper) endWrapper.hidden = isMonth;
    if (monthSelect) monthSelect.disabled = !isMonth || !months.length;
    if (startInput) {
      startInput.disabled = isMonth;
      startInput.value = state.start || '';
    }
    if (endInput) {
      endInput.disabled = isMonth;
      endInput.value = state.end || '';
    }
  };

  const updateResetState = () => {
    if (!resetBtn) return;
    const isDefault =
      state.periodMode === 'month' &&
      (state.month === defaultMonth || (!state.month && !defaultMonth)) &&
      !state.start &&
      !state.end &&
      state.type === 'all' &&
      state.client === 'all';
    resetBtn.disabled = isDefault;
  };

  const getRangeForMonth = (monthId) => {
    if (!monthId) return null;
    const monthEntries = normalizedEntries.filter((entry) => entry.month === monthId);
    if (!monthEntries.length) return null;
    const dates = monthEntries.map((entry) => entry.date).sort();
    return { start: dates[0], end: dates[dates.length - 1] };
  };

  const ensureCustomDates = () => {
    if (state.periodMode !== 'custom') return;
    if (state.start && state.end) return;
    const monthRange = getRangeForMonth(state.month) || (allDates.length ? { start: allDates[0], end: allDates[allDates.length - 1] } : null);
    if (monthRange) {
      state.start = state.start || monthRange.start;
      state.end = state.end || monthRange.end;
    }
  };

  const normalizeRange = () => {
    if (!state.start || !state.end) return;
    if (state.start <= state.end) return;
    const temp = state.start;
    state.start = state.end;
    state.end = temp;
  };

  const getFilteredEntries = () => {
    let list = normalizedEntries;
    if (state.periodMode === 'month') {
      if (state.month) {
        list = list.filter((entry) => entry.month === state.month);
      }
    } else {
      const from = state.start || '';
      const to = state.end || '';
      list = list.filter((entry) => {
        if (from && entry.date < from) return false;
        if (to && entry.date > to) return false;
        return true;
      });
    }
    if (state.type && state.type !== 'all') {
      list = list.filter((entry) => entry.productType === state.type);
    }
    if (state.client && state.client !== 'all') {
      list = list.filter((entry) => entry.clientId !== null && String(entry.clientId) === state.client);
    }
    return list;
  };

  const buildStats = (entries) => {
    const ordersMap = new Map();
    entries.forEach((entry) => {
      const key = entry.orderId;
      let order = ordersMap.get(key);
      if (!order) {
        order = {
          orderId: key,
          date: entry.date,
          month: entry.month,
          clientId: entry.clientId,
          clientName: entry.clientId !== null ? (clientDirectory.get(String(entry.clientId)) || entry.clientName || `Cliente ${entry.clientId}`) : 'Cliente sin asignar',
          statusSet: new Set(),
          revenue: 0,
          units: 0,
          points: 0,
          totalQuantity: 0,
          completed: false,
          pending: false,
          cancelled: false,
        };
        ordersMap.set(key, order);
      }
      order.statusSet.add(entry.status);
      if (order.clientId === null && entry.clientId !== null) {
        order.clientId = entry.clientId;
        order.clientName = clientDirectory.get(String(entry.clientId)) || entry.clientName || `Cliente ${entry.clientId}`;
      }
      order.totalQuantity += entry.quantity;
      if (STATUS_COMPLETED.has(entry.status)) {
        const revenueLine = entry.unitPrice * entry.quantity;
        const pointsLine = entry.unitPoints * entry.quantity;
        order.revenue += revenueLine;
        order.units += entry.quantity;
        order.points += pointsLine;
        order.completed = true;
      }
      if (STATUS_PENDING.has(entry.status)) {
        order.pending = true;
      }
      if (STATUS_CANCELLED.has(entry.status)) {
        order.cancelled = true;
      }
    });

    const orders = Array.from(ordersMap.values());
    const totals = { all: orders.length, completed: 0, pending: 0 };
    let revenue = 0;
    let units = 0;
    let points = 0;
    const clientsSet = new Set();

    orders.forEach((order) => {
      revenue += order.revenue;
      units += order.units;
      points += order.points;
      if (order.clientId !== null) {
        clientsSet.add(order.clientId);
      }
      if (order.completed) {
        totals.completed += 1;
      } else if (order.pending && !order.cancelled) {
        totals.pending += 1;
      }
    });

    const averageTicket = totals.completed > 0 ? revenue / totals.completed : 0;
    const averageItems = totals.completed > 0 ? units / totals.completed : 0;

    const dailyMap = new Map();
    entries.forEach((entry) => {
      const dayKey = entry.date;
      let day = dailyMap.get(dayKey);
      if (!day) {
        day = { date: dayKey, orders: new Set(), clients: new Set(), revenue: 0, units: 0, points: 0, completedOrders: 0 };
        dailyMap.set(dayKey, day);
      }
      day.orders.add(entry.orderId);
      if (entry.clientId !== null) {
        day.clients.add(entry.clientId);
      }
      if (STATUS_COMPLETED.has(entry.status)) {
        day.revenue += entry.unitPrice * entry.quantity;
        day.units += entry.quantity;
        day.points += entry.unitPoints * entry.quantity;
      }
    });

    orders.forEach((order) => {
      const day = dailyMap.get(order.date);
      if (!day) return;
      if (order.completed) {
        day.completedOrders += 1;
      }
    });

    const dailyList = Array.from(dailyMap.values())
      .map((day) => ({
        date: day.date,
        orders: day.orders.size,
        completed: day.completedOrders,
        units: day.units,
        revenue: day.revenue,
        points: day.points,
        clients: day.clients.size,
        ticket: day.completedOrders > 0 ? day.revenue / day.completedOrders : 0,
      }))
      .sort((a, b) => (a.date < b.date ? 1 : -1));

    const productMap = new Map();
    entries.forEach((entry) => {
      if (!STATUS_COMPLETED.has(entry.status)) return;
      const key = entry.productId;
      let product = productMap.get(key);
      if (!product) {
        product = {
          productId: key,
          name: entry.productName,
          type: entry.productType,
          units: 0,
          revenue: 0,
          points: 0,
          orders: new Set(),
        };
        productMap.set(key, product);
      }
      product.units += entry.quantity;
      product.revenue += entry.unitPrice * entry.quantity;
      product.points += entry.unitPoints * entry.quantity;
      product.orders.add(entry.orderId);
    });

    const productList = Array.from(productMap.values())
      .map((product) => ({
        ...product,
        orders: product.orders.size,
        share: revenue > 0 ? product.revenue / revenue : 0,
      }))
      .sort((a, b) => {
        if (b.revenue !== a.revenue) return b.revenue - a.revenue;
        if (b.units !== a.units) return b.units - a.units;
        return a.name.localeCompare(b.name, locale, { sensitivity: 'base' });
      });

    const clientMap = new Map();
    orders.forEach((order) => {
      if (!order.completed || order.revenue <= 0) return;
      const key = order.clientId !== null ? String(order.clientId) : 'sin-asignar';
      let client = clientMap.get(key);
      if (!client) {
        client = {
          clientId: order.clientId,
          name: order.clientId !== null ? (clientDirectory.get(String(order.clientId)) || order.clientName) : 'Cliente sin asignar',
          orders: 0,
          units: 0,
          revenue: 0,
          points: 0,
        };
        clientMap.set(key, client);
      }
      client.orders += 1;
      client.units += order.units;
      client.revenue += order.revenue;
      client.points += order.points;
    });

    const clientList = Array.from(clientMap.values())
      .sort((a, b) => {
        if (b.revenue !== a.revenue) return b.revenue - a.revenue;
        if (b.orders !== a.orders) return b.orders - a.orders;
        return a.name.localeCompare(b.name, locale, { sensitivity: 'base' });
      });

    const typeMap = new Map();
    productList.forEach((product) => {
      const key = product.type || 'Otro';
      let type = typeMap.get(key);
      if (!type) {
        type = { type: key, orders: 0, units: 0, revenue: 0 };
        typeMap.set(key, type);
      }
      type.orders += product.orders;
      type.units += product.units;
      type.revenue += product.revenue;
    });

    const typeList = Array.from(typeMap.values())
      .map((type) => ({
        ...type,
        share: revenue > 0 ? type.revenue / revenue : 0,
      }))
      .sort((a, b) => {
        if (b.revenue !== a.revenue) return b.revenue - a.revenue;
        return a.type.localeCompare(b.type, locale, { sensitivity: 'base' });
      });

    const topDay = dailyList.reduce((best, item) => {
      if (!best) return item;
      if (item.revenue > best.revenue) return item;
      if (item.revenue === best.revenue && item.units > best.units) return item;
      return best;
    }, null);

    const topProduct = productList.length ? productList[0] : null;
    const topClient = clientList.length ? clientList[0] : null;

    return {
      totals,
      revenue,
      units,
      points,
      averageTicket,
      averageItems,
      uniqueClients: clientsSet.size,
      dailyList,
      productList,
      clientList,
      typeList,
      topDay,
      topProduct,
      topClient,
    };
  };

  const resetKpis = () => {
    Object.values(kpiEls).forEach((node) => setText(node, '-'));
    Object.values(kpiSubs).forEach((node) => setText(node, ''));
  };

  const resetMeta = () => {
    setText(metaTopDay, '-');
    setText(metaTopDayRevenue, '-');
    setText(metaTopProduct, '-');
    setText(metaTopProductRevenue, '-');
    setText(metaTopClient, '-');
    setText(metaTopClientRevenue, '-');
  };

  const updateKpis = (stats) => {
    setText(kpiEls.revenue, formatCurrency(stats.revenue));
    setText(kpiSubs.revenue, stats.totals.completed > 0 ? `Sobre ${formatNumber(stats.totals.completed)} pedidos completados.` : 'Sin ventas finalizadas.');

    setText(kpiEls.orders, formatNumber(stats.totals.completed));
    setText(kpiSubs.orders, `De ${formatNumber(stats.totals.all)} pedidos.`);

    setText(kpiEls.pending, formatNumber(stats.totals.pending));
    setText(kpiSubs.pending, stats.totals.pending > 0 ? 'Pendientes de cierre.' : 'Sin pedidos pendientes.');

    setText(kpiEls.units, formatNumber(stats.units));
    setText(kpiSubs.units, stats.totals.completed > 0 ? `Promedio ${formatDecimal(stats.units / stats.totals.completed)} por pedido.` : 'Unidades vendidas.');

    setText(kpiEls.clients, formatNumber(stats.uniqueClients));
    setText(kpiSubs.clients, stats.uniqueClients > 0 ? 'Compradores en el periodo.' : 'Sin clientes registrados.');

    setText(kpiEls.ticket, stats.averageTicket > 0 ? formatCurrency(stats.averageTicket) : '-');
    setText(kpiSubs.ticket, stats.totals.completed > 0 ? 'Ticket promedio finalizado.' : 'Sin pedidos completados.');

    setText(kpiEls.points, formatNumber(stats.points));
    setText(kpiSubs.points, stats.points > 0 ? 'Puntos generados para clientes.' : 'Sin puntos acumulados.');

    setText(kpiEls.avgItems, stats.averageItems > 0 ? formatDecimal(stats.averageItems) : '-');
    setText(kpiSubs.avgItems, stats.totals.completed > 0 ? 'Unidades promedio por pedido.' : 'Sin pedidos completados.');
  };

  const updateMeta = (stats) => {
    const day = stats.topDay;
    const product = stats.topProduct;
    const client = stats.topClient;

    setText(metaTopDay, day ? formatDateShort(day.date) : '-');
    setText(metaTopDayRevenue, day ? formatCurrency(day.revenue) : '-');

    setText(metaTopProduct, product ? product.name : '-');
    setText(metaTopProductRevenue, product ? formatCurrency(product.revenue) : '-');

    setText(metaTopClient, client ? client.name : '-');
    setText(metaTopClientRevenue, client ? formatCurrency(client.revenue) : '-');
  };

  const renderDailyTable = (rows) => {
    if (!dailyBody) return;
    if (!rows || !rows.length) {
      dailyBody.innerHTML = '<tr><td colspan="7" class="muted">Sin datos para el periodo seleccionado.</td></tr>';
      return;
    }
    const html = rows
      .map((row) => `
        <tr>
          <td>${formatDateShort(row.date)}</td>
          <td>${formatNumber(row.orders)}</td>
          <td>${formatNumber(row.units)}</td>
          <td>${formatCurrency(row.revenue)}</td>
          <td>${row.ticket > 0 ? formatCurrency(row.ticket) : '-'}</td>
          <td>${formatNumber(row.points)}</td>
          <td>${formatNumber(row.clients)}</td>
        </tr>
      `)
      .join('');
    dailyBody.innerHTML = html;
  };

  const renderProductTable = (rows) => {
    if (!productBody) return;
    if (!rows || !rows.length) {
      productBody.innerHTML = '<tr><td colspan="6" class="muted">Sin datos para el periodo seleccionado.</td></tr>';
      return;
    }
    const html = rows
      .map((row) => `
        <tr>
          <td>${row.name}</td>
          <td>${row.type}</td>
          <td>${formatNumber(row.units)}</td>
          <td>${formatCurrency(row.revenue)}</td>
          <td>${formatNumber(row.points)}</td>
          <td>${formatPercent(row.share)}</td>
        </tr>
      `)
      .join('');
    productBody.innerHTML = html;
  };

  const renderClientTable = (rows) => {
    if (!clientBody) return;
    if (!rows || !rows.length) {
      clientBody.innerHTML = '<tr><td colspan="5" class="muted">Sin clientes en el periodo seleccionado.</td></tr>';
      return;
    }
    const html = rows
      .map((row) => `
        <tr>
          <td>${row.name}</td>
          <td>${formatNumber(row.orders)}</td>
          <td>${formatNumber(row.units)}</td>
          <td>${formatCurrency(row.revenue)}</td>
          <td>${formatNumber(row.points)}</td>
        </tr>
      `)
      .join('');
    clientBody.innerHTML = html;
  };

  const renderTypeTable = (rows, totalRevenue) => {
    if (!typeBody) return;
    if (!rows || !rows.length) {
      typeBody.innerHTML = '<tr><td colspan="5" class="muted">Sin datos para el periodo seleccionado.</td></tr>';
      return;
    }
    const html = rows
      .map((row) => `
        <tr>
          <td>${row.type}</td>
          <td>${formatNumber(row.orders)}</td>
          <td>${formatNumber(row.units)}</td>
          <td>${formatCurrency(row.revenue)}</td>
          <td>${formatPercent(row.share)}</td>
        </tr>
      `)
      .join('');
    typeBody.innerHTML = html;
  };

  const updatePeriodLabel = (stats) => {
    if (!periodLabel) return;
    const parts = [];
    if (state.periodMode === 'month') {
      parts.push(state.month ? formatMonthLabel(state.month) : 'Mes sin definir');
    } else {
      const from = state.start ? formatDateShort(state.start) : 'sin inicio';
      const to = state.end ? formatDateShort(state.end) : 'sin fin';
      parts.push(`Rango ${from} a ${to}`);
    }
    if (state.type !== 'all') {
      parts.push(`Tipo: ${state.type}`);
    }
    if (state.client !== 'all') {
      const clientName = clientDirectory.get(state.client) || `Cliente ${state.client}`;
      parts.push(`Cliente: ${clientName}`);
    }
    if (stats && stats.totals.all > 0) {
      parts.push(`${formatNumber(stats.totals.all)} pedidos`);
    }
    periodLabel.textContent = parts.length ? parts.join(' | ') : 'Selecciona un periodo para comenzar.';
  };

  const render = () => {
    ensureCustomDates();
    normalizeRange();
    updatePeriodInputs();
    updateResetState();

    const filtered = getFilteredEntries();
    const hasData = filtered.length > 0;
    if (emptyNotice) emptyNotice.hidden = hasData;

    if (!hasData) {
      resetKpis();
      resetMeta();
      updatePeriodLabel(null);
      renderDailyTable([]);
      renderProductTable([]);
      renderClientTable([]);
      renderTypeTable([], 0);
      return;
    }

    const stats = buildStats(filtered);
    updateKpis(stats);
    updateMeta(stats);
    updatePeriodLabel(stats);
    renderDailyTable(stats.dailyList);
    renderProductTable(stats.productList.slice(0, 8));
    renderClientTable(stats.clientList.slice(0, 8));
    renderTypeTable(stats.typeList, stats.revenue);
  };

  const resetFilters = () => {
    state.periodMode = 'month';
    state.month = defaultMonth || months[months.length - 1] || '';
    state.start = '';
    state.end = '';
    state.type = 'all';
    state.client = 'all';

    if (periodModeSelect) periodModeSelect.value = state.periodMode;
    if (monthSelect) monthSelect.value = state.month || '';
    if (startInput) startInput.value = '';
    if (endInput) endInput.value = '';
    if (typeSelect) typeSelect.value = state.type;
    if (clientSelect) clientSelect.value = state.client;

    updatePeriodInputs();
    updateResetState();
    render();
  };

  const openModal = () => {
    if (modalLoading) modalLoading.show(modal);
    state.periodMode = 'month';
    state.month = defaultMonth || months[months.length - 1] || '';
    state.start = '';
    state.end = '';
    state.type = 'all';
    state.client = 'all';

    if (periodModeSelect) periodModeSelect.value = state.periodMode;

    buildMonthOptions();
    buildTypeOptions();
    buildClientOptions();
    updatePeriodInputs();
    updateResetState();
    render();

    modal.hidden = false;
    requestAnimationFrame(() => {
      modal.classList.add('is-visible');
      if (modalLoading) modalLoading.hide(modal);
    });
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

  if (periodModeSelect) {
    periodModeSelect.addEventListener('change', () => {
      const value = periodModeSelect.value === 'custom' ? 'custom' : 'month';
      state.periodMode = value;
      if (state.periodMode === 'custom') {
        ensureCustomDates();
      }
      render();
    });
  }

  if (monthSelect) {
    monthSelect.addEventListener('change', () => {
      const value = monthSelect.value;
      if (!value) return;
      state.month = value;
      if (state.periodMode === 'custom') {
        const range = getRangeForMonth(value);
        if (range) {
          state.start = range.start;
          state.end = range.end;
        }
      }
      render();
    });
  }

  if (startInput) {
    startInput.addEventListener('change', () => {
      state.start = startInput.value || '';
      render();
    });
  }

  if (endInput) {
    endInput.addEventListener('change', () => {
      state.end = endInput.value || '';
      render();
    });
  }

  if (typeSelect) {
    typeSelect.addEventListener('change', () => {
      state.type = typeSelect.value || 'all';
      render();
    });
  }

  if (clientSelect) {
    clientSelect.addEventListener('change', () => {
      state.client = clientSelect.value || 'all';
      render();
    });
  }

  if (resetBtn) {
    resetBtn.addEventListener('click', () => {
      resetFilters();
    });
  }

  if (!months.length) {
    buildTypeOptions();
    buildClientOptions();
    updatePeriodInputs();
    updateResetState();
    render();
  }
})();
