(function adminReservasFilter() {
  const select = document.querySelector('[data-admin-reserva-filter]');
  const dateInput = document.querySelector('[data-admin-reserva-date]');
  const table = document.querySelector('[data-admin-reservas-table]');
  const tbody = table ? table.querySelector('tbody') : null;
  const countEl = document.querySelector('[data-admin-reserva-count]');
  const amountEl = document.querySelector('[data-admin-reserva-amount]');
  const emptyEl = document.querySelector('[data-admin-reserva-empty]');
  const badgeEl = document.querySelector('[data-bottom-badge="reservas"]');
  if (!tbody) return;

  const API_URL = '../src/api/reservas_admin.php';
  const defaultValue = select ? (select.getAttribute('data-admin-reserva-default') || select.value || 'todos') : 'todos';
  const defaultDate = dateInput ? (dateInput.getAttribute('data-admin-reserva-date-default') || dateInput.value || '') : '';
  if (dateInput && !dateInput.value && defaultDate) {
    dateInput.value = defaultDate;
  }
  let currentStatus = select && select.value ? select.value : defaultValue;
  let currentDate = dateInput ? (dateInput.value || defaultDate || '') : '';
  let isFetching = false;
  let pendingRequest = null;
  const emptyBase = emptyEl ? (emptyEl.getAttribute('data-empty-base') || emptyEl.textContent || 'No hay reservas para el estado seleccionado.') : 'No hay reservas para el estado seleccionado.';

  const updateBadge = (value) => {
    if (!badgeEl) return;
    const num = Number(value) || 0;
    if (num > 0) {
      badgeEl.hidden = false;
      badgeEl.textContent = num;
    } else {
      badgeEl.hidden = true;
    }
  };

  const applyData = (payload) => {
    if (!payload) return;
    if (select && payload.status) {
      select.value = payload.status;
    }
    currentStatus = payload.status || currentStatus;
    if (dateInput && Object.prototype.hasOwnProperty.call(payload, 'date')) {
      dateInput.value = payload.date || '';
      currentDate = payload.date || '';
    }

    if (typeof payload.html === 'string') {
      tbody.innerHTML = payload.html;
    }
    if (countEl) {
      const label = payload.label || currentStatus;
      countEl.textContent = `Total (${label}): ${payload.total ?? 0}`;
    }
    if (emptyEl) {
      const shouldHide = (payload.total ?? 0) > 0;
      emptyEl.hidden = shouldHide;
      emptyEl.textContent = shouldHide ? emptyBase : (payload.emptyMessage || emptyBase);
    }
    if (amountEl && payload.finalizedLabel) {
      amountEl.textContent = payload.finalizedLabel;
    }
    updateBadge(payload.badge);
  };

  const fetchData = async (status, opts = {}) => {
    if (isFetching) {
      pendingRequest = { status, opts };
      return;
    }
    isFetching = true;
    try {
      const url = new URL(API_URL, window.location.href);
      if (status) url.searchParams.set('status', status);
      if (currentDate) {
        url.searchParams.set('date', currentDate);
      } else {
        url.searchParams.delete('date');
      }
      const response = await fetch(url.toString(), { credentials: 'same-origin', cache: 'no-store' });
      const data = await response.json();
      if (!data || data.ok !== true) {
        throw new Error(data && data.error ? data.error : 'No se pudo actualizar las reservas');
      }
      applyData(data);

      if (!opts.quiet && select) {
        const def = select.getAttribute('data-admin-reserva-default') || '';
        const nextStatus = data.status || status || def;
        const nextUrl = new URL(window.location.href);
        if (nextStatus && nextStatus !== def) {
          nextUrl.searchParams.set('res_status', nextStatus);
        } else {
          nextUrl.searchParams.delete('res_status');
        }
        if (currentDate) {
          nextUrl.searchParams.set('res_date', currentDate);
        } else {
          nextUrl.searchParams.delete('res_date');
        }
        window.history.replaceState({}, '', `${nextUrl.pathname}${nextUrl.search}#reservas`);
      }
    } catch (error) {
      if (!opts.quiet) {
        console.error('[Reservas] No se pudo actualizar la lista.', error);
      }
    } finally {
      isFetching = false;
      if (pendingRequest) {
        const next = pendingRequest;
        pendingRequest = null;
        fetchData(next.status, next.opts);
      }
    }
  };

  select?.addEventListener('change', () => {
    const value = select.value || '';
    fetchData(value);
  });
  dateInput?.addEventListener('change', () => {
    currentDate = dateInput.value || '';
    fetchData(currentStatus);
  });

  fetchData(currentStatus, { quiet: true });
  window.setInterval(() => fetchData(currentStatus, { quiet: true }), 45000);

  window.AdminReservasRefresh = (status = currentStatus) => {
    fetchData(status, { quiet: true });
  };
})();
