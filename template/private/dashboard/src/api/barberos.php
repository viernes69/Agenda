<?php
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/src/API/Autoload.php';

$projectRoot = dirname(__DIR__, 5);
require_once $projectRoot . '/src/Core/bootstrap.php';

use Agenduy\Core\CommerceStorage;
use Agenduy\Core\MembershipPlan;
use Agenduy\Core\TenantApiGuard;

header('Content-Type: application/json; charset=utf-8');

$tenantStaff = TenantApiGuard::requireStaff(dirname(__DIR__, 4));

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Metodo no permitido']);
    exit;
}

$action = strtolower((string)($_POST['action'] ?? 'create'));
if ($action === 'insert') {
    $action = 'create';
}
if (!in_array($action, ['create', 'update'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Accion no soportada']);
    exit;
}

$services = AutoloadDB::all('servicios');
$validServiceIds = [];
foreach ($services as $srv) {
    $sid = $srv['ID_Servicio'] ?? null;
    if ($sid === null || $sid === '' || !is_numeric($sid)) {
        continue;
    }
    $validServiceIds[(string)$sid] = true;
}

try {
    $infoBarberia = AutoloadDB::getConfigSection('info_barberia');
} catch (Throwable $e) {
    $infoBarberia = [];
}
$validScheduleDays = [];
if (isset($infoBarberia['horarios']) && is_array($infoBarberia['horarios'])) {
    foreach ($infoBarberia['horarios'] as $dayKey => $config) {
        if (!is_array($config)) {
            continue;
        }
        $normalized = strtolower(trim((string)$dayKey));
        if ($normalized === '' || $normalized === 'feriados') {
            continue;
        }
        $validScheduleDays[$normalized] = true;
    }
}

function tenantCommerceId(): ?int
{
    return \Agenduy\Core\CommercePanel::commerceIdForTenantRoot(dirname(__DIR__, 4));
}

function assetUploadDir(string $kind): string
{
    $commerceId = tenantCommerceId();
    if ($commerceId !== null && $commerceId > 0) {
        return CommerceStorage::kindDir($commerceId, $kind);
    }
    $dir = dirname(__DIR__, 4) . '/src/img/' . $kind;
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

function assetStoredPath(string $kind, string $filename): string
{
    $commerceId = tenantCommerceId();
    if ($commerceId !== null && $commerceId > 0) {
        return CommerceStorage::relativePath($commerceId, $kind, $filename);
    }
    return 'src/img/' . $kind . '/' . $filename;
}

/**
 * Normaliza habilidades recibidas y filtra contra la tabla de servicios.
 */
function buildSkillsString($input, array $validIds): string
{
    $skills = [];
    if (is_array($input)) {
        foreach ($input as $val) {
            $id = trim((string)$val);
            if ($id !== '') {
                $skills[] = $id;
            }
        }
    } else {
        $raw = str_replace(';', ',', (string)$input);
        foreach (explode(',', $raw) as $val) {
            $id = trim((string)$val);
            if ($id !== '') {
                $skills[] = $id;
            }
        }
    }
    $skills = array_values(array_unique($skills));
    $filtered = [];
    foreach ($skills as $sid) {
        if (isset($validIds[$sid])) {
            $filtered[] = $sid;
        }
    }
    return implode(', ', $filtered);
}

/**
 * Maneja la carga de imagen opcional.
 */
function handleProfileUpload(string $fieldName, array &$errors, string $existing = ''): string
{
    $file = $_FILES[$fieldName] ?? null;
    if (!$file || !isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return $existing;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'No se pudo subir la imagen del perfil.';
        return $existing;
    }
    $maxSize = 5 * 1024 * 1024;
    if (!isset($file['size']) || (int)$file['size'] > $maxSize) {
        $errors[] = 'La imagen de perfil supera el tamano maximo permitido (5 MB).';
        return $existing;
    }
    $ext = strtolower((string)pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'webp'];
    if (!in_array($ext, $allowed, true)) {
        $errors[] = 'Formato de imagen invalido. Usa JPG, PNG o WebP.';
        return $existing;
    }

    $uploadDir = assetUploadDir('barbers');
    try {
        $token = bin2hex(random_bytes(4));
    } catch (Throwable $e) {
        $token = substr(str_replace('.', '', (string)microtime(true)), -8);
    }
    $unique = 'Barber_' . date('Ymd_His') . '_' . $token . '.' . $ext;
    $destPath = rtrim($uploadDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $unique;
    if (!@move_uploaded_file($file['tmp_name'], $destPath)) {
        $errors[] = 'No se pudo guardar la imagen de perfil.';
        return $existing;
    }

    return assetStoredPath('barbers', $unique);
}

/**
 * Normaliza los dias de trabajo seleccionados contra la definicion de horarios.
 *
 * @return string[] Lista de dias en minusculas.
 */
function normalizeWorkingDays($input, array $validDays): array
{
    $raw = [];
    if (is_array($input)) {
        foreach ($input as $val) {
            $raw[] = $val;
        }
    } else {
        $raw = explode(',', str_replace(';', ',', (string)$input));
    }
    $normalized = [];
    foreach ($raw as $value) {
        $key = strtolower(trim((string)$value));
        if ($key === '' || !isset($validDays[$key])) {
            continue;
        }
        $normalized[$key] = true;
    }
    return array_keys($normalized);
}

/**
 * Sincroniza la asignacion de dias de trabajo dentro de info_barberia->horarios.
 *
 * @param int $barberId
 * @param string[] $dayKeys
 */
function syncBarberWorkingDays(int $barberId, array $dayKeys): void
{
    if ($barberId <= 0) {
        return;
    }
    try {
        $info = AutoloadDB::getConfigSection('info_barberia');
    } catch (Throwable $e) {
        error_log('[barberos] No se pudo leer info_barberia para sincronizar dias: ' . $e->getMessage());
        return;
    }
    if (!isset($info['horarios']) || !is_array($info['horarios'])) {
        return;
    }
    $selection = [];
    foreach ($dayKeys as $day) {
        $selection[strtolower(trim((string)$day))] = true;
    }

    $changed = false;
    foreach ($info['horarios'] as $dayKey => $config) {
        if (!is_array($config)) {
            continue;
        }
        if ($dayKey === 'feriados') {
            continue;
        }
        $normalized = strtolower(trim((string)$dayKey));
        if ($normalized === '') {
            continue;
        }

        $existing = [];
        foreach (['barber_ids', 'barberos', 'barbers'] as $field) {
            if (!isset($config[$field]) || !is_array($config[$field])) {
                continue;
            }
            foreach ($config[$field] as $id) {
                $intId = (int)$id;
                if ($intId > 0) {
                    $existing[$intId] = true;
                }
            }
        }

        $has = isset($existing[$barberId]);
        $shouldHave = isset($selection[$normalized]);
        if ($shouldHave && !$has) {
            $existing[$barberId] = true;
            $changed = true;
        } elseif (!$shouldHave && $has) {
            unset($existing[$barberId]);
            $changed = true;
        }

        $newList = array_values(array_map('intval', array_keys($existing)));
        if (!isset($info['horarios'][$dayKey]['barber_ids']) || $info['horarios'][$dayKey]['barber_ids'] !== $newList) {
            $info['horarios'][$dayKey]['barber_ids'] = $newList;
            if (!$changed) {
                $changed = $changed || $newList !== ($config['barber_ids'] ?? []);
            }
        }
    }

    if ($changed) {
        try {
            AutoloadDB::updateConfigSection('info_barberia', ['horarios' => $info['horarios']]);
        } catch (Throwable $e) {
            error_log('[barberos] No se pudo actualizar las asignaciones de horarios: ' . $e->getMessage());
        }
    }
}

/**
 * Recibe campos comunes y valida.
 *
 * @return array{errors: string[], data: array}
 */
function collectCommonData(string $skillsString, string $workingDaysString): array
{
    $nombre = trim((string)($_POST['Nombre'] ?? ''));
    $apellido = trim((string)($_POST['Apellido'] ?? ''));
    $cedula = trim((string)($_POST['Cedula'] ?? ''));
    $psw = trim((string)($_POST['Psw'] ?? ''));
    $rol = trim((string)($_POST['Rol'] ?? ''));
    $comisionRaw = trim((string)($_POST['Comision'] ?? ''));

    $errors = [];
    if ($nombre === '') {
        $errors[] = 'El nombre es obligatorio.';
    }
    if ($cedula === '' || !preg_match('/^[0-9]{7,}$/', $cedula)) {
        $errors[] = 'La cedula debe contener solo numeros y tener al menos 7 digitos.';
    }
    if ($psw === '' || !preg_match('/^(?=.*[A-Z])(?=.*[0-9]).{8,}$/', $psw)) {
        $errors[] = 'La contrasena debe tener minimo 8 caracteres, 1 mayuscula y 1 numero.';
    }
    if ($rol === '') {
        $rol = 'Func';
    }
    $rol = strtoupper($rol) === 'ADMIN' ? 'Admin' : 'Func';

    $comision = null;
    if ($comisionRaw !== '') {
        $normalized = str_replace(',', '.', $comisionRaw);
        if (!is_numeric($normalized)) {
            $errors[] = 'La comision debe ser un numero valido.';
        } else {
            $value = (float)$normalized;
            if ($value < 0 || $value > 100) {
                $errors[] = 'La comision debe estar entre 0 y 100.';
            } else {
                $comision = round($value, 2);
            }
        }
    }

    return [
        'errors' => $errors,
        'data' => [
            'Nombre' => $nombre,
            'Apellido' => $apellido,
        'Cedula' => $cedula,
        'Psw' => $psw,
        'Rol' => $rol,
        'Habilidades' => $skillsString,
        'Comision' => $comision,
        'DiasTrabajo' => $workingDaysString,
    ],
  ];
}

$skillsString = buildSkillsString($_POST['Habilidades'] ?? '', $validServiceIds);
$workingDays = normalizeWorkingDays($_POST['DiasTrabajo'] ?? '', $validScheduleDays);
$workingDaysString = implode(', ', $workingDays);
$payload = collectCommonData($skillsString, $workingDaysString);
$errors = $payload['errors'];
$data = $payload['data'];

if ($action === 'create') {
    $data['Disponibilidad'] = 'Disponible';
    $data['Status'] = 'Offline';
    $data['Perfil'] = handleProfileUpload('Perfil', $errors, '');

    if (!empty($errors)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'errors' => $errors]);
        exit;
    }

    // Hash the password before persisting
    if (!empty($data['Psw'])) {
        $data['Psw'] = password_hash((string)$data['Psw'], PASSWORD_DEFAULT);
    }
    try {
        $tenantSlug = \Agenduy\Core\CommercePanel::resolveEffectiveSlug(dirname(__DIR__, 4));
        $plan = MembershipPlan::forCommerceSlug($tenantSlug);
        if (is_array($plan)) {
            $maxProfessionals = MembershipPlan::maxProfessionals($plan);
            if ($maxProfessionals !== null) {
                $currentCount = count(AutoloadDB::all('barberos'));
                if ($currentCount >= $maxProfessionals) {
                    http_response_code(403);
                    echo json_encode(MembershipPlan::denialPayload('PLAN_LIMIT_MAX_PROFESSIONALS', [
                        'max_professionals' => $maxProfessionals,
                        'current' => $currentCount,
                    ]));
                    exit;
                }
            }
        }
        $row = AutoloadDB::insert('barberos', $data);
        $newId = isset($row['ID_Barber']) ? (int)$row['ID_Barber'] : 0;
        if ($newId > 0) {
            syncBarberWorkingDays($newId, $workingDays);
        }
        echo json_encode(['ok' => true, 'data' => $row]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'No se pudo registrar al profesional.']);
    }
    exit;
}

// Update flow
$id = $_POST['ID_Barber'] ?? $_POST['id'] ?? null;
if ($id === null || $id === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Falta el identificador del profesional.']);
    exit;
}

$current = AutoloadDB::find('barberos', $id);
if (!$current) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Profesional no encontrado.']);
    exit;
}

$perfilActual = trim((string)($_POST['PerfilActual'] ?? ($current['Perfil'] ?? '')));
$data['Perfil'] = handleProfileUpload('Perfil', $errors, $perfilActual);

if (!empty($errors)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'errors' => $errors]);
    exit;
}

// If a new password was provided, and it's different from current, hash it
if (isset($data['Psw']) && $data['Psw'] !== '') {
    $currentPsw = $current['Psw'] ?? '';
    if ($data['Psw'] !== $currentPsw) {
        $data['Psw'] = password_hash((string)$data['Psw'], PASSWORD_DEFAULT);
    }
}
try {
    $row = AutoloadDB::updateById('barberos', $id, $data);
    if (!$row) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'No se pudo actualizar al profesional.']);
        exit;
    }
    syncBarberWorkingDays((int)$id, $workingDays);
    echo json_encode(['ok' => true, 'data' => $row]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'No se pudo actualizar al profesional.']);
}
