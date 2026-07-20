(function adminPwaManager() {
  if (typeof window === 'undefined' || !('serviceWorker' in navigator)) {
    return;
  }

  const PUBLIC_KEY = (window.ADMIN_PUSH_PUBLIC_KEY || '').trim();
  const ENDPOINT = (window.ADMIN_PUSH_ENDPOINT || '').trim();
  if (PUBLIC_KEY === '' || PUBLIC_KEY.indexOf('REEMPLAZAR') !== -1) {
    return;
  }

  const modal = document.querySelector('[data-admin-modal="pwa-install"]');
  if (!modal) return;
  const acceptBtn = modal.querySelector('[data-admin-pwa-accept]');
  const dismissBtns = modal.querySelectorAll('[data-admin-pwa-dismiss]');
  let isVisible = false;
  let dismissed = false;
  let swRegistration = null;

  const showModal = () => {
    if (isVisible || dismissed) return;
    modal.hidden = false;
    requestAnimationFrame(() => {
      modal.classList.add('is-visible');
    });
    isVisible = true;
  };

  const hideModal = () => {
    if (!isVisible) return;
    modal.classList.remove('is-visible');
    setTimeout(() => { modal.hidden = true; }, 180);
    isVisible = false;
  };

  const urlBase64ToUint8Array = (base64String) => {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const rawData = window.atob(base64);
    const outputArray = new Uint8Array(rawData.length);
    for (let i = 0; i < rawData.length; i++) {
      outputArray[i] = rawData.charCodeAt(i);
    }
    return outputArray;
  };

  const requestPermission = async () => {
    if (!('Notification' in window)) {
      throw new Error('El navegador no soporta notificaciones.');
    }
    if (Notification.permission === 'granted') {
      return 'granted';
    }
    if (Notification.permission === 'denied') {
      throw new Error('Debes habilitar las notificaciones en el navegador.');
    }
    const result = await Notification.requestPermission();
    if (result !== 'granted') {
      throw new Error('Debes aceptar las notificaciones para continuar.');
    }
    return result;
  };

  const syncSubscription = async (subscription) => {
    if (!ENDPOINT) return;
    const body = JSON.stringify({ action: 'subscribe', subscription: subscription.toJSON() });
    await fetch(ENDPOINT, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body,
    });
  };

  const ensurePushSubscription = async (registration, forceRenew = false) => {
    await requestPermission();
    const existing = await registration.pushManager.getSubscription();
    if (existing && !forceRenew) {
      await syncSubscription(existing);
      return existing;
    }
    if (existing && forceRenew) {
      try { await existing.unsubscribe(); } catch (_) {}
    }
    const subscription = await registration.pushManager.subscribe({
      userVisibleOnly: true,
      applicationServerKey: urlBase64ToUint8Array(PUBLIC_KEY),
    });
    await syncSubscription(subscription);
    return subscription;
  };

  const waitForActivation = (registration) => new Promise((resolve, reject) => {
    if (!registration) {
      reject(new Error('No se pudo registrar el Service Worker.'));
      return;
    }
    if (!registration.installing) {
      resolve();
      return;
    }
    const worker = registration.installing;
    worker.addEventListener('statechange', () => {
      if (worker.state === 'activated') {
        resolve();
      } else if (worker.state === 'redundant') {
        reject(new Error('El Service Worker fue reemplazado.'));
      }
    });
  });

  const registerAndSubscribe = async () => {
    try {
      const registration = await navigator.serviceWorker.register('../sw.js', { scope: '../' });
      swRegistration = registration;
      await waitForActivation(registration);
      await ensurePushSubscription(registration, true);
      dismissed = true;
      hideModal();
      if (typeof adminNotify === 'function') {
        adminNotify('Aplicación instalada. Notificaciones activadas.', 'success');
      }
    } catch (error) {
      if (typeof adminNotify === 'function') {
        adminNotify(error && error.message ? error.message : 'No se pudo instalar la aplicación.', 'error');
      }
    }
  };

  const findRegistration = async () => {
    const registrations = await navigator.serviceWorker.getRegistrations();
    const scopeMatch = registrations.find((reg) => reg.scope.endsWith('/template/private/dashboard/'));
    if (scopeMatch) return scopeMatch;
    try {
      return await navigator.serviceWorker.getRegistration('../');
    } catch (_) {
      return null;
    }
  };

  const bootstrap = async () => {
    const registration = await findRegistration();
    if (registration) {
      swRegistration = registration;
      try {
        await ensurePushSubscription(registration);
      } catch (error) {
        if (typeof adminNotify === 'function') {
          adminNotify(error && error.message ? error.message : 'Activa las notificaciones para recibir alertas.', 'info');
        }
      }
      return;
    }
    showModal();
  };

  acceptBtn && acceptBtn.addEventListener('click', (event) => {
    event.preventDefault();
    registerAndSubscribe();
  });

  dismissBtns.forEach((btn) => {
    btn.addEventListener('click', (event) => {
      event.preventDefault();
      dismissed = true;
      hideModal();
    });
  });

  navigator.serviceWorker.addEventListener('message', (event) => {
    if (event.data && event.data.type === 'PUSH_SUBSCRIPTION_CHANGE' && swRegistration) {
      ensurePushSubscription(swRegistration, true).catch(() => {});
    }
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootstrap, { once: true });
  } else {
    bootstrap();
  }
})();
