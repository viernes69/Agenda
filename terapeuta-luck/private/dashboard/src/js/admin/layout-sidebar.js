(function adminSidebar() {
  const layout = document.querySelector('.admin-layout');
  if (!layout) return;
  const btn = document.getElementById('admin-toggle');

  const desktopMq = window.matchMedia('(min-width: 1024px)');
  const applyInitialState = () => {
    layout.classList.add('is-collapsed');
  };

  applyInitialState();
  if (typeof desktopMq.addEventListener === 'function') {
    desktopMq.addEventListener('change', applyInitialState);
  } else if (typeof desktopMq.addListener === 'function') {
    desktopMq.addListener(applyInitialState);
  }

  if (!btn) return;

  const toggle = () => {
    if (desktopMq.matches) return;
    layout.classList.toggle('is-collapsed');
  };

  const collapse = () => {
    if (desktopMq.matches) return;
    layout.classList.add('is-collapsed');
  };

  btn.addEventListener('click', (e) => { e.stopPropagation(); toggle(); });
  document.addEventListener('click', (e) => {
    if (desktopMq.matches || layout.classList.contains('is-collapsed')) return;
    const aside = document.querySelector('.admin-aside');
    if (!aside) return;
    const insideAside = aside.contains(e.target);
    const onToggle = btn.contains(e.target);
    if (!insideAside && !onToggle) collapse();
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') collapse();
  });
})();
