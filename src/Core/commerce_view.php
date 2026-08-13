<?php
/**
 * Agenduy - Commerce View
 *
 * Renderiza la página pública de un comercio. Usado por:
 *  - El index.php raíz como router genérico
 *  - Los index.php de cada comercio (fallback de compatibilidad)
 *
 * La función render() hace exit() al final.
 */

declare(strict_types=1);

use Agenduy\Core\Availability;
use Agenduy\Core\Auth;
use Agenduy\Core\CommercePanel;
use Agenduy\Core\CommercePublic;
use Agenduy\Core\CommerceRegistrar;
use Agenduy\Core\Database;
use Agenduy\Core\CSRF;
use Agenduy\Core\GoogleAuth;
use Agenduy\Core\CommerceSettings;
use Agenduy\Core\CommerceStorage;
use Agenduy\Core\MembershipPlan;
use Agenduy\Core\MercadoPago;

if (!function_exists('agenduy_render_commerce')) {
    /**
     * Renderiza el sitio público de un comercio.
     *
     * @param string|null $slug Si es null, usa current_slug() para detectar.
     */
    function agenduy_render_commerce(?string $slug = null): void
    {
        $db = Database::getInstance();
        $slug = $slug !== null && $slug !== '' ? $slug : (current_slug() ?? basename(getcwd()));
        $slug = trim($slug, '/');

        $commerce = $db->fetchOne(
            'SELECT c.*, r.tipo AS rubro_tipo, r.nombre AS rubro_nombre, m.nombre AS plan_nombre
             FROM commerces c
             LEFT JOIN rubros r ON r.id_rubro = c.id_rubro
             LEFT JOIN memberships m ON m.id_membership = c.id_membership
             WHERE c.slug = :s',
            [':s' => $slug]
        );
        if (!$commerce) {
            http_response_code(404);
            echo '<h1>Comercio no encontrado</h1>';
            return;
        }

        Auth::start();
        $commerceIdEarly = (int)$commerce['id_commerce'];
        $isCommerceOwner = Auth::check()
            && Auth::role() === Auth::ROLE_LOCAL
            && (int)Auth::commerceId() === $commerceIdEarly;
        $ownerDashboardUrl = $isCommerceOwner
            ? CommercePanel::urlForSlug($slug)
            : null;

        $services = $db->fetchAll(
            'SELECT * FROM services WHERE id_commerce = :c AND estado = :a ORDER BY nombre',
            [':c' => $commerce['id_commerce'], ':a' => 'Activo']
        );

        // Catálogo local: carpeta tenant legacy o storage central (sin carpetas).
        $catalog = CommercePublic::loadLocalCatalog((int)$commerce['id_commerce'], $slug);
        $localProducts = $catalog['products'];
        $localServiceImages = $catalog['service_images'];

        foreach ($services as &$svcRow) {
            $centralImg = trim((string)($svcRow['imagen'] ?? ''));
            if ($centralImg !== '') {
                continue;
            }
            $localId = (int)($svcRow['id_local'] ?? 0);
            if ($localId > 0 && isset($localServiceImages[$localId])) {
                $svcRow['imagen'] = $localServiceImages[$localId];
            }
        }
        unset($svcRow);

        // Contadores reales de reservas por servicio (SQLite central = fuente de la agenda pública).
        // Incluye pending/confirmed/done; excluye cancelled y no_show.
        $serviceBookingCounts = [];
        $topBookedServiceId = 0;
        $topBookedCount = 0;
        $bookingBadgeThreshold = 15;
        if (!empty($services)) {
            $countRows = $db->fetchAll(
                "SELECT id_service, COUNT(*) AS n
                 FROM appointments
                 WHERE id_commerce = :c
                   AND id_service IS NOT NULL
                   AND status IN ('pending', 'confirmed', 'in_progress', 'done')
                 GROUP BY id_service",
                [':c' => (int)$commerce['id_commerce']]
            );
            foreach ($countRows as $countRow) {
                $sid = (int)($countRow['id_service'] ?? 0);
                $n = (int)($countRow['n'] ?? 0);
                if ($sid <= 0 || $n <= 0) {
                    continue;
                }
                $serviceBookingCounts[$sid] = $n;
                if ($n > $topBookedCount || ($n === $topBookedCount && ($topBookedServiceId === 0 || $sid < $topBookedServiceId))) {
                    $topBookedCount = $n;
                    $topBookedServiceId = $sid;
                }
            }
            if ($topBookedCount < $bookingBadgeThreshold) {
                $topBookedServiceId = 0;
            }
        }

        $horarios = [
            'lunes' => 'Lunes', 'martes' => 'Martes', 'miercoles' => 'Miércoles',
            'jueves' => 'Jueves', 'viernes' => 'Viernes',
            'sabado' => 'Sábado', 'domingo' => 'Domingo',
        ];

        $legacyInfo = [];
        $oldDbPath = dirname($_SERVER['SCRIPT_FILENAME'] ?? __DIR__) . '/src/db/database.php';
        if (is_file($oldDbPath)) {
            $old = @include $oldDbPath;
            if (is_array($old) && isset($old['info_barberia']) && is_array($old['info_barberia'])) {
                $legacyInfo = $old['info_barberia'];
            }
        }

        $commerceId = (int)$commerce['id_commerce'];
        $scheduleRaw = CommerceSettings::get(
            $commerceId,
            'horarios',
            $legacyInfo['horarios'] ?? CommerceSettings::defaultsForSection('horarios')
        );
        $seo = CommerceSettings::get($commerceId, 'seo', $legacyInfo['seo'] ?? CommerceSettings::defaultsForSection('seo'));
        $redes = CommerceSettings::get($commerceId, 'redes', $legacyInfo['redes'] ?? CommerceSettings::defaultsForSection('redes'));
        $moneda = CommerceSettings::get($commerceId, 'moneda', $legacyInfo['moneda'] ?? CommerceSettings::defaultsForSection('moneda'));
        $legal = CommerceSettings::get($commerceId, 'legal', $legacyInfo['legales'] ?? CommerceSettings::defaultsForSection('legal'));
        $funciones = CommerceSettings::get($commerceId, 'funciones', $legacyInfo['features'] ?? CommerceSettings::defaultsForSection('funciones'));
        $tema = CommerceSettings::get($commerceId, 'tema', $legacyInfo['temas'] ?? CommerceSettings::defaultsForSection('tema'));
        $reservasCfg = CommerceSettings::get($commerceId, 'reservas', $legacyInfo['reservas'] ?? CommerceSettings::defaultsForSection('reservas'));
        $carritoCfg = CommerceSettings::get($commerceId, 'carrito', $legacyInfo['carrito'] ?? CommerceSettings::defaultsForSection('carrito'));
        $defaultTheme = (($tema['publico'] ?? 'claro') === 'oscuro') ? 'dark' : 'light';
        $rubroType = trim((string)($commerce['rubro_tipo'] ?? ($legacyInfo['rubro'] ?? '')));
        $rubroLabel = trim((string)($commerce['rubro_nombre'] ?? ($legacyInfo['rubro_nombre'] ?? '')));
        $hasConfiguredBusinessType = isset($funciones['tipo_comercio']) || isset($funciones['tipo']);
        $businessType = CommerceRegistrar::businessTypeFromFeatures($funciones, $rubroType, $rubroLabel);
        if (!$hasConfiguredBusinessType) {
            $funciones = array_replace($funciones, CommerceRegistrar::featuresForBusinessType($businessType));
        }
        $isStoreMode = $businessType === 'tienda';
        $showServices = !$isStoreMode && !empty($funciones['servicios']);
        $showBooking = $showServices && !empty($funciones['reservas']);
        $showProducts = !empty($funciones['productos']) && $localProducts !== [];
        $showCatalogSection = $showProducts || $isStoreMode;
        $cartEnabled = $showProducts && !empty($carritoCfg['enabled']);
        $cartWhatsAppEnabled = $cartEnabled && !empty($carritoCfg['whatsapp_enabled']);
        $cartMpSettingEnabled = $cartEnabled && !empty($carritoCfg['mercado_pago_enabled']);
        $cartInstructions = trim((string)($carritoCfg['instructions'] ?? ''));
        if ($cartInstructions === '') {
            $pickupEnabled = !array_key_exists('pickup_enabled', $carritoCfg) || !empty($carritoCfg['pickup_enabled']);
            $deliveryEnabled = !array_key_exists('delivery_enabled', $carritoCfg) || !empty($carritoCfg['delivery_enabled']);
            if ($pickupEnabled && !$deliveryEnabled) {
                $cartInstructions = 'Coordinamos el retiro por este medio. Gracias!';
            } elseif (!$pickupEnabled && $deliveryEnabled) {
                $cartInstructions = 'Coordinamos la entrega por este medio. Gracias!';
            } else {
                $cartInstructions = 'Coordinamos entrega o retiro por este medio. Gracias!';
            }
        }
        $hasConfiguredSchedule = false;
        foreach (array_keys($horarios) as $dayKey) {
            if (Availability::isWeekdayConfiguredOpen($scheduleRaw, $dayKey)) {
                $hasConfiguredSchedule = true;
                break;
            }
        }
        $scheduleSummary = CommercePublic::scheduleSummary($scheduleRaw);
        $aboutHighlights = CommercePublic::highlights((int)($commerce['id_rubro'] ?? 0), $businessType);
        $coverImageRel = CommercePublic::rubroCoverImage((int)($commerce['id_rubro'] ?? 0), (string)($commerce['rubro_nombre'] ?? ''));
        $coverImageUrl = url($coverImageRel);
        $cssPath = dirname(__DIR__, 2) . '/assets/css/commerce-public.css';
        $cssVer = is_file($cssPath) ? (string)filemtime($cssPath) : (string)time();

        $titulo = (string)($commerce['nombre'] ?? 'Agenduy');
        $slogan = (string)($commerce['slogan'] ?? '');
        $descripcion = (string)($commerce['descripcion'] ?? '');
        $aboutDescription = $descripcion !== ''
            ? $descripcion
            : ($isStoreMode
                ? 'Somos una tienda pensada para que encuentres productos, consultes disponibilidad y coordines tu pedido de forma simple.'
                : 'Somos un equipo con agenda organizada para que puedas consultar servicios, elegir disponibilidad y reservar sin vueltas.');
        $ciudad = (string)($commerce['ciudad'] ?? '');
        $calle = (string)($commerce['calle'] ?? '');
        $telefono = (string)($commerce['telefono'] ?? '');
        $whatsapp = trim((string)($redes['whatsapp'] ?? ''))
            ?: trim((string)($legacyInfo['contacto']['whatsapp'] ?? ''))
            ?: trim((string)($commerce['whatsapp'] ?? ''));
        $whatsappDigits = preg_replace('/\D+/', '', $whatsapp) ?: '';
        $redesVisible = !array_key_exists('visible', $redes) || !empty($redes['visible']);
        $socialDefinitions = [
            'instagram' => ['label' => 'Instagram', 'icon' => 'bxl-instagram', 'base' => 'https://www.instagram.com/'],
            'facebook' => ['label' => 'Facebook', 'icon' => 'bxl-facebook', 'base' => 'https://www.facebook.com/'],
            'tiktok' => ['label' => 'TikTok', 'icon' => 'bxl-tiktok', 'base' => 'https://www.tiktok.com/@'],
            'twitter' => ['label' => 'Twitter / X', 'icon' => 'bxl-twitter', 'base' => 'https://twitter.com/'],
            'youtube' => ['label' => 'YouTube', 'icon' => 'bxl-youtube', 'base' => 'https://www.youtube.com/'],
        ];
        $normalizeSocialUrl = static function (string $value, string $base, string $network): string {
            $value = trim($value);
            if ($value === '') {
                return '';
            }
            if (preg_match('#^https?://#i', $value) === 1) {
                return $value;
            }
            $value = preg_replace('#^(www\.)?(instagram|facebook|tiktok|twitter|x|youtube)\.com/#i', '', $value) ?? $value;
            $value = ltrim(trim($value), '@/');
            if ($value === '') {
                return '';
            }
            if ($network === 'tiktok') {
                return rtrim($base, '@') . '@' . $value;
            }
            return rtrim($base, '/') . '/' . $value;
        };
        $socialLinks = [];
        if ($redesVisible) {
            foreach ($socialDefinitions as $network => $socialDef) {
                $urlSocial = $normalizeSocialUrl((string)($redes[$network] ?? ''), (string)$socialDef['base'], $network);
                if ($urlSocial === '') {
                    continue;
                }
                $path = trim((string)(parse_url($urlSocial, PHP_URL_PATH) ?? ''), '/');
                $display = $path !== '' ? '@' . ltrim($path, '@') : (string)$socialDef['label'];
                $socialLinks[$network] = [
                    'label' => (string)$socialDef['label'],
                    'icon' => (string)$socialDef['icon'],
                    'url' => $urlSocial,
                    'display' => $display,
                ];
            }
        }
        $email = (string)($commerce['email'] ?? '');
        $logo = (string)($commerce['logo'] ?? '');
        $currencySymbol = (string)($moneda['simbolo'] ?? '$');
        $currencyDecimals = max(0, min(4, (int)($moneda['decimales'] ?? 0)));
        $seoTitle = trim((string)($seo['title'] ?? '')) ?: $titulo . ' · Reservas online';
        $seoDescription = trim((string)($seo['description'] ?? '')) ?: mb_substr($descripcion, 0, 160, 'UTF-8');

        $productsForJs = [];
        if ($showProducts) {
            foreach ($localProducts as $pRow) {
                $productsForJs[] = [
                    'id' => (string)($pRow['ID_Product'] ?? ''),
                    'name' => trim((string)($pRow['Nombre'] ?? 'Producto')),
                    'tipo' => trim((string)($pRow['Tipo'] ?? '')),
                    'price' => is_numeric($pRow['Precio'] ?? null) ? (float)$pRow['Precio'] : 0.0,
                    'desc' => trim((string)($pRow['Descripcion'] ?? '')),
                ];
            }
        }

        $initial = mb_substr($titulo, 0, 1, 'UTF-8');

        $commerceId = (int)($commerce['id_commerce'] ?? 0);
        $logoUrl = CommerceStorage::publicUrl($commerceId, $slug, $logo);
        $hasLogo = $logoUrl !== '';

        $tenantAssetUrl = static function (string $relative) use ($commerceId, $slug): string {
            return CommerceStorage::publicUrl($commerceId, $slug, $relative);
        };

        $csrf = CSRF::generate('public_booking');
        $googleClientId = GoogleAuth::isEnabled() ? GoogleAuth::clientId() : '';
        $clientGoogleAuthApi = url('src/API/client_google_auth.php');
        $apiBase = url('admin/api/appointments.php');
        $cancelAppointmentApi = url('admin/api/cancel_appointment.php');
        $cartOrderApi = url('admin/api/cart_order.php');
        $cartMercadoPagoApi = url('admin/api/cart_mercadopago.php');
        $commercePlan = MembershipPlan::forCommerceId($commerceId);
        $storePlan = $isStoreMode ? $commercePlan : null;
        $storeMpConfig = $isStoreMode ? MercadoPago::commerceConfig($commerceId, $slug) : [];
        $storeMpCheckoutEnabled = $showProducts
            && $cartMpSettingEnabled
            && $isStoreMode
            && MercadoPago::isStoreCheckoutAllowed($storePlan)
            && !empty($storeMpConfig['enabled'])
            && trim((string)($storeMpConfig['access_token'] ?? '')) !== '';
        $bookingMpConfig = $showBooking ? MercadoPago::commerceConfig($commerceId, $slug) : [];
        $bookingMpCheckoutEnabled = $showBooking
            && !empty($reservasCfg['mercado_pago_enabled'])
            && MercadoPago::isReservationCheckoutAllowed($commercePlan)
            && !empty($bookingMpConfig['enabled'])
            && trim((string)($bookingMpConfig['access_token'] ?? '')) !== '';
        $bookingMpRequired = $bookingMpCheckoutEnabled && !empty($reservasCfg['mercado_pago_required']);
        $availabilityApi = url('admin/api/availability.php');
        $maxDiasAdelante = max(0, (int)($reservasCfg['max_dias_adelante'] ?? 60));
        $bookingMinDate = new \DateTimeImmutable('today');
        $bookingMaxDate = $bookingMinDate->modify(sprintf('+%d days', $maxDiasAdelante));
        $bookingCalendar = Availability::calendarForRange($scheduleRaw, $bookingMinDate, $bookingMaxDate);

        require_once dirname(__DIR__) . '/components/dlocal/plans.php';
        $dlocalPlansHtml = '';
        $hasDlocalPlans = false;
        try {
            $dlocalPlansHtml = AgenduyDlocalPlans::render($slug);
            $hasDlocalPlans = trim($dlocalPlansHtml) !== '';
        } catch (\Throwable $e) {
            error_log('[commerce_view.dlocal] ' . $e->getMessage());
        }
        ?>
        <!doctype html>
        <html lang="es">
        <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= htmlspecialchars($seoTitle, ENT_QUOTES, 'UTF-8') ?></title>
        <meta name="description" content="<?= htmlspecialchars($seoDescription, ENT_QUOTES, 'UTF-8') ?>">
        <meta name="robots" content="<?= htmlspecialchars((string)($seo['robots'] ?? 'index,follow'), ENT_QUOTES, 'UTF-8') ?>">
        <?php if (!empty($seo['keywords'])): ?>
        <meta name="keywords" content="<?= htmlspecialchars(implode(', ', (array)$seo['keywords']), ENT_QUOTES, 'UTF-8') ?>">
        <?php endif; ?>
        <?php if (!empty($seo['canonical'])): ?>
        <link rel="canonical" href="<?= htmlspecialchars((string)$seo['canonical'], ENT_QUOTES, 'UTF-8') ?>">
        <?php endif; ?>
        <?php if (!empty($seo['og_image'])): ?>
        <meta property="og:image" content="<?= htmlspecialchars(url((string)$seo['og_image']), ENT_QUOTES, 'UTF-8') ?>">
        <?php endif; ?>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css">
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.css">
        <link rel="stylesheet" href="<?= htmlspecialchars(url('assets/css/commerce-public.css?v=' . $cssVer), ENT_QUOTES, 'UTF-8') ?>">
        <?php if ($hasDlocalPlans): ?>
        <link rel="stylesheet" href="<?= htmlspecialchars(url('public/assets/css/dlocal-plans.css'), ENT_QUOTES, 'UTF-8') ?>">
        <?php endif; ?>
        <link rel="icon" type="image/png" href="<?= htmlspecialchars(url('src/img/favicon/favicon.png'), ENT_QUOTES, 'UTF-8') ?>">
        <link rel="apple-touch-icon" href="<?= htmlspecialchars(url('src/img/favicon/favicon.png'), ENT_QUOTES, 'UTF-8') ?>">
        <link rel="manifest" href="<?= htmlspecialchars(url('template/manifest.webmanifest'), ENT_QUOTES, 'UTF-8') ?>">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="default">
        <meta name="csrf-token" content="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
        </head>
        <body class="site-public" data-theme="<?= htmlspecialchars($defaultTheme, ENT_QUOTES, 'UTF-8') ?>">
        <a href="#main" class="skip-link">Saltar al contenido</a>

        <header class="topbar">
            <div class="topbar__inner">
                <a href="<?= htmlspecialchars(url($slug), ENT_QUOTES, 'UTF-8') ?>" class="brand">
                    <?php if ($hasLogo): ?>
                        <span class="brand__logo"><img src="<?= htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8') ?>" alt=""></span>
                    <?php else: ?>
                        <span class="brand__logo"><?= htmlspecialchars(mb_strtoupper($initial, 'UTF-8'), ENT_QUOTES, 'UTF-8') ?></span>
                    <?php endif; ?>
                    <span>
                        <?= htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8') ?>
                        <?php if ($slogan !== ''): ?>
                            <div class="brand__sub"><?= htmlspecialchars(mb_substr($slogan, 0, 40, 'UTF-8'), ENT_QUOTES, 'UTF-8') ?></div>
                        <?php endif; ?>
                    </span>
                </a>
                <nav class="nav" aria-label="Navegación">
                    <?php if ($showServices): ?>
                    <a href="#servicios">Servicios</a>
                    <?php endif; ?>
                    <?php if ($hasDlocalPlans): ?>
                    <a href="#suscripciones">Suscripciones</a>
                    <?php endif; ?>
                    <?php if ($showCatalogSection): ?>
                    <a href="#productos"><?= $isStoreMode ? 'Catalogo' : 'Productos' ?></a>
                    <?php endif; ?>
                    <a href="#nosotros">Nosotros</a>
                    <?php if ($hasConfiguredSchedule): ?>
                    <a href="#horarios">Horarios</a>
                    <?php endif; ?>
                    <a href="#contacto">Contacto</a>
                </nav>
                <div style="display:flex; gap:.5rem; align-items:center">
                    <?php if ($showProducts): ?>
                    <button class="cart-btn" type="button" id="cart-open" aria-label="Ver carrito" hidden>
                        <i class="bx bx-cart" aria-hidden="true"></i>
                        <span class="cart-btn__count" id="cart-count">0</span>
                    </button>
                    <?php endif; ?>
                    <button class="install-app-btn" type="button" id="install-app" aria-label="Instalar app" hidden>
                        <i class="bx bx-download" aria-hidden="true"></i>
                    </button>
                    <button class="theme-toggle" type="button" id="theme-toggle" aria-label="Cambiar tema">
                        <i class="bx bx-moon" id="theme-icon"></i>
                    </button>
                    <?php if ($isCommerceOwner && $ownerDashboardUrl): ?>
                    <a class="client-auth-btn client-auth-btn--profile" href="<?= htmlspecialchars($ownerDashboardUrl, ENT_QUOTES, 'UTF-8') ?>">
                        <i class="bx bx-grid-alt"></i> Panel
                    </a>
                    <?php endif; ?>
                    <?php if ($showBooking): ?>
                    <a href="#servicios" class="btn btn--primary">Reservar</a>
                    <?php elseif ($showCatalogSection): ?>
                    <a href="#productos" class="btn btn--primary">Catalogo</a>
                    <?php endif; ?>
                    <button class="menu-btn" type="button" id="menu-btn" aria-label="Menú">
                        <i class="bx bx-menu"></i>
                    </button>
                </div>
            </div>
            <div class="mobile-menu" id="mobile-menu">
                <?php if ($showServices): ?>
                <a href="#servicios">Servicios</a>
                <?php endif; ?>
                <?php if ($hasDlocalPlans): ?>
                <a href="#suscripciones">Suscripciones</a>
                <?php endif; ?>
                <?php if ($showCatalogSection): ?>
                <a href="#productos"><?= $isStoreMode ? 'Catalogo' : 'Productos' ?></a>
                <?php endif; ?>
                <a href="#nosotros">Nosotros</a>
                <?php if ($hasConfiguredSchedule): ?>
                <a href="#horarios">Horarios</a>
                <?php endif; ?>
                <a href="#contacto">Contacto</a>
                <?php if ($showBooking): ?>
                <a href="#servicios">Reservar ahora</a>
                <?php endif; ?>
            </div>
        </header>

        <main id="main">
        <section class="hero">
            <div class="wrap hero__inner">
                <div>
                    <?php if ($isStoreMode): ?>
                    <span class="hero__eyebrow"><i class="bx bx-store"></i> <?= $rubroLabel !== '' ? htmlspecialchars($rubroLabel, ENT_QUOTES, 'UTF-8') : 'Catalogo online' ?></span>
                    <h1>Explora el catalogo de <?= htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8') ?></h1>
                    <p class="lead"><?= $slogan !== '' ? htmlspecialchars($slogan, ENT_QUOTES, 'UTF-8') : 'Conoce productos, arma tu pedido y coordina entrega o retiro por WhatsApp.' ?></p>
                    <div class="hero__actions">
                        <a href="#productos" class="btn btn--primary btn--lg"><i class="bx bx-package"></i> Ver catalogo</a>
                        <?php if ($whatsappDigits !== ''): ?>
                        <a target="_blank" rel="noopener" href="https://wa.me/<?= htmlspecialchars($whatsappDigits, ENT_QUOTES, 'UTF-8') ?>" class="btn btn--ghost btn--lg">Consultar por WhatsApp</a>
                        <?php endif; ?>
                    </div>
                    <div class="hero__stats">
                        <div><div class="stat__num">+<?= count($localProducts) ?></div><div class="stat__lbl">Productos</div></div>
                        <div><div class="stat__num">WA</div><div class="stat__lbl">Pedidos directos</div></div>
                        <div><div class="stat__num">24/7</div><div class="stat__lbl">Catalogo visible</div></div>
                    </div>
                    <?php else: ?>
                    <span class="hero__eyebrow"><i class="bx bx-calendar-check"></i> <?= $rubroLabel !== '' ? htmlspecialchars($rubroLabel, ENT_QUOTES, 'UTF-8') : 'Reservas online 24/7' ?></span>
                    <h1>Reservá tu turno en <?= htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8') ?> en segundos</h1>
                    <p class="lead"><?= $slogan !== '' ? htmlspecialchars($slogan, ENT_QUOTES, 'UTF-8') : 'Elegí tu servicio, día y horario. Sin llamadas, sin esperas.' ?></p>
                    <div class="hero__actions">
                        <a href="#servicios" class="btn btn--primary btn--lg"><i class="bx bx-calendar-plus"></i> Reservar ahora</a>
                        <a href="#servicios" class="btn btn--ghost btn--lg">Ver servicios</a>
                    </div>
                    <div class="hero__stats">
                        <div><div class="stat__num">24/7</div><div class="stat__lbl">Reservas online</div></div>
                        <div><div class="stat__num">+<?= count($services) ?></div><div class="stat__lbl">Servicios</div></div>
                        <div><div class="stat__num">⭐ 4.9</div><div class="stat__lbl">Calificación</div></div>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="hero__visual">
                    <?php if ($hasLogo): ?>
                        <img src="<?= htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="">
                    <?php else: ?>
                        <div class="hero__visual-fallback" aria-hidden="true">
                            <span class="hero__visual-initial"><?= htmlspecialchars(mb_strtoupper($initial, 'UTF-8'), ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                    <?php endif; ?>
                    <div class="hero__badge"><i class="bx bx-check-circle" style="color: var(--success)"></i> <?= $isStoreMode ? ($showProducts ? 'Catalogo disponible' : 'Catalogo en preparacion') : 'Confirmacion inmediata' ?></div>
                </div>
            </div>
        </section>

        <section class="steps alt" aria-label="Cómo reservar">
            <div class="wrap">
                <?php if ($isStoreMode): ?>
                <span class="eyebrow">Simple y directo</span>
                <h2 class="section-title">Compra en 3 pasos</h2>
                <p class="section-sub">Explora el catalogo, arma tu pedido y coordina los detalles por WhatsApp.</p>
                <div class="steps-grid">
                    <article class="step-card">
                        <span class="step-card__num">1</span>
                        <h3>Elegi productos</h3>
                        <p>Revisa precios, tipos y detalles del catalogo disponible.</p>
                    </article>
                    <article class="step-card">
                        <span class="step-card__num">2</span>
                        <h3>Arma tu pedido</h3>
                        <p>Agrega al carrito lo que quieras consultar o comprar.</p>
                    </article>
                    <article class="step-card">
                        <span class="step-card__num">3</span>
                        <h3>Coordina entrega</h3>
                        <p>Envia el pedido por WhatsApp y acuerda retiro, envio o pago.</p>
                    </article>
                </div>
                <?php else: ?>
                <span class="eyebrow">Simple y rápido</span>
                <h2 class="section-title">Reservá en 3 pasos</h2>
                <p class="section-sub">Sin llamadas ni mensajes de ida y vuelta. Tu turno queda confirmado al instante.</p>
                <div class="steps-grid">
                    <article class="step-card">
                        <span class="step-card__num">1</span>
                        <h3>Elegí el servicio</h3>
                        <p>Explorá nuestra carta y seleccioná lo que necesitás.</p>
                    </article>
                    <article class="step-card">
                        <span class="step-card__num">2</span>
                        <h3>Seleccioná día y hora</h3>
                        <p>Disponibilidad en tiempo real según nuestros horarios.</p>
                    </article>
                    <article class="step-card">
                        <span class="step-card__num">3</span>
                        <h3>Confirmá tu reserva</h3>
                        <p>Recibís confirmación por email. También avisamos al negocio.</p>
                    </article>
                </div>
                <?php endif; ?>
            </div>
        </section>

        <?php if ($showServices): ?>
        <section id="servicios" class="alt">
            <div class="wrap">
                <span class="eyebrow">Servicios</span>
                <h2 class="section-title">Lo que ofrecemos</h2>
                <p class="section-sub">Servicios profesionales con la mejor calidad. Reservá el que más te guste.</p>
                <?php if (empty($services)): ?>
                    <p class="section-sub">Próximamente más servicios.</p>
                <?php else: ?>
                    <div class="svc-grid">
                        <?php foreach ($services as $s):
                            $svcImgRel = trim((string)($s['imagen'] ?? ''));
                            $svcImgUrl = $svcImgRel !== '' ? $tenantAssetUrl($svcImgRel) : '';
                            $svcId = (int)$s['id_service'];
                            $svcBookings = (int)($serviceBookingCounts[$svcId] ?? 0);
                            $svcBadgeLabel = '';
                            $svcBadgeClass = 'svc-card__badge';
                            if ($svcBookings >= $bookingBadgeThreshold) {
                                if ($topBookedServiceId === $svcId) {
                                    $svcBadgeLabel = 'Más utilizado';
                                    $svcBadgeClass .= ' svc-card__badge--top';
                                } else {
                                    $svcBadgeLabel = '+' . $svcBookings . ' reservas';
                                }
                            }
                        ?>
                        <article class="svc-card" data-svc-id="<?= $svcId ?>" data-svc-name="<?= htmlspecialchars($s['nombre'], ENT_QUOTES, 'UTF-8') ?>" data-svc-duration="<?= (int)$s['duracion_min'] ?>" data-svc-price="<?= (float)$s['precio'] ?>" tabindex="0">
                            <?php if ($svcImgUrl !== ''): ?>
                            <div class="svc-card__media">
                                <img src="<?= htmlspecialchars($svcImgUrl, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars((string)$s['nombre'], ENT_QUOTES, 'UTF-8') ?>" loading="lazy">
                            </div>
                            <?php endif; ?>
                            <div class="svc-card__head">
                                <div class="svc-card__title">
                                    <div class="svc-card__name"><?= htmlspecialchars($s['nombre'], ENT_QUOTES, 'UTF-8') ?></div>
                                    <?php if ($svcBadgeLabel !== ''): ?>
                                    <span class="<?= htmlspecialchars($svcBadgeClass, ENT_QUOTES, 'UTF-8') ?>" title="<?= (int)$svcBookings ?> reservas"><?= htmlspecialchars($svcBadgeLabel, ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="svc-card__price"><?= htmlspecialchars($currencySymbol, ENT_QUOTES, 'UTF-8') ?><?= number_format((float)$s['precio'], $currencyDecimals, ',', '.') ?></div>
                            </div>
                            <div class="svc-card__meta">
                                <span><i class="bx bx-time"></i> <?= (int)$s['duracion_min'] ?> min</span>
                                <?php if (!empty($s['descripcion'])): ?>
                                    <span><i class="bx bx-info-circle"></i> <?= htmlspecialchars(mb_substr((string)$s['descripcion'], 0, 60, 'UTF-8'), ENT_QUOTES, 'UTF-8') ?></span>
                                <?php endif; ?>
                            </div>
                            <button class="btn btn--primary btn--block" type="button" data-book="<?= $svcId ?>">Reservar</button>
                        </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </section>
        <?php endif; ?>

        <?php if ($hasDlocalPlans): ?>
        <section id="suscripciones" class="alt">
            <div class="wrap">
                <?= $dlocalPlansHtml ?>
            </div>
        </section>
        <?php endif; ?>

        <?php if ($showCatalogSection): ?>
        <section id="productos">
            <div class="wrap">
                <span class="eyebrow">Productos</span>
                <h2 class="section-title">Nuestra tienda</h2>
                <p class="section-sub"><?= htmlspecialchars($cartEnabled ? 'Agrega al carrito y coordina tu pedido en minutos.' : 'Explora los productos disponibles y consulta al comercio para comprar.', ENT_QUOTES, 'UTF-8') ?></p>
                <?php if (!$showProducts): ?>
                    <p class="section-sub">El catalogo esta en preparacion. Volve pronto o contacta al comercio para consultar productos.</p>
                    <?php if ($isCommerceOwner && $ownerDashboardUrl): ?>
                    <div style="display:flex; gap:.75rem; flex-wrap:wrap">
                        <a href="<?= htmlspecialchars(CommercePanel::dashboardUrlForSlug($slug, 'productos'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn--primary btn--lg">
                            <i class="bx bx-package"></i> Cargar productos
                        </a>
                    </div>
                    <?php endif; ?>
                <?php else: ?>
                <div class="prod-grid">
                    <?php foreach ($localProducts as $p):
                        $pId = (string)($p['ID_Product'] ?? '');
                        $pName = trim((string)($p['Nombre'] ?? 'Producto'));
                        $pTipo = trim((string)($p['Tipo'] ?? ''));
                        $pDesc = trim((string)($p['Descripcion'] ?? ''));
                        $pImgRel = trim((string)($p['Img_src'] ?? ''));
                        $pImgUrl = $pImgRel !== '' ? $tenantAssetUrl($pImgRel) : '';
                        $pPrice = is_numeric($p['Precio'] ?? null) ? (float)$p['Precio'] : 0;
                        // Gallery: additional images from Img_Gallery (pipe-separated)
                        $allImages = [];
                        if ($pImgUrl !== '') {
                            $allImages[] = $pImgUrl;
                        }
                        $pGalleryRaw = $p['Img_Gallery'] ?? '';
                        if ($pGalleryRaw !== '') {
                            $parts = is_array($pGalleryRaw) ? $pGalleryRaw : explode('|', (string)$pGalleryRaw);
                            foreach ($parts as $g) {
                                $g = trim((string)$g);
                                if ($g !== '') {
                                    $gUrl = $tenantAssetUrl($g);
                                    if ($gUrl !== '') {
                                        $allImages[] = $gUrl;
                                    }
                                }
                            }
                        }
                        $hasMultiple = count($allImages) > 1;
                    ?>
                    <article class="prod-card"
                        data-product-id="<?= htmlspecialchars($pId, ENT_QUOTES, 'UTF-8') ?>"
                        data-product-name="<?= htmlspecialchars($pName, ENT_QUOTES, 'UTF-8') ?>"
                        data-product-price="<?= htmlspecialchars((string)$pPrice, ENT_QUOTES, 'UTF-8') ?>">
                        <div class="prod-card__media">
                            <?php if (!empty($allImages)): ?>
                                <div class="prod-gallery" data-gallery>
                                    <div class="prod-gallery__track" data-track>
                                        <?php foreach ($allImages as $gi => $gUrl): ?>
                                        <div class="prod-gallery__slide<?= $gi === 0 ? ' is-active' : '' ?>" data-slide="<?= $gi ?>">
                                            <img src="<?= htmlspecialchars($gUrl, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($pName . ($gi > 0 ? ' - ' . ($gi + 1) : ''), ENT_QUOTES, 'UTF-8') ?>" loading="lazy">
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php if ($hasMultiple): ?>
                                    <button type="button" class="prod-gallery__arrow prod-gallery__arrow--prev" data-dir="-1" aria-label="Anterior"><i class="bx bx-chevron-left"></i></button>
                                    <button type="button" class="prod-gallery__arrow prod-gallery__arrow--next" data-dir="1" aria-label="Siguiente"><i class="bx bx-chevron-right"></i></button>
                                    <div class="prod-gallery__dots" data-dots>
                                        <?php foreach ($allImages as $di => $_): ?>
                                        <button type="button" class="prod-gallery__dot<?= $di === 0 ? ' is-active' : '' ?>" data-slide="<?= $di ?>" aria-label="Imagen <?= $di + 1 ?>"></button>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <span class="prod-card__placeholder" aria-hidden="true"><i class="bx bx-package"></i></span>
                            <?php endif; ?>
                        </div>
                        <div class="prod-card__body">
                            <?php if ($pTipo !== ''): ?>
                                <span class="prod-card__type"><?= htmlspecialchars($pTipo, ENT_QUOTES, 'UTF-8') ?></span>
                            <?php endif; ?>
                            <h3 class="prod-card__name"><?= htmlspecialchars($pName, ENT_QUOTES, 'UTF-8') ?></h3>
                            <?php if ($pDesc !== '' && $pDesc !== $pName): ?>
                                <p class="prod-card__desc"><?= htmlspecialchars(mb_substr($pDesc, 0, 110, 'UTF-8'), ENT_QUOTES, 'UTF-8') ?></p>
                            <?php endif; ?>
                            <div class="prod-card__footer">
                                <div class="prod-card__price"><?= htmlspecialchars($currencySymbol, ENT_QUOTES, 'UTF-8') ?><?= number_format($pPrice, $currencyDecimals, ',', '.') ?></div>
                                <?php if ($cartEnabled): ?>
                                <button type="button" class="btn btn--ghost btn--sm" data-add-to-cart>
                                    <i class="bx bx-cart-add" aria-hidden="true"></i> Agregar
                                </button>
                                <?php elseif ($cartWhatsAppEnabled && $whatsappDigits !== ''): ?>
                                <a class="btn btn--ghost btn--sm" href="https://wa.me/<?= htmlspecialchars($whatsappDigits, ENT_QUOTES, 'UTF-8') ?>?text=<?= rawurlencode('Hola! Quiero consultar por ' . $pName . ' en ' . $titulo) ?>" target="_blank" rel="noopener">
                                    <i class="bx bxl-whatsapp" aria-hidden="true"></i> Consultar
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </article>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </section>
        <?php endif; ?>

        <section id="nosotros">
            <div class="wrap about">
                <div class="about__text">
                    <span class="eyebrow">Nosotros</span>
                    <h2 class="section-title">Sobre <?= htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8') ?></h2>
                    <p><?= htmlspecialchars($aboutDescription, ENT_QUOTES, 'UTF-8') ?></p>
                    <ul class="about-highlights">
                        <?php foreach ($aboutHighlights as $highlight): ?>
                        <li><i class="bx bx-check-circle" aria-hidden="true"></i> <span><?= htmlspecialchars($highlight, ENT_QUOTES, 'UTF-8') ?></span></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <div class="about__media">
                    <img src="<?= htmlspecialchars($hasLogo ? $logoUrl : $coverImageUrl, ENT_QUOTES, 'UTF-8') ?>" alt="">
                </div>
            </div>
        </section>

        <?php if ($hasConfiguredSchedule): ?>
        <section id="horarios" class="alt">
            <div class="wrap">
                <span class="eyebrow">Horarios</span>
                <h2 class="section-title">Cuándo podés venir</h2>
                <p class="section-sub"><?= htmlspecialchars($scheduleSummary, ENT_QUOTES, 'UTF-8') ?></p>
                <div class="schedule">
                    <?php foreach ($horarios as $key => $label):
                        $d = $scheduleRaw[$key] ?? ['abierto' => false, 'inicio' => '', 'fin' => ''];
                    ?>
                    <div class="sch-row <?= !$d['abierto'] ? 'sch-row--closed' : '' ?>">
                        <span class="sch-row__day"><?= $label ?></span>
                        <span class="sch-row__time">
                            <?php if ($d['abierto']): ?>
                                <?= htmlspecialchars(substr($d['inicio'], 0, 5), ENT_QUOTES, 'UTF-8') ?> – <?= htmlspecialchars(substr($d['fin'], 0, 5), ENT_QUOTES, 'UTF-8') ?>
                            <?php else: ?>
                                <span class="muted">Cerrado</span>
                            <?php endif; ?>
                        </span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <section id="contacto">
            <div class="wrap">
                <span class="eyebrow">Contacto</span>
                <h2 class="section-title">Cómo encontrarnos</h2>
                <p class="section-sub">Estamos en <?= htmlspecialchars($ciudad, ENT_QUOTES, 'UTF-8') ?>. Pasá, escribinos o reservá online.</p>
                <div class="contact-grid">
                    <?php if ($calle !== ''): ?>
                    <div class="contact-card">
                        <i class="bx bx-map"></i>
                        <div>
                            <div class="contact-card__label">Dirección</div>
                            <div class="contact-card__value"><?= htmlspecialchars($calle, ENT_QUOTES, 'UTF-8') ?><?= $ciudad !== '' ? ', ' . htmlspecialchars($ciudad, ENT_QUOTES, 'UTF-8') : '' ?></div>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php if ($telefono !== ''): ?>
                    <div class="contact-card">
                        <i class="bx bx-phone"></i>
                        <div>
                            <div class="contact-card__label">Teléfono</div>
                            <div class="contact-card__value"><a href="tel:<?= htmlspecialchars($telefono, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($telefono, ENT_QUOTES, 'UTF-8') ?></a></div>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php if ($whatsappDigits !== ''): ?>
                    <div class="contact-card">
                        <i class="bx bxl-whatsapp"></i>
                        <div>
                            <div class="contact-card__label">WhatsApp</div>
                            <div class="contact-card__value"><a target="_blank" rel="noopener" href="https://wa.me/<?= htmlspecialchars($whatsappDigits, ENT_QUOTES, 'UTF-8') ?>">Enviar mensaje</a></div>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php if ($email !== ''): ?>
                    <div class="contact-card">
                        <i class="bx bx-envelope"></i>
                        <div>
                            <div class="contact-card__label">Email</div>
                            <div class="contact-card__value"><a href="mailto:<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?></a></div>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php foreach ($socialLinks as $social): ?>
                    <div class="contact-card">
                        <i class="bx <?= htmlspecialchars((string)$social['icon'], ENT_QUOTES, 'UTF-8') ?>"></i>
                        <div>
                            <div class="contact-card__label"><?= htmlspecialchars((string)$social['label'], ENT_QUOTES, 'UTF-8') ?></div>
                            <div class="contact-card__value"><a target="_blank" rel="noopener" href="<?= htmlspecialchars((string)$social['url'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string)$social['display'], ENT_QUOTES, 'UTF-8') ?></a></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <?php if ($isCommerceOwner && $ownerDashboardUrl): ?>
        <section id="panel-negocio" class="alt">
            <div class="wrap">
                <span class="eyebrow">Panel del negocio</span>
                <h2 class="section-title">Administrá tu negocio</h2>
                <p class="section-sub"><?= $isStoreMode ? 'Estas logueado como dueno. Gestiona catalogo, pedidos y configuracion desde tu panel.' : 'Estás logueado como dueño. Gestioná reservas, servicios y horarios desde tu panel.' ?></p>
                <div style="display:flex; gap:.75rem; flex-wrap:wrap">
                <a href="<?= htmlspecialchars($ownerDashboardUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn--primary btn--lg">
                    <i class="bx bx-grid-alt"></i> Ir al panel
                </a>
                <a href="<?= htmlspecialchars(CommercePanel::dashboardUrlForSlug($slug, 'config'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn--ghost btn--lg">
                    <i class="bx bx-cog"></i> Configuración
                </a>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <?php if ($showBooking): ?>
        <section id="reservar" class="alt" style="text-align:center">
            <div class="wrap">
                <span class="eyebrow">Reservar</span>
                <h2 class="section-title">Listo para reservar</h2>
                <p class="section-sub" style="margin: 0 auto 1.8rem">Elegí un servicio arriba y hacé clic en "Reservar". Vas a recibir confirmación por email o WhatsApp.</p>
                <div style="display:flex; gap:.75rem; justify-content:center; flex-wrap:wrap">
                    <a href="#servicios" class="btn btn--primary btn--lg"><i class="bx bx-calendar-plus"></i> Ver servicios y reservar</a>
                    <button type="button" class="btn btn--ghost btn--lg" data-open-cancel-appointment><i class="bx bx-calendar-x"></i> Cancelar reserva</button>
                </div>
            </div>
        </section>
        <?php endif; ?>
        </main>

        <footer class="footer">
            <div class="wrap">
                <div class="footer__links">
                    <?php foreach ($socialLinks as $social): ?>
                        <a target="_blank" rel="noopener" href="<?= htmlspecialchars((string)$social['url'], ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars((string)$social['label'], ENT_QUOTES, 'UTF-8') ?>"><i class="bx <?= htmlspecialchars((string)$social['icon'], ENT_QUOTES, 'UTF-8') ?>" style="font-size:1.5rem"></i></a>
                    <?php endforeach; ?>
                    <?php if ($whatsappDigits !== ''): ?>
                        <a target="_blank" rel="noopener" href="https://wa.me/<?= htmlspecialchars($whatsappDigits, ENT_QUOTES, 'UTF-8') ?>" aria-label="WhatsApp"><i class="bx bxl-whatsapp" style="font-size:1.5rem"></i></a>
                    <?php endif; ?>
                    <?php if ($email !== ''): ?>
                        <a href="mailto:<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>" aria-label="Email"><i class="bx bx-envelope" style="font-size:1.5rem"></i></a>
                    <?php endif; ?>
                    <?php if ($telefono !== ''): ?>
                        <a href="tel:<?= htmlspecialchars($telefono, ENT_QUOTES, 'UTF-8') ?>" aria-label="Teléfono"><i class="bx bx-phone" style="font-size:1.5rem"></i></a>
                    <?php endif; ?>
                </div>
                <div class="footer__small">© <?= date('Y') ?> <?= htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8') ?></div>
                <div class="footer__legal">
                    <a href="<?= htmlspecialchars(url(''), ENT_QUOTES, 'UTF-8') ?>" class="footer__brand-link">
                        <span class="footer__brand-logo">
                            <img src="<?= htmlspecialchars(url('src/img/favicon/favicon.png'), ENT_QUOTES, 'UTF-8') ?>" alt="Agendarte UY">
                        </span>
                        <span>Agendarte UY</span>
                    </a>
                    · Sistema de reservas online
                </div>
            </div>
        </footer>

        <!-- Modal de reserva -->
        <div class="modal-bg" id="booking-modal" role="dialog" aria-modal="true" aria-labelledby="booking-title">
            <div class="modal">
                <div class="modal__header">
                    <h3 id="booking-title">Reservar turno</h3>
                    <button class="modal__close" type="button" data-close-modal aria-label="Cerrar">×</button>
                </div>
                <div class="modal__body">
                    <div id="booking-step-form">
                        <div id="booking-alert" hidden></div>
                        <?php if ($googleClientId !== ''): ?>
                        <div id="booking-google-wrap" hidden>
                            <div id="booking-google-btn"></div>
                            <div class="booking-or-divider"><span>o completá tus datos</span></div>
                        </div>
                        <div class="client-history" id="booking-history" hidden>
                            <p class="client-history__title"><i class="bx bx-history" aria-hidden="true"></i> Tu historial en este negocio</p>
                            <div class="client-history__body" id="booking-history-body"></div>
                        </div>
                        <?php endif; ?>
                        <form id="booking-form" novalidate>
                            <input type="hidden" name="slug" value="<?= htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="id_service" id="booking-svc-id" value="">
                            <input type="hidden" name="payment_method" id="booking-payment-method" value="manual">
                            <div class="field">
                                <label>Servicio</label>
                                <input type="text" id="booking-svc-name" readonly>
                            </div>
                            <div class="field field--row">
                                <div>
                                    <label for="booking-date">Fecha</label>
                                    <input type="text" id="booking-date" name="fecha" required autocomplete="off" inputmode="none" placeholder="Elegí una fecha" readonly>
                                </div>
                                <div>
                                    <label for="booking-time">Hora</label>
                                    <select id="booking-time" name="hora_inicio" required disabled>
                                        <option value="">Elegí una fecha</option>
                                    </select>
                                    <p class="hint" id="booking-time-hint" hidden></p>
                                </div>
                            </div>
                            <div class="field">
                                <label for="booking-name">Nombre y apellido</label>
                                <input type="text" id="booking-name" name="cliente_nombre" required autocomplete="name" placeholder="Ej: Ana Pereira">
                            </div>
                            <div class="field field--row">
                                <div>
                                    <label for="booking-email">Email</label>
                                    <input type="email" id="booking-email" name="cliente_email" autocomplete="email" placeholder="tu@email.com">
                                </div>
                                <div>
                                    <label for="booking-cedula">Cédula</label>
                                    <input type="text" id="booking-cedula" name="cliente_cedula" inputmode="numeric" autocomplete="off" placeholder="12345678" pattern="[0-9]{7,}" title="Solo números, mínimo 7 dígitos" required>
                                </div>
                            </div>
                            <div class="field">
                                <label for="booking-phone">Teléfono</label>
                                <input type="tel" id="booking-phone" name="cliente_telefono" autocomplete="tel" placeholder="099 123 456">
                            </div>
                            <p class="hint" id="booking-lookup-hint" hidden role="status"></p>
                            <div class="field">
                                <label for="booking-notes">Notas (opcional)</label>
                                <textarea id="booking-notes" name="notas" rows="2"></textarea>
                            </div>
                            <?php if ($bookingMpCheckoutEnabled): ?>
                            <div class="booking-payment" id="booking-payment-box" hidden>
                                <div>
                                    <span>Pago online disponible</span>
                                    <strong id="booking-payment-amount"></strong>
                                </div>
                                <small id="booking-payment-required" hidden>Este comercio solicita pago online para confirmar la reserva.</small>
                            </div>
                            <?php endif; ?>
                        </form>
                    </div>
                    <?php if ($showProducts): ?>
                    <div id="booking-step-upsell" hidden>
                        <div class="alert alert--ok" id="booking-upsell-ok">
                            <i class="bx bx-check-circle"></i> Reserva confirmada.
                        </div>
                        <p class="upsell-lead" id="booking-upsell-lead">¿Desea alguno de nuestros productos?</p>
                        <p class="section-sub" style="margin:0 0 1rem">Podés agregarlos al carrito y coordinar todo por WhatsApp junto con tu reserva.</p>
                        <div class="upsell-grid" id="booking-upsell-grid"></div>
                        <div class="cart-lines" id="booking-upsell-cart" hidden></div>
                        <p class="hint" id="booking-upsell-wa-hint" hidden></p>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="modal__foot" id="booking-foot-form">
                    <button type="button" class="btn btn--ghost" data-close-modal>Cancelar</button>
                    <button type="submit" form="booking-form" class="btn btn--primary" id="booking-submit" data-booking-submit="manual">Confirmar reserva</button>
                    <?php if ($bookingMpCheckoutEnabled): ?>
                    <button type="submit" form="booking-form" class="btn btn--primary" id="booking-pay-submit" data-booking-submit="mercadopago" hidden>
                        <i class="bx bx-credit-card" aria-hidden="true"></i> Pagar y reservar
                    </button>
                    <?php endif; ?>
                </div>
                <?php if ($showProducts): ?>
                <div class="modal__foot" id="booking-foot-upsell" hidden>
                    <button type="button" class="btn btn--ghost" id="booking-upsell-skip">No, gracias</button>
                    <button type="button" class="btn btn--primary" id="booking-upsell-wa">
                        <i class="bx bxl-whatsapp" aria-hidden="true"></i> Finalizar mi compra
                    </button>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($showBooking): ?>
        <!-- Modal cancelar reserva -->
        <div class="modal-bg" id="cancel-appointment-modal" role="dialog" aria-modal="true" aria-labelledby="cancel-appointment-title">
            <div class="modal">
                <div class="modal__header">
                    <h3 id="cancel-appointment-title">Cancelar reserva</h3>
                    <button class="modal__close" type="button" data-close-cancel-appointment aria-label="Cerrar">×</button>
                </div>
                <div class="modal__body">
                    <div id="cancel-appointment-alert" hidden></div>
                    <form id="cancel-appointment-form" novalidate>
                        <input type="hidden" name="slug" value="<?= htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') ?>">
                        <div class="field field--row">
                            <div>
                                <label for="cancel-appointment-id">Número de reserva</label>
                                <input type="text" id="cancel-appointment-id" name="id_appointment" inputmode="numeric" required placeholder="154">
                            </div>
                            <div>
                                <label for="cancel-appointment-cedula">Cédula</label>
                                <input type="text" id="cancel-appointment-cedula" name="cedula" inputmode="numeric" required placeholder="12345678">
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal__foot">
                    <button type="button" class="btn btn--ghost" data-close-cancel-appointment>Volver</button>
                    <button type="submit" form="cancel-appointment-form" class="btn btn--primary" id="cancel-appointment-submit">Cancelar reserva</button>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($cartEnabled): ?>
        <!-- Modal carrito -->
        <div class="modal-bg" id="cart-modal" role="dialog" aria-modal="true" aria-labelledby="cart-title">
            <div class="modal modal--cart">
                <div class="modal__header">
                    <h3 id="cart-title">Tu pedido</h3>
                    <button class="modal__close" type="button" data-close-cart aria-label="Cerrar">×</button>
                </div>
                <div class="modal__body">
                    <div id="cart-empty" class="cart-empty" hidden>
                        <i class="bx bx-cart" aria-hidden="true"></i>
                        <p>Tu carrito está vacío.</p>
                        <a href="#productos" class="btn btn--ghost" data-close-cart>Ver productos</a>
                    </div>
                    <div id="cart-content">
                        <div class="cart-lines" id="cart-lines"></div>
                        <div class="cart-total" id="cart-total"></div>
                        <?php if ($googleClientId !== ''): ?>
                        <div class="cart-google" id="cart-google-wrap" hidden>
                            <div id="cart-google-btn"></div>
                            <p class="hint" id="cart-google-hint" hidden role="status"></p>
                            <div class="booking-or-divider"><span>o completá tus datos</span></div>
                        </div>
                        <div class="client-history" id="cart-history" hidden>
                            <p class="client-history__title"><i class="bx bx-history" aria-hidden="true"></i> Tu historial en este negocio</p>
                            <div class="client-history__body" id="cart-history-body"></div>
                        </div>
                        <?php endif; ?>
                        <div class="cart-contact" id="cart-contact">
                            <p class="cart-contact__title">Datos para confirmarte el pedido</p>
                            <div class="cart-contact__grid">
                                <label for="cart-customer-name">
                                    Nombre
                                    <input type="text" id="cart-customer-name" autocomplete="name" placeholder="Tu nombre">
                                </label>
                                <label for="cart-customer-email">
                                    Email
                                    <input type="email" id="cart-customer-email" autocomplete="email" placeholder="tu@email.com">
                                </label>
                                <label for="cart-customer-phone">
                                    Telefono
                                    <input type="tel" id="cart-customer-phone" autocomplete="tel" placeholder="099 123 456">
                                </label>
                            </div>
                            <p class="cart-contact__hint">Te enviamos la confirmacion por email y WhatsApp.</p>
                        </div>
                        <p class="hint" id="cart-wa-hint" hidden></p>
                    </div>
                </div>
                <div class="modal__foot">
                    <button type="button" class="btn btn--ghost" data-close-cart>Seguir mirando</button>
                    <?php if ($storeMpCheckoutEnabled): ?>
                    <button type="button" class="btn btn--primary" id="cart-mp-btn">
                        <i class="bx bx-credit-card" aria-hidden="true"></i> Pagar con Mercado Pago
                    </button>
                    <?php endif; ?>
                    <?php if ($cartWhatsAppEnabled): ?>
                    <button type="button" class="btn btn--primary" id="cart-wa-btn">
                        <i class="bx bxl-whatsapp" aria-hidden="true"></i> Enviar por WhatsApp
                    </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($hasDlocalPlans): ?>
        <script>window.__DLOCAL_ENDPOINTS__ = <?= json_encode(['subscribe' => url('src/API/dlocal/subscribe.php')], JSON_UNESCAPED_SLASHES) ?>;</script>
        <script src="<?= htmlspecialchars(url('public/assets/js/dlocal-plans.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
        <?php endif; ?>
        <script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/l10n/es.js"></script>
        <script>
        (function(){
            const menuBtn = document.getElementById('menu-btn');
            const mobileMenu = document.getElementById('mobile-menu');
            if (menuBtn && mobileMenu) {
                menuBtn.addEventListener('click', () => mobileMenu.classList.toggle('is-open'));
                mobileMenu.querySelectorAll('a').forEach(a => a.addEventListener('click', () => mobileMenu.classList.remove('is-open')));
            }

            const themeBtn = document.getElementById('theme-toggle');
            const themeIcon = document.getElementById('theme-icon');
            const adminDefault = <?= json_encode($defaultTheme, JSON_UNESCAPED_SLASHES) ?>;
            const themeStorageKey = <?= json_encode('agenduy-theme-' . $slug, JSON_UNESCAPED_SLASHES) ?>;
            // Prefer tenant admin setting; only keep a visitor override for this slug.
            const savedTheme = localStorage.getItem(themeStorageKey);
            const activeTheme = (savedTheme === 'dark' || savedTheme === 'light') ? savedTheme : adminDefault;
            document.body.setAttribute('data-theme', activeTheme);
            if (themeIcon) themeIcon.className = activeTheme === 'dark' ? 'bx bx-sun' : 'bx bx-moon';
            if (themeBtn) themeBtn.addEventListener('click', () => {
                const cur = document.body.getAttribute('data-theme') || 'light';
                const next = cur === 'dark' ? 'light' : 'dark';
                document.body.setAttribute('data-theme', next);
                localStorage.setItem(themeStorageKey, next);
                themeIcon.className = next === 'dark' ? 'bx bx-sun' : 'bx bx-moon';
            });

            // Settings disponibles para UI futura (legal/funciones/reservas).
            window.__COMMERCE_PUBLIC_SETTINGS__ = <?= json_encode([
                'legal' => $legal,
                'funciones' => $funciones,
                'reservas' => $reservasCfg,
                'carrito' => $carritoCfg,
                'redes' => $redes,
                'tema' => $tema,
                'payments' => [
                    'mercado_pago_tienda' => $storeMpCheckoutEnabled,
                    'mercado_pago_reservas' => $bookingMpCheckoutEnabled,
                    'mercado_pago_reservas_required' => $bookingMpRequired,
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

            const commerceSlug = <?= json_encode($slug, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
            const commerceName = <?= json_encode($titulo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
            const waDigits = <?= json_encode($whatsappDigits, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
            const currencySymbol = <?= json_encode($currencySymbol, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
            const currencyDecimals = <?= (int)$currencyDecimals ?>;
            const productsCatalog = <?= json_encode($productsForJs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
            const hasProducts = Array.isArray(productsCatalog) && productsCatalog.length > 0;
            const cartEnabled = <?= $cartEnabled ? 'true' : 'false' ?>;
            const cartWhatsAppEnabled = <?= $cartWhatsAppEnabled ? 'true' : 'false' ?>;
            const cartInstructions = <?= json_encode($cartInstructions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
            const cartKey = 'agenduy-cart-' + commerceSlug;
            const cartOrderUrl = <?= json_encode($cartOrderApi, JSON_UNESCAPED_SLASHES) ?>;
            const cartMercadoPagoUrl = <?= json_encode($cartMercadoPagoApi, JSON_UNESCAPED_SLASHES) ?>;
            const cancelAppointmentUrl = <?= json_encode($cancelAppointmentApi, JSON_UNESCAPED_SLASHES) ?>;
            const storeMpCheckoutEnabled = <?= $storeMpCheckoutEnabled ? 'true' : 'false' ?>;
            const bookingMpCheckoutEnabled = <?= $bookingMpCheckoutEnabled ? 'true' : 'false' ?>;
            const bookingMpRequired = <?= $bookingMpRequired ? 'true' : 'false' ?>;
            let lastBooking = null;
            let csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
            function syncCsrfToken(token) {
                csrfToken = String(token || '');
                const meta = document.querySelector('meta[name="csrf-token"]');
                if (meta) meta.setAttribute('content', csrfToken);
            }
            let orderSubmitInFlight = false;

            function showPublicToast(message, type) {
                const toast = document.createElement('div');
                toast.className = 'public-toast public-toast--' + (type || 'info');
                toast.innerHTML = '<i class="bx ' + (type === 'error' ? 'bx-error-circle' : 'bx-check-circle') + '" aria-hidden="true"></i><span>' + escapeHtml(message) + '</span>';
                document.body.appendChild(toast);
                requestAnimationFrame(() => toast.classList.add('is-visible'));
                setTimeout(() => {
                    toast.classList.remove('is-visible');
                    setTimeout(() => toast.remove(), 250);
                }, 6500);
            }

            (function showAppointmentPaymentReturn() {
                const params = new URLSearchParams(window.location.search);
                const appointmentId = params.get('mp_appointment');
                if (!appointmentId) return;
                const status = String(params.get('mp_status') || '').toLowerCase();
                if (status === 'success') {
                    showPublicToast('Pago recibido. La reserva queda confirmada cuando Mercado Pago informa la aprobacion.', 'success');
                } else if (status === 'pending') {
                    showPublicToast('El pago quedo pendiente. Te avisaremos cuando Mercado Pago lo confirme.', 'info');
                } else {
                    showPublicToast('No se completo el pago de la reserva. Podes intentar nuevamente con otro horario.', 'error');
                }
                params.delete('mp_appointment');
                params.delete('mp_status');
                const clean = window.location.pathname + (params.toString() ? '?' + params.toString() : '') + window.location.hash;
                window.history.replaceState({}, '', clean);
            })();

            function formatMoney(n) {
                const num = Number(n) || 0;
                try {
                    return currencySymbol + num.toLocaleString('es-UY', {
                        minimumFractionDigits: currencyDecimals,
                        maximumFractionDigits: currencyDecimals
                    });
                } catch (_) {
                    return currencySymbol + num.toFixed(currencyDecimals);
                }
            }

            function loadCart() {
                try {
                    const raw = localStorage.getItem(cartKey);
                    const parsed = raw ? JSON.parse(raw) : [];
                    return Array.isArray(parsed) ? parsed.filter(i => i && i.id) : [];
                } catch (_) {
                    return [];
                }
            }

            function saveCart(items) {
                localStorage.setItem(cartKey, JSON.stringify(items));
                renderCartUI();
            }

            function cartQty(items) {
                return items.reduce((sum, i) => sum + (Number(i.qty) || 0), 0);
            }

            function cartTotal(items) {
                return items.reduce((sum, i) => sum + (Number(i.price) || 0) * (Number(i.qty) || 0), 0);
            }

            function addToCart(id, name, price, qty) {
                const items = loadCart();
                const pid = String(id);
                const existing = items.find(i => String(i.id) === pid);
                const addQty = Math.max(1, Number(qty) || 1);
                if (existing) {
                    existing.qty = (Number(existing.qty) || 0) + addQty;
                } else {
                    items.push({
                        id: pid,
                        name: String(name || 'Producto'),
                        price: Number(price) || 0,
                        qty: addQty
                    });
                }
                saveCart(items);
            }

            function setCartQty(id, qty) {
                let items = loadCart();
                const pid = String(id);
                const nextQty = Math.max(0, Number(qty) || 0);
                if (nextQty <= 0) {
                    items = items.filter(i => String(i.id) !== pid);
                } else {
                    const row = items.find(i => String(i.id) === pid);
                    if (row) row.qty = nextQty;
                }
                saveCart(items);
            }

            function buildProductsMessage(items) {
                if (!items.length) return '';
                const lines = items.map(i =>
                    '- ' + (Number(i.qty) || 1) + 'x ' + i.name + ' (' + formatMoney((Number(i.price) || 0) * (Number(i.qty) || 1)) + ')'
                );
                lines.push('Total productos: ' + formatMoney(cartTotal(items)));
                return lines.join('\n');
            }

            function buildStoreWaMessage(items, paymentUrl) {
                const lines = [
                    'Hola! Quiero pedir estos productos de ' + commerceName + ':',
                    '',
                    buildProductsMessage(items),
                ];
                const mpUrl = String(paymentUrl || '').trim();
                if (mpUrl) {
                    lines.push('', 'Link de pago Mercado Pago:', mpUrl);
                }
                lines.push('', cartInstructions || 'Coordinamos entrega o retiro por este medio. Gracias!');
                return lines.join('\n');
            }

            function buildBookingWaMessage(booking, items) {
                const parts = [
                    'Hola! Acabo de reservar en ' + commerceName + ':',
                    '',
                    'Servicio: ' + (booking.servicio || ''),
                    'Fecha: ' + (booking.fecha || ''),
                    'Hora: ' + (booking.hora || ''),
                    'Nombre: ' + (booking.nombre || ''),
                ];
                if (booking.id) parts.push('Nº reserva: ' + booking.id);
                if (items.length) {
                    parts.push('', 'También me interesan estos productos:', '', buildProductsMessage(items));
                }
                parts.push('', 'Coordinamos por este medio. Gracias!');
                return parts.join('\n');
            }

            function openWhatsApp(text, targetWindow) {
                if (!waDigits) {
                    window.alert('Este comercio aún no tiene WhatsApp configurado. Contactalo en el local o por teléfono.');
                    return false;
                }
                const url = 'https://wa.me/' + waDigits + '?text=' + encodeURIComponent(text);
                if (targetWindow && !targetWindow.closed) {
                    try {
                        targetWindow.location.href = url;
                        return true;
                    } catch (_) {}
                }
                window.open(url, '_blank', 'noopener');
                return true;
            }

            async function registerPendingOrder(items, meta) {
                meta = meta || {};
                if (!items || !items.length) {
                    return { ok: true, skipped: true };
                }
                const body = {
                    slug: commerceSlug,
                    items: items.map(i => ({
                        id: String(i.id),
                        qty: Math.max(1, Number(i.qty) || 1)
                    })),
                    cliente_nombre: meta.nombre || '',
                    cliente_email: meta.email || '',
                    cliente_telefono: meta.telefono || '',
                    appointment_id: meta.appointment_id || '',
                    note: meta.note || 'Pedido WhatsApp',
                    address: meta.address || cartInstructions || 'Coordinar por WhatsApp',
                    _csrf: csrfToken
                };
                const postOnce = async () => {
                    const ctrl = new AbortController();
                    const timer = setTimeout(() => ctrl.abort(), 15000);
                    try {
                        const res = await fetch(cartOrderUrl, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify(body),
                            credentials: 'same-origin',
                            signal: ctrl.signal
                        });
                        let json = null;
                        try { json = await res.json(); } catch (_) {}
                        return { res, json: json || { ok: false, error: 'Respuesta inválida del servidor.' } };
                    } finally {
                        clearTimeout(timer);
                    }
                };
                let { res, json } = await postOnce();
                if (json && json.error === 'csrf_retry' && json.csrf) {
                    csrfToken = String(json.csrf);
                    syncCsrfToken(json.csrf);
                    body._csrf = csrfToken;
                    ({ res, json } = await postOnce());
                }
                if (!json || !json.ok) {
                    const msg = (json && json.error && json.error !== 'csrf_retry')
                        ? String(json.error)
                        : 'No se pudo registrar el pedido en el comercio.';
                    throw new Error(msg);
                }
                return json;
            }

            async function createMercadoPagoCheckout(items, meta) {
                meta = meta || {};
                const body = {
                    slug: commerceSlug,
                    items: items.map(i => ({
                        id: String(i.id),
                        qty: Math.max(1, Number(i.qty) || 1)
                    })),
                    cliente_nombre: meta.nombre || '',
                    cliente_email: meta.email || '',
                    cliente_telefono: meta.telefono || '',
                    note: meta.note || 'Pedido Mercado Pago',
                    address: meta.address || cartInstructions || 'Coordinar entrega o retiro',
                    _csrf: csrfToken
                };
                const postOnce = async () => {
                    const ctrl = new AbortController();
                    const timer = setTimeout(() => ctrl.abort(), 20000);
                    try {
                        const res = await fetch(cartMercadoPagoUrl, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify(body),
                            credentials: 'same-origin',
                            signal: ctrl.signal
                        });
                        let json = null;
                        try { json = await res.json(); } catch (_) {}
                        return { res, json: json || { ok: false, error: 'Respuesta invalida del servidor.' } };
                    } finally {
                        clearTimeout(timer);
                    }
                };
                let { json } = await postOnce();
                if (json && json.error === 'csrf_retry' && json.csrf) {
                    syncCsrfToken(json.csrf);
                    body._csrf = csrfToken;
                    ({ json } = await postOnce());
                }
                if (!json || !json.ok || !json.checkout_url) {
                    const msg = (json && json.error && json.error !== 'csrf_retry')
                        ? String(json.error)
                        : 'No se pudo iniciar el checkout de Mercado Pago.';
                    throw new Error(msg);
                }
                return json;
            }

            async function startMercadoPagoCheckout(opts) {
                opts = opts || {};
                if (orderSubmitInFlight) return false;
                if (!storeMpCheckoutEnabled) {
                    window.alert('Mercado Pago no esta disponible para esta tienda.');
                    return false;
                }
                const items = loadCart();
                if (!items.length) {
                    window.alert('Agrega al menos un producto para pagar.');
                    return false;
                }
                orderSubmitInFlight = true;
                const buttons = [
                    document.getElementById('cart-mp-btn'),
                    document.getElementById('cart-wa-btn'),
                    document.getElementById('booking-upsell-wa')
                ].filter(Boolean);
                buttons.forEach(btn => { btn.disabled = true; });
                const whatsappWindow = cartWhatsAppEnabled && waDigits ? window.open('', '_blank') : null;
                if (whatsappWindow) {
                    whatsappWindow.opener = null;
                }
                try {
                    const checkout = await createMercadoPagoCheckout(items, opts.meta || {});
                    if (cartWhatsAppEnabled && waDigits && checkout.checkout_url) {
                        openWhatsApp(buildStoreWaMessage(items, checkout.checkout_url), whatsappWindow);
                    }
                    saveCart([]);
                    closeCartModal();
                    window.location.href = checkout.checkout_url;
                    return true;
                } catch (err) {
                    if (whatsappWindow && !whatsappWindow.closed) {
                        whatsappWindow.close();
                    }
                    window.alert(err && err.message ? err.message : 'No se pudo iniciar Mercado Pago.');
                    orderSubmitInFlight = false;
                    renderCartUI();
                    return false;
                }
            }

            async function finalizePurchase(opts) {
                opts = opts || {};
                if (orderSubmitInFlight) return false;
                const items = loadCart();
                const requireItems = opts.requireItems !== false;
                if (requireItems && !items.length) {
                    window.alert('Agregá al menos un producto para finalizar la compra.');
                    return false;
                }
                if (!items.length) {
                    const openedEmpty = openWhatsApp(opts.waText || '');
                    if (openedEmpty && typeof opts.afterSuccess === 'function') opts.afterSuccess();
                    return openedEmpty;
                }
                orderSubmitInFlight = true;
                const buttons = [
                    document.getElementById('cart-mp-btn'),
                    document.getElementById('cart-wa-btn'),
                    document.getElementById('booking-upsell-wa')
                ].filter(Boolean);
                buttons.forEach(btn => { btn.disabled = true; });
                let registered = false;
                try {
                    await registerPendingOrder(items, opts.meta || {});
                    registered = true;
                    saveCart([]);
                    closeCartModal();
                } catch (err) {
                    window.alert((err && err.message ? err.message : 'No se pudo registrar el pedido.') +
                        ' Podés coordinar igual por WhatsApp; el comercio puede no ver el pedido en el panel.');
                }
                try {
                    const opened = openWhatsApp(opts.waText || buildStoreWaMessage(items));
                    if (registered && typeof opts.afterSuccess === 'function') opts.afterSuccess();
                    return opened;
                } finally {
                    orderSubmitInFlight = false;
                    renderCartUI();
                    const upsellBtn = document.getElementById('booking-upsell-wa');
                    if (upsellBtn) upsellBtn.disabled = false;
                }
            }

            function renderCartLines(container, items, opts) {
                if (!container) return;
                const showControls = !opts || opts.controls !== false;
                if (!items.length) {
                    container.innerHTML = '';
                    return;
                }
                container.innerHTML = items.map(i => {
                    const lineTotal = formatMoney((Number(i.price) || 0) * (Number(i.qty) || 0));
                    const controls = showControls
                        ? '<div class="cart-line__qty">' +
                            '<button type="button" class="qty-btn" data-cart-dec="' + i.id + '" aria-label="Quitar uno">−</button>' +
                            '<span>' + (Number(i.qty) || 0) + '</span>' +
                            '<button type="button" class="qty-btn" data-cart-inc="' + i.id + '" aria-label="Agregar uno">+</button>' +
                            '<button type="button" class="qty-btn qty-btn--remove" data-cart-remove="' + i.id + '" aria-label="Eliminar"><i class="bx bx-trash"></i></button>' +
                          '</div>'
                        : '<span class="cart-line__qty-label">x' + (Number(i.qty) || 0) + '</span>';
                    return '<div class="cart-line" data-id="' + i.id + '">' +
                        '<div class="cart-line__info"><strong>' + escapeHtml(i.name) + '</strong><span>' + formatMoney(i.price) + ' c/u</span></div>' +
                        controls +
                        '<div class="cart-line__total">' + lineTotal + '</div>' +
                      '</div>';
                }).join('');
            }

            function escapeHtml(str) {
                return String(str)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;');
            }

            function bindCartLineActions(root) {
                if (!root || root.dataset.cartBound === '1') return;
                root.dataset.cartBound = '1';
                root.addEventListener('click', (e) => {
                    const dec = e.target.closest('[data-cart-dec]');
                    const inc = e.target.closest('[data-cart-inc]');
                    const rem = e.target.closest('[data-cart-remove]');
                    if (dec) {
                        const id = dec.getAttribute('data-cart-dec');
                        const row = loadCart().find(i => String(i.id) === String(id));
                        setCartQty(id, (row ? Number(row.qty) : 1) - 1);
                    } else if (inc) {
                        const id = inc.getAttribute('data-cart-inc');
                        const row = loadCart().find(i => String(i.id) === String(id));
                        setCartQty(id, (row ? Number(row.qty) : 0) + 1);
                    } else if (rem) {
                        setCartQty(rem.getAttribute('data-cart-remove'), 0);
                    }
                });
            }

            const cartOpenBtn = document.getElementById('cart-open');
            const cartCountEl = document.getElementById('cart-count');
            const cartModal = document.getElementById('cart-modal');
            const cartLines = document.getElementById('cart-lines');
            const cartTotalEl = document.getElementById('cart-total');
            const cartEmpty = document.getElementById('cart-empty');
            const cartContent = document.getElementById('cart-content');
            const cartWaBtn = document.getElementById('cart-wa-btn');
            const cartMpBtn = document.getElementById('cart-mp-btn');
            const cartWaHint = document.getElementById('cart-wa-hint');
            const cartCustomerName = document.getElementById('cart-customer-name');
            const cartCustomerEmail = document.getElementById('cart-customer-email');
            const cartCustomerPhone = document.getElementById('cart-customer-phone');

            function isValidEmail(value) {
                return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(value || '').trim());
            }

            function prefillCartContact() {
                const guest = typeof loadSavedGuest === 'function' ? loadSavedGuest() : null;
                if (!guest) return;
                if (cartCustomerName && !String(cartCustomerName.value || '').trim()) {
                    cartCustomerName.value = String(guest.nombre || '').trim();
                }
                if (cartCustomerEmail && !String(cartCustomerEmail.value || '').trim()) {
                    cartCustomerEmail.value = String(guest.email || '').trim();
                }
                if (cartCustomerPhone && !String(cartCustomerPhone.value || '').trim()) {
                    cartCustomerPhone.value = String(guest.telefono || '').trim();
                }
            }

            function collectCartCustomerMeta() {
                const nombre = String(cartCustomerName?.value || '').trim();
                const email = String(cartCustomerEmail?.value || '').trim();
                const telefono = String(cartCustomerPhone?.value || '').trim();
                if (!isValidEmail(email)) {
                    window.alert('Ingresa un email valido para enviarte la confirmacion.');
                    cartCustomerEmail?.focus();
                    return null;
                }
                if ((telefono.replace(/\D/g, '')).length < 7) {
                    window.alert('Ingresa un telefono valido para enviarte WhatsApp.');
                    cartCustomerPhone?.focus();
                    return null;
                }
                if (typeof saveGuest === 'function') {
                    saveGuest({ nombre, email, telefono });
                }
                return { nombre, email, telefono };
            }

            function openCartModal() {
                prefillCartContact();
                hideClientHistory();
                if (cartModal) cartModal.classList.add('is-open');
                renderGoogleAuthButtons();
            }
            function closeCartModal() {
                if (cartModal) cartModal.classList.remove('is-open');
            }

            function renderCartUI() {
                const items = loadCart();
                const qty = cartQty(items);
                if (cartCountEl) cartCountEl.textContent = String(qty);
                if (cartOpenBtn) cartOpenBtn.hidden = qty === 0 || !cartEnabled;

                if (cartLines) {
                    renderCartLines(cartLines, items, { controls: true });
                    bindCartLineActions(cartLines);
                }
                if (cartTotalEl) {
                    cartTotalEl.innerHTML = items.length
                        ? '<span>Total</span><strong>' + formatMoney(cartTotal(items)) + '</strong>'
                        : '';
                }
                if (cartEmpty) cartEmpty.hidden = items.length > 0;
                if (cartContent) cartContent.hidden = items.length === 0;
                if (cartMpBtn) cartMpBtn.disabled = items.length === 0 || !storeMpCheckoutEnabled;
                if (cartWaBtn) cartWaBtn.disabled = items.length === 0 || !cartWhatsAppEnabled || !waDigits;
                if (cartWaHint) {
                    if (!cartWhatsAppEnabled) {
                        cartWaHint.hidden = false;
                        cartWaHint.textContent = 'WhatsApp para pedidos esta desactivado en esta tienda.';
                    } else if (!waDigits) {
                        cartWaHint.hidden = false;
                        cartWaHint.textContent = 'WhatsApp no configurado. Contactá al comercio en el local o por teléfono.';
                    } else {
                        cartWaHint.hidden = true;
                        cartWaHint.textContent = '';
                    }
                }

                const upsellCart = document.getElementById('booking-upsell-cart');
                if (upsellCart) {
                    upsellCart.hidden = items.length === 0;
                    renderCartLines(upsellCart, items, { controls: true });
                    bindCartLineActions(upsellCart);
                }
                const upsellWa = document.getElementById('booking-upsell-wa');
                if (upsellWa) upsellWa.disabled = !cartWhatsAppEnabled || !waDigits;
                const upsellHint = document.getElementById('booking-upsell-wa-hint');
                if (upsellHint) {
                    if (!cartWhatsAppEnabled) {
                        upsellHint.hidden = false;
                        upsellHint.textContent = 'WhatsApp para pedidos esta desactivado en esta tienda.';
                    } else if (!waDigits) {
                        upsellHint.hidden = false;
                        upsellHint.textContent = 'WhatsApp no configurado. Contactá al comercio en el local o por teléfono.';
                    } else {
                        upsellHint.hidden = true;
                    }
                }
            }

            if (hasProducts) {
                document.querySelectorAll('[data-add-to-cart]').forEach(btn => {
                    btn.addEventListener('click', (e) => {
                        const card = e.target.closest('.prod-card');
                        if (!card) return;
                        addToCart(card.dataset.productId, card.dataset.productName, card.dataset.productPrice, 1);
                        btn.classList.add('is-added');
                        const prev = btn.innerHTML;
                        btn.innerHTML = '<i class="bx bx-check" aria-hidden="true"></i> Agregado';
                        setTimeout(() => {
                            btn.classList.remove('is-added');
                            btn.innerHTML = prev;
                        }, 1200);
                    });
                });
                if (cartOpenBtn) cartOpenBtn.addEventListener('click', openCartModal);
                document.querySelectorAll('[data-close-cart]').forEach(btn => {
                    btn.addEventListener('click', (e) => {
                        closeCartModal();
                        if (btn.tagName === 'A' && btn.getAttribute('href') === '#productos') {
                            // allow navigation
                        } else {
                            e.preventDefault();
                        }
                    });
                });
                if (cartModal) {
                    cartModal.addEventListener('click', e => { if (e.target === cartModal) closeCartModal(); });
                }
                if (cartWaBtn) {
                    cartWaBtn.addEventListener('click', () => {
                        const items = loadCart();
                        if (!items.length) return;
                        const customerMeta = collectCartCustomerMeta();
                        if (!customerMeta) return;
                        finalizePurchase({
                            waText: buildStoreWaMessage(items),
                            meta: Object.assign({ note: 'Pedido WhatsApp (tienda)' }, customerMeta),
                            requireItems: true
                        });
                    });
                }
                if (cartMpBtn) {
                    cartMpBtn.addEventListener('click', () => {
                        const customerMeta = collectCartCustomerMeta();
                        if (!customerMeta) return;
                        startMercadoPagoCheckout({
                            meta: Object.assign({ note: 'Pedido Mercado Pago (tienda)' }, customerMeta)
                        });
                    });
                }
                renderCartUI();
            }

            const modal = document.getElementById('booking-modal');
            const form = document.getElementById('booking-form');
            const alertBox = document.getElementById('booking-alert');
            const submitBtn = document.getElementById('booking-submit');
            const bookingPayBtn = document.getElementById('booking-pay-submit');
            const bookingPaymentMethodInput = document.getElementById('booking-payment-method');
            const bookingPaymentBox = document.getElementById('booking-payment-box');
            const bookingPaymentAmount = document.getElementById('booking-payment-amount');
            const bookingPaymentRequired = document.getElementById('booking-payment-required');
            const svcIdInput = document.getElementById('booking-svc-id');
            const svcNameInput = document.getElementById('booking-svc-name');
            const dateInput = document.getElementById('booking-date');
            const timeSelect = document.getElementById('booking-time');
            const timeHint = document.getElementById('booking-time-hint');
            const bookingNameInput = document.getElementById('booking-name');
            const bookingEmailInput = document.getElementById('booking-email');
            const bookingCedulaInput = document.getElementById('booking-cedula');
            const bookingPhoneInput = document.getElementById('booking-phone');
            const bookingLookupHint = document.getElementById('booking-lookup-hint');
            const clientLookupUrl = <?= json_encode(url('src/API/client_lookup.php'), JSON_UNESCAPED_SLASHES) ?>;
            const clientGoogleAuthUrl = <?= json_encode($clientGoogleAuthApi, JSON_UNESCAPED_SLASHES) ?>;
            const googleClientId = <?= json_encode($googleClientId, JSON_UNESCAPED_SLASHES) ?>;
            const bookingGuestKey = 'agenduy-booking-guest-' + commerceSlug;
            let lookupTimer = null;
            let lookupInFlight = false;
            let lastLookupKey = '';
            const availabilityUrl = <?= json_encode($availabilityApi, JSON_UNESCAPED_SLASHES) ?>;
            const maxDaysAhead = <?= (int)$maxDiasAdelante ?>;
            const stepForm = document.getElementById('booking-step-form');
            const stepUpsell = document.getElementById('booking-step-upsell');
            const footForm = document.getElementById('booking-foot-form');
            const footUpsell = document.getElementById('booking-foot-upsell');
            const bookingTitle = document.getElementById('booking-title');
            let slotsRequestId = 0;
            let bookingCalendar = <?= json_encode($bookingCalendar, JSON_UNESCAPED_UNICODE) ?>;
            let datePicker = null;
            let suppressDateChange = false;
            let currentBookingServicePrice = 0;
            const bookingSubmitLabel = submitBtn ? submitBtn.innerHTML : 'Confirmar reserva';
            const bookingPayLabel = bookingPayBtn ? bookingPayBtn.innerHTML : 'Pagar y reservar';

            function ymdLocal(date) {
                const pad = (n) => String(n).padStart(2, '0');
                return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate());
            }

            function normalizeCalendar(calendar) {
                const openDates = Array.isArray(calendar && calendar.open_dates)
                    ? calendar.open_dates.filter((d) => /^\d{4}-\d{2}-\d{2}$/.test(String(d)))
                    : [];
                const closedDates = Array.isArray(calendar && calendar.closed_dates)
                    ? calendar.closed_dates.filter((d) => /^\d{4}-\d{2}-\d{2}$/.test(String(d)))
                    : [];
                const openWeekdays = Array.isArray(calendar && calendar.open_weekdays)
                    ? calendar.open_weekdays.map((n) => Number(n)).filter((n) => n >= 0 && n <= 6)
                    : [];
                const nextOpen = calendar && typeof calendar.next_open_date === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(calendar.next_open_date)
                    ? calendar.next_open_date
                    : (openDates[0] || null);
                return {
                    open_dates: openDates,
                    closed_dates: closedDates,
                    open_weekdays: openWeekdays,
                    next_open_date: nextOpen,
                };
            }

            bookingCalendar = normalizeCalendar(bookingCalendar);

            function preferredBookingDate(limits) {
                const cal = bookingCalendar;
                if (cal.next_open_date) return cal.next_open_date;
                if (cal.open_dates.length) return cal.open_dates[0];
                if (limits && limits.min_date) return limits.min_date;
                return ymdLocal(new Date());
            }

            function applyDateLimits(limits, calendar) {
                if (calendar) {
                    bookingCalendar = normalizeCalendar(calendar);
                }
                const today = new Date();
                today.setHours(0, 0, 0, 0);
                let minDate = today;
                let maxDate = new Date(today);
                maxDate.setDate(maxDate.getDate() + maxDaysAhead);
                if (limits && limits.min_date) {
                    const parsed = new Date(limits.min_date + 'T00:00:00');
                    if (!Number.isNaN(parsed.getTime())) minDate = parsed;
                }
                if (limits && limits.max_date) {
                    const parsed = new Date(limits.max_date + 'T00:00:00');
                    if (!Number.isNaN(parsed.getTime())) maxDate = parsed;
                }

                const enableDates = bookingCalendar.open_dates.length
                    ? bookingCalendar.open_dates
                    : [];
                const defaultDate = preferredBookingDate(limits);
                const current = dateInput.value;
                const selected = (current && enableDates.includes(current))
                    ? current
                    : defaultDate;

                if (datePicker) {
                    suppressDateChange = true;
                    datePicker.set('minDate', minDate);
                    datePicker.set('maxDate', maxDate);
                    datePicker.set('enable', enableDates.length ? enableDates : (selected ? [selected] : []));
                    if (selected) {
                        datePicker.setDate(selected, false);
                    } else {
                        datePicker.clear();
                    }
                    suppressDateChange = false;
                    return;
                }

                if (typeof flatpickr !== 'function') {
                    dateInput.removeAttribute('readonly');
                    dateInput.type = 'date';
                    dateInput.min = ymdLocal(minDate);
                    dateInput.max = ymdLocal(maxDate);
                    dateInput.value = selected || '';
                    return;
                }

                datePicker = flatpickr(dateInput, {
                    locale: (flatpickr.l10ns && flatpickr.l10ns.es) ? flatpickr.l10ns.es : 'default',
                    dateFormat: 'Y-m-d',
                    altInput: true,
                    altFormat: 'd/m/Y',
                    allowInput: false,
                    disableMobile: true,
                    minDate: minDate,
                    maxDate: maxDate,
                    enable: enableDates.length ? enableDates : (selected ? [selected] : []),
                    defaultDate: selected || undefined,
                    onChange: function () {
                        if (suppressDateChange) return;
                        loadSlots();
                    },
                });
            }

            function setTimeHint(text, isError) {
                if (!timeHint) return;
                const value = String(text || '').trim();
                if (!value) {
                    timeHint.hidden = true;
                    timeHint.textContent = '';
                    timeHint.className = 'hint';
                    return;
                }
                timeHint.hidden = false;
                timeHint.textContent = value;
                timeHint.className = isError ? 'hint hint--warn' : 'hint';
            }

            function resetTimeSelect(placeholder) {
                timeSelect.innerHTML = '';
                const opt = document.createElement('option');
                opt.value = '';
                opt.textContent = placeholder || 'Elegí un horario';
                timeSelect.appendChild(opt);
                timeSelect.value = '';
                timeSelect.disabled = true;
            }

            async function loadSlots() {
                const reqId = ++slotsRequestId;
                const fecha = dateInput.value;
                const idService = svcIdInput.value;
                if (!fecha) {
                    resetTimeSelect('Elegí una fecha');
                    setTimeHint('');
                    return;
                }
                resetTimeSelect('Cargando horarios...');
                setTimeHint('');
                try {
                    const params = new URLSearchParams({
                        slug: commerceSlug,
                        fecha: fecha,
                    });
                    if (idService) params.set('id_service', idService);
                    const res = await fetch(availabilityUrl + '?' + params.toString(), { cache: 'no-store' });
                    const json = await res.json();
                    if (reqId !== slotsRequestId) return;
                    if (!json || json.ok === false) {
                        throw new Error((json && json.error) || 'No se pudieron cargar los horarios.');
                    }
                    const nextOpen = json.calendar && json.calendar.next_open_date
                        ? json.calendar.next_open_date
                        : null;
                    // Si eligieron un día cerrado (fallback nativo), saltar al próximo abierto.
                    if (json.closed && nextOpen && nextOpen !== fecha) {
                        applyDateLimits(json.limits || null, json.calendar || null);
                        if (datePicker) {
                            suppressDateChange = true;
                            datePicker.setDate(nextOpen, false);
                            suppressDateChange = false;
                        } else {
                            dateInput.value = nextOpen;
                        }
                        await loadSlots();
                        return;
                    }
                    applyDateLimits(json.limits || null, json.calendar || null);
                    const slots = Array.isArray(json.slots) ? json.slots : [];
                    timeSelect.innerHTML = '';
                    const placeholder = document.createElement('option');
                    placeholder.value = '';
                    if (slots.length === 0) {
                        placeholder.textContent = 'Sin horarios disponibles';
                        timeSelect.appendChild(placeholder);
                        timeSelect.disabled = true;
                        setTimeHint(
                            json.closed
                                ? 'Ese día el comercio está cerrado. Probá otra fecha.'
                                : 'No hay turnos libres para esa fecha. Probá otro día.',
                            true
                        );
                        return;
                    }
                    placeholder.textContent = 'Elegí un horario';
                    timeSelect.appendChild(placeholder);
                    slots.forEach((slot) => {
                        const opt = document.createElement('option');
                        opt.value = slot;
                        opt.textContent = slot;
                        timeSelect.appendChild(opt);
                    });
                    timeSelect.disabled = false;
                    setTimeHint(slots.length + ' horario' + (slots.length === 1 ? '' : 's') + ' disponible' + (slots.length === 1 ? '' : 's'));
                } catch (err) {
                    if (reqId !== slotsRequestId) return;
                    resetTimeSelect('Sin horarios');
                    setTimeHint(err.message || 'No se pudieron cargar los horarios.', true);
                }
            }

            function showBookingFormStep() {
                if (stepForm) stepForm.hidden = false;
                if (stepUpsell) stepUpsell.hidden = true;
                if (footForm) footForm.hidden = false;
                if (footUpsell) footUpsell.hidden = true;
                if (bookingTitle) bookingTitle.textContent = 'Reservar turno';
                syncBookingPaymentUI();
            }

            function setBookingPaymentMethod(method) {
                const normalized = method === 'mercadopago' ? 'mercadopago' : 'manual';
                if (bookingPaymentMethodInput) {
                    bookingPaymentMethodInput.value = normalized;
                }
            }

            function syncBookingPaymentUI() {
                const canPayOnline = bookingMpCheckoutEnabled && currentBookingServicePrice > 0;
                if (!canPayOnline) {
                    setBookingPaymentMethod('manual');
                } else if (bookingMpRequired) {
                    setBookingPaymentMethod('mercadopago');
                }
                if (bookingPaymentBox) {
                    bookingPaymentBox.hidden = !canPayOnline;
                }
                if (bookingPaymentAmount) {
                    bookingPaymentAmount.textContent = canPayOnline ? formatMoney(currentBookingServicePrice) : '';
                }
                if (bookingPaymentRequired) {
                    bookingPaymentRequired.hidden = !(canPayOnline && bookingMpRequired);
                }
                if (bookingPayBtn) {
                    bookingPayBtn.hidden = !canPayOnline;
                }
                if (submitBtn) {
                    submitBtn.hidden = canPayOnline && bookingMpRequired;
                }
            }

            function setBookingSubmitting(isSubmitting, paymentMethod) {
                [submitBtn, bookingPayBtn].forEach((btn) => {
                    if (btn) btn.disabled = isSubmitting;
                });
                if (isSubmitting) {
                    if (paymentMethod === 'mercadopago' && bookingPayBtn) {
                        bookingPayBtn.innerHTML = '<span class="spinner"></span> Redirigiendo...';
                    } else if (submitBtn) {
                        submitBtn.innerHTML = '<span class="spinner"></span> Enviando...';
                    }
                    return;
                }
                if (submitBtn) submitBtn.innerHTML = bookingSubmitLabel;
                if (bookingPayBtn) bookingPayBtn.innerHTML = bookingPayLabel;
                syncBookingPaymentUI();
            }

            function renderUpsellGrid() {
                const grid = document.getElementById('booking-upsell-grid');
                if (!grid) return;
                grid.innerHTML = productsCatalog.map(p => {
                    return '<article class="upsell-card">' +
                        '<div class="upsell-card__body">' +
                          '<strong>' + escapeHtml(p.name) + '</strong>' +
                          (p.tipo ? '<span class="prod-card__type">' + escapeHtml(p.tipo) + '</span>' : '') +
                          '<span class="upsell-card__price">' + formatMoney(p.price) + '</span>' +
                        '</div>' +
                        '<button type="button" class="btn btn--ghost btn--sm" data-upsell-add="' + escapeHtml(p.id) + '">' +
                          '<i class="bx bx-cart-add" aria-hidden="true"></i> Agregar' +
                        '</button>' +
                      '</article>';
                }).join('');
                grid.querySelectorAll('[data-upsell-add]').forEach(btn => {
                    btn.addEventListener('click', () => {
                        const id = btn.getAttribute('data-upsell-add');
                        const p = productsCatalog.find(x => String(x.id) === String(id));
                        if (!p) return;
                        addToCart(p.id, p.name, p.price, 1);
                        btn.innerHTML = '<i class="bx bx-check" aria-hidden="true"></i> Agregado';
                        setTimeout(() => {
                            btn.innerHTML = '<i class="bx bx-cart-add" aria-hidden="true"></i> Agregar';
                        }, 1000);
                    });
                });
            }

            function loadSavedGuest() {
                try {
                    const raw = localStorage.getItem(bookingGuestKey);
                    const parsed = raw ? JSON.parse(raw) : null;
                    if (!parsed || typeof parsed !== 'object') return null;
                    return parsed;
                } catch (_) {
                    return null;
                }
            }

            function saveGuest(data) {
                if (!data) return;
                const previous = loadSavedGuest() || {};
                const hasCedula = Object.prototype.hasOwnProperty.call(data, 'cedula');
                const payload = {
                    nombre: String(data.nombre || '').trim(),
                    email: String(data.email || '').trim(),
                    telefono: String(data.telefono || '').trim(),
                    cedula: hasCedula ? String(data.cedula || '').trim() : String(previous.cedula || '').trim(),
                };
                if (!payload.nombre && !payload.email && !payload.telefono && !payload.cedula) {
                    localStorage.removeItem(bookingGuestKey);
                    return;
                }
                localStorage.setItem(bookingGuestKey, JSON.stringify(payload));
            }

            function currentBookingGuest() {
                return {
                    nombre: String(bookingNameInput?.value || '').trim(),
                    email: String(bookingEmailInput?.value || '').trim(),
                    telefono: String(bookingPhoneInput?.value || '').trim(),
                    cedula: String(bookingCedulaInput?.value || '').trim(),
                };
            }

            let saveBookingGuestTimer = null;
            function scheduleSaveBookingGuest() {
                clearTimeout(saveBookingGuestTimer);
                saveBookingGuestTimer = setTimeout(() => saveGuest(currentBookingGuest()), 250);
            }

            function setLookupHint(text, isError) {
                if (!bookingLookupHint) return;
                const value = String(text || '').trim();
                if (!value) {
                    bookingLookupHint.hidden = true;
                    bookingLookupHint.textContent = '';
                    bookingLookupHint.className = 'hint';
                    return;
                }
                bookingLookupHint.textContent = value;
                bookingLookupHint.hidden = false;
                bookingLookupHint.className = isError ? 'hint hint--error' : 'hint hint--ok';
            }

            function applyGuestFields(data, source) {
                if (!data) return;
                const fillIfEmpty = (el, value) => {
                    if (!el || !value) return;
                    if (source === 'lookup' || !String(el.value || '').trim()) {
                        el.value = value;
                    }
                };
                fillIfEmpty(bookingNameInput, String(data.nombre || '').trim());
                fillIfEmpty(bookingEmailInput, String(data.email || '').trim());
                fillIfEmpty(bookingCedulaInput, String(data.cedula || '').trim());
                fillIfEmpty(bookingPhoneInput, String(data.telefono || '').trim());
                if (source === 'lookup' && String(data.nombre || '').trim()) {
                    setLookupHint('Datos completados automáticamente.', false);
                }
            }

            async function lookupClient() {
                const cedula = String(bookingCedulaInput?.value || '').replace(/\D/g, '');
                const email = String(bookingEmailInput?.value || '').trim();
                const telefono = String(bookingPhoneInput?.value || '').trim();
                const phoneDigits = telefono.replace(/\D/g, '');
                const emailOk = email.includes('@') && email.length > 5;
                if (cedula.length < 7 && !emailOk && phoneDigits.length < 8) {
                    setLookupHint('');
                    return;
                }

                const key = cedula + '|' + email.toLowerCase() + '|' + phoneDigits;
                if (key === lastLookupKey || lookupInFlight) return;
                lastLookupKey = key;
                lookupInFlight = true;

                try {
                    const res = await fetch(clientLookupUrl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-Token': csrfToken,
                        },
                        body: JSON.stringify({
                            slug: commerceSlug,
                            cedula,
                            email: emailOk ? email : '',
                            telefono,
                            _csrf: csrfToken,
                        }),
                    });
                    const json = await res.json();
                    if (json && json.found) {
                        applyGuestFields(json, 'lookup');
                        saveGuest(json);
                        if (json.historial) {
                            renderClientHistory(json.historial);
                        }
                    } else if (json && json.ok) {
                        setLookupHint('');
                        hideClientHistory();
                    }
                } catch (_) {
                    // Silencioso: el usuario puede seguir completando manualmente.
                } finally {
                    lookupInFlight = false;
                }
            }

            function scheduleClientLookup() {
                clearTimeout(lookupTimer);
                lookupTimer = setTimeout(() => lookupClient(), 450);
            }

            // --- Google "Continuar con Google" (clientes de tiendas) ---
            let googleAuthInited = false;

            function initGoogleClientAuth() {
                if (!googleClientId || !window.google || !window.google.accounts || !window.google.accounts.id) {
                    return;
                }
                if (googleAuthInited) {
                    renderGoogleAuthButtons();
                    return;
                }
                googleAuthInited = true;
                window.google.accounts.id.initialize({
                    client_id: googleClientId,
                    callback: handleGoogleCredential,
                    auto_select: false,
                    cancel_on_tap_outside: true,
                    context: 'signin',
                    ux_mode: 'popup',
                    itp_support: true,
                });
                renderGoogleAuthButtons();
            }

            function renderGoogleAuthButtons() {
                renderGoogleButtonInto(document.getElementById('booking-google-wrap'), document.getElementById('booking-google-btn'));
                renderGoogleButtonInto(document.getElementById('cart-google-wrap'), document.getElementById('cart-google-btn'));
            }

            function renderGoogleButtonInto(wrap, container) {
                if (!wrap || !container || !googleClientId) return;
                if (!window.google || !window.google.accounts || !window.google.accounts.id) return;
                wrap.hidden = false;
                if (container.dataset.rendered === '1') return;
                container.dataset.rendered = '1';
                window.google.accounts.id.renderButton(container, {
                    type: 'standard',
                    theme: 'outline',
                    size: 'large',
                    text: 'continue_with',
                    width: Math.min(container.offsetWidth || 300, 340),
                    locale: 'es',
                });
            }

            const cartGoogleHint = document.getElementById('cart-google-hint');

            function setGoogleStatus(text, isError) {
                const bookingHint = document.getElementById('booking-lookup-hint');
                if (cartModal && cartModal.classList.contains('is-open') && cartGoogleHint) {
                    cartGoogleHint.textContent = String(text || '');
                    cartGoogleHint.hidden = !text;
                    cartGoogleHint.className = isError ? 'hint hint--error' : 'hint hint--ok';
                } else if (bookingHint) {
                    setLookupHint(text, !!isError);
                }
            }

            async function handleGoogleCredential(response) {
                const token = response && response.credential ? response.credential : '';
                if (!token) {
                    setGoogleStatus('No se recibió respuesta de Google.', true);
                    return;
                }
                setGoogleStatus('Verificando tu cuenta de Google...', false);
                try {
                    const res = await fetch(clientGoogleAuthUrl, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        credentials: 'same-origin',
                        body: JSON.stringify({ credential: token, slug: commerceSlug, _csrf: csrfToken }),
                    });
                    const data = await res.json();
                    if (!res.ok || !data || data.ok === false) {
                        setGoogleStatus((data && data.error) || 'No se pudo iniciar sesión con Google.', true);
                        return;
                    }
                    const profile = data.profile || {};
                    const fillIfEmpty = (el, value) => {
                        if (!el || !value) return;
                        if (!String(el.value || '').trim()) el.value = value;
                    };
                    fillIfEmpty(bookingNameInput, profile.nombre);
                    fillIfEmpty(bookingEmailInput, profile.email);
                    fillIfEmpty(bookingPhoneInput, profile.telefono);
                    fillIfEmpty(cartCustomerName, profile.nombre);
                    fillIfEmpty(cartCustomerEmail, profile.email);
                    fillIfEmpty(cartCustomerPhone, profile.telefono);
                    setGoogleStatus(
                        data.found
                            ? '¡Hola de nuevo! Cargamos tu historial de reservas y pedidos.'
                            : '¡Registrado con Google! Datos cargados automáticamente.',
                        false
                    );
                    if (data.found) {
                        renderClientHistory(data.historial || null);
                    }
                    if (!(cartModal && cartModal.classList.contains('is-open'))) {
                        scheduleClientLookup();
                    }
                } catch (e) {
                    setGoogleStatus('Error de conexión. Intentá de nuevo.', true);
                }
            }

            const APPT_STATUS_LABELS = {
                pending: 'Pendiente',
                confirmed: 'Confirmada',
                in_progress: 'Atendiendo',
                cancelled: 'Cancelada',
                done: 'Realizada',
                no_show: 'No asistió',
            };

            function formatHistDate(v) {
                const m = String(v || '').match(/^(\d{4})-(\d{2})-(\d{2})/);
                return m ? m[3] + '/' + m[2] + '/' + m[1] : String(v || '');
            }

            function renderClientHistory(historial) {
                const bookingPanel = document.getElementById('booking-history');
                const cartPanel = document.getElementById('cart-history');
                if (!bookingPanel && !cartPanel) return;
                const data = historial || {};
                const reservas = Array.isArray(data.reservas) ? data.reservas : [];
                const pedidos = Array.isArray(data.pedidos) ? data.pedidos : [];
                const hasAny = reservas.length > 0 || pedidos.length > 0;
                let html = '';
                if (reservas.length) {
                    html += '<p class="client-history__group">Reservas</p><ul class="client-history__list">';
                    reservas.forEach(r => {
                        const when = formatHistDate(r.fecha) + (r.hora ? ' · ' + String(r.hora).slice(0, 5) : '');
                        const label = APPT_STATUS_LABELS[r.status] || r.status || '';
                        html += '<li class="client-history__item">'
                            + '<span class="client-history__when">' + escapeHtml(when) + '</span>'
                            + '<span class="client-history__what">' + escapeHtml(r.servicio || 'Turno') + '</span>'
                            + '<span class="client-history__badge">' + escapeHtml(label) + '</span>'
                            + '</li>';
                    });
                    html += '</ul>';
                }
                if (pedidos.length) {
                    html += '<p class="client-history__group">Pedidos</p><ul class="client-history__list">';
                    pedidos.forEach(p => {
                        const when = formatHistDate(p.fecha) + (p.hora ? ' · ' + String(p.hora).slice(0, 5) : '');
                        const total = p.total ? ' · $' + escapeHtml(p.total) : '';
                        html += '<li class="client-history__item">'
                            + '<span class="client-history__when">' + escapeHtml(when) + '</span>'
                            + '<span class="client-history__what">Pedido #' + escapeHtml(String(p.id ?? '')) + total + '</span>'
                            + '<span class="client-history__badge">' + escapeHtml(p.status || '') + '</span>'
                            + '</li>';
                    });
                    html += '</ul>';
                }
                if (!hasAny) {
                    html = '<p class="client-history__empty">Todavía no tenés reservas ni pedidos en este negocio.</p>';
                }
                if (bookingPanel) {
                    const body = document.getElementById('booking-history-body');
                    if (body) body.innerHTML = html;
                    bookingPanel.hidden = false;
                }
                if (cartPanel) {
                    const body = document.getElementById('cart-history-body');
                    if (body) body.innerHTML = html;
                    cartPanel.hidden = false;
                }
            }

            function hideClientHistory() {
                ['booking-history', 'cart-history'].forEach(id => {
                    const panel = document.getElementById(id);
                    if (panel) panel.hidden = true;
                });
            }

            function loadGoogleClientScript() {
                if (!googleClientId || (window.google && window.google.accounts && window.google.accounts.id)) {
                    initGoogleClientAuth();
                    return;
                }
                if (document.querySelector('script[data-storefront-gis]')) return;
                const script = document.createElement('script');
                script.src = 'https://accounts.google.com/gsi/client';
                script.async = true;
                script.defer = true;
                script.setAttribute('data-storefront-gis', '1');
                script.onload = initGoogleClientAuth;
                document.head.appendChild(script);
            }
            loadGoogleClientScript();

            function prefillBookingGuest() {
                const guest = loadSavedGuest();
                applyGuestFields(guest, 'local');
                if (guest && (guest.cedula || guest.email || guest.telefono)) {
                    scheduleClientLookup();
                }
            }

            [bookingNameInput, bookingEmailInput, bookingCedulaInput, bookingPhoneInput].forEach((input) => {
                if (!input) return;
                input.addEventListener('input', scheduleSaveBookingGuest);
                input.addEventListener('change', scheduleSaveBookingGuest);
            });
            if (bookingEmailInput) {
                bookingEmailInput.addEventListener('input', scheduleClientLookup);
                bookingEmailInput.addEventListener('blur', lookupClient);
            }
            if (bookingCedulaInput) {
                bookingCedulaInput.addEventListener('input', scheduleClientLookup);
                bookingCedulaInput.addEventListener('blur', lookupClient);
            }
            if (bookingPhoneInput) {
                bookingPhoneInput.addEventListener('input', scheduleClientLookup);
                bookingPhoneInput.addEventListener('blur', lookupClient);
            }

            function showUpsellStep(booking) {
                lastBooking = booking;
                if (!hasProducts || !stepUpsell) {
                    alertBox.className = 'alert alert--ok';
                    alertBox.innerHTML = '<i class="bx bx-check-circle"></i> Listo. Te enviamos la confirmacion por el medio que ingresaste.';
                    alertBox.hidden = false;
                    setTimeout(closeModal, 2500);
                    return;
                }
                if (stepForm) stepForm.hidden = true;
                stepUpsell.hidden = false;
                if (footForm) footForm.hidden = true;
                if (footUpsell) footUpsell.hidden = false;
                if (bookingTitle) bookingTitle.textContent = '¿Desea productos?';
                renderUpsellGrid();
                renderCartUI();
            }

            function openModal(svcId, svcName, svcPrice) {
                alertBox.hidden = true;
                form.reset();
                svcIdInput.value = svcId;
                svcNameInput.value = svcName;
                currentBookingServicePrice = Math.max(0, Number(svcPrice) || 0);
                setBookingPaymentMethod('manual');
                applyDateLimits(null, bookingCalendar);
                const defaultDate = preferredBookingDate(null);
                if (datePicker) {
                    suppressDateChange = true;
                    datePicker.setDate(defaultDate, false);
                    suppressDateChange = false;
                } else {
                    dateInput.value = defaultDate;
                }
                resetTimeSelect('Cargando horarios...');
                setTimeHint('');
                showBookingFormStep();
                lastBooking = null;
                lastLookupKey = '';
                setLookupHint('');
                hideClientHistory();
                prefillBookingGuest();
                modal.classList.add('is-open');
                renderGoogleAuthButtons();
                loadSlots();
                const focusEl = (bookingEmailInput && bookingEmailInput.value)
                    ? bookingEmailInput
                    : bookingNameInput;
                setTimeout(() => focusEl?.focus(), 200);
            }
            function closeModal() {
                saveGuest(currentBookingGuest());
                modal.classList.remove('is-open');
                showBookingFormStep();
            }

            document.querySelectorAll('[data-book]').forEach(btn => {
                btn.addEventListener('click', e => {
                    const card = e.target.closest('.svc-card');
                    openModal(card.dataset.svcId, card.dataset.svcName, card.dataset.svcPrice);
                });
            });
            document.querySelectorAll('[data-booking-submit]').forEach(btn => {
                btn.addEventListener('click', () => {
                    setBookingPaymentMethod(btn.getAttribute('data-booking-submit'));
                });
            });
            document.querySelectorAll('[data-close-modal]').forEach(btn => {
                btn.addEventListener('click', closeModal);
            });
            modal.addEventListener('click', e => { if (e.target === modal) closeModal(); });
            document.addEventListener('keydown', e => {
                if (e.key !== 'Escape') return;
                if (cartModal && cartModal.classList.contains('is-open')) {
                    closeCartModal();
                    return;
                }
                closeModal();
            });
            dateInput.addEventListener('change', () => {
                if (datePicker || suppressDateChange) return;
                loadSlots();
            });
            applyDateLimits(null, bookingCalendar);

            const upsellSkip = document.getElementById('booking-upsell-skip');
            const upsellWaBtn = document.getElementById('booking-upsell-wa');
            if (upsellSkip) upsellSkip.addEventListener('click', closeModal);
            if (upsellWaBtn) {
                upsellWaBtn.addEventListener('click', () => {
                    const items = loadCart();
                    const booking = lastBooking || {};
                    if (items.length === 0 && !confirm('No agregaste productos. ¿Enviar igual la reserva por WhatsApp?')) {
                        return;
                    }
                    finalizePurchase({
                        waText: buildBookingWaMessage(booking, items),
                        requireItems: false,
                        meta: {
                            note: 'Post-reserva',
                            nombre: booking.nombre || '',
                            email: booking.email || '',
                            telefono: booking.telefono || '',
                            appointment_id: booking.id || ''
                        },
                        afterSuccess: () => closeModal()
                    });
                });
            }

            const cancelAppointmentModal = document.getElementById('cancel-appointment-modal');
            const cancelAppointmentForm = document.getElementById('cancel-appointment-form');
            const cancelAppointmentAlert = document.getElementById('cancel-appointment-alert');
            const cancelAppointmentSubmit = document.getElementById('cancel-appointment-submit');
            const cancelAppointmentId = document.getElementById('cancel-appointment-id');
            const cancelAppointmentCedula = document.getElementById('cancel-appointment-cedula');

            function openCancelAppointmentModal(prefillId = '') {
                if (!cancelAppointmentModal) return;
                if (cancelAppointmentId && prefillId) cancelAppointmentId.value = String(prefillId).replace(/\D/g, '');
                if (cancelAppointmentAlert) {
                    cancelAppointmentAlert.hidden = true;
                    cancelAppointmentAlert.innerHTML = '';
                }
                cancelAppointmentModal.classList.add('is-open');
                setTimeout(() => (cancelAppointmentId || cancelAppointmentCedula)?.focus(), 50);
            }

            function closeCancelAppointmentModal() {
                if (cancelAppointmentModal) cancelAppointmentModal.classList.remove('is-open');
            }

            document.querySelectorAll('[data-open-cancel-appointment]').forEach(btn => {
                btn.addEventListener('click', () => openCancelAppointmentModal());
            });
            document.querySelectorAll('[data-close-cancel-appointment]').forEach(btn => {
                btn.addEventListener('click', closeCancelAppointmentModal);
            });
            if (cancelAppointmentModal) {
                cancelAppointmentModal.addEventListener('click', e => {
                    if (e.target === cancelAppointmentModal) closeCancelAppointmentModal();
                });
            }

            const cancelFromUrl = new URLSearchParams(window.location.search).get('cancel_reserva') || '';
            if (cancelFromUrl) {
                openCancelAppointmentModal(cancelFromUrl);
            }

            async function submitCancelAppointment() {
                if (!cancelAppointmentForm) return null;
                const body = new FormData(cancelAppointmentForm);
                body.set('_csrf', csrfToken);
                const res = await fetch(cancelAppointmentUrl, {
                    method: 'POST',
                    body,
                    credentials: 'same-origin'
                });
                let json = null;
                try { json = await res.json(); } catch (_) {}
                return json || { ok: false, error: 'Respuesta inválida del servidor (' + res.status + ').' };
            }

            if (cancelAppointmentForm) {
                cancelAppointmentForm.addEventListener('submit', async e => {
                    e.preventDefault();
                    const id = String(cancelAppointmentId?.value || '').replace(/\D/g, '');
                    const cedula = String(cancelAppointmentCedula?.value || '').replace(/\D/g, '');
                    if (!id || cedula.length < 7) {
                        if (cancelAppointmentAlert) {
                            cancelAppointmentAlert.className = 'alert alert--error';
                            cancelAppointmentAlert.innerHTML = '<i class="bx bx-error-circle"></i> Ingresá número de reserva y cédula.';
                            cancelAppointmentAlert.hidden = false;
                        }
                        return;
                    }
                    if (cancelAppointmentSubmit) {
                        cancelAppointmentSubmit.disabled = true;
                        cancelAppointmentSubmit.innerHTML = '<span class="spinner"></span> Cancelando...';
                    }
                    if (cancelAppointmentAlert) cancelAppointmentAlert.hidden = true;
                    try {
                        let json = await submitCancelAppointment();
                        if (json && json.error === 'csrf_retry' && json.csrf) {
                            syncCsrfToken(json.csrf);
                            json = await submitCancelAppointment();
                        }
                        if (!json || !json.ok) {
                            throw new Error((json && json.error) ? json.error : 'No se pudo cancelar la reserva.');
                        }
                        cancelAppointmentForm.reset();
                        if (cancelAppointmentAlert) {
                            cancelAppointmentAlert.className = 'alert alert--ok';
                            cancelAppointmentAlert.innerHTML = '<i class="bx bx-check-circle"></i> Reserva cancelada. Te enviamos el aviso por email y WhatsApp si tus datos estaban cargados.';
                            cancelAppointmentAlert.hidden = false;
                        }
                    } catch (err) {
                        if (cancelAppointmentAlert) {
                            cancelAppointmentAlert.className = 'alert alert--error';
                            cancelAppointmentAlert.innerHTML = '<i class="bx bx-error-circle"></i> ' + (err.message || 'No se pudo cancelar la reserva.');
                            cancelAppointmentAlert.hidden = false;
                        }
                    } finally {
                        if (cancelAppointmentSubmit) {
                            cancelAppointmentSubmit.disabled = false;
                            cancelAppointmentSubmit.innerHTML = 'Cancelar reserva';
                        }
                    }
                });
            }

            async function sendBooking() {
                const data = new FormData(form);
                data.set('_csrf', csrfToken);
                // Timeout duro: el botón nunca queda en "Enviando..." indefinidamente.
                const ctrl = new AbortController();
                const timer = setTimeout(() => ctrl.abort(), 15000);
                try {
                    const res = await fetch('<?= $apiBase ?>', {
                        method: 'POST',
                        body: data,
                        credentials: 'same-origin',
                        signal: ctrl.signal
                    });
                    let json = null;
                    try { json = await res.json(); } catch (_) {}
                    return json || { ok: false, error: 'Respuesta inválida del servidor (' + res.status + ').' };
                } finally {
                    clearTimeout(timer);
                }
            }

            form.addEventListener('submit', async e => {
                e.preventDefault();
                if (!timeSelect.value) {
                    alertBox.className = 'alert alert--error';
                    alertBox.innerHTML = '<i class="bx bx-error-circle"></i> Elegí un horario de la lista.';
                    alertBox.hidden = false;
                    return;
                }
                const bookingName = String(bookingNameInput?.value || '').trim().replace(/\s+/g, ' ');
                if (!/\S+\s+\S+/.test(bookingName)) {
                    alertBox.className = 'alert alert--error';
                    alertBox.innerHTML = '<i class="bx bx-error-circle"></i> Ingresa tu nombre y apellido.';
                    alertBox.hidden = false;
                    bookingNameInput?.focus();
                    return;
                }
                if (bookingNameInput) bookingNameInput.value = bookingName;
                const bookingCedula = String(bookingCedulaInput?.value || '').replace(/\D/g, '');
                if (bookingCedula.length < 7) {
                    alertBox.className = 'alert alert--error';
                    alertBox.innerHTML = '<i class="bx bx-error-circle"></i> Ingresá tu cédula (mínimo 7 dígitos) para poder cancelar tu reserva si lo necesitás.';
                    alertBox.hidden = false;
                    return;
                }
                const bookingEmail = String(bookingEmailInput?.value || '').trim();
                if (bookingEmail !== '' && !isValidEmail(bookingEmail)) {
                    alertBox.className = 'alert alert--error';
                    alertBox.innerHTML = '<i class="bx bx-error-circle"></i> Ingresa un email valido para enviarte la confirmacion.';
                    alertBox.hidden = false;
                    bookingEmailInput?.focus();
                    return;
                }
                const bookingPhoneDigits = String(bookingPhoneInput?.value || '').replace(/\D/g, '');
                if (String(bookingPhoneInput?.value || '').trim() !== '' && bookingPhoneDigits.length < 7) {
                    alertBox.className = 'alert alert--error';
                    alertBox.innerHTML = '<i class="bx bx-error-circle"></i> Ingresa un telefono valido para enviarte WhatsApp.';
                    alertBox.hidden = false;
                    bookingPhoneInput?.focus();
                    return;
                }
                if (bookingEmail === '' && bookingPhoneDigits.length < 7) {
                    alertBox.className = 'alert alert--error';
                    alertBox.innerHTML = '<i class="bx bx-error-circle"></i> Ingresa un email o un telefono para poder confirmarte la reserva.';
                    alertBox.hidden = false;
                    (bookingEmailInput || bookingPhoneInput)?.focus();
                    return;
                }
                const paymentMethod = bookingPaymentMethodInput && bookingPaymentMethodInput.value === 'mercadopago'
                    ? 'mercadopago'
                    : 'manual';
                setBookingSubmitting(true, paymentMethod);
                alertBox.hidden = true;

                try {
                    let json = await sendBooking();
                    // Token vencido o sesión nueva: el servidor manda uno fresco
                    // (HTTP 428) y reintentamos sin que el usuario vea el error.
                    if (json && json.error === 'csrf_retry' && json.csrf) {
                        syncCsrfToken(json.csrf);
                        json = await sendBooking();
                    }
                    if (!json.ok) {
                        const msg = (!json.error || json.error === 'csrf_retry')
                            ? 'No se pudo completar la reserva. Recargá la página e intentá de nuevo.'
                            : json.error;
                        throw new Error(msg);
                    }
                    if (json.payment_required && json.checkout_url) {
                        alertBox.className = 'alert alert--ok';
                        alertBox.innerHTML = '<i class="bx bx-check-circle"></i> Reserva creada. Te llevamos a Mercado Pago para completar el pago.';
                        alertBox.hidden = false;
                        window.location.href = json.checkout_url;
                        return;
                    }
                    const booking = {
                        id: json.id_appointment || '',
                        servicio: svcNameInput.value || '',
                        fecha: dateInput.value || '',
                        hora: timeSelect.value || '',
                        nombre: (bookingNameInput || {}).value || '',
                        email: (bookingEmailInput || {}).value || '',
                        cedula: (bookingCedulaInput || {}).value || '',
                        telefono: (bookingPhoneInput || {}).value || ''
                    };
                    saveGuest(booking);
                    lastLookupKey = '';
                    scheduleClientLookup();
                    showUpsellStep(booking);
                } catch (err) {
                    const msg = (err && err.name === 'AbortError')
                        ? 'La solicitud tardó demasiado. Verificá tu conexión e intentá de nuevo.'
                        : (err.message || 'Error al reservar');
                    alertBox.className = 'alert alert--error';
                    alertBox.innerHTML = '<i class="bx bx-error-circle"></i> ' + msg;
                    alertBox.hidden = false;
                    // Si el horario dejó de estar disponible, refrescar la lista.
                    if (/horario|disponible/i.test(msg)) {
                        loadSlots();
                    }
                } finally {
                    setBookingSubmitting(false, paymentMethod);
                }
            });
        })();
        </script>
        <script>
        // Product gallery carousel
        (function(){
            document.querySelectorAll('[data-gallery]').forEach(function(gallery) {
                var track = gallery.querySelector('[data-track]');
                var prev = gallery.querySelector('[data-dir="-1"]');
                var next = gallery.querySelector('[data-dir="1"]');
                var dots = gallery.querySelector('[data-dots]');
                var slides = track ? track.querySelectorAll('.prod-gallery__slide') : [];
                if (!track || slides.length < 2) return;

                var current = 0;
                var total = slides.length;

                function goTo(index) {
                    if (index < 0) index = total - 1;
                    if (index >= total) index = 0;
                    current = index;
                    track.style.transform = 'translateX(-' + (current * 100) + '%)';
                    slides.forEach(function(s, i) {
                        s.classList.toggle('is-active', i === current);
                    });
                    if (dots) {
                        dots.querySelectorAll('.prod-gallery__dot').forEach(function(d, i) {
                            d.classList.toggle('is-active', i === current);
                        });
                    }
                }

                if (prev) prev.addEventListener('click', function() { goTo(current - 1); });
                if (next) next.addEventListener('click', function() { goTo(current + 1); });

                if (dots) {
                    dots.querySelectorAll('.prod-gallery__dot').forEach(function(dot) {
                        dot.addEventListener('click', function() {
                            var idx = parseInt(this.getAttribute('data-slide'), 10);
                            if (!isNaN(idx)) goTo(idx);
                        });
                    });
                }

                // Auto-rotate every 5 seconds, pause on hover
                var autoTimer = setInterval(function() { goTo(current + 1); }, 5000);
                gallery.addEventListener('mouseenter', function() { clearInterval(autoTimer); });
                gallery.addEventListener('mouseleave', function() {
                    clearInterval(autoTimer);
                    autoTimer = setInterval(function() { goTo(current + 1); }, 5000);
                });
            });
        })();

        // PWA install prompt
        (function(){
            var installBtn = document.getElementById('install-app');
            if (!installBtn) return;

            var deferredPrompt = null;

            window.addEventListener('beforeinstallprompt', function(e) {
                e.preventDefault();
                deferredPrompt = e;
                installBtn.removeAttribute('hidden');
            });

            installBtn.addEventListener('click', function() {
                if (!deferredPrompt) {
                    window.alert('Para instalar esta app, abrí el menú del navegador y seleccioná "Agregar a pantalla de inicio" o "Instalar app".');
                    return;
                }
                deferredPrompt.prompt();
                deferredPrompt.userChoice.then(function(choiceResult) {
                    if (choiceResult.outcome === 'accepted') {
                        installBtn.setAttribute('hidden', '');
                    }
                    deferredPrompt = null;
                });
            });

            // Service worker registration
            if ('serviceWorker' in navigator) {
                var swUrl = <?= json_encode(url('sw.js'), JSON_UNESCAPED_SLASHES) ?>;
                window.addEventListener('load', function() {
                    navigator.serviceWorker.register(swUrl, { scope: '/' }).then(function(reg) {
                        // registered
                    }).catch(function(err) {
                        // service worker not available
                    });
                });
            }
        })();
        </script>
        </body>
        </html>
        <?php
    }
}
