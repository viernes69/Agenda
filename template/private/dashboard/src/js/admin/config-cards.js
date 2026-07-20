(function adminConfigCards() {
  const grid = document.querySelector('[data-admin-config-grid]');
  if (!grid) return;

  let activeCard = null;
  const basicAllowed = new Set(['info', 'redes', 'horarios', 'reservas']);
  const settingsTier = String(grid.getAttribute('data-settings-tier') || 'full').toLowerCase();
  const isBasicOnly = settingsTier === 'basic';

  const openWithGuard = (_sectionLabel, callback) => {
    if (typeof callback === 'function') callback();
  };

  const denyBasic = () => {
    const msg = 'Tu plan no permite realizar esta acción. Mejorá tu membresía para continuar.';
    if (typeof window.adminNotify === 'function') {
      window.adminNotify(msg, 'error');
    } else {
      window.alert(msg);
    }
  };

  grid.addEventListener('click', (evt) => {
    const card = evt.target && evt.target.closest && evt.target.closest('[data-admin-config-item]');
    if (!card || !grid.contains(card)) return;

    if (activeCard === card) {
      card.classList.remove('is-active');
      activeCard = null;
      return;
    }

    if (activeCard) activeCard.classList.remove('is-active');
    card.classList.add('is-active');
    activeCard = card;

    const configId = card.getAttribute('data-admin-config-id');
    if (isBasicOnly && configId && !basicAllowed.has(configId)) {
      denyBasic();
      return;
    }

    if (configId === 'info' && window.AdminConfigInfoModal && typeof window.AdminConfigInfoModal.open === 'function') {
      window.AdminConfigInfoModal.open();
    } else if (configId === 'moneda' && window.AdminConfigMonedaModal && typeof window.AdminConfigMonedaModal.open === 'function') {
      openWithGuard('Configuración de Moneda', window.AdminConfigMonedaModal.open);
    } else if (configId === 'horarios' && window.AdminConfigHoursModal && typeof window.AdminConfigHoursModal.open === 'function') {
      window.AdminConfigHoursModal.open();
    } else if (configId === 'reservas' && window.AdminConfigReservasModal && typeof window.AdminConfigReservasModal.open === 'function') {
      window.AdminConfigReservasModal.open();
    } else if (configId === 'fiscal' && window.AdminConfigFiscalModal && typeof window.AdminConfigFiscalModal.open === 'function') {
      openWithGuard('Configuración Fiscal', window.AdminConfigFiscalModal.open);
    } else if (configId === 'mercadopago' && window.AdminConfigMercadoPagoModal && typeof window.AdminConfigMercadoPagoModal.open === 'function') {
      openWithGuard('Mercado Pago', window.AdminConfigMercadoPagoModal.open);
    } else if (configId === 'seo' && window.AdminConfigSeoModal && typeof window.AdminConfigSeoModal.open === 'function') {
      window.AdminConfigSeoModal.open();
    } else if (configId === 'redes' && window.AdminConfigRedesModal && typeof window.AdminConfigRedesModal.open === 'function') {
      window.AdminConfigRedesModal.open();
    } else if (configId === 'legal') {
      if (window.AdminConfigLegalesModal && typeof window.AdminConfigLegalesModal.open === 'function') {
        openWithGuard('Config. Legal', window.AdminConfigLegalesModal.open);
      } else {
        const legalModal = document.querySelector('[data-admin-modal="config-legales"]');
        if (legalModal) {
          legalModal.hidden = false;
          requestAnimationFrame(() => legalModal.classList.add('is-visible'));
        } else if (typeof window.adminNotify === 'function') {
          window.adminNotify('No se pudo abrir Config. Legal. Recargá la página.', 'error');
        }
      }
    } else if (configId === 'notificaciones' && window.AdminConfigNotificacionesModal && typeof window.AdminConfigNotificacionesModal.open === 'function') {
      window.AdminConfigNotificacionesModal.open();
    } else if (configId === 'email_plantillas' && window.AdminConfigEmailTemplatesModal && typeof window.AdminConfigEmailTemplatesModal.open === 'function') {
      window.AdminConfigEmailTemplatesModal.open();
    } else if (configId === 'funciones' && window.AdminConfigFeaturesModal && typeof window.AdminConfigFeaturesModal.open === 'function') {
      openWithGuard('Funciones', window.AdminConfigFeaturesModal.open);
    } else if (configId === 'temas' && window.AdminConfigThemeModal && typeof window.AdminConfigThemeModal.open === 'function') {
      window.AdminConfigThemeModal.open();
    }
  });
})();
