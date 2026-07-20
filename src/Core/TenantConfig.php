<?php
declare(strict_types=1);

namespace Agenduy\Core;

/**
 * Feature flags del modelo multi-tenant (carpetas legacy vs perfil central).
 */
final class TenantConfig
{
    /**
     * Si true, al registrar se copia template/ → {slug}/ (modelo legacy).
     * Por defecto false: solo SQLite + panel central.
     */
    public static function useLegacyFolders(): bool
    {
        $env = getenv('AGENDUY_TENANT_FOLDERS');
        if ($env !== false && $env !== '') {
            return filter_var($env, FILTER_VALIDATE_BOOLEAN);
        }
        $cfg = Database::getInstance()->config();
        return (bool)($cfg['tenant']['legacy_folders'] ?? false);
    }
}
