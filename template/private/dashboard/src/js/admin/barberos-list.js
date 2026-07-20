(function adminBarbersList() {
  const wrapper = document.querySelector('.admin-barbers');
  const list = document.querySelector('[data-admin-barber-list]');
  if (!wrapper || !list) return;
  const countEl = wrapper.querySelector('.admin-barbers-count');

  let servicesMap = {};
  try {
    servicesMap = JSON.parse(wrapper.getAttribute('data-admin-barber-services') || '{}') || {};
  } catch (_) {
    servicesMap = {};
  }

  let emptyMsg = list.querySelector('.admin-barbers-empty');
  if (!emptyMsg) {
    emptyMsg = document.createElement('p');
    emptyMsg.className = 'muted admin-barbers-empty';
    emptyMsg.hidden = true;
    list.appendChild(emptyMsg);
  }

  const getItems = () => Array.from(list.querySelectorAll('[data-admin-barber-item]'));
  const updateCount = () => {
    if (countEl) countEl.textContent = 'Total: ' + getItems().length;
  };
  const ensureEmptyState = (items) => {
    if (!emptyMsg) return;
    if (!items.length) {
      emptyMsg.textContent = 'No hay profesionales registrados todavia.';
      emptyMsg.hidden = false;
    } else {
      emptyMsg.hidden = true;
    }
  };

  const toSkillNames = (skillsRaw) => {
    if (!skillsRaw) return [];
    const ids = [];
    if (Array.isArray(skillsRaw)) {
      skillsRaw.forEach((val) => {
        const id = String(val || '').trim();
        if (id) ids.push(id);
      });
    } else {
      String(skillsRaw)
        .replace(/;/g, ',')
        .split(',')
        .forEach((val) => {
          const id = String(val || '').trim();
          if (id) ids.push(id);
        });
    }
    const names = [];
    ids.forEach((id) => {
      if (Object.prototype.hasOwnProperty.call(servicesMap, id)) {
        names.push(servicesMap[id]);
      }
    });
    return names;
  };

  const formatCommission = (value) => {
    if (value === null || value === undefined || value === '') return null;
    const normalized = Number(String(value).replace(',', '.'));
    if (!Number.isFinite(normalized)) return null;
    const rounded = Math.round(normalized * 10) / 10;
    return Number.isInteger(rounded) ? String(rounded) : rounded.toFixed(1).replace(/\.0$/, '');
  };

  const createCard = (barber) => {
    if (!barber || (!barber.ID_Barber && !barber.id)) return null;
    const id = barber.ID_Barber || barber.id;
    const nombre = (barber.Nombre || barber.nombre || '').toString().trim();
    const apellido = (barber.Apellido || barber.apellido || '').toString().trim();
    const fullname = (nombre + ' ' + apellido).trim() || 'Profesional sin nombre';
    const cedula = (barber.Cedula || barber.cedula || '').toString().trim();
    const rol = (barber.Rol || barber.rol || '').toString().trim();
    const dispo = (barber.Disponibilidad || barber.disponibilidad || '').toString().trim();
    const status = (barber.Status || barber.status || '').toString().trim();
    const statusLower = status.toLowerCase();
    const habilidades = barber.Habilidades || barber.habilidades || '';
    const habilidadesNames = toSkillNames(habilidades);
    const commissionFormatted = formatCommission(barber.Comision || barber.comision || '');

    let photo = (barber.Perfil || barber.perfil || '').toString().trim().replace(/\\/g, '/');
    let photoUrl = '';
    if (photo) {
      if (/^https?:\/\//i.test(photo)) photoUrl = photo;
      else photoUrl = `../../../${photo.replace(/^\/+/, '')}`;
    }
    const initials = (() => {
      const parts = fullname.split(/\s+/).filter(Boolean);
      if (parts.length === 0) return 'B';
      const first = parts[0]?.charAt(0) || '';
      const second = parts[1]?.charAt(0) || '';
      const combo = (first + second).toUpperCase();
      return combo || first.toUpperCase() || 'B';
    })();
    const fallbackPhoto = `../../../src/img/users/default.php?n=${encodeURIComponent(fullname || 'B')}`;

    const article = document.createElement('article');
    article.className = 'admin-barber-card';
    article.setAttribute('data-admin-barber-item', '');
    article.setAttribute('data-admin-barber-id', String(id));

    const info = document.createElement('div');
    info.className = 'admin-barber-info';

    const avatar = document.createElement('div');
    avatar.className = 'admin-barber-avatar';
    if (photoUrl) {
      const img = document.createElement('img');
      img.loading = 'lazy';
      img.alt = fullname;
      img.src = photoUrl;
      img.addEventListener('error', () => { img.src = fallbackPhoto; });
      avatar.appendChild(img);
    } else {
      const span = document.createElement('span');
      span.textContent = initials;
      avatar.appendChild(span);
    }

    const meta = document.createElement('div');
    meta.className = 'admin-barber-meta';
    const nameEl = document.createElement('p');
    nameEl.className = 'admin-barber-name';
    nameEl.textContent = fullname;

    const sub = document.createElement('p');
    sub.className = 'admin-barber-sub';
    if (cedula) {
      const span = document.createElement('span');
      span.textContent = `CI ${cedula}`;
      sub.appendChild(span);
    }
    if (rol) {
      const span = document.createElement('span');
      span.textContent = `Rol: ${rol}`;
      sub.appendChild(span);
    }
    if (dispo) {
      const span = document.createElement('span');
      span.textContent = dispo;
      sub.appendChild(span);
    }
    if (commissionFormatted !== null) {
      const span = document.createElement('span');
      span.textContent = `Comisión ${commissionFormatted}%`;
      sub.appendChild(span);
    }

    meta.appendChild(nameEl);
    meta.appendChild(sub);

    if (habilidadesNames.length) {
      const skillsWrap = document.createElement('p');
      skillsWrap.className = 'admin-barber-skills';
      habilidadesNames.forEach((skill) => {
        const tag = document.createElement('span');
        tag.textContent = skill;
        skillsWrap.appendChild(tag);
      });
      meta.appendChild(skillsWrap);
    }

    if (status) {
      const badge = document.createElement('span');
      badge.className = `admin-barber-status admin-barber-status--${statusLower}`;
      badge.textContent = status;
      meta.appendChild(badge);
    }

    info.appendChild(avatar);
    info.appendChild(meta);

    const actions = document.createElement('div');
    actions.className = 'admin-barber-actions';

    const editBtn = document.createElement('button');
    editBtn.type = 'button';
    editBtn.className = 'admin-barber-edit';
    editBtn.setAttribute('data-admin-barber-edit', String(id));
    editBtn.setAttribute('aria-label', 'Editar profesional');
    editBtn.innerHTML = '<i class="bx bx-edit-alt"></i>';

    const deleteBtn = document.createElement('button');
    deleteBtn.type = 'button';
    deleteBtn.className = 'admin-barber-delete';
    deleteBtn.setAttribute('data-admin-barber-delete', String(id));
    deleteBtn.setAttribute('aria-label', 'Eliminar profesional');
    deleteBtn.innerHTML = '<i class="bx bx-trash"></i>';

    actions.appendChild(editBtn);
    actions.appendChild(deleteBtn);

    article.appendChild(info);
    article.appendChild(actions);
    return article;
  };

  const addItem = (barber) => {
    const card = createCard(barber);
    if (!card) return;
    list.prepend(card);
    updateCount();
    ensureEmptyState(getItems());
  };

  const updateItem = (barber) => {
    if (!barber) return;
    const id = barber.ID_Barber || barber.id;
    const card = createCard(barber);
    if (!card || !id) return;
    const target = list.querySelector(`[data-admin-barber-id="${String(id)}"]`);
    if (target) {
      target.replaceWith(card);
    } else {
      list.prepend(card);
    }
    updateCount();
    ensureEmptyState(getItems());
  };

  updateCount();
  ensureEmptyState(getItems());

  list.addEventListener('click', async (evt) => {
    const target = evt.target;
    if (!target || !target.closest) return;
    const btnDelete = target.closest('[data-admin-barber-delete]');
    if (!btnDelete || !list.contains(btnDelete)) return;
    const item = btnDelete.closest('[data-admin-barber-item]');
    if (!item) return;
    const id = btnDelete.getAttribute('data-admin-barber-delete');
    if (!id) return;
    const nameEl = item.querySelector('.admin-barber-name');
    const name = nameEl ? nameEl.textContent.trim() : 'este profesional';
    const proceed = await adminConfirm({
      title: 'Eliminar profesional',
      message: `\u00bfEliminar al profesional "${name}"? Esta accion no se puede deshacer.`,
      confirmText: 'Eliminar',
    });
    if (!proceed) return;
    btnDelete.disabled = true;
    try {
      const res = await fetch('../../../src/API/Autoload.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ action: 'delete', table: 'barberos', id })
      });
      const payload = await res.json().catch(() => null);
      if (!res.ok || !payload || !payload.ok) {
        throw new Error('delete-failed');
      }
      item.remove();
      updateCount();
      ensureEmptyState(getItems());
      adminNotify('Profesional eliminado correctamente.', 'success');
    } catch (_) {
      adminNotify('No se pudo eliminar el profesional.', 'error');
      if (btnDelete.isConnected) btnDelete.disabled = false;
      return;
    }
    if (btnDelete.isConnected) btnDelete.disabled = false;
  });

  window.__adminBarbers = {
    addItem,
    updateItem,
    servicesMap,
    createCard,
    refresh: () => { updateCount(); ensureEmptyState(getItems()); },
  };
})();

