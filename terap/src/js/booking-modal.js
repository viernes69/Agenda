// Handles interactions for the booking modal (fecha, servicio y horario)
(function (global) {
  const DEFAULT_CONTROLLER = {
    open: () => {},
    updateCTA: () => {},
    refreshSlots: () => {},
    fields: null,
  };

  const getBarberId = (barber) => {
    if (!barber) return null;
    return barber.barber_id ?? barber.ID_Barber ?? barber.id ?? null;
  };

  global.initBookingModal = function initBookingModal(options = {}) {
    const {
      modalManager,
      barberModal,
      bookButton = barberModal ? barberModal.querySelector('[data-barber-book]') : null,
      barberFields,
      dateInput,
      serviceSelect,
      state,
      applyAvatar,
      normalizeAvatarPath,
      showBarberPhoto,
      hideBarberPhoto,
    } = options;

    if (!modalManager || !barberModal || !barberFields || !state) {
      return DEFAULT_CONTROLLER;
    }

    let currentBarber = null;
    let currentWorkDays = [];
    let currentBarberSkills = [];

    const formatYmd = (date) => {
      const pad = (n) => String(n).padStart(2, '0');
      return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
    };

    const updateCTA = () => {
      if (!bookButton) return;
      const hasSlot = !!(state.selected && state.selected.slot && state.selected.barberId);
      const hasService = !barberFields.serviceSelect || !!barberFields.serviceSelect.value;
      const hasDate = !barberFields.dateInput || !!barberFields.dateInput.value;
      bookButton.disabled = !(hasSlot && hasService && hasDate);
    };

    const dayLabels = ['domingo', 'lunes', 'martes', 'miercoles', 'jueves', 'viernes', 'sabado'];
    const dayIndexMap = {
      domingo: 0,
      lunes: 1,
      martes: 2,
      miercoles: 3,
      jueves: 4,
      viernes: 5,
      sabado: 6,
    };
    const normalizeDayToken = (token) => {
      let value = String(token || '').trim().toLowerCase();
      if (!value) return '';
      if (typeof value.normalize === 'function') {
        value = value.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
      }
      return value.replace(/[^a-z]/g, '');
    };
    const toWorkDayIndices = (source) => {
      if (!source) return [];
      const indices = [];
      const pushIndex = (candidate) => {
        if (candidate === null || Number.isNaN(candidate)) return;
        const index = Number(candidate);
        if (index < 0 || index > 6 || indices.includes(index)) return;
        indices.push(index);
      };
      if (Array.isArray(source)) {
        source.forEach((value) => {
          if (typeof value === 'number') {
            pushIndex(value);
          } else {
            const numeric = Number(value);
            if (!Number.isNaN(numeric)) {
              pushIndex(numeric);
            } else {
              const idx = dayIndexMap[normalizeDayToken(value)];
              if (typeof idx === 'number') pushIndex(idx);
            }
          }
        });
        return indices;
      }
      const text = String(source || '');
      if (!text.trim()) return [];
      text
        .split(/[\/,;|]+|\s+y\s+|\s+a\s+/)
        .map((token) => normalizeDayToken(token))
        .filter(Boolean)
        .forEach((token) => {
          const idx = dayIndexMap[token];
          if (typeof idx === 'number') pushIndex(idx);
        });
      return indices;
    };
    const dayNameFromIndex = (index) => {
      const label = dayLabels[index] || 'dia seleccionado';
      return label.charAt(0).toUpperCase() + label.slice(1);
    };
    const setWarning = (text) => {
      if (!barberFields.warning) return;
      const value = String(text || '').trim();
      if (!value) {
        barberFields.warning.textContent = '';
        barberFields.warning.hidden = true;
        return;
      }
      barberFields.warning.textContent = value;
      barberFields.warning.hidden = false;
    };
    const resetSlotSelect = () => {
      if (!barberFields.slotSelect) return;
      barberFields.slotSelect.innerHTML = '';
      const placeholder = document.createElement('option');
      placeholder.value = '';
      placeholder.textContent = 'Selecciona un horario';
      barberFields.slotSelect.appendChild(placeholder);
    };
    const parseDateValue = (value) => {
      if (!value) return null;
      const parts = String(value).trim().split('-');
      if (parts.length !== 3) return null;
      const year = Number(parts[0]);
      const month = Number(parts[1]) - 1;
      const day = Number(parts[2]);
      if (Number.isNaN(year) || Number.isNaN(month) || Number.isNaN(day)) return null;
      const date = new Date(year, month, day);
      if (Number.isNaN(date.getTime())) return null;
      date.setHours(0, 0, 0, 0);
      return date;
    };
    const getScheduleLimits = () => {
      const today = new Date();
      today.setHours(0, 0, 0, 0);
      const limits = (state && state.scheduleLimits) || {};
      let minDate = parseDateValue(limits.min_date) || new Date(today);
      let maxDate = parseDateValue(limits.max_date);
      if (minDate < today) {
        minDate = new Date(today);
      }
      if (!maxDate || maxDate < minDate) {
        maxDate = new Date(minDate);
        maxDate.setDate(maxDate.getDate() + 30);
      }
      return { minDate, maxDate };
    };
    const applyDateConstraints = () => {
      if (!barberFields.dateInput) return;
      const { minDate, maxDate } = getScheduleLimits();
      barberFields.dateInput.min = formatYmd(minDate);
      barberFields.dateInput.max = formatYmd(maxDate);
    };
    const describeWorkingDays = () => {
      if (!currentWorkDays || currentWorkDays.length === 0) return '';
      return currentWorkDays
        .map((idx) => dayLabels[idx] || '')
        .filter(Boolean)
        .map((label) => label.charAt(0).toUpperCase() + label.slice(1))
        .join(', ');
    };
    const findNextWorkingDate = (startDate) => {
      if (!currentWorkDays || currentWorkDays.length === 0) return null;
      const { minDate, maxDate } = getScheduleLimits();
      const candidate = startDate ? new Date(startDate) : new Date(minDate);
      if (candidate < minDate) candidate.setTime(minDate.getTime());
      candidate.setHours(0, 0, 0, 0);
      while (candidate <= maxDate) {
        if (currentWorkDays.includes(candidate.getDay())) {
          return candidate;
        }
        candidate.setDate(candidate.getDate() + 1);
      }
      return null;
    };
    const ensureWorkingDay = (dateValue) => {
      if (!barberFields.slotSelect) {
        setWarning('');
        return true;
      }
      applyDateConstraints();
      if (!currentWorkDays || currentWorkDays.length === 0) {
        setWarning('Este profesional no tiene dias de trabajo configurados.');
        barberFields.slotSelect.disabled = true;
        state.selected.slot = null;
        updateCTA();
        return false;
      }
      const { minDate, maxDate } = getScheduleLimits();
      const original = parseDateValue(dateValue);
      let candidate = original ? new Date(original) : new Date(minDate);
      let adjusted = false;

      if (candidate < minDate) {
        candidate = new Date(minDate);
        adjusted = true;
      }
      if (candidate > maxDate) {
        candidate = new Date(maxDate);
        adjusted = true;
      }

      if (!currentWorkDays.includes(candidate.getDay())) {
        const next = findNextWorkingDate(candidate);
        if (!next) {
          const label = describeWorkingDays();
          setWarning(label
            ? `No hay fechas disponibles dentro del rango permitido. El profesional trabaja los: ${label}.`
            : 'No hay fechas disponibles para este profesional dentro del rango permitido.');
          barberFields.slotSelect.disabled = true;
          state.selected.slot = null;
          updateCTA();
          return false;
        }
        candidate = next;
        adjusted = true;
      }

      if (barberFields.dateInput) {
        const value = formatYmd(candidate);
        if (barberFields.dateInput.value !== value) {
          barberFields.dateInput.value = value;
        }
      }

      if (adjusted) {
        const label = describeWorkingDays();
        setWarning(label
          ? `Mostramos la proxima fecha disponible. El profesional trabaja los: ${label}.`
          : 'Mostramos la proxima fecha disponible para este profesional.');
      } else {
        setWarning('');
      }
      barberFields.slotSelect.disabled = false;
      return true;
    };
    const normalizeSkillIds = (value) => {
      if (value === null || value === undefined) return [];
      if (Array.isArray(value)) {
        return value
          .map((entry) => String(entry).trim())
          .filter((entry) => entry !== '');
      }
      return String(value || '')
        .split(/[;,]+/)
        .map((entry) => entry.trim())
        .filter(Boolean);
    };

    const loadServicesInto = async (selectEl, allowedIds = null) => {
      if (!selectEl) return [];
      selectEl.innerHTML = '';
      const placeholder = document.createElement('option');
      placeholder.value = '';
      placeholder.textContent = 'Selecciona un servicio';
      selectEl.appendChild(placeholder);
      selectEl.dataset.noServices = '';
      const allowed = Array.isArray(allowedIds) && allowedIds.length
        ? new Set(allowedIds.map((id) => String(id)))
        : null;
      try {
        const res = await fetch('src/API/Autoload.php?action=list&table=servicios');
        const payload = await res.json();
        const list = Array.isArray(payload && payload.data) ? payload.data : [];
        const activeServices = list.filter((service) => !service.Estado || String(service.Estado).toLowerCase() === 'activo');
        const filtered = allowed
          ? activeServices.filter((service) => allowed.has(String(service.ID_Servicio ?? service.id ?? '')))
          : activeServices;
        filtered.forEach((service) => {
          const option = document.createElement('option');
          option.value = service.ID_Servicio ?? service.id ?? '';
          const duration = service.Duracion ? ` - ${parseInt(service.Duracion, 10)} min` : '';
          option.textContent = `${service.Nombre || 'Servicio'}${duration}`;
          selectEl.appendChild(option);
        });
        if (filtered.length === 0) {
          selectEl.disabled = true;
          selectEl.dataset.noServices = '1';
        } else {
          selectEl.disabled = false;
          selectEl.dataset.noServices = '';
        }
        return filtered.map((service) => String(service.ID_Servicio ?? service.id ?? ''));
      } catch (_) {
        // deja el placeholder
        selectEl.disabled = true;
        selectEl.dataset.noServices = 'error';
      }
      return [];
    };

    const refreshModalSlots = async () => {
      if (!barberFields.slotSelect || !currentBarber) return;
      const dateValue = barberFields.dateInput
        ? barberFields.dateInput.value
        : (dateInput ? dateInput.value : '');
      const serviceValue = barberFields.serviceSelect
        ? barberFields.serviceSelect.value
        : (serviceSelect ? serviceSelect.value : '');
      const barberId = getBarberId(currentBarber);
      if (!barberId) {
        resetSlotSelect();
        setWarning('No se pudo identificar al profesional seleccionado.');
        barberFields.slotSelect.disabled = true;
        state.selected.slot = null;
        updateCTA();
        return;
      }

      const allowed = ensureWorkingDay(dateValue);
      const currentDateValue = barberFields.dateInput
        ? barberFields.dateInput.value
        : dateValue;
      resetSlotSelect();
      if (!allowed) {
        return;
      }
      if (barberFields.serviceSelect && barberFields.serviceSelect.disabled) {
        const status = barberFields.serviceSelect.dataset.noServices || '';
        if (status === '1') {
          setWarning('Este profesional no tiene servicios habilitados.');
        } else if (status === 'error') {
          setWarning('No se pudieron cargar los servicios. Intenta nuevamente.');
        }
        barberFields.slotSelect.disabled = true;
        state.selected.slot = null;
        updateCTA();
        return;
      }

      try {
        const params = new URLSearchParams();
        if (currentDateValue) params.set('date', currentDateValue);
        if (serviceValue) params.set('service_id', serviceValue);
        params.set('barber_id', barberId);
        const res = await fetch(`src/API/schedule.php?${params.toString()}`);
        const payload = await res.json();
        if (payload && payload.limits) {
          state.scheduleLimits = payload.limits;
          applyDateConstraints();
          const latestDateValue = barberFields.dateInput
            ? barberFields.dateInput.value
            : currentDateValue;
          ensureWorkingDay(latestDateValue);
        }
        const list = Array.isArray(payload && payload.data) ? payload.data : [];
        const found = list.find((barber) => String(barber.barber_id) === String(barberId));
      const slots = found && Array.isArray(found.slots) ? found.slots : [];
      const normalizedSlots = slots
        .filter((slot) => typeof slot === 'string' || typeof slot === 'number')
        .map((slot) => String(slot).trim())
        .filter(Boolean);
      if (normalizedSlots.length === 0) {
        setWarning('No hay horarios disponibles para este profesional en la fecha seleccionada.');
        barberFields.slotSelect.disabled = true;
      } else {
        setWarning('');
        barberFields.slotSelect.disabled = false;
      }
      normalizedSlots.forEach((slot) => {
        const option = document.createElement('option');
        option.value = slot;
        option.textContent = slot;
        barberFields.slotSelect.appendChild(option);
      });
      if (Array.isArray(state.data)) {
        const targetId = String(barberId);
        const match = state.data.find((entry) => String(entry.barber_id) === targetId);
        if (match) {
          // Mantiene los slots usados para la validaciÃ³n posterior en el flujo principal.
          match.slots = normalizedSlots.slice();
        }
      }
      state.selected.slot = null;
      updateCTA();
      } catch (_) {
        // deja el placeholder
        barberFields.slotSelect.disabled = true;
        setWarning('No se pudo cargar la disponibilidad. Intenta nuevamente.');
        state.selected.slot = null;
        updateCTA();
      }
    };

    const bindPhotoOverlay = () => {
      if (!barberFields.photoOverlay) return;
      if (!barberFields.photoOverlay._bound) {
        barberFields.photoOverlay.addEventListener('click', (event) => {
          if (event.target === barberFields.photoOverlay && typeof hideBarberPhoto === 'function') {
            hideBarberPhoto();
          }
        });
        barberFields.photoOverlay._bound = true;
      }
      if (barberFields.avatarTrigger) {
        barberFields.avatarTrigger.onclick = () => {
          if (typeof showBarberPhoto === 'function') {
            showBarberPhoto();
          }
        };
      }
      if (barberFields.photoClose) {
        barberFields.photoClose.onclick = () => {
          if (typeof hideBarberPhoto === 'function') {
            hideBarberPhoto();
          }
        };
      }
    };

    const open = (item) => {
      if (!item) return;
      currentBarber = item;
      currentWorkDays = toWorkDayIndices(
        item.workDays
        ?? item.working_days
        ?? item.DiasTrabajo
        ?? item.diasTrabajo
        ?? item.dias_trabajo
        ?? ''
      );
      currentBarberSkills = normalizeSkillIds(item.skills ?? item.Habilidades ?? item.habilidades ?? item.skill_ids ?? '');
      setWarning('');
      resetSlotSelect();
      state.selected = {
        barberId: getBarberId(item),
        slot: null,
        barberName: item.barber || null,
      };
      state.selectedButton = null;

      if (barberFields.name) barberFields.name.textContent = item.barber || 'Profesional';
      const turnsText = Array.isArray(item.turns) ? item.turns.join(' â€¢ ') : '';
      if (barberFields.turns) barberFields.turns.textContent = turnsText;

      if (barberFields.avatarInner && typeof applyAvatar === 'function') {
        applyAvatar(barberFields.avatarInner, item.barber, item.avatar);
      }

      if (barberFields.photoImg && barberFields.photoFallback && barberFields.photoFallbackInner) {
        const url = typeof normalizeAvatarPath === 'function'
          ? normalizeAvatarPath(item.avatar)
          : (item.avatar || '');
        if (url) {
          barberFields.photoImg.src = url;
          barberFields.photoImg.hidden = false;
          barberFields.photoFallback.hidden = true;
        } else {
          barberFields.photoImg.hidden = true;
          barberFields.photoFallback.hidden = false;
          if (typeof applyAvatar === 'function') {
            applyAvatar(barberFields.photoFallbackInner, item.barber, '');
          }
        }
        if (barberFields.photoName) barberFields.photoName.textContent = item.barber || 'Profesional';
        if (barberFields.photoTurns) barberFields.photoTurns.textContent = turnsText;
      }

      if (barberFields.dateInput) {
        applyDateConstraints();
        if (!barberFields.dateInput.value) {
          const next = findNextWorkingDate();
          if (next) {
            barberFields.dateInput.value = formatYmd(next);
          }
        }
      }

      const initialDateValue = barberFields.dateInput
        ? barberFields.dateInput.value
        : (dateInput ? dateInput.value : '');
      const initialAllowed = ensureWorkingDay(initialDateValue);
      if (!initialAllowed) {
        updateCTA();
      }

      if (barberFields.serviceSelect) {
        loadServicesInto(barberFields.serviceSelect, currentBarberSkills).then((availableServices) => {
          const current = barberFields.serviceSelect.value;
          if (!current || !availableServices.includes(String(current))) {
            barberFields.serviceSelect.value = '';
          }
          if (availableServices.length > 0) {
            setWarning('');
            barberFields.serviceSelect.disabled = false;
          }
          refreshModalSlots();
        });
      } else {
        refreshModalSlots();
      }

      if (barberFields.dateInput) {
        barberFields.dateInput.onchange = refreshModalSlots;
      }
      if (barberFields.serviceSelect) {
        barberFields.serviceSelect.onchange = () => {
          const opt = barberFields.serviceSelect.selectedOptions && barberFields.serviceSelect.selectedOptions[0];
          state.serviceName = opt ? (opt.textContent || 'Servicio').split(' - ')[0] : 'Servicio';
          refreshModalSlots();
          updateCTA();
        };
      }
      if (barberFields.slotSelect) {
        barberFields.slotSelect.onchange = () => {
          const value = barberFields.slotSelect.value;
          state.selected = {
            barberId: getBarberId(item),
            slot: value || null,
            barberName: item.barber,
          };
          updateCTA();
        };
      }
      bindPhotoOverlay();
      updateCTA();
      if (typeof modalManager.open === 'function') {
        modalManager.open('barber');
      }
    };

    bindPhotoOverlay();

    return {
      open,
      updateCTA,
      refreshSlots: refreshModalSlots,
      fields: barberFields,
    };
  };
})(window);
















