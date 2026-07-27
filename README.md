# Agendarte - Sistema de Reservas

Plataforma multi-tenant de gestión de reservas (barberías, dentistas, abogados, etc.) con MercadoPago + PayPal + Transferencias, Google Calendar y panel global de super admin.

## Estructura

```
agenduy.uy/
├── index.php                      ← Landing principal
├── admin/                         ← Panel Super Admin
│   ├── login.php                  ← Login super admin
│   ├── commerce_login.php         ← Login dueño de comercio
│   ├── index.php                  ← Overview (KPIs globales)
│   ├── commerces.php              ← CRUD comercios
│   ├── memberships.php            ← CRUD planes
│   ├── subscriptions.php          ← Activar/cancelar suscripciones
│   ├── keys.php                   ← API keys con autogenerar
│   ├── payments.php               ← Approve/reject transferencias
│   ├── config.php                 ← Config global de gateways
│   ├── commerce_dashboard.php     ← Panel del comercio
│   ├── commerce_appointments.php
│   ├── commerce_clients.php
│   ├── commerce_services.php
│   ├── commerce_settings.php
│   ├── api/                       ← Endpoints JSON
│   │   ├── mercadopago.php
│   │   ├── paypal.php
│   │   ├── google_calendar.php
│   │   ├── appointments.php       ← público
│   │   ├── transfer_upload.php    ← público
│   │   ├── register.php           ← público
│   │   ├── commerce_auth.php      ← público
│   │   ├── webhook_mercadopago.php
│   │   └── webhook_paypal.php
│   └── install.php                ← (borrar tras instalar)
├── src/
│   ├── Core/                      ← Clases base
│   │   ├── Database.php           (SQLite + PDO)
│   │   ├── Auth.php
│   │   ├── CSRF.php
│   │   ├── Crypto.php             (AES-256-GCM)
│   │   ├── Mail.php
│   │   ├── Keys.php
│   │   ├── config.php
│   │   ├── bootstrap.php
│   │   └── db/
│   │       ├── schema.sql         (16 tablas)
│   │       └── seed.php
│   └── components/
│       └── register/
│           └── modal.php          ← Form de registro (nuevo)
└── storage/
    ├── agenduy.db                 ← BD SQLite (FUERA del document root)
    ├── logs/
    ├── backups/
    └── uploads/
        └── receipts/{id_commerce}/
```

## Instalación

### 1. Verificar requisitos
- PHP 8.0+ con PDO/SQLite
- Apache con mod_rewrite (o usar el server embebido)

### 2. Crear la BD y semilla
```
http://localhost/agenduy.uy/admin/install.php?key=INSTALL_AGENDUY_2026
```

Esto crea:
- 8 rubros
- 1 membresía "Plan Básico"
- 1 super admin: `admin@agenduy.uy` / `Agenduy2026!`
- 3 providers (mercadopago, paypal, transfer)

### 3. Migrar datos legacy (opcional)
Si tenés el viejo `src/db/database.php`:
```
http://localhost/agenduy.uy/admin/migrate.php?key=INSTALL_AGENDUY_2026
```

### 4. Eliminar archivos sensibles
```bash
rm admin/install.php
rm admin/migrate.php
```

### 5. Configurar credenciales por variable de entorno (producción)
```
AGENDUY_DB_PATH=/ruta/fuera/del/documentroot/agenduy.db
AGENDUY_ENCRYPTION_KEY=<bin2hex de 32 bytes random>
AGENDUY_SMTP_HOST=...
AGENDUY_SMTP_PASSWORD=...
AGENDUY_SMTP_PORT=465
AGENDUY_SMTP_ENCRYPTION=ssl
AGENDUY_SMTP_USER=...
AGENDUY_SESSION_SECURE=true
AGENDUY_URL_BASE=https://www.agenduy.uy
```

`AGENDUY_URL_BASE` debe ser una URL publica cuando uses Mercado Pago o PayPal. En sandbox desde localhost, usa el dominio publico configurado o un tunel tipo ngrok/Cloudflare Tunnel.

## Seguridad implementada

- ✅ **CSRF tokens** server-side con un solo uso
- ✅ **SQLite fuera del document root** (en `storage/`)
- ✅ **API keys encriptadas** con AES-256-GCM
- ✅ **`.htaccess` que niega acceso** a `database.php`, `*.sqlite*`, `Private/`, `storage/`
- ✅ **Auth con bcrypt cost 12** y lockout tras 5 intentos
- ✅ **Roles**: `super_admin` ve todo, `commerce_admin` solo su comercio
- ✅ **Audit log** de todas las acciones sensibles
- ✅ **Headers de seguridad** (X-Content-Type-Options, X-Frame-Options, Referrer-Policy)
- ✅ **CORS cerrado** por defecto
- ✅ **Validación CSRF** en TODOS los POST/PUT/DELETE
- ✅ **HTTPS ready** (cookie secure opcional vía env)

## Flujo del trial

1. Comercio se registra → status=`trial`, trial_expires_at=+30 días
2. Si paga antes (MP, PayPal o transferencia aprobada) → status=`active`
3. Si no paga → el trial se mantiene hasta que admin apruebe un comprobante
4. Webhook de MP/PayPal actualiza automáticamente
5. Transferencias requieren approve manual desde `/admin/payments.php`

## Google Calendar por comercio

Cada comercio configura sus propias credenciales en `/admin/commerce_settings.php`:
- `GOOGLE_SERVICE_ACCOUNT_JSON` (credenciales de la cuenta de servicio)
- `GOOGLE_CALENDAR_ID` (ID del calendario)

Al confirmar un turno → se crea evento en Calendar y se envía email a ambas partes.

## Notificaciones

Las reservas encolan emails (SMTP) y WhatsApp (UltraMsg) en `notification_outbox`; las inmediatas se despachan al crear la reserva y el recordatorio de 2 horas queda agendado. Todo envío se registra en `notifications_log`.

**Configuración** (super admin → `/admin/config.php`):
- **SMTP**: host, puerto, cifrado (ssl/tls), usuario, contraseña, email y nombre remitente. Habilitar el provider.
- **UltraMsg** (WhatsApp): Instance ID y Token (se obtienen en [ultramsg.com](https://docs.ultramsg.com/)). Habilitar el provider.

**Procesar la cola** (recordatorios y reintentos):
```bash
php bin/process-outbox.php
```

En Windows, programarlo con Task Scheduler cada 5 minutos (acción: `php.exe C:\xampp\htdocs\agenduy.uy\bin\process-outbox.php`). En Linux, cron: `*/5 * * * * php /ruta/agenduy.uy/bin/process-outbox.php`.

## URLs útiles

- **Landing**: `https://www.agenduy.uy/`
- **Super admin**: `https://www.agenduy.uy/admin/`
- **Login comercio**: `https://www.agenduy.uy/admin/commerce_login.php`
- **MP webhook**: `https://www.agenduy.uy/admin/api/webhook_mercadopago.php`
- **PayPal webhook**: `https://www.agenduy.uy/admin/api/webhook_paypal.php`
