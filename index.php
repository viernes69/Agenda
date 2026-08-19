<?php
/**
 * Agendarte - Router genérico
 *
 * Detecta automáticamente si la URL es:
 *   - /agenduy.uy/                   -> landing principal
 *   - /agenduy.uy/la-estetica/       -> sitio público de ese comercio
 *   - /agenduy.uy/admin/             -> redirige al login super admin
 *
 * Si venís a un index.php dentro de un sub-comercio, también funciona
 * (los index.php de cada comercio son un wrapper de este router).
 */
declare(strict_types=1);

$config = require __DIR__ . '/src/Core/bootstrap.php';

use Agenduy\Core\Auth;
use Agenduy\Core\Database;
use Agenduy\Core\GoogleAuth;
use Agenduy\Core\LandingContent;
use Agenduy\Core\MembershipPlan;
use Agenduy\Core\PlatformSettings;

// Legacy admin config API handler
$requestUri = (string)($_SERVER['REQUEST_URI'] ?? '');
if (stripos($requestUri, '/src/API/AdminConfig.php') !== false) {
    require __DIR__ . '/template/src/API/AdminConfig.php';
    exit;
}

// ¿Hay un slug de comercio en la URL?
$slug = current_slug();

if ($slug !== null) {
    // Es un comercio: renderizar el sitio del comercio
    require __DIR__ . '/src/Core/commerce_view.php';
    agenduy_render_commerce($slug);
    exit;
}

// No es un comercio: mostrar la landing principal
Auth::start();
$db = Database::getInstance();
$authUser = Auth::check() ? Auth::user() : null;
$profileUrl = ($authUser !== null) ? Auth::dashboardUrl($authUser) : null;

// Rubros
$rubros = $db->fetchAll('SELECT * FROM rubros WHERE activo = 1 ORDER BY orden ASC, nombre COLLATE NOCASE ASC');

// Comercios destacados
$destacados = $db->fetchAll(
    "SELECT c.*, r.nombre AS rubro_nombre
     FROM commerces c
     LEFT JOIN rubros r ON r.id_rubro = c.id_rubro
     WHERE c.status IN ('trial','active')
     ORDER BY c.created_at DESC
     LIMIT 12"
);

$planesActivos = $db->fetchAll(
    "SELECT * FROM memberships WHERE activo = 1 ORDER BY precio ASC, id_membership ASC"
);
$planDestacado = $planesActivos[0] ?? null;

$cfgRow = $db->fetchOne("SELECT * FROM payment_provider_config WHERE provider = 'mercadopago'");
$mpConfig = $cfgRow ? json_decode((string)$cfgRow['config_json'], true) : [];
$mpPublicKey = (string)($mpConfig['public_key'] ?? '');
$freeTrial = (int)($planDestacado['trial_dias'] ?? 30);
$googleClientId = GoogleAuth::isEnabled() ? GoogleAuth::clientId() : '';
$platformContact = PlatformSettings::contact();
$platformInstagramUrl = $platformContact['instagram_url'];
$platformWhatsAppUrl = $platformContact['whatsapp_url'];

$clientAvatars = [];
$clientDir = __DIR__ . '/src/img/clients';
if (is_dir($clientDir)) {
    $files = scandir($clientDir);
    if ($files !== false) {
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            $ext = strtolower((string)pathinfo($file, PATHINFO_EXTENSION));
            if ($ext === '' || !in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif', 'svg'], true)) continue;
            $clientAvatars[] = 'src/img/clients/' . $file;
            if (count($clientAvatars) >= 5) break;
        }
    }
}

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$stylesPath = __DIR__ . '/src/css/styles.css';
$stylesVer = is_file($stylesPath) ? (string)filemtime($stylesPath) : (string)time();
$logoIconPath = __DIR__ . '/src/media/logo/logo-icon.png';
$logoVer = is_file($logoIconPath) ? (string)filemtime($logoIconPath) : (string)time();
$loaderLogoPath = __DIR__ . '/src/media/logo/logo-icon-loader.png';
$loaderLogoVer = is_file($loaderLogoPath) ? (string)filemtime($loaderLogoPath) : $logoVer;
$faviconPath = __DIR__ . '/src/img/favicon/favicon.png';
$faviconVer = is_file($faviconPath) ? (string)filemtime($faviconPath) : $logoVer;
$siteUrl = rtrim(url(''), '/');
$seoTitle = 'Agendarte UY | Reservas online y agenda digital en Uruguay';
$seoDescription = LandingContent::SITE_DESCRIPTION;
$seoKeywords = implode(', ', LandingContent::metaKeywords());

$mainJsVer = is_file(__DIR__ . '/src/js/main.js') ? (string)filemtime(__DIR__ . '/src/js/main.js') : (string)time();
$regJsVer = is_file(__DIR__ . '/src/js/register/modal.js') ? (string)filemtime(__DIR__ . '/src/js/register/modal.js') : (string)time();
$themeJsVer = is_file(__DIR__ . '/src/js/theme.js') ? (string)filemtime(__DIR__ . '/src/js/theme.js') : (string)time();
$loginJsVer = is_file(__DIR__ . '/src/js/site-login.js') ? (string)filemtime(__DIR__ . '/src/js/site-login.js') : (string)time();
$authJsVer = is_file(__DIR__ . '/src/js/auth-google.js') ? (string)filemtime(__DIR__ . '/src/js/auth-google.js') : (string)time();
$cursosJsVer = is_file(__DIR__ . '/src/js/cursos-modal.js') ? (string)filemtime(__DIR__ . '/src/js/cursos-modal.js') : (string)time();
$benJsVer = is_file(__DIR__ . '/src/js/beneficios-modal.js') ? (string)filemtime(__DIR__ . '/src/js/beneficios-modal.js') : (string)time();
$aboutJsVer = is_file(__DIR__ . '/src/js/about-modal.js') ? (string)filemtime(__DIR__ . '/src/js/about-modal.js') : (string)time();
$buscarJsVer = is_file(__DIR__ . '/src/js/buscar-modal.js') ? (string)filemtime(__DIR__ . '/src/js/buscar-modal.js') : (string)time();
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <script>
    /* Aplica el tema guardado antes del primer pintado para evitar flash */
    (function () {
      var theme = 'light';
      try {
        var saved = localStorage.getItem('agendarte-theme');
        if (saved !== 'dark' && saved !== 'light') {
          saved = localStorage.getItem('agenduy-theme');
        }
        if (saved === 'dark' || saved === 'light') theme = saved;
      } catch (e) {}
      document.documentElement.setAttribute('data-theme', theme);
    })();
  </script>
  <script src="<?= h(url('src/js/main.js?v=' . $mainJsVer)) ?>" defer></script>
  <?php if (is_file(__DIR__ . '/src/js/theme.js')): ?>
  <script src="<?= h(url('src/js/theme.js?v=' . $themeJsVer)) ?>" defer></script>
  <?php endif; ?>
  <script src="<?= h(url('src/js/site-login.js?v=' . $loginJsVer)) ?>" defer></script>
  <script src="<?= h(url('src/js/auth-google.js?v=' . $authJsVer)) ?>" defer></script>
  <script src="<?= h(url('src/js/cursos-modal.js?v=' . $cursosJsVer)) ?>" defer></script>
  <script src="<?= h(url('src/js/beneficios-modal.js?v=' . $benJsVer)) ?>" defer></script>
  <script src="<?= h(url('src/js/about-modal.js?v=' . $aboutJsVer)) ?>" defer></script>
  <script src="<?= h(url('src/js/buscar-modal.js?v=' . $buscarJsVer)) ?>" defer></script>
  <script src="<?= h(url('src/js/register/modal.js?v=' . $regJsVer)) ?>" defer></script>
  <title><?= h($seoTitle) ?></title>
  <meta name="description" content="<?= h($seoDescription) ?>">
  <meta name="keywords" content="<?= h($seoKeywords) ?>">
  <meta name="author" content="Agendarte UY">
  <meta name="robots" content="index,follow">
  <link rel="canonical" href="<?= h($siteUrl . '/') ?>">
  <meta property="og:locale" content="es_UY">
  <meta property="og:type" content="website">
  <meta property="og:site_name" content="Agendarte UY">
  <meta property="og:title" content="<?= h($seoTitle) ?>">
  <meta property="og:description" content="<?= h($seoDescription) ?>">
  <meta property="og:url" content="<?= h($siteUrl . '/') ?>">
  <meta property="og:image" content="<?= h($siteUrl . '/src/media/logo/og-image.png') ?>">
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="<?= h($seoTitle) ?>">
  <meta name="twitter:description" content="<?= h($seoDescription) ?>">
  <script type="application/ld+json"><?= LandingContent::jsonLdOrganization($siteUrl) ?></script>
  <script type="application/ld+json"><?= LandingContent::jsonLdFaq() ?></script>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    /* Header critico: evita layout roto si el CSS principal tarda o esta cacheado */
    .site-header__inner{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:nowrap}
    .site-header__brand-text{display:flex;flex-direction:column;line-height:1.15;min-width:0}
    .site-header__actions{display:flex;align-items:center;gap:8px;flex:0 0 auto;margin:0;padding:0}
    .site-header__user{position:relative}
    .site-header__login-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border:0;border-radius:999px;background:#6d28d9;color:#fff;font-weight:600;cursor:pointer;-webkit-appearance:none;appearance:none}
    .site-login-dropdown{position:absolute;top:calc(100% + 10px);right:0;min-width:280px;background:#fff;border:1px solid #e4e4f0;border-radius:14px;padding:18px;z-index:120;box-shadow:0 18px 40px -12px rgba(15,23,42,.18)}
    .site-login-dropdown[hidden]{display:none!important}
    .site-login-form[hidden]{display:none!important}
  </style>
  <link rel="stylesheet" href="<?= h(url('src/css/styles.css?v=' . $stylesVer)) ?>">
  <link rel="stylesheet" href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css">
  <link rel="icon" type="image/png" sizes="32x32" href="<?= h(url('src/img/favicon/favicon.png?v=' . $faviconVer)) ?>">
  <link rel="apple-touch-icon" href="<?= h(url('src/media/logo/logo-icon.png?v=' . $logoVer)) ?>">
  <meta name="csrf-token" content="<?= h(\Agenduy\Core\CSRF::generate('public_booking')) ?>">
</head>
<body>
<div id="page-loader" role="status" aria-live="assertive" aria-label="Cargando contenido">
  <div class="page-loader__glow"></div>
  <div class="page-loader__card">
    <img src="<?= h(url('src/media/logo/logo-icon-loader.png?v=' . $loaderLogoVer)) ?>"
         alt="Agendarte UY"
         class="page-loader__logo"
         width="156"
         height="164"
         decoding="async">
    <div class="page-loader__spinner">
      <span></span>
    </div>
    <p class="page-loader__title">Preparando tu experiencia</p>
    <p class="page-loader__hint">Optimizado para conexiones lentas. Aguarda un instante...</p>
    <div class="page-loader__progress">
      <div class="page-loader__progress-bar"></div>
    </div>
  </div>
</div>
  <header class="site-header">
    <div class="site-header__inner">
      <a href="<?= h(url('')) ?>" class="site-header__brand" aria-label="Inicio" style="display:flex;align-items:center;gap:8px;font-weight:700;font-size:1.25rem;color:var(--text, #1e293b);text-decoration:none;">
        <img src="<?= h(url('src/media/logo/logo-icon.png?v=' . $logoVer)) ?>"
             alt="Agendarte UY"
             class="brand-logo"
             width="28" height="28" fetchpriority="high">
        <span>Agendarte <span class="brand-uy" style="color:var(--primary, #6366f1);">UY</span></span>
      </a>
      <nav class="site-header__actions" aria-label="Acciones principales">
        <?php if ($platformInstagramUrl !== ''): ?>
        <a class="site-header__icon" href="<?= h($platformInstagramUrl) ?>" target="_blank" rel="noopener" aria-label="Instagram">
          <i class="bx bxl-instagram" aria-hidden="true"></i>
        </a>
        <?php endif; ?>
        <?php if ($platformWhatsAppUrl !== ''): ?>
        <a class="site-header__icon" href="<?= h($platformWhatsAppUrl) ?>" target="_blank" rel="noopener" aria-label="WhatsApp">
          <i class="bx bxl-whatsapp" aria-hidden="true"></i>
        </a>
        <?php endif; ?>
        <button type="button" id="theme-toggle" class="theme-toggle" aria-pressed="false" aria-label="Cambiar a tema oscuro" title="Tema oscuro">
          <i class="bx bx-sun theme-toggle__icon theme-toggle__icon--sun" aria-hidden="true"></i>
          <i class="bx bx-moon theme-toggle__icon theme-toggle__icon--moon" aria-hidden="true"></i>
        </button>
        <div class="site-header__user" id="site-user">
          <?php if ($profileUrl): ?>
          <a href="<?= h($profileUrl) ?>" class="site-header__login-btn" id="site-profile-link">
            <i class="bx bx-user-circle" aria-hidden="true"></i>
            <span class="site-header__login-label">Perfil</span>
          </a>
          <?php else:
            $adminLoginCsrf = \Agenduy\Core\CSRF::generate('admin_login');
          ?>
          <button type="button" class="site-header__login-btn" id="site-login-toggle" aria-expanded="false" aria-haspopup="dialog" aria-controls="site-login-dropdown">
            <i class="bx bx-user-circle" aria-hidden="true"></i>
            <span class="site-header__login-label">Iniciar sesion</span>
            <i class="bx bx-chevron-down site-header__login-caret" aria-hidden="true"></i>
          </button>
          <div class="site-login-dropdown" id="site-login-dropdown" role="dialog" aria-label="Iniciar sesion" hidden>
            <div class="site-login-tabs" role="tablist">
              <button type="button" class="site-login-tabs__btn is-active" data-login-tab="password" role="tab" aria-selected="true">Login</button>
              <button type="button" class="site-login-tabs__btn" data-login-tab="magic" role="tab" aria-selected="false">Registro rápido</button>
            </div>
            <form method="post" action="<?= h(url('admin/login.php')) ?>" class="site-login-form" id="site-login-form" data-login-panel="password" novalidate>
              <input type="hidden" name="_csrf" value="<?= h($adminLoginCsrf) ?>">
              <p class="site-login-form__hint">Ingresa el Email y contraseña para ingresar a tu cuenta.</p>
              <label class="site-login-form__field">
                <span>Email</span>
                <input type="email" name="email" required autocomplete="username" placeholder="tu@email.com">
              </label>
              <label class="site-login-form__field">
                <span>Contrasena</span>
                <input type="password" name="password" required autocomplete="current-password" placeholder="********">
              </label>
              <button type="submit" class="site-login-form__btn">Ingresar</button>
            </form>
            <form class="site-login-form" id="site-login-magic-form" data-login-panel="magic" hidden novalidate>
              <input type="hidden" name="_csrf" value="<?= h($adminLoginCsrf) ?>">
              <p class="site-login-form__hint">Registro rápido: te enviamos un link seguro a tu correo. Si no tenés cuenta, se crea al abrirlo. Sin contraseña.</p>
              <label class="site-login-form__field">
                <span>Email</span>
                <input type="email" name="email" required autocomplete="email" placeholder="tu@email.com">
              </label>
              <button type="submit" class="site-login-form__btn">Registrarme y recibir el link</button>
            </form>
            <?php if ($googleClientId !== ''): ?>
            <div class="site-login-divider"><span>o</span></div>
            <div id="site-login-google" class="site-login-google"></div>
            <?php endif; ?>
            <p class="site-login-form__msg" id="site-login-msg" role="status" aria-live="polite"></p>
          </div>
          <?php endif; ?>
        </div>
      </nav>
    </div>
  </header>

<main class="landing">
  <section class="hero" aria-label="Propuesta de valor">
    <div class="hero-bg-orbs">
      <div class="orb orb-1"></div>
      <div class="orb orb-2"></div>
    </div>
    <p class="hero__badge">
      <i class="bx bx-gift" aria-hidden="true"></i>
      Gratis para siempre · sin tarjeta
    </p>
    <h1 class="hero__title">Llevá tu negocio o agenda<br><span class="hero__title-accent">al siguiente nivel</span></h1>
    <p class="hero__subtitle">
      Creá tu agenda de reservas online o armá tu catálogo digital con carrito de compras.
      Con Agendarte UY, tu negocio está disponible las 24 horas, automatiza tus ventas y organiza tu tiempo en una plataforma profesional.
    </p>
    <p class="hero__audience">
      Ideal para tiendas online, comercios, salones de belleza, clínicas, barberías, consultorios, lavaderos, talleres y servicios independientes.
    </p>
    <div class="hero__actions">
      <button type="button" class="btn-primary hero__cta plan-btn" data-rubro-id="0" data-rubro-nombre="">
        Registrá tu negocio
        <i class="bx bx-right-arrow-alt" aria-hidden="true"></i>
      </button>
      <a class="hero__secondary" href="<?= h(url('categorias/')) ?>">Buscar servicios</a>
    </div>
  </section>

  <section class="rubros-section" id="rubros" aria-label="Elige tu tipo de negocio">
    <div class="section-heading">
      <p class="section-heading__eyebrow">Soluciones</p>
      <h2 class="section-heading__title">¿Qué tipo de negocio querés crear?</h2>
      <p class="section-heading__text">Seleccioná la opción que mejor se adapte a tu necesidad para comenzar el registro.</p>
    </div>

    <!-- Toggle de Tipo de Negocio (Tabs) -->
    <div class="business-type-tabs" style="display: flex; justify-content: center; gap: 1rem; margin-bottom: 3rem; flex-wrap: wrap;">
      <button type="button" class="tab-btn active" data-tab-target="agenda-rubros" style="padding: 14px 28px; border-radius: 999px; border: 2px solid var(--primary); background: var(--primary); color: #fff; font-weight: 600; cursor: pointer; transition: all 0.3s ease; display: inline-flex; align-items: center; gap: 8px; font-family: inherit;">
        <i class="bx bx-calendar" style="font-size: 1.25rem;"></i>
        <span>Agenda Digital / Servicios</span>
      </button>
      <button type="button" class="tab-btn" data-tab-target="tienda-rubros" style="padding: 14px 28px; border-radius: 999px; border: 2px solid var(--border); background: var(--surface); color: var(--text); font-weight: 600; cursor: pointer; transition: all 0.3s ease; display: inline-flex; align-items: center; gap: 8px; font-family: inherit;">
        <i class="bx bx-store" style="font-size: 1.25rem;"></i>
        <span>Tienda Online / Catálogo</span>
      </button>
    </div>

    <!-- Contenido de las Tabs -->
    <div class="tab-contents">
      <!-- TAB AGENDA DIGITAL -->
      <div class="tab-pane active" id="agenda-rubros">
        <div class="plan-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 24px; max-width: 1200px; margin: 0 auto; padding: 0 1rem; width: 100%;">
          
          <!-- CLINICA DENTAL -->
          <article class="plan-card" style="position: relative; padding: clamp(24px, 2.5vw, 32px); border-radius: var(--radius-lg); background: var(--surface); border: 1px solid var(--border); box-shadow: var(--shadow-sm); display: flex; flex-direction: column; justify-content: space-between; gap: 16px; transition: transform .35s var(--ease-spring), box-shadow .35s var(--ease-spring), border-color .35s ease;" onmouseover="this.style.transform='translateY(-8px) scale(1.02)'; this.style.boxShadow='var(--shadow-lg), var(--shadow-glow)'; this.style.borderColor='rgba(124, 58, 237, 0.4)'" onmouseout="this.style.transform='none'; this.style.boxShadow='var(--shadow-sm)'; this.style.borderColor='var(--border)'">
            <div style="display: flex; flex-direction: column; align-items: center; text-align: center; gap: 12px;">
              <img src="<?= h(url('src/media/carousel/dentistas.jpg')) ?>" alt="Dentistas" style="width: 100px; height: 100px; border-radius: 50%; border: 4px solid var(--surface-3); object-fit: cover; box-shadow: var(--shadow-md);">
              <h3 style="font-weight: 700; font-size: 1.25rem; color: var(--text); margin-top: 0.5rem;">Clínica & Dentistas</h3>
              <div class="hc-highlight">Gratis ilimitado</div>
              <ul class="plan-card__features" aria-label="Características de Clínica Dental" style="margin-top: 1rem; width: 100%; text-align: left; padding: 0;">
                <li class="is-included" style="display: flex; align-items: center; gap: 8px; font-size: 0.9rem; margin-bottom: 8px;"><span style="color: var(--success); font-weight: bold;">✓</span> Gestión de pacientes e historial</li>
                <li class="is-included" style="display: flex; align-items: center; gap: 8px; font-size: 0.9rem; margin-bottom: 8px;"><span style="color: var(--success); font-weight: bold;">✓</span> Recordatorios por WhatsApp</li>
                <li class="is-included" style="display: flex; align-items: center; gap: 8px; font-size: 0.9rem; margin-bottom: 8px;"><span style="color: var(--success); font-weight: bold;">✓</span> Atención en consultorio o local</li>
              </ul>
            </div>
            <button type="button" class="plan-btn" data-rubro-id="7" data-rubro-nombre="Dentistas" style="width: 100%; margin-top: auto;">Crear mi Agenda</button>
          </article>

          <!-- LAVADERO DE AUTOS -->
          <article class="plan-card" style="position: relative; padding: clamp(24px, 2.5vw, 32px); border-radius: var(--radius-lg); background: var(--surface); border: 1px solid var(--border); box-shadow: var(--shadow-sm); display: flex; flex-direction: column; justify-content: space-between; gap: 16px; transition: transform .35s var(--ease-spring), box-shadow .35s var(--ease-spring), border-color .35s ease;" onmouseover="this.style.transform='translateY(-8px) scale(1.02)'; this.style.boxShadow='var(--shadow-lg), var(--shadow-glow)'; this.style.borderColor='rgba(124, 58, 237, 0.4)'" onmouseout="this.style.transform='none'; this.style.boxShadow='var(--shadow-sm)'; this.style.borderColor='var(--border)'">
            <div style="display: flex; flex-direction: column; align-items: center; text-align: center; gap: 12px;">
              <img src="<?= h(url('src/media/carousel/lavaderos.jpg')) ?>" alt="Lavaderos" style="width: 100px; height: 100px; border-radius: 50%; border: 4px solid var(--surface-3); object-fit: cover; box-shadow: var(--shadow-md);">
              <h3 style="font-weight: 700; font-size: 1.25rem; color: var(--text); margin-top: 0.5rem;">Lavadero & Talleres</h3>
              <div class="hc-highlight">Gratis ilimitado</div>
              <ul class="plan-card__features" aria-label="Características de Lavadero de Autos" style="margin-top: 1rem; width: 100%; text-align: left; padding: 0;">
                <li class="is-included" style="display: flex; align-items: center; gap: 8px; font-size: 0.9rem; margin-bottom: 8px;"><span style="color: var(--success); font-weight: bold;">✓</span> Control de turnos y boxes</li>
                <li class="is-included" style="display: flex; align-items: center; gap: 8px; font-size: 0.9rem; margin-bottom: 8px;"><span style="color: var(--success); font-weight: bold;">✓</span> Notificación automática "Listo"</li>
                <li class="is-included" style="display: flex; align-items: center; gap: 8px; font-size: 0.9rem; margin-bottom: 8px;"><span style="color: var(--success); font-weight: bold;">✓</span> Pago integrado o al retirar</li>
              </ul>
            </div>
            <button type="button" class="plan-btn" data-rubro-id="9" data-rubro-nombre="Lavaderos" style="width: 100%; margin-top: auto;">Crear mi Agenda</button>
          </article>

          <!-- BARBERIA -->
          <article class="plan-card" style="position: relative; padding: clamp(24px, 2.5vw, 32px); border-radius: var(--radius-lg); background: var(--surface); border: 1px solid var(--border); box-shadow: var(--shadow-sm); display: flex; flex-direction: column; justify-content: space-between; gap: 16px; transition: transform .35s var(--ease-spring), box-shadow .35s var(--ease-spring), border-color .35s ease;" onmouseover="this.style.transform='translateY(-8px) scale(1.02)'; this.style.boxShadow='var(--shadow-lg), var(--shadow-glow)'; this.style.borderColor='rgba(124, 58, 237, 0.4)'" onmouseout="this.style.transform='none'; this.style.boxShadow='var(--shadow-sm)'; this.style.borderColor='var(--border)'">
            <div style="display: flex; flex-direction: column; align-items: center; text-align: center; gap: 12px;">
              <img src="<?= h(url('src/media/carousel/barberias.jpg')) ?>" alt="Barbería" style="width: 100px; height: 100px; border-radius: 50%; border: 4px solid var(--surface-3); object-fit: cover; box-shadow: var(--shadow-md);">
              <h3 style="font-weight: 700; font-size: 1.25rem; color: var(--text); margin-top: 0.5rem;">Barberías & Estética</h3>
              <div class="hc-highlight">Gratis ilimitado</div>
              <ul class="plan-card__features" aria-label="Características de Barbería" style="margin-top: 1rem; width: 100%; text-align: left; padding: 0;">
                <li class="is-included" style="display: flex; align-items: center; gap: 8px; font-size: 0.9rem; margin-bottom: 8px;"><span style="color: var(--success); font-weight: bold;">✓</span> Selección de profesional</li>
                <li class="is-included" style="display: flex; align-items: center; gap: 8px; font-size: 0.9rem; margin-bottom: 8px;"><span style="color: var(--success); font-weight: bold;">✓</span> Reservas 24/7 con seña online</li>
                <li class="is-included" style="display: flex; align-items: center; gap: 8px; font-size: 0.9rem; margin-bottom: 8px;"><span style="color: var(--success); font-weight: bold;">✓</span> Control de horarios del equipo</li>
              </ul>
            </div>
            <button type="button" class="plan-btn" data-rubro-id="2" data-rubro-nombre="Barbería" style="width: 100%; margin-top: auto;">Crear mi Agenda</button>
          </article>

          <!-- SERVICIOS A DOMICILIO -->
          <article class="plan-card" style="position: relative; padding: clamp(24px, 2.5vw, 32px); border-radius: var(--radius-lg); background: var(--surface); border: 1px solid var(--border); box-shadow: var(--shadow-sm); display: flex; flex-direction: column; justify-content: space-between; gap: 16px; transition: transform .35s var(--ease-spring), box-shadow .35s var(--ease-spring), border-color .35s ease;" onmouseover="this.style.transform='translateY(-8px) scale(1.02)'; this.style.boxShadow='var(--shadow-lg), var(--shadow-glow)'; this.style.borderColor='rgba(124, 58, 237, 0.4)'" onmouseout="this.style.transform='none'; this.style.boxShadow='var(--shadow-sm)'; this.style.borderColor='var(--border)'">
            <div style="display: flex; flex-direction: column; align-items: center; text-align: center; gap: 12px;">
              <img src="<?= h(url('src/media/carousel/coaches.jpg')) ?>" alt="Servicios a Domicilio" style="width: 100px; height: 100px; border-radius: 50%; border: 4px solid var(--surface-3); object-fit: cover; box-shadow: var(--shadow-md);">
              <h3 style="font-weight: 700; font-size: 1.25rem; color: var(--text); margin-top: 0.5rem;">Servicio a Domicilio</h3>
              <div class="hc-highlight">Gratis ilimitado</div>
              <ul class="plan-card__features" aria-label="Características de Consultorio" style="margin-top: 1rem; width: 100%; text-align: left; padding: 0;">
                <li class="is-included" style="display: flex; align-items: center; gap: 8px; font-size: 0.9rem; margin-bottom: 8px;"><span style="color: var(--success); font-weight: bold;">✓</span> Dirección del cliente integrada</li>
                <li class="is-included" style="display: flex; align-items: center; gap: 8px; font-size: 0.9rem; margin-bottom: 8px;"><span style="color: var(--success); font-weight: bold;">✓</span> Pagos integrados MP / WhatsApp</li>
                <li class="is-included" style="display: flex; align-items: center; gap: 8px; font-size: 0.9rem; margin-bottom: 8px;"><span style="color: var(--success); font-weight: bold;">✓</span> Calendario individual</li>
              </ul>
            </div>
            <button type="button" class="plan-btn" data-rubro-id="5" data-rubro-nombre="Coaching" style="width: 100%; margin-top: auto;">Crear mi Agenda</button>
          </article>

        </div>
      </div>

      <!-- TAB TIENDA ONLINE -->
      <div class="tab-pane" id="tienda-rubros" style="display: none;">
        <div class="plan-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 24px; max-width: 1200px; margin: 0 auto; padding: 0 1rem; width: 100%;">
          
          <!-- TIENDA COMPLETA / E-COMMERCE -->
          <article class="plan-card" style="position: relative; padding: clamp(24px, 2.5vw, 32px); border-radius: var(--radius-lg); background: var(--surface); border: 1px solid var(--border); box-shadow: var(--shadow-sm); display: flex; flex-direction: column; justify-content: space-between; gap: 16px; transition: transform .35s var(--ease-spring), box-shadow .35s var(--ease-spring), border-color .35s ease;" onmouseover="this.style.transform='translateY(-8px) scale(1.02)'; this.style.boxShadow='var(--shadow-lg), var(--shadow-glow)'; this.style.borderColor='rgba(124, 58, 237, 0.4)'" onmouseout="this.style.transform='none'; this.style.boxShadow='var(--shadow-sm)'; this.style.borderColor='var(--border)'">
            <div style="display: flex; flex-direction: column; align-items: center; text-align: center; gap: 12px;">
              <img src="<?= h(url('src/media/carousel/emprendedores.jpg')) ?>" alt="Tienda Online" style="width: 100px; height: 100px; border-radius: 50%; border: 4px solid var(--surface-3); object-fit: cover; box-shadow: var(--shadow-md);">
              <h3 style="font-weight: 700; font-size: 1.25rem; color: var(--text); margin-top: 0.5rem;">E-commerce & Ropa</h3>
              <div class="hc-highlight">Gratis ilimitado</div>
              <ul class="plan-card__features" aria-label="Características de Tienda" style="margin-top: 1rem; width: 100%; text-align: left; padding: 0;">
                <li class="is-included" style="display: flex; align-items: center; gap: 8px; font-size: 0.9rem; margin-bottom: 8px;"><span style="color: var(--success); font-weight: bold;">✓</span> Envíos a domicilio o retiro</li>
                <li class="is-included" style="display: flex; align-items: center; gap: 8px; font-size: 0.9rem; margin-bottom: 8px;"><span style="color: var(--success); font-weight: bold;">✓</span> Gestión de productos y stock</li>
                <li class="is-included" style="display: flex; align-items: center; gap: 8px; font-size: 0.9rem; margin-bottom: 8px;"><span style="color: var(--success); font-weight: bold;">✓</span> Pago WhatsApp o Mercado Pago</li>
              </ul>
            </div>
            <button type="button" class="plan-btn" data-rubro-id="13" data-rubro-nombre="Tienda" style="width: 100%; margin-top: auto;">Crear mi Tienda</button>
          </article>

          <!-- GASTRONOMIA -->
          <article class="plan-card" style="position: relative; padding: clamp(24px, 2.5vw, 32px); border-radius: var(--radius-lg); background: var(--surface); border: 1px solid var(--border); box-shadow: var(--shadow-sm); display: flex; flex-direction: column; justify-content: space-between; gap: 16px; transition: transform .35s var(--ease-spring), box-shadow .35s var(--ease-spring), border-color .35s ease;" onmouseover="this.style.transform='translateY(-8px) scale(1.02)'; this.style.boxShadow='var(--shadow-lg), var(--shadow-glow)'; this.style.borderColor='rgba(124, 58, 237, 0.4)'" onmouseout="this.style.transform='none'; this.style.boxShadow='var(--shadow-sm)'; this.style.borderColor='var(--border)'">
            <div style="display: flex; flex-direction: column; align-items: center; text-align: center; gap: 12px;">
              <img src="<?= h(url('src/media/carousel/gastronomia.png')) ?>" alt="Gastronomía" style="width: 100px; height: 100px; border-radius: 50%; border: 4px solid var(--surface-3); object-fit: cover; box-shadow: var(--shadow-md);">
              <h3 style="font-weight: 700; font-size: 1.25rem; color: var(--text); margin-top: 0.5rem;">Restaurantes & Delivery</h3>
              <div class="hc-highlight">Gratis ilimitado</div>
              <ul class="plan-card__features" aria-label="Características de Gastronomía" style="margin-top: 1rem; width: 100%; text-align: left; padding: 0;">
                <li class="is-included" style="display: flex; align-items: center; gap: 8px; font-size: 0.9rem; margin-bottom: 8px;"><span style="color: var(--success); font-weight: bold;">✓</span> Menú digital con fotos</li>
                <li class="is-included" style="display: flex; align-items: center; gap: 8px; font-size: 0.9rem; margin-bottom: 8px;"><span style="color: var(--success); font-weight: bold;">✓</span> Impresión automática de comandas</li>
                <li class="is-included" style="display: flex; align-items: center; gap: 8px; font-size: 0.9rem; margin-bottom: 8px;"><span style="color: var(--success); font-weight: bold;">✓</span> Pedidos inmediatos e impresión</li>
              </ul>
            </div>
            <button type="button" class="plan-btn" data-rubro-id="13" data-rubro-nombre="Tienda" style="width: 100%; margin-top: auto;">Crear mi Tienda</button>
          </article>

          <!-- MINIMARKET / ALMACEN -->
          <article class="plan-card" style="position: relative; padding: clamp(24px, 2.5vw, 32px); border-radius: var(--radius-lg); background: var(--surface); border: 1px solid var(--border); box-shadow: var(--shadow-sm); display: flex; flex-direction: column; justify-content: space-between; gap: 16px; transition: transform .35s var(--ease-spring), box-shadow .35s var(--ease-spring), border-color .35s ease;" onmouseover="this.style.transform='translateY(-8px) scale(1.02)'; this.style.boxShadow='var(--shadow-lg), var(--shadow-glow)'; this.style.borderColor='rgba(124, 58, 237, 0.4)'" onmouseout="this.style.transform='none'; this.style.boxShadow='var(--shadow-sm)'; this.style.borderColor='var(--border)'">
            <div style="display: flex; flex-direction: column; align-items: center; text-align: center; gap: 12px;">
              <img src="<?= h(url('src/media/carousel/minimarket.png')) ?>" alt="Minimarket" style="width: 100px; height: 100px; border-radius: 50%; border: 4px solid var(--surface-3); object-fit: cover; box-shadow: var(--shadow-md);">
              <h3 style="font-weight: 700; font-size: 1.25rem; color: var(--text); margin-top: 0.5rem;">Almacenes & Kioscos</h3>
              <div class="hc-highlight">Gratis ilimitado</div>
              <ul class="plan-card__features" aria-label="Características de Minimarket" style="margin-top: 1rem; width: 100%; text-align: left; padding: 0;">
                <li class="is-included" style="display: flex; align-items: center; gap: 8px; font-size: 0.9rem; margin-bottom: 8px;"><span style="color: var(--success); font-weight: bold;">✓</span> Catálogo rápido de mercadería</li>
                <li class="is-included" style="display: flex; align-items: center; gap: 8px; font-size: 0.9rem; margin-bottom: 8px;"><span style="color: var(--success); font-weight: bold;">✓</span> Retiro en local o envío rápido</li>
                <li class="is-included" style="display: flex; align-items: center; gap: 8px; font-size: 0.9rem; margin-bottom: 8px;"><span style="color: var(--success); font-weight: bold;">✓</span> Pagos seguros simplificados</li>
              </ul>
            </div>
            <button type="button" class="plan-btn" data-rubro-id="13" data-rubro-nombre="Tienda" style="width: 100%; margin-top: auto;">Crear mi Tienda</button>
          </article>

          <!-- PRODUCTOS DIGITALES -->
          <article class="plan-card" style="position: relative; padding: clamp(24px, 2.5vw, 32px); border-radius: var(--radius-lg); background: var(--surface); border: 1px solid var(--border); box-shadow: var(--shadow-sm); display: flex; flex-direction: column; justify-content: space-between; gap: 16px; transition: transform .35s var(--ease-spring), box-shadow .35s var(--ease-spring), border-color .35s ease;" onmouseover="this.style.transform='translateY(-8px) scale(1.02)'; this.style.boxShadow='var(--shadow-lg), var(--shadow-glow)'; this.style.borderColor='rgba(124, 58, 237, 0.4)'" onmouseout="this.style.transform='none'; this.style.boxShadow='var(--shadow-sm)'; this.style.borderColor='var(--border)'">
            <div style="display: flex; flex-direction: column; align-items: center; text-align: center; gap: 12px;">
              <img src="<?= h(url('src/media/carousel/digital.png')) ?>" alt="Productos Digitales" style="width: 100px; height: 100px; border-radius: 50%; border: 4px solid var(--surface-3); object-fit: cover; box-shadow: var(--shadow-md);">
              <h3 style="font-weight: 700; font-size: 1.25rem; color: var(--text); margin-top: 0.5rem;">Servicios & Digitales</h3>
              <div class="hc-highlight">Gratis ilimitado</div>
              <ul class="plan-card__features" aria-label="Características de Productos Digitales" style="margin-top: 1rem; width: 100%; text-align: left; padding: 0;">
                <li class="is-included" style="display: flex; align-items: center; gap: 8px; font-size: 0.9rem; margin-bottom: 8px;"><span style="color: var(--success); font-weight: bold;">✓</span> Entrega automática de links</li>
                <li class="is-included" style="display: flex; align-items: center; gap: 8px; font-size: 0.9rem; margin-bottom: 8px;"><span style="color: var(--success); font-weight: bold;">✓</span> Descargas directas post-pago</li>
                <li class="is-included" style="display: flex; align-items: center; gap: 8px; font-size: 0.9rem; margin-bottom: 8px;"><span style="color: var(--success); font-weight: bold;">✓</span> Enlaces e info digital</li>
              </ul>
            </div>
            <button type="button" class="plan-btn" data-rubro-id="8" data-rubro-nombre="Emprendedores" style="width: 100%; margin-top: auto;">Crear mi Tienda</button>
          </article>

        </div>
      </div>
    </div>
  </section>

  <section class="features-section" id="funcionalidades" style="margin-top: 5rem;">
    <div class="section-heading">
      <p class="section-heading__eyebrow">Todo lo que necesitas</p>
      <h2 class="section-heading__title">Todo lo que Necesitas para <span style="background: var(--brand-gradient); -webkit-background-clip: text; -webkit-text-fill-color: transparent;">Triunfar</span></h2>
      <p class="section-heading__text">Funcionalidades diseñadas específicamente para potenciar y hacer crecer tu negocio.</p>
    </div>
    
    <div class="features-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(min(300px, 100%), 1fr)); gap: 24px; max-width: 1200px; margin: 0 auto; width: 100%; padding: 0 1rem;">
      <!-- CARD 1 -->
      <div class="feature-card" style="background: var(--surface); padding: 24px; border-radius: var(--radius-lg); border: 1px solid var(--border); box-shadow: var(--shadow-sm); display: flex; gap: 16px; align-items: flex-start; transition: all 0.3s ease;">
        <div style="background: rgba(16, 185, 129, 0.1); color: #10b981; padding: 12px; border-radius: 12px; display: inline-flex; align-items: center; justify-content: center; font-size: 1.5rem; flex-shrink: 0;">
          <i class="bx bx-calendar"></i>
        </div>
        <div>
          <h3 style="font-weight: 700; font-size: 1.1rem; color: var(--text); margin-bottom: 6px;">Agenda Inteligente</h3>
          <p style="font-size: 0.9rem; color: var(--text-muted); line-height: 1.45; margin: 0;">Calendario compartido entre profesionales con disponibilidad en tiempo real.</p>
        </div>
      </div>
      
      <!-- CARD 2 -->
      <div class="feature-card" style="background: var(--surface); padding: 24px; border-radius: var(--radius-lg); border: 1px solid var(--border); box-shadow: var(--shadow-sm); display: flex; gap: 16px; align-items: flex-start; transition: all 0.3s ease;">
        <div style="background: rgba(16, 185, 129, 0.1); color: #10b981; padding: 12px; border-radius: 12px; display: inline-flex; align-items: center; justify-content: center; font-size: 1.5rem; flex-shrink: 0;">
          <i class="bx bx-message-rounded-dots"></i>
        </div>
        <div>
          <h3 style="font-weight: 700; font-size: 1.1rem; color: var(--text); margin-bottom: 6px;">Recordatorios Automáticos</h3>
          <p style="font-size: 0.9rem; color: var(--text-muted); line-height: 1.45; margin: 0;">Confirmaciones y recordatorios por WhatsApp para reducir ausencias.</p>
        </div>
      </div>
      
      <!-- CARD 3 -->
      <div class="feature-card" style="background: var(--surface); padding: 24px; border-radius: var(--radius-lg); border: 1px solid var(--border); box-shadow: var(--shadow-sm); display: flex; gap: 16px; align-items: flex-start; transition: all 0.3s ease;">
        <div style="background: rgba(16, 185, 129, 0.1); color: #10b981; padding: 12px; border-radius: 12px; display: inline-flex; align-items: center; justify-content: center; font-size: 1.5rem; flex-shrink: 0;">
          <i class="bx bx-user"></i>
        </div>
        <div>
          <h3 style="font-weight: 700; font-size: 1.1rem; color: var(--text); margin-bottom: 6px;">Gestión de Clientes</h3>
          <p style="font-size: 0.9rem; color: var(--text-muted); line-height: 1.45; margin: 0;">Base de datos completa con historial de citas y preferencias.</p>
        </div>
      </div>
      
      <!-- CARD 4 -->
      <div class="feature-card" style="background: var(--surface); padding: 24px; border-radius: var(--radius-lg); border: 1px solid var(--border); box-shadow: var(--shadow-sm); display: flex; gap: 16px; align-items: flex-start; transition: all 0.3s ease;">
        <div style="background: rgba(16, 185, 129, 0.1); color: #10b981; padding: 12px; border-radius: 12px; display: inline-flex; align-items: center; justify-content: center; font-size: 1.5rem; flex-shrink: 0;">
          <i class="bx bx-wallet"></i>
        </div>
        <div>
          <h3 style="font-weight: 700; font-size: 1.1rem; color: var(--text); margin-bottom: 6px;">Cobros Online</h3>
          <p style="font-size: 0.9rem; color: var(--text-muted); line-height: 1.45; margin: 0;">Integración con MercadoPago y gestión de señas y pagos rápidos.</p>
        </div>
      </div>
      
      <!-- CARD 5 -->
      <div class="feature-card" style="background: var(--surface); padding: 24px; border-radius: var(--radius-lg); border: 1px solid var(--border); box-shadow: var(--shadow-sm); display: flex; gap: 16px; align-items: flex-start; transition: all 0.3s ease;">
        <div style="background: rgba(16, 185, 129, 0.1); color: #10b981; padding: 12px; border-radius: 12px; display: inline-flex; align-items: center; justify-content: center; font-size: 1.5rem; flex-shrink: 0;">
          <i class="bx bx-bar-chart-alt-2"></i>
        </div>
        <div>
          <h3 style="font-weight: 700; font-size: 1.1rem; color: var(--text); margin-bottom: 6px;">Reportes Avanzados</h3>
          <p style="font-size: 0.9rem; color: var(--text-muted); line-height: 1.45; margin: 0;">Estadísticas de ingresos, profesionales y servicios más rentables.</p>
        </div>
      </div>
      
      <!-- CARD 6 -->
      <div class="feature-card" style="background: var(--surface); padding: 24px; border-radius: var(--radius-lg); border: 1px solid var(--border); box-shadow: var(--shadow-sm); display: flex; gap: 16px; align-items: flex-start; transition: all 0.3s ease;">
        <div style="background: rgba(16, 185, 129, 0.1); color: #10b981; padding: 12px; border-radius: 12px; display: inline-flex; align-items: center; justify-content: center; font-size: 1.5rem; flex-shrink: 0;">
          <i class="bx bx-globe"></i>
        </div>
        <div>
          <h3 style="font-weight: 700; font-size: 1.1rem; color: var(--text); margin-bottom: 6px;">Página de Reservas</h3>
          <p style="font-size: 0.9rem; color: var(--text-muted); line-height: 1.45; margin: 0;">Tu propia página personalizada donde clientes reservan 24/7.</p>
        </div>
      </div>
    </div>
  </section>

  <section class="comparison-section" id="comparativa" style="margin-top: 5rem;">
    <div class="section-heading">
      <p class="section-heading__eyebrow">Comparativa</p>
      <h2 class="section-heading__title">¿Por qué elegir Agendarte UY?</h2>
      <p class="section-heading__text">Compara las características y descubre por qué somos la mejor opción para potenciar tu negocio.</p>
    </div>
    
    <div style="max-width: 1200px; margin: 0 auto; width: 100%; padding: 0 1rem; overflow-x: auto;">
      <table style="width: 100%; border-collapse: collapse; text-align: left; background: var(--surface); border-radius: var(--radius-lg); overflow: hidden; border: 1px solid var(--border); box-shadow: var(--shadow-sm); min-width: 750px;">
        <thead>
          <tr style="background: var(--surface-2); border-bottom: 1px solid var(--border);">
            <th style="padding: 20px; font-weight: 700; color: var(--text-muted); font-size: 0.95rem; width: 28%;">Característica</th>
            <th style="padding: 20px; text-align: center; width: 18%;">
              <div style="border: 2px solid #10b981; background: rgba(16, 185, 129, 0.05); padding: 12px; border-radius: 12px; display: inline-block; width: 100%;">
                <div style="font-weight: 800; color: #10b981; font-size: 1.1rem; margin-bottom: 4px;">Agendarte UY</div>
                <div style="font-size: 0.8rem; color: var(--text-muted); font-weight: 600;">(Plan Profesional)<br>$1.190 UYU/mes</div>
              </div>
            </th>
            <th style="padding: 20px; text-align: center; width: 18%;">
              <div style="background: var(--surface-3); padding: 12px; border-radius: 12px; display: inline-block; width: 100%; border: 1px solid var(--border);">
                <div style="font-weight: 700; color: var(--text); font-size: 1rem; margin-bottom: 4px;">Booksy</div>
                <div style="font-size: 0.8rem; color: var(--text-muted); font-weight: 600;">$30 USD/mes</div>
              </div>
            </th>
            <th style="padding: 20px; text-align: center; width: 18%;">
              <div style="background: var(--surface-3); padding: 12px; border-radius: 12px; display: inline-block; width: 100%; border: 1px solid var(--border);">
                <div style="font-weight: 700; color: var(--text); font-size: 1rem; margin-bottom: 4px;">Fresha</div>
                <div style="font-size: 0.8rem; color: var(--text-muted); font-weight: 600;">$20 USD/mes</div>
              </div>
            </th>
            <th style="padding: 20px; text-align: center; width: 18%;">
              <div style="background: var(--surface-3); padding: 12px; border-radius: 12px; display: inline-block; width: 100%; border: 1px solid var(--border);">
                <div style="font-weight: 700; color: var(--text); font-size: 1rem; margin-bottom: 4px;">AgendaPro</div>
                <div style="font-size: 0.8rem; color: var(--text-muted); font-weight: 600;">$30 USD/mes</div>
              </div>
            </th>
          </tr>
        </thead>
        <tbody>
          <!-- ROW 1 -->
          <tr style="border-bottom: 1px solid var(--border);">
            <td style="padding: 16px 20px; font-weight: 600; color: var(--text); font-size: 0.9rem;">Profesionales incluidos</td>
            <td style="padding: 16px 20px; text-align: center; font-weight: 700; color: #10b981; font-size: 0.9rem; background: rgba(16, 185, 129, 0.02);">Ilimitado</td>
            <td style="padding: 16px 20px; text-align: center; color: var(--text-muted); font-size: 0.9rem;">1 (+$20 USD c/u)</td>
            <td style="padding: 16px 20px; text-align: center; color: var(--text-muted); font-size: 0.9rem;">1 (+$15 USD c/u)</td>
            <td style="padding: 16px 20px; text-align: center; color: var(--text-muted); font-size: 0.9rem;">Variable</td>
          </tr>
          <!-- ROW 2 -->
          <tr style="border-bottom: 1px solid var(--border);">
            <td style="padding: 16px 20px; font-weight: 600; color: var(--text); font-size: 0.9rem;">WhatsApp nativo</td>
            <td style="padding: 16px 20px; text-align: center; color: #10b981; font-size: 1.25rem; font-weight: bold; background: rgba(16, 185, 129, 0.02);">✓</td>
            <td style="padding: 16px 20px; text-align: center; color: var(--text-muted); font-size: 1.25rem;">✗</td>
            <td style="padding: 16px 20px; text-align: center; color: var(--text-muted); font-size: 1.25rem;">✗</td>
            <td style="padding: 16px 20px; text-align: center; color: var(--text-muted); font-size: 1.25rem;">✗</td>
          </tr>
          <!-- ROW 3 -->
          <tr style="border-bottom: 1px solid var(--border);">
            <td style="padding: 16px 20px; font-weight: 600; color: var(--text); font-size: 0.9rem;">Notificaciones WhatsApp & Email</td>
            <td style="padding: 16px 20px; text-align: center; color: #10b981; font-size: 1.25rem; font-weight: bold; background: rgba(16, 185, 129, 0.02);">✓</td>
            <td style="padding: 16px 20px; text-align: center; color: var(--text-muted); font-size: 1.25rem;">✗</td>
            <td style="padding: 16px 20px; text-align: center; color: var(--text-muted); font-size: 1.25rem;">✗</td>
            <td style="padding: 16px 20px; text-align: center; color: #10b981; font-size: 1.25rem; font-weight: bold;">✓</td>
          </tr>
          <!-- ROW 4 -->
          <tr style="border-bottom: 1px solid var(--border);">
            <td style="padding: 16px 20px; font-weight: 600; color: var(--text); font-size: 0.9rem;">Recordatorio Google Calendar</td>
            <td style="padding: 16px 20px; text-align: center; color: #10b981; font-size: 1.25rem; font-weight: bold; background: rgba(16, 185, 129, 0.02);">✓</td>
            <td style="padding: 16px 20px; text-align: center; color: var(--text-muted); font-size: 1.25rem;">✗</td>
            <td style="padding: 16px 20px; text-align: center; color: var(--text-muted); font-size: 1.25rem;">✗</td>
            <td style="padding: 16px 20px; text-align: center; color: #10b981; font-size: 1.25rem; font-weight: bold;">✓</td>
          </tr>
          <!-- ROW 5 -->
          <tr style="border-bottom: 1px solid var(--border);">
            <td style="padding: 16px 20px; font-weight: 600; color: var(--text); font-size: 0.9rem;">Selección por Profesionales</td>
            <td style="padding: 16px 20px; text-align: center; color: #10b981; font-size: 1.25rem; font-weight: bold; background: rgba(16, 185, 129, 0.02);">✓</td>
            <td style="padding: 16px 20px; text-align: center; color: #10b981; font-size: 1.25rem; font-weight: bold;">✓</td>
            <td style="padding: 16px 20px; text-align: center; color: #10b981; font-size: 1.25rem; font-weight: bold;">✓</td>
            <td style="padding: 16px 20px; text-align: center; color: #10b981; font-size: 1.25rem; font-weight: bold;">✓</td>
          </tr>
          <!-- ROW 6 -->
          <tr style="border-bottom: 1px solid var(--border);">
            <td style="padding: 16px 20px; font-weight: 600; color: var(--text); font-size: 0.9rem;">Sistema de Puntos</td>
            <td style="padding: 16px 20px; text-align: center; color: #10b981; font-size: 1.25rem; font-weight: bold; background: rgba(16, 185, 129, 0.02);">✓</td>
            <td style="padding: 16px 20px; text-align: center; color: var(--text-muted); font-size: 1.25rem;">✗</td>
            <td style="padding: 16px 20px; text-align: center; color: var(--text-muted); font-size: 1.25rem;">✗</td>
            <td style="padding: 16px 20px; text-align: center; color: var(--text-muted); font-size: 1.25rem;">✗</td>
          </tr>
          <!-- ROW 7 -->
          <tr style="border-bottom: 1px solid var(--border);">
            <td style="padding: 16px 20px; font-weight: 600; color: var(--text); font-size: 0.9rem;">Pago por servicios (total)</td>
            <td style="padding: 16px 20px; text-align: center; color: #10b981; font-size: 1.25rem; font-weight: bold; background: rgba(16, 185, 129, 0.02);">✓</td>
            <td style="padding: 16px 20px; text-align: center; color: var(--text-muted); font-size: 1.25rem;">✗</td>
            <td style="padding: 16px 20px; text-align: center; color: var(--text-muted); font-size: 1.25rem;">✗</td>
            <td style="padding: 16px 20px; text-align: center; color: var(--text-muted); font-size: 1.25rem;">✗</td>
          </tr>
          <!-- ROW 8 -->
          <tr style="border-bottom: 1px solid var(--border);">
            <td style="padding: 16px 20px; font-weight: 600; color: var(--text); font-size: 0.9rem;">Membresías recurrentes</td>
            <td style="padding: 16px 20px; text-align: center; color: var(--text-muted); font-size: 1.25rem; background: rgba(16, 185, 129, 0.02);">✗</td>
            <td style="padding: 16px 20px; text-align: center; color: var(--text-muted); font-size: 1.25rem;">✗</td>
            <td style="padding: 16px 20px; text-align: center; color: var(--text-muted); font-size: 1.25rem;">✗</td>
            <td style="padding: 16px 20px; text-align: center; color: #10b981; font-size: 1.25rem; font-weight: bold;">✓</td>
          </tr>
          <!-- ROW 9 -->
          <tr style="border-bottom: 1px solid var(--border);">
            <td style="padding: 16px 20px; font-weight: 600; color: var(--text); font-size: 0.9rem;">Comisiones profesionales</td>
            <td style="padding: 16px 20px; text-align: center; color: var(--text-muted); font-size: 1.25rem; background: rgba(16, 185, 129, 0.02);">✗</td>
            <td style="padding: 16px 20px; text-align: center; color: var(--text-muted); font-size: 1.25rem;">✗</td>
            <td style="padding: 16px 20px; text-align: center; color: var(--text-muted); font-size: 1.25rem;">✗</td>
            <td style="padding: 16px 20px; text-align: center; color: #10b981; font-size: 1.25rem; font-weight: bold;">✓</td>
          </tr>
          <!-- ROW 10 -->
          <tr style="border-bottom: 1px solid var(--border);">
            <td style="padding: 16px 20px; font-weight: 600; color: var(--text); font-size: 0.9rem;">Múltiples sucursales</td>
            <td style="padding: 16px 20px; text-align: center; color: var(--text-muted); font-size: 1.25rem; background: rgba(16, 185, 129, 0.02);">✗</td>
            <td style="padding: 16px 20px; text-align: center; color: #10b981; font-size: 1.25rem; font-weight: bold;">✓</td>
            <td style="padding: 16px 20px; text-align: center; color: #10b981; font-size: 1.25rem; font-weight: bold;">✓</td>
            <td style="padding: 16px 20px; text-align: center; color: #10b981; font-size: 1.25rem; font-weight: bold;">✓</td>
          </tr>
          <!-- ROW 11 -->
          <tr style="border-bottom: 1px solid var(--border);">
            <td style="padding: 16px 20px; font-weight: 600; color: var(--text); font-size: 0.9rem;">100% en Español</td>
            <td style="padding: 16px 20px; text-align: center; color: #10b981; font-size: 1.25rem; font-weight: bold; background: rgba(16, 185, 129, 0.02);">✓</td>
            <td style="padding: 16px 20px; text-align: center; color: var(--text-muted); font-size: 1.25rem;">✗</td>
            <td style="padding: 16px 20px; text-align: center; color: var(--text-muted); font-size: 1.25rem;">✗</td>
            <td style="padding: 16px 20px; text-align: center; color: #10b981; font-size: 1.25rem; font-weight: bold;">✓</td>
          </tr>
          <!-- ROW 12 -->
          <tr style="border-bottom: 1px solid var(--border);">
            <td style="padding: 16px 20px; font-weight: 600; color: var(--text); font-size: 0.9rem;">Soporte por WhatsApp</td>
            <td style="padding: 16px 20px; text-align: center; color: #10b981; font-size: 1.25rem; font-weight: bold; background: rgba(16, 185, 129, 0.02);">✓</td>
            <td style="padding: 16px 20px; text-align: center; color: var(--text-muted); font-size: 1.25rem;">✗</td>
            <td style="padding: 16px 20px; text-align: center; color: var(--text-muted); font-size: 1.25rem;">✗</td>
            <td style="padding: 16px 20px; text-align: center; color: #10b981; font-size: 1.25rem; font-weight: bold;">✓</td>
          </tr>
          <!-- ROW 13 -->
          <tr>
            <td style="padding: 16px 20px; font-weight: 600; color: var(--text); font-size: 0.9rem;">Precios en moneda local</td>
            <td style="padding: 16px 20px; text-align: center; color: #10b981; font-size: 1.25rem; font-weight: bold; background: rgba(16, 185, 129, 0.02);">✓</td>
            <td style="padding: 16px 20px; text-align: center; color: var(--text-muted); font-size: 1.25rem;">✗</td>
            <td style="padding: 16px 20px; text-align: center; color: var(--text-muted); font-size: 1.25rem;">✗</td>
            <td style="padding: 16px 20px; text-align: center; color: #10b981; font-size: 1.25rem; font-weight: bold;">✓</td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>

  <section class="planes-section" id="planes" style="margin-top: 5rem;">
    <div class="section-heading">
      <p class="section-heading__eyebrow">Planes</p>
      <h2 class="section-heading__title">Planes para cada etapa de tu negocio</h2>
      <p class="section-heading__text">Elegí el plan que mejor se adapte a lo que necesitás. Podés cambiar o cancelar cuando quieras.</p>
    </div>
    <div class="plan-body" style="padding: 2px 2px 4px; max-width: 1200px; margin: 0 auto; width: 100%;">
      <?php if (empty($planesActivos)): ?>
        <div class="cat-empty"><p>No hay planes activos.</p></div>
      <?php else:
        $anyAnnual = false;
        foreach ($planesActivos as $ap) {
            if (MembershipPlan::isAnnualEnabled($ap)) {
                $anyAnnual = true;
                break;
            }
        }
        $planCount = count($planesActivos);

        // Custom features for Agenda Digital
        $agendaFeatures = [
            'Free' => [
                ['label' => 'Reservas al mes', 'value' => 'Hasta 25 reservas', 'included' => true],
                ['label' => 'Profesionales', 'value' => '1 profesional', 'included' => true],
                ['label' => 'Clientes registrados', 'value' => 'Hasta 25 clientes', 'included' => true],
                ['label' => 'Servicios a agendar', 'value' => 'Hasta 4 servicios', 'included' => true],
                ['label' => 'Página web de reservas', 'value' => 'Básica', 'included' => true],
                ['label' => 'Recordatorios WhatsApp', 'value' => 'No incluido', 'included' => false],
                ['label' => 'Sistema de puntos', 'value' => 'No incluido', 'included' => false],
            ],
            'Básico' => [
                ['label' => 'Reservas al mes', 'value' => 'Hasta 100 reservas', 'included' => true],
                ['label' => 'Profesionales', 'value' => 'Hasta 3 profesionales', 'included' => true],
                ['label' => 'Clientes registrados', 'value' => 'Hasta 100 clientes', 'included' => true],
                ['label' => 'Servicios a agendar', 'value' => 'Hasta 8 servicios', 'included' => true],
                ['label' => 'Página web de reservas', 'value' => 'Personalizada', 'included' => true],
                ['label' => 'Recordatorios WhatsApp', 'value' => 'Incluido', 'included' => true],
                ['label' => 'Soporte prioritario', 'value' => 'No incluido', 'included' => false],
            ],
            'Profesional' => [
                ['label' => 'Reservas al mes', 'value' => 'Ilimitadas', 'included' => true],
                ['label' => 'Profesionales', 'value' => 'Ilimitados', 'included' => true],
                ['label' => 'Clientes registrados', 'value' => 'Ilimitados', 'included' => true],
                ['label' => 'Servicios a agendar', 'value' => 'Ilimitados', 'included' => true],
                ['label' => 'Página web de reservas', 'value' => 'Avanzada', 'included' => true],
                ['label' => 'Notificaciones WhatsApp & Email', 'value' => 'Ilimitadas', 'included' => true],
                ['label' => 'Recordatorio Google Calendar', 'value' => 'Incluido', 'included' => true],
                ['label' => 'Sistema de puntos', 'value' => 'Incluido', 'included' => true],
            ]
        ];

        // Custom features for Tienda Online / Catálogo
        $tiendaFeatures = [
            'Free' => [
                ['label' => 'Catálogo online', 'value' => 'Básico (Hasta 3 productos)', 'included' => true],
                ['label' => 'Pedidos al WhatsApp', 'value' => 'Incluido', 'included' => true],
                ['label' => 'Carrito de compras', 'value' => 'No incluido', 'included' => false],
                ['label' => 'Pagos online (Mercado Pago)', 'value' => 'No incluido', 'included' => false],
                ['label' => 'Envíos y retiros', 'value' => 'No incluido', 'included' => false],
                ['label' => 'Configuración básica', 'value' => 'Incluida', 'included' => true],
                ['label' => 'Reportes y estadísticas', 'value' => 'No incluido', 'included' => false],
            ],
            'Básico' => [
                ['label' => 'Catálogo online', 'value' => 'Hasta 20 productos', 'included' => true],
                ['label' => 'Carrito de compras integrado', 'value' => 'Incluido', 'included' => true],
                ['label' => 'Pedidos al WhatsApp', 'value' => 'Incluido', 'included' => true],
                ['label' => 'Pagos online (Mercado Pago)', 'value' => 'Incluido', 'included' => true],
                ['label' => 'Envíos y retiros locales', 'value' => 'Configurable', 'included' => true],
                ['label' => 'Control básico de stock', 'value' => 'Incluido', 'included' => true],
                ['label' => 'Reportes de ventas', 'value' => 'Básicos', 'included' => true],
            ],
            'Profesional' => [
                ['label' => 'Catálogo online', 'value' => 'Productos ilimitados', 'included' => true],
                ['label' => 'E-commerce con carrito', 'value' => 'Completo', 'included' => true],
                ['label' => 'Pagos online (MP & PayPal)', 'value' => 'Integrado', 'included' => true],
                ['label' => 'Impresión de tickets automática', 'value' => 'Incluida', 'included' => true],
                ['label' => 'Control de stock avanzado', 'value' => 'Ilimitado', 'included' => true],
                ['label' => 'Notificaciones WhatsApp & Email', 'value' => 'Ilimitadas', 'included' => true],
                ['label' => 'Reportes avanzados y ventas', 'value' => 'Completos', 'included' => true],
            ]
        ];
      ?>
        <?php if ($anyAnnual): ?>
          <div class="plan-billing-toggle" data-landing-billing-toggle style="margin: 0 auto 2.5rem; display: inline-flex; justify-content: center; width: 100%;">
            <button type="button" class="is-active" data-billing="monthly">Facturación Mensual</button>
            <button type="button" data-billing="yearly">Facturación Anual</button>
          </div>
        <?php endif; ?>
        <div class="plan-grid" role="list">
          <?php foreach ($planesActivos as $i => $p):
            $precio = (float)$p['precio'];
            $isFree = $precio <= 0;
            $trialDias = (int)($p['trial_dias'] ?? 0);
            $descripcion = trim((string)($p['descripcion'] ?? ''));
            $isFeatured = $planCount >= 3 && $i === 1;
            $ctaLabel = $isFree ? 'Empezar gratis' : 'Elegir este plan';
            $displayDesc = MembershipPlan::displayDescription($p);
            $yearly = MembershipPlan::yearlyPrice($p);
            $discount = MembershipPlan::annualDiscountPct($p);
            $hasAnnual = MembershipPlan::isAnnualEnabled($p);
            $nombrePlan = trim((string)($p['nombre'] ?? ''));
          ?>
            <article class="plan-card<?= $isFeatured ? ' plan-card--featured' : '' ?>"
                     role="listitem"
                     data-landing-plan
                     data-plan-id="<?= (int)$p['id_membership'] ?>"
                     data-plan-nombre="<?= h((string)$p['nombre']) ?>"
                     data-monthly="<?= $precio ?>"
                     data-yearly="<?= $yearly !== null ? $yearly : '' ?>"
                     data-has-annual="<?= $hasAnnual ? '1' : '0' ?>"
                     data-discount-pct="<?= (int)$discount ?>"
                     data-currency="<?= h((string)$p['moneda']) ?>">
              <?php if ($isFeatured): ?>
                <span class="plan-card__badge">Recomendado</span>
              <?php endif; ?>
              <h4 class="plan-card__name"><?= h((string)$p['nombre']) ?></h4>
              <div class="plan-card__price">
                <?php if ($isFree): ?>
                  <span class="plan-card__amount">Gratis</span>
                <?php else: ?>
                  <span class="plan-card__currency"><?= h((string)$p['moneda']) ?></span>
                  <span class="plan-card__amount" data-landing-price-amount><?= number_format($precio, 0, ',', '.') ?></span>
                  <span class="plan-card__period" data-landing-price-period>/ mes</span>
                <?php endif; ?>
              </div>
              <?php if ($hasAnnual && $discount > 0): ?>
                <p class="plan-card__annual-note" data-landing-annual-note hidden><?= (int)$discount ?>% off al pagar anual</p>
              <?php endif; ?>
              <?php if (!$isFree && $trialDias > 0): ?>
                <p class="plan-card__trial"><?= $trialDias ?> días de prueba gratis</p>
              <?php endif; ?>
              <?php if ($displayDesc !== '' || $descripcion !== ''): ?>
                <p class="plan-card__desc"><?= h($displayDesc !== '' ? $displayDesc : $descripcion) ?></p>
              <?php endif; ?>

              <!-- Agenda specific features -->
              <?php if (isset($agendaFeatures[$nombrePlan])): ?>
                <ul class="plan-card__features plan-features-agenda" aria-label="Características de Agenda - <?= h($nombrePlan) ?>">
                  <?php foreach ($agendaFeatures[$nombrePlan] as $row): ?>
                    <?php $included = !empty($row['included']); ?>
                    <li class="<?= $included ? 'is-included' : 'is-excluded' ?>">
                      <span class="plan-card__feature-icon" aria-hidden="true"><?= $included ? '&#10003;' : '&#10005;' ?></span>
                      <span class="plan-card__feature-copy">
                        <span class="plan-card__feature-label"><?= h((string)($row['label'] ?? '')) ?></span>
                        <span class="plan-card__feature-value"><?= h((string)($row['value'] ?? '')) ?></span>
                      </span>
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>

              <!-- Tienda specific features -->
              <?php if (isset($tiendaFeatures[$nombrePlan])): ?>
                <ul class="plan-card__features plan-features-tienda" style="display: none;" aria-label="Características de Tienda - <?= h($nombrePlan) ?>">
                  <?php foreach ($tiendaFeatures[$nombrePlan] as $row): ?>
                    <?php $included = !empty($row['included']); ?>
                    <li class="<?= $included ? 'is-included' : 'is-excluded' ?>">
                      <span class="plan-card__feature-icon" aria-hidden="true"><?= $included ? '&#10003;' : '&#10005;' ?></span>
                      <span class="plan-card__feature-copy">
                        <span class="plan-card__feature-label"><?= h((string)($row['label'] ?? '')) ?></span>
                        <span class="plan-card__feature-value"><?= h((string)($row['value'] ?? '')) ?></span>
                      </span>
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>

              <button type="button" class="plan-btn plan-card__cta"
                data-plan-id="<?= (int)$p['id_membership'] ?>"
                data-plan-nombre="<?= h((string)$p['nombre']) ?>"
                data-billing-period="monthly"
                data-monthly-price="<?= $precio ?>"
                data-yearly-price="<?= $yearly !== null ? $yearly : '' ?>"><?= h($ctaLabel) ?></button>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </section>

  <section class="trust-banner-section" aria-label="Clientes de Agendarte">
    <div class="trust-banner">
      <div class="trust-banner__text">
        <p class="trust-banner__eyebrow">Confianza que crece cada mes</p>
        <h3>Negocios de todo Uruguay ya confían en Agendarte UY</h3>
      </div>
      <div class="trust-banner__avatars">
        <?php for ($i = 0; $i < 5; $i++):
          $avatarSrc = $clientAvatars[$i] ?? '';
        ?>
          <span class="trust-banner__avatar<?php echo $avatarSrc === '' ? ' trust-banner__avatar--empty' : ''; ?>">
            <?php if ($avatarSrc !== ''): ?>
              <img src="<?php echo h($avatarSrc); ?>" alt="Cliente satisfecho <?php echo $i + 1; ?>" loading="lazy" decoding="async" width="52" height="52">
            <?php else: ?>
              <span aria-hidden="true">AG</span>
            <?php endif; ?>
          </span>
        <?php endfor; ?>
        <span class="trust-banner__count">+</span>
      </div>
    </div>
  </section>

  <section class="benefits-section" id="beneficios" aria-label="Beneficios de Agendarte UY">
    <div class="section-heading">
      <p class="section-heading__eyebrow">Beneficios</p>
      <h2 class="section-heading__title">Tu negocio, siempre disponible</h2>
      <p class="section-heading__text">Centralizá turnos, reducí mensajes de coordinación y ofrecé una experiencia profesional a tus clientes.</p>
    </div>
    <ul class="benefits-grid">
      <?php foreach (LandingContent::benefits() as $benefit): ?>
      <li class="benefits-grid__item">
        <i class="bx bx-check-circle" aria-hidden="true"></i>
        <span><?= h($benefit) ?></span>
      </li>
      <?php endforeach; ?>
    </ul>
  </section>

  <section class="categories-section" id="categorias" aria-label="Categorías de servicios">
    <div class="section-heading">
      <p class="section-heading__eyebrow">Categorías</p>
      <h2 class="section-heading__title">Encontrá y reservá el servicio que necesitás</h2>
      <p class="section-heading__text">Consultá disponibilidad y solicitá turnos para distintas actividades y profesionales en Uruguay.</p>
    </div>
    <div class="categories-grid">
      <?php foreach (array_slice(LandingContent::categories(), 0, 8, true) as $catSlug => $cat): ?>
      <a class="category-card" href="<?= h(url('categorias/' . rawurlencode($catSlug) . '/')) ?>">
        <span class="category-card__title"><?= h($cat['title']) ?></span>
      </a>
      <?php endforeach; ?>
    </div>
    <p class="categories-section__more">
      <a href="<?= h(url('categorias/')) ?>">Ver todas las categorías</a>
      <span class="categories-section__sep" aria-hidden="true">·</span>
      <a href="<?= h(url('ubicaciones/')) ?>">Buscar por ciudad</a>
    </p>
  </section>

  <section class="about-section" id="nosotros" aria-label="Quiénes somos">
    <div class="about-section__inner">
      <p class="section-heading__eyebrow">Quiénes somos</p>
      <h2 class="section-heading__title">Una nueva forma de gestionar tu tiempo</h2>
      <p>En Agendarte UY creemos que reservar un servicio debería ser sencillo, rápido y accesible. Conectamos clientes y prestadores de servicios con una experiencia moderna, confiable y eficiente.</p>
      <button type="button" class="hero__secondary" id="btnSobreNosotrosInline">Leer más</button>
    </div>
  </section>

  <section class="faq-section" id="faq" aria-label="Preguntas frecuentes">
    <div class="section-heading">
      <p class="section-heading__eyebrow">FAQ</p>
      <h2 class="section-heading__title">Preguntas frecuentes</h2>
    </div>
    <div class="faq-list">
      <?php foreach (LandingContent::faq() as $i => $item): ?>
      <details class="faq-item"<?= $i === 0 ? ' open' : '' ?>>
        <summary><?= h($item['q']) ?></summary>
        <p><?= h($item['a']) ?></p>
      </details>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="explore-section" id="explorar" aria-label="Explorar Agendarte UY">
    <div class="section-heading">
      <p class="section-heading__eyebrow">Explorá</p>
      <h2 class="section-heading__title">Todo lo que ofrece Agendarte UY</h2>
    </div>
    <nav class="container" aria-label="Secciones de Agendarte">
      <div class="category" id="btnRubrosDisponibles" role="button" tabindex="0" aria-controls="modal-rubros" aria-haspopup="dialog">
        <span class="category__icon"><i class="bx bx-briefcase" aria-hidden="true"></i></span>
        <p>Rubros Disponibles</p>
        <span class="category__hint">Conocé los rubros que pueden usar la plataforma</span>
      </div>

      <div class="category" id="btnPlanes" role="button" tabindex="0" aria-controls="modal-planes" aria-haspopup="dialog">
        <span class="category__icon"><i class="bx bx-purchase-tag" aria-hidden="true"></i></span>
        <p>Planes</p>
        <span class="category__hint">Funciones incluidas en tu suscripción</span>
      </div>
      <div class="category" id="btnBeneficios" role="button" tabindex="0" aria-controls="modal-beneficios" aria-haspopup="dialog">
        <span class="category__icon"><i class="bx bx-gift" aria-hidden="true"></i></span>
        <p>Beneficios</p>
        <span class="category__hint">Ventajas para tu negocio y tus clientes</span>
      </div>
      <div class="category" id="btnBuscarServicios" role="button" tabindex="0" aria-controls="modal-buscar" aria-haspopup="dialog">
        <span class="category__icon"><i class="bx bx-search" aria-hidden="true"></i></span>
        <p>Buscar Servicios</p>
        <span class="category__hint">Encontrá negocios que ya usan Agendarte</span>
      </div>
      <div class="category" id="btnSobreNosotros" role="button" tabindex="0" aria-controls="modal-about" aria-haspopup="dialog">
        <span class="category__icon"><i class="bx bx-info-circle" aria-hidden="true"></i></span>
        <p>Sobre Nosotros</p>
        <span class="category__hint">Quiénes estamos detrás de la plataforma</span>
      </div>
    </nav>
  </section>

  <section class="final-cta" aria-label="Registrar negocio en Agendarte UY">
    <h2 class="final-cta__title">Registrá tu negocio en Agendarte UY</h2>
    <p class="final-cta__text">Comenzá a recibir reservas online. Plan gratuito ilimitado, sin tarjeta.</p>
    <p class="final-cta__brand-line"><?= h(LandingContent::TAGLINE) ?></p>
    <button type="button" class="plan-btn final-cta__btn" data-rubro-id="" data-rubro-nombre="">Registrar mi negocio</button>
  </section>
</main>

<footer class="site-footer">
  <div class="site-footer__inner">
    <div class="site-footer__brand">
      <img src="<?= h(url('src/media/logo/logo-icon.png?v=' . $logoVer)) ?>" alt="" width="28" height="28" loading="lazy" decoding="async">
      <span>Agendarte <span class="brand-uy">UY</span></span>
    </div>
    <p class="site-footer__tagline"><?= h(LandingContent::TAGLINE) ?></p>
    <nav class="site-footer__links" aria-label="Enlaces SEO">
      <a href="<?= h(url('categorias/')) ?>">Categorías</a>
      <a href="<?= h(url('ubicaciones/')) ?>">Ubicaciones</a>
      <a href="#faq">FAQ</a>
    </nav>
    <div class="site-footer__social">
      <?php if ($platformInstagramUrl !== ''): ?>
      <a href="<?= h($platformInstagramUrl) ?>" aria-label="Instagram" target="_blank" rel="noopener"><i class="bx bxl-instagram" aria-hidden="true"></i></a>
      <?php endif; ?>
      <?php if ($platformWhatsAppUrl !== ''): ?>
      <a href="<?= h($platformWhatsAppUrl) ?>" aria-label="WhatsApp" target="_blank" rel="noopener"><i class="bx bxl-whatsapp" aria-hidden="true"></i></a>
      <?php endif; ?>
    </div>
  </div>
</footer>

<div id="modal-rubros" class="u-modal hidden" role="dialog" aria-modal="true" aria-labelledby="modal-rubros-title">
  <div class="u-modal__overlay"></div>
  <div class="u-modal__dialog">
    <div class="u-modal__content" id="modal-rubros-content"></div>
  </div>
</div>

<div id="modal-planes" class="u-modal hidden" role="dialog" aria-modal="true" aria-labelledby="modal-planes-title">
  <div class="u-modal__overlay"></div>
  <div class="u-modal__dialog">
    <div class="u-modal__content" id="modal-planes-content"></div>
  </div>
</div>

<div id="modal-cursos" class="u-modal hidden" role="dialog" aria-modal="true" aria-labelledby="modal-cursos-title">
  <div class="u-modal__overlay"></div>
  <div class="u-modal__dialog">
    <div class="u-modal__content" id="modal-cursos-content"></div>
  </div>
</div>

<div id="modal-beneficios" class="u-modal hidden" role="dialog" aria-modal="true" aria-labelledby="modal-beneficios-title">
  <div class="u-modal__overlay"></div>
  <div class="u-modal__dialog">
    <div class="u-modal__content" id="modal-beneficios-content"></div>
  </div>
</div>

<div id="modal-about" class="u-modal hidden" role="dialog" aria-modal="true" aria-labelledby="modal-about-title">
  <div class="u-modal__overlay"></div>
  <div class="u-modal__dialog">
    <div class="u-modal__content" id="modal-about-content"></div>
  </div>
</div>

<div id="modal-buscar" class="u-modal hidden" role="dialog" aria-modal="true" aria-labelledby="modal-buscar-title">
  <div class="u-modal__overlay"></div>
  <div class="u-modal__dialog">
    <div class="u-modal__content" id="modal-buscar-content"></div>
  </div>
</div>

<?php include __DIR__ . '/src/components/register/modal.php'; ?>

<script>
<?php
$planesMap = [];
foreach ($planesActivos as $m) {
    $mid = (string)($m['id_membership'] ?? '');
    if ($mid === '') continue;
    $planesMap[$mid] = [
        'id' => $mid,
        'nombre' => (string)($m['nombre'] ?? ''),
        'descripcion' => (string)($m['descripcion'] ?? ''),
        'display_descripcion' => MembershipPlan::displayDescription($m),
        'comparativa' => MembershipPlan::comparisonRows($m),
        'precio' => (float)($m['precio'] ?? 0),
        'moneda' => (string)($m['moneda'] ?? 'UYU'),
        'duracion_dias' => (int)($m['duracion_dias'] ?? 30),
        'trial_dias' => (int)($m['trial_dias'] ?? 30),
        'mp_amount' => (float)($m['precio'] ?? 0),
        'mp_currency' => (string)($m['moneda'] ?? 'UYU'),
        'frecuencia' => 1,
        'frecuencia_tipo' => 'months',
    ];
}
?>
window.__AGENDUY_CONFIG__ = {
    rubros: <?= json_encode(array_map(function($r) {
        $img = trim((string)($r['imagen'] ?? ''));
        if ($img === '' || !is_file(__DIR__ . '/' . ltrim(str_replace('\\', '/', $img), '/'))) {
            $img = 'src/media/carousel/profesionales.jpg';
        }
        return [
            'id' => (int)$r['id_rubro'],
            'nombre' => $r['nombre'],
            'tipo' => $r['tipo'],
            'imagen' => url($img),
        ];
    }, $rubros), JSON_UNESCAPED_UNICODE) ?>,
    planes: <?= json_encode($planesMap, JSON_UNESCAPED_UNICODE) ?>,
    mercadoPago: {
        publicKey: <?= json_encode($mpPublicKey) ?>,
        locale: 'es-UY',
        freeTrialDays: <?= $freeTrial ?>
    },
    apiBase: <?= json_encode(url('admin/api')) ?>,
    googleClientId: <?= json_encode($googleClientId) ?>,
    csrfToken: document.querySelector('meta[name="csrf-token"]')?.content || '',
    env: <?= json_encode(agenduy_env()) ?>,
    urlBase: <?= json_encode(url_base()) ?>
};
</script>

<script>
(function(){
  const loader = document.getElementById('page-loader');
  if (!loader) return;
  const MIN_TIME = 1200;
  const start = Date.now();
  let hidden = false;
  const hideLoader = () => {
    if (hidden) return;
    hidden = true;
    const elapsed = Date.now() - start;
    const wait = Math.max(0, MIN_TIME - elapsed);
    setTimeout(() => {
      loader.classList.add('page-loader--hidden');
      setTimeout(() => loader.remove(), 800);
    }, wait);
  };
  window.addEventListener('load', hideLoader, { once: true });
  setTimeout(hideLoader, 8000);
})();

(function () {
  var inlineAbout = document.getElementById('btnSobreNosotrosInline');
  var mainAbout = document.getElementById('btnSobreNosotros');
  if (inlineAbout && mainAbout) {
    inlineAbout.addEventListener('click', function () { mainAbout.click(); });
  }
})();
</script>
</body>
</html>
