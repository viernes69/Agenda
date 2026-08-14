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

if (empty($_SESSION['admin_config_csrf']) || !is_string($_SESSION['admin_config_csrf'])) {
  $_SESSION['admin_config_csrf'] = bin2hex(random_bytes(32));
}
$centralPanelSlug = \Agenduy\Core\CommercePanel::centralSessionSlug();
$templateHost = \Agenduy\Core\CommercePanel::isTemplateHost(basename(dirname(__DIR__, 3)));
if ($centralPanelSlug !== '') {
  $tenantSlug = $centralPanelSlug;
  $commerceIdForPanel = (int)(\Agenduy\Core\Auth::commerceId() ?? 0);
  if ($commerceIdForPanel > 0) {
    \Agenduy\Core\CommercePanel::bootstrapCentralAccess($commerceIdForPanel, $tenantSlug);
  }
} elseif ($templateHost) {
  $commerceIdForPanel = (int)(\Agenduy\Core\Auth::commerceId() ?? 0);
  if ($commerceIdForPanel > 0) {
    $commerceRow = \Agenduy\Core\Database::getInstance()->fetchOne(
      'SELECT slug FROM commerces WHERE id_commerce = :id LIMIT 1',
      [':id' => $commerceIdForPanel]
    );
    $ownedSlugRow = trim((string)($commerceRow['slug'] ?? ''));
    if ($ownedSlugRow !== '') {
      $tenantSlug = $ownedSlugRow;
      \Agenduy\Core\CommercePanel::bootstrapCentralAccess($commerceIdForPanel, $ownedSlugRow);
    } else {
      $tenantSlug = 'template';
    }
  } else {
    $tenantSlug = 'template';
  }
} else {
  $tenantSlug = basename(dirname(__DIR__, 3));
}

if (
  $tenantSlug !== ''
  && !\Agenduy\Core\CommercePanel::isTemplateHost($tenantSlug)
  && (!defined('AGENDUY_LOCAL_DB_PATH') || AGENDUY_LOCAL_DB_PATH === '')
) {
  $commerceIdForPanel = (int)(\Agenduy\Core\CommercePanel::commerceIdForTenantRoot(dirname(__DIR__, 3)) ?? 0);
  if ($commerceIdForPanel > 0) {
    \Agenduy\Core\CommercePanel::bootstrapCentralAccess($commerceIdForPanel, $tenantSlug);
  }
}

// URL canónica: evitar /template/private/dashboard/admin/ en el navegador (acceso directo al archivo)
if (
    $templateHost
    && $tenantSlug !== ''
    && $tenantSlug !== 'template'
    && !defined('AGENDUY_COMMERCE_PANEL_EMBED')
) {
  $reqPath = strtok((string)($_SERVER['REQUEST_URI'] ?? ''), '?') ?: '';
  if (stripos($reqPath, '/template/private/dashboard/admin') !== false) {
    $section = \Agenduy\Core\CommercePanel::normalizeDashboardSection((string)($_GET['section'] ?? 'resumen'));
    $redirectQuery = [];
    if (!empty($_GET['setup'])) {
      $redirectQuery['setup'] = 'ok';
    }
    header('Location: ' . \Agenduy\Core\CommercePanel::dashboardUrlForSlug($tenantSlug, $section, $redirectQuery), true, 302);
    exit;
  }
}

function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function admin_payment_type_label(array $row, string $fallback): string {
  $method = strtolower(trim((string)($row['Metodo_Pago'] ?? $row['metodo_pago'] ?? $row['payment_method'] ?? '')));
  $paymentStatus = strtolower(trim((string)($row['Payment_Status'] ?? $row['payment_status'] ?? '')));
  $hasMpSignal = false;
  foreach (['MP_Preference_ID', 'MP_Payment_ID', 'MP_External_Reference', 'MP_Status_Detail'] as $key) {
    if (trim((string)($row[$key] ?? '')) !== '') {
      $hasMpSignal = true;
      break;
    }
  }
  if (
    str_contains($method, 'mercado')
    || $method === 'mp'
    || $method === 'mercadopago'
    || $hasMpSignal
    || in_array($paymentStatus, ['created', 'pending', 'approved', 'rejected', 'cancelled', 'canceled', 'refunded', 'charged_back'], true)
    || \Agenduy\Core\TenantLocalDb::isPaymentFailureStatus((string)($row['Status'] ?? ''))
  ) {
    return 'Mercado Pago';
  }
  if (str_contains($method, 'whatsapp') || str_contains($method, 'whats')) {
    return 'Pago WhatsApp';
  }
  if (str_contains($method, 'local') || str_contains($method, 'presencial') || str_contains($method, 'manual')) {
    return $fallback;
  }
  return $fallback;
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
if (
  class_exists(\Agenduy\Core\TenantLocalDb::class)
  && $tenantSlug !== ''
  && !\Agenduy\Core\CommercePanel::isTemplateHost($tenantSlug)
) {
  \Agenduy\Core\TenantLocalDb::syncCentralAppointments((string)$tenantSlug);
}
$reservas = AutoloadDB::all('reservas');
$pendingReservations = 0;
$todayDateObj = new DateTime('today');
foreach ($reservas as $rv) {
  if (!is_array($rv)) continue;
  $statusRaw = \Agenduy\Core\TenantLocalDb::normalizeStatusKey((string)($rv['Status'] ?? $rv['status'] ?? ''));
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
$carritos = AutoloadDB::all('carrito');
$cartClientIds = [];
foreach ($clientes as $cliente) {
  $cid = $cliente['ID_Cliente'] ?? null;
  if ($cid !== null && $cid !== '' && is_numeric($cid)) {
    $cartClientIds[(string)(int)$cid] = true;
  }
}
$cartCustomerFromOrder = static function(array $row): array {
  $firstNonEmpty = static function(array $keys) use ($row): string {
    foreach ($keys as $key) {
      $value = trim((string)($row[$key] ?? ''));
      if ($value !== '') {
        return $value;
      }
    }
    return '';
  };
  $address = '';
  foreach ($row as $key => $value) {
    $keyText = strtolower((string)$key);
    if (str_contains($keyText, 'direcci') || str_contains($keyText, 'direccion')) {
      $address = trim((string)$value);
      if ($address !== '') { break; }
    }
  }
  $nombre = $firstNonEmpty(['Cliente_Nombre', 'cliente_nombre']);
  $email = strtolower($firstNonEmpty(['Cliente_Email', 'cliente_email', 'payer_email']));
  $telefono = $firstNonEmpty(['Cliente_Telefono', 'cliente_telefono', 'Telefono']);
  $cedula = preg_replace('/\D+/', '', $firstNonEmpty(['Cliente_Cedula', 'cliente_cedula', 'Cedula'])) ?? '';
  if ($address !== '') {
    if ($email === '' && preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $address, $match)) {
      $email = strtolower($match[0]);
    }
    if ($telefono === '' && preg_match('/\+?\d[\d\s().-]{6,}\d/', $address, $match)) {
      $telefono = trim($match[0]);
    }
    if ($nombre === '') {
      $parts = preg_split('/\s+(?:-|[^\pL\pN@+.])\s+/u', $address) ?: [];
      $skipWords = ['pedido', 'whatsapp', 'mercado', 'reserva', 'coordinar', 'entrega', 'retiro', 'local'];
      foreach (array_reverse($parts) as $part) {
        $candidate = trim((string)$part);
        $candidateKey = strtolower($candidate);
        if ($candidate === '' || strlen($candidate) > 80 || preg_match('/\d|@/', $candidate)) { continue; }
        $skip = false;
        foreach ($skipWords as $word) {
          if (str_contains($candidateKey, $word)) { $skip = true; break; }
        }
        if (!$skip) {
          $nombre = $candidate;
          break;
        }
      }
    }
  }
  return [
    'nombre' => $nombre,
    'email' => $email,
    'telefono' => $telefono,
    'cedula' => $cedula,
  ];
};
if (
  class_exists(\Agenduy\Core\TenantLocalDb::class)
  && $tenantSlug !== ''
  && !\Agenduy\Core\CommercePanel::isTemplateHost($tenantSlug)
) {
  $cartClientRepairs = 0;
  foreach ($carritos as $cartRow) {
    if (!is_array($cartRow)) { continue; }
    $orderId = $cartRow['ID_Carrito'] ?? null;
    if ($orderId === null || $orderId === '' || !is_numeric($orderId)) { continue; }
    $clientIdRaw = $cartRow['ID_Cliente'] ?? null;
    $clientKey = ($clientIdRaw !== null && $clientIdRaw !== '' && is_numeric($clientIdRaw)) ? (string)(int)$clientIdRaw : '';
    if ($clientKey !== '' && isset($cartClientIds[$clientKey])) { continue; }
    $customer = $cartCustomerFromOrder($cartRow);
    if ($customer['email'] === '' && $customer['telefono'] === '' && $customer['cedula'] === '') { continue; }
    try {
      $newClientId = \Agenduy\Core\TenantLocalDb::findOrCreateCliente(
        (string)$tenantSlug,
        $customer['nombre'],
        $customer['telefono'],
        $customer['email'],
        '',
        $customer['cedula']
      );
      if ($newClientId !== null && $newClientId > 0) {
        \Agenduy\Core\TenantLocalDb::updateCartOrder((string)$tenantSlug, (int)$orderId, [
          'ID_Cliente' => $newClientId,
          'Cliente_Nombre' => $customer['nombre'],
          'Cliente_Email' => $customer['email'],
          'Cliente_Telefono' => $customer['telefono'],
          'Cliente_Cedula' => $customer['cedula'],
        ]);
        $cartClientRepairs++;
      }
    } catch (Throwable $e) {
      error_log('[admin cart client repair] ' . $e->getMessage());
    }
  }
  if ($cartClientRepairs > 0) {
    $clientes = AutoloadDB::all('clientes');
    $carritos = AutoloadDB::all('carrito');
  }
}
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
$infoBarberia = [];
$dbPath = (defined('AGENDUY_LOCAL_DB_PATH') && is_string(AGENDUY_LOCAL_DB_PATH) && AGENDUY_LOCAL_DB_PATH !== '')
  ? AGENDUY_LOCAL_DB_PATH
  : dirname(__DIR__, 3) . '/src/db/database.php';
$dbFull = is_file($dbPath) ? @include $dbPath : null;
if (is_array($dbFull) && isset($dbFull['info_barberia']) && is_array($dbFull['info_barberia'])) {
  $infoBarberia = $dbFull['info_barberia'];
}
try {
  $infoBarberia = \Agenduy\Core\CommerceConfig::infoForSlug($tenantSlug, $infoBarberia);
} catch (Throwable $e) {
  if (!isset($infoBarberia['temas']) || !is_array($infoBarberia['temas'])) {
    $infoBarberia['temas'] = ['publico' => 'oscuro', 'privado' => 'oscuro'];
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
$publicUrl = $publicShareUrl !== '' ? $publicShareUrl : url($tenantSlug !== 'template' ? $tenantSlug : '');
$planSettingsTier = 'full';
$currentPlanRow = null;
$maxClientsLimit = null;
$maxProductsLimit = null;
$maxProfessionalsLimit = null;
$commerceCheckoutAllowed = false;
$reservationCheckoutAllowed = false;
try {
  $currentPlanRow = \Agenduy\Core\MembershipPlan::forCommerceSlug($tenantSlug);
  if (is_array($currentPlanRow)) {
    $planSettingsTier = \Agenduy\Core\MembershipPlan::settingsTier($currentPlanRow);
    $maxClientsLimit = \Agenduy\Core\MembershipPlan::maxClients($currentPlanRow);
    $maxProductsLimit = \Agenduy\Core\MembershipPlan::maxProducts($currentPlanRow);
    $maxProfessionalsLimit = \Agenduy\Core\MembershipPlan::maxProfessionals($currentPlanRow);
    $commerceCheckoutAllowed = \Agenduy\Core\MercadoPago::isCommerceCheckoutAllowed($currentPlanRow);
    $reservationCheckoutAllowed = \Agenduy\Core\MercadoPago::isReservationCheckoutAllowed($currentPlanRow);
  }
} catch (Throwable $e) {
  $planSettingsTier = 'full';
  $maxClientsLimit = null;
  $maxProductsLimit = null;
  $maxProfessionalsLimit = null;
  $commerceCheckoutAllowed = false;
  $reservationCheckoutAllowed = false;
}
// Source of truth: CommerceSettings funciones (central DB), fallback: legacy features (local database.php)
$funcionesFromLegacy = $infoBarberia['features'] ?? [];
try {
  $funcionesFromCentral = \Agenduy\Core\CommerceSettings::get(
    (int)\Agenduy\Core\Auth::commerceId(),
    'funciones',
    $funcionesFromLegacy ?: \Agenduy\Core\CommerceSettings::defaultsForSection('funciones')
  );
} catch (Throwable $e) {
  $funcionesFromCentral = $funcionesFromLegacy ?: \Agenduy\Core\CommerceSettings::defaultsForSection('funciones');
}
$fallbackRubroTipo = trim((string)($infoBarberia['rubro'] ?? ''));
$fallbackRubroNombre = trim((string)($infoBarberia['rubro_nombre'] ?? ''));
if (($fallbackRubroTipo === '' || $fallbackRubroNombre === '') && !empty($infoBarberia['ID_Rubro'])) {
  try {
    $fallbackRubro = \Agenduy\Core\Database::getInstance()->fetchOne(
      'SELECT tipo, nombre FROM rubros WHERE id_rubro = :id',
      [':id' => (int)$infoBarberia['ID_Rubro']]
    );
    if ($fallbackRubroTipo === '') {
      $fallbackRubroTipo = (string)($fallbackRubro['tipo'] ?? '');
    }
    if ($fallbackRubroNombre === '') {
      $fallbackRubroNombre = (string)($fallbackRubro['nombre'] ?? '');
    }
  } catch (Throwable $e) {
    // Mantener fallback legacy si la consulta del rubro no esta disponible.
  }
}
$hasConfiguredBusinessType = isset($funcionesFromCentral['tipo_comercio']) || isset($funcionesFromCentral['tipo']);
$businessType = \Agenduy\Core\CommerceRegistrar::businessTypeFromFeatures(
  $funcionesFromCentral,
  $fallbackRubroTipo,
  $fallbackRubroNombre
);
if (!$hasConfiguredBusinessType) {
  $funcionesFromCentral = array_replace(
    $funcionesFromCentral,
    \Agenduy\Core\CommerceRegistrar::featuresForBusinessType($businessType)
  );
}
$infoBarberia['features'] = $funcionesFromCentral;
$carritoFromLegacy = isset($infoBarberia['carrito']) && is_array($infoBarberia['carrito']) ? $infoBarberia['carrito'] : [];
try {
  $infoBarberia['carrito'] = \Agenduy\Core\CommerceSettings::get(
    (int)\Agenduy\Core\Auth::commerceId(),
    'carrito',
    $carritoFromLegacy ?: \Agenduy\Core\CommerceSettings::defaultsForSection('carrito')
  );
} catch (Throwable $e) {
  $infoBarberia['carrito'] = $carritoFromLegacy ?: \Agenduy\Core\CommerceSettings::defaultsForSection('carrito');
}
$isStoreMode = $businessType === 'tienda';
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
  $st = \Agenduy\Core\TenantLocalDb::normalizeStatusKey((string)($r['Status'] ?? ''));
  $statusRegistry[$st] = true;
  if ((string)($r['Fecha_Reserva'] ?? '') === $today) { $reservasHoy++; }
  if ($st === 'pendiente') { $reservasPend++; }
  if ($st !== 'finalizado' && $st !== 'cancelado') { $reservasActivas++; }
}

$statusOrderSeed = ['pendiente', 'aprobado', 'en progreso', 'rechazado', 'cancelado', 'finalizado'];
$statusList = $statusOrderSeed;
foreach ($statusOrderSeed as $seed) {
  unset($statusRegistry[$seed]);
}
if (!empty($statusRegistry)) {
  foreach ($statusRegistry as $rest => $_) {
    $statusList[] = $rest;
  }
}
$statusFilterRaw = strtolower(trim((string)($_GET['res_status'] ?? '')));
$statusFilter = $statusFilterRaw === '' ? '' : ($statusFilterRaw === 'todos' ? 'todos' : \Agenduy\Core\TenantLocalDb::normalizeStatusKey($statusFilterRaw));
$defaultStatus = 'todos';
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
  return \Agenduy\Core\TenantLocalDb::statusLabel((string)$value);
};
$statusOptionLabels = [];
foreach ($statusOptions as $option) {
  $statusOptionLabels[$option] = ($option === 'todos') ? 'Todos' : $formatStatusLabel($option);
}
$currentStatusLabel = $statusOptionLabels[$statusFilter] ?? ($statusFilter === 'todos' ? 'Todos' : $formatStatusLabel($statusFilter));
$nowTs = time();
$ultimas = [];
foreach ($reservas as $r) {
  $st = \Agenduy\Core\TenantLocalDb::normalizeStatusKey((string)($r['Status'] ?? ''));

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
    $include = ($st === 'pendiente');
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
  if (isset($row['Precio']) && is_numeric($row['Precio']) && (float)$row['Precio'] > 0) {
    $finalizedAmount += $normalizeAmount($row['Precio']);
  } else {
    $finalizedAmount += $normalizeAmount($serviceData['Precio'] ?? 0);
  }
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
$clientIdsWithOrders = [];
foreach ($carritos as $carrito) {
  $oid = $carrito['ID_Cliente'] ?? null;
  if ($oid !== null && $oid !== '' && is_numeric($oid)) {
    $clientIdsWithOrders[(string)(int)$oid] = true;
  }
}
$clientesStats = [
  'total' => 0,
  'con_reservas' => count($clientIdsWithReservations),
  'con_pedidos' => count($clientIdsWithOrders),
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

$parseCartItems = static function($raw, $detailRaw = '') {
  if (is_string($detailRaw) && trim($detailRaw) !== '') {
    $decoded = json_decode($detailRaw, true);
    if (is_array($decoded)) {
      $items = [];
      foreach ($decoded as $entry) {
        if (!is_array($entry)) { continue; }
        $pidRaw = $entry['id'] ?? $entry['product'] ?? $entry['ID_Product'] ?? null;
        $qtyRaw = $entry['qty'] ?? $entry['quantity'] ?? $entry['cantidad'] ?? null;
        if ($pidRaw === null || $pidRaw === '' || !is_numeric($pidRaw) || !is_numeric($qtyRaw)) { continue; }
        $pid = (int)$pidRaw;
        $qty = max(1, (int)$qtyRaw);
        $items[] = [
          'product' => $pid,
          'quantity' => $qty,
          'variant' => isset($entry['variant']) && is_numeric($entry['variant']) ? (int)$entry['variant'] : 0,
          'variant_label' => trim((string)($entry['variant_label'] ?? '')),
          'name' => trim((string)($entry['name'] ?? '')),
          'price' => isset($entry['price']) && is_numeric($entry['price']) ? (float)$entry['price'] : null,
        ];
      }
      if ($items) { return $items; }
    }
  }
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
  $statusRawValue = trim((string)($carrito['Status'] ?? ''));
  $statusRaw = \Agenduy\Core\TenantLocalDb::normalizeStatusKey($statusRawValue);
  if ($statusRaw === '') { $statusRaw = 'pendiente'; }
  $itemsRaw = $carrito['ID_Producto + Cantidad'] ?? '';
  $items = $parseCartItems($itemsRaw, $carrito['Detalle_Items'] ?? '');
  if (!$items) { continue; }
  $orderSummaryItems = [];
  foreach ($items as $item) {
    $pid = $item['product'];
    $qty = $item['quantity'];
    $productKey = (string)$pid;
    $displayName = trim((string)($item['name'] ?? ''));
    if ($displayName === '') {
      $displayName = $productNameMap[$productKey] ?? ('Producto ' . $pid);
    }
    $unitPrice = isset($item['price']) && is_numeric($item['price'])
      ? (float)$item['price']
      : ($productPriceMap[$productKey] ?? 0.0);
    $productSalesEntries[] = [
      'order_id' => (int)$orderId,
      'client_id' => $clientId,
      'client_name' => $clientId !== null ? ($clientNameMap[(string)$clientId] ?? ('Cliente ' . $clientId)) : null,
      'product_id' => $pid,
      'product_name' => $displayName,
      'product_type' => $productTypeMap[$productKey] ?? 'Otro',
      'quantity' => $qty,
      'unit_price' => $unitPrice,
      'unit_points' => $productPointsMap[$productKey] ?? 0,
      'date' => $dateIso,
      'month' => $monthIso,
      'time' => $timeRaw,
      'status' => $statusRaw,
    ];
    $orderSummaryItems[] = $qty . ' x ' . $displayName;
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
  $cartStatusLabels[$statusKey] = $formatStatusLabel($statusKey);
  $cartStatusLabel = \Agenduy\Core\TenantLocalDb::statusLabel($statusRawValue !== '' ? $statusRawValue : $statusKey);
  $itemsData = [];
  foreach ($items as $item) {
    $pid = (int)$item['product'];
    $qty = (int)$item['quantity'];
    $itemsData[] = [
      'product' => $pid,
      'quantity' => $qty,
      'variant' => isset($item['variant']) && is_numeric($item['variant']) ? (int)$item['variant'] : 0,
      'variant_label' => trim((string)($item['variant_label'] ?? '')),
      'name' => trim((string)($item['name'] ?? '')) !== '' ? trim((string)$item['name']) : ($productNameMap[(string)$pid] ?? ('Producto ' . $pid)),
      'price' => isset($item['price']) && is_numeric($item['price']) ? (float)$item['price'] : ($productPriceMap[(string)$pid] ?? 0.0),
    ];
  }
  $clientRow = $clientId !== null ? ($clientesMap[(string)$clientId] ?? []) : [];
  $cartCustomer = $cartCustomerFromOrder(is_array($carrito) ? $carrito : []);
  $orderClientName = $clientId !== null ? ($clientNameMap[(string)$clientId] ?? ('Cliente ' . $clientId)) : '';
  if ($orderClientName === '' || $orderClientName === 'Cliente sin asignar') {
    $orderClientName = $cartCustomer['nombre'] !== '' ? $cartCustomer['nombre'] : 'Cliente sin asignar';
  }
  $orderClientEmail = trim((string)($clientRow['Email'] ?? ''));
  if ($orderClientEmail === '') { $orderClientEmail = $cartCustomer['email']; }
  $orderClientPhone = trim((string)($clientRow['Telefono'] ?? ($clientRow['Whatsapp'] ?? '')));
  if ($orderClientPhone === '') { $orderClientPhone = $cartCustomer['telefono']; }
  $orderClientCedula = trim((string)($clientRow['Cedula'] ?? ''));
  if ($orderClientCedula === '') { $orderClientCedula = $cartCustomer['cedula']; }
  $cartOrders[] = [
    'id' => (int)$orderId,
    'client' => $orderClientName,
    'client_email' => $orderClientEmail,
    'client_phone' => $orderClientPhone,
    'client_cedula' => $orderClientCedula,
    'status_key' => $statusKey,
    'status_label' => $cartStatusLabel,
    'payment_type' => admin_payment_type_label($carrito, 'Pago WhatsApp'),
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
  $statusRaw = \Agenduy\Core\TenantLocalDb::normalizeStatusKey((string)($reserva['Status'] ?? ''));
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

$summaryCards = [];
if ($isStoreMode) {
  $summaryCards[] = [
    'title' => 'Pedidos',
    'subtitle' => $formatNumber($cartTotalOrders) . ' registrados',
    'items' => [
      ['label' => 'Pendientes', 'value' => $formatNumber($cartPendingCount)],
      ['label' => 'Finalizados', 'value' => $formatNumber((int)($cartStatusCounts['finalizado'] ?? 0))],
      ['label' => 'Cancelados', 'value' => $formatNumber((int)($cartStatusCounts['cancelado'] ?? 0))],
    ],
    'cta_type' => 'link',
    'target' => '#pedidos',
  ];
} else {
  $summaryCards[] = [
    'title' => 'Reservas',
    'subtitle' => $formatNumber($totalReservas) . ' registradas',
    'items' => [
      ['label' => 'Hoy', 'value' => $formatNumber($reservasHoy)],
      ['label' => 'Activas', 'value' => $formatNumber($reservasActivas)],
      ['label' => 'Pendientes', 'value' => $formatNumber($reservasPend)],
    ],
    'cta_type' => 'modal',
    'modal' => 'reservas-summary',
  ];
  $summaryCards[] = [
    'title' => 'Profesionales',
    'subtitle' => $formatNumber($barberStats['total']) . ' en el equipo',
    'items' => [
      ['label' => 'Disponibles', 'value' => $formatNumber($barberStats['disponibles'])],
      ['label' => 'Online', 'value' => $formatNumber($barberStats['online'])],
      ['label' => 'Con comisión', 'value' => $formatNumber($barberStats['con_comision'])],
    ],
    'cta_type' => 'link',
    'target' => '#funcionarios',
  ];
  $summaryCards[] = [
    'title' => 'Servicios',
    'subtitle' => $formatNumber($serviciosStats['total']) . ' publicados',
    'items' => [
      ['label' => 'Activos', 'value' => $formatNumber($serviciosStats['activos'])],
      ['label' => 'Con imagen', 'value' => $formatNumber($serviciosStats['con_imagen'])],
      ['label' => 'Duración promedio', 'value' => $serviciosStats['duracion_promedio'] > 0 ? $serviciosStats['duracion_promedio'] . ' min' : 'Sin datos'],
    ],
    'cta_type' => 'link',
    'target' => '#servicios',
  ];
}
$summaryCards[] = [
  'title' => 'Clientes',
  'subtitle' => $formatNumber($clientesStats['total']) . ' registrados',
  'items' => [
    ['label' => $isStoreMode ? 'Con pedidos' : 'Con reservas', 'value' => $formatNumber($isStoreMode ? ($clientesStats['con_pedidos'] ?? 0) : $clientesStats['con_reservas'])],
    ['label' => 'Con email', 'value' => $formatNumber($clientesStats['con_email'])],
    ['label' => 'Con teléfono', 'value' => $formatNumber($clientesStats['con_telefono'])],
  ],
  'cta_type' => 'link',
  'target' => '#clientes',
];
$summaryCards[] = [
  'title' => 'Productos',
  'subtitle' => $formatNumber($productosStats['total']) . ' en catálogo',
  'items' => [
    ['label' => 'Con imagen', 'value' => $formatNumber($productosStats['con_imagen'])],
    ['label' => 'Con puntos', 'value' => $formatNumber($productosStats['con_puntos'])],
    ['label' => 'Tipos distintos', 'value' => $formatNumber($productosStats['tipos'])],
  ],
  'cta_type' => 'modal',
  'modal' => 'productos-summary',
];
$isCentralPanelEmbed = defined('AGENDUY_COMMERCE_PANEL_EMBED') && AGENDUY_COMMERCE_PANEL_EMBED;
if (!function_exists('admin_panel_href')) {
    function admin_panel_href(string $relative): string {
        if (!defined('AGENDUY_COMMERCE_PANEL_EMBED') || !AGENDUY_COMMERCE_PANEL_EMBED) {
            return $relative;
        }
        return \Agenduy\Core\CommercePanel::dashboardAssetUrl($relative);
    }
}
if (!function_exists('admin_tenant_asset_url')) {
    function admin_tenant_asset_url(string $storedPath): string {
        $storedPath = ltrim(str_replace('\\', '/', trim($storedPath)), '/');
        if ($storedPath === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $storedPath)) {
            return $storedPath;
        }

        $idCommerce = (int)($GLOBALS['commerceIdForPanel'] ?? \Agenduy\Core\Auth::commerceId() ?? 0);
        $tenantSlugForAsset = trim((string)($GLOBALS['tenantSlug'] ?? ''), '/');
        if ($idCommerce > 0) {
            $resolved = \Agenduy\Core\CommerceStorage::publicUrl($idCommerce, $tenantSlugForAsset, $storedPath);
            if ($resolved !== '') {
                return $resolved;
            }
            if (\Agenduy\Core\CommerceStorage::isCentralPath($storedPath)) {
                return '';
            }
        }

        if ($tenantSlugForAsset !== '' && $tenantSlugForAsset !== 'template') {
            return url($tenantSlugForAsset . '/' . $storedPath);
        }
        return url($storedPath);
    }
}
$panelApiEndpoints = $isCentralPanelEmbed ? \Agenduy\Core\CommercePanel::dashboardApiEndpoints() : [];
$tenantPublicUrl = ($tenantSlug !== '' && $tenantSlug !== 'template')
    ? \Agenduy\Core\CommercePanel::publicUrlForSlug($tenantSlug)
    : url('');
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php if (defined('AGENDUY_PANEL_BASE_HREF') && AGENDUY_PANEL_BASE_HREF !== ''): ?>
  <base href="<?php echo e(AGENDUY_PANEL_BASE_HREF); ?>">
  <?php endif; ?>
  <meta name="theme-color" content="#7c3aed">
  <meta name="csrf-token" content="<?php echo e($_SESSION['admin_config_csrf']); ?>">
  <meta name="url-base" content="<?php echo e($publicUrl); ?>">
  <meta name="tenant-slug" content="<?php echo e($tenantSlug); ?>">
  <title>Panel · Agendarte UY</title>
  <script>
    (function () {
      try {
        var userThemeSet = localStorage.getItem('agendarte-admin-theme-user-set') === '1';
        var theme = userThemeSet
          ? (localStorage.getItem('agendarte-theme') || localStorage.getItem('agendarte-admin-theme') || 'light')
          : 'light';
        if (theme !== 'dark' && theme !== 'light') theme = 'light';
        document.documentElement.setAttribute('data-admin-theme', theme);
        document.documentElement.setAttribute('data-theme', theme);
      } catch (error) {
        document.documentElement.setAttribute('data-admin-theme', 'light');
        document.documentElement.setAttribute('data-theme', 'light');
      }
    })();
  </script>
  <link rel="manifest" href="<?php echo e(admin_panel_href('../manifest.admin.php')); ?>">
  <link rel="stylesheet" href="<?php echo e(admin_panel_href('../../../src/css/main.css')); ?>">
  <link rel="stylesheet" href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css">
  <link rel="stylesheet" href="<?php echo e(admin_panel_href('../src/admin.css')); ?>?v=20260813_2">
  <link rel="stylesheet" href="<?php echo e(\Agenduy\Core\AdminBrand::cssUrl()); ?>">
  <link rel="stylesheet" href="<?php echo e(admin_panel_href('../src/reservas-ledger.css')); ?>">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.css">
  <link rel="icon" type="image/png" sizes="32x32" href="<?php echo e(\Agenduy\Core\AdminBrand::faviconUrl()); ?>">
  <link rel="apple-touch-icon" href="<?php echo e(\Agenduy\Core\AdminBrand::iconUrl()); ?>">
</head>
<body>
  <div class="admin-layout is-collapsed">
    <aside class="admin-aside">
      <div class="admin-brand">
        <?php echo \Agenduy\Core\AdminBrand::sidebarBrandInnerHtml(); ?>
        <small class="muted admin-brand__tenant"><?php echo e($infoBarberia['rubro_nombre'] ?? ($businessName !== '' ? $businessName : 'Mi negocio')); ?></small>
      </div>
      <nav class="admin-nav">
        <a class="admin-link" href="#resumen">Resumen</a>
        <?php if ($isStoreMode): ?>
        <a class="admin-link" href="#pedidos">Pedidos</a>
        <?php else: ?>
        <a class="admin-link" href="#reservas">Reservas</a>
        <a class="admin-link" href="#funcionarios">Profesionales</a>
        <a class="admin-link" href="#servicios">Servicios</a>
        <?php endif; ?>
        <a class="admin-link" href="#clientes">Clientes</a>
        <a class="admin-link" href="#productos">Productos</a>
        <a class="admin-link" href="<?php echo e(\Agenduy\Core\CommercePanel::siteUrl('admin/commerce_plan.php')); ?>">Mi Plan</a>
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
          <?php
            $modeLabel = $funcionesFromCentral['tipo_comercio_label'] ?? '';
            if ($modeLabel === '') {
              $modeLabel = $isStoreMode ? 'Modo tienda' : 'Modo agenda';
            }
          ?>
          <span class="admin-mode-badge admin-mode-badge--<?php echo $isStoreMode ? 'tienda' : 'agenda'; ?>">
            <i class="bx <?php echo $isStoreMode ? 'bx-store' : 'bx-calendar-check'; ?>" aria-hidden="true"></i>
            <?php echo e($modeLabel); ?>
          </span>
        </div>
        <div class="admin-header-actions">
          <button type="button" class="admin-theme-toggle" data-admin-theme-toggle aria-label="Cambiar modo visual">
            <i class="bx bx-moon" aria-hidden="true"></i>
            <span data-admin-theme-toggle-label>Modo oscuro</span>
          </button>
        </div>
        <details class="admin-orders"<?php echo $hasAnyCartOrders ? '' : ' data-empty="1"'; ?> data-active-status="<?php echo e($cartActiveStatus); ?>" hidden aria-hidden="true">
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
                  data-order-client="<?php echo e($order['client']); ?>"
                  data-order-client-email="<?php echo e($order['client_email'] ?? ''); ?>"
                  data-order-client-phone="<?php echo e($order['client_phone'] ?? ''); ?>"
                  data-order-client-cedula="<?php echo e($order['client_cedula'] ?? ''); ?>"
                  data-order-payment="<?php echo e($order['payment_type']); ?>"
                  data-order-date="<?php echo e($order['date']); ?>"
                  data-order-time="<?php echo e($order['time']); ?>"
                  data-order-address="<?php echo e($order['address']); ?>"
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
                    <div>
                      <dt>Tipo de pago</dt>
                      <dd><?php echo e($order['payment_type']); ?></dd>
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
                    <button
                      type="button"
                      class="admin-orders__sale-btn admin-orders__sale-btn--print"
                      data-order-action="print"
                      data-order-id="<?php echo (int)$order['id']; ?>">
                      Imprimir
                    </button>
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

      <?php if ($isStoreMode): ?>
      <section class="admin-section" id="pedidos">
        <div class="admin-section-tools">
          <span class="admin-section-count">Total: <?php echo (int)$cartTotalOrders; ?> pedidos</span>
          <label class="admin-orders-print-toggle">
            <input type="checkbox" data-admin-orders-autoprint>
            <span>Imprimir pedidos nuevos</span>
          </label>
        </div>
        <?php if ($cartTotalOrders > 0): ?>
        <div class="table-wrap table-wrap--scroll">
          <table class="table" data-admin-orders-table>
            <thead>
              <tr>
                <th>Pedido</th>
                <th>Cliente</th>
                <th>Productos</th>
                <th>Fecha</th>
                <th>Tipo de pago</th>
                <th>Estado</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach (array_slice($cartOrders, 0, 50) as $order): ?>
              <?php
                $orderItemsJson = json_encode($order['items_data'] ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS);
                if ($orderItemsJson === false) { $orderItemsJson = '[]'; }
              ?>
              <tr
                data-admin-order-row
                data-order-status="<?php echo e($order['status_key']); ?>"
                data-order-id="<?php echo (int)$order['id']; ?>"
                data-order-client="<?php echo e($order['client']); ?>"
                data-order-client-email="<?php echo e($order['client_email'] ?? ''); ?>"
                data-order-client-phone="<?php echo e($order['client_phone'] ?? ''); ?>"
                data-order-client-cedula="<?php echo e($order['client_cedula'] ?? ''); ?>"
                data-order-payment="<?php echo e($order['payment_type']); ?>"
                data-order-date="<?php echo e($order['date']); ?>"
                data-order-time="<?php echo e($order['time']); ?>"
                data-order-address="<?php echo e($order['address']); ?>"
                data-items='<?php echo $orderItemsJson; ?>'>
                <td><strong>#<?php echo (int)$order['id']; ?></strong></td>
                <td><?php echo e($order['client']); ?></td>
                <td><?php echo e(implode(', ', array_map(function($i) {
                  $variant = trim((string)($i['variant_label'] ?? ''));
                  return $i['quantity'] . 'x ' . $i['name'] . ($variant !== '' ? ' - ' . $variant : '');
                }, $order['items_data'] ?? []))); ?></td>
                <td><?php echo e($order['date']); ?></td>
                <td><?php echo e($order['payment_type']); ?></td>
                <td>
                  <div class="admin-order-table-status">
                    <span class="status-pill st-<?php echo e($order['status_key']); ?>" data-admin-order-status-label><?php echo e($order['status_label']); ?></span>
                    <button
                      type="button"
                      class="admin-orders__sale-btn admin-orders__sale-btn--print"
                      data-order-action="print"
                      data-order-id="<?php echo (int)$order['id']; ?>">
                      Imprimir
                    </button>
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
                  </div>
                </td>
              </tr>
              <tr class="admin-order-edit-row" data-admin-order-edit-row="<?php echo (int)$order['id']; ?>" hidden>
                <td colspan="6">
                  <div class="admin-orders__edit admin-orders__edit--table" data-order-edit="<?php echo (int)$order['id']; ?>" hidden></div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php else: ?>
        <p class="muted">Aún no hay pedidos registrados.</p>
        <?php endif; ?>
      </section>
      <?php endif; ?>

      <?php if (!$isStoreMode): ?>
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
                <th>Tipo de pago</th>
                <th>Status</th>
                <th>Acciones</th>
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
                $statusRawValue = (string)($r['Status'] ?? '');
                $st = isset($r['_status_norm']) ? $r['_status_norm'] : \Agenduy\Core\TenantLocalDb::normalizeStatusKey($statusRawValue);
                $stLabel = \Agenduy\Core\TenantLocalDb::statusLabel($statusRawValue !== '' ? $statusRawValue : $st);
                $cls = 'st-' . \Agenduy\Core\TenantLocalDb::statusClassKey($st);
                $paymentTypeLabel = admin_payment_type_label($r, 'Pago local');
                $timestampAttr = '';
                if (isset($r['_timestamp']) && $r['_timestamp'] !== PHP_INT_MAX) {
                  $timestampAttr = (string)(int)$r['_timestamp'];
                }
                $idReserva = (int)($r['ID_Reserva'] ?? 0);
                $canAttend = in_array($st, ['pendiente', 'aprobado'], true);
                $canFinish = in_array($st, ['pendiente', 'aprobado', 'en progreso'], true);
                $canModify = !in_array($st, ['rechazado', 'cancelado', 'finalizado'], true);
              ?>
              <tr
                data-admin-reserva-item
                data-admin-res-row-id="<?php echo $idReserva; ?>"
                data-admin-reserva-status="<?php echo e($st); ?>"
                data-admin-reserva-fecha="<?php echo e($r['Fecha_Reserva'] ?? ''); ?>"
                data-admin-reserva-hora="<?php echo e(substr((string)($r['Hora_Reserva'] ?? ''), 0, 5)); ?>"
                data-admin-reserva-ts="<?php echo e($timestampAttr); ?>"
                data-admin-reserva-price="<?php echo e($servicePriceLabel); ?>"
                data-admin-reserva-payment="<?php echo e($paymentTypeLabel); ?>"
              >
                <td data-heading="Cliente"><?php echo e($cn); ?></td>
                <td data-heading="Profesional"><?php echo e(trim($bn)); ?></td>
                <td data-heading="Servicio"><span class="reserva-servicio__name"><?php echo e($sn); ?></span></td>
                <td data-heading="Precio" class="numeric"><?php echo e($servicePriceLabel); ?></td>
                <td data-heading="Fecha"><?php echo e($r['Fecha_Reserva'] ?? ''); ?></td>
                <td data-heading="Hora"><?php echo e(substr((string)($r['Hora_Reserva'] ?? ''),0,5)); ?></td>
                <td data-heading="Tipo de pago"><?php echo e($paymentTypeLabel); ?></td>
                <td data-heading="Status"><span class="status-pill <?php echo e($cls); ?>"><?php echo e($stLabel); ?></span></td>
                <td data-heading="Acciones">
                  <div class="admin-reserva-row-actions">
                  <?php if ($canAttend): ?>
                    <button
                      type="button"
                      class="btn btn-secondary btn-sm"
                      data-admin-reserva-quick-status="En progreso"
                      data-admin-reserva-id="<?php echo $idReserva; ?>"
                    >Atención</button>
                  <?php endif; ?>
                  <?php if ($canFinish): ?>
                    <button
                      type="button"
                      class="btn btn-primary btn-sm"
                      data-admin-reserva-quick-status="Finalizado"
                      data-admin-reserva-id="<?php echo $idReserva; ?>"
                    >Finalizar</button>
                  <?php endif; ?>
                  <?php if ($canModify): ?>
                    <button
                      type="button"
                      class="btn btn-warning btn-sm"
                      data-admin-view-reserva="<?php echo $idReserva; ?>"
                    >Modificar</button>
                  <?php endif; ?>
                  <?php if (!$canAttend && !$canFinish && !$canModify): ?>
                    <span class="muted">-</span>
                  <?php endif; ?>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <p class="muted admin-reservas-empty" data-admin-reserva-empty data-empty-base="No hay reservas para el estado seleccionado."<?php echo $renderedCount > 0 ? ' hidden' : ''; ?>>No hay reservas para el estado seleccionado.</p>
      </section>
      <?php endif; ?>

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
                  $photoUrl = admin_tenant_asset_url($photo);
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

      <?php if (!$isStoreMode): ?>
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
          $barbersAtLimit = ($maxProfessionalsLimit !== null && $adminBarberCount >= (int)$maxProfessionalsLimit);
          ?>
          <div class="admin-barbers-header admin-section-tools">
            <?php if ($barbersAtLimit): ?>
              <button type="button" class="btn btn-outline admin-barbers-create" data-plan-membership-open title="Mejorá tu plan para registrar más profesionales">
                <i class="bx bx-crown" aria-hidden="true"></i>
                <span>Mejorar plan</span>
              </button>
            <?php else: ?>
              <button type="button" class="admin-barbers-create" data-admin-barber-create>
                <i class="bx bx-user-plus"></i>
                Registrar un Profesional
              </button>
            <?php endif; ?>
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
      <?php endif; ?>
      <?php if (!$isStoreMode): ?>
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
              $imgUrl = $imgRel !== '' ? admin_tenant_asset_url($imgRel) : '';
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
      <?php endif; ?>
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
              $productImages = \Agenduy\Core\ProductCatalog::mediaForRow($prod);
              $productImagesJson = json_encode($productImages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
              if ($productImagesJson === false) { $productImagesJson = '[]'; }
              $discount = \Agenduy\Core\ProductCatalog::discountPercent($prod);
              $discountLabel = $discount > 0 ? rtrim(rtrim(number_format($discount, 2, '.', ''), '0'), '.') : '';
              $saleLabel = \Agenduy\Core\ProductCatalog::saleLabel($prod);
              $imgRel = trim((string)($productImages[0]['src'] ?? ($prod['Img_src'] ?? '')));
              $imgUrl = $imgRel !== '' ? admin_tenant_asset_url($imgRel) : '';
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
            data-admin-product-image="<?php echo e($imgRel); ?>"
            data-admin-product-gallery="<?php echo e((string)($prod['Img_Gallery'] ?? '')); ?>"
            data-admin-product-images="<?php echo e($productImagesJson); ?>"
            data-admin-product-discount="<?php echo e($discountLabel); ?>"
            data-admin-product-sale-label="<?php echo e($saleLabel); ?>">
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
              <span class="admin-product-sale-label"<?php echo $saleLabel !== '' ? '' : ' hidden'; ?>><?php echo e($saleLabel); ?></span>
              <span class="admin-product-type"><?php echo e($tipo !== '' ? $tipo : 'Producto'); ?></span>
              <?php if ($descripcion !== ''): ?>
                <p class="admin-product-desc admin-product-description"><?php echo e($descripcion); ?></p>
              <?php else: ?>
                <p class="admin-product-desc admin-product-description muted">Sin descripci?n.</p>
              <?php endif; ?>
              <ul class="admin-product-meta">
                <li><i class="bx bx-purchase-tag"></i>$ <?php echo e($precioFmt !== '' ? $precioFmt : '0'); ?></li>
                <?php if ($discountLabel !== ''): ?><li><i class="bx bx-badge-percent"></i><?php echo e($discountLabel); ?>% off</li><?php endif; ?>
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
        <div class="admin-config-grid" data-admin-config-grid data-settings-tier="<?php echo e($planSettingsTier); ?>" data-commerce-checkout-allowed="<?php echo $commerceCheckoutAllowed ? '1' : '0'; ?>" data-reservation-checkout-allowed="<?php echo $reservationCheckoutAllowed ? '1' : '0'; ?>">
          <?php
            $configOptions = [
              ['id' => 'info', 'title' => 'Info. del Negocio', 'icon' => 'bx-buildings'],
              ['id' => 'horarios', 'title' => 'Horarios', 'icon' => 'bx-time-five'],
              ['id' => $isStoreMode ? 'carrito' : 'reservas', 'title' => $isStoreMode ? 'Config. de Carrito / Pedidos' : 'Config. de Reservas', 'icon' => $isStoreMode ? 'bx-cart' : 'bx-calendar-check'],
              ['id' => 'moneda', 'title' => 'Config. de Moneda', 'icon' => 'bx-money'],
              ['id' => 'fiscal', 'title' => 'Config. Fiscal', 'icon' => 'bx-receipt'],
              ['id' => 'mercadopago', 'title' => 'Mercado Pago', 'icon' => 'bx-credit-card'],
              ['id' => 'redes', 'title' => 'Contacto y redes', 'icon' => 'bx-share-alt'],
              ['id' => 'seo', 'title' => 'SEO', 'icon' => 'bx-line-chart'],
              ['id' => 'notificaciones', 'title' => 'Notificaciones', 'icon' => 'bx-bell'],
              ['id' => 'legal', 'title' => 'Config. Legal', 'icon' => 'bx-shield-quarter'],
              ['id' => 'funciones', 'title' => $isStoreMode ? 'Modo del comercio' : 'Funciones', 'icon' => 'bx-wrench'],
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
      <?php if ($isStoreMode): ?>
      <a class="admin-bottomnav__item" href="#pedidos" data-admin-nav-target="pedidos">
        <i class="bx bx-cart" aria-hidden="true"></i>
        <span>Pedidos</span>
      </a>
      <?php else: ?>
      <a class="admin-bottomnav__item" href="#reservas" data-admin-nav-target="reservas">
        <i class="bx bx-calendar" aria-hidden="true"></i>
        <span>Reservas</span>
        <span class="admin-bottomnav__badge" data-bottom-badge="reservas"<?php echo $pendingReservations > 0 ? '' : ' hidden'; ?>>
          <?php echo (int)$pendingReservations; ?>
        </span>
      </a>
      <a class="admin-bottomnav__item" href="#funcionarios" data-admin-nav-target="funcionarios">
        <i class="bx bx-customize" aria-hidden="true"></i>
        <span>Profesionales</span>
      </a>
      <a class="admin-bottomnav__item" href="#servicios" data-admin-nav-target="servicios">
        <i class="bx bx-cut" aria-hidden="true"></i>
        <span>Servicios</span>
      </a>
      <?php endif; ?>
      <a class="admin-bottomnav__item" href="#clientes" data-admin-nav-target="clientes">
        <i class="bx bx-user" aria-hidden="true"></i>
        <span>Clientes</span>
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
  <div id="admin-modal-root">
    <?php
    $adminComponentsDir = __DIR__ . '/../src/components';
    foreach ([
        'admin_reserva_modal.php',
        'admin_qr_modal.php',
        'admin_cliente_modal.php',
        'admin_client_form_modal.php',
        'admin_historial_modal.php',
        'admin_reservas_summary_modal.php',
        'admin_productos_summary_modal.php',
        'admin_service_form_modal.php',
        'admin_service_modal.php',
        'admin_product_form_modal.php',
        'admin_barber_modal.php',
        'admin_barber_edit_modal.php',
        'admin_business_info_modal.php',
        'admin_auth_guard_modal.php',
        'admin_config_redes_modal.php',
        'admin_config_seo_modal.php',
        'admin_config_notifications_modal.php',
        'admin_config_features_modal.php',
        'admin_config_cart_modal.php',
        'admin_config_mercadopago_modal.php',
        'admin_config_fiscal_modal.php',
        'admin_config_moneda_modal.php',
        'admin_hours_modal.php',
        'admin_reservas_config_modal.php',
        'admin_plan_trial_modal.php',
        'admin_plan_cancel_modal.php',
        'admin_plan_membership_modal.php',
        'admin_theme_modal.php',
        'admin_config_legales_modal.php',
    ] as $adminComponentFile) {
        $adminComponentPath = $adminComponentsDir . '/' . $adminComponentFile;
        if (is_file($adminComponentPath)) {
            echo include $adminComponentPath;
        }
    }
    ?>
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
    try {
      $sqliteCommerce = \Agenduy\Core\Database::getInstance()->fetchOne(
        'SELECT id_commerce, id_rubro FROM commerces WHERE slug = :s LIMIT 1',
        [':s' => $tenantSlug]
      );
      if ($sqliteCommerce) {
        $infoBarberia['ID_Negocio'] = (int)$sqliteCommerce['id_commerce'];
        $infoBarberia['ID_Rubro'] = (int)$sqliteCommerce['id_rubro'];
        $infoBarberiaJson = json_encode($infoBarberia, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($infoBarberiaJson)) { $infoBarberiaJson = '{}'; }
      }
    } catch (Throwable $e) {
      // conservar legacy
    }
    $adminConfigEndpoint = $panelApiEndpoints['adminConfig'] ?? admin_panel_href('../../../src/API/AdminConfig.php');
    $adminApiBase = preg_replace('#AdminConfig\.php$#', '', (string)$adminConfigEndpoint);
    if (!is_string($adminApiBase) || $adminApiBase === '') { $adminApiBase = '../../../src/API/'; }
  ?>
  <script>
    window.ADMIN_INFO_BARBERIA = <?php echo $infoBarberiaJson; ?>;
    window.ADMIN_DASHBOARD = <?php echo json_encode($panelApiEndpoints, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    window.AdminApiBase = <?php echo json_encode($adminApiBase, JSON_UNESCAPED_SLASHES); ?>;
    window.__TENANT_CONFIG__ = {
      slug: <?php echo json_encode($tenantSlug, JSON_UNESCAPED_SLASHES); ?>,
      basePath: <?php echo json_encode(url(''), JSON_UNESCAPED_SLASHES); ?>,
      publicUrl: <?php echo json_encode($tenantPublicUrl, JSON_UNESCAPED_SLASHES); ?>,
      logoutUrl: <?php echo json_encode(url('admin/logout.php'), JSON_UNESCAPED_SLASHES); ?>
    };
    (function attachAdminFetch() {
      const nativeFetch = window.fetch.bind(window);
      const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
      const dash = window.ADMIN_DASHBOARD || {};

      const resolveUrl = (input) => {
        if (typeof input !== 'string') {
          return input;
        }
        let url = input;
        const replacePreservingRequest = (target) => {
          try {
            const sourceUrl = new URL(url, window.location.href);
            const targetUrl = new URL(target, window.location.href);
            if (sourceUrl.search) {
              targetUrl.search = sourceUrl.search;
            }
            if (sourceUrl.hash) {
              targetUrl.hash = sourceUrl.hash;
            }
            return targetUrl.toString();
          } catch (_) {
            const suffixStart = url.search(/[?#]/);
            const suffix = suffixStart >= 0 ? url.slice(suffixStart) : '';
            if (!suffix) {
              return target;
            }
            const hashStart = target.indexOf('#');
            if (hashStart >= 0) {
              return target.slice(0, hashStart) + suffix + target.slice(hashStart);
            }
            return target + suffix;
          }
        };
        if (dash.adminConfig && url.includes('AdminConfig.php')) {
          return replacePreservingRequest(dash.adminConfig);
        }
        if (dash.autoload && url.includes('Autoload.php')) {
          return replacePreservingRequest(dash.autoload);
        }
        if (dash.adminPush && url.includes('AdminPush.php')) {
          return replacePreservingRequest(dash.adminPush);
        }
        if (dash.reservas && url.includes('reservas_admin.php')) {
          return replacePreservingRequest(dash.reservas);
        }
        if (dash.servicios && url.includes('servicios.php')) {
          return replacePreservingRequest(dash.servicios);
        }
        if (dash.productos && url.includes('productos.php')) {
          return replacePreservingRequest(dash.productos);
        }
        if (dash.barberos && url.includes('barberos.php')) {
          return replacePreservingRequest(dash.barberos);
        }
        return url;
      };

      window.fetch = function(resource, options) {
        const originalUrl = typeof resource === 'string' ? resource : resource?.url || '';
        const resolvedUrl = resolveUrl(originalUrl);
        let finalResource = resource;
        if (typeof resource === 'string' && resolvedUrl !== originalUrl) {
          finalResource = resolvedUrl;
        } else if (resource && typeof resource === 'object' && resolvedUrl !== originalUrl) {
          finalResource = new Request(resolvedUrl, resource);
        }
        const url = typeof finalResource === 'string' ? finalResource : finalResource?.url || resolvedUrl;
        if (!url.includes('AdminConfig.php')) {
          return nativeFetch(finalResource, options);
        }
        const next = { ...(options || {}) };
        next.headers = new Headers(next.headers || {});
        next.headers.set('X-CSRF-Token', token);
        next.credentials = next.credentials || 'same-origin';
        return nativeFetch(finalResource, next);
      };
    })();
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
    window.ADMIN_PUSH_PUBLIC_KEY = '<?php echo e($pushPublicKey); ?>';
    window.ADMIN_PUSH_ENDPOINT = <?php echo json_encode(
        $panelApiEndpoints['adminPush'] ?? admin_panel_href('../../../src/API/AdminPush.php'),
        JSON_UNESCAPED_SLASHES
    ); ?>;
    (function setupWelcomeToast() {
      try {
        var params = new URLSearchParams(window.location.search);
        if (params.get('setup') !== 'ok') return;
        params.delete('setup');
        var next = window.location.pathname + (params.toString() ? '?' + params.toString() : '') + window.location.hash;
        history.replaceState(null, '', next);
        var msg = 'Tu negocio quedó listo. Empezá por Resumen o Configuración.';
        if (typeof window.AdminNotify === 'function') {
          window.AdminNotify(msg, 'success');
        }
      } catch (_) {}
    })();
  </script>

  <script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/l10n/es.js"></script>
  <script src="<?php echo e(admin_panel_href('../src/js/admin/core.js')); ?>"></script>
  <script src="<?php echo e(admin_panel_href('../src/js/admin/theme-toggle.js')); ?>"></script>
  <script src="<?php echo e(admin_panel_href('../src/js/admin/admin-orders.js')); ?>?v=20260813_3"></script>
  <script src="<?php echo e(admin_panel_href('../src/js/admin/plan-trial-modal.js')); ?>"></script>
  <script src="<?php echo e(admin_panel_href('../src/js/admin/plan-membership-modal.js')); ?>"></script>
  <script src="<?php echo e(admin_panel_href('../src/js/admin/modal-loading.js')); ?>"></script>
  <script src="<?php echo e(admin_panel_href('../src/js/admin/layout-sidebar.js')); ?>"></script>
  <script src="<?php echo e(admin_panel_href('../src/js/admin/bottom-nav.js?v=4')); ?>"></script>
  <script src="<?php echo e(admin_panel_href('../src/js/admin/clientes-list.js')); ?>"></script>
  <script src="<?php echo e(admin_panel_href('../src/js/admin/clientes-form.js')); ?>"></script>
  <?php if (!$isStoreMode): ?>
  <script src="<?php echo e(admin_panel_href('../src/js/admin/reservas-filter.js')); ?>"></script>
  <script src="<?php echo e(admin_panel_href('../src/js/admin/barberos-list.js')); ?>"></script>
  <script src="<?php echo e(admin_panel_href('../src/js/admin/barbero-create-modal.js')); ?>"></script>
  <script src="<?php echo e(admin_panel_href('../src/js/admin/barbero-edit-modal.js')); ?>"></script>
  <script src="<?php echo e(admin_panel_href('../src/js/admin/reserva-modal.js')); ?>"></script>
  <script src="<?php echo e(admin_panel_href('../src/js/admin/reservas-summary-modal.js')); ?>"></script>
  <script src="<?php echo e(admin_panel_href('../src/js/admin/servicios-crud.js')); ?>"></script>
  <script src="<?php echo e(admin_panel_href('../src/js/admin/service-modal.js')); ?>"></script>
  <script src="<?php echo e(admin_panel_href('../src/js/admin/config-reservas-modal.js')); ?>"></script>
  <?php endif; ?>
  <script src="<?php echo e(admin_panel_href('../src/js/admin/productos-summary-modal.js')); ?>"></script>
  <script src="<?php echo e(admin_panel_href('../src/js/admin/cliente-modal.js')); ?>"></script>
  <script src="<?php echo e(admin_panel_href('../src/js/admin/productos-crud.js')); ?>"></script>
  <script src="<?php echo e(admin_panel_href('../src/js/admin/config-info-modal.js')); ?>"></script>
  <script src="<?php echo e(admin_panel_href('../src/js/admin/admin-auth-guard.js')); ?>"></script>
  <script src="<?php echo e(admin_panel_href('../src/js/admin/admin-config-redes.js')); ?>"></script>
  <script src="<?php echo e(admin_panel_href('../src/js/admin/admin-config-seo.js')); ?>"></script>
  <script src="<?php echo e(admin_panel_href('../src/js/admin/admin-config-notificaciones.js')); ?>"></script>
  <script src="<?php echo e(admin_panel_href('../src/js/admin/admin-config-legales.js')); ?>"></script>
  <script src="<?php echo e(admin_panel_href('../src/js/admin/admin-config-features.js')); ?>"></script>
  <script src="<?php echo e(admin_panel_href('../src/js/admin/admin-config-cart.js')); ?>"></script>
  <script src="<?php echo e(admin_panel_href('../src/js/admin/admin-config-theme.js')); ?>"></script>
  <script src="<?php echo e(admin_panel_href('../src/js/admin/admin-config-fiscal.js')); ?>"></script>
  <script src="<?php echo e(admin_panel_href('../src/js/admin/admin-config-moneda.js')); ?>"></script>
  <script src="<?php echo e(admin_panel_href('../src/js/admin/admin-config-mercadopago.js')); ?>"></script>
  <script src="<?php echo e(admin_panel_href('../src/js/admin/config-hours.js')); ?>"></script>
  <script src="<?php echo e(admin_panel_href('../src/js/admin/config-cards.js')); ?>"></script>
  <script src="<?php echo e(admin_panel_href('../src/js/admin/pwa.js')); ?>"></script>
  <script src="<?php echo e(admin_panel_href('../src/js/admin/admin-live-refresh.js')); ?>"></script>
  </body>
  </html>



