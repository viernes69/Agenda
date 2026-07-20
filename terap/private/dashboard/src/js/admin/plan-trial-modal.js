(function planStatusModalsPersistent() {
  const banner = document.querySelector('[data-plan-banner]');
  if (!banner) return;

  const status = (banner.getAttribute('data-plan-status') || '').trim().toLowerCase();
  const lockedModals = [];

  const openLockedModal = (modal) => {
    if (!modal) return;
    lockedModals.push(modal);
    modal.hidden = false;
    requestAnimationFrame(() => {
      modal.classList.add('is-visible');
      const focusable = modal.querySelector('a, button, input, select, textarea, [tabindex]:not([tabindex="-1"])');
      if (focusable && typeof focusable.focus === 'function') {
        focusable.focus();
      }
    });

    modal.addEventListener('click', (event) => {
      const dialog = modal.querySelector('.modal__dialog');
      if (!dialog) return;
      if (!dialog.contains(event.target)) {
        event.stopPropagation();
        event.preventDefault();
      }
    }, { capture: true });
  };

  const preventDismiss = (event) => {
    if (event.key === 'Escape') {
      event.preventDefault();
    }
  };
  document.addEventListener('keydown', preventDismiss, true);

  if (status === 'prueba') {
    const daysAttr = banner.getAttribute('data-plan-days') || '';
    const daysRemaining = daysAttr !== '' && !Number.isNaN(Number(daysAttr)) ? Number(daysAttr) : null;
    if (daysRemaining !== null && daysRemaining <= 0) {
      const trialModal = document.querySelector('[data-plan-expired-modal]');
      openLockedModal(trialModal);
    }
  } else if (status === 'cancelado') {
    const cancelModal = document.querySelector('[data-plan-cancel-modal]');
    openLockedModal(cancelModal);
  }

  if (lockedModals.length === 0) {
    document.removeEventListener('keydown', preventDismiss, true);
  }
})();
