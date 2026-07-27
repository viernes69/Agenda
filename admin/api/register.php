<?php
/**
 * Agenduy - API: Registro de nuevo comercio
 *
 * POST /admin/api/register.php
 *   body: { owner: {nombre, apellido, cedula, email, password},
 *           negocio: {nombre, pais, ciudad, calle, telefono, rut, rubro_id},
 *           planId, servicios: [{nombre, duracion, precio}],
 *           horarios: { lunes: {abierto, inicio, fin, ...}, ... },
 *           _csrf }
 */
declare(strict_types=1);

$config = require __DIR__ . '/../../src/Core/bootstrap.php';

use Agenduy\Core\Database;
use Agenduy\Core\CSRF;
use Agenduy\Core\Keys;
use Agenduy\Core\NotificationOutbox;

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido']);
    exit;
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);
if (!is_array($payload)) $payload = $_POST;

$csrf = $payload['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
if (!CSRF::validate(is_string($csrf) ? $csrf : null, 'public_booking')) {
    http_response_code(419);
    echo json_encode(['ok' => false, 'error' => 'CSRF inválido.']);
    exit;
}

$owner    = $payload['owner']    ?? [];
$business = $payload['negocio']  ?? [];
$schedule = $payload['horarios'] ?? [];
$services = $payload['servicios'] ?? [];
$planId   = (int)($payload['planId'] ?? 0);

$email = strtolower(trim((string)($owner['email'] ?? '')));
$pass  = (string)($owner['password'] ?? '');
$name  = trim((string)($owner['nombre'] ?? ''));
$last  = trim((string)($owner['apellido'] ?? ''));
$cedula= trim((string)($owner['cedula'] ?? ''));
$bizName = trim((string)($business['nombre'] ?? ''));
$pais  = strtoupper(trim((string)($business['pais'] ?? 'UY')));
$ciudad= trim((string)($business['ciudad'] ?? ''));
$calle = trim((string)($business['calle'] ?? ''));
$tel   = trim((string)($business['telefono'] ?? ''));
$rut   = trim((string)($business['rut'] ?? ''));
$rubroId= (int)($business['rubroId'] ?? 0);
$tz    = trim((string)($schedule['timezone'] ?? 'America/Montevideo'));
$db = Database::getInstance();

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Email inválido.']);
    exit;
}
if (strlen($pass) < 8) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'La contraseña debe tener al menos 8 caracteres.']);
    exit;
}
if ($name === '' || $bizName === '' || $ciudad === '' || $calle === '' || $tel === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Completá los datos obligatorios.']);
    exit;
}
if ($rubroId <= 0) {
    $rubroId = (int)($db->fetchValue('SELECT id_rubro FROM rubros ORDER BY id_rubro ASC LIMIT 1') ?? 0);
    if ($rubroId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'No hay rubros configurados.']);
        exit;
    }
}

// ¿Email ya registrado?
$exists = $db->fetchOne('SELECT id_user FROM users WHERE email = :e', [':e' => $email]);
if ($exists) {
    http_response_code(409);
    echo json_encode(['ok' => false, 'error' => 'Ese email ya tiene una cuenta. Iniciá sesión.']);
    exit;
}

// ¿Slug libre?
$baseSlug = Keys::slug($bizName);
$slug = $baseSlug; $i = 2;
while ($db->fetchOne('SELECT id_commerce FROM commerces WHERE slug = :s', [':s' => $slug])) {
    $slug = $baseSlug . '-' . $i++;
}

// Membresía por defecto si no se eligió
if ($planId <= 0) {
    $planId = (int)$db->fetchValue('SELECT id_membership FROM memberships WHERE activo=1 ORDER BY id_membership ASC LIMIT 1');
}
$membership = $planId > 0 ? $db->fetchOne('SELECT * FROM memberships WHERE id_membership = :id', [':id' => $planId]) : null;
$trialDays = $membership ? (int)$membership['trial_dias'] : 30;
$trialEnd = date('Y-m-d', strtotime("+{$trialDays} days"));

$db->transaction(function () use (&$idCommerce, $db, $slug, $rubroId, $planId, $bizName, $rut, $email, $tel, $pais, $ciudad, $calle, $tz, $trialEnd, $name, $last, $cedula, $pass, $services, $schedule) {
    $idCommerce = (int)$db->insert('commerces', [
        'slug'             => $slug,
        'id_rubro'         => $rubroId,
        'id_membership'    => $planId ?: null,
        'nombre'           => $bizName,
        'razon_social'     => $bizName,
        'rut_ruc'          => $rut,
        'email'            => $email,
        'telefono'         => $tel,
        'whatsapp'         => $tel,
        'pais'             => $pais,
        'ciudad'           => $ciudad,
        'calle'            => $calle,
        'timezone'         => $tz,
        'status'           => 'trial',
        'trial_expires_at' => $trialEnd,
        'serial'           => Keys::serial(),
    ]);

    $idUser = (int)$db->insert('users', [
        'role'          => 'commerce_admin',
        'id_commerce'   => $idCommerce,
        'nombre'        => $name,
        'apellido'      => $last,
        'cedula'        => $cedula,
        'email'         => $email,
        'telefono'      => $tel,
        'whatsapp'      => $tel,
        'password_hash' => password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]),
        'activo'        => 1,
    ]);

    if ($planId > 0) {
        $db->insert('subscriptions', [
            'id_commerce'         => $idCommerce,
            'id_membership'       => $planId,
            'status'              => 'trial',
            'current_period_start'=> date('Y-m-d'),
            'current_period_end'  => $trialEnd,
            'trial_expires_at'    => $trialEnd,
        ]);
    }

    // Servicios iniciales
    foreach ((array)$services as $svc) {
        if (!is_array($svc)) continue;
        $sname = trim((string)($svc['nombre'] ?? ''));
        if ($sname === '') continue;
        $db->insert('services', [
            'id_commerce'  => $idCommerce,
            'nombre'       => $sname,
            'duracion_min' => max(15, (int)($svc['duracion'] ?? 30)),
            'precio'       => (float)($svc['precio'] ?? 0),
            'estado'       => 'Activo',
        ]);
    }

    return $idCommerce;
});

NotificationOutbox::enqueueRegistrationNotifications((int)$idCommerce, $email, $tel, [
    'nombre' => $name,
    'negocio' => $bizName,
    'trial_end' => $trialEnd,
    'slug' => $slug,
]);

echo json_encode([
    'ok' => true,
    'id_commerce' => $idCommerce,
    'slug' => $slug,
    'trial_expires_at' => $trialEnd,
    'message' => 'Cuenta creada. Revisá tu email para los siguientes pasos.',
], JSON_UNESCAPED_UNICODE);
