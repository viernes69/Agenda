/**
 * Site Login Dropdown
 * - Toggle del dropdown al click en el boton
 * - Tabs: contrasena / link por email
 * - Cierra al hacer click fuera, al Escape, o al cambiar de hash
 */
(function () {
    'use strict';

    function $(sel, root) { return (root || document).querySelector(sel); }
    function $all(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

    function ready(fn) {
        if (document.readyState !== 'loading') fn();
        else document.addEventListener('DOMContentLoaded', fn);
    }

    function getConfig() {
        return window.__AGENDUY_CONFIG__ || {};
    }

    function getAdminCsrf() {
        var selectors = ['#site-login-magic-form input[name="_csrf"]', '#site-login-form input[name="_csrf"]'];
        for (var i = 0; i < selectors.length; i++) {
            var input = document.querySelector(selectors[i]);
            if (input && input.value) {
                return String(input.value);
            }
        }
        return '';
    }

    function showMsg(text, isError) {
        var msg = $('#site-login-msg');
        if (!msg) return;
        msg.textContent = text || '';
        msg.classList.toggle('is-error', !!isError);
        msg.classList.toggle('is-success', !isError && !!text);
    }

    function setLoginTab(tab) {
        var tabs = $all('[data-login-tab]');
        var panels = $all('[data-login-panel]');
        tabs.forEach(function (btn) {
            var active = btn.getAttribute('data-login-tab') === tab;
            btn.classList.toggle('is-active', active);
            btn.setAttribute('aria-selected', active ? 'true' : 'false');
        });
        panels.forEach(function (panel) {
            var show = panel.getAttribute('data-login-panel') === tab;
            if (show) panel.removeAttribute('hidden');
            else panel.setAttribute('hidden', '');
        });
        showMsg('');
    }

    ready(function () {
        var btn      = $('#site-login-toggle');
        var dropdown = $('#site-login-dropdown');
        var user     = $('#site-user');
        var magicForm = $('#site-login-magic-form');
        if (!btn || !dropdown || !user) return;

        function isOpen() { return !dropdown.hasAttribute('hidden'); }
        function open() {
            dropdown.removeAttribute('hidden');
            btn.setAttribute('aria-expanded', 'true');
            var activePanel = dropdown.querySelector('[data-login-panel]:not([hidden]) input[name="email"]');
            var first = activePanel || dropdown.querySelector('input[name="email"]');
            if (first) setTimeout(function () { first.focus(); }, 60);
        }
        function close() {
            dropdown.setAttribute('hidden', '');
            btn.setAttribute('aria-expanded', 'false');
        }
        function toggle() { isOpen() ? close() : open(); }

        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            toggle();
        });

        document.addEventListener('click', function (e) {
            if (!isOpen()) return;
            if (user.contains(e.target)) return;
            close();
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && isOpen()) {
                close();
                btn.focus();
            }
        });

        window.addEventListener('hashchange', close);

        $all('[data-login-tab]').forEach(function (tabBtn) {
            tabBtn.addEventListener('click', function () {
                setLoginTab(tabBtn.getAttribute('data-login-tab') || 'password');
            });
        });

        if (magicForm) {
            magicForm.addEventListener('submit', function (e) {
                e.preventDefault();
                var emailInput = magicForm.querySelector('input[name="email"]');
                var email = emailInput ? String(emailInput.value || '').trim() : '';
                if (!email) {
                    showMsg('Ingresá tu email.', true);
                    return;
                }

                var cfg = getConfig();
                var apiBase = cfg.apiBase || 'admin/api';
                showMsg('Enviando link...', false);

                fetch(apiBase + '/magic_link.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'include',
                    body: JSON.stringify({ email: email, _csrf: getAdminCsrf() }),
                }).then(function (res) {
                    return res.json().catch(function () { return null; }).then(function (data) {
                        if (!res.ok || !data || !data.ok) {
                            var err = (data && data.error) || 'No se pudo enviar el link.';
                            if (res.status === 419 || err.indexOf('CSRF') !== -1) {
                                err = 'Sesion expirada. Recarga la pagina e intenta de nuevo.';
                            }
                            showMsg(err, true);
                            return;
                        }
                        showMsg(data.message || 'Revisá tu correo.', false);
                        if (emailInput) emailInput.value = '';
                    });
                }).catch(function () {
                    showMsg('Error de conexión. Intentá de nuevo.', true);
                });
            });
        }

        var params = new URLSearchParams(window.location.search);
        var loginError = params.get('login_error');
        if (loginError) {
            open();
            var magicErr = params.get('magic');
            if (magicErr) {
                showMsg(decodeURIComponent(magicErr), true);
            } else if (loginError === 'csrf') {
                showMsg('Sesion expirada. Volve a intentar desde este formulario.', true);
            } else if (loginError === 'missing') {
                showMsg('Ingresa email y contrasena.', true);
            } else {
                showMsg('Email o contrasena incorrectos.', true);
            }
            if (window.history && window.history.replaceState) {
                params.delete('login_error');
                params.delete('magic');
                var cleanQuery = params.toString();
                window.history.replaceState({}, document.title, window.location.pathname + (cleanQuery ? '?' + cleanQuery : '') + window.location.hash);
            }
        }
    });
})();
