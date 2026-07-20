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
  <script src="src/js/main.js" defer></script>
  <?php if (is_file(__DIR__ . '/src/js/theme.js')): ?>
  <script src="src/js/theme.js" defer></script>
  <?php endif; ?>
  <script src="src/js/site-login.js" defer></script>
  <script src="src/js/auth-google.js" defer></script>
  <script src="src/js/cursos-modal.js" defer></script>
  <script src="src/js/beneficios-modal.js" defer></script>
  <script src="src/js/about-modal.js" defer></script>
  <script src="src/js/buscar-modal.js" defer></script>
  <script src="src/js/register/modal.js" defer></script>
  <title>Agendarte | Sistema de Reservas Online</title>
  <meta name="description" content="Agendarte: reservas online para negocios de Uruguay. Agenda digital, recordatorios por WhatsApp y pagos online. Probalo gratis.">

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
  <link rel="icon" type="image/png" sizes="32x32" href="src/img/favicon/favicon.png">
  <link rel="apple-touch-icon" href="src/img/favicon/favicon.png">
  <meta name="csrf-token" content="<?= h(\Agenduy\Core\CSRF::generate('public_booking')) ?>">
</head>
<body>
<div id="page-loader" role="status" aria-live="assertive" aria-label="Cargando contenido">
  <div class="page-loader__glow"></div>
  <div class="page-loader__card">
    <div class="page-loader__badge">
      <span>Agendarte</span>
    </div>
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
      <a class="site-header__brand" href="<?= h(url_base()) ?>" aria-label="Inicio de Agendarte">
        <img src="src/media/logo/logo.png"
             alt="Logotipo de Agendarte"
             class="brand-logo"
             width="40" height="40"
             decoding="async" loading="eager">
        <span class="site-header__brand-text">
          <span class="brand-title">Agendarte</span>
          <span class="brand-tagline">Reservas online para negocios de Uruguay.</span>
        </span>
      </a>
      <nav class="site-header__actions" aria-label="Acciones principales">
        <a class="site-header__icon" href="https://instagram.com/agendarte.uy" target="_blank" rel="noopener" aria-label="Instagram">
          <i class="bx bxl-instagram" aria-hidden="true"></i>
        </a>
        <a class="site-header__icon" href="https://wa.me/59892365135" target="_blank" rel="noopener" aria-label="WhatsApp">
          <i class="bx bxl-whatsapp" aria-hidden="true"></i>
        </a>
        <button type="button" id="theme-toggle" class="theme-toggle" aria-pressed="false" aria-label="Cambiar a tema oscuro" title="Tema oscuro">
          <i class="bx bx-sun theme-toggle__icon theme-toggle__icon--sun" aria-hidden="true"></i>
          <i class="bx bx-moon theme-toggle__icon theme-toggle__icon--moon" aria-hidden="true"></i>
        </button>
        <div class="site-header__user" id="site-user">
          <button type="button" class="site-header__login-btn" id="site-login-toggle" aria-expanded="false" aria-haspopup="dialog" aria-controls="site-login-dropdown">
            <i class="bx bx-user-circle" aria-hidden="true"></i>
            <span class="site-header__login-label">Iniciar sesion</span>
            <i class="bx bx-chevron-down site-header__login-caret" aria-hidden="true"></i>
          </button>
          <div class="site-login-dropdown" id="site-login-dropdown" role="dialog" aria-label="Iniciar sesion" hidden>
            <div class="site-login-tabs" role="tablist">
              <button type="button" class="site-login-tabs__btn is-active" data-login-tab="password" role="tab" aria-selected="true">Contrasena</button>
              <button type="button" class="site-login-tabs__btn" data-login-tab="magic" role="tab" aria-selected="false">Link por email</button>
            </div>
            <form method="post" action="<?= h(url('admin/login.php')) ?>" class="site-login-form" id="site-login-form" data-login-panel="password" novalidate>
              <input type="hidden" name="_csrf" value="<?= h(\Agenduy\Core\CSRF::generate('admin_login')) ?>">
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
              <p class="site-login-form__hint">Te enviamos un link seguro a tu correo. Sin contrasena.</p>
              <label class="site-login-form__field">
                <span>Email</span>
                <input type="email" name="email" required autocomplete="email" placeholder="tu@email.com">
              </label>
              <button type="submit" class="site-login-form__btn">Enviame el link</button>
            </form>
            <?php if ($googleClientId !== ''): ?>
            <div class="site-login-divider"><span>o</span></div>
            <div id="site-login-google" class="site-login-google"></div>
            <?php endif; ?>
            <p class="site-login-form__msg" id="site-login-msg" role="status" aria-live="polite"></p>
          </div>
        </div>
      </nav>
    </div>
  </header>

<main class="landing">
  <section class="hero" aria-label="Propuesta de valor">
    <p class="hero__badge">
      <i class="bx bx-gift" aria-hidden="true"></i>
      <?= $freeTrial ?> días gratis · sin tarjeta
    </p>
    <h1 class="hero__title">Tu agenda online,<br><span class="hero__title-accent">simple y profesional</span></h1>
    <p class="hero__subtitle">
      Agendarte es la plataforma de reservas para negocios de Uruguay:
      calendario inteligente, recordatorios por WhatsApp y pagos online, todo en un solo lugar.
    </p>
    <div class="hero__actions">
      <a class="btn-primary hero__cta" href="#rubros">
        Comenzar gratis
        <i class="bx bx-right-arrow-alt" aria-hidden="true"></i>
      </a>
      <a class="hero__secondary" href="#explorar">Conocer la plataforma</a>
    </div>
  </section>

  <section class="rubros-section" id="rubros" aria-label="Rubros destacados">
    <div class="section-heading">
      <p class="section-heading__eyebrow">Rubros</p>
      <h2 class="section-heading__title">Pensado para tu negocio</h2>
      <p class="section-heading__text">Elegí tu rubro y empezá a recibir reservas hoy mismo.</p>
    </div>
    <div class="hero-carousel">
      <button class="hc-btn prev" aria-label="Anterior"><i class="bx bx-chevron-left" aria-hidden="true"></i></button>
      <div class="hc-viewport">
        <ul class="hc-track">
          <?php if (!empty($rubros)): ?>
            <?php foreach ($rubros as $r):
              $rImg = trim((string)($r['imagen'] ?? ''));
              // default.jpg no existe en disco; Apache lo enruta a index.php (HTML) y rompe el icono
              if ($rImg === '' || !is_file(__DIR__ . '/' . ltrim(str_replace('\\', '/', $rImg), '/'))) {
                $rImg = 'src/media/carousel/profesionales.jpg';
              }
            ?>
            <li class="hc-card" data-rubro-id="<?= (int)$r['id_rubro'] ?>" data-plan-id="">
              <figure>
                <img src="<?= h(url($rImg)) ?>" alt="<?= h($r['nombre']) ?>" loading="lazy" decoding="async">
                <figcaption><?= h($r['nombre']) ?></figcaption>
                <div class="hc-highlight"><?= $freeTrial ?> días gratis</div>
                <div class="hc-perks">
                  <div class="hc-perk">
                    <i class="bx bx-check" aria-hidden="true"></i>
                    <span>Sin tarjeta de crédito</span>
                  </div>
                  <div class="hc-perk">
                    <i class="bx bx-check" aria-hidden="true"></i>
                    <span>Listo en 5 minutos</span>
                  </div>
                  <div class="hc-perk">
                    <i class="bx bx-check" aria-hidden="true"></i>
                    <span>Página web incluida</span>
                  </div>
                </div>
                <button type="button" class="plan-btn" data-rubro-id="<?= (int)$r['id_rubro'] ?>" data-rubro-nombre="<?= h($r['nombre']) ?>">Comenzar ahora</button>
              </figure>
            </li>
            <?php endforeach; ?>
          <?php else: ?>
            <li class="hc-card">
              <figure>
                <img src="<?= h(url('src/media/carousel/barberias.jpg')) ?>" alt="Barberias">
                <figcaption>Barberias</figcaption>
                <button type="button" class="plan-btn" data-rubro-id="0" data-rubro-nombre="Barberias">Comenzar ahora</button>
              </figure>
            </li>
          <?php endif; ?>
        </ul>
      </div>
      <button class="hc-btn next" aria-label="Siguiente"><i class="bx bx-chevron-right" aria-hidden="true"></i></button>
      <div class="hc-dots" role="tablist" aria-label="Paginacion"></div>
    </div>
  </section>

  <section class="trust-banner-section" aria-label="Clientes de Agendarte">
    <div class="trust-banner">
      <div class="trust-banner__text">
        <p class="trust-banner__eyebrow">Confianza que crece cada mes</p>
        <h3>Negocios de todo Uruguay ya confían en Agendarte</h3>
      </div>
      <div class="trust-banner__avatars">
        <?php for ($i = 0; $i < 5; $i++):
          $avatarSrc = $clientAvatars[$i] ?? '';
        ?>
          <span class="trust-banner__avatar<?php echo $avatarSrc === '' ? ' trust-banner__avatar--empty' : ''; ?>">
            <?php if ($avatarSrc !== ''): ?>
              <img src="<?php echo h($avatarSrc); ?>" alt="Cliente satisfecho <?php echo $i + 1; ?>">
            <?php else: ?>
              <span aria-hidden="true">AG</span>
            <?php endif; ?>
          </span>
        <?php endfor; ?>
        <span class="trust-banner__count">+</span>
      </div>
    </div>
  </section>

  <section class="explore-section" id="explorar" aria-label="Explorar Agendarte">
    <div class="section-heading">
      <p class="section-heading__eyebrow">Explorá</p>
      <h2 class="section-heading__title">Todo lo que ofrece Agendarte</h2>
    </div>
    <nav class="container" aria-label="Secciones de Agendarte">
      <div class="category" id="btnRubrosDisponibles" role="button" tabindex="0" aria-controls="modal-rubros" aria-haspopup="dialog">
        <span class="category__icon"><i class="bx bx-briefcase" aria-hidden="true"></i></span>
        <p>Rubros Disponibles</p>
        <span class="category__hint">Conocé los rubros que pueden usar la plataforma</span>
      </div>
      <div class="category" id="btnCursos" role="button" tabindex="0" aria-controls="modal-cursos" aria-haspopup="dialog">
        <span class="category__icon"><i class='bx bx-notepad' aria-hidden="true"></i></span>
        <p>Nuestros Cursos</p>
        <span class="category__hint">Capacitaciones y novedades para tu equipo</span>
      </div>
      <div class="category" id="btnPlanes" role="button" tabindex="0" aria-controls="modal-planes" aria-haspopup="dialog">
        <span class="category__icon"><i class="bx bx-purchase-tag" aria-hidden="true"></i></span>
        <p>Planes</p>
        <span class="category__hint">Funciones incluidas en tu suscripción</span>
      </div>
      <div class="category" id="btnBeneficios" role="button" tabindex="0" aria-controls="modal-beneficios" aria-haspopup="dialog">
        <span class="category__icon"><i class="bx bx-gift" aria-hidden="true"></i></span>
        <p>Beneficios</p>
        <span class="category__hint">Puntos y recompensas por usar Agendarte</span>
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

  <section class="final-cta" aria-label="Comenzar con Agendarte">
    <h2 class="final-cta__title">Empezá a agendar clientes hoy</h2>
    <p class="final-cta__text"><?= $freeTrial ?> días de prueba gratis. Sin tarjeta, sin compromiso.</p>
    <button type="button" class="plan-btn final-cta__btn" data-rubro-id="" data-rubro-nombre="">Crear mi cuenta gratis</button>
  </section>
</main>

<footer class="site-footer">
  <div class="site-footer__inner">
    <div class="site-footer__brand">
      <img src="src/media/logo/logo.png" alt="" width="28" height="28" loading="lazy" decoding="async">
      <span>Agendarte</span>
    </div>
    <p class="site-footer__tagline">Reservas online para negocios de Uruguay.</p>
    <div class="site-footer__social">
      <a href="https://instagram.com/agendarte.uy" aria-label="Instagram" target="_blank" rel="noopener"><i class="bx bxl-instagram" aria-hidden="true"></i></a>
      <a href="https://wa.me/59892365135" aria-label="WhatsApp" target="_blank" rel="noopener"><i class="bx bxl-whatsapp" aria-hidden="true"></i></a>
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
</script>
</body>
</html>
