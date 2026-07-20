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

    /**
     * Slugs que no deben tratarse como tenants (plantillas, pruebas, ya eliminados).
     * Extra vía AGENDUY_TENANT_IGNORE=slug1,slug2
     *
     * @return list<string>
     */
    public static function ignoredTenantSlugs(): array
    {
        static $cache = null;
        if (is_array($cache)) {
            return $cache;
        }

        $slugs = ['template_curso', 'terap', 'terapeuta-luck'];
        $env = getenv('AGENDUY_TENANT_IGNORE');
        if ($env !== false && trim($env) !== '') {
            foreach (explode(',', $env) as $part) {
                $part = trim($part);
                if ($part !== '' && preg_match('/^[a-z0-9][a-z0-9-]*$/', $part)) {
                    $slugs[] = $part;
                }
            }
        }

        $cache = array_values(array_unique($slugs));
        return $cache;
    }

    public static function isIgnoredTenantSlug(string $slug): bool
    {
        return in_array(trim($slug), self::ignoredTenantSlugs(), true);
    }
}
