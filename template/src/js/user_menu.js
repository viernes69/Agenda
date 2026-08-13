(() => {
  const headerUser = document.getElementById('header-user');
  const headerUserContainer = document.getElementById('header-user-container');
  const userMenuPanel = document.getElementById('header-user-panel');
  const userMenuItems = userMenuPanel
    ? Array.from(userMenuPanel.querySelectorAll('[data-user-menu]'))
    : [];
  if (!headerUser || !headerUserContainer || !userMenuPanel) return;
  const headerResBadge = document.getElementById('header-res-count');
  const menuResBadge = document.getElementById('menu-res-count');

  let menuOpen = false;
  const open = () => {
    if (menuOpen) return;
    userMenuPanel.classList.add('is-open');
    headerUser.setAttribute('aria-expanded', 'true');
    menuOpen = true;
  };
  const close = () => {
    if (!menuOpen) return;
    userMenuPanel.classList.remove('is-open');
    headerUser.setAttribute('aria-expanded', 'false');
    menuOpen = false;
  };
  const toggle = () => (menuOpen ? close() : open());
  const apiUrl = (path) => {
    const cleanPath = String(path || '').replace(/^\/+/, '');
    const currentPath = window.location.pathname || '/';
    const basePath = currentPath.endsWith('/') || /\.[a-z0-9]+$/i.test(currentPath)
      ? currentPath
      : `${currentPath}/`;
    return new URL(cleanPath, `${window.location.origin}${basePath}`).href;
  };

  headerUser.addEventListener('click', (e) => {
    e.preventDefault();
    e.stopPropagation();
    try { updateReservationsBadge && updateReservationsBadge(); } catch (_) {}
    toggle();
  });

  document.addEventListener('click', (e) => {
    if (!menuOpen) return;
    if (!headerUserContainer.contains(e.target)) close();
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') close();
  });

  const confirmAction = (message, options = {}) => {
    if (typeof window.publicConfirm === 'function') {
      return window.publicConfirm({ message, ...options });
    }
    return Promise.resolve(window.confirm(message));
  };

  const getSessionStatus = async () => {
    try {
      const res = await fetch(apiUrl('src/API/session_client.php?action=status'), { credentials: 'same-origin' });
      const data = await res.json();
      if (data && data.ok && data.data) return data.data;
      return null;
    } catch (_) { return null; }
  };

  const updateReservationsBadge = async () => {
    try {
      const session = await getSessionStatus();
      const list = (session && Array.isArray(session.reservas)) ? session.reservas : [];
      const count = list.filter((r) => String((r.Status || r.status || '')).toLowerCase().trim() !== 'finalizado').length;
      if (headerResBadge) { headerResBadge.textContent = String(count); headerResBadge.hidden = !(count > 0); }
      if (menuResBadge) { menuResBadge.textContent = String(count); menuResBadge.hidden = !(count > 0); }
    } catch (_) { /* ignore */ }
  };

  const normalizeStatusKey = (value) => {
    let raw = String(value || '').trim();
    if (!raw) return '';
    if (typeof raw.normalize === 'function') {
      raw = raw.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    }
    raw = raw.toLowerCase().replace(/[_-]+/g, ' ').replace(/\s+/g, ' ');
    if (['approved', 'confirmed', 'aprobado', 'aprobada', 'reservado', 'reservada'].includes(raw)) return 'aprobado';
    if (['in progress', 'en progreso', 'en curso', 'atendiendo'].includes(raw)) return 'en progreso';
    if (['completed', 'complete', 'done', 'finalizado', 'finalizada', 'atendido', 'atendida'].includes(raw)) return 'finalizado';
    if (['cancelled', 'canceled', 'cancelado', 'cancelada'].includes(raw)) return 'cancelado';
    if (['rejected', 'rechazado', 'rechazada'].includes(raw)) return 'rechazado';
    if (['pending', 'pendiente', 'sin confirmar'].includes(raw)) return 'pendiente';
    return raw;
  };

  const statusLabelFor = (key, fallback) => {
    const labels = {
      pendiente: 'Pendiente',
      aprobado: 'Reservado',
      'en progreso': 'Atendiendo',
      cancelado: 'Cancelado',
      rechazado: 'Rechazado',
      finalizado: 'Finalizado',
    };
    return labels[key] || fallback || 'Pendiente';
  };

  const extractDateValue = (value) => {
    const raw = String(value || '').trim();
    if (!raw) return '';
    const candidate = raw.slice(0, 10);
    return /^\d{4}-\d{2}-\d{2}$/.test(candidate) ? candidate : '';
  };

  const formatDateDisplay = (iso) => {
    if (!iso) return '';
    const match = iso.match(/^(\d{4})-(\d{2})-(\d{2})$/);
    if (!match) return iso;
    const [, y, m, d] = match;
    return `${d}/${m}/${y}`;
  };

  const formatTimeDisplay = (value) => {
    const raw = String(value || '').trim();
    if (!raw) return '';
    const hhmm = raw.slice(0, 5);
    return /^[0-2]\d:[0-5]\d$/.test(hhmm) ? hhmm : raw;
  };

  const openPointsModal = async () => {
    const session = await getSessionStatus();
    const clienteId = session && session.cliente_id;
    const modal = typeof modalManager !== 'undefined' && modalManager.getModal
      ? modalManager.getModal('points')
      : null;
    if (!modal) return;
    const totalEl = modal.querySelector('[data-points-total]');
    const fillEl = modal.querySelector('[data-points-fill]');
    const rewardsEl = modal.querySelector('[data-points-rewards]');
    const rewardsEmptyEl = modal.querySelector('[data-points-empty]');

    const computePoints = async (clientId) => {
      try {
        const [serv, prod, pts] = await Promise.all([
          fetch('src/API/Autoload.php?action=list&table=servicios', { credentials: 'same-origin' }).then((r) => r.json()),
          fetch('src/API/Autoload.php?action=list&table=productos', { credentials: 'same-origin' }).then((r) => r.json()),
          fetch('src/API/Autoload.php?action=list&table=puntos', { credentials: 'same-origin' }).then((r) => r.json()),
        ]);
        const servicios = Array.isArray(serv && serv.data) ? serv.data : [];
        const productos = Array.isArray(prod && prod.data) ? prod.data : [];
        const puntos = Array.isArray(pts && pts.data) ? pts.data : [];

        let total = 0;
        const entry = puntos.find((row) => {
          return String(row.ID_Client || row.id_cliente || '') === String(clientId || '')
            && (!row.Estado || String(row.Estado).toLowerCase().trim() !== 'inactivo');
        });
        if (entry && entry.Total != null) {
          total = Number(entry.Total) || 0;
        }

        return {
          total,
          servicios,
          productos,
        };
      } catch (_) {
        return {
          total: 0,
          servicios: [],
          productos: [],
        };
      }
    };

    const { total, servicios = [], productos = [] } = (clienteId != null)
      ? await computePoints(clienteId)
      : { total: 0, servicios: [], productos: [] };
    if (totalEl) totalEl.textContent = String(total);
    if (fillEl) {
      const max = 1200;
      const value = Math.max(0, Math.min(max, Number(total) || 0));
      const pct = Math.round((value / max) * 100);
      fillEl.style.width = pct + '%';
      fillEl.classList.remove('is-low', 'is-mid', 'is-high');
      if (value < 400) fillEl.classList.add('is-low');
      else if (value < 800) fillEl.classList.add('is-mid');
      else fillEl.classList.add('is-high');
    }
    if (rewardsEl) {
      rewardsEl.innerHTML = '';
      if (rewardsEmptyEl && !rewardsEmptyEl.isConnected) {
        rewardsEl.appendChild(rewardsEmptyEl);
      }
      const rewards = [];
      servicios.forEach((service) => {
        const points = Number(service.Puntos || service.puntos || 0);
        if (points <= 0) return;
        rewards.push({
          id: String(service.ID_Servicio || service.id || ''),
          label: service.Nombre || 'Servicio',
          points,
          description: service.Descripcion
            ? String(service.Descripcion)
            : service.Duracion
              ? `DuraciÃ³n: ${service.Duracion} min`
              : 'Servicio disponible para canjear.',
          type: 'Servicio',
        });
      });
      productos.forEach((product) => {
        const points = Number(product.Puntos || product.puntos || 0);
        if (points <= 0) return;
        rewards.push({
          id: String(product.ID_Product || product.id || ''),
          label: product.Nombre || 'Producto',
          points,
          description: product.Descripcion
            ? String(product.Descripcion)
            : product.Precio
              ? `Precio: $${Number(product.Precio).toFixed(2)}`
              : 'Producto disponible para canjear.',
          type: 'Producto',
        });
      });
      rewards.sort((a, b) => a.points - b.points || a.label.localeCompare(b.label));
      if (!rewards.length) {
        if (rewardsEmptyEl) {
          rewardsEmptyEl.hidden = false;
        }
      } else {
        if (rewardsEmptyEl) rewardsEmptyEl.hidden = true;
        const fragment = document.createDocumentFragment();
        rewards.forEach((reward) => {
          const unlocked = total >= reward.points;
          const card = document.createElement('article');
          card.className = `points-reward-card${unlocked ? '' : ' is-locked'}`;

          const head = document.createElement('div');
          head.className = 'points-reward-head';
          const title = document.createElement('h3');
          title.className = 'points-reward-title';
          title.textContent = reward.label;
          const pointsTag = document.createElement('span');
          pointsTag.className = 'points-reward-points';
          pointsTag.textContent = `${reward.points} pts`;
          head.appendChild(title);
          head.appendChild(pointsTag);

          const body = document.createElement('p');
          body.className = 'points-reward-body';
          const prefix = reward.type ? `${reward.type} Â· ` : '';
          body.textContent = prefix + reward.description;

          const actions = document.createElement('div');
          actions.className = 'points-reward-actions';
          const button = document.createElement('button');
          button.type = 'button';
          button.className = 'points-reward-btn';
          button.textContent = 'Canjear';
          if (!unlocked) {
            button.disabled = true;
            button.setAttribute('aria-disabled', 'true');
          }
          actions.appendChild(button);

          card.appendChild(head);
          card.appendChild(body);
          card.appendChild(actions);
          fragment.appendChild(card);
        });
        rewardsEl.appendChild(fragment);
      }
    }
    modalManager && modalManager.open('points');
  };

  const openPurchasesModal = async () => {
    const modal = typeof modalManager !== 'undefined' && modalManager.getModal
      ? modalManager.getModal('purchases')
      : null;
    if (!modal) return;
    const list = modal.querySelector('[data-purchases-list]');
    const empty = modal.querySelector('[data-purchases-empty]');
    const countEl = modal.querySelector('[data-purchases-count]');
    const statusEl = modal.querySelector('[data-purchases-status]');
    if (list) {
      list.innerHTML = '<p class="muted">Cargando compras...</p>';
      list.hidden = false;
    }
    if (empty) empty.hidden = true;
    if (statusEl) statusEl.textContent = '';

    try {
      const res = await fetch(apiUrl('src/API/client_purchases.php'), { credentials: 'same-origin' });
      const payload = await res.json();
      if (!res.ok || !payload || payload.ok === false) {
        throw new Error((payload && payload.error) || 'No se pudo obtener el historial');
      }
      const data = Array.isArray(payload.data) ? payload.data : [];
      if (countEl) {
        const n = data.length;
        countEl.textContent = n === 1 ? '1 compra' : `${n} compras`;
      }
      if (!data.length) {
        if (list) list.hidden = true;
        if (empty) {
          empty.hidden = false;
          empty.textContent = 'No registramos compras completas en tu cuenta.';
        }
        modalManager && modalManager.open('purchases');
        return;
      }
      if (statusEl) {
        const finalizados = data.filter((p) => String(p.status || '').toLowerCase() === 'finalizado').length;
        statusEl.textContent = `${finalizados} finalizadas`;
      }
      if (list) {
        list.hidden = false;
        list.innerHTML = '';
        const fragment = document.createDocumentFragment();
        data.forEach((purchase) => {
          const date = String(purchase.fecha || '').slice(0, 10);
          const time = String(purchase.hora || '').slice(0, 5);
          const status = String(purchase.status || '').trim();
          const statusClass = status ? `status-${status.toLowerCase()}` : '';
          const card = document.createElement('article');
          card.className = 'purchase-card';
          card.innerHTML = `
            <div class="purchase-head">
              <div>
                <h3 class="purchase-title">Compra #${purchase.id_carrito || '-'}</h3>
                <p class="purchase-meta">${date || '-'} Â· ${time || ''}</p>
                ${purchase.direccion ? `<p class="purchase-meta">DirecciÃ³n: ${purchase.direccion}</p>` : ''}
              </div>
              <span class="purchase-status ${statusClass}">${status || 'Pendiente'}</span>
            </div>
            <ul class="purchase-items"></ul>
          `;
          const itemsList = card.querySelector('.purchase-items');
          const items = Array.isArray(purchase.items) ? purchase.items : [];
          if (items.length) {
            items.forEach((item) => {
              const qty = Number(item.quantity || item.qty || 0);
              const precio = (item.precio !== null && item.precio !== undefined && item.precio !== '')
                ? ` Â· $${Number(item.precio).toFixed(2)}`
                : '';
              const li = document.createElement('li');
              li.className = 'purchase-item';
              li.innerHTML = `
                <span class="purchase-item-name">${item.nombre || `Producto ${item.product_id || ''}`}</span>
                <span class="purchase-item-qty">x${qty}${precio}</span>
              `;
              itemsList.appendChild(li);
            });
          } else {
            const li = document.createElement('li');
            li.className = 'purchase-item';
            li.innerHTML = '<span class="purchase-item-name">Sin detalle de productos</span>';
            itemsList.appendChild(li);
          }
          fragment.appendChild(card);
        });
        list.appendChild(fragment);
      }
      modalManager && modalManager.open('purchases');
    } catch (error) {
      if (list) {
        list.hidden = true;
      }
      if (empty) {
        empty.hidden = false;
        empty.textContent = error.message || 'No se pudo cargar el historial.';
      }
      modalManager && modalManager.open('purchases');
    }
  };

  const normalizeAvatarPath = (path) => {
    if (!path) return '';
    const normalized = String(path).trim().replace(/\\/g, '/');
    if (normalized.startsWith('http')) return normalized;
    return normalized.startsWith('/') ? normalized.slice(1) : normalized;
  };

  const openAccountModal = async () => {
    const session = await getSessionStatus();
    const modal = typeof modalManager !== 'undefined' && modalManager.getModal
      ? modalManager.getModal('account')
      : null;
    if (!modal) return;

    const fields = {
      name: modal.querySelector('[data-account-name]'),
      cedula: modal.querySelector('[data-account-cedula]'),
      telefono: modal.querySelector('[data-account-telefono]'),
      email: modal.querySelector('[data-account-email]'),
      started: modal.querySelector('[data-account-started]'),
      photo: modal.querySelector('[data-account-photo]'),
      initials: modal.querySelector('[data-account-initials]'),
      status: modal.querySelector('[data-account-status]'),
    };

    const fieldModal = {
      root: modal.querySelector('[data-account-field-modal]'),
      label: modal.querySelector('[data-account-field-label]'),
      labelText: modal.querySelector('[data-account-field-label-text]'),
      input: modal.querySelector('[data-account-field-input]'),
      status: modal.querySelector('[data-account-field-status]'),
      form: modal.querySelector('[data-account-field-form]'),
      cancel: modal.querySelector('[data-account-field-cancel]'),
      save: modal.querySelector('[data-account-field-save]'),
      close: modal.querySelectorAll('[data-account-field-close]'),
    };

    const fieldDisplays = {
      nombre: fields.name,
      cedula: fields.cedula,
      telefono: fields.telefono,
      email: fields.email,
    };

    const fieldConfig = {
      nombre: { label: 'Nombre', type: 'text' },
      cedula: { label: 'Cedula', type: 'text' },
      telefono: { label: 'Telefono', type: 'tel' },
      email: {
        label: 'Email',
        type: 'email',
        validator: (value) => (/\S+@\S+\.\S+/.test(value) ? null : 'Email invalido'),
      },
    };

    const clientData = (() => {
      if (!session) return null;
      if (session.cliente && typeof session.cliente === 'object') return session.cliente;
      if (session.data && typeof session.data === 'object') {
        if (session.data.cliente) return session.data.cliente;
        return session.data;
      }
      return session;
    })();

    const nombreValue = clientData ? (clientData.nombre || clientData.Nombre || '') : '';
    const cedulaValue = clientData ? (clientData.cedula || clientData.Cedula || '') : '';
    const telefonoValue = clientData ? (clientData.telefono || clientData.Telefono || '') : '';
    const emailValue = clientData ? (clientData.email || clientData.Email || '') : '';
    const startedTs = clientData
      ? (clientData.session_started_at || clientData.started_at
          || (clientData.expires_at ? (parseInt(clientData.expires_at, 10) - 86400) : null))
      : null;

    const current = {
      nombre: nombreValue,
      cedula: cedulaValue,
      telefono: telefonoValue,
      email: emailValue,
    };

    const getDisplayName = () => current.nombre || current.email || current.cedula || 'Cliente';

    const setStatus = (message, variant) => {
      if (!fields.status) return;
      if (!message) {
        fields.status.hidden = true;
        fields.status.textContent = '';
        fields.status.removeAttribute('data-variant');
        return;
      }
      fields.status.hidden = false;
      fields.status.textContent = message;
      fields.status.setAttribute('data-variant', variant === 'error' ? 'error' : 'info');
    };

    const fillView = () => {
      if (fields.name) fields.name.textContent = current.nombre || '-';
      if (fields.cedula) fields.cedula.textContent = current.cedula || '-';
      if (fields.telefono) fields.telefono.textContent = current.telefono || '-';
      if (fields.email) fields.email.textContent = current.email || '-';
    };

    fillView();
    if (fields.started) {
      const date = Number.isFinite(Number(startedTs))
        ? new Date(Number(startedTs) * 1000)
        : null;
      fields.started.textContent = (date && !Number.isNaN(date.getTime()))
        ? date.toLocaleString()
        : '-';
    }
    setStatus('');

    const applyAvatar = (url, nameValue) => {
      const img = fields.photo;
      const ini = fields.initials;
      const initials = (nameValue || '').split(/\s+/).slice(0, 2)
        .map((w) => w.charAt(0).toUpperCase()).join('') || 'U';
      const def = 'src/img/users/default.php?n=' + encodeURIComponent(initials);

      if (img) {
        const normalized = normalizeAvatarPath(url);
        img.hidden = false;
        img.src = normalized || def;
        img.onerror = () => {
          img.onerror = null;
          img.src = def;
        };
      }

      if (ini) {
        ini.textContent = initials;
        ini.hidden = true;
      }
      return initials;
    };

    let photoUrl = clientData ? (clientData.perfil || clientData.Perfil || '') : '';
    let modalInitials = applyAvatar(photoUrl, getDisplayName());

    const updateHeaderMeta = () => {
      const headerTrigger = document.getElementById('header-user');
      const display = getDisplayName();
      if (headerTrigger) {
        headerTrigger.setAttribute('title', display);
        headerTrigger.setAttribute('aria-label', 'Abrir menu de usuario de ' + display);
      }
      const headerInitials = document.getElementById('header-avatar-initials');
      if (headerInitials && !headerInitials.hidden) {
        headerInitials.textContent = modalInitials;
      }
    };

    const syncClientSession = () => {
      if (typeof window.__CLIENT_SESSION !== 'object' || !window.__CLIENT_SESSION) return;
      const target = window.__CLIENT_SESSION;
      target.nombre = current.nombre;
      target.Nombre = current.nombre;
      target.cedula = current.cedula;
      target.Cedula = current.cedula;
      target.telefono = current.telefono;
      target.Telefono = current.telefono;
      target.email = current.email;
      target.Email = current.email;
      target.display_name = getDisplayName();
    };

    const setFieldStatus = (message, variant) => {
      if (!fieldModal.status) return;
      if (!message) {
        fieldModal.status.hidden = true;
        fieldModal.status.textContent = '';
        fieldModal.status.removeAttribute('data-variant');
        return;
      }
      fieldModal.status.hidden = false;
      fieldModal.status.textContent = message;
      fieldModal.status.setAttribute('data-variant', variant === 'error' ? 'error' : 'info');
    };

    const closeFieldModal = () => {
      if (!fieldModal.root) return;
      fieldModal.root.hidden = true;
      fieldModal.root.classList.remove('is-open');
      fieldModal.currentField = null;
      setFieldStatus('');
      if (fieldModal.input) fieldModal.input.value = '';
    };

    const openFieldModal = (field) => {
      if (!fieldModal.root || !fieldModal.form) return;
      const config = fieldConfig[field];
      if (!config) return;
      fieldModal.currentField = field;
      if (fieldModal.label) fieldModal.label.textContent = config.label;
      if (fieldModal.labelText) fieldModal.labelText.textContent = config.label;
      if (fieldModal.input) {
        fieldModal.input.type = config.type || 'text';
        fieldModal.input.value = current[field] || '';
      }
      setFieldStatus('');
      fieldModal.root.hidden = false;
      fieldModal.root.classList.add('is-open');
      window.setTimeout(() => {
        if (fieldModal.input) fieldModal.input.focus();
      }, 0);
    };

    const bindFieldTriggers = () => {
      const triggers = modal.querySelectorAll('[data-account-field-trigger]');
      triggers.forEach((btn) => {
        if (btn._bound) return;
        btn._bound = true;
        btn.addEventListener('click', () => {
          const field = btn.getAttribute('data-account-field-trigger');
          if (field) openFieldModal(field);
        });
      });
    };

    const editBtn = modal.querySelector('[data-account-photo-edit]');
    const fileInput = modal.querySelector('[data-account-file]');
    if (editBtn && fileInput && !editBtn._bound) {
      editBtn._bound = true;
      editBtn.addEventListener('click', () => {
        fileInput.click();
      });
      fileInput.addEventListener('change', async () => {
        if (!fileInput.files || !fileInput.files[0]) return;
        const fd = new FormData();
        fd.append('action', 'upload_photo');
        fd.append('photo', fileInput.files[0]);
        editBtn.disabled = true;
        editBtn.textContent = 'Guardando...';
        try {
          const res = await fetch('src/API/profile.php', { method: 'POST', body: fd, credentials: 'same-origin' });
          const payload = await res.json();
          if (res.ok && payload && payload.ok && payload.path) {
            photoUrl = payload.path;
            modalInitials = applyAvatar(photoUrl, getDisplayName());
            updateHeaderMeta();
            syncClientSession();
            setStatus('Foto actualizada correctamente');
            window.setTimeout(() => setStatus(''), 4000);
          } else {
            publicAlert((payload && payload.error) || 'No se pudo actualizar la foto');
          }
        } catch (err) {
          publicAlert('No se pudo actualizar la foto');
        } finally {
          editBtn.disabled = false;
          editBtn.textContent = 'Editar';
          fileInput.value = '';
        }
      });
    }

    if (fieldModal.close) {
      fieldModal.close.forEach((btn) => {
        if (btn._bound) return;
        btn._bound = true;
        btn.addEventListener('click', closeFieldModal);
      });
    }
    if (fieldModal.cancel && !fieldModal.cancel._bound) {
      fieldModal.cancel._bound = true;
      fieldModal.cancel.addEventListener('click', (event) => {
        event.preventDefault();
        closeFieldModal();
      });
    }
    if (fieldModal.root && !fieldModal.root._backdropBound) {
      fieldModal.root._backdropBound = true;
      const backdrop = fieldModal.root.querySelector('[data-account-field-backdrop]');
      if (backdrop) {
        backdrop.addEventListener('click', closeFieldModal);
      }
    }
    if (fieldModal.form && !fieldModal.form._bound) {
      fieldModal.form._bound = true;
      fieldModal.form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!fieldModal.currentField) return;
        const config = fieldConfig[fieldModal.currentField];
        if (!config || !fieldModal.input) return;
        const value = fieldModal.input.value.trim();
        if (value === '') {
          setFieldStatus('Este campo es obligatorio', 'error');
          fieldModal.input.focus();
          return;
        }
        if (config.validator) {
          const error = config.validator(value);
          if (error) {
            setFieldStatus(error, 'error');
            fieldModal.input.focus();
            return;
          }
        }
        const payload = {
          action: 'update_profile_field',
          field: fieldModal.currentField,
          value,
        };
        if (fieldModal.save) fieldModal.save.disabled = true;
        if (fieldModal.cancel) fieldModal.cancel.disabled = true;
        setFieldStatus('Guardando cambios...');
        try {
          const res = await fetch('src/API/profile.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify(payload),
          });
          const body = await res.json().catch(() => ({}));
          if (!res.ok || !body || !body.ok) {
            const message = (body && body.error) ? body.error : 'No se pudo guardar el cambio';
            setFieldStatus(message, 'error');
            if (fieldModal.save) fieldModal.save.disabled = false;
            if (fieldModal.cancel) fieldModal.cancel.disabled = false;
            return;
          }
          current[fieldModal.currentField] = value;
          const displayEl = fieldDisplays[fieldModal.currentField];
          if (displayEl) displayEl.textContent = value || '-';
          modalInitials = applyAvatar(photoUrl, getDisplayName());
          updateHeaderMeta();
          syncClientSession();
          setStatus(fieldConfig[fieldModal.currentField].label + ' actualizado correctamente');
          closeFieldModal();
          window.setTimeout(() => setStatus(''), 4000);
        } catch (_) {
          setFieldStatus('No se pudo guardar el cambio', 'error');
        } finally {
          if (fieldModal.save) fieldModal.save.disabled = false;
          if (fieldModal.cancel) fieldModal.cancel.disabled = false;
        }
      });
    }

    closeFieldModal();
    bindFieldTriggers();
    updateHeaderMeta();
    syncClientSession();
    modalManager && modalManager.open('account');
  };
  const doLogout = async () => {
    try {
      // Si hay una sesiÃ³n de barbero activa, usar el nuevo sistema
      if (window.BarberSessionManager && BarberSessionManager.isAuthenticated()) {
        await BarberSessionManager.logout();
        clearBarberUI();
      } else {
        // Logout normal para clientes
        await fetch(apiUrl('src/API/session_client.php'), {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          credentials: 'same-origin',
          body: JSON.stringify({ action: 'logout' }),
        });
      }
    } catch (_) { /* ignore */ }
    try { window.location.reload(); } catch (_) {}
  };

  userMenuItems.forEach((btn) => {
    btn.addEventListener('click', async (e) => {
      e.preventDefault();
      const action = btn.getAttribute('data-user-menu');
      switch (action) {
        case 'account':
          close();
          openAccountModal();
          break;
        case 'reservas':
          close();
          (async () => {
            try {
              const session = await getSessionStatus();
              const modal = typeof modalManager !== 'undefined' && modalManager.getModal ? modalManager.getModal('reservas') : null;
              if (!modal) return;
              const listEl = modal.querySelector('[data-reservas-list]');
              const emptyEl = modal.querySelector('[data-reservas-empty]');
              const statusFilter = modal.querySelector('[data-reservas-filter-status]');
              const dateFilter = modal.querySelector('[data-reservas-filter-date]');
              if (!listEl) return;
              listEl.innerHTML = '';
              if (statusFilter) statusFilter.value = '';
              if (dateFilter) dateFilter.value = '';
              let reservas = (session && Array.isArray(session.reservas)) ? session.reservas : [];
              if (!reservas.length) {
                // fallback: fetch from DB
                try {
                  const res = await fetch(apiUrl('src/API/Autoload.php?action=list&table=reservas'), { credentials: 'same-origin' });
                  const payload = await res.json();
                  reservas = Array.isArray(payload && payload.data) ? payload.data : [];
                  const id = session && session.cliente_id;
                  reservas = reservas.filter((r) => String(r.ID_Cliente || '') === String(id || ''));
                } catch (_) { reservas = []; }
              }
              // prefetch names maps
              const [servMap, barbMap] = await (async () => {
                try {
                  const [sv, bb] = await Promise.all([
                    fetch(apiUrl('src/API/Autoload.php?action=list&table=servicios'), { credentials: 'same-origin' }).then(r=>r.json()),
                    fetch(apiUrl('src/API/Autoload.php?action=list&table=barberos'), { credentials: 'same-origin' }).then(r=>r.json()),
                  ]);
                  const sMap = {};
                  (Array.isArray(sv && sv.data) ? sv.data : []).forEach(s => { sMap[String(s.ID_Servicio)] = s.Nombre || 'Servicio'; });
                  const bMap = {};
                  (Array.isArray(bb && bb.data) ? bb.data : []).forEach(b => { bMap[String(b.ID_Barber)] = (b.Nombre ? (b.Nombre + (b.Apellido ? (' ' + b.Apellido) : '')) : 'Profesional'); });
                  return [sMap, bMap];
                } catch (_) { return [{}, {}]; }
              })();
              const knownStatusKeys = new Set(['pendiente', 'aprobado', 'en progreso', 'cancelado', 'finalizado', 'rechazado']);
              const dataset = reservas.map((r) => {
                const rawStatus = String(r.Status || r.status || '').trim() || 'Pendiente';
                const statusKey = normalizeStatusKey(rawStatus) || 'pendiente';
                const statusLabel = statusLabelFor(statusKey, rawStatus);
                const statusClass = knownStatusKeys.has(statusKey) ? `status-${statusKey.replace(/\s+/g, '-')}` : 'status-pendiente';
                const dateValue = extractDateValue(r.Fecha_Reserva || r.fecha || r.date || '');
                const timeValue = formatTimeDisplay(r.Hora_Reserva || r.hora || r.time || '');
                return {
                  id: String(r.ID_Reserva || r.id || ''),
                  serviceName: servMap[String(r.ID_Servicio || '')] || 'Servicio',
                  barberName: barbMap[String(r.ID_Barber || '')] || 'Profesional',
                  statusLabel,
                  statusKey,
                  statusClass,
                  dateValue,
                  dateDisplay: dateValue ? formatDateDisplay(dateValue) : '',
                  timeDisplay: timeValue,
                  canCancel: statusKey === 'pendiente',
                  source: r,
                };
              });

              const renderList = (entries) => {
                listEl.innerHTML = '';
                if (emptyEl && !emptyEl.isConnected) {
                  listEl.appendChild(emptyEl);
                }
                if (!entries.length) {
                  if (emptyEl) emptyEl.hidden = false;
                  return;
                }
                if (emptyEl) emptyEl.hidden = true;
                entries.forEach((item) => {
                  const metaParts = [];
                  const dateText = item.dateDisplay || (item.dateValue ? formatDateDisplay(item.dateValue) : '');
                  metaParts.push(`Fecha: ${dateText || '-'}`);
                  if (item.timeDisplay) metaParts.push(`Hora: ${item.timeDisplay}`);
                  const el = document.createElement('div');
                  el.className = 'res-item';
                  el.innerHTML = `
                    <div class="res-head">
                      <h3 class="res-title">${item.serviceName} <span class="status-badge ${item.statusClass}">${item.statusLabel}</span></h3>
                      <div class="res-actions">${item.canCancel ? '<button type="button" class="btn-danger" data-res-cancel>Cancelar</button>' : ''}</div>
                    </div>
                    <p class="res-meta">Profesional: ${item.barberName}</p>
                    <p class="res-meta">${metaParts.join('  ')}</p>
                  `;
                  const cancelBtn = el.querySelector('[data-res-cancel]');
                  if (cancelBtn) {
                    cancelBtn.addEventListener('click', async () => {
                      if (!item.id) return;
                      const confirmed = await confirmAction('¿Seguro que deseas cancelar esta reserva?');
                      if (!confirmed) return;
                      try {
                        const res = await fetch('src/API/Autoload.php', {
                          method: 'POST',
                          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                          credentials: 'same-origin',
                          body: new URLSearchParams({ action: 'update', table: 'reservas', id: item.id, data: JSON.stringify({ Status: 'Cancelado' }) }),
                        });
                        const payload = await res.json();
                        if (res.ok && payload && payload.ok) {
                          publicAlert('Reserva cancelada');
                          item.statusLabel = 'Cancelado';
                          item.statusKey = 'cancelado';
                          item.statusClass = 'status-cancelado';
                          item.canCancel = false;
                          if (item.source) item.source.Status = 'Cancelado';
                          updateReservationsBadge && updateReservationsBadge();
                          applyFilters();
                        } else {
                          publicAlert('No se pudo cancelar');
                        }
                      } catch (_) {
                        publicAlert('No se pudo cancelar');
                      }
                    });
                  }
                  listEl.appendChild(el);
                });
              };

              const applyFilters = () => {
                let filtered = dataset.slice();
                const selectedStatus = statusFilter ? statusFilter.value : '';
                if (selectedStatus) {
                  filtered = filtered.filter((item) => item.statusKey === selectedStatus);
                }
                const selectedDate = dateFilter ? dateFilter.value : '';
                if (selectedDate) {
                  filtered = filtered.filter((item) => item.dateValue === selectedDate);
                }
                renderList(filtered);
              };

              if (statusFilter) statusFilter.onchange = applyFilters;
              if (dateFilter) {
                dateFilter.onchange = applyFilters;
                dateFilter.oninput = applyFilters;
              }

              applyFilters();
              modalManager && modalManager.open('reservas');
            } catch (_) {}
          })();
          break;
        case 'compras':
          close();
          openPurchasesModal();
          break;
        case 'puntos':
          close();
          openPointsModal();
          break;
        case 'carrito':
          close();
          try { window.dispatchEvent(new CustomEvent('open-cart')); } catch (_) {}
          break;
        case 'logout':
          close();
          {
            const confirmed = await confirmAction('¿Seguro que deseas cerrar sesión?');
            if (confirmed) {
              doLogout();
            }
          }
          break;
      default:
        close();
        break;
      }
    });
  });
  // Initial badge update
  try { updateReservationsBadge(); } catch (_) {}
})();





