(function adminConfigFeaturesModal() {
  const modal = document.querySelector('[data-admin-modal="config-features"]');
  if (!modal) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', adminConfigFeaturesModal, { once: true });
    }
    return;
  }

  const modalLoading = window.AdminModalLoading;

  const form = modal.querySelector('[data-admin-config-features-form]');
  if (!form) return;

  const toggleInputs = Array.from(form.querySelectorAll('[data-admin-config-features-toggle]'));
  const labelInput = form.querySelector('[data-admin-config-features-label]');
  const errorEl = form.querySelector('[data-admin-config-features-error]');
  const submitBtn = form.querySelector('[data-admin-config-features-submit]');
  const closeEls = modal.querySelectorAll('[data-admin-config-features-close]');

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

  const fillForm = () => {
    current = clone(window.ADMIN_INFO_BARBERIA || {});
    const features = current.features || {};
    toggleInputs.forEach((input) => {
      const key = input.getAttribute('data-admin-config-features-toggle');
      input.checked = !!features[key];
    });
    if (labelInput) {
      labelInput.value = features['tipo_comercio_label'] || '';
    }
    if (errorEl) {
      errorEl.hidden = true;
      errorEl.textContent = '';
    }
    if (submitBtn) submitBtn.disabled = false;
  };

  const collectPayload = () => {
    const payload = { features: {} };
    toggleInputs.forEach((input) => {
      const key = input.getAttribute('data-admin-config-features-toggle');
      payload.features[key] = !!input.checked;
    });
    if (labelInput) {
      const val = labelInput.value.trim();
      if (val) {
        payload.features['tipo_comercio_label'] = val;
      } else {
        payload.features['tipo_comercio_label'] = '';
      }
    }
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
        throw new Error(json && json.error ? json.error : 'No se pudo guardar las funciones.');
      }
      window.ADMIN_INFO_BARBERIA = clone(json.data || {});
      notify('Funciones actualizadas. Recargando panel...', 'success');
      window.setTimeout(function() { window.location.reload(); }, 800);
    } catch (error) {
      const message = error && error.message ? error.message : 'No se pudo guardar las funciones.';
      showError(message);
      if (submitBtn) submitBtn.disabled = false;
    }
  });

  window.AdminConfigFeaturesModal = { open, close };
})();
