(function adminReservaModal() {
  const modal = document.querySelector('[data-admin-modal="reserva"]');
  if (!modal && document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', adminReservaModal, { once: true });
    return;
  }
  if (!modal) return;
  const modalLoading = window.AdminModalLoading;
  const closeEls = modal.querySelectorAll('[data-admin-reserva-close]');
  const el = (sel) => modal.querySelector(sel);
  const setText = (sel, v) => { const n = el(sel); if (n) n.textContent = String(v || '-'); };
  const normalizeStatusKey = (value) => {
    let status = String(value || '').trim().toLowerCase().replace(/[_-]+/g, ' ');
    status = status.replace(/\s+/g, ' ');
    if (!status || status === 'pending' || status === 'sin confirmar') return 'pendiente';
    if (['confirmed', 'approved', 'aprobado', 'aprobada', 'confirmado', 'confirmada', 'reservado', 'reservada'].includes(status)) return 'aprobado';
    if (['in progress', 'en progreso', 'en curso', 'atendiendo'].includes(status)) return 'en progreso';
    if (['rejected', 'rechazado', 'rechazada', 'no show', 'no asistio'].includes(status)) return 'rechazado';
    if (['cancelled', 'canceled', 'cancelado', 'cancelada'].includes(status)) return 'cancelado';
    if (['completed', 'complete', 'done', 'finalizado', 'finalizada', 'completado', 'completada', 'attended', 'atendido', 'atendida'].includes(status)) return 'finalizado';
    return status;
  };
  const statusClassKey = (value) => normalizeStatusKey(value).replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') || 'pendiente';
  const statusLabel = (value) => {
    const key = normalizeStatusKey(value);
    const labels = {
      pendiente: 'Pendiente',
      aprobado: 'Reservado',
      'en progreso': 'Atendiendo',
      rechazado: 'Rechazado',
      cancelado: 'Cancelado',
      finalizado: 'Finalizado',
    };
    return labels[key] || key.replace(/[_-]+/g, ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
  };
  const updateRowActions = (row, statusValue) => {
    if (!row) return;
    const key = normalizeStatusKey(statusValue);
    const canAttend = ['pendiente', 'aprobado'].includes(key);
    const canFinish = ['pendiente', 'aprobado', 'en progreso'].includes(key);
    const canModify = !['rechazado', 'cancelado', 'finalizado'].includes(key);
    row.querySelectorAll('[data-admin-reserva-quick-status]').forEach((button) => {
      const target = normalizeStatusKey(button.getAttribute('data-admin-reserva-quick-status') || '');
      if (target === 'en progreso') button.disabled = !canAttend;
      if (target === 'finalizado') button.disabled = !canFinish;
    });
    const modify = row.querySelector('[data-admin-view-reserva]');
    if (modify) modify.disabled = !canModify;
  };

  const fill = (data) => {
    setText('[data-admin-res-cliente]', data.cliente);
    setText('[data-admin-res-barbero]', data.barbero);
    setText('[data-admin-res-servicio]', data.servicio);
    setText('[data-admin-res-precio]', data.precio);
    setText('[data-admin-res-fecha]', data.fecha);
    setText('[data-admin-res-hora]', data.hora);
    const stRaw = (data.status || '').toString();
    const st = stRaw.trim();
    const stKey = normalizeStatusKey(st);
    modal.setAttribute('data-admin-res-status-key', stKey);
    const stEl = el('[data-admin-res-status]');
    if (stEl) { stEl.textContent = statusLabel(stKey); stEl.className = 'status-pill st-' + statusClassKey(stKey); }
    const isTerminal = ['cancelado', 'finalizado', 'rechazado'].includes(stKey);
    const canReserve = stKey === 'pendiente';
    const canAttend = ['pendiente', 'aprobado'].includes(stKey);
    const canFinalize = ['pendiente', 'aprobado', 'en progreso'].includes(stKey);
    const btnRech = el('[data-admin-res-rechazar]');
    const btnAten = el('[data-admin-res-atender]');
    const btnApr = el('[data-admin-res-aprobar]');
    const btnFin = el('[data-admin-res-finalizar]');
    if (btnRech) btnRech.disabled = isTerminal;
    if (btnApr) btnApr.disabled = !canReserve;
    if (btnAten) btnAten.disabled = !canAttend;
    if (btnFin) btnFin.disabled = !canFinalize;
    const btnResume = el('[data-admin-res-retomar]');
    if (btnResume) btnResume.hidden = !(stKey === 'en progreso');
  };
  const open = () => {
    try { window.AdminPrepareModalOpen && window.AdminPrepareModalOpen(modal); } catch (_) {}
    modal.hidden = false;
    requestAnimationFrame(() => {
      modal.classList.add('is-visible');
      if (modalLoading) modalLoading.hide(modal);
    });
  };
  const close = () => {
    modal.classList.remove('is-visible');
    modal.hidden = true;
    if (modalLoading) modalLoading.hide(modal);
  };
  closeEls.forEach((x) => x.addEventListener('click', close));

  // Fetch helpers
  const apiBase = (window.AdminApiBase
    ? String(window.AdminApiBase).replace(/\/?$/, '/') + 'Autoload.php'
    : '../../../src/API/Autoload.php');
  const fetchJson = async (url) => {
    const r = await fetch(url, { credentials: 'same-origin', cache: 'no-store' });
    try { return await r.json(); } catch (_) { return null; }
  };

  const isInsideReservationSlot = () => {
    const fecha = modal.getAttribute('data-admin-res-fecha') || '';
    const hora = (modal.getAttribute('data-admin-res-hora') || '').slice(0,5);
    const duration = Math.max(1, parseInt(modal.getAttribute('data-admin-res-duration') || '30', 10) || 30);
    const now = new Date();
    const nowDate = ymd(now);
    if (nowDate !== fecha || !/^\d{2}:\d{2}$/.test(hora)) {
      return false;
    }
    const [hh, mm] = hora.split(':').map((part) => parseInt(part, 10));
    const start = (hh * 60) + mm;
    const current = (now.getHours() * 60) + now.getMinutes();
    return current >= start && current <= (start + duration);
  };

  const startAttendFlow = async () => {
    let proceed = true;
    if (!isInsideReservationSlot()) {
      const wasVisible = !modal.hidden;
      if (wasVisible) close();
      proceed = await adminConfirm({
        title: 'Fuera de horario',
        message: 'Vas a atender este cliente fuera del horario de la reserva, \u00bfDeseas avanzar?',
        confirmText: 'Atender igual',
      });
      if (!proceed && wasVisible) {
        open();
      }
    }
    if (!proceed) return;
    try { if (btnAten) btnAten.disabled = true; if (btnRech) btnRech.disabled = true; if (btnFin) btnFin.disabled = true; } catch (_) {}
    const okUpdate = await actionUpdate('En progreso');
    try { if (btnAten) btnAten.disabled = false; if (btnRech) btnRech.disabled = false; if (btnFin) btnFin.disabled = false; } catch (_) {}
    if (okUpdate) {
      try { window.openServiceFlow && window.openServiceFlow(modal.getAttribute('data-admin-reserva-id')); } catch (_) {}
    }
  };

  document.addEventListener('click', async (evt) => {
    const quickBtn = evt.target && evt.target.closest && evt.target.closest('[data-admin-reserva-quick-status]');
    if (quickBtn) {
      evt.preventDefault();
      if (quickBtn.disabled) return;
      const id = quickBtn.getAttribute('data-admin-reserva-id');
      const status = quickBtn.getAttribute('data-admin-reserva-quick-status');
      if (!id || !status) return;
      quickBtn.disabled = true;
      const ok = await actionUpdate(status, id);
      if (!ok) quickBtn.disabled = false;
      return;
    }
    const btn = evt.target && evt.target.closest && evt.target.closest('[data-admin-view-reserva]');
    if (!btn) return;
    if (btn.disabled) return;
    const id = btn.getAttribute('data-admin-view-reserva');
    if (!id) return;
    if (modalLoading) modalLoading.show(modal);
    try {
      const payload = await fetchJson(`${apiBase}?action=get&table=reservas&id=${encodeURIComponent(id)}`);
      const r = payload && payload.data ? payload.data : null;
      if (!r) {
        adminNotify('No se pudo cargar la reserva', 'error');
        return;
      }
      const [sv, bb, cl] = await Promise.all([
        fetchJson(`${apiBase}?action=list&table=servicios`),
        fetchJson(`${apiBase}?action=list&table=barberos`),
        fetchJson(`${apiBase}?action=list&table=clientes`),
      ]);
      const serviceMap = {};
      (Array.isArray(sv && sv.data) ? sv.data : []).forEach((srv) => {
        const key = String(srv.ID_Servicio);
        serviceMap[key] = srv;
      });
      const barbMap = {};
      (Array.isArray(bb && bb.data) ? bb.data : []).forEach((x) => {
        barbMap[String(x.ID_Barber)] = (x.Nombre || '') + (x.Apellido ? (' ' + x.Apellido) : '');
      });
      const cliMap = {};
      (Array.isArray(cl && cl.data) ? cl.data : []).forEach((x) => {
        cliMap[String(x.ID_Cliente)] = x.Nombre || 'Cliente';
      });
      const parseAmount = (value) => {
        if (value === null || value === undefined || value === '') return null;
        if (typeof value === 'number' && Number.isFinite(value)) return value;
        const cleaned = String(value).replace(/[^0-9,.\-]/g, '').replace(/\.(?=.*\.)/g, '');
        const normalized = cleaned.replace(',', '.');
        const num = Number(normalized);
        return Number.isFinite(num) ? num : null;
      };
      const formatCurrency = (value) => {
        const num = Number(value);
        if (!Number.isFinite(num)) return '-';
        try {
          return new Intl.NumberFormat('es-UY', { style: 'currency', currency: 'UYU' }).format(num);
        } catch (_) {
          return '$ ' + num.toFixed(2);
        }
      };

      const getRowPriceLabel = () => {
        const row = document.querySelector(`[data-admin-res-row-id="${r.ID_Reserva}"]`);
        if (!row) return null;
        const value = row.getAttribute('data-admin-reserva-price');
        return value && value.trim() !== '' ? value.trim() : null;
      };
      const rowPriceLabel = getRowPriceLabel();

      const serviceEntry = serviceMap[String(r.ID_Servicio || '')] || {};
      const data = {
        id: r.ID_Reserva,
        cliente: cliMap[String(r.ID_Cliente||'')] || 'Cliente',
        barbero: barbMap[String(r.ID_Barber||'')] || 'Profesional',
        servicio: serviceEntry.Nombre || serviceEntry.nombre || 'Servicio',
        precio: (() => {
          const parsed = parseAmount(serviceEntry.Precio ?? serviceEntry.precio ?? null);
          if (parsed !== null) {
            return formatCurrency(parsed);
          }
          if (rowPriceLabel) {
            return rowPriceLabel;
          }
          return '-';
        })(),
        fecha: r.Fecha_Reserva || '-',
        hora: String(r.Hora_Reserva || '').slice(0,5),
        status: r.Status || 'Pendiente',
        duration: parseInt(serviceEntry.Duracion ?? serviceEntry.duracion ?? serviceEntry.duracion_min ?? serviceEntry.Duracion_Min ?? 30, 10) || 30,
      };
      modal.setAttribute('data-admin-reserva-id', String(data.id||''));
      modal.setAttribute('data-admin-res-cliente-id', String(r.ID_Cliente || ''));
      modal.setAttribute('data-admin-res-fecha', String(data.fecha||''));
      modal.setAttribute('data-admin-res-hora', String(data.hora||''));
      modal.setAttribute('data-admin-res-duration', String(data.duration || 30));
      const fechaInput = el('[data-admin-res-edit-fecha]');
      const horaInput = el('[data-admin-res-edit-hora]');
      if (fechaInput) fechaInput.value = (data.fecha && /^\d{4}-\d{2}-\d{2}$/.test(data.fecha)) ? data.fecha : '';
      if (horaInput) horaInput.value = (data.hora && data.hora !== '-') ? String(data.hora).slice(0, 5) : '';
      const reprogramWrap = el('[data-admin-res-reprogram-wrap]');
      if (reprogramWrap) {
        const statusKey = normalizeStatusKey(data.status);
        const locked = ['cancelado', 'finalizado', 'rechazado'].includes(statusKey);
        reprogramWrap.hidden = locked;
      }
      fill(data);
      open();
    } catch (_) {
      adminNotify('No se pudo abrir la reserva', 'error');
    } finally {
      if (modalLoading) modalLoading.hide(modal);
    }
  });

  const actionUpdate = async (status, explicitId = null) => {
    const id = explicitId || modal.getAttribute('data-admin-reserva-id'); if (!id) return false;
    const modalMatches = modal.getAttribute('data-admin-reserva-id') === String(id);
    try {
      const res = await fetch(apiBase, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        credentials: 'same-origin',
        cache: 'no-store',
        body: new URLSearchParams({ action: 'update', table: 'reservas', id, data: JSON.stringify({ Status: status }) })
      });
      const payload = await res.json();
      if (res.ok && payload && payload.ok) {
        const nextStatusKey = normalizeStatusKey(status);
        if (modalMatches) {
          modal.setAttribute('data-admin-res-status-key', nextStatusKey);
          const stEl = el('[data-admin-res-status]');
          if (stEl) {
            stEl.textContent = statusLabel(status);
            stEl.className = 'status-pill st-' + statusClassKey(status);
          }
        }
        const row = document.querySelector(`[data-admin-res-row-id="${id}"]`);
        if (row) {
          row.setAttribute('data-admin-reserva-status', nextStatusKey);
          const pill = row.querySelector('.status-pill');
          if (pill) { pill.textContent = statusLabel(status); pill.className = 'status-pill st-' + statusClassKey(status); }
          updateRowActions(row, nextStatusKey);
        }
        if (!explicitId) {
          close();
        } else {
          adminNotify('Reserva marcada como ' + statusLabel(status), 'success');
        }
        try { window.AdminReservasRefresh && window.AdminReservasRefresh(); } catch (_) {}
        return true;
      }
      const msg = (payload && payload.error)
        ? String(payload.error)
        : 'No se pudo actualizar la reserva';
      adminNotify(msg, 'error');
      return false;
    } catch (_) {
      adminNotify('No se pudo actualizar la reserva', 'error');
      return false;
    }
  };

  const actionUpdateSchedule = async () => {
    const id = modal.getAttribute('data-admin-reserva-id');
    if (!id) return;
    const fechaInput = el('[data-admin-res-edit-fecha]');
    const horaInput = el('[data-admin-res-edit-hora]');
    const fecha = fechaInput ? String(fechaInput.value || '').trim() : '';
    let hora = horaInput ? String(horaInput.value || '').trim() : '';
    if (!/^\d{4}-\d{2}-\d{2}$/.test(fecha)) {
      adminNotify('Fecha inválida', 'error');
      return;
    }
    if (!/^\d{2}:\d{2}$/.test(hora)) {
      adminNotify('Hora inválida', 'error');
      return;
    }
    hora = hora + ':00';
    const ok = await adminConfirm({
      title: 'Reprogramar reserva',
      message: '¿Confirmas cambiar la fecha/hora de esta reserva?',
      confirmText: 'Guardar',
    });
    if (!ok) return;
    try {
      const res = await fetch(apiBase, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        credentials: 'same-origin',
        cache: 'no-store',
        body: new URLSearchParams({
          action: 'update',
          table: 'reservas',
          id,
          data: JSON.stringify({ Fecha_Reserva: fecha, Hora_Reserva: hora }),
        }),
      });
      const payload = await res.json();
      if (res.ok && payload && payload.ok) {
        modal.setAttribute('data-admin-res-fecha', fecha);
        modal.setAttribute('data-admin-res-hora', hora.slice(0, 5));
        setText('[data-admin-res-fecha]', fecha);
        setText('[data-admin-res-hora]', hora.slice(0, 5));
        const row = document.querySelector(`[data-admin-res-row-id="${id}"]`);
        if (row) {
          row.setAttribute('data-admin-reserva-fecha', fecha);
          row.setAttribute('data-admin-reserva-hora', hora.slice(0, 5));
          const cells = row.querySelectorAll('td');
          if (cells[4]) cells[4].textContent = fecha;
          if (cells[5]) cells[5].textContent = hora.slice(0, 5);
        }
        adminNotify('Reserva reprogramada', 'success');
        try { window.AdminReservasRefresh && window.AdminReservasRefresh(); } catch (_) {}
      } else {
        adminNotify('No se pudo reprogramar', 'error');
      }
    } catch (_) {
      adminNotify('No se pudo reprogramar', 'error');
    }
  };

  el('[data-admin-res-guardar-fecha]')?.addEventListener('click', () => {
    actionUpdateSchedule();
  });

  const btnRech = el('[data-admin-res-rechazar]');
  const btnAten = el('[data-admin-res-atender]');
  const btnApr = el('[data-admin-res-aprobar]');
  const btnFin = el('[data-admin-res-finalizar]');
  btnApr && btnApr.addEventListener('click', async () => {
    try { btnApr.disabled = true; } catch (_) {}
    await actionUpdate('Aprobado');
    try { btnApr.disabled = false; } catch (_) {}
  });

  btnFin && btnFin.addEventListener('click', async () => {
    const id = modal.getAttribute('data-admin-reserva-id');
    if (!id) return;
    try { btnFin.disabled = true; } catch (_) {}
    await actionUpdate('Finalizado');
    try { btnFin.disabled = false; } catch (_) {}
  });

  btnRech && btnRech.addEventListener('click', async () => {
    const wasVisible = !modal.hidden;
    if (wasVisible) close();
    const ok = await adminConfirm({
      title: 'Rechazar reserva',
      message: '\u00bfSeguro que deseas rechazar esta reserva? Esta accion no se puede deshacer.',
      confirmText: 'Rechazar',
    });
    if (!ok) {
      if (wasVisible) open();
      return;
    }
    try { btnRech.disabled = true; if (btnAten) btnAten.disabled = true; } catch (_) {}
    await actionUpdate('Cancelado');
    try { btnRech.disabled = false; if (btnAten) btnAten.disabled = false; } catch (_) {}
  });

  const fmt2 = (n) => String(n).padStart(2,'0');
  const ymd = (d) => `${d.getFullYear()}-${fmt2(d.getMonth()+1)}-${fmt2(d.getDate())}`;
  const hm = (d) => `${fmt2(d.getHours())}:${fmt2(d.getMinutes())}`;
  btnAten && btnAten.addEventListener('click', startAttendFlow);

  // Retomar desde modal de reserva (si esta en progreso)
  el('[data-admin-res-retomar]')?.addEventListener('click', () => {
    const id = modal.getAttribute('data-admin-reserva-id');
    if (!id) return;
    close();
    window.openServiceFlow && window.openServiceFlow(id);
  });

})();

