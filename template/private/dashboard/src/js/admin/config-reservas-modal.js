(function adminConfigReservasModal() {
  const modal = document.querySelector('[data-admin-modal="config-reservas"]');
  if (!modal) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', adminConfigReservasModal, { once: true });
    }
    return;
  }
  const form = modal.querySelector('[data-admin-config-reservas-form]');
  if (!form && document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', adminConfigReservasModal, { once: true });
    return;
  }
  if (!form) return;
  const modalLoading = window.AdminModalLoading;
  const closeEls = modal.querySelectorAll('[data-admin-config-reservas-close]');
  const errorEl = form.querySelector('[data-admin-config-reservas-error]');
  const submitBtn = form.querySelector('[data-admin-config-reservas-submit]');
  const fieldNodes = Array.from(form.querySelectorAll('[data-admin-config-reservas-field]'));
  const toggleNodes = Array.from(form.querySelectorAll('[data-admin-config-reservas-toggle]'));

  const clone = (obj) => {
    try {
      return JSON.parse(JSON.stringify(obj || {}));
    } catch (_) {
      return {};
    }
  };

  const defaultConfig = () => ({
    anticipacion_minutos: 60,
    max_dias_adelante: 30,
    politica_cancelacion_horas: 1,
    requiere_login: false,
    max_reservas_por_dia_por_cliente: 1,
    mercado_pago_enabled: false,
    mercado_pago_required: false
  });

  const ensureNumber = (value, fallback, min) => {
    const num = Number(value);
    if (!Number.isFinite(num)) return fallback;
    const rounded = Math.round(num);
    return Math.max(min, rounded);
  };

  const toBool = (value) => {
    if (typeof value === 'boolean') return value;
    if (typeof value === 'number') return value === 1;
    const normalized = String(value ?? '').trim().toLowerCase();
    return normalized === '1' || normalized === 'true' || normalized === 'si' || normalized === 'sí' || normalized === 'yes';
  };

  const normalizeConfig = (source) => {
    const base = defaultConfig();
    if (!source || typeof source !== 'object') {
      return base;
    }
    if (source.hasOwnProperty('anticipacion_minutos')) {
      base.anticipacion_minutos = ensureNumber(source.anticipacion_minutos, base.anticipacion_minutos, 0);
    }
    if (source.hasOwnProperty('max_dias_adelante')) {
      base.max_dias_adelante = ensureNumber(source.max_dias_adelante, base.max_dias_adelante, 0);
    }
    if (source.hasOwnProperty('politica_cancelacion_horas')) {
      base.politica_cancelacion_horas = ensureNumber(source.politica_cancelacion_horas, base.politica_cancelacion_horas, 0);
    }
    if (source.hasOwnProperty('max_reservas_por_dia_por_cliente')) {
      base.max_reservas_por_dia_por_cliente = ensureNumber(
        source.max_reservas_por_dia_por_cliente,
        base.max_reservas_por_dia_por_cliente,
        1
      );
    }
    if (source.hasOwnProperty('requiere_login')) {
      base.requiere_login = toBool(source.requiere_login);
    }
    if (source.hasOwnProperty('mercado_pago_enabled')) {
      base.mercado_pago_enabled = toBool(source.mercado_pago_enabled);
    }
    if (source.hasOwnProperty('mercado_pago_required')) {
      base.mercado_pago_required = toBool(source.mercado_pago_required);
    }
    return base;
  };

  let currentConfig = normalizeConfig(window.ADMIN_INFO_BARBERIA && window.ADMIN_INFO_BARBERIA.reservas);

  const fillForm = (data) => {
    fieldNodes.forEach((field) => {
      const key = field.getAttribute('data-admin-config-reservas-field');
      if (!key) return;
      if (key === 'requiere_login') {
        field.value = data.requiere_login ? '1' : '0';
      } else if (Object.prototype.hasOwnProperty.call(data, key)) {
        field.value = String(data[key]);
      } else {
        field.value = '';
      }
    });
    toggleNodes.forEach((field) => {
      const key = field.getAttribute('data-admin-config-reservas-toggle');
      if (!key) return;
      field.checked = !!data[key];
    });
  };

  const collectData = () => {
    const result = { ...currentConfig };
    fieldNodes.forEach((field) => {
      const key = field.getAttribute('data-admin-config-reservas-field');
      if (!key) return;
      if (key === 'requiere_login') {
        result.requiere_login = toBool(field.value);
        return;
      }
      if (key === 'max_reservas_por_dia_por_cliente') {
        result[key] = ensureNumber(field.value, currentConfig[key], 1);
        return;
      }
      if (Object.prototype.hasOwnProperty.call(result, key)) {
        result[key] = ensureNumber(field.value, currentConfig[key], 0);
      }
    });
    toggleNodes.forEach((field) => {
      const key = field.getAttribute('data-admin-config-reservas-toggle');
      if (!key) return;
      result[key] = !!field.checked;
    });
    if (!result.mercado_pago_enabled) {
      result.mercado_pago_required = false;
    }
    return result;
  };

  const showError = (msg) => {
    if (!errorEl) {
      adminNotify(msg, 'error');
      return;
    }
    errorEl.textContent = msg;
    errorEl.hidden = false;
  };

  const clearError = () => {
    if (!errorEl) return;
    errorEl.hidden = true;
    errorEl.textContent = '';
  };

  const open = () => {
    if (modalLoading) modalLoading.show(modal);
    currentConfig = normalizeConfig(window.ADMIN_INFO_BARBERIA && window.ADMIN_INFO_BARBERIA.reservas);
    fillForm(currentConfig);
    clearError();
    if (submitBtn) submitBtn.disabled = false;
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

  form.addEventListener('submit', async (evt) => {
    evt.preventDefault();
    clearError();
    if (!form.reportValidity()) {
      return;
    }
    const payload = collectData();
    try {
      if (submitBtn) submitBtn.disabled = true;
      const res = await fetch((window.AdminApiBase || '../../../src/API/') + 'AdminConfig.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'config_update', key: 'info_barberia', data: { reservas: payload } })
      });
      const json = await res.json().catch(() => null);
      if (!res.ok || !json || !json.ok) {
        throw new Error(json && json.error ? json.error : 'No se pudo guardar la configuracion de reservas.');
      }
      const infoDataRaw = json.data && typeof json.data === 'object' ? json.data : null;
      const baseInfo = infoDataRaw && Object.keys(infoDataRaw).length > 0
        ? infoDataRaw
        : (window.ADMIN_INFO_BARBERIA && typeof window.ADMIN_INFO_BARBERIA === 'object' ? window.ADMIN_INFO_BARBERIA : {});
      currentConfig = normalizeConfig((baseInfo && baseInfo.reservas) || payload);
      window.ADMIN_INFO_BARBERIA = clone(baseInfo);
      window.ADMIN_INFO_BARBERIA.reservas = clone(currentConfig);
      adminNotify('Configuracion de reservas actualizada correctamente.', 'success');
      close();
    } catch (err) {
      const message = err && err.message ? err.message : 'No se pudo guardar la configuracion de reservas.';
      showError(message);
      if (submitBtn) submitBtn.disabled = false;
    }
  });

  window.AdminConfigReservasModal = { open, close };
})();
