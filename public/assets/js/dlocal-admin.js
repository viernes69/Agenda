/**
 * dLocal Admin - JS para los forms de config y crear plan.
 * Espera estar en el panel del salon, con CSRF token en los inputs hidden.
 */
(function () {
    'use strict';

    function showMsg(scope, msg, isError) {
        var el = document.querySelector('[data-msg="' + scope + '"]');
        if (!el) return;
        el.textContent = msg;
        el.style.color = isError ? '#dc2626' : '#15803d';
    }

    function reloadSoon() { setTimeout(function () { window.location.reload(); }, 1200); }

    function postJSON(url, data) {
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data),
        }).then(function (r) { return r.json().then(function (j) { return { status: r.status, body: j }; }); });
    }

    function endpoint(name, fallback) {
        var cfg = window.__DLOCAL_ENDPOINTS__ || {};
        return cfg[name] || fallback;
    }

    document.addEventListener('DOMContentLoaded', function () {
        // Config
        var cfg = document.getElementById('dlocal-config-form');
        if (cfg) {
            cfg.addEventListener('submit', function (e) {
                e.preventDefault();
                var fd = new FormData(cfg);
                var data = {
                    _csrf: fd.get('_csrf'),
                    api_key: fd.get('api_key'),
                    secret_key: fd.get('secret_key'),
                    sandbox: fd.get('sandbox') === '1',
                };
                showMsg('config', 'Guardando...', false);
                postJSON(endpoint('config', 'src/API/dlocal/config_save.php'), data)
                    .then(function (r) {
                        if (r.status >= 200 && r.status < 300 && r.body && r.body.ok) {
                            showMsg('config', 'Listo. Recargando...', false);
                            reloadSoon();
                        } else {
                            showMsg('config', (r.body && r.body.error) || ('HTTP ' + r.status), true);
                        }
                    })
                    .catch(function (err) { showMsg('config', 'Error: ' + err.message, true); });
            });
        }

        // Crear plan
        var planForm = document.getElementById('dlocal-create-plan-form');
        if (planForm) {
            planForm.addEventListener('submit', function (e) {
                e.preventDefault();
                var fd = new FormData(planForm);
                var data = {
                    _csrf: fd.get('_csrf'),
                    name: fd.get('name'),
                    description: fd.get('description'),
                    currency: fd.get('currency'),
                    amount: parseFloat(fd.get('amount')),
                    frequency_type: fd.get('frequency_type'),
                    frequency_value: parseInt(fd.get('frequency_value'), 10) || 1,
                    country: fd.get('country'),
                    free_trial_days: parseInt(fd.get('free_trial_days'), 10) || 0,
                    max_periods: parseInt(fd.get('max_periods'), 10) || 0,
                };
                showMsg('plan', 'Creando plan en dLocal...', false);
                postJSON(endpoint('createPlan', 'src/API/dlocal/create_plan.php'), data)
                    .then(function (r) {
                        if (r.status >= 200 && r.status < 300 && r.body && r.body.ok) {
                            showMsg('plan', 'Plan creado. Recargando...', false);
                            reloadSoon();
                        } else {
                            showMsg('plan', (r.body && r.body.error) || ('HTTP ' + r.status), true);
                        }
                    })
                    .catch(function (err) { showMsg('plan', 'Error: ' + err.message, true); });
            });
        }
    });
})();
