<?php
/**
 * Helpers for membership plans: features, limits, annual pricing.
 */
declare(strict_types=1);

namespace Agenduy\Core;

final class MembershipPlan
{
    /** Known limit keys (extend only when enforced in code). */
    public const LIMIT_MAX_PRODUCTS = 'max_products';
    public const LIMIT_MAX_SERVICES = 'max_services';
    public const LIMIT_MAX_APPOINTMENTS_MONTH = 'max_appointments_month';
    public const LIMIT_MAX_PROFESSIONALS = 'max_professionals';
    public const LIMIT_MAX_CLIENTS = 'max_clients';
    public const LIMIT_SETTINGS_TIER = 'settings_tier';

    public const SETTINGS_TIER_BASIC = 'basic';
    public const SETTINGS_TIER_FULL = 'full';

    public const CODE_PLAN_DENIED = 'PLAN_LIMIT';

    /** Human message when an action exceeds the plan (commerce admin / dashboard). */
    public const DENIAL_MESSAGE = 'Tu plan no permite realizar esta acción.';

    /**
     * Legacy public message when create was blocked at quota.
     * Create no longer blocks; kept for soft copy / tests. Prefer waitlist on create.
     */
    public const PUBLIC_APPOINTMENTS_MONTH_FULL_MESSAGE =
        'Este comercio no puede recibir más reservas este mes. Probá más adelante o contactá al comercio.';

    /**
     * Commerce admin message when Atender/Finalizar exceeds monthly paid quota.
     */
    public const APPOINTMENTS_MONTH_PROCESS_DENIED_MESSAGE =
        'Alcanzaste el límite de reservas del mes en tu plan. Mejorá tu membresía para atender o finalizar esta reserva.';

    /** Demo/seed rows must not consume the commerce monthly quota. */
    public const APPOINTMENT_NOTA_BADGE_SEED = 'badge-seed';

    /** Marker in central appointments.notas for over-quota public bookings (waitlist). */
    public const APPOINTMENT_NOTA_PLAN_WAITLIST = 'plan-waitlist';

    /**
     * Config keys allowed when settings_tier=basic.
     * horarios/reservas stay allowed so the Free agenda keeps working.
     *
     * @return list<string>
     */
    public static function basicAllowedConfigKeys(): array
    {
        return ['info_barberia', 'redes', 'horarios', 'reservas'];
    }

    /**
     * Nested keys inside info_barberia that basic plans cannot save.
     *
     * @return list<string>
     */
    public static function basicBlockedInfoSections(): array
    {
        return [
            'fiscal',
            'seo',
            'mercadopago',
            'legales',
            'legal',
            'notificaciones',
            'features',
            'funciones',
            'moneda',
            'temas',
            'tema',
        ];
    }

    /**
     * @return list<string>
     */
    public static function features(array $plan): array
    {
        $raw = $plan['features'] ?? '[]';
        if (is_array($raw)) {
            return self::normalizeStringList($raw);
        }
        $decoded = json_decode((string)$raw, true);
        if (is_array($decoded)) {
            return self::normalizeStringList($decoded);
        }
        return [];
    }

    /**
     * Parse features from admin textarea (one item per line).
     *
     * @return list<string>
     */
    public static function featuresFromText(string $text): array
    {
        $lines = preg_split('/\R/u', $text) ?: [];
        return self::normalizeStringList($lines);
    }

    public static function featuresToText(array $plan): string
    {
        return implode("\n", self::features($plan));
    }

    public static function featuresToJson(array $features): string
    {
        return json_encode(array_values($features), JSON_UNESCAPED_UNICODE) ?: '[]';
    }

    /**
     * @return array<string, mixed>
     */
    public static function limits(array $plan): array
    {
        $raw = $plan['limits'] ?? '{}';
        if (is_array($raw)) {
            return $raw;
        }
        $decoded = json_decode((string)$raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    public static function limitsToJson(array $limits): string
    {
        return json_encode($limits, JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    /**
     * null = unlimited / not configured.
     */
    public static function maxIntLimit(array $plan, string $key): ?int
    {
        $limits = self::limits($plan);
        if (!array_key_exists($key, $limits) || $limits[$key] === null || $limits[$key] === '') {
            return null;
        }
        $n = (int)$limits[$key];
        return $n < 0 ? null : $n;
    }

    /**
     * null = unlimited / not configured.
     */
    public static function maxProducts(array $plan): ?int
    {
        return self::maxIntLimit($plan, self::LIMIT_MAX_PRODUCTS);
    }

    /**
     * null = unlimited / not configured.
     */
    public static function maxServices(array $plan): ?int
    {
        return self::maxIntLimit($plan, self::LIMIT_MAX_SERVICES);
    }

    /**
     * null = unlimited / not configured.
     */
    public static function maxAppointmentsMonth(array $plan): ?int
    {
        return self::maxIntLimit($plan, self::LIMIT_MAX_APPOINTMENTS_MONTH);
    }

    /**
     * Max staff/barberos/funcionarios. null = unlimited / not configured.
     */
    public static function maxProfessionals(array $plan): ?int
    {
        return self::maxIntLimit($plan, self::LIMIT_MAX_PROFESSIONALS);
    }

    /**
     * Max registered clients. null = unlimited / not configured.
     */
    public static function maxClients(array $plan): ?int
    {
        return self::maxIntLimit($plan, self::LIMIT_MAX_CLIENTS);
    }

    public static function settingsTier(array $plan): string
    {
        $tier = strtolower(trim((string)(self::limits($plan)[self::LIMIT_SETTINGS_TIER] ?? self::SETTINGS_TIER_FULL)));
        return $tier === self::SETTINGS_TIER_BASIC
            ? self::SETTINGS_TIER_BASIC
            : self::SETTINGS_TIER_FULL;
    }

    public static function isBasicSettingsOnly(array $plan): bool
    {
        return self::settingsTier($plan) === self::SETTINGS_TIER_BASIC;
    }

    public static function allowsConfigKey(array $plan, string $key): bool
    {
        if (!self::isBasicSettingsOnly($plan)) {
            return true;
        }
        return in_array($key, self::basicAllowedConfigKeys(), true);
    }

    /**
     * Standard API denial payload for plan limits.
     *
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    public static function denialPayload(string $code = self::CODE_PLAN_DENIED, array $extra = []): array
    {
        return array_merge([
            'ok' => false,
            'error' => self::DENIAL_MESSAGE . ' Mejorá tu membresía para continuar.',
            'code' => $code,
            'upgrade_hint' => 'Ver planes',
        ], $extra);
    }

    /**
     * Count central appointments created this calendar month for a commerce.
     * Excludes demo badge-seed rows so seeding does not block real clients.
     */
    public static function countAppointmentsThisMonth(int $commerceId): int
    {
        if ($commerceId <= 0) {
            return 0;
        }
        $start = date('Y-m-01 00:00:00');
        $end = date('Y-m-01 00:00:00', strtotime('first day of next month'));
        $db = Database::getInstance();
        return (int)$db->fetchValue(
            'SELECT COUNT(*) FROM appointments
             WHERE id_commerce = :c
               AND created_at >= :start
               AND created_at < :end
               AND (notas IS NULL OR notas <> :seed)',
            [
                ':c' => $commerceId,
                ':start' => $start,
                ':end' => $end,
                ':seed' => self::APPOINTMENT_NOTA_BADGE_SEED,
            ]
        );
    }

    /**
     * Legacy denial payload when public create was blocked at quota.
     * Prefer allowing create as waitlist; use processDeniedPayload for Atender/Finalizar.
     *
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    public static function publicAppointmentsMonthFullPayload(array $extra = []): array
    {
        return array_merge([
            'ok' => false,
            'error' => self::PUBLIC_APPOINTMENTS_MONTH_FULL_MESSAGE,
            'code' => 'PLAN_LIMIT_MAX_APPOINTMENTS_MONTH',
        ], $extra);
    }

    /**
     * Denial when commerce tries to Atender/Finalizar beyond monthly quota.
     *
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    public static function appointmentsMonthProcessDeniedPayload(array $extra = []): array
    {
        return array_merge([
            'ok' => false,
            'error' => self::APPOINTMENTS_MONTH_PROCESS_DENIED_MESSAGE,
            'code' => 'PLAN_LIMIT_MAX_APPOINTMENTS_MONTH',
            'upgrade_hint' => 'Ver planes',
        ], $extra);
    }

    /**
     * Statuses that consume a paid monthly appointment slot (Atender / Finalizar).
     */
    public static function isConsumingAppointmentStatus(string $status): bool
    {
        $st = strtolower(trim($status));
        return in_array($st, ['aprobado', 'en progreso', 'finalizado', 'atendido'], true);
    }

    /**
     * Count tenant-local reservas whose Fecha_Reserva falls in the current month.
     * (Local SQLite rows typically lack created_at.)
     *
     * @param list<array<string, mixed>> $reservas
     */
    public static function countLocalReservasThisMonth(array $reservas): int
    {
        $prefix = date('Y-m');
        $n = 0;
        foreach ($reservas as $row) {
            $fecha = trim((string)($row['Fecha_Reserva'] ?? ''));
            if ($fecha !== '' && str_starts_with($fecha, $prefix)) {
                $n++;
            }
        }
        return $n;
    }

    /**
     * Count local reservas in a month (YYYY-MM) that already consume the paid quota
     * (Aprobado / En progreso / Finalizado / Atendido). Pendiente waitlist does not count.
     *
     * @param list<array<string, mixed>> $reservas
     */
    public static function countLocalReservasConsumedThisMonth(array $reservas, ?string $monthPrefix = null): int
    {
        $prefix = $monthPrefix !== null && $monthPrefix !== '' ? $monthPrefix : date('Y-m');
        $n = 0;
        foreach ($reservas as $row) {
            $fecha = trim((string)($row['Fecha_Reserva'] ?? ''));
            if ($fecha === '' || !str_starts_with($fecha, $prefix)) {
                continue;
            }
            if (self::isConsumingAppointmentStatus((string)($row['Status'] ?? ''))) {
                $n++;
            }
        }
        return $n;
    }

    /**
     * Whether transitioning a local reserva to $newStatus would exceed monthly quota.
     * Idempotent when the row already consumes a slot. Quota month = booking Fecha_Reserva.
     *
     * @param list<array<string, mixed>> $reservas
     * @param array<string, mixed>|null $existingRow
     */
    public static function wouldExceedAppointmentsMonthOnProcess(
        ?array $plan,
        array $reservas,
        ?array $existingRow,
        string $newStatus
    ): bool {
        if (!is_array($plan)) {
            return false;
        }
        $max = self::maxAppointmentsMonth($plan);
        if ($max === null) {
            return false;
        }
        if (!self::isConsumingAppointmentStatus($newStatus)) {
            return false;
        }
        $oldStatus = (string)($existingRow['Status'] ?? '');
        if (self::isConsumingAppointmentStatus($oldStatus)) {
            return false;
        }
        $fecha = trim((string)($existingRow['Fecha_Reserva'] ?? ''));
        $monthPrefix = (preg_match('/^\d{4}-\d{2}/', $fecha) === 1) ? substr($fecha, 0, 7) : date('Y-m');
        return self::countLocalReservasConsumedThisMonth($reservas, $monthPrefix) >= $max;
    }

    public static function isAnnualEnabled(array $plan): bool
    {
        return (int)($plan['anual_habilitado'] ?? 0) === 1 && (float)($plan['precio'] ?? 0) > 0;
    }

    /**
     * Effective yearly price when annual billing is enabled; null if not available.
     */
    public static function yearlyPrice(array $plan): ?float
    {
        if (!self::isAnnualEnabled($plan)) {
            return null;
        }
        $explicit = $plan['precio_anual'] ?? null;
        if ($explicit !== null && $explicit !== '' && is_numeric($explicit) && (float)$explicit >= 0) {
            return round((float)$explicit, 2);
        }
        $monthly = (float)($plan['precio'] ?? 0);
        if ($monthly <= 0) {
            return 0.0;
        }
        $discount = max(0.0, min(100.0, (float)($plan['descuento_anual_pct'] ?? 0)));
        return round($monthly * 12 * (1 - $discount / 100), 2);
    }

    public static function annualDiscountPct(array $plan): float
    {
        return max(0.0, min(100.0, (float)($plan['descuento_anual_pct'] ?? 0)));
    }

    /**
     * Savings vs paying monthly for 12 months (when annual enabled).
     */
    public static function annualSavings(array $plan): ?float
    {
        $yearly = self::yearlyPrice($plan);
        if ($yearly === null) {
            return null;
        }
        $monthlyTotal = (float)($plan['precio'] ?? 0) * 12;
        $save = $monthlyTotal - $yearly;
        return $save > 0 ? round($save, 2) : 0.0;
    }

    public static function forCommerceSlug(string $slug): ?array
    {
        $slug = trim($slug);
        if ($slug === '') {
            return null;
        }
        $db = Database::getInstance();
        return $db->fetchOne(
            'SELECT m.* FROM commerces c
             INNER JOIN memberships m ON m.id_membership = c.id_membership
             WHERE c.slug = :s
             LIMIT 1',
            [':s' => $slug]
        );
    }

    public static function forCommerceId(int $commerceId): ?array
    {
        if ($commerceId <= 0) {
            return null;
        }
        $db = Database::getInstance();
        return $db->fetchOne(
            'SELECT m.* FROM commerces c
             INNER JOIN memberships m ON m.id_membership = c.id_membership
             WHERE c.id_commerce = :id
             LIMIT 1',
            [':id' => $commerceId]
        );
    }

    /**
     * Catalog defaults for Free / Básico / Profesional (limits + marketing copy).
     *
     * @return array<string, array{features: list<string>, limits: array<string, mixed>, anual_habilitado: int, descuento_anual_pct: float, descripcion?: string}>
     */
    public static function catalogDefaults(): array
    {
        return [
            'Free' => [
                'descripcion' => 'Ideal para empezar: 1 profesional, hasta 4 servicios, 25 clientes y 25 reservas al mes.',
                'features' => [
                    'Hasta 25 reservas al mes',
                    'Hasta 25 clientes',
                    '1 profesional',
                    'Hasta 4 servicios',
                    'Sin productos en la tienda',
                    'Configuración básica (nombre, logo, redes)',
                    'Agenda online básica',
                    'Soporte por email',
                ],
                'limits' => [
                    self::LIMIT_MAX_PRODUCTS => 0,
                    self::LIMIT_MAX_SERVICES => 4,
                    self::LIMIT_MAX_APPOINTMENTS_MONTH => 25,
                    self::LIMIT_MAX_PROFESSIONALS => 1,
                    self::LIMIT_MAX_CLIENTS => 25,
                    self::LIMIT_SETTINGS_TIER => self::SETTINGS_TIER_BASIC,
                ],
                'anual_habilitado' => 0,
                'descuento_anual_pct' => 0.0,
            ],
            'Básico' => [
                'descripcion' => 'Agenda completa, hasta 3 profesionales, 8 servicios, 6 productos, 100 clientes y 100 reservas al mes.',
                'features' => [
                    'Hasta 100 reservas al mes',
                    'Hasta 100 clientes',
                    'Hasta 3 profesionales',
                    'Hasta 8 servicios',
                    'Hasta 6 productos en la tienda',
                    'Configuración completa',
                    'Agenda y recordatorios',
                    'Soporte prioritario',
                ],
                'limits' => [
                    self::LIMIT_MAX_PRODUCTS => 6,
                    self::LIMIT_MAX_SERVICES => 8,
                    self::LIMIT_MAX_APPOINTMENTS_MONTH => 100,
                    self::LIMIT_MAX_PROFESSIONALS => 3,
                    self::LIMIT_MAX_CLIENTS => 100,
                    self::LIMIT_SETTINGS_TIER => self::SETTINGS_TIER_FULL,
                ],
                'anual_habilitado' => 1,
                'descuento_anual_pct' => 20.0,
            ],
            'Profesional' => [
                'descripcion' => 'Ilimitado: profesionales, clientes, reservas, servicios, productos y configuración completa.',
                'features' => [
                    'Profesionales ilimitados',
                    'Clientes, reservas, servicios y productos ilimitados',
                    'Configuración completa',
                    'Todo lo del plan Básico',
                    'Reportes avanzados',
                    'Soporte prioritario',
                ],
                'limits' => [
                    self::LIMIT_SETTINGS_TIER => self::SETTINGS_TIER_FULL,
                ],
                'anual_habilitado' => 1,
                'descuento_anual_pct' => 20.0,
            ],
        ];
    }

    /**
     * Encode a pending paid-plan upgrade in subscriptions.notes without changing
     * commerces.id_membership (effective plan) until capture / transfer approve.
     */
    public static function encodePendingMembershipNote(int $pendingId, int $previousId, string $detail): string
    {
        $detail = trim($detail);
        return sprintf(
            'pending_membership_id=%d;previous_membership_id=%d;%s',
            $pendingId,
            $previousId,
            $detail !== '' ? $detail : 'Pago pendiente.'
        );
    }

    /**
     * @return array{pending_id:int,previous_id:int}|null
     */
    public static function parsePendingMembershipNote(?string $notes): ?array
    {
        $notes = (string)$notes;
        if ($notes === '' || !preg_match('/pending_membership_id=(\d+)/', $notes, $m)) {
            return null;
        }
        $previous = 0;
        if (preg_match('/previous_membership_id=(\d+)/', $notes, $m2)) {
            $previous = (int)$m2[1];
        }
        return [
            'pending_id' => (int)$m[1],
            'previous_id' => $previous,
        ];
    }

    public static function clearPendingMembershipNote(?string $notes): string
    {
        $notes = (string)$notes;
        if ($notes === '' || !preg_match('/pending_membership_id=\d+/', $notes)) {
            return $notes;
        }
        return '';
    }

    /**
     * @return list<string>
     */
    private static function normalizeStringList(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            $s = trim((string)$item);
            if ($s !== '') {
                $out[] = $s;
            }
        }
        return $out;
    }
}
