const adminNotify = (message, icon = 'success') => {
  if (typeof window.AdminNotify === 'function') {
    window.AdminNotify(message, icon);
  } else if (icon === 'error') {
    console.error(message);
  } else {
    console.log(message);
  }
};

window.AdminApplyResponsiveTableHeadings = () => {
  document.querySelectorAll('.table').forEach((table) => {
    const headers = Array.from(table.querySelectorAll('thead th')).map((th) => th.textContent.trim());
    if (!headers.length) return;
    table.querySelectorAll('tbody tr').forEach((row) => {
      Array.from(row.children).forEach((cell, index) => {
        if (!cell || cell.tagName !== 'TD') return;
        const label = headers[index] || '';
        if (label) {
          cell.setAttribute('data-heading', label);
        }
      });
    });
  });
};

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', window.AdminApplyResponsiveTableHeadings, { once: true });
} else {
  window.AdminApplyResponsiveTableHeadings();
}

const createConfirmModal = () => {
  const wrapper = document.createElement('div');
  wrapper.innerHTML = `
    <div class="modal" role="dialog" aria-modal="true" data-admin-confirm hidden>
      <div class="modal__backdrop" data-admin-confirm-cancel></div>
      <div class="modal__dialog modal__dialog--sm">
        <header class="modal__header">
          <div class="modal__header-text">
            <p class="modal__eyebrow">Confirmacion</p>
            <h2 class="modal__title" data-admin-confirm-title>Confirmar accion</h2>
          </div>
        </header>
        <div class="modal__body">
          <p data-admin-confirm-message></p>
        </div>
        <footer class="modal__footer">
          <button type="button" class="btn btn-muted" data-admin-confirm-cancel>Cancelar</button>
          <button type="button" class="btn btn-primary" data-admin-confirm-accept>Aceptar</button>
        </footer>
      </div>
    </div>
  `;
  return wrapper.firstElementChild;
};

let confirmRefs = null;
const ensureConfirmRefs = () => {
  if (confirmRefs) {
    return confirmRefs;
  }
  if (!document || !document.body) {
    return null;
  }
  const modal = createConfirmModal();
  document.body.appendChild(modal);
  confirmRefs = {
    modal,
    title: modal.querySelector('[data-admin-confirm-title]'),
    message: modal.querySelector('[data-admin-confirm-message]'),
    accept: modal.querySelector('[data-admin-confirm-accept]'),
    cancelBtns: modal.querySelectorAll('[data-admin-confirm-cancel]'),
  };
  return confirmRefs;
};

const adminConfirm = (options = {}) => {
  const defaults = {
    title: 'Confirmar accion',
    message: 'Deseas continuar?',
    confirmText: 'Aceptar',
    cancelText: 'Cancelar',
  };
  const settings = { ...defaults, ...options };
  return new Promise((resolve) => {
    if (!document || (document.readyState === 'loading' && !document.body)) {
      const fallback = typeof window.confirm === 'function'
        ? window.confirm(settings.message)
        : true;
      resolve(fallback);
      return;
    }
    const refs = ensureConfirmRefs();
    if (!refs) {
      resolve(true);
      return;
    }
    const { modal, title, message, accept, cancelBtns } = refs;
    const prevActive = document.activeElement;
    let settled = false;
    const cleanup = (result) => {
      if (settled) return;
      settled = true;
      modal.classList.remove('is-visible');
      window.setTimeout(() => { modal.hidden = true; }, 180);
      document.removeEventListener('keydown', onKeyDown);
      accept.removeEventListener('click', onAccept);
      cancelBtns.forEach((btn) => btn.removeEventListener('click', onCancel));
      if (prevActive && typeof prevActive.focus === 'function') {
        prevActive.focus({ preventScroll: true });
      }
      resolve(result);
    };
    const onAccept = () => cleanup(true);
    const onCancel = () => cleanup(false);
    const onKeyDown = (evt) => {
      if (evt.key === 'Escape') {
        evt.preventDefault();
        cleanup(false);
      }
    };
    if (title) title.textContent = settings.title;
    if (message) message.textContent = settings.message;
    if (accept) accept.textContent = settings.confirmText;
    cancelBtns.forEach((btn) => {
      if (btn.tagName === 'BUTTON') {
        btn.textContent = settings.cancelText;
      }
    });
    accept.addEventListener('click', onAccept);
    cancelBtns.forEach((btn) => btn.addEventListener('click', onCancel));
    document.addEventListener('keydown', onKeyDown);
    modal.hidden = false;
    window.requestAnimationFrame(() => {
      modal.classList.add('is-visible');
      accept.focus({ preventScroll: true });
    });
  });
};

window.adminNotify = adminNotify;
window.AdminNotifyFallback = adminNotify;
window.adminConfirm = adminConfirm;
window.AdminConfirm = adminConfirm;

/**
 * Shared usage label for plan-limited lists (clientes, productos, …).
 * max === null → unlimited → "Registrados {n}"
 * otherwise → "Registrados {n}/{max}"
 */
window.adminFormatRegisteredLabel = (count, max) => {
  const n = Number(count) || 0;
  if (max === null || max === undefined || max === '' || max === '∞') {
    return 'Registrados ' + n;
  }
  const m = parseInt(max, 10);
  if (!Number.isFinite(m) || m < 0) return 'Registrados ' + n;
  return 'Registrados ' + n + '/' + m;
};
