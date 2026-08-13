(function adminSummaryLazyLoader() {
  const current = document.currentScript;
  const srcByModal = {
    'reservas-summary': current ? current.getAttribute('data-reservas-src') : '',
    'productos-summary': current ? current.getAttribute('data-productos-src') : '',
  };
  const loading = new Map();

  const loadScript = (src) => {
    if (!src) return Promise.reject(new Error('Script no configurado'));
    if (loading.has(src)) return loading.get(src);
    const promise = new Promise((resolve, reject) => {
      const script = document.createElement('script');
      script.src = src;
      script.async = false;
      script.onload = resolve;
      script.onerror = () => reject(new Error('No se pudo cargar ' + src));
      document.body.appendChild(script);
    });
    loading.set(src, promise);
    return promise;
  };

  document.addEventListener('click', async (event) => {
    const trigger = event.target instanceof Element
      ? event.target.closest('[data-admin-summary-modal]')
      : null;
    if (!trigger) return;

    const modalId = trigger.getAttribute('data-admin-summary-modal') || '';
    const src = srcByModal[modalId] || '';
    if (!src || trigger.dataset.adminSummaryReady === 'true') return;

    event.preventDefault();
    event.stopImmediatePropagation();
    trigger.setAttribute('aria-busy', 'true');

    try {
      await loadScript(src);
      trigger.dataset.adminSummaryReady = 'true';
      trigger.dispatchEvent(new MouseEvent('click', {
        bubbles: true,
        cancelable: true,
        view: window,
      }));
    } catch (error) {
      console.error('[summary-lazy-loader]', error);
      if (typeof window.AdminNotify === 'function') {
        window.AdminNotify('No se pudo abrir el resumen. Intenta nuevamente.', 'error');
      }
    } finally {
      trigger.removeAttribute('aria-busy');
    }
  }, true);
})();
