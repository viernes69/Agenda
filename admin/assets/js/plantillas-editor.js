(function plantillasEditor() {
  'use strict';

  const root = document.querySelector('[data-preview-url]');
  const previewUrl = root ? root.getAttribute('data-preview-url') || 'api/plantillas_preview.php' : 'api/plantillas_preview.php';
  const csrfInput = document.querySelector('input[name="_csrf"]');
  const csrf = csrfInput ? csrfInput.value : '';

  const debounce = (fn, ms) => {
    let t;
    return (...args) => {
      clearTimeout(t);
      t = setTimeout(() => fn(...args), ms);
    };
  };

  const syncRichToTextarea = (wrap) => {
    const rich = wrap.querySelector('[data-rich-body]');
    const ta = wrap.querySelector('[data-rich-source]');
    if (rich && ta) {
      ta.value = rich.innerHTML.trim();
    }
  };

  document.querySelectorAll('[data-tpl-editor]').forEach((wrap) => {
    const rich = wrap.querySelector('[data-rich-body]');
    const ta = wrap.querySelector('[data-rich-source]');
    if (!rich || !ta) return;
    rich.innerHTML = ta.value || '';
    wrap.querySelectorAll('[data-rich-cmd]').forEach((btn) => {
      btn.addEventListener('click', (e) => {
        e.preventDefault();
        rich.focus();
        const cmd = btn.getAttribute('data-rich-cmd') || '';
        const val = btn.getAttribute('data-rich-val') || null;
        if (cmd === 'logo') {
          document.execCommand('insertHTML', false, '{logo}');
        } else if (cmd === 'link') {
          const url = window.prompt('URL del enlace:', 'https://');
          if (url) document.execCommand('createLink', false, url);
        } else {
          document.execCommand(cmd, false, val);
        }
        syncRichToTextarea(wrap);
        wrap.dispatchEvent(new CustomEvent('tpl-change'));
      });
    });
    rich.addEventListener('input', () => {
      syncRichToTextarea(wrap);
      wrap.dispatchEvent(new CustomEvent('tpl-change'));
    });
  });

  const requestPreview = debounce(async (card) => {
    const channel = card.getAttribute('data-channel') || '';
    const key = card.getAttribute('data-template-key') || '';
    const subjectEl = card.querySelector('[data-preview-subject-source]');
    const bodyEl = card.querySelector('[data-preview-body-source]');
    const subject = subjectEl ? subjectEl.value : '';
    let body = bodyEl ? bodyEl.value : '';
    const richWrap = card.querySelector('[data-tpl-editor]');
    if (richWrap) syncRichToTextarea(richWrap);

    const target = card.querySelector('[data-preview-target]');
    if (!target) return;
    target.classList.add('is-loading');

    try {
      const res = await fetch(previewUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({
          channel,
          template_key: key,
          subject,
          body,
          _csrf: csrf,
        }),
      });
      const json = await res.json().catch(() => null);
      if (!res.ok || !json || !json.ok) {
        target.innerHTML = '<p class="hint hint--error">No se pudo generar la vista previa.</p>';
        return;
      }
      const p = json.preview || {};
      if (p.channel === 'email') {
        target.innerHTML =
          '<div class="preview-gmail">' +
          '<div class="preview-gmail__bar"><span class="preview-gmail__dot"></span><span class="preview-gmail__dot"></span><span class="preview-gmail__dot"></span><span>Gmail · Vista previa</span></div>' +
          '<div class="preview-gmail__meta"><div><strong>De:</strong> Agendarte Notificaciones</div>' +
          '<div><strong>Asunto:</strong> ' + escapeHtml(p.subject || '') + '</div></div>' +
          '<div class="preview-gmail__body"><iframe class="preview-gmail__iframe" title="Vista previa email"></iframe></div></div>';
        const iframe = target.querySelector('iframe');
        if (iframe) {
          iframe.srcdoc = p.html || '';
        }
      } else {
        const lines = String(p.text || '').split('\n');
        const bubbles = lines.map((line) =>
          '<div class="preview-wa__bubble">' + escapeHtml(line) + '<span class="preview-wa__time">' + escapeHtml(p.time || '') + '</span></div>'
        ).join('');
        target.innerHTML =
          '<div class="preview-wa">' +
          '<div class="preview-wa__header"><i class="bx bxl-whatsapp"></i> WhatsApp · UltraMSG</div>' +
          '<div class="preview-wa__chat">' + bubbles + '</div></div>';
      }
    } catch (_) {
      target.innerHTML = '<p class="hint hint--error">Error de conexión.</p>';
    } finally {
      target.classList.remove('is-loading');
    }
  }, 400);

  function escapeHtml(str) {
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  document.querySelectorAll('[data-template-card]').forEach((card) => {
    const run = () => requestPreview(card);
    card.querySelectorAll('[data-preview-subject-source], [data-preview-body-source]').forEach((el) => {
      el.addEventListener('input', run);
    });
    const richWrap = card.querySelector('[data-tpl-editor]');
    if (richWrap) richWrap.addEventListener('tpl-change', run);
    run();
  });

  const mainForm = document.getElementById('plantillas-form');
  if (mainForm) {
    mainForm.addEventListener('submit', () => {
      document.querySelectorAll('[data-tpl-editor]').forEach(syncRichToTextarea);
    });
  }
})();
