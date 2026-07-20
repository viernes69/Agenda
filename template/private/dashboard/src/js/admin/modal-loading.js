(function adminModalLoading() {
  const DEFAULT_DELAY = 150;
  const overlayClass = 'admin-modal-loading';
  const overlayActiveClass = 'admin-modal-loading--active';
  const modalLoadingClass = 'is-loading';
  const DEFAULT_MESSAGE = 'Obteniedo Datos, por favor espere';
  const state = new WeakMap();

  const isElement = (value) => value && typeof value === 'object' && value.nodeType === 1;

  const ensureOverlay = (modal) => {
    if (!modal) return null;
    let overlay = modal.querySelector('[data-admin-modal-loading]');
    if (!overlay) {
      overlay = document.createElement('div');
      overlay.className = overlayClass;
      overlay.setAttribute('data-admin-modal-loading', '');
      overlay.setAttribute('hidden', '');
      overlay.innerHTML = `
        <div class="admin-modal-loading__backdrop"></div>
        <div class="admin-modal-loading__content" role="status" aria-live="polite">
          <div class="admin-modal-loading__spinner"></div>
          <p class="admin-modal-loading__label" data-admin-modal-loading-label>${DEFAULT_MESSAGE}</p>
        </div>
      `;
      modal.appendChild(overlay);
    }
    return overlay;
  };

  const setLabel = (overlay, text) => {
    if (!overlay) return;
    const labelEl = overlay.querySelector('[data-admin-modal-loading-label]');
    if (!labelEl) return;
    const label = typeof text === 'string' && text.trim() !== '' ? text.trim() : DEFAULT_MESSAGE;
    labelEl.textContent = label;
  };

  const resolveModal = (target) => {
    if (!target) return null;
    if (isElement(target)) {
      return target.matches('[data-admin-modal]') ? target : target.closest('[data-admin-modal]');
    }
    if (typeof target === 'string') {
      return document.querySelector(`[data-admin-modal="${target}"]`);
    }
    if (typeof target === 'object' && target.modal && isElement(target.modal)) {
      return resolveModal(target.modal);
    }
    return null;
  };

  const show = (target, options) => {
    const modal = resolveModal(target);
    if (!modal) return;
    const overlay = ensureOverlay(modal);
    const entry = state.get(modal) || {};
    if (entry.timer) {
      clearTimeout(entry.timer);
    }
    const delay = options && typeof options.delay === 'number' ? Math.max(0, options.delay) : DEFAULT_DELAY;
    const labelText = options && typeof options.label === 'string' ? options.label : DEFAULT_MESSAGE;
    setLabel(overlay, labelText);
    entry.timer = setTimeout(() => {
      overlay.removeAttribute('hidden');
      overlay.classList.add(overlayActiveClass);
      modal.classList.add(modalLoadingClass);
      entry.timer = null;
      entry.visible = true;
    }, delay);
    entry.visible = false;
    state.set(modal, entry);
  };

  const hide = (target) => {
    const modal = resolveModal(target);
    if (!modal) return;
    const overlay = ensureOverlay(modal);
    const entry = state.get(modal);
    if (entry && entry.timer) {
      clearTimeout(entry.timer);
      entry.timer = null;
    }
    overlay.classList.remove(overlayActiveClass);
    overlay.setAttribute('hidden', '');
    modal.classList.remove(modalLoadingClass);
    if (entry) {
      entry.visible = false;
    }
  };

  const wrap = async (target, task, options) => {
    show(target, options);
    try {
      return await task();
    } finally {
      hide(target);
    }
  };

  const initModal = (modal) => {
    if (!modal || modal.__adminModalLoadingObserved) return;
    ensureOverlay(modal);
    const observer = new MutationObserver((mutations) => {
      mutations.forEach((mutation) => {
        if (mutation.type === 'attributes' && mutation.attributeName === 'hidden' && modal.hidden) {
          hide(modal);
        }
      });
    });
    observer.observe(modal, { attributes: true, attributeFilter: ['hidden'] });
    modal.__adminModalLoadingObserved = true;
  };

  const bootstrap = () => {
    document.querySelectorAll('[data-admin-modal]').forEach(initModal);
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootstrap, { once: true });
  } else {
    bootstrap();
  }

  window.AdminModalLoading = { show, hide, wrap };
})();
