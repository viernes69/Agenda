(function adminThemeToggle() {
  'use strict';

  const STORAGE_KEY = 'agendarte-theme';
  const ADMIN_STORAGE_KEY = 'agendarte-admin-theme';
  const root = document.documentElement;
  const buttons = Array.from(document.querySelectorAll('[data-admin-theme-toggle]'));

  const isTheme = (value) => value === 'dark' || value === 'light';

  const readTheme = () => {
    try {
      const stored = localStorage.getItem(STORAGE_KEY);
      if (isTheme(stored)) return stored;
      const adminStored = localStorage.getItem(ADMIN_STORAGE_KEY);
      if (isTheme(adminStored)) return adminStored;
    } catch (error) {
      // Storage can be unavailable in private browsing or locked WebViews.
    }
    const current = root.getAttribute('data-admin-theme') || root.getAttribute('data-theme');
    return isTheme(current) ? current : 'dark';
  };

  const updateButton = (button, theme) => {
    const nextTheme = theme === 'dark' ? 'light' : 'dark';
    const label = nextTheme === 'dark' ? 'Modo oscuro' : 'Modo claro';
    const icon = button.querySelector('i');
    const text = button.querySelector('[data-admin-theme-toggle-label]');

    button.setAttribute('aria-pressed', theme === 'dark' ? 'true' : 'false');
    button.setAttribute('aria-label', 'Cambiar a ' + label.toLowerCase());
    button.setAttribute('title', label);
    if (icon) icon.className = 'bx ' + (nextTheme === 'dark' ? 'bx-moon' : 'bx-sun');
    if (text) text.textContent = label;
  };

  const applyTheme = (theme) => {
    root.setAttribute('data-admin-theme', theme);
    root.setAttribute('data-theme', theme);
    if (document.body) document.body.setAttribute('data-theme', theme);

    const metaTheme = document.querySelector('meta[name="theme-color"]');
    if (metaTheme) {
      metaTheme.setAttribute('content', theme === 'dark' ? '#111827' : '#f8fafc');
    }

    buttons.forEach((button) => updateButton(button, theme));
  };

  const saveTheme = (theme) => {
    try {
      localStorage.setItem(STORAGE_KEY, theme);
      localStorage.setItem(ADMIN_STORAGE_KEY, theme);
    } catch (error) {
      // The current page still updates even if persistence fails.
    }
  };

  const initialTheme = readTheme();
  applyTheme(initialTheme);

  buttons.forEach((button) => {
    button.addEventListener('click', () => {
      const current = root.getAttribute('data-admin-theme') === 'light' ? 'light' : 'dark';
      const next = current === 'dark' ? 'light' : 'dark';
      applyTheme(next);
      saveTheme(next);
    });
  });
})();
