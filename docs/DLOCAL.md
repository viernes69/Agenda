# dLocal Go - Integración de Suscripciones Recurrentes

Documentación de la integración con [dLocal Go](https://docs.dlocalgo.com/integration-api) para
que los comercios vendan **planes recurrentes** (ej: "Membresía Mensual 4 Cortes") a sus clientes.

## Tabla de contenidos

- [Visión general](#visión-general)
- [Setup por comercio](#setup-por-comercio)
- [Estructura de archivos](#estructura-de-archivos)
- [Flujo end-to-end](#flujo-end-to-end)
- [API de dLocal Go usada](#api-de-dlocal-go-usada)
- [Testing](#testing)
- [Webhooks](#webhooks)
- [Seguridad](#seguridad)
- [Troubleshooting](#troubleshooting)
- [Pendientes / TODO](#pendientes--todo)

## Visión general

Cada comercio tiene su **propia cuenta dLocal Go** y guarda sus credenciales localmente.
El flujo es:

```
1. Salon crea un plan en dLocal (via nuestro panel admin)
   -> dLocal devuelve un plan_token (ej: "abc123def456")

2. Nuestro sistema guarda el plan en la DB del tenant bajo "planes_cliente"

3. Cliente del salon ve los planes en la web publica y hace click en "Suscribirme"
   -> nuestro sistema genera la URL de checkout de dLocal con email + external_id

4. Cliente completa el pago en el checkout de dLocal
   -> dLocal notifica al webhook con el external_id

5. Nuestro webhook valida la firma HMAC, busca la suscripcion por external_id
   y actualiza el estado en la DB del tenant.
```

**Importante:** dLocal maneja todo el ciclo de cobros recurrentes. Nosotros solo registramos
los eventos.

## Setup por comercio

### 1. Crear cuenta en dLocal Go

- Sandbox: https://dashboard-sbx.dlocalgo.com/signup
- Live: https://dashboard.dlocalgo.com/signup

### 2. Obtener las API keys

En el dashboard, ir a **API Integration** y copiar:
- **API Key** (ej: `pousvjavqtUMIjgTUvEocPLeYdkhhbVk`)
- **Secret Key** (ej: `9fJzsSRd3OkUHc5LD79cVCVORNp4uJTbd1mGxrA4`)

### 3. Configurar en el salón

**Opción A - Panel admin** (próximamente): usar el componente `src/components/dlocal/admin_plans.php`.

**Opción B - CLI** (disponible ya):
```bash
php tests/seed_dlocal.php mi-salon <api_key> <secret_key> 1
#                                       ^             ^         ^
#                                       |             |         +-- sandbox: 1 = sandbox, 0 = live
#                                       |             +-- secret key
#                                       +-- api key
```

O con variables de entorno:
```bash
export DLOCAL_SEED_SLUG=mi-salon
export DLOCAL_API_KEY=pousvjav...
export DLOCAL_SECRET_KEY=9fJzsSRd...
export DLOCAL_SANDBOX=1
php tests/seed_dlocal.php
```

**Opción C - API directa**:
```bash
curl -X POST https://agenduy.uy/src/API/dlocal/config_save.php \
  -H "Content-Type: application/json" \
  -H "Cookie: <sesion admin>" \
  -d '{"api_key":"...","secret_key":"...","sandbox":true,"_csrf":"<token>"}'
```

### 4. Crear el webhook en dLocal (opcional - lo seteamos auto al crear planes)

Cuando nuestro sistema crea un plan via API, le pasa a dLocal un `notification_url` que
apunta a `https://<tu-dominio>/admin/api/webhook_dlocal.php?slug=<slug>&source=plan`.
No hay que configurar nada en el dashboard de dLocal.

## Estructura de archivos

```
src/
├── Core/
│   ├── Dlocal.php                       # Helper principal (auth, planes, HMAC)
│   ├── TenantLocalDb.php                # Acceso a la DB del tenant (ya existía)
│   ├── Database.php                     # SQLite central + auto-migración
│   └── db/
│       └── schema.sql                   # Enums actualizados con 'dlocal'
├── API/
│   └── dlocal/
│       ├── config_save.php              # POST: guardar credenciales del tenant
│       ├── create_plan.php              # POST: crear plan en dLocal
│       └── subscribe.php                # POST: generar URL de checkout
├── components/
│   └── dlocal/
│       ├── plans.php                    # Componente público (HTML de los planes)
│       └── admin_plans.php              # Componente admin (config + gestión)
admin/
└── api/
    └── webhook_dlocal.php               # POST: recibir notificaciones de dLocal
public/
└── assets/
    ├── css/
    │   ├── dlocal-plans.css
    │   └── dlocal-admin.css
    └── js/
        ├── dlocal-plans.js
        └── dlocal-admin.js
tests/
├── dlocal_helper_test.php               # Test unit del helper
├── dlocal_webhook_test.php              # Test del webhook (HMAC, status mapping)
├── dlocal_integration_test.php          # Test integral end-to-end (con mock)
├── dlocal_run_integration.ps1           # PowerShell wrapper (arranca servers)
├── dlocal_mock.php                      # Mock server de dLocal para tests
├── dlocal_schema_test.php               # Test de migración SQLite
├── dlocal_render_test.php               # Test del componente público
├── dlocal_admin_render_test.php         # Test del componente admin
└── seed_dlocal.php                      # CLI seed
```

## Flujo end-to-end

### 1. Comercio configura dLocal
```
POST /src/API/dlocal/config_save.php
{ "_csrf": "...", "api_key": "...", "secret_key": "...", "sandbox": true }
```

### 2. Comercio crea un plan
```
POST /src/API/dlocal/create_plan.php
{ "_csrf": "...", "name": "Membresia 4 Cortes",
  "description": "...", "amount": 2500, "currency": "UYU",
  "frequency_type": "MONTHLY", "frequency_value": 1,
  "free_trial_days": 7 }
```
Respuesta:
```json
{ "ok": true, "internal_id": "abc123", "dlocal_plan_id": 1584,
  "plan_token": "8ceWJ0nFvoPYGI3Y3sJqpQD4HE7IlPHi",
  "subscribe_url": "https://checkout-sbx.dlocalgo.com/validate/subscription/..." }
```

### 3. Cliente se suscribe (desde la web pública)
```
POST /src/API/dlocal/subscribe.php
{ "_csrf": "...", "slug": "mi-salon",
  "plan_internal_id": "abc123",
  "customer_email": "cliente@example.com",
  "customer_name": "Juan Pérez" }
```
Respuesta:
```json
{ "ok": true,
  "subscribe_url": "https://checkout-sbx.dlocalgo.com/validate/subscription/...?...&external_id=c5_p1584_abc12345",
  "external_id": "c5_p1584_abc12345",
  "suscripcion_id": "sub_xyz" }
```
El frontend redirige al cliente a `subscribe_url`.

### 4. dLocal notifica al webhook
```
POST /admin/api/webhook_dlocal.php?slug=mi-salon&source=plan
Authorization: V2-HMAC-SHA256, Signature: <hex>
Content-Type: application/json

{ "payment_id": "DP-12345", "status": "PAID" }
```

El webhook:
1. Lee `slug` del query string
2. Lee la config dLocal del tenant
3. Valida la firma HMAC-SHA256
4. Si el body no trae `external_id`, hace GET a dLocal para obtener el `order_id` (= external_id)
5. Busca la suscripcion local por `external_id`
6. Actualiza el estado (`status: CONFIRMED/CANCELLED/REJECTED/EXPIRED`)
7. Loguea en `audit_log`

## API de dLocal Go usada

| Endpoint | Verbo | Uso |
|---|---|---|
| `/v1/subscription/plan` | POST | Crear plan |
| `/v1/subscription/plan` | GET | Listar planes |
| `/v1/subscription/plan/{id}` | GET | Obtener un plan |
| `/v1/subscription/plan/{planId}/subscription/{subId}/deactivate` | PATCH | Cancelar suscripción |
| `/v1/payments/{id}` | GET | Detalle de un pago (usado por el webhook) |

**Auth header:** `Authorization: Bearer <api_key>:<secret_key>`

**URLs:**
- Sandbox: `https://api-sbx.dlocalgo.com`
- Live: `https://api.dlocalgo.com`
- Checkout sandbox: `https://checkout-sbx.dlocalgo.com`
- Checkout live: `https://checkout.dlocalgo.com`

**Firma webhook (HMAC-SHA256):**
```
Signature = HMAC-SHA256(api_key + raw_body, secret_key)  // en hex
```
Header: `Authorization: V2-HMAC-SHA256, Signature: <hex>`

## Testing

### Test unit del helper
```bash
php tests/dlocal_helper_test.php
```
Verifica: base URLs, auth header, HMAC sign/verify (multi-formato), error messages, fromConfig.

### Test del webhook
```bash
php tests/dlocal_webhook_test.php
```
Crea un tenant mock, hace POST al webhook con distintos casos:
- HMAC válido + external_id existente → CONFIRMED
- HMAC inválido → 401
- Sin Authorization → 401
- Slug inexistente → 200 (no retry)
- external_id no existe → matched=false
- status CANCELLED → actualiza DB

**7/7 tests OK**

### Test integral end-to-end
```powershell
powershell -ExecutionPolicy Bypass -File tests/dlocal_run_integration.ps1
```
Arranca un mock server de dLocal + el app server, corre 9 tests que cubren todo el flujo.

**9/9 tests OK**

### Test del schema
```bash
php tests/dlocal_schema_test.php
```
Verifica la migración SQLite (agrega 'dlocal' a los CHECK de subscriptions, payment_provider_config, api_keys).

### Test del componente público
```bash
php tests/dlocal_render_test.php
```
Verifica el HTML del componente `plans.php` (15 casos).

**15/15 tests OK**

### Test del componente admin
```bash
php tests/dlocal_admin_render_test.php
```
Verifica carga del componente admin (3 casos).

**3/3 tests OK**

## Webhooks

- **Endpoint:** `https://<tu-dominio>/admin/api/webhook_dlocal.php?slug=<slug>&source=plan`
- **Validación:** HMAC-SHA256 obligatoria
- **Reintentos:** dLocal reintenta cada 10 min por 30 días si no devolvemos 200.
  Por eso, ante cualquier error nuestro webhook devuelve **200 con `ok: false`** (en vez de 4xx/5xx)
  para evitar el loop de reintentos cuando el problema es "tenant no configurado" o "dLocal
  no encuentra la suscripción".

### Eventos que manejamos
- `payment_id` con `status: PAID` → CONFIRMED + `confirmed_at`
- `payment_id` con `status: REJECTED` → REJECTED
- `payment_id` con `status: CANCELLED` → CANCELLED + `cancelled_at`
- `subscription_id` con cambios → actualiza según status
- Body sin `external_id` pero con `payment_id` → hace GET a dLocal para obtener `order_id`

## Seguridad

- **Credenciales:** se guardan en `{slug}/src/db/database.php` bajo la clave `dlocal`.
  El archivo `database.php` está bloqueado por Apache (`Require all denied` en `.htaccess`).
- **Secret en logs:** el `audit_log` solo guarda `is_enabled` y `sandbox`, nunca la key.
  El `Dlocal::userErrorMessage` nunca loguea el header `Authorization`.
- **Firma webhook:** `hash_equals` para evitar timing attacks.
- **CSRF:** todos los endpoints admin requieren token CSRF (`dlocal_config` / `dlocal_plan`).
  El endpoint público (`subscribe.php`) usa `public_booking` (mismo que las reservas).
- **API override para tests:** el constructor acepta `base_url` y `checkout_base` opcionales.
  Solo se usan en tests con mock server; en producción las URLs vienen del modo sandbox/live.

## Troubleshooting

### 403 "Invalid Credentials" en dLocal
Las keys no corresponden al ambiente (sandbox vs live) o están mal copiadas.
Verificar en el dashboard de dLocal que las keys coincidan con el modo configurado.

### Webhook devuelve `ok: false, error: "Tenant dLocal config not found"`
El tenant no tiene `dlocal` configurado en su `database.php`. Correr:
```bash
php tests/seed_dlocal.php <slug> <api_key> <secret_key> <sandbox:0|1>
```

### Webhook devuelve `ok: true, matched: false`
- `external_id` no matchea ninguna `suscripciones_cliente` del tenant.
- Verificar que el `subscribe.php` haya pre-registrado la suscripcion antes de que el cliente
  llegara al checkout.
- O que el `order_id` de dLocal se corresponda con el `external_id` que generamos.

### 500 en el endpoint `GET /v1/subscription/plan` (listar todos)
Bug conocido de dLocal. No es bloqueante porque nuestro sistema crea planes uno a uno y los
planes se listan desde nuestra DB local (`planes_cliente`), no desde dLocal.

### Test del webhook falla con "table 'X' not allowed"
Los slugs de test deben matchear el regex `/^[a-z0-9][a-z0-9-]*$/`. No usar guiones bajos
en el slug (usar guiones medios).

### `Cannot use output buffering` o warning de sesión
Los tests se ejecutan mejor desde CLI con `php`, no desde el navegador. Los warnings
de sesión no afectan los tests.

## Pendientes / TODO

- [x] Integrar el componente `admin_plans.php` en el panel del salón (`admin/commerce_settings.php`).
- [x] Integrar el componente `plans.php` en la web pública (`src/Core/commerce_view.php`).
- [ ] Agregar `dlocal` al super admin `payment_provider_config` global (por si en el futuro
      queremos una sola cuenta de plataforma).
- [ ] UI para cancelar suscripciones (PATCH /v1/subscription/plan/.../subscription/.../deactivate)
- [ ] UI para listar `suscripciones_cliente` del tenant con filtros (status, fecha)
- [ ] Cron que sincronice el estado con dLocal periódicamente (por si dLocal no notifica o hay
      eventos perdidos).
- [ ] Tests E2E con credenciales reales (requiere credenciales válidas del usuario).

## Changelog

- **2026-07-19**: Integración inicial. Helper + 4 endpoints + schema migration + UI components
  + tests. **9/9 tests integrales + 7/7 tests webhook + 15/15 tests render + 3/3 tests admin = 34/34 OK.**
