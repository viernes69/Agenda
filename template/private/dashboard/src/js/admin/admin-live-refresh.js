(function adminLiveRefresh() {
  'use strict';

  const INTERVAL_MS = 12000;
  const FETCH_TIMEOUT_MS = 9000;
  let inFlight = false;
  let lastReloadAt = 0;

  const isBusy = () => {
    if (document.visibilityState !== 'visible') return true;
    if (navigator.onLine === false) return true;
    if (document.querySelector('.modal:not([hidden]), [data-admin-confirm]:not([hidden])')) return true;
    if (document.querySelector('[data-order-edit]:not([hidden])')) return true;

    const active = document.activeElement;
    if (active && active !== document.body) {
      const tag = String(active.tagName || '').toLowerCase();
      if (['input', 'textarea', 'select'].includes(tag)) return true;
      if (active.isContentEditable) return true;
    }
    return false;
  };

  const liveUrl = () => {
    const url = new URL(window.location.href);
    url.hash = '';
    url.searchParams.set('_live', String(Date.now()));
    return url;
  };

  const safeReload = () => {
    const now = Date.now();
    if (now - lastReloadAt < 30000) return;
    lastReloadAt = now;
    window.location.reload();
  };

  const syncTextAndAttrs = (selector, doc) => {
    const current = document.querySelector(selector);
    const fresh = doc.querySelector(selector);
    if (!current || !fresh) return;
    current.textContent = fresh.textContent;
    Array.from(fresh.attributes).forEach((attr) => {
      if (attr.name === 'id' || attr.name === 'class') return;
      current.setAttribute(attr.name, attr.value);
    });
  };

  const syncSelectOptions = (selector, doc) => {
    const current = document.querySelector(selector);
    const fresh = doc.querySelector(selector);
    if (!current || !fresh) return;
    const previous = current.value;
    current.innerHTML = fresh.innerHTML;
    if (Array.from(current.options).some((option) => option.value === previous)) {
      current.value = previous;
    } else {
      current.value = fresh.value || (current.options[0] ? current.options[0].value : '');
    }
  };

  const refreshSummary = (doc) => {
    const currentCards = Array.from(document.querySelectorAll('#resumen .summary-card'));
    const freshCards = Array.from(doc.querySelectorAll('#resumen .summary-card'));
    if (!currentCards.length || currentCards.length !== freshCards.length) return;
    currentCards.forEach((card, index) => {
      const fresh = freshCards[index];
      const currentTitle = card.querySelector('.summary-card__header h3');
      const freshTitle = fresh.querySelector('.summary-card__header h3');
      const currentSubtitle = card.querySelector('.summary-card__subtitle');
      const freshSubtitle = fresh.querySelector('.summary-card__subtitle');
      const currentList = card.querySelector('.summary-card__list');
      const freshList = fresh.querySelector('.summary-card__list');
      if (currentTitle && freshTitle) currentTitle.textContent = freshTitle.textContent;
      if (currentSubtitle && freshSubtitle) currentSubtitle.textContent = freshSubtitle.textContent;
      if (currentList && freshList) currentList.innerHTML = freshList.innerHTML;
    });
  };

  const refreshClients = (doc) => {
    const currentList = document.querySelector('[data-admin-client-list]');
    const freshList = doc.querySelector('[data-admin-client-list]');
    if (!currentList || !freshList) return;
    syncTextAndAttrs('[data-admin-client-count]', doc);
    if (window.AdminClientsList && typeof window.AdminClientsList.replaceMarkup === 'function') {
      window.AdminClientsList.replaceMarkup(freshList.innerHTML);
    } else {
      currentList.innerHTML = freshList.innerHTML;
    }
  };

  const refreshProducts = (doc) => {
    const currentList = document.querySelector('[data-admin-product-list]');
    const freshList = doc.querySelector('[data-admin-product-list]');
    if (!currentList || !freshList) return;
    syncTextAndAttrs('[data-admin-product-count]', doc);
    syncTextAndAttrs('[data-admin-product-filter-count]', doc);
    syncSelectOptions('[data-admin-product-filter]', doc);
    const empty = document.querySelector('.admin-products-empty');
    const freshEmpty = doc.querySelector('.admin-products-empty');
    if (empty && freshEmpty) {
      empty.textContent = freshEmpty.textContent;
      empty.hidden = freshEmpty.hidden;
      Array.from(freshEmpty.attributes).forEach((attr) => {
        if (attr.name !== 'class') empty.setAttribute(attr.name, attr.value);
      });
    }
    if (window.AdminProductsCrud && typeof window.AdminProductsCrud.replaceMarkup === 'function') {
      window.AdminProductsCrud.replaceMarkup(freshList.innerHTML);
    } else {
      currentList.innerHTML = freshList.innerHTML;
    }
  };

  const refreshOrders = (doc) => {
    const currentTbody = document.querySelector('[data-admin-orders-table] tbody');
    const freshTbody = doc.querySelector('[data-admin-orders-table] tbody');
    const currentList = document.querySelector('.admin-orders [data-role="orders-list"]');
    const freshList = doc.querySelector('.admin-orders [data-role="orders-list"]');
    if (
      (!currentTbody && freshTbody)
      || (currentTbody && !freshTbody)
      || (!currentList && freshList)
      || (currentList && !freshList)
    ) {
      safeReload();
      return;
    }
    if ((!currentTbody || !freshTbody) && (!currentList || !freshList)) return;
    const freshCatalog = doc.querySelector('#admin-orders-catalog');
    const freshCount = doc.querySelector('#pedidos .admin-section-count');
    if (window.AdminOrdersLiveRefresh && typeof window.AdminOrdersLiveRefresh === 'function') {
      window.AdminOrdersLiveRefresh({
        listHtml: freshList ? freshList.innerHTML : '',
        tbodyHtml: freshTbody ? freshTbody.innerHTML : '',
        catalogJson: freshCatalog ? freshCatalog.textContent : '',
        sectionCountText: freshCount ? freshCount.textContent : '',
      });
    } else {
      if (currentList && freshList) currentList.innerHTML = freshList.innerHTML;
      if (currentTbody && freshTbody) currentTbody.innerHTML = freshTbody.innerHTML;
      if (freshCount) syncTextAndAttrs('#pedidos .admin-section-count', doc);
      try { window.AdminApplyResponsiveTableHeadings && window.AdminApplyResponsiveTableHeadings(); } catch (_) {}
    }
  };

  const refreshReservations = () => {
    if (typeof window.AdminReservasRefresh === 'function') {
      window.AdminReservasRefresh();
    }
  };

  const refreshOnce = async () => {
    if (inFlight || isBusy()) return;
    inFlight = true;
    let timeoutId = null;
    try {
      const controller = typeof AbortController === 'function' ? new AbortController() : null;
      if (controller) timeoutId = window.setTimeout(() => controller.abort(), FETCH_TIMEOUT_MS);
      const response = await fetch(liveUrl().toString(), {
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        signal: controller ? controller.signal : undefined,
      });
      if (!response.ok) throw new Error('HTTP ' + response.status);
      const html = await response.text();
      const doc = new DOMParser().parseFromString(html, 'text/html');
      refreshSummary(doc);
      refreshOrders(doc);
      refreshClients(doc);
      refreshProducts(doc);
      refreshReservations();
      try { window.AdminApplyResponsiveTableHeadings && window.AdminApplyResponsiveTableHeadings(); } catch (_) {}
    } catch (_) {
      // Silent by design: the next interval/focus event will retry.
    } finally {
      if (timeoutId) window.clearTimeout(timeoutId);
      inFlight = false;
    }
  };

  window.setInterval(refreshOnce, INTERVAL_MS);
  window.addEventListener('focus', refreshOnce);
  window.addEventListener('online', refreshOnce);
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') refreshOnce();
  });
})();
