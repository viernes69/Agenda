(function adminConfigEmailTemplatesModal() {
  const modal = document.querySelector('[data-admin-modal="config-email-templates"]');
  if (!modal) return;

  const form = modal.querySelector('[data-admin-config-email-templates-form]');
  if (!form) return;

  const fields = {
    client_subject: form.querySelector('[data-admin-config-email-field="client_subject"]'),
    client_body: form.querySelector('[data-admin-config-email-field="client_body"]'),
    owner_subject: form.querySelector('[data-admin-config-email-field="owner_subject"]'),
    owner_body: form.querySelector('[data-admin-config-email-field="owner_body"]'),
  };
  const submitBtn = form.querySelector('[data-admin-config-email-templates-submit]');
  const errorEl = form.querySelector('[data-admin-config-email-templates-error]');
  const closeEls = modal.querySelectorAll('[data-admin-config-email-templates-close]');

  const defaults = {
    client_subject: 'Reserva confirmada - {negocio}',
    client_body: 'Hola {cliente}, tu reserva en {negocio} quedó confirmada.\nServicio: {servicio}\nFecha: {fecha}\nHora: {hora}',
    owner_subject: 'Nueva reserva - {cliente}',
    owner_body: 'Nueva reserva en {negocio}\nCliente: {cliente}\nCelular: {telefono}\nServicio: {servicio}\nFecha: {fecha}\nHora: {hora}',
  };

  const notify = (message, type) => {
    if (typeof window.AdminNotify === 'function') {
      window.AdminNotify(message, type || 'success');
    }
  };

  const fillForm = () => {
    const data = window.ADMIN_INFO_BARBERIA || {};
    const templates = (data.email_plantillas && typeof data.email_plantillas === 'object') ? data.email_plantillas : {};
    const client = templates.appointment_confirmed_client || {};
    const owner = templates.appointment_confirmed_owner || {};
    if (fields.client_subject) fields.client_subject.value = client.subject || defaults.client_subject;
    if (fields.client_body) fields.client_body.value = client.body || defaults.client_body;
    if (fields.owner_subject) fields.owner_subject.value = owner.subject || defaults.owner_subject;
    if (fields.owner_body) fields.owner_body.value = owner.body || defaults.owner_body;
    if (errorEl) { errorEl.hidden = true; errorEl.textContent = ''; }
    if (submitBtn) submitBtn.disabled = false;
  };

  const close = () => {
    modal.classList.remove('is-visible');
    modal.hidden = true;
    if (submitBtn) submitBtn.disabled = false;
  };

  const open = () => {
    fillForm();
    modal.hidden = false;
    requestAnimationFrame(() => modal.classList.add('is-visible'));
  };

  closeEls.forEach((btn) => btn.addEventListener('click', close));

  form.addEventListener('submit', async (evt) => {
    evt.preventDefault();
    if (!form.reportValidity()) return;
    if (submitBtn) submitBtn.disabled = true;
    const payload = {
      email_plantillas: {
        appointment_confirmed_client: {
          subject: fields.client_subject ? fields.client_subject.value.trim() : defaults.client_subject,
          body: fields.client_body ? fields.client_body.value.trim() : defaults.client_body,
        },
        appointment_confirmed_owner: {
          subject: fields.owner_subject ? fields.owner_subject.value.trim() : defaults.owner_subject,
          body: fields.owner_body ? fields.owner_body.value.trim() : defaults.owner_body,
        },
      },
    };
    try {
      const res = await fetch((window.AdminApiBase || '../../../src/API/') + 'AdminConfig.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'config_update', key: 'info_barberia', data: payload }),
      });
      const json = await res.json().catch(() => null);
      if (!res.ok || !json || !json.ok) {
        throw new Error(json && json.error ? json.error : 'No se pudieron guardar las plantillas.');
      }
      window.ADMIN_INFO_BARBERIA = JSON.parse(JSON.stringify(json.data || {}));
      notify('Plantillas de email guardadas.', 'success');
      close();
    } catch (error) {
      const message = error && error.message ? error.message : 'No se pudieron guardar las plantillas.';
      if (errorEl) { errorEl.hidden = false; errorEl.textContent = message; }
      notify(message, 'error');
      if (submitBtn) submitBtn.disabled = false;
    }
  });

  window.AdminConfigEmailTemplatesModal = { open, close };
})();
