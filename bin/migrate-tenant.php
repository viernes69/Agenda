<?php
declare(strict_types=1);

/**
 * Migra un tenant legacy (database.php) a SQLite sin duplicar.
 * Uso: php bin/migrate-tenant.php terapeuta-luck
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$projectRoot = dirname(__DIR__);
require $projectRoot . '/src/Core/bootstrap.php';

use Agenduy\Core\CommerceSettings;
use Agenduy\Core\Database;
use Agenduy\Core\Keys;

$slug = $argv[1] ?? 'terapeuta-luck';
if (!preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug)) {
    fwrite(STDERR, "Slug inválido.\n");
    exit(1);
}

$tenantDir = $projectRoot . DIRECTORY_SEPARATOR . $slug;
$dbPath = $tenantDir . '/src/db/database.php';
if (!is_file($dbPath)) {
    fwrite(STDERR, "No existe {$dbPath}\n");
    exit(1);
}

$legacy = include $dbPath;
if (!is_array($legacy) || !isset($legacy['info_barberia']) || !is_array($legacy['info_barberia'])) {
    fwrite(STDERR, "database.php inválido.\n");
    exit(1);
}

$info = $legacy['info_barberia'];
$db = Database::getInstance();

$existing = $db->fetchOne('SELECT * FROM commerces WHERE slug = :s', [':s' => $slug]);
if ($existing) {
    $commerceId = (int)$existing['id_commerce'];
    echo "Comercio ya existe (id={$commerceId}). Actualizando settings/servicios...\n";
} else {
    $rubroId = (int)($info['ID_Rubro'] ?? 0);
    $rubroExists = $rubroId > 0
        ? $db->fetchOne('SELECT id_rubro FROM rubros WHERE id_rubro = :id', [':id' => $rubroId])
        : null;
    if (!$rubroExists) {
        $rubroId = (int)$db->fetchValue('SELECT id_rubro FROM rubros ORDER BY id_rubro ASC LIMIT 1');
    }
    if ($rubroId <= 0) {
        fwrite(STDERR, "No hay rubros en SQLite.\n");
        exit(1);
    }
    $planId = (int)$db->fetchValue('SELECT id_membership FROM memberships WHERE activo = 1 ORDER BY precio ASC LIMIT 1');
    $trialDays = (int)$db->fetchValue('SELECT trial_dias FROM memberships WHERE id_membership = :id', [':id' => $planId]) ?: 30;
    $trialEnd = date('Y-m-d', strtotime("+{$trialDays} days"));

    $email = strtolower(trim((string)($info['email'] ?? ($info['contacto']['email'] ?? ''))));
    $tel = trim((string)($info['contacto']['telefono'] ?? ''));
    $whatsapp = trim((string)($info['contacto']['whatsapp'] ?? $tel));

    $commerceId = (int)$db->insert('commerces', [
        'slug'             => $slug,
        'id_rubro'         => $rubroId,
        'id_membership'    => $planId,
        'nombre'           => (string)($info['nombre'] ?? $slug),
        'razon_social'     => (string)($info['razon_social'] ?? ($info['nombre'] ?? $slug)),
        'rut_ruc'          => (string)($info['rut_ruc'] ?? ''),
        'email'            => $email,
        'telefono'         => $tel,
        'whatsapp'         => $whatsapp,
        'pais'             => (string)($info['direccion']['pais'] ?? 'UY'),
        'ciudad'           => (string)($info['direccion']['ciudad'] ?? ''),
        'calle'            => (string)($info['direccion']['calle'] ?? ''),
        'website'          => (string)($info['contacto']['website'] ?? ''),
        'slogan'           => (string)($info['slogan'] ?? ''),
        'descripcion'      => (string)($info['descripcion'] ?? ''),
        'logo'             => (string)($info['logo_src'] ?? ''),
        'timezone'         => (string)($info['horarios']['timezone'] ?? 'America/Montevideo'),
        'status'           => 'trial',
        'trial_expires_at' => $trialEnd,
        'serial'           => Keys::serial(),
    ]);

    $db->insert('subscriptions', [
        'id_commerce'          => $commerceId,
        'id_membership'        => $planId,
        'status'               => 'trial',
        'gateway'              => 'manual',
        'started_at'           => date('Y-m-d'),
        'trial_expires_at'     => $trialEnd,
        'current_period_start' => date('Y-m-d'),
        'current_period_end'   => $trialEnd,
        'notes'                => 'Migración legacy ' . $slug,
    ]);

    echo "Comercio creado id={$commerceId}\n";
}

// Admin user from legacy barberos
$admin = null;
foreach (($legacy['barberos'] ?? []) as $b) {
    if (!is_array($b) || empty($b['ID_Barber'])) {
        continue;
    }
    if (strtolower((string)($b['Rol'] ?? '')) === 'admin') {
        $admin = $b;
        break;
    }
}
if ($admin) {
    $adminEmail = strtolower(trim((string)($info['email'] ?? '')));
    if ($adminEmail === '') {
        $adminEmail = $slug . '@migrated.local';
    }
    $user = $db->fetchOne('SELECT id_user FROM users WHERE email = :e', [':e' => $adminEmail]);
    if (!$user) {
        $userId = (int)$db->insert('users', [
            'role'          => 'commerce_admin',
            'id_commerce'   => $commerceId,
            'nombre'        => (string)($admin['Nombre'] ?? 'Admin'),
            'apellido'      => (string)($admin['Apellido'] ?? ''),
            'cedula'        => (string)($admin['Cedula'] ?? ''),
            'email'         => $adminEmail,
            'telefono'      => (string)($info['contacto']['telefono'] ?? ''),
            'whatsapp'      => (string)($info['contacto']['whatsapp'] ?? ''),
            'password_hash' => (string)($admin['Psw'] ?? password_hash('ChangeMe123!', PASSWORD_BCRYPT)),
            'activo'        => 1,
        ]);
        echo "Usuario admin creado id={$userId}\n";
    } else {
        $db->update('users', ['id_commerce' => $commerceId], 'id_user = :id', [':id' => $user['id_user']]);
        echo "Usuario admin ya existía; vinculado al comercio.\n";
    }
}

// Services (skip null placeholders; key by local ID, not name)
$existingLocals = $db->fetchAll(
    'SELECT id_local FROM services WHERE id_commerce = :c AND id_local IS NOT NULL',
    [':c' => $commerceId]
);
$knownLocals = array_map('intval', array_column($existingLocals, 'id_local'));
$added = 0;
foreach (($legacy['servicios'] ?? []) as $svc) {
    if (!is_array($svc) || empty($svc['ID_Servicio'])) {
        continue;
    }
    $idLocal = (int)$svc['ID_Servicio'];
    if ($idLocal <= 0 || in_array($idLocal, $knownLocals, true)) {
        continue;
    }
    $name = trim((string)($svc['Nombre'] ?? ''));
    if ($name === '') {
        continue;
    }
    $db->insert('services', [
        'id_commerce'  => $commerceId,
        'id_local'     => $idLocal,
        'nombre'       => $name,
        'duracion_min' => max(15, (int)($svc['Duracion'] ?? 30)),
        'precio'       => (float)($svc['Precio'] ?? 0),
        'estado'       => (string)($svc['Estado'] ?? 'Activo') === 'Activo' ? 'Activo' : 'Inactivo',
        'imagen'       => (string)($svc['Img_Link'] ?? ''),
    ]);
    $knownLocals[] = $idLocal;
    $added++;
}
echo "Servicios añadidos: {$added}\n";

$sectionMap = [
    'horarios' => 'horarios',
    'reservas' => 'reservas',
    'moneda' => 'moneda',
    'fiscal' => 'fiscal',
    'redes' => 'redes',
    'seo' => 'seo',
    'legal' => 'legales',
    'notificaciones' => 'notificaciones',
    'funciones' => 'features',
    'tema' => 'temas',
];
foreach ($sectionMap as $section => $legacyKey) {
    $data = $info[$legacyKey] ?? null;
    if (!is_array($data)) {
        $data = CommerceSettings::defaultsForSection($section);
    }
    CommerceSettings::set($commerceId, $section, $data);
}
echo "Settings sincronizados.\n";
echo "OK migrate {$slug}\n";
