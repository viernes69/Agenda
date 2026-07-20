(() => {
  const trigger = document.getElementById('btnSobreNosotros');
  const modal = document.getElementById('modal-about');
  const container = document.getElementById('modal-about-content');

  if (!trigger || !modal || !container) return;

  const overlay = modal.querySelector('.u-modal__overlay');
  let escBound = false;

  function handleEsc(event) {
    if (event.key === 'Escape') closeModal();
  }

  function bindEsc() {
    if (escBound) return;
    document.addEventListener('keydown', handleEsc);
    escBound = true;
  }

  function unbindEsc() {
    if (!escBound) return;
    document.removeEventListener('keydown', handleEsc);
    escBound = false;
  }

  function closeModal() {
    modal.classList.add('hidden');
    container.innerHTML = '';
    document.body.classList.remove('modal-open');
    unbindEsc();
    if (overlay) overlay.removeEventListener('click', closeModal);
  }

  function attachInnerHandlers(root) {
    root.querySelector('.about-modal__close')?.addEventListener('click', closeModal);
  }

  function openModal(html) {
    container.innerHTML = html;
    attachInnerHandlers(container);
    modal.classList.remove('hidden');
    document.body.classList.add('modal-open');
    bindEsc();
    if (overlay) overlay.addEventListener('click', closeModal, { once: true });
    container.querySelector('button, [href], input, select, textarea')?.focus({ preventScroll: true });
  }

  async function showAboutModal() {
    try {
      const response = await fetch('src/components/about.php', {
        headers: { 'X-Requested-With': 'fetch' },
        cache: 'no-store'
      });
      const html = await response.text();
      openModal(html);
    } catch (error) {
      openModal(
        '<div class="about-modal" role="document">' +
          '<header class="about-modal__header">' +
            '<h3 class="about-modal__title" id="modal-about-title">Sobre Nosotros</h3>' +
            '<button type="button" class="about-modal__close" aria-label="Cerrar">&times;</button>' +
          '</header>' +
          '<div class="about-modal__body"><p>No pudimos cargar la información.</p></div>' +
        '</div>'
      );
    }
  }

  trigger.addEventListener('click', showAboutModal);
  trigger.addEventListener('keydown', (event) => {
    if (event.key === 'Enter' || event.key === ' ') {
      event.preventDefault();
      showAboutModal();
    }
  });
})();
