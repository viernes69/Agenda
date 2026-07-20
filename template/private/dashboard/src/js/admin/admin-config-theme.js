(function adminConfigThemeModal() {
  const modal = document.querySelector('[data-admin-modal="config-theme"]');
  if (!modal) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', adminConfigThemeModal, { once: true });
    }
    return;
  }

  const form = modal.querySelector('[data-admin-config-theme-form]');
  if (!form) return;

  const selectPublic = form.querySelector('[data-admin-config-theme-public]');
  const selectPrivate = form.querySelector('[data-admin-config-theme-private]');
  const submitBtn = form.querySelector('[data-admin-config-theme-submit]');
  const errorEl = form.querySelector('[data-admin-config-theme-error]');
  const closeBtns = modal.querySelectorAll('[data-admin-config-theme-close]');

  const notify = (message, type = 'success') => {
    if (typeof window.adminNotify === 'function') {
      window.adminNotify(message, type);
    } else if (typeof window.AdminNotify === 'function') {
      window.AdminNotify(message, type);
    } else {
      console.log('[THEME]', type.toUpperCase(), message);
    }
  };

  const cloneInfo = () => JSON.parse(JSON.stringify(window.ADMIN_INFO_BARBERIA || {}));
  const getThemeConfig = () => {
    const info = cloneInfo();
    const temas = info.temas && typeof info.temas === 'object' ? info.temas : {};
    return {
      publico: temas.publico === 'claro' ? 'claro' : 'oscuro',
      privado: temas.privado === 'claro' ? 'claro' : 'oscuro',
    };
  };

  const setProgressMessage = (message) => {
    const overlay = modal.querySelector('[data-admin-modal-loading]');
    if (!overlay) return;
    const label = overlay.querySelector('.admin-modal-loading__label');
    if (label) label.textContent = message;
  };

  const showProgress = (message) => {
    if (window.AdminModalLoading && typeof window.AdminModalLoading.show === 'function') {
      window.AdminModalLoading.show(modal, { delay: 0 });
      setProgressMessage(message);
    }
  };

  const hideProgress = () => {
    if (window.AdminModalLoading && typeof window.AdminModalLoading.hide === 'function') {
      window.AdminModalLoading.hide(modal);
      setProgressMessage('Cargando...');
    }
  };

  const fillForm = () => {
    const themes = getThemeConfig();
    if (selectPublic) selectPublic.value = themes.publico;
    if (selectPrivate) selectPrivate.value = themes.privado;
    if (errorEl) {
      errorEl.hidden = true;
      errorEl.textContent = '';
    }
    if (submitBtn) submitBtn.disabled = false;
  };

  const close = () => {
    modal.classList.remove('is-visible');
    modal.hidden = true;
    hideProgress();
    if (submitBtn) submitBtn.disabled = false;
  };

  const open = () => {
    fillForm();
    modal.hidden = false;
    requestAnimationFrame(() => {
      modal.classList.add('is-visible');
    });
  };

  closeBtns.forEach((btn) => btn.addEventListener('click', close));

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (!form.reportValidity()) return;

    if (submitBtn) submitBtn.disabled = true;
    if (errorEl) { errorEl.hidden = true; errorEl.textContent = ''; }

    const selectedPublic = selectPublic ? (selectPublic.value || 'oscuro') : 'oscuro';
    const selectedPrivate = selectPrivate ? (selectPrivate.value || 'oscuro') : 'oscuro';
    const previous = getThemeConfig();

    if (selectedPublic === previous.publico && selectedPrivate === previous.privado) {
      notify('No hay cambios pendientes.', 'info');
      if (submitBtn) submitBtn.disabled = false;
      return;
    }

    const hasLight = selectedPublic === 'claro' || selectedPrivate === 'claro';
    const hasDark = selectedPublic === 'oscuro' || selectedPrivate === 'oscuro';
    let progressMessage = 'Estamos aplicando la configuración de temas para tu negocio...';
    if (hasLight && !hasDark) {
      progressMessage = 'Estamos aplicando el modo claro para tu negocio...';
    } else if (!hasLight && hasDark) {
      progressMessage = 'Estamos aplicando el modo oscuro para tu negocio...';
    }

    showProgress(progressMessage);

    try {
      const response = await fetch('../../../src/API/AdminConfig.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          action: 'apply_theme',
          mode_public: selectedPublic,
          mode_private: selectedPrivate,
        }),
      });
      const payload = await response.json().catch(() => null);
      if (!response.ok || !payload || payload.ok !== true) {
        throw new Error(payload && payload.error ? payload.error : 'No se pudo aplicar el tema seleccionado.');
      }

      if (payload.data && typeof payload.data === 'object') {
        window.ADMIN_INFO_BARBERIA = payload.data;
      }

      notify('Temas actualizados.', 'success');
      close();
      setTimeout(() => window.location.reload(), 400);
    } catch (error) {
      const message = error && error.message ? error.message : 'No se pudo aplicar el tema seleccionado.';
      if (errorEl) {
        errorEl.hidden = false;
        errorEl.textContent = message;
      }
      notify(message, 'error');
      if (submitBtn) submitBtn.disabled = false;
    } finally {
      hideProgress();
    }
  });

  window.AdminConfigThemeModal = { open, close };
})();
