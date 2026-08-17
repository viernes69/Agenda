<?php
/**
 * Agenduy - Database
 * Wrapper sobre PDO/SQLite con:
 *   - singleton (instancia por proceso)
 *   - migraciones automáticas
 *   - helpers de INSERT/UPDATE/SELECT
 *   - transacciones
 *   - logging de queries lentas (opcional)
 */

declare(strict_types=1);

namespace Agenduy\Core;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;
use Throwable;

final class Database
{
    private static ?Database $instance = null;

    private PDO $pdo;
    private array $config;
    private string $path;

    private function __construct(array $config)
    {
        $this->config = $config;
        $this->path   = $config['db']['path'];

        $this->ensureDirectory();
        $this->pdo = $this->connect();
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

        $this->runMigrations();
    }

    public static function getInstance(?array $config = null): self
    {
        if (self::$instance === null) {
            if ($config === null) {
                $configFile = __DIR__ . DIRECTORY_SEPARATOR . 'config.php';
                $config = require $configFile;
            }
            self::$instance = new self($config);
        }
        return self::$instance;
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    private function ensureDirectory(): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("No se pudo crear el directorio de la base de datos: {$dir}");
        }
    }

    private function connect(): PDO
    {
        try {
            return new PDO('sqlite:' . $this->path);
        } catch (PDOException $e) {
            throw new RuntimeException('No se pudo abrir la base de datos SQLite: ' . $e->getMessage());
        }
    }

    private function runMigrations(): void
    {
        $schemaFile = __DIR__ . DIRECTORY_SEPARATOR . 'db' . DIRECTORY_SEPARATOR . 'schema.sql';
        if (!is_file($schemaFile)) {
            throw new RuntimeException("Falta el schema: {$schemaFile}");
        }
        $sql = (string) file_get_contents($schemaFile);

        // SQLite ejecuta un script completo con exec().
        $this->pdo->exec($sql);

        // Parches incrementales (CREATE IF NOT EXISTS no altera tablas existentes)
        $this->ensureSchemaPatches();

        // Llave de encriptación: si no está en env ni en archivo, autogenerar y guardar
        $this->ensureEncryptionKey();
    }

    private function ensureSchemaPatches(): void
    {
        $cols = $this->pdo->query('PRAGMA table_info(rubros)')->fetchAll(PDO::FETCH_ASSOC);
        $names = array_column($cols, 'name');
        if (!in_array('orden', $names, true)) {
            $this->pdo->exec('ALTER TABLE rubros ADD COLUMN orden INTEGER NOT NULL DEFAULT 0');
            $ids = $this->pdo->query('SELECT id_rubro FROM rubros ORDER BY nombre COLLATE NOCASE')->fetchAll(PDO::FETCH_COLUMN);
            $stmt = $this->pdo->prepare('UPDATE rubros SET orden = ? WHERE id_rubro = ?');
            $orden = 10;
            foreach ($ids as $id) {
                $stmt->execute([$orden, (int)$id]);
                $orden += 10;
            }
        }

        $this->ensureDefaultRubros();
        $this->ensureMembershipPlanColumns();
        $this->ensureSubscriptionBillingPeriod();
        $this->ensureServicesIdLocal();
        $this->ensureAppointmentColumns();
        $this->ensureAppointmentStatusInProgress();
        $this->seedMembershipPlanDefaults();
        $this->retireLegacySeedMembership();
        $this->ensureDlocalEnums();
        $this->ensurePaymentProviderGoogleOauth();
        $this->ensureOAuthAuth();
        $this->ensureRateLimitsTable();
        $this->ensurePlatformSettingsTable();
        $this->ensureStoreOrderPaymentsTable();
        $this->ensureAppointmentPaymentsTable();
        $this->ensureSuperAdminUser();
        $this->ensurePaymentProviderDefaults();
    }

    /**
     * Keeps a fresh/partial production DB from rendering the one-card landing fallback.
     * Existing customized rubros are left intact, except when none are active.
     */
    private function ensureDefaultRubros(): void
    {
        $defaults = [
            ['Abogacía', 'abogados', 'Servicios legales y asesoramiento', 'src/media/carousel/abogados.jpg', 10],
            ['Barbería', 'barberia', 'Barberías y peluquerías', 'src/media/carousel/barberias.jpg', 20],
            ['Belleza y estética', 'belleza', 'Salones y spas', 'src/media/carousel/clinicas_estetica.jpg', 30],
            ['Clínica de Estética', 'estetica', 'Servicios de belleza y cuidado personal', 'src/media/carousel/clinicas_estetica.jpg', 40],
            ['Coaching', 'coaches', 'Coaching personal y profesional', 'src/media/carousel/coaches.jpg', 50],
            ['Consultorios', 'consultorios', 'Servicios médicos y de salud', 'src/media/carousel/consultorios.jpg', 60],
            ['Dentistas', 'dentistas', 'Servicios odontológicos y cuidado dental', 'src/media/carousel/dentistas.jpg', 70],
            ['Emprendedores', 'emprendedores', 'Asesoría para emprendedores', 'src/media/carousel/emprendedores.jpg', 80],
            ['Lavaderos', 'lavaderos', 'Servicios de lavado y limpieza de vehículos', 'src/media/carousel/lavaderos.jpg', 90],
            ['Locales de Eventos', 'eventos', 'Espacios para eventos y celebraciones', 'src/media/carousel/fiestas_eventos.jpg', 100],
            ['Odontología', 'odontologia', 'Consultorios dentales', 'src/media/carousel/dentistas.jpg', 110],
            ['Profesores Particulares', 'profesores', 'Clases y tutorías personalizadas', 'src/media/carousel/profesionales.jpg', 120],
            ['Tienda', 'tienda', 'Tiendas y retail con agenda de atención', 'src/media/carousel/emprendedores.jpg', 130],
        ];

        $activeCount = (int)$this->pdo->query('SELECT COUNT(*) FROM rubros WHERE activo = 1')->fetchColumn();
        $reactivateDefaults = $activeCount === 0;

        $select = $this->pdo->prepare(
            'SELECT id_rubro, nombre, descripcion, imagen, activo, orden FROM rubros WHERE tipo = ?'
        );
        $insert = $this->pdo->prepare(
            "INSERT INTO rubros (nombre, tipo, descripcion, imagen, activo, orden, created_at, updated_at)
             VALUES (?, ?, ?, ?, 1, ?, datetime('now'), datetime('now'))"
        );

        foreach ($defaults as [$nombre, $tipo, $descripcion, $imagen, $orden]) {
            $select->execute([$tipo]);
            $row = $select->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                $insert->execute([$nombre, $tipo, $descripcion, $imagen, $orden]);
                continue;
            }

            $sets = [];
            $params = [];
            if (trim((string)($row['nombre'] ?? '')) === '') {
                $sets[] = 'nombre = ?';
                $params[] = $nombre;
            }
            if (trim((string)($row['descripcion'] ?? '')) === '') {
                $sets[] = 'descripcion = ?';
                $params[] = $descripcion;
            }
            if (trim((string)($row['imagen'] ?? '')) === '') {
                $sets[] = 'imagen = ?';
                $params[] = $imagen;
            }
            if ((int)($row['orden'] ?? 0) === 0) {
                $sets[] = 'orden = ?';
                $params[] = $orden;
            }
            if ($reactivateDefaults && (int)($row['activo'] ?? 0) !== 1) {
                $sets[] = 'activo = 1';
            }
            if ($sets === []) {
                continue;
            }

            $sets[] = "updated_at = datetime('now')";
            $params[] = (int)$row['id_rubro'];
            $sql = 'UPDATE rubros SET ' . implode(', ', $sets) . ' WHERE id_rubro = ?';
            $this->pdo->prepare($sql)->execute($params);
        }
    }

    private function ensurePlatformSettingsTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS platform_settings (
                id_setting    INTEGER PRIMARY KEY AUTOINCREMENT,
                section       TEXT    NOT NULL UNIQUE,
                config_json   TEXT    NOT NULL DEFAULT \'{}\',
                updated_at    TEXT    NOT NULL DEFAULT (datetime(\'now\'))
            )'
        );
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_platform_settings_section ON platform_settings(section)');
    }

    private function ensureStoreOrderPaymentsTable(): void
    {
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS store_order_payments (
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
            )"
        );
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_store_payments_commerce ON store_order_payments(id_commerce)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_store_payments_status ON store_order_payments(status)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_store_payments_payment ON store_order_payments(payment_id)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_store_payments_preference ON store_order_payments(preference_id)');
    }

    private function ensureSuperAdminUser(): void
    {
        try {
            $email = 'admin@agendarte.uy';
            $stmt = $this->pdo->prepare('SELECT id_user FROM users WHERE email = ? OR email = "admin@agenduy.uy" LIMIT 1');
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            $pwd  = 'Agendarte2026!';
            $hash = password_hash($pwd, PASSWORD_BCRYPT, ['cost' => 12]);

            if ($user) {
                $update = $this->pdo->prepare(
                    "UPDATE users SET email = ?, role = 'super_admin', password_hash = ?, activo = 1, failed_attempts = 0, locked_until = NULL, updated_at = datetime('now') WHERE id_user = ?"
                );
                $update->execute([$email, $hash, (int)$user['id_user']]);
            } else {
                $insert = $this->pdo->prepare(
                    "INSERT INTO users (role, id_commerce, nombre, apellido, cedula, email, telefono, whatsapp, password_hash, activo, failed_attempts, locked_until, created_at, updated_at)
                     VALUES ('super_admin', NULL, 'Administrador', 'Agendarte', '', ?, '', '', ?, 1, 0, NULL, datetime('now'), datetime('now'))"
                );
                $insert->execute([$email, $hash]);
            }
        } catch (Throwable $e) {
            // Ignore error if users table is not initialized yet
        }
    }

    private function ensurePaymentProviderDefaults(): void
    {
        try {
            $providers = ['paypal', 'mercadopago', 'transfer', 'smtp', 'ultramsg', 'google_oauth'];
            $check = $this->pdo->prepare('SELECT COUNT(*) FROM payment_provider_config WHERE provider = ?');
            $insert = $this->pdo->prepare("INSERT INTO payment_provider_config (provider, is_enabled, config_json, notes, created_at, updated_at) VALUES (?, 0, '{}', '', datetime('now'), datetime('now'))");
            foreach ($providers as $p) {
                $check->execute([$p]);
                if ((int)$check->fetchColumn() === 0) {
                    $insert->execute([$p]);
                }
            }
        } catch (Throwable $e) {
            // Table might not exist yet
        }
    }

    private function ensureAppointmentPaymentsTable(): void
    {
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS appointment_payments (
                id_appointment_payment INTEGER PRIMARY KEY AUTOINCREMENT,
                id_commerce       INTEGER NOT NULL,
                slug              TEXT    NOT NULL,
                id_appointment    INTEGER NOT NULL,
                local_reservation_id INTEGER DEFAULT NULL,
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
                checkout_url      TEXT    DEFAULT '',
                expires_at        TEXT    DEFAULT '',
                created_at        TEXT    NOT NULL DEFAULT (datetime('now')),
                updated_at        TEXT    NOT NULL DEFAULT (datetime('now')),
                FOREIGN KEY (id_commerce) REFERENCES commerces(id_commerce) ON DELETE CASCADE,
                FOREIGN KEY (id_appointment) REFERENCES appointments(id_appointment) ON DELETE CASCADE
            )"
        );
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_appt_payments_commerce ON appointment_payments(id_commerce)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_appt_payments_appt ON appointment_payments(id_appointment)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_appt_payments_status ON appointment_payments(status)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_appt_payments_payment ON appointment_payments(payment_id)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_appt_payments_preference ON appointment_payments(preference_id)');
    }

    private function ensureRateLimitsTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS rate_limits (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                bucket TEXT NOT NULL UNIQUE,
                hits INTEGER NOT NULL DEFAULT 1,
                window_start INTEGER NOT NULL,
                updated_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
            )'
        );
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_rate_limits_window ON rate_limits(window_start)');
    }

    private function ensureOAuthAuth(): void
    {
        $cols = $this->pdo->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_ASSOC);
        $names = array_column($cols, 'name');
        if (!in_array('google_id', $names, true)) {
            $this->pdo->exec('ALTER TABLE users ADD COLUMN google_id TEXT DEFAULT NULL');
        }
        if (!in_array('auth_provider', $names, true)) {
            $this->pdo->exec("ALTER TABLE users ADD COLUMN auth_provider TEXT NOT NULL DEFAULT 'password'");
        }
        $this->pdo->exec(
            'CREATE UNIQUE INDEX IF NOT EXISTS idx_users_google_id ON users(google_id) WHERE google_id IS NOT NULL'
        );

        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS auth_tokens (
                id_token INTEGER PRIMARY KEY AUTOINCREMENT,
                email TEXT NOT NULL,
                token_hash TEXT NOT NULL UNIQUE,
                purpose TEXT NOT NULL DEFAULT \'admin_login\',
                id_commerce INTEGER DEFAULT NULL,
                meta_json TEXT DEFAULT \'{}\',
                expires_at TEXT NOT NULL,
                used_at TEXT DEFAULT NULL,
                ip TEXT DEFAULT \'\',
                created_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
                FOREIGN KEY (id_commerce) REFERENCES commerces(id_commerce) ON DELETE CASCADE
            )'
        );
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_auth_tokens_email ON auth_tokens(email)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_auth_tokens_expires ON auth_tokens(expires_at)');
    }

    /**
     * Agrega 'dlocal' a los CHECK constraints de provider/gateway en SQLite.
     * SQLite no permite ALTER CHECK, asi que detecta si la tabla ya incluye 'dlocal'
     * en su definicion y, si no, la recrea copiando los datos.
     */
    private function ensureDlocalEnums(): void
    {
        $this->ensureCheckContains(
            'subscriptions',
            'dlocal',
            "CREATE TABLE IF NOT EXISTS subscriptions_new (
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
            )",
            "INSERT INTO subscriptions_new
                (id_subscription, id_commerce, id_membership, status, gateway, gateway_id,
                 started_at, trial_expires_at, current_period_start, current_period_end,
                 cancelled_at, billing_period, notes, created_at, updated_at)
             SELECT
                id_subscription, id_commerce, id_membership, status, gateway, gateway_id,
                started_at, trial_expires_at, current_period_start, current_period_end,
                cancelled_at, COALESCE(billing_period, 'monthly'), COALESCE(notes, ''),
                created_at, updated_at
             FROM subscriptions"
        );

        $this->ensureCheckContains(
            'payment_provider_config',
            'dlocal',
            "CREATE TABLE IF NOT EXISTS payment_provider_config_new (
                id_config     INTEGER PRIMARY KEY AUTOINCREMENT,
                provider      TEXT    NOT NULL UNIQUE
                              CHECK (provider IN ('mercadopago','paypal','transfer','smtp','ultramsg','dlocal')),
                is_enabled    INTEGER NOT NULL DEFAULT 0,
                config_json   TEXT    NOT NULL DEFAULT '{}',
                notes         TEXT    DEFAULT '',
                updated_by    INTEGER,
                updated_at    TEXT    NOT NULL DEFAULT (datetime('now'))
            )",
            "INSERT INTO payment_provider_config_new
                (id_config, provider, is_enabled, config_json, notes, updated_by, updated_at)
             SELECT
                id_config, provider, is_enabled,
                COALESCE(config_json, '{}'), COALESCE(notes, ''), updated_by, updated_at
             FROM payment_provider_config"
        );

        $this->ensureCheckContains(
            'api_keys',
            'dlocal',
            "CREATE TABLE IF NOT EXISTS api_keys_new (
                id_key        INTEGER PRIMARY KEY AUTOINCREMENT,
                id_commerce   INTEGER DEFAULT NULL,
                provider      TEXT    NOT NULL
                              CHECK (provider IN ('mercadopago','paypal','google_calendar',
                                                  'google_service_account','smtp','ultramsg','generic','dlocal')),
                key_name      TEXT    NOT NULL,
                key_value     TEXT    NOT NULL,
                key_preview   TEXT    NOT NULL,
                label         TEXT    DEFAULT '',
                is_active     INTEGER NOT NULL DEFAULT 1,
                created_at    TEXT    NOT NULL DEFAULT (datetime('now')),
                updated_at    TEXT    NOT NULL DEFAULT (datetime('now')),
                FOREIGN KEY (id_commerce) REFERENCES commerces(id_commerce) ON DELETE CASCADE
            )",
            "INSERT INTO api_keys_new
                (id_key, id_commerce, provider, key_name, key_value, key_preview,
                 label, is_active, created_at, updated_at)
             SELECT
                id_key, id_commerce, provider, key_name, key_value, key_preview,
                COALESCE(label, ''), is_active, created_at, updated_at
             FROM api_keys"
        );
    }

    /**
     * Permite guardar provider google_oauth en payment_provider_config.
     */
    private function ensurePaymentProviderGoogleOauth(): void
    {
        $this->ensureCheckContains(
            'payment_provider_config',
            'google_oauth',
            "CREATE TABLE IF NOT EXISTS payment_provider_config_new (
                id_config     INTEGER PRIMARY KEY AUTOINCREMENT,
                provider      TEXT    NOT NULL UNIQUE
                              CHECK (provider IN ('mercadopago','paypal','transfer','smtp','ultramsg','dlocal','google_oauth')),
                is_enabled    INTEGER NOT NULL DEFAULT 0,
                config_json   TEXT    NOT NULL DEFAULT '{}',
                notes         TEXT    DEFAULT '',
                updated_by    INTEGER,
                updated_at    TEXT    NOT NULL DEFAULT (datetime('now'))
            )",
            "INSERT INTO payment_provider_config_new
                (id_config, provider, is_enabled, config_json, notes, updated_by, updated_at)
             SELECT
                id_config, provider, is_enabled,
                COALESCE(config_json, '{}'), COALESCE(notes, ''), updated_by, updated_at
             FROM payment_provider_config"
        );
    }

    private function ensureAppointmentColumns(): void
    {
        $cols = $this->pdo->query('PRAGMA table_info(appointments)')->fetchAll(PDO::FETCH_ASSOC);
        $names = array_column($cols, 'name');
        if (!in_array('cliente_cedula', $names, true)) {
            $this->pdo->exec("ALTER TABLE appointments ADD COLUMN cliente_cedula TEXT DEFAULT ''");
        }
    }

    private function ensureAppointmentStatusInProgress(): void
    {
        $this->ensureCheckContains(
            'appointments',
            'in_progress',
            "CREATE TABLE IF NOT EXISTS appointments_new (
                id_appointment   INTEGER PRIMARY KEY AUTOINCREMENT,
                id_commerce      INTEGER NOT NULL,
                id_client        INTEGER,
                id_service       INTEGER,
                id_user_admin    INTEGER,
                fecha            TEXT    NOT NULL,
                hora_inicio      TEXT    NOT NULL,
                hora_fin         TEXT    DEFAULT '',
                cliente_nombre   TEXT    DEFAULT '',
                cliente_cedula   TEXT    DEFAULT '',
                cliente_email    TEXT    DEFAULT '',
                cliente_telefono TEXT    DEFAULT '',
                notas            TEXT    DEFAULT '',
                precio           REAL    DEFAULT 0,
                status           TEXT    NOT NULL DEFAULT 'pending'
                                 CHECK (status IN ('pending','confirmed','in_progress','cancelled','done','no_show')),
                google_event_id  TEXT    DEFAULT '',
                email_sent_to_client    INTEGER NOT NULL DEFAULT 0,
                email_sent_to_commerce  INTEGER NOT NULL DEFAULT 0,
                created_at       TEXT    NOT NULL DEFAULT (datetime('now')),
                updated_at       TEXT    NOT NULL DEFAULT (datetime('now')),
                FOREIGN KEY (id_commerce)   REFERENCES commerces(id_commerce) ON DELETE CASCADE,
                FOREIGN KEY (id_client)     REFERENCES clients(id_client) ON DELETE SET NULL,
                FOREIGN KEY (id_service)    REFERENCES services(id_service) ON DELETE SET NULL,
                FOREIGN KEY (id_user_admin) REFERENCES users(id_user) ON DELETE SET NULL
            )",
            "INSERT INTO appointments_new
                (id_appointment, id_commerce, id_client, id_service, id_user_admin,
                 fecha, hora_inicio, hora_fin, cliente_nombre, cliente_cedula,
                 cliente_email, cliente_telefono, notas, precio, status,
                 google_event_id, email_sent_to_client, email_sent_to_commerce,
                 created_at, updated_at)
             SELECT
                id_appointment, id_commerce, id_client, id_service, id_user_admin,
                fecha, hora_inicio, COALESCE(hora_fin, ''), COALESCE(cliente_nombre, ''),
                COALESCE(cliente_cedula, ''), COALESCE(cliente_email, ''),
                COALESCE(cliente_telefono, ''), COALESCE(notas, ''), COALESCE(precio, 0),
                status, COALESCE(google_event_id, ''), COALESCE(email_sent_to_client, 0),
                COALESCE(email_sent_to_commerce, 0), created_at, updated_at
             FROM appointments"
        );
    }

    /**
     * Si la definicion de la tabla no contiene $needle en su CHECK, la recrea
     * copiando los datos con un INSERT selectivo que sanea NULLs.
     */
    private function ensureCheckContains(string $table, string $needle, string $createNewSql, string $copySql): void
    {
        $sql = $this->pdo->query(
            "SELECT sql FROM sqlite_master WHERE type='table' AND name=" . $this->pdo->quote($table)
        )->fetchColumn();
        if (!is_string($sql) || $sql === '') {
            return;
        }
        if (strpos($sql, "'" . $needle . "'") !== false) {
            return;
        }
        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec($createNewSql);
            $this->pdo->exec($copySql);
            $this->pdo->exec("DROP TABLE {$table}");
            $this->pdo->exec("ALTER TABLE {$table}_new RENAME TO {$table}");
            if ($table === 'subscriptions') {
                $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_subs_commerce ON subscriptions(id_commerce)');
                $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_subs_status   ON subscriptions(status)');
            }
            if ($table === 'api_keys') {
                $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_keys_commerce  ON api_keys(id_commerce)');
                $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_keys_provider  ON api_keys(provider)');
                $this->pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_keys_unique ON api_keys(IFNULL(id_commerce,0), provider, key_name)');
            }
            if ($table === 'appointments') {
                $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_appt_commerce ON appointments(id_commerce)');
                $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_appt_fecha    ON appointments(fecha)');
                $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_appt_status   ON appointments(status)');
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            error_log('[Database::ensureCheckContains] ' . $table . '/' . $needle . ': ' . $e->getMessage());
        }
    }

    /**
     * Maps tenant database.php ID_Servicio → central services row.
     * Prevents duplicate service names from overwriting each other on sync.
     */
    private function ensureServicesIdLocal(): void
    {
        $cols = $this->pdo->query('PRAGMA table_info(services)')->fetchAll(PDO::FETCH_ASSOC);
        $names = array_column($cols, 'name');
        if (!in_array('id_local', $names, true)) {
            $this->pdo->exec('ALTER TABLE services ADD COLUMN id_local INTEGER DEFAULT NULL');
        }
        $this->pdo->exec(
            'CREATE UNIQUE INDEX IF NOT EXISTS idx_services_commerce_local
             ON services(id_commerce, id_local)
             WHERE id_local IS NOT NULL'
        );
    }

    /**
     * Old seed created "Plan Básico" at $800. Modern catalog is Free / Básico / Profesional.
     * Soft-deactivate the legacy row so it no longer appears in the membership modal grid.
     */
    private function retireLegacySeedMembership(): void
    {
        $hasModern = $this->pdo->query(
            "SELECT 1 FROM memberships WHERE activo = 1 AND nombre IN ('Free', 'Básico', 'Profesional') LIMIT 1"
        )->fetchColumn();
        if (!$hasModern) {
            return;
        }
        $this->pdo->exec(
            "UPDATE memberships
             SET activo = 0, updated_at = datetime('now')
             WHERE activo = 1
               AND nombre = 'Plan Básico'
               AND ABS(precio - 800) < 0.01"
        );
    }

    private function ensureMembershipPlanColumns(): void
    {
        $cols = $this->pdo->query('PRAGMA table_info(memberships)')->fetchAll(PDO::FETCH_ASSOC);
        $names = array_column($cols, 'name');
        $add = [
            'descripcion' => "ALTER TABLE memberships ADD COLUMN descripcion TEXT DEFAULT ''",
            'moneda' => "ALTER TABLE memberships ADD COLUMN moneda TEXT NOT NULL DEFAULT 'UYU'",
            'duracion_dias' => 'ALTER TABLE memberships ADD COLUMN duracion_dias INTEGER NOT NULL DEFAULT 30',
            'trial_dias' => 'ALTER TABLE memberships ADD COLUMN trial_dias INTEGER NOT NULL DEFAULT 30',
            'mp_preapproval_id' => 'ALTER TABLE memberships ADD COLUMN mp_preapproval_id TEXT DEFAULT NULL',
            'paypal_plan_id' => 'ALTER TABLE memberships ADD COLUMN paypal_plan_id TEXT DEFAULT NULL',
            'activo' => 'ALTER TABLE memberships ADD COLUMN activo INTEGER NOT NULL DEFAULT 1',
            'created_at' => "ALTER TABLE memberships ADD COLUMN created_at TEXT DEFAULT ''",
            'updated_at' => "ALTER TABLE memberships ADD COLUMN updated_at TEXT DEFAULT ''",
            'features' => "ALTER TABLE memberships ADD COLUMN features TEXT DEFAULT '[]'",
            'limits' => "ALTER TABLE memberships ADD COLUMN limits TEXT DEFAULT '{}'",
            'precio_anual' => 'ALTER TABLE memberships ADD COLUMN precio_anual REAL DEFAULT NULL',
            'descuento_anual_pct' => 'ALTER TABLE memberships ADD COLUMN descuento_anual_pct REAL NOT NULL DEFAULT 0',
            'anual_habilitado' => 'ALTER TABLE memberships ADD COLUMN anual_habilitado INTEGER NOT NULL DEFAULT 0',
        ];
        foreach ($add as $col => $sql) {
            if (!in_array($col, $names, true)) {
                $this->pdo->exec($sql);
            }
        }
        $this->pdo->exec("UPDATE memberships SET created_at = datetime('now') WHERE created_at IS NULL OR created_at = ''");
        $this->pdo->exec("UPDATE memberships SET updated_at = datetime('now') WHERE updated_at IS NULL OR updated_at = ''");
    }

    private function ensureSubscriptionBillingPeriod(): void
    {
        $cols = $this->pdo->query('PRAGMA table_info(subscriptions)')->fetchAll(PDO::FETCH_ASSOC);
        $names = array_column($cols, 'name');
        if (!in_array('billing_period', $names, true)) {
            $this->pdo->exec(
                "ALTER TABLE subscriptions ADD COLUMN billing_period TEXT NOT NULL DEFAULT 'monthly'"
            );
        }
    }

    /**
     * Soft-fill / upgrade features+limits for Free/Básico/Profesional.
     * Upgrades when limits lack settings_tier (new catalog). Does not overwrite prices.
     */
    private function seedMembershipPlanDefaults(): void
    {
        $defaults = MembershipPlan::catalogDefaults();
        $prices = ['Free' => 0.0, 'Básico' => 299.0, 'Profesional' => 599.0];
        $trials = ['Free' => 30, 'Básico' => 0, 'Profesional' => 0];
        $hasActiveModern = (int)$this->pdo->query(
            "SELECT COUNT(*) FROM memberships WHERE activo = 1 AND nombre IN ('Free', 'Básico', 'Profesional')"
        )->fetchColumn() > 0;

        $selectByName = $this->pdo->prepare(
            'SELECT id_membership, activo FROM memberships WHERE nombre = ? ORDER BY id_membership ASC LIMIT 1'
        );
        $insertDefault = $this->pdo->prepare(
            "INSERT INTO memberships
                (nombre, descripcion, precio, moneda, duracion_dias, trial_dias, activo,
                 features, limits, anual_habilitado, descuento_anual_pct, created_at, updated_at)
             VALUES (?, ?, ?, 'UYU', 30, ?, 1, ?, ?, ?, ?, datetime('now'), datetime('now'))"
        );
        $reactivateDefault = $this->pdo->prepare(
            "UPDATE memberships SET activo = 1, updated_at = datetime('now') WHERE id_membership = ?"
        );

        foreach ($defaults as $name => $def) {
            $selectByName->execute([$name]);
            $row = $selectByName->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                $insertDefault->execute([
                    $name,
                    (string)($def['descripcion'] ?? ''),
                    (float)($prices[$name] ?? 0),
                    (int)($trials[$name] ?? 0),
                    json_encode($def['features'], JSON_UNESCAPED_UNICODE) ?: '[]',
                    json_encode($def['limits'], JSON_UNESCAPED_UNICODE) ?: '{}',
                    (int)($def['anual_habilitado'] ?? 0),
                    (float)($def['descuento_anual_pct'] ?? 0),
                ]);
                continue;
            }
            if (!$hasActiveModern && (int)($row['activo'] ?? 0) !== 1) {
                $reactivateDefault->execute([(int)$row['id_membership']]);
            }
        }

        $rows = $this->pdo->query(
            'SELECT id_membership, nombre, descripcion, features, limits, anual_habilitado, descuento_anual_pct FROM memberships'
        )->fetchAll(PDO::FETCH_ASSOC);
        $stmt = $this->pdo->prepare(
            'UPDATE memberships SET descripcion = ?, features = ?, limits = ?, anual_habilitado = ?, descuento_anual_pct = ?, updated_at = datetime(\'now\')
             WHERE id_membership = ?'
        );

        foreach ($rows as $row) {
            $name = (string)($row['nombre'] ?? '');
            if (!isset($defaults[$name])) {
                continue;
            }
            $def = $defaults[$name];
            $featuresRaw = trim((string)($row['features'] ?? ''));
            $limitsRaw = trim((string)($row['limits'] ?? ''));
            $featuresEmpty = $featuresRaw === '' || $featuresRaw === '[]' || $featuresRaw === 'null';
            $limitsEmpty = $limitsRaw === '' || $limitsRaw === '{}' || $limitsRaw === 'null';
            $decodedLimits = json_decode($limitsRaw, true);
            $limitsNeedUpgrade = $limitsEmpty
                || !is_array($decodedLimits)
                || !array_key_exists(MembershipPlan::LIMIT_SETTINGS_TIER, $decodedLimits);
            // Free/Básico must carry max_professionals + max_clients; Profesional refreshes marketing when peers upgrade.
            if (!$limitsNeedUpgrade && is_array($decodedLimits)) {
                if ($name === 'Free' || $name === 'Básico') {
                    $limitsNeedUpgrade = !array_key_exists(
                        MembershipPlan::LIMIT_MAX_PROFESSIONALS,
                        $decodedLimits
                    ) || !array_key_exists(
                        MembershipPlan::LIMIT_MAX_CLIENTS,
                        $decodedLimits
                    );
                    if (!$limitsNeedUpgrade && $name === 'Free') {
                        $defaultMaxProfessionals = $def['limits'][MembershipPlan::LIMIT_MAX_PROFESSIONALS] ?? null;
                        $currentMaxProfessionals = $decodedLimits[MembershipPlan::LIMIT_MAX_PROFESSIONALS] ?? null;
                        $limitsNeedUpgrade = $defaultMaxProfessionals !== null
                            && (string)$currentMaxProfessionals !== (string)$defaultMaxProfessionals;
                        if (!$limitsNeedUpgrade) {
                            $defaultMaxProducts = $def['limits'][MembershipPlan::LIMIT_MAX_PRODUCTS] ?? null;
                            $currentMaxProducts = $decodedLimits[MembershipPlan::LIMIT_MAX_PRODUCTS] ?? null;
                            $limitsNeedUpgrade = $defaultMaxProducts !== null
                                && (string)$currentMaxProducts !== (string)$defaultMaxProducts;
                        }
                    }
                } elseif ($name === 'Profesional') {
                    $featRaw = (string)($row['features'] ?? '');
                    $limitsNeedUpgrade = stripos($featRaw, 'Profesionales ilimitados') === false
                        || stripos($featRaw, 'Clientes') === false;
                }
            }
            if (!$featuresEmpty && !$limitsNeedUpgrade) {
                continue;
            }
            $features = ($featuresEmpty || $limitsNeedUpgrade)
                ? json_encode($def['features'], JSON_UNESCAPED_UNICODE)
                : $featuresRaw;
            $limits = $limitsNeedUpgrade
                ? json_encode($def['limits'], JSON_UNESCAPED_UNICODE)
                : $limitsRaw;
            $descripcion = trim((string)($row['descripcion'] ?? ''));
            if ($descripcion === '' || $limitsNeedUpgrade) {
                $descripcion = (string)($def['descripcion'] ?? $descripcion);
            }
            $anual = (int)($row['anual_habilitado'] ?? 0);
            $discount = (float)($row['descuento_anual_pct'] ?? 0);
            if ($featuresEmpty || $limitsNeedUpgrade) {
                if ($anual === 0 && $discount <= 0 && !empty($def['anual_habilitado'])) {
                    $anual = (int)$def['anual_habilitado'];
                    $discount = (float)$def['descuento_anual_pct'];
                }
            }
            $stmt->execute([$descripcion, $features, $limits, $anual, $discount, (int)$row['id_membership']]);
        }
    }

    private function ensureEncryptionKey(): void
    {
        $key = (string)($this->config['security']['encryption_key'] ?? '');
        if ($key !== '') {
            return;
        }
        $keyFile = dirname($this->path) . DIRECTORY_SEPARATOR . '.app_key';
        if (is_file($keyFile)) {
            $stored = trim((string)file_get_contents($keyFile));
            if ($stored !== '' && strlen($stored) >= 32) {
                $this->config['security']['encryption_key'] = $stored;
                return;
            }
        }
        $new = bin2hex(random_bytes(32));
        // 0640 para que solo el dueño del proceso pueda leerla
        @file_put_contents($keyFile, $new);
        @chmod($keyFile, 0640);
        $this->config['security']['encryption_key'] = $new;
    }

    public function config(): array
    {
        return $this->config;
    }

    // ===== Helpers =====

    public function fetchAll(string $sql, array $params = []): array
    {
        $stmt = $this->execute($sql, $params);
        return $stmt->fetchAll();
    }

    public function fetchOne(string $sql, array $params = []): ?array
    {
        $stmt = $this->execute($sql, $params);
        $row  = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function fetchValue(string $sql, array $params = [])
    {
        $stmt = $this->execute($sql, $params);
        $val  = $stmt->fetchColumn();
        return $val === false ? null : $val;
    }

    public function insert(string $table, array $data): int
    {
        $cols      = array_keys($data);
        $placeholders = array_map(fn($c) => ':' . $c, $cols);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->quoteIdent($table),
            implode(',', array_map([$this, 'quoteIdent'], $cols)),
            implode(',', $placeholders)
        );
        $params = [];
        foreach ($data as $k => $v) {
            $params[':' . $k] = $v;
        }
        $this->execute($sql, $params);
        return (int)$this->pdo->lastInsertId();
    }

    public function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $set = [];
        $params = [];
        foreach ($data as $col => $val) {
            $set[] = $this->quoteIdent($col) . ' = :set_' . $col;
            $params[':set_' . $col] = $val;
        }
        foreach ($whereParams as $k => $v) {
            $params[':' . ltrim($k, ':')] = $v;
        }
        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s',
            $this->quoteIdent($table),
            implode(', ', $set),
            $where
        );
        return $this->execute($sql, $params)->rowCount();
    }

    public function delete(string $table, string $where, array $params = []): int
    {
        $sql = sprintf('DELETE FROM %s WHERE %s', $this->quoteIdent($table), $where);
        return $this->execute($sql, $params)->rowCount();
    }

    public function transaction(callable $fn)
    {
        $this->pdo->beginTransaction();
        try {
            $result = $fn($this);
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    private function execute(string $sql, array $params): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    private function quoteIdent(string $name): string
    {
        // SQLite acepta comillas dobles para identificadores. Reemplaza cualquier " interna.
        return '"' . str_replace('"', '""', $name) . '"';
    }

    public function tableExists(string $table): bool
    {
        $row = $this->fetchOne(
            "SELECT name FROM sqlite_master WHERE type='table' AND name = :t",
            [':t' => $table]
        );
        return $row !== null;
    }
}
