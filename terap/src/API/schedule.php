<?php
date_default_timezone_set('America/Montevideo');
require_once __DIR__ . '/Autoload.php';

header('Content-Type: application/json; charset=utf-8');

$dateParam = (string)($_GET['date'] ?? $_POST['date'] ?? '') ?: date('Y-m-d');
$serviceParam = $_GET['service_id'] ?? $_POST['service_id'] ?? null;
$barberParam = $_GET['barber_id'] ?? $_POST['barber_id'] ?? null;
$barberFilter = null;
if ($barberParam !== null && $barberParam !== '') {
    $barberFilter = (string)$barberParam;
}

try {
    $requestedDate = new DateTimeImmutable($dateParam);
} catch (Throwable $e) {
    $requestedDate = new DateTimeImmutable('today');
}

try {
    $barbers = AutoloadDB::all('barberos');
    $services = AutoloadDB::all('servicios');
    $reservations = AutoloadDB::all('reservas');
    $shifts = AutoloadDB::all('turnos');
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'No se pudo acceder a la base de datos.']);
    exit;
}

try {
    $infoBarberia = AutoloadDB::getConfigSection('info_barberia');
} catch (Throwable $e) {
    $infoBarberia = [];
}

$today = new DateTimeImmutable('today');
$maxDaysAhead = 7;
if (isset($infoBarberia['reservas']) && is_array($infoBarberia['reservas'])) {
    $reservasConfig = $infoBarberia['reservas'];
    if (isset($reservasConfig['max_dias_adelante'])) {
        $candidate = (int)$reservasConfig['max_dias_adelante'];
        if ($candidate >= 0) {
            $maxDaysAhead = $candidate;
        }
    }
}
$maxDate = $today->modify(sprintf('+%d days', $maxDaysAhead));

if ($requestedDate < $today) {
    $requestedDate = $today;
}
if ($requestedDate > $maxDate) {
    $requestedDate = $maxDate;
}

$canonicalDate = $requestedDate->format('Y-m-d');
$selectedDayKey = dayKeyFromDate($requestedDate);
$defaultServiceDuration = 30; // minutos
$slotStep = 15;                // intervalo entre turnos

$dayWindows = scheduleWindowsForDate($infoBarberia, $requestedDate);

$serviceDuration = $defaultServiceDuration;
if ($serviceParam !== null) {
    $service = AutoloadDB::find('servicios', $serviceParam);
    if ($service && isset($service['Duracion']) && is_numeric($service['Duracion'])) {
        $serviceDuration = max(5, (int)$service['Duracion']);
    }
}

$durationByService = [];
foreach ($services as $srv) {
    if (!isset($srv['ID_Servicio'])) {
        continue;
    }
    $durationByService[(string)$srv['ID_Servicio']] = isset($srv['Duracion']) && is_numeric($srv['Duracion'])
        ? (int)$srv['Duracion']
        : $defaultServiceDuration;
}

$busyByBarber = [];
foreach ($reservations as $res) {
    if (!isset($res['ID_Barber'], $res['Fecha_Reserva'], $res['Hora_Reserva'])) {
        continue;
    }
    if ($barberFilter !== null && (string)$res['ID_Barber'] !== $barberFilter) {
        continue;
    }
    if (strcasecmp((string)($res['Status'] ?? ''), 'cancelado') === 0) {
        continue;
    }
    if ($res['Fecha_Reserva'] !== $canonicalDate) {
        continue;
    }
    $startMinutes = timeToMinutes((string)$res['Hora_Reserva']);
    if ($startMinutes === null) {
        continue;
    }
    $duration = $defaultServiceDuration;
    if (isset($res['ID_Servicio'])) {
        $duration = $durationByService[(string)$res['ID_Servicio']] ?? $defaultServiceDuration;
    }
    $busyByBarber[(string)$res['ID_Barber']][] = [
        $startMinutes,
        $startMinutes + $duration
    ];
}

$windowsByBarber = [];
foreach ($shifts as $turno) {
    if (!isset($turno['ID_Barbers'], $turno['Hora_Inicio'], $turno['Hora_Cierre'])) {
        continue;
    }
    if ($barberFilter !== null && (string)$turno['ID_Barbers'] !== $barberFilter) {
        continue;
    }
    if (strcasecmp((string)($turno['Estado'] ?? ''), 'activo') !== 0) {
        continue;
    }
    $start = timeToMinutes((string)$turno['Hora_Inicio']);
    $end = timeToMinutes((string)$turno['Hora_Cierre']);
    if ($start === null || $end === null || $end <= $start) {
        continue;
    }
    $barberId = (string)$turno['ID_Barbers'];
    $windowsByBarber[$barberId][] = [$start, $end];
}

$isToday = $canonicalDate === $today->format('Y-m-d');
$minStartMinutes = 0;
if ($isToday) {
    $now = new DateTimeImmutable('now');
    $nowMinutes = (int)$now->format('H') * 60 + (int)$now->format('i');
    $rounded = (int)ceil($nowMinutes / $slotStep) * $slotStep;
    $minStartMinutes = min(24 * 60 - $slotStep, max(0, $rounded));
}

$data = [];
foreach ($barbers as $barber) {
    if (!isset($barber['ID_Barber'])) {
        continue;
    }
    $id = (string)$barber['ID_Barber'];
    if ($barberFilter !== null && $id !== $barberFilter) {
        continue;
    }
    $name = trim(($barber['Nombre'] ?? '') . ' ' . ($barber['Apellido'] ?? '')) ?: 'Barbero ' . $id;

    $windows = $dayWindows;
    if (!empty($windowsByBarber[$id])) {
        $windows = intersectWindows($windows, $windowsByBarber[$id]);
    }
    $busySlots = $busyByBarber[$id] ?? [];
    $availableSlots = buildAvailableSlots($windows, $busySlots, $serviceDuration, $slotStep, $minStartMinutes);

    $skills = normalizeSkillIds($barber['Habilidades'] ?? null);
    $workingDays = normalizeWorkingDays($barber['DiasTrabajo'] ?? null);
    $worksSelectedDay = true;
    if (!empty($workingDays)) {
        $worksSelectedDay = in_array($selectedDayKey, $workingDays, true);
    }
    if ($selectedDayKey === '' || empty($dayWindows) || empty($windows)) {
        $worksSelectedDay = false;
    }
    if (!$worksSelectedDay) {
        $availableSlots = [];
    }

    $data[] = [
        'barber_id' => $id,
        'barber' => $name,
        'slots' => $availableSlots,
        'turns' => formatWindows($windows),
        'avatar' => normalizeAvatar($barber['Perfil'] ?? ''),
        'skills' => $skills,
        'working_days' => $workingDays,
        'works_selected_day' => $worksSelectedDay,
    ];
}

echo json_encode([
    'ok' => true,
    'date' => $canonicalDate,
    'service_duration' => $serviceDuration,
    'limits' => [
        'today' => $today->format('Y-m-d'),
        'min_date' => $today->format('Y-m-d'),
        'max_date' => $maxDate->format('Y-m-d'),
        'max_days_ahead' => $maxDaysAhead,
    ],
    'data' => $data,
]);

function normalizeAvatar($path): string {
    if (!is_string($path)) {
        return '';
    }
    $trimmed = trim($path);
    if ($trimmed === '') {
        return '';
    }
    return str_replace('\\', '/', $trimmed);
}

function timeToMinutes(string $time): ?int {
    if (!preg_match('/^(\d{2}):(\d{2})(?::\d{2})?$/', $time, $m)) {
        return null;
    }
    return ((int)$m[1]) * 60 + (int)$m[2];
}

function minutesToTime(int $minutes): string {
    $hours = intdiv($minutes, 60);
    $mins = $minutes % 60;
    return sprintf('%02d:%02d', $hours, $mins);
}

function minutesToRange(int $start, int $end): string {
    return minutesToTime($start) . ' - ' . minutesToTime($end);
}

function overlaps(int $startA, int $endA, int $startB, int $endB): bool {
    return $startA < $endB && $startB < $endA;
}

function buildAvailableSlots(array $windows, array $busySlots, int $serviceDuration, int $step, int $minStart = 0): array {
    if (empty($windows)) {
        return [];
    }

    usort($busySlots, function ($a, $b) {
        return $a[0] <=> $b[0];
    });
    usort($windows, function ($a, $b) {
        return $a[0] <=> $b[0];
    });

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
            $isFree = true;
            foreach ($busySlots as $busy) {
                if (overlaps($start, $start + $serviceDuration, $busy[0], $busy[1])) {
                    $isFree = false;
                    break;
                }
            }
            if ($isFree) {
                $slots[] = minutesToTime($start);
            }
        }
    }

    return array_values(array_unique($slots));
}

function formatWindows(array $windows): array {
    if (empty($windows)) {
        return [];
    }
    usort($windows, function ($a, $b) {
        return $a[0] <=> $b[0];
    });
    $labels = [];
    foreach ($windows as $window) {
        [$start, $end] = $window;
        if ($end <= $start) {
            continue;
        }
        $labels[] = minutesToRange($start, $end);
    }
    return $labels;
}

function normalizeSkillIds($value): array {
    if ($value === null || $value === '') {
        return [];
    }
    $source = is_array($value) ? $value : preg_split('/[,;]+/', (string)$value);
    if (!is_array($source)) {
        return [];
    }
    $result = [];
    foreach ($source as $entry) {
        $id = trim((string)$entry);
        if ($id === '') {
            continue;
        }
        $result[$id] = true;
    }
    return array_keys($result);
}

function normalizeWorkingDays($value): array {
    if ($value === null || $value === '') {
        return [];
    }
    $source = is_array($value) ? $value : preg_split('/[,;]+/', (string)$value);
    if (!is_array($source)) {
        return [];
    }
    $result = [];
    foreach ($source as $entry) {
        $token = normalizeDayToken((string)$entry);
        if ($token === '') {
            continue;
        }
        $result[$token] = true;
    }
    return array_keys($result);
}

function normalizeDayToken(string $token): string {
    $token = trim($token);
    if ($token === '') {
        return '';
    }
    if (function_exists('mb_strtolower')) {
        $token = mb_strtolower($token, 'UTF-8');
    } else {
        $token = strtolower($token);
    }
    $token = strtr($token, [
        'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a',
        'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
        'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o',
        'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
        'ñ' => 'n',
    ]);
    return $token;
}

function dayKeyFromDate(DateTimeImmutable $date): string {
    static $map = ['domingo', 'lunes', 'martes', 'miercoles', 'jueves', 'viernes', 'sabado'];
    $index = (int)$date->format('w');
    return $map[$index] ?? '';
}

function scheduleWindowsForDate(array $infoBarberia, DateTimeImmutable $date): array {
    if (!isset($infoBarberia['horarios']) || !is_array($infoBarberia['horarios'])) {
        return [];
    }
    $dayKey = dayKeyFromDate($date);
    if ($dayKey === '') {
        return [];
    }
    $config = $infoBarberia['horarios'][$dayKey] ?? null;
    if (!is_array($config)) {
        return [];
    }
    $isOpen = isset($config['abierto']) ? (bool)$config['abierto'] : true;
    if (!$isOpen) {
        return [];
    }
    $start = timeToMinutesSafe($config['inicio'] ?? '');
    $end = timeToMinutesSafe($config['fin'] ?? '');
    if ($start === null || $end === null || $end <= $start) {
        return [];
    }
    $windows = [[ $start, $end ]];
    $breakStart = timeToMinutesSafe($config['descanso_inicio'] ?? '');
    $breakEnd = timeToMinutesSafe($config['descanso_fin'] ?? '');
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
    return mergeWindows($windows);
}

function intersectWindows(array $base, array $limits): array {
    if (empty($base)) {
        return [];
    }
    if (empty($limits)) {
        return mergeWindows($base);
    }
    $result = [];
    foreach ($base as $windowA) {
        if (!is_array($windowA) || count($windowA) < 2) {
            continue;
        }
        foreach ($limits as $windowB) {
            if (!is_array($windowB) || count($windowB) < 2) {
                continue;
            }
            $start = max((int)$windowA[0], (int)$windowB[0]);
            $end = min((int)$windowA[1], (int)$windowB[1]);
            if ($end > $start) {
                $result[] = [$start, $end];
            }
        }
    }
    return mergeWindows($result);
}

function mergeWindows(array $windows): array {
    if (empty($windows)) {
        return [];
    }
    usort($windows, function ($a, $b) {
        return $a[0] <=> $b[0];
    });
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

function timeToMinutesSafe($value): ?int {
    if (!is_string($value) && !is_numeric($value)) {
        return null;
    }
    $trimmed = trim((string)$value);
    if ($trimmed === '') {
        return null;
    }
    $normalized = str_replace('.', ':', $trimmed);
    return timeToMinutes($normalized);
}
?>
