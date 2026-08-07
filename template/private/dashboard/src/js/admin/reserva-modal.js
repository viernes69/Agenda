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
    if (['confirmed', 'approved', 'aprobado', 'aprobada', 'confirmado', 'confirmada'].includes(status)) return 'aprobado';
    if (['in progress', 'en progreso', 'en curso', 'atendiendo'].includes(status)) return 'en progreso';
    if (['rejected', 'rechazado', 'rechazada', 'no show', 'no asistio'].includes(status)) return 'rechazado';
    if (['cancelled', 'canceled', 'cancelado', 'cancelada'].includes(status)) return 'cancelado';
    if (['completed', 'complete', 'done', 'finalizado', 'finalizada', 'completado', 'completada'].includes(status)) return 'finalizado';
    return status;
  };
  const statusClassKey = (value) => normalizeStatusKey(value).replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') || 'pendiente';
  const statusLabel = (value) => {
    const key = normalizeStatusKey(value);
    const labels = {
      pendiente: 'Pendiente',
      aprobado: 'Aprobado',
      'en progreso': 'En progreso',
      rechazado: 'Rechazado',
      cancelado: 'Cancelado',
      finalizado: 'Finalizado',
    };
    return labels[key] || key.replace(/[_-]+/g, ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
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
    const stEl = el('[data-admin-res-status]');
    if (stEl) { stEl.textContent = st || statusLabel(stKey); stEl.className = 'status-pill st-' + statusClassKey(stKey); }
    const isPending = stKey === 'pendiente';
    const btnRech = el('[data-admin-res-rechazar]');
    const btnAten = el('[data-admin-res-atender]');
    if (btnRech) btnRech.disabled = !isPending;
    if (btnAten) btnAten.disabled = !isPending;
    // Retomar atencion cuando esta aprobado
    const btnResume = el('[data-admin-res-retomar]');
    if (btnResume) btnResume.hidden = !(stKey === 'aprobado');
  };
  const open = () => {
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
  const apiBase = '../../../src/API/Autoload.php';
  const fetchJson = async (url) => { const r = await fetch(url); try { return await r.json(); } catch (_) { return null; } };

  document.addEventListener('click', async (evt) => {
    const btn = evt.target && evt.target.closest && evt.target.closest('[data-admin-view-reserva]');
    if (!btn) return;
    const id = btn.getAttribute('data-admin-view-reserva');
    if (!id) return;
    if (modalLoading) modalLoading.show(modal);
    try {
      const payload = await fetchJson(`${apiBase}?action=get&table=reservas&id=${encodeURIComponent(id)}`);
      const r = payload && payload.data ? payload.data : null;
      if (!r) return;
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

      const data = {
        id: r.ID_Reserva,
        cliente: cliMap[String(r.ID_Cliente||'')] || 'Cliente',
        barbero: barbMap[String(r.ID_Barber||'')] || 'Profesional',
        servicio: (serviceMap[String(r.ID_Servicio||'')] && serviceMap[String(r.ID_Servicio||'')].Nombre) || 'Servicio',
        precio: (() => {
          const entry = serviceMap[String(r.ID_Servicio || '')] || {};
          const parsed = parseAmount(entry.Precio ?? entry.precio ?? null);
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
      };
      modal.setAttribute('data-admin-reserva-id', String(data.id||''));
      modal.setAttribute('data-admin-res-cliente-id', String(r.ID_Cliente || ''));
      modal.setAttribute('data-admin-res-fecha', String(data.fecha||''));
      modal.setAttribute('data-admin-res-hora', String(data.hora||''));
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
      // ignore
    } finally {
      if (modalLoading) modalLoading.hide(modal);
    }
  });

  const actionUpdate = async (status) => {
    const id = modal.getAttribute('data-admin-reserva-id'); if (!id) return false;
    try {
      const res = await fetch(apiBase, {
        method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ action: 'update', table: 'reservas', id, data: JSON.stringify({ Status: status }) })
      });
      const payload = await res.json();
      if (res.ok && payload && payload.ok) {
        const row = document.querySelector(`[data-admin-res-row-id="${id}"]`);
        if (row) {
          row.setAttribute('data-admin-reserva-status', normalizeStatusKey(status));
          const pill = row.querySelector('.status-pill');
          if (pill) { pill.textContent = statusLabel(status); pill.className = 'status-pill st-' + statusClassKey(status); }
        }
        close();
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
  btnRech && btnRech.addEventListener('click', async () => {
    const ok = await adminConfirm({
      title: 'Rechazar reserva',
      message: '\u00bfSeguro que deseas rechazar esta reserva? Esta accion no se puede deshacer.',
      confirmText: 'Rechazar',
    });
    if (!ok) return;
    try { btnRech.disabled = true; if (btnAten) btnAten.disabled = true; } catch (_) {}
    await actionUpdate('Cancelado');
    try { btnRech.disabled = false; if (btnAten) btnAten.disabled = false; } catch (_) {}
  });

  const fmt2 = (n) => String(n).padStart(2,'0');
  const ymd = (d) => `${d.getFullYear()}-${fmt2(d.getMonth()+1)}-${fmt2(d.getDate())}`;
  const hm = (d) => `${fmt2(d.getHours())}:${fmt2(d.getMinutes())}`;
  btnAten && btnAten.addEventListener('click', async () => {
    const ok = await adminConfirm({
      title: 'Atender reserva',
      message: '\u00bfConfirmas que vas a atender esta reserva ahora?',
      confirmText: 'Atender',
    });
    if (!ok) return;
    const fecha = modal.getAttribute('data-admin-res-fecha') || '';
    const hora = (modal.getAttribute('data-admin-res-hora') || '').slice(0,5);
    const now = new Date();
    const nowDate = ymd(now);
    const nowTime = hm(now);
    let proceed = true;
    if (!(nowDate === fecha && nowTime === hora)) {
      proceed = await adminConfirm({
        title: 'Fuera de horario',
        message: 'Vas a atender este cliente fuera del horario de la reserva, \u00bfDeseas avanzar?',
        confirmText: 'Atender igual',
      });
    }
    if (!proceed) return;
    try { btnAten.disabled = true; if (btnRech) btnRech.disabled = true; } catch (_) {}
    const okUpdate = await actionUpdate('Aprobado');
    try { btnAten.disabled = false; if (btnRech) btnRech.disabled = false; } catch (_) {}
    if (okUpdate) {
      try { window.openServiceFlow && window.openServiceFlow(modal.getAttribute('data-admin-reserva-id')); } catch (_) {}
    }
  });

  // Retomar desde modal de reserva (si Aprobado)
  el('[data-admin-res-retomar]')?.addEventListener('click', () => {
    const id = modal.getAttribute('data-admin-reserva-id');
    if (!id) return;
    window.openServiceFlow && window.openServiceFlow(id);
  });

})();

