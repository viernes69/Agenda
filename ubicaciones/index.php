<?php
/**
 * Agendarte UY — Páginas de ubicaciones SEO (/ubicaciones/{slug})
 */
declare(strict_types=1);

$config = require __DIR__ . '/../src/Core/bootstrap.php';

use Agenduy\Core\LandingContent;

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$slug = trim((string)($_GET['l'] ?? ''));
$locations = LandingContent::locations();
$isHub = ($slug === '' || !isset($locations[$slug]));
$location = $isHub ? null : $locations[$slug];

$siteUrl = rtrim(url(''), '/');
$pageTitle = $isHub
    ? 'Reservas online por ciudad · Agendarte UY'
    : 'Reservas online en ' . ($location['title'] ?? '') . ' · Agendarte UY';
$pageDescription = $isHub
    ? 'Turnos y agendas online para servicios en distintas ciudades de Uruguay.'
    : (string)($location['description'] ?? LandingContent::SITE_DESCRIPTION);

$commerces = [];
if (!$isHub) {
    $commerces = LandingContent::commercesForLocation($slug);
}

$stylesPath = dirname(__DIR__) . '/src/css/styles.css';
$stylesVer = is_file($stylesPath) ? (string)filemtime($stylesPath) : (string)time();
$logoIconPath = dirname(__DIR__) . '/src/media/logo/logo-icon.png';
$logoVer = is_file($logoIconPath) ? (string)filemtime($logoIconPath) : (string)time();
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= h($pageTitle) ?></title>
  <meta name="description" content="<?= h($pageDescription) ?>">
  <link rel="canonical" href="<?= h($isHub ? $siteUrl . '/ubicaciones/' : $siteUrl . '/ubicaciones/' . rawurlencode($slug) . '/') ?>">
  <meta property="og:title" content="<?= h($pageTitle) ?>">
  <meta property="og:description" content="<?= h($pageDescription) ?>">
  <meta property="og:type" content="website">
  <meta property="og:image" content="<?= h($siteUrl . '/src/media/logo/og-image.png') ?>">
  <link rel="icon" type="image/png" href="<?= h(url('src/img/favicon/favicon.png')) ?>">
  <link rel="stylesheet" href="<?= h(url('src/css/styles.css?v=' . $stylesVer)) ?>">
  <link rel="stylesheet" href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css">
</head>
<body>
<header class="site-header">
  <div class="site-header__inner">
    <a class="site-header__brand" href="<?= h(url('')) ?>" aria-label="Inicio" style="display:flex;align-items:center;gap:8px;font-weight:700;font-size:1.25rem;color:var(--text, #1e293b);text-decoration:none;">
      <img src="<?= h(url('src/media/logo/logo-icon.png?v=' . $logoVer)) ?>" alt="Agendarte UY" class="brand-logo" width="28" height="28" fetchpriority="high">
      <span>Agendarte <span class="brand-uy" style="color:var(--primary, #6366f1);">UY</span></span>
    </a>
    <nav class="site-header__actions">
      <a class="btn-primary btn-primary--sm" href="<?= h(url('')) ?>#rubros">Registrar mi negocio</a>
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
      <p class="section-heading__eyebrow">Ubicaciones</p>
      <h1>Reservas online por ciudad</h1>
      <p>Encontrá servicios con agenda en distintas ciudades de Uruguay y reservá turnos en pocos clics.</p>
    <?php else: ?>
      <p class="section-heading__eyebrow">Ciudad</p>
      <h1>Reservas online en <?= h($location['title']) ?></h1>
      <p><?= h($location['description']) ?></p>
    <?php endif; ?>
  </section>

  <?php if ($isHub): ?>
  <section class="categories-grid-section" aria-label="Ciudades disponibles">
    <div class="categories-grid">
      <?php foreach ($locations as $locSlug => $loc): ?>
      <a class="category-card category-card--location" href="<?= h(url('ubicaciones/' . rawurlencode($locSlug) . '/')) ?>">
        <span class="category-card__icon" aria-hidden="true"><i class="bx bx-map"></i></span>
        <span class="category-card__title"><?= h($loc['title']) ?></span>
        <span class="category-card__hint"><?= h($loc['description']) ?></span>
      </a>
      <?php endforeach; ?>
    </div>
    <p class="categories-section__more">
      <a href="<?= h(url('categorias/')) ?>">Ver todas las categorías</a>
    </p>
  </section>
  <?php else: ?>
  <section class="seo-page__list" aria-label="Negocios en esta ciudad">
    <h2>Negocios disponibles</h2>
    <?php if (empty($commerces)): ?>
      <p class="seo-page__empty">Todavía no hay comercios publicados en esta ciudad. <a href="javascript:void(0)" onclick="window.openRegisterModal && window.openRegisterModal()">Registrá el tuyo</a>.</p>
    <?php else: ?>
      <ul class="seo-commerce-list">
        <?php foreach ($commerces as $c): ?>
        <li>
          <a href="<?= h(url((string)$c['slug'])) ?>">
            <strong><?= h($c['nombre']) ?></strong>
            <?php if (!empty($c['rubro_nombre'])): ?>
              <span><?= h($c['rubro_nombre']) ?><?php if (!empty($c['ciudad'])): ?> · <?= h($c['ciudad']) ?><?php endif; ?></span>
            <?php elseif (!empty($c['ciudad'])): ?>
              <span><?= h($c['ciudad']) ?></span>
            <?php endif; ?>
          </a>
        </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
    <p class="seo-page__back">
      <a href="<?= h(url('ubicaciones/')) ?>">&larr; Ver todas las ciudades</a>
      ·
      <a href="<?= h(url('categorias/')) ?>">Ver categorías</a>
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
</body>
</html>
