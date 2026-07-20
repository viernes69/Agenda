function updateUIWithBarberData(barberData) {
    if (!barberData) return;

    // Actualizar nombre del barbero donde sea necesario
    const barberNameElements = document.querySelectorAll('.barber-name');
    barberNameElements.forEach(el => {
        el.textContent = window.buildBarberoName(barberData);
    });

    // Actualizar estado del barbero
    if (barberData.Status) {
        const statusElements = document.querySelectorAll('.barber-status');
        statusElements.forEach(el => {
            el.textContent = barberData.Status;
            el.className = `barber-status status-${barberData.Status.toLowerCase()}`;
        });
    }

    // Mostrar elementos que requieren autenticación
    document.querySelectorAll('.requires-auth').forEach(el => {
        el.style.display = 'block';
    });

    // Ocultar elementos de login
    document.querySelectorAll('.requires-no-auth').forEach(el => {
        el.style.display = 'none';
    });

    // Disparar evento personalizado
    const event = new CustomEvent('barberSessionUpdated', { detail: barberData });
    document.dispatchEvent(event);
}

// Función para limpiar la UI cuando se cierra sesión
function clearBarberUI() {
    // Ocultar elementos que requieren autenticación
    document.querySelectorAll('.requires-auth').forEach(el => {
        el.style.display = 'none';
    });

    // Mostrar elementos de login
    document.querySelectorAll('.requires-no-auth').forEach(el => {
        el.style.display = 'block';
    });

    // Limpiar nombre del barbero
    document.querySelectorAll('.barber-name').forEach(el => {
        el.textContent = 'Barbero';
    });

    // Limpiar estado
    document.querySelectorAll('.barber-status').forEach(el => {
        el.textContent = '';
        el.className = 'barber-status';
    });

    // Disparar evento personalizado
    document.dispatchEvent(new Event('barberSessionEnded'));
}