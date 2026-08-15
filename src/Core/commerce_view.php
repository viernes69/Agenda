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
use Agenduy\Core\AdminBrand;
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
use Agenduy\Core\ProductCatalog;

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
        if (!headers_sent()) {
            header('Cache-Control: no-cache, max-age=0, must-revalidate');
            header('Pragma: no-cache');
            header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
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
        $publicContent = CommerceSettings::get($commerceId, 'public_content', CommerceSettings::defaultsForSection('public_content'));
        $publicTextOverrides = is_array($publicContent['text'] ?? null) ? $publicContent['text'] : [];
        $publicImageOverrides = is_array($publicContent['images'] ?? null) ? $publicContent['images'] : [];
        $publicContentVersion = trim((string)($publicContent['version'] ?? ''));
        if ($publicContentVersion === '') {
            $publicContentVersion = sha1(json_encode([
                'text' => $publicTextOverrides,
                'images' => $publicImageOverrides,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
        }
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
        $showProducts = ($isStoreMode || !empty($funciones['productos'])) && $localProducts !== [];
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
        $swPath = dirname(__DIR__, 2) . '/sw.js';
        $swVer = is_file($swPath) ? (string)filemtime($swPath) : (string)time();
        $manifestPath = dirname(__DIR__, 2) . '/template/manifest.webmanifest';
        $manifestVer = is_file($manifestPath) ? (string)filemtime($manifestPath) : (string)time();

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
                $media = ProductCatalog::mediaForRow($pRow);
                $discount = ProductCatalog::discountPercent($pRow);
                $basePrice = ProductCatalog::basePrice($pRow);
                $variants = [];
                foreach ($media as $mediaItem) {
                    $rawPrice = ($mediaItem['price'] ?? null) !== null ? (float)$mediaItem['price'] : $basePrice;
                    $variants[] = [
                        'index' => (int)($mediaItem['index'] ?? count($variants)),
                        'label' => (string)($mediaItem['label'] ?? ''),
                        'price' => ProductCatalog::effectivePrice($rawPrice, $discount),
                        'originalPrice' => round($rawPrice, 2),
                    ];
                }
                if ($variants === []) {
                    $variants[] = [
                        'index' => 0,
                        'label' => '',
                        'price' => ProductCatalog::effectivePrice($basePrice, $discount),
                        'originalPrice' => round($basePrice, 2),
                    ];
                }
                $productsForJs[] = [
                    'id' => (string)($pRow['ID_Product'] ?? ''),
                    'name' => trim((string)($pRow['Nombre'] ?? 'Producto')),
                    'tipo' => trim((string)($pRow['Tipo'] ?? '')),
                    'price' => (float)$variants[0]['price'],
                    'originalPrice' => (float)$variants[0]['originalPrice'],
                    'discountPercent' => $discount,
                    'saleLabel' => ProductCatalog::saleLabel($pRow),
                    'variants' => $variants,
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

        $publicTextValue = static function (string $key, string $default) use ($publicTextOverrides): string {
            $value = $publicTextOverrides[$key] ?? null;
            if (is_scalar($value) && trim((string)$value) !== '') {
                return trim((string)$value);
            }
            return $default;
        };
        $publicImageValue = static function (string $key, string $default = '') use ($publicImageOverrides): string {
            $value = $publicImageOverrides[$key] ?? null;
            if (is_scalar($value) && trim((string)$value) !== '') {
                return trim((string)$value);
            }
            return $default;
        };
        $editableText = static function (string $key, string $default, string $label) use ($publicTextValue, $isCommerceOwner): string {
            $value = $publicTextValue($key, $default);
            $html = '<span class="public-editable" data-public-edit-field data-public-edit-type="text" data-public-edit-key="'
                . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '" data-public-edit-label="'
                . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '"><span data-public-edit-value>'
                . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '</span>';
            if ($isCommerceOwner) {
                $html .= '<button type="button" class="public-edit-btn" data-public-edit-trigger aria-label="Editar '
                    . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '"><i class="bx bx-pencil" aria-hidden="true"></i></button>';
            }
            return $html . '</span>';
        };
        $imageEditButton = static function (string $key, string $label) use ($isCommerceOwner): string {
            if (!$isCommerceOwner) {
                return '';
            }
            return '<button type="button" class="public-edit-btn public-edit-btn--floating" data-public-edit-image="'
                . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '" data-public-edit-label="'
                . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '" aria-label="Editar '
                . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '"><i class="bx bx-pencil" aria-hidden="true"></i></button>';
        };
        $dashboardEditLink = static function (string $section, string $label) use ($isCommerceOwner, $slug): string {
            if (!$isCommerceOwner) {
                return '';
            }
            return '<a class="public-edit-btn public-edit-btn--floating public-edit-btn--link" href="'
                . htmlspecialchars(CommercePanel::dashboardUrlForSlug($slug, $section), ENT_QUOTES, 'UTF-8')
                . '" aria-label="' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '" title="'
                . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '"><i class="bx bx-pencil" aria-hidden="true"></i></a>';
        };

        $serviceNamesById = [];
        $serviceIdsByLocalId = [];
        $allServiceIds = [];
        foreach ($services as $svcRow) {
            $sid = (int)($svcRow['id_service'] ?? 0);
            if ($sid <= 0) {
                continue;
            }
            $allServiceIds[] = $sid;
            $serviceNamesById[$sid] = trim((string)($svcRow['nombre'] ?? ('Servicio ' . $sid)));
            $localId = isset($svcRow['id_local']) && is_numeric($svcRow['id_local']) ? (int)$svcRow['id_local'] : 0;
            if ($localId > 0) {
                $serviceIdsByLocalId[$localId] = $sid;
            }
        }
        $parseProfessionalSkills = static function ($raw): array {
            $ids = [];
            $parts = is_array($raw) ? $raw : (preg_split('/[,;|]+/', (string)$raw) ?: []);
            foreach ($parts as $part) {
                if (is_array($part)) {
                    continue;
                }
                if (preg_match_all('/\d+/', (string)$part, $matches)) {
                    foreach ($matches[0] as $match) {
                        $id = (int)$match;
                        if ($id > 0) {
                            $ids[$id] = $id;
                        }
                    }
                }
            }
            return array_values($ids);
        };
        $professionalInitials = static function (string $name): string {
            $parts = preg_split('/\s+/', trim($name)) ?: [];
            $initials = '';
            foreach (array_slice($parts, 0, 2) as $part) {
                $initials .= mb_substr($part, 0, 1, 'UTF-8');
            }
            return $initials !== '' ? mb_strtoupper($initials, 'UTF-8') : 'P';
        };
        $publicProfessionals = [];
        foreach (($catalog['barbers'] ?? []) as $barberRow) {
            if (!is_array($barberRow)) {
                continue;
            }
            $barberId = (int)($barberRow['ID_Barber'] ?? 0);
            $barberName = trim((string)($barberRow['Nombre'] ?? '') . ' ' . (string)($barberRow['Apellido'] ?? ''));
            $barberStatus = strtolower(trim((string)($barberRow['Status'] ?? '')));
            if ($barberId <= 0 || $barberName === '' || in_array($barberStatus, ['inactivo', 'inactive', 'suspendido', 'suspended', 'baja'], true)) {
                continue;
            }
            $skills = $parseProfessionalSkills($barberRow['Habilidades'] ?? '');
            $serviceIds = [];
            if ($skills === []) {
                $serviceIds = $allServiceIds;
            } else {
                foreach ($skills as $skillId) {
                    if (isset($serviceIdsByLocalId[$skillId])) {
                        $serviceIds[$serviceIdsByLocalId[$skillId]] = $serviceIdsByLocalId[$skillId];
                    } elseif (isset($serviceNamesById[$skillId])) {
                        $serviceIds[$skillId] = $skillId;
                    }
                }
                $serviceIds = array_values($serviceIds);
            }
            $profileRel = trim((string)($barberRow['Perfil'] ?? ''));
            $profileUrl = $profileRel !== '' ? $tenantAssetUrl($profileRel) : '';
            $serviceLabels = [];
            foreach ($serviceIds as $sid) {
                if (isset($serviceNamesById[$sid])) {
                    $serviceLabels[] = $serviceNamesById[$sid];
                }
            }
            $publicProfessionals[] = [
                'id' => $barberId,
                'name' => $barberName,
                'first_name' => preg_split('/\s+/', $barberName)[0] ?? $barberName,
                'initials' => $professionalInitials($barberName),
                'profile_url' => $profileUrl,
                'service_ids' => array_values(array_unique(array_map('intval', $serviceIds))),
                'service_labels' => array_values(array_unique($serviceLabels)),
            ];
        }
        $showProfessionals = $showServices && !$isStoreMode && $publicProfessionals !== [];
        $professionalsForJs = array_map(static function (array $professional): array {
            return [
                'id' => (string)$professional['id'],
                'name' => (string)$professional['name'],
                'first_name' => (string)$professional['first_name'],
                'serviceIds' => array_map('strval', $professional['service_ids']),
            ];
        }, $publicProfessionals);
        $serviceProfessionalIds = [];
        foreach ($allServiceIds as $sid) {
            $serviceProfessionalIds[$sid] = [];
            foreach ($publicProfessionals as $professional) {
                if (in_array($sid, $professional['service_ids'], true)) {
                    $serviceProfessionalIds[$sid][] = (string)$professional['id'];
                }
            }
        }

        $heroDefaultEyebrow = $isStoreMode
            ? ($rubroLabel !== '' ? $rubroLabel : 'Catalogo online')
            : ($rubroLabel !== '' ? $rubroLabel : 'Reservas online 24/7');
        $heroDefaultTitle = $isStoreMode
            ? 'Explora el catalogo de ' . $titulo
            : 'Reservá tu turno en ' . $titulo . ' en segundos';
        $heroDefaultLead = $slogan !== ''
            ? $slogan
            : ($isStoreMode
                ? 'Conoce productos, arma tu pedido y coordina entrega o retiro por WhatsApp.'
                : 'Elegí tu servicio, día y horario. Sin llamadas, sin esperas.');
        $heroImageRel = $publicImageValue('hero.image', '');
        $heroImageUrl = $heroImageRel !== '' ? $tenantAssetUrl($heroImageRel) : ($hasLogo ? $logoUrl : '');
        $aboutImageRel = $publicImageValue('about.image', '');
        $aboutImageUrl = $aboutImageRel !== '' ? $tenantAssetUrl($aboutImageRel) : ($hasLogo ? $logoUrl : $coverImageUrl);
        $contactDefaultSubtitle = $ciudad !== ''
            ? 'Estamos en ' . $ciudad . '. Pasá, escribinos o reservá online.'
            : 'Pasá, escribinos o reservá online.';
        $siteEditCsrf = $isCommerceOwner ? CSRF::generate('public_site_edit') : '';

        $csrf = CSRF::generate('public_booking');
        $googleClientId = GoogleAuth::isEnabled() ? GoogleAuth::clientId() : '';
        $clientGoogleAuthApi = url('src/API/client_google_auth.php');
        $apiBase = url('admin/api/appointments.php');
        $cancelAppointmentApi = url('admin/api/cancel_appointment.php');
        $cartOrderApi = url('admin/api/cart_order.php');
        $cartMercadoPagoApi = url('admin/api/cart_mercadopago.php');
        $mercadoPagoReturnApi = url('admin/api/mercadopago_return.php');
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
        <link rel="icon" type="image/png" href="<?= htmlspecialchars(AdminBrand::faviconUrl(), ENT_QUOTES, 'UTF-8') ?>">
        <link rel="apple-touch-icon" href="<?= htmlspecialchars(AdminBrand::faviconUrl(), ENT_QUOTES, 'UTF-8') ?>">
        <link rel="manifest" href="<?= htmlspecialchars(url('template/manifest.webmanifest?v=' . $manifestVer), ENT_QUOTES, 'UTF-8') ?>">
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
                    <?php if ($showProfessionals): ?>
                    <a href="#profesionales">Profesionales</a>
                    <?php endif; ?>
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
                <?php if ($showProfessionals): ?>
                <a href="#profesionales">Profesionales</a>
                <?php endif; ?>
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
                    <span class="hero__eyebrow"><i class="bx bx-store"></i> <?= $editableText('hero.eyebrow', $heroDefaultEyebrow, 'etiqueta principal') ?></span>
                    <h1><?= $editableText('hero.title', $heroDefaultTitle, 'titulo principal') ?></h1>
                    <p class="lead"><?= $editableText('hero.lead', $heroDefaultLead, 'texto principal') ?></p>
                    <div class="hero__actions">
                        <a href="#productos" class="btn btn--primary btn--lg"><i class="bx bx-package"></i> Ver catalogo</a>
                        <?php if ($whatsappDigits !== ''): ?>
                        <a target="_blank" rel="noopener" href="https://wa.me/<?= htmlspecialchars($whatsappDigits, ENT_QUOTES, 'UTF-8') ?>" class="btn btn--ghost btn--lg">Consultar por WhatsApp</a>
                        <?php endif; ?>
                    </div>
                    <div class="hero__stats">
                        <div><div class="stat__num"><?= $editableText('hero.stat_1.value', '+' . count($localProducts), 'dato destacado 1') ?></div><div class="stat__lbl"><?= $editableText('hero.stat_1.label', 'Productos', 'descripcion destacada 1') ?></div></div>
                        <div><div class="stat__num"><?= $editableText('hero.stat_2.value', 'WA', 'dato destacado 2') ?></div><div class="stat__lbl"><?= $editableText('hero.stat_2.label', 'Pedidos directos', 'descripcion destacada 2') ?></div></div>
                        <div><div class="stat__num"><?= $editableText('hero.stat_3.value', '24/7', 'dato destacado 3') ?></div><div class="stat__lbl"><?= $editableText('hero.stat_3.label', 'Catalogo visible', 'descripcion destacada 3') ?></div></div>
                    </div>
                    <?php else: ?>
                    <span class="hero__eyebrow"><i class="bx bx-calendar-check"></i> <?= $editableText('hero.eyebrow', $heroDefaultEyebrow, 'etiqueta principal') ?></span>
                    <h1><?= $editableText('hero.title', $heroDefaultTitle, 'titulo principal') ?></h1>
                    <p class="lead"><?= $editableText('hero.lead', $heroDefaultLead, 'texto principal') ?></p>
                    <div class="hero__actions">
                        <a href="#servicios" class="btn btn--primary btn--lg"><i class="bx bx-calendar-plus"></i> Reservar ahora</a>
                        <a href="#servicios" class="btn btn--ghost btn--lg">Ver servicios</a>
                    </div>
                    <div class="hero__stats">
                        <div><div class="stat__num"><?= $editableText('hero.stat_1.value', '24/7', 'dato destacado 1') ?></div><div class="stat__lbl"><?= $editableText('hero.stat_1.label', 'Reservas online', 'descripcion destacada 1') ?></div></div>
                        <div><div class="stat__num"><?= $editableText('hero.stat_2.value', '+' . count($services), 'dato destacado 2') ?></div><div class="stat__lbl"><?= $editableText('hero.stat_2.label', 'Servicios', 'descripcion destacada 2') ?></div></div>
                        <div><div class="stat__num"><?= $editableText('hero.stat_3.value', '4.9', 'dato destacado 3') ?></div><div class="stat__lbl"><?= $editableText('hero.stat_3.label', 'Calificación', 'descripcion destacada 3') ?></div></div>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="hero__visual">
                    <?= $imageEditButton('hero.image', 'imagen principal') ?>
                    <?php if ($heroImageUrl !== ''): ?>
                        <img src="<?= htmlspecialchars($heroImageUrl, ENT_QUOTES, 'UTF-8') ?>" alt="" data-public-edit-image-preview="hero.image">
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
                <span class="eyebrow"><?= $editableText('steps.eyebrow', 'Simple y directo', 'etiqueta de pasos') ?></span>
                <h2 class="section-title"><?= $editableText('steps.title', 'Compra en 3 pasos', 'titulo de pasos') ?></h2>
                <p class="section-sub"><?= $editableText('steps.subtitle', 'Explora el catalogo, arma tu pedido y coordina los detalles por WhatsApp.', 'texto de pasos') ?></p>
                <div class="steps-grid">
                    <article class="step-card">
                        <span class="step-card__num">1</span>
                        <h3><?= $editableText('steps.1.title', 'Elegi productos', 'titulo paso 1') ?></h3>
                        <p><?= $editableText('steps.1.text', 'Revisa precios, tipos y detalles del catalogo disponible.', 'texto paso 1') ?></p>
                    </article>
                    <article class="step-card">
                        <span class="step-card__num">2</span>
                        <h3><?= $editableText('steps.2.title', 'Arma tu pedido', 'titulo paso 2') ?></h3>
                        <p><?= $editableText('steps.2.text', 'Agrega al carrito lo que quieras consultar o comprar.', 'texto paso 2') ?></p>
                    </article>
                    <article class="step-card">
                        <span class="step-card__num">3</span>
                        <h3><?= $editableText('steps.3.title', 'Coordina entrega', 'titulo paso 3') ?></h3>
                        <p><?= $editableText('steps.3.text', 'Envia el pedido por WhatsApp y acuerda retiro, envio o pago.', 'texto paso 3') ?></p>
                    </article>
                </div>
                <?php else: ?>
                <span class="eyebrow"><?= $editableText('steps.eyebrow', 'Simple y rápido', 'etiqueta de pasos') ?></span>
                <h2 class="section-title"><?= $editableText('steps.title', 'Reservá en 3 pasos', 'titulo de pasos') ?></h2>
                <p class="section-sub"><?= $editableText('steps.subtitle', 'Sin llamadas ni mensajes de ida y vuelta. Tu turno queda confirmado al instante.', 'texto de pasos') ?></p>
                <div class="steps-grid">
                    <article class="step-card">
                        <span class="step-card__num">1</span>
                        <h3><?= $editableText('steps.1.title', 'Elegí el servicio', 'titulo paso 1') ?></h3>
                        <p><?= $editableText('steps.1.text', 'Explorá nuestra carta y seleccioná lo que necesitás.', 'texto paso 1') ?></p>
                    </article>
                    <article class="step-card">
                        <span class="step-card__num">2</span>
                        <h3><?= $editableText('steps.2.title', 'Seleccioná día y hora', 'titulo paso 2') ?></h3>
                        <p><?= $editableText('steps.2.text', 'Disponibilidad en tiempo real según nuestros horarios.', 'texto paso 2') ?></p>
                    </article>
                    <article class="step-card">
                        <span class="step-card__num">3</span>
                        <h3><?= $editableText('steps.3.title', 'Confirmá tu reserva', 'titulo paso 3') ?></h3>
                        <p><?= $editableText('steps.3.text', 'Recibís confirmación por email. También avisamos al negocio.', 'texto paso 3') ?></p>
                    </article>
                </div>
                <?php endif; ?>
            </div>
        </section>

        <?php if ($showProfessionals): ?>
        <section id="profesionales" class="professionals-section">
            <div class="wrap">
                <span class="eyebrow"><?= $editableText('professionals.eyebrow', 'Profesionales disponibles', 'etiqueta de profesionales') ?></span>
                <h2 class="section-title"><?= $editableText('professionals.title', 'Elegí con quién reservar', 'titulo de profesionales') ?></h2>
                <p class="section-sub"><?= $editableText('professionals.subtitle', 'Seleccioná un profesional para ver únicamente los servicios que realiza.', 'texto de profesionales') ?></p>
                <div class="professionals-grid" data-professionals-grid>
                    <?php foreach ($publicProfessionals as $professional): ?>
                    <?php
                        $serviceLabels = array_slice($professional['service_labels'], 0, 4);
                        $hasAssignedServices = !empty($professional['service_ids']);
                    ?>
                    <article class="professional-card" data-professional-card data-professional-id="<?= (int)$professional['id'] ?>">
                        <div class="professional-card__avatar">
                            <?php if ($professional['profile_url'] !== ''): ?>
                                <img src="<?= htmlspecialchars((string)$professional['profile_url'], ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars((string)$professional['name'], ENT_QUOTES, 'UTF-8') ?>" loading="lazy">
                            <?php else: ?>
                                <span><?= htmlspecialchars((string)$professional['initials'], ENT_QUOTES, 'UTF-8') ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="professional-card__body">
                            <h3><?= htmlspecialchars((string)$professional['name'], ENT_QUOTES, 'UTF-8') ?></h3>
                            <p><?= $hasAssignedServices ? count($professional['service_ids']) . ' servicio' . (count($professional['service_ids']) === 1 ? '' : 's') . ' disponible' . (count($professional['service_ids']) === 1 ? '' : 's') : 'Servicios a confirmar' ?></p>
                            <?php if ($serviceLabels): ?>
                            <div class="professional-card__skills">
                                <?php foreach ($serviceLabels as $label): ?>
                                <span><?= htmlspecialchars((string)$label, ENT_QUOTES, 'UTF-8') ?></span>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <button
                            class="btn btn--ghost btn--block"
                            type="button"
                            data-professional-filter="<?= (int)$professional['id'] ?>"
                            <?= $hasAssignedServices ? '' : 'disabled' ?>
                        >Reservar con <?= htmlspecialchars((string)$professional['first_name'], ENT_QUOTES, 'UTF-8') ?></button>
                    </article>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <?php if ($showServices): ?>
        <section id="servicios" class="alt">
            <div class="wrap">
                <span class="eyebrow"><?= $editableText('services.eyebrow', 'Servicios', 'etiqueta de servicios') ?></span>
                <h2 class="section-title"><?= $editableText('services.title', 'Lo que ofrecemos', 'titulo de servicios') ?></h2>
                <p class="section-sub"><?= $editableText('services.subtitle', 'Servicios profesionales con la mejor calidad. Reservá el que más te guste.', 'texto de servicios') ?></p>
                <?php if ($showProfessionals): ?>
                <div class="svc-filter-status" data-professional-service-filter hidden>
                    <span data-professional-service-filter-text></span>
                    <button type="button" class="btn btn--ghost btn--sm" data-clear-professional-filter>Ver todos</button>
                </div>
                <?php endif; ?>
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
                        <article class="svc-card" data-svc-id="<?= $svcId ?>" data-svc-name="<?= htmlspecialchars($s['nombre'], ENT_QUOTES, 'UTF-8') ?>" data-svc-duration="<?= (int)$s['duracion_min'] ?>" data-svc-price="<?= (float)$s['precio'] ?>" data-svc-professionals="<?= htmlspecialchars(implode(',', $serviceProfessionalIds[$svcId] ?? []), ENT_QUOTES, 'UTF-8') ?>" tabindex="0">
                            <?= $dashboardEditLink('servicios', 'Modificar servicio') ?>
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
                <span class="eyebrow"><?= $editableText('products.eyebrow', 'Productos', 'etiqueta de productos') ?></span>
                <h2 class="section-title"><?= $editableText('products.title', 'Nuestra tienda', 'titulo de productos') ?></h2>
                <p class="section-sub"><?= $editableText('products.subtitle', $cartEnabled ? 'Agrega al carrito y coordina tu pedido en minutos.' : 'Explora los productos disponibles y consulta al comercio para comprar.', 'texto de productos') ?></p>
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
                        $pBasePrice = ProductCatalog::basePrice($p);
                        $pDiscount = ProductCatalog::discountPercent($p);
                        $pSaleLabel = ProductCatalog::saleLabel($p);
                        $pMedia = ProductCatalog::mediaForRow($p);
                        $pPrice = ProductCatalog::effectivePrice($pBasePrice, $pDiscount);
                        $hasMultiple = count($pMedia) > 1;
                    ?>
                    <article class="prod-card"
                        data-product-id="<?= htmlspecialchars($pId, ENT_QUOTES, 'UTF-8') ?>"
                        data-product-name="<?= htmlspecialchars($pName, ENT_QUOTES, 'UTF-8') ?>"
                        data-product-price="<?= htmlspecialchars((string)$pPrice, ENT_QUOTES, 'UTF-8') ?>"
                        data-product-original-price="<?= htmlspecialchars((string)$pBasePrice, ENT_QUOTES, 'UTF-8') ?>"
                        data-product-variant="0"
                        data-product-variant-label="<?= htmlspecialchars((string)($pMedia[0]['label'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                        <?= $dashboardEditLink('productos', 'Modificar producto') ?>
                        <div class="prod-card__media">
                            <?php if (!empty($pMedia)): ?>
                                <div class="prod-gallery" data-gallery>
                                    <div class="prod-gallery__track" data-track>
                                        <?php foreach ($pMedia as $gi => $mediaItem):
                                            $gUrl = $tenantAssetUrl((string)$mediaItem['src']);
                                            $rawPrice = ($mediaItem['price'] ?? null) !== null ? (float)$mediaItem['price'] : $pBasePrice;
                                            $variantPrice = ProductCatalog::effectivePrice($rawPrice, $pDiscount);
                                        ?>
                                        <div class="prod-gallery__slide<?= $gi === 0 ? ' is-active' : '' ?>"
                                            data-slide="<?= $gi ?>"
                                            data-variant-index="<?= $gi ?>"
                                            data-variant-price="<?= htmlspecialchars((string)$variantPrice, ENT_QUOTES, 'UTF-8') ?>"
                                            data-variant-original-price="<?= htmlspecialchars((string)round($rawPrice, 2), ENT_QUOTES, 'UTF-8') ?>"
                                            data-variant-label="<?= htmlspecialchars((string)($mediaItem['label'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                            <img src="<?= htmlspecialchars($gUrl, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($pName . (($mediaItem['label'] ?? '') !== '' ? ' - ' . (string)$mediaItem['label'] : ''), ENT_QUOTES, 'UTF-8') ?>" loading="lazy">
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php if ($hasMultiple): ?>
                                    <button type="button" class="prod-gallery__arrow prod-gallery__arrow--prev" data-dir="-1" aria-label="Anterior"><i class="bx bx-chevron-left"></i></button>
                                    <button type="button" class="prod-gallery__arrow prod-gallery__arrow--next" data-dir="1" aria-label="Siguiente"><i class="bx bx-chevron-right"></i></button>
                                    <div class="prod-gallery__dots" data-dots>
                                        <?php foreach ($pMedia as $di => $dotMedia): ?>
                                        <button type="button" class="prod-gallery__dot<?= $di === 0 ? ' is-active' : '' ?>" data-slide="<?= $di ?>" aria-label="<?= htmlspecialchars((string)($dotMedia['label'] ?? ('Imagen ' . ($di + 1))), ENT_QUOTES, 'UTF-8') ?>"></button>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <span class="prod-card__placeholder" aria-hidden="true"><i class="bx bx-package"></i></span>
                            <?php endif; ?>
                        </div>
                        <div class="prod-card__body">
                            <?php if ($pSaleLabel !== '' || $pDiscount > 0): ?>
                                <span class="prod-card__offer"><?= htmlspecialchars($pSaleLabel !== '' ? $pSaleLabel : ('Oferta ' . rtrim(rtrim(number_format($pDiscount, 2, ',', '.'), '0'), ',') . '%'), ENT_QUOTES, 'UTF-8') ?></span>
                            <?php endif; ?>
                            <?php if ($pTipo !== ''): ?>
                                <span class="prod-card__type"><?= htmlspecialchars($pTipo, ENT_QUOTES, 'UTF-8') ?></span>
                            <?php endif; ?>
                            <h3 class="prod-card__name"><?= htmlspecialchars($pName, ENT_QUOTES, 'UTF-8') ?></h3>
                            <?php if ($pDesc !== '' && $pDesc !== $pName): ?>
                                <p class="prod-card__desc"><?= htmlspecialchars(mb_substr($pDesc, 0, 110, 'UTF-8'), ENT_QUOTES, 'UTF-8') ?></p>
                            <?php endif; ?>
                            <p class="prod-card__variant" data-product-variant-label-view<?= ($pMedia[0]['label'] ?? '') !== '' ? '' : ' hidden' ?>><?= htmlspecialchars((string)($pMedia[0]['label'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
                            <div class="prod-card__footer">
                                <div class="prod-card__price" data-product-price-label>
                                    <?php if ($pDiscount > 0 && $pBasePrice > $pPrice): ?>
                                        <span class="prod-card__price-old"><?= htmlspecialchars($currencySymbol, ENT_QUOTES, 'UTF-8') ?><?= number_format($pBasePrice, $currencyDecimals, ',', '.') ?></span>
                                    <?php endif; ?>
                                    <strong><?= htmlspecialchars($currencySymbol, ENT_QUOTES, 'UTF-8') ?><?= number_format($pPrice, $currencyDecimals, ',', '.') ?></strong>
                                </div>
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
                    <span class="eyebrow"><?= $editableText('about.eyebrow', 'Nosotros', 'etiqueta sobre nosotros') ?></span>
                    <h2 class="section-title"><?= $editableText('about.title', 'Sobre ' . $titulo, 'titulo sobre nosotros') ?></h2>
                    <p><?= $editableText('about.description', $aboutDescription, 'texto sobre nosotros') ?></p>
                    <ul class="about-highlights">
                        <?php foreach ($aboutHighlights as $idx => $highlight): ?>
                        <li><i class="bx bx-check-circle" aria-hidden="true"></i> <span><?= $editableText('about.highlight_' . ($idx + 1), (string)$highlight, 'destacado ' . ($idx + 1)) ?></span></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <div class="about__media">
                    <?= $imageEditButton('about.image', 'imagen sobre nosotros') ?>
                    <img src="<?= htmlspecialchars($aboutImageUrl, ENT_QUOTES, 'UTF-8') ?>" alt="" data-public-edit-image-preview="about.image">
                </div>
            </div>
        </section>

        <?php if ($hasConfiguredSchedule): ?>
        <section id="horarios" class="alt">
            <div class="wrap">
                <?= $dashboardEditLink('config', 'Modificar horarios') ?>
                <span class="eyebrow"><?= $editableText('schedule.eyebrow', 'Horarios', 'etiqueta de horarios') ?></span>
                <h2 class="section-title"><?= $editableText('schedule.title', 'Cuándo podés venir', 'titulo de horarios') ?></h2>
                <p class="section-sub"><?= $editableText('schedule.subtitle', $scheduleSummary, 'texto de horarios') ?></p>
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
                <?= $dashboardEditLink('config', 'Modificar datos de contacto') ?>
                <span class="eyebrow"><?= $editableText('contact.eyebrow', 'Contacto', 'etiqueta de contacto') ?></span>
                <h2 class="section-title"><?= $editableText('contact.title', 'Cómo encontrarnos', 'titulo de contacto') ?></h2>
                <p class="section-sub"><?= $editableText('contact.subtitle', $contactDefaultSubtitle, 'texto de contacto') ?></p>
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
                <span class="eyebrow"><?= $editableText('booking_cta.eyebrow', 'Reservar', 'etiqueta final de reserva') ?></span>
                <h2 class="section-title"><?= $editableText('booking_cta.title', 'Listo para reservar', 'titulo final de reserva') ?></h2>
                <p class="section-sub" style="margin: 0 auto 1.8rem"><?= $editableText('booking_cta.subtitle', 'Elegí un servicio arriba y hacé clic en "Reservar". Vas a recibir confirmación por email o WhatsApp.', 'texto final de reserva') ?></p>
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
                            <img src="<?= htmlspecialchars(AdminBrand::faviconUrl(), ENT_QUOTES, 'UTF-8') ?>" alt="Agendarte UY">
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
                        <details class="client-history" id="booking-history" hidden>
                            <summary class="client-history__summary"><span><i class="bx bx-history" aria-hidden="true"></i> Tu historial en este negocio</span><i class="bx bx-chevron-down" aria-hidden="true"></i></summary>
                            <div class="client-history__body" id="booking-history-body"></div>
                        </details>
                        <?php endif; ?>
                        <form id="booking-form" novalidate>
                            <input type="hidden" name="slug" value="<?= htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="id_service" id="booking-svc-id" value="">
                            <input type="hidden" name="payment_method" id="booking-payment-method" value="manual">
                            <div class="field">
                                <label>Servicio</label>
                                <input type="text" id="booking-svc-name" readonly>
                            </div>
                            <?php if ($showProfessionals): ?>
                            <div class="field" id="booking-barber-field" hidden>
                                <label for="booking-barber-select">Profesional</label>
                                <select id="booking-barber-select" name="id_barber"></select>
                                <p class="hint" id="booking-barber-hint" hidden></p>
                            </div>
                            <?php endif; ?>
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
                        <details class="client-history" id="cart-history" hidden>
                            <summary class="client-history__summary"><span><i class="bx bx-history" aria-hidden="true"></i> Tu historial en este negocio</span><i class="bx bx-chevron-down" aria-hidden="true"></i></summary>
                            <div class="client-history__body" id="cart-history-body"></div>
                        </details>
                        <?php endif; ?>
                        <div class="cart-contact" id="cart-contact">
                            <p class="cart-contact__title">Datos para confirmarte el pedido</p>
                            <div class="cart-delivery-selector" id="cart-delivery-selector">
                                <span class="cart-delivery__title">Forma de entrega</span>
                                <div class="cart-delivery__options">
                                    <label class="cart-delivery__option">
                                        <input type="radio" name="cart-delivery-type" value="retiro" id="cart-delivery-retiro" checked>
                                        <div class="cart-delivery__card">
                                            <i class="bx bx-store-alt" aria-hidden="true"></i>
                                            <span>Retirar en local</span>
                                        </div>
                                    </label>
                                    <label class="cart-delivery__option">
                                        <input type="radio" name="cart-delivery-type" value="envio" id="cart-delivery-envio">
                                        <div class="cart-delivery__card">
                                            <i class="bx bx-truck" aria-hidden="true"></i>
                                            <span>Envío a domicilio</span>
                                        </div>
                                    </label>
                                </div>
                            </div>
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
                                <label for="cart-customer-cedula">
                                    Cedula
                                    <input type="text" id="cart-customer-cedula" inputmode="numeric" autocomplete="off" placeholder="12345678" pattern="[0-9]{7,}" maxlength="20" required>
                                </label>
                            </div>
                            <div class="cart-address-field" id="cart-address-field" hidden>
                                <label for="cart-customer-address">
                                    Dirección de envío <span class="required-star">*</span>
                                    <input type="text" id="cart-customer-address" autocomplete="street-address" placeholder="Calle, N°, Apto / Esquina, Barrio">
                                </label>
                            </div>
                            <p class="cart-contact__hint">Te enviamos la confirmacion por email y WhatsApp.</p>
                        </div>
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

        <?php if ($isCommerceOwner): ?>
        <div class="public-edit-modal" id="public-edit-modal" role="dialog" aria-modal="true" aria-labelledby="public-edit-title" hidden>
            <div class="public-edit-modal__backdrop" data-public-edit-close></div>
            <form class="public-edit-modal__dialog" id="public-edit-form">
                <div class="public-edit-modal__head">
                    <div>
                        <span class="public-edit-modal__eyebrow">Editar página</span>
                        <h3 id="public-edit-title">Modificar contenido</h3>
                    </div>
                    <button type="button" class="public-edit-modal__close" data-public-edit-close aria-label="Cerrar">×</button>
                </div>
                <div class="public-edit-modal__body">
                    <label class="public-edit-modal__field" id="public-edit-text-wrap">
                        <span>Texto</span>
                        <textarea id="public-edit-text" rows="4" maxlength="700"></textarea>
                    </label>
                    <label class="public-edit-modal__field" id="public-edit-image-wrap" hidden>
                        <span>Imagen</span>
                        <input id="public-edit-image" type="file" accept="image/jpeg,image/png,image/webp,image/gif">
                        <small>JPG, PNG, WebP o GIF. Máximo 5 MB.</small>
                    </label>
                    <p class="public-edit-modal__error" id="public-edit-error" hidden></p>
                </div>
                <div class="public-edit-modal__foot">
                    <button type="button" class="btn btn--ghost" data-public-edit-close>Cancelar</button>
                    <button type="submit" class="btn btn--primary" id="public-edit-submit">Guardar</button>
                </div>
            </form>
        </div>
        <script>
        window.__PUBLIC_SITE_EDITOR__ = <?= json_encode([
            'enabled' => true,
            'endpoint' => url('src/API/public_content.php'),
            'csrf' => $siteEditCsrf,
            'slug' => $slug,
            'version' => $publicContentVersion,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        </script>
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
            const professionalsCatalog = <?= json_encode($professionalsForJs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
            const hasProducts = Array.isArray(productsCatalog) && productsCatalog.length > 0;
            const cartEnabled = <?= $cartEnabled ? 'true' : 'false' ?>;
            const cartWhatsAppEnabled = <?= $cartWhatsAppEnabled ? 'true' : 'false' ?>;
            const cartInstructions = <?= json_encode($cartInstructions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
            const cartKey = 'agenduy-cart-' + commerceSlug;
            const cartOrderUrl = <?= json_encode($cartOrderApi, JSON_UNESCAPED_SLASHES) ?>;
            const cartMercadoPagoUrl = <?= json_encode($cartMercadoPagoApi, JSON_UNESCAPED_SLASHES) ?>;
            const mercadoPagoReturnUrl = <?= json_encode($mercadoPagoReturnApi, JSON_UNESCAPED_SLASHES) ?>;
            const cancelAppointmentUrl = <?= json_encode($cancelAppointmentApi, JSON_UNESCAPED_SLASHES) ?>;
            const storeMpCheckoutEnabled = <?= $storeMpCheckoutEnabled ? 'true' : 'false' ?>;
            const bookingMpCheckoutEnabled = <?= $bookingMpCheckoutEnabled ? 'true' : 'false' ?>;
            const bookingMpRequired = <?= $bookingMpRequired ? 'true' : 'false' ?>;
            let lastBooking = null;
            let csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
            const publicContentSync = {
                endpoint: <?= json_encode(url('src/API/public_content.php'), JSON_UNESCAPED_SLASHES) ?>,
                slug: commerceSlug,
                version: <?= json_encode($publicContentVersion, JSON_UNESCAPED_SLASHES) ?>,
            };
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

            const publicContentCssEscape = window.CSS && typeof window.CSS.escape === 'function'
                ? window.CSS.escape.bind(window.CSS)
                : (value) => String(value).replace(/["\\]/g, '\\$&');
            const setPublicContentVersion = (version) => {
                const next = String(version || '').trim();
                if (!next) return;
                publicContentSync.version = next;
                if (window.__PUBLIC_SITE_EDITOR__) {
                    window.__PUBLIC_SITE_EDITOR__.version = next;
                }
            };
            const applyPublicContentPayload = (payload) => {
                if (!payload || typeof payload !== 'object') return false;
                let applied = false;
                const text = payload.text && typeof payload.text === 'object' ? payload.text : {};
                Object.keys(text).forEach((key) => {
                    const value = text[key] == null ? '' : String(text[key]);
                    document.querySelectorAll('[data-public-edit-key="' + publicContentCssEscape(key) + '"] [data-public-edit-value]').forEach((node) => {
                        if (node.textContent !== value) {
                            node.textContent = value;
                            applied = true;
                        }
                    });
                });
                const images = payload.images && typeof payload.images === 'object' ? payload.images : {};
                Object.keys(images).forEach((key) => {
                    const url = String(images[key] || '');
                    if (!url) return;
                    document.querySelectorAll('img[data-public-edit-image-preview="' + publicContentCssEscape(key) + '"]').forEach((img) => {
                        if (img.getAttribute('src') !== url) {
                            img.src = url;
                            applied = true;
                        }
                    });
                });
                return applied;
            };

            (function publicContentAutoRefresh() {
                if (!publicContentSync.endpoint || !publicContentSync.slug || !publicContentSync.version) return;
                let inFlight = false;
                const check = async (force) => {
                    if (inFlight) return;
                    if (!force && (document.visibilityState !== 'visible' || navigator.onLine === false)) return;
                    inFlight = true;
                    try {
                        const url = new URL(publicContentSync.endpoint, window.location.href);
                        url.searchParams.set('action', 'state');
                        url.searchParams.set('slug', publicContentSync.slug);
                        url.searchParams.set('_', String(Date.now()));
                        const response = await fetch(url.toString(), {
                            credentials: 'same-origin',
                            cache: 'no-store',
                            headers: { 'Accept': 'application/json' },
                        });
                        const data = await response.json().catch(() => null);
                        if (!response.ok || !data || data.ok !== true || !data.version) return;
                        if (String(data.version) === String(publicContentSync.version)) return;
                        const applied = applyPublicContentPayload(data);
                        setPublicContentVersion(data.version);
                        if (!applied && String(data.latest_type || '') === 'image') {
                            window.location.reload();
                        }
                    } catch (_) {
                        // El siguiente intervalo reintenta sin molestar al visitante.
                    } finally {
                        inFlight = false;
                    }
                };
                window.setInterval(() => check(false), 30000);
                document.addEventListener('visibilitychange', () => {
                    if (document.visibilityState === 'visible') check(true);
                });
                window.addEventListener('focus', () => check(true));
                window.addEventListener('online', () => check(true));
            })();

            (function publicSiteEditor() {
                const cfg = window.__PUBLIC_SITE_EDITOR__ || {};
                if (!cfg.enabled) return;
                const modal = document.getElementById('public-edit-modal');
                const form = document.getElementById('public-edit-form');
                const title = document.getElementById('public-edit-title');
                const textWrap = document.getElementById('public-edit-text-wrap');
                const imageWrap = document.getElementById('public-edit-image-wrap');
                const textarea = document.getElementById('public-edit-text');
                const imageInput = document.getElementById('public-edit-image');
                const submit = document.getElementById('public-edit-submit');
                const error = document.getElementById('public-edit-error');
                const cssEscape = window.CSS && typeof window.CSS.escape === 'function'
                    ? window.CSS.escape.bind(window.CSS)
                    : (value) => String(value).replace(/["\\]/g, '\\$&');
                let active = null;
                if (!modal || !form || !textarea || !imageInput) return;

                const close = () => {
                    modal.hidden = true;
                    modal.classList.remove('is-visible');
                    active = null;
                    if (error) {
                        error.hidden = true;
                        error.textContent = '';
                    }
                    if (submit) submit.disabled = false;
                    imageInput.value = '';
                };
                const open = (nextActive) => {
                    active = nextActive;
                    if (title) title.textContent = nextActive.label || 'Modificar contenido';
                    if (textWrap) textWrap.hidden = nextActive.type !== 'text';
                    if (imageWrap) imageWrap.hidden = nextActive.type !== 'image';
                    if (nextActive.type === 'text') {
                        textarea.value = nextActive.field?.querySelector('[data-public-edit-value]')?.textContent?.trim() || '';
                        requestAnimationFrame(() => textarea.focus());
                    }
                    modal.hidden = false;
                    requestAnimationFrame(() => modal.classList.add('is-visible'));
                };
                const showError = (message) => {
                    if (!error) return;
                    error.textContent = message || 'No se pudo guardar.';
                    error.hidden = false;
                };

                document.addEventListener('click', (event) => {
                    const textBtn = event.target.closest('[data-public-edit-trigger]');
                    if (textBtn) {
                        event.preventDefault();
                        event.stopPropagation();
                        const field = textBtn.closest('[data-public-edit-field]');
                        if (!field) return;
                        open({
                            type: 'text',
                            key: field.dataset.publicEditKey || '',
                            label: field.dataset.publicEditLabel || 'Modificar texto',
                            field
                        });
                        return;
                    }
                    const imageBtn = event.target.closest('[data-public-edit-image]');
                    if (imageBtn) {
                        event.preventDefault();
                        event.stopPropagation();
                        open({
                            type: 'image',
                            key: imageBtn.dataset.publicEditImage || '',
                            label: imageBtn.dataset.publicEditLabel || 'Modificar imagen'
                        });
                    }
                });
                modal.querySelectorAll('[data-public-edit-close]').forEach(btn => btn.addEventListener('click', close));
                modal.addEventListener('click', event => {
                    if (event.target === modal || event.target.classList.contains('public-edit-modal__backdrop')) {
                        close();
                    }
                });
                document.addEventListener('keydown', event => {
                    if (event.key === 'Escape' && !modal.hidden) close();
                });

                form.addEventListener('submit', async (event) => {
                    event.preventDefault();
                    if (!active || !active.key) return;
                    if (submit) submit.disabled = true;
                    if (error) {
                        error.hidden = true;
                        error.textContent = '';
                    }
                    try {
                        let response;
                        if (active.type === 'text') {
                            response = await fetch(cfg.endpoint, {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-CSRF-Token': cfg.csrf || '',
                                    'Accept': 'application/json'
                                },
                                body: JSON.stringify({
                                    action: 'save_text',
                                    slug: cfg.slug || commerceSlug,
                                    key: active.key,
                                    value: textarea.value.trim()
                                })
                            });
                        } else {
                            const file = imageInput.files && imageInput.files[0];
                            if (!file) {
                                showError('Elegí una imagen.');
                                if (submit) submit.disabled = false;
                                return;
                            }
                            const data = new FormData();
                            data.append('action', 'save_image');
                            data.append('slug', cfg.slug || commerceSlug);
                            data.append('key', active.key);
                            data.append('image', file);
                            response = await fetch(cfg.endpoint, {
                                method: 'POST',
                                headers: { 'X-CSRF-Token': cfg.csrf || '', 'Accept': 'application/json' },
                                body: data
                            });
                        }
                        const json = await response.json().catch(() => null);
                        if (!response.ok || !json || !json.ok) {
                            throw new Error(json && json.error ? json.error : 'No se pudo guardar.');
                        }
                        if (json.version) {
                            setPublicContentVersion(json.version);
                        }
                        if (active.type === 'text') {
                            const target = active.field?.querySelector('[data-public-edit-value]');
                            if (target) target.textContent = json.value || '';
                        } else {
                            const img = document.querySelector('img[data-public-edit-image-preview="' + cssEscape(active.key) + '"]');
                            if (img && json.url) {
                                img.src = json.url;
                            } else {
                                window.location.reload();
                                return;
                            }
                        }
                        showPublicToast('Contenido actualizado.', 'success');
                        close();
                    } catch (err) {
                        showError(err && err.message ? err.message : 'No se pudo guardar.');
                        if (submit) submit.disabled = false;
                    }
                });
            })();

            (function showMercadoPagoReturn() {
                const params = new URLSearchParams(window.location.search);
                const appointmentId = params.get('mp_appointment');
                const orderId = params.get('mp_order');
                if (!appointmentId && !orderId) return;
                const status = String(params.get('mp_status') || '').toLowerCase();
                const returnBody = {
                    slug: commerceSlug,
                    kind: appointmentId ? 'appointment' : 'store',
                    mp_status: status,
                    mp_ref: params.get('mp_ref') || params.get('external_reference') || '',
                    preference_id: params.get('preference_id') || params.get('preference') || '',
                    payment_id: params.get('payment_id') || params.get('collection_id') || '',
                    status_detail: params.get('status_detail') || '',
                    _csrf: csrfToken
                };
                if (appointmentId) {
                    returnBody.appointment_id = appointmentId;
                } else {
                    returnBody.order_id = orderId;
                }
                if (status && status !== 'success') {
                    (async () => {
                        const postReturn = async () => {
                            const res = await fetch(mercadoPagoReturnUrl, {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify(returnBody),
                                credentials: 'same-origin',
                                cache: 'no-store'
                            });
                            let json = null;
                            try { json = await res.json(); } catch (_) {}
                            return json || { ok: false };
                        };
                        let json = await postReturn();
                        if (json && json.error === 'csrf_retry' && json.csrf) {
                            syncCsrfToken(json.csrf);
                            returnBody._csrf = csrfToken;
                            json = await postReturn();
                        }
                        if (!json || !json.ok) {
                            console.warn('No se pudo registrar el retorno de Mercado Pago.', json && json.error ? json.error : json);
                        }
                    })();
                }
                if (status === 'success') {
                    showPublicToast(appointmentId
                        ? 'Pago recibido. La reserva queda confirmada cuando Mercado Pago informa la aprobacion.'
                        : 'Pago recibido. El pedido queda confirmado cuando Mercado Pago informa la aprobacion.', 'success');
                } else if (status === 'pending') {
                    showPublicToast('El pago quedo pendiente. Te avisaremos cuando Mercado Pago lo confirme.', 'info');
                } else {
                    showPublicToast(appointmentId
                        ? 'No se completo el pago de la reserva. Podes intentar nuevamente con otro horario.'
                        : 'No se completo el pago del pedido. Podes intentar nuevamente.', 'error');
                }
                params.delete('mp_appointment');
                params.delete('mp_order');
                params.delete('mp_status');
                params.delete('mp_ref');
                [
                    'external_reference',
                    'preference_id',
                    'preference',
                    'payment_id',
                    'collection_id',
                    'collection_status',
                    'status',
                    'status_detail',
                    'merchant_order_id',
                    'payment_type',
                ].forEach((key) => params.delete(key));
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

            function cartItemKey(id, variant) {
                return String(id) + '@' + String(Math.max(0, Number(variant) || 0));
            }

            function productById(id) {
                return productsCatalog.find(p => String(p.id) === String(id)) || null;
            }

            function variantForProduct(product, variantIndex) {
                const variants = Array.isArray(product && product.variants) ? product.variants : [];
                const normalized = Math.max(0, Number(variantIndex) || 0);
                return variants.find(v => Number(v.index) === normalized) || variants[0] || {
                    index: 0,
                    label: '',
                    price: product ? product.price : 0,
                    originalPrice: product ? (product.originalPrice || product.price || 0) : 0
                };
            }

            function addToCart(id, name, price, qty, variant, variantLabel, originalPrice) {
                const items = loadCart();
                const pid = String(id);
                const variantIndex = Math.max(0, Number(variant) || 0);
                const key = cartItemKey(pid, variantIndex);
                const existing = items.find(i => String(i.key || cartItemKey(i.id, i.variant || 0)) === key);
                const addQty = Math.max(1, Number(qty) || 1);
                if (existing) {
                    existing.qty = (Number(existing.qty) || 0) + addQty;
                } else {
                    items.push({
                        key,
                        id: pid,
                        variant: variantIndex,
                        variantLabel: String(variantLabel || ''),
                        name: String(name || 'Producto'),
                        price: Number(price) || 0,
                        originalPrice: Number(originalPrice) || Number(price) || 0,
                        qty: addQty
                    });
                }
                saveCart(items);
            }

            function setCartQty(key, qty) {
                let items = loadCart();
                const itemKey = String(key);
                const nextQty = Math.max(0, Number(qty) || 0);
                if (nextQty <= 0) {
                    items = items.filter(i => String(i.key || cartItemKey(i.id, i.variant || 0)) !== itemKey);
                } else {
                    const row = items.find(i => String(i.key || cartItemKey(i.id, i.variant || 0)) === itemKey);
                    if (row) row.qty = nextQty;
                }
                saveCart(items);
            }

            function buildProductsMessage(items) {
                if (!items.length) return '';
                const lines = items.map(i =>
                    '- ' + (Number(i.qty) || 1) + 'x ' + i.name + (i.variantLabel ? ' - ' + i.variantLabel : '') + ' (' + formatMoney((Number(i.price) || 0) * (Number(i.qty) || 1)) + ')'
                );
                lines.push('Total productos: ' + formatMoney(cartTotal(items)));
                return lines.join('\n');
            }

            function buildStoreWaMessage(items, paymentUrl) {
                const deliveryEnvioEl = document.getElementById('cart-delivery-envio');
                const customerAddressEl = document.getElementById('cart-customer-address');
                const isEnvio = deliveryEnvioEl && deliveryEnvioEl.checked;
                const direccion = String(customerAddressEl?.value || '').trim();
                const deliveryLine = isEnvio
                    ? ('Forma de entrega: 🚚 Envío a domicilio\nDirección de envío: ' + (direccion || 'A coordinar'))
                    : 'Forma de entrega: 🏬 Retiro en local del comercio';

                const lines = [
                    'Hola! Quiero pedir estos productos de ' + commerceName + ':',
                    '',
                    buildProductsMessage(items),
                    '',
                    deliveryLine,
                ];
                const mpUrl = String(paymentUrl || '').trim();
                if (mpUrl) {
                    lines.push('', 'Link de pago Mercado Pago:', mpUrl);
                }
                lines.push('', cartInstructions || 'Coordinamos la compra por este medio. Gracias!');
                return lines.join('\n');
            }

            function buildBookingWaMessage(booking, items) {
                const parts = [
                    'Hola! Acabo de reservar en ' + commerceName + ':',
                    '',
                    'Servicio: ' + (booking.servicio || ''),
                    booking.profesional ? ('Profesional: ' + booking.profesional) : '',
                    'Fecha: ' + (booking.fecha || ''),
                    'Hora: ' + (booking.hora || ''),
                    'Nombre: ' + (booking.nombre || ''),
                ];
                if (booking.id) parts.push('Nº reserva: ' + booking.id);
                if (items.length) {
                    parts.push('', 'También me interesan estos productos:', '', buildProductsMessage(items));
                }
                parts.push('', 'Coordinamos por este medio. Gracias!');
                return parts.filter((part) => part !== false && part !== null && part !== undefined).join('\n');
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
                if (!items || !items.length) {
                    return { ok: true, skipped: true };
                }
                const body = {
                    slug: commerceSlug,
                    items: items.map(i => ({
                        id: String(i.id),
                        variant: Math.max(0, Number(i.variant) || 0),
                        qty: Math.max(1, Number(i.qty) || 1)
                    })),
                    cliente_nombre: meta.nombre || '',
                    cliente_email: meta.email || '',
                    cliente_telefono: meta.telefono || '',
                    cliente_cedula: meta.cedula || '',
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
                        variant: Math.max(0, Number(i.variant) || 0),
                        qty: Math.max(1, Number(i.qty) || 1)
                    })),
                    cliente_nombre: meta.nombre || '',
                    cliente_email: meta.email || '',
                    cliente_telefono: meta.telefono || '',
                    cliente_cedula: meta.cedula || '',
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
                    const key = String(i.key || cartItemKey(i.id, i.variant || 0));
                    const subtitle = formatMoney(i.price) + ' c/u' + (i.variantLabel ? ' - ' + escapeHtml(i.variantLabel) : '');
                    const controls = showControls
                        ? '<div class="cart-line__qty">' +
                            '<button type="button" class="qty-btn" data-cart-dec="' + escapeHtml(key) + '" aria-label="Quitar uno">-</button>' +
                            '<span>' + (Number(i.qty) || 0) + '</span>' +
                            '<button type="button" class="qty-btn" data-cart-inc="' + escapeHtml(key) + '" aria-label="Agregar uno">+</button>' +
                            '<button type="button" class="qty-btn qty-btn--remove" data-cart-remove="' + escapeHtml(key) + '" aria-label="Eliminar"><i class="bx bx-trash"></i></button>' +
                          '</div>'
                        : '<span class="cart-line__qty-label">x' + (Number(i.qty) || 0) + '</span>';
                    return '<div class="cart-line" data-id="' + escapeHtml(i.id) + '" data-key="' + escapeHtml(key) + '">' +
                        '<div class="cart-line__info"><strong>' + escapeHtml(i.name) + '</strong><span>' + subtitle + '</span></div>' +
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
                        const key = dec.getAttribute('data-cart-dec');
                        const row = loadCart().find(i => String(i.key || cartItemKey(i.id, i.variant || 0)) === String(key));
                        setCartQty(key, (row ? Number(row.qty) : 1) - 1);
                    } else if (inc) {
                        const key = inc.getAttribute('data-cart-inc');
                        const row = loadCart().find(i => String(i.key || cartItemKey(i.id, i.variant || 0)) === String(key));
                        setCartQty(key, (row ? Number(row.qty) : 0) + 1);
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
            const cartCustomerCedula = document.getElementById('cart-customer-cedula');
            const cartDeliveryRetiro = document.getElementById('cart-delivery-retiro');
            const cartDeliveryEnvio = document.getElementById('cart-delivery-envio');
            const cartAddressField = document.getElementById('cart-address-field');
            const cartCustomerAddress = document.getElementById('cart-customer-address');

            function updateDeliveryUI() {
                const isEnvio = cartDeliveryEnvio && cartDeliveryEnvio.checked;
                if (cartAddressField) {
                    cartAddressField.hidden = !isEnvio;
                }
            }

            if (cartDeliveryRetiro) cartDeliveryRetiro.addEventListener('change', updateDeliveryUI);
            if (cartDeliveryEnvio) cartDeliveryEnvio.addEventListener('change', updateDeliveryUI);

            function isValidEmail(value) {
                return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(value || '').trim());
            }

            function prefillCartContact() {
                const guest = typeof loadSavedGuest === 'function' ? loadSavedGuest() : null;
                if (guest) {
                    if (cartCustomerName && !String(cartCustomerName.value || '').trim()) {
                        cartCustomerName.value = String(guest.nombre || '').trim();
                    }
                    if (cartCustomerEmail && !String(cartCustomerEmail.value || '').trim()) {
                        cartCustomerEmail.value = String(guest.email || '').trim();
                    }
                    if (cartCustomerPhone && !String(cartCustomerPhone.value || '').trim()) {
                        cartCustomerPhone.value = String(guest.telefono || '').trim();
                    }
                    if (cartCustomerCedula && !String(cartCustomerCedula.value || '').trim()) {
                        cartCustomerCedula.value = String(guest.cedula || '').trim();
                    }
                    if (cartCustomerAddress && !String(cartCustomerAddress.value || '').trim()) {
                        cartCustomerAddress.value = String(guest.direccion || '').trim();
                    }
                    if (guest.delivery_type === 'envio' && cartDeliveryEnvio) {
                        cartDeliveryEnvio.checked = true;
                    } else if (cartDeliveryRetiro) {
                        cartDeliveryRetiro.checked = true;
                    }
                }
                updateDeliveryUI();
            }

            function collectCartCustomerMeta() {
                const nombre = String(cartCustomerName?.value || '').trim();
                const email = String(cartCustomerEmail?.value || '').trim();
                const telefono = String(cartCustomerPhone?.value || '').trim();
                const cedula = String(cartCustomerCedula?.value || '').replace(/\D/g, '');
                const isEnvio = cartDeliveryEnvio && cartDeliveryEnvio.checked;
                const deliveryType = isEnvio ? 'envio' : 'retiro';
                const direccion = String(cartCustomerAddress?.value || '').trim();

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
                if (cedula.length < 7) {
                    window.alert('Ingresa una cedula valida.');
                    cartCustomerCedula?.focus();
                    return null;
                }
                if (isEnvio && !direccion) {
                    window.alert('Por favor ingresá tu dirección para el envío a domicilio.');
                    cartCustomerAddress?.focus();
                    return null;
                }

                const addressText = isEnvio
                    ? ('Envío a domicilio: ' + direccion)
                    : 'Retiro en el comercio';

                if (typeof saveGuest === 'function') {
                    saveGuest({ nombre, email, telefono, cedula, direccion, delivery_type: deliveryType });
                }
                return { nombre, email, telefono, cedula, direccion, delivery_type: deliveryType, address: addressText };
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
            }

            document.addEventListener('click', (e) => {
                const addBtn = e.target.closest('[data-add-to-cart]');
                if (addBtn) {
                    const card = addBtn.closest('.prod-card');
                    if (!card) return;
                    addToCart(
                        card.dataset.productId,
                        card.dataset.productName,
                        card.dataset.productPrice,
                        1,
                        card.dataset.productVariant || 0,
                        card.dataset.productVariantLabel || '',
                        card.dataset.productOriginalPrice || card.dataset.productPrice
                    );
                    addBtn.classList.add('is-added');
                    const prev = addBtn.innerHTML;
                    addBtn.innerHTML = '<i class="bx bx-check" aria-hidden="true"></i> Agregado';
                    setTimeout(() => {
                        addBtn.classList.remove('is-added');
                        addBtn.innerHTML = prev;
                    }, 1200);
                }
            });

            if (hasProducts) {
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
            const bookingBarberField = document.getElementById('booking-barber-field');
            const bookingBarberSelect = document.getElementById('booking-barber-select');
            const bookingBarberHint = document.getElementById('booking-barber-hint');
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
            let currentProfessionalFilter = null;
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
                    if (bookingBarberSelect && bookingBarberSelect.value) {
                        params.set('id_barber', bookingBarberSelect.value);
                    }
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
                        const p = productById(id);
                        if (!p) return;
                        const variant = variantForProduct(p, 0);
                        addToCart(p.id, p.name, variant.price, 1, variant.index || 0, variant.label || '', variant.originalPrice || variant.price);
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
                    bookingPanel.open = false;
                    bookingPanel.hidden = false;
                }
                if (cartPanel) {
                    const body = document.getElementById('cart-history-body');
                    if (body) body.innerHTML = html;
                    cartPanel.open = false;
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

            function professionalById(id) {
                const key = String(id || '');
                return professionalsCatalog.find((item) => String(item.id) === key) || null;
            }

            function serviceProfessionalIdsFromCard(card) {
                if (!card) return [];
                return String(card.getAttribute('data-svc-professionals') || '')
                    .split(',')
                    .map((id) => id.trim())
                    .filter(Boolean);
            }

            function serviceCardById(serviceId) {
                return document.querySelector('.svc-card[data-svc-id="' + String(serviceId || '').replace(/["\\]/g, '\\$&') + '"]');
            }

            function professionalsForService(serviceId, card) {
                const ids = serviceProfessionalIdsFromCard(card || serviceCardById(serviceId));
                if (!ids.length) return [];
                return professionalsCatalog.filter((item) => ids.includes(String(item.id)));
            }

            function setBookingBarberHint(text) {
                if (!bookingBarberHint) return;
                const value = String(text || '').trim();
                bookingBarberHint.textContent = value;
                bookingBarberHint.hidden = value === '';
            }

            function updateBookingBarberOptions(serviceId, preferredId, card) {
                if (!bookingBarberField || !bookingBarberSelect) return;
                const professionals = professionalsForService(serviceId, card);
                bookingBarberSelect.innerHTML = '';
                if (!professionals.length) {
                    bookingBarberField.hidden = true;
                    bookingBarberSelect.disabled = true;
                    setBookingBarberHint('');
                    return;
                }
                bookingBarberField.hidden = false;
                bookingBarberSelect.disabled = false;
                const preferred = preferredId ? String(preferredId) : '';
                if (professionals.length > 1) {
                    const empty = document.createElement('option');
                    empty.value = '';
                    empty.textContent = 'Elegí profesional';
                    bookingBarberSelect.appendChild(empty);
                }
                professionals.forEach((professional) => {
                    const option = document.createElement('option');
                    option.value = String(professional.id);
                    option.textContent = professional.name || 'Profesional';
                    bookingBarberSelect.appendChild(option);
                });
                const hasPreferred = preferred && professionals.some((item) => String(item.id) === preferred);
                if (hasPreferred) {
                    bookingBarberSelect.value = preferred;
                } else if (professionals.length === 1) {
                    bookingBarberSelect.value = String(professionals[0].id);
                } else {
                    bookingBarberSelect.value = '';
                }
                const selected = professionalById(bookingBarberSelect.value);
                setBookingBarberHint(
                    professionals.length === 1 && selected
                        ? 'Reserva con ' + selected.name + '.'
                        : 'Elegí quién querés que te atienda.'
                );
            }

            function applyProfessionalServiceFilter(professionalId) {
                const selectedId = professionalId ? String(professionalId) : '';
                currentProfessionalFilter = selectedId || null;
                const selected = professionalById(selectedId);
                document.querySelectorAll('[data-professional-card]').forEach((card) => {
                    const isActive = selectedId && card.getAttribute('data-professional-id') === selectedId;
                    card.classList.toggle('is-active', !!isActive);
                });
                document.querySelectorAll('.svc-card').forEach((card) => {
                    const ids = serviceProfessionalIdsFromCard(card);
                    card.hidden = !!selectedId && !ids.includes(selectedId);
                });
                const status = document.querySelector('[data-professional-service-filter]');
                const statusText = document.querySelector('[data-professional-service-filter-text]');
                if (status) status.hidden = !selectedId;
                if (statusText && selected) {
                    statusText.textContent = 'Mostrando servicios de ' + selected.name + '.';
                }
                if (selectedId) {
                    document.getElementById('servicios')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            }

            document.querySelectorAll('[data-professional-filter]').forEach((btn) => {
                btn.addEventListener('click', () => {
                    if (btn.disabled) return;
                    applyProfessionalServiceFilter(btn.getAttribute('data-professional-filter'));
                });
            });
            document.querySelectorAll('[data-clear-professional-filter]').forEach((btn) => {
                btn.addEventListener('click', () => applyProfessionalServiceFilter(null));
            });
            if (bookingBarberSelect) {
                bookingBarberSelect.addEventListener('change', () => {
                    const selected = professionalById(bookingBarberSelect.value);
                    setBookingBarberHint(selected ? ('Reserva con ' + selected.name + '.') : 'Elegí quién querés que te atienda.');
                    loadSlots();
                });
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

            function openModal(svcId, svcName, svcPrice, preferredProfessionalId = null) {
                alertBox.hidden = true;
                form.reset();
                svcIdInput.value = svcId;
                svcNameInput.value = svcName;
                currentBookingServicePrice = Math.max(0, Number(svcPrice) || 0);
                updateBookingBarberOptions(svcId, preferredProfessionalId, serviceCardById(svcId));
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
                    const ids = serviceProfessionalIdsFromCard(card);
                    const preferredProfessionalId = currentProfessionalFilter && ids.includes(currentProfessionalFilter)
                        ? currentProfessionalFilter
                        : null;
                    openModal(card.dataset.svcId, card.dataset.svcName, card.dataset.svcPrice, preferredProfessionalId);
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
                            cedula: booking.cedula || '',
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
                if (bookingBarberField && !bookingBarberField.hidden && bookingBarberSelect && !bookingBarberSelect.value) {
                    alertBox.className = 'alert alert--error';
                    alertBox.innerHTML = '<i class="bx bx-error-circle"></i> Elegí el profesional que querés para este servicio.';
                    alertBox.hidden = false;
                    bookingBarberSelect.focus();
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
                        profesional: bookingBarberSelect && bookingBarberSelect.value
                            ? (professionalById(bookingBarberSelect.value)?.name || '')
                            : '',
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
            var currencySymbol = <?= json_encode($currencySymbol, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
            var currencyDecimals = <?= (int)$currencyDecimals ?>;
            function moneyLabel(value) {
                var num = Number(value);
                if (!Number.isFinite(num)) num = 0;
                try {
                    return currencySymbol + new Intl.NumberFormat('es-UY', {
                        minimumFractionDigits: currencyDecimals,
                        maximumFractionDigits: currencyDecimals
                    }).format(num);
                } catch (_) {
                    return currencySymbol + num.toFixed(currencyDecimals);
                }
            }
            document.querySelectorAll('[data-gallery]').forEach(function(gallery) {
                var track = gallery.querySelector('[data-track]');
                var prev = gallery.querySelector('[data-dir="-1"]');
                var next = gallery.querySelector('[data-dir="1"]');
                var dots = gallery.querySelector('[data-dots]');
                var slides = track ? track.querySelectorAll('.prod-gallery__slide') : [];
                if (!track || slides.length < 1) return;

                var current = 0;
                var total = slides.length;

                function syncProductVariant() {
                    var slide = slides[current];
                    var card = gallery.closest('.prod-card');
                    if (!slide || !card) return;
                    var price = Number(slide.getAttribute('data-variant-price') || card.dataset.productPrice || 0);
                    var original = Number(slide.getAttribute('data-variant-original-price') || price);
                    var label = slide.getAttribute('data-variant-label') || '';
                    card.dataset.productPrice = String(price);
                    card.dataset.productOriginalPrice = String(original);
                    card.dataset.productVariant = slide.getAttribute('data-variant-index') || String(current);
                    card.dataset.productVariantLabel = label;
                    var priceEl = card.querySelector('[data-product-price-label]');
                    if (priceEl) {
                        var old = original > price
                            ? '<span class="prod-card__price-old">' + moneyLabel(original) + '</span>'
                            : '';
                        priceEl.innerHTML = old + '<strong>' + moneyLabel(price) + '</strong>';
                    }
                    var labelEl = card.querySelector('[data-product-variant-label-view]');
                    if (labelEl) {
                        labelEl.textContent = label;
                        labelEl.hidden = !label;
                    }
                }

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
                    syncProductVariant();
                }

                syncProductVariant();
                if (slides.length < 2) return;

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

                gallery.setAttribute('data-gallery-ready', '1');
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
                var swUrl = <?= json_encode(url('sw.js?v=' . $swVer), JSON_UNESCAPED_SLASHES) ?>;
                window.addEventListener('load', function() {
                    navigator.serviceWorker.register(swUrl, { scope: '/' }).then(function(reg) {
                        if (reg && typeof reg.update === 'function') {
                            reg.update().catch(function(){});
                        }
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
