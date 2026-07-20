(function adminConfigSeoModal() {
  const modal = document.querySelector('[data-admin-modal="config-seo"]');
  if (!modal) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', adminConfigSeoModal, { once: true });
    }
    return;
  }

  const modalLoading = window.AdminModalLoading;

  const form = modal.querySelector('[data-admin-config-seo-form]');
  if (!form) return;

  const titleInput = form.querySelector('[data-admin-config-seo-field="seo.title"]');
  const descriptionInput = form.querySelector('[data-admin-config-seo-field="seo.description"]');
  const canonicalInput = form.querySelector('[data-admin-config-seo-field="seo.canonical"]');
  const ogImageInput = form.querySelector('[data-admin-config-seo-field="seo.og_image"]');
  const descCount = form.querySelector('[data-admin-config-seo-desc-count]');
  const keywordInput = form.querySelector('[data-admin-config-seo-keyword-input]');
  const keywordAddBtn = form.querySelector('[data-admin-config-seo-keyword-add]');
  const keywordList = form.querySelector('[data-admin-config-seo-keyword-list]');
  const robotsIndex = form.querySelector('[data-admin-config-seo-robots="index"]');
  const robotsFollow = form.querySelector('[data-admin-config-seo-robots="follow"]');
  const syncCheckbox = form.querySelector('[data-admin-config-seo-sync]');
  const previewUrl = form.querySelector('[data-admin-config-seo-preview-url]');
  const previewTitle = form.querySelector('[data-admin-config-seo-preview-title]');
  const previewDescription = form.querySelector('[data-admin-config-seo-preview-description]');
  const submitBtn = form.querySelector('[data-admin-config-seo-submit]');
  const errorEl = form.querySelector('[data-admin-config-seo-error]');
  const closeEls = modal.querySelectorAll('[data-admin-config-seo-close]');

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
  let keywords = [];
  let currentSeo = clone((window.ADMIN_INFO_BARBERIA && window.ADMIN_INFO_BARBERIA.seo) || {});

  const updateDescCount = () => {
    if (!descCount || !descriptionInput) return;
    const length = descriptionInput.value.length;
    descCount.textContent = `${length} / 160`;
  };

  const updatePreview = () => {
    if (!previewUrl || !previewTitle || !previewDescription) return;
    const canonical = canonicalInput && canonicalInput.value.trim() ? canonicalInput.value.trim() : (window.LOCATION_ORIGIN || window.location.origin || 'https://www.tusitio.com');
    const title = titleInput && titleInput.value.trim() ? titleInput.value.trim() : 'Título del sitio';
    const description = descriptionInput && descriptionInput.value.trim() ? descriptionInput.value.trim() : 'Descripción breve de la página.';
    previewUrl.textContent = canonical;
    previewTitle.textContent = title;
    previewDescription.textContent = description;
  };

  const renderKeywords = () => {
    if (!keywordList) return;
    keywordList.innerHTML = '';
    keywords.forEach((word, index) => {
      const chip = document.createElement('span');
      chip.className = 'admin-keyword-chip';
      chip.textContent = word;
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.setAttribute('aria-label', `Eliminar ${word}`);
      btn.textContent = '×';
      btn.addEventListener('click', () => {
        keywords.splice(index, 1);
        renderKeywords();
      });
      chip.appendChild(btn);
      keywordList.appendChild(chip);
    });
  };

  const sanitizeKeyword = (value) => {
    if (!value) return '';
    return value.trim().replace(/\s+/g, ' ').toLowerCase();
  };

  const addKeyword = () => {
    if (!keywordInput) return;
    const sanitized = sanitizeKeyword(keywordInput.value);
    if (!sanitized) {
      keywordInput.value = '';
      return;
    }
    if (!keywords.includes(sanitized)) {
      keywords.push(sanitized);
      renderKeywords();
    }
    keywordInput.value = '';
    keywordInput.focus();
  };

  const parseRobots = (value) => {
    const defaults = { index: true, follow: true };
    if (!value || typeof value !== 'string') return defaults;
    const parts = value.split(',').map((part) => part.trim().toLowerCase());
    return {
      index: !parts.includes('noindex'),
      follow: !parts.includes('nofollow'),
    };
  };

  const buildRobots = () => {
    const indexChecked = robotsIndex ? !!robotsIndex.checked : true;
    const followChecked = robotsFollow ? !!robotsFollow.checked : true;
    return [
      indexChecked ? 'index' : 'noindex',
      followChecked ? 'follow' : 'nofollow',
    ].join(',');
  };

  const fillForm = () => {
    currentSeo = clone((window.ADMIN_INFO_BARBERIA && window.ADMIN_INFO_BARBERIA.seo) || {});
    if (titleInput) titleInput.value = currentSeo.title || '';
    if (descriptionInput) descriptionInput.value = currentSeo.description || '';
    if (canonicalInput) canonicalInput.value = currentSeo.canonical || '';
    if (ogImageInput) ogImageInput.value = currentSeo.og_image || '';
    if (syncCheckbox) syncCheckbox.checked = !!currentSeo.sync_og;

    const robotsConfig = parseRobots(currentSeo.robots);
    if (robotsIndex) robotsIndex.checked = robotsConfig.index;
    if (robotsFollow) robotsFollow.checked = robotsConfig.follow;

    keywords = Array.isArray(currentSeo.keywords) ? currentSeo.keywords.map(sanitizeKeyword).filter(Boolean) : [];
    renderKeywords();
    updateDescCount();
    updatePreview();
    if (submitBtn) submitBtn.disabled = false;
    if (errorEl) {
      errorEl.hidden = true;
      errorEl.textContent = '';
    }
  };

  const collectPayload = () => {
    const payload = { seo: {} };
    if (titleInput) payload.seo.title = titleInput.value.trim();
    if (descriptionInput) payload.seo.description = descriptionInput.value.trim();
    if (canonicalInput) payload.seo.canonical = canonicalInput.value.trim();
    if (ogImageInput) payload.seo.og_image = ogImageInput.value.trim();
    if (syncCheckbox) payload.seo.sync_og = !!syncCheckbox.checked;
    payload.seo.keywords = keywords;
    payload.seo.robots = buildRobots();
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

  closeEls.forEach((btn) => btn.addEventListener('click', close));

  if (keywordAddBtn) {
    keywordAddBtn.addEventListener('click', addKeyword);
  }
  if (keywordInput) {
    keywordInput.addEventListener('keydown', (evt) => {
      if (evt.key === 'Enter') {
        evt.preventDefault();
        addKeyword();
      }
    });
  }
  if (descriptionInput) {
    descriptionInput.addEventListener('input', updateDescCount);
  }
  [titleInput, descriptionInput, canonicalInput].forEach((input) => {
    if (!input) return;
    input.addEventListener('input', updatePreview);
  });

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
      const res = await fetch('../../../src/API/AdminConfig.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'config_update', key: 'info_barberia', data: payload }),
      });
      const json = await res.json().catch(() => null);
      if (!res.ok || !json || !json.ok) {
        throw new Error(json && json.error ? json.error : 'No se pudo guardar la configuración SEO.');
      }
      window.ADMIN_INFO_BARBERIA = clone(json.data || {});
      currentSeo = clone((window.ADMIN_INFO_BARBERIA && window.ADMIN_INFO_BARBERIA.seo) || {});
      notify('Configuración SEO actualizada.', 'success');
      close();
    } catch (error) {
      const message = error && error.message ? error.message : 'No se pudo guardar la configuración SEO.';
      if (errorEl) {
        errorEl.hidden = false;
        errorEl.textContent = message;
      }
      notify(message, 'error');
      if (submitBtn) submitBtn.disabled = false;
    }
  });

  window.AdminConfigSeoModal = { open, close };
})();
