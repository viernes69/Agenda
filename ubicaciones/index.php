<?php
/**
 * Agendarte UY — Páginas de ubicaciones SEO (/ubicaciones/{slug})
 */
declare(strict_types=1);

$config = require __DIR__ . '/../src/Core/bootstrap.php';

use Agenduy\Core\Database;
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

$db = Database::getInstance();
$commerces = [];
if (!$isHub) {
    $city = (string)($location['title'] ?? '');
    $commerces = $db->fetchAll(
        "SELECT slug, nombre, ciudad FROM commerces
         WHERE status IN ('trial','active') AND lower(ciudad) LIKE lower(:city)
         ORDER BY nombre COLLATE NOCASE ASC LIMIT 24",
        [':city' => '%' . $city . '%']
    );
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= h($pageTitle) ?></title>
  <meta name="description" content="<?= h($pageDescription) ?>">
  <link rel="canonical" href="<?= h($isHub ? $siteUrl . '/ubicaciones/' : $siteUrl . '/ubicaciones/' . rawurlencode($slug) . '/') ?>">
  <link rel="stylesheet" href="<?= h(url('src/css/styles.css')) ?>">
</head>
<body>
<header class="site-header">
  <div class="site-header__inner">
    <a class="site-header__brand" href="<?= h(url('')) ?>">
      <img src="<?= h(url('src/media/logo/logo-horizontal.png')) ?>" alt="Agendarte UY" class="brand-logo brand-logo--horizontal" width="168" height="44">
    </a>
  </div>
</header>

<main class="landing seo-page">
  <section class="seo-page__hero">
    <?php if ($isHub): ?>
      <h1>Reservas online por ubicación</h1>
      <p>Encontrá servicios con agenda en distintas ciudades de Uruguay.</p>
      <ul class="seo-location-list">
        <?php foreach ($locations as $locSlug => $loc): ?>
        <li><a href="<?= h(url('ubicaciones/' . rawurlencode($locSlug) . '/')) ?>"><?= h($loc['title']) ?></a></li>
        <?php endforeach; ?>
      </ul>
    <?php else: ?>
      <h1>Reservas online en <?= h($location['title']) ?></h1>
      <p><?= h($location['description']) ?></p>
      <?php if (empty($commerces)): ?>
        <p class="seo-page__empty">Pronto habrá más negocios en esta zona. <a href="<?= h(url('')) ?>#rubros">Registrá el tuyo</a>.</p>
      <?php else: ?>
        <ul class="seo-commerce-list">
          <?php foreach ($commerces as $c): ?>
          <li><a href="<?= h(url((string)$c['slug'])) ?>"><strong><?= h($c['nombre']) ?></strong></a></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <p class="seo-page__back"><a href="<?= h(url('categorias/')) ?>">Ver categorías</a></p>
    <?php endif; ?>
  </section>
</main>

<footer class="site-footer">
  <div class="site-footer__inner">
    <p class="site-footer__tagline"><?= h(LandingContent::TAGLINE) ?></p>
  </div>
</footer>
</body>
</html>
