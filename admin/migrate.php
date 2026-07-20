<?php
/**
 * Agenduy - Migración de datos legacy
 *
 * Toma el archivo src/db/database.php (viejo) y migra su contenido
 * a la nueva BD SQLite.
 *
 * USO: /admin/migrate.php?key=INSTALL_AGENDUY_2026
 */
declare(strict_types=1);

$config = require __DIR__ . '/../src/Core/bootstrap.php';

$installKey = getenv('AGENDUY_INSTALL_KEY') ?: 'INSTALL_AGENDUY_2026';
if (($_GET['key'] ?? '') !== $installKey) {
    http_response_code(403);
    echo "Acceso denegado.\n";
    exit;
}

use Agenduy\Core\Database;

$db = Database::getInstance();
$oldDbPath = dirname(__DIR__) . '/src/db/database.php';
$migration = ['migrated' => 0, 'skipped' => 0, 'errors' => []];

if (!is_file($oldDbPath)) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "No se encontró el archivo legacy: {$oldDbPath}\n";
    exit;
}

$old = @include $oldDbPath;
if (!is_array($old)) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "El archivo legacy no devolvió un array válido.\n";
    exit;
}

require_once __DIR__ . '/../src/Core/Keys.php';
require_once __DIR__ . '/../src/Core/Auth.php';

// ... (resto igual que en install.php, con namespace Agenduy\Core)
// Para mantenerlo simple, reutilizo la lógica de install vía include
$installCode = file_get_contents(__DIR__ . '/_migration_helper.php');
// Si no existe, lo creo on-the-fly
if (!$installCode) {
    // No, mejor: reescribo la lógica acá
}

// Para no duplicar 200 líneas, voy a hacer la migración inline
$migration = doMigration($old, $db, dirname(__DIR__));

header('Content-Type: text/plain; charset=utf-8');
echo "Migración completada\n";
echo "====================\n";
echo "  Migrados: {$migration['migrated']}\n";
echo "  Omitidos: {$migration['skipped']}\n";
echo "  Errores:  " . count($migration['errors']) . "\n";
foreach ($migration['errors'] as $e) echo "    - $e\n";

function doMigration(array $old, \Agenduy\Core\Database $db, string $root): array
{
    $result = ['migrated' => 0, 'skipped' => 0, 'errors' => []];

    if (!empty($old['rubros']) && is_array($old['rubros'])) {
        $rows = normalizeList($old['rubros'], 'ID_Rubro');
        foreach ($rows as $r) {
            $tipo = (string)($r['Tipo'] ?? '');
            if ($tipo === '') continue;
            $exists = $db->fetchOne('SELECT id_rubro FROM rubros WHERE tipo = :t', [':t' => $tipo]);
            if ($exists) { $result['skipped']++; continue; }
            $db->insert('rubros', [
                'nombre' => (string)($r['Nombre'] ?? $tipo),
                'tipo'   => $tipo,
                'descripcion' => (string)($r['Descripcion'] ?? ''),
                'imagen' => (string)($r['Imagen'] ?? ''),
                'activo' => 1,
            ]);
            $result['migrated']++;
        }
    }

    if (!empty($old['info_negocios']) && is_array($old['info_negocios'])) {
        $negocios = normalizeList($old['info_negocios'], 'ID_Negocio');
        $admins   = !empty($old['admins']) ? normalizeList($old['admins'], 'ID_Admin') : [];
        $serials  = !empty($old['serial'])  ? normalizeList($old['serial'],  'ID_Suscripcion') : [];

        $adminById = [];
        foreach ($admins as $a) {
            $id = (int)($a['ID_Admin'] ?? 0);
            if ($id > 0) $adminById[$id] = $a;
        }
        $serialByAdmin = [];
        foreach ($serials as $s) {
            $aid = (int)($s['ID_Admin'] ?? 0);
            if ($aid > 0 && !isset($serialByAdmin[$aid])) $serialByAdmin[$aid] = $s;
        }

        $firstRubro = $db->fetchOne('SELECT id_rubro FROM rubros LIMIT 1');
        $idRubro = $firstRubro ? (int)$firstRubro['id_rubro'] : 1;
        $idMembership = $db->fetchValue('SELECT id_membership FROM memberships ORDER BY id_membership ASC LIMIT 1');

        foreach ($negocios as $n) {
            $slug = trim((string)($n['URL'] ?? ''), '/');
            if ($slug === '') {
                $slug = \Agenduy\Core\Keys::slug((string)($n['nombre'] ?? 'comercio'));
            }
            $base = $slug; $i = 2;
            while ($db->fetchOne('SELECT id_commerce FROM commerces WHERE slug = :s', [':s' => $slug])) {
                $slug = $base . '-' . $i++;
            }
            $exists = $db->fetchOne('SELECT id_commerce FROM commerces WHERE slug = :s', [':s' => $slug]);
            if ($exists) { $result['skipped']++; continue; }

            $status = 'trial';
            $serialRow = $serialByAdmin[(int)($n['ID_Admin'] ?? 0)] ?? null;
            $trialExpires = null;
            if ($serialRow && !empty($serialRow['FechaRenovacion'])) {
                $dt = \DateTime::createFromFormat('d-m-Y', (string)$serialRow['FechaRenovacion']);
                if ($dt) $trialExpires = $dt->format('Y-m-d');
            }
            $serialCode = $serialRow['SERIAL'] ?? \Agenduy\Core\Keys::serial();
            $statusRaw = strtolower((string)($serialRow['Status'] ?? 'trial'));
            if ($statusRaw === 'activo') $status = 'active';

            $idCommerce = $db->insert('commerces', [
                'slug' => $slug,
                'id_rubro' => $idRubro,
                'id_membership' => $idMembership,
                'nombre' => (string)($n['nombre'] ?? $slug),
                'email' => '',
                'telefono' => (string)($n['telefono'] ?? ''),
                'whatsapp' => (string)($n['whatsapp'] ?? ($n['telefono'] ?? '')),
                'pais' => (string)($n['pais'] ?? 'UY'),
                'ciudad' => (string)($n['ciudad'] ?? ''),
                'calle' => (string)($n['calle'] ?? ''),
                'status' => $status,
                'trial_expires_at' => $trialExpires,
                'serial' => $serialCode,
            ]);

            $oldAdmin = $adminById[(int)($n['ID_Admin'] ?? 0)] ?? null;
            if ($oldAdmin) {
                $email = strtolower(trim((string)($oldAdmin['correo'] ?? '')));
                if ($email === '') $email = 'admin+' . $slug . '@agenduy.uy';
                $existsUser = $db->fetchOne('SELECT id_user FROM users WHERE email = :e', [':e' => $email]);
                if (!$existsUser) {
                    // Generamos nueva password (el hash viejo no es recuperable)
                    $newPwd = 'Cambiar' . date('Y') . '!';
                    $db->insert('users', [
                        'role' => 'commerce_admin',
                        'id_commerce' => $idCommerce,
                        'nombre' => (string)($oldAdmin['nombre'] ?? 'Admin'),
                        'cedula' => (string)($oldAdmin['cedula'] ?? ''),
                        'email' => $email,
                        'whatsapp' => (string)($oldAdmin['whatsapp'] ?? ''),
                        'password_hash' => password_hash($newPwd, PASSWORD_BCRYPT, ['cost' => 12]),
                        'activo' => 1,
                    ]);
                    $result['migrated']++;
                    $result['errors'][] = "{$email}: password temporal = {$newPwd}";
                }
            }

            $db->insert('subscriptions', [
                'id_commerce' => $idCommerce,
                'id_membership' => $idMembership,
                'status' => $status === 'active' ? 'active' : 'trial',
                'gateway_id' => $serialCode,
                'trial_expires_at' => $trialExpires,
                'current_period_start' => date('Y-m-d'),
                'current_period_end' => $trialExpires,
                'notes' => 'Migrado',
            ]);
            $result['migrated']++;
        }
    }
    return $result;
}

function normalizeList($value, string $primaryKey): array
{
    if (!is_array($value)) return [];
    $keys = array_keys($value);
    if ($keys === range(0, count($value) - 1)) return $value;
    if (isset($value[$primaryKey])) return [$value];
    $out = [];
    foreach ($value as $row) {
        if (is_array($row)) $out[] = $row;
    }
    return $out;
}
