(function adminConfigMonedaModal() {
  const modal = document.querySelector('[data-admin-modal="config-moneda"]');
  if (!modal) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', adminConfigMonedaModal, { once: true });
    }
    return;
  }
  const form = modal.querySelector('[data-admin-config-moneda-form]');
  if (!form && document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', adminConfigMonedaModal, { once: true });
    return;
  }
  if (!form) return;
  const modalLoading = window.AdminModalLoading;
  const submitBtn = form.querySelector('[data-admin-config-moneda-submit]');
  const errorEl = form.querySelector('[data-admin-config-moneda-error]');
  const closeEls = modal.querySelectorAll('[data-admin-config-moneda-close]');
  const fields = Array.from(form.querySelectorAll('[data-admin-config-moneda-field]'));

  const clone = (v) => JSON.parse(JSON.stringify(v || {}));
  let current = clone(window.ADMIN_INFO_BARBERIA || {});

  // Currency presets map
  const CURRENCY_PRESETS = {
    UYU: { simbolo: '$', separador_decimal: ',', separador_miles: '.', locale: 'es_UY', formatos: { fecha: 'Y-m-d', hora: 'H:i' } },
    USD: { simbolo: '$', separador_decimal: '.', separador_miles: ',', locale: 'en_US', formatos: { fecha: 'm/d/Y', hora: 'g:i A' } },
    EUR: { simbolo: '€', separador_decimal: ',', separador_miles: '.', locale: 'es_ES', formatos: { fecha: 'd/m/Y', hora: 'H:i' } },
    ARS: { simbolo: '$', separador_decimal: ',', separador_miles: '.', locale: 'es_AR', formatos: { fecha: 'd/m/Y', hora: 'H:i' } },
    BRL: { simbolo: 'R$', separador_decimal: ',', separador_miles: '.', locale: 'pt_BR', formatos: { fecha: 'd/m/Y', hora: 'H:i' } },
    GBP: { simbolo: '£', separador_decimal: '.', separador_miles: ',', locale: 'en_GB', formatos: { fecha: 'd/m/Y', hora: 'H:i' } }
  };

  const DATE_FORMAT_OPTIONS = [
    'Y-m-d', 'd/m/Y', 'd-m-Y', 'm/d/Y', 'd M Y', 'jS F Y'
  ];
  const TIME_FORMAT_OPTIONS = [
    'H:i', 'g:i A', 'h:i A', 'H:i:s'
  ];

  const getValueByPath = (obj, path) => {
    return path.split('.').reduce((acc, p) => (acc && Object.prototype.hasOwnProperty.call(acc, p) ? acc[p] : ''), obj);
  };

  const setByPath = (target, path, value) => {
    const parts = path.split('.');
    let cur = target;
    parts.forEach((part, idx) => {
      if (idx === parts.length - 1) {
        cur[part] = value;
      } else {
        if (!cur[part] || typeof cur[part] !== 'object') cur[part] = {};
        cur = cur[part];
      }
    });
  };

  const fillForm = (data) => {
    // Populate currency select and readonly fields depending on preset
    const currencySelect = form.querySelector('#admin-config-moneda-codigo');
    const simboloInput = form.querySelector('#admin-config-moneda-simbolo');
    const sepDec = form.querySelector('#admin-config-moneda-sep-dec');
    const sepMil = form.querySelector('#admin-config-moneda-sep-mil');
    const localeInput = form.querySelector('#admin-config-moneda-locale');
    const dateSelect = form.querySelector('#admin-config-moneda-formato-fecha');
    const timeSelect = form.querySelector('#admin-config-moneda-formato-hora');

    // ensure options for date/time selects
    if (dateSelect && dateSelect.options.length <= DATE_FORMAT_OPTIONS.length) {
      dateSelect.innerHTML = '';
      DATE_FORMAT_OPTIONS.forEach((opt) => {
        const o = document.createElement('option'); o.value = opt; o.textContent = opt; dateSelect.appendChild(o);
      });
    }
    if (timeSelect && timeSelect.options.length <= TIME_FORMAT_OPTIONS.length) {
      timeSelect.innerHTML = '';
      TIME_FORMAT_OPTIONS.forEach((opt) => {
        const o = document.createElement('option'); o.value = opt; o.textContent = opt; timeSelect.appendChild(o);
      });
    }

    // Fill from data
    const moneda = data && data.moneda ? data.moneda : {};
    const codigo = moneda.codigo || (data && data.locale && Object.keys(CURRENCY_PRESETS).find(k => CURRENCY_PRESETS[k].locale === data.locale) ) || '';
    if (currencySelect) currencySelect.value = codigo || '';

    const preset = (codigo && CURRENCY_PRESETS[codigo]) ? CURRENCY_PRESETS[codigo] : (moneda || {});
    if (simboloInput) simboloInput.value = moneda.simbolo || preset.simbolo || '';
    if (sepDec) sepDec.value = moneda.separador_decimal || preset.separador_decimal || '';
    if (sepMil) sepMil.value = moneda.separador_miles || preset.separador_miles || '';
    if (localeInput) localeInput.value = data.locale || preset.locale || '';

    // Fill formatos
    const formatos = data && data.formatos ? data.formatos : {};
    if (dateSelect) dateSelect.value = formatos.fecha || dateSelect.value || DATE_FORMAT_OPTIONS[0];
    if (timeSelect) timeSelect.value = formatos.hora || timeSelect.value || TIME_FORMAT_OPTIONS[0];

    // Enable listener: when currency changes, update readonly fields (attach once)
    if (currencySelect && currencySelect.dataset.listener !== '1') {
      currencySelect.addEventListener('change', () => {
        const val = currencySelect.value;
        const p = CURRENCY_PRESETS[val] || {};
        if (simboloInput) simboloInput.value = p.simbolo || '';
        if (sepDec) sepDec.value = p.separador_decimal || '';
        if (sepMil) sepMil.value = p.separador_miles || '';
        if (localeInput) localeInput.value = p.locale || '';
        // Update formatos preview if preset available
        if (p.formatos) {
          if (dateSelect) dateSelect.value = p.formatos.fecha || dateSelect.value || DATE_FORMAT_OPTIONS[0];
          if (timeSelect) timeSelect.value = p.formatos.hora || timeSelect.value || TIME_FORMAT_OPTIONS[0];
        }
      });
      currencySelect.dataset.listener = '1';
    }
  };

  const collect = () => {
    const payload = {};
    // Collect only the fields we want to update
    const currencySelect = form.querySelector('#admin-config-moneda-codigo');
    const simboloInput = form.querySelector('#admin-config-moneda-simbolo');
    const sepDec = form.querySelector('#admin-config-moneda-sep-dec');
    const sepMil = form.querySelector('#admin-config-moneda-sep-mil');
    const localeInput = form.querySelector('#admin-config-moneda-locale');
    const dateSelect = form.querySelector('#admin-config-moneda-formato-fecha');
    const timeSelect = form.querySelector('#admin-config-moneda-formato-hora');

    // moneda
    const monedaPayload = {};
    if (currencySelect) monedaPayload.codigo = String(currencySelect.value || '').trim();
    if (simboloInput) monedaPayload.simbolo = String(simboloInput.value || '').trim();
    if (sepDec) monedaPayload.separador_decimal = String(sepDec.value || '').trim();
    if (sepMil) monedaPayload.separador_miles = String(sepMil.value || '').trim();
    setByPath(payload, 'moneda', monedaPayload);

    // locale
    if (localeInput) setByPath(payload, 'locale', String(localeInput.value || '').trim());

    // formatos
    const formatosPayload = {};
    if (dateSelect) formatosPayload.fecha = String(dateSelect.value || '').trim();
    if (timeSelect) formatosPayload.hora = String(timeSelect.value || '').trim();
    setByPath(payload, 'formatos', formatosPayload);
    return payload;
  };

  const open = () => {
    if (modalLoading) modalLoading.show(modal);
    fillForm(current || {});
    if (errorEl) { errorEl.hidden = true; errorEl.textContent = ''; }
    if (submitBtn) submitBtn.disabled = false;
    modal.hidden = false;
    requestAnimationFrame(() => {
      modal.classList.add('is-visible');
      if (modalLoading) modalLoading.hide(modal);
    });
  };

  const close = () => {
    modal.classList.remove('is-visible');
    modal.hidden = true;
    if (modalLoading) modalLoading.hide(modal);
    if (submitBtn) submitBtn.disabled = false;
  };

  const showError = (msg) => {
    if (errorEl) {
      errorEl.textContent = msg;
      errorEl.hidden = false;
    }
    adminNotify(msg, 'error');
  };

  closeEls.forEach((btn) => btn.addEventListener('click', close));
  document.addEventListener('keydown', (evt) => {
    if (evt.key === 'Escape' && !modal.hidden) close();
  });

  form.addEventListener('submit', async (evt) => {
    evt.preventDefault();
    if (errorEl) { errorEl.hidden = true; errorEl.textContent = ''; }
    if (!form.reportValidity()) return;
    if (submitBtn) submitBtn.disabled = true;
    const payload = collect();
    try {
      const res = await fetch('../../../src/API/AdminConfig.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'config_update', key: 'info_barberia', data: payload })
      });
      const json = await res.json().catch(() => null);
      if (!res.ok || !json || !json.ok) {
        throw new Error(json && json.error ? json.error : 'No se pudo guardar la configuracion.');
      }
      current = clone(json.data || {});
      window.ADMIN_INFO_BARBERIA = clone(json.data || {});
      adminNotify('Configuracion de moneda guardada.');
      close();
    } catch (err) {
      const message = err && err.message ? err.message : 'No se pudo guardar.';
      showError(message);
      if (submitBtn) submitBtn.disabled = false;
    }
  });

  // Expose
  window.AdminConfigMonedaModal = { open, close };
  // Initialize current from global
  current = clone(window.ADMIN_INFO_BARBERIA || {});
  // Pre-fill once on load
  try { fillForm(current); } catch (e) { /* ignore */ }
})();
