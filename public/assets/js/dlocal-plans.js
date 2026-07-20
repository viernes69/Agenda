/**
 * dLocal Plans - front-end del checkout publico.
 *
 * Intercepta el submit de los forms con clase .dlocal-plan__form
 * y llama al endpoint /src/API/dlocal/subscribe.php para obtener
 * la URL de checkout de dLocal, luego redirige al cliente.
 */
(function () {
    'use strict';

    function $(sel, root) { return (root || document).querySelector(sel); }
    function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

    function showMsg(form, msg, isError) {
        var el = form.querySelector('.dlocal-plan__msg');
        if (!el) return;
        el.textContent = msg;
        el.className = 'dlocal-plan__msg ' + (isError ? 'is-error' : 'is-ok');
    }

    function endpoint(name, fallback) {
        var cfg = window.__DLOCAL_ENDPOINTS__ || {};
        return cfg[name] || fallback;
    }

    function attach() {
        $$('form.dlocal-plan__form').forEach(function (form) {
            if (form.dataset.dlocalBound === '1') return;
            form.dataset.dlocalBound = '1';

            form.addEventListener('submit', function (e) {
                e.preventDefault();
                var btn = form.querySelector('button[type="submit"]');
                var slug = form.querySelector('input[name="slug"]').value;
                var planId = form.querySelector('input[name="plan_internal_id"]').value;
                var email = form.querySelector('input[name="customer_email"]').value.trim();
                var name = form.querySelector('input[name="customer_name"]').value.trim();
                var csrf = form.querySelector('input[name="_csrf"]').value;

                if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                    showMsg(form, 'Email invalido.', true);
                    return;
                }

                if (btn) {
                    btn.disabled = true;
                    btn.textContent = 'Redirigiendo...';
                }
                showMsg(form, 'Generando link de pago...', false);

                fetch(endpoint('subscribe', 'src/API/dlocal/subscribe.php'), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                    body: JSON.stringify({
                        slug: slug,
                        plan_internal_id: planId,
                        customer_email: email,
                        customer_name: name,
                        _csrf: csrf,
                    }),
                })
                .then(function (r) { return r.json().then(function (j) { return { status: r.status, body: j }; }); })
                .then(function (res) {
                    if (res.status === 428 && res.body && res.body.csrf) {
                        // Renovar CSRF y reintentar una vez
                        form.querySelector('input[name="_csrf"]').value = res.body.csrf;
                        return fetch('/src/API/dlocal/subscribe.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': res.body.csrf },
                            body: JSON.stringify({
                                slug: slug,
                                plan_internal_id: planId,
                                customer_email: email,
                                customer_name: name,
                                _csrf: res.body.csrf,
                            }),
                        }).then(function (r) { return r.json().then(function (j) { return { status: r.status, body: j }; }); });
                    }
                    return res;
                })
                .then(function (res) {
                    if (res && res.body && res.body.ok && res.body.subscribe_url) {
                        showMsg(form, 'Abriendo checkout de dLocal...', false);
                        window.location.href = res.body.subscribe_url;
                    } else {
                        showMsg(form, (res && res.body && res.body.error) || 'No se pudo generar el link de pago.', true);
                        if (btn) {
                            btn.disabled = false;
                            btn.textContent = 'Suscribirme';
                        }
                    }
                })
                .catch(function (err) {
                    showMsg(form, 'Error de red: ' + err.message, true);
                    if (btn) {
                        btn.disabled = false;
                        btn.textContent = 'Suscribirme';
                    }
                });
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', attach);
    } else {
        attach();
    }
})();
