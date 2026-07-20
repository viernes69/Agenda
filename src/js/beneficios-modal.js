(() => {
  const trigger = document.getElementById('btnBeneficios');
  const modal = document.getElementById('modal-beneficios');
  const container = document.getElementById('modal-beneficios-content');

  if (!trigger || !modal || !container) return;

  const overlay = modal.querySelector('.u-modal__overlay');
  let escHandlerBound = false;

  function escHandler(event) {
    if (event.key === 'Escape') closeModal();
  }

  function bindEsc() {
    if (escHandlerBound) return;
    document.addEventListener('keydown', escHandler);
    escHandlerBound = true;
  }

  function unbindEsc() {
    if (!escHandlerBound) return;
    document.removeEventListener('keydown', escHandler);
    escHandlerBound = false;
  }

  function closeModal() {
    modal.classList.add('hidden');
    container.innerHTML = '';
    document.body.classList.remove('modal-open');
    unbindEsc();
    if (overlay) overlay.removeEventListener('click', closeModal);
  }

  function attachInnerHandlers(root) {
    root.querySelector('.benefits-modal__close')?.addEventListener('click', closeModal);
    root.querySelector('.benefits-modal__cta')?.addEventListener('click', function () {
      closeModal();
      if (typeof window.openRegisterModal === 'function') {
        window.openRegisterModal({});
      } else {
        document.querySelector('.plan-btn, [href="#rubros"]')?.click();
      }
    });
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

  async function showBenefitsModal() {
    try {
      const response = await fetch('src/components/beneficios.php', {
        headers: { 'X-Requested-With': 'fetch' },
        cache: 'no-store'
      });
      const html = await response.text();
      openModal(html);
    } catch (error) {
      openModal(
        '<div class="benefits-modal" role="document">' +
          '<header class="benefits-modal__header">' +
            '<h3 class="benefits-modal__title" id="modal-beneficios-title">Beneficios</h3>' +
            '<button type="button" class="benefits-modal__close" aria-label="Cerrar">&times;</button>' +
          '</header>' +
          '<div class="benefits-modal__body"><p>No pudimos cargar la información.</p></div>' +
        '</div>'
      );
    }
  }

  trigger.addEventListener('click', showBenefitsModal);
  trigger.addEventListener('keydown', (event) => {
    if (event.key === 'Enter' || event.key === ' ') {
      event.preventDefault();
      showBenefitsModal();
    }
  });
})();
