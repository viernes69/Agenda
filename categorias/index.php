<?php
/**
 * Agendarte UY — Páginas de categorías SEO (/categorias/{slug})
 */
declare(strict_types=1);

$config = require __DIR__ . '/../src/Core/bootstrap.php';

use Agenduy\Core\LandingContent;

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$slug = trim((string)($_GET['c'] ?? ''));
$categories = LandingContent::categories();
$isHub = ($slug === '' || !isset($categories[$slug]));
$category = $isHub ? null : $categories[$slug];

$siteUrl = rtrim(url(''), '/');
$stylesPath = dirname(__DIR__) . '/src/css/styles.css';
$stylesVer = is_file($stylesPath) ? (string)filemtime($stylesPath) : (string)time();
$logoIconPath = dirname(__DIR__) . '/src/media/logo/logo-icon.png';
$logoVer = is_file($logoIconPath) ? (string)filemtime($logoIconPath) : (string)time();
$pageTitle = $isHub
    ? 'Categorías de servicios con reserva online · Agendarte UY'
    : ($category['title'] ?? '') . ' · Reservas online Uruguay · Agendarte UY';
$pageDescription = $isHub
    ? 'Encontrá y reservá turnos online por categoría: belleza, salud, deporte, educación y más servicios en Uruguay.'
    : (string)($category['description'] ?? LandingContent::SITE_DESCRIPTION);

$commerces = [];
if (!$isHub) {
    $commerces = LandingContent::commercesForCategory($slug);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= h($pageTitle) ?></title>
  <meta name="description" content="<?= h($pageDescription) ?>">
  <?php if (!$isHub && !empty($category['keywords'])): ?>
  <meta name="keywords" content="<?= h(implode(', ', $category['keywords'])) ?>">
  <?php endif; ?>
  <link rel="canonical" href="<?= h($isHub ? $siteUrl . '/categorias/' : $siteUrl . '/categorias/' . rawurlencode($slug) . '/') ?>">
  <meta property="og:title" content="<?= h($pageTitle) ?>">
  <meta property="og:description" content="<?= h($pageDescription) ?>">
  <meta property="og:type" content="website">
  <meta property="og:image" content="<?= h($siteUrl . '/src/media/logo/og-image.png') ?>">
  <link rel="icon" type="image/png" href="<?= h(url('src/img/favicon/favicon.png')) ?>">
  <link rel="stylesheet" href="<?= h(url('src/css/styles.css?v=' . $stylesVer)) ?>">
  <link rel="stylesheet" href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css">
  <script src="<?= h(url('src/js/register/modal.js')) ?>" defer></script>
</head>
<body>
<header class="site-header">
  <div class="site-header__inner">
    <a class="site-header__brand" href="<?= h(url('')) ?>" aria-label="Inicio" style="display:flex;align-items:center;gap:8px;font-weight:700;font-size:1.25rem;color:var(--text, #1e293b);text-decoration:none;">
      <img src="<?= h(url('src/media/logo/logo-icon.png?v=' . $logoVer)) ?>" alt="Agendarte UY" class="brand-logo" width="28" height="28" fetchpriority="high">
      <span>Agendarte <span class="brand-uy" style="color:var(--primary, #6366f1);">UY</span></span>
    </a>
    <nav class="site-header__actions">
      <a class="btn-primary btn-primary--sm" href="<?= h(url('')) ?>#rubros" onclick="event.preventDefault(); window.openRegisterModal && window.openRegisterModal();">Registrar mi negocio</a>
    </nav>
  </div>
</header>

<main class="landing seo-page">
  <section class="seo-page__hero">
    <div class="hero-bg-orbs">
      <div class="orb orb-1"></div>
      <div class="orb orb-2"></div>
    </div>
    <?php if ($isHub): ?>
      <p class="section-heading__eyebrow">Categorías</p>
      <h1>Encontrá y reservá el servicio que necesitás</h1>
      <p>En Agendarte UY podés consultar disponibilidad y solicitar turnos para diferentes actividades y profesionales en Uruguay.</p>
    <?php else: ?>
      <p class="section-heading__eyebrow">Categoría</p>
      <h1><?= h($category['title']) ?></h1>
      <p><?= h($category['description']) ?></p>
    <?php endif; ?>
  </section>

  <?php if ($isHub): ?>
  <section class="categories-grid-section" aria-label="Categorías disponibles">
    <div class="categories-grid">
      <?php foreach ($categories as $catSlug => $cat): ?>
      <a class="category-card" href="<?= h(url('categorias/' . rawurlencode($catSlug) . '/')) ?>">
        <span class="category-card__title"><?= h($cat['title']) ?></span>
        <span class="category-card__hint"><?= h($cat['description']) ?></span>
      </a>
      <?php endforeach; ?>
    </div>
  </section>
  <?php else: ?>
  <section class="seo-page__list" aria-label="Negocios en esta categoría">
    <h2>Negocios disponibles</h2>
    <?php if (empty($commerces)): ?>
      <p class="seo-page__empty">Todavía no hay comercios publicados en esta categoría. <a href="javascript:void(0)" onclick="window.openRegisterModal && window.openRegisterModal()">Registrá el tuyo</a>.</p>
    <?php else: ?>
      <ul class="seo-commerce-list">
        <?php foreach ($commerces as $c): ?>
        <li>
          <a href="<?= h(url((string)$c['slug'])) ?>">
            <strong><?= h($c['nombre']) ?></strong>
            <?php if (!empty($c['ciudad'])): ?>
              <span><?= h($c['ciudad']) ?></span>
            <?php endif; ?>
          </a>
        </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
    <p class="seo-page__back">
      <a href="<?= h(url('categorias/')) ?>">&larr; Ver todas las categorías</a>
      ·
      <a href="<?= h(url('ubicaciones/')) ?>">Buscar por ciudad</a>
    </p>
  </section>
  <?php endif; ?>
</main>

<footer class="site-footer">
  <div class="site-footer__inner">
    <div class="site-footer__brand">
      <img src="<?= h(url('src/media/logo/logo-icon.png?v=' . $logoVer)) ?>" alt="" width="28" height="28" loading="lazy">
      <span>Agendarte <span class="brand-uy">UY</span></span>
    </div>
    <p class="site-footer__tagline"><?= h(LandingContent::TAGLINE) ?></p>
  </div>
</footer>
<?php include __DIR__ . '/../src/components/register/modal.php'; ?>
</body>
</html>
