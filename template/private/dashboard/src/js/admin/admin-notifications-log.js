(function adminNotificationsLogModal() {
  const modal = document.querySelector('[data-admin-modal="notifications-log"]');
  if (!modal) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', adminNotificationsLogModal, { once: true });
    }
    return;
  }

  const apiBase = '../../../src/api/notifications_log.php';
  const tbody = modal.querySelector('[data-admin-notif-log-tbody]');
  const emptyEl = modal.querySelector('[data-admin-notif-log-empty]');
  const tableWrap = modal.querySelector('[data-admin-notif-log-table-wrap]');
  const pagination = modal.querySelector('[data-admin-notif-log-pagination]');
  const prevBtn = modal.querySelector('[data-admin-notif-log-prev]');
  const nextBtn = modal.querySelector('[data-admin-notif-log-next]');
  const pageInfo = modal.querySelector('[data-admin-notif-log-page-info]');
  const applyBtn = modal.querySelector('[data-admin-notif-log-apply]');
  const clearBtn = modal.querySelector('[data-admin-notif-log-clear]');
  const channelSelect = modal.querySelector('[data-admin-notif-log-channel]');
  const statusSelect = modal.querySelector('[data-admin-notif-log-status]');
  const dateFromInput = modal.querySelector('[data-admin-notif-log-date-from]');
  const dateToInput = modal.querySelector('[data-admin-notif-log-date-to]');
  const closeEls = modal.querySelectorAll('[data-admin-notif-log-close]');

  const statTotal = modal.querySelector('[data-notif-stat-total]');
  const statSent = modal.querySelector('[data-notif-stat-sent]');
  const statFailed = modal.querySelector('[data-notif-stat-failed]');
  const statEmail = modal.querySelector('[data-notif-stat-email]');
  const statWa = modal.querySelector('[data-notif-stat-wa]');

  let currentPage = 1;
  let loading = false;

  const notify = (message, type) => {
    if (typeof window.adminNotify === 'function') {
      window.adminNotify(message, type || 'success');
    } else {
      console.log('[NOTIFY]', message);
    }
  };

  const buildParams = () => {
    const params = new URLSearchParams();
    params.set('page', String(currentPage));
    if (channelSelect && channelSelect.value) params.set('channel', channelSelect.value);
    if (statusSelect && statusSelect.value) params.set('status', statusSelect.value);
    if (dateFromInput && dateFromInput.value) params.set('date_from', dateFromInput.value);
    if (dateToInput && dateToInput.value) params.set('date_to', dateToInput.value);
    return params.toString();
  };

  const updateStats = (stats) => {
    if (statTotal) statTotal.textContent = String(stats.total || 0);
    if (statSent) statSent.textContent = String(stats.sent || 0);
    if (statFailed) statFailed.textContent = String(stats.failed || 0);
    if (statEmail) statEmail.textContent = String(stats.email || 0);
    if (statWa) statWa.textContent = String(stats.whatsapp || 0);
  };

  const updatePagination = (page, totalPages, total) => {
    if (!pagination) return;
    if (totalPages <= 1) {
      pagination.hidden = true;
      return;
    }
    pagination.hidden = false;
    if (pageInfo) pageInfo.textContent = 'Página ' + page + ' de ' + totalPages + ' (' + total + ' total)';
    if (prevBtn) prevBtn.disabled = page <= 1;
    if (nextBtn) nextBtn.disabled = page >= totalPages;
  };

  const load = async () => {
    if (loading) return;
    loading = true;

    if (emptyEl) {
      emptyEl.hidden = false;
      emptyEl.textContent = 'Cargando...';
    }
    if (tableWrap) tableWrap.style.opacity = '0.5';

    try {
      const res = await fetch(apiBase + '?' + buildParams());
      const json = await res.json().catch(() => null);
      if (!res.ok || !json || !json.ok) {
        throw new Error(json && json.error ? json.error : 'Error al cargar notificaciones');
      }

      if (tbody) tbody.innerHTML = json.html || '';
      updateStats(json.stats || {});
      updatePagination(json.page || 1, json.totalPages || 1, json.total || 0);

      if (emptyEl) {
        if (json.total === 0) {
          emptyEl.hidden = false;
          emptyEl.textContent = json.emptyMessage || 'No hay notificaciones.';
        } else {
          emptyEl.hidden = true;
        }
      }
    } catch (err) {
      const msg = err && err.message ? err.message : 'Error al cargar notificaciones';
      if (emptyEl) {
        emptyEl.hidden = false;
        emptyEl.textContent = msg;
      }
      notify(msg, 'error');
    } finally {
      loading = false;
      if (tableWrap) tableWrap.style.opacity = '1';
    }
  };

  const close = () => {
    modal.classList.remove('is-visible');
    modal.hidden = true;
  };

  const open = () => {
    currentPage = 1;
    modal.hidden = false;
    requestAnimationFrame(() => {
      modal.classList.add('is-visible');
      load();
    });
  };

  closeEls.forEach((btn) => btn.addEventListener('click', close));

  if (applyBtn) applyBtn.addEventListener('click', () => { currentPage = 1; load(); });

  if (clearBtn) clearBtn.addEventListener('click', () => {
    if (channelSelect) channelSelect.value = '';
    if (statusSelect) statusSelect.value = '';
    if (dateFromInput) dateFromInput.value = '';
    if (dateToInput) dateToInput.value = '';
    currentPage = 1;
    load();
  });

  if (prevBtn) prevBtn.addEventListener('click', () => {
    if (currentPage > 1) { currentPage--; load(); }
  });
  if (nextBtn) nextBtn.addEventListener('click', () => {
    currentPage++;
    load();
  });

  window.AdminNotificationsLogModal = { open, close };
})();
