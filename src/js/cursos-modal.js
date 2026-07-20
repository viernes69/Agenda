(() => {
  const trigger = document.getElementById('btnCursos');
  const modal = document.getElementById('modal-cursos');
  const container = document.getElementById('modal-cursos-content');

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
    document.body.classList.remove('modal-open');
    container.innerHTML = '';
    unbindEsc();
    if (overlay) overlay.removeEventListener('click', closeModal);
  }

  function attachInnerHandlers(root) {
    root.querySelector('.courses-modal__close')?.addEventListener('click', closeModal);
    root.querySelector('.courses-modal__dismiss')?.addEventListener('click', closeModal);
  }

  function openModal(html) {
    container.innerHTML = html;
    attachInnerHandlers(container);
    modal.classList.remove('hidden');
    document.body.classList.add('modal-open');
    bindEsc();
    if (overlay) {
      overlay.addEventListener('click', closeModal, { once: true });
    }
    container.querySelector('button, [href], input, select, textarea')?.focus({ preventScroll: true });
  }

  async function showCoursesModal() {
    try {
      const response = await fetch('src/components/cursos.php', {
        headers: { 'X-Requested-With': 'fetch' },
        cache: 'no-store'
      });
      const html = await response.text();
      openModal(html);
    } catch (error) {
      openModal(
        '<div class="courses-modal" role="document">' +
          '<header class="courses-modal__header">' +
            '<h3 class="courses-modal__title" id="modal-cursos-title">Nuestros Cursos</h3>' +
            '<button type="button" class="courses-modal__close" aria-label="Cerrar">&times;</button>' +
          '</header>' +
          '<div class="courses-modal__body"><p>No pudimos cargar la información.</p></div>' +
        '</div>'
      );
    }
  }

  trigger.addEventListener('click', showCoursesModal);
  trigger.addEventListener('keydown', (event) => {
    if (event.key === 'Enter' || event.key === ' ') {
      event.preventDefault();
      showCoursesModal();
    }
  });
})();
