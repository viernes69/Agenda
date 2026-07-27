-- =====================================================================
-- AGENDUY · SQLite Schema
-- Single database file. All commerces live in the same DB,
-- isolated by `commerce_id` (and `slug`) foreign keys.
-- =====================================================================

PRAGMA foreign_keys = ON;
PRAGMA journal_mode = WAL;
PRAGMA synchronous = NORMAL;

-- ---------------------------------------------------------------------
-- 1. RUBROS (categorias de negocio)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS rubros (
    id_rubro      INTEGER PRIMARY KEY AUTOINCREMENT,
    nombre        TEXT    NOT NULL,
    tipo          TEXT    NOT NULL UNIQUE,
    descripcion   TEXT    DEFAULT '',
    imagen        TEXT    DEFAULT '',
    id_plan_def   INTEGER,
    orden         INTEGER NOT NULL DEFAULT 0,
    activo        INTEGER NOT NULL DEFAULT 1,
    created_at    TEXT    NOT NULL DEFAULT (datetime('now')),
    updated_at    TEXT    NOT NULL DEFAULT (datetime('now'))
);

-- ---------------------------------------------------------------------
-- 2. MEMBRESIAS / PLANES
--    (los crea el super admin; cada comercio se subscribe a uno)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS memberships (
    id_membership    INTEGER PRIMARY KEY AUTOINCREMENT,
    nombre           TEXT    NOT NULL,
    descripcion      TEXT    DEFAULT '',
    features         TEXT    DEFAULT '[]',
    limits           TEXT    DEFAULT '{}',
    precio           REAL    NOT NULL DEFAULT 0,
    precio_anual     REAL    DEFAULT NULL,
    descuento_anual_pct REAL NOT NULL DEFAULT 0,
    anual_habilitado INTEGER NOT NULL DEFAULT 0,
    moneda           TEXT    NOT NULL DEFAULT 'UYU',
    duracion_dias    INTEGER NOT NULL DEFAULT 30,
    trial_dias       INTEGER NOT NULL DEFAULT 30,
    mp_preapproval_id TEXT   DEFAULT NULL,
    paypal_plan_id   TEXT    DEFAULT NULL,
    activo           INTEGER NOT NULL DEFAULT 1,
    created_at       TEXT    NOT NULL DEFAULT (datetime('now')),
    updated_at       TEXT    NOT NULL DEFAULT (datetime('now'))
);

-- ---------------------------------------------------------------------
-- 3. USERS
--    role = 'super_admin'   → ve todo
--    role = 'commerce_admin'→ solo su comercio (id_commerce)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id_user        INTEGER PRIMARY KEY AUTOINCREMENT,
    role           TEXT    NOT NULL CHECK (role IN ('super_admin','commerce_admin')),
    id_commerce    INTEGER DEFAULT NULL,
    nombre         TEXT    NOT NULL,
    apellido       TEXT    DEFAULT '',
    cedula         TEXT    DEFAULT '',
    email          TEXT    NOT NULL UNIQUE,
    telefono       TEXT    DEFAULT '',
    whatsapp       TEXT    DEFAULT '',
    password_hash  TEXT    NOT NULL,
    last_login_at  TEXT    DEFAULT NULL,
    last_login_ip  TEXT    DEFAULT NULL,
    activo         INTEGER NOT NULL DEFAULT 1,
    failed_attempts INTEGER NOT NULL DEFAULT 0,
    locked_until   TEXT    DEFAULT NULL,
    created_at     TEXT    NOT NULL DEFAULT (datetime('now')),
    updated_at     TEXT    NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (id_commerce) REFERENCES commerces(id_commerce) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_users_email        ON users(email);
CREATE INDEX IF NOT EXISTS idx_users_commerce     ON users(id_commerce);
CREATE INDEX IF NOT EXISTS idx_users_role         ON users(role);

-- ---------------------------------------------------------------------
-- 4. COMMERCES (cada negocio registrado)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS commerces (
    id_commerce       INTEGER PRIMARY KEY AUTOINCREMENT,
    slug              TEXT    NOT NULL UNIQUE,
    id_rubro          INTEGER NOT NULL,
    id_membership     INTEGER,
    nombre            TEXT    NOT NULL,
    razon_social      TEXT    DEFAULT '',
    rut_ruc           TEXT    DEFAULT '',
    email             TEXT    DEFAULT '',
    telefono          TEXT    DEFAULT '',
    whatsapp          TEXT    DEFAULT '',
    pais              TEXT    DEFAULT 'UY',
    ciudad            TEXT    DEFAULT '',
    calle             TEXT    DEFAULT '',
    website           TEXT    DEFAULT '',
    slogan            TEXT    DEFAULT '',
    descripcion       TEXT    DEFAULT '',
    logo              TEXT    DEFAULT '',
    timezone          TEXT    NOT NULL DEFAULT 'America/Montevideo',
    status            TEXT    NOT NULL DEFAULT 'trial'
                      CHECK (status IN ('trial','active','past_due','cancelled','suspended')),
    trial_expires_at  TEXT    DEFAULT NULL,
    next_billing_at   TEXT    DEFAULT NULL,
    cancelled_at      TEXT    DEFAULT NULL,
    serial            TEXT    NOT NULL UNIQUE,
    created_at        TEXT    NOT NULL DEFAULT (datetime('now')),
    updated_at        TEXT    NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (id_rubro)      REFERENCES rubros(id_rubro),
    FOREIGN KEY (id_membership) REFERENCES memberships(id_membership)
);

CREATE INDEX IF NOT EXISTS idx_commerces_slug     ON commerces(slug);
CREATE INDEX IF NOT EXISTS idx_commerces_status   ON commerces(status);
CREATE INDEX IF NOT EXISTS idx_commerces_rubro    ON commerces(id_rubro);
CREATE INDEX IF NOT EXISTS idx_commerces_serial   ON commerces(serial);

-- ---------------------------------------------------------------------
-- 5. SUBSCRIPTIONS (suscripcion activa por comercio)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS subscriptions (
    id_subscription    INTEGER PRIMARY KEY AUTOINCREMENT,
    id_commerce        INTEGER NOT NULL,
    id_membership      INTEGER NOT NULL,
    status             TEXT    NOT NULL DEFAULT 'trial'
                       CHECK (status IN ('trial','active','past_due','cancelled')),
    gateway            TEXT    DEFAULT NULL
                       CHECK (gateway IN ('mercadopago','paypal','transfer','manual','dlocal',NULL)),
    gateway_id         TEXT    DEFAULT NULL,
    started_at         TEXT    NOT NULL DEFAULT (datetime('now')),
    trial_expires_at   TEXT    DEFAULT NULL,
    current_period_start TEXT  DEFAULT NULL,
    current_period_end TEXT    DEFAULT NULL,
    cancelled_at       TEXT    DEFAULT NULL,
    billing_period     TEXT    NOT NULL DEFAULT 'monthly'
                       CHECK (billing_period IN ('monthly','yearly')),
    notes              TEXT    DEFAULT '',
    created_at         TEXT    NOT NULL DEFAULT (datetime('now')),
    updated_at         TEXT    NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (id_commerce)   REFERENCES commerces(id_commerce) ON DELETE CASCADE,
    FOREIGN KEY (id_membership) REFERENCES memberships(id_membership)
);

CREATE INDEX IF NOT EXISTS idx_subs_commerce ON subscriptions(id_commerce);
CREATE INDEX IF NOT EXISTS idx_subs_status   ON subscriptions(status);

-- ---------------------------------------------------------------------
-- 6. API_KEYS
--    Credenciales por comercio y por gateway.
--    El super admin las genera desde el panel (autogenerar).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS api_keys (
    id_key        INTEGER PRIMARY KEY AUTOINCREMENT,
    id_commerce   INTEGER DEFAULT NULL,  -- NULL = global (super admin)
    provider      TEXT    NOT NULL
                  CHECK (provider IN ('mercadopago','paypal','google_calendar',
                                      'google_service_account','smtp','ultramsg','generic','dlocal')),
    key_name      TEXT    NOT NULL,      -- ej. 'MP_ACCESS_TOKEN'
    key_value     TEXT    NOT NULL,      -- valor encriptado
    key_preview   TEXT    NOT NULL,      -- ultimos 4 chars para mostrar
    label         TEXT    DEFAULT '',
    is_active     INTEGER NOT NULL DEFAULT 1,
    created_at    TEXT    NOT NULL DEFAULT (datetime('now')),
    updated_at    TEXT    NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (id_commerce) REFERENCES commerces(id_commerce) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_keys_commerce  ON api_keys(id_commerce);
CREATE INDEX IF NOT EXISTS idx_keys_provider  ON api_keys(provider);
CREATE UNIQUE INDEX IF NOT EXISTS idx_keys_unique
    ON api_keys(IFNULL(id_commerce,0), provider, key_name);

-- ---------------------------------------------------------------------
-- 7. PAYMENT TRANSFERS (comprobantes de transferencia pendientes)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS payment_transfers (
    id_transfer    INTEGER PRIMARY KEY AUTOINCREMENT,
    id_commerce    INTEGER NOT NULL,
    id_subscription INTEGER,
    monto          REAL    NOT NULL,
    moneda         TEXT    NOT NULL DEFAULT 'UYU',
    referencia     TEXT    DEFAULT '',
    banco_origen   TEXT    DEFAULT '',
    fecha_transferencia TEXT DEFAULT NULL,
    comprobante_path TEXT  DEFAULT '',
    status         TEXT    NOT NULL DEFAULT 'pending'
                   CHECK (status IN ('pending','approved','rejected')),
    reviewed_by    INTEGER DEFAULT NULL,
    reviewed_at    TEXT    DEFAULT NULL,
    review_notes   TEXT    DEFAULT '',
    created_at     TEXT    NOT NULL DEFAULT (datetime('now')),
    updated_at     TEXT    NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (id_commerce)    REFERENCES commerces(id_commerce) ON DELETE CASCADE,
    FOREIGN KEY (id_subscription) REFERENCES subscriptions(id_subscription) ON DELETE SET NULL,
    FOREIGN KEY (reviewed_by)    REFERENCES users(id_user) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_transfers_status   ON payment_transfers(status);
CREATE INDEX IF NOT EXISTS idx_transfers_commerce ON payment_transfers(id_commerce);

-- ---------------------------------------------------------------------
-- STORE ORDER PAYMENTS (pedidos de tienda cobrados online)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS store_order_payments (
    id_store_payment  INTEGER PRIMARY KEY AUTOINCREMENT,
    id_commerce       INTEGER NOT NULL,
    slug              TEXT    NOT NULL,
    local_order_id    INTEGER NOT NULL,
    external_reference TEXT   NOT NULL UNIQUE,
    preference_id     TEXT    DEFAULT '',
    payment_id        TEXT    DEFAULT '',
    merchant_order_id TEXT    DEFAULT '',
    status            TEXT    NOT NULL DEFAULT 'created'
                      CHECK (status IN ('created','pending','approved','rejected','cancelled','refunded','charged_back','unknown')),
    status_detail     TEXT    DEFAULT '',
    amount            REAL    NOT NULL DEFAULT 0,
    currency          TEXT    NOT NULL DEFAULT 'UYU',
    payer_email       TEXT    DEFAULT '',
    items_json        TEXT    NOT NULL DEFAULT '[]',
    checkout_url      TEXT    DEFAULT '',
    created_at        TEXT    NOT NULL DEFAULT (datetime('now')),
    updated_at        TEXT    NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (id_commerce) REFERENCES commerces(id_commerce) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_store_payments_commerce ON store_order_payments(id_commerce);
CREATE INDEX IF NOT EXISTS idx_store_payments_status ON store_order_payments(status);
CREATE INDEX IF NOT EXISTS idx_store_payments_payment ON store_order_payments(payment_id);
CREATE INDEX IF NOT EXISTS idx_store_payments_preference ON store_order_payments(preference_id);

-- ---------------------------------------------------------------------
-- 8. APPOINTMENTS (turnos / reservas)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS appointments (
    id_appointment   INTEGER PRIMARY KEY AUTOINCREMENT,
    id_commerce      INTEGER NOT NULL,
    id_client        INTEGER,
    id_service       INTEGER,
    id_user_admin    INTEGER,
    fecha            TEXT    NOT NULL,  -- YYYY-MM-DD
    hora_inicio      TEXT    NOT NULL,  -- HH:MM
    hora_fin         TEXT    DEFAULT '',
    cliente_nombre   TEXT    DEFAULT '',
    cliente_email    TEXT    DEFAULT '',
    cliente_telefono TEXT    DEFAULT '',
    notas            TEXT    DEFAULT '',
    precio           REAL    DEFAULT 0,
    status           TEXT    NOT NULL DEFAULT 'pending'
                     CHECK (status IN ('pending','confirmed','cancelled','done','no_show')),
    google_event_id  TEXT    DEFAULT '',
    email_sent_to_client     INTEGER NOT NULL DEFAULT 0,
    email_sent_to_commerce  INTEGER NOT NULL DEFAULT 0,
    created_at       TEXT    NOT NULL DEFAULT (datetime('now')),
    updated_at       TEXT    NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (id_commerce)   REFERENCES commerces(id_commerce) ON DELETE CASCADE,
    FOREIGN KEY (id_client)     REFERENCES clients(id_client) ON DELETE SET NULL,
    FOREIGN KEY (id_service)    REFERENCES services(id_service) ON DELETE SET NULL,
    FOREIGN KEY (id_user_admin) REFERENCES users(id_user) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_appt_commerce ON appointments(id_commerce);
CREATE INDEX IF NOT EXISTS idx_appt_fecha    ON appointments(fecha);
CREATE INDEX IF NOT EXISTS idx_appt_status   ON appointments(status);

-- ---------------------------------------------------------------------
-- 9. CLIENTS (clientes de cada comercio)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS clients (
    id_client     INTEGER PRIMARY KEY AUTOINCREMENT,
    id_commerce   INTEGER NOT NULL,
    nombre        TEXT    NOT NULL,
    apellido      TEXT    DEFAULT '',
    cedula        TEXT    DEFAULT '',
    email         TEXT    DEFAULT '',
    telefono      TEXT    DEFAULT '',
    avatar        TEXT    DEFAULT '',
    notes         TEXT    DEFAULT '',
    total_visits  INTEGER NOT NULL DEFAULT 0,
    last_visit_at TEXT    DEFAULT NULL,
    created_at    TEXT    NOT NULL DEFAULT (datetime('now')),
    updated_at    TEXT    NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (id_commerce) REFERENCES commerces(id_commerce) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_clients_commerce ON clients(id_commerce);
CREATE INDEX IF NOT EXISTS idx_clients_email    ON clients(email);

-- ---------------------------------------------------------------------
-- 10. SERVICES (servicios ofrecidos por cada comercio)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS services (
    id_service    INTEGER PRIMARY KEY AUTOINCREMENT,
    id_commerce   INTEGER NOT NULL,
    id_local      INTEGER DEFAULT NULL,
    nombre        TEXT    NOT NULL,
    descripcion   TEXT    DEFAULT '',
    duracion_min  INTEGER NOT NULL DEFAULT 30,
    precio        REAL    NOT NULL DEFAULT 0,
    estado        TEXT    NOT NULL DEFAULT 'Activo'
                  CHECK (estado IN ('Activo','Inactivo')),
    imagen        TEXT    DEFAULT '',
    created_at    TEXT    NOT NULL DEFAULT (datetime('now')),
    updated_at    TEXT    NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (id_commerce) REFERENCES commerces(id_commerce) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_services_commerce ON services(id_commerce);
-- Unique (id_commerce, id_local) is created in Database::ensureServicesIdLocal()
-- so existing DBs get the column via ALTER before the index.

-- ---------------------------------------------------------------------
-- 11. NOTIFICATIONS LOG
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notifications_log (
    id_notification INTEGER PRIMARY KEY AUTOINCREMENT,
    id_commerce     INTEGER,
    channel         TEXT    NOT NULL CHECK (channel IN ('email','sms','push','whatsapp')),
    recipient       TEXT    NOT NULL,
    subject         TEXT    DEFAULT '',
    body            TEXT    DEFAULT '',
    related_type    TEXT    DEFAULT '',
    related_id      INTEGER DEFAULT NULL,
    status          TEXT    NOT NULL DEFAULT 'queued'
                    CHECK (status IN ('queued','sent','failed')),
    error_message   TEXT    DEFAULT '',
    sent_at         TEXT    DEFAULT NULL,
    created_at      TEXT    NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (id_commerce) REFERENCES commerces(id_commerce) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_notif_commerce ON notifications_log(id_commerce);
CREATE INDEX IF NOT EXISTS idx_notif_status   ON notifications_log(status);

-- ---------------------------------------------------------------------
-- 12. AUDIT LOG (acciones sensibles)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS audit_log (
    id_audit    INTEGER PRIMARY KEY AUTOINCREMENT,
    id_user     INTEGER,
    action      TEXT    NOT NULL,
    target_type TEXT    DEFAULT '',
    target_id   INTEGER,
    meta        TEXT    DEFAULT '',
    ip          TEXT    DEFAULT '',
    user_agent  TEXT    DEFAULT '',
    created_at  TEXT    NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_audit_user    ON audit_log(id_user);
CREATE INDEX IF NOT EXISTS idx_audit_action  ON audit_log(action);

-- ---------------------------------------------------------------------
-- 13. CSRF TOKENS
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS csrf_tokens (
    id_token    INTEGER PRIMARY KEY AUTOINCREMENT,
    token       TEXT    NOT NULL UNIQUE,
    id_user     INTEGER,
    id_session  TEXT    NOT NULL,
    purpose     TEXT    DEFAULT 'form',
    expires_at  TEXT    NOT NULL,
    created_at  TEXT    NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_csrf_session ON csrf_tokens(id_session);
CREATE INDEX IF NOT EXISTS idx_csrf_expires ON csrf_tokens(expires_at);

-- ---------------------------------------------------------------------
-- 14. SESSIONS (server-side session store, opcional)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sessions (
    id_session   TEXT    PRIMARY KEY,
    id_user      INTEGER,
    payload      TEXT    NOT NULL DEFAULT '{}',
    ip           TEXT    DEFAULT '',
    user_agent   TEXT    DEFAULT '',
    last_seen_at TEXT    NOT NULL DEFAULT (datetime('now')),
    created_at   TEXT    NOT NULL DEFAULT (datetime('now')),
    expires_at   TEXT    NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_sessions_user     ON sessions(id_user);
CREATE INDEX IF NOT EXISTS idx_sessions_expires  ON sessions(expires_at);

-- ---------------------------------------------------------------------
-- 15. PAYMENT PROVIDER CONFIG (datos bancarios del super admin)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS payment_provider_config (
    id_config     INTEGER PRIMARY KEY AUTOINCREMENT,
    provider      TEXT    NOT NULL UNIQUE
                  CHECK (provider IN ('mercadopago','paypal','transfer','smtp','ultramsg','dlocal','google_oauth')),
    is_enabled    INTEGER NOT NULL DEFAULT 0,
    config_json   TEXT    NOT NULL DEFAULT '{}',
    notes         TEXT    DEFAULT '',
    updated_by    INTEGER,
    updated_at    TEXT    NOT NULL DEFAULT (datetime('now'))
);

-- ---------------------------------------------------------------------
-- 16. COMMERCE SETTINGS (config por comercio, JSON por sección)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS commerce_settings (
    id_setting    INTEGER PRIMARY KEY AUTOINCREMENT,
    id_commerce   INTEGER NOT NULL,
    section       TEXT    NOT NULL,
    config_json   TEXT    NOT NULL DEFAULT '{}',
    updated_at    TEXT    NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (id_commerce) REFERENCES commerces(id_commerce) ON DELETE CASCADE,
    UNIQUE (id_commerce, section)
);

CREATE INDEX IF NOT EXISTS idx_commerce_settings_commerce ON commerce_settings(id_commerce);

-- ---------------------------------------------------------------------
-- 17. NOTIFICATION OUTBOX (email / whatsapp programados e idempotentes)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notification_outbox (
    id_outbox        INTEGER PRIMARY KEY AUTOINCREMENT,
    id_commerce      INTEGER,
    channel          TEXT    NOT NULL CHECK (channel IN ('email','whatsapp')),
    recipient        TEXT    NOT NULL,
    template_key     TEXT    NOT NULL,
    subject          TEXT    DEFAULT '',
    body             TEXT    DEFAULT '',
    payload_json     TEXT    NOT NULL DEFAULT '{}',
    scheduled_at     TEXT    NOT NULL,
    idempotency_key  TEXT    NOT NULL UNIQUE,
    status           TEXT    NOT NULL DEFAULT 'queued'
                     CHECK (status IN ('queued','sent','failed','cancelled')),
    attempts         INTEGER NOT NULL DEFAULT 0,
    last_error       TEXT    DEFAULT '',
    sent_at          TEXT    DEFAULT NULL,
    created_at       TEXT    NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (id_commerce) REFERENCES commerces(id_commerce) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_outbox_status_sched ON notification_outbox(status, scheduled_at);
CREATE INDEX IF NOT EXISTS idx_outbox_commerce ON notification_outbox(id_commerce);

-- ---------------------------------------------------------------------
-- 18. PLATFORM SETTINGS (config global del super admin)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS platform_settings (
    id_setting    INTEGER PRIMARY KEY AUTOINCREMENT,
    section       TEXT    NOT NULL UNIQUE,
    config_json   TEXT    NOT NULL DEFAULT '{}',
    updated_at    TEXT    NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_platform_settings_section ON platform_settings(section);
