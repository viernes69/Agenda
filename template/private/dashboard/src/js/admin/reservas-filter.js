(function adminReservasFilter() {
  const select = document.querySelector('[data-admin-reserva-filter]');
  const dateInput = document.querySelector('[data-admin-reserva-date]');
  const clearBtn = document.querySelector('[data-admin-reserva-date-clear]');
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
  let datePicker = null;
  const emptyBase = emptyEl ? (emptyEl.getAttribute('data-empty-base') || emptyEl.textContent || 'No hay reservas para el estado seleccionado.') : 'No hay reservas para el estado seleccionado.';

  const isYmd = (value) => /^\d{4}-\d{2}-\d{2}$/.test(String(value || ''));

  const parseDatesAttr = (el) => {
    if (!el) return [];
    const raw = el.getAttribute('data-admin-reserva-dates') || '[]';
    try {
      const parsed = JSON.parse(raw);
      if (!Array.isArray(parsed)) return [];
      return parsed.map(String).filter(isYmd);
    } catch (_) {
      return [];
    }
  };

  const syncClearBtn = () => {
    if (!clearBtn) return;
    clearBtn.hidden = !currentDate;
  };

  const setEnabledDates = (dates) => {
    const list = Array.isArray(dates) ? dates.map(String).filter(isYmd) : [];
    if (dateInput) {
      dateInput.setAttribute('data-admin-reserva-dates', JSON.stringify(list));
    }
    if (!datePicker) return;
    // Flatpickr: empty enable[] can be ambiguous — force no selectable days.
    datePicker.set('enable', list.length ? list : [() => false]);
  };

  const setPickerDate = (value, triggerChange) => {
    const next = isYmd(value) ? value : '';
    currentDate = next;
    if (datePicker) {
      if (next) {
        datePicker.setDate(next, !!triggerChange);
      } else {
        datePicker.clear(!!triggerChange);
      }
    } else if (dateInput) {
      dateInput.value = next;
    }
    syncClearBtn();
  };

  const initDatePicker = () => {
    if (!dateInput) return;
    const enableDates = parseDatesAttr(dateInput);
    if (currentDate && enableDates.length && !enableDates.includes(currentDate)) {
      currentDate = '';
      dateInput.value = '';
    }

    if (typeof flatpickr !== 'function') {
      dateInput.type = 'date';
      dateInput.removeAttribute('readonly');
      dateInput.placeholder = '';
      return;
    }

    dateInput.type = 'text';
    dateInput.setAttribute('readonly', 'readonly');
    dateInput.setAttribute('autocomplete', 'off');
    dateInput.setAttribute('inputmode', 'none');
    if (!dateInput.getAttribute('placeholder')) {
      dateInput.setAttribute('placeholder', 'Todas');
    }

    datePicker = flatpickr(dateInput, {
      locale: (flatpickr.l10ns && flatpickr.l10ns.es) ? flatpickr.l10ns.es : 'default',
      dateFormat: 'Y-m-d',
      altInput: true,
      altFormat: 'd/m/Y',
      allowInput: false,
      disableMobile: true,
      enable: enableDates.length ? enableDates : [() => false],
      defaultDate: currentDate || undefined,
      onChange: (_selectedDates, dateStr) => {
        currentDate = dateStr || '';
        syncClearBtn();
        fetchData(currentStatus);
      },
    });

    if (datePicker.altInput) {
      datePicker.altInput.classList.add('admin-reservas-filter__input');
      datePicker.altInput.placeholder = 'Todas';
    }
  };

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
    if (Object.prototype.hasOwnProperty.call(payload, 'date')) {
      setPickerDate(payload.date || '', false);
    }
    if (Array.isArray(payload.dates)) {
      setEnabledDates(payload.dates);
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

  // Native <input type="date"> fallback when Flatpickr is unavailable.
  dateInput?.addEventListener('change', () => {
    if (datePicker) return;
    currentDate = dateInput.value || '';
    syncClearBtn();
    fetchData(currentStatus);
  });

  clearBtn?.addEventListener('click', () => {
    setPickerDate('', true);
    if (!datePicker) {
      fetchData(currentStatus);
    }
  });

  initDatePicker();
  syncClearBtn();
  fetchData(currentStatus, { quiet: true });
  // Soft refresh so new public bookings appear without a hard reload.
  window.setInterval(() => fetchData(currentStatus, { quiet: true }), 2000);
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') {
      fetchData(currentStatus, { quiet: true });
    }
  });
  window.addEventListener('focus', () => {
    fetchData(currentStatus, { quiet: true });
  });

  window.AdminReservasRefresh = (status = currentStatus) => {
    fetchData(status, { quiet: true });
  };
})();
