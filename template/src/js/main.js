let bookingModalController = null;
// Simple carousel scroll controls
(() => {
  const carousels = document.querySelectorAll('[data-carousel]');
  carousels.forEach((carousel) => {
    const track = carousel.querySelector('[data-track]');
    const prev = carousel.querySelector('[data-dir="-1"]');
    const next = carousel.querySelector('[data-dir="1"]');
    if (!track) return;

    const scrollByCard = () => {
      const firstCard = track.querySelector('.slide');
      if (!firstCard) return 300;
      const width = firstCard.getBoundingClientRect().width;
      const gap = parseFloat(window.getComputedStyle(track).columnGap || 0) ||
                  parseFloat(window.getComputedStyle(track).gap || 0) || 0;
      return Math.ceil(width + gap);
    };

    const scroll = (dir) => {
      track.scrollBy({ left: dir * scrollByCard(), behavior: 'smooth' });
    };

    const delay = Number.parseInt(carousel.dataset.autoplayDelay || '', 10) || 7000;
    let autoTimer = null;

    const stopAuto = () => {
      if (!autoTimer) return;
      clearInterval(autoTimer);
      autoTimer = null;
    };

    const startAuto = () => {
      if (autoTimer || delay <= 0) return;
      autoTimer = setInterval(() => scroll(1), delay);
    };

    const handleManualScroll = (dir) => {
      scroll(dir);
      stopAuto();
      startAuto();
    };

    prev && prev.addEventListener('click', () => handleManualScroll(-1));
    next && next.addEventListener('click', () => handleManualScroll(1));

    carousel.addEventListener('mouseenter', stopAuto);
    carousel.addEventListener('mouseleave', startAuto);

    startAuto();
  });
})();

// Lightweight modal manager used by payment/login forms
const modalManager = (() => {
  const root = document.querySelector('[data-modal-root]');
  if (!root) return null;

  const body = document.body;
  let openCount = 0;

  const getModal = (key) => root.querySelector(`[data-modal="${key}"]`);

  const applyScrollLock = () => {
    body.style.overflow = openCount > 0 ? 'hidden' : '';
  };

  // Runtime UI helpers and state utilities
  let usedFallback = false;

  const setMessage = (text) => {
    if (messageEl) messageEl.textContent = String(text || '');
  };

  const setBusy = (busy) => {
    if (!resultsEl) return;
    resultsEl.setAttribute('aria-busy', busy ? 'true' : 'false');
    resultsEl.classList.toggle('is-loading', !!busy);
  };

  const renderStatus = (text, kind = 'info') => {
    if (!resultsEl) return 0;
    const safe = String(text || '');
    resultsEl.innerHTML = `<div class="schedule-status schedule-status--${kind}">${safe}</div>`;
    return 0;
  };

  const currentServiceName = () => {
    if (!serviceSelect) return 'Servicio';
    const opt = serviceSelect.selectedOptions && serviceSelect.selectedOptions[0];
    const label = opt ? (opt.textContent || '') : '';
    return (label.split(' - ')[0] || 'Servicio').trim() || 'Servicio';
  };

  const clearSelection = ({ preserveMessage } = { preserveMessage: false }) => {
    state.selected = { barberId: null, slot: null, barberName: null };
    if (state.selectedButton) {
      state.selectedButton.classList.remove('is-selected');
      state.selectedButton = null;
    }
    updateCTA();
    if (!preserveMessage) setMessage('Selecciona una fecha o servicio para ver la disponibilidad.');
  };

  const updateCTA = () => {
    if (!bookButton) return;
    const enabled = !!(state.selected && state.selected.slot && state.selected.barberId);
    bookButton.disabled = !enabled;
  };

  const renderSlotButtons = (container, item) => {
    if (!container) return 0;
    container.innerHTML = '';
    let count = 0;
    const slots = Array.isArray(item.slots) ? item.slots : [];
    slots.forEach((slot) => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'slot-btn';
      btn.textContent = slot;
      btn.addEventListener('click', () => {
        if (state.selectedButton) state.selectedButton.classList.remove('is-selected');
        state.selected = { barberId: item.barber_id, slot, barberName: item.barber };
        state.selectedButton = btn;
        btn.classList.add('is-selected');
        updateCTA();
      });
      container.appendChild(btn);
      count += 1;
    });
    return count;
  };

  const openBarberModal = (item) => {
    if (!bookingModalController || typeof bookingModalController.open !== 'function') return;
    bookingModalController.open(item);
  };

  const validateSelection = () => {
    const sel = state.selected || {};
    if (!sel.barberId || !sel.slot) return { ok: false };
    const found = (state.data || []).find((b) => b.barber_id === sel.barberId);
    if (!found) return { ok: false };
    const ok = Array.isArray(found.slots) && found.slots.includes(sel.slot);
    return { ok, barber: found };
  };

  const renderData = (items) => {
    if (!resultsEl) return 0;
    const dataset = prepareBarberDataset(items || [], { keepSlots: true });
    state.data = dataset;
    usedFallback = false;
    // Compute total available slots
    const totalSlots = dataset.reduce((acc, it) => acc + (Array.isArray(it.slots) ? it.slots.length : 0), 0);
    // If no slots, try a fallback with catalog (no slots, only profiles)
    if (dataset.length && totalSlots === 0) {
      usedFallback = true;
    }
    // Render cards
    resultsEl.innerHTML = '';
    const frag = document.createDocumentFragment();
    dataset.forEach((item) => {
      const card = document.createElement('article');
      card.className = 'barber-card';
      card.innerHTML = `
        <button class="barber-card__avatar-button" type="button" aria-label="Ver profesional">
          <span class="barber-card__avatar"><span class="barber-card__avatar-inner"></span></span>
        </button>
        <div class="barber-card__body">
          <h4 class="barber-card__name"></h4>
          <p class="barber-card__turns"></p>
          <div class="barber-card__slots"></div>
        </div>`;
      const avatarInner = card.querySelector('.barber-card__avatar-inner');
      const nameEl = card.querySelector('.barber-card__name');
      const turnsEl = card.querySelector('.barber-card__turns');
      const slotsEl = card.querySelector('.barber-card__slots');
      applyAvatar(avatarInner, item.barber, item.avatar);
      nameEl.textContent = item.barber || 'Profesional';
      turnsEl.textContent = Array.isArray(item.turns) ? item.turns.join(' ? ') : '';
      const count = renderSlotButtons(slotsEl, item);
      // Card click -> verify session, then open modal with all slots
      card.querySelector('.barber-card__avatar-button').addEventListener('click', async () => {
        const session = state.session || await getSessionStatus();
        if (!session) {
          if (authRequiredModal) modalManager.open('auth_required');
          else showLoginModal({ message: 'Necesitas tener una cuenta de cliente para reservar.' });
          return;
        }
        openBarberModal(item);
      });
      frag.appendChild(card);
    });
    resultsEl.appendChild(frag);
    return totalSlots;
  };

  const findClienteByCedula = async (cedula) => {
    try {
      const res = await fetch('src/API/Autoload.php?action=list&table=clientes', { credentials: 'same-origin' });
      if (!res.ok) return null;
      const payload = await res.json();
      const list = Array.isArray(payload && payload.data) ? payload.data : [];
      const needle = String(cedula || '').trim().toLowerCase();
      return list.find((c) => String(c.Cedula || '').trim().toLowerCase() === needle) || null;
    } catch (_) { return null; }
  };

  // NOTE: barber/staff login is handled via a separate modal (`staff_login`).

  const getSessionStatus = async () => {
    try {
    const res = await fetch('src/API/session_client.php?action=status', { credentials: 'same-origin' });
      const data = await res.json();
      if (data && data.ok && data.data) return data.data;
      return null;
    } catch (_) { return null; }
  };

  const startSession = async (cedula) => {
  const res = await fetch('src/API/session_client.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({ action: 'start', cedula }),
    });
    const payload = await res.json();
    if (!res.ok || !payload || payload.ok === false) {
      throw new Error((payload && payload.error) || 'No se pudo iniciar sesion');
    }
    return payload.data || null;
  };

  const toggleAuthUi = (sessionData) => {
    const logged = !!(sessionData && (sessionData.nombre || sessionData.cedula));
    if (headerLoginBtn) headerLoginBtn.style.display = logged ? 'none' : '';
    if (headerUser && headerUserName) {
      if (logged) {
        headerUserName.textContent = sessionData.nombre || 'Cliente';
        headerUser.setAttribute('data-user-name', sessionData.nombre || 'Cliente');
        headerUser.parentElement && (headerUser.parentElement.style.display = '');
      } else {
        headerUser.parentElement && (headerUser.parentElement.style.display = 'none');
      }
    }
    state.session = logged ? sessionData : null;
  };

  const clearConfirmQr = () => {};
  const renderConfirmQr = () => {};

  const showLoginModal = (opts = {}) => {
    const { message, cedula } = opts || {};
    if (loginMessage && message) {
      loginMessage.textContent = String(message);
      loginMessage.hidden = false;
    }
    if (loginForm && cedula) {
      const input = loginForm.querySelector('input[name="cedula"]');
      if (input) input.value = cedula;
    }
    modalManager && modalManager.open('login');
  };

  const clampDateValue = () => {
    if (!dateInput) return;
    const min = dateInput.getAttribute('min');
    const max = dateInput.getAttribute('max');
    if (!dateInput.value) dateInput.value = min || '';
    if (min && dateInput.value < min) dateInput.value = min;
    if (max && dateInput.value > max) dateInput.value = max;
  };

  const closingMap = new WeakMap();

  const cancelClosing = (modal) => {
    const record = closingMap.get(modal);
    if (!record) return;
    clearTimeout(record.timer);
    record.target.removeEventListener('animationend', record.listener);
    closingMap.delete(modal);
    modal.classList.remove('is-closing');
  };

  const finishClose = (modal) => {
    const record = closingMap.get(modal);
    if (record) {
      clearTimeout(record.timer);
      record.target.removeEventListener('animationend', record.listener);
      closingMap.delete(modal);
    }
    modal.classList.remove('is-closing');
    modal.classList.remove('is-visible');
    openCount = Math.max(0, openCount - 1);
    applyScrollLock();
  };

  const performClose = (modal) => {
    if (!modal || !modal.classList.contains('is-visible') || modal.classList.contains('is-closing')) {
      return;
    }
    modal.classList.add('is-closing');
    const dialog = modal.querySelector('.modal__dialog');
    const target = dialog || modal;
    const listener = (event) => {
      if (event && event.target !== target) return;
      if (event && event.animationName && event.animationName !== 'modal-jelly-out') return;
      finishClose(modal);
    };
    const timer = setTimeout(() => finishClose(modal), 500);
    closingMap.set(modal, { timer, listener, target });
    target.addEventListener('animationend', listener, { once: false });
  };

  const open = (key) => {
    const modal = getModal(key);
    if (!modal) return null;
    cancelClosing(modal);
    if (!modal.classList.contains('is-visible')) {
      modal.classList.add('is-visible');
      openCount += 1;
      applyScrollLock();
    }
    return modal;
  };

  const close = (keyOrModal) => {
    const modal = typeof keyOrModal === 'string' ? getModal(keyOrModal) : keyOrModal;
    if (!modal) return;
    performClose(modal);
  };

  const closeAll = () => {
    const modals = root.querySelectorAll('[data-modal].is-visible');
    if (!modals.length) {
      openCount = 0;
      applyScrollLock();
      return;
    }
    modals.forEach((modal) => performClose(modal));
  }

  root.addEventListener('click', (evt) => {
    if (evt.target.matches('[data-modal-close]')) {
      const modal = evt.target.closest('[data-modal]');
      if (modal) {
        close(modal.getAttribute('data-modal'));
      }
    }
  });

  document.addEventListener('keydown', (evt) => {
    if (evt.key === 'Escape') {
      closeAll();
    }
  });

  return { open, close, closeAll, getModal };
})();

// Booking experience + auth handling
(() => {
  const resultsEl = document.getElementById('schedule-results');
  if (!resultsEl) return;

  const messageEl = document.getElementById('schedule-message');
  const dateInput = document.getElementById('schedule-date');
  const serviceSelect = document.getElementById('schedule-service');
  const serviceLinks = document.querySelectorAll('.service-link[data-service-id]');

  const headerUser = document.getElementById('header-user');
  const headerUserName = headerUser ? headerUser.querySelector('.user-pill__name') : null;
  const headerUserContainer = document.getElementById('header-user-container');
  const headerCartBtn = document.getElementById('header-cart');
  const headerCartCount = document.getElementById('header-cart-count');
  const headerAvatarImg = document.getElementById('header-avatar');
  const headerAvatarInitials = document.getElementById('header-avatar-initials');
  const userMenuPanel = document.getElementById('header-user-panel');
  const userMenuItems = userMenuPanel
    ? Array.from(userMenuPanel.querySelectorAll('[data-user-menu]'))
    : [];
  const headerLoginBtn = document.getElementById('header-login-btn');

  const formatReservationDate = (value) => {
    const raw = (value ?? '').toString().trim();
    if (!raw) return '';
    const isoMatch = raw.match(/^(\d{4})[-\/](\d{2})[-\/](\d{2})$/);
    if (isoMatch) {
      const [, year, month, day] = isoMatch;
      return `${day}/${month}/${year}`;
    }
    return raw;
  };

  const paymentModal = modalManager && modalManager.getModal('payment');
  const clientModal = modalManager && modalManager.getModal('client');
  const loginModal = modalManager && modalManager.getModal('login');
  const authRequiredModal = modalManager && modalManager.getModal('auth_required');
  const cartAddedModal = modalManager && modalManager.getModal('cart_added');
  const cartModal = modalManager && modalManager.getModal('modal_cart');
  const cartPaymentModal = modalManager && modalManager.getModal('cart_payment');
  const cartSummaryModal = modalManager && modalManager.getModal('cart_summary');
  const confirmModal = modalManager && modalManager.getModal('confirm');
  const bookingProgressModal = modalManager && modalManager.getModal('booking-progress');
  const clientSuccessModal = modalManager && modalManager.getModal('client_success');
  const bookingProgressTitleEl = bookingProgressModal && bookingProgressModal.querySelector('[data-loading-title]');
  const bookingProgressSubtitleEl = bookingProgressModal && bookingProgressModal.querySelector('[data-loading-subtitle]');
  const bookingProgressDefaults = {
    title: bookingProgressTitleEl ? bookingProgressTitleEl.textContent.trim() : 'Guardando Reserva',
    subtitle: bookingProgressSubtitleEl ? bookingProgressSubtitleEl.textContent.trim() : 'Por Favor Espere.'
  };
  const barberModal = modalManager && modalManager.getModal('barber');
  const bookButton = barberModal ? barberModal.querySelector('[data-barber-book]') : null;

  const clientPaymentText = clientModal && clientModal.querySelector('[data-client-payment]');
  const clientForm = clientModal && clientModal.querySelector('[data-client-form]');
  const loginForm = loginModal && loginModal.querySelector('[data-login-form]');
  const loginMessage = loginModal && loginModal.querySelector('[data-login-message]');
  // Confirm modal fields
  const confirmFields = confirmModal ? {
    statusWrapper: confirmModal.querySelector('[data-confirm-status-wrapper]'),
    status: confirmModal.querySelector('[data-confirm-status]'),
    service: confirmModal.querySelector('[data-confirm-service]'),
    barber: confirmModal.querySelector('[data-confirm-barber]'),
    date: confirmModal.querySelector('[data-confirm-date]'),
    slot: confirmModal.querySelector('[data-confirm-slot]'),
    payment: confirmModal.querySelector('[data-confirm-payment]'),
    error: confirmModal.querySelector('[data-confirm-error]'),
    whatsappBtn: confirmModal.querySelector('[data-confirm-whatsapp]'),
  } : null;
  const clientSuccessLoginBtn = clientSuccessModal && clientSuccessModal.querySelector('[data-client-success-login]');
  const getBusinessWhatsappNumber = () => {
    if (typeof window === 'undefined') return '';
    const raw = (window.__BUSINESS_WHATSAPP || window.__CONTACT_WHATSAPP || '').toString();
    return raw.replace(/[^\d]/g, '');
  };
  const getClientDisplayName = () => {
    if (!state.session) return 'Cliente';
    return state.session.nombre
      || state.session.Nombre
      || state.session.name
      || state.session.cliente_nombre
      || 'Cliente';
  };
  const hideWhatsappCTA = () => {
    if (!confirmFields || !confirmFields.whatsappBtn) return;
    confirmFields.whatsappBtn.hidden = true;
    confirmFields.whatsappBtn.removeAttribute('href');
  };
  const updateWhatsappCTA = ({ dateText, timeText, serviceName }) => {
    if (!confirmFields || !confirmFields.whatsappBtn) return;
    const phoneDigits = getBusinessWhatsappNumber();
    if (!phoneDigits) {
      hideWhatsappCTA();
      return;
    }
    const clientName = getClientDisplayName();
    const safeDate = dateText || '';
    const safeTime = timeText || '';
    const safeService = serviceName || 'Servicio';
    const message = `Hola, Soy ${clientName} y Deseo confirmar mi reserva para el dÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â­a ${safeDate} ${safeTime} Servicio: ${safeService}`;
    const encoded = encodeURIComponent(message.trim());
    confirmFields.whatsappBtn.href = `https://wa.me/${phoneDigits}?text=${encoded}`;
    confirmFields.whatsappBtn.hidden = false;
  };
  hideWhatsappCTA();
  const showBookingProgress = (titleText, subtitleText) => {
    if (bookingProgressTitleEl && (titleText || bookingProgressDefaults.title)) {
      bookingProgressTitleEl.textContent = titleText || bookingProgressDefaults.title;
    }
    if (bookingProgressSubtitleEl && (subtitleText || bookingProgressDefaults.subtitle)) {
      bookingProgressSubtitleEl.textContent = subtitleText || bookingProgressDefaults.subtitle;
    }
    if (!bookingProgressModal) return;
    if (modalManager && typeof modalManager.open === 'function') {
      modalManager.open('booking-progress');
    } else {
      bookingProgressModal.classList.add('is-open');
    }
  };
  const hideBookingProgress = () => {
    if (bookingProgressTitleEl) {
      bookingProgressTitleEl.textContent = bookingProgressDefaults.title;
    }
    if (bookingProgressSubtitleEl) {
      bookingProgressSubtitleEl.textContent = bookingProgressDefaults.subtitle;
    }
    if (!bookingProgressModal) return;
    if (modalManager && typeof modalManager.close === 'function') {
      modalManager.close('booking-progress');
    } else {
      bookingProgressModal.classList.remove('is-open');
    }
  };
  const cartSummaryFields = cartSummaryModal ? {
    id: cartSummaryModal.querySelector('[data-order-id]'),
    status: cartSummaryModal.querySelector('[data-order-status]'),
    address: cartSummaryModal.querySelector('[data-order-address]'),
    datetime: cartSummaryModal.querySelector('[data-order-datetime]'),
    items: cartSummaryModal.querySelector('[data-order-items]'),
    total: cartSummaryModal.querySelector('[data-order-total]'),
  } : null;
  const cartSummaryClose = cartSummaryModal && cartSummaryModal.querySelector('[data-order-close]');
  const barberFields = barberModal ? {
    avatarInner: barberModal.querySelector("[data-barber-avatar-inner]"),
    name: barberModal.querySelector("[data-barber-name]"),
    turns: barberModal.querySelector("[data-barber-turns]"),
    slots: barberModal.querySelector("[data-barber-slots]"),
    dateInput: barberModal.querySelector('[data-barber-date]'),
    serviceSelect: barberModal.querySelector('[data-barber-service]'),
    slotSelect: barberModal.querySelector('[data-barber-slot]'),
    warning: barberModal.querySelector('[data-barber-warning]'),
    avatarTrigger: barberModal.querySelector("[data-barber-avatar-trigger]"),
    photoOverlay: barberModal.querySelector("[data-barber-photo-overlay]"),
    photoImg: barberModal.querySelector("[data-barber-photo-img]"),
    photoFallback: barberModal.querySelector("[data-barber-photo-fallback]"),
    photoFallbackInner: barberModal.querySelector("[data-barber-photo-fallback-inner]"),
    photoName: barberModal.querySelector("[data-barber-photo-name]"),
    photoTurns: barberModal.querySelector("[data-barber-photo-turns]"),
    photoClose: barberModal.querySelector("[data-barber-photo-close]"),
  } : null;


  // --- Helpers & state (scoped to booking IIFE) ---------------------------
  let usedFallback = false;

  const setMessage = (text) => {
    if (messageEl) messageEl.textContent = String(text || '');
  };

  const setBusy = (busy) => {
    if (!resultsEl) return;
    resultsEl.setAttribute('aria-busy', busy ? 'true' : 'false');
    resultsEl.classList.toggle('is-loading', !!busy);
  };

  const renderStatus = (text, kind = 'info') => {
    if (!resultsEl) return 0;
    const safe = String(text || '');
    resultsEl.innerHTML = `<div class="schedule-status schedule-status--${kind}">${safe}</div>`;
    return 0;
  };

  const currentServiceName = () => {
    if (!serviceSelect) return 'Servicio';
    const opt = serviceSelect.selectedOptions && serviceSelect.selectedOptions[0];
    const label = opt ? (opt.textContent || '') : '';
    return (label.split(' - ')[0] || 'Servicio').trim() || 'Servicio';
  };

  // Util: YYYY-MM-DD para inputs date
  const ymd = (d) => {
    const pad = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
  };

  

  const clearSelection = ({ preserveMessage } = { preserveMessage: false }) => {
    state.selected = { barberId: null, slot: null, barberName: null };
    if (state.selectedButton) {
      state.selectedButton.classList.remove('is-selected');
      state.selectedButton = null;
    }
    updateCTA();
    if (!preserveMessage) setMessage('Selecciona una fecha o servicio para ver la disponibilidad.');
  };

  const updateCTA = () => {
    if (bookingModalController && typeof bookingModalController.updateCTA === 'function') {
      bookingModalController.updateCTA();
    }
  };

  const renderSlotButtons = (container, item) => {
    if (!container) return 0;
    container.innerHTML = '';
    let count = 0;
    const slots = Array.isArray(item.slots) ? item.slots : [];
    slots.forEach((slot) => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'slot-btn';
      btn.textContent = slot;
      btn.addEventListener('click', () => {
        if (state.selectedButton) state.selectedButton.classList.remove('is-selected');
        state.selected = { barberId: item.barber_id, slot, barberName: item.barber };
        state.selectedButton = btn;
        btn.classList.add('is-selected');
        updateCTA();
      });
      container.appendChild(btn);
      count += 1;
    });
    return count;
  };

  const openBarberModal = (item) => {
    if (!bookingModalController || typeof bookingModalController.open !== 'function') return;
    bookingModalController.open(item);
  };

  const validateSelection = () => {
    const sel = state.selected || {};
    if (!sel.barberId || !sel.slot) return { ok: false };
    const found = (state.data || []).find((b) => b.barber_id === sel.barberId);
    if (!found) return { ok: false };
    const ok = Array.isArray(found.slots) && found.slots.includes(sel.slot);
    return { ok, barber: found };
  };

  const renderData = (items) => {
    if (!resultsEl) return 0;
    const dataset = prepareBarberDataset(items || [], { keepSlots: true });
    state.data = dataset;
    usedFallback = false;
    // Compute total available slots
    const totalSlots = dataset.reduce((acc, it) => acc + (Array.isArray(it.slots) ? it.slots.length : 0), 0);
    // If no slots, try a fallback with catalog (no slots, only profiles)
    if (dataset.length && totalSlots === 0) {
      usedFallback = true;
    }
    // Render cards
    resultsEl.innerHTML = '';
    const frag = document.createDocumentFragment();
    dataset.forEach((item) => {
      const card = document.createElement('article');
      card.className = 'barber-card';
      card.innerHTML = `
        <button class="barber-card__avatar-button" type="button" aria-label="Ver profesional">
          <span class="barber-card__avatar"><span class="barber-card__avatar-inner"></span></span>
        </button>
        <div class="barber-card__body">
          <h4 class="barber-card__name"></h4>
          <p class="barber-card__turns"></p>
          <div class="barber-card__actions"><button type="button" class="btn btn-outline barber-card__cta">Agendarse</button></div>
        </div>`;
      const avatarInner = card.querySelector('.barber-card__avatar-inner');
      const nameEl = card.querySelector('.barber-card__name');
      const turnsEl = card.querySelector('.barber-card__turns');
      applyAvatar(avatarInner, item.barber, item.avatar);
      nameEl.textContent = item.barber || 'Profesional';
      turnsEl.textContent = Array.isArray(item.turns) ? item.turns.join(' ? ') : '';
      // Abrir modal desde avatar o CTA, validando sesion
      const guardedOpen = async () => {
        const session = state.session || await getSessionStatus();
        if (!session) {
          if (authRequiredModal) modalManager.open('auth_required');
          else showLoginModal({ message: 'Necesitas tener una cuenta de cliente para reservar.' });
          return;
        }
        openBarberModal(item);
      };
      card.querySelector('.barber-card__avatar-button').addEventListener('click', guardedOpen);
      const cta = card.querySelector('.barber-card__cta');
      cta && cta.addEventListener('click', guardedOpen);
      frag.appendChild(card);
    });
    resultsEl.appendChild(frag);
    return totalSlots;
  };

  const findClienteByCedula = async (cedula) => {
    try {
      const res = await fetch('src/API/Autoload.php?action=list&table=clientes', { credentials: 'same-origin' });
      if (!res.ok) return null;
      const payload = await res.json();
      const list = Array.isArray(payload && payload.data) ? payload.data : [];
      const needle = String(cedula || '').trim().toLowerCase();
      return list.find((c) => String(c.Cedula || '').trim().toLowerCase() === needle) || null;
    } catch (_) { return null; }
  };

  const getSessionStatus = async () => {
    try {
  const res = await fetch('src/API/session_client.php?action=status', { credentials: 'same-origin' });
      const data = await res.json();
      if (data && data.ok && data.data) return data.data;
      return null;
    } catch (_) { return null; }
  };

  const startSession = async (cedula) => {
  const res = await fetch('src/API/session_client.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({ action: 'start', cedula }),
    });
    const payload = await res.json();
    if (!res.ok || !payload || payload.ok === false) {
      throw new Error((payload && payload.error) || 'No se pudo iniciar sesion');
    }
    return payload.data || null;
  };

  const applyHeaderAvatarFallback = () => {
    const img = headerAvatarImg;
    if (!img) return;
    const ensureDefault = () => {
      const def = (img.dataset && img.dataset.defaultAvatar) ? img.dataset.defaultAvatar : '';
      if (def && img.src !== def) { img.src = def; }
    };
    const src = (img.getAttribute('src') || '').trim();
    if (!src) { ensureDefault(); return; }
    if (img.complete && !(Number(img.naturalWidth || 0) > 0)) { ensureDefault(); }
    img.addEventListener('error', ensureDefault, { once: false });
  };

  const toggleAuthUi = (sessionData) => {
    const logged = !!(sessionData && (sessionData.nombre || sessionData.cedula));
    if (headerLoginBtn) headerLoginBtn.style.display = logged ? 'none' : '';
    if (headerUser && headerUserName) {
      if (logged) {
        headerUserName.textContent = sessionData.nombre || 'Cliente';
        headerUser.setAttribute('data-user-name', sessionData.nombre || 'Cliente');
        headerUser.parentElement && (headerUser.parentElement.style.display = '');
      } else {
        headerUser.parentElement && (headerUser.parentElement.style.display = 'none');
      }
    }
    if (headerCartBtn) headerCartBtn.style.display = logged ? '' : 'none';
    if (headerAvatarImg) headerAvatarImg.style.display = logged ? '' : 'none';
    // Ensure avatar fallback logic runs when auth state changes
    if (logged) applyHeaderAvatarFallback();
    state.session = logged ? sessionData : null;
  };

  const refreshCartBadge = async () => {
    if (!headerCartBtn || !headerCartCount) return;
    try {
      const res = await fetch('src/API/cart.php?action=get', { credentials: 'same-origin' });
      const data = await res.json();
      const qty = (data && data.cart && data.cart.totals && data.cart.totals.quantity) ? Number(data.cart.totals.quantity) : 0;
      headerCartCount.textContent = String(qty);
      headerCartCount.hidden = !(qty > 0);
    } catch (_) { /* ignore */ }
  };

  const clearConfirmQr = () => {};
  const renderConfirmQr = () => {};

  const showLoginModal = (opts = {}) => {
    const { message, cedula } = opts || {};
    if (loginMessage && message) {
      loginMessage.textContent = String(message);
      loginMessage.hidden = false;
    }
    if (loginForm && cedula) {
      const input = loginForm.querySelector('input[name="cedula"]');
      if (input) input.value = cedula;
    }
    modalManager && modalManager.open('login');
  };

  const clampDateValue = () => {
    if (!dateInput) return;
    const min = dateInput.getAttribute('min');
    const max = dateInput.getAttribute('max');
    if (!dateInput.value) dateInput.value = min || '';
    if (min && dateInput.value < min) dateInput.value = min;
    if (max && dateInput.value > max) dateInput.value = max;
  };

  // duplicate helper removed (kept single definition later)

  // duplicate (disabled)
  const prepareBarberDataset_old = (items, { keepSlots = true } = {}) => {
    const list = Array.isArray(items) ? items : [];
    const normalizeSkillIds = (value) => {
      if (value === null || value === undefined) return [];
      if (Array.isArray(value)) {
        return value
          .map((entry) => String(entry).trim())
          .filter((entry) => entry !== '');
      }
      const source = String(value || '')
        .split(/[;,]+/)
        .map((entry) => entry.trim())
        .filter(Boolean);
      return Array.from(new Set(source));
    };

    return list
      .map((item) => {
        const id = item.barber_id ?? item.ID_Barber ?? item.id ?? null;
        if (id === null || id === undefined) return null;
        const nameParts = [];
        if (item.barber) {
          nameParts.push(String(item.barber));
        } else {
          if (item.Nombre) nameParts.push(String(item.Nombre));
          if (item.Apellido) nameParts.push(String(item.Apellido));
        }
        const normalizedName = nameParts.join(' ').trim() || 'Profesional';
        let turnsSource = item.turns ?? item.Turnos ?? item.Disponibilidad ?? '';
        if (typeof turnsSource === 'string') {
          turnsSource = turnsSource
            .split(/[\/?,]+/)
            
            
            .map((token) => token.trim())
            .filter(Boolean);
        } else if (Array.isArray(turnsSource)) {
          turnsSource = turnsSource.filter(Boolean);
        } else {
          turnsSource = [];
        }
        const avatar = normalizeAvatarPath(item.avatar ?? item.Perfil ?? item.photo ?? '');
        const slots = keepSlots && Array.isArray(item.slots)
          ? item.slots.filter(Boolean)
          : [];
        const skills = normalizeSkillIds(item.skills ?? item.Habilidades ?? item.habilidades ?? item.skill_ids ?? '');
        return {
          barber_id: String(id),
          barber: normalizedName,
          slots,
          turns: turnsSource,
          avatar,
          skills,
        };
      })
      .filter(Boolean);
  };

  const loadBarberCatalog = async () => {
    if (Array.isArray(state.cachedBarberCatalog) && state.cachedBarberCatalog.length) {
      return state.cachedBarberCatalog;
    }
    try {
      const res = await fetch('src/API/Autoload.php?action=list&table=barberos');
      if (!res.ok) throw new Error('Respuesta invalida del catalogo de profesionales');
      const payload = await res.json();
      if (!payload || payload.ok === false) {
        throw new Error(payload && payload.error ? payload.error : 'Error catalogo profesionales');
      }
      const dataset = prepareBarberDataset(payload.data || [], { keepSlots: false });
      state.cachedBarberCatalog = dataset;
      return dataset;
    } catch (error) {
      console.error('No se pudo cargar el catalogo de profesionales', error);
      state.cachedBarberCatalog = [];
      return [];
    }
  };

  const applyAvatar = (innerEl, name, imageUrl) => {
    if (!innerEl) return;
    const initials = getInitials(name);
    innerEl.textContent = initials;
    innerEl.classList.remove('has-image');
    innerEl.style.backgroundImage = '';
    innerEl.style.background = colorFromName(name);
    innerEl.style.backgroundSize = '';
    innerEl.style.backgroundPosition = '';
    const url = normalizeAvatarPath(imageUrl);
    if (url) {
      innerEl.style.backgroundImage = `url("${url}")`;
      innerEl.style.backgroundSize = 'cover';
      innerEl.style.backgroundPosition = 'center';
      innerEl.classList.add('has-image');
      innerEl.textContent = '';
    }
  };
  const state = {
    data: [],
    serviceName: 'Servicio',
    selected: {
      barberId: null,
      slot: null,
      barberName: null,
    },
    selectedButton: null,
    paymentMethod: null,
    session: null,
    activeBarberId: null,
    cachedBarberCatalog: [],
    scheduleLimits: null,
  };
  let userMenuOpen = false;
  const showBarberPhoto = () => {
    if (!barberFields || !barberFields.photoOverlay) return;
    const overlay = barberFields.photoOverlay;
    overlay.hidden = false;
    requestAnimationFrame(() => {
      overlay.classList.add('is-visible');
      overlay.setAttribute('aria-hidden', 'false');
      overlay.focus({ preventScroll: true });
    });
  };

  const hideBarberPhoto = (immediate = false) => {
    if (!barberFields || !barberFields.photoOverlay) return;
    const overlay = barberFields.photoOverlay;
    const finish = () => {
      overlay.hidden = true;
      overlay.setAttribute('aria-hidden', 'true');
      overlay.removeEventListener('transitionend', finish);
      if (barberFields && barberFields.avatarTrigger && !immediate) {
        barberFields.avatarTrigger.focus({ preventScroll: true });
      }
    };
    if (immediate) {
      overlay.classList.remove('is-visible');
      finish();
      return;
    }
    if (!overlay.classList.contains('is-visible')) {
      finish();
      return;
    }
    overlay.classList.remove('is-visible');
    overlay.addEventListener('transitionend', finish);
  };

  const getInitials = (name) => {
    if (!name) return 'B';
    const cleaned = String(name).trim();
    if (!cleaned) return 'B';
    const parts = cleaned.split(/\s+/).slice(0, 2);
    return parts.map((part) => part.charAt(0).toUpperCase()).join('') || cleaned.charAt(0).toUpperCase();
  };

  const colorFromName = (name) => {
    let hash = 0;
    const str = String(name || 'barber');
    for (let i = 0; i < str.length; i += 1) {
      hash = str.charCodeAt(i) + ((hash << 5) - hash);
    }
    const hue = ((hash % 360) + 360) % 360;
    const hue2 = (hue + 35) % 360;
    return `linear-gradient(135deg, hsl(${hue}, 70%, 55%), hsl(${hue2}, 70%, 45%))`;
  };

  const normalizeAvatarPath = (path) => {
    if (!path) return '';
    return String(path).trim().replace(/\\/g, '/');
  };

  if (typeof window.initBookingModal === 'function') {
    bookingModalController = window.initBookingModal({
      modalManager,
      barberModal,
      bookButton,
      barberFields,
      dateInput,
      serviceSelect,
      state,
      applyAvatar,
      normalizeAvatarPath,
      showBarberPhoto,
      hideBarberPhoto,
    });
  };

  const prepareBarberDataset = (items, { keepSlots = true } = {}) => {
    const list = Array.isArray(items) ? items : [];
    const dayNameIndex = {
      domingo: 0,
      lunes: 1,
      martes: 2,
      miercoles: 3,
      jueves: 4,
      viernes: 5,
      sabado: 6,
    };
    const normalizeDayToken = (token) => {
      let value = String(token || '').trim().toLowerCase();
      if (!value) return '';
      if (typeof value.normalize === 'function') {
        value = value.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
      }
      return value.replace(/[^a-z]/g, '');
    };
    const toDayIndex = (token) => {
      const key = normalizeDayToken(token);
      return Object.prototype.hasOwnProperty.call(dayNameIndex, key) ? dayNameIndex[key] : null;
    };
    const parseWorkDays = (source) => {
      if (!source) return [];
      const indices = [];
      const pushIndex = (candidate) => {
        if (candidate === null || Number.isNaN(candidate)) return;
        const index = Number(candidate);
        if (index < 0 || index > 6 || indices.includes(index)) return;
        indices.push(index);
      };
      if (Array.isArray(source)) {
        source.forEach((value) => {
          if (typeof value === 'number') {
            pushIndex(value);
          } else {
            const idx = toDayIndex(value);
            if (idx !== null) pushIndex(idx);
          }
        });
        return indices;
      }
      const text = String(source || '');
      if (!text.trim()) return [];
      text
        .split(/[\/,;|]+|\s+y\s+|\s+a\s+/i)
        .filter(Boolean)
        .forEach((token) => {
          const idx = toDayIndex(token);
          if (idx !== null) pushIndex(idx);
        });
      return indices;
    };
    const normalizeSkillIds = (value) => {
      if (value === null || value === undefined) return [];
      if (Array.isArray(value)) {
        return value
          .map((entry) => String(entry).trim())
          .filter((entry) => entry !== '');
      }
      return String(value || '')
        .split(/[;,]+/)
        .map((entry) => entry.trim())
        .filter(Boolean);
    };

    return list
      .map((item) => {
        const id = item.barber_id ?? item.ID_Barber ?? item.id ?? null;
        if (id === null || id === undefined) return null;
        const nameParts = [];
        if (item.barber) {
          nameParts.push(String(item.barber));
        } else {
          if (item.Nombre) nameParts.push(String(item.Nombre));
          if (item.Apellido) nameParts.push(String(item.Apellido));
        }
        const normalizedName = nameParts.join(' ').trim() || 'Profesional';
        let turnsSource = item.turns ?? item.Turnos ?? item.Disponibilidad ?? '';
        if (Array.isArray(turnsSource)) {
          turnsSource = turnsSource.filter(Boolean);
        } else if (typeof turnsSource === 'string') {
          turnsSource = turnsSource
            .split(/[\/,\u0007,-]+/)
            .map((token) => token.trim())
            .filter(Boolean);
        } else {
          turnsSource = [];
        }
        const avatar = normalizeAvatarPath(item.avatar ?? item.Perfil ?? item.photo ?? '');
        const slots = keepSlots && Array.isArray(item.slots)
          ? item.slots.filter(Boolean)
          : [];
        const workDays = parseWorkDays(
          item.workDays
          ?? item.working_days
          ?? item.DiasTrabajo
          ?? item.diasTrabajo
          ?? item.dias_trabajo
          ?? ''
        );
        const skills = normalizeSkillIds(item.skills ?? item.Habilidades ?? item.habilidades ?? item.skill_ids ?? '');
        return {
          barber_id: String(id),
          barber: normalizedName,
          slots,
          turns: turnsSource,
          avatar,
          workDays,
          skills,
        };
      })
      .filter(Boolean);
  };
  const handleBook = () => {
    const validation = validateSelection();
    if (!validation.ok) {
      clearSelection({ preserveMessage: true });
      renderStatus('El horario elegido ya no esta disponible. Elegi otro horario.', 'error');
      setMessage('Ese horario acaba de ocuparse o ya no encaja con la duracion del servicio.');
      return;
    }

    setMessage(`Listo, ${validation.barber.barber} a las ${state.selected.slot}. Continua con la reserva.`);
    state.activeBarberId = null;
    updateCTA();
    modalManager && modalManager.close('barber');
    modalManager && modalManager.open('payment');
  };

  const loadData = async () => {
    clampDateValue();

    const params = new URLSearchParams();
    if (dateInput && dateInput.value) params.set('date', dateInput.value);
    if (serviceSelect && serviceSelect.value) {
      params.set('service_id', serviceSelect.value);
      state.serviceName = currentServiceName();
    } else {
      state.serviceName = 'Servicio';
    }

    setBusy(true);
    renderStatus('Cargando horarios...', 'loading');

    try {
      const res = await fetch(`src/API/schedule.php?${params.toString()}`);
      if (!res.ok) throw new Error('Respuesta invalida del servidor');
      const payload = await res.json();
      if (!payload || payload.ok === false) throw new Error(payload && payload.error ? payload.error : 'Error al cargar horarios');
      if (payload.date && dateInput && dateInput.value !== payload.date) dateInput.value = payload.date;
      state.scheduleLimits = payload.limits || null;
      if (payload.limits && dateInput) {
        if (payload.limits.min_date) dateInput.min = payload.limits.min_date;
        if (payload.limits.max_date) dateInput.max = payload.limits.max_date;
      }
      const total = renderData(payload.data || []);
      if (!state.selected.barberId) {
        if (total) {
          setMessage('Selecciona el profesional con el que vas a atender.');
        } else if (usedFallback) {
          setMessage('Toca un profesional para atenderte.');
        } else {
          setMessage('No hay disponibilidades para la fecha seleccionada.');
        }
      }
      
    } catch (err) {
      console.error(err);
      clearSelection({ preserveMessage: true });
      renderStatus('No se pudo cargar la disponibilidad. Intenta nuevamente.', 'error');
      setMessage('Hubo un problema al consultar los horarios.');
    } finally {
      setBusy(false);
    }
  };

  const setService = (serviceId, serviceLabel) => {
    if (!serviceSelect) return;
    const option = Array.from(serviceSelect.options).find((opt) => opt.value === String(serviceId));
    if (!option) return;
    if (serviceSelect.value !== option.value) serviceSelect.value = option.value;
    state.serviceName = serviceLabel || (option.textContent || '').split(' - ')[0] || 'Servicio';
    clearSelection({ preserveMessage: false });
    loadData();
  };

  // --- Event bindings -----------------------------------------------------
  // User menu moved to user_menu.js

  dateInput && dateInput.addEventListener('change', () => {
    clearSelection({ preserveMessage: false });
    loadData();
  });

  serviceSelect && serviceSelect.addEventListener('change', () => {
    state.serviceName = currentServiceName();
    clearSelection({ preserveMessage: false });
    loadData();
  });

  // Abrir modal de login desde el header
  if (headerLoginBtn) {
    headerLoginBtn.addEventListener('click', () => {
      showLoginModal();
    });
  }

  bookButton && bookButton.addEventListener('click', handleBook);

  serviceLinks.forEach((link) => {
    link.addEventListener('click', () => {
      const serviceId = link.getAttribute('data-service-id');
      if (!serviceId) return;
      const label = link.getAttribute('data-service-name') || '';
      setService(serviceId, label);
      document.getElementById('horarios')?.scrollIntoView({ behavior: 'smooth' });
    });
  });

  const createReservation = async () => {
    const chosenService = (barberFields && barberFields.serviceSelect && barberFields.serviceSelect.value) || (serviceSelect ? serviceSelect.value : null);
    const chosenDate = (barberFields && barberFields.dateInput && barberFields.dateInput.value) || (dateInput ? dateInput.value : null);
    const payload = {
      action: 'create',
      service_id: chosenService,
      barber_id: state.selected.barberId,
      fecha: chosenDate,
      hora: state.selected.slot,
      status: 'Aprobado',
    };
    const res = await fetch('src/API/reservas.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify(payload),
    });
    const data = await res.json();
    if (!res.ok || !data || data.ok === false) {
      throw new Error((data && data.error) || 'No se pudo crear la reserva');
    }
    return {
      reservation: data.data || {},
      session: data.session || null,
    };
  };
  if (paymentModal) {
    paymentModal.querySelectorAll('.payment-btn').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const method = btn.dataset.payment || '';
        state.paymentMethod = method;
        modalManager.close('payment');
        showBookingProgress('Guardando Reserva', 'Por favor espere.');
        let session;
        try {
          session = state.session || await getSessionStatus();
        } catch (sessionError) {
          console.error('No se pudo verificar la sesiÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â³n:', sessionError);
          hideBookingProgress();
          publicAlert('No se pudo verificar la sesiÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â³n. Intenta nuevamente.');
          return;
        }

        if (session) {
          try {
            const { reservation, session: updatedSession } = await createReservation();
            if (updatedSession) {
              state.session = updatedSession;
            } else if (reservation) {
              if (!state.session) state.session = {};
              if (!Array.isArray(state.session.reservas)) {
                state.session.reservas = [];
              }
              state.session.reservas.push(reservation);
            }
            hideBookingProgress();
            if (confirmModal && confirmFields) {
              const fallbackDate = (barberFields && barberFields.dateInput && barberFields.dateInput.value)
                || (dateInput ? dateInput.value : '');
              const rawReservationDate = reservation
                && (reservation.Fecha_Reserva
                  || reservation.fecha
                  || reservation.fecha_reserva
                  || reservation.date
                  || reservation.date_reservation);
              const displayDate = formatReservationDate(rawReservationDate || fallbackDate);

              if (confirmFields.status) confirmFields.status.textContent = 'Reserva Finalizada';
              if (confirmFields.statusWrapper) {
                confirmFields.statusWrapper.classList.remove('is-error');
                confirmFields.statusWrapper.classList.add('is-success');
              }
              confirmFields.service && (confirmFields.service.textContent = state.serviceName);
              confirmFields.barber && (confirmFields.barber.textContent = state.selected.barberName || '');
              confirmFields.date && (confirmFields.date.textContent = displayDate);
              confirmFields.slot && (confirmFields.slot.textContent = state.selected.slot || '');
              confirmFields.payment && (confirmFields.payment.textContent = state.paymentMethod || '');
              if (confirmFields.error) { confirmFields.error.hidden = true; confirmFields.error.textContent = ''; }
              updateWhatsappCTA({
                dateText: displayDate,
                timeText: state.selected.slot || '',
                serviceName: state.serviceName,
              });
              modalManager.open('confirm');
            }
            clearSelection({ preserveMessage: false });
            loadData();
          } catch (err) {
            hideBookingProgress();
            console.error('Reserva fallida:', err);
            if (confirmModal && confirmFields) {
              if (confirmFields.status) confirmFields.status.textContent = 'Error al reservar';
              if (confirmFields.statusWrapper) {
                confirmFields.statusWrapper.classList.add('is-error');
                confirmFields.statusWrapper.classList.remove('is-success');
              }
              confirmFields.service && (confirmFields.service.textContent = state.serviceName);
              confirmFields.barber && (confirmFields.barber.textContent = state.selected.barberName || '');
              const fallbackDate = (barberFields && barberFields.dateInput && barberFields.dateInput.value)
                || (dateInput ? dateInput.value : '');
              confirmFields.date && (confirmFields.date.textContent = formatReservationDate(fallbackDate));
              confirmFields.slot && (confirmFields.slot.textContent = state.selected.slot || '');
              confirmFields.payment && (confirmFields.payment.textContent = state.paymentMethod || '');
              if (confirmFields.error) {
                confirmFields.error.textContent = (err && err.message) ? String(err.message) : 'Intenta nuevamente.';
                confirmFields.error.hidden = false;
              }
              hideWhatsappCTA();
              clearConfirmQr();
              modalManager.open('confirm');
            } else {
              publicAlert('No se pudo crear la reserva. Intenta nuevamente.');
            }
          }
        } else {
          hideBookingProgress();
          if (clientPaymentText) clientPaymentText.textContent = method;
          modalManager.open('client');
        }
      });
    });
  }

  if (clientForm) {
    clientForm.addEventListener('submit', async (evt) => {
      evt.preventDefault();
      const formData = new FormData(clientForm);
      const cedula = (formData.get('cedula') || '').trim();
      const existing = await findClienteByCedula(cedula);
      if (existing) {
        modalManager.close('client');
        showLoginModal({
          message: 'Parece que este usuario ya esta registrado, inicia sesion para continuar',
          cedula,
        });
        return;
      }

      // Insertar nuevo cliente en la DB (con Perfil = null)
      const newClient = {
        Nombre: String(formData.get('nombre') || ''),
        Cedula: cedula,
        Telefono: String(formData.get('telefono') || ''),
        Email: String(formData.get('email') || ''),
        Perfil: null,
      };
      showBookingProgress('Registrando cliente', 'Por favor espere.');
      try {
        const res = await fetch('src/API/Autoload.php?action=insert&table=clientes', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          credentials: 'same-origin',
          body: JSON.stringify({ data: newClient }),
        });
        const payload = await res.json();
        if (!res.ok || !payload || payload.ok === false || !payload.data) {
          throw new Error((payload && payload.error) || 'No se pudo registrar el cliente');
        }
        const created = payload.data;
        const newId = created.ID_Cliente || created.id || created.ID || null;
        // Crear registro de puntos asociado al cliente
        if (newId != null) {
          try {
            await fetch('src/API/Autoload.php?action=insert&table=puntos', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              credentials: 'same-origin',
              body: JSON.stringify({ data: { ID_Client: String(newId), Total: 0, Estado: 'Activo' } }),
            });
          } catch (_) { /* ignore points insertion errors */ }
        }
        hideBookingProgress();
        if (modalManager) {
          modalManager.close('client');
        }
        clientForm.reset();
        if (clientSuccessModal && modalManager && typeof modalManager.open === 'function') {
          modalManager.open('client_success');
        } else {
          publicAlert('Cliente registrado correctamente. Ahora puedes iniciar sesiÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â³n.');
        }
      } catch (err) {
        hideBookingProgress();
        publicAlert((err && err.message) || 'No se pudo registrar el cliente');
      }
    });
  }

  if (clientModal) {
    const loginBtn = clientModal.querySelector('[data-client-login]');
    loginBtn && loginBtn.addEventListener('click', () => {
      modalManager.close('client');
      showLoginModal();
    });
  }

  if (clientSuccessLoginBtn) {
    clientSuccessLoginBtn.addEventListener('click', () => {
      if (modalManager) {
        modalManager.close('client_success');
      }
      showLoginModal();
    });
  }

  // Desde el modal de login, ir al registro de cliente
  if (loginModal) {
    const registerLink = loginModal.querySelector('[data-login-register]');
    registerLink && registerLink.addEventListener('click', () => {
      modalManager.close('login');
      modalManager.open('client');
    });
  }

  // Modal "Acceso requerido" -> botones
  if (authRequiredModal) {
    const goLogin = authRequiredModal.querySelector('[data-auth-login]');
    const goRegister = authRequiredModal.querySelector('[data-auth-register]');
    goLogin && goLogin.addEventListener('click', () => {
      modalManager.close('auth_required');
      showLoginModal();
    });
    goRegister && goRegister.addEventListener('click', () => {
      modalManager.close('auth_required');
      modalManager.open('client');
    });
  }

  // Header cart open (guarded by session)
  if (headerCartBtn) {
    headerCartBtn.addEventListener('click', async () => {
      const session = state.session || await getSessionStatus();
      if (!session) { if (authRequiredModal) modalManager.open('auth_required'); else showLoginModal(); return; }
      await renderCart();
      modalManager.open('modal_cart');
    });
  }

  // --- Carrito: helpers ---------------------------------------------------
  const cartListEl = cartModal && cartModal.querySelector('[data-cart-list]');
  const cartTotalEl = cartModal && cartModal.querySelector('[data-cart-total]');
  const cartClearBtn = cartModal && cartModal.querySelector('[data-cart-clear]');
  const cartCheckoutBtn = cartModal && cartModal.querySelector('[data-cart-checkout]');
  const cartAddressInput = cartModal && cartModal.querySelector('[data-cart-address]');
  const cartPickupCheckbox = cartModal && cartModal.querySelector('[data-cart-pickup]');
  const cartAddressError = cartModal && cartModal.querySelector('[data-cart-address-error]');
  const CART_PICKUP_VALUE = 'Retira en Local';
  const CART_PICKUP_DB_VALUE = 'Pasa a retirar';
  let cartIsEmpty = true;
  let cartManualAddress = '';
  let lastKnownCart = null;
  let pendingCheckoutPayload = null;

  const updateCartCheckoutState = (options = {}) => {
    const showError = options.showError === true;
    if (!cartCheckoutBtn) return;
    const pickupSelected = cartPickupCheckbox && cartPickupCheckbox.checked;
    const addressValue = cartAddressInput ? cartAddressInput.value.trim() : '';
    const hasAddress = addressValue.length > 0;
    const canCheckout = !cartIsEmpty && (pickupSelected || hasAddress);
    cartCheckoutBtn.disabled = !canCheckout;
    if (cartAddressError) {
      if (cartIsEmpty || canCheckout) {
        cartAddressError.hidden = true;
      } else {
        cartAddressError.hidden = !showError;
      }
    }
  };

  const cartGet = async () => {
    try {
      const res = await fetch('src/API/cart.php?action=get', { credentials: 'same-origin' });
      const data = await res.json();
      return (res.ok && data && data.cart) ? data.cart : { items: {}, totals: { quantity: 0, amount: 0 } };
    } catch (_) { return { items: {}, totals: { quantity: 0, amount: 0 } }; }
  };

  // Run fallback once at startup as well (in case markup is present on load)
  applyHeaderAvatarFallback();
  const cartAdd = async (productId, qty) => {
    const res = await fetch('src/API/cart.php', {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin',
      body: JSON.stringify({ action: 'add', product_id: productId, qty })
    });
    const data = await res.json();
    if (!res.ok || !data || data.ok === false) throw new Error((data && data.error) || 'No se pudo agregar');
    return data.cart;
  };
  const cartClear = async () => {
    const res = await fetch('src/API/cart.php', {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin',
      body: JSON.stringify({ action: 'clear' })
    });
    const data = await res.json();
    return (res.ok && data && data.cart) ? data.cart : { items: {}, totals: { quantity: 0, amount: 0 } };
  };

  const renderCart = async () => {
    if (!cartModal) return;
    const cart = await cartGet();
    lastKnownCart = cart;
    if (cartListEl) {
      const items = cart.items || {};
      const keys = Object.keys(items);
      if (!keys.length) {
        cartListEl.innerHTML = '<p class="muted">Tu carrito est? vac?o.</p>';
      } else {
        const frag = document.createDocumentFragment();
        cartListEl.innerHTML = '';
        keys.forEach((id) => {
          const it = items[id];
          const row = document.createElement('div');
          row.className = 'cart-item';
          row.innerHTML = `
            <div class=\"cart-item__name\">${(it.Nombre || 'Producto')}</div>
            <div class=\"cart-item__qty\">x${it.cantidad || 1}</div>
            <div class=\"cart-item__subtotal\">$${(it.subtotal || 0).toFixed(2)}</div>
          `;
          frag.appendChild(row);
        });
        cartListEl.appendChild(frag);
      }
    }
    if (cartTotalEl) {
      const total = (cart.totals && cart.totals.amount) || 0;
      cartTotalEl.textContent = 'Total: $' + Number(total).toFixed(2);
    }
    // Enable/disable action buttons depending on emptiness
    const isEmpty = !cart || !cart.items || Object.keys(cart.items).length === 0 || !(cart.totals && cart.totals.quantity > 0);
    cartIsEmpty = !!isEmpty;
    if (cartClearBtn) cartClearBtn.disabled = cartIsEmpty;
    if (cartIsEmpty) {
      if (cartPickupCheckbox) cartPickupCheckbox.checked = false;
      if (cartAddressInput) {
        cartAddressInput.disabled = false;
        cartAddressInput.value = '';
      }
      cartManualAddress = '';
      pendingCheckoutPayload = null;
    }
    updateCartCheckoutState();
  };

  const normalizeKey = (value) => {
    if (typeof value !== 'string') return '';
    if (value.normalize) {
      return value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
    }
    return value.toLowerCase();
  };

  const pickOrderField = (row, key) => {
    if (!row || typeof row !== 'object') return undefined;
    if (Object.prototype.hasOwnProperty.call(row, key)) {
      return row[key];
    }
    const target = normalizeKey(key);
    const keys = Object.keys(row);
    for (const current of keys) {
      if (normalizeKey(current) === target) {
        return row[current];
      }
    }
    return undefined;
  };

  const showOrderSummary = (orderRow, payload) => {
    if (!cartSummaryModal || !cartSummaryFields) return false;
    const summarySetText = (el, value) => {
      if (!el) return;
      el.textContent = value;
    };
    const resolveAddress = () => {
      if (payload && typeof payload.displayAddress === 'string' && payload.displayAddress !== '') {
        return payload.displayAddress;
      }
      if (!orderRow) return '-';
      const value = pickOrderField(orderRow, 'direccion');
      return value !== undefined && value !== null && String(value) !== '' ? String(value) : '-';
    };
    const statusRaw = pickOrderField(orderRow, 'status');
    const fechaRaw = pickOrderField(orderRow, 'fecha');
    const horaRaw = pickOrderField(orderRow, 'hora');
    const idRaw = pickOrderField(orderRow, 'id_carrito') ?? pickOrderField(orderRow, 'id');
    const status = statusRaw ? String(statusRaw) : 'Pendiente';
    const fecha = fechaRaw ? String(fechaRaw) : '';
    const hora = horaRaw ? String(horaRaw) : '';
    const id = idRaw;
    const itemsSnapshot = payload && payload.cart && payload.cart.items ? payload.cart.items : null;
    let totalAmount = null;
    if (payload && payload.cart && payload.cart.totals) {
      totalAmount = Number(payload.cart.totals.amount || 0);
    } else if (itemsSnapshot && Object.keys(itemsSnapshot).length) {
      totalAmount = Object.keys(itemsSnapshot).reduce((sum, key) => {
        const item = itemsSnapshot[key];
        return sum + Number(item && item.subtotal ? item.subtotal : 0);
      }, 0);
    }
    summarySetText(cartSummaryFields.id, id != null ? String(id) : '-');
    summarySetText(cartSummaryFields.status, status || 'Pendiente');
    summarySetText(cartSummaryFields.address, resolveAddress());
    const datetime = fecha && hora ? `${fecha} ${hora}` : (fecha || hora || '-');
    summarySetText(cartSummaryFields.datetime, datetime);
    if (cartSummaryFields.total) {
      cartSummaryFields.total.textContent = totalAmount !== null ? '$' + totalAmount.toFixed(2) : '-';
    }
    if (cartSummaryFields.items) {
      cartSummaryFields.items.innerHTML = '';
      const list = document.createElement('ul');
      list.className = 'order-summary-list';
      if (itemsSnapshot && Object.keys(itemsSnapshot).length) {
        Object.keys(itemsSnapshot).forEach((key) => {
          const item = itemsSnapshot[key] || {};
          const name = item.Nombre || `Producto ${key}`;
          const qty = Number(item.cantidad || 0);
          const subtotal = Number(item.subtotal || 0);
          const li = document.createElement('li');
          li.textContent = `${name} x${qty} - $${subtotal.toFixed(2)}`;
          list.appendChild(li);
        });
      } else if (orderRow) {
        const combos = pickOrderField(orderRow, 'id_producto + cantidad');
        const li = document.createElement('li');
        li.textContent = combos ? String(combos) : 'Sin detalle';
        list.appendChild(li);
      } else {
        const li = document.createElement('li');
        li.textContent = 'Sin detalle';
        list.appendChild(li);
      }
      cartSummaryFields.items.appendChild(list);
    }
    modalManager.open('cart_summary');
    return true;
  };

  const openCart = async () => {
    await renderCart();
    modalManager.open('modal_cart');
  };

  // Cart modal buttons
  if (cartModal) {
    cartClearBtn && cartClearBtn.addEventListener('click', async () => {
      await cartClear();
      await renderCart();
    });
    if (cartAddressInput) {
      cartAddressInput.addEventListener('input', () => {
        if (!cartPickupCheckbox || !cartPickupCheckbox.checked) {
          cartManualAddress = cartAddressInput.value;
        }
        updateCartCheckoutState();
      });
    }
    if (cartPickupCheckbox) {
      cartPickupCheckbox.addEventListener('change', () => {
        if (!cartAddressInput) {
          updateCartCheckoutState();
          return;
        }
        if (cartPickupCheckbox.checked) {
          if (!cartAddressInput.disabled) {
            cartManualAddress = cartAddressInput.value;
          }
          cartAddressInput.value = CART_PICKUP_VALUE;
          cartAddressInput.disabled = true;
        } else {
          cartAddressInput.disabled = false;
          if (cartAddressInput.value === CART_PICKUP_VALUE) {
            cartAddressInput.value = cartManualAddress || '';
          }
        }
        updateCartCheckoutState();
      });
    }
    cartCheckoutBtn && cartCheckoutBtn.addEventListener('click', () => {
      if (cartCheckoutBtn.disabled) {
        updateCartCheckoutState({ showError: true });
        return;
      }
      const pickup = !!(cartPickupCheckbox && cartPickupCheckbox.checked);
      const currentAddress = cartAddressInput ? cartAddressInput.value.trim() : '';
      const addressForApi = pickup ? CART_PICKUP_DB_VALUE : currentAddress;
      const addressForDisplay = pickup ? CART_PICKUP_VALUE : currentAddress;
      const cartSnapshot = lastKnownCart ? JSON.parse(JSON.stringify(lastKnownCart)) : null;
      pendingCheckoutPayload = {
        pickup,
        address: addressForApi,
        displayAddress: addressForDisplay,
        cart: cartSnapshot,
      };
      // Abrir selecciÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¯ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¿ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â½n de pago
      modalManager.close('modal_cart');
      if (cartPaymentModal) {
        modalManager.open('cart_payment');
      } else {
        updateCartCheckoutState();
      }
    });
    updateCartCheckoutState();
    // Allow open via user menu event
    window.addEventListener('open-cart', async () => {
      const session = state.session || await getSessionStatus();
      if (!session) { if (authRequiredModal) modalManager.open('auth_required'); else showLoginModal(); return; }
      await openCart();
    });
  }

  // Cart added modal buttons
  if (cartAddedModal) {
    const go = cartAddedModal.querySelector('[data-cart-go]');
    const cont = cartAddedModal.querySelector('[data-cart-continue]');
    go && go.addEventListener('click', () => { modalManager.close('cart_added'); openCart(); });
    cont && cont.addEventListener('click', () => { modalManager.close('cart_added'); });
  }

  // Cart payment modal buttons
  if (cartPaymentModal) {
    const payMp = cartPaymentModal.querySelector('[data-cart-pay-mp]');
    const payCash = cartPaymentModal.querySelector('[data-cart-pay-cash]');
    const finish = (method) => {
      modalManager.close('cart_payment');
      publicAlert('Medio de pago seleccionado: ' + method);
      pendingCheckoutPayload = null;
    };
    payMp && payMp.addEventListener('click', () => finish('Mercado Pago / Tarjeta de Credito'));
    if (payCash) {
      const originalLabel = payCash.textContent;
      const handleCashPayment = async () => {
        if (payCash.disabled) return;
        if (!pendingCheckoutPayload || !pendingCheckoutPayload.address) {
          publicAlert('No se encontro informacion del carrito. Vuelve a intentarlo.');
          modalManager.close('cart_payment');
          return;
        }
        payCash.disabled = true;
        payCash.textContent = 'Procesando...';
        showBookingProgress('Confirmando pedido', 'Por favor espere.');
        try {
          const payloadForSummary = pendingCheckoutPayload;
          const res = await fetch('src/API/cart.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({
              action: 'checkout',
              address: payloadForSummary.address,
              pickup: payloadForSummary.pickup,
            }),
          });
          const responseBody = await res.json().catch(() => null);
          if (!res.ok || !responseBody || responseBody.ok === false) {
            const errorMsg = responseBody && responseBody.error ? responseBody.error : 'No se pudo registrar el pedido';
            throw new Error(errorMsg);
          }
          pendingCheckoutPayload = null;
          modalManager.close('cart_payment');
          await renderCart();
          refreshCartBadge();
          const orderRow = responseBody.data || {};
          const summaryShown = showOrderSummary(orderRow, payloadForSummary);
          if (!summaryShown) {
            publicAlert('Pedido registrado correctamente.');
          }
        } catch (err) {
          publicAlert(err && err.message ? err.message : 'No se pudo registrar el pedido');
        } finally {
          hideBookingProgress();
          payCash.disabled = false;
          payCash.textContent = originalLabel;
        }
      };
      payCash.addEventListener('click', handleCashPayment);
    }
  }

  if (cartSummaryClose) {
    cartSummaryClose.addEventListener('click', () => {
      modalManager.close('cart_summary');
    });
  }

  // Delegated handler for "Agregar al Carrito"
  document.addEventListener('click', async (evt) => {
    const btn = evt.target && evt.target.closest && evt.target.closest('[data-add-cart]');
    if (!btn) return;
    evt.preventDefault();
    const session = state.session || await getSessionStatus();
    if (!session) {
      if (authRequiredModal) modalManager.open('auth_required');
      else showLoginModal({ message: 'Necesitas tener una cuenta de cliente para comprar.' });
      return;
    }
    const productId = btn.getAttribute('data-product-id');
    let qty = 1;
    const qtyInput = btn.closest('.slide-actions')?.querySelector('.slide-qty');
    if (qtyInput) {
      qty = Math.max(1, Math.min(10, parseInt(qtyInput.value, 10) || 1));
    }
    try {
      await cartAdd(productId, qty);
      refreshCartBadge();
      if (cartAddedModal) modalManager.open('cart_added');
    } catch (err) {
      publicAlert('No se pudo agregar al carrito');
    }
  });

  if (loginForm) {
    loginForm.addEventListener('submit', async (evt) => {
      evt.preventDefault();
      const formData = new FormData(loginForm);
      const cedula = (formData.get('cedula') || '').trim();
      const existing = await findClienteByCedula(cedula);
      if (existing) {
        try {
          const sessionData = await startSession(existing.Cedula || cedula);
          modalManager.closeAll();
          toggleAuthUi(sessionData);
          const welcomeName = sessionData.nombre || existing.Nombre || 'cliente';
          setMessage(`Bienvenido ${welcomeName}, continua con la reserva.`);
          try { sessionStorage.setItem('welcomeName', welcomeName); } catch (_) {}
          window.location.reload();
        } catch (error) {
          if (loginMessage) {
            loginMessage.textContent = error.message;
            loginMessage.hidden = false;
          }
        }
      } else if (loginMessage) {
        loginMessage.textContent = 'No encontramos un cliente con esa cedula. Intenta registrarte o selecciona Login para Funcionarios.';
        loginMessage.hidden = false;
      }
    });
  }

  // Bind button in client login modal to open staff login modal
  if (loginModal) {
    const staffBtn = loginModal.querySelector('[data-login-staff]');
    if (staffBtn) {
      staffBtn.addEventListener('click', () => {
        modalManager && modalManager.close('login');
        modalManager && modalManager.open('staff_login');
      });
    }
  }

  // Staff login handler (separate modal)
  const staffModal = modalManager && modalManager.getModal ? modalManager.getModal('staff_login') : null;
  if (staffModal) {
    const staffForm = staffModal.querySelector('[data-staff-login-form]');
    const staffMsg = staffModal.querySelector('[data-staff-login-message]');
    if (staffForm && !staffForm._bound) {
      staffForm._bound = true;
      staffForm.addEventListener('submit', async (ev) => {
        ev.preventDefault();
        const fd = new FormData(staffForm);
        const ced = (fd.get('cedula') || '').toString().trim();
        const pw = (fd.get('password') || '').toString().trim();
        if (!ced || !pw) {
          if (staffMsg) { staffMsg.textContent = 'CÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â©dula y contraseÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â±a requeridas'; staffMsg.hidden = false; }
          return;
        }
        try {
          const res = await fetch('src/API/session_barber.php?action=barber_login', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'barber_login', barber_data: { Cedula: ced }, password: pw }),
          });
          const payload = await res.json().catch(() => null);
          if (!res.ok || !payload || payload.ok === false) {
            if (staffMsg) { staffMsg.textContent = (payload && payload.error) ? payload.error : 'Credenciales inv?lidas'; staffMsg.hidden = false; }
            return;
          }
          // Auth ok
          modalManager.closeAll();
          // Redirecci?n universal desde carpeta actual
          const redirect = (payload.data && payload.data.redirect) ? payload.data.redirect : null;
          if (redirect) {
            const base = window.location.pathname.replace(/\/[^\/]*$/, '/');
            window.location.href = base + redirect.replace(/^\.?\//, '');
          }
        } catch (err) {
          if (staffMsg) { staffMsg.textContent = err && err.message ? err.message : 'Error al autenticar'; staffMsg.hidden = false; }
        }
      });
    }
  }

  // --- Initialisation -----------------------------------------------------
  const initialName = headerUser ? headerUser.dataset.userName : '';
  toggleAuthUi(initialName ? { nombre: initialName } : null);
  try {
    const pendingWelcome = sessionStorage.getItem('welcomeName');
    if (pendingWelcome) {
      publicAlert(`Bienvenido ${pendingWelcome}`);
      sessionStorage.removeItem('welcomeName');
    }
  } catch (_) {}
  refreshCartBadge();
  // Poll cart badge every 5 seconds to reflect external changes
  try {
    setInterval(() => { if (state.session) refreshCartBadge(); }, 5000);
  } catch (_) {}
  clampDateValue();
  loadData();
})();

// Bottom dock navigation
(() => {
  const dock = document.querySelector('[data-app-dock]');
  if (!dock) return;
  const buttons = Array.from(dock.querySelectorAll('[data-dock-target]'));
  if (!buttons.length) return;

  const sections = buttons.map((btn) => {
    const selector = btn.getAttribute('data-dock-target');
    if (!selector) return null;
    const section = document.querySelector(selector);
    if (!section) return null;
    btn.addEventListener('click', () => activate(selector, true));
    return { selector, section, btn };
  }).filter(Boolean);

  const activate = (selector, scroll) => {
    sections.forEach(({ selector: sel, section, btn }) => {
      const active = sel === selector;
      section.classList.toggle('is-active', active);
      btn.classList.toggle('is-active', active);
      if (active && scroll) {
        section.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }
    });
  };

  if (sections.length) {
    activate(sections[0].selector, false);
  }

  document.addEventListener('click', (event) => {
    const trigger = event.target.closest('[data-open-section]');
    if (!trigger) return;
    const selector = trigger.getAttribute('data-open-section');
    if (!selector) return;
    activate(selector, true);
  });
})();

(() => {
  const prompt = document.querySelector('[data-pwa-install]');
  if (!prompt) return;

  const STORAGE_KEY = 'agenduy_pwa_install_dismissed';
  const cancelBtn = prompt.querySelector('[data-pwa-install-cancel]');
  const confirmBtn = prompt.querySelector('[data-pwa-install-confirm]');
  const messageEl = prompt.querySelector('[data-pwa-install-message]');
  const stepsWrapper = prompt.querySelector('[data-pwa-install-steps]');
  const steps = {
    ios: prompt.querySelectorAll('[data-pwa-steps-ios]'),
    android: prompt.querySelectorAll('[data-pwa-steps-android]'),
    desktop: prompt.querySelectorAll('[data-pwa-steps-desktop]')
  };

  const ua = window.navigator.userAgent.toLowerCase();
  const isStandalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
  if (isStandalone) return;

  const isIos = /iphone|ipad|ipod/.test(ua);
  const isSafari = /safari/.test(ua) && !/crios|fxios|chrome/.test(ua);
  const isAndroid = /android/.test(ua);
  const isChrome = /chrome|crios/.test(ua);
  const showForDesktop = !isIos && !isAndroid;

  let deferredPrompt = null;
  let currentType = null;
  const dismissed = localStorage.getItem(STORAGE_KEY) === '1';
  if (dismissed) return;

  const BASE_MESSAGE = 'Deseas Instalar Nuestra Aplicacion, para obtener beneficios y descuentos dentro de nuestra plataforma';

  const setMessage = (textContent) => {
    if (!messageEl) return;
    messageEl.textContent = textContent;
  };

  const hideAllSteps = () => {
    Object.values(steps).forEach((nodeList) => nodeList.forEach((node) => { node.hidden = true; }));
  };

  const showSteps = (group) => {
    hideAllSteps();
    const targetList = steps[group];
    if (targetList && targetList.length) {
      targetList.forEach((node) => { node.hidden = false; });
      if (stepsWrapper) stepsWrapper.hidden = false;
    } else if (stepsWrapper) {
      stepsWrapper.hidden = true;
    }
  };

  const showPrompt = (type) => {
    currentType = type;
    showSteps(type);

    if (type === 'ios') {
      setMessage('Sigue los pasos para instalar la app en tu dispositivo iOS.');
      confirmBtn.hidden = true;
      cancelBtn.textContent = 'Entendido';
    } else {
      setMessage(BASE_MESSAGE);
      confirmBtn.hidden = false;
      confirmBtn.textContent = 'Instalar App';
      cancelBtn.textContent = 'Cancelar';
    }

    prompt.hidden = false;
    requestAnimationFrame(() => {
      prompt.classList.add('is-visible');
    });
  };

  const hidePrompt = () => {
    prompt.classList.remove('is-visible');
    setTimeout(() => { prompt.hidden = true; }, 250);
  };

  const dismiss = () => {
    localStorage.setItem(STORAGE_KEY, '1');
    hidePrompt();
  };

  cancelBtn?.addEventListener('click', dismiss);

  confirmBtn?.addEventListener('click', async () => {
    if (currentType === 'android' && deferredPrompt) {
      try {
        deferredPrompt.prompt();
        await deferredPrompt.userChoice;
      } catch (_) {
        // ignore
      }
      deferredPrompt = null;
    }
    dismiss();
  });

  if (isIos && isSafari) {
    setTimeout(() => showPrompt('ios'), 1200);
  } else if (showForDesktop) {
    setTimeout(() => showPrompt('desktop'), 1200);
  }

  if (isAndroid && isChrome && 'serviceWorker' in navigator) {
    window.addEventListener('beforeinstallprompt', (event) => {
      event.preventDefault();
      if (localStorage.getItem(STORAGE_KEY) === '1') return;
      deferredPrompt = event;
      showPrompt('android');
    });
  }
})();
// Global alert helper using modal styling
(() => {
  const modal = document.querySelector('[data-modal="system-alert"]');
  const fallback = (message) => {
    const toast = document.createElement('div');
    toast.textContent = message || 'NotificaciÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â³n';
    toast.style.cssText = 'position:fixed;bottom:20px;left:50%;transform:translateX(-50%);background:rgba(15,23,42,.9);color:#fff;padding:12px 18px;border-radius:12px;font-size:14px;z-index:9999;';
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 3200);
  };

  if (!modal) {
    window.publicAlert = fallback;
    return;
  }

  const titleEl = modal.querySelector('[data-alert-title]');
  const bodyEl = modal.querySelector('[data-alert-body]');

  window.publicAlert = (message, title = 'NotificaciÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â³n') => {
    if (titleEl) titleEl.textContent = title || 'NotificaciÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â³n';
    if (bodyEl) bodyEl.textContent = message || '';
    if (modalManager && typeof modalManager.open === 'function') {
      modalManager.open('system-alert');
    } else {
      fallback(message);
    }
  };
})();

// Unified public confirm helper that mirrors native confirm()
(() => {
  const modal = document.querySelector('[data-modal="system-confirm"]');
  const fallback = (message) => Promise.resolve(window.confirm(message || ''));

  if (!modal) {
    window.publicConfirm = (options) => {
      const msg = typeof options === 'string' ? options : (options && options.message) || '';
      return fallback(msg);
    };
    return;
  }

  const titleEl = modal.querySelector('[data-confirm-title]');
  const bodyEl = modal.querySelector('[data-confirm-body]');
  const acceptBtn = modal.querySelector('[data-confirm-accept]');
  const cancelBtn = modal.querySelector('[data-confirm-cancel]');
  const dismissEls = modal.querySelectorAll('[data-confirm-dismiss]');

  let resolver = null;
  let escapeHandler = null;

  const closeModal = () => {
    if (modalManager && typeof modalManager.close === 'function') {
      modalManager.close('system-confirm');
    } else {
      modal.classList.remove('is-open');
    }
  };

  const settle = (value) => {
    if (resolver) {
      resolver(value);
      resolver = null;
    }
    closeModal();
    if (escapeHandler) {
      document.removeEventListener('keydown', escapeHandler, true);
      escapeHandler = null;
    }
  };

  const attach = (element, value) => {
    if (!element) return;
    element.addEventListener('click', (event) => {
      event.preventDefault();
      settle(value);
    });
  };

  attach(acceptBtn, true);
  attach(cancelBtn, false);
  dismissEls.forEach((el) => attach(el, false));

  window.publicConfirm = (options) => {
    const opts = typeof options === 'string' ? { message: options } : (options || {});
    const message = opts.message || '';
    if (!modalManager || typeof modalManager.open !== 'function') {
      return fallback(message);
    }
    const title = opts.title || 'ConfirmaciÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â³n';
    if (titleEl) titleEl.textContent = title;
    if (bodyEl) bodyEl.textContent = message;
    if (acceptBtn) acceptBtn.textContent = opts.acceptLabel || 'Aceptar';
    if (cancelBtn) cancelBtn.textContent = opts.cancelLabel || 'Cancelar';
    if (resolver) settle(false);
    if (escapeHandler) {
      document.removeEventListener('keydown', escapeHandler, true);
      escapeHandler = null;
    }
    escapeHandler = (event) => {
      if (event.key !== 'Escape') return;
      event.preventDefault();
      event.stopPropagation();
      settle(false);
    };
    document.addEventListener('keydown', escapeHandler, true);
    modalManager.open('system-confirm');
    return new Promise((resolve) => {
      resolver = resolve;
    });
  };
})();




























