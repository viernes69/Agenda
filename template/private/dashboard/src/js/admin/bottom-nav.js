(function adminSectionNavigation() {
  'use strict';

  const sections = Array.from(document.querySelectorAll('.admin-main .admin-section'));
  if (!sections.length) return;

  const normalizeSectionId = (value) => String(value || '').replace(/^#/, '').trim().toLowerCase();
  const defaultSectionId = sections[0].id || 'resumen';

  const bottomNav = document.querySelector('.admin-bottomnav');
  const asideNav = document.querySelector('.admin-nav');

  const bottomItems = bottomNav ? Array.from(bottomNav.querySelectorAll('[data-admin-nav-target]')) : [];
  const asideItems = asideNav ? Array.from(asideNav.querySelectorAll('a[href^="#"]')) : [];
  const summaryLinks = Array.from(document.querySelectorAll('a.summary-card__cta[href^="#"]'));

  const bindNavTarget = (link) => {
    const targetId = normalizeSectionId(link.getAttribute('href') || link.dataset.adminNavTarget || '');
    if (!targetId) return;
    link.dataset.adminNavTarget = targetId;
    if (link.tagName === 'A') {
      link.setAttribute('href', `#${targetId}`);
    }
  };

  bottomItems.forEach((item) => {
    const targetId = normalizeSectionId(item.dataset.adminNavTarget || item.getAttribute('href') || '');
    if (targetId) item.dataset.adminNavTarget = targetId;
  });
  asideItems.forEach(bindNavTarget);
  summaryLinks.forEach(bindNavTarget);

  const allNavItems = [...bottomItems, ...asideItems, ...summaryLinks];

  const setNavActive = (id) => {
    const activeId = normalizeSectionId(id);
    allNavItems.forEach((item) => {
      const target = normalizeSectionId(item.dataset.adminNavTarget);
      const isMatch = target === activeId;
      item.classList.toggle('is-active', isMatch);
      if (isMatch) {
        item.setAttribute('aria-current', 'page');
      } else {
        item.removeAttribute('aria-current');
      }
    });
  };

  const showSection = (id, { scroll = false } = {}) => {
    const targetId = normalizeSectionId(id);
    let matchedId = '';
    sections.forEach((section) => {
      const match = normalizeSectionId(section.id) === targetId;
      section.hidden = !match;
      section.classList.toggle('is-active', match);
      if (match) {
        matchedId = section.id;
        if (scroll) {
          section.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
      }
    });
    return matchedId;
  };

  const activate = (id, options) => {
    const shownId = showSection(id, options);
    const validId = shownId || defaultSectionId;
    if (!shownId) {
      showSection(validId, options);
    }
    setNavActive(validId);
    const expectedHash = `#${normalizeSectionId(validId)}`;
    if (window.location.hash.toLowerCase() !== expectedHash) {
      history.replaceState(null, '', expectedHash);
    }
  };

  const initialHash = normalizeSectionId(window.location.hash);
  activate(initialHash || defaultSectionId);

  const optimizeEndpoint = '../optimizar.php';
  let isOptimizing = false;
  const runOptimization = async () => {
    if (isOptimizing) return true;
    isOptimizing = true;
    try {
      const controller = new AbortController();
      const timeoutId = setTimeout(() => controller.abort(), 15000);
      const response = await fetch(optimizeEndpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ optimize: true }),
        credentials: 'same-origin',
        signal: controller.signal,
      });
      clearTimeout(timeoutId);
      const payload = await response.json().catch(() => ({}));
      if (!response.ok || !payload || payload.ok !== true) {
        throw new Error(payload && payload.error ? payload.error : 'No se pudo optimizar la base de datos.');
      }
      const reservasRemoved = payload.removed?.reservas?.removed ?? 0;
      const carritoRemoved  = payload.removed?.carrito?.removed ?? 0;
      const totalRemoved = reservasRemoved + carritoRemoved;
      const message = totalRemoved > 0
        ? `Optimización completada. Registros eliminados: ${totalRemoved}.`
        : 'Base de datos ya optimizada (sin registros antiguos).';
      if (typeof window.AdminNotify === 'function') {
        window.AdminNotify(message, 'success');
      } else {
        console.log('[OPTIMIZAR]', message);
      }
      return true;
    } catch (error) {
      if (typeof window.AdminNotify === 'function') {
        window.AdminNotify(error?.message || 'Error al optimizar la base de datos.', 'error');
      } else {
        console.error('[OPTIMIZAR] Error', error);
      }
      return false;
    } finally {
      isOptimizing = false;
    }
  };

  if (allNavItems.length) {
    allNavItems.forEach((item) => {
      item.addEventListener('click', async (event) => {
        const id = normalizeSectionId(item.dataset.adminNavTarget);
        if (!id) return;
        event.preventDefault();
        if (id === 'config') {
          await runOptimization();
        }
        if (typeof window.AdminReservasRefresh === 'function') {
          window.AdminReservasRefresh();
        }
        activate(id, { scroll: true });
      });
    });
  }

  window.addEventListener('hashchange', () => {
    const id = normalizeSectionId(window.location.hash);
    if (!id) {
      activate(defaultSectionId);
      return;
    }
    activate(id, { scroll: true });
  });

  const tenantUrls = () => {
    const config = window.__TENANT_CONFIG__ || {};
    const slug = String(config.slug || document.querySelector('meta[name="tenant-slug"]')?.content || '').trim();
    const configuredBase = String(config.basePath || document.querySelector('meta[name="url-base"]')?.content || '/');
    const baseUrl = new URL(configuredBase, window.location.origin);
    let tenantPath = `/${baseUrl.pathname.split('/').filter(Boolean).join('/')}/`;
    if (slug && !tenantPath.endsWith(`/${slug}/`)) {
      tenantPath += `${slug}/`;
    }
    const platformPath = slug && tenantPath.endsWith(`/${slug}/`)
      ? tenantPath.slice(0, -`${slug}/`.length)
      : tenantPath;
    return {
      logout: new URL(`${platformPath}admin/logout.php`, window.location.origin).href,
      public: new URL(tenantPath, window.location.origin).href,
    };
  };

  const logoutBtn = bottomNav ? bottomNav.querySelector('[data-admin-logout]') : null;
  if (logoutBtn) {
    logoutBtn.addEventListener('click', async () => {
      if (logoutBtn.disabled) return;
      const wantsLogout = await adminConfirm({
        title: 'Cerrar sesion',
        message: '\u00bfCerrar sesion y volver al inicio?',
        confirmText: 'Cerrar sesion',
      });
      if (!wantsLogout) return;
      logoutBtn.disabled = true;
      const urls = tenantUrls();
      try {
        await fetch(urls.logout, { method: 'GET', credentials: 'same-origin' });
      } catch (_) {
        // Ignorar errores de red del logout; igual redirigir
      }
      window.location.href = urls.public;
    });
  }
})();
