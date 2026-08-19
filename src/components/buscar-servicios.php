<?php
declare(strict_types=1);

header('Content-Type: text/html; charset=UTF-8');

// Este componente se carga vía fetch() desde el landing, sin pasar por el bootstrap.
// Cargamos manualmente solo lo necesario: helpers de URL + bootstrap completo (BD nueva).
$__needBootstrap = true;
require __DIR__ . '/../Core/helpers.php';

function h($value) {
  return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function business_text_lower(string $value): string {
  return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function business_text_upper(string $value): string {
  return function_exists('mb_strtoupper') ? mb_strtoupper($value, 'UTF-8') : strtoupper($value);
}

function business_text_substr(string $value, int $start, int $length): string {
  return function_exists('mb_substr') ? mb_substr($value, $start, $length, 'UTF-8') : substr($value, $start, $length);
}

function business_text_length(string $value): int {
  return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function business_initials(string $name): string {
  $name = trim($name);
  if ($name === '') {
    return 'AG';
  }
  $parts = preg_split('/\s+/', $name);
  $initials = '';
  foreach ($parts as $part) {
    $initials .= business_text_upper(business_text_substr($part, 0, 1));
    if (business_text_length($initials) >= 2) {
      break;
    }
  }
  return $initials ?: business_text_upper(business_text_substr($name, 0, 2));
}

function country_name(string $code): string {
  $map = [
    'UY' => 'Uruguay',
    'AR' => 'Argentina',
    'BR' => 'Brasil',
    'CL' => 'Chile',
    'PY' => 'Paraguay',
  ];
  $code = strtoupper($code);
  return $map[$code] ?? $code;
}

function business_logo_url(array $business): string {
  $idCommerce = (int)($business['id_commerce'] ?? 0);
  $slug = trim((string)($business['slug'] ?? ''), '/');
  $candidates = [trim((string)($business['logo'] ?? ''))];

  if ($idCommerce > 0 && $slug !== '' && class_exists('Agenduy\\Core\\CommercePublic')) {
    $localDbPath = \Agenduy\Core\CommercePublic::resolveLocalDatabasePath($idCommerce, $slug);
    if ($localDbPath !== null && is_file($localDbPath)) {
      $localDb = @include $localDbPath;
      $info = is_array($localDb) && isset($localDb['info_barberia']) && is_array($localDb['info_barberia'])
        ? $localDb['info_barberia']
        : [];
      foreach (['logo_src', 'logo', 'imagen', 'Logo', 'avatar'] as $key) {
        $candidates[] = trim((string)($info[$key] ?? ''));
      }
    }
  }

  foreach ($candidates as $candidate) {
    $url = business_logo_candidate_url($idCommerce, $slug, $candidate);
    if ($url !== '') {
      return $url;
    }
  }

  foreach (['src/img/logo.png', 'src/img/logo.jpg', 'src/img/logo.webp', 'src/media/logo/logo.png'] as $fallback) {
    $url = business_logo_candidate_url($idCommerce, $slug, $fallback);
    if ($url !== '') {
      return $url;
    }
  }

  return '';
}

function business_logo_candidate_url(int $idCommerce, string $slug, string $storedPath): string {
  $storedPath = trim(str_replace('\\', '/', $storedPath));
  if ($storedPath === '') {
    return '';
  }
  if (preg_match('#^https?://#i', $storedPath)) {
    return $storedPath;
  }
  if ($storedPath[0] === '/') {
    return $storedPath;
  }

  if ($idCommerce > 0 && class_exists('Agenduy\\Core\\CommerceStorage')) {
    $url = \Agenduy\Core\CommerceStorage::publicUrl($idCommerce, $slug, $storedPath);
    if ($url !== '') {
      return $url;
    }
  }

  $root = dirname(__DIR__, 2);
  $relative = ltrim($storedPath, '/');
  if ($slug !== '') {
    $tenantRelative = str_starts_with($relative, $slug . '/') ? $relative : $slug . '/' . $relative;
    $tenantFullPath = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $tenantRelative);
    if (is_file($tenantFullPath)) {
      return url($tenantRelative);
    }
  }

  return '';
}

// Lee comercios de la BD nueva (SQLite).
// Si el bootstrap ya está cargado, lo usa. Si no, lo carga ahora.
try {
  if (!class_exists('Agenduy\\Core\\Database')) {
    require __DIR__ . '/../Core/bootstrap.php';
  }
  $db = \Agenduy\Core\Database::getInstance();
  $rows = $db->fetchAll(
    "SELECT id_commerce, slug, nombre, ciudad, calle, pais, logo
     FROM commerces
     WHERE status IN ('trial','active')
     ORDER BY nombre"
  );
  $businesses = [];
  foreach ($rows as $r) {
    $businesses[] = [
      'id_commerce' => (int)($r['id_commerce'] ?? 0),
      'slug'        => (string)($r['slug'] ?? ''),
      'nombre'      => (string)($r['nombre'] ?? 'Negocio'),
      'ciudad'      => (string)($r['ciudad'] ?? ''),
      'calle'       => (string)($r['calle'] ?? ''),
      'pais'        => (string)($r['pais'] ?? 'UY'),
      'logo'        => (string)($r['logo'] ?? ''),
    ];
  }
} catch (Throwable $e) {
  $logDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
  $message = '[' . date('c') . '] [buscar-servicios] ' . $e->getMessage() . PHP_EOL;
  $logReady = is_dir($logDir) || @mkdir($logDir, 0775, true) || is_dir($logDir);
  if (!$logReady || error_log($message, 3, $logDir . DIRECTORY_SEPARATOR . 'buscar-servicios.log') === false) {
    error_log(trim($message));
  }
  $businesses = [];
}
?>
<div class="search-modal" role="document">
  <header class="search-modal__header">
    <div>
      <h3 id="modal-buscar-title">Busca servicios de nuestros clientes</h3>
      <p class="search-modal__hint">Explorá negocios reales que ya confían en Agenduy. Hacé clic para conocer sus sitios.</p>
    </div>
    <button type="button" class="search-modal__close" aria-label="Cerrar">&times;</button>
  </header>
  <div class="search-modal__filter">
    <i class='bx bx-search' aria-hidden="true"></i>
    <input type="search"
           class="search-modal__input"
           placeholder="Buscar por nombre de negocio..."
           aria-label="Buscar servicios"
           data-search-input />
  </div>

  <?php if (empty($businesses)): ?>
    <div class="search-modal__empty">
      <p>Todavía no hay servicios cargados. Vuelve más tarde.</p>
    </div>
  <?php else: ?>
    <div class="search-grid">
      <?php foreach ($businesses as $biz):
        $name = trim((string)($biz['nombre'] ?? 'Negocio'));
        $initials = business_initials($name);
        $slug = (string)($biz['slug'] ?? '');
        $logo = business_logo_url($biz);
        $addressParts = array_filter([
          (string)($biz['calle'] ?? ''),
          (string)($biz['ciudad'] ?? ''),
          country_name((string)($biz['pais'] ?? '')),
        ], static fn($part) => trim((string)$part) !== '');
        $address = implode(', ', array_map('trim', $addressParts));
        $profileUrl = $slug !== '' ? url($slug) : url_base();
      ?>
        <article class="search-card" data-search-name="<?php echo h(business_text_lower($name)); ?>">
          <a class="search-card__link" href="<?php echo h($profileUrl); ?>" target="_blank" rel="noopener noreferrer">
            <div class="search-card__avatar<?php echo $logo !== '' ? ' has-logo' : ''; ?>">
              <?php if ($logo !== ''): ?>
                <img src="<?php echo h($logo); ?>"
                     alt="Logo de <?php echo h($name); ?>"
                     class="search-card__avatar-img"
                     loading="lazy"
                     decoding="async"
                     onerror="this.hidden=true; this.parentElement && this.parentElement.classList.remove('has-logo');"
                     width="48" height="48" />
              <?php endif; ?>
              <span class="search-card__initials" aria-hidden="true"><?php echo h($initials); ?></span>
            </div>
            <div class="search-card__meta">
              <h4><?php echo h($name); ?></h4>
              <?php if ($address !== ''): ?>
                <p><?php echo h($address); ?></p>
              <?php endif; ?>
            </div>
          </a>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
