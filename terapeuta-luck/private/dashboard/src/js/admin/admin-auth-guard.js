(function adminAuthGuardModal() {
  const open = (config) => {
    if (config && typeof config.onAuthorized === 'function') {
      config.onAuthorized();
    }
  };

  window.AdminAuthGuardModal = { open, close() {} };
})();
