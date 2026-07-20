(function adminBarberCreateModal() {
  const modal = document.querySelector('[data-admin-modal="barber-create"]');
  const openBtns = document.querySelectorAll('[data-admin-barber-create]');
  if ((!modal || !openBtns.length) && document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', adminBarberCreateModal, { once: true });
    return;
  }
  if (!modal || !openBtns.length) return;
  const modalLoading = window.AdminModalLoading;
  const form = modal.querySelector('[data-admin-barber-form]');
  const closeEls = modal.querySelectorAll('[data-admin-barber-close]');
  const errorEl = modal.querySelector('[data-admin-barber-error]');
  const submitBtn = modal.querySelector('[data-admin-barber-submit]');
  const fileInput = modal.querySelector('input[name="Perfil"]');
  const endpoint = '../src/api/barberos.php';

  const clearError = () => { if (errorEl) { errorEl.textContent = ''; errorEl.hidden = true; } };
  const showError = (msg) => {
    if (!errorEl) return;
    errorEl.textContent = msg;
    errorEl.hidden = !msg;
  };
  const disableSubmit = () => { if (submitBtn) submitBtn.disabled = true; };
  const enableSubmit = () => { if (submitBtn) submitBtn.disabled = false; };

  const open = () => {
    if (modalLoading) modalLoading.show(modal);
    if (form) form.reset();
    if (fileInput) { try { fileInput.value = ''; } catch (_) {} }
    clearError();
    modal.hidden = false;
    requestAnimationFrame(() => {
      modal.classList.add('is-visible');
      if (modalLoading) modalLoading.hide(modal);
    });
    const firstInput = modal.querySelector('input[name="Nombre"]');
    if (firstInput) firstInput.focus({ preventScroll: true });
  };
  const close = () => {
    modal.classList.remove('is-visible');
    modal.hidden = true;
    if (modalLoading) modalLoading.hide(modal);
    enableSubmit();
    clearError();
  };

  openBtns.forEach((btn) => btn.addEventListener('click', (evt) => {
    evt.preventDefault();
    open();
  }));

  closeEls.forEach((btn) => btn.addEventListener('click', (evt) => {
    evt.preventDefault();
    close();
  }));

  modal.addEventListener('keydown', (evt) => {
    if (evt.key === 'Escape') {
      evt.preventDefault();
      close();
    }
  });

  if (form) {
    form.addEventListener('submit', async (evt) => {
      evt.preventDefault();
      clearError();
      disableSubmit();

      const fd = new FormData(form);
      const skills = [];
      form.querySelectorAll('input[name="Habilidades[]"]:checked').forEach((input) => {
        const val = String(input.value || '').trim();
        if (val) skills.push(val);
      });
      const workingDays = [];
      form.querySelectorAll('input[name="DiasTrabajo[]"]:checked').forEach((input) => {
        const val = String(input.value || '').trim().toLowerCase();
        if (val) workingDays.push(val);
      });
      fd.delete('Habilidades[]');
      fd.delete('DiasTrabajo[]');
      fd.append('Habilidades', skills.join(', '));
      fd.append('DiasTrabajo', workingDays.join(','));
      fd.append('Disponibilidad', 'Disponible');
      fd.append('Status', 'Offline');
      fd.append('action', 'create');
      const comisionInput = form.querySelector('#admin-barber-comision');
      if (comisionInput) {
        const raw = comisionInput.value.trim().replace(',', '.');
        fd.set('Comision', raw);
      }

      const cedula = form.querySelector('#admin-barber-cedula');
      const psw = form.querySelector('#admin-barber-psw');
      const cedulaVal = cedula ? cedula.value.trim() : '';
      const pswVal = psw ? psw.value : '';
      const cedulaRegex = /^[0-9]{7,}$/;
      const pswRegex = /^(?=.*[A-Z])(?=.*[0-9]).{8,}$/;
      if (!cedulaRegex.test(cedulaVal)) {
        showError('La cedula debe contener solo numeros y tener al menos 7 digitos.');
        enableSubmit();
        return;
      }
      if (!pswRegex.test(pswVal)) {
        showError('La contrasena debe tener minimo 8 caracteres, 1 mayuscula y 1 numero.');
        enableSubmit();
        return;
      }

      const file = fileInput && fileInput.files ? fileInput.files[0] : null;
      if (file && file.size > 5 * 1024 * 1024) {
        showError('La imagen no puede superar los 5 MB.');
        enableSubmit();
        return;
      }

      try {
        const res = await fetch(endpoint, { method: 'POST', body: fd });
        const payload = await res.json().catch(() => null);
        if (!res.ok || !payload || !payload.ok) {
          const msg = (payload && (payload.error || (Array.isArray(payload.errors) ? payload.errors.join(' ') : ''))) || 'No se pudo registrar el profesional.';
          showError(msg);
          enableSubmit();
          return;
        }
        const data = payload.data || null;
        window.__adminBarbers?.addItem?.(data);
        close();
        if (typeof window.adminNotify === 'function') {
          window.adminNotify('Profesional registrado correctamente.', 'success');
        } else if (typeof window.AdminNotify === 'function') {
          window.AdminNotify('Profesional registrado correctamente.', 'success');
        } else {
          console.log('[NOTIFY] Profesional registrado correctamente.');
        }
      } catch (_) {
        showError('No se pudo registrar el profesional.');
        enableSubmit();
      } finally {
        enableSubmit();
      }
    });
  }
})();
