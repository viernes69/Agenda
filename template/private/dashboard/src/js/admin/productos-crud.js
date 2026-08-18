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
  const coverField = form?.querySelector('[data-admin-product-field="cover-index"]');
  const imageSlots = Array.from(form?.querySelectorAll('[data-admin-product-image-slot]') || []);
  const imageInputs = Array.from(form?.querySelectorAll('[data-admin-product-image-input]') || []);
  const imagePriceInputs = Array.from(form?.querySelectorAll('[data-admin-product-image-price]') || []);
  const imageLabelInputs = Array.from(form?.querySelectorAll('[data-admin-product-image-label]') || []);
  const coverRadios = Array.from(form?.querySelectorAll('[data-admin-product-cover-radio]') || []);
  const basePriceInput = form?.querySelector('input[name="Precio"]');
  const typeSelect = form?.querySelector('[data-admin-product-field="tipo-select"]')
    || form?.querySelector('select[name="Tipo"]');
  const tipoCustomWrap = modal.querySelector('[data-admin-product-tipo-custom-wrap]');
  const tipoCustomInput = form?.querySelector('[data-admin-product-field="tipo-custom"]');
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
  const previewObjectUrls = {};
  const revokePreviewObjectUrl = (slot = null) => {
    const slots = slot === null ? Object.keys(previewObjectUrls) : [String(slot)];
    slots.forEach((key) => {
      if (previewObjectUrls[key]) {
        try { URL.revokeObjectURL(previewObjectUrls[key]); } catch (_) {}
        previewObjectUrls[key] = '';
      }
    });
  };
  const tenantPublicBase = () => {
    const parts = String(window.location.pathname || '').split('/').filter(Boolean);
    const idx = parts.indexOf('private');
    if (idx <= 0) return '';
    if (String(parts[idx - 1] || '').toLowerCase() === 'template') return '';
    return '/' + parts.slice(0, idx).join('/');
  };
  const detectedAppBase = () => {
    const parts = String(window.location.pathname || '').split('/').filter(Boolean);
    const templateIdx = parts.indexOf('template');
    if (templateIdx > 0) return '/' + parts.slice(0, templateIdx).join('/');
    const adminIdx = parts.indexOf('admin');
    if (adminIdx > 0) return '/' + parts.slice(0, adminIdx).join('/');
    return '';
  };
  const appPublicBase = () => {
    const meta = document.querySelector('meta[name="url-base"]');
    const slug = String(document.querySelector('meta[name="tenant-slug"]')?.content || '').trim().replace(/^\/+|\/+$/g, '');
    const raw = String(meta?.content || '').trim();
    if (!raw) return detectedAppBase();
    try {
      let path = new URL(raw, window.location.origin).pathname.replace(/\/+$/, '');
      if (slug && path.endsWith(`/${slug}`)) {
        path = path.slice(0, -(slug.length + 1));
      }
      return path || detectedAppBase();
    } catch (_) {
      let path = raw.replace(/\/+$/, '');
      if (slug && path.endsWith(`/${slug}`)) {
        path = path.slice(0, -(slug.length + 1));
      }
      return path || detectedAppBase();
    }
  };
  const resolveImageUrl = (value) => {
    const ref = String(value || '').trim();
    if (!ref) return '';
    if (/^(https?:|blob:|data:)/i.test(ref)) return ref;
    if (ref.startsWith('/')) return ref;
    const rel = ref.replace(/^\/+/, '');
    const appBase = appPublicBase();
    if (rel.startsWith('commerce-assets/')) {
      const prefix = appBase || '';
      return `${prefix}/src/API/commerce_asset.php?p=${encodeURIComponent(rel)}`;
    }
    if (rel.startsWith('src/') || rel.startsWith('storage/') || rel.startsWith('uploads/') || rel.startsWith('assets/')) {
      return appBase ? `${appBase}/${rel}` : `/${rel}`;
    }
    const tenantBase = tenantPublicBase();
    if (tenantBase) return `${tenantBase}/${rel}`;
    if (appBase) return `${appBase}/${rel}`;
    return `/${rel}`;
  };
  const parseImages = (data) => {
    const raw = data?.Imagenes ?? data?.imagenes ?? '';
    let parsed = [];
    if (Array.isArray(raw)) {
      parsed = raw;
    } else if (String(raw || '').trim()) {
      try {
        parsed = JSON.parse(String(raw));
      } catch (_) {
        parsed = [];
      }
    }
    if (!Array.isArray(parsed) || !parsed.length) {
      const paths = [];
      const cover = String(data?.Img_src ?? data?.img_src ?? data?.img ?? '').trim();
      if (cover) paths.push(cover);
      String(data?.Img_Gallery ?? data?.img_gallery ?? '')
        .split('|')
        .map((part) => part.trim())
        .filter(Boolean)
        .forEach((part) => {
          if (!paths.includes(part)) paths.push(part);
        });
      parsed = paths.map((src, index) => ({ src, cover: index === 0, price: '', label: '' }));
    }
    const clean = parsed
      .filter((item) => item && String(item.src || item.path || '').trim())
      .slice(0, 4)
      .map((item, index) => ({
        src: String(item.src || item.path || '').trim(),
        price: item.price ?? item.precio ?? '',
        cover: Boolean(item.cover || item.portada),
        label: String(item.label || ''),
        index,
      }));
    if (clean.length && !clean.some((item) => item.cover)) clean[0].cover = true;
    return clean;
  };
  const imagesToJson = (images) => {
    try {
      return JSON.stringify((images || []).map((item) => ({
        src: item.src || '',
        price: item.price ?? '',
        cover: Boolean(item.cover),
        label: item.label || '',
      })));
    } catch (_) {
      return '[]';
    }
  };
  const primaryImage = (data) => {
    const images = parseImages(data);
    return images[0]?.src || data?.Img_src || data?.img_src || '';
  };
  const syncCoverField = () => {
    const checked = coverRadios.find((radio) => radio.checked);
    if (coverField && checked) coverField.value = checked.value || '0';
  };
  const filenameLabel = (name) => String(name || '')
    .replace(/\.[a-z0-9]{2,6}$/i, '')
    .replace(/[_-]+/g, ' ')
    .replace(/\s+/g, ' ')
    .trim();
  const setSlotPreview = (slot, src, alt) => {
    const slotEl = form?.querySelector(`[data-admin-product-image-slot="${slot}"]`);
    const img = form?.querySelector(`[data-admin-product-image-preview="${slot}"]`);
    const empty = form?.querySelector(`[data-admin-product-image-empty="${slot}"]`);
    const resolved = String(src || '').trim();
    const url = resolved ? resolveImageUrl(resolved) : '';
    if (slotEl) slotEl.classList.toggle('has-image', Boolean(url));
    if (img) {
      if (url) {
        img.hidden = false;
        img.style.display = 'block';
        img.src = url;
        img.alt = alt || 'Vista previa';
      } else {
        img.hidden = true;
        img.style.display = 'none';
        img.removeAttribute('src');
        img.alt = '';
      }
    }
    if (empty) {
      if (url) {
        empty.hidden = true;
        empty.style.display = 'none';
      } else {
        empty.hidden = false;
        empty.style.display = '';
      }
    }
    syncSlotMeta(slot);
  };
  const clearImageSlot = (slot) => {
    revokePreviewObjectUrl(slot);
    const input = form?.querySelector(`[data-admin-product-image-input="${slot}"]`);
    const current = form?.querySelector(`[data-admin-product-image-current="${slot}"]`);
    const remove = form?.querySelector(`[data-admin-product-image-remove-value="${slot}"]`);
    const price = form?.querySelector(`[data-admin-product-image-price="${slot}"]`);
    const label = form?.querySelector(`[data-admin-product-image-label="${slot}"]`);
    if (input) { try { input.value = ''; } catch (_) {} }
    if (current) current.value = '';
    if (remove) remove.value = String(slot);
    if (price) price.value = '';
    if (label) label.value = '';
    setSlotPreview(slot, '', 'Vista previa');
  };
  const resetImageSlots = () => {
    revokePreviewObjectUrl();
    imageSlots.forEach((slotEl) => {
      const slot = slotEl.getAttribute('data-admin-product-image-slot') || '0';
      clearImageSlot(slot);
      const remove = form?.querySelector(`[data-admin-product-image-remove-value="${slot}"]`);
      if (remove) remove.value = '';
    });
    if (coverRadios[0]) coverRadios[0].checked = true;
    syncCoverField();
  };
  const applyImagesToForm = (data) => {
    resetImageSlots();
    const images = parseImages(data);
    images.forEach((item, slot) => {
      const current = form?.querySelector(`[data-admin-product-image-current="${slot}"]`);
      const remove = form?.querySelector(`[data-admin-product-image-remove-value="${slot}"]`);
      const price = form?.querySelector(`[data-admin-product-image-price="${slot}"]`);
      const label = form?.querySelector(`[data-admin-product-image-label="${slot}"]`);
      const radio = form?.querySelector(`[data-admin-product-cover-radio][value="${slot}"]`);
      if (current) current.value = item.src || '';
      if (remove) remove.value = '';
      if (price) price.value = item.price === null || item.price === undefined ? '' : String(item.price);
      if (label) label.value = item.label === null || item.label === undefined ? '' : String(item.label);
      if (radio) radio.checked = Boolean(item.cover);
      setSlotPreview(slot, item.src || '', data?.Nombre || 'Producto');
    });
    if (images.length && !coverRadios.some((radio) => radio.checked)) {
      coverRadios[0].checked = true;
    }
    syncCoverField();
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
  const imagePriceLabel = (slot) => {
    const custom = form?.querySelector(`[data-admin-product-image-price="${slot}"]`)?.value || '';
    const base = basePriceInput?.value || '';
    const raw = String(custom || base || '').trim();
    if (!raw || !Number.isFinite(Number(raw))) {
      return custom ? 'Revisa el precio' : 'Precio base';
    }
    const suffix = custom ? 'precio propio' : 'precio base';
    return '$ ' + formatPrice(raw) + ' - ' + suffix;
  };
  const syncSlotMeta = (slot) => {
    const titleEl = form?.querySelector(`[data-admin-product-image-title-preview="${slot}"]`);
    const priceEl = form?.querySelector(`[data-admin-product-image-price-preview="${slot}"]`);
    const labelInput = form?.querySelector(`[data-admin-product-image-label="${slot}"]`);
    const title = String(labelInput?.value || '').trim() || ('Imagen ' + (Number(slot) + 1));
    if (titleEl) titleEl.textContent = title;
    if (priceEl) priceEl.textContent = imagePriceLabel(slot);
  };
  const syncAllSlotMeta = () => {
    imageSlots.forEach((slotEl) => {
      syncSlotMeta(slotEl.getAttribute('data-admin-product-image-slot') || '0');
    });
  };
  const applyDataset = (el, data) => {
    el.dataset.adminProductId = String(data.ID_Product ?? data.id ?? '');
    el.dataset.adminProductName = String(data.Nombre ?? '');
    el.dataset.adminProductType = String(data.Tipo ?? '');
    el.dataset.adminProductTypeKey = normalizeKey(data.Tipo ?? '') || 'otro';
    el.dataset.adminProductPrice = String(data.Precio ?? '');
    el.dataset.adminProductDescription = String(data.Descripcion ?? '');
    el.dataset.adminProductPoints = data.Puntos === null || data.Puntos === undefined ? '' : String(data.Puntos);
    el.dataset.adminProductImage = String(primaryImage(data));
    el.dataset.adminProductGallery = String(data.Img_Gallery ?? data.img_gallery ?? '');
    el.dataset.adminProductImages = String(data.Imagenes ?? data.imagenes ?? imagesToJson(parseImages(data)));
    el.dataset.adminProductDiscount = String(data.Descuento_Porcentaje ?? data.Descuento ?? '');
    el.dataset.adminProductSaleLabel = String(data.Etiqueta_Venta ?? data.Oferta_Tipo ?? '');
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
    const discount = Number(data.Descuento_Porcentaje ?? data.Descuento ?? 0);
    if (Number.isFinite(discount) && discount > 0) {
      items.splice(1, 0, { icon: 'bx-badge-percent', text: `${formatPrice(discount)}% off` });
    }
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

    const sale = document.createElement('span');
    sale.className = 'admin-product-sale-label';
    sale.hidden = !String(data.Etiqueta_Venta || '').trim();
    sale.textContent = String(data.Etiqueta_Venta || '').trim();
    content.appendChild(sale);

    const meta = document.createElement('ul');
    meta.className = 'admin-product-meta';
    content.appendChild(meta);

    const desc = document.createElement('p');
    desc.className = 'admin-product-desc admin-product-description';
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

    updateThumb(card, primaryImage(data), data.Nombre);
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
    const sale = card.querySelector('.admin-product-sale-label');
    if (sale) {
      sale.textContent = String(data.Etiqueta_Venta || '').trim();
      sale.hidden = !sale.textContent;
    }
    const desc = card.querySelector('.admin-product-description');
    if (desc) desc.textContent = decode(data.Descripcion || 'Sin descripcion');
    card.querySelectorAll('[data-admin-product-edit],[data-admin-product-delete]').forEach((btn) => {
      const attr = btn.hasAttribute('data-admin-product-edit') ? 'data-admin-product-edit' : 'data-admin-product-delete';
      btn.setAttribute(attr, String(data.ID_Product));
    });
    updateThumb(card, primaryImage(data), data.Nombre);
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
    Img_Gallery: card?.dataset?.adminProductGallery || '',
    Imagenes: card?.dataset?.adminProductImages || '',
    Descuento_Porcentaje: card?.dataset?.adminProductDiscount || '',
    Etiqueta_Venta: card?.dataset?.adminProductSaleLabel || '',
  });
  const findCard = (id) => {
    if (!id) return null;
    return list.querySelector(`[data-admin-product-item][data-admin-product-id="${id}"]`);
  };

  const resetForm = () => {
    form?.reset();
    resetImageSlots();
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
      const nameInput = form?.querySelector('input[name="Nombre"]');
      if (nameInput) nameInput.value = String(data.Nombre || '');
      applyTipoToForm(data.Tipo);
      const priceInput = form?.querySelector('input[name="Precio"]');
      if (priceInput) priceInput.value = data.Precio === null || data.Precio === undefined ? '' : String(data.Precio);
      const pointsInput = form?.querySelector('input[name="Puntos"]');
      if (pointsInput) pointsInput.value = data.Puntos === null || data.Puntos === undefined ? '' : String(data.Puntos);
      const discountInput = form?.querySelector('input[name="Descuento_Porcentaje"]');
      if (discountInput) discountInput.value = data.Descuento_Porcentaje === null || data.Descuento_Porcentaje === undefined ? '' : String(data.Descuento_Porcentaje);
      const saleLabelInput = form?.querySelector('input[name="Etiqueta_Venta"]');
      if (saleLabelInput) saleLabelInput.value = data.Etiqueta_Venta === null || data.Etiqueta_Venta === undefined ? '' : String(data.Etiqueta_Venta);
      const descInput = form?.querySelector('textarea[name="Descripcion"]');
      if (descInput) descInput.value = data.Descripcion === null || data.Descripcion === undefined ? '' : String(data.Descripcion);
      applyImagesToForm(data);
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
  imageInputs.forEach((input) => {
    input.addEventListener('change', () => {
      const slot = input.getAttribute('data-admin-product-image-input') || '0';
      revokePreviewObjectUrl(slot);
      const remove = form?.querySelector(`[data-admin-product-image-remove-value="${slot}"]`);
      if (remove) remove.value = '';
      const file = input.files && input.files[0];
      if (!file) {
        const current = form?.querySelector(`[data-admin-product-image-current="${slot}"]`)?.value || '';
        setSlotPreview(slot, current, form?.querySelector('input[name="Nombre"]')?.value || 'Vista previa');
        return;
      }
      previewObjectUrls[String(slot)] = URL.createObjectURL(file);
      const labelInput = form?.querySelector(`[data-admin-product-image-label="${slot}"]`);
      if (labelInput && !String(labelInput.value || '').trim()) {
        labelInput.value = filenameLabel(file.name) || ('Imagen ' + (Number(slot) + 1));
      }
      setSlotPreview(slot, previewObjectUrls[String(slot)], file.name || 'Vista previa');
    });
  });
  imagePriceInputs.forEach((input) => {
    input.addEventListener('input', () => {
      syncSlotMeta(input.getAttribute('data-admin-product-image-price') || '0');
    });
  });
  imageLabelInputs.forEach((input) => {
    input.addEventListener('input', () => {
      syncSlotMeta(input.getAttribute('data-admin-product-image-label') || '0');
    });
  });
  basePriceInput?.addEventListener('input', syncAllSlotMeta);
  form?.querySelectorAll('.admin-product-image-slot__preview').forEach((preview) => {
    preview.addEventListener('keydown', (evt) => {
      if (evt.key !== 'Enter' && evt.key !== ' ') return;
      evt.preventDefault();
      const id = preview.getAttribute('for');
      if (!id) return;
      document.getElementById(id)?.click();
    });
  });
  coverRadios.forEach((radio) => {
    radio.addEventListener('change', syncCoverField);
  });
  form?.querySelectorAll('[data-admin-product-image-remove]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const slot = btn.getAttribute('data-admin-product-image-remove') || '0';
      clearImageSlot(slot);
      const checked = coverRadios.find((radio) => radio.checked);
      if (checked && checked.value === slot) {
        const next = coverRadios.find((radio) => {
          const s = radio.value || '0';
          return s !== slot && (
            form?.querySelector(`[data-admin-product-image-current="${s}"]`)?.value
            || form?.querySelector(`[data-admin-product-image-input="${s}"]`)?.files?.length
          );
        }) || coverRadios[0];
        if (next) next.checked = true;
      }
      syncCoverField();
    });
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
      syncCoverField();
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

  window.AdminProductsCrud = {
    replaceMarkup: (html) => {
      list.innerHTML = String(html || '');
      applyFilter();
    },
    refresh: applyFilter,
  };

  applyFilter();
})();
