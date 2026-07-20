<?php
date_default_timezone_set('America/Montevideo');

$projectRoot = dirname(__DIR__, 4);
require_once $projectRoot . '/src/Core/bootstrap.php';
\Agenduy\Core\Auth::start();

header('Content-Type: text/html; charset=utf-8');
ini_set('default_charset', 'UTF-8');
mb_internal_encoding('UTF-8');

require_once dirname(__DIR__, 3) . '/src/API/Autoload.php';
require_once dirname(__DIR__, 2) . '/session_guard.php';

function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$tenantRootPath = dirname(__DIR__, 3);
$tenantSlug = \Agenduy\Core\CommercePanel::resolveEffectiveSlug($tenantRootPath);
$maxClientsLimit = null;
$maxProductsLimit = null;
try {
  $empleadoPlanRow = \Agenduy\Core\MembershipPlan::forCommerceSlug($tenantSlug);
  if (is_array($empleadoPlanRow)) {
    $maxClientsLimit = \Agenduy\Core\MembershipPlan::maxClients($empleadoPlanRow);
    $maxProductsLimit = \Agenduy\Core\MembershipPlan::maxProducts($empleadoPlanRow);
  }
} catch (Throwable $e) {
  $maxClientsLimit = null;
  $maxProductsLimit = null;
}

function admin_normalize_public_list($value, string $primaryKey): array {
  if (!is_array($value)) {
    return [];
  }
  $keys = array_keys($value);
  $isSequential = $keys === range(0, count($value) - 1);
  if ($isSequential) {
    return array_values(array_filter($value, 'is_array'));
  }
  if (isset($value[$primaryKey])) {
    return [is_array($value) ? $value : []];
  }
  $normalized = [];
  foreach ($value as $row) {
    if (is_array($row)) {
      $normalized[] = $row;
    }
  }
  return $normalized;
}

// Load data for KPIs
$today = date('Y-m-d');
$reservas = AutoloadDB::all('reservas');

$employeeSession = null;
if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
  $employeeSession = $_SESSION['user'];
} elseif (isset($_SESSION['barbero']) && is_array($_SESSION['barbero'])) {
  $employeeSession = $_SESSION['barbero'];
}
$employeeRoleRaw = is_array($employeeSession) ? ($employeeSession['Rol'] ?? $employeeSession['rol'] ?? '') : '';
$employeeRole = strtolower(trim((string)$employeeRoleRaw));
if (!in_array($employeeRole, ['admin', 'func'], true)) {
  $employeeRole = 'admin';
}
$isEmployeeFunc = $employeeRole === 'func';
$employeeId = 0;
if (is_array($employeeSession)) {
  $employeeIdRaw = $employeeSession['ID_Barber'] ?? $employeeSession['id_barber'] ?? null;
  if ($employeeIdRaw !== null && $employeeIdRaw !== '' && is_numeric($employeeIdRaw)) {
    $employeeId = (int)$employeeIdRaw;
  }
}

if ($isEmployeeFunc && $employeeId > 0) {
  $reservas = array_values(array_filter($reservas, static function ($row) use ($employeeId) {
    $rowId = $row['ID_Barber'] ?? $row['id_barber'] ?? null;
    if ($rowId === null || $rowId === '' || !is_numeric($rowId)) {
      return false;
    }
    return (int)$rowId === $employeeId;
  }));
}

$pendingReservations = 0;
$todayDateObj = new DateTime('today');
foreach ($reservas as $rv) {
  if (!is_array($rv)) continue;
  $statusRaw = strtolower(trim((string)($rv['Status'] ?? $rv['status'] ?? '')));
  if ($statusRaw !== '' && $statusRaw !== 'pendiente' && $statusRaw !== 'sin confirmar') {
    continue;
  }
  $dateRaw = trim((string)($rv['Fecha_Reserva'] ?? ''));
  if ($dateRaw === '') continue;
  $dateObj = DateTime::createFromFormat('Y-m-d', $dateRaw) ?: DateTime::createFromFormat('d/m/Y', $dateRaw);
  if ($dateObj && $dateObj < $todayDateObj) {
    continue;
  }
  $pendingReservations++;
}
$clientes = AutoloadDB::all('clientes');
$barberos = AutoloadDB::all('barberos');
$servicios = AutoloadDB::all('servicios');
$productos = AutoloadDB::all('productos');
$clientesMap = [];
foreach ($clientes as $cliente) {
  $cid = (string)($cliente['ID_Cliente'] ?? '');
  if ($cid !== '') {
    $clientesMap[$cid] = $cliente;
  }
}
$barberosMap = [];
foreach ($barberos as $barbero) {
  $bid = (string)($barbero['ID_Barber'] ?? '');
  if ($bid !== '') {
    $barberosMap[$bid] = $barbero;
  }
}
$serviciosMap = [];
foreach ($servicios as $servicio) {
  $sid = (string)($servicio['ID_Servicio'] ?? '');
  if ($sid !== '') {
    $serviciosMap[$sid] = $servicio;
  }
}
$carritos = AutoloadDB::all('carrito');
$infoBarberia = [];
$dbFull = @include dirname(__DIR__, 3) . '/src/db/database.php';
if (is_array($dbFull) && isset($dbFull['info_barberia']) && is_array($dbFull['info_barberia'])) {
  $infoBarberia = $dbFull['info_barberia'];
if (!isset($infoBarberia['temas']) || !is_array($infoBarberia['temas'])) {
  $infoBarberia['temas'] = ['publico' => 'oscuro', 'privado' => 'oscuro'];
} else {
  if (!isset($infoBarberia['temas']['publico']) || !in_array($infoBarberia['temas']['publico'], ['oscuro', 'claro'], true)) {
    $infoBarberia['temas']['publico'] = 'oscuro';
  }
  if (!isset($infoBarberia['temas']['privado']) || !in_array($infoBarberia['temas']['privado'], ['oscuro', 'claro'], true)) {
    $infoBarberia['temas']['privado'] = 'oscuro';
  }
}
}
$businessName = '';
$businessNameRaw = $infoBarberia['nombre'] ?? '';
if (is_string($businessNameRaw)) {
  $businessName = trim($businessNameRaw);
}
if ($businessName === '') {
  $businessName = 'Nombre sin definir';
}
$pushConfigPath = dirname(__DIR__, 3) . '/src/config/push.php';
$pushConfig = @include $pushConfigPath;
$pushPublicKey = '';
if (is_array($pushConfig) && isset($pushConfig['publicKey'])) {
  $pushPublicKey = trim((string)$pushConfig['publicKey']);
}
require __DIR__ . '/../src/php/plan_banner_from_sqlite.php';
$tenantPublicUrl = ($tenantSlug !== '' && $tenantSlug !== 'template')
  ? \Agenduy\Core\CommercePanel::publicUrlForSlug($tenantSlug)
  : url('');
$scheduleDays = [];
if (isset($infoBarberia['horarios']) && is_array($infoBarberia['horarios'])) {
  $dayNameMap = [
    'lunes' => 'Lunes',
    'martes' => 'Martes',
    'miercoles' => 'Miercoles',
    'jueves' => 'Jueves',
    'viernes' => 'Viernes',
    'sabado' => 'Sabado',
    'sábado' => 'Sabado',
    'domingo' => 'Domingo',
  ];
  foreach ($infoBarberia['horarios'] as $dayKey => $dayConfig) {
    if (!is_array($dayConfig)) {
      continue;
    }
    $normalizedKey = strtolower(trim((string)$dayKey));
    if ($normalizedKey === '' || $normalizedKey === 'feriados') {
      continue;
    }
    $label = $dayNameMap[$normalizedKey] ?? ucwords(str_replace('_', ' ', $normalizedKey));
    $scheduleDays[$normalizedKey] = [
      'label' => $label,
      'abierto' => isset($dayConfig['abierto']) ? (bool)$dayConfig['abierto'] : true,
    ];
  }
}

$normalizePrice = static function($value): float {
  if ($value === null || $value === '') {
    return 0.0;
  }
  if (is_numeric($value)) {
    return (float)$value;
  }
  if (!is_string($value)) {
    return 0.0;
  }
  $clean = preg_replace('/[^0-9,.\-]/', '', $value);
  if ($clean === '' || $clean === '-' || $clean === '.' || $clean === ',') {
    return 0.0;
  }
  $lastComma = strrpos($clean, ',');
  $lastDot = strrpos($clean, '.');
  if ($lastComma !== false && $lastDot !== false) {
    if ($lastComma > $lastDot) {
      $clean = str_replace('.', '', $clean);
      $clean = str_replace(',', '.', $clean);
    } else {
      $clean = str_replace(',', '', $clean);
    }
  } elseif ($lastComma !== false) {
    $clean = str_replace(',', '.', $clean);
  } else {
    $clean = str_replace(',', '', $clean);
  }
  return is_numeric($clean) ? (float)$clean : 0.0;
};

$serviceNameMap = [];
$servicePriceMap = [];
foreach ($servicios as $srv) {
  $sid = isset($srv['ID_Servicio']) ? (string)$srv['ID_Servicio'] : '';
  if ($sid === '') { continue; }
  $serviceNameMap[$sid] = trim((string)($srv['Nombre'] ?? ('Servicio ' . $sid)));
  $servicePriceMap[$sid] = $normalizePrice($srv['Precio'] ?? 0);
}
$serviceNameJson = json_encode($serviceNameMap, JSON_UNESCAPED_UNICODE);
if (!is_string($serviceNameJson)) { $serviceNameJson = '{}'; }

$barberNameMap = [];
foreach ($barberos as $barbero) {
  $bid = $barbero['ID_Barber'] ?? null;
  if ($bid === null || $bid === '' || !is_numeric($bid)) { continue; }
  $nombre = trim((string)($barbero['Nombre'] ?? ''));
  $apellido = trim((string)($barbero['Apellido'] ?? ''));
  $barberNameMap[(string)(int)$bid] = trim($nombre . ' ' . $apellido) ?: 'Profesional ' . (int)$bid;
}
$barberCommissionMap = [];

$normalizeCommission = static function($value) {
  if ($value === null || $value === '') { return null; }
  $normalized = str_replace(',', '.', (string)$value);
  if (!is_numeric($normalized)) { return null; }
  $float = (float)$normalized;
  if (!is_finite($float)) { return null; }
  if ($float < 0 || $float > 100) { return null; }
  return round($float, 4);
};

$totalReservas = count($reservas);
$reservasHoy = 0; $reservasPend = 0; $reservasActivas = 0;
$statusRegistry = [];
foreach ($reservas as $r) {
  $st = strtolower(trim((string)($r['Status'] ?? '')));
  if ($st === '') { $st = 'pendiente'; }
  $statusRegistry[$st] = true;
  if ((string)($r['Fecha_Reserva'] ?? '') === $today) { $reservasHoy++; }
  if ($st === 'pendiente') { $reservasPend++; }
  if ($st !== 'finalizado' && $st !== 'cancelado') { $reservasActivas++; }
}

$statusOrderSeed = ['pendiente', 'aprobado', 'en progreso', 'rechazado', 'cancelado', 'finalizado'];
$statusList = [];
foreach ($statusOrderSeed as $seed) {
  if (isset($statusRegistry[$seed])) {
    $statusList[] = $seed;
    unset($statusRegistry[$seed]);
  }
}
if (!empty($statusRegistry)) {
  foreach ($statusRegistry as $rest => $_) {
    $statusList[] = $rest;
  }
}
$statusFilter = strtolower(trim((string)($_GET['res_status'] ?? '')));
$hasPendiente = in_array('pendiente', $statusList, true);
$defaultStatus = $hasPendiente ? 'pendiente' : 'todos';
if ($statusFilter === '' || ($statusFilter !== 'todos' && !in_array($statusFilter, $statusList, true))) {
  $statusFilter = $defaultStatus;
}

$dateFilter = trim((string)($_GET['res_date'] ?? ''));
if ($dateFilter !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFilter)) {
  $dateFilter = '';
}

$reservaDatesMap = [];
foreach ($reservas as $r) {
  if (!is_array($r)) continue;
  $fechaRaw = trim((string)($r['Fecha_Reserva'] ?? ''));
  if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaRaw)) {
    $reservaDatesMap[$fechaRaw] = true;
  }
}
$reservaDates = array_keys($reservaDatesMap);
sort($reservaDates);
$reservaDatesJson = json_encode(array_values($reservaDates), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$statusOptions = array_merge(['todos'], $statusList);
$formatStatusLabel = static function($value) {
  $value = strtolower(trim((string)$value));
  if ($value === '' || $value === 'pendiente') { return 'Pendiente'; }
  return ucwords($value);
};
$statusOptionLabels = [];
foreach ($statusOptions as $option) {
  $statusOptionLabels[$option] = ($option === 'todos') ? 'Todos' : $formatStatusLabel($option);
}
$currentStatusLabel = $statusOptionLabels[$statusFilter] ?? ($statusFilter === 'todos' ? 'Todos' : $formatStatusLabel($statusFilter));
$nowTs = time();
$ultimas = [];
foreach ($reservas as $r) {
  $st = strtolower(trim((string)($r['Status'] ?? '')));
  if ($st === '') { $st = 'pendiente'; }

  $fecha = trim((string)($r['Fecha_Reserva'] ?? ''));
  $hora = trim((string)($r['Hora_Reserva'] ?? ''));
  $hora = $hora !== '' ? $hora : '00:00:00';
  $timestamp = PHP_INT_MAX;
  if ($fecha !== '') {
    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $fecha . ' ' . $hora);
    if (!$dt) {
      $dt = DateTime::createFromFormat('Y-m-d H:i', $fecha . ' ' . substr($hora, 0, 5));
    }
    if ($dt instanceof DateTime) {
      $timestamp = (int)$dt->format('U');
    }
  }

  $include = false;
  if ($statusFilter === 'todos') {
    $include = true;
  } elseif ($statusFilter === 'pendiente') {
    $include = ($st === 'pendiente') && ($timestamp !== PHP_INT_MAX) && ($timestamp >= $nowTs);
  } else {
    $include = ($st === $statusFilter);
  }
  if ($include && $dateFilter !== '' && $fecha !== $dateFilter) {
    $include = false;
  }

  if ($include) {
    $r['_status_norm'] = $st;
    $r['_timestamp'] = $timestamp;
    $ultimas[] = $r;
  }
}

usort($ultimas, function($a, $b) {
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
$renderedCount = count($ultimas);

$formatNumber = static function($value) {
  return number_format((int)$value, 0, ',', '.');
};
$normalizeAmount = static function($value): float {
  if ($value === null || $value === '') {
    return 0.0;
  }
  if (is_numeric($value)) {
    return (float)$value;
  }
  $normalized = str_replace(',', '.', (string)$value);
  return is_numeric($normalized) ? (float)$normalized : 0.0;
};
$formatCurrency = static function($value): string {
  return '$ ' . number_format((float)$value, 2, ',', '.');
};

$finalizedAmount = 0.0;
foreach ($ultimas as $row) {
  if (($row['_status_norm'] ?? '') !== 'finalizado') {
    continue;
  }
  $sid = (string)($row['ID_Servicio'] ?? '');
  $serviceData = $serviciosMap[$sid] ?? [];
  $finalizedAmount += $normalizeAmount($serviceData['Precio'] ?? 0);
}
$finalizedAmountLabel = 'Total finalizado: ' . $formatCurrency($finalizedAmount);

$barberStats = [
  'total' => 0,
  'online' => 0,
  'offline' => 0,
  'disponibles' => 0,
  'admins' => 0,
  'func' => 0,
  'con_comision' => 0,
];
foreach ($barberos as $barbero) {
  $id = $barbero['ID_Barber'] ?? null;
  if ($id === null || $id === '' || !is_numeric($id)) { continue; }
  $barberStats['total']++;
  $status = strtolower(trim((string)($barbero['Status'] ?? '')));
  if ($status === 'online') { $barberStats['online']++; }
  if ($status === 'offline' || $status === '') { $barberStats['offline']++; }
  $dispo = strtolower(trim((string)($barbero['Disponibilidad'] ?? '')));
  if ($dispo === 'disponible') { $barberStats['disponibles']++; }
  $rol = strtolower(trim((string)($barbero['Rol'] ?? '')));
  if ($rol === 'admin') { $barberStats['admins']++; }
  else { $barberStats['func']++; }
  $comRaw = $barbero['Comision'] ?? null;
  if ($comRaw !== null && $comRaw !== '') {
    $normalized = str_replace(',', '.', (string)$comRaw);
    if (is_numeric($normalized)) {
      $barberStats['con_comision']++;
    }
  }
  $commissionNormalized = $normalizeCommission($barbero['Comision'] ?? null);
  if ($commissionNormalized !== null) {
    $barberCommissionMap[(string)(int)$id] = $commissionNormalized;
  }
}

$clientIdsWithReservations = [];
foreach ($reservas as $reserva) {
  $cid = $reserva['ID_Cliente'] ?? null;
  if ($cid === null || $cid === '' || !is_numeric($cid)) { continue; }
  $clientIdsWithReservations[(string)$cid] = true;
}
$clientesStats = [
  'total' => 0,
  'con_reservas' => count($clientIdsWithReservations),
  'con_email' => 0,
  'con_telefono' => 0,
];
$clientNameMap = [];
foreach ($clientes as $cliente) {
  $id = $cliente['ID_Cliente'] ?? null;
  if ($id === null || $id === '' || !is_numeric($id)) { continue; }
  $clientesStats['total']++;
  $email = trim((string)($cliente['Email'] ?? ''));
  if ($email !== '') { $clientesStats['con_email']++; }
  $tel = trim((string)($cliente['Telefono'] ?? ''));
  $wa = trim((string)($cliente['Whatsapp'] ?? ''));
  if ($tel !== '' || $wa !== '') { $clientesStats['con_telefono']++; }
  $nombre = trim((string)($cliente['Nombre'] ?? ''));
  $apellido = trim((string)($cliente['Apellido'] ?? ''));
  $clientNameMap[(string)(int)$id] = trim($nombre . ' ' . $apellido) ?: 'Cliente ' . (int)$id;
}

$productoTipos = [];
$productosStats = [
  'total' => 0,
  'con_imagen' => 0,
  'con_puntos' => 0,
];
$productNameMap = [];
$productPriceMap = [];
$productTypeMap = [];
$productPointsMap = [];
foreach ($productos as $producto) {
  $id = $producto['ID_Product'] ?? null;
  if ($id === null || $id === '' || !is_numeric($id)) { continue; }
  $productosStats['total']++;
  $img = trim((string)($producto['Img_src'] ?? ''));
  if ($img !== '') { $productosStats['con_imagen']++; }
  $puntos = $producto['Puntos'] ?? null;
  if ($puntos !== null && $puntos !== '' && is_numeric($puntos) && (int)$puntos > 0) {
    $productosStats['con_puntos']++;
  }
  $tipo = trim((string)($producto['Tipo'] ?? ''));
  if ($tipo === '') { $tipo = 'Otro'; }
  $tipoKey = function_exists('mb_strtolower') ? mb_strtolower($tipo, 'UTF-8') : strtolower($tipo);
  $tipoKey = preg_replace('/\s+/', ' ', $tipoKey);
  $tipoKey = trim((string)$tipoKey);
  if ($tipoKey === '') { $tipoKey = 'otro'; }
  $productoTipos[$tipoKey] = true;
  $productIdKey = (string)(int)$id;
  $productNameMap[$productIdKey] = trim((string)($producto['Nombre'] ?? ('Producto ' . $productIdKey)));
  $productPriceMap[$productIdKey] = isset($producto['Precio']) && is_numeric($producto['Precio']) ? (float)$producto['Precio'] : 0.0;
$productTypeMap[$productIdKey] = $tipo;
  $productPointsMap[$productIdKey] = isset($producto['Puntos']) && is_numeric($producto['Puntos']) ? (int)$producto['Puntos'] : 0;
}
$productosStats['tipos'] = count($productoTipos);

$productTypeLabels = [];
foreach ($productTypeMap as $typeLabel) {
  $cleanLabel = trim((string)$typeLabel);
  if ($cleanLabel === '') { $cleanLabel = 'Otro'; }
  $productTypeLabels[$cleanLabel] = true;
}
$productTypesList = array_keys($productTypeLabels);
natcasesort($productTypesList);
$productTypesList = array_values($productTypesList);

$parseCartItems = static function($raw) {
  if (!is_string($raw) || trim($raw) === '') {
    return [];
  }
  $result = [];
  if (preg_match_all('/\(?\s*(\d+)\s*\+\s*(\d+)\s*\)?/', $raw, $matches, PREG_SET_ORDER)) {
    foreach ($matches as $match) {
      $pid = (int)$match[1];
      $qty = (int)$match[2];
      if ($pid > 0 && $qty > 0) {
        $result[] = ['product' => $pid, 'quantity' => $qty];
      }
    }
    return $result;
  }
  $parts = preg_split('/[,;]+/', $raw);
  foreach ($parts as $part) {
    if (preg_match('/(\d+)\s*\+\s*(\d+)/', $part, $match)) {
      $pid = (int)$match[1];
      $qty = (int)$match[2];
      if ($pid > 0 && $qty > 0) {
        $result[] = ['product' => $pid, 'quantity' => $qty];
      }
    }
  }
  return $result;
};

$productSalesEntries = [];
$cartOrders = [];
$cartStatusCounts = [];
$cartStatusLabels = [];
$cartStatusOrderSeed = ['pendiente', 'cancelado', 'finalizado'];
foreach ($carritos as $carrito) {
  $orderId = $carrito['ID_Carrito'] ?? null;
  if ($orderId === null || $orderId === '' || !is_numeric($orderId)) { continue; }
  $clientIdRaw = $carrito['ID_Cliente'] ?? null;
  $clientId = ($clientIdRaw !== null && $clientIdRaw !== '' && is_numeric($clientIdRaw)) ? (int)$clientIdRaw : null;
  $dateRaw = trim((string)($carrito['Fecha'] ?? ''));
  if ($dateRaw === '') { continue; }
  $dateObj = DateTime::createFromFormat('Y-m-d', $dateRaw);
  if (!$dateObj instanceof DateTime) {
    $dateObj = DateTime::createFromFormat('Y-m-d H:i:s', $dateRaw . ' 00:00:00');
  }
  if (!$dateObj instanceof DateTime) { continue; }
  $dateIso = $dateObj->format('Y-m-d');
  $monthIso = $dateObj->format('Y-m');
  $timeRaw = trim((string)($carrito['Hora'] ?? ''));
  $statusRaw = strtolower(trim((string)($carrito['Status'] ?? '')));
  if ($statusRaw === '') { $statusRaw = 'pendiente'; }
  $itemsRaw = $carrito['ID_Producto + Cantidad'] ?? '';
  $items = $parseCartItems($itemsRaw);
  if (!$items) { continue; }
  $orderSummaryItems = [];
  foreach ($items as $item) {
    $pid = $item['product'];
    $qty = $item['quantity'];
    $productKey = (string)$pid;
    $productSalesEntries[] = [
      'order_id' => (int)$orderId,
      'client_id' => $clientId,
      'client_name' => $clientId !== null ? ($clientNameMap[(string)$clientId] ?? ('Cliente ' . $clientId)) : null,
      'product_id' => $pid,
      'product_name' => $productNameMap[$productKey] ?? ('Producto ' . $pid),
      'product_type' => $productTypeMap[$productKey] ?? 'Otro',
      'quantity' => $qty,
      'unit_price' => $productPriceMap[$productKey] ?? 0.0,
      'unit_points' => $productPointsMap[$productKey] ?? 0,
      'date' => $dateIso,
      'month' => $monthIso,
      'time' => $timeRaw,
      'status' => $statusRaw,
    ];
    $orderSummaryItems[] = $qty . ' x ' . ($productNameMap[$productKey] ?? ('Producto ' . $pid));
  }
  $address = '';
  foreach (['Dirección', 'Direcci?n', 'Direccion'] as $dirKey) {
    if (!isset($carrito[$dirKey])) { continue; }
    $candidate = trim((string)$carrito[$dirKey]);
    if ($candidate !== '') {
      $address = $candidate;
      break;
    }
  }
  $statusKey = $statusRaw;
  $cartStatusCounts[$statusKey] = ($cartStatusCounts[$statusKey] ?? 0) + 1;
  $cartStatusLabels[$statusKey] = $formatStatusLabel($statusRaw);
  $itemsData = [];
  foreach ($items as $item) {
    $pid = (int)$item['product'];
    $qty = (int)$item['quantity'];
    $itemsData[] = [
      'product' => $pid,
      'quantity' => $qty,
      'name' => $productNameMap[(string)$pid] ?? ('Producto ' . $pid),
    ];
  }
  $cartOrders[] = [
    'id' => (int)$orderId,
    'client' => $clientId !== null ? ($clientNameMap[(string)$clientId] ?? ('Cliente ' . $clientId)) : 'Cliente sin asignar',
    'status_key' => $statusKey,
    'status_label' => $cartStatusLabels[$statusKey],
    'date' => $dateObj->format('d/m/Y'),
    'time' => $timeRaw,
    'address' => $address,
    'items' => $orderSummaryItems,
    'items_data' => $itemsData,
    'sort_key' => $dateIso . 'T' . ($timeRaw !== '' ? $timeRaw : '00:00:00'),
  ];
}
$ordersProductCatalog = [];
foreach ($productNameMap as $pidKey => $pname) {
  $ordersProductCatalog[] = [
    'id' => (int)$pidKey,
    'name' => $pname,
    'price' => $productPriceMap[$pidKey] ?? 0.0,
  ];
}
if ($cartOrders) {
  usort($cartOrders, static function($a, $b) {
    return strcmp($b['sort_key'], $a['sort_key']);
  });
  foreach ($cartOrders as &$orderEntry) {
    unset($orderEntry['sort_key']);
  }
  unset($orderEntry);
}
$cartTotalOrders = count($cartOrders);
$cartStatusOptions = $cartStatusOrderSeed;
foreach (array_keys($cartStatusCounts) as $statusKey) {
  if (!in_array($statusKey, $cartStatusOptions, true)) {
    $cartStatusOptions[] = $statusKey;
  }
}
$cartStatusLabelsResolved = [];
foreach ($cartStatusOptions as $statusKey) {
  $cartStatusLabelsResolved[$statusKey] = $cartStatusLabels[$statusKey] ?? $formatStatusLabel($statusKey);
}
$cartPendingCount = (int)($cartStatusCounts['pendiente'] ?? 0);
$cartDefaultStatus = 'pendiente';
if ($cartPendingCount === 0) {
  if (($cartStatusCounts['finalizado'] ?? 0) > 0) {
    $cartDefaultStatus = 'finalizado';
  } else {
    foreach ($cartStatusOptions as $candidateStatus) {
      if (($cartStatusCounts[$candidateStatus] ?? 0) > 0) {
        $cartDefaultStatus = $candidateStatus;
        break;
      }
    }
  }
}

$cartActiveStatus = $cartDefaultStatus;
// Cart icon badge always reflects actionable (pending) orders.
$cartActiveStatusCount = $cartPendingCount;
$hasAnyCartOrders = $cartTotalOrders > 0;

$productSummaryList = [];
foreach ($productNameMap as $id => $name) {
  $productSummaryList[] = [
    'id' => (int)$id,
    'name' => $name,
    'type' => $productTypeMap[$id] ?? 'Otro',
    'price' => $productPriceMap[$id] ?? 0.0,
    'points' => $productPointsMap[$id] ?? 0,
  ];
}
usort($productSummaryList, static function($a, $b) {
  return strcasecmp($a['name'], $b['name']);
});

$productClientList = [];
foreach ($clientNameMap as $id => $name) {
  $productClientList[] = [
    'id' => (int)$id,
    'name' => $name,
  ];
}
usort($productClientList, static function($a, $b) {
  return strcasecmp($a['name'], $b['name']);
});

$serviciosStats = [
  'total' => 0,
  'activos' => 0,
  'inactivos' => 0,
  'con_imagen' => 0,
  'duracion_sum' => 0,
  'duracion_count' => 0,
];
foreach ($servicios as $servicio) {
  $id = $servicio['ID_Servicio'] ?? null;
  if ($id === null || $id === '' || !is_numeric($id)) { continue; }
  $serviciosStats['total']++;
  $estado = strtolower(trim((string)($servicio['Estado'] ?? '')));
  if ($estado === 'inactivo') { $serviciosStats['inactivos']++; }
  else { $serviciosStats['activos']++; }
  $img = trim((string)($servicio['Img_Link'] ?? ''));
  if ($img !== '') { $serviciosStats['con_imagen']++; }
  $duracion = $servicio['Duracion'] ?? null;
  if ($duracion !== null && $duracion !== '' && is_numeric($duracion)) {
    $serviciosStats['duracion_sum'] += (int)$duracion;
    $serviciosStats['duracion_count']++;
  }
}
$serviciosStats['duracion_promedio'] = $serviciosStats['duracion_count'] > 0
  ? (int)round($serviciosStats['duracion_sum'] / $serviciosStats['duracion_count'])
  : 0;

$reservasFinanceEntries = [];
foreach ($reservas as $reserva) {
  $rid = $reserva['ID_Reserva'] ?? null;
  if ($rid === null || $rid === '' || !is_numeric($rid)) { continue; }
  $fechaRaw = trim((string)($reserva['Fecha_Reserva'] ?? ''));
  if ($fechaRaw === '') { continue; }
  $fechaObj = DateTime::createFromFormat('Y-m-d', $fechaRaw);
  if (!$fechaObj instanceof DateTime) {
    $fechaObj = DateTime::createFromFormat('Y-m-d H:i:s', $fechaRaw . ' 00:00:00');
  }
  if (!$fechaObj instanceof DateTime) { continue; }
  $fecha = $fechaObj->format('Y-m-d');
  $mesId = $fechaObj->format('Y-m');
  $hora = trim((string)($reserva['Hora_Reserva'] ?? ''));
  $statusRaw = strtolower(trim((string)($reserva['Status'] ?? '')));
  if ($statusRaw === '') { $statusRaw = 'pendiente'; }
  $sid = isset($reserva['ID_Servicio']) ? (string)(int)$reserva['ID_Servicio'] : '';
  $precio = 0.0;
  if (isset($reserva['Precio']) && is_numeric($reserva['Precio']) && (float)$reserva['Precio'] > 0) {
    $precio = (float)$reserva['Precio'];
  } elseif ($sid !== '' && isset($servicePriceMap[$sid])) {
    $precio = (float)$servicePriceMap[$sid];
  }
  $barberIdRaw = $reserva['ID_Barber'] ?? null;
  $barberId = ($barberIdRaw !== null && $barberIdRaw !== '' && is_numeric($barberIdRaw)) ? (int)$barberIdRaw : null;
  $barberKey = $barberId !== null ? (string)$barberId : 'unassigned';
  $barberCommission = $barberId !== null ? ($barberCommissionMap[(string)$barberId] ?? null) : null;
  $clientesId = $reserva['ID_Cliente'] ?? null;
  $reservasFinanceEntries[] = [
    'id' => (int)$rid,
    'date' => $fecha,
    'month' => $mesId,
    'status' => $statusRaw,
    'price' => $precio,
    'serviceId' => $sid !== '' ? (int)$sid : null,
    'serviceName' => $sid !== '' ? ($serviceNameMap[$sid] ?? ('Servicio ' . $sid)) : 'Servicio',
    'barberId' => $barberId,
    'barberKey' => $barberKey,
    'barberName' => $barberId !== null ? ($barberNameMap[(string)$barberId] ?? ('Profesional ' . $barberId)) : 'Sin asignar',
    'barberCommission' => $barberCommission,
    'clientId' => ($clientesId !== null && $clientesId !== '' && is_numeric($clientesId)) ? (int)$clientesId : null,
    'time' => $hora,
  ];
}

$barberSummaryList = [];
foreach ($barberNameMap as $id => $name) {
  $barberSummaryList[] = [
    'id' => (int)$id,
    'name' => $name,
    'commission_rate' => $barberCommissionMap[$id] ?? null,
  ];
}
usort($barberSummaryList, static function($a, $b) {
  return strcasecmp($a['name'], $b['name']);
});

$summaryCards = [
  [
    'title' => 'Reservas',
    'subtitle' => $formatNumber($totalReservas) . ' registradas',
    'items' => [
      ['label' => 'Hoy', 'value' => $formatNumber($reservasHoy)],
      ['label' => 'Activas', 'value' => $formatNumber($reservasActivas)],
      ['label' => 'Pendientes', 'value' => $formatNumber($reservasPend)],
    ],
    'cta_type' => 'modal',
    'modal' => 'reservas-summary',
  ],
  [
    'title' => 'Profesionales',
    'subtitle' => $formatNumber($barberStats['total']) . ' en el equipo',
    'items' => [
      ['label' => 'Disponibles', 'value' => $formatNumber($barberStats['disponibles'])],
      ['label' => 'Online', 'value' => $formatNumber($barberStats['online'])],
      ['label' => 'Con comisión', 'value' => $formatNumber($barberStats['con_comision'])],
    ],
    'cta_type' => 'link',
    'target' => '#funcionarios',
  ],
  [
    'title' => 'Clientes',
    'subtitle' => $formatNumber($clientesStats['total']) . ' registrados',
    'items' => [
      ['label' => 'Con reservas', 'value' => $formatNumber($clientesStats['con_reservas'])],
      ['label' => 'Con email', 'value' => $formatNumber($clientesStats['con_email'])],
      ['label' => 'Con teléfono', 'value' => $formatNumber($clientesStats['con_telefono'])],
    ],
    'cta_type' => 'link',
    'target' => '#clientes',
  ],
  [
    'title' => 'Productos',
    'subtitle' => $formatNumber($productosStats['total']) . ' en catálogo',
    'items' => [
      ['label' => 'Con imagen', 'value' => $formatNumber($productosStats['con_imagen'])],
      ['label' => 'Con puntos', 'value' => $formatNumber($productosStats['con_puntos'])],
      ['label' => 'Tipos distintos', 'value' => $formatNumber($productosStats['tipos'])],
    ],
    'cta_type' => 'modal',
    'modal' => 'productos-summary',
  ],
  [
    'title' => 'Servicios',
    'subtitle' => $formatNumber($serviciosStats['total']) . ' publicados',
    'items' => [
      ['label' => 'Activos', 'value' => $formatNumber($serviciosStats['activos'])],
      ['label' => 'Con imagen', 'value' => $formatNumber($serviciosStats['con_imagen'])],
      ['label' => 'Duración promedio', 'value' => $serviciosStats['duracion_promedio'] > 0 ? $serviciosStats['duracion_promedio'] . ' min' : 'Sin datos'],
    ],
    'cta_type' => 'link',
    'target' => '#servicios',
  ],
];
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#7c3aed">
  <meta name="url-base" content="<?php echo e($publicShareUrl !== '' ? $publicShareUrl : $tenantPublicUrl); ?>">
  <meta name="tenant-slug" content="<?php echo e($tenantSlug); ?>">
  <title>Panel · Agendarte UY</title>
  <link rel="manifest" href="../manifest.admin.php">
  <link rel="stylesheet" href="../../../src/css/main.css">
  <link rel="stylesheet" href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css">
  <link rel="stylesheet" href="../src/admin.css">
  <link rel="stylesheet" href="<?php echo e(\Agenduy\Core\AdminBrand::cssUrl()); ?>">
  <link rel="stylesheet" href="../src/reservas-ledger.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.css">
  <link rel="icon" type="image/png" sizes="32x32" href="<?php echo e(\Agenduy\Core\AdminBrand::faviconUrl()); ?>">
  <link rel="apple-touch-icon" href="<?php echo e(\Agenduy\Core\AdminBrand::iconUrl()); ?>">
</head>
<body data-employee-role="<?php echo e($employeeRole); ?>">
  <div class="admin-layout is-collapsed">
    <aside class="admin-aside">
      <div class="admin-brand">
        <?php echo \Agenduy\Core\AdminBrand::sidebarBrandInnerHtml(); ?>
        <small class="muted admin-brand__tenant"><?php echo e($infoBarberia['rubro_nombre'] ?? ($businessName !== '' ? $businessName : 'Mi negocio')); ?></small>
      </div>
      <nav class="admin-nav">
        <a class="admin-link" href="#resumen">Resumen</a>
        <a class="admin-link" href="#reservas">Reservas</a>
        <a class="admin-link" href="#clientes">Clientes</a>
        <a class="admin-link" href="#funcionarios">Profesionales</a>
        <a class="admin-link" href="#servicios">Servicios</a>
        <a class="admin-link" href="#productos">Productos</a>
        <a class="admin-link" href="#config">Configuración</a>
      </nav>
    </aside>

    <main class="admin-main">
      <header class="admin-main__header">
        <div class="admin-heading-group">
          <?php if ($publicShareUrl !== ''): ?>
          <div class="admin-share-link">
            <p class="admin-share-link__label">Comparte este enlace con tus clientes</p>
            <button type="button"
                    class="admin-share-link__btn"
                    data-share-copy="<?php echo e($publicShareUrl); ?>">
              <i class="bx bx-world" aria-hidden="true"></i>
              <span><?php echo e($publicShareUrlDisplay); ?></span>
              <i class="bx bx-copy" aria-hidden="true"></i>
            </button>
            <small class="admin-share-link__hint" data-share-hint>Copiá y envía a tus clientes.</small>
          </div>
          <?php else: ?>
          <h1 class="admin-heading">Dashboard</h1>
          <?php endif; ?>
        </div>
        <details class="admin-orders"<?php echo $hasAnyCartOrders ? '' : ' data-empty="1"'; ?> data-active-status="<?php echo e($cartActiveStatus); ?>">
          <summary class="admin-orders__summary" aria-label="Pedidos">
            <i class="bx bx-cart" aria-hidden="true"></i>
            <span class="admin-orders__badge"><?php echo $cartActiveStatusCount; ?></span>
          </summary>
          <div class="admin-orders__dropdown">
            <?php if ($hasAnyCartOrders): ?>
              <div class="admin-orders__filters" role="tablist" aria-label="Filtrar pedidos por estado">
                <?php foreach ($cartStatusOptions as $statusOption): ?>
                  <?php
                    $statusLabel = $cartStatusLabelsResolved[$statusOption] ?? $formatStatusLabel($statusOption);
                    $statusCount = (int)($cartStatusCounts[$statusOption] ?? 0);
                    $isActiveStatus = ($statusOption === $cartActiveStatus);
                  ?>
                  <button
                    type="button"
                    class="admin-orders__filter-btn<?php echo $isActiveStatus ? ' is-active' : ''; ?>"
                    data-status="<?php echo e($statusOption); ?>"
                    data-count="<?php echo $statusCount; ?>"
                    aria-pressed="<?php echo $isActiveStatus ? 'true' : 'false'; ?>">
                    <span class="admin-orders__filter-label"><?php echo e($statusLabel); ?></span>
                    <span class="admin-orders__filter-count"><?php echo $statusCount; ?></span>
                  </button>
                <?php endforeach; ?>
              </div>
              <script type="application/json" id="admin-orders-catalog"><?php echo json_encode($ordersProductCatalog, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS); ?></script>
              <div class="admin-orders__list" data-role="orders-list">
                <?php foreach ($cartOrders as $order): ?>
                <?php
                  $orderItemsJson = json_encode($order['items_data'] ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS);
                  if ($orderItemsJson === false) { $orderItemsJson = '[]'; }
                ?>
                <article
                  class="admin-orders__item<?php echo $order['status_key'] === 'pendiente' ? ' is-pending' : ''; ?>"
                  data-order-status="<?php echo e($order['status_key']); ?>"
                  data-order-id="<?php echo (int)$order['id']; ?>"
                  data-items='<?php echo $orderItemsJson; ?>'>
                  <header class="admin-orders__item-header">
                    <span class="admin-orders__item-id">Pedido #<?php echo e($order['id']); ?></span>
                    <span class="admin-orders__item-status"><?php echo e($order['status_label']); ?></span>
                  </header>
                  <dl class="admin-orders__meta">
                    <div>
                      <dt>Cliente</dt>
                      <dd><?php echo e($order['client']); ?></dd>
                    </div>
                    <div>
                      <dt>Fecha</dt>
                      <dd><?php echo e($order['date']); ?><?php if ($order['time'] !== ''): ?> | <?php echo e($order['time']); ?><?php endif; ?></dd>
                    </div>
                    <?php if ($order['address'] !== ''): ?>
                      <div>
                        <dt>Entrega</dt>
                        <dd><?php echo e($order['address']); ?></dd>
                      </div>
                    <?php endif; ?>
                  </dl>
                  <?php if (!empty($order['items'])): ?>
                    <ul class="admin-orders__items">
                      <?php foreach ($order['items'] as $itemLine): ?>
                        <li><?php echo e($itemLine); ?></li>
                    <?php endforeach; ?>
                    </ul>
                  <?php else: ?>
                    <ul class="admin-orders__items" hidden></ul>
                  <?php endif; ?>
                  <div class="admin-orders__actions">
                    <div class="admin-orders__sale-actions"<?php echo $order['status_key'] === 'pendiente' ? '' : ' hidden'; ?>>
                      <button
                        type="button"
                        class="admin-orders__sale-btn admin-orders__sale-btn--finalize"
                        data-order-action="finalize"
                        data-order-id="<?php echo (int)$order['id']; ?>">
                        Finalizar venta
                      </button>
                      <button
                        type="button"
                        class="admin-orders__sale-btn admin-orders__sale-btn--cancel"
                        data-order-action="cancel"
                        data-order-id="<?php echo (int)$order['id']; ?>">
                        Cancelar venta
                      </button>
                      <button
                        type="button"
                        class="admin-orders__sale-btn admin-orders__sale-btn--edit"
                        data-order-action="edit"
                        data-order-id="<?php echo (int)$order['id']; ?>"
                        aria-expanded="false">
                        Cambiar venta
                      </button>
                    </div>
                    <div class="admin-orders__status-row">
                      <label class="admin-orders__status-select-label" for="order-status-<?php echo (int)$order['id']; ?>">Estado</label>
                      <select
                        id="order-status-<?php echo (int)$order['id']; ?>"
                        class="admin-orders__status-select"
                        data-order-id="<?php echo (int)$order['id']; ?>"
                        data-current-status="<?php echo e($order['status_key']); ?>">
                        <?php foreach ($cartStatusOptions as $statusOption): ?>
                          <option value="<?php echo e($statusOption); ?>"<?php echo $statusOption === $order['status_key'] ? ' selected' : ''; ?>>
                            <?php echo e($cartStatusLabelsResolved[$statusOption] ?? $formatStatusLabel($statusOption)); ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                  </div>
                  <div class="admin-orders__edit" data-order-edit="<?php echo (int)$order['id']; ?>" hidden></div>
                </article>
              <?php endforeach; ?>
              </div>
              <p class="admin-orders__empty admin-orders__empty--filter" data-role="no-results">Sin pedidos para este estado.</p>
            <?php else: ?>
              <p class="admin-orders__empty">Sin pedidos registrados.</p>
            <?php endif; ?>
          </div>
        </details>
      </header>
      <?php if ($planBannerData !== null):
        $planStatusAttr = isset($planBannerData['status']) ? (string)$planBannerData['status'] : '';
        $planDaysAttr = (isset($planBannerData['days_remaining']) && $planBannerData['days_remaining'] !== null)
          ? (string)(int)$planBannerData['days_remaining']
          : '';
        $planRenewalAttr = isset($planBannerData['renewal_iso']) ? (string)$planBannerData['renewal_iso'] : '';
        $planBusinessAttr = isset($planBannerData['business_id']) ? (string)$planBannerData['business_id'] : '';
      ?>
        <section
          class="admin-plan-banner <?php echo e($planBannerData['class']); ?>"
          data-plan-banner
          data-plan-status="<?php echo e($planStatusAttr); ?>"
          data-plan-days="<?php echo e($planDaysAttr); ?>"
          data-plan-renovacion="<?php echo e($planRenewalAttr); ?>"
          data-plan-business="<?php echo e($planBusinessAttr); ?>"
        >
          <div class="admin-plan-banner__body">
            <div class="admin-plan-banner__title-row">
              <?php if (!empty($planBannerData['badge'])): ?>
                <span class="admin-plan-banner__badge"><?php echo e($planBannerData['badge']); ?></span>
              <?php endif; ?>
              <h2 class="admin-plan-banner__title"><?php echo e($planBannerData['title']); ?></h2>
            </div>
            <?php if (!empty($planBannerData['message'])): ?>
              <p class="admin-plan-banner__message"><?php echo e($planBannerData['message']); ?></p>
            <?php endif; ?>
            <?php if (!empty($planBannerData['details'])): ?>
              <ul class="admin-plan-banner__details admin-plan-banner__details--inline">
                <?php foreach ($planBannerData['details'] as $detail): ?>
                  <?php
                    $detailLabel = $detail['label'] ?? '';
                    $detailValueRaw = $detail['value'] ?? '';
                    $detailValue = is_string($detailValueRaw) ? trim($detailValueRaw) : (string)$detailValueRaw;
                  ?>
                  <?php if ($detailValue !== ''): ?>
                    <li class="admin-plan-banner__detail">
                      <span class="admin-plan-banner__detail-label"><?php echo e($detailLabel); ?></span>
                      <span class="admin-plan-banner__detail-value"><?php echo e($detailValue); ?></span>
                    </li>
                  <?php endif; ?>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </div>
          <div class="admin-plan-banner__actions">
            <button type="button" class="btn btn-outline admin-plan-banner__cta" data-plan-membership-open>
              <?php echo e($planBannerData['cta_label'] ?? 'Ver planes'); ?>
            </button>
          </div>
        </section>
      <?php endif; ?>
      <section class="admin-section" id="resumen">
        <div class="summary-grid">
          <?php foreach ($summaryCards as $card): ?>
            <article class="summary-card">
              <header class="summary-card__header">
                <h3><?php echo e($card['title'] ?? ''); ?></h3>
                <p class="summary-card__subtitle"><?php echo e($card['subtitle'] ?? ''); ?></p>
              </header>
              <?php if (!empty($card['items']) && is_array($card['items'])): ?>
                <ul class="summary-card__list">
                  <?php foreach ($card['items'] as $item): ?>
                    <li class="summary-card__list-item">
                      <span class="summary-card__label"><?php echo e($item['label'] ?? ''); ?></span>
                      <span class="summary-card__value"><?php echo e($item['value'] ?? ''); ?></span>
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>
              <?php
                $ctaType = $card['cta_type'] ?? 'link';
                $modalId = $card['modal'] ?? '';
                if ($ctaType === 'modal' && $modalId !== ''):
              ?>
                <button type="button" class="summary-card__cta" data-admin-summary-modal="<?php echo e($modalId); ?>">Ver más detalles</button>
              <?php else: ?>
                <a class="summary-card__cta" href="<?php echo e($card['target'] ?? '#'); ?>">Ver más detalles</a>
              <?php endif; ?>
            </article>
          <?php endforeach; ?>
        </div>
      </section>

      <section class="admin-section" id="reservas">
        <div class="admin-section-tools">
          <div class="admin-reservas-filter">
            <label for="admin-reserva-status" class="admin-reservas-filter__label">Estado</label>
            <select id="admin-reserva-status" name="res_status" class="admin-reservas-filter__select" data-admin-reserva-filter data-admin-reserva-default="<?php echo e($defaultStatus); ?>">
              <?php foreach ($statusOptions as $option): ?>
                <option value="<?php echo e($option); ?>"<?php echo $option === $statusFilter ? ' selected' : ''; ?>>
                  <?php echo e($statusOptionLabels[$option] ?? ucfirst($option)); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="admin-reservas-filter">
            <label for="admin-reserva-date" class="admin-reservas-filter__label">Fecha</label>
            <input
              type="text"
              id="admin-reserva-date"
              class="admin-reservas-filter__input"
              data-admin-reserva-date
              data-admin-reserva-dates="<?php echo e($reservaDatesJson); ?>"
              data-admin-reserva-date-default="<?php echo e($dateFilter); ?>"
              value="<?php echo e($dateFilter); ?>"
              placeholder="Todas"
              autocomplete="off"
              inputmode="none"
              readonly
            >
            <button
              type="button"
              class="admin-reservas-filter__clear"
              data-admin-reserva-date-clear
              title="Ver todas las fechas"
              aria-label="Limpiar fecha"
              <?php echo $dateFilter === '' ? 'hidden' : ''; ?>
            >&times;</button>
          </div>
          <span class="admin-section-count" data-admin-reserva-count>Total (<?php echo e($currentStatusLabel); ?>): <?php echo (int)$renderedCount; ?></span>
          <span class="admin-section-total" data-admin-reserva-amount><?php echo e($finalizedAmountLabel); ?></span>
        </div>
        <div class="table-wrap table-wrap--scroll">
          <table class="table" data-admin-reservas-table>
            <thead>
              <tr>
                <th>Cliente</th>
                <th>Profesional</th>
                <th>Servicio</th>
                <th class="numeric">Precio</th>
                <th>Fecha</th>
                <th>Hora</th>
                <th>Status</th>
                <th>Accion</th>
              </tr>
            </thead>
            <tbody>
              <?php
              foreach ($ultimas as $r):
                $cid = (string)($r['ID_Cliente'] ?? '');
                $bid = (string)($r['ID_Barber'] ?? '');
                $sid = (string)($r['ID_Servicio'] ?? '');
                $cn = trim(($clientesMap[$cid]['Nombre'] ?? 'Cliente'));
                $bn = trim(($barberosMap[$bid]['Nombre'] ?? 'Profesional') . ' ' . ($barberosMap[$bid]['Apellido'] ?? ''));
                $serviceData = $serviciosMap[$sid] ?? [];
                $sn = ($serviceData['Nombre'] ?? 'Servicio');
                if (isset($r['Precio']) && is_numeric($r['Precio']) && (float)$r['Precio'] > 0) {
                  $servicePrice = $normalizeAmount($r['Precio']);
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
                $st = isset($r['_status_norm']) ? $r['_status_norm'] : strtolower(trim((string)($r['Status'] ?? 'pendiente')));
                $stLabel = $statusOptionLabels[$st] ?? ucwords(str_replace(['_', '-'], ' ', $st));
                $cls = 'st-' . $st;
                $timestampAttr = '';
                if (isset($r['_timestamp']) && $r['_timestamp'] !== PHP_INT_MAX) {
                  $timestampAttr = (string)(int)$r['_timestamp'];
                }
              ?>
              <tr
                data-admin-reserva-item
                data-admin-res-row-id="<?php echo (int)($r['ID_Reserva'] ?? 0); ?>"
                data-admin-reserva-status="<?php echo e($st); ?>"
                data-admin-reserva-fecha="<?php echo e($r['Fecha_Reserva'] ?? ''); ?>"
                data-admin-reserva-hora="<?php echo e(substr((string)($r['Hora_Reserva'] ?? ''), 0, 5)); ?>"
                data-admin-reserva-ts="<?php echo e($timestampAttr); ?>"
                data-admin-reserva-price="<?php echo e($servicePriceLabel); ?>"
              >
                <td><?php echo e($cn); ?></td>
                <td><?php echo e(trim($bn)); ?></td>
                <td><span class="reserva-servicio__name"><?php echo e($sn); ?></span></td>
                <td class="numeric"><?php echo e($servicePriceLabel); ?></td>
                <td><?php echo e($r['Fecha_Reserva'] ?? ''); ?></td>
                <td><?php echo e(substr((string)($r['Hora_Reserva'] ?? ''),0,5)); ?></td>
                <td><span class="status-pill <?php echo e($cls); ?>"><?php echo e($stLabel); ?></span></td>
                <td>
                  <?php if (in_array($st, ['pendiente','aprobado','rechazado','cancelado','finalizado'], true)): ?>
                    <?php if ($st === 'pendiente'): ?>
                      <button
                        type="button"
                        class="btn btn-success btn-sm admin-reserva-attend"
                        data-admin-view-reserva="<?php echo (int)($r['ID_Reserva'] ?? 0); ?>"
                        data-admin-reserva-attend
                      >Ver y Atender</button>
                    <?php else: ?>
                      <button
                        type="button"
                        class="btn btn-warning btn-sm"
                        data-admin-view-reserva="<?php echo (int)($r['ID_Reserva'] ?? 0); ?>"
                      >Ver</button>
                    <?php endif; ?>
                  <?php else: ?>
                    <span class="muted">-</span>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <p class="muted admin-reservas-empty" data-admin-reserva-empty data-empty-base="No hay reservas para el estado seleccionado."<?php echo $renderedCount > 0 ? ' hidden' : ''; ?>>No hay reservas para el estado seleccionado.</p>
      </section>

      <section class="admin-section" id="clientes">
        <div class="admin-clients">
          <div class="admin-clients-controls">
            <button type="button" class="btn btn-success admin-clients-add" data-admin-client-create>
              <i class="bx bx-user-plus" aria-hidden="true"></i>
              <span>Agregar cliente</span>
            </button>
            <?php
              $clientsCountNow = (int)count($clientes);
              $clientsCountLabel = $maxClientsLimit === null
                ? ('Registrados ' . $clientsCountNow)
                : ('Registrados ' . $clientsCountNow . '/' . (int)$maxClientsLimit);
            ?>
            <span
              class="admin-section-count admin-clients-count"
              data-admin-client-count
              data-max-clients="<?php echo $maxClientsLimit === null ? '' : (int)$maxClientsLimit; ?>"
            ><?php echo e($clientsCountLabel); ?></span>
          </div>
          <div class="admin-clients-list" data-admin-client-list>
            <?php
            $renderedClients = 0;
            foreach ($clientes as $cliente):
              $id = $cliente['ID_Cliente'] ?? null;
              if ($id === null || $id === '' || !is_numeric($id)) { continue; }
              $renderedClients++;
              $name = trim((string)($cliente['Nombre'] ?? 'Cliente sin nombre'));
              $email = trim((string)($cliente['Email'] ?? ''));
              $telefono = trim((string)($cliente['Telefono'] ?? ''));
              $cedula = trim((string)($cliente['Cedula'] ?? ''));
              $photo = trim((string)($cliente['Perfil'] ?? ''));
              $photo = str_replace('\\', '/', $photo);
              $photoUrl = '';
              if ($photo !== '') {
                if (preg_match('#^https?://#i', $photo)) {
                  $photoUrl = $photo;
                } else {
                  $photoUrl = '../../../' . ltrim($photo, '/');
                }
              }
              $initials = '';
              if ($name !== '') {
                $parts = preg_split('/\s+/', $name);
                $initials = strtoupper(substr($parts[0] ?? '', 0, 1) . substr($parts[1] ?? '', 0, 1));
                if ($initials === '') {
                  $initials = strtoupper(substr($name, 0, 1));
                }
              }
              if ($initials === '') { $initials = 'U'; }
              $fallbackPhoto = '../../../src/img/users/default.php?n=' . rawurlencode($name ?: 'U');
            ?>
            <article class="admin-client-card" data-admin-client-item data-admin-client-id="<?php echo (int)$id; ?>" data-admin-client-name="<?php echo e(strtolower($name)); ?>">
              <div class="admin-client-info">
                <div class="admin-client-avatar">
                  <?php if ($photoUrl !== ''): ?>
                    <img src="<?php echo e($photoUrl); ?>" alt="<?php echo e($name); ?>" loading="lazy" onerror="this.onerror=null;this.src='<?php echo e($fallbackPhoto); ?>';">
                  <?php else: ?>
                    <span><?php echo e($initials); ?></span>
                  <?php endif; ?>
                </div>
                <div class="admin-client-meta">
                  <p class="admin-client-name"><?php echo e($name); ?></p>
                  <p class="admin-client-sub">
                    <?php if ($email !== ''): ?>
                      <span><?php echo e($email); ?></span>
                    <?php endif; ?>
                    <?php if ($telefono !== ''): ?>
                      <span><?php echo e($telefono); ?></span>
                    <?php endif; ?>
                    <?php if ($cedula !== ''): ?>
                      <span>CI <?php echo e($cedula); ?></span>
                    <?php endif; ?>
                  </p>
                </div>
              </div>
              <div class="admin-client-actions">
                <button type="button" class="admin-client-edit" data-admin-client-edit="<?php echo (int)$id; ?>" aria-label="Editar cliente">
                  <i class="bx bx-edit-alt"></i>
                </button>
                <button type="button" class="admin-client-delete" data-admin-client-delete="<?php echo (int)$id; ?>" aria-label="Eliminar cliente">
                  <i class="bx bx-trash"></i>
                </button>
              </div>
            </article>
            <?php endforeach; ?>
            <?php if ($renderedClients === 0): ?>
              <p class="muted admin-clients-empty">A&uacute;n no tienes Clientes registrados.</p>
            <?php endif; ?>
          </div>
        </div>
      </section>
      <section class="admin-section" id="funcionarios">
        <div class="admin-barbers" data-admin-barber-services="<?php echo e($serviceNameJson); ?>">
          <?php
          $adminBarberCount = 0;
          foreach ($barberos as $barTemp) {
            $bid = $barTemp['ID_Barber'] ?? null;
            if ($bid !== null && $bid !== '' && is_numeric($bid)) {
              $adminBarberCount++;
            }
          }
          ?>
          <div class="admin-barbers-header admin-section-tools">
            <button type="button" class="admin-barbers-create" data-admin-barber-create>
              <i class="bx bx-user-plus"></i>
              Registrar un Profesional
            </button>
            <span class="admin-section-count admin-barbers-count" data-admin-barber-count>Total: <?php echo (int)$adminBarberCount; ?></span>
          </div>
          <div class="admin-barbers-list" data-admin-barber-list>
            <?php
            $hasBarbers = false;
            foreach ($barberos as $barbero):
              $id = $barbero['ID_Barber'] ?? null;
              if ($id === null || $id === '' || !is_numeric($id)) { continue; }
              $hasBarbers = true;
              $nombre = trim((string)($barbero['Nombre'] ?? ''));
              $apellido = trim((string)($barbero['Apellido'] ?? ''));
              $fullname = trim($nombre . ' ' . $apellido);
              if ($fullname === '') { $fullname = 'Profesional sin nombre'; }
              $cedula = trim((string)($barbero['Cedula'] ?? ''));
              $dispo = trim((string)($barbero['Disponibilidad'] ?? ''));
              $rol = trim((string)($barbero['Rol'] ?? ''));
              $status = trim((string)($barbero['Status'] ?? ''));
              $habilidadesRaw = trim((string)($barbero['Habilidades'] ?? ''));
              $habilidadesNames = [];
              if ($habilidadesRaw !== '') {
                $parts = preg_split('/\s*,\s*/', str_replace(';', ',', $habilidadesRaw));
                foreach ($parts as $part) {
                  $idSkill = trim((string)$part);
                  if ($idSkill === '') { continue; }
                  if (isset($serviceNameMap[$idSkill])) {
                    $habilidadesNames[] = $serviceNameMap[$idSkill];
                  }
                }
              }
              $photo = trim((string)($barbero['Perfil'] ?? ''));
              $photo = str_replace('\\', '/', $photo);
              $photoUrl = '';
              if ($photo !== '') {
                if (preg_match('#^https?://#i', $photo)) {
                  $photoUrl = $photo;
                } else {
                  $photoUrl = '../../../' . ltrim($photo, '/');
                }
              }
              $initials = '';
              if ($nombre !== '' || $apellido !== '') {
                $parts = preg_split('/\s+/', $fullname);
                $initials = strtoupper(substr($parts[0] ?? '', 0, 1) . substr($parts[1] ?? '', 0, 1));
                if ($initials === '') {
                  $initials = strtoupper(substr($fullname, 0, 1));
                }
              }
              if ($initials === '') { $initials = 'B'; }
              $fallbackPhoto = '../../../src/img/users/default.php?n=' . rawurlencode($fullname ?: 'B');
            ?>
            <article class="admin-barber-card" data-admin-barber-item data-admin-barber-id="<?php echo (int)$id; ?>">
              <div class="admin-barber-info">
                <div class="admin-barber-avatar">
                  <?php if ($photoUrl !== ''): ?>
                    <img src="<?php echo e($photoUrl); ?>" alt="<?php echo e($fullname); ?>" loading="lazy" onerror="this.onerror=null;this.src='<?php echo e($fallbackPhoto); ?>';">
                  <?php else: ?>
                    <span><?php echo e($initials); ?></span>
                  <?php endif; ?>
                </div>
                <div class="admin-barber-meta">
                  <p class="admin-barber-name"><?php echo e($fullname); ?></p>
                  <p class="admin-barber-sub">
                    <?php if ($cedula !== ''): ?>
                      <span>CI <?php echo e($cedula); ?></span>
                    <?php endif; ?>
                    <?php if ($rol !== ''): ?>
                      <span>Rol: <?php echo e($rol); ?></span>
                    <?php endif; ?>
                    <?php if ($dispo !== ''): ?>
                      <span><?php echo e($dispo); ?></span>
                    <?php endif; ?>
                  </p>
                  <?php if (!empty($habilidadesNames)): ?>
                    <p class="admin-barber-skills">
                      <?php foreach ($habilidadesNames as $skillName): ?>
                        <span><?php echo e($skillName); ?></span>
                      <?php endforeach; ?>
                    </p>
                  <?php endif; ?>
                  <?php if ($status !== ''): ?>
                    <span class="admin-barber-status admin-barber-status--<?php echo e(strtolower($status)); ?>"><?php echo e($status); ?></span>
                  <?php endif; ?>
                </div>
              </div>
              <div class="admin-barber-actions">
                <button type="button" class="admin-barber-edit" data-admin-barber-edit="<?php echo (int)$id; ?>" aria-label="Editar profesional">
                  <i class="bx bx-edit-alt"></i>
                </button>
                <button type="button" class="admin-barber-delete" data-admin-barber-delete="<?php echo (int)$id; ?>" aria-label="Eliminar profesional">
                  <i class="bx bx-trash"></i>
                </button>
              </div>
            </article>
            <?php endforeach; ?>
          </div>
          <p class="muted admin-barbers-empty" data-empty-base="Aún no tienes Profesionales registrados."<?php echo $hasBarbers ? ' hidden' : ''; ?>>Aún no tienes Profesionales registrados.</p>
        </div>
      </section>
      <section class="admin-section" id="servicios">
        <div class="admin-services-header">
          <button type="button" class="btn btn-success admin-services-add" data-admin-service-create>
            <i class="bx bx-plus"></i>
            <span>Agregar servicio</span>
          </button>
        </div>
        <?php
          $serviceCountTotal = 0;
          foreach ($servicios as $srvTmp) {
            $sidTmp = $srvTmp['ID_Servicio'] ?? null;
            if ($sidTmp === null || $sidTmp === '' || !is_numeric($sidTmp)) { continue; }
            $serviceCountTotal++;
          }
        ?>
        <div class="admin-section-tools">
          <span class="admin-section-count admin-services-count" data-admin-service-count>Total: <?php echo (int)$serviceCountTotal; ?></span>
        </div>
        <div class="admin-services-grid" data-admin-service-list>
          <?php
            $serviceCount = 0;
            foreach ($servicios as $srv):
              $sid = $srv['ID_Servicio'] ?? null;
              if ($sid === null || $sid === '' || !is_numeric($sid)) { continue; }
              $serviceCount++;
              $sidStr = (string)(int)$sid;
              $name = trim((string)($srv['Nombre'] ?? ('Servicio ' . $sidStr)));
              $duration = $srv['Duracion'] ?? '';
              $estado = trim((string)($srv['Estado'] ?? 'Activo'));
              $precio = $srv['Precio'] ?? '';
              $puntos = $srv['Puntos'] ?? '';
              $imgRel = trim((string)($srv['Img_Link'] ?? ''));
              $imgUrl = '';
              if ($imgRel !== '') {
                if (preg_match('/^https?:\\/\\//i', $imgRel)) {
                  $imgUrl = $imgRel;
                } else {
                  $imgUrl = '../../../' . ltrim($imgRel, '/');
                }
              }
              $statusClass = 'admin-service-status--' . (strtolower($estado) === 'activo' ? 'active' : 'inactive');
              $precioFmt = is_numeric($precio) ? number_format((float)$precio, 0, ',', '.') : trim((string)$precio);
              $puntosFmt = ($puntos === null || $puntos === '' || !is_numeric($puntos))
                ? 'No asignados'
                : number_format((float)$puntos, 0, ',', '.');
              $durationFmt = is_numeric($duration) ? ((int)$duration . ' min') : trim((string)$duration);
          ?>
          <article class="admin-service-card"
            data-admin-service-item
            data-admin-service-id="<?php echo e($sidStr); ?>"
            data-admin-service-name="<?php echo e($name); ?>"
            data-admin-service-duration="<?php echo e((string)$duration); ?>"
            data-admin-service-status="<?php echo e($estado); ?>"
            data-admin-service-price="<?php echo e((string)$precio); ?>"
            data-admin-service-points="<?php echo e((string)$puntos); ?>"
            data-admin-service-image="<?php echo e($imgRel); ?>">
            <div class="admin-service-thumb">
              <?php if ($imgUrl !== ''): ?>
                <img src="<?php echo e($imgUrl); ?>" alt="<?php echo e($name); ?>" loading="lazy">
              <?php else: ?>
                <span class="admin-service-thumb-placeholder"><i class="bx bx-image-alt"></i></span>
              <?php endif; ?>
            </div>
            <div class="admin-service-content">
              <header class="admin-service-header">
                <h3><?php echo e($name); ?></h3>
                <span class="admin-service-status <?php echo e($statusClass); ?>"><?php echo e($estado ?: 'Activo'); ?></span>
              </header>
              <ul class="admin-service-meta">
                <li><i class="bx bx-time"></i><?php echo e($durationFmt ?: 'Sin duraci?n'); ?></li>
                <li><i class="bx bx-purchase-tag"></i>$ <?php echo e($precioFmt !== '' ? $precioFmt : '0'); ?></li>
                <li><i class="bx bx-gift"></i><?php echo e($puntosFmt); ?></li>
              </ul>
            </div>
            <div class="admin-service-actions">
              <button type="button" class="admin-service-edit" data-admin-service-edit="<?php echo e($sidStr); ?>" aria-label="Editar servicio">
                <i class="bx bx-edit-alt"></i>
              </button>
              <button type="button" class="admin-service-delete" data-admin-service-delete="<?php echo e($sidStr); ?>" aria-label="Eliminar servicio">
                <i class="bx bx-trash"></i>
              </button>
            </div>
          </article>
          <?php endforeach; ?>
        </div>
        <p class="muted admin-services-empty" data-empty-base="A&uacute;n no tienes Servicios registrados."<?php echo ($serviceCount ?? 0) > 0 ? ' hidden' : ''; ?>>A&uacute;n no tienes Servicios registrados.</p>
      </section>
      <section class="admin-section" id="productos">
        <?php
          $productCountTotal = 0;
          $productTypeOptions = [];
          foreach ($productos as $prodTmp) {
            $pidTmp = $prodTmp['ID_Product'] ?? null;
            if ($pidTmp === null || $pidTmp === '' || !is_numeric($pidTmp)) { continue; }
            $productCountTotal++;
            $tipoLabel = trim((string)($prodTmp['Tipo'] ?? ''));
            if ($tipoLabel === '') { $tipoLabel = 'Otro'; }
            $tipoKeyRaw = function_exists('mb_strtolower') ? mb_strtolower($tipoLabel, 'UTF-8') : strtolower($tipoLabel);
            $tipoKeyRaw = preg_replace('/\s+/', ' ', $tipoKeyRaw);
            $tipoKey = trim((string)$tipoKeyRaw);
            if ($tipoKey === '') { $tipoKey = 'otro'; }
            if (!isset($productTypeOptions[$tipoKey])) {
              $productTypeOptions[$tipoKey] = $tipoLabel;
            }
          }
          if (!empty($productTypeOptions)) {
            uksort($productTypeOptions, static function($a, $b) {
              return strnatcasecmp($a, $b);
            });
          }
          $productsPlanDisabled = ($maxProductsLimit === 0);
          $productsAtLimit = ($maxProductsLimit !== null && $productCountTotal >= (int)$maxProductsLimit);
          $productsCountLabel = $maxProductsLimit === null
            ? ('Registrados ' . (int)$productCountTotal)
            : ('Registrados ' . (int)$productCountTotal . '/' . (int)$maxProductsLimit);
          $productsEmptyBase = $productsPlanDisabled
            ? 'Su plan no tiene habilitado cargar productos'
            : 'Aún no tienes Productos registrados.';
        ?>
        <div class="admin-products-header">
          <?php if ($productsPlanDisabled): ?>
            <button type="button" class="btn btn-outline admin-products-add" data-plan-membership-open title="Mejorá tu plan para cargar productos">
              <i class="bx bx-crown" aria-hidden="true"></i>
              <span>Mejorar plan</span>
            </button>
          <?php else: ?>
            <button type="button" class="btn btn-success admin-products-add" data-admin-product-create<?php echo $productsAtLimit ? ' disabled title="Alcanzaste el límite de productos de tu plan"' : ''; ?>>
              <i class="bx bx-plus"></i>
              <span>Agregar producto</span>
            </button>
          <?php endif; ?>
        </div>
        <div class="admin-section-tools">
          <div class="admin-products-filter">
            <label for="admin-product-type-filter" class="admin-products-filter__label">Tipo</label>
            <select id="admin-product-type-filter" class="admin-products-filter__select" data-admin-product-filter data-admin-product-default="todos">
              <option value="todos">Todos</option>
              <?php foreach ($productTypeOptions as $typeKey => $typeLabel): ?>
                <option value="<?php echo e($typeKey); ?>"><?php echo e($typeLabel); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <span class="admin-section-count admin-products-filter-count" data-admin-product-filter-count>Coincidencias: <?php echo (int)$productCountTotal; ?></span>
          <span
            class="admin-section-count admin-products-count"
            data-admin-product-count
            data-max-products="<?php echo $maxProductsLimit === null ? '' : (int)$maxProductsLimit; ?>"
          ><?php echo e($productsCountLabel); ?></span>
        </div>
        <div class="admin-products-grid" data-admin-product-list>
          <?php
            $productCount = 0;
            foreach ($productos as $prod):
              $pid = $prod['ID_Product'] ?? null;
              if ($pid === null || $pid === '' || !is_numeric($pid)) { continue; }
              $productCount++;
              $pidStr = (string)(int)$pid;
              $name = trim((string)($prod['Nombre'] ?? ('Producto ' . $pidStr)));
              $tipo = trim((string)($prod['Tipo'] ?? 'Otro'));
              $tipoKeyRaw = function_exists('mb_strtolower') ? mb_strtolower($tipo, 'UTF-8') : strtolower($tipo);
              $tipoKeyRaw = preg_replace('/\s+/', ' ', $tipoKeyRaw);
              $tipoKey = trim((string)$tipoKeyRaw);
              if ($tipoKey === '') { $tipoKey = 'otro'; }
              $precio = $prod['Precio'] ?? '';
              $descripcion = trim((string)($prod['Descripcion'] ?? ''));
              $puntos = $prod['Puntos'] ?? '';
              $imgRel = trim((string)($prod['Img_src'] ?? ''));
              $imgUrl = '';
              if ($imgRel !== '') {
                if (preg_match('/^https?:\\/\\//i', $imgRel)) {
                  $imgUrl = $imgRel;
                } else {
                  $imgUrl = '../../../' . ltrim($imgRel, '/');
                }
              }
              $precioFmt = is_numeric($precio) ? number_format((float)$precio, 0, ',', '.') : trim((string)$precio);
              $puntosFmt = ($puntos === null || $puntos === '' || !is_numeric($puntos))
                ? 'Sin puntos'
                : number_format((float)$puntos, 0, ',', '.');
          ?>
          <article class="admin-product-card"
            data-admin-product-item
            data-admin-product-id="<?php echo e($pidStr); ?>"
            data-admin-product-name="<?php echo e($name); ?>"
            data-admin-product-type="<?php echo e($tipo); ?>"
            data-admin-product-type-key="<?php echo e($tipoKey); ?>"
            data-admin-product-price="<?php echo e((string)$precio); ?>"
            data-admin-product-description="<?php echo e($descripcion); ?>"
            data-admin-product-points="<?php echo e((string)$puntos); ?>"
            data-admin-product-image="<?php echo e($imgRel); ?>">
            <div class="admin-product-thumb">
              <?php if ($imgUrl !== ''): ?>
                <img src="<?php echo e($imgUrl); ?>" alt="<?php echo e($name); ?>" loading="lazy">
              <?php else: ?>
                <span class="admin-product-thumb-placeholder"><i class="bx bx-package"></i></span>
              <?php endif; ?>
            </div>
            <div class="admin-product-content">
              <header class="admin-product-header">
                <h3><?php echo e($name); ?></h3>
              </header>
              <span class="admin-product-type"><?php echo e($tipo !== '' ? $tipo : 'Producto'); ?></span>
              <?php if ($descripcion !== ''): ?>
                <p class="admin-product-desc"><?php echo e($descripcion); ?></p>
              <?php else: ?>
                <p class="admin-product-desc muted">Sin descripci?n.</p>
              <?php endif; ?>
              <ul class="admin-product-meta">
                <li><i class="bx bx-purchase-tag"></i>$ <?php echo e($precioFmt !== '' ? $precioFmt : '0'); ?></li>
                <li><i class="bx bx-gift"></i><?php echo e($puntosFmt); ?></li>
              </ul>
            </div>
            <div class="admin-product-actions">
              <button type="button" class="admin-product-edit" data-admin-product-edit="<?php echo e($pidStr); ?>" aria-label="Editar producto">
                <i class="bx bx-edit-alt"></i>
              </button>
              <button type="button" class="admin-product-delete" data-admin-product-delete="<?php echo e($pidStr); ?>" aria-label="Eliminar producto">
                <i class="bx bx-trash"></i>
              </button>
            </div>
          </article>
          <?php endforeach; ?>
        </div>
        <p class="muted admin-products-empty" data-empty-base="<?php echo e($productsEmptyBase); ?>" data-empty-filter="No hay productos para el tipo seleccionado."<?php echo ($productCount ?? 0) > 0 ? ' hidden' : ''; ?>><?php echo e($productsEmptyBase); ?></p>
      </section>
      <section class="admin-section" id="config">
        <div class="admin-config-grid" data-admin-config-grid>
          <?php
            $configOptions = [
              ['id' => 'info', 'title' => 'Info. del Negocio', 'icon' => 'bx-buildings'],
              ['id' => 'horarios', 'title' => 'Horarios', 'icon' => 'bx-time-five'],
              ['id' => 'reservas', 'title' => 'Config. de Reservas', 'icon' => 'bx-calendar-check'],
              ['id' => 'moneda', 'title' => 'Config. de Moneda', 'icon' => 'bx-money'],
              ['id' => 'fiscal', 'title' => 'Config. Fiscal', 'icon' => 'bx-receipt'],
              ['id' => 'mercadopago', 'title' => 'Mercado Pago', 'icon' => 'bx-credit-card'],
              ['id' => 'redes', 'title' => 'Redes', 'icon' => 'bx-share-alt'],
              ['id' => 'seo', 'title' => 'SEO', 'icon' => 'bx-line-chart'],
              ['id' => 'notificaciones', 'title' => 'Notificaciones', 'icon' => 'bx-bell'],
              ['id' => 'legal', 'title' => 'Config. Legal', 'icon' => 'bx-shield-quarter'],
              ['id' => 'funciones', 'title' => 'Funciones', 'icon' => 'bx-wrench'],
              ['id' => 'temas', 'title' => 'Tema visual', 'icon' => 'bx-palette'],
            ];
            foreach ($configOptions as $option):
          ?>
            <button type="button"
              class="admin-config-card"
              data-admin-config-item
              data-admin-config-id="<?php echo e($option['id']); ?>">
              <span class="admin-config-icon"><i class="bx <?php echo e($option['icon']); ?>"></i></span>
              <span class="admin-config-label"><?php echo e($option['title']); ?></span>
            </button>
          <?php endforeach; ?>
        </div>
      </section>
    </main>

    <nav class="admin-bottomnav" aria-label="Navegaci?n r?pida">
      <a class="admin-bottomnav__item is-active" href="#resumen" data-admin-nav-target="resumen">
        <i class="bx bx-grid-alt" aria-hidden="true"></i>
        <span>Resumen</span>
        <span class="admin-bottomnav__badge" data-bottom-badge="resumen">1</span>
      </a>
      <a class="admin-bottomnav__item" href="#reservas" data-admin-nav-target="reservas">
        <i class="bx bx-calendar" aria-hidden="true"></i>
        <span>Reservas</span>
        <span class="admin-bottomnav__badge" data-bottom-badge="reservas"<?php echo $pendingReservations > 0 ? '' : ' hidden'; ?>>
          <?php echo (int)$pendingReservations; ?>
        </span>
      </a>
      <a class="admin-bottomnav__item" href="#clientes" data-admin-nav-target="clientes">
        <i class="bx bx-user" aria-hidden="true"></i>
        <span>Clientes</span>
      </a>
      <a class="admin-bottomnav__item" href="#funcionarios" data-admin-nav-target="funcionarios">
        <i class="bx bx-customize" aria-hidden="true"></i>
        <span>Profesionales</span>
      </a>
      <a class="admin-bottomnav__item" href="#servicios" data-admin-nav-target="servicios">
        <i class="bx bx-cut" aria-hidden="true"></i>
        <span>Servicios</span>
      </a>
      <a class="admin-bottomnav__item" href="#productos" data-admin-nav-target="productos">
        <i class="bx bx-package" aria-hidden="true"></i>
        <span>Productos</span>
      </a>
      <a class="admin-bottomnav__item" href="#config" data-admin-nav-target="config">
        <i class="bx bx-cog" aria-hidden="true"></i>
        <span>Config</span>
      </a>
      <button class="admin-bottomnav__item admin-bottomnav__logout" type="button" data-admin-logout>
        <i class="bx bx-log-out-circle" aria-hidden="true"></i>
        <span>Salir</span>
      </button>
    </nav>
  </div>
  <div id="admin-modal-root">
    <?php if (file_exists('../src/components/admin_reserva_modal.php')) { echo include '../src/components/admin_reserva_modal.php'; } ?>
    <?php if (file_exists('../src/components/admin_qr_modal.php')) { echo include '../src/components/admin_qr_modal.php'; } ?>
    <?php if (file_exists('../src/components/admin_cliente_modal.php')) { echo include '../src/components/admin_cliente_modal.php'; } ?>
    <?php if (file_exists('../src/components/admin_client_form_modal.php')) { echo include '../src/components/admin_client_form_modal.php'; } ?>
    <?php if (file_exists('../src/components/admin_historial_modal.php')) { echo include '../src/components/admin_historial_modal.php'; } ?>
    <?php if (file_exists('../src/components/admin_reservas_summary_modal.php')) { echo include '../src/components/admin_reservas_summary_modal.php'; } ?>
    <?php if (file_exists('../src/components/admin_productos_summary_modal.php')) { echo include '../src/components/admin_productos_summary_modal.php'; } ?>
    <?php if (file_exists('../src/components/admin_service_form_modal.php')) { echo include '../src/components/admin_service_form_modal.php'; } ?>
    <?php if (file_exists('../src/components/admin_service_modal.php')) { echo include '../src/components/admin_service_modal.php'; } ?>
    <?php if (file_exists('../src/components/admin_product_form_modal.php')) { echo include '../src/components/admin_product_form_modal.php'; } ?>
    <?php if (file_exists('../src/components/admin_barber_modal.php')) { echo include '../src/components/admin_barber_modal.php'; } ?>
    <?php if (file_exists('../src/components/admin_barber_edit_modal.php')) { echo include '../src/components/admin_barber_edit_modal.php'; } ?>
    <?php if (file_exists('../src/components/admin_business_info_modal.php')) { echo include '../src/components/admin_business_info_modal.php'; } ?>
    <?php if (file_exists('../src/components/admin_auth_guard_modal.php')) { echo include '../src/components/admin_auth_guard_modal.php'; } ?>
    <?php if (file_exists('../src/components/admin_config_redes_modal.php')) { echo include '../src/components/admin_config_redes_modal.php'; } ?>
    <?php if (file_exists('../src/components/admin_config_seo_modal.php')) { echo include '../src/components/admin_config_seo_modal.php'; } ?>
    <?php if (file_exists('../src/components/admin_config_notifications_modal.php')) { echo include '../src/components/admin_config_notifications_modal.php'; } ?>
    <?php if (file_exists('../src/components/admin_config_features_modal.php')) { echo include '../src/components/admin_config_features_modal.php'; } ?>
    <?php if (file_exists('../src/components/admin_config_mercadopago_modal.php')) { echo include '../src/components/admin_config_mercadopago_modal.php'; } ?>
  <?php if (file_exists('../src/components/admin_config_fiscal_modal.php')) { echo include '../src/components/admin_config_fiscal_modal.php'; } ?>
  <?php if (file_exists('../src/components/admin_config_moneda_modal.php')) { echo include '../src/components/admin_config_moneda_modal.php'; } ?>
    <?php if (file_exists('../src/components/admin_hours_modal.php')) { echo include '../src/components/admin_hours_modal.php'; } ?>
    <?php if (file_exists('../src/components/admin_reservas_config_modal.php')) { echo include '../src/components/admin_reservas_config_modal.php'; } ?>
    <?php if (file_exists('../src/components/admin_plan_trial_modal.php')) { echo include '../src/components/admin_plan_trial_modal.php'; } ?>
    <?php if (file_exists('../src/components/admin_plan_cancel_modal.php')) { echo include '../src/components/admin_plan_cancel_modal.php'; } ?>
    <?php if (file_exists('../src/components/admin_plan_membership_modal.php')) { echo include '../src/components/admin_plan_membership_modal.php'; } ?>
    <?php if (file_exists('../src/components/admin_theme_modal.php')) { echo include '../src/components/admin_theme_modal.php'; } ?>
    <?php if (file_exists('../src/components/admin_config_legales_modal.php')) { echo include '../src/components/admin_config_legales_modal.php'; } ?>
    <div class="modal" role="dialog" aria-modal="true" data-admin-modal="employee-guard" hidden>
      <div class="modal__backdrop" data-employee-guard-close></div>
      <div class="modal__dialog modal__dialog--sm">
        <header class="modal__header">
          <div class="modal__header-text">
            <p class="modal__eyebrow">Acceso restringido</p>
            <h2>Acción disponible solo para el dueño</h2>
          </div>
          <button type="button" class="modal__close" aria-label="Cerrar" data-employee-guard-close>&times;</button>
        </header>
        <div class="modal__body">
          <p class="modal__subtitle" data-employee-guard-message>Por Favor acceda con el usuario del dueño del negocio para poder acceder a esta secci&oacute;n.</p>
        </div>
        <footer class="modal__footer">
          <button type="button" class="btn btn-primary" data-employee-guard-close>Entendido</button>
        </footer>
      </div>
    </div>
  </div>
  <script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <?php
    $productLedgerPayload = [
      'entries' => $productSalesEntries,
      'currency_symbol' => $infoBarberia['moneda']['simbolo'] ?? '$',
      'currency_code' => $infoBarberia['moneda']['codigo'] ?? 'USD',
      'locale' => $infoBarberia['locale'] ?? 'es_UY',
      'products' => $productSummaryList,
      'clients' => $productClientList,
      'types' => $productTypesList,
    ];
    $productLedgerJson = json_encode($productLedgerPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($productLedgerJson)) { $productLedgerJson = '{"entries":[]}'; }
  ?>
  <script>
    window.ADMIN_PRODUCT_METRICS = <?php echo $productLedgerJson; ?>;
  </script>
  <?php
    $reservasFinancePayload = [
      'entries' => $reservasFinanceEntries,
      'currency_symbol' => $infoBarberia['moneda']['simbolo'] ?? '$',
      'currency_code' => $infoBarberia['moneda']['codigo'] ?? 'USD',
      'locale' => $infoBarberia['locale'] ?? 'es_UY',
      'barbers' => $barberSummaryList,
    ];
    $reservasFinanceJson = json_encode($reservasFinancePayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($reservasFinanceJson)) { $reservasFinanceJson = '{"entries":[]}'; }
  ?>
  <script>
    window.ADMIN_RESERVAS_METRICS = <?php echo $reservasFinanceJson; ?>;
  </script>
  <?php
    $infoBarberiaJson = json_encode($infoBarberia, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($infoBarberiaJson)) { $infoBarberiaJson = '{}'; }
  ?>
  <script>
    window.ADMIN_INFO_BARBERIA = <?php echo $infoBarberiaJson; ?>;
    window.ADMIN_EMPLOYEE_ROLE = <?php echo json_encode($employeeRole, JSON_UNESCAPED_UNICODE); ?>;
    window.AdminNotify = function(message, icon) {
      if (window.Swal && typeof window.Swal.mixin === 'function') {
        const toast = window.Swal.mixin({
          toast: true,
          position: 'top-end',
          showConfirmButton: false,
          timer: 2200,
          timerProgressBar: true
        });
        toast.fire({ icon: icon || 'success', title: message });
      } else {
        console.log('[NOTIFY]', message);
      }
    };
    window.adminNotify = window.AdminNotify;
  </script>
  <script>
    (function(){
      const shareBtn = document.querySelector('[data-share-copy]');
      if (!shareBtn) return;
      const hint = document.querySelector('[data-share-hint]');
      let timerId = null;

      const showHint = (text, success = true) => {
        if (!hint) return;
        hint.textContent = text;
        hint.classList.toggle('is-success', success);
        hint.classList.add('is-visible');
        if (timerId) window.clearTimeout(timerId);
        timerId = window.setTimeout(() => {
          hint.classList.remove('is-visible');
        }, 3000);
      };

      const legacyCopy = (text) => {
        const textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.setAttribute('readonly', '');
        textarea.style.position = 'absolute';
        textarea.style.left = '-9999px';
        document.body.appendChild(textarea);
        textarea.select();
        let ok = false;
        try {
          ok = document.execCommand('copy');
        } catch (_) {
          ok = false;
        }
        document.body.removeChild(textarea);
        return ok;
      };

      shareBtn.addEventListener('click', async () => {
        const url = shareBtn.getAttribute('data-share-copy') || '';
        if (!url) return;
        try {
          if (navigator.clipboard && navigator.clipboard.writeText) {
            await navigator.clipboard.writeText(url);
            showHint('Enlace copiado correctamente.');
            return;
          }
          if (legacyCopy(url)) {
            showHint('Enlace copiado correctamente.');
            return;
          }
          window.prompt('Copia este enlace y compártelo', url);
          showHint('Copiá manualmente el enlace.', false);
        } catch (_) {
          if (!legacyCopy(url)) {
            window.prompt('Copia este enlace y compártelo', url);
            showHint('Copiá manualmente el enlace.', false);
          } else {
            showHint('Enlace copiado correctamente.');
          }
        }
      });
    })();
  </script>
  <div class="modal" role="dialog" aria-modal="true" data-admin-modal="pwa-install" hidden>
    <div class="modal__backdrop" data-admin-pwa-dismiss></div>
    <div class="modal__dialog modal__dialog--sm">
      <header class="modal__header">
        <div class="modal__header-text">
          <p class="modal__eyebrow">Aplicación disponible</p>
          <h2>Instalar Agenda Pro</h2>
        </div>
        <button type="button" class="modal__close" aria-label="Cerrar" data-admin-pwa-dismiss>&times;</button>
      </header>
      <div class="modal__body">
        <p>¿Deseas instalar la aplicación? Podrás recibir notificaciones en tiempo real sobre reservas, pedidos y pagos aunque no tengas el panel abierto.</p>
        <p class="muted">Puedes activarla ahora o más tarde desde este mismo panel.</p>
      </div>
      <footer class="modal__footer">
        <button type="button" class="btn btn-muted" data-admin-pwa-dismiss>Más tarde</button>
        <button type="button" class="btn btn-primary" data-admin-pwa-accept>Instalar y activar</button>
      </footer>
    </div>
  </div>

  <script>
    window.__TENANT_CONFIG__ = {
      slug: <?php echo json_encode($tenantSlug, JSON_UNESCAPED_SLASHES); ?>,
      basePath: <?php echo json_encode(url(''), JSON_UNESCAPED_SLASHES); ?>,
      publicUrl: <?php echo json_encode($tenantPublicUrl, JSON_UNESCAPED_SLASHES); ?>,
      logoutUrl: <?php echo json_encode(url('admin/logout.php'), JSON_UNESCAPED_SLASHES); ?>
    };
    window.ADMIN_PUSH_PUBLIC_KEY = '<?php echo e($pushPublicKey); ?>';
    window.ADMIN_PUSH_ENDPOINT = '../../../src/API/AdminPush.php';
  </script>

  <script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/l10n/es.js"></script>
  <script src="../src/js/admin/core.js"></script>
  <script src="../src/js/admin/admin-orders.js"></script>
  <script src="../src/js/admin/plan-trial-modal.js"></script>
  <script src="../src/js/admin/plan-membership-modal.js"></script>
  <script src="../src/js/admin/modal-loading.js"></script>
  <script src="../src/js/admin/layout-sidebar.js"></script>
  <script src="../src/js/admin/bottom-nav.js?v=4"></script>
  <script src="../src/js/admin/reservas-filter.js"></script>
  <script src="../src/js/admin/clientes-list.js"></script>
  <script src="../src/js/admin/clientes-form.js"></script>
  <script src="../src/js/admin/barberos-list.js"></script>
  <script src="../src/js/admin/barbero-create-modal.js"></script>
  <script src="../src/js/admin/barbero-edit-modal.js"></script>
  <script src="../src/js/admin/reserva-modal.js"></script>
  <script src="../src/js/admin/reservas-summary-modal.js"></script>
  <script src="../src/js/admin/productos-summary-modal.js"></script>
  <script src="../src/js/admin/cliente-modal.js"></script>
  <script src="../src/js/admin/servicios-crud.js"></script>
  <script src="../src/js/admin/productos-crud.js"></script>
  <script src="../src/js/admin/service-modal.js"></script>
  <script src="../src/js/admin/config-info-modal.js"></script>
  <script src="../src/js/admin/admin-auth-guard.js"></script>
  <script src="../src/js/admin/admin-config-redes.js"></script>
  <script src="../src/js/admin/admin-config-seo.js"></script>
  <script src="../src/js/admin/admin-config-notificaciones.js"></script>
  <script src="../src/js/admin/admin-config-legales.js"></script>
  <script src="../src/js/admin/admin-config-features.js"></script>
  <script src="../src/js/admin/admin-config-theme.js"></script>
  <script src="../src/js/admin/admin-config-fiscal.js"></script>
  <script src="../src/js/admin/admin-config-moneda.js"></script>
  <script src="../src/js/admin/admin-config-mercadopago.js"></script>
  <script src="../src/js/admin/config-reservas-modal.js"></script>
  <script src="../src/js/admin/config-hours.js"></script>
  <script src="../src/js/admin/config-cards.js"></script>
  <script src="../src/js/admin/pwa.js"></script>
  <script src="../src/js/admin/employee-guard.js"></script>
  </body>
  </html>



