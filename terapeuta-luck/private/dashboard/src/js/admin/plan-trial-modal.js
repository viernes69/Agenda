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

  // Handler para el botón "Me quedo con el Plan Free" dentro del modal bloqueado
  const expiredModal = document.querySelector('[data-plan-expired-modal]');
  if (expiredModal) {
    expiredModal.addEventListener('submit', async (event) => {
      const form = event.target;
      if (!(form instanceof HTMLFormElement)) return;
      if (!form.matches('[data-plan-membership-form]')) return;
      event.preventDefault();

      const submitBtn = form.querySelector('button[type="submit"]');
      const endpoint = form.getAttribute('action') || '';
      if (!endpoint) return;

      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.textContent = 'Activando plan gratis…';
      }

      try {
        const body = new FormData(form);
        const response = await fetch(endpoint, {
          method: 'POST',
          body,
          credentials: 'same-origin',
          headers: { Accept: 'application/json' },
        });
        let payload = null;
        try { payload = await response.json(); } catch (_) { payload = null; }

        if (!response.ok || !payload || payload.ok !== true) {
          const errMsg = (payload && payload.error) ? String(payload.error) : 'No se pudo activar el plan. Intentá de nuevo.';
          if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Me quedo con el Plan Free (sin costo)';
          }
          alert(errMsg);
          return;
        }

        if (submitBtn) submitBtn.textContent = '✓ Plan activado. Recargando…';
        window.setTimeout(() => { window.location.reload(); }, 600);
      } catch (_) {
        if (submitBtn) {
          submitBtn.disabled = false;
          submitBtn.textContent = 'Me quedo con el Plan Free (sin costo)';
        }
        alert('Error de red. Intentá de nuevo.');
      }
    });
  }
})();
