(() => {
  const config = window.__AGENDUY_CONFIG__ || {};
  const rubrosConfig = Array.isArray(config.rubros) ? config.rubros : [];
  const planConfig = config.planes && typeof config.planes === 'object' ? config.planes : {};
  const freeTrialDays = Number(config.mercadoPago && config.mercadoPago.freeTrialDays) || 30;
  let csrfToken = String(config.csrfToken || '');
  const defaultCurrency = (config.negocio && config.negocio.moneda && config.negocio.moneda.codigo)
    || (config.currency || 'UYU');

  const modal = document.getElementById('modal-registro');
  if (!modal) return;

  const body = document.body;
  const modalDialog = modal.querySelector('.reg-modal');
  const form = modal.querySelector('#registro-form');
  if (!form) return;

  const overlayTriggers = modal.querySelectorAll('[data-registro-dismiss]');
  const stepPanels = Array.from(modal.querySelectorAll('[data-step-panel]'));
  const stepIndicators = Array.from(modal.querySelectorAll('[data-step-indicator]'));
  const nextBtn = modal.querySelector('[data-step-next]');
  const prevBtn = modal.querySelector('[data-step-prev]');
  const submitBtn = modal.querySelector('[data-reg-submit]');
  const errorEl = modal.querySelector('[data-form-error]');
  const statusEl = modal.querySelector('[data-reg-status]');
  const badgeEl = modal.querySelector('.reg-badge');
  const planLabelEl = modal.querySelector('[data-reg-plan]');
  const hiddenRubroId = form.querySelector('input[name="rubro_id"]');
  const businessRubroSelect = form.querySelector('select[name="business_rubro"]');
  const hiddenPlanId = form.querySelector('input[name="plan_id"]');
  const hiddenPlanNombre = form.querySelector('input[name="plan_nombre"]');
  const businessPlanSelect = form.querySelector('select[name="business_plan"]');
  const businessTypeSelect = form.querySelector('[data-reg-business-type]');
  const logoInput = form.querySelector('input[name="business_logo"]');
  const termsInput = form.querySelector('input[name="terms"]');
  const phoneCountryField = form.querySelector('[data-reg-phone-country]');
  const phoneInput = form.querySelector('[data-reg-phone-input]');
  const phoneHintEl = form.querySelector('[data-reg-phone-hint]');

  const progressOverlay = modal.querySelector('[data-reg-progress]');
  const progressMessage = progressOverlay ? progressOverlay.querySelector('[data-reg-progress-message]') : null;
  const progressBarFill = progressOverlay ? progressOverlay.querySelector('[data-reg-progress-bar]') : null;

  const hoursContainer = modal.querySelector('[data-reg-hours]');
  const tzSummary = hoursContainer ? hoursContainer.querySelector('[data-reg-hours-summary]') : null;
  const timezoneField = hoursContainer ? hoursContainer.querySelector('[data-reg-hours-timezone]') : null;
  const dayFieldsets = hoursContainer ? Array.from(hoursContainer.querySelectorAll('[data-reg-hours-day]')) : [];

  const serviceFormEl = modal.querySelector('[data-reg-service-form]');
  const serviceStepTitle = modal.querySelector('#reg-step-3-title');
  const serviceStepHint = modal.querySelector('[data-step-panel="2"] .reg-hint');
  const serviceListEl = modal.querySelector('[data-reg-service-list]');
  const serviceErrorEl = modal.querySelector('[data-reg-service-error]');
  const serviceAddBtn = modal.querySelector('[data-reg-service-add]');
  const serviceResetBtn = modal.querySelector('[data-reg-service-reset]');
  const serviceFields = serviceFormEl ? {
    nombre: serviceFormEl.querySelector('[data-reg-service-field="Nombre"]'),
    duracion: serviceFormEl.querySelector('[data-reg-service-field="Duracion"]'),
    precio: serviceFormEl.querySelector('[data-reg-service-field="Precio"]'),
    imagen: serviceFormEl.querySelector('[data-reg-service-field="Imagen"]'),
  } : {};

  const statusClasses = ['reg-status--info', 'reg-status--error', 'reg-status--success'];
  const DAY_KEYS = dayFieldsets.map((fieldset) => fieldset.getAttribute('data-reg-hours-day') || '');
  const SERVICE_STEP = 2;
  const HOURS_STEP = 3;
  const PHONE_DEFAULT_COUNTRY = 'UY';
  const PHONE_RULES = {
    UY: {
      prefix: '+598',
      pattern: /^0?9\d{7}$/,
      placeholder: 'Ej: 092365135',
      hint: 'Ingresa un celular uruguayo (09 + 7 dígitos)',
    },
    AR: {
      prefix: '+54',
      pattern: /^\d{6,12}$/,
      placeholder: 'Ej: 11 2345 6789',
      hint: 'Ingresa tu número móvil argentino',
    },
    BR: {
      prefix: '+55',
      pattern: /^\d{10,11}$/,
      placeholder: 'Ej: 11 92345 6789',
      hint: 'Ingresa tu número móvil brasileño',
    },
    CL: {
      prefix: '+56',
      pattern: /^\d{9}$/,
      placeholder: 'Ej: 912345678',
      hint: 'Ingresa tu celular chileno',
    },
    PY: {
      prefix: '+595',
      pattern: /^\d{9}$/,
      placeholder: 'Ej: 981123456',
      hint: 'Ingresa tu celular paraguayo',
    },
  };

  let currentStep = 0;
  let isOpen = false;
  let restoreFocusEl = null;
  let timezoneReady = false;
  let isSubmitting = false;
  let servicesData = [];
  let serviceAutoId = 1;
  let currentRubroName = '';
  let googleIdToken = '';
  let isRestoringDraft = false;
  let registerDraftTimer = null;

  const REGISTER_DRAFT_KEY = 'agenduy-register-draft-v1';

  const regGoogleWrap = modal.querySelector('#reg-google-wrap');

  function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, (match) => ({
      '&': '&amp;',
      '<': '&lt;',
      '>': '&gt;',
      '"': '&quot;',
      "'": '&#39;'
    })[match] || match);
  }

  function formatCurrency(amount, currency) {
    const num = Number(amount || 0);
    const code = currency || 'UYU';
    try {
      return new Intl.NumberFormat('es-UY', { style: 'currency', currency: code }).format(num);
    } catch (_) {
      return `${code} ${num.toFixed(2)}`;
    }
  }

  function getPhoneRule(code) {
    const key = (code || '').toUpperCase();
    return PHONE_RULES[key] || PHONE_RULES[PHONE_DEFAULT_COUNTRY];
  }

  function syncPhoneField() {
    if (!phoneInput) return;
    const countryValue = phoneCountryField ? phoneCountryField.value : PHONE_DEFAULT_COUNTRY;
    const rule = getPhoneRule(countryValue);
    phoneInput.pattern = rule.pattern.source.replace(/^\^|\$$/g, '');
    phoneInput.placeholder = rule.placeholder;
    if (phoneHintEl) {
      phoneHintEl.textContent = rule.hint;
    }
  }

  function formatPhoneValue(countryCode, localValue) {
    const rule = getPhoneRule(countryCode);
    const normalized = (localValue || '').replace(/[^\d]/g, '');
    const prefix = rule.prefix || '';
    return [prefix, normalized].filter(Boolean).join(' ').trim();
  }

  function getPlan(planId) {
    if (!planId) return null;
    const key = String(planId);
    return Object.prototype.hasOwnProperty.call(planConfig, key) ? planConfig[key] : null;
  }

  function normalizeBusinessType(value) {
    const type = String(value || '').trim().toLowerCase().replace(/[\s-]+/g, '_');
    return ['tienda', 'store', 'catalogo', 'catalog'].includes(type) ? 'tienda' : 'servicios';
  }

  function selectedBusinessType() {
    if (businessTypeSelect) {
      return normalizeBusinessType(businessTypeSelect.value);
    }
    return inferBusinessType();
  }

  function inferBusinessType(payload = {}) {
    const explicit = payload.tipoComercio || payload.tipo_comercio || payload.businessType || payload.business_type || '';
    if (explicit) return normalizeBusinessType(explicit);
    const label = String(payload.rubroNombre || payload.rubro_nombre || currentRubroName || '').toLowerCase();
    return /tienda|comercio|retail|catalogo/.test(label) ? 'tienda' : 'servicios';
  }

  function syncBusinessTypeUi() {
    const isStore = selectedBusinessType() === 'tienda';
    if (serviceStepTitle) {
      serviceStepTitle.textContent = isStore ? 'Catalogo de tu tienda' : 'Servicios de tu Negocio';
    }
    if (serviceStepHint) {
      serviceStepHint.textContent = isStore
        ? 'No necesitas cargar servicios ahora. Al finalizar vas al panel para subir productos y armar tu catalogo.'
        : 'Agrega los servicios que ofreceras. Podras sumar mas desde el panel.';
    }
    if (serviceFormEl) serviceFormEl.hidden = isStore;
    if (serviceListEl) serviceListEl.hidden = isStore;
    showServiceError('');
  }

  function getPlanIdForRubro(rubroId) {
    const rid = String(rubroId || '');
    if (!rid) return Object.keys(planConfig)[0] || '';
    const found = rubrosConfig.find((item) => String(item.id) === rid);
    if (found && found.plan_id) return String(found.plan_id);
    return Object.keys(planConfig)[0] || '';
  }

  function syncRubroSelection(rubroId) {
    const rid = String(rubroId || '');
    if (hiddenRubroId) hiddenRubroId.value = rid;
    if (businessRubroSelect) {
      businessRubroSelect.value = rid;
    }
    const found = rubrosConfig.find((item) => String(item.id) === rid);
    if (found && found.nombre) {
      currentRubroName = String(found.nombre);
    }
  }

  function showError(message) {
    if (!errorEl) return;
    const text = String(message || '').trim();
    if (!text) {
      errorEl.textContent = '';
      errorEl.hidden = true;
    } else {
      errorEl.textContent = text;
      errorEl.hidden = false;
    }
  }

  function clearStatus() {
    if (!statusEl) return;
    statusEl.textContent = '';
    statusEl.hidden = true;
    statusClasses.forEach((cls) => statusEl.classList.remove(cls));
  }

  function setStatus(message, kind = 'info') {
    if (!statusEl) return;
    const text = String(message || '').trim();
    clearStatus();
    if (!text) return;
    const className = kind === 'error' ? 'reg-status--error' : kind === 'success' ? 'reg-status--success' : 'reg-status--info';
    statusEl.textContent = text;
    statusEl.hidden = false;
    statusEl.classList.add(className);
  }

  function collectRegisterFields() {
    const fields = {};
    Array.from(form.elements).forEach((field) => {
      if (!field || !field.name || field.disabled) return;
      if (field.type === 'file' || field.type === 'password') return;
      if (field.type === 'checkbox') {
        fields[field.name] = Boolean(field.checked);
        return;
      }
      if (field.type === 'radio') {
        if (field.checked) fields[field.name] = field.value;
        return;
      }
      fields[field.name] = field.value;
    });
    return fields;
  }

  function applyRegisterFields(fields) {
    if (!fields || typeof fields !== 'object') return;
    Array.from(form.elements).forEach((field) => {
      if (!field || !field.name || !Object.prototype.hasOwnProperty.call(fields, field.name)) return;
      if (field.type === 'file' || field.type === 'password') return;
      const value = fields[field.name];
      if (field.type === 'checkbox') {
        field.checked = Boolean(value);
        return;
      }
      if (field.type === 'radio') {
        field.checked = String(field.value) === String(value);
        return;
      }
      field.value = String(value ?? '');
    });
  }

  function collectServiceDraft() {
    if (!serviceFormEl) return {};
    return {
      nombre: serviceFields.nombre ? serviceFields.nombre.value : '',
      duracion: serviceFields.duracion ? serviceFields.duracion.value : '',
      precio: serviceFields.precio ? serviceFields.precio.value : '',
    };
  }

  function applyServiceDraft(draft) {
    if (!draft || typeof draft !== 'object') return;
    if (serviceFields.nombre) serviceFields.nombre.value = String(draft.nombre || '');
    if (serviceFields.duracion) serviceFields.duracion.value = String(draft.duracion || '');
    if (serviceFields.precio) serviceFields.precio.value = String(draft.precio || '');
  }

  function collectRegisterDraft() {
    return {
      version: 1,
      savedAt: Date.now(),
      currentStep,
      currentRubroName,
      fields: collectRegisterFields(),
      services: servicesData.map((service) => ({ ...service })),
      serviceDraft: collectServiceDraft(),
      horarios: collectHoursData(),
    };
  }

  function hasRegisterDraftContent(draft) {
    if (!draft || typeof draft !== 'object') return false;
    const fields = draft.fields && typeof draft.fields === 'object' ? draft.fields : {};
    const hasField = Object.entries(fields).some(([name, value]) => {
      if (name === 'terms') return Boolean(value);
      return String(value || '').trim() !== '';
    });
    const hasServices = Array.isArray(draft.services) && draft.services.length > 0;
    const serviceDraft = draft.serviceDraft && typeof draft.serviceDraft === 'object' ? draft.serviceDraft : {};
    const hasServiceDraft = Object.values(serviceDraft).some((value) => String(value || '').trim() !== '');
    const hours = draft.horarios && typeof draft.horarios === 'object' ? draft.horarios : {};
    const hasOpenDay = Object.values(hours).some((day) => day && typeof day === 'object' && day.abierto);
    return hasField || hasServices || hasServiceDraft || hasOpenDay || Number(draft.currentStep || 0) > 0;
  }

  function saveRegisterDraftNow() {
    if (isRestoringDraft || isSubmitting) return;
    try {
      const draft = collectRegisterDraft();
      if (!hasRegisterDraftContent(draft)) {
        sessionStorage.removeItem(REGISTER_DRAFT_KEY);
        return;
      }
      sessionStorage.setItem(REGISTER_DRAFT_KEY, JSON.stringify(draft));
    } catch (_) {}
  }

  function scheduleRegisterDraftSave() {
    if (isRestoringDraft || isSubmitting) return;
    window.clearTimeout(registerDraftTimer);
    registerDraftTimer = window.setTimeout(saveRegisterDraftNow, 250);
  }

  function loadRegisterDraft() {
    try {
      const raw = sessionStorage.getItem(REGISTER_DRAFT_KEY);
      const draft = raw ? JSON.parse(raw) : null;
      return draft && typeof draft === 'object' ? draft : null;
    } catch (_) {
      return null;
    }
  }

  function clearRegisterDraft() {
    try {
      sessionStorage.removeItem(REGISTER_DRAFT_KEY);
    } catch (_) {}
  }

  function toggleProgress(show, message) {
    if (!progressOverlay || !modalDialog) return;
    if (message && progressMessage) {
      progressMessage.textContent = message;
    }
    progressOverlay.classList.toggle('hidden', !show);
    modalDialog.classList.toggle('reg-modal--loading', show);
    if (progressBarFill) {
      progressBarFill.classList.remove('is-animating');
      if (show) {
        // force reflow to restart animation
        void progressBarFill.offsetWidth;
        progressBarFill.classList.add('is-animating');
      }
    }
  }

  function setStep(index) {
    const max = Math.max(0, stepPanels.length - 1);
    currentStep = Math.min(Math.max(index, 0), max);
    stepPanels.forEach((panel, idx) => {
      const active = idx === currentStep;
      panel.hidden = !active;
      panel.setAttribute('aria-hidden', active ? 'false' : 'true');
    });
    stepIndicators.forEach((item, idx) => {
      item.classList.toggle('active', idx === currentStep);
      item.classList.toggle('completed', idx < currentStep);
    });
    if (prevBtn) prevBtn.disabled = currentStep === 0;
    if (nextBtn) nextBtn.hidden = currentStep === max;
    if (submitBtn) submitBtn.hidden = currentStep !== max;
    if (statusEl && currentStep !== max) clearStatus();
    if (errorEl) showError('');
    if (currentStep !== SERVICE_STEP) {
      showServiceError('');
    }
    if (currentStep === HOURS_STEP) {
      prepareHoursStep();
    }
    scheduleRegisterDraftSave();
  }

  function validateStep(index) {
    if (index === SERVICE_STEP) {
      return validateServicesStep();
    }
    if (index === 0 && !googleIdToken) {
      const passInput = form.querySelector('[name="owner_password"]');
      const passVal = passInput ? String(passInput.value || '') : '';
      if (passVal.length < 8) {
        if (passInput) {
          passInput.setCustomValidity('La contraseña debe tener al menos 8 caracteres.');
          passInput.reportValidity();
          passInput.setCustomValidity('');
        }
        return false;
      }
      const cedulaInput = form.querySelector('[name="owner_cedula"]');
      if (cedulaInput && !String(cedulaInput.value || '').trim()) {
        cedulaInput.setCustomValidity('Completa tu cédula.');
        cedulaInput.reportValidity();
        cedulaInput.setCustomValidity('');
        return false;
      }
    }
    const panel = stepPanels[index];
    if (!panel) return true;
    const inputs = panel.querySelectorAll('input, select, textarea');
    for (const input of inputs) {
      if (input.disabled || input.type === 'file') continue;
      if (!input.checkValidity()) {
        input.reportValidity();
        return false;
      }
    }
    return true;
  }

  function showServiceError(message) {
    if (!serviceErrorEl) return;
    const text = String(message || '').trim();
    if (!text) {
      serviceErrorEl.textContent = '';
      serviceErrorEl.hidden = true;
    } else {
      serviceErrorEl.textContent = text;
      serviceErrorEl.hidden = false;
    }
  }

  function serviceFormHasValues() {
    if (!serviceFormEl) return false;
    let dirty = false;
    if (serviceFields.nombre && serviceFields.nombre.value.trim() !== '') {
      dirty = true;
    }
    if (serviceFields.duracion && String(serviceFields.duracion.value || '').trim() !== '') {
      dirty = true;
    }
    if (serviceFields.precio && String(serviceFields.precio.value || '').trim() !== '') {
      dirty = true;
    }
    if (serviceFields.imagen && serviceFields.imagen.files && serviceFields.imagen.files.length > 0) {
      dirty = true;
    }
    return dirty;
  }

  function validateServicesStep() {
    showServiceError('');
    if (selectedBusinessType() === 'tienda') {
      return true;
    }
    if (!serviceFormEl) return true;
    if (serviceFormHasValues()) {
      showServiceError('Completa el servicio y presiona "Agregar servicio" o limpia los campos.');
      return false;
    }
    if (servicesData.length === 0) {
      showServiceError('Agrega al menos un servicio para continuar.');
      return false;
    }
    return true;
  }

  function collectData() {
    const formData = new FormData(form);
    const rubroId = formData.get('rubro_id') || '';
    const planId = formData.get('plan_id') || '';
    const plan = getPlan(planId) || {};
    const rubroName = currentRubroName || '';
    const phoneCountry = (formData.get('business_phone_country') || PHONE_DEFAULT_COUNTRY).toString().trim() || PHONE_DEFAULT_COUNTRY;
    const phoneLocal = (formData.get('business_phone') || '').toString().trim();
    const telefono = formatPhoneValue(phoneCountry, phoneLocal);
    const tipoComercio = selectedBusinessType();

    const billingPeriod = (formData.get('billing_period') || 'monthly').toString().trim() || 'monthly';

    return {
      _csrf: csrfToken,
      tipoComercio,
      planId: plan && plan.id ? String(plan.id) : String(planId || ''),
      planNombre: plan && plan.nombre ? plan.nombre : (hiddenPlanNombre ? hiddenPlanNombre.value : ''),
      billing_period: billingPeriod,
      billingPeriod: billingPeriod,
      rubroId: rubroId ? String(rubroId) : '',
      owner: {
        nombre: (formData.get('owner_name') || '').toString().trim(),
        apellido: (formData.get('owner_lastname') || '').toString().trim(),
        email: (formData.get('owner_email') || '').toString().trim(),
        cedula: (formData.get('owner_cedula') || '').toString().trim(),
        password: (formData.get('owner_password') || '').toString(),
        google_id_token: googleIdToken || '',
      },
      negocio: {
        rubroId: rubroId ? String(rubroId) : '',
        rubroNombre: rubroName,
        tipoComercio,
        telefono,
        telefonoPais: phoneCountry,
        nombre: (formData.get('business_name') || '').toString().trim(),
        rut: (formData.get('business_rut') || '').toString().trim(),
        pais: (formData.get('business_country') || '').toString().trim(),
        ciudad: (formData.get('business_city') || '').toString().trim(),
        calle: (formData.get('business_street') || '').toString().trim(),
        logoNombre: logoInput && logoInput.files && logoInput.files[0] ? logoInput.files[0].name : ''
      },
      servicios: tipoComercio === 'tienda' ? [] : serializeServices(),
      horarios: collectHoursData(),
    };
  }

  function serializeServices() {
    return servicesData.map((service) => ({
      tempId: service.id,
      nombre: service.nombre,
      duracion: service.duracion,
      estado: service.estado,
      precio: service.precio,
      puntos: service.puntos,
      imagenNombre: service.imagenNombre,
    }));
  }

  function ensureInputsValid(inputs) {
    for (const input of inputs) {
      if (!input || input.disabled || input.type === 'file') continue;
      if (!input.checkValidity()) {
        input.reportValidity();
        return false;
      }
    }
    return true;
  }

  function resetServiceForm() {
    if (!serviceFormEl) return;
    if (serviceFields.nombre) serviceFields.nombre.value = '';
    if (serviceFields.duracion) serviceFields.duracion.value = '';
    if (serviceFields.precio) serviceFields.precio.value = '';
    if (serviceFields.imagen) serviceFields.imagen.value = '';
  }

  function renderServicesList() {
    if (!serviceListEl) return;
    serviceListEl.replaceChildren();
    if (servicesData.length === 0) {
      const empty = document.createElement('p');
      empty.className = 'reg-hint';
      empty.textContent = 'Aún no agregaste servicios.';
      serviceListEl.appendChild(empty);
      return;
    }

    servicesData.forEach((service) => {
      const item = document.createElement('article');
      item.className = 'reg-collection__item';

      const header = document.createElement('header');
      const title = document.createElement('h5');
      title.className = 'reg-collection__title';
      title.textContent = service.nombre || `Servicio ${service.id}`;
      header.appendChild(title);

      const removeBtn = document.createElement('button');
      removeBtn.type = 'button';
      removeBtn.className = 'reg-collection__remove';
      removeBtn.dataset.regServiceRemove = String(service.id);
      removeBtn.textContent = 'Quitar';
      header.appendChild(removeBtn);

      item.appendChild(header);

      const meta = document.createElement('p');
      meta.className = 'reg-collection__meta';

      const durationBadge = document.createElement('span');
      durationBadge.textContent = `${service.duracion} min`;
      meta.appendChild(durationBadge);

      const priceBadge = document.createElement('span');
      priceBadge.textContent = formatCurrency(service.precio, defaultCurrency);
      meta.appendChild(priceBadge);

      if (service.puntos !== null && service.puntos !== '' && !Number.isNaN(Number(service.puntos))) {
        const pointsBadge = document.createElement('span');
        pointsBadge.textContent = `${service.puntos} pts`;
        meta.appendChild(pointsBadge);
      }

      item.appendChild(meta);

      serviceListEl.appendChild(item);
    });
  }

  function addServiceFromForm() {
    if (!serviceFormEl) return;
    showServiceError('');
    const fieldsToValidate = [
      serviceFields.nombre,
      serviceFields.duracion,
      serviceFields.precio,
    ];
    if (!ensureInputsValid(fieldsToValidate)) {
      return;
    }

    const nombre = serviceFields.nombre ? serviceFields.nombre.value.trim() : '';
    const duracion = serviceFields.duracion ? Number(serviceFields.duracion.value) : 0;
    const precioRaw = serviceFields.precio ? Number(serviceFields.precio.value) : 0;
    const imagenNombre = serviceFields.imagen && serviceFields.imagen.files && serviceFields.imagen.files[0]
      ? serviceFields.imagen.files[0].name
      : '';

    const service = {
      id: serviceAutoId++,
      nombre,
      duracion: Number.isFinite(duracion) ? duracion : 0,
      estado: 'Activo',
      precio: Number.isFinite(precioRaw) ? precioRaw : 0,
      puntos: null,
      imagenNombre,
    };

    servicesData.push(service);
    renderServicesList();
    resetServiceForm();
    showServiceError('');
    scheduleRegisterDraftSave();
  }

  function removeServiceById(id) {
    const nextServices = servicesData.filter((service) => service.id !== id);
    if (nextServices.length === servicesData.length) {
      return;
    }
    servicesData = nextServices;
    renderServicesList();
    validateServicesStep();
    scheduleRegisterDraftSave();
  }

  function onServiceListClick(event) {
    const button = event.target.closest('[data-reg-service-remove]');
    if (!button) return;
    event.preventDefault();
    const id = Number(button.dataset.regServiceRemove);
    if (!Number.isNaN(id)) {
      removeServiceById(id);
    }
  }

  function syncPlanInfo(planId, rubroName, billingPeriod = 'monthly') {
    const plan = getPlan(planId);
    const planNombre = plan && plan.nombre ? plan.nombre : (hiddenPlanNombre ? hiddenPlanNombre.value : '');
    const isYearly = billingPeriod === 'yearly';
    const hiddenPeriod = form.querySelector('input[name="billing_period"]');
    if (hiddenPeriod) hiddenPeriod.value = isYearly ? 'yearly' : 'monthly';
    if (hiddenPlanId) hiddenPlanId.value = planId ? String(planId) : '';
    if (hiddenPlanNombre) hiddenPlanNombre.value = planNombre || '';
    if (businessPlanSelect && getPlan(planId)) businessPlanSelect.value = String(planId);

    const price = plan ? Number(plan.precio || 0) : 0;
    const isFree = price <= 0;
    const discount = plan ? Number(plan.descuento_anual_pct || 20) : 20;
    const yearlyPrice = plan && plan.precio_anual ? Number(plan.precio_anual) : Math.round(price * 12 * (1 - discount / 100));

    if (planLabelEl) {
      if (isFree) {
        planLabelEl.textContent = `Plan ${planNombre || 'Gratis'} - Gratis para siempre`;
      } else if (isYearly) {
        planLabelEl.textContent = `Plan ${planNombre} - ${plan.moneda || 'UYU'} ${yearlyPrice.toLocaleString('es-UY')}/año (${discount}% OFF)`;
      } else {
        planLabelEl.textContent = `Plan ${planNombre} - ${plan.moneda || 'UYU'} ${price.toLocaleString('es-UY')}/mes`;
      }
    }
    if (badgeEl) {
      badgeEl.textContent = isFree ? 'Gratis Ilimitado' : (isYearly ? `Plan Anual · ${discount}% OFF` : 'Plan Mensual');
    }
  }

  function resetForm() {
    form.reset();
    googleIdToken = '';
    if (window.AgendarteGoogleAuth && typeof window.AgendarteGoogleAuth.clearToken === 'function') {
      window.AgendarteGoogleAuth.clearToken();
    }
    const passInput = form.querySelector('[name="owner_password"]');
    const cedulaInput = form.querySelector('[name="owner_cedula"]');
    if (passInput) passInput.setAttribute('required', 'required');
    if (cedulaInput) cedulaInput.setAttribute('required', 'required');
    if (logoInput) logoInput.value = '';
    timezoneReady = false;
    servicesData = [];
    serviceAutoId = 1;
    resetServiceForm();
    renderServicesList();
    syncBusinessTypeUi();
    showServiceError('');
    resetHoursForm();
    toggleProgress(false);
  }

  function updateTimezoneSummary(value) {
    if (!tzSummary) return;

    if (!value) {
      tzSummary.textContent = 'No pudimos detectar tu zona horaria automáticamente.';
    } else {
      tzSummary.textContent = `Zona horaria detectada: ${value}`;
    }
  }

  function setTimezoneValue(value) {
    if (timezoneField) timezoneField.value = value || '';
    updateTimezoneSummary(value || '');
  }

  function detectBrowserTimezone() {
    try {
      const tz = Intl.DateTimeFormat().resolvedOptions().timeZone;
      return typeof tz === 'string' && tz.trim() ? tz.trim() : '';
    } catch (error) {
      console.warn('No se pudo detectar la zona horaria del navegador.', error);
      return '';
    }
  }

  function applyAutoTimezone() {
    const detected = detectBrowserTimezone();
    if (detected) {
      setTimezoneValue(detected);
      return;
    }

    setTimezoneValue('');
  }

  function snapTimeQuarter(value, fallback = '09:00') {
    if (typeof value !== 'string' || value.trim() === '') return fallback;
    const match = value.trim().match(/^(\d{1,2}):(\d{2})/);
    if (!match) return fallback;
    let hour = parseInt(match[1], 10);
    let minute = parseInt(match[2], 10);
    if (Number.isNaN(hour) || Number.isNaN(minute)) return fallback;
    minute = Math.round(minute / 15) * 15;
    if (minute === 60) {
      minute = 0;
      hour = (hour + 1) % 24;
    }
    return `${String(hour).padStart(2, '0')}:${String(minute).padStart(2, '0')}`;
  }

  function applyTimeSelect(select, value, fallback = '09:00') {
    if (!select) return;
    const normalized = snapTimeQuarter(value, fallback);
    if (normalized && !select.querySelector(`option[value="${normalized}"]`)) {
      const option = document.createElement('option');
      option.value = normalized;
      option.textContent = normalized;
      select.appendChild(option);
    }
    select.value = normalized || fallback;
  }

  function setDayInputsState(dayEl, enabled) {
    if (!dayEl) return;
    const start = dayEl.querySelector('[data-reg-hours-start]');
    const end = dayEl.querySelector('[data-reg-hours-end]');
    const breakToggle = dayEl.querySelector('[data-reg-hours-break-toggle]');
    const breakStart = dayEl.querySelector('[data-reg-hours-break-start]');
    const breakEnd = dayEl.querySelector('[data-reg-hours-break-end]');
    [start, end].forEach((input) => {
      if (!input) return;
      input.disabled = !enabled;
      input.required = enabled;
      if (enabled) {
        if (!input.value) {
          applyTimeSelect(input, input === start ? '09:00' : '18:00', input === start ? '09:00' : '18:00');
        }
      }
    });
    if (breakToggle) {
      breakToggle.disabled = !enabled;
      if (!enabled) {
        breakToggle.checked = false;
      }
    }
    const breakEnabled = enabled && breakToggle && breakToggle.checked;
    if (breakStart && breakEnd) {
      breakStart.disabled = !breakEnabled;
      breakEnd.disabled = !breakEnabled;
      breakStart.required = breakEnabled;
      breakEnd.required = breakEnabled;
      if (!breakEnabled) {
        breakStart.value = '';
        breakEnd.value = '';
      }
    }
  }

  function initializeDayFieldsets() {
    dayFieldsets.forEach((dayEl) => {
      const toggle = dayEl.querySelector('[data-reg-hours-open]');
      const breakToggle = dayEl.querySelector('[data-reg-hours-break-toggle]');
      if (toggle) {
        toggle.addEventListener('change', () => {
          setDayInputsState(dayEl, Boolean(toggle.checked));
        });
        setDayInputsState(dayEl, Boolean(toggle.checked));
      }
      if (breakToggle) {
        breakToggle.addEventListener('change', () => {
          setDayInputsState(dayEl, Boolean(toggle && toggle.checked));
        });
      }
    });
  }

  function resetHoursForm() {
    applyAutoTimezone();
    dayFieldsets.forEach((dayEl) => {
      const toggle = dayEl.querySelector('[data-reg-hours-open]');
      if (toggle) toggle.checked = false;
      setDayInputsState(dayEl, false);
      applyTimeSelect(dayEl.querySelector('[data-reg-hours-start]'), '09:00', '09:00');
      applyTimeSelect(dayEl.querySelector('[data-reg-hours-end]'), '18:00', '18:00');
    });
    timezoneReady = true;
  }

  function applyHoursDraft(schedule) {
    if (!schedule || typeof schedule !== 'object') return;
    if (typeof schedule.timezone === 'string') {
      setTimezoneValue(schedule.timezone);
    }
    dayFieldsets.forEach((dayEl) => {
      const key = dayEl.getAttribute('data-reg-hours-day') || '';
      const day = key ? schedule[key] : null;
      if (!day || typeof day !== 'object') return;
      const toggle = dayEl.querySelector('[data-reg-hours-open]');
      if (toggle) toggle.checked = Boolean(day.abierto);
      setDayInputsState(dayEl, Boolean(day.abierto));
      applyTimeSelect(dayEl.querySelector('[data-reg-hours-start]'), day.inicio || '09:00', '09:00');
      applyTimeSelect(dayEl.querySelector('[data-reg-hours-end]'), day.fin || '18:00', '18:00');
      const breakToggle = dayEl.querySelector('[data-reg-hours-break-toggle]');
      if (breakToggle) breakToggle.checked = Boolean(day.descanso_inicio || day.descanso_fin);
      applyTimeSelect(dayEl.querySelector('[data-reg-hours-break-start]'), day.descanso_inicio || '', '');
      applyTimeSelect(dayEl.querySelector('[data-reg-hours-break-end]'), day.descanso_fin || '', '');
      setDayInputsState(dayEl, Boolean(day.abierto));
    });
    timezoneReady = true;
  }

  function applyRegisterDraft(draft) {
    if (!draft || typeof draft !== 'object') return null;
    isRestoringDraft = true;
    try {
      applyRegisterFields(draft.fields || {});
      currentRubroName = String(draft.currentRubroName || currentRubroName || '');
      syncPhoneField();
      syncBusinessTypeUi();
      const restoredServices = Array.isArray(draft.services) ? draft.services : [];
      let maxServiceId = 0;
      servicesData = restoredServices.map((service) => {
        const id = Number(service.id || service.tempId || 0) || (maxServiceId + 1);
        maxServiceId = Math.max(maxServiceId, id);
        return {
          id,
          nombre: String(service.nombre || ''),
          duracion: Number(service.duracion) || 0,
          estado: service.estado || 'Activo',
          precio: Number(service.precio) || 0,
          puntos: service.puntos ?? null,
          imagenNombre: String(service.imagenNombre || ''),
        };
      });
      serviceAutoId = maxServiceId + 1;
      renderServicesList();
      applyServiceDraft(draft.serviceDraft || {});
      applyHoursDraft(draft.horarios || {});
      return Number.isFinite(Number(draft.currentStep)) ? Number(draft.currentStep) : null;
    } finally {
      isRestoringDraft = false;
    }
  }

  function collectHoursData() {
    const schedule = { timezone: timezoneField ? timezoneField.value.trim() : '' };
    dayFieldsets.forEach((dayEl) => {
      const key = dayEl.getAttribute('data-reg-hours-day') || '';
      if (!key) return;
      const toggle = dayEl.querySelector('[data-reg-hours-open]');
      const start = dayEl.querySelector('[data-reg-hours-start]');
      const end = dayEl.querySelector('[data-reg-hours-end]');
      const breakToggle = dayEl.querySelector('[data-reg-hours-break-toggle]');
      const breakStart = dayEl.querySelector('[data-reg-hours-break-start]');
      const breakEnd = dayEl.querySelector('[data-reg-hours-break-end]');
      const abierto = Boolean(toggle && toggle.checked);
      schedule[key] = {
        abierto,
        inicio: start && start.value ? start.value : '',
        fin: end && end.value ? end.value : '',
        descanso_inicio: breakToggle && breakToggle.checked && breakStart ? breakStart.value : '',
        descanso_fin: breakToggle && breakToggle.checked && breakEnd ? breakEnd.value : ''
      };
    });
    return schedule;
  }

  function compareTimes(a, b) {
    const [ah, am] = a.split(':').map(Number);
    const [bh, bm] = b.split(':').map(Number);
    return ah * 60 + am - (bh * 60 + bm);
  }

  function validateHoursData(schedule) {
    if (!schedule.timezone) {
      return { ok: false, error: 'Define la zona horaria de tu negocio.' };
    }
    for (const key of DAY_KEYS) {
      const day = schedule[key];
      if (!day || !day.abierto) continue;
      if (!day.inicio || !day.fin) {
        return { ok: false, error: `Completa el horario de inicio y fin para ${key}.` };
      }
      if (compareTimes(day.inicio, day.fin) >= 0) {
        return { ok: false, error: `El horario de ${key} debe tener una hora de fin posterior al inicio.` };
      }
      if ((day.descanso_inicio && !day.descanso_fin) || (!day.descanso_inicio && day.descanso_fin)) {
        return { ok: false, error: `Completa ambos horarios de descanso para ${key}.` };
      }
      if (day.descanso_inicio && day.descanso_fin) {
        if (compareTimes(day.inicio, day.descanso_inicio) > 0 || compareTimes(day.descanso_fin, day.fin) > 0) {
          return { ok: false, error: `El descanso de ${key} debe estar dentro del horario de apertura.` };
        }
        if (compareTimes(day.descanso_inicio, day.descanso_fin) >= 0) {
          return { ok: false, error: `El descanso de ${key} debe tener una hora de fin posterior al inicio.` };
        }
      }
    }
    return { ok: true };
  }

  function prepareHoursStep() {
    if (!timezoneReady) {
      resetHoursForm();
    }
  }

  async function completeRegistration() {
    if (isSubmitting) return;
    if (!validateStep(currentStep)) return;
    if (termsInput && !termsInput.checked) {
      setStatus('Debes aceptar los terminos antes de continuar.', 'error');
      termsInput.focus();
      return;
    }

    const data = collectData();
    const validation = validateHoursData(data.horarios || {});
    if (!validation.ok) {
      setStatus(validation.error || 'Revisa los horarios configurados.', 'error');
      return;
    }

    setStatus('Datos listos. Finalizando registro...', 'success');
    toggleProgress(true, 'Estamos Preparando el sitio para tu Negocio, Por Favor Espera');
    if (submitBtn) submitBtn.disabled = true;
    isSubmitting = true;

    const sendRegister = async () => {
      const body = { ...data, _csrf: csrfToken };
      const rawUrlBase = (window.__AGENDUY_CONFIG__ && window.__AGENDUY_CONFIG__.urlBase) ? window.__AGENDUY_CONFIG__.urlBase : '';
      const urlBase = rawUrlBase ? (rawUrlBase.endsWith('/') ? rawUrlBase : rawUrlBase + '/') : '';
      const response = await fetch(urlBase + 'src/API/register.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          ...(csrfToken ? { 'X-CSRF-Token': csrfToken } : {}),
        },
        credentials: 'include',
        body: JSON.stringify(body),
      });
      let payload = null;
      try {
        payload = await response.json();
      } catch (parseError) {
        payload = null;
      }
      return { response, payload };
    };

    let redirectUrl = null;
    try {
      let { response, payload } = await sendRegister();

      // Token vencido o sesión nueva: el servidor manda uno fresco;
      // reintentamos sin que el usuario vea el error de CSRF.
      if (payload && payload.error === 'csrf_retry' && payload.csrf) {
        csrfToken = String(payload.csrf);
        ({ response, payload } = await sendRegister());
      }

      const friendly = (msg) => (!msg || msg === 'csrf_retry')
        ? 'No se pudo completar el registro en este momento. Intenta nuevamente.'
        : msg;
      if (!response.ok || !payload) {
        throw new Error(friendly(payload && payload.error));
      }
      if (!payload.ok) {
        throw new Error(friendly(payload.error));
      }

      clearRegisterDraft();
      redirectUrl = typeof payload.redirect === 'string' && payload.redirect ? payload.redirect : null;
      if (redirectUrl) {
        window.location.href = redirectUrl;
        return;
      }

      toggleProgress(false);
      setStatus('Registro completado. Redirige manualmente al panel de administraci&oacute;n.', 'success');
    } catch (error) {
      const message = error && error.message ? error.message : 'Ocurri&oacute; un problema al finalizar el registro.';
      setStatus(message, 'error');
    } finally {
      if (!redirectUrl) {
        toggleProgress(false);
        if (submitBtn) submitBtn.disabled = false;
      }
      isSubmitting = false;
    }
  }

  function openModal(payload = {}) {
    const rubroId = payload.rubroId || payload.rubro_id || '';
    const rubroName = payload.rubroNombre || payload.rubro_nombre || '';
    const incomingPlanId = payload.planId || payload.plan_id || '';
    const billingPeriod = payload.billingPeriod || payload.billing_period || 'monthly';

    resetForm();
    const draft = loadRegisterDraft();
    const restoredStep = draft ? applyRegisterDraft(draft) : null;
    const targetRubroId = rubroId ? String(rubroId) : (hiddenRubroId ? hiddenRubroId.value : '');
    const targetPlanId = incomingPlanId || (hiddenPlanId ? hiddenPlanId.value : '') || getPlanIdForRubro(targetRubroId);
    syncRubroSelection(targetRubroId);
    if (businessTypeSelect) {
      businessTypeSelect.value = inferBusinessType({ ...payload, rubroNombre: rubroName || currentRubroName });
    }
    syncBusinessTypeUi();
    if (hiddenPlanNombre && payload.planNombre) hiddenPlanNombre.value = payload.planNombre;
    currentRubroName = rubroName || currentRubroName || '';
    syncPlanInfo(targetPlanId, currentRubroName, billingPeriod);
    showError('');
    clearStatus();
    setStep(restoredStep !== null ? restoredStep : 0);

    if (payload.googleIdToken) {
      googleIdToken = String(payload.googleIdToken);
      if (window.AgendarteGoogleAuth) {
        window.AgendarteGoogleAuth.setToken(googleIdToken);
        if (payload.googleProfile) {
          window.AgendarteGoogleAuth.applyProfileToRegisterForm(payload.googleProfile);
        }
      }
    }

    if (regGoogleWrap) {
      regGoogleWrap.hidden = !(config.googleClientId);
    }

    if (isOpen) return;
    isOpen = true;
    restoreFocusEl = document.activeElement;
    modal.classList.remove('hidden');
    document.documentElement.classList.add('modal-open');
    body.classList.add('modal-open');
    document.addEventListener('keydown', onKeydown);
    document.dispatchEvent(new CustomEvent('agendarte:register-opened'));
    window.requestAnimationFrame(() => {
      const firstInput = stepPanels[0]?.querySelector('input, select, textarea');
      if (firstInput && typeof firstInput.focus === 'function') {
        firstInput.focus();
      }
    });
  }

  function closeModal() {
    if (!isOpen) return;
    saveRegisterDraftNow();
    modal.classList.add('hidden');
    document.documentElement.classList.remove('modal-open');
    body.classList.remove('modal-open');
    document.removeEventListener('keydown', onKeydown);
    isOpen = false;
    const toFocus = restoreFocusEl;
    restoreFocusEl = null;
    if (toFocus && typeof toFocus.focus === 'function') {
      window.requestAnimationFrame(() => toFocus.focus());
    }
  }

  function onTriggerClick(event) {
    const trigger = event.target.closest('.plan-btn');
    if (!trigger) return;
    event.preventDefault();
    const card = trigger.closest('.hc-card') || trigger.closest('.plan-card');
    const rubroId = trigger.dataset.rubroId || (card ? card.dataset.rubroId : '');
    const planId = trigger.dataset.planId || (card ? card.dataset.planId : '');
    const rubroNombre = trigger.dataset.rubroNombre || (card ? card.querySelector('figcaption')?.textContent?.trim() : '');
    const planNombre = trigger.dataset.planNombre || (planConfig[planId]?.nombre || '');
    const billingPeriod = trigger.dataset.billingPeriod || (card ? card.dataset.billingPeriod : '') || 'monthly';
    openModal({ rubroId, planId, rubroNombre, planNombre, billingPeriod });
  }

  function onNext() {
    if (!validateStep(currentStep)) return;
    setStep(currentStep + 1);
  }

  function onPrev() {
    setStep(currentStep - 1);
  }

  function onKeydown(event) {
    if (event.key === 'Escape') {
      event.preventDefault();
      closeModal();
    }
  }

  initializeDayFieldsets();
  resetHoursForm();
  renderServicesList();
  syncBusinessTypeUi();
  syncPhoneField();
  if (regGoogleWrap) {
    regGoogleWrap.hidden = !(config.googleClientId);
  }

  const api = window.AgenduyRegister || {};
  api.open = openModal;
  api.close = closeModal;
  api.reset = () => setStep(0);
  api.bindCategoryButtons = (root) => {
    const container = root || document;
    const modalShell = document.getElementById('modal-rubros');
    const modalContent = document.getElementById('modal-rubros-content');

    const closeCategoriasModal = () => {
      if (modalShell) modalShell.classList.add('hidden');
      if (modalContent) modalContent.replaceChildren();
      document.body.classList.remove('modal-open');
    };

    container.querySelectorAll('.btn-contratar').forEach((btn) => {
      if (btn._agenduyBound) return;
      btn._agenduyBound = true;
      btn.addEventListener('click', () => {
        const payload = {
          rubroId: btn.dataset.rubro || '',
          rubroNombre: btn.dataset.nombre || '',
          planId: btn.dataset.planId || '',
          planNombre: btn.dataset.planNombre || '',
        };

        const catModal = btn.closest('.cat-modal');
        const closeBtn = catModal?.querySelector('.cat-close');
        if (closeBtn) {
          closeBtn.click();
        } else {
          closeCategoriasModal();
        }

        setTimeout(() => openModal(payload), 0);
      });
    });
  };
  window.AgenduyRegister = api;
  window.openRegisterModal = openModal;
  if (typeof api.bindCategoryButtons === "function") {
    api.bindCategoryButtons();
  }

  document.addEventListener('agenduy:open-register', (event) => {
    const detail = event && event.detail ? event.detail : {};
    openModal(detail);
  });

  document.addEventListener('agenduy:close-register', () => {
    closeModal();
  });

  document.addEventListener('agendarte:google-register', (event) => {
    const detail = event && event.detail ? event.detail : {};
    if (detail.token) {
      googleIdToken = String(detail.token);
    }
    if (window.AgendarteGoogleAuth && detail.profile) {
      window.AgendarteGoogleAuth.applyProfileToRegisterForm(detail.profile);
    }
    setStatus('Datos de Google cargados. Completá el registro de tu negocio.', 'success');
  });

  document.addEventListener('click', onTriggerClick);
  form.addEventListener('input', scheduleRegisterDraftSave);
  form.addEventListener('change', scheduleRegisterDraftSave);
  if (serviceAddBtn) serviceAddBtn.addEventListener('click', addServiceFromForm);
  if (serviceResetBtn) serviceResetBtn.addEventListener('click', () => {
    resetServiceForm();
    showServiceError('');
  });
  if (serviceListEl) serviceListEl.addEventListener('click', onServiceListClick);
  if (phoneCountryField) phoneCountryField.addEventListener('change', syncPhoneField);
  if (businessRubroSelect) {
    businessRubroSelect.addEventListener('change', () => {
      const rid = businessRubroSelect.value || '';
      syncRubroSelection(rid);
      syncPlanInfo(getPlanIdForRubro(rid), currentRubroName);
      if (businessTypeSelect) {
        businessTypeSelect.value = inferBusinessType({ rubroNombre: currentRubroName });
      }
      syncBusinessTypeUi();
    });
  }
  if (businessPlanSelect) {
    businessPlanSelect.addEventListener('change', () => {
      syncPlanInfo(businessPlanSelect.value, currentRubroName);
    });
  }
  if (businessTypeSelect) {
    businessTypeSelect.addEventListener('change', syncBusinessTypeUi);
  }
  overlayTriggers.forEach((el) => el.addEventListener('click', closeModal));
  if (nextBtn) nextBtn.addEventListener('click', onNext);
  if (prevBtn) prevBtn.addEventListener('click', onPrev);
  if (submitBtn) submitBtn.addEventListener('click', completeRegistration);
})();
