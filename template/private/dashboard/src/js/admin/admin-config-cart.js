(function adminConfigCartModal() {
  const modal = document.querySelector('[data-admin-modal="config-carrito"]');
  if (!modal) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', adminConfigCartModal, { once: true });
    }
    return;
  }

  const modalLoading = window.AdminModalLoading;
  const form = modal.querySelector('[data-admin-config-cart-form]');
  if (!form) return;

  const toggles = Array.from(form.querySelectorAll('[data-admin-config-cart-toggle]'));
  const fields = Array.from(form.querySelectorAll('[data-admin-config-cart-field]'));
  const submitBtn = form.querySelector('[data-admin-config-cart-submit]');
  const errorEl = form.querySelector('[data-admin-config-cart-error]');
  const closeEls = modal.querySelectorAll('[data-admin-config-cart-close]');

  const clone = (value) => {
    try { return JSON.parse(JSON.stringify(value || {})); } catch (_) { return {}; }
  };

  const defaults = () => ({
    enabled: true,
    whatsapp_enabled: true,
    mercado_pago_enabled: true,
    pickup_enabled: true,
    delivery_enabled: true,
    instructions: 'Coordinamos entrega o retiro por este medio. Gracias!'
  });

  const toBool = (value, fallback) => {
    if (typeof value === 'boolean') return value;
    if (typeof value === 'number') return value === 1;
    if (value === undefined || value === null || value === '') return fallback;
    const normalized = String(value).trim().toLowerCase();
    return normalized === '1' || normalized === 'true' || normalized === 'si' || normalized === 'sí' || normalized === 'yes';
  };

  const normalize = (data) => {
    const base = defaults();
    const src = data && typeof data === 'object' ? data : {};
    Object.keys(base).forEach((key) => {
      if (key === 'instructions') {
        base.instructions = String(src.instructions ?? base.instructions).trim();
      } else {
        base[key] = toBool(src[key], base[key]);
      }
    });
    return base;
  };

  const notify = (message, type) => {
    if (typeof window.adminNotify === 'function') {
      window.adminNotify(message, type || 'success');
    } else {
      console.log('[NOTIFY]', message);
    }
  };

  const showError = (message) => {
    if (errorEl) {
      errorEl.textContent = message;
      errorEl.hidden = false;
    }
    notify(message, 'error');
  };

  const clearError = () => {
    if (!errorEl) return;
    errorEl.textContent = '';
    errorEl.hidden = true;
  };

  const fillForm = (config) => {
    toggles.forEach((input) => {
      const key = input.getAttribute('data-admin-config-cart-toggle');
      input.checked = !!config[key];
    });
    fields.forEach((field) => {
      const key = field.getAttribute('data-admin-config-cart-field');
      field.value = String(config[key] ?? '');
    });
  };

  const collect = () => {
    const result = normalize((window.ADMIN_INFO_BARBERIA || {}).carrito);
    toggles.forEach((input) => {
      const key = input.getAttribute('data-admin-config-cart-toggle');
      result[key] = !!input.checked;
    });
    fields.forEach((field) => {
      const key = field.getAttribute('data-admin-config-cart-field');
      result[key] = String(field.value || '').trim();
    });
    if (result.enabled && !result.pickup_enabled && !result.delivery_enabled) {
      throw new Error('Activa retiro o entrega para poder recibir pedidos.');
    }
    return result;
  };

  const open = () => {
    if (modalLoading) modalLoading.show(modal);
    const config = normalize((window.ADMIN_INFO_BARBERIA || {}).carrito);
    fillForm(config);
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
    if (evt.key === 'Escape' && !modal.hidden) close();
  });

  form.addEventListener('submit', async (evt) => {
    evt.preventDefault();
    clearError();
    if (!form.reportValidity()) return;
    let carrito;
    try {
      carrito = collect();
    } catch (error) {
      showError(error && error.message ? error.message : 'Revisa la configuracion del carrito.');
      return;
    }
    if (submitBtn) submitBtn.disabled = true;
    const payload = { carrito };
    try {
      const res = await fetch('../../../src/API/AdminConfig.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'config_update', key: 'info_barberia', data: payload })
      });
      const json = await res.json().catch(() => null);
      if (!res.ok || !json || !json.ok) {
        throw new Error(json && json.error ? json.error : 'No se pudo guardar el carrito.');
      }
      window.ADMIN_INFO_BARBERIA = clone(json.data || {});
      window.ADMIN_INFO_BARBERIA.carrito = normalize(window.ADMIN_INFO_BARBERIA.carrito || carrito);
      notify('Carrito y pedidos actualizados.', 'success');
      close();
    } catch (error) {
      showError(error && error.message ? error.message : 'No se pudo guardar el carrito.');
      if (submitBtn) submitBtn.disabled = false;
    }
  });

  window.AdminConfigCartModal = { open, close };
})();
