/**
 * Site Login Dropdown
 * - Toggle del dropdown al click en el boton
 * - Cierra al hacer click fuera, al Escape, o al cambiar de hash
 * - El form hace POST normal a /admin/login.php (que ya existe)
 */
(function () {
    'use strict';

    function $(sel, root) { return (root || document).querySelector(sel); }

    function ready(fn) {
        if (document.readyState !== 'loading') fn();
        else document.addEventListener('DOMContentLoaded', fn);
    }

    ready(function () {
        var btn      = $('#site-login-toggle');
        var dropdown = $('#site-login-dropdown');
        var user     = $('#site-user');
        if (!btn || !dropdown || !user) return;

        function isOpen() { return !dropdown.hasAttribute('hidden'); }
        function open() {
            dropdown.removeAttribute('hidden');
            btn.setAttribute('aria-expanded', 'true');
            var first = dropdown.querySelector('input[name="email"]');
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

        if (window.location.search.indexOf('login_error=1') !== -1) {
            open();
            var msg = $('#site-login-msg');
            if (msg) {
                msg.textContent = 'Email o contrasena incorrectos.';
                msg.classList.add('is-error');
            }
            if (window.history && window.history.replaceState) {
                window.history.replaceState({}, document.title, window.location.pathname);
            }
        }
    });
})();
