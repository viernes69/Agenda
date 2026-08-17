(function adminConfigPlatformPayments() {
  const modal = document.querySelector('[data-admin-modal="config-platform-payments"]');
  if (!modal) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', adminConfigPlatformPayments, { once: true });
    }
    return;
  }

  const form = modal.querySelector('[data-admin-config-platform-payments-form]');
  if (!form) return;

  const modalLoading = window.AdminModalLoading;
  const submitBtn = form.querySelector('[data-admin-config-pp-submit]');
  const errorEl = form.querySelector('[data-admin-config-pp-error]');
  const closeEls = modal.querySelectorAll('[data-admin-config-platform-payments-close]');
  const fieldNodes = Array.from(form.querySelectorAll('[data-admin-config-pp-field]'));

  const getCsrf = () => {
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') : '';
  };

  const getEndpoint = () => {
    const base = window.AdminApiBase || '../../../src/API/';
    return base.replace(/\/+$/, '') + '/AdminConfig.php';
  };

  const setByPath = (target, path, value) => {
    const parts = path.split('.');
    let current = target;
    parts.forEach((part, idx) => {
      if (idx === parts.length - 1) {
        current[part] = value;
      } else {
        if (!current[part] || typeof current[part] !== 'object') {
          current[part] = {};
        }
        current = current[part];
      }
    });
  };

  const getValue = (source, path) => {
    if (!source || typeof source !== 'object') return '';
    return path.split('.').reduce((acc, part) => {
      if (acc && Object.prototype.hasOwnProperty.call(acc, part)) {
        return acc[part];
      }
      return '';
    }, source);
  };

  const fillForm = (data) => {
    fieldNodes.forEach((field) => {
      const path = field.getAttribute('data-admin-config-pp-field');
      if (!path) return;
      const val = getValue(data, path);
      if (field.tagName === 'SELECT') {
        field.value = val === true || val === 1 || val === '1' ? '1' : (val === false || val === 0 || val === '0' ? '0' : String(val || ''));
      } else {
        field.value = val === null || val === undefined ? '' : String(val);
      }
    });
  };

  const collectData = () => {
    const payload = {};
    fieldNodes.forEach((field) => {
      const path = field.getAttribute('data-admin-config-pp-field');
      if (!path) return;
      let val = field.value.trim();
      if (field.tagName === 'SELECT' && (val === '1' || val === '0')) {
        val = val === '1';
      }
      setByPath(payload, path, val);
    });
    return payload;
  };

  const fetchConfig = async () => {
    if (modalLoading) modalLoading.show(modal);
    try {
      const res = await fetch(getEndpoint() + '?action=platform_payments_get', {
        headers: { 'X-CSRF-Token': getCsrf() }
      });
      const json = await res.json().catch(() => null);
      if (res.ok && json && json.ok && json.data) {
        fillForm(json.data);
      }
    } catch (_) {
      /* ignore */
    } finally {
      if (modalLoading) modalLoading.hide(modal);
    }
  };

  const open = () => {
    if (errorEl) { errorEl.hidden = true; errorEl.textContent = ''; }
    if (submitBtn) submitBtn.disabled = false;
    modal.hidden = false;
    requestAnimationFrame(() => modal.classList.add('is-visible'));
    fetchConfig();
  };

  const close = () => {
    modal.classList.remove('is-visible');
    modal.hidden = true;
  };

  closeEls.forEach((btn) => btn.addEventListener('click', close));
  document.addEventListener('keydown', (evt) => {
    if (evt.key === 'Escape' && !modal.hidden) close();
  });

  form.addEventListener('submit', async (evt) => {
    evt.preventDefault();
    if (errorEl) { errorEl.hidden = true; errorEl.textContent = ''; }
    if (submitBtn) submitBtn.disabled = true;

    const payload = collectData();
    try {
      const res = await fetch(getEndpoint(), {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': getCsrf()
        },
        body: JSON.stringify({
          action: 'platform_payments_update',
          data: payload
        })
      });
      const json = await res.json().catch(() => null);
      if (!res.ok || !json || !json.ok) {
        throw new Error(json && json.error ? json.error : 'No se pudo guardar la configuración.');
      }
      if (typeof adminNotify === 'function') {
        adminNotify('Pagos de suscripciones actualizados correctamente.', 'success');
      }
      close();
    } catch (err) {
      if (errorEl) {
        errorEl.textContent = err && err.message ? err.message : 'Error al guardar.';
        errorEl.hidden = false;
      }
      if (submitBtn) submitBtn.disabled = false;
    }
  });

  window.AdminConfigPlatformPaymentsModal = { open, close };
})();
