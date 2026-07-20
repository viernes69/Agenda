<?php
declare(strict_types=1);

namespace Agenduy\Core;

use RuntimeException;

/**
 * Importa un tenant legacy (carpeta + src/db/database.php) a SQLite central.
 */
final class TenantMigrator
{
    /**
     * @return array{ok:bool, slug:string, id_commerce:int, created:bool, services_added:int, message:string}
     */
    public static function import(string $slug, ?string $projectRoot = null): array
    {
        $slug = trim($slug, '/');
        if (!preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug)) {
            throw new RuntimeException('Slug inválido.');
        }

        $root = $projectRoot ?? dirname(__DIR__, 2);
        $dbPath = $root . DIRECTORY_SEPARATOR . $slug . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'db' . DIRECTORY_SEPARATOR . 'database.php';
        if (!is_file($dbPath)) {
            throw new RuntimeException("No existe la carpeta del tenant o falta {$slug}/src/db/database.php.");
        }

        $legacy = include $dbPath;
        if (!is_array($legacy) || !isset($legacy['info_barberia']) || !is_array($legacy['info_barberia'])) {
            throw new RuntimeException('database.php del tenant inválido.');
        }

        $info = $legacy['info_barberia'];
        $db = Database::getInstance();
        $created = false;
        $servicesAdded = 0;

        $existing = $db->fetchOne('SELECT * FROM commerces WHERE slug = :s', [':s' => $slug]);
        if ($existing) {
            $commerceId = (int)$existing['id_commerce'];
        } else {
            $rubroId = (int)($info['ID_Rubro'] ?? 0);
            $rubroExists = $rubroId > 0
                ? $db->fetchOne('SELECT id_rubro FROM rubros WHERE id_rubro = :id', [':id' => $rubroId])
                : null;
            if (!$rubroExists) {
                $rubroId = (int)$db->fetchValue('SELECT id_rubro FROM rubros ORDER BY id_rubro ASC LIMIT 1');
            }
            if ($rubroId <= 0) {
                throw new RuntimeException('No hay rubros en la base central.');
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
                'notes'                => 'Importación carpeta ' . $slug,
            ]);
            $created = true;
        }

        self::syncAdminUser($db, $legacy, $info, $commerceId, $slug);
        $servicesAdded = self::syncServices($db, $legacy, $commerceId);
        self::syncSettings($info, $commerceId);

        return [
            'ok'             => true,
            'slug'           => $slug,
            'id_commerce'    => $commerceId,
            'created'        => $created,
            'services_added' => $servicesAdded,
            'message'        => $created
                ? "Comercio {$slug} registrado (id {$commerceId})."
                : "Comercio {$slug} actualizado desde su carpeta (id {$commerceId}).",
        ];
    }

    /**
     * Carpetas tenant con database.php que aún no están en SQLite (o al revés).
     *
     * @return list<array{slug:string, folder:bool, registered:bool, nombre:string}>
     */
    public static function scanFolders(?string $projectRoot = null): array
    {
        $root = $projectRoot ?? dirname(__DIR__, 2);
        $skip = [
            'admin', 'src', 'storage', 'template', 'template_curso', 'assets', 'public', 'bin', 'tests',
            'vendor', 'components', 'node_modules', '.git', '.cursor', 'Private', 'docs', 'tools',
            'categorias', 'ubicaciones', 'cgi-bin', '.well-known',
        ];
        $db = Database::getInstance();
        $registered = [];
        foreach ($db->fetchAll('SELECT slug, nombre FROM commerces ORDER BY slug') as $row) {
            $slug = (string)$row['slug'];
            if (TenantConfig::isIgnoredTenantSlug($slug)) {
                continue;
            }
            $registered[$slug] = (string)$row['nombre'];
        }

        $rows = [];
        $seen = [];
        foreach (scandir($root) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || in_array($entry, $skip, true)) {
                continue;
            }
            if (TenantConfig::isIgnoredTenantSlug($entry)) {
                continue;
            }
            $path = $root . DIRECTORY_SEPARATOR . $entry;
            if (!is_dir($path)) {
                continue;
            }
            $dbFile = $path . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'db' . DIRECTORY_SEPARATOR . 'database.php';
            if (!is_file($dbFile)) {
                continue;
            }
            $seen[$entry] = true;
            $legacy = @include $dbFile;
            $nombre = is_array($legacy) && is_array($legacy['info_barberia'] ?? null)
                ? (string)($legacy['info_barberia']['nombre'] ?? $entry)
                : $entry;
            $rows[] = [
                'slug'       => $entry,
                'folder'     => true,
                'registered' => isset($registered[$entry]),
                'nombre'     => $nombre,
            ];
        }

        foreach ($registered as $slug => $nombre) {
            if (isset($seen[$slug])) {
                continue;
            }
            $rows[] = [
                'slug'       => $slug,
                'folder'     => is_dir($root . DIRECTORY_SEPARATOR . $slug),
                'registered' => true,
                'nombre'     => $nombre,
            ];
        }

        usort($rows, static fn(array $a, array $b): int => strcmp($a['slug'], $b['slug']));
        return $rows;
    }

    /**
     * Filas relevantes para el panel super admin (sin tenants ignorados ni ruido en modo central).
     *
     * @return list<array{slug:string, folder:bool, registered:bool, nombre:string}>
     */
    public static function scanFoldersForAdmin(?string $projectRoot = null): array
    {
        $rows = self::scanFolders($projectRoot);
        if (TenantConfig::useLegacyFolders()) {
            return $rows;
        }

        // Modo central: solo carpetas huérfanas en disco que aún no están en SQLite.
        return array_values(array_filter(
            $rows,
            static fn(array $row): bool => !empty($row['folder']) && empty($row['registered'])
        ));
    }

    private static function syncAdminUser(Database $db, array $legacy, array $info, int $commerceId, string $slug): void
    {
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
        if (!$admin) {
            return;
        }

        $adminEmail = strtolower(trim((string)($info['email'] ?? '')));
        if ($adminEmail === '') {
            $adminEmail = $slug . '@migrated.local';
        }
        $user = $db->fetchOne('SELECT id_user FROM users WHERE email = :e', [':e' => $adminEmail]);
        if (!$user) {
            $db->insert('users', [
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
        } else {
            $db->update('users', ['id_commerce' => $commerceId], 'id_user = :id', [':id' => $user['id_user']]);
        }
    }

    private static function syncServices(Database $db, array $legacy, int $commerceId): int
    {
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
        return $added;
    }

    private static function syncSettings(array $info, int $commerceId): void
    {
        $sectionMap = [
            'horarios'       => 'horarios',
            'reservas'       => 'reservas',
            'moneda'         => 'moneda',
            'fiscal'         => 'fiscal',
            'redes'          => 'redes',
            'seo'            => 'seo',
            'legal'          => 'legales',
            'notificaciones' => 'notificaciones',
            'funciones'      => 'features',
            'tema'           => 'temas',
        ];
        foreach ($sectionMap as $section => $legacyKey) {
            $data = $info[$legacyKey] ?? null;
            if (!is_array($data)) {
                $data = CommerceSettings::defaultsForSection($section);
            }
            CommerceSettings::set($commerceId, $section, $data);
        }
    }
}
