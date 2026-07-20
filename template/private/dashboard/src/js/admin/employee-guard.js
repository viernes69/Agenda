(function employeeAccessGuard() {
  const root = document.body || document.documentElement;
  if (!root) return;

  const role = String(root.dataset.employeeRole || window.ADMIN_EMPLOYEE_ROLE || '').trim().toLowerCase();
  if (role !== 'func') return;

  root.classList.add('employee-role-func');

  const allowedSections = new Set(['resumen', 'reservas']);
  const guardModal = document.querySelector('[data-admin-modal="employee-guard"]');
  const messageEl = guardModal ? guardModal.querySelector('[data-employee-guard-message]') : null;
  const closeEls = guardModal ? guardModal.querySelectorAll('[data-employee-guard-close]') : [];
  const baseMessage = 'Por Favor acceda con el usuario del due\u00f1o del negocio para poder acceder a esta secci\u00f3n.';

  const closeGuard = () => {
    if (!guardModal || guardModal.hidden) return;
    guardModal.classList.remove('is-visible');
    guardModal.hidden = true;
  };

  const showGuard = (label) => {
    const sectionLabel = label && label.trim() ? label.trim() : '';
    const composedMessage = sectionLabel ? `${baseMessage} (${sectionLabel})` : baseMessage;
    if (!guardModal) {
      window.alert(composedMessage);
      return;
    }
    if (messageEl) {
      messageEl.textContent = composedMessage;
    }
    guardModal.hidden = false;
    requestAnimationFrame(() => {
      guardModal.classList.add('is-visible');
    });
  };

  closeEls.forEach((btn) => btn.addEventListener('click', closeGuard));

  const bindGuard = (element, label) => {
    if (!element || element.__employeeGuardBound) return;
    element.__employeeGuardBound = true;
    element.addEventListener('click', (event) => {
      event.preventDefault();
      event.stopPropagation();
      showGuard(label || element.textContent || '');
    });
  };

  document.querySelectorAll('[data-admin-nav-target]').forEach((navItem) => {
    const target = (navItem.getAttribute('data-admin-nav-target') || '').toLowerCase();
    if (!allowedSections.has(target)) {
      navItem.classList.add('is-locked');
      navItem.setAttribute('aria-disabled', 'true');
      navItem.setAttribute('tabindex', '-1');
      bindGuard(navItem);
    }
  });

  document.querySelectorAll('.admin-section').forEach((section) => {
    const sectionId = (section.id || '').toLowerCase();
    if (sectionId && !allowedSections.has(sectionId)) {
      section.classList.add('is-locked-section');
      section.setAttribute('hidden', '');
    }
  });

  document.addEventListener('click', (event) => {
    const anchor = event.target && event.target.closest && event.target.closest('a[href^="#"]');
    if (!anchor) return;
    const href = (anchor.getAttribute('href') || '').trim();
    if (!href || href === '#') return;
    const targetId = href.replace(/^#/, '').toLowerCase();
    if (targetId && !allowedSections.has(targetId)) {
      event.preventDefault();
      event.stopPropagation();
      showGuard(anchor.textContent || '');
    }
  }, true);

  document.addEventListener('click', (event) => {
    const configCard = event.target && event.target.closest && event.target.closest('[data-admin-config-item]');
    if (!configCard) return;
    event.preventDefault();
    event.stopPropagation();
    const label = configCard.querySelector('.admin-config-label');
    showGuard(label ? label.textContent : 'Configuraci\u00f3n');
  }, true);

  const guardableModals = {
    AdminConfigInfoModal: 'Info del negocio',
    AdminConfigMonedaModal: 'Configuraci\u00f3n de moneda',
    AdminConfigHoursModal: 'Horarios',
    AdminConfigReservasModal: 'Configuraci\u00f3n de reservas',
    AdminConfigFiscalModal: 'Configuraci\u00f3n fiscal',
    AdminConfigMercadoPagoModal: 'Mercado Pago',
    AdminConfigSeoModal: 'SEO',
    AdminConfigRedesModal: 'Redes',
    AdminConfigLegalesModal: 'Config. legal',
    AdminConfigNotificacionesModal: 'Notificaciones',
    AdminConfigFeaturesModal: 'Funciones',
    AdminConfigThemeModal: 'Tema visual'
  };

  const protectModalApi = (globalKey, label) => {
    const api = window[globalKey];
    if (!api || typeof api.open !== 'function' || api.__employeeGuardWrapped) {
      return;
    }
    api.__employeeGuardWrapped = true;
    api.open = () => showGuard(label);
  };

  Object.entries(guardableModals).forEach(([key, label]) => {
    protectModalApi(key, label);
  });

  window.EmployeeAccessGuard = { show: showGuard };
})();
