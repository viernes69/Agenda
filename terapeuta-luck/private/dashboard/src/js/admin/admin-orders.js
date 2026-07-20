(function adminOrdersPanel() {
  const ordersDetails = document.querySelector('.admin-orders');
  if (!ordersDetails) return;

  const list = ordersDetails.querySelector('[data-role="orders-list"]');
  const filterButtons = Array.from(ordersDetails.querySelectorAll('.admin-orders__filter-btn'));
  if (!list || !filterButtons.length) return;

  const items = Array.from(list.querySelectorAll('.admin-orders__item'));
  const selects = Array.from(ordersDetails.querySelectorAll('.admin-orders__status-select'));
  const badge = ordersDetails.querySelector('.admin-orders__badge');
  const emptyState = ordersDetails.querySelector('.admin-orders__empty[data-role="no-results"]');
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

  const renderItemsList = (itemEl, rows) => {
    const ul = itemEl.querySelector('.admin-orders__items');
    if (!ul) return;
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
      const editPanel = itemEl.querySelector('[data-order-edit]');
      if (editPanel) {
        editPanel.hidden = true;
        editPanel.innerHTML = '';
      }
    }
  };

  const setOrderStatusLocal = (itemEl, selectEl, prevStatus, nextStatus) => {
    if (selectEl) {
      selectEl.dataset.currentStatus = nextStatus;
      selectEl.value = nextStatus;
    }
    if (itemEl) {
      itemEl.dataset.orderStatus = nextStatus;
      itemEl.classList.toggle('is-pending', nextStatus === 'pendiente');
      syncSaleActionsVisibility(itemEl, nextStatus);
    }
    const nextStatusLabel = statusLabelMap[nextStatus] || formatStatusForDB(nextStatus);
    const statusTextEl = itemEl ? itemEl.querySelector('.admin-orders__item-status') : null;
    if (statusTextEl) statusTextEl.textContent = nextStatusLabel;
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
    const panel = itemEl.querySelector('[data-order-edit]');
    if (!panel) return;
    panel.hidden = true;
    panel.innerHTML = '';
    const editBtn = itemEl.querySelector('[data-order-action="edit"]');
    if (editBtn) editBtn.setAttribute('aria-expanded', 'false');
  };

  const openEditPanel = (itemEl) => {
    const panel = itemEl.querySelector('[data-order-edit]');
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
        };
      }).filter((row) => row.product > 0);
    };

    renderEditor();
    const editBtn = itemEl.querySelector('[data-order-action="edit"]');
    if (editBtn) editBtn.setAttribute('aria-expanded', 'true');

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
          rows.push({ product: pid, quantity: 1, name: productNameById(pid) });
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
          }))));
          renderItemsList(itemEl, rows);
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

  list.addEventListener('click', async (event) => {
    const target = event.target;
    if (!target || !target.closest) return;
    const actionBtn = target.closest('[data-order-action]');
    if (!actionBtn || !list.contains(actionBtn)) return;

    const itemEl = actionBtn.closest('.admin-orders__item');
    const orderId = actionBtn.getAttribute('data-order-id')
      || (itemEl && itemEl.dataset.orderId)
      || '';
    const action = actionBtn.getAttribute('data-order-action') || '';
    if (!itemEl || !orderId || !action) return;

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
})();
