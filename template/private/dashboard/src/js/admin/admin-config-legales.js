(function adminConfigLegalesModal() {
  const modal = document.querySelector('[data-admin-modal="config-legales"]');
  if (!modal) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', adminConfigLegalesModal, { once: true });
    }
    return;
  }

  const modalLoading = window.AdminModalLoading;

  const form = modal.querySelector('[data-admin-config-legales-form]');
  if (!form) return;

  const fieldNodes = Array.from(form.querySelectorAll('[data-admin-config-legales-field]'));
  const errorEl = form.querySelector('[data-admin-config-legales-error]');
  const submitBtn = form.querySelector('[data-admin-config-legales-submit]');
  const closeEls = modal.querySelectorAll('[data-admin-config-legales-close]');

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

  const fillForm = () => {
    current = clone(window.ADMIN_INFO_BARBERIA || {});
    const legales = current.legales || {};
    fieldNodes.forEach((field) => {
      const path = field.getAttribute('data-admin-config-legales-field');
      if (!path) return;
      const key = path.split('.').pop();
      field.value = legales[key] || '';
    });
    if (errorEl) {
      errorEl.hidden = true;
      errorEl.textContent = '';
    }
    if (submitBtn) submitBtn.disabled = false;
  };

  const collectPayload = () => {
    const payload = { legales: {} };
    fieldNodes.forEach((field) => {
      const path = field.getAttribute('data-admin-config-legales-field');
      if (!path) return;
      const key = path.split('.').pop();
      payload.legales[key] = field.value.trim();
    });
    return payload;
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
    if (errorEl) {
      errorEl.hidden = true;
      errorEl.textContent = '';
    }

    const payload = collectPayload();
    try {
      const res = await fetch((window.AdminApiBase || '../../../src/API/') + 'AdminConfig.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'config_update', key: 'info_barberia', data: payload }),
      });
      const json = await res.json().catch(() => null);
      if (!res.ok || !json || !json.ok) {
        throw new Error(json && json.error ? json.error : 'No se pudo guardar las políticas legales.');
      }
      window.ADMIN_INFO_BARBERIA = clone(json.data || {});
      notify('Políticas legales actualizadas.', 'success');
      close();
    } catch (error) {
      const message = error && error.message ? error.message : 'No se pudo guardar las políticas legales.';
      showError(message);
      if (submitBtn) submitBtn.disabled = false;
    }
  });

  window.AdminConfigLegalesModal = { open, close };
})();
