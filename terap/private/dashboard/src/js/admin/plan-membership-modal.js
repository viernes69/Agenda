(function planMembershipModal() {
  const modal = document.querySelector('[data-plan-membership-modal]');
  if (!modal) return;

  const openTriggers = Array.from(document.querySelectorAll('[data-plan-membership-open]'));
  const closeEls = Array.from(modal.querySelectorAll('[data-plan-membership-close]'));
  const feedback = modal.querySelector('[data-plan-membership-feedback]');
  const plansPanel = modal.querySelector('[data-plan-membership-plans]');
  const payPanel = modal.querySelector('[data-plan-membership-pay]');
  const payBack = modal.querySelector('[data-plan-membership-pay-back]');
  const transferPanel = modal.querySelector('[data-plan-pay-transfer]');
  const transferForm = modal.querySelector('[data-plan-transfer-form]');
  const selectUrl = (modal.getAttribute('data-plan-select-url') || '').trim();
  const paypalUrl = (modal.getAttribute('data-plan-paypal-url') || '').trim();
  const transferUrl = (modal.getAttribute('data-plan-transfer-url') || '').trim();
  const mpUrl = (modal.getAttribute('data-plan-mp-url') || '').trim();
  let lastFocus = null;
  let pendingPay = null;

  const money = (n) => '$' + Math.round(Number(n) || 0).toLocaleString('es-UY');

  const setFeedback = (message, kind) => {
    if (!feedback) return;
    if (!message) {
      feedback.hidden = true;
      feedback.textContent = '';
      feedback.classList.remove('is-ok', 'is-error');
      return;
    }
    feedback.hidden = false;
    feedback.textContent = message;
    feedback.classList.toggle('is-ok', kind === 'ok');
    feedback.classList.toggle('is-error', kind === 'error');
  };

  const showPlans = () => {
    if (plansPanel) plansPanel.hidden = false;
    if (payPanel) payPanel.hidden = true;
    if (transferPanel) transferPanel.hidden = true;
    modal.classList.remove('is-pay-step');
    pendingPay = null;
  };

  const resolveAmount = (form) => {
    const card = form.closest('[data-plan-membership-card]');
    const periodInput = form.querySelector('[data-plan-membership-billing-input]');
    const period = (periodInput && periodInput.value === 'yearly') ? 'yearly' : 'monthly';
    const monthly = parseFloat((card && card.getAttribute('data-monthly')) || form.getAttribute('data-plan-price') || '0');
    const yearlyRaw = card ? card.getAttribute('data-yearly') : '';
    const hasAnnual = card && card.getAttribute('data-has-annual') === '1';
    const useYearly = period === 'yearly' && hasAnnual && yearlyRaw !== '';
    const amount = useYearly ? parseFloat(yearlyRaw) : monthly;
    return {
      amount: amount > 0 ? amount : 0,
      period: useYearly ? 'yearly' : 'monthly',
      currency: (form.getAttribute('data-plan-currency') || 'UYU').toUpperCase(),
      name: form.getAttribute('data-plan-name') || 'plan',
      id: parseInt(form.getAttribute('data-plan-id') || '0', 10) || 0,
      csrf: (form.querySelector('input[name="_csrf"]') || {}).value || '',
    };
  };

  const showPayStep = (info) => {
    pendingPay = info;
    if (plansPanel) plansPanel.hidden = true;
    if (payPanel) payPanel.hidden = false;
    if (transferPanel) transferPanel.hidden = true;
    modal.classList.add('is-pay-step');
    const nameEl = modal.querySelector('[data-plan-pay-name]');
    const amountEl = modal.querySelector('[data-plan-pay-amount]');
    const periodEl = modal.querySelector('[data-plan-pay-period-label]');
    if (nameEl) nameEl.textContent = info.name;
    if (amountEl) amountEl.textContent = money(info.amount);
    if (periodEl) periodEl.textContent = info.period === 'yearly' ? '/ año' : '/ mes';
    const methods = modal.querySelector('[data-plan-pay-methods]');
    const hasMethodBtn = methods && methods.querySelector('[data-plan-pay-method]');
    if (hasMethodBtn) {
      setFeedback('Elegí un método de pago para continuar.', 'ok');
    } else {
      setFeedback('No hay métodos de pago habilitados. Contactá a soporte.', 'error');
    }
    if (payPanel && typeof payPanel.scrollIntoView === 'function') {
      payPanel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
  };

  const initCarousel = () => {
    const root = modal.querySelector('[data-plan-membership-carousel]');
    if (!root) return;
    const track = root.querySelector('[data-plan-carousel-track]');
    const prevBtn = root.querySelector('[data-plan-carousel-prev]');
    const nextBtn = root.querySelector('[data-plan-carousel-next]');
    const dotsEl = root.querySelector('[data-plan-carousel-dots]');
    const cards = track ? Array.from(track.querySelectorAll('[data-plan-membership-card]')) : [];
    if (!track || cards.length <= 3) return;

    let page = 0;

    const pageSize = () => (window.matchMedia('(min-width: 900px)').matches ? 3 : 1);
    const pageCount = () => Math.max(1, Math.ceil(cards.length / pageSize()));

    const renderDots = () => {
      if (!dotsEl) return;
      const total = pageCount();
      dotsEl.innerHTML = '';
      for (let i = 0; i < total; i += 1) {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'plan-membership-carousel__dot' + (i === page ? ' is-active' : '');
        btn.setAttribute('aria-label', 'Ir a página ' + (i + 1));
        btn.setAttribute('aria-selected', i === page ? 'true' : 'false');
        btn.addEventListener('click', () => {
          page = i;
          apply();
        });
        dotsEl.appendChild(btn);
      }
    };

    const apply = () => {
      const size = pageSize();
      const total = pageCount();
      if (page >= total) page = total - 1;
      if (page < 0) page = 0;
      const start = page * size;
      const end = start + size;
      cards.forEach((card, idx) => {
        const visible = idx >= start && idx < end;
        card.hidden = !visible;
        card.setAttribute('aria-hidden', visible ? 'false' : 'true');
        card.classList.toggle('is-carousel-visible', visible);
      });
      if (prevBtn) prevBtn.disabled = page <= 0;
      if (nextBtn) nextBtn.disabled = page >= total - 1;
      renderDots();
    };

    if (prevBtn) {
      prevBtn.addEventListener('click', (event) => {
        event.preventDefault();
        page -= 1;
        apply();
      });
    }
    if (nextBtn) {
      nextBtn.addEventListener('click', (event) => {
        event.preventDefault();
        page += 1;
        apply();
      });
    }

    let resizeTimer = null;
    window.addEventListener('resize', () => {
      window.clearTimeout(resizeTimer);
      resizeTimer = window.setTimeout(apply, 120);
    });

    apply();
  };

  initCarousel();

  const openModal = () => {
    lastFocus = document.activeElement;
    setFeedback('');
    showPlans();
    modal.hidden = false;
    requestAnimationFrame(() => {
      modal.classList.add('is-visible');
      const focusable = modal.querySelector('[data-plan-membership-close], button, [href], input, select, textarea');
      if (focusable && typeof focusable.focus === 'function') {
        focusable.focus();
      }
    });
  };

  const closeModal = () => {
    modal.classList.remove('is-visible');
    window.setTimeout(() => {
      modal.hidden = true;
      showPlans();
      if (lastFocus && typeof lastFocus.focus === 'function') {
        lastFocus.focus();
      }
    }, 180);
  };

  openTriggers.forEach((el) => {
    el.addEventListener('click', (event) => {
      event.preventDefault();
      openModal();
    });
  });

  closeEls.forEach((el) => {
    el.addEventListener('click', (event) => {
      event.preventDefault();
      closeModal();
    });
  });

  if (payBack) {
    payBack.addEventListener('click', (event) => {
      event.preventDefault();
      setFeedback('');
      showPlans();
    });
  }

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && !modal.hidden && modal.classList.contains('is-visible')) {
      if (modal.classList.contains('modal--locked')) return;
      closeModal();
    }
  });

  const activateFreePlan = async (form, submitBtn) => {
    const endpoint = selectUrl || form.getAttribute('action') || '';
    if (!endpoint) {
      setFeedback('No se pudo enviar la solicitud.', 'error');
      return;
    }
    if (submitBtn) submitBtn.disabled = true;
    setFeedback('Activando plan gratis…', 'ok');
    try {
      const body = new FormData(form);
      const response = await fetch(endpoint, {
        method: 'POST',
        body,
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
      });
      let payload = null;
      try {
        payload = await response.json();
      } catch (_) {
        payload = null;
      }
      if (!response.ok || !payload || payload.ok !== true) {
        const errMsg = (payload && payload.error) ? String(payload.error) : 'No se pudo cambiar el plan.';
        setFeedback(errMsg, 'error');
        if (submitBtn) submitBtn.disabled = false;
        return;
      }
      setFeedback('Plan actualizado. Recargando…', 'ok');
      window.setTimeout(() => {
        window.location.reload();
      }, 500);
    } catch (_) {
      setFeedback('Error de red. Intentá de nuevo.', 'error');
      if (submitBtn) submitBtn.disabled = false;
    }
  };

  modal.addEventListener('submit', async (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement)) return;

    if (form.matches('[data-plan-transfer-form]')) {
      event.preventDefault();
      if (!pendingPay || !transferUrl) {
        setFeedback('No se pudo enviar el comprobante.', 'error');
        return;
      }
      const submitBtn = form.querySelector('button[type="submit"]');
      const fechaInput = form.querySelector('[name="fecha_transferencia"]');
      const fechaRaw = fechaInput ? String(fechaInput.value || '').trim() : '';
      let fechaIso = '';
      if (fechaRaw !== '') {
        const isoMatch = /^(\d{4})-(\d{2})-(\d{2})$/.exec(fechaRaw);
        const dmyMatch = /^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{4})$/.exec(fechaRaw);
        if (isoMatch) {
          fechaIso = `${isoMatch[1]}-${isoMatch[2]}-${isoMatch[3]}`;
        } else if (dmyMatch) {
          const d = String(dmyMatch[1]).padStart(2, '0');
          const m = String(dmyMatch[2]).padStart(2, '0');
          const y = dmyMatch[3];
          fechaIso = `${y}-${m}-${d}`;
        } else {
          setFeedback('Fecha inválida. Usá el formato dd/mm/aaaa (ej. 18/07/2026).', 'error');
          return;
        }
      }
      if (submitBtn) submitBtn.disabled = true;
      setFeedback('Enviando comprobante…', 'ok');
      try {
        const body = new FormData(form);
        body.set('id_membership', String(pendingPay.id));
        body.set('billing_period', pendingPay.period);
        body.set('monto', String(pendingPay.amount));
        body.set('moneda', pendingPay.currency || body.get('moneda') || 'UYU');
        if (fechaIso !== '') {
          body.set('fecha_transferencia', fechaIso);
        }
        const response = await fetch(transferUrl, {
          method: 'POST',
          body,
          credentials: 'same-origin',
          headers: { Accept: 'application/json' },
        });
        let payload = null;
        try {
          payload = await response.json();
        } catch (_) {
          payload = null;
        }
        if (!response.ok || !payload || payload.ok !== true) {
          const errMsg = (payload && payload.error) ? String(payload.error) : 'No se pudo subir el comprobante.';
          setFeedback(errMsg, 'error');
          if (submitBtn) submitBtn.disabled = false;
          return;
        }
        setFeedback(payload.message || 'Comprobante recibido. Pendiente de aprobación.', 'ok');
        window.setTimeout(() => {
          window.location.reload();
        }, 900);
      } catch (_) {
        setFeedback('Error de red. Intentá de nuevo.', 'error');
        if (submitBtn) submitBtn.disabled = false;
      }
      return;
    }

    if (!form.matches('[data-plan-membership-form]')) return;
    event.preventDefault();

    const submitBtn = form.querySelector('button[type="submit"]');
    const info = resolveAmount(form);
    if (info.amount > 0) {
      // Paid plan: never silent-activate — show payment step.
      showPayStep(info);
      return;
    }
    await activateFreePlan(form, submitBtn);
  });

  modal.addEventListener('click', async (event) => {
    const methodBtn = event.target.closest('[data-plan-pay-method]');
    if (!methodBtn || !pendingPay) return;
    event.preventDefault();
    const method = methodBtn.getAttribute('data-plan-pay-method') || '';

    if (method === 'transfer') {
      if (transferPanel) transferPanel.hidden = false;
      const amountLabel = modal.querySelector('[data-plan-transfer-amount]');
      const membershipInput = modal.querySelector('[data-plan-transfer-membership]');
      const billingInput = modal.querySelector('[data-plan-transfer-billing]');
      const montoInput = modal.querySelector('[data-plan-transfer-monto]');
      if (amountLabel) amountLabel.textContent = money(pendingPay.amount);
      if (membershipInput) membershipInput.value = String(pendingPay.id);
      if (billingInput) billingInput.value = pendingPay.period;
      if (montoInput) montoInput.value = String(pendingPay.amount);
      setFeedback('Transferí el monto indicado y subí el comprobante.', 'ok');
      transferPanel && transferPanel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      return;
    }

    if (method === 'paypal') {
      if (!paypalUrl) {
        setFeedback('PayPal no está configurado.', 'error');
        return;
      }
      methodBtn.disabled = true;
      setFeedback('Abriendo PayPal…', 'ok');
      try {
        const response = await fetch(paypalUrl + '?action=create_order', {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
          },
          body: JSON.stringify({
            action: 'create_order',
            id_membership: pendingPay.id,
            billing_period: pendingPay.period,
            _csrf: pendingPay.csrf,
          }),
        });
        let payload = null;
        try {
          payload = await response.json();
        } catch (_) {
          payload = null;
        }
        if (!response.ok || !payload || payload.ok !== true || !payload.approve_link) {
          const errMsg = (payload && payload.error) ? String(payload.error) : 'No se pudo iniciar PayPal.';
          setFeedback(errMsg, 'error');
          methodBtn.disabled = false;
          return;
        }
        window.location.href = String(payload.approve_link);
      } catch (_) {
        setFeedback('Error de red al contactar PayPal.', 'error');
        methodBtn.disabled = false;
      }
      return;
    }

    if (method === 'mercadopago') {
      if (!mpUrl) {
        setFeedback('MercadoPago no está configurado.', 'error');
        return;
      }
      methodBtn.disabled = true;
      setFeedback('Abriendo MercadoPago…', 'ok');
      try {
        const response = await fetch(mpUrl + '?action=create_preapproval', {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
          },
          body: JSON.stringify({
            action: 'create_preapproval',
            id_membership: pendingPay.id,
            billing_period: pendingPay.period,
            _csrf: pendingPay.csrf,
          }),
        });
        let payload = null;
        try {
          payload = await response.json();
        } catch (_) {
          payload = null;
        }
        const link = payload && (payload.init_point || payload.sandbox_init_point);
        if (!response.ok || !payload || payload.ok !== true || !link) {
          const errMsg = (payload && payload.error) ? String(payload.error) : 'No se pudo iniciar MercadoPago.';
          setFeedback(errMsg, 'error');
          methodBtn.disabled = false;
          return;
        }
        window.location.href = String(link);
      } catch (_) {
        setFeedback('Error de red al contactar MercadoPago.', 'error');
        methodBtn.disabled = false;
      }
    }
  });

  // Capture PayPal return (?pay=paypal_ok&token=ORDER_ID)
  const params = new URLSearchParams(window.location.search);
  const payFlag = params.get('pay');
  if (payFlag === 'paypal_ok') {
    const orderId = params.get('token') || params.get('order_id') || '';
    if (orderId && paypalUrl) {
      setFeedback('Confirmando pago PayPal…', 'ok');
      openModal();
      fetch(paypalUrl + '?action=capture_order', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          action: 'capture_order',
          order_id: orderId,
          _csrf: (modal.querySelector('input[name="_csrf"]') || {}).value || '',
        }),
      }).then(async (response) => {
        let payload = null;
        try {
          payload = await response.json();
        } catch (_) {
          payload = null;
        }
        if (response.ok && payload && payload.ok) {
          setFeedback('Pago confirmado. Recargando…', 'ok');
          window.setTimeout(() => {
            const url = new URL(window.location.href);
            url.searchParams.delete('pay');
            url.searchParams.delete('token');
            url.searchParams.delete('order_id');
            url.searchParams.delete('PayerID');
            window.location.href = url.toString();
          }, 700);
        } else {
          setFeedback((payload && payload.error) || 'No se pudo confirmar el pago PayPal.', 'error');
        }
      }).catch(() => {
        setFeedback('Error al confirmar el pago PayPal.', 'error');
      });
    }
  } else if (payFlag === 'paypal_cancel') {
    openModal();
    setFeedback('Cancelaste el pago en PayPal. Podés reintentar cuando quieras.', 'error');
  }
})();
