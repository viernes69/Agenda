(function adminBarberEditModal() {
  const modal = document.querySelector('[data-admin-modal="barber-edit"]');
  if (!modal && document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', adminBarberEditModal, { once: true });
    return;
  }
  if (!modal) return;
  const modalLoading = window.AdminModalLoading;
  const form = modal.querySelector('[data-admin-barber-edit-form]');
  const closeEls = modal.querySelectorAll('[data-admin-barber-edit-close]');
  const errorEl = modal.querySelector('[data-admin-barber-edit-error]');
  const submitBtn = modal.querySelector('[data-admin-barber-edit-submit]');
  const fileInput = modal.querySelector('input[name="Perfil"]');
  const idInput = modal.querySelector('[data-admin-barber-edit-id]');
  const perfilActualInput = modal.querySelector('[data-admin-barber-edit-perfil-actual]');
  const previewWrap = modal.querySelector('[data-admin-barber-edit-preview]');
  const previewImg = modal.querySelector('[data-admin-barber-edit-preview-img]');
  const skillCheckboxes = Array.from(modal.querySelectorAll('[data-admin-barber-edit-skill]'));
  const dayCheckboxes = Array.from(modal.querySelectorAll('[data-admin-barber-day]'));
  const endpoint = '../src/api/barberos.php';
  const apiBase = '../../../src/API/Autoload.php';
  let currentPhotoUrl = '';

  const clearError = () => { if (errorEl) { errorEl.textContent = ''; errorEl.hidden = true; } };
  const showError = (msg) => {
    if (!errorEl) return;
    errorEl.textContent = msg;
    errorEl.hidden = !msg;
  };
  const disableSubmit = () => { if (submitBtn) submitBtn.disabled = true; };
  const enableSubmit = () => { if (submitBtn) submitBtn.disabled = false; };

  const togglePreview = (url) => {
    currentPhotoUrl = url || '';
    if (!previewWrap || !previewImg) return;
    if (currentPhotoUrl) {
      previewImg.src = currentPhotoUrl;
      previewWrap.hidden = false;
    } else {
      previewWrap.hidden = true;
      previewImg.removeAttribute('src');
    }
  };

  const resolvePhotoUrl = (path) => {
    if (!path) return '';
    const trimmed = String(path).trim();
    if (!trimmed) return '';
    if (/^https?:\/\//i.test(trimmed)) return trimmed;
    const rel = trimmed.replace(/^\/+/, '');
    const appBase = (() => {
      const raw = String(window.__TENANT_CONFIG__?.basePath || window.location.origin || '').replace(/\/+$/, '');
      try {
        return new URL(raw || '/', window.location.origin).href.replace(/\/+$/, '');
      } catch (_) {
        return raw;
      }
    })();
    if (rel.startsWith('commerce-assets/')) {
      return `${appBase}/src/API/commerce_asset.php?p=${encodeURIComponent(rel)}`;
    }
    if (rel.startsWith('src/media/commerce/')) {
      return appBase ? `${appBase}/${rel}` : `/${rel}`;
    }
    return `../../../${rel}`;
  };

  const fetchJson = async (url) => {
    const res = await fetch(url);
    if (!res.ok) return null;
    try { return await res.json(); } catch (_) { return null; }
  };

  const open = (data) => {
    if (!form || !data) return;
    form.reset();
    skillCheckboxes.forEach((cb) => { cb.checked = false; });
    dayCheckboxes.forEach((cb) => { cb.checked = false; });

    const id = data.ID_Barber || data.id || '';
    if (idInput) idInput.value = id;
    const nombreInput = modal.querySelector('#admin-barber-edit-nombre');
    const apellidoInput = modal.querySelector('#admin-barber-edit-apellido');
    const cedulaInput = modal.querySelector('#admin-barber-edit-cedula');
    const pswInput = modal.querySelector('#admin-barber-edit-psw');
    const rolSelect = modal.querySelector('#admin-barber-edit-rol');
    const comisionInput = modal.querySelector('#admin-barber-edit-comision');
    if (nombreInput) nombreInput.value = data.Nombre || data.nombre || '';
    if (apellidoInput) apellidoInput.value = data.Apellido || data.apellido || '';
    if (cedulaInput) cedulaInput.value = data.Cedula || data.cedula || '';
    if (pswInput) pswInput.value = data.Psw || data.psw || '';
    if (rolSelect) rolSelect.value = (String(data.Rol || data.rol || '').toLowerCase() === 'admin') ? 'Admin' : 'Func';
    if (comisionInput) {
      const raw = data.Comision ?? data.comision ?? '';
      comisionInput.value = (raw === null || raw === '') ? '' : String(raw).replace(',', '.');
    }

    const habilidadesRaw = String(data.Habilidades || data.habilidades || '').replace(/;/g, ',');
    const skillsSet = new Set(habilidadesRaw.split(',').map((val) => val.trim()).filter(Boolean));
    skillCheckboxes.forEach((cb) => {
      const val = String(cb.value || '').trim();
      cb.checked = skillsSet.has(val);
    });
    const diasRaw = String(data.DiasTrabajo || data.dias_trabajo || data.dias || '').replace(/;/g, ',');
    const diasSet = new Set(diasRaw.split(',').map((val) => val.trim().toLowerCase()).filter(Boolean));
    dayCheckboxes.forEach((cb) => {
      const val = String(cb.value || cb.getAttribute('data-admin-barber-day') || '').trim().toLowerCase();
      cb.checked = diasSet.has(val);
    });

    const perfil = data.Perfil || data.perfil || '';
    if (perfilActualInput) perfilActualInput.value = perfil;
    togglePreview(resolvePhotoUrl(perfil));
    if (fileInput) {
      try { fileInput.value = ''; } catch (_) {}
    }

    clearError();
    enableSubmit();
    modal.hidden = false;
    requestAnimationFrame(() => {
      modal.classList.add('is-visible');
      if (modalLoading) modalLoading.hide(modal);
    });
    const focusTarget = nombreInput || modal.querySelector('input, select, textarea');
    if (focusTarget && typeof focusTarget.focus === 'function') {
      focusTarget.focus({ preventScroll: true });
    }
  };

  const close = () => {
    modal.classList.remove('is-visible');
    modal.hidden = true;
    if (modalLoading) modalLoading.hide(modal);
    clearError();
    enableSubmit();
    if (form) form.reset();
    skillCheckboxes.forEach((cb) => { cb.checked = false; });
    dayCheckboxes.forEach((cb) => { cb.checked = false; });
    togglePreview('');
    if (fileInput) {
      try { fileInput.value = ''; } catch (_) {}
    }
  };

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

  if (fileInput) {
    fileInput.addEventListener('change', () => {
      const file = fileInput.files && fileInput.files[0] ? fileInput.files[0] : null;
      if (!file) {
        togglePreview(resolvePhotoUrl(perfilActualInput ? perfilActualInput.value : ''));
        return;
      }
      const reader = new FileReader();
      reader.onload = (e) => {
        const result = e && e.target ? e.target.result : null;
        if (typeof result === 'string') {
          togglePreview(result);
        }
      };
      reader.readAsDataURL(file);
    });
  }

  document.addEventListener('click', async (evt) => {
    const btn = evt.target && evt.target.closest && evt.target.closest('[data-admin-barber-edit]');
    if (!btn) return;
    evt.preventDefault();
    const id = btn.getAttribute('data-admin-barber-edit');
    if (!id) return;
    if (modalLoading) modalLoading.show(modal);
    try {
      const payload = await fetchJson(`${apiBase}?action=get&table=barberos&id=${encodeURIComponent(id)}`);
      const data = payload && payload.data ? payload.data : null;
      if (!data) {
        adminNotify('No se pudo cargar el profesional.', 'error');
        return;
      }
      open(data);
    } catch (_) {
      adminNotify('No se pudo cargar el profesional.', 'error');
    } finally {
      if (modalLoading) modalLoading.hide(modal);
    }
  });

  if (form) {
    form.addEventListener('submit', async (evt) => {
      evt.preventDefault();
      clearError();
      disableSubmit();

      const fd = new FormData(form);
      const skills = [];
      skillCheckboxes.forEach((cb) => {
        if (cb.checked) {
          const val = String(cb.value || '').trim();
          if (val) skills.push(val);
        }
      });
      const workingDays = [];
      dayCheckboxes.forEach((cb) => {
        if (cb.checked) {
          const val = String(cb.value || '').trim().toLowerCase();
          if (val) workingDays.push(val);
        }
      });
      fd.delete('Habilidades[]');
      fd.delete('DiasTrabajo[]');
      fd.append('Habilidades', skills.join(', '));
      fd.append('DiasTrabajo', workingDays.join(','));
      fd.append('action', 'update');
      const comisionInput = modal.querySelector('#admin-barber-edit-comision');
      if (comisionInput) {
        const raw = comisionInput.value.trim().replace(',', '.');
        fd.set('Comision', raw);
      }

      const cedulaInput = modal.querySelector('#admin-barber-edit-cedula');
      const pswInput = modal.querySelector('#admin-barber-edit-psw');
      const cedulaVal = cedulaInput ? cedulaInput.value.trim() : '';
      const pswVal = pswInput ? pswInput.value : '';
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
          const msg = (payload && (payload.error || (Array.isArray(payload.errors) ? payload.errors.join(' ') : ''))) || 'No se pudo actualizar el profesional.';
          showError(msg);
          enableSubmit();
          return;
        }
        const data = payload.data || null;
        if (data) {
          window.__adminBarbers?.updateItem?.(data);
        }
        close();
        adminNotify('Profesional actualizado correctamente.', 'success');
      } catch (_) {
        showError('No se pudo actualizar el profesional.');
        enableSubmit();
      } finally {
        enableSubmit();
      }
    });
  }
})();

