<?php
declare(strict_types=1);

namespace Agenduy\Core;

/**
 * Auditoría del modelo multi-tenant (fase 0 del plan de unificación).
 */
final class TenantAudit
{
    /** Archivos que sync-tenant.php propaga desde template → tenants. */
    private const SYNC_MANIFEST = [
        'index.php',
        'src/API/AdminConfig.php',
        'src/API/reservas.php',
        'private/session_guard.php',
        'private/dashboard/admin/index.php',
        'private/dashboard/src/api/servicios.php',
        'private/dashboard/src/api/productos.php',
        'private/dashboard/src/api/barberos.php',
        'src/API/Autoload.php',
    ];

    /** Puntos de doble escritura SQLite ↔ database.php local. */
    private const DUAL_WRITE_TOUCHPOINTS = [
        'admin/api/appointments.php → TenantLocalDb::mirrorAppointment',
        'admin/api/cart_order.php → TenantLocalDb',
        'template/src/API/Autoload.php → pushReservaToCentral',
        'template/src/API/AdminConfig.php → AutoloadDB + CommerceSettings',
        'src/Core/CommerceSetup.php → syncLegacyDatabase',
    ];

    /**
     * @return array<string,mixed>
     */
    public static function run(?string $projectRoot = null): array
    {
        $root = $projectRoot ?? dirname(__DIR__, 2);
        $folders = TenantMigrator::scanFolders($root);
        $templateCount = self::countFiles($root . DIRECTORY_SEPARATOR . 'template');
        $tenants = [];
        foreach ($folders as $row) {
            if (empty($row['folder'])) {
                continue;
            }
            $slug = (string)$row['slug'];
            $tenants[$slug] = [
                'slug'       => $slug,
                'registered' => !empty($row['registered']),
                'file_count' => self::countFiles($root . DIRECTORY_SEPARATOR . $slug),
                'drift'        => self::driftFromTemplate($root, $slug),
            ];
        }

        return [
            'generated_at'          => date('c'),
            'legacy_folders_enabled'=> TenantConfig::useLegacyFolders(),
            'template_files'        => $templateCount,
            'folder_scan'           => $folders,
            'tenant_details'        => array_values($tenants),
            'dual_write_touchpoints'=> self::DUAL_WRITE_TOUCHPOINTS,
            'storage'               => CommerceStorage::auditAll(),
            'recommendations'       => self::recommendations($folders),
        ];
    }

    /**
     * @return list<string>
     */
    private static function recommendations(array $folders): array
    {
        $tips = [];
        if (TenantConfig::useLegacyFolders()) {
            $tips[] = 'AGENDUY_TENANT_FOLDERS=true: los registros nuevos siguen clonando template/. Poné false para desactivar.';
        } else {
            $tips[] = 'Registros nuevos sin carpeta tenant (modo central activo).';
        }
        foreach ($folders as $row) {
            if (!empty($row['folder']) && empty($row['registered'])) {
                $tips[] = 'Carpeta "' . $row['slug'] . '" sin fila SQLite → importar desde admin/commerces.php.';
            }
            if (empty($row['folder']) && !empty($row['registered'])) {
                $tips[] = 'Comercio "' . $row['slug'] . '" en SQLite sin carpeta (OK en modo central).';
            }
        }
        if ($tips === []) {
            $tips[] = 'Sin inconsistencias críticas detectadas.';
        }
        return $tips;
    }

    /**
     * @return list<array{path:string, status:string}>
     */
    private static function driftFromTemplate(string $root, string $slug): array
    {
        $out = [];
        foreach (self::SYNC_MANIFEST as $rel) {
            $tpl = $root . DIRECTORY_SEPARATOR . 'template' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
            $ten = $root . DIRECTORY_SEPARATOR . $slug . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
            if (!is_file($tpl)) {
                $out[] = ['path' => $rel, 'status' => 'missing_template'];
                continue;
            }
            if (!is_file($ten)) {
                $out[] = ['path' => $rel, 'status' => 'missing_in_tenant'];
                continue;
            }
            if (hash_file('sha256', $tpl) !== hash_file('sha256', $ten)) {
                $out[] = ['path' => $rel, 'status' => 'drift'];
            }
        }
        return $out;
    }

    private static function countFiles(string $dir): int
    {
        if (!is_dir($dir)) {
            return 0;
        }
        $count = 0;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if ($file->isFile()) {
                $count++;
            }
        }
        return $count;
    }
}
