(function adminConfigMercadoPagoModal() {
  const modal = document.querySelector('[data-admin-modal="config-mercadopago"]');
  if (!modal) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', adminConfigMercadoPagoModal, { once: true });
    }
    return;
  }

  const modalLoading = window.AdminModalLoading;

  const form = modal.querySelector('[data-admin-config-mercadopago-form]');
  if (!form) return;

  const submitBtn = form.querySelector('[data-admin-config-mercadopago-submit]');
  const errorEl = form.querySelector('[data-admin-config-mercadopago-error]');
  const closeEls = modal.querySelectorAll('[data-admin-config-mercadopago-close]');
  const fieldNodes = Array.from(form.querySelectorAll('[data-admin-config-mercadopago-field]'));
  const methodsSelect = form.querySelector('[data-admin-config-mercadopago-methods]');
  const countrySelect = form.querySelector('#admin-config-mercadopago-country');

  const notify = (message, type) => {
    if (typeof window.adminNotify === 'function') {
      window.adminNotify(message, type || 'success');
    } else if (typeof window.AdminNotify === 'function') {
      window.AdminNotify(message, type || 'success');
    } else {
      console.log('[NOTIFY]', message);
    }
  };

  const clone = (value) => JSON.parse(JSON.stringify(value || {}));
  let current = clone(window.ADMIN_INFO_BARBERIA || {});

  const COUNTRIES = (function buildCountries() {
    const list = [];
    if (window.AdminConfigInfoModal && Array.isArray(window.AdminConfigInfoModal.COUNTRIES)) {
      return window.AdminConfigInfoModal.COUNTRIES;
    }
    const infoModalCountries = (() => {
      try {
        const script = document.querySelector('script[data-admin-config-info-countries]');
        if (script) {
          return JSON.parse(script.textContent);
        }
      } catch (error) {
        /* ignore */
      }
      return null;
    })();
    if (Array.isArray(infoModalCountries) && infoModalCountries.length) return infoModalCountries;
    const defaultCountries = [
      { code: 'AR', name: 'Argentina' },
      { code: 'BR', name: 'Brasil' },
      { code: 'CL', name: 'Chile' },
      { code: 'CO', name: 'Colombia' },
      { code: 'MX', name: 'México' },
      { code: 'PE', name: 'Perú' },
      { code: 'UY', name: 'Uruguay' },
      { code: 'VE', name: 'Venezuela' },
    ];
    return defaultCountries;
  })();

  const PAYMENT_METHOD_LABELS = {
    credit_card: 'Tarjeta de crédito',
    debit_card: 'Tarjeta de débito',
    prepaid_card: 'Tarjeta prepaga',
    account_money: 'Dinero en cuenta',
    bank_transfer: 'Transferencia bancaria',
    ticket: 'Pago en efectivo/pago en punto',
  };

  const ensureCountryOptions = () => {
    if (!countrySelect) return;
    if (countrySelect.dataset.filled === 'true') return;
    const fragment = document.createDocumentFragment();
    COUNTRIES.forEach((country) => {
      if (!country || !country.code) return;
      const option = document.createElement('option');
      option.value = country.code;
      option.textContent = country.name || country.code;
      fragment.appendChild(option);
    });
    countrySelect.appendChild(fragment);
    countrySelect.dataset.filled = 'true';
  };

  const getValue = (obj, path) => {
    return path.split('.').reduce((acc, key) => (acc && Object.prototype.hasOwnProperty.call(acc, key) ? acc[key] : undefined), obj);
  };

  const setValue = (target, path, value) => {
    const keys = path.split('.');
    let cursor = target;
    keys.forEach((key, index) => {
      if (index === keys.length - 1) {
        cursor[key] = value;
      } else {
        if (!cursor[key] || typeof cursor[key] !== 'object') cursor[key] = {};
        cursor = cursor[key];
      }
    });
  };

  const initSecretToggles = () => {
    const toggles = modal.querySelectorAll('[data-admin-secret-toggle]');
    toggles.forEach((btn) => {
      if (btn.dataset.bound === '1') return;
      btn.dataset.bound = '1';
      const container = btn.closest('.admin-form__secret');
      if (!container) return;
      const input = container.querySelector('[data-admin-secret-input]');
      if (!input) return;
      btn.addEventListener('click', () => {
        const isHidden = input.type === 'password';
        input.type = isHidden ? 'text' : 'password';
        btn.textContent = isHidden ? 'Ocultar' : 'Mostrar';
        const label = btn.getAttribute('aria-label') || '';
        const newLabel = label.replace(isHidden ? 'Mostrar' : 'Ocultar', '');
        btn.setAttribute('aria-label', `${isHidden ? 'Ocultar' : 'Mostrar'} ${newLabel || ''}`.trim());
      });
    });
  };

  const fillForm = (data) => {
    ensureCountryOptions();
    const mp = (data && data.mercado_pago) || {};
    fieldNodes.forEach((field) => {
      const path = field.getAttribute('data-admin-config-mercadopago-field');
      if (!path) return;
      const value = getValue({ mercado_pago: mp }, path);
      if (path === 'mercado_pago.enabled') {
        field.value = value ? '1' : '0';
      } else {
        field.value = value !== undefined && value !== null ? String(value) : '';
      }
      if (field.matches('[data-admin-secret-input]')) {
        field.type = 'password';
      }
    });

    if (countrySelect) {
      const value = mp.country || '';
      countrySelect.value = value && Array.from(countrySelect.options).some((opt) => opt.value === value) ? value : '';
    }

    if (methodsSelect) {
      const values = Array.isArray(mp.allowed_payment_methods) ? mp.allowed_payment_methods.map(String) : [];
      Array.from(methodsSelect.options).forEach((option) => {
        option.selected = values.includes(option.value);
      });
    }
    initSecretToggles();
  };

  const collect = () => {
    const payload = { mercado_pago: {} };
    fieldNodes.forEach((field) => {
      const path = field.getAttribute('data-admin-config-mercadopago-field');
      if (!path) return;
      let value = field.value;
      if (path === 'mercado_pago.enabled') {
        setValue(payload, path, value === '1');
      } else if (path === 'mercado_pago.modo') {
        setValue(payload, path, value === 'live' ? 'live' : 'test');
      } else {
        setValue(payload, path, value === undefined ? '' : value);
      }
    });
    if (countrySelect) {
      const countryValue = countrySelect.value || '';
      setValue(payload, 'mercado_pago.country', countryValue);
    }
    if (methodsSelect) {
      const selected = Array.from(methodsSelect.selectedOptions).map((option) => option.value);
      setValue(payload, 'mercado_pago.allowed_payment_methods', selected);
    }
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
        throw new Error(json && json.error ? json.error : 'No se pudo guardar la configuraci&oacute;n de Mercado Pago.');
      }
      current = clone(json.data || {});
      window.ADMIN_INFO_BARBERIA = clone(json.data || {});
      notify('Configuraci&oacute;n de Mercado Pago guardada.', 'success');
      close();
    } catch (error) {
      const message = error && error.message ? error.message : 'No se pudo guardar la configuraci&oacute;n de Mercado Pago.';
      showError(message);
      if (submitBtn) submitBtn.disabled = false;
    }
  });

  window.AdminConfigMercadoPagoModal = { open, close, PAYMENT_METHOD_LABELS, COUNTRIES };
  try {
    fillForm(current);
  } catch (error) {
    /* ignore initial fill errors */
  }
  initSecretToggles();
})();
