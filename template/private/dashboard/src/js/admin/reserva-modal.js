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
    if ([
      'cancelled', 'canceled', 'cancelado', 'cancelada',
      'cancelacion por pago incorrecto', 'cancelación por pago incorrecto',
      'cancelacion de mercado pago', 'cancelación de mercado pago',
      'pago rechazado', 'pago cancelado',
    ].includes(status)) return 'cancelado';
    if (['completed', 'complete', 'done', 'finalizado', 'finalizada', 'completado', 'completada', 'attended', 'atendido', 'atendida'].includes(status)) return 'finalizado';
    return status;
  };
  const statusClassKey = (value) => normalizeStatusKey(value).replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') || 'pendiente';
  const statusLabel = (value) => {
    const key = normalizeStatusKey(value);
    const raw = String(value || '').trim().toLowerCase().replace(/[_-]+/g, ' ').replace(/\s+/g, ' ');
    if (['cancelacion por pago incorrecto', 'cancelación por pago incorrecto', 'pago rechazado', 'pago cancelado'].includes(raw)) {
      return 'Cancelación por pago incorrecto';
    }
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
  const paymentTypeLabel = (row, fallback = 'Pago local') => {
    const method = String(row?.Metodo_Pago || row?.metodo_pago || row?.payment_method || '').trim().toLowerCase();
    const paymentStatus = String(row?.Payment_Status || row?.payment_status || '').trim().toLowerCase();
    const hasMpSignal = ['MP_Preference_ID', 'MP_Payment_ID', 'MP_External_Reference', 'MP_Status_Detail']
      .some((key) => String(row?.[key] || '').trim() !== '');
    if (
      method.includes('mercado')
      || method === 'mp'
      || method === 'mercadopago'
      || hasMpSignal
      || ['created', 'pending', 'approved', 'rejected', 'cancelled', 'canceled', 'refunded', 'charged_back'].includes(paymentStatus)
      || statusLabel(row?.Status || '').toLowerCase().includes('pago incorrecto')
    ) {
      return 'Mercado Pago';
    }
    if (method.includes('whatsapp') || method.includes('whats')) return 'Pago WhatsApp';
    return fallback;
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
    setText('[data-admin-res-payment]', data.payment);
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
    isReprogramSaving = false;
    setReprogramFeedback('');
    updateScheduleSaveState();
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
  const readJsonResponse = async (response) => {
    const text = await response.text();
    let payload = null;
    try {
      payload = text ? JSON.parse(text) : null;
    } catch (_) {
      payload = null;
    }
    if (!payload && (response.status === 401 || response.status === 403)) {
      throw new Error('La sesion expiro o no tenes permisos para modificar esta reserva.');
    }
    if (!payload && text && /<html|<!doctype/i.test(text)) {
      throw new Error('El servidor devolvio una pagina en vez de JSON. Reingresa al panel e intenta de nuevo.');
    }
    return payload;
  };

  let isReprogramSaving = false;
  const normalizeModalDate = (value) => {
    const date = String(value || '').trim();
    return /^\d{4}-\d{2}-\d{2}$/.test(date) ? date : '';
  };
  const normalizeModalTime = (value) => {
    const match = String(value || '').trim().match(/^(\d{2}):(\d{2})/);
    return match ? `${match[1]}:${match[2]}` : '';
  };
  const getReprogramValues = () => {
    const fechaInput = el('[data-admin-res-edit-fecha]');
    const horaInput = el('[data-admin-res-edit-hora]');
    return {
      fecha: normalizeModalDate(fechaInput ? fechaInput.value : ''),
      hora: normalizeModalTime(horaInput ? horaInput.value : ''),
    };
  };
  const hasScheduleChanges = () => {
    const values = getReprogramValues();
    const currentFecha = normalizeModalDate(modal.getAttribute('data-admin-res-fecha'));
    const currentHora = normalizeModalTime(modal.getAttribute('data-admin-res-hora'));
    return values.fecha !== currentFecha || values.hora !== currentHora;
  };
  const setReprogramFeedback = (message, type = 'info') => {
    const feedback = el('[data-admin-res-reprogram-feedback]');
    if (!feedback) return;
    const text = String(message || '').trim();
    feedback.textContent = text;
    feedback.hidden = !text;
    feedback.classList.remove('is-success', 'is-error', 'is-info');
    if (text) feedback.classList.add(type === 'success' || type === 'error' ? `is-${type}` : 'is-info');
  };
  const updateScheduleSaveState = () => {
    const button = el('[data-admin-res-guardar-fecha]');
    if (!button) return false;
    const wrap = el('[data-admin-res-reprogram-wrap]');
    const values = getReprogramValues();
    const ready = !isReprogramSaving
      && !(wrap && wrap.hidden)
      && Boolean(values.fecha)
      && Boolean(values.hora)
      && hasScheduleChanges();
    button.disabled = !ready;
    button.setAttribute('aria-disabled', ready ? 'false' : 'true');
    button.classList.toggle('btn-primary', ready);
    button.classList.toggle('btn-secondary', !ready);
    button.classList.toggle('is-ready', ready);
    button.textContent = isReprogramSaving ? 'Guardando...' : 'Guardar cambios';
    return ready;
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
      const getRowPaymentLabel = () => {
        const row = document.querySelector(`[data-admin-res-row-id="${r.ID_Reserva}"]`);
        if (!row) return null;
        const value = row.getAttribute('data-admin-reserva-payment');
        return value && value.trim() !== '' ? value.trim() : null;
      };
      const rowPriceLabel = getRowPriceLabel();
      const rowPaymentLabel = getRowPaymentLabel();

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
        payment: rowPaymentLabel || paymentTypeLabel(r, 'Pago local'),
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
      setReprogramFeedback('');
      updateScheduleSaveState();
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
      const payload = await readJsonResponse(res);
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
    } catch (error) {
      adminNotify(error && error.message ? error.message : 'No se pudo actualizar la reserva', 'error');
      return false;
    }
  };

  const actionUpdateSchedule = async () => {
    const id = modal.getAttribute('data-admin-reserva-id');
    if (!id) return;
    const fechaInput = el('[data-admin-res-edit-fecha]');
    const horaInput = el('[data-admin-res-edit-hora]');
    if (isReprogramSaving || !updateScheduleSaveState()) return;
    const values = getReprogramValues();
    const fecha = values.fecha;
    const hora = values.hora;
    if (!/^\d{4}-\d{2}-\d{2}$/.test(fecha)) {
      setReprogramFeedback('Fecha invalida', 'error');
      updateScheduleSaveState();
      return;
    }
    if (!/^\d{2}:\d{2}$/.test(hora)) {
      setReprogramFeedback('Hora invalida', 'error');
      updateScheduleSaveState();
      return;
    }
    isReprogramSaving = true;
    setReprogramFeedback('Guardando cambios...', 'info');
    updateScheduleSaveState();
    try {
      const horaSql = hora + ':00';
      const res = await fetch(apiBase, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        credentials: 'same-origin',
        cache: 'no-store',
        body: new URLSearchParams({
          action: 'update',
          table: 'reservas',
          id,
          data: JSON.stringify({ Fecha_Reserva: fecha, Hora_Reserva: horaSql }),
        }),
      });
      const payload = await readJsonResponse(res);
      if (res.ok && payload && payload.ok) {
        modal.setAttribute('data-admin-res-fecha', fecha);
        modal.setAttribute('data-admin-res-hora', hora);
        setText('[data-admin-res-fecha]', fecha);
        setText('[data-admin-res-hora]', hora);
        if (fechaInput) fechaInput.value = fecha;
        if (horaInput) horaInput.value = hora;
        const row = document.querySelector(`[data-admin-res-row-id="${id}"]`);
        if (row) {
          row.setAttribute('data-admin-reserva-fecha', fecha);
          row.setAttribute('data-admin-reserva-hora', hora);
          const cells = row.querySelectorAll('td');
          if (cells[4]) cells[4].textContent = fecha;
          if (cells[5]) cells[5].textContent = hora;
        }
        setReprogramFeedback('Reserva reprogramada', 'success');
        try { window.AdminReservasRefresh && window.AdminReservasRefresh(); } catch (_) {}
      } else {
        const msg = payload && payload.error ? String(payload.error) : 'No se pudo reprogramar';
        setReprogramFeedback(msg, 'error');
      }
    } catch (error) {
      setReprogramFeedback(error && error.message ? error.message : 'No se pudo reprogramar', 'error');
    } finally {
      isReprogramSaving = false;
      updateScheduleSaveState();
    }
  };

  ['[data-admin-res-edit-fecha]', '[data-admin-res-edit-hora]'].forEach((selector) => {
    const input = el(selector);
    if (!input) return;
    input.addEventListener('input', () => {
      setReprogramFeedback('');
      updateScheduleSaveState();
    });
    input.addEventListener('change', () => {
      setReprogramFeedback('');
      updateScheduleSaveState();
    });
  });

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

