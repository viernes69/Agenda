(function adminService() {
  const serviceModal = document.querySelector('[data-admin-modal="service"]');
  if (!serviceModal && document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', adminService, { once: true });
    return;
  }
  if (!serviceModal) return;
  const modalLoading = window.AdminModalLoading;
  const finishBtn = serviceModal.querySelector('[data-service-finish]');
  const closeEls = serviceModal.querySelectorAll('[data-admin-service-close]');
  const apiBase = (window.AdminApiBase
    ? String(window.AdminApiBase).replace(/\/?$/, '/') + 'Autoload.php'
    : '../../../src/API/Autoload.php');

  const open = () => {
    try { window.AdminPrepareModalOpen && window.AdminPrepareModalOpen(serviceModal); } catch (_) {}
    serviceModal.hidden = false;
    requestAnimationFrame(() => {
      serviceModal.classList.add('is-visible');
      if (modalLoading) modalLoading.hide(serviceModal);
    });
  };
  const close = () => {
    serviceModal.classList.remove('is-visible');
    serviceModal.hidden = true;
    if (modalLoading) modalLoading.hide(serviceModal);
  };

  window.openServiceFlow = async (resId) => {
    if (modalLoading) modalLoading.show(serviceModal);
    open();
  };

  closeEls.forEach((x) => x.addEventListener('click', () => { close(); }));

  finishBtn && finishBtn.addEventListener('click', async () => {
    try {
      const reservaModal = document.querySelector('[data-admin-modal="reserva"]');
      const id = reservaModal && reservaModal.getAttribute('data-admin-reserva-id');
      if (!id) { close(); return; }
      const res = await fetch(apiBase, {
        method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        credentials: 'same-origin',
        body: new URLSearchParams({ action: 'update', table: 'reservas', id, data: JSON.stringify({ Status: 'Finalizado' }) })
      });
      const payload = await res.json();
      if (res.ok && payload && payload.ok) {
        const row = document.querySelector(`[data-admin-res-row-id="${id}"]`);
        if (row) {
          row.setAttribute('data-admin-reserva-status', 'finalizado');
          const pill = row.querySelector('.status-pill');
          if (pill) { pill.textContent = 'Finalizado'; pill.className = 'status-pill st-finalizado'; }
        }
        try {
          const reservaModal = document.querySelector('[data-admin-modal="reserva"]');
          if (reservaModal && !reservaModal.hidden) {
            const stPill = reservaModal.querySelector('[data-admin-res-status]');
            if (stPill) { stPill.textContent = 'Finalizado'; stPill.className = 'status-pill st-finalizado'; }
            const btnResume = reservaModal.querySelector('[data-admin-res-retomar]');
            if (btnResume) btnResume.hidden = true;
            const btnRech = reservaModal.querySelector('[data-admin-res-rechazar]');
            const btnAten = reservaModal.querySelector('[data-admin-res-atender]');
            if (btnRech) btnRech.disabled = true;
            if (btnAten) btnAten.disabled = true;
          }
        } catch (_) {}
        adminNotify('Servicio finalizado', 'success');
        try { window.AdminReservasRefresh && window.AdminReservasRefresh(); } catch (_) {}
      } else {
        const msg = (payload && payload.error)
          ? String(payload.error)
          : 'No se pudo finalizar la reserva';
        adminNotify(msg, 'error');
      }
    } catch (_) { adminNotify('No se pudo finalizar la reserva', 'error'); }
    finally { close(); }
  });
})();
