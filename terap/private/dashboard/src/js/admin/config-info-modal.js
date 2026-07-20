(function adminConfigInfoModal() {
  const modal = document.querySelector('[data-admin-modal="config-info"]');
  if (!modal) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', adminConfigInfoModal, { once: true });
    }
    return;
  }
  const form = modal.querySelector('[data-admin-config-info-form]');
  if (!form && document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', adminConfigInfoModal, { once: true });
    return;
  }
  if (!form) return;
  const modalLoading = window.AdminModalLoading;
  const submitBtn = form.querySelector('[data-admin-config-info-submit]');
  const errorEl = form.querySelector('[data-admin-config-info-error]');
  const closeEls = modal.querySelectorAll('[data-admin-config-info-close]');
  const fieldNodes = Array.from(form.querySelectorAll('[data-admin-config-info-field]'));
  const countrySelect = form.querySelector('[data-admin-config-info-country]');
  const regionSelect = form.querySelector('[data-admin-config-info-region-select]');
  const regionInput = form.querySelector('[data-admin-config-info-region-input]');
  const regionHint = form.querySelector('[data-admin-config-info-region-hint]');
  const websiteContainer = form.querySelector('[data-admin-config-info-website]');
  const websiteValueEl = form.querySelector('[data-admin-config-info-website-value]');
  const websiteCopyBtn = form.querySelector('[data-admin-config-info-website-copy]');
  const websiteEditBtn = form.querySelector('[data-admin-config-info-website-edit]');
  const websiteHiddenInput = form.querySelector('input[data-admin-config-info-field="contacto.website"]');
  const logoPreview = form.querySelector('[data-admin-config-info-logo-preview]');
  const logoImg = form.querySelector('[data-admin-config-info-logo-img]');
  const logoPlaceholder = form.querySelector('[data-admin-config-info-logo-placeholder]');
  const logoInput = form.querySelector('[data-admin-config-info-logo-input]');
  const getActiveFields = () => fieldNodes.filter((field) => !field.disabled);

  const COUNTRIES = [
    { code: 'AF', name: 'Afghanistan' },
    { code: 'AL', name: 'Albania' },
    { code: 'DZ', name: 'Algeria' },
    { code: 'AD', name: 'Andorra' },
    { code: 'AO', name: 'Angola' },
    { code: 'AG', name: 'Antigua and Barbuda' },
    { code: 'AR', name: 'Argentina' },
    { code: 'AM', name: 'Armenia' },
    { code: 'AU', name: 'Australia' },
    { code: 'AT', name: 'Austria' },
    { code: 'AZ', name: 'Azerbaijan' },
    { code: 'BS', name: 'Bahamas' },
    { code: 'BH', name: 'Bahrain' },
    { code: 'BD', name: 'Bangladesh' },
    { code: 'BB', name: 'Barbados' },
    { code: 'BY', name: 'Belarus' },
    { code: 'BE', name: 'Belgium' },
    { code: 'BZ', name: 'Belize' },
    { code: 'BJ', name: 'Benin' },
    { code: 'BT', name: 'Bhutan' },
    { code: 'BO', name: 'Bolivia' },
    { code: 'BA', name: 'Bosnia and Herzegovina' },
    { code: 'BW', name: 'Botswana' },
    { code: 'BR', name: 'Brazil' },
    { code: 'BN', name: 'Brunei' },
    { code: 'BG', name: 'Bulgaria' },
    { code: 'BF', name: 'Burkina Faso' },
    { code: 'BI', name: 'Burundi' },
    { code: 'CV', name: 'Cabo Verde' },
    { code: 'KH', name: 'Cambodia' },
    { code: 'CM', name: 'Cameroon' },
    { code: 'CA', name: 'Canada' },
    { code: 'CF', name: 'Central African Republic' },
    { code: 'TD', name: 'Chad' },
    { code: 'CL', name: 'Chile' },
    { code: 'CN', name: 'China' },
    { code: 'CO', name: 'Colombia' },
    { code: 'KM', name: 'Comoros' },
    { code: 'CG', name: 'Congo' },
    { code: 'CD', name: 'Congo (DRC)' },
    { code: 'CR', name: 'Costa Rica' },
    { code: 'CI', name: "Cote d'Ivoire" },
    { code: 'HR', name: 'Croatia' },
    { code: 'CU', name: 'Cuba' },
    { code: 'CY', name: 'Cyprus' },
    { code: 'CZ', name: 'Czechia' },
    { code: 'DK', name: 'Denmark' },
    { code: 'DJ', name: 'Djibouti' },
    { code: 'DM', name: 'Dominica' },
    { code: 'DO', name: 'Dominican Republic' },
    { code: 'EC', name: 'Ecuador' },
    { code: 'EG', name: 'Egypt' },
    { code: 'SV', name: 'El Salvador' },
    { code: 'GQ', name: 'Equatorial Guinea' },
    { code: 'ER', name: 'Eritrea' },
    { code: 'EE', name: 'Estonia' },
    { code: 'SZ', name: 'Eswatini' },
    { code: 'ET', name: 'Ethiopia' },
    { code: 'FJ', name: 'Fiji' },
    { code: 'FI', name: 'Finland' },
    { code: 'FR', name: 'France' },
    { code: 'GA', name: 'Gabon' },
    { code: 'GM', name: 'Gambia' },
    { code: 'GE', name: 'Georgia' },
    { code: 'DE', name: 'Germany' },
    { code: 'GH', name: 'Ghana' },
    { code: 'GR', name: 'Greece' },
    { code: 'GD', name: 'Grenada' },
    { code: 'GT', name: 'Guatemala' },
    { code: 'GN', name: 'Guinea' },
    { code: 'GW', name: 'Guinea-Bissau' },
    { code: 'GY', name: 'Guyana' },
    { code: 'HT', name: 'Haiti' },
    { code: 'HN', name: 'Honduras' },
    { code: 'HU', name: 'Hungary' },
    { code: 'IS', name: 'Iceland' },
    { code: 'IN', name: 'India' },
    { code: 'ID', name: 'Indonesia' },
    { code: 'IR', name: 'Iran' },
    { code: 'IQ', name: 'Iraq' },
    { code: 'IE', name: 'Ireland' },
    { code: 'IL', name: 'Israel' },
    { code: 'IT', name: 'Italy' },
    { code: 'JM', name: 'Jamaica' },
    { code: 'JP', name: 'Japan' },
    { code: 'JO', name: 'Jordan' },
    { code: 'KZ', name: 'Kazakhstan' },
    { code: 'KE', name: 'Kenya' },
    { code: 'KI', name: 'Kiribati' },
    { code: 'KW', name: 'Kuwait' },
    { code: 'KG', name: 'Kyrgyzstan' },
    { code: 'LA', name: 'Laos' },
    { code: 'LV', name: 'Latvia' },
    { code: 'LB', name: 'Lebanon' },
    { code: 'LS', name: 'Lesotho' },
    { code: 'LR', name: 'Liberia' },
    { code: 'LY', name: 'Libya' },
    { code: 'LI', name: 'Liechtenstein' },
    { code: 'LT', name: 'Lithuania' },
    { code: 'LU', name: 'Luxembourg' },
    { code: 'MG', name: 'Madagascar' },
    { code: 'MW', name: 'Malawi' },
    { code: 'MY', name: 'Malaysia' },
    { code: 'MV', name: 'Maldives' },
    { code: 'ML', name: 'Mali' },
    { code: 'MT', name: 'Malta' },
    { code: 'MH', name: 'Marshall Islands' },
    { code: 'MR', name: 'Mauritania' },
    { code: 'MU', name: 'Mauritius' },
    { code: 'MX', name: 'Mexico' },
    { code: 'FM', name: 'Micronesia' },
    { code: 'MD', name: 'Moldova' },
    { code: 'MC', name: 'Monaco' },
    { code: 'MN', name: 'Mongolia' },
    { code: 'ME', name: 'Montenegro' },
    { code: 'MA', name: 'Morocco' },
    { code: 'MZ', name: 'Mozambique' },
    { code: 'MM', name: 'Myanmar' },
    { code: 'NA', name: 'Namibia' },
    { code: 'NR', name: 'Nauru' },
    { code: 'NP', name: 'Nepal' },
    { code: 'NL', name: 'Netherlands' },
    { code: 'NZ', name: 'New Zealand' },
    { code: 'NI', name: 'Nicaragua' },
    { code: 'NE', name: 'Niger' },
    { code: 'NG', name: 'Nigeria' },
    { code: 'KP', name: 'North Korea' },
    { code: 'MK', name: 'North Macedonia' },
    { code: 'NO', name: 'Norway' },
    { code: 'OM', name: 'Oman' },
    { code: 'PK', name: 'Pakistan' },
    { code: 'PW', name: 'Palau' },
    { code: 'PA', name: 'Panama' },
    { code: 'PG', name: 'Papua New Guinea' },
    { code: 'PY', name: 'Paraguay' },
    { code: 'PE', name: 'Peru' },
    { code: 'PH', name: 'Philippines' },
    { code: 'PL', name: 'Poland' },
    { code: 'PT', name: 'Portugal' },
    { code: 'QA', name: 'Qatar' },
    { code: 'RO', name: 'Romania' },
    { code: 'RU', name: 'Russia' },
    { code: 'RW', name: 'Rwanda' },
    { code: 'WS', name: 'Samoa' },
    { code: 'SM', name: 'San Marino' },
    { code: 'ST', name: 'Sao Tome and Principe' },
    { code: 'SA', name: 'Saudi Arabia' },
    { code: 'SN', name: 'Senegal' },
    { code: 'RS', name: 'Serbia' },
    { code: 'SC', name: 'Seychelles' },
    { code: 'SL', name: 'Sierra Leone' },
    { code: 'SG', name: 'Singapore' },
    { code: 'SK', name: 'Slovakia' },
    { code: 'SI', name: 'Slovenia' },
    { code: 'SB', name: 'Solomon Islands' },
    { code: 'SO', name: 'Somalia' },
    { code: 'ZA', name: 'South Africa' },
    { code: 'KR', name: 'South Korea' },
    { code: 'SS', name: 'South Sudan' },
    { code: 'ES', name: 'Spain' },
    { code: 'LK', name: 'Sri Lanka' },
    { code: 'KN', name: 'St. Kitts and Nevis' },
    { code: 'LC', name: 'St. Lucia' },
    { code: 'VC', name: 'St. Vincent and the Grenadines' },
    { code: 'SD', name: 'Sudan' },
    { code: 'SR', name: 'Suriname' },
    { code: 'SE', name: 'Sweden' },
    { code: 'CH', name: 'Switzerland' },
    { code: 'SY', name: 'Syria' },
    { code: 'TW', name: 'Taiwan' },
    { code: 'TJ', name: 'Tajikistan' },
    { code: 'TZ', name: 'Tanzania' },
    { code: 'TH', name: 'Thailand' },
    { code: 'TL', name: 'Timor-Leste' },
    { code: 'TG', name: 'Togo' },
    { code: 'TO', name: 'Tonga' },
    { code: 'TT', name: 'Trinidad and Tobago' },
    { code: 'TN', name: 'Tunisia' },
    { code: 'TR', name: 'Turkey' },
    { code: 'TM', name: 'Turkmenistan' },
    { code: 'TV', name: 'Tuvalu' },
    { code: 'UG', name: 'Uganda' },
    { code: 'UA', name: 'Ukraine' },
    { code: 'AE', name: 'United Arab Emirates' },
    { code: 'GB', name: 'United Kingdom' },
    { code: 'US', name: 'United States' },
    { code: 'UY', name: 'Uruguay' },
    { code: 'UZ', name: 'Uzbekistan' },
    { code: 'VU', name: 'Vanuatu' },
    { code: 'VE', name: 'Venezuela' },
    { code: 'VN', name: 'Vietnam' },
    { code: 'YE', name: 'Yemen' },
    { code: 'ZM', name: 'Zambia' },
    { code: 'ZW', name: 'Zimbabwe' },
  ];

  const COUNTRY_REGIONS = {
    US: ['Alabama', 'Alaska', 'Arizona', 'Arkansas', 'California', 'Colorado', 'Connecticut', 'Delaware', 'Florida', 'Georgia', 'Hawaii', 'Idaho', 'Illinois', 'Indiana', 'Iowa', 'Kansas', 'Kentucky', 'Louisiana', 'Maine', 'Maryland', 'Massachusetts', 'Michigan', 'Minnesota', 'Mississippi', 'Missouri', 'Montana', 'Nebraska', 'Nevada', 'New Hampshire', 'New Jersey', 'New Mexico', 'New York', 'North Carolina', 'North Dakota', 'Ohio', 'Oklahoma', 'Oregon', 'Pennsylvania', 'Rhode Island', 'South Carolina', 'South Dakota', 'Tennessee', 'Texas', 'Utah', 'Vermont', 'Virginia', 'Washington', 'West Virginia', 'Wisconsin', 'Wyoming'],
    CA: ['Alberta', 'British Columbia', 'Manitoba', 'New Brunswick', 'Newfoundland and Labrador', 'Nova Scotia', 'Ontario', 'Prince Edward Island', 'Quebec', 'Saskatchewan'],
    AU: ['Australian Capital Territory', 'New South Wales', 'Northern Territory', 'Queensland', 'South Australia', 'Tasmania', 'Victoria', 'Western Australia'],
    MX: ['Aguascalientes', 'Baja California', 'Baja California Sur', 'Campeche', 'Chiapas', 'Chihuahua', 'Ciudad de Mexico', 'Coahuila', 'Colima', 'Durango', 'Estado de Mexico', 'Guanajuato', 'Guerrero', 'Hidalgo', 'Jalisco', 'Michoacan', 'Morelos', 'Nayarit', 'Nuevo Leon', 'Oaxaca', 'Puebla', 'Queretaro', 'Quintana Roo', 'San Luis Potosi', 'Sinaloa', 'Sonora', 'Tabasco', 'Tamaulipas', 'Tlaxcala', 'Veracruz', 'Yucatan', 'Zacatecas'],
    BR: ['Acre', 'Alagoas', 'Amapa', 'Amazonas', 'Bahia', 'Ceara', 'Distrito Federal', 'Espirito Santo', 'Goias', 'Maranhao', 'Mato Grosso', 'Mato Grosso do Sul', 'Minas Gerais', 'Para', 'Paraiba', 'Parana', 'Pernambuco', 'Piaui', 'Rio de Janeiro', 'Rio Grande do Norte', 'Rio Grande do Sul', 'Rondonia', 'Roraima', 'Santa Catarina', 'Sao Paulo', 'Sergipe', 'Tocantins'],
    AR: ['Buenos Aires', 'Catamarca', 'Chaco', 'Chubut', 'Cordoba', 'Corrientes', 'Entre Rios', 'Formosa', 'Jujuy', 'La Pampa', 'La Rioja', 'Mendoza', 'Misiones', 'Neuquen', 'Rio Negro', 'Salta', 'San Juan', 'San Luis', 'Santa Cruz', 'Santa Fe', 'Santiago del Estero', 'Tierra del Fuego', 'Tucuman'],
    ES: ['Andalucia', 'Aragon', 'Asturias', 'Baleares', 'Canarias', 'Cantabria', 'Castilla y Leon', 'Castilla-La Mancha', 'Cataluna', 'Comunidad de Madrid', 'Comunidad Valenciana', 'Extremadura', 'Galicia', 'La Rioja', 'Murcia', 'Navarra', 'Pais Vasco', 'Ceuta', 'Melilla'],
    IT: ['Abruzzo', 'Basilicata', 'Calabria', 'Campania', 'Emilia-Romagna', 'Friuli Venezia Giulia', 'Lazio', 'Liguria', 'Lombardia', 'Marche', 'Molise', 'Piemonte', 'Puglia', 'Sardegna', 'Sicilia', 'Toscana', 'Trentino-Alto Adige', 'Umbria', 'Valle d\'Aosta', 'Veneto'],
    DE: ['Baden-Wurttemberg', 'Bavaria', 'Berlin', 'Brandenburg', 'Bremen', 'Hamburg', 'Hesse', 'Lower Saxony', 'Mecklenburg-Vorpommern', 'North Rhine-Westphalia', 'Rhineland-Palatinate', 'Saarland', 'Saxony', 'Saxony-Anhalt', 'Schleswig-Holstein', 'Thuringia'],
    GB: ['England', 'Scotland', 'Wales', 'Northern Ireland'],
    FR: ['Auvergne-Rhone-Alpes', 'Bourgogne-Franche-Comte', 'Bretagne', 'Centre-Val de Loire', 'Corse', 'Grand Est', 'Hauts-de-France', 'Ile-de-France', 'Normandie', 'Nouvelle-Aquitaine', 'Occitanie', 'Pays de la Loire', 'Provence-Alpes-Cote d\'Azur', 'Guadeloupe', 'Martinique', 'Guyane', 'La Reunion', 'Mayotte'],
  };

  const clone = (data) => JSON.parse(JSON.stringify(data || {}));
  let currentData = clone(window.ADMIN_INFO_BARBERIA || {});
  let currentLogoSrc = typeof (currentData && currentData.logo_src) === 'string' && currentData.logo_src
    ? currentData.logo_src
    : 'src/img/logo.jpg';
  const MAX_LOGO_SIZE = 2 * 1024 * 1024;

  const resolveLogoUrl = (value) => {
    if (!value) return '';
    const trimmed = String(value).trim();
    if (!trimmed) return '';
    if (/^https?:\/\//i.test(trimmed)) return trimmed;
    return `../../../${trimmed.replace(/^\/+/, '')}`;
  };

  const updateLogoPreview = (value) => {
    if (!logoImg || !logoPlaceholder) return;
    const url = value && value.startsWith('data:') ? value : resolveLogoUrl(value);
    if (url) {
      logoImg.src = url;
      logoImg.hidden = false;
      logoPlaceholder.hidden = true;
    } else {
      logoImg.hidden = true;
      logoPlaceholder.hidden = false;
      logoImg.removeAttribute('src');
    }
  };

  const resetLogoInput = () => {
    if (!logoInput) return;
    try {
      logoInput.value = '';
    } catch (_) {
      /* ignore */
    }
  };

  const populateCountryOptions = () => {
    if (!countrySelect || !COUNTRIES.length) return;
    if (countrySelect.dataset.filled === 'true') return;
    const fragment = document.createDocumentFragment();
    COUNTRY_REGIONS;
    COUNTRIES.forEach((country) => {
      const option = document.createElement('option');
      option.value = country.code;
      option.textContent = country.name;
      fragment.appendChild(option);
    });
    countrySelect.appendChild(fragment);
    countrySelect.dataset.filled = 'true';
  };

  const setCountryValue = (value) => {
    if (!countrySelect) return '';
    const stringValue = String(value || '').trim();
    if (!stringValue) {
      countrySelect.value = '';
      return '';
    }
    const matchCode = COUNTRIES.find((country) => country.code === stringValue);
    if (matchCode) {
      countrySelect.value = matchCode.code;
      return matchCode.code;
    }
    const optionExists = Array.from(countrySelect.options).some((opt) => opt.value === stringValue);
    if (optionExists) {
      countrySelect.value = stringValue;
      return stringValue;
    }
    const match = COUNTRIES.find((country) => country.name.toLowerCase() === stringValue.toLowerCase());
    if (match) {
      countrySelect.value = match.code;
      return match.code;
    }
    const customOption = document.createElement('option');
    customOption.value = stringValue;
    customOption.textContent = stringValue;
    countrySelect.appendChild(customOption);
    countrySelect.value = stringValue;
    return stringValue;
  };

  const trimValue = (field) => {
    if (!field) return '';
    if (field.type === 'checkbox') return field.checked;
    return String(field.value || '').trim();
  };

  const setByPath = (target, path, value) => {
    const parts = path.split('.');
    let current = target;
    parts.forEach((part, idx) => {
      if (idx === parts.length - 1) {
        current[part] = value;
      } else {
        if (!current[part] || typeof current[part] !== 'object') {
          current[part] = {};
        }
        current = current[part];
      }
    });
  };

  const getValue = (source, path) => {
    if (!source || typeof source !== 'object') return '';
    return path.split('.').reduce((acc, part) => {
      if (acc && Object.prototype.hasOwnProperty.call(acc, part)) {
        return acc[part];
      }
      return '';
    }, source);
  };

  const updateRegionField = (countryCode, regionValue) => {
    if (!regionSelect || !regionInput || !regionHint) return;
    const regions = COUNTRY_REGIONS[countryCode];
    if (!regions || !regions.length) {
      regionSelect.disabled = true;
      regionSelect.required = false;
      regionSelect.value = '';
      regionInput.disabled = false;
      regionInput.required = false;
      regionInput.value = regionValue || '';
      regionHint.textContent = 'Puedes escribir la region o estado manualmente.';
      return;
    }
    regionSelect.disabled = false;
    regionSelect.required = true;
    regionSelect.innerHTML = '<option value="">Selecciona una region</option>';
    regions.forEach((region) => {
      const option = document.createElement('option');
      option.value = region;
      option.textContent = region;
      regionSelect.appendChild(option);
    });
    regionSelect.value = regionValue && regions.includes(regionValue) ? regionValue : '';
    regionInput.disabled = true;
    regionInput.required = false;
    regionInput.value = '';
    regionHint.textContent = 'Selecciona una region de la lista.';
  };

  if (countrySelect) {
    countrySelect.addEventListener('change', () => {
      const code = countrySelect.value;
      updateRegionField(code, '');
    });
  }

  const syncWebsiteDisplay = (value) => {
    if (!websiteContainer || !websiteValueEl) return;
    const displayValue = value || 'Sin sitio web configurado.';
    websiteValueEl.textContent = displayValue;
    websiteContainer.dataset.state = value ? 'filled' : 'empty';
  };

  if (websiteEditBtn && websiteHiddenInput) {
    websiteEditBtn.addEventListener('click', (evt) => {
      evt.preventDefault();
      const promptValue = window.prompt('Ingresa la URL del sitio web', websiteHiddenInput.value || '');
      if (promptValue === null) return;
      const trimmed = promptValue.trim();
      websiteHiddenInput.value = trimmed;
      syncWebsiteDisplay(trimmed);
    });
  }

  if (websiteCopyBtn && websiteHiddenInput) {
    websiteCopyBtn.addEventListener('click', async (evt) => {
      evt.preventDefault();
      const value = websiteHiddenInput.value.trim();
      if (!value) {
        adminNotify('No hay un sitio web configurado para copiar.', 'info');
        return;
      }
      try {
        await navigator.clipboard.writeText(value);
        adminNotify('Sitio web copiado al portapapeles.', 'success');
      } catch (_) {
        adminNotify('No se pudo copiar el sitio web.', 'error');
      }
    });
  }

  const fillForm = (data) => {
    populateCountryOptions();
    const regionValue = getValue(data, 'direccion.region') || '';
    const countryValue = setCountryValue(getValue(data, 'direccion.pais') || '');
    updateRegionField(countryValue, regionValue);
    if (regionSelect && !regionSelect.disabled) {
      regionSelect.value = regionValue && COUNTRY_REGIONS[countryValue] && COUNTRY_REGIONS[countryValue].includes(regionValue)
        ? regionValue
        : '';
    }
    if (regionInput && !regionInput.disabled) {
      regionInput.value = regionValue;
    }
    getActiveFields().forEach((field) => {
      if ((countrySelect && field === countrySelect) || (regionSelect && field === regionSelect) || (regionInput && field === regionInput)) {
        return;
      }
      const path = field.getAttribute('data-admin-config-info-field');
      if (!path) return;
      const value = getValue(data, path);
      field.value = value === null || value === undefined ? '' : String(value);
    });
    if (websiteHiddenInput) {
      const websiteValue = getValue(data, 'contacto.website') || '';
      websiteHiddenInput.value = websiteValue;
      syncWebsiteDisplay(websiteValue);
    }
    const logoValue = (data && typeof data.logo_src === 'string') ? data.logo_src : '';
    currentLogoSrc = logoValue || currentLogoSrc || 'src/img/logo.jpg';
    updateLogoPreview(currentLogoSrc);
    resetLogoInput();
  };

  const collectData = () => {
    const payload = {};
    getActiveFields().forEach((field) => {
      const path = field.getAttribute('data-admin-config-info-field');
      if (!path) return;
      const value = trimValue(field);
      setByPath(payload, path, value);
    });
    payload.logo_src = currentLogoSrc || 'src/img/logo.jpg';
    return payload;
  };

  const open = () => {
    if (modalLoading) modalLoading.show(modal);
    fillForm(currentData);
    if (errorEl) {
      errorEl.hidden = true;
      errorEl.textContent = '';
    }
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
    resetLogoInput();
    updateLogoPreview(currentLogoSrc);
  };

  const showError = (msg) => {
    if (errorEl) {
      errorEl.textContent = msg;
      errorEl.hidden = false;
    }
    adminNotify(msg, 'error');
  };

  const clearError = () => {
    if (!errorEl) return;
    errorEl.hidden = true;
    errorEl.textContent = '';
  };

  if (logoInput) {
    logoInput.addEventListener('change', () => {
      const file = logoInput.files && logoInput.files[0] ? logoInput.files[0] : null;
      if (!file) {
        updateLogoPreview(currentLogoSrc);
        return;
      }
      if (!file.type || !file.type.startsWith('image/')) {
        showError('Selecciona un archivo de imagen valido.');
        resetLogoInput();
        updateLogoPreview(currentLogoSrc);
        return;
      }
      if (file.size > MAX_LOGO_SIZE) {
        showError('El logo debe ser menor a 2 MB.');
        resetLogoInput();
        updateLogoPreview(currentLogoSrc);
        return;
      }
      clearError();
      const reader = new FileReader();
      reader.onload = (event) => {
        const result = event && event.target ? event.target.result : null;
        if (typeof result === 'string') {
          updateLogoPreview(result);
        }
      };
      reader.readAsDataURL(file);
    });
  }

  closeEls.forEach((btn) => btn.addEventListener('click', close));
  document.addEventListener('keydown', (evt) => {
    if (evt.key === 'Escape' && !modal.hidden) {
      close();
    }
  });

  form.addEventListener('submit', async (evt) => {
    evt.preventDefault();
    if (errorEl) { errorEl.hidden = true; errorEl.textContent = ''; }
    if (!form.reportValidity()) {
      return;
    }
    if (submitBtn) submitBtn.disabled = true;
    const payload = collectData();
    try {
      const formData = new FormData();
      formData.append('action', 'config_update');
      formData.append('key', 'info_barberia');
      formData.append('data', JSON.stringify(payload));
      if (logoInput && logoInput.files && logoInput.files[0]) {
        formData.append('logo', logoInput.files[0]);
      }
      const res = await fetch('../../../src/API/AdminConfig.php', {
        method: 'POST',
        body: formData
      });
      const json = await res.json().catch(() => null);
      if (!res.ok || !json || !json.ok) {
        throw new Error(json && json.error ? json.error : 'No se pudo guardar la informacion.');
      }
      currentData = clone(json.data);
      window.ADMIN_INFO_BARBERIA = clone(json.data);
      currentLogoSrc = typeof currentData.logo_src === 'string' && currentData.logo_src
        ? currentData.logo_src
        : currentLogoSrc;
      updateLogoPreview(currentLogoSrc);
      resetLogoInput();
      adminNotify('La informacion del negocio se actualizo correctamente.', 'success');
      close();
    } catch (err) {
      const message = err && err.message ? err.message : 'No se pudo guardar la informacion del negocio.';
      showError(message);
      if (submitBtn) submitBtn.disabled = false;
    }
  });

  window.AdminConfigInfoModal = { open, close };
})();

