(function adminServicesCrud() {
  const list = document.querySelector('[data-admin-service-list]');
  const modal = document.querySelector('[data-admin-modal="service-form"]');
  if ((!list || !modal) && document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', adminServicesCrud, { once: true });
    return;
  }
  if (!list || !modal) return;
  const modalLoading = window.AdminModalLoading;

  const addBtn = document.querySelector('[data-admin-service-create]');
  const emptyMsg = document.querySelector('.admin-services-empty');
  const countEl = document.querySelector('.admin-services-count');
  const form = modal.querySelector('[data-admin-service-form]');
  const closeEls = modal.querySelectorAll('[data-admin-service-close]');
  const errorEl = modal.querySelector('[data-admin-service-error]');
  const submitBtn = modal.querySelector('[data-admin-service-submit]');
  const titleEl = modal.querySelector('[data-admin-service-form-title]');
  const idField = form?.querySelector('[data-admin-service-field="id"]');
  const imgField = form?.querySelector('[data-admin-service-field="img-current"]');
  const imageInput = form?.querySelector('[data-admin-service-field="image-input"]');
  const previewWrapper = modal.querySelector('[data-admin-service-current]');
  const previewImg = modal.querySelector('[data-admin-service-preview]');
  const durationSelect = form?.querySelector('[data-admin-service-field="duration"]');
  const estadoSelect = form?.querySelector('[data-admin-service-field="estado"]');
  const endpoint = '../src/api/servicios.php';
  let mode = 'create';

  const escapeAttr = (str) => String(str ?? '').replace(/[&<>"']/g, (m) => (
    m === '&' ? '&amp;' :
    m === '<' ? '&lt;' :
    m === '>' ? '&gt;' :
    m === '"' ? '&quot;' : '&#39;'
  ));
  const getCards = () => Array.from(list.querySelectorAll('[data-admin-service-item]'));
  const updateCount = () => {
    if (countEl) countEl.textContent = 'Total: ' + getCards().length;
  };
  const updateEmptyState = () => {
    if (!emptyMsg) return;
    emptyMsg.hidden = getCards().length > 0;
  };
  const clearError = () => { if (errorEl) { errorEl.textContent = ''; errorEl.hidden = true; } };
  const showError = (msg) => {
    if (!errorEl) return;
    errorEl.textContent = msg;
    errorEl.hidden = !msg;
  };
  const disableSubmit = () => { if (submitBtn) submitBtn.disabled = true; };
  const enableSubmit = () => { if (submitBtn) submitBtn.disabled = false; };
  let previewObjectUrl = '';
  const revokePreviewObjectUrl = () => {
    if (previewObjectUrl) {
      try { URL.revokeObjectURL(previewObjectUrl); } catch (_) {}
      previewObjectUrl = '';
    }
  };
  const tenantPublicBase = () => {
    const parts = String(window.location.pathname || '').split('/').filter(Boolean);
    const idx = parts.indexOf('private');
    if (idx <= 0) return '';
    return '/' + parts.slice(0, idx).join('/');
  };
  const appPublicBase = () => {
    const meta = document.querySelector('meta[name="url-base"]');
    const slug = String(document.querySelector('meta[name="tenant-slug"]')?.content || '').trim().replace(/^\/+|\/+$/g, '');
    const raw = String(meta?.content || '').trim();
    if (!raw) return '';
    try {
      let path = new URL(raw, window.location.origin).pathname.replace(/\/+$/, '');
      if (slug && path.endsWith(`/${slug}`)) {
        path = path.slice(0, -(slug.length + 1));
      }
      return path;
    } catch (_) {
      let path = raw.replace(/\/+$/, '');
      if (slug && path.endsWith(`/${slug}`)) {
        path = path.slice(0, -(slug.length + 1));
      }
      return path;
    }
  };
  const resolveImageUrl = (value) => {
    const ref = String(value || '').trim();
    if (!ref) return '';
    if (/^(https?:|blob:|data:)/i.test(ref)) return ref;
    if (ref.startsWith('/')) return ref;
    const rel = ref.replace(/^\/+/, '');
    if (rel.startsWith('commerce-assets/')) {
      const appBase = appPublicBase();
      const prefix = appBase || '';
      return `${prefix}/src/API/commerce_asset.php?p=${encodeURIComponent(rel)}`;
    }
    if (rel.startsWith('src/media/commerce/')) {
      const appBase = appPublicBase();
      return appBase ? `${appBase}/${rel}` : `/${rel}`;
    }
    const base = tenantPublicBase();
    return base ? `${base}/${rel}` : `../../../${rel}`;
  };
  const showPreview = (url, alt) => {
    if (!previewWrapper || !previewImg) return;
    const src = String(url || '').trim();
    previewWrapper.hidden = !src;
    previewImg.src = src;
    previewImg.alt = alt || 'Vista previa';
  };
  const formatPrice = (val) => {
    const num = Number(val);
    if (!Number.isFinite(num)) return '0';
    const hasDecimals = Math.abs(num % 1) > 0;
    return new Intl.NumberFormat('es-UY', {
      minimumFractionDigits: hasDecimals ? 2 : 0,
      maximumFractionDigits: hasDecimals ? 2 : 0,
    }).format(num);
  };
  const formatPoints = (val) => {
    if (val === null || val === undefined || val === '') return 'No asignados';
    const num = Number(val);
    if (!Number.isFinite(num)) return 'No asignados';
    return new Intl.NumberFormat('es-UY', { maximumFractionDigits: 0 }).format(num);
  };
  const formatDuration = (val) => {
    const num = Number(val);
    if (!Number.isFinite(num)) return 'Sin duracion';
    return `${num} min`;
  };
  const applyDataset = (el, data) => {
    el.dataset.adminServiceId = String(data.ID_Servicio ?? data.id ?? '');
    el.dataset.adminServiceName = String(data.Nombre ?? '');
    el.dataset.adminServiceDuration = String(data.Duracion ?? '');
    el.dataset.adminServiceStatus = String(data.Estado ?? 'Activo');
    el.dataset.adminServicePrice = String(data.Precio ?? '');
    el.dataset.adminServicePoints = data.Puntos === null || data.Puntos === undefined ? '' : String(data.Puntos);
    el.dataset.adminServiceImage = String(data.Img_Link ?? data.img_link ?? data.img ?? '');
  };
  const updateStatusPill = (card, estado) => {
    const pill = card.querySelector('.admin-service-status');
    if (!pill) return;
    const st = String(estado || 'Activo');
    pill.textContent = st;
    pill.classList.remove('admin-service-status--active', 'admin-service-status--inactive');
    pill.classList.add(`admin-service-status--${st.toLowerCase() === 'activo' ? 'active' : 'inactive'}`);
  };
  const updateImage = (card, imgRel, name) => {
    const thumb = card.querySelector('.admin-service-thumb');
    if (!thumb) return;
    thumb.innerHTML = '';
    const url = resolveImageUrl(imgRel);
    if (url) {
      const img = document.createElement('img');
      img.src = url;
      img.alt = String(name || '');
      img.loading = 'lazy';
      thumb.appendChild(img);
    } else {
      const span = document.createElement('span');
      span.className = 'admin-service-thumb-placeholder';
      span.innerHTML = '<i class="bx bx-image-alt"></i>';
      thumb.appendChild(span);
    }
  };
  const updateMeta = (card, data) => {
    const meta = card.querySelector('.admin-service-meta');
    if (!meta) return;
    meta.innerHTML = '';
    const items = [
      { icon: 'bx-time', text: formatDuration(data.Duracion) },
      { icon: 'bx-purchase-tag', text: `$ ${formatPrice(data.Precio)}` },
      { icon: 'bx-gift', text: formatPoints(data.Puntos) },
    ];
    items.forEach(({ icon, text }) => {
      const li = document.createElement('li');
      const i = document.createElement('i');
      i.className = `bx ${icon}`;
      const span = document.createElement('span');
      span.textContent = text;
      li.appendChild(i);
      li.appendChild(span);
      meta.appendChild(li);
    });
  };
  const createCard = (data) => {
    const card = document.createElement('article');
    card.className = 'admin-service-card';
    card.setAttribute('data-admin-service-item', '');
    applyDataset(card, data);

    const thumb = document.createElement('div');
    thumb.className = 'admin-service-thumb';
    card.appendChild(thumb);

    const content = document.createElement('div');
    content.className = 'admin-service-content';
    card.appendChild(content);

    const header = document.createElement('header');
    header.className = 'admin-service-header';
    const title = document.createElement('h3');
    title.textContent = String(data.Nombre || 'Servicio');
    const status = document.createElement('span');
    status.className = 'admin-service-status';
    header.appendChild(title);
    header.appendChild(status);
    content.appendChild(header);

    const meta = document.createElement('ul');
    meta.className = 'admin-service-meta';
    content.appendChild(meta);

    const actions = document.createElement('div');
    actions.className = 'admin-service-actions';
    actions.innerHTML = `
      <button type="button" class="admin-service-edit" data-admin-service-edit="${escapeAttr(data.ID_Servicio ?? '')}" aria-label="Editar servicio">
        <i class="bx bx-edit-alt"></i>
      </button>
      <button type="button" class="admin-service-delete" data-admin-service-delete="${escapeAttr(data.ID_Servicio ?? '')}" aria-label="Eliminar servicio">
        <i class="bx bx-trash"></i>
      </button>
    `;
    card.appendChild(actions);

    updateStatusPill(card, data.Estado);
    updateImage(card, data.Img_Link, data.Nombre);
    updateMeta(card, data);
    return card;
  };
  const updateCard = (card, data) => {
    if (!card) return;
    applyDataset(card, data);
    const title = card.querySelector('.admin-service-header h3');
    if (title) title.textContent = String(data.Nombre || 'Servicio');
    card.querySelectorAll('[data-admin-service-edit],[data-admin-service-delete]').forEach((btn) => {
      const attr = btn.hasAttribute('data-admin-service-edit') ? 'data-admin-service-edit' : 'data-admin-service-delete';
      btn.setAttribute(attr, String(data.ID_Servicio));
    });
    updateStatusPill(card, data.Estado);
    updateImage(card, data.Img_Link, data.Nombre);
    updateMeta(card, data);
  };
  const readCardData = (card) => ({
    ID_Servicio: card?.dataset?.adminServiceId || '',
    Nombre: card?.dataset?.adminServiceName || '',
    Duracion: card?.dataset?.adminServiceDuration || '',
    Estado: card?.dataset?.adminServiceStatus || 'Activo',
    Precio: card?.dataset?.adminServicePrice || '',
    Puntos: card?.dataset?.adminServicePoints || '',
    Img_Link: card?.dataset?.adminServiceImage || '',
  });

  const resetForm = () => {
    form?.reset();
    revokePreviewObjectUrl();
    if (imageInput) { try { imageInput.value = ''; } catch (_) {} }
    showPreview('', 'Vista previa');
    if (imgField) imgField.value = '';
  };
  const openModal = (nextMode, data) => {
    if (modalLoading) modalLoading.show(modal);
    mode = nextMode;
    resetForm();
    clearError();
    if (titleEl) {
      titleEl.textContent = mode === 'edit' ? 'Editar servicio' : 'Registrar nuevo servicio';
    }
    if (mode === 'edit' && data) {
      if (idField) idField.value = String(data.ID_Servicio || '');
      if (imgField) imgField.value = String(data.Img_Link || '');
      const nameInput = form?.querySelector('input[name="Nombre"]');
      if (nameInput) nameInput.value = String(data.Nombre || '');
      if (durationSelect) durationSelect.value = String(data.Duracion || '');
      if (estadoSelect) estadoSelect.value = String(data.Estado || 'Activo');
      const priceInput = form?.querySelector('input[name="Precio"]');
      if (priceInput) priceInput.value = data.Precio === null || data.Precio === undefined ? '' : String(data.Precio);
      const pointsInput = form?.querySelector('input[name="Puntos"]');
      if (pointsInput) pointsInput.value = data.Puntos === null || data.Puntos === undefined ? '' : String(data.Puntos);
      showPreview(resolveImageUrl(data.Img_Link), String(data.Nombre || 'Servicio'));
    } else if (idField) {
      idField.value = '';
    }
    modal.hidden = false;
    requestAnimationFrame(() => {
      modal.classList.add('is-visible');
      if (modalLoading) modalLoading.hide(modal);
      const first = form?.querySelector('input[name="Nombre"]');
      first?.focus({ preventScroll: true });
    });
  };
  const closeModal = () => {
    modal.classList.remove('is-visible');
    modal.hidden = true;
    if (modalLoading) modalLoading.hide(modal);
    enableSubmit();
    clearError();
  };

  addBtn?.addEventListener('click', (evt) => {
    evt.preventDefault();
    openModal('create');
  });
  closeEls.forEach((btn) => btn.addEventListener('click', (evt) => {
    evt.preventDefault();
    closeModal();
  }));
  document.addEventListener('keydown', (evt) => {
    if (evt.key === 'Escape' && !modal.hidden) {
      evt.preventDefault();
      closeModal();
    }
  });
  imageInput?.addEventListener('change', () => {
    revokePreviewObjectUrl();
    const file = imageInput.files && imageInput.files[0];
    if (!file) {
      showPreview(resolveImageUrl(imgField?.value || ''), form?.querySelector('input[name="Nombre"]')?.value || 'Vista previa');
      return;
    }
    previewObjectUrl = URL.createObjectURL(file);
    showPreview(previewObjectUrl, file.name || 'Vista previa');
  });

  const findCardById = (id) => {
    if (!id) return null;
    return list.querySelector(`[data-admin-service-item][data-admin-service-id="${id}"]`);
  };

  list.addEventListener('click', async (evt) => {
    const target = evt.target;
    if (!target || !target.closest) return;
    const editBtn = target.closest('[data-admin-service-edit]');
    if (editBtn) {
      evt.preventDefault();
      const id = editBtn.getAttribute('data-admin-service-edit');
      if (!id) return;
      const card = findCardById(id);
      if (!card) return;
      const data = readCardData(card);
      openModal('edit', data);
      return;
    }
    const deleteBtn = target.closest('[data-admin-service-delete]');
    if (deleteBtn) {
      evt.preventDefault();
      const id = deleteBtn.getAttribute('data-admin-service-delete');
      if (!id) return;
      const card = findCardById(id);
      const name = card?.querySelector('.admin-service-header h3')?.textContent?.trim() || 'este servicio';
      const ok = await adminConfirm({
        title: 'Eliminar servicio',
        message: `\u00bfEliminar "${name}"? Esta accion no se puede deshacer.`,
        confirmText: 'Eliminar',
      });
      if (!ok) return;
      deleteBtn.disabled = true;
      const fd = new FormData();
      fd.append('action', 'delete');
      fd.append('ID_Servicio', id);
      fetch(endpoint, { method: 'POST', body: fd })
        .then((res) => res.json().then((json) => ({ json, ok: res.ok })).catch(() => ({ json: null, ok: res.ok })))
        .then(({ json, ok }) => {
          if (!ok || !json || !json.ok) throw new Error('delete failed');
          card?.remove();
          updateCount();
          updateEmptyState();
          adminNotify('Servicio eliminado correctamente.', 'success');
        })
        .catch(() => {
          adminNotify('No se pudo eliminar el servicio.', 'error');
        })
        .finally(() => {
          deleteBtn.disabled = false;
        });
    }
  });

  form?.addEventListener('submit', async (evt) => {
    evt.preventDefault();
    clearError();
    disableSubmit();
    try {
      const fd = new FormData(form);
      fd.append('action', mode === 'edit' ? 'update' : 'create');
      const res = await fetch(endpoint, { method: 'POST', body: fd });
      const payload = await res.json().catch(() => null);
      if (!payload) {
        throw new Error('request failed');
      }
      if (!res.ok || !payload.ok) {
        const msg = Array.isArray(payload.errors) && payload.errors.length
          ? String(payload.errors[0])
          : String(payload.error || 'No se pudo guardar el servicio.');
        showError(msg);
        enableSubmit();
        return;
      }
      const row = payload.data || {};
      const id = String(row.ID_Servicio || '');
      if (mode === 'edit') {
        const card = findCardById(id);
        if (card) {
          updateCard(card, row);
        } else {
          const newCard = createCard(row);
          list.prepend(newCard);
        }
        adminNotify('Servicio actualizado correctamente.', 'success');
      } else {
        const card = createCard(row);
        list.prepend(card);
        adminNotify('Servicio creado correctamente.', 'success');
      }
      updateCount();
      updateEmptyState();
      closeModal();
    } catch (err) {
      showError('No se pudo guardar el servicio.');
      enableSubmit();
    }
  });

  updateCount();
  updateEmptyState();
})();
