(function adminConfigRedesModal() {
  const modal = document.querySelector('[data-admin-modal="config-redes"]');
  if (!modal) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', adminConfigRedesModal, { once: true });
    }
    return;
  }

  const modalLoading = window.AdminModalLoading;

  const form = modal.querySelector('[data-admin-config-redes-form]');
  if (!form) return;

  const visibleCheckbox = form.querySelector('[data-admin-config-redes-visible]');
  const emailInput = form.querySelector('[data-admin-config-redes-email]');
  const submitBtn = form.querySelector('[data-admin-config-redes-submit]');
  const errorEl = form.querySelector('[data-admin-config-redes-error]');
  const closeEls = modal.querySelectorAll('[data-admin-config-redes-close]');
  const usernameInputs = Array.from(form.querySelectorAll('[data-admin-config-redes-username]'));

  const NETWORKS = [
    { key: 'instagram', base: 'https://www.instagram.com/', label: 'Instagram' },
    { key: 'facebook', base: 'https://www.facebook.com/', label: 'Facebook' },
    { key: 'tiktok', base: 'https://www.tiktok.com/@', label: 'TikTok' },
    { key: 'twitter', base: 'https://twitter.com/', label: 'Twitter / X' },
    { key: 'youtube', base: 'https://www.youtube.com/', label: 'YouTube' },
    { key: 'whatsapp', base: 'https://wa.me/', label: 'WhatsApp' },
  ];

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

  const getNetworkConfig = (key) => NETWORKS.find((network) => network.key === key);

  const sanitizeUsername = (raw, base) => {
    if (!raw) return '';
    let value = String(raw).trim();
    if (!value) return '';
    if (value.startsWith(base)) {
      value = value.slice(base.length);
    }
    value = value.replace(/^https?:\/\//i, '');
    if (value.startsWith('www.')) {
      value = value.slice(value.indexOf('/') + 1);
    }
    value = value.replace(/^@/, '');
    value = value.replace(/^\//, '');
    return value;
  };

  const sanitizeWhatsAppDigits = (raw) => String(raw || '').replace(/\D/g, '');

  const resolveCompanyEmail = (data) => {
    const notif = (data && data.notificaciones) || {};
    return String(
      (data && data.email) ||
      (data.contacto && data.contacto.email) ||
      notif.owner_email ||
      ''
    ).trim();
  };

  const resolveWhatsAppDigits = (data) => {
    const redes = (data && data.redes) || {};
    const contacto = (data && data.contacto) || {};
    const notifWa = ((data && data.notificaciones) || {}).whatsapp || {};
    const fromRedes = sanitizeWhatsAppDigits(sanitizeUsername(redes.whatsapp || '', 'https://wa.me/'));
    if (fromRedes) return fromRedes;
    const fromContact = sanitizeWhatsAppDigits(contacto.whatsapp || '');
    if (fromContact) return fromContact;
    return sanitizeWhatsAppDigits(notifWa.number || '');
  };

  const fillForm = (data) => {
    const redes = (data && data.redes) || {};
    const globalVisible = typeof redes.visible === 'boolean' ? redes.visible : true;
    if (visibleCheckbox) {
      visibleCheckbox.checked = globalVisible;
    }
    if (emailInput) {
      emailInput.value = resolveCompanyEmail(data);
    }
    usernameInputs.forEach((input) => {
      const key = input.getAttribute('data-admin-config-redes-username');
      const config = getNetworkConfig(key);
      if (!config) return;
      if (key === 'whatsapp') {
        input.value = resolveWhatsAppDigits(data);
        return;
      }
      const valueRaw = redes && redes[key] ? redes[key] : '';
      let username = '';
      if (typeof valueRaw === 'string') {
        username = sanitizeUsername(valueRaw, config.base);
      } else if (valueRaw && typeof valueRaw.url === 'string') {
        username = sanitizeUsername(valueRaw.url, config.base);
      }
      input.value = username;
    });
  };

  const collect = () => {
    const payload = { redes: {}, contacto: {} };
    if (visibleCheckbox) {
      payload.redes.visible = !!visibleCheckbox.checked;
    }
    const companyEmail = emailInput ? emailInput.value.trim() : '';
    if (companyEmail !== '') {
      payload.email = companyEmail;
      payload.contacto.email = companyEmail;
    }
    usernameInputs.forEach((input) => {
      const key = input.getAttribute('data-admin-config-redes-username');
      const config = getNetworkConfig(key);
      if (!config) return;
      if (key === 'whatsapp') {
        const digits = sanitizeWhatsAppDigits(input.value);
        payload.redes.whatsapp = digits ? config.base + digits : '';
        payload.contacto.whatsapp = digits ? '+' + digits.replace(/^\+/, '') : '';
        return;
      }
      const username = sanitizeUsername(input.value, config.base);
      payload.redes[key] = username ? config.base + username : '';
    });
    return payload;
  };

  const open = () => {
    if (modalLoading) modalLoading.show(modal);
    current = clone(window.ADMIN_INFO_BARBERIA || {});
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

  form.addEventListener('submit', async (evt) => {
    evt.preventDefault();
    if (!form.reportValidity()) return;
    const waInput = form.querySelector('[data-admin-config-redes-username="whatsapp"]');
    if (waInput && sanitizeWhatsAppDigits(waInput.value).length < 8) {
      showError('Ingresá un WhatsApp válido (mínimo 8 dígitos).');
      return;
    }
    if (submitBtn) submitBtn.disabled = true;
    if (errorEl) {
      errorEl.hidden = true;
      errorEl.textContent = '';
    }

    const payload = collect();
    try {
      const res = await fetch((window.AdminApiBase || '../../../src/API/') + 'AdminConfig.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'config_update', key: 'info_barberia', data: payload }),
      });
      const json = await res.json().catch(() => null);
      if (!res.ok || !json || !json.ok) {
        throw new Error(json && json.error ? json.error : 'No se pudo guardar el contacto.');
      }
      window.ADMIN_INFO_BARBERIA = clone(json.data || {});
      notify('Contacto y redes actualizados.', 'success');
      close();
    } catch (error) {
      const message = error && error.message ? error.message : 'No se pudo guardar el contacto y las redes.';
      showError(message);
      if (submitBtn) submitBtn.disabled = false;
    }
  });

  window.AdminConfigRedesModal = { open, close };
})();
