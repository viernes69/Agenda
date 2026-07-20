/**
 * Google Identity Services + login/registro Agendarte
 */
(function () {
  'use strict';

  function $(sel, root) { return (root || document).querySelector(sel); }

  function ready(fn) {
    if (document.readyState !== 'loading') fn();
    else document.addEventListener('DOMContentLoaded', fn);
  }

  function getConfig() {
    return window.__AGENDUY_CONFIG__ || {};
  }

  function getAdminCsrf() {
    var input = document.querySelector('#site-login-form input[name="_csrf"]');
    return input ? String(input.value || '') : '';
  }

  function showLoginMsg(text, isError) {
    var msg = $('#site-login-msg');
    if (!msg) return;
    msg.textContent = text || '';
    msg.classList.toggle('is-error', !!isError);
    msg.classList.toggle('is-success', !isError && !!text);
  }

  function postJson(url, body) {
    return fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'include',
      body: JSON.stringify(body),
    }).then(function (res) {
      return res.json().catch(function () { return null; }).then(function (data) {
        return { ok: res.ok, status: res.status, data: data };
      });
    });
  }

  function handleGoogleCredential(response) {
    var cfg = getConfig();
    var token = response && response.credential ? response.credential : '';
    if (!token) {
      showLoginMsg('No se recibió respuesta de Google.', true);
      return;
    }

    showLoginMsg('Verificando cuenta...', false);

    postJson((cfg.apiBase || 'admin/api') + '/google_auth.php', {
      credential: token,
      _csrf: getAdminCsrf(),
    }).then(function (result) {
      var data = result.data || {};
      if (result.ok && data.ok && data.redirect) {
        if (data.registered) {
          showLoginMsg(data.message || 'Cuenta creada. Redirigiendo...', false);
        }
        window.location.href = data.redirect;
        return;
      }
      if (data.needs_register) {
        showLoginMsg('No se pudo crear la cuenta automáticamente. Usá el registro manual.', true);
        if (typeof window.openRegisterModal === 'function') {
          window.openRegisterModal({
            googleProfile: data.profile || {},
            googleIdToken: token,
          });
        }
        return;
      }
      showLoginMsg(mapAuthError(result), true);
    }).catch(function () {
      showLoginMsg('Error de conexión. Intentá de nuevo.', true);
    });
  }

  function mapAuthError(result) {
    var data = result.data || {};
    var err = data.error || 'No se pudo iniciar sesión con Google.';
    if (result.status === 419 || String(err).indexOf('CSRF') !== -1) {
      return 'Sesión expirada. Recargá la página e intentá de nuevo.';
    }
    return err;
  }

  function renderGoogleButton(container, context) {
    if (!container || !window.google || !window.google.accounts || !window.google.accounts.id) return;
    var cfg = getConfig();
    if (!cfg.googleClientId) return;

    container.innerHTML = '';
    window.google.accounts.id.renderButton(container, {
      type: 'standard',
      theme: 'outline',
      size: 'large',
      text: context === 'register' ? 'signup_with' : 'continue_with',
      width: Math.min(container.offsetWidth || 260, 320),
      locale: 'es',
    });
  }

  function initGoogle() {
    var cfg = getConfig();
    if (!cfg.googleClientId) return;

    window.google.accounts.id.initialize({
      client_id: cfg.googleClientId,
      callback: handleGoogleCredential,
      auto_select: false,
      cancel_on_tap_outside: true,
      context: 'signin',
      ux_mode: 'popup',
      itp_support: true,
    });

    renderGoogleButton($('#site-login-google'), 'login');
    renderGoogleButton($('#reg-google-btn'), 'register');
  }

  function loadGoogleScript() {
    var cfg = getConfig();
    if (!cfg.googleClientId) return;

    if (window.google && window.google.accounts && window.google.accounts.id) {
      initGoogle();
      return;
    }

    var existing = document.querySelector('script[data-agendarte-gis]');
    if (existing) {
      existing.addEventListener('load', initGoogle);
      return;
    }

    var script = document.createElement('script');
    script.src = 'https://accounts.google.com/gsi/client';
    script.async = true;
    script.defer = true;
    script.setAttribute('data-agendarte-gis', '1');
    script.onload = initGoogle;
    document.head.appendChild(script);
  }

  ready(function () {
    loadGoogleScript();

    document.addEventListener('agendarte:register-opened', function () {
      setTimeout(function () {
        renderGoogleButton($('#reg-google-btn'), 'register');
      }, 80);
    });
  });

  window.AgendarteGoogleAuth = {
    getToken: function () { return window.__AGENDARTE_GOOGLE_TOKEN__ || ''; },
    setToken: function (token) { window.__AGENDARTE_GOOGLE_TOKEN__ = token || ''; },
    clearToken: function () { window.__AGENDARTE_GOOGLE_TOKEN__ = ''; },
    applyProfileToRegisterForm: function (profile) {
      var form = document.getElementById('registro-form');
      if (!form || !profile) return;
      var email = form.querySelector('[name="owner_email"]');
      var name = form.querySelector('[name="owner_name"]');
      var last = form.querySelector('[name="owner_lastname"]');
      var pass = form.querySelector('[name="owner_password"]');
      var cedula = form.querySelector('[name="owner_cedula"]');
      if (email && profile.email) email.value = profile.email;
      if (name && profile.nombre) name.value = profile.nombre;
      if (last && profile.apellido) last.value = profile.apellido;
      if (pass) {
        pass.removeAttribute('required');
        pass.value = '';
        pass.closest('label')?.classList.add('reg-field--optional');
      }
      if (cedula) {
        cedula.removeAttribute('required');
        cedula.closest('label')?.classList.add('reg-field--optional');
      }
    },
  };
})();
