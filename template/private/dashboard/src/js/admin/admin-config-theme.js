(function() {
  const modal = document.querySelector('[data-admin-modal="config-temas"]');
  if (!modal) return;

  const closeBtns = modal.querySelectorAll('[data-admin-theme-close]');
  const lightBtn = modal.querySelector('[data-admin-theme-set="light"]');
  const darkBtn = modal.querySelector('[data-admin-theme-set="dark"]');

  const close = () => {
    modal.hidden = true;
  };

  closeBtns.forEach(b => b.addEventListener('click', close));

  const setTheme = (theme) => {
    try {
      localStorage.setItem('agendarte-theme', theme);
      localStorage.setItem('agendarte-admin-theme', theme);
      document.documentElement.setAttribute('data-admin-theme', theme);
      document.documentElement.setAttribute('data-theme', theme);
      updateButtons(theme);
    } catch (err) {}
  };

  const updateButtons = (theme) => {
    if (lightBtn) {
      if (theme === 'light') {
        lightBtn.classList.add('btn-primary');
        lightBtn.classList.remove('btn-outline');
      } else {
        lightBtn.classList.remove('btn-primary');
        lightBtn.classList.add('btn-outline');
      }
    }

    if (darkBtn) {
      if (theme === 'dark') {
        darkBtn.classList.add('btn-primary');
        darkBtn.classList.remove('btn-outline');
      } else {
        darkBtn.classList.remove('btn-primary');
        darkBtn.classList.add('btn-outline');
      }
    }
  };

  if (lightBtn) {
    lightBtn.addEventListener('click', () => {
      setTheme('light');
    });
  }

  if (darkBtn) {
    darkBtn.addEventListener('click', () => {
      setTheme('dark');
    });
  }

  // Initial state
  const currentTheme = document.documentElement.getAttribute('data-admin-theme') || 'dark';
  updateButtons(currentTheme);

})();
