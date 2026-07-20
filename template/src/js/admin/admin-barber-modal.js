// admin-barber-modal.js
// Funcionalidades para el modal de reserva de barbero
(function adminBarberModal() {
  // Espera a que el DOM esté listo
  document.addEventListener('DOMContentLoaded', function() {
    const barberModal = document.querySelector('[data-modal="barber"]');
    if (!barberModal) return;
    const barberFields = {
      avatarInner: barberModal.querySelector('[data-barber-avatar-inner]'),
      name: barberModal.querySelector('[data-barber-name]'),
      turns: barberModal.querySelector('[data-barber-turns]'),
      slots: barberModal.querySelector('[data-barber-slots]'),
      dateInput: barberModal.querySelector('[data-barber-date]'),
      serviceSelect: barberModal.querySelector('[data-barber-service]'),
      slotSelect: barberModal.querySelector('[data-barber-slot]'),
      avatarTrigger: barberModal.querySelector('[data-barber-avatar-trigger]'),
      photoOverlay: barberModal.querySelector('[data-barber-photo-overlay]'),
      photoImg: barberModal.querySelector('[data-barber-photo-img]'),
      photoFallback: barberModal.querySelector('[data-barber-photo-fallback]'),
      photoFallbackInner: barberModal.querySelector('[data-barber-photo-fallback-inner]'),
      photoName: barberModal.querySelector('[data-barber-photo-name]'),
      photoTurns: barberModal.querySelector('[data-barber-photo-turns]'),
      photoClose: barberModal.querySelector('[data-barber-photo-close]'),
    };

    // Función para mostrar el modal con los datos del barbero
    window.openBarberModal = function(item) {
      if (!barberModal || !barberFields) return;
      barberFields.name && (barberFields.name.textContent = item.barber || 'Barbero');
      const turnsText = Array.isArray(item.turns) ? item.turns.join(' • ') : '';
      barberFields.turns && (barberFields.turns.textContent = turnsText);
      if (barberFields.avatarInner) {
        barberFields.avatarInner.textContent = (item.barber || 'B').split(' ').map(n => n[0]).join('').toUpperCase();
      }
      // Foto y overlay
      if (barberFields.photoImg && barberFields.photoFallback && barberFields.photoFallbackInner) {
        const url = item.avatar || '';
        if (url) {
          barberFields.photoImg.src = url;
          barberFields.photoImg.hidden = false;
          barberFields.photoFallback.hidden = true;
        } else {
          barberFields.photoImg.hidden = true;
          barberFields.photoFallback.hidden = false;
          barberFields.photoFallbackInner.textContent = (item.barber || 'B').split(' ').map(n => n[0]).join('').toUpperCase();
        }
        if (barberFields.photoName) barberFields.photoName.textContent = item.barber || 'Barbero';
        if (barberFields.photoTurns) barberFields.photoTurns.textContent = turnsText;
      }
      // Slots
      if (barberFields.slots) {
        barberFields.slots.innerHTML = '';
        (Array.isArray(item.slots) ? item.slots : []).forEach(slot => {
          const btn = document.createElement('button');
          btn.type = 'button';
          btn.className = 'slot-btn';
          btn.textContent = slot;
          barberFields.slots.appendChild(btn);
        });
      }
      // Validación de días de trabajo
      let warningMsg = barberModal.querySelector('#barber-dia-warning');
      if (!warningMsg) {
        warningMsg = document.createElement('div');
        warningMsg.id = 'barber-dia-warning';
        warningMsg.style.color = 'red';
        warningMsg.style.marginTop = '8px';
        warningMsg.style.display = 'none';
        if (barberFields.dateInput) barberFields.dateInput.parentNode.appendChild(warningMsg);
      }
      const diasTrabajoRaw = item.DiasTrabajo || '';
      const diasTrabajoArr = diasTrabajoRaw.split(',').map(d => d.trim().toLowerCase());
      function getDayName(dateStr) {
        const dias = ['domingo', 'lunes', 'martes', 'miercoles', 'jueves', 'viernes', 'sabado'];
        const date = new Date(dateStr);
        return dias[date.getDay()];
      }
      if (barberFields.dateInput) {
        barberFields.dateInput.addEventListener('change', function() {
          const dayName = getDayName(this.value);
          if (dayName && !diasTrabajoArr.includes(dayName)) {
            warningMsg.textContent = 'Seleccionaste un día en el cual el funcionario no trabaja';
            warningMsg.style.display = 'block';
          } else {
            warningMsg.textContent = '';
            warningMsg.style.display = 'none';
          }
        });
        // Validar al abrir el modal también
        const dayNameInit = getDayName(barberFields.dateInput.value);
        if (dayNameInit && !diasTrabajoArr.includes(dayNameInit)) {
          warningMsg.textContent = 'Seleccionaste un día en el cual el funcionario no trabaja';
          warningMsg.style.display = 'block';
        } else {
          warningMsg.textContent = '';
          warningMsg.style.display = 'none';
        }
      }
      barberModal.classList.add('is-visible');
      barberModal.hidden = false;
    };
  });
})();
