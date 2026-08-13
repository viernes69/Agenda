(function adminOrdersPanel() {
  const ordersDetails = document.querySelector('.admin-orders');
  if (!ordersDetails) return;

  const list = ordersDetails.querySelector('[data-role="orders-list"]');
  const filterButtons = Array.from(ordersDetails.querySelectorAll('.admin-orders__filter-btn'));
  if (!list || !filterButtons.length) return;

  const items = Array.from(list.querySelectorAll('.admin-orders__item'));
  const table = document.querySelector('[data-admin-orders-table]');
  const selects = Array.from(document.querySelectorAll('.admin-orders__status-select'));
  const badge = ordersDetails.querySelector('.admin-orders__badge');
  const emptyState = ordersDetails.querySelector('.admin-orders__empty[data-role="no-results"]');
  const autoPrintToggle = document.querySelector('[data-admin-orders-autoprint]');
  const apiUrl = '../../../src/API/Autoload.php';

  const statusCounts = filterButtons.reduce((acc, btn) => {
    const status = btn.dataset.status || '';
    const count = parseInt(btn.dataset.count || '0', 10);
    acc[status] = Number.isNaN(count) ? 0 : count;
    return acc;
  }, {});

  const statusLabelMap = filterButtons.reduce((acc, btn) => {
    const status = btn.dataset.status || '';
    const labelEl = btn.querySelector('.admin-orders__filter-label');
    if (status && labelEl) {
      acc[status] = labelEl.textContent.trim();
    }
    return acc;
  }, {});

  const initialItemCounts = {};
  items.forEach((item) => {
    const status = item.dataset.orderStatus || '';
    if (!status) return;
    initialItemCounts[status] = (initialItemCounts[status] ?? 0) + 1;
  });
  Object.keys(initialItemCounts).forEach((status) => {
    statusCounts[status] = initialItemCounts[status];
  });

  const catalogEl = document.getElementById('admin-orders-catalog');
  let productCatalog = [];
  try {
    productCatalog = catalogEl ? JSON.parse(catalogEl.textContent || '[]') : [];
  } catch (_) {
    productCatalog = [];
  }
  if (!Array.isArray(productCatalog)) productCatalog = [];

  const notify = (message, icon) => {
    if (typeof window.AdminNotify === 'function') {
      window.AdminNotify(message, icon);
    } else {
      console.log('[Orders]', message);
    }
  };

  const formatStatusForDB = (status) => {
    if (!status) return '';
    return status.charAt(0).toUpperCase() + status.slice(1);
  };

  const escapeHtml = (value) => String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');

  const productNameById = (id) => {
    const found = productCatalog.find((p) => Number(p.id) === Number(id));
    return found && found.name ? found.name : ('Producto ' + id);
  };

  const productById = (id) => productCatalog.find((p) => Number(p.id) === Number(id)) || null;
  const cleanOrderId = (orderId) => String(orderId || '').replace(/[^0-9]/g, '');
  const printStorageScope = (document.body && document.body.dataset && document.body.dataset.tenantSlug)
    || window.location.pathname.replace(/[^a-z0-9_-]+/gi, '_');
  const AUTO_PRINT_KEY = 'adminOrdersAutoPrint:' + printStorageScope;
  const PRINTED_ORDERS_KEY = 'adminOrdersPrinted:' + printStorageScope;
  const orderSelector = (orderId) => '[data-order-id="' + cleanOrderId(orderId) + '"]';
  const orderElements = (orderId) => Array.from(document.querySelectorAll(
    '.admin-orders__item' + orderSelector(orderId) + ', [data-admin-order-row]' + orderSelector(orderId)
  ));
  const orderElement = (node) => node && node.closest
    ? node.closest('.admin-orders__item, [data-admin-order-row]')
    : null;
  const editPanelFor = (itemEl) => {
    if (!itemEl) return null;
    const orderId = itemEl.dataset.orderId || '';
    if (itemEl.matches('[data-admin-order-row]')) {
      return document.querySelector('[data-admin-order-edit-row="' + cleanOrderId(orderId) + '"] [data-order-edit]');
    }
    return itemEl.querySelector('[data-order-edit]');
  };
  const editRowFor = (orderId) => document.querySelector('[data-admin-order-edit-row="' + cleanOrderId(orderId) + '"]');

  const parseItemsData = (itemEl) => {
    try {
      const raw = itemEl.getAttribute('data-items') || '[]';
      const parsed = JSON.parse(raw);
      if (!Array.isArray(parsed)) return [];
      return parsed
        .map((row) => ({
          product: Number(row.product),
          quantity: Number(row.quantity),
          name: row.name || productNameById(row.product),
          variant: Number(row.variant) || 0,
          variant_label: row.variant_label || '',
          price: row.price === null || row.price === undefined || row.price === '' ? null : Number(row.price),
        }))
        .filter((row) => row.product > 0 && row.quantity > 0);
    } catch (_) {
      return [];
    }
  };

  const formatItemsPayload = (rows) => rows
    .filter((row) => row.product > 0 && row.quantity > 0)
    .map((row) => '(' + row.product + ' + ' + row.quantity + ')')
    .join(', ');

  const formatItemsDetailPayload = (rows) => JSON.stringify(rows
    .filter((row) => row.product > 0 && row.quantity > 0)
    .map((row) => ({
      id: String(row.product),
      variant: Number(row.variant) || 0,
      variant_label: row.variant_label || '',
      name: row.name || productNameById(row.product),
      qty: Number(row.quantity) || 1,
      price: row.price === null || row.price === undefined || Number.isNaN(Number(row.price))
        ? 0
        : Number(row.price),
    })));

  const totalFromRows = (rows) => rows.reduce((sum, row) => {
    const price = Number(row.price);
    const qty = Number(row.quantity) || 0;
    return Number.isFinite(price) ? sum + price * qty : sum;
  }, 0);

  const formatMoney = (value) => {
    const amount = Number(value) || 0;
    try {
      return new Intl.NumberFormat('es-UY', { style: 'currency', currency: 'UYU', maximumFractionDigits: 0 }).format(amount);
    } catch (_) {
      return '$' + Math.round(amount);
    }
  };

  const readPrintedOrders = () => {
    try {
      const parsed = JSON.parse(localStorage.getItem(PRINTED_ORDERS_KEY) || '[]');
      return Array.isArray(parsed) ? parsed.map(String) : [];
    } catch (_) {
      return [];
    }
  };

  const markOrderPrinted = (orderId) => {
    const id = cleanOrderId(orderId);
    if (!id) return;
    try {
      const printed = readPrintedOrders();
      if (!printed.includes(id)) {
        printed.push(id);
        localStorage.setItem(PRINTED_ORDERS_KEY, JSON.stringify(printed.slice(-200)));
      }
    } catch (_) { /* localStorage unavailable */ }
  };

  const isOrderPrinted = (orderId) => readPrintedOrders().includes(cleanOrderId(orderId));

  const businessName = () => {
    const candidates = [
      document.querySelector('[data-admin-business-name]'),
      document.querySelector('.admin-brand__tenant'),
      document.querySelector('.admin-topbar__brand strong'),
      document.querySelector('.admin-logo span'),
    ];
    for (const el of candidates) {
      const text = el ? String(el.textContent || '').trim() : '';
      if (text) return text;
    }
    return 'Pedido';
  };

  const orderPrintData = (itemEl) => {
    const rows = parseItemsData(itemEl);
    const orderId = cleanOrderId(itemEl.dataset.orderId || '');
    const dateText = itemEl.dataset.orderDate || '';
    const timeText = itemEl.dataset.orderTime || '';
    const statusEl = itemEl.querySelector('[data-admin-order-status-label], .admin-orders__item-status');
    return {
      id: orderId,
      business: businessName(),
      client: itemEl.dataset.orderClient || (itemEl.children && itemEl.children[1] ? itemEl.children[1].textContent.trim() : 'Cliente'),
      email: itemEl.dataset.orderClientEmail || '',
      phone: itemEl.dataset.orderClientPhone || '',
      cedula: itemEl.dataset.orderClientCedula || '',
      payment: itemEl.dataset.orderPayment || (itemEl.children && itemEl.children[4] ? itemEl.children[4].textContent.trim() : ''),
      date: [dateText, timeText].filter(Boolean).join(' '),
      status: statusEl ? statusEl.textContent.trim() : '',
      address: itemEl.dataset.orderAddress || '',
      rows,
      total: totalFromRows(rows),
    };
  };

  const printOrder = (itemEl, { auto = false } = {}) => {
    if (!itemEl) return false;
    const data = orderPrintData(itemEl);
    if (!data.id) return false;
    const rowsHtml = data.rows.length
      ? data.rows.map((row) => {
        const name = row.name || productNameById(row.product);
        const variant = row.variant_label ? ' - ' + row.variant_label : '';
        const subtotal = Number(row.price) * Number(row.quantity || 0);
        return `<tr><td>${escapeHtml(String(row.quantity))}x ${escapeHtml(name + variant)}</td><td>${escapeHtml(formatMoney(subtotal))}</td></tr>`;
      }).join('')
      : '<tr><td colspan="2">Sin productos</td></tr>';
    const details = [
      ['Cliente', data.client],
      ['Cedula', data.cedula],
      ['Telefono', data.phone],
      ['Email', data.email],
      ['Tipo de pago', data.payment],
      ['Fecha', data.date],
      ['Estado', data.status],
      ['Entrega', data.address],
    ].filter(([, value]) => String(value || '').trim() !== '')
      .map(([label, value]) => `<p><strong>${escapeHtml(label)}:</strong> ${escapeHtml(value)}</p>`)
      .join('');
    const html = `<!doctype html>
      <html lang="es">
      <head>
        <meta charset="utf-8">
        <title>Pedido #${escapeHtml(data.id)}</title>
        <style>
          * { box-sizing: border-box; }
          body { margin: 0; padding: 18px; font-family: Arial, sans-serif; color: #111827; background: #fff; }
          .ticket { width: 320px; max-width: 100%; margin: 0 auto; }
          h1 { margin: 0 0 4px; font-size: 20px; }
          h2 { margin: 0 0 14px; font-size: 15px; font-weight: 700; color: #4b5563; }
          p { margin: 4px 0; font-size: 13px; line-height: 1.35; }
          table { width: 100%; border-collapse: collapse; margin-top: 14px; font-size: 13px; }
          td { padding: 7px 0; border-top: 1px dashed #cbd5e1; vertical-align: top; }
          td:last-child { text-align: right; white-space: nowrap; }
          .total { display: flex; justify-content: space-between; gap: 12px; margin-top: 14px; padding-top: 10px; border-top: 2px solid #111827; font-size: 16px; font-weight: 800; }
          .foot { margin-top: 18px; text-align: center; color: #6b7280; font-size: 12px; }
          @media print {
            body { padding: 0; }
            .ticket { width: 72mm; padding: 4mm; }
          }
        </style>
      </head>
      <body>
        <main class="ticket">
          <h1>${escapeHtml(data.business)}</h1>
          <h2>Pedido #${escapeHtml(data.id)}</h2>
          ${details}
          <table><tbody>${rowsHtml}</tbody></table>
          <div class="total"><span>Total</span><span>${escapeHtml(formatMoney(data.total))}</span></div>
          <p class="foot">Generado desde Agendarte UY</p>
        </main>
        <script>window.onload = function(){ window.focus(); setTimeout(function(){ window.print(); }, 120); };</script>
      </body>
      </html>`;
    const frame = document.createElement('iframe');
    frame.style.position = 'fixed';
    frame.style.right = '0';
    frame.style.bottom = '0';
    frame.style.width = '0';
    frame.style.height = '0';
    frame.style.border = '0';
    frame.setAttribute('aria-hidden', 'true');
    document.body.appendChild(frame);
    const doc = frame.contentWindow && frame.contentWindow.document;
    if (!doc) {
      frame.remove();
      return false;
    }
    doc.open();
    doc.write(html);
    doc.close();
    markOrderPrinted(data.id);
    setTimeout(() => {
      if (frame.isConnected) frame.remove();
    }, auto ? 9000 : 6000);
    return true;
  };

  const autoPrintNewOrders = () => {
    if (!autoPrintToggle || !autoPrintToggle.checked) return;
    const pendingRows = Array.from(document.querySelectorAll('[data-admin-order-row][data-order-status="pendiente"], .admin-orders__item[data-order-status="pendiente"]'));
    for (const row of pendingRows) {
      const orderId = row.dataset.orderId || '';
      if (!orderId || isOrderPrinted(orderId)) continue;
      printOrder(row, { auto: true });
      break;
    }
  };

  const renderItemsList = (itemEl, rows) => {
    const ul = itemEl.querySelector('.admin-orders__items');
    if (!ul) {
      if (itemEl.matches('[data-admin-order-row]')) {
        const productsCell = itemEl.children[2];
        if (productsCell) {
          productsCell.textContent = rows
            .map((row) => {
              const variant = row.variant_label ? ' - ' + row.variant_label : '';
              return row.quantity + 'x ' + (row.name || productNameById(row.product)) + variant;
            })
            .join(', ');
        }
      }
      return;
    }
    if (!rows.length) {
      ul.innerHTML = '';
      ul.hidden = true;
      return;
    }
    ul.hidden = false;
    ul.innerHTML = rows
      .map((row) => `<li>${escapeHtml(row.quantity + ' x ' + (row.name || productNameById(row.product)))}</li>`)
      .join('');
  };

  const FILTER_STORAGE_KEY = 'adminOrdersFilterStatus';

  const updateButtonCounts = () => {
    filterButtons.forEach((btn) => {
      const statusKey = btn.dataset.status || '';
      const count = statusCounts[statusKey] ?? 0;
      btn.dataset.count = String(count);
      const countEl = btn.querySelector('.admin-orders__filter-count');
      if (countEl) countEl.textContent = count;
    });
  };

  const getPendingCount = () => statusCounts.pendiente ?? 0;

  const updateCartBadge = () => {
    if (badge) badge.textContent = String(getPendingCount());
  };

  const resolveDefaultStatus = () => {
    try {
      const remembered = sessionStorage.getItem(FILTER_STORAGE_KEY) || '';
      if (
        remembered
        && filterButtons.some((btn) => (btn.dataset.status || '') === remembered)
        && (statusCounts[remembered] ?? 0) > 0
      ) {
        return remembered;
      }
    } catch (_) { /* sessionStorage unavailable */ }

    if ((statusCounts.pendiente ?? 0) > 0) return 'pendiente';
    if ((statusCounts.finalizado ?? 0) > 0) return 'finalizado';

    const withItems = filterButtons.find((btn) => {
      const key = btn.dataset.status || '';
      return key !== '' && (statusCounts[key] ?? 0) > 0;
    });
    if (withItems) return withItems.dataset.status || 'pendiente';

    return ordersDetails.dataset.activeStatus
      || (filterButtons[0] ? filterButtons[0].dataset.status : '')
      || 'pendiente';
  };

  const applyStatus = (status, { remember = false } = {}) => {
    let visible = 0;
    items.forEach((item) => {
      const match = status === (item.dataset.orderStatus || '');
      item.style.display = match ? '' : 'none';
      if (match) visible += 1;
    });
    filterButtons.forEach((btn) => {
      const isActive = (btn.dataset.status || '') === status;
      btn.classList.toggle('is-active', isActive);
      btn.setAttribute('aria-pressed', isActive ? 'true' : 'false');
    });
    updateCartBadge();
    if (emptyState) {
      emptyState.style.display = visible === 0 ? '' : 'none';
    }
    ordersDetails.dataset.activeStatus = status;
    if (remember) {
      try {
        sessionStorage.setItem(FILTER_STORAGE_KEY, status);
      } catch (_) { /* sessionStorage unavailable */ }
    }
  };

  const syncSaleActionsVisibility = (itemEl, statusKey) => {
    const saleActions = itemEl.querySelector('.admin-orders__sale-actions');
    if (!saleActions) return;
    const isPending = statusKey === 'pendiente';
    saleActions.hidden = !isPending;
    if (!isPending) {
      const editPanel = editPanelFor(itemEl);
      if (editPanel) {
        editPanel.hidden = true;
        editPanel.innerHTML = '';
      }
      const editRow = editRowFor(itemEl.dataset.orderId || '');
      if (editRow) editRow.hidden = true;
    }
  };

  const setOrderStatusLocal = (itemEl, selectEl, prevStatus, nextStatus) => {
    const nextStatusLabel = statusLabelMap[nextStatus] || formatStatusForDB(nextStatus);
    const orderId = (itemEl && itemEl.dataset.orderId) || (selectEl && selectEl.dataset.orderId) || '';
    orderElements(orderId).forEach((el) => {
      el.dataset.orderStatus = nextStatus;
      el.classList.toggle('is-pending', nextStatus === 'pendiente');
      syncSaleActionsVisibility(el, nextStatus);
      const statusTextEl = el.querySelector('.admin-orders__item-status');
      if (statusTextEl) statusTextEl.textContent = nextStatusLabel;
      const pill = el.querySelector('[data-admin-order-status-label]');
      if (pill) {
        pill.textContent = nextStatusLabel;
        pill.className = 'status-pill st-' + nextStatus;
      }
      const localSelect = el.querySelector('.admin-orders__status-select');
      if (localSelect) {
        localSelect.dataset.currentStatus = nextStatus;
        localSelect.value = nextStatus;
      }
    });
    if (selectEl) {
      selectEl.dataset.currentStatus = nextStatus;
      selectEl.value = nextStatus;
    }
    if (selectEl) {
      const option = Array.from(selectEl.options).find((opt) => opt.value === nextStatus);
      if (option) statusLabelMap[nextStatus] = option.textContent.trim();
    }
    statusCounts[prevStatus] = Math.max((statusCounts[prevStatus] ?? 1) - 1, 0);
    statusCounts[nextStatus] = (statusCounts[nextStatus] ?? 0) + 1;
    updateButtonCounts();
    const activeStatus = ordersDetails.dataset.activeStatus || nextStatus;
    applyStatus(activeStatus);
  };

  const updateOrderStatus = async (orderId, nextStatus, { selectEl = null, itemEl = null, confirm = null } = {}) => {
    const parentItem = itemEl || (selectEl ? selectEl.closest('.admin-orders__item') : null);
    const select = selectEl || (parentItem ? parentItem.querySelector('.admin-orders__status-select') : null);
    const prevStatus = (select && select.dataset.currentStatus)
      || (parentItem && parentItem.dataset.orderStatus)
      || '';
    if (!orderId || !nextStatus || prevStatus === nextStatus) return false;

    if (confirm && typeof window.adminConfirm === 'function') {
      const ok = await window.adminConfirm(confirm);
      if (!ok) return false;
    }

    if (select) select.disabled = true;
    const actionBtns = parentItem
      ? Array.from(parentItem.querySelectorAll('[data-order-action]'))
      : [];
    actionBtns.forEach((btn) => { btn.disabled = true; });

    try {
      const body = new URLSearchParams();
      body.append('action', 'update');
      body.append('table', 'carrito');
      body.append('id', String(orderId));
      body.append('data', JSON.stringify({ Status: formatStatusForDB(nextStatus) }));
      const response = await fetch(apiUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
        credentials: 'same-origin',
        body: body.toString(),
      });
      if (!response.ok) throw new Error('Error de red');
      const payload = await response.json();
      if (!payload || payload.ok !== true) {
        throw new Error('No se pudo actualizar el pedido.');
      }
      setOrderStatusLocal(parentItem, select, prevStatus, nextStatus);
      notify('Estado del pedido actualizado.', 'success');
      return true;
    } catch (error) {
      if (select) select.value = prevStatus;
      notify(error instanceof Error ? error.message : 'Error inesperado al actualizar el estado.', 'error');
      return false;
    } finally {
      if (select) select.disabled = false;
      actionBtns.forEach((btn) => {
        if (btn.isConnected) btn.disabled = false;
      });
    }
  };

  const closeEditPanel = (itemEl) => {
    const panel = editPanelFor(itemEl);
    if (!panel) return;
    panel.hidden = true;
    panel.innerHTML = '';
    const orderId = itemEl.dataset.orderId || '';
    const editRow = editRowFor(orderId);
    if (editRow) editRow.hidden = true;
    document.querySelectorAll('[data-order-action="edit"][data-order-id="' + cleanOrderId(orderId) + '"]').forEach((editBtn) => {
      editBtn.setAttribute('aria-expanded', 'false');
    });
  };

  const openEditPanel = (itemEl) => {
    const panel = editPanelFor(itemEl);
    if (!panel) return;
    const orderId = itemEl.dataset.orderId || '';
    let rows = parseItemsData(itemEl).map((row) => ({ ...row }));

    const renderEditor = () => {
      const optionsHtml = productCatalog
        .map((p) => `<option value="${escapeHtml(String(p.id))}">${escapeHtml(p.name || ('Producto ' + p.id))}</option>`)
        .join('');
      const linesHtml = rows.length
        ? rows.map((row, index) => `
            <div class="admin-orders__edit-line" data-edit-index="${index}">
              <span class="admin-orders__edit-name">${escapeHtml(row.name || productNameById(row.product))}</span>
              <div class="admin-orders__edit-qty">
                <button type="button" class="admin-orders__edit-qty-btn" data-edit-qty="-1" aria-label="Restar">−</button>
                <input type="number" min="1" step="1" value="${escapeHtml(String(row.quantity))}" class="admin-orders__edit-qty-input" aria-label="Cantidad">
                <button type="button" class="admin-orders__edit-qty-btn" data-edit-qty="1" aria-label="Sumar">+</button>
              </div>
              <button type="button" class="admin-orders__edit-remove" data-edit-remove aria-label="Quitar producto">
                <i class="bx bx-trash" aria-hidden="true"></i>
              </button>
            </div>
          `).join('')
        : '<p class="admin-orders__edit-empty">Sin productos. Agregá al menos uno.</p>';

      panel.innerHTML = `
        <p class="admin-orders__edit-title">Cambiar venta #${escapeHtml(String(orderId))}</p>
        <div class="admin-orders__edit-lines">${linesHtml}</div>
        <div class="admin-orders__edit-add">
          <select class="admin-orders__edit-add-select" aria-label="Producto a agregar">
            <option value="">Agregar producto…</option>
            ${optionsHtml}
          </select>
          <button type="button" class="admin-orders__edit-add-btn" data-edit-add>Agregar</button>
        </div>
        <div class="admin-orders__edit-footer">
          <button type="button" class="admin-orders__sale-btn admin-orders__sale-btn--ghost" data-edit-cancel>Cancelar</button>
          <button type="button" class="admin-orders__sale-btn admin-orders__sale-btn--finalize" data-edit-save>Guardar cambios</button>
        </div>
      `;
      panel.hidden = false;
      const editRow = editRowFor(orderId);
      if (editRow) editRow.hidden = false;
    };

    const syncRowsFromDom = () => {
      const lineEls = Array.from(panel.querySelectorAll('.admin-orders__edit-line'));
      rows = lineEls.map((line) => {
        const index = Number(line.getAttribute('data-edit-index'));
        const current = rows[index] || {};
        const input = line.querySelector('.admin-orders__edit-qty-input');
        const qty = Math.max(1, parseInt(input && input.value ? input.value : '1', 10) || 1);
        return {
          product: Number(current.product),
          quantity: qty,
          name: current.name || productNameById(current.product),
          variant: Number(current.variant) || 0,
          variant_label: current.variant_label || '',
          price: current.price,
        };
      }).filter((row) => row.product > 0);
    };

    renderEditor();
    document.querySelectorAll('[data-order-action="edit"][data-order-id="' + cleanOrderId(orderId) + '"]').forEach((editBtn) => {
      editBtn.setAttribute('aria-expanded', 'true');
    });

    panel.onclick = async (event) => {
      const target = event.target;
      if (!target || !target.closest) return;

      if (target.closest('[data-edit-cancel]')) {
        closeEditPanel(itemEl);
        return;
      }

      if (target.closest('[data-edit-add]')) {
        syncRowsFromDom();
        const select = panel.querySelector('.admin-orders__edit-add-select');
        const pid = select ? Number(select.value) : 0;
        if (!pid) {
          notify('Elegí un producto para agregar.', 'error');
          return;
        }
        const existing = rows.find((row) => row.product === pid);
        if (existing) {
          existing.quantity += 1;
        } else {
          const product = productById(pid);
          rows.push({
            product: pid,
            quantity: 1,
            name: productNameById(pid),
            variant: 0,
            variant_label: '',
            price: product && product.price !== undefined ? Number(product.price) : null,
          });
        }
        if (select) select.value = '';
        renderEditor();
        return;
      }

      const removeBtn = target.closest('[data-edit-remove]');
      if (removeBtn) {
        syncRowsFromDom();
        const line = removeBtn.closest('.admin-orders__edit-line');
        const index = line ? Number(line.getAttribute('data-edit-index')) : -1;
        if (index >= 0) rows.splice(index, 1);
        renderEditor();
        return;
      }

      const qtyBtn = target.closest('[data-edit-qty]');
      if (qtyBtn) {
        syncRowsFromDom();
        const line = qtyBtn.closest('.admin-orders__edit-line');
        const index = line ? Number(line.getAttribute('data-edit-index')) : -1;
        const delta = Number(qtyBtn.getAttribute('data-edit-qty')) || 0;
        if (index >= 0 && rows[index]) {
          rows[index].quantity = Math.max(1, rows[index].quantity + delta);
        }
        renderEditor();
        return;
      }

      if (target.closest('[data-edit-save]')) {
        syncRowsFromDom();
        if (!rows.length) {
          notify('La venta debe tener al menos un producto.', 'error');
          return;
        }
        const saveBtn = panel.querySelector('[data-edit-save]');
        if (saveBtn) saveBtn.disabled = true;
        try {
          const body = new URLSearchParams();
          body.append('action', 'update');
          body.append('table', 'carrito');
          body.append('id', String(orderId));
          body.append('data', JSON.stringify({
            'ID_Producto + Cantidad': formatItemsPayload(rows),
            Detalle_Items: formatItemsDetailPayload(rows),
            Total: totalFromRows(rows).toFixed(2),
          }));
          const response = await fetch(apiUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
            credentials: 'same-origin',
            body: body.toString(),
          });
          if (!response.ok) throw new Error('Error de red');
          const payload = await response.json();
          if (!payload || payload.ok !== true) {
            throw new Error('No se pudo actualizar la venta.');
          }
          itemEl.setAttribute('data-items', JSON.stringify(rows.map((row) => ({
            product: row.product,
            quantity: row.quantity,
            name: row.name || productNameById(row.product),
            variant: Number(row.variant) || 0,
            variant_label: row.variant_label || '',
            price: row.price,
          }))));
          orderElements(orderId).forEach((el) => {
            el.setAttribute('data-items', itemEl.getAttribute('data-items') || '[]');
            renderItemsList(el, rows);
          });
          closeEditPanel(itemEl);
          notify('Venta actualizada.', 'success');
        } catch (error) {
          notify(error instanceof Error ? error.message : 'No se pudo guardar la venta.', 'error');
          if (saveBtn && saveBtn.isConnected) saveBtn.disabled = false;
        }
      }
    };

    panel.onchange = (event) => {
      const input = event.target;
      if (!input || !input.classList || !input.classList.contains('admin-orders__edit-qty-input')) return;
      const line = input.closest('.admin-orders__edit-line');
      const index = line ? Number(line.getAttribute('data-edit-index')) : -1;
      const qty = Math.max(1, parseInt(input.value || '1', 10) || 1);
      input.value = String(qty);
      if (index >= 0 && rows[index]) rows[index].quantity = qty;
    };
  };

  filterButtons.forEach((btn) => {
    btn.addEventListener('click', (event) => {
      event.preventDefault();
      applyStatus(btn.dataset.status || '', { remember: true });
    });
  });

  if (autoPrintToggle) {
    try {
      autoPrintToggle.checked = localStorage.getItem(AUTO_PRINT_KEY) === '1';
    } catch (_) { /* localStorage unavailable */ }
    autoPrintToggle.addEventListener('change', () => {
      try {
        localStorage.setItem(AUTO_PRINT_KEY, autoPrintToggle.checked ? '1' : '0');
      } catch (_) { /* localStorage unavailable */ }
      if (autoPrintToggle.checked) {
        autoPrintNewOrders();
      }
    });
  }

  selects.forEach((select) => {
    select.addEventListener('change', async (event) => {
      const target = event.currentTarget;
      const orderId = target.dataset.orderId;
      const nextStatus = target.value || '';
      const prevStatus = target.dataset.currentStatus || '';
      if (!orderId || prevStatus === nextStatus) {
        target.value = prevStatus;
        return;
      }
      const ok = await updateOrderStatus(orderId, nextStatus, {
        selectEl: target,
        itemEl: target.closest('.admin-orders__item'),
      });
      if (!ok) target.value = prevStatus;
    });
  });

  document.addEventListener('click', async (event) => {
    const target = event.target;
    if (!target || !target.closest) return;
    const actionBtn = target.closest('[data-order-action]');
    if (!actionBtn) return;
    if (!ordersDetails.contains(actionBtn) && !(table && table.contains(actionBtn))) return;

    const itemEl = orderElement(actionBtn);
    const orderId = actionBtn.getAttribute('data-order-id')
      || (itemEl && itemEl.dataset.orderId)
      || '';
    const action = actionBtn.getAttribute('data-order-action') || '';
    if (!itemEl || !orderId || !action) return;

    if (action === 'print') {
      printOrder(itemEl);
      return;
    }

    if (action === 'finalize') {
      await updateOrderStatus(orderId, 'finalizado', {
        itemEl,
        confirm: {
          title: 'Finalizar venta',
          message: '¿Marcar este pedido como Finalizado? Pasará al ledger de ventas.',
          confirmText: 'Finalizar venta',
        },
      });
      return;
    }

    if (action === 'cancel') {
      await updateOrderStatus(orderId, 'cancelado', {
        itemEl,
        confirm: {
          title: 'Cancelar venta',
          message: '¿Cancelar este pedido? El estado pasará a Cancelado.',
          confirmText: 'Cancelar venta',
        },
      });
      return;
    }

    if (action === 'edit') {
      const panel = itemEl.querySelector('[data-order-edit]');
      if (panel && !panel.hidden && panel.innerHTML.trim() !== '') {
        closeEditPanel(itemEl);
      } else {
        openEditPanel(itemEl);
      }
    }
  });

  items.forEach((item) => {
    syncSaleActionsVisibility(item, item.dataset.orderStatus || '');
  });

  updateButtonCounts();
  applyStatus(resolveDefaultStatus());
  window.addEventListener('focus', autoPrintNewOrders);
  setTimeout(autoPrintNewOrders, 500);
  setInterval(autoPrintNewOrders, 8000);
})();
