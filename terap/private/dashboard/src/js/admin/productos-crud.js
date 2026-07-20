(function adminProductsCrud() {
  const list = document.querySelector('[data-admin-product-list]');
  const modal = document.querySelector('[data-admin-modal="product-form"]');
  if ((!list || !modal) && document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', adminProductsCrud, { once: true });
    return;
  }
  if (!list || !modal) return;
  const modalLoading = window.AdminModalLoading;

  const addBtn = document.querySelector('[data-admin-product-create]');
  const emptyMsg = document.querySelector('.admin-products-empty');
  const countEl = document.querySelector('[data-admin-product-count]')
    || document.querySelector('.admin-products-count');
  const filterSelect = document.querySelector('[data-admin-product-filter]');
  const filterCountEl = document.querySelector('[data-admin-product-filter-count]');
  const form = modal.querySelector('[data-admin-product-form]');
  const closeEls = modal.querySelectorAll('[data-admin-product-close]');
  const errorEl = modal.querySelector('[data-admin-product-error]');
  const submitBtn = modal.querySelector('[data-admin-product-submit]');
  const titleEl = modal.querySelector('[data-admin-product-form-title]');
  const idField = form?.querySelector('[data-admin-product-field="id"]');
  const imgField = form?.querySelector('[data-admin-product-field="img-current"]');
  const imageInput = form?.querySelector('[data-admin-product-field="image-input"]');
  const typeSelect = form?.querySelector('[data-admin-product-field="tipo-select"]')
    || form?.querySelector('select[name="Tipo"]');
  const tipoCustomWrap = modal.querySelector('[data-admin-product-tipo-custom-wrap]');
  const tipoCustomInput = form?.querySelector('[data-admin-product-field="tipo-custom"]');
  const previewWrapper = modal.querySelector('[data-admin-product-current]');
  const previewImg = modal.querySelector('[data-admin-product-preview]');
  const endpoint = '../src/api/productos.php';
  let mode = 'create';

  const normalizeKey = (value) => String(value ?? '').trim().toLowerCase().replace(/\s+/g, ' ');
  const capitalizeFirst = (value) => {
    const text = String(value ?? '').trim();
    if (!text) return '';
    return text.charAt(0).toLocaleUpperCase('es') + text.slice(1);
  };
  const isOtrosTipo = (value) => normalizeKey(value) === 'otros';
  const predefinedTipoValues = () => Array.from(typeSelect?.options || [])
    .map((opt) => String(opt.value || '').trim())
    .filter((value) => value !== '' && !isOtrosTipo(value));
  const syncTipoCustomVisibility = ({ focus = false } = {}) => {
    const showCustom = isOtrosTipo(typeSelect?.value);
    if (tipoCustomWrap) tipoCustomWrap.hidden = !showCustom;
    if (tipoCustomInput) {
      tipoCustomInput.required = showCustom;
      if (!showCustom) tipoCustomInput.value = '';
      else if (focus) tipoCustomInput.focus({ preventScroll: true });
    }
  };
  const applyTipoToForm = (tipoRaw) => {
    if (!typeSelect) return;
    const tipo = String(tipoRaw || '').trim();
    const known = predefinedTipoValues();
    if (tipo && known.includes(tipo)) {
      typeSelect.value = tipo;
      if (tipoCustomInput) tipoCustomInput.value = '';
    } else if (tipo) {
      const otrosOption = Array.from(typeSelect.options).find((opt) => isOtrosTipo(opt.value));
      typeSelect.value = otrosOption ? otrosOption.value : 'Otros';
      if (tipoCustomInput) tipoCustomInput.value = isOtrosTipo(tipo) ? '' : tipo;
    } else {
      typeSelect.value = '';
      if (tipoCustomInput) tipoCustomInput.value = '';
    }
    syncTipoCustomVisibility();
  };
  const empties = {
    base: emptyMsg?.getAttribute('data-empty-base') || 'Aun no tienes Productos registrados.',
    filter: emptyMsg?.getAttribute('data-empty-filter') || 'No hay productos para el tipo seleccionado.',
  };
  const defaultFilterKey = normalizeKey(filterSelect?.getAttribute('data-admin-product-default') || '');
  const getCards = () => Array.from(list.querySelectorAll('[data-admin-product-item]'));

  const parseMaxProducts = () => {
    if (!countEl) return null;
    const raw = countEl.getAttribute('data-max-products');
    if (raw === null || raw === '' || raw === 'null' || raw === '∞') return null;
    const n = parseInt(raw, 10);
    return Number.isFinite(n) && n >= 0 ? n : null;
  };

  /** Same shape as clientes-list formatRegisteredLabel (Registrados n or Registrados n/max). */
  const formatRegisteredLabel = (count) => {
    if (typeof window.adminFormatRegisteredLabel === 'function') {
      return window.adminFormatRegisteredLabel(count, parseMaxProducts());
    }
    const max = parseMaxProducts();
    if (max === null) return 'Registrados ' + count;
    return 'Registrados ' + count + '/' + max;
  };

  const syncAddButtonState = (count) => {
    if (!addBtn) return;
    const max = parseMaxProducts();
    if (max === null) {
      addBtn.disabled = false;
      addBtn.removeAttribute('title');
      return;
    }
    if (max === 0) {
      addBtn.disabled = true;
      addBtn.title = 'Su plan no tiene habilitado cargar productos';
      return;
    }
    const atLimit = count >= max;
    addBtn.disabled = atLimit;
    if (atLimit) {
      addBtn.title = 'Alcanzaste el límite de productos de tu plan';
    } else {
      addBtn.removeAttribute('title');
    }
  };

  const updateCount = (visibleOverride) => {
    const cards = getCards();
    const total = cards.length;
    if (countEl) countEl.textContent = formatRegisteredLabel(total);
    syncAddButtonState(total);
    if (filterCountEl) {
      const visible = typeof visibleOverride === 'number'
        ? visibleOverride
        : cards.reduce((acc, card) => acc + (card.hidden ? 0 : 1), 0);
      filterCountEl.textContent = 'Coincidencias: ' + visible;
    }
  };
  const getActiveFilterKey = () => {
    if (!filterSelect) return '';
    const valueKey = normalizeKey(filterSelect.value);
    if (!valueKey || valueKey === 'todos') return '';
    if (defaultFilterKey && valueKey === defaultFilterKey) return '';
    return valueKey;
  };
  const applyFilter = () => {
    const activeKey = getActiveFilterKey();
    const cards = getCards();
    let visible = 0;
    cards.forEach((card) => {
      const cardKey = normalizeKey(card.dataset.adminProductTypeKey || card.dataset.adminProductType || '');
      const shouldShow = !activeKey || cardKey === activeKey;
      card.hidden = !shouldShow;
      if (shouldShow) visible++;
    });
    if (emptyMsg) {
      if (visible > 0) {
        emptyMsg.hidden = true;
        emptyMsg.textContent = empties.base;
      } else {
        emptyMsg.textContent = activeKey ? empties.filter : empties.base;
        emptyMsg.hidden = false;
      }
    }
    updateCount(visible);
    return visible;
  };
  const decode = (value) => {
    const txt = document.createElement('textarea');
    txt.innerHTML = String(value ?? '');
    return txt.value;
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
  const resolveImageUrl = (value) => {
    const ref = String(value || '').trim();
    if (!ref) return '';
    if (/^(https?:|blob:|data:)/i.test(ref)) return ref;
    if (ref.startsWith('/')) return ref;
    const base = tenantPublicBase();
    const rel = ref.replace(/^\/+/, '');
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
    if (val === null || val === undefined || val === '') return 'Sin puntos';
    const num = Number(val);
    if (!Number.isFinite(num)) return 'Sin puntos';
    return new Intl.NumberFormat('es-UY', { maximumFractionDigits: 0 }).format(num);
  };
  const applyDataset = (el, data) => {
    el.dataset.adminProductId = String(data.ID_Product ?? data.id ?? '');
    el.dataset.adminProductName = String(data.Nombre ?? '');
    el.dataset.adminProductType = String(data.Tipo ?? '');
    el.dataset.adminProductTypeKey = normalizeKey(data.Tipo ?? '') || 'otro';
    el.dataset.adminProductPrice = String(data.Precio ?? '');
    el.dataset.adminProductDescription = String(data.Descripcion ?? '');
    el.dataset.adminProductPoints = data.Puntos === null || data.Puntos === undefined ? '' : String(data.Puntos);
    el.dataset.adminProductImage = String(data.Img_src ?? data.img_src ?? data.img ?? '');
  };
  const updateThumb = (card, imgRel, name) => {
    const thumb = card.querySelector('.admin-product-thumb');
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
      span.className = 'admin-product-thumb-placeholder';
      span.innerHTML = '<i class="bx bx-package"></i>';
      thumb.appendChild(span);
    }
  };
  const updateMeta = (card, data) => {
    const meta = card.querySelector('.admin-product-meta');
    if (!meta) return;
    meta.innerHTML = '';
    const items = [
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
    card.className = 'admin-product-card';
    card.setAttribute('data-admin-product-item', '');
    applyDataset(card, data);

    const thumb = document.createElement('div');
    thumb.className = 'admin-product-thumb';
    card.appendChild(thumb);

    const content = document.createElement('div');
    content.className = 'admin-product-content';
    card.appendChild(content);

    const header = document.createElement('header');
    header.className = 'admin-product-header';
    const title = document.createElement('h3');
    title.textContent = String(data.Nombre || 'Producto');
    const type = document.createElement('span');
    type.className = 'admin-product-type';
    type.textContent = decode(data.Tipo || '');
    header.appendChild(title);
    header.appendChild(type);
    content.appendChild(header);

    const meta = document.createElement('ul');
    meta.className = 'admin-product-meta';
    content.appendChild(meta);

    const desc = document.createElement('p');
    desc.className = 'admin-product-description';
    desc.textContent = decode(data.Descripcion || 'Sin descripcion');
    content.appendChild(desc);

    const actions = document.createElement('div');
    actions.className = 'admin-product-actions';
    actions.innerHTML = `
      <button type="button" class="admin-product-edit" data-admin-product-edit="${String(data.ID_Product ?? '')}" aria-label="Editar producto">
        <i class="bx bx-edit-alt"></i>
      </button>
      <button type="button" class="admin-product-delete" data-admin-product-delete="${String(data.ID_Product ?? '')}" aria-label="Eliminar producto">
        <i class="bx bx-trash"></i>
      </button>
    `;
    content.appendChild(actions);

    updateThumb(card, data.Img_src, data.Nombre);
    updateMeta(card, data);
    return card;
  };
  const updateCard = (card, data) => {
    if (!card) return;
    applyDataset(card, data);
    const title = card.querySelector('.admin-product-header h3');
    if (title) title.textContent = String(data.Nombre || 'Producto');
    const type = card.querySelector('.admin-product-type');
    if (type) type.textContent = decode(data.Tipo || '');
    const desc = card.querySelector('.admin-product-description');
    if (desc) desc.textContent = decode(data.Descripcion || 'Sin descripcion');
    card.querySelectorAll('[data-admin-product-edit],[data-admin-product-delete]').forEach((btn) => {
      const attr = btn.hasAttribute('data-admin-product-edit') ? 'data-admin-product-edit' : 'data-admin-product-delete';
      btn.setAttribute(attr, String(data.ID_Product));
    });
    updateThumb(card, data.Img_src, data.Nombre);
    updateMeta(card, data);
  };
  const readCardData = (card) => ({
    ID_Product: card?.dataset?.adminProductId || '',
    Nombre: card?.dataset?.adminProductName || '',
    Tipo: card?.dataset?.adminProductType || '',
    Precio: card?.dataset?.adminProductPrice || '',
    Descripcion: card?.dataset?.adminProductDescription || '',
    Puntos: card?.dataset?.adminProductPoints || '',
    Img_src: card?.dataset?.adminProductImage || '',
  });
  const findCard = (id) => {
    if (!id) return null;
    return list.querySelector(`[data-admin-product-item][data-admin-product-id="${id}"]`);
  };

  const resetForm = () => {
    form?.reset();
    revokePreviewObjectUrl();
    if (imageInput) { try { imageInput.value = ''; } catch (_) {} }
    showPreview('', 'Vista previa');
    if (imgField) imgField.value = '';
    if (tipoCustomInput) tipoCustomInput.value = '';
    syncTipoCustomVisibility();
  };
  const openModal = (nextMode, data) => {
    if (modalLoading) modalLoading.show(modal);
    mode = nextMode;
    resetForm();
    clearError();
    if (titleEl) {
      titleEl.textContent = mode === 'edit' ? 'Editar producto' : 'Registrar nuevo producto';
    }
    if (mode === 'edit' && data) {
      if (idField) idField.value = String(data.ID_Product || '');
      if (imgField) imgField.value = String(data.Img_src || data.img_src || '');
      const nameInput = form?.querySelector('input[name="Nombre"]');
      if (nameInput) nameInput.value = String(data.Nombre || '');
      applyTipoToForm(data.Tipo);
      const priceInput = form?.querySelector('input[name="Precio"]');
      if (priceInput) priceInput.value = data.Precio === null || data.Precio === undefined ? '' : String(data.Precio);
      const pointsInput = form?.querySelector('input[name="Puntos"]');
      if (pointsInput) pointsInput.value = data.Puntos === null || data.Puntos === undefined ? '' : String(data.Puntos);
      const descInput = form?.querySelector('textarea[name="Descripcion"]');
      if (descInput) descInput.value = data.Descripcion === null || data.Descripcion === undefined ? '' : String(data.Descripcion);
      showPreview(resolveImageUrl(data.Img_src), data.Nombre || 'Producto');
    } else if (idField) {
      idField.value = '';
      syncTipoCustomVisibility();
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
    if (addBtn.disabled) return;
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
  filterSelect?.addEventListener('change', () => { applyFilter(); });
  typeSelect?.addEventListener('change', () => {
    syncTipoCustomVisibility({ focus: isOtrosTipo(typeSelect.value) });
  });
  tipoCustomInput?.addEventListener('blur', () => {
    tipoCustomInput.value = capitalizeFirst(tipoCustomInput.value);
  });
  imageInput?.addEventListener('change', () => {
    revokePreviewObjectUrl();
    const file = imageInput.files && imageInput.files[0];
    if (!file) {
      const current = resolveImageUrl(imgField?.value || '');
      showPreview(current, form?.querySelector('input[name="Nombre"]')?.value || 'Vista previa');
      return;
    }
    previewObjectUrl = URL.createObjectURL(file);
    showPreview(previewObjectUrl, file.name || 'Vista previa');
  });

  list.addEventListener('click', async (evt) => {
    const target = evt.target;
    if (!target || !target.closest) return;
    const editBtn = target.closest('[data-admin-product-edit]');
    if (editBtn) {
      evt.preventDefault();
      const id = editBtn.getAttribute('data-admin-product-edit');
      if (!id) return;
      const card = findCard(id);
      if (!card) return;
      const data = readCardData(card);
      openModal('edit', data);
      return;
    }
    const deleteBtn = target.closest('[data-admin-product-delete]');
    if (deleteBtn) {
      evt.preventDefault();
      const id = deleteBtn.getAttribute('data-admin-product-delete');
      if (!id) return;
      const card = findCard(id);
      const name = card?.querySelector('.admin-product-header h3')?.textContent?.trim() || 'este producto';
      const ok = await adminConfirm({
        title: 'Eliminar producto',
        message: `\u00bfEliminar "${name}"? Esta accion no se puede deshacer.`,
        confirmText: 'Eliminar',
      });
      if (!ok) return;
      deleteBtn.disabled = true;
      const fd = new FormData();
      fd.append('action', 'delete');
      fd.append('ID_Product', id);
      fetch(endpoint, { method: 'POST', body: fd })
        .then((res) => res.json().then((json) => ({ json, ok: res.ok })).catch(() => ({ json: null, ok: res.ok })))
        .then(({ json, ok }) => {
          if (!ok || !json || !json.ok) throw new Error('delete failed');
          card?.remove();
          applyFilter();
          adminNotify('Producto eliminado correctamente.', 'success');
        })
        .catch(() => {
          adminNotify('No se pudo eliminar el producto.', 'error');
        })
        .finally(() => { deleteBtn.disabled = false; });
    }
  });

  form?.addEventListener('submit', async (evt) => {
    evt.preventDefault();
    clearError();
    disableSubmit();
    try {
      const fd = new FormData(form);
      if (isOtrosTipo(typeSelect?.value)) {
        const customTipo = capitalizeFirst(tipoCustomInput?.value || '');
        if (!customTipo) {
          showError('Escribe el tipo de producto.');
          enableSubmit();
          tipoCustomInput?.focus({ preventScroll: true });
          return;
        }
        if (tipoCustomInput) tipoCustomInput.value = customTipo;
        fd.set('Tipo', customTipo);
      }
      fd.append('action', mode === 'edit' ? 'update' : 'create');
      const res = await fetch(endpoint, { method: 'POST', body: fd });
      const payload = await res.json().catch(() => null);
      if (!payload) {
        throw new Error('request failed');
      }
      if (!res.ok || !payload.ok) {
        const msg = Array.isArray(payload.errors) && payload.errors.length
          ? String(payload.errors[0])
          : String(payload.error || 'No se pudo guardar el producto.');
        showError(msg);
        enableSubmit();
        return;
      }
      const row = payload.data || {};
      const id = String(row.ID_Product || '');
      if (mode === 'edit') {
        const card = findCard(id);
        if (card) {
          updateCard(card, row);
        } else {
          const newCard = createCard(row);
          list.prepend(newCard);
        }
        adminNotify('Producto actualizado correctamente.', 'success');
      } else {
        const card = createCard(row);
        list.prepend(card);
        adminNotify('Producto creado correctamente.', 'success');
      }
      applyFilter();
      closeModal();
    } catch (_) {
      showError('No se pudo guardar el producto.');
      enableSubmit();
    }
  });

  applyFilter();
})();


