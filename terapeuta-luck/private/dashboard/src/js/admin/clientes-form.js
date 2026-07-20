(function adminClientForm() {
  const modal = document.querySelector('[data-admin-modal="cliente-form"]');
  if (!modal) return;

  const form = modal.querySelector('[data-admin-client-form]');
  const titleEl = modal.querySelector('[data-admin-client-form-title]');
  const errorEl = modal.querySelector('[data-admin-client-form-error]');
  const submitBtn = modal.querySelector('[data-admin-client-submit]');
  const closeButtons = Array.from(modal.querySelectorAll('[data-admin-client-form-close]'));
  const modalLoading = window.AdminModalLoading;

  const fields = {
    id: form.querySelector('input[name="ID_Cliente"]'),
    nombre: form.querySelector('input[name="Nombre"]'),
    email: form.querySelector('input[name="Email"]'),
    telefono: form.querySelector('input[name="Telefono"]'),
    cedula: form.querySelector('input[name="Cedula"]'),
  };

  const apiBase = '../../../src/API/Autoload.php';
  let mode = 'create';
  let currentId = null;

  const escapeHtml = (value) => {
    return String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  };

  const showModal = () => {
    modal.hidden = false;
    requestAnimationFrame(() => modal.classList.add('is-visible'));
  };

  const hideModal = () => {
    modal.classList.remove('is-visible');
    setTimeout(() => {
      modal.hidden = true;
      resetForm();
      mode = 'create';
      currentId = null;
      if (titleEl) titleEl.textContent = 'Registrar cliente';
      submitBtn.textContent = 'Registrar';
    }, 180);
  };

  const resetForm = () => {
    form.reset();
    if (fields.id) fields.id.value = '';
    errorEl.textContent = '';
    errorEl.hidden = true;
    submitBtn.disabled = false;
    submitBtn.removeAttribute('data-loading');
  };

  const setError = (message) => {
    if (!errorEl) return;
    if (message) {
      errorEl.textContent = message;
      errorEl.hidden = false;
    } else {
      errorEl.textContent = '';
      errorEl.hidden = true;
    }
  };

  const setSubmitting = (isSubmitting) => {
    submitBtn.disabled = isSubmitting;
    if (isSubmitting) {
      submitBtn.dataset.loading = '1';
    } else {
      submitBtn.removeAttribute('data-loading');
    }
  };

  const notify = (message, icon) => {
    if (typeof window.AdminNotify === 'function') {
      window.AdminNotify(message, icon);
    } else {
      console.log('[Clientes]', message);
    }
  };

  const openCreate = () => {
    mode = 'create';
    currentId = null;
    resetForm();
    if (titleEl) titleEl.textContent = 'Registrar cliente';
    submitBtn.textContent = 'Registrar';
    showModal();
    setTimeout(() => {
      if (fields.nombre) fields.nombre.focus();
    }, 220);
  };

  const fillForm = (data) => {
    if (!data) return;
    if (fields.id) fields.id.value = data.ID_Cliente ?? '';
    if (fields.nombre) fields.nombre.value = (data.Nombre || '').toString().trim();
    if (fields.email) fields.email.value = (data.Email || '').toString().trim();
    if (fields.telefono) fields.telefono.value = (data.Telefono || '').toString().trim();
    if (fields.cedula) fields.cedula.value = (data.Cedula || '').toString().trim();
  };

  const openEdit = async (clientId) => {
    mode = 'edit';
    currentId = clientId;
    resetForm();
    if (titleEl) titleEl.textContent = 'Editar cliente';
    submitBtn.textContent = 'Guardar cambios';
    showModal();
    if (modalLoading) modalLoading.show(modal);
    setSubmitting(true);
    try {
      const response = await fetch(`${apiBase}?action=get&table=clientes&id=${encodeURIComponent(clientId)}`, {
        credentials: 'same-origin',
      });
      const payload = await response.json().catch(() => null);
      if (!response.ok || !payload || payload.ok !== true) {
        throw new Error('No se pudo cargar los datos del cliente.');
      }
      const data = payload.data || null;
      if (!data || !data.ID_Cliente) {
        throw new Error('Cliente no encontrado.');
      }
      fillForm(data);
      setTimeout(() => {
        if (fields.nombre) fields.nombre.focus();
      }, 60);
    } catch (error) {
      const message = error instanceof Error ? error.message : 'No se pudo cargar los datos del cliente.';
      setError(message);
      notify(message, 'error');
    } finally {
      setSubmitting(false);
      if (modalLoading) modalLoading.hide(modal);
    }
  };

  closeButtons.forEach((btn) => {
    btn.addEventListener('click', () => {
      hideModal();
    });
  });

  modal.addEventListener('click', (event) => {
    if (event.target && event.target.matches('[data-admin-client-form-close], .modal__backdrop')) {
      hideModal();
    }
  });

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    setError('');
    setSubmitting(true);

    const nombre = fields.nombre ? fields.nombre.value.trim() : '';
    const email = fields.email ? fields.email.value.trim() : '';
    const telefono = fields.telefono ? fields.telefono.value.trim() : '';
    const cedula = fields.cedula ? fields.cedula.value.trim() : '';

    if (!nombre) {
      setError('El nombre es obligatorio.');
      setSubmitting(false);
      return;
    }
    if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
      setError('El email ingresado no es válido.');
      setSubmitting(false);
      return;
    }

    const payload = {
      Nombre: nombre,
      Email: email,
      Telefono: telefono,
      Cedula: cedula,
    };

    const body = new URLSearchParams();
    body.append('table', 'clientes');
    if (mode === 'edit' && currentId !== null) {
      body.append('action', 'update');
      body.append('id', String(currentId));
    } else {
      body.append('action', 'insert');
    }
    body.append('data', JSON.stringify(payload));

    try {
      const response = await fetch(apiBase, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
        credentials: 'same-origin',
        body: body.toString(),
      });
      const result = await response.json().catch(() => null);
      if (!response.ok || !result || result.ok !== true) {
        throw new Error(result && result.error ? result.error : 'No se pudo guardar el cliente.');
      }
      const clientData = result.data || null;
      if (!clientData || !clientData.ID_Cliente) {
        throw new Error('Respuesta inválida del servidor.');
      }
      if (window.AdminClientsList && typeof window.AdminClientsList.upsert === 'function') {
        window.AdminClientsList.upsert(clientData);
      }
      const successMessage = mode === 'edit' ? 'Cliente actualizado correctamente.' : 'Cliente registrado correctamente.';
      notify(successMessage, 'success');
      hideModal();
    } catch (error) {
      const message = error instanceof Error ? error.message : 'No se pudo guardar el cliente.';
      setError(message);
      notify(message, 'error');
    } finally {
      setSubmitting(false);
    }
  });

  document.addEventListener('click', (event) => {
    const triggerCreate = event.target && event.target.closest && event.target.closest('[data-admin-client-create]');
    if (triggerCreate) {
      event.preventDefault();
      openCreate();
      return;
    }
    const triggerEdit = event.target && event.target.closest && event.target.closest('[data-admin-client-edit]');
    if (triggerEdit) {
      event.preventDefault();
      const clientId = triggerEdit.getAttribute('data-admin-client-edit');
      if (!clientId) return;
      openEdit(clientId);
    }
  });
})();
