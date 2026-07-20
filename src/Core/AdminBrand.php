<?php
declare(strict_types=1);

namespace Agenduy\Core;

/**
 * Identidad Agendarte UY en paneles admin de comercios (tenant dashboard).
 * Usa output buffering para aplicar marca también en tenants ya desplegados.
 */
final class AdminBrand
{
    private const THEME_COLOR = '#7c3aed';
    private const ACCENT = '#7c3aed';
    private const ACCENT_DARK = '#6d28d9';

    private static bool $bufferStarted = false;

    public static function cssUrl(): string
    {
        return url('src/css/admin-brand.css');
    }

    public static function iconUrl(): string
    {
        return url('src/media/logo/logo-icon.png');
    }

    public static function horizontalLogoUrl(): string
    {
        return url('src/media/logo/logo-horizontal.png');
    }

    public static function faviconUrl(): string
    {
        return url('src/img/favicon/favicon.png');
    }

    public static function homeUrl(): string
    {
        return url('');
    }

    public static function injectDashboardBranding(): void
    {
        if (PHP_SAPI === 'cli' || self::$bufferStarted) {
            return;
        }

        $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
        $isTenantDashboard = (bool)preg_match('#/private/dashboard/(admin|empleado)/index\.php$#', $script);
        $isCommercePanel = (bool)preg_match('#/admin/commerce[_a-z]*\.php$#', $script);
        if (!$isTenantDashboard && !$isCommercePanel) {
            return;
        }

        self::$bufferStarted = true;
        ob_start(static function (string $html) use ($isTenantDashboard): string {
            return $isTenantDashboard
                ? self::transformDashboardHtml($html)
                : self::transformCommercePanelHtml($html);
        });
    }

    public static function transformDashboardHtml(string $html): string
    {
        if ($html === '') {
            return $html;
        }

        if (stripos($html, 'admin-brand.css') === false) {
            $link = '<link rel="stylesheet" href="' . htmlspecialchars(self::cssUrl(), ENT_QUOTES, 'UTF-8') . '">' . "\n";
            $html = preg_replace('/<\/head>/i', $link . '</head>', $html, 1) ?? $html;
        }

        $html = preg_replace(
            '/<meta name="theme-color" content="[^"]*">/i',
            '<meta name="theme-color" content="' . self::THEME_COLOR . '">',
            $html,
            1
        ) ?? $html;

        $favicon = htmlspecialchars(self::faviconUrl(), ENT_QUOTES, 'UTF-8');
        $icon = htmlspecialchars(self::iconUrl(), ENT_QUOTES, 'UTF-8');
        $html = preg_replace(
            '#<link rel="icon"[^>]*>#i',
            '<link rel="icon" type="image/png" sizes="32x32" href="' . $favicon . '">',
            $html,
            1
        ) ?? $html;
        $html = preg_replace(
            '#<link rel="apple-touch-icon"[^>]*>#i',
            '<link rel="apple-touch-icon" href="' . $icon . '">',
            $html,
            1
        ) ?? $html;

        $brandInner = self::sidebarBrandInnerHtml();
        $html = preg_replace(
            '#<div class="admin-brand">\s*<span>Panel Administrativo</span>\s*<small class="muted">#',
            '<div class="admin-brand">' . $brandInner . '<small class="muted admin-brand__tenant">',
            $html,
            1
        ) ?? $html;

        return $html;
    }

    public static function transformCommercePanelHtml(string $html): string
    {
        if ($html === '') {
            return $html;
        }

        if (stripos($html, 'admin-brand.css') === false) {
            $link = '<link rel="stylesheet" href="' . htmlspecialchars(self::cssUrl(), ENT_QUOTES, 'UTF-8') . '">' . "\n";
            $html = preg_replace('/<link rel="stylesheet" href="assets\/css\/admin\.css">/i', '$0' . "\n" . $link, $html, 1) ?? $html;
        }

        $icon = htmlspecialchars(self::iconUrl(), ENT_QUOTES, 'UTF-8');
        $brandLink = '<a href="commerce_dashboard.php">'
            . '<img src="' . $icon . '" alt="" class="topbar__brand-logo" width="30" height="30">'
            . '<span class="topbar__brand-text"><strong>Agendarte</strong> <span class="brand-uy">UY</span></span>'
            . '</a>';

        $html = preg_replace(
            '#<div class="topbar__brand">\s*<a href="commerce_dashboard\.php"><strong>[^<]*</strong></a>\s*</div>#',
            '<div class="topbar__brand">' . $brandLink . '</div>',
            $html,
            1
        ) ?? $html;

        return $html;
    }

    public static function platformBrandHeaderHtml(string $subtitle = ''): string
    {
        $icon = htmlspecialchars(self::iconUrl(), ENT_QUOTES, 'UTF-8');
        $sub = $subtitle !== '' ? '<small style="color:var(--muted)">' . htmlspecialchars($subtitle, ENT_QUOTES, 'UTF-8') . '</small>' : '';

        return '<div class="platform-brand-header">'
            . '<img src="' . $icon . '" alt="">'
            . '<div><p class="platform-brand-header__title">Agendarte <span class="brand-uy">UY</span></p>' . $sub . '</div>'
            . '</div>';
    }

    public static function sidebarBrandInnerHtml(): string
    {
        $home = htmlspecialchars(self::homeUrl(), ENT_QUOTES, 'UTF-8');
        $icon = htmlspecialchars(self::iconUrl(), ENT_QUOTES, 'UTF-8');

        return '<a class="admin-brand__platform" href="' . $home . '">'
            . '<img src="' . $icon . '" alt="" class="admin-brand__icon" width="36" height="36" decoding="async">'
            . '<span class="admin-brand__name">Agendarte <span class="brand-uy">UY</span></span>'
            . '</a>';
    }

    /** @return array<string,mixed> */
    public static function manifestData(string $startUrl): array
    {
        return [
            'name'             => 'Agendarte UY · Panel',
            'short_name'       => 'Agendarte',
            'description'      => 'Panel para gestionar reservas, clientes y servicios.',
            'start_url'        => $startUrl,
            'display'          => 'standalone',
            'background_color' => '#0f1115',
            'theme_color'      => self::THEME_COLOR,
            'icons'            => [
                [
                    'src'     => self::iconUrl(),
                    'sizes'   => '192x192',
                    'type'    => 'image/png',
                    'purpose' => 'any maskable',
                ],
                [
                    'src'     => self::iconUrl(),
                    'sizes'   => '512x512',
                    'type'    => 'image/png',
                    'purpose' => 'any maskable',
                ],
            ],
        ];
    }

    public static function cssVariablesBlock(): string
    {
        return '--accent:' . self::ACCENT . ';--accent-600:' . self::ACCENT_DARK . ';--primary:' . self::ACCENT . ';';
    }
}
