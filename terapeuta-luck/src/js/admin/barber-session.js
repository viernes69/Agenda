// Manejador de sesión del barbero (URLs dinámicas por tenant)
window.BarberSessionManager = {
    STORAGE_KEY: 'barberSessionData',

    sessionEndpoint(action) {
        const parts = window.location.pathname.split('/').filter(Boolean);
        const privateIdx = parts.indexOf('private');
        const srcIdx = parts.indexOf('src');
        let tenantRoot = '/';
        if (privateIdx > 0) {
            tenantRoot = '/' + parts.slice(0, privateIdx).join('/') + '/';
        } else if (srcIdx > 0) {
            tenantRoot = '/' + parts.slice(0, srcIdx).join('/') + '/';
        } else if (window.__TENANT_CONFIG__ && window.__TENANT_CONFIG__.basePath) {
            tenantRoot = String(window.__TENANT_CONFIG__.basePath);
            if (!tenantRoot.endsWith('/')) tenantRoot += '/';
        }
        return new URL(`src/API/session_barber.php?action=${encodeURIComponent(action)}`, window.location.origin + tenantRoot).href;
    },

    saveSession(barberData) {
        if (!barberData) return false;
        localStorage.setItem(this.STORAGE_KEY, JSON.stringify({
            data: barberData,
            timestamp: Date.now()
        }));
        return true;
    },

    getSession() {
        try {
            const stored = localStorage.getItem(this.STORAGE_KEY);
            if (!stored) return null;
            const session = JSON.parse(stored);
            const elapsed = Date.now() - session.timestamp;
            if (elapsed > 24 * 60 * 60 * 1000) {
                this.clearSession();
                return null;
            }
            return session.data;
        } catch (e) {
            console.error('Error al recuperar sesión:', e);
            return null;
        }
    },

    clearSession() {
        localStorage.removeItem(this.STORAGE_KEY);
    },

    isAuthenticated() {
        return this.getSession() !== null;
    },

    async syncWithServer() {
        try {
            const response = await fetch(this.sessionEndpoint('status'), {
                method: 'GET',
                credentials: 'include'
            });
            const text = await response.text();
            try {
                const result = JSON.parse(text);
                if (!result || !result.ok || !result.data) {
                    this.clearSession();
                    return false;
                }
                const currentData = this.getSession();
                if (JSON.stringify(currentData) !== JSON.stringify(result.data)) {
                    this.saveSession(result.data);
                }
                return true;
            } catch (jsonErr) {
                console.error('Respuesta no JSON al sincronizar sesión de barbero:', text);
                this.clearSession();
                return false;
            }
        } catch (e) {
            console.error('Error al sincronizar con servidor:', e);
            return false;
        }
    },

    async login(barberoData, password) {
        try {
            const response = await fetch(this.sessionEndpoint('barber_login'), {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'barber_login',
                    barber_data: barberoData,
                    password: password
                })
            });
            const result = await response.json();
            if (result.ok && result.data) {
                this.saveSession(result.data);
                return result.data;
            }
            return null;
        } catch (e) {
            console.error('Error en login:', e);
            return null;
        }
    },

    async logout() {
        try {
            await fetch(this.sessionEndpoint('barber_logout'), {
                method: 'POST',
                credentials: 'include'
            });
            this.clearSession();
            return true;
        } catch (e) {
            console.error('Error en logout:', e);
            return false;
        }
    }
};

document.addEventListener('DOMContentLoaded', async () => {
    const isSessionValid = await BarberSessionManager.syncWithServer();
    if (isSessionValid && typeof updateUIWithBarberData === 'function') {
        updateUIWithBarberData(BarberSessionManager.getSession());
    }
});
