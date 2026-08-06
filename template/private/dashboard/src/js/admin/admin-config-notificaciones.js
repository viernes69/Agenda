(function adminConfigNotificacionesModal() {
  const modal = document.querySelector('[data-admin-modal="config-notificaciones"]');
  if (!modal) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', adminConfigNotificacionesModal, { once: true });
    }
    return;
  }

  const modalLoading = window.AdminModalLoading;

  const form = modal.querySelector('[data-admin-config-notificaciones-form]');
  if (!form) return;

  const enabledCheckbox = form.querySelector('[data-admin-config-notificaciones-enabled]');
  const ownerEmailInput = form.querySelector('[data-admin-config-notificaciones-owner-email]');
  const countrySelect = form.querySelector('[data-admin-config-notificaciones-country]');
  const numberInput = form.querySelector('[data-admin-config-notificaciones-number]');
  const submitBtn = form.querySelector('[data-admin-config-notificaciones-submit]');
  const errorEl = form.querySelector('[data-admin-config-notificaciones-error]');
  const closeEls = modal.querySelectorAll('[data-admin-config-notificaciones-close]');

  const COUNTRY_CODES = Array.from(countrySelect ? countrySelect.options : []).map((option) => option.value);

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

  const sanitizeNumber = (value) => {
    if (!value) return '';
    return String(value).replace(/[^0-9]/g, '');
  };

  const splitNumber = (raw) => {
    const sanitized = sanitizeNumber(raw);
    if (!sanitized) return { prefix: COUNTRY_CODES[0] || '+598', number: '' };
    const withPlus = raw && raw.startsWith('+') ? `+${sanitized}` : sanitized;
    for (const prefix of COUNTRY_CODES) {
      const digits = prefix.replace('+', '');
      if (withPlus.startsWith(prefix) || withPlus.startsWith(digits)) {
        return {
          prefix,
          number: withPlus.startsWith(prefix) ? withPlus.slice(prefix.length) : withPlus.slice(digits.length),
        };
      }
    }
    return { prefix: COUNTRY_CODES[0] || '+598', number: sanitized };
  };

  const fillForm = () => {
    current = clone(window.ADMIN_INFO_BARBERIA || {});
    const whatsappConfig = (current.notificaciones && current.notificaciones.whatsapp) || {};
    const ownerEmail = (current.notificaciones && current.notificaciones.owner_email)
      || current.email
      || '';
    if (ownerEmailInput) ownerEmailInput.value = String(ownerEmail || '').trim();
    if (enabledCheckbox) enabledCheckbox.checked = !!whatsappConfig.enabled;
    const parts = splitNumber(whatsappConfig.number || '');
    if (countrySelect) countrySelect.value = COUNTRY_CODES.includes(parts.prefix) ? parts.prefix : (COUNTRY_CODES[0] || '+598');
    if (numberInput) numberInput.value = parts.number || '';
    if (errorEl) { errorEl.hidden = true; errorEl.textContent = ''; }
    if (submitBtn) submitBtn.disabled = false;
  };

  const close = () => {
    modal.classList.remove('is-visible');
    modal.hidden = true;
    if (modalLoading) modalLoading.hide(modal);
    if (submitBtn) submitBtn.disabled = false;
  };

  const open = () => {
    if (modalLoading) modalLoading.show(modal);
    fillForm();
    modal.hidden = false;
    requestAnimationFrame(() => {
      modal.classList.add('is-visible');
      if (modalLoading) modalLoading.hide(modal);
    });
  };

  const showError = (message) => {
    if (errorEl) {
      errorEl.hidden = false;
      errorEl.textContent = message;
    }
    notify(message, 'error');
  };

  closeEls.forEach((btn) => btn.addEventListener('click', close));

  form.addEventListener('submit', async (evt) => {
    evt.preventDefault();
    if (!form.reportValidity()) return;
    if (submitBtn) submitBtn.disabled = true;
    if (errorEl) { errorEl.hidden = true; errorEl.textContent = ''; }

    const prefix = countrySelect ? (countrySelect.value || '+598') : '+598';
    const number = sanitizeNumber(numberInput ? numberInput.value : '');
    if (!number) {
      showError('Ingresa un número de WhatsApp válido.');
      if (submitBtn) submitBtn.disabled = false;
      return;
    }
    const fullNumber = `${prefix}${number}`;
    const ownerEmail = ownerEmailInput ? ownerEmailInput.value.trim() : '';
    const payload = {
      email: ownerEmail,
      contacto: {
        email: ownerEmail,
        whatsapp: fullNumber.startsWith('+') ? fullNumber : `+${fullNumber}`,
      },
      redes: {
        whatsapp: `https://wa.me/${number}`,
      },
      notificaciones: {
        owner_email: ownerEmail,
        whatsapp: {
          enabled: enabledCheckbox ? !!enabledCheckbox.checked : true,
          number: fullNumber.startsWith('+') ? fullNumber : `+${fullNumber}`,
          provider: 'meta',
        },
      },
    };

    try {
      const res = await fetch((window.AdminApiBase || '../../../src/API/') + 'AdminConfig.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'config_update', key: 'info_barberia', data: payload }),
      });
      const json = await res.json().catch(() => null);
      if (!res.ok || !json || !json.ok) {
        throw new Error(json && json.error ? json.error : 'No se pudo guardar la configuración de notificaciones.');
      }
      window.ADMIN_INFO_BARBERIA = clone(json.data || {});
      notify('Notificaciones actualizadas.', 'success');
      close();
    } catch (error) {
      const message = error && error.message ? error.message : 'No se pudo guardar la configuración de notificaciones.';
      showError(message);
      if (submitBtn) submitBtn.disabled = false;
    }
  });

  window.AdminConfigNotificacionesModal = { open, close };
})();
