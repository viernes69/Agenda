<?php
/**
 * Sitemap XML dinámico — Agendarte UY
 */
declare(strict_types=1);

$config = require __DIR__ . '/src/Core/bootstrap.php';

use Agenduy\Core\Database;
use Agenduy\Core\LandingContent;

header('Content-Type: application/xml; charset=utf-8');

$base = rtrim(url(''), '/');
$now = date('c');

$urls = [
    ['loc' => $base . '/', 'priority' => '1.0'],
    ['loc' => $base . '/categorias/', 'priority' => '0.9'],
    ['loc' => $base . '/ubicaciones/', 'priority' => '0.8'],
];

foreach (array_keys(LandingContent::categories()) as $slug) {
    $urls[] = ['loc' => $base . '/categorias/' . rawurlencode($slug) . '/', 'priority' => '0.7'];
}
foreach (array_keys(LandingContent::locations()) as $slug) {
    $urls[] = ['loc' => $base . '/ubicaciones/' . rawurlencode($slug) . '/', 'priority' => '0.6'];
}

$db = Database::getInstance();
$commerces = $db->fetchAll(
    "SELECT slug FROM commerces WHERE status IN ('trial','active') ORDER BY slug ASC"
);
foreach ($commerces as $row) {
    $slug = trim((string)($row['slug'] ?? ''));
    if ($slug === '') continue;
    $urls[] = ['loc' => $base . '/' . rawurlencode($slug) . '/', 'priority' => '0.5'];
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
<?php foreach ($urls as $u): ?>
  <url>
    <loc><?= htmlspecialchars($u['loc'], ENT_XML1 | ENT_QUOTES, 'UTF-8') ?></loc>
    <lastmod><?= $now ?></lastmod>
    <changefreq>weekly</changefreq>
    <priority><?= htmlspecialchars((string)$u['priority'], ENT_XML1, 'UTF-8') ?></priority>
  </url>
<?php endforeach; ?>
</urlset>
