(function adminClientsList() {
  const list = document.querySelector('[data-admin-client-list]');
  if (!list) return;
  const countEl = document.querySelector('[data-admin-client-count]')
    || document.querySelector('.admin-clients-count');
  const getItems = () => Array.from(list.querySelectorAll('[data-admin-client-item]'));

  const parseMaxClients = () => {
    if (!countEl) return null;
    const raw = countEl.getAttribute('data-max-clients');
    if (raw === null || raw === '' || raw === 'null' || raw === '∞') return null;
    const n = parseInt(raw, 10);
    return Number.isFinite(n) && n >= 0 ? n : null;
  };

  const formatRegisteredLabel = (count) => {
    if (typeof window.adminFormatRegisteredLabel === 'function') {
      return window.adminFormatRegisteredLabel(count, parseMaxClients());
    }
    const max = parseMaxClients();
    if (max === null) return 'Registrados ' + count;
    return 'Registrados ' + count + '/' + max;
  };

  let emptyMsg = list.querySelector('.admin-clients-empty');
  if (!emptyMsg) {
    emptyMsg = document.createElement('p');
    emptyMsg.className = 'muted admin-clients-empty';
    emptyMsg.hidden = true;
    list.appendChild(emptyMsg);
  }

  const updateCount = () => {
    if (countEl) countEl.textContent = formatRegisteredLabel(getItems().length);
  };

  const updateEmptyState = () => {
    if (!emptyMsg) return;
    const items = getItems();
    if (items.length === 0) {
      emptyMsg.textContent = 'No hay clientes registrados todavia.';
      emptyMsg.hidden = false;
    } else {
      emptyMsg.hidden = true;
    }
  };

  const escapeHtml = (value) => {
    return String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  };

  const buildMetaSpans = (parts) => {
    return parts.filter(Boolean).map((part) => `<span>${escapeHtml(part)}</span>`).join('');
  };

  const computeInitials = (name) => {
    const cleaned = (name || '').trim();
    if (!cleaned) return 'U';
    const segments = cleaned.split(/\s+/).filter(Boolean);
    if (segments.length === 0) return cleaned.charAt(0).toUpperCase() || 'U';
    if (segments.length === 1) return segments[0].charAt(0).toUpperCase() || 'U';
    return (segments[0].charAt(0) + segments[1].charAt(0)).toUpperCase();
  };

  const normalizePhoto = (photo) => {
    if (!photo) return '';
    const cleaned = String(photo).trim().replace(/\\/g, '/');
    if (!cleaned) return '';
    if (/^https?:\/\//i.test(cleaned) || cleaned.startsWith('/')) {
      return cleaned;
    }
    return '../../../' + cleaned.replace(/^\/+/, '');
  };

  const buildClientCard = (client) => {
    const id = client?.ID_Cliente;
    if (!id && id !== 0) return null;
    const name = (client.Nombre || '').toString().trim() || 'Cliente sin nombre';
    const email = (client.Email || '').toString().trim();
    const telefono = (client.Telefono || '').toString().trim();
    const cedula = (client.Cedula || '').toString().trim();
    const perfil = normalizePhoto(client.Perfil);
    const initials = computeInitials(name);
    const fallback = '../../../src/img/users/default.php?n=' + encodeURIComponent(name || 'U');
    const card = document.createElement('article');
    card.className = 'admin-client-card';
    card.setAttribute('data-admin-client-item', '');
    card.setAttribute('data-admin-client-id', String(id));
    card.setAttribute('data-admin-client-name', name.toLowerCase());
    const metaHtml = buildMetaSpans([
      email !== '' ? email : '',
      telefono !== '' ? telefono : '',
      cedula !== '' ? 'CI ' + cedula : '',
    ]);
    card.innerHTML = `
      <div class="admin-client-info">
        <div class="admin-client-avatar">
          ${perfil
            ? `<img src="${escapeHtml(perfil)}" alt="${escapeHtml(name)}" loading="lazy" onerror="this.onerror=null;this.src='${escapeHtml(fallback)}';">`
            : `<span>${escapeHtml(initials)}</span>`}
        </div>
        <div class="admin-client-meta">
          <p class="admin-client-name">${escapeHtml(name)}</p>
          <p class="admin-client-sub">${metaHtml || ''}</p>
        </div>
      </div>
      <div class="admin-client-actions">
        <button type="button" class="admin-client-edit" data-admin-client-edit="${escapeHtml(String(id))}" aria-label="Editar cliente">
          <i class="bx bx-edit-alt"></i>
        </button>
        <button type="button" class="admin-client-delete" data-admin-client-delete="${escapeHtml(String(id))}" aria-label="Eliminar cliente">
          <i class="bx bx-trash"></i>
        </button>
      </div>
    `;
    return card;
  };

  const upsertClient = (client) => {
    const id = client?.ID_Cliente;
    if (!id && id !== 0) return;
    const existing = list.querySelector(`[data-admin-client-item][data-admin-client-id="${id}"]`);
    const card = buildClientCard(client);
    if (!card) return;
    if (existing) {
      list.replaceChild(card, existing);
    } else {
      list.prepend(card);
    }
    updateCount();
    updateEmptyState();
  };

  const removeClient = (id) => {
    const item = list.querySelector(`[data-admin-client-item][data-admin-client-id="${id}"]`);
    if (item) {
      item.remove();
    }
    updateCount();
    updateEmptyState();
  };

  updateCount();
  updateEmptyState();

  list.addEventListener('click', async (evt) => {
    const target = evt.target;
    if (!target || !target.closest) return;
    const btn = target.closest('[data-admin-client-delete]');
    if (!btn || !list.contains(btn)) return;
    const item = btn.closest('[data-admin-client-item]');
    if (!item) return;
    const id = btn.getAttribute('data-admin-client-delete');
    if (!id) return;
    const nameEl = item.querySelector('.admin-client-name');
    const name = nameEl ? nameEl.textContent.trim() : 'este cliente';
    const proceed = await adminConfirm({
      title: 'Eliminar cliente',
      message: `\u00bfEliminar al cliente "${name}"? Esta accion no se puede deshacer.`,
      confirmText: 'Eliminar',
    });
    if (!proceed) return;
    btn.disabled = true;
    try {
      const res = await fetch('../../../src/API/Autoload.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ action: 'delete', table: 'clientes', id })
      });
      const payload = await res.json().catch(() => null);
      if (!res.ok || !payload || !payload.ok) {
        throw new Error('delete-failed');
      }
      removeClient(id);
      adminNotify('Cliente eliminado correctamente.', 'success');
    } catch (_) {
      adminNotify('No se pudo eliminar el cliente.', 'error');
      if (btn.isConnected) btn.disabled = false;
      return;
    }
    if (btn.isConnected) btn.disabled = false;
  });

  window.AdminClientsList = {
    upsert: upsertClient,
    remove: removeClient,
    refresh: () => {
      updateCount();
      updateEmptyState();
    },
  };
})();

