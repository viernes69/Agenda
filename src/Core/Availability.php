<?php
declare(strict_types=1);

namespace Agenduy\Core;

/**
 * Calcula slots públicos a partir de horarios del comercio + appointments existentes.
 * Modelo: un calendario por comercio (sin profesional en el modal público).
 */
final class Availability
{
    public const SLOT_STEP_MINUTES = 15;
    public const DEFAULT_DURATION_MINUTES = 30;

    /**
     * @return array{
     *   ok: bool,
     *   date: string,
     *   slots: list<string>,
     *   service_duration: int,
     *   limits: array{min_date: string, max_date: string, max_dias_adelante: int, anticipacion_minutos: int, anticipacion_min_horas: int},
     *   calendar: array{
     *     open_weekdays: list<int>,
     *     closed_dates: list<string>,
     *     open_dates: list<string>,
     *     next_open_date: ?string
     *   },
     *   closed?: bool,
     *   error?: string
     * }
     */
    public static function forCommerce(
        int $idCommerce,
        string $fecha,
        ?int $idService = null,
        ?\DateTimeImmutable $now = null
    ): array {
        $now = $now ?? new \DateTimeImmutable('now');
        $today = $now->setTime(0, 0, 0);

        $horarios = CommerceSettings::get(
            $idCommerce,
            'horarios',
            CommerceSettings::defaultsForSection('horarios')
        );
        $reservas = CommerceSettings::get(
            $idCommerce,
            'reservas',
            CommerceSettings::defaultsForSection('reservas')
        );

        $maxDays = max(0, (int)($reservas['max_dias_adelante'] ?? 60));
        if (array_key_exists('anticipacion_minutos', $reservas)) {
            $anticipacionMinutos = max(0, (int)$reservas['anticipacion_minutos']);
        } else {
            $anticipacionMinutos = max(0, (int)($reservas['anticipacion_min_horas'] ?? 0)) * 60;
        }
        $anticipacionHoras = intdiv($anticipacionMinutos, 60);
        $maxDate = $today->modify(sprintf('+%d days', $maxDays));
        $calendar = self::calendarForRange($horarios, $today, $maxDate);

        try {
            $requested = new \DateTimeImmutable($fecha);
        } catch (\Throwable $e) {
            $requested = $today;
        }
        $requested = $requested->setTime(0, 0, 0);

        if ($requested < $today) {
            $requested = $today;
        }
        if ($requested > $maxDate) {
            $requested = $maxDate;
        }
        $canonical = $requested->format('Y-m-d');

        $duration = self::DEFAULT_DURATION_MINUTES;
        if ($idService !== null && $idService > 0) {
            $db = Database::getInstance();
            $svc = $db->fetchOne(
                'SELECT duracion_min FROM services WHERE id_service = :id AND id_commerce = :c',
                [':id' => $idService, ':c' => $idCommerce]
            );
            if ($svc && (int)$svc['duracion_min'] > 0) {
                $duration = max(5, (int)$svc['duracion_min']);
            }
        }

        $limits = [
            'min_date' => $today->format('Y-m-d'),
            'max_date' => $maxDate->format('Y-m-d'),
            'max_dias_adelante' => $maxDays,
            'anticipacion_minutos' => $anticipacionMinutos,
            'anticipacion_min_horas' => $anticipacionHoras,
        ];

        $windows = self::windowsForDate($horarios, $requested);
        if ($windows === []) {
            return [
                'ok' => true,
                'date' => $canonical,
                'slots' => [],
                'service_duration' => $duration,
                'limits' => $limits,
                'calendar' => $calendar,
                'closed' => true,
            ];
        }

        $minStart = 0;
        $earliest = $now->modify(sprintf('+%d minutes', $anticipacionMinutos));
        if ($canonical === $earliest->format('Y-m-d')) {
            $minStart = ((int)$earliest->format('H')) * 60 + (int)$earliest->format('i');
            $step = self::SLOT_STEP_MINUTES;
            if ($minStart % $step !== 0) {
                $minStart = (int)ceil($minStart / $step) * $step;
            }
        } elseif ($canonical < $earliest->format('Y-m-d')) {
            return [
                'ok' => true,
                'date' => $canonical,
                'slots' => [],
                'service_duration' => $duration,
                'limits' => $limits,
                'calendar' => $calendar,
                'closed' => false,
            ];
        }

        self::expireStalePaymentAppointments($idCommerce);
        $busy = self::busyIntervals($idCommerce, $canonical);
        $slots = self::buildSlots($windows, $busy, $duration, self::SLOT_STEP_MINUTES, $minStart);

        return [
            'ok' => true,
            'date' => $canonical,
            'slots' => $slots,
            'service_duration' => $duration,
            'limits' => $limits,
            'calendar' => $calendar,
            'closed' => false,
        ];
    }

    /**
     * Días abiertos del comercio en [from, to] (horarios semanales − feriados).
     *
     * @return array{
     *   open_weekdays: list<int>,
     *   closed_dates: list<string>,
     *   open_dates: list<string>,
     *   next_open_date: ?string
     * }
     */
    public static function calendarForRange(
        array $horarios,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to
    ): array {
        $from = $from->setTime(0, 0, 0);
        $to = $to->setTime(0, 0, 0);
        if ($to < $from) {
            $to = $from;
        }

        $openWeekdays = [];
        $dayKeys = ['domingo', 'lunes', 'martes', 'miercoles', 'jueves', 'viernes', 'sabado'];
        foreach ($dayKeys as $jsDay => $dayKey) {
            if (self::isWeekdayConfiguredOpen($horarios, $dayKey)) {
                $openWeekdays[] = $jsDay;
            }
        }

        $closedDates = [];
        $feriados = $horarios['feriados'] ?? [];
        if (is_array($feriados)) {
            foreach ($feriados as $feriado) {
                $ymd = trim((string)$feriado);
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) {
                    $closedDates[] = $ymd;
                }
            }
            $closedDates = array_values(array_unique($closedDates));
            sort($closedDates);
        }

        $openDates = [];
        $cursor = $from;
        while ($cursor <= $to) {
            if (self::windowsForDate($horarios, $cursor) !== []) {
                $openDates[] = $cursor->format('Y-m-d');
            }
            $cursor = $cursor->modify('+1 day');
        }

        return [
            'open_weekdays' => $openWeekdays,
            'closed_dates' => $closedDates,
            'open_dates' => $openDates,
            'next_open_date' => $openDates[0] ?? null,
        ];
    }

    /**
     * True si el día de la semana está configurado como abierto con ventana válida
     * (sin considerar feriados puntuales).
     */
    public static function isWeekdayConfiguredOpen(array $horarios, string $dayKey): bool
    {
        $config = $horarios[$dayKey] ?? null;
        if (!is_array($config)) {
            return false;
        }
        $isOpen = array_key_exists('abierto', $config) ? (bool)$config['abierto'] : true;
        if (!$isOpen) {
            return false;
        }
        $start = self::timeToMinutes((string)($config['inicio'] ?? ''));
        $end = self::timeToMinutes((string)($config['fin'] ?? ''));
        return $start !== null && $end !== null && $end > $start;
    }

    /**
     * True si la hora está entre los slots ofrecidos para esa fecha/servicio.
     */
    public static function isSlotAvailable(
        int $idCommerce,
        string $fecha,
        string $horaInicio,
        ?int $idService = null,
        ?\DateTimeImmutable $now = null
    ): bool {
        $hora = self::normalizeTime($horaInicio);
        if ($hora === null) {
            return false;
        }
        $result = self::forCommerce($idCommerce, $fecha, $idService, $now);
        return in_array($hora, $result['slots'], true);
    }

    /**
     * @return list<array{0:int,1:int}>
     */
    public static function windowsForDate(array $horarios, \DateTimeImmutable $date): array
    {
        $dayKey = self::dayKeyFromDate($date);
        if ($dayKey === '') {
            return [];
        }

        // Feriados: fechas YYYY-MM-DD cerradas
        $feriados = $horarios['feriados'] ?? [];
        if (is_array($feriados) && in_array($date->format('Y-m-d'), $feriados, true)) {
            return [];
        }

        $config = $horarios[$dayKey] ?? null;
        if (!is_array($config)) {
            return [];
        }
        $isOpen = array_key_exists('abierto', $config) ? (bool)$config['abierto'] : true;
        if (!$isOpen) {
            return [];
        }

        $start = self::timeToMinutes((string)($config['inicio'] ?? ''));
        $end = self::timeToMinutes((string)($config['fin'] ?? ''));
        if ($start === null || $end === null || $end <= $start) {
            return [];
        }

        $windows = [[$start, $end]];
        $breakStart = self::timeToMinutes((string)($config['descanso_inicio'] ?? ''));
        $breakEnd = self::timeToMinutes((string)($config['descanso_fin'] ?? ''));
        if ($breakStart !== null && $breakEnd !== null && $breakEnd > $breakStart) {
            $breakStart = max($start, min($breakStart, $end));
            $breakEnd = max($breakStart, min($breakEnd, $end));
            $windows = [];
            if ($breakStart > $start) {
                $windows[] = [$start, $breakStart];
            }
            if ($breakEnd < $end) {
                $windows[] = [$breakEnd, $end];
            }
        }

        return self::mergeWindows($windows);
    }

    /**
     * @return list<array{0:int,1:int}>
     */
    private static function busyIntervals(int $idCommerce, string $fecha): array
    {
        $db = Database::getInstance();
        $rows = $db->fetchAll(
            "SELECT a.hora_inicio, a.hora_fin, s.duracion_min
             FROM appointments a
             LEFT JOIN services s ON s.id_service = a.id_service
             WHERE a.id_commerce = :c
               AND a.fecha = :f
               AND a.status NOT IN ('cancelled')
               AND NOT (
                   a.status = 'pending'
                   AND EXISTS (
                       SELECT 1
                       FROM appointment_payments ap
                       WHERE ap.id_appointment = a.id_appointment
                         AND ap.status IN ('created','pending')
                         AND ap.expires_at <> ''
                         AND datetime(ap.expires_at) <= datetime('now')
                   )
               )",
            [':c' => $idCommerce, ':f' => $fecha]
        );

        $busy = [];
        foreach ($rows as $row) {
            $start = self::timeToMinutes((string)($row['hora_inicio'] ?? ''));
            if ($start === null) {
                continue;
            }
            $end = self::timeToMinutes((string)($row['hora_fin'] ?? ''));
            if ($end === null || $end <= $start) {
                $dur = isset($row['duracion_min']) && (int)$row['duracion_min'] > 0
                    ? (int)$row['duracion_min']
                    : self::DEFAULT_DURATION_MINUTES;
                $end = $start + $dur;
            }
            $busy[] = [$start, $end];
        }
        return $busy;
    }

    private static function expireStalePaymentAppointments(int $idCommerce): void
    {
        if ($idCommerce <= 0) {
            return;
        }
        try {
            $db = Database::getInstance();
            $rows = $db->fetchAll(
                "SELECT id_appointment_payment, id_appointment, slug
                 FROM appointment_payments
                 WHERE id_commerce = :c
                   AND status IN ('created','pending')
                   AND expires_at <> ''
                   AND datetime(expires_at) <= datetime('now')
                 LIMIT 50",
                [':c' => $idCommerce]
            );
            foreach ($rows as $row) {
                $paymentId = (int)($row['id_appointment_payment'] ?? 0);
                $appointmentId = (int)($row['id_appointment'] ?? 0);
                $slug = trim((string)($row['slug'] ?? ''));
                if ($paymentId <= 0 || $appointmentId <= 0) {
                    continue;
                }
                $db->update('appointment_payments', [
                    'status' => 'cancelled',
                    'status_detail' => 'Pago vencido',
                    'updated_at' => date('Y-m-d H:i:s'),
                ], 'id_appointment_payment = :id', [':id' => $paymentId]);
                $db->update('appointments', [
                    'status' => 'cancelled',
                    'updated_at' => date('Y-m-d H:i:s'),
                ], "id_appointment = :id AND id_commerce = :c AND status = 'pending'", [
                    ':id' => $appointmentId,
                    ':c' => $idCommerce,
                ]);
                if ($slug !== '' && TenantLocalDb::exists($slug)) {
                    $appointment = $db->fetchOne(
                        "SELECT a.*, cl.avatar AS client_avatar
                         FROM appointments a
                         LEFT JOIN clients cl ON cl.id_client = a.id_client
                         WHERE a.id_appointment = :id
                         LIMIT 1",
                        [':id' => $appointmentId]
                    );
                    if ($appointment) {
                        try {
                            TenantLocalDb::mirrorAppointment($slug, $appointment);
                        } catch (\Throwable $e) {
                            error_log('[Availability.expirePayment] mirror: ' . $e->getMessage());
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log('[Availability.expirePayment] ' . $e->getMessage());
        }
    }

    /**
     * @param list<array{0:int,1:int}> $windows
     * @param list<array{0:int,1:int}> $busySlots
     * @return list<string>
     */
    public static function buildSlots(
        array $windows,
        array $busySlots,
        int $serviceDuration,
        int $step,
        int $minStart = 0
    ): array {
        if ($windows === [] || $serviceDuration < 1 || $step < 1) {
            return [];
        }

        usort($busySlots, static fn($a, $b) => $a[0] <=> $b[0]);
        usort($windows, static fn($a, $b) => $a[0] <=> $b[0]);

        $slots = [];
        foreach ($windows as $window) {
            [$windowStart, $windowEnd] = $window;
            if ($windowEnd <= $windowStart) {
                continue;
            }
            $start = max($windowStart, $minStart);
            if ($start % $step !== 0) {
                $start = (int)ceil($start / $step) * $step;
            }
            for (; $start + $serviceDuration <= $windowEnd; $start += $step) {
                $free = true;
                foreach ($busySlots as $busy) {
                    if ($start < $busy[1] && $busy[0] < $start + $serviceDuration) {
                        $free = false;
                        break;
                    }
                }
                if ($free) {
                    $slots[] = self::minutesToTime($start);
                }
            }
        }

        return array_values(array_unique($slots));
    }

    public static function dayKeyFromDate(\DateTimeImmutable $date): string
    {
        static $map = ['domingo', 'lunes', 'martes', 'miercoles', 'jueves', 'viernes', 'sabado'];
        return $map[(int)$date->format('w')] ?? '';
    }

    public static function timeToMinutes(string $time): ?int
    {
        $normalized = str_replace('.', ':', trim($time));
        if (!preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $normalized, $m)) {
            return null;
        }
        $h = (int)$m[1];
        $i = (int)$m[2];
        if ($h > 23 || $i > 59) {
            return null;
        }
        return $h * 60 + $i;
    }

    public static function minutesToTime(int $minutes): string
    {
        $hours = intdiv($minutes, 60);
        $mins = $minutes % 60;
        return sprintf('%02d:%02d', $hours, $mins);
    }

    public static function normalizeTime(string $time): ?string
    {
        $minutes = self::timeToMinutes($time);
        return $minutes === null ? null : self::minutesToTime($minutes);
    }

    /**
     * @param list<array{0:int,1:int}> $windows
     * @return list<array{0:int,1:int}>
     */
    private static function mergeWindows(array $windows): array
    {
        if ($windows === []) {
            return [];
        }
        usort($windows, static fn($a, $b) => $a[0] <=> $b[0]);
        $merged = [];
        $current = $windows[0];
        foreach ($windows as $window) {
            if ($window[0] <= $current[1]) {
                $current[1] = max($current[1], $window[1]);
            } else {
                $merged[] = $current;
                $current = $window;
            }
        }
        $merged[] = $current;
        return $merged;
    }
}
