<?php
declare(strict_types=1);

namespace Agenduy\Core;

/**
 * Resuelve URLs del panel de negocio (legacy tenant vs panel central compartido).
 */
final class CommercePanel
{
    public const CENTRAL_TEMPLATE_PATH = 'template/private/dashboard/admin/index.php';

    public static function hasLegacyPanel(string $slug): bool
    {
        $slug = trim($slug, '/');
        if ($slug === '' || !preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug)) {
            return false;
        }
        $root = realpath(dirname(__DIR__, 2));
        if ($root === false) {
            return false;
        }
        $path = $root . DIRECTORY_SEPARATOR . $slug
            . DIRECTORY_SEPARATOR . 'private'
            . DIRECTORY_SEPARATOR . 'dashboard'
            . DIRECTORY_SEPARATOR . 'admin'
            . DIRECTORY_SEPARATOR . 'index.php';
        return is_file($path);
    }

    public static function legacyUrl(string $slug): string
    {
        return \url($slug . '/private/dashboard/admin/index.php');
    }

    public static function centralUrl(?string $slug = null): string
    {
        if ($slug !== null && $slug !== '') {
            return \url('admin/commerce_panel.php?slug=' . rawurlencode($slug));
        }
        return \url('admin/commerce_panel.php');
    }

    /**
     * @param array<string,mixed>|null $commerce
     */
    public static function urlForCommerce(?array $commerce): ?string
    {
        if ($commerce === null) {
            return null;
        }
        $slug = trim((string)($commerce['slug'] ?? ''));
        if ($slug === '') {
            return null;
        }
        if (self::hasLegacyPanel($slug)) {
            return self::legacyUrl($slug);
        }
        return self::centralUrl($slug);
    }

    public static function urlForSlug(string $slug): string
    {
        return self::hasLegacyPanel($slug) ? self::legacyUrl($slug) : self::centralUrl($slug);
    }

    /** @var list<string> */
    public const DASHBOARD_SECTIONS = [
        'resumen', 'reservas', 'clientes', 'funcionarios', 'servicios', 'productos', 'config',
    ];

    public static function normalizeDashboardSection(string $section): string
    {
        $section = strtolower(trim(ltrim($section, '#')));
        return in_array($section, self::DASHBOARD_SECTIONS, true) ? $section : 'resumen';
    }

    /**
     * @param array<string, scalar|null> $query
     */
    public static function appendQuery(string $url, array $query): string
    {
        foreach ($query as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $sep = str_contains($url, '?') ? '&' : '?';
            $url .= $sep . rawurlencode((string)$key) . '=' . rawurlencode((string)$value);
        }
        return $url;
    }

    /**
     * URL de ingreso al panel admin del comercio (legacy o central) con sección SPA.
     *
     * @param array<string, scalar|null> $query
     */
    public static function dashboardUrlForSlug(string $slug, string $section = 'resumen', array $query = []): string
    {
        $slug = trim($slug, '/');
        $section = self::normalizeDashboardSection($section);

        if (self::hasLegacyPanel($slug)) {
            $url = self::legacyUrl($slug);
            if ($query !== []) {
                $url = self::appendQuery($url, $query);
            }
            return $url . '#' . $section;
        }

        $params = array_merge(['slug' => $slug, 'section' => $section], $query);
        return self::appendQuery(self::centralUrl($slug), $params);
    }

    /**
     * URL directa al template compartido (ya con sesión/bootstrap previo).
     */
    public static function centralDashboardUrl(string $section = 'resumen', array $query = []): string
    {
        $section = self::normalizeDashboardSection($section);
        $url = url(self::CENTRAL_TEMPLATE_PATH);
        if ($query !== []) {
            $url = self::appendQuery($url, $query);
        }
        return $url . '#' . $section;
    }

    public static function localDatabasePath(int $idCommerce): string
    {
        $dir = CommerceStorage::baseDir($idCommerce);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('No se pudo preparar el almacenamiento del comercio.');
        }
        return $dir . DIRECTORY_SEPARATOR . 'database.php';
    }

    public static function localDatabaseExists(int $idCommerce): bool
    {
        return is_file(self::localDatabasePath($idCommerce));
    }

    public static function activateCentralSession(string $slug): void
    {
        Auth::start();
        $_SESSION['agenduy_central_panel_slug'] = trim($slug, '/');
    }

    public static function centralSessionSlug(): string
    {
        Auth::start();
        return trim((string)($_SESSION['agenduy_central_panel_slug'] ?? ''), '/');
    }

    public static function clearCentralSession(): void
    {
        Auth::start();
        unset($_SESSION['agenduy_central_panel_slug']);
    }

    public static function defineLocalDatabasePath(int $idCommerce): void
    {
        if (!defined('AGENDUY_LOCAL_DB_PATH')) {
            define('AGENDUY_LOCAL_DB_PATH', self::localDatabasePath($idCommerce));
        }
    }

    /**
     * Prepara database.php central si falta (evita pantalla en blanco en AutoloadDB).
     */
    public static function ensureLocalDatabase(int $idCommerce, string $slug): void
    {
        if ($idCommerce <= 0 || $slug === '') {
            return;
        }
        self::defineLocalDatabasePath($idCommerce);
        if (self::localDatabaseExists($idCommerce)) {
            return;
        }

        $db = Database::getInstance();
        $commerce = $db->fetchOne('SELECT * FROM commerces WHERE id_commerce = :id', [':id' => $idCommerce]);
        if (!$commerce) {
            return;
        }

        $owner = $db->fetchOne(
            'SELECT * FROM users WHERE id_commerce = :c AND role = :r ORDER BY id_user ASC LIMIT 1',
            [':c' => $idCommerce, ':r' => Auth::ROLE_LOCAL]
        );
        $services = $db->fetchAll(
            'SELECT nombre, duracion_min AS duracion, precio FROM services WHERE id_commerce = :c ORDER BY id_service ASC',
            [':c' => $idCommerce]
        );
        $schedule = CommerceSettings::get(
            $idCommerce,
            'horarios',
            CommerceSettings::defaultsForSection('horarios')
        );

        CentralCommerceData::provision(
            $idCommerce,
            (int)($owner['id_user'] ?? 0),
            [
                'nombre' => (string)($owner['nombre'] ?? ''),
                'apellido' => (string)($owner['apellido'] ?? ''),
                'cedula' => (string)($owner['cedula'] ?? ''),
                'email' => (string)($owner['email'] ?? $commerce['email'] ?? ''),
            ],
            [
                'nombre' => (string)$commerce['nombre'],
                'rut' => (string)($commerce['rut_ruc'] ?? ''),
                'pais' => (string)($commerce['pais'] ?? 'UY'),
                'ciudad' => (string)($commerce['ciudad'] ?? ''),
                'calle' => (string)($commerce['calle'] ?? ''),
                'telefono' => (string)($commerce['telefono'] ?? ''),
            ],
            $schedule,
            $services,
            (int)($commerce['id_rubro'] ?? 0)
        );
    }

    /**
     * Bootstrap del panel compartido en /template/private/dashboard/admin/.
     */
    public static function bootstrapCentralAccess(int $idCommerce, string $ownedSlug): void
    {
        $ownedSlug = trim($ownedSlug, '/');
        if ($idCommerce <= 0 || $ownedSlug === '') {
            return;
        }
        self::activateCentralSession($ownedSlug);
        self::ensureLocalDatabase($idCommerce, $ownedSlug);
    }

    public static function isTemplateHost(string $tenantFolderName): bool
    {
        return strtolower(trim($tenantFolderName)) === 'template';
    }
}
