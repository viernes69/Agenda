(function adminConfigFiscalModal() {
  const modal = document.querySelector('[data-admin-modal="config-fiscal"]');
  if (!modal) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', adminConfigFiscalModal, { once: true });
    }
    return;
  }

  const modalLoading = window.AdminModalLoading;

  const form = modal.querySelector('[data-admin-config-fiscal-form]');
  if (!form) return;

  const submitBtn = form.querySelector('[data-admin-config-fiscal-submit]');
  const errorEl = form.querySelector('[data-admin-config-fiscal-error]');
  const closeEls = modal.querySelectorAll('[data-admin-config-fiscal-close]');
  const fieldNodes = Array.from(form.querySelectorAll('[data-admin-config-fiscal-field]'));

  const clone = (value) => JSON.parse(JSON.stringify(value || {}));
  const notify = (message, type) => {
    if (typeof window.adminNotify === 'function') {
      window.adminNotify(message, type || 'success');
    } else if (typeof window.AdminNotify === 'function') {
      window.AdminNotify(message, type || 'success');
    } else {
      console.log('[NOTIFY]', message);
    }
  };

  let current = clone(window.ADMIN_INFO_BARBERIA || {});

  const getValue = (data, path) => {
    return path.split('.').reduce((acc, key) => (acc && Object.prototype.hasOwnProperty.call(acc, key) ? acc[key] : undefined), data);
  };

  const fillForm = (data) => {
    const fiscal = (data && data.fiscal) || {};
    fieldNodes.forEach((field) => {
      const path = field.getAttribute('data-admin-config-fiscal-field');
      if (!path) return;
      const value = getValue({ fiscal }, path) ?? '';
      if (path === 'fiscal.enabled') {
        field.value = value ? '1' : '0';
      } else if (path === 'fiscal.iva_porcentaje') {
        const numeric = Number(value);
        field.value = Number.isFinite(numeric) ? numeric : '';
      } else {
        field.value = String(value || '');
      }
    });
  };

  const collect = () => {
    const payload = { fiscal: {} };
    fieldNodes.forEach((field) => {
      const path = field.getAttribute('data-admin-config-fiscal-field');
      if (!path) return;
      const rawValue = field.value;
      if (path === 'fiscal.iva_porcentaje') {
        const numeric = Number(rawValue);
        payload.fiscal.iva_porcentaje = Number.isFinite(numeric) ? numeric : 0;
      } else if (path === 'fiscal.comprobante') {
        payload.fiscal.comprobante = String(rawValue || '').trim();
      } else if (path === 'fiscal.enabled') {
        payload.fiscal.enabled = rawValue === '1';
      }
    });
    return payload;
  };

  const open = () => {
    if (modalLoading) modalLoading.show(modal);
    fillForm(current);
    if (errorEl) {
      errorEl.hidden = true;
      errorEl.textContent = '';
    }
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

  const showError = (message) => {
    if (errorEl) {
      errorEl.hidden = false;
      errorEl.textContent = message;
    }
    notify(message, 'error');
  };

  closeEls.forEach((btn) => btn.addEventListener('click', close));
  document.addEventListener('keydown', (evt) => {
    if (evt.key === 'Escape' && !modal.hidden) {
      close();
    }
  });

  form.addEventListener('submit', async (evt) => {
    evt.preventDefault();
    if (!form.reportValidity()) return;
    if (submitBtn) submitBtn.disabled = true;
    if (errorEl) {
      errorEl.hidden = true;
      errorEl.textContent = '';
    }

    const payload = collect();
    try {
      const response = await fetch((window.AdminApiBase || '../../../src/API/') + 'AdminConfig.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'config_update', key: 'info_barberia', data: payload }),
      });
      const json = await response.json().catch(() => null);
      if (!response.ok || !json || !json.ok) {
        throw new Error(json && json.error ? json.error : 'No se pudo guardar la configuraci&oacute;n fiscal.');
      }
      current = clone(json.data || {});
      window.ADMIN_INFO_BARBERIA = clone(json.data || {});
      notify('Configuraci&oacute;n fiscal guardada.', 'success');
      close();
    } catch (error) {
      const message = error && error.message ? error.message : 'No se pudo guardar la configuraci&oacute;n fiscal.';
      showError(message);
      if (submitBtn) submitBtn.disabled = false;
    }
  });

  window.AdminConfigFiscalModal = { open, close };
  try {
    fillForm(current);
  } catch (error) {
    // ignore initial fill errors
  }
})();
