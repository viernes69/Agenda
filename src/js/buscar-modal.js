(() => {
  const trigger = document.getElementById('btnBuscarServicios');
  const modal = document.getElementById('modal-buscar');
  const container = document.getElementById('modal-buscar-content');

  if (!trigger || !modal || !container) return;

  const overlay = modal.querySelector('.u-modal__overlay');
  const body = document.body;
  let escBound = false;
  let isLoading = false;

  function bindEsc() {
    if (escBound) return;
    document.addEventListener('keydown', onEsc);
    escBound = true;
  }

  function unbindEsc() {
    if (!escBound) return;
    document.removeEventListener('keydown', onEsc);
    escBound = false;
  }

  function onEsc(event) {
    if (event.key === 'Escape') {
      closeModal();
    }
  }

  function closeModal() {
    modal.classList.add('hidden');
    body.classList.remove('modal-open');
    unbindEsc();
    if (overlay) overlay.removeEventListener('click', closeModal);
    container.innerHTML = '';
  }

  function handleAvatarFallback(scope) {
    const avatars = scope.querySelectorAll('.search-card__avatar-img');
    avatars.forEach((img) => {
      img.addEventListener('error', () => {
        const wrapper = img.closest('.search-card__avatar');
        img.remove();
        wrapper?.classList.remove('has-logo');
      });
    });
  }

  function handleFilter(input) {
    const cards = container.querySelectorAll('.search-card');
    const value = (input.value || '').toLowerCase().trim();
    cards.forEach((card) => {
      const name = card.getAttribute('data-search-name') || '';
      const matches = value === '' || name.includes(value);
      card.hidden = !matches;
    });
  }

  function attachInnerHandlers(scope) {
    scope.querySelector('.search-modal__close')?.addEventListener('click', closeModal);
    const searchInput = scope.querySelector('[data-search-input]');
    if (searchInput) {
      searchInput.addEventListener('input', () => handleFilter(searchInput));
      searchInput.focus({ preventScroll: true });
    }
    handleAvatarFallback(scope);
  }

  function openModalWith(html) {
    container.innerHTML = html;
    attachInnerHandlers(container);
    modal.classList.remove('hidden');
    body.classList.add('modal-open');
    bindEsc();
    if (overlay) overlay.addEventListener('click', closeModal, { once: true });
    const focusable = container.querySelector('[data-search-input]') || container.querySelector('button, [href], input, select, textarea');
    focusable?.focus({ preventScroll: true });
  }

  async function loadModal() {
    if (isLoading) return;
    isLoading = true;
    try {
      const response = await fetch('src/components/buscar-servicios.php', {
        headers: { 'X-Requested-With': 'fetch' },
        cache: 'no-store'
      });
      if (!response.ok) {
        throw new Error('No se pudo cargar el directorio.');
      }
      const html = await response.text();
      openModalWith(html);
    } catch (error) {
      const fallback = `
        <div class="search-modal" role="document">
          <header class="search-modal__header">
            <h3 id="modal-buscar-title">Directorio de servicios</h3>
            <button type="button" class="search-modal__close" aria-label="Cerrar">&times;</button>
          </header>
          <div class="search-modal__empty">
            <p>${error?.message || 'No pudimos mostrar los servicios en este momento.'}</p>
          </div>
        </div>`;
      openModalWith(fallback);
    } finally {
      isLoading = false;
    }
  }

  trigger.addEventListener('click', loadModal);
  trigger.addEventListener('keydown', (event) => {
    if (event.key === 'Enter' || event.key === ' ') {
      event.preventDefault();
      loadModal();
    }
  });
})();
