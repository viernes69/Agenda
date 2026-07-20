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
}
