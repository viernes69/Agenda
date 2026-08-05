<?php
declare(strict_types=1);

date_default_timezone_set('America/Montevideo');

$projectRoot = dirname(__DIR__, 5);
require_once $projectRoot . '/src/Core/bootstrap.php';
require_once dirname(__DIR__, 4) . '/src/API/Autoload.php';

use Agenduy\Core\TenantApiGuard;

header('Content-Type: application/json; charset=utf-8');

$respond = static function (int $code, array $payload): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

$tenantStaff = TenantApiGuard::requireStaff(dirname(__DIR__, 4));
$activeSession = $tenantStaff['session'];
$employeeRole = $tenantStaff['role'];
$isFunc = $employeeRole === 'func';

$escape = static function ($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
};

$normalizeAmount = static function ($value): float {
    if ($value === null || $value === '') {
        return 0.0;
    }
    if (is_numeric($value)) {
        return (float)$value;
    }
    $normalized = str_replace(',', '.', (string)$value);
    return is_numeric($normalized) ? (float)$normalized : 0.0;
};

$formatCurrency = static function (float $value): string {
    return '$ ' . number_format($value, 2, ',', '.');
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    $respond(405, ['ok' => false, 'error' => 'Metodo no permitido']);
}

try {
    $reservas  = AutoloadDB::all('reservas');
    $clientes  = AutoloadDB::all('clientes');
    $barberos  = AutoloadDB::all('barberos');
    $servicios = AutoloadDB::all('servicios');
} catch (Throwable $e) {
    $respond(500, ['ok' => false, 'error' => 'No se pudo leer la base de datos']);
}

$todayObj = new DateTime('today');
$nowTs    = time();

$normalizeName = static function ($value): string {
    $value = strtolower(trim((string)$value));
    $value = preg_replace('/\s+/', ' ', $value);
    return $value;
};

$mapClientes = [];
foreach ($clientes as $c) {
    $cid = (string)($c['ID_Cliente'] ?? '');
    if ($cid !== '') $mapClientes[$cid] = $c;
}
$mapBarberos = [];
foreach ($barberos as $b) {
    $bid = (string)($b['ID_Barber'] ?? '');
    if ($bid !== '') $mapBarberos[$bid] = $b;
}
$mapServicios = [];
foreach ($servicios as $s) {
    $sid = (string)($s['ID_Servicio'] ?? '');
    if ($sid !== '') $mapServicios[$sid] = $s;
}

$sessionBarberId = 0;
if (is_array($activeSession)) {
    $idRaw = $activeSession['ID_Barber'] ?? $activeSession['id_barber'] ?? null;
    if ($idRaw !== null && $idRaw !== '' && is_numeric($idRaw)) {
        $sessionBarberId = (int)$idRaw;
    }
}
$sessionBarberName = '';
if ($sessionBarberId > 0) {
    $key = (string)$sessionBarberId;
    if (isset($mapBarberos[$key])) {
        $sessionBarberName = trim(($mapBarberos[$key]['Nombre'] ?? '') . ' ' . ($mapBarberos[$key]['Apellido'] ?? ''));
    }
}
if ($sessionBarberName === '' && is_array($activeSession)) {
    $sessionBarberName = trim(($activeSession['Nombre'] ?? '') . ' ' . ($activeSession['Apellido'] ?? ''));
}
$sessionBarberNameNormalized = $sessionBarberName !== '' ? $normalizeName($sessionBarberName) : '';

if ($isFunc && ($sessionBarberId > 0 || $sessionBarberNameNormalized !== '')) {
    $reservas = array_values(array_filter($reservas, static function ($row) use ($sessionBarberId, $sessionBarberNameNormalized, $normalizeName, $mapBarberos) {
        if (!is_array($row)) return false;
        $rowIdRaw = $row['ID_Barber'] ?? $row['id_barber'] ?? null;
        if ($rowIdRaw !== null && $rowIdRaw !== '' && is_numeric($rowIdRaw)) {
            if ($sessionBarberId > 0 && (int)$rowIdRaw === $sessionBarberId) {
                return true;
            }
            if ($sessionBarberNameNormalized !== '') {
                $rowIdKey = (string)(int)$rowIdRaw;
                if (isset($mapBarberos[$rowIdKey])) {
                    $rowName = trim(($mapBarberos[$rowIdKey]['Nombre'] ?? '') . ' ' . ($mapBarberos[$rowIdKey]['Apellido'] ?? ''));
                    if ($rowName !== '' && $normalizeName($rowName) === $sessionBarberNameNormalized) {
                        return true;
                    }
                }
            }
        }
        if ($sessionBarberNameNormalized !== '') {
            $rowNameRaw = '';
            if (isset($row['Profesional'])) {
                $rowNameRaw = $row['Profesional'];
            } elseif (isset($row['Barbero'])) {
                $rowNameRaw = $row['Barbero'];
            } elseif (isset($row['NombreBarbero'])) {
                $rowNameRaw = $row['NombreBarbero'];
            }
            if ($rowNameRaw !== '' && $normalizeName($rowNameRaw) === $sessionBarberNameNormalized) {
                return true;
            }
        }
        return false;
    }));
}

$statusRegistry = [];
$pendingBadge   = 0;
foreach ($reservas as $row) {
    if (!is_array($row)) continue;
    $status = strtolower(trim((string)($row['Status'] ?? '')));
    if ($status === '') $status = 'pendiente';
    $statusRegistry[$status] = true;

    if ($status !== 'pendiente' && $status !== 'sin confirmar') {
        continue;
    }
    $dateRaw = trim((string)($row['Fecha_Reserva'] ?? ''));
    if ($dateRaw === '') continue;
    $dateObj = DateTime::createFromFormat('Y-m-d', $dateRaw)
            ?: DateTime::createFromFormat('d/m/Y', $dateRaw);
    if ($dateObj && $dateObj < $todayObj) {
        continue;
    }
    $pendingBadge++;
}

$statusSeed = ['pendiente', 'aprobado', 'en progreso', 'rechazado', 'cancelado', 'finalizado'];
$statusList = [];
$registryCopy = $statusRegistry;
foreach ($statusSeed as $seed) {
    if (isset($registryCopy[$seed])) {
        $statusList[] = $seed;
        unset($registryCopy[$seed]);
    }
}
if (!empty($registryCopy)) {
    foreach ($registryCopy as $extra => $_) {
        $statusList[] = $extra;
    }
}

$statusFilter = strtolower(trim((string)($_GET['status'] ?? '')));
$hasPendiente = in_array('pendiente', $statusList, true);
$defaultStatus = 'todos';
if ($statusFilter === '' || ($statusFilter !== 'todos' && !in_array($statusFilter, $statusList, true))) {
    $statusFilter = $defaultStatus;
}

$dateFilter = trim((string)($_GET['date'] ?? ''));
if ($dateFilter !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFilter)) {
    $dateFilter = '';
}

// Distinct Fecha_Reserva values (work days) for the Fecha filter calendar.
$availableDatesMap = [];
foreach ($reservas as $row) {
    if (!is_array($row)) {
        continue;
    }
    $fechaRaw = trim((string)($row['Fecha_Reserva'] ?? ''));
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaRaw)) {
        $availableDatesMap[$fechaRaw] = true;
    }
}
$availableDates = array_keys($availableDatesMap);
sort($availableDates);

$statusOptions = array_merge(['todos'], $statusList);
$formatStatusLabel = static function (string $value): string {
    $value = strtolower(trim($value));
    if ($value === '' || $value === 'pendiente') return 'Pendiente';
    return ucwords($value);
};

$statusOptionLabels = [];
foreach ($statusOptions as $option) {
    $statusOptionLabels[$option] = ($option === 'todos') ? 'Todos' : $formatStatusLabel($option);
}
$currentStatusLabel = $statusOptionLabels[$statusFilter] ?? ($statusFilter === 'todos' ? 'Todos' : $formatStatusLabel($statusFilter));

$prepared = [];
foreach ($reservas as $row) {
    if (!is_array($row)) continue;
    $status = strtolower(trim((string)($row['Status'] ?? '')));
    if ($status === '') $status = 'pendiente';

    $fecha = trim((string)($row['Fecha_Reserva'] ?? ''));
    $hora  = trim((string)($row['Hora_Reserva'] ?? ''));
    if ($hora === '') $hora = '00:00:00';
    $timestamp = PHP_INT_MAX;
    if ($fecha !== '') {
        $dt = DateTime::createFromFormat('Y-m-d H:i:s', $fecha . ' ' . $hora)
            ?: DateTime::createFromFormat('Y-m-d H:i', $fecha . ' ' . substr($hora, 0, 5));
        if ($dt instanceof DateTime) {
            $timestamp = (int)$dt->format('U');
        }
    }

    $include = false;
    if ($statusFilter === 'todos') {
        $include = true;
    } elseif ($statusFilter === 'pendiente') {
        $include = ($status === 'pendiente');
    } else {
        $include = ($status === $statusFilter);
    }
    if ($include && $dateFilter !== '' && $fecha !== $dateFilter) {
        $include = false;
    }
    if (!$include) continue;

    $row['_status_norm'] = $status;
    $row['_timestamp']   = $timestamp;
    $prepared[] = $row;
}

usort($prepared, static function ($a, $b) {
    $ta = isset($a['_timestamp']) ? (int)$a['_timestamp'] : PHP_INT_MAX;
    $tb = isset($b['_timestamp']) ? (int)$b['_timestamp'] : PHP_INT_MAX;
    if ($ta === $tb) {
        // Same slot: newest registration first (higher ID).
        $ia = isset($a['ID_Reserva']) ? (int)$a['ID_Reserva'] : 0;
        $ib = isset($b['ID_Reserva']) ? (int)$b['ID_Reserva'] : 0;
        return $ib <=> $ia;
    }
    return $ta <=> $tb;
});

$totalFinalizedAmount = 0.0;
$rowHtml = '';
foreach ($prepared as $row) {
    $cid = (string)($row['ID_Cliente'] ?? '');
    $bid = (string)($row['ID_Barber'] ?? '');
    $sid = (string)($row['ID_Servicio'] ?? '');

    $clientName = trim((string)($mapClientes[$cid]['Nombre'] ?? 'Cliente'));
    if ($clientName === '') $clientName = 'Cliente';
    $barberName = trim((string)($mapBarberos[$bid]['Nombre'] ?? '') . ' ' . (string)($mapBarberos[$bid]['Apellido'] ?? ''));
    if ($barberName === '') $barberName = 'Profesional';
    $serviceData = $mapServicios[$sid] ?? [];
    $serviceName = (string)($serviceData['Nombre'] ?? 'Servicio');
    if (isset($row['Precio']) && is_numeric($row['Precio']) && (float)$row['Precio'] > 0) {
        $servicePrice = $normalizeAmount($row['Precio']);
    } else {
        $servicePrice = $normalizeAmount($serviceData['Precio'] ?? 0);
    }
    $servicePriceLabel = $formatCurrency($servicePrice);

    $serviceImgRel = isset($serviceData['Img_Link']) ? trim((string)$serviceData['Img_Link']) : '';
    $serviceImgRel = str_replace('\\', '/', $serviceImgRel);
    $serviceImgUrl = '';
    if ($serviceImgRel !== '') {
        if (preg_match('#^https?://#i', $serviceImgRel)) {
            $serviceImgUrl = $serviceImgRel;
        } else {
            $serviceImgUrl = '../../../' . ltrim($serviceImgRel, '/');
        }
    }

    $statusNorm  = $row['_status_norm'] ?? 'pendiente';
    $statusLabel = $statusOptionLabels[$statusNorm] ?? $formatStatusLabel($statusNorm);
    $timestampAttr = (isset($row['_timestamp']) && $row['_timestamp'] !== PHP_INT_MAX) ? (string)(int)$row['_timestamp'] : '';
    $idReserva = (int)($row['ID_Reserva'] ?? 0);

    $rowHtml .= '<tr data-admin-reserva-item data-admin-res-row-id="' . $idReserva . '" data-admin-reserva-status="' . $escape($statusNorm) . '" data-admin-reserva-fecha="' . $escape($row['Fecha_Reserva'] ?? '') . '" data-admin-reserva-hora="' . $escape(substr((string)($row['Hora_Reserva'] ?? ''), 0, 5)) . '" data-admin-reserva-ts="' . $escape($timestampAttr) . '" data-admin-reserva-price="' . $escape($servicePriceLabel) . '">';
    $rowHtml .= '<td>' . $escape($clientName) . '</td>';
    $rowHtml .= '<td>' . $escape($barberName) . '</td>';
    $rowHtml .= '<td><span class="reserva-servicio__name">' . $escape($serviceName) . '</span></td>';
    $rowHtml .= '<td class="numeric">' . $escape($servicePriceLabel) . '</td>';
    $rowHtml .= '<td>' . $escape($row['Fecha_Reserva'] ?? '') . '</td>';
    $rowHtml .= '<td>' . $escape(substr((string)($row['Hora_Reserva'] ?? ''), 0, 5)) . '</td>';
    $rowHtml .= '<td><span class="status-pill st-' . $escape($statusNorm) . '">' . $escape($statusLabel) . '</span></td>';
    if (in_array($statusNorm, ['pendiente','aprobado','en progreso','rechazado','cancelado','finalizado'], true)) {
        $rowHtml .= '<td><button type="button" class="btn btn-warning btn-sm" data-admin-view-reserva="' . $idReserva . '">Ver</button></td>';
    } else {
        $rowHtml .= '<td><span class="muted">-</span></td>';
    }
    $rowHtml .= '</tr>';

    if ($statusNorm === 'finalizado') {
        $totalFinalizedAmount += $servicePrice;
    }
}

$respond(200, [
    'ok'        => true,
    'status'    => $statusFilter,
    'label'     => $currentStatusLabel,
    'total'     => count($prepared),
    'date'      => $dateFilter,
    'dates'     => $availableDates,
    'finalizedAmount' => $totalFinalizedAmount,
    'finalizedLabel'  => 'Total finalizado: ' . $formatCurrency($totalFinalizedAmount),
    'html'      => $rowHtml,
    'badge'     => $pendingBadge,
    'emptyMessage' => 'No hay reservas para el estado seleccionado.'
]);
