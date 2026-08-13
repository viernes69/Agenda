(function adminCliente() {
  const getModal = (key) => document.querySelector(`[data-admin-modal="${key}"]`);
  const clientModal = getModal('cliente');
  const historyModal = getModal('historial');

  if ((!clientModal || !historyModal) && document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', adminCliente, { once: true });
    return;
  }
  if (!clientModal || !historyModal) return;

  const modalLoading = window.AdminModalLoading;
  const apiBase = '../../../src/API/Autoload.php';

  const showLoader = (modal) => modalLoading && modalLoading.show(modal);
  const hideLoader = (modal) => modalLoading && modalLoading.hide(modal);

  const toggleModal = (modal, shouldOpen) => {
    if (!modal) return;
    if (shouldOpen) {
      modal.hidden = false;
      requestAnimationFrame(() => modal.classList.add('is-visible'));
    } else {
      modal.classList.remove('is-visible');
      setTimeout(() => { modal.hidden = true; }, 180);
    }
  };

  const closeClient = () => toggleModal(clientModal, false);
  const openClient = () => toggleModal(clientModal, true);
  const closeHistory = () => toggleModal(historyModal, false);
  const openHistory = () => toggleModal(historyModal, true);

  clientModal.querySelectorAll('[data-admin-cliente-close]').forEach((btn) => btn.addEventListener('click', closeClient));
  historyModal.querySelectorAll('[data-admin-historial-close]').forEach((btn) => btn.addEventListener('click', closeHistory));

  const fetchJson = async (url) => {
    const response = await fetch(url, { credentials: 'same-origin' });
    if (!response.ok) throw new Error('No se pudo completar la solicitud.');
    try {
      return await response.json();
    } catch (error) {
      throw new Error('La respuesta del servidor no es válida.');
    }
  };

  const setText = (selector, value, modal = clientModal) => {
    const node = modal.querySelector(selector);
    if (node) node.textContent = value;
  };

  const fillClient = (client) => {
    setText('[data-admin-cliente-nombre]', client.Nombre || client.nombre || '-');
    setText('[data-admin-cliente-cedula]', client.Cedula || client.cedula || '-');
    setText('[data-admin-cliente-telefono]', client.Telefono || client.telefono || '-');
    setText('[data-admin-cliente-email]', client.Email || client.email || '-');

    const name = (client.Nombre || client.nombre || '').toString();
    const initials = name.trim().split(/\s+/).slice(0, 2).map((part) => part.charAt(0).toUpperCase()).join('') || 'U';
    const avatarBox = clientModal.querySelector('[data-admin-cliente-avatar]');
    const img = clientModal.querySelector('[data-admin-cliente-photo]');
    const ini = clientModal.querySelector('[data-admin-cliente-initials]');

    if (img) {
      let photo = (client.Perfil || client.perfil || '').toString().trim().replace(/\\/g, '/');
      if (photo && !/^https?:\/\//i.test(photo) && !photo.startsWith('/')) {
        photo = '../../../' + photo;
      }
      const fallback = '../../../src/img/users/default.php?n=' + encodeURIComponent(name || 'U');
      img.src = photo || fallback;
      img.hidden = false;
      img.onerror = () => { img.onerror = null; img.src = fallback; };
    }
    if (ini) {
      ini.textContent = initials;
      ini.hidden = true;
    }
    if (avatarBox) avatarBox.hidden = false;
  };

  const renderHistory = (list, maps) => {
    const container = historyModal.querySelector('[data-admin-historial-list]');
    if (!container) return;
    container.innerHTML = '';
    if (!Array.isArray(list) || !list.length) {
      container.innerHTML = '<p class="muted">Sin reservas registradas.</p>';
      return;
    }
    const wrapper = document.createElement('div');
    wrapper.style.display = 'grid';
    wrapper.style.gap = '.5rem';

    list.forEach((reserva) => {
      const item = document.createElement('div');
      item.className = 'res-item';
      item.style.padding = '.6rem .7rem';
      item.style.border = '1px solid var(--border)';
      item.style.borderRadius = '.6rem';

      const servicio = maps.serv[String(reserva.ID_Servicio || '')] || 'Servicio';
      const profesional = maps.pro[String(reserva.ID_Barber || '')] || 'Profesional';
      const fecha = reserva.Fecha_Reserva || '-';
      const hora = String(reserva.Hora_Reserva || '').slice(0, 5);
      const statusKey = String(reserva.Status || 'Pendiente').trim().toLowerCase().replace(/[_-]+/g, ' ').replace(/\s+/g, ' ');
      const statusMap = {
        pendiente: ['Pendiente', 'pendiente'],
        pending: ['Pendiente', 'pendiente'],
        aprobado: ['Reservado', 'aprobado'],
        aprobada: ['Reservado', 'aprobado'],
        approved: ['Reservado', 'aprobado'],
        confirmed: ['Reservado', 'aprobado'],
        reservado: ['Reservado', 'aprobado'],
        reservada: ['Reservado', 'aprobado'],
        'en progreso': ['Atendiendo', 'en-progreso'],
        'in progress': ['Atendiendo', 'en-progreso'],
        'en curso': ['Atendiendo', 'en-progreso'],
        atendiendo: ['Atendiendo', 'en-progreso'],
        finalizado: ['Finalizado', 'finalizado'],
        finalizada: ['Finalizado', 'finalizado'],
        done: ['Finalizado', 'finalizado'],
        atendido: ['Finalizado', 'finalizado'],
        atendida: ['Finalizado', 'finalizado'],
        cancelado: ['Cancelado', 'cancelado'],
        cancelada: ['Cancelado', 'cancelado'],
        rechazado: ['Rechazado', 'rechazado'],
        rechazada: ['Rechazado', 'rechazado'],
      };
      const status = statusMap[statusKey] || [(reserva.Status || 'Pendiente').toString(), statusKey.replace(/[^a-z0-9]+/g, '-') || 'pendiente'];

      item.innerHTML = `
        <div style="display:flex; justify-content:space-between; align-items:center; gap:.5rem;">
          <div>
            <div><strong>${servicio}</strong>&nbsp;&middot;&nbsp;${profesional}</div>
            <div class="muted">${fecha}&nbsp;&middot;&nbsp;${hora}</div>
          </div>
          <span class="status-pill st-${status[1]}">${status[0]}</span>
        </div>
      `;
      wrapper.appendChild(item);
    });

    container.appendChild(wrapper);
  };

  const openClientFlow = async (clientId) => {
    try {
      showLoader(clientModal);
      const url = `${apiBase}?action=get&table=clientes&id=${encodeURIComponent(clientId)}`;
      const payload = await fetchJson(url);
      const data = payload && (payload.data || payload.value || payload.cliente);
      if (!data) throw new Error('Cliente no encontrado.');
      fillClient(data);
      clientModal.setAttribute('data-admin-cliente-id', String(clientId));
      openClient();
    } catch (error) {
      adminNotify(error && error.message ? error.message : 'No se pudo cargar el cliente.', 'error');
    } finally {
      hideLoader(clientModal);
    }
  };

  const openHistoryFlow = async (clientId) => {
    try {
      showLoader(historyModal);
      const [resResp, servResp, proResp] = await Promise.all([
        fetchJson(`${apiBase}?action=list&table=reservas`),
        fetchJson(`${apiBase}?action=list&table=servicios`),
        fetchJson(`${apiBase}?action=list&table=barberos`)
      ]);

      const reservas = Array.isArray(resResp?.data) ? resResp.data : [];
      const servicios = Array.isArray(servResp?.data) ? servResp.data : [];
      const profesionales = Array.isArray(proResp?.data) ? proResp.data : [];

      const list = reservas.filter((reserva) => String(reserva.ID_Cliente || '') === String(clientId));
      list.sort((a, b) => ((b.ID_Reserva || 0) - (a.ID_Reserva || 0)));

      const maps = { serv: {}, pro: {} };
      servicios.forEach((srv) => {
        if (!srv || srv.ID_Servicio == null) return;
        maps.serv[String(srv.ID_Servicio)] = srv.Nombre || 'Servicio';
      });
      profesionales.forEach((pro) => {
        if (!pro || pro.ID_Barber == null) return;
        const nombre = (pro.Nombre || '').toString().trim();
        const apellido = (pro.Apellido || '').toString().trim();
        const full = (nombre + ' ' + apellido).trim();
        maps.pro[String(pro.ID_Barber)] = full || 'Profesional';
      });

      renderHistory(list, maps);
      openHistory();
    } catch (error) {
      adminNotify(error && error.message ? error.message : 'No se pudo cargar el historial.', 'error');
    } finally {
      hideLoader(historyModal);
    }
  };

  document.addEventListener('click', (event) => {
    const trigger = event.target.closest('[data-admin-res-ver-cliente]');
    if (!trigger) return;
    const reservaModal = document.querySelector('[data-admin-modal="reserva"]');
    const clientId = reservaModal?.getAttribute('data-admin-res-cliente-id');
    if (!clientId) {
      adminNotify('No hay cliente asociado.', 'info');
      return;
    }
    openClientFlow(clientId);
  });

  const historyBtn = clientModal.querySelector('[data-admin-cliente-historial]');
  if (historyBtn) {
    historyBtn.addEventListener('click', () => {
      const clientId = clientModal.getAttribute('data-admin-cliente-id');
      if (!clientId) return;
      openHistoryFlow(clientId);
    });
  }
})();







