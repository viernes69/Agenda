(function adminConfigHoursModule() {
  const setup = () => {
    const modal = document.querySelector('[data-admin-modal="config-hours"]');
    if (!modal) return;
    const form = modal.querySelector('[data-admin-config-hours-form]');
    if (!form) return;
    const modalLoading = window.AdminModalLoading;

    const selectorsWrapper = form.querySelector('[data-admin-config-hours-tz-selectors]');
    const regionSelect = form.querySelector('[data-admin-config-hours-tz-region]');
    const zoneSelect = form.querySelector('[data-admin-config-hours-tz-zone]');
    const summaryEl = form.querySelector('[data-admin-config-hours-tz-summary]');
    const manualWrapper = form.querySelector('[data-admin-config-hours-timezone-manual]');
    const manualInput = manualWrapper ? manualWrapper.querySelector('input') : null;
    const timezoneField = form.querySelector('[data-admin-config-hours-field="timezone"]');
    const dayItems = Array.from(form.querySelectorAll('[data-admin-config-hours-day]'));
    const holidayInput = form.querySelector('[data-admin-config-hours-holiday-input]');
    const holidayAddBtn = form.querySelector('[data-admin-config-hours-holiday-add]');
    const holidayList = form.querySelector('[data-admin-config-hours-holiday-list]');
    const holidayEmpty = form.querySelector('[data-admin-config-hours-holiday-empty]');
    const errorEl = form.querySelector('[data-admin-config-hours-error]');
    const submitBtn = form.querySelector('[data-admin-config-hours-submit]');
    const closeEls = modal.querySelectorAll('[data-admin-config-hours-close]');

    if (!timezoneField) return;

    const DAY_KEYS = dayItems.map((item) => item.getAttribute('data-admin-config-hours-day') || '');

    const snapTimeQuarter = (value, fallback = '09:00') => {
      if (typeof value !== 'string' || value.trim() === '') return fallback;
      const match = value.trim().match(/^(\d{1,2}):(\d{2})/);
      if (!match) return fallback;
      let hour = parseInt(match[1], 10);
      let minute = parseInt(match[2], 10);
      if (Number.isNaN(hour) || Number.isNaN(minute)) return fallback;
      minute = Math.round(minute / 15) * 15;
      if (minute === 60) {
        minute = 0;
        hour = (hour + 1) % 24;
      }
      return `${String(hour).padStart(2, '0')}:${String(minute).padStart(2, '0')}`;
    };

    const applyTimeSelect = (select, value, fallback = '09:00') => {
      if (!select) return;
      const normalized = snapTimeQuarter(value, fallback);
      if (normalized && !select.querySelector(`option[value="${normalized}"]`)) {
        const option = document.createElement('option');
        option.value = normalized;
        option.textContent = normalized;
        select.appendChild(option);
      }
      select.value = normalized || fallback;
    };

    const clone = (obj) => {
      try {
        return JSON.parse(JSON.stringify(obj || {}));
      } catch (_) {
        return {};
      }
    };

    const defaultDay = () => ({
      abierto: false,
      inicio: '09:00',
      fin: '18:00',
      descanso_inicio: '',
      descanso_fin: ''
    });

    const normalizeSchedule = (src) => {
      const base = { timezone: '', feriados: [] };
      if (src && typeof src === 'object') {
        if (typeof src.timezone === 'string') {
          base.timezone = src.timezone;
        }
        if (Array.isArray(src.feriados)) {
          base.feriados = src.feriados
            .filter((v) => typeof v === 'string' && v.trim() !== '')
            .map((v) => v.trim());
        }
        DAY_KEYS.forEach((key) => {
          const dayData = src[key];
          if (dayData && typeof dayData === 'object') {
            base[key] = {
              abierto: Boolean(dayData.abierto),
              inicio: typeof dayData.inicio === 'string' ? dayData.inicio : '09:00',
              fin: typeof dayData.fin === 'string' ? dayData.fin : '18:00',
              descanso_inicio: typeof dayData.descanso_inicio === 'string' ? dayData.descanso_inicio : '',
              descanso_fin: typeof dayData.descanso_fin === 'string' ? dayData.descanso_fin : ''
            };
          } else {
            base[key] = defaultDay();
          }
        });
      } else {
        DAY_KEYS.forEach((key) => { base[key] = defaultDay(); });
      }
      return base;
    };

    const state = {
      schedule: normalizeSchedule(window.ADMIN_INFO_BARBERIA && window.ADMIN_INFO_BARBERIA.horarios),
      holidays: []
    };
    state.holidays = Array.isArray(state.schedule.feriados) ? [...state.schedule.feriados] : [];

    const formatZoneLabel = (value) => value.split('/').slice(1).join(' / ').replace(/_/g, ' ');

    const FALLBACK_TIMEZONES = [
      'Africa/Abidjan', 'Africa/Cairo', 'Africa/Casablanca', 'Africa/Johannesburg', 'Africa/Lagos', 'Africa/Nairobi',
      'America/Anchorage', 'America/Argentina/Buenos_Aires', 'America/Bogota', 'America/Caracas', 'America/Chicago',
      'America/Denver', 'America/Guayaquil', 'America/Halifax', 'America/Los_Angeles', 'America/Mexico_City',
      'America/New_York', 'America/Panama', 'America/Santiago', 'America/Sao_Paulo', 'America/Tijuana',
      'America/Toronto', 'America/Lima', 'America/Montevideo',
      'Antarctica/Palmer',
      'Asia/Bangkok', 'Asia/Dubai', 'Asia/Hong_Kong', 'Asia/Jakarta', 'Asia/Kolkata', 'Asia/Seoul', 'Asia/Shanghai',
      'Asia/Singapore', 'Asia/Tokyo',
      'Atlantic/Azores', 'Atlantic/Reykjavik',
      'Australia/Adelaide', 'Australia/Brisbane', 'Australia/Darwin', 'Australia/Perth', 'Australia/Sydney',
      'Europe/Amsterdam', 'Europe/Athens', 'Europe/Berlin', 'Europe/Brussels', 'Europe/Istanbul', 'Europe/Lisbon',
      'Europe/London', 'Europe/Madrid', 'Europe/Moscow', 'Europe/Paris', 'Europe/Rome', 'Europe/Stockholm',
      'Europe/Zurich',
      'Indian/Maldives', 'Indian/Mauritius', 'Indian/Reunion',
      'Pacific/Auckland', 'Pacific/Fiji', 'Pacific/Guam', 'Pacific/Honolulu', 'Pacific/Pago_Pago',
      'Etc/UTC'
    ];

    const getSupportedTimezones = () => {
      if (typeof Intl === 'object' && typeof Intl.supportedValuesOf === 'function') {
        try {
          const list = Intl.supportedValuesOf('timeZone');
          if (Array.isArray(list) && list.length) return list;
        } catch (_) { /* ignore */ }
      }
      return FALLBACK_TIMEZONES.slice();
    };

    const timezoneCatalog = (() => {
      const catalog = {};
      const regions = [];
      const list = getSupportedTimezones();
      list.forEach((tz) => {
        if (typeof tz !== 'string' || !tz.includes('/')) return;
        const [region] = tz.split('/');
        if (!catalog[region]) {
          catalog[region] = [];
          regions.push(region);
        }
        catalog[region].push(tz);
      });
      regions.forEach((region) => {
        catalog[region].sort();
      });
      regions.sort((a, b) => a.localeCompare(b, 'es'));
      return { catalog, regions };
    })();

    const ensureTimezoneInCatalog = (value) => {
      if (!value || !value.includes('/')) return;
      const [region] = value.split('/');
      if (!timezoneCatalog.catalog[region]) {
        timezoneCatalog.catalog[region] = [value];
        timezoneCatalog.regions.push(region);
        timezoneCatalog.regions.sort((a, b) => a.localeCompare(b, 'es'));
      } else if (!timezoneCatalog.catalog[region].includes(value)) {
        timezoneCatalog.catalog[region].push(value);
        timezoneCatalog.catalog[region].sort();
      }
    };

    const updateSummary = (value) => {
      if (!summaryEl) return;
      if (value) {
        summaryEl.textContent = `Zona seleccionada: ${value}`;
      } else {
        summaryEl.textContent = 'Selecciona una region para ver las zonas disponibles.';
      }
    };

    const setTimezoneField = (value) => {
      timezoneField.value = value || '';
      updateSummary(timezoneField.value);
    };

    let timezoneListenersBound = false;

    const applyManualMode = () => {
      if (selectorsWrapper) selectorsWrapper.hidden = true;
      if (regionSelect) regionSelect.required = false;
      if (zoneSelect) {
        zoneSelect.required = false;
        zoneSelect.innerHTML = '<option value="">Selecciona una zona</option>';
      }
      if (manualWrapper) manualWrapper.hidden = false;
      if (manualInput) {
        manualInput.required = true;
        manualInput.value = timezoneField.value || '';
        if (!manualInput.dataset.bound) {
          manualInput.addEventListener('input', () => {
            setTimezoneField(manualInput.value.trim());
          });
          manualInput.dataset.bound = 'true';
        }
      }
      updateSummary(timezoneField.value);
    };

    const populateZoneSelect = (region, preset) => {
      if (!zoneSelect) return;
      const zones = timezoneCatalog.catalog[region] || [];
      zoneSelect.innerHTML = '<option value="">Selecciona una zona</option>';
      zones.forEach((tz) => {
        const option = document.createElement('option');
        option.value = tz;
        option.textContent = formatZoneLabel(tz);
        zoneSelect.appendChild(option);
      });
      if (preset && zones.includes(preset)) {
        zoneSelect.value = preset;
      } else {
        zoneSelect.value = '';
      }
    };

    const populateRegions = () => {
      if (!regionSelect) return;
      regionSelect.innerHTML = '<option value="">Selecciona una region</option>';
      timezoneCatalog.regions.forEach((region) => {
        const option = document.createElement('option');
        option.value = region;
        option.textContent = region;
        regionSelect.appendChild(option);
      });
    };

    const applyRegionMode = (presetTz) => {
      if (!regionSelect || !zoneSelect || !selectorsWrapper) {
        applyManualMode();
        return;
      }
      selectorsWrapper.hidden = false;
      regionSelect.required = true;
      zoneSelect.required = true;
      if (manualWrapper) manualWrapper.hidden = true;
      if (manualInput) {
        manualInput.required = false;
        manualInput.value = '';
      }
      ensureTimezoneInCatalog(presetTz);
      populateRegions();
      let region = '';
      if (presetTz && presetTz.includes('/')) {
        [region] = presetTz.split('/');
      }
      if (region && timezoneCatalog.catalog[region]) {
        regionSelect.value = region;
        populateZoneSelect(region, presetTz);
      } else {
        regionSelect.value = '';
        zoneSelect.innerHTML = '<option value="">Selecciona una zona</option>';
      }
      setTimezoneField(presetTz || '');
    };

    const initializeTimezoneSelectors = (preset) => {
      const initialValue = preset || timezoneField.value || '';
      setTimezoneField(initialValue);
      if (!timezoneCatalog.regions.length || !regionSelect || !zoneSelect || !selectorsWrapper) {
        applyManualMode();
        return;
      }
      applyRegionMode(initialValue);
      if (!timezoneListenersBound && regionSelect && zoneSelect) {
        regionSelect.addEventListener('change', () => {
          const region = regionSelect.value;
          populateZoneSelect(region, '');
          if (!region) {
            setTimezoneField('');
            return;
          }
          const zone = zoneSelect.value;
          if (zone) {
            setTimezoneField(zone);
          } else {
            setTimezoneField('');
          }
        });
        zoneSelect.addEventListener('change', () => {
          const zone = zoneSelect.value;
          setTimezoneField(zone);
        });
        timezoneListenersBound = true;
      }
    };

    initializeTimezoneSelectors(state.schedule.timezone || timezoneField.value || '');

    const setDayInputsState = (dayEl, isOpen) => {
      const start = dayEl.querySelector('[data-admin-config-hours-start]');
      const end = dayEl.querySelector('[data-admin-config-hours-end]');
      const breakToggle = dayEl.querySelector('[data-admin-config-hours-break-toggle]');
      const breakStart = dayEl.querySelector('[data-admin-config-hours-break-start]');
      const breakEnd = dayEl.querySelector('[data-admin-config-hours-break-end]');
      if (!start || !end) return;
      start.disabled = !isOpen;
      end.disabled = !isOpen;
      start.required = isOpen;
      end.required = isOpen;
      if (!isOpen) {
        applyTimeSelect(start, '09:00', '09:00');
        applyTimeSelect(end, '18:00', '18:00');
        if (breakToggle) {
          breakToggle.checked = false;
        }
        if (breakStart && breakEnd) {
          breakStart.disabled = true;
          breakEnd.disabled = true;
          breakStart.required = false;
          breakEnd.required = false;
          breakStart.value = '';
          breakEnd.value = '';
        }
      }
    };

    const fillDay = (dayEl, dayKey, data) => {
      const toggle = dayEl.querySelector('[data-admin-config-hours-open]');
      const start = dayEl.querySelector('[data-admin-config-hours-start]');
      const end = dayEl.querySelector('[data-admin-config-hours-end]');
      const breakToggle = dayEl.querySelector('[data-admin-config-hours-break-toggle]');
      const breakStart = dayEl.querySelector('[data-admin-config-hours-break-start]');
      const breakEnd = dayEl.querySelector('[data-admin-config-hours-break-end]');
      const dayData = data[dayKey] || defaultDay();
      const isOpen = Boolean(dayData.abierto);
      if (toggle) {
        toggle.checked = isOpen;
      }
      applyTimeSelect(start, dayData.inicio || '09:00', '09:00');
      applyTimeSelect(end, dayData.fin || '18:00', '18:00');
      if (breakToggle && breakStart && breakEnd) {
        const hasBreak = dayData.descanso_inicio && dayData.descanso_fin;
        breakToggle.checked = Boolean(hasBreak);
        if (hasBreak) {
          applyTimeSelect(breakStart, dayData.descanso_inicio, '13:00');
          applyTimeSelect(breakEnd, dayData.descanso_fin, '14:00');
        } else {
          breakStart.value = '';
          breakEnd.value = '';
        }
        breakStart.disabled = !hasBreak;
        breakEnd.disabled = !hasBreak;
        breakStart.required = hasBreak;
        breakEnd.required = hasBreak;
      }
      setDayInputsState(dayEl, isOpen);
    };

    const renderHolidays = () => {
      if (!holidayList || !holidayEmpty) return;
      holidayList.innerHTML = '';
      const uniqueDates = Array.from(new Set(state.holidays))
        .filter((v) => typeof v === 'string' && v.trim() !== '')
        .sort();
      state.holidays = uniqueDates;
      if (uniqueDates.length === 0) {
        holidayEmpty.hidden = false;
        return;
      }
      holidayEmpty.hidden = true;
      uniqueDates.forEach((date) => {
        const li = document.createElement('li');
        li.className = 'admin-hours-holidays__item';
        li.dataset.date = date;
        const span = document.createElement('span');
        span.textContent = date;
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.setAttribute('data-admin-config-hours-holiday-remove', date);
        btn.innerHTML = '<i class="bx bx-trash"></i>';
        li.appendChild(span);
        li.appendChild(btn);
        holidayList.appendChild(li);
      });
    };

    const fillForm = () => {
      initializeTimezoneSelectors(state.schedule.timezone || '');
      dayItems.forEach((dayEl, idx) => {
        const key = DAY_KEYS[idx];
        if (!key) return;
        fillDay(dayEl, key, state.schedule);
      });
      renderHolidays();
    };

    const collectData = () => {
      const schedule = { timezone: '', feriados: [] };
      schedule.timezone = timezoneField.value.trim();
      dayItems.forEach((dayEl, idx) => {
        const key = DAY_KEYS[idx];
        if (!key) return;
        const toggle = dayEl.querySelector('[data-admin-config-hours-open]');
        const start = dayEl.querySelector('[data-admin-config-hours-start]');
        const end = dayEl.querySelector('[data-admin-config-hours-end]');
        const breakToggle = dayEl.querySelector('[data-admin-config-hours-break-toggle]');
        const breakStart = dayEl.querySelector('[data-admin-config-hours-break-start]');
        const breakEnd = dayEl.querySelector('[data-admin-config-hours-break-end]');
        const abierto = toggle ? Boolean(toggle.checked) : false;
        const hasBreak = breakToggle ? Boolean(breakToggle.checked) : false;
        schedule[key] = {
          abierto,
          inicio: abierto && start ? start.value.trim() : '',
          fin: abierto && end ? end.value.trim() : '',
          descanso_inicio: abierto && hasBreak && breakStart ? breakStart.value.trim() : '',
          descanso_fin: abierto && hasBreak && breakEnd ? breakEnd.value.trim() : ''
        };
      });
      schedule.feriados = state.holidays.slice();
      return schedule;
    };

    const showError = (msg) => {
      if (!errorEl) {
        adminNotify(msg, 'error');
        return;
      }
      errorEl.textContent = msg;
      errorEl.hidden = false;
    };

    const open = () => {
      if (modalLoading) modalLoading.show(modal);
      state.schedule = normalizeSchedule(window.ADMIN_INFO_BARBERIA && window.ADMIN_INFO_BARBERIA.horarios);
      state.holidays = Array.isArray(state.schedule.feriados) ? [...state.schedule.feriados] : [];
      if (errorEl) {
        errorEl.hidden = true;
        errorEl.textContent = '';
      }
      if (submitBtn) submitBtn.disabled = false;
      fillForm();
      modal.hidden = false;
      requestAnimationFrame(() => {
        modal.classList.add('is-visible');
        if (modalLoading) modalLoading.hide(modal);
      });
    };

    const close = () => {
      modal.classList.remove('is-visible');
      modal.hidden = true;
      if (modalLoading) modalLoading.hide(modal);
      if (submitBtn) submitBtn.disabled = false;
    };

    closeEls.forEach((btn) => btn.addEventListener('click', close));
    document.addEventListener('keydown', (evt) => {
      if (evt.key === 'Escape' && !modal.hidden) {
        close();
      }
    });

    dayItems.forEach((dayEl) => {
      const toggle = dayEl.querySelector('[data-admin-config-hours-open]');
      const breakToggle = dayEl.querySelector('[data-admin-config-hours-break-toggle]');
      const breakStart = dayEl.querySelector('[data-admin-config-hours-break-start]');
      const breakEnd = dayEl.querySelector('[data-admin-config-hours-break-end]');
      if (!toggle) return;
      toggle.addEventListener('change', () => {
        if (toggle.checked) {
          const start = dayEl.querySelector('[data-admin-config-hours-start]');
          const end = dayEl.querySelector('[data-admin-config-hours-end]');
          applyTimeSelect(start, start && start.value ? start.value : '09:00', '09:00');
          applyTimeSelect(end, end && end.value ? end.value : '18:00', '18:00');
        }
        setDayInputsState(dayEl, toggle.checked);
      });
      if (breakToggle && breakStart && breakEnd) {
        breakToggle.addEventListener('change', () => {
          const enabled = Boolean(breakToggle.checked);
          breakStart.disabled = !enabled;
          breakEnd.disabled = !enabled;
          breakStart.required = enabled;
          breakEnd.required = enabled;
          if (!enabled) {
            breakStart.value = '';
            breakEnd.value = '';
          }
        });
      }
    });

    if (holidayAddBtn && holidayInput) {
      holidayAddBtn.addEventListener('click', () => {
        const value = holidayInput.value.trim();
        if (value === '') {
          adminNotify('Selecciona una fecha para agregar.', 'info');
          return;
        }
        if (state.holidays.includes(value)) {
          adminNotify('La fecha ya se encuentra registrada como feriado.', 'warning');
          return;
        }
        state.holidays.push(value);
        renderHolidays();
        holidayInput.value = '';
      });
    }

    if (holidayList) {
      holidayList.addEventListener('click', (evt) => {
        const btn = evt.target && evt.target.closest('[data-admin-config-hours-holiday-remove]');
        if (!btn) return;
        const date = btn.getAttribute('data-admin-config-hours-holiday-remove');
        state.holidays = state.holidays.filter((d) => d !== date);
        renderHolidays();
      });
    }

    form.addEventListener('submit', async (evt) => {
      evt.preventDefault();
      if (errorEl) {
        errorEl.hidden = true;
        errorEl.textContent = '';
      }
      if (!form.reportValidity()) {
        return;
      }
      const payload = collectData();
      if (!payload.timezone) {
        showError('Selecciona una zona horaria.');
        return;
      }
      try {
        if (submitBtn) submitBtn.disabled = true;
        const res = await fetch((window.AdminApiBase || '../../../src/API/') + 'AdminConfig.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'config_update', key: 'info_barberia', data: { horarios: payload } })
        });
        const json = await res.json().catch(() => null);
        if (!res.ok || !json || !json.ok) {
          throw new Error(json && json.error ? json.error : 'No se pudieron guardar los horarios.');
        }
        state.schedule = normalizeSchedule(json.data && json.data.horarios ? json.data.horarios : payload);
        state.holidays = Array.isArray(state.schedule.feriados) ? [...state.schedule.feriados] : [];
        if (!window.ADMIN_INFO_BARBERIA || typeof window.ADMIN_INFO_BARBERIA !== 'object') {
          window.ADMIN_INFO_BARBERIA = {};
        }
        window.ADMIN_INFO_BARBERIA.horarios = clone(state.schedule);
        adminNotify('Horarios actualizados correctamente.', 'success');
        close();
      } catch (err) {
        const message = err && err.message ? err.message : 'No se pudieron guardar los horarios.';
        showError(message);
        if (submitBtn) submitBtn.disabled = false;
      }
    });

    window.AdminConfigHoursModal = { open, close };
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', setup, { once: true });
  } else {
    setup();
  }
})();

