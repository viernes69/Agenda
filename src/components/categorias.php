<?php
declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');

require __DIR__ . '/../Core/bootstrap.php';

use Agenduy\Core\Database;

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$db = Database::getInstance();
$rubros = $db->fetchAll('SELECT id_rubro, nombre, descripcion, imagen, id_plan_def FROM rubros WHERE activo = 1 ORDER BY orden ASC, nombre COLLATE NOCASE ASC');
$planes = $db->fetchAll('SELECT id_membership, nombre, precio, moneda, trial_dias FROM memberships WHERE activo = 1 ORDER BY precio ASC, id_membership ASC');
$planMap = [];
foreach ($planes as $p) {
    $planMap[(string)$p['id_membership']] = $p;
}
$defaultPlan = $planes[0] ?? ['id_membership' => '', 'nombre' => 'Plan', 'precio' => 0, 'moneda' => 'UYU', 'trial_dias' => 30];
?>
<div class="cat-modal">
  <header class="cat-header">
    <div class="cat-header__text">
      <h3 id="modal-rubros-title">Rubros disponibles</h3>
      <p class="cat-subtitle">Elegí tu rubro y comenzá el registro.</p>
    </div>
    <button type="button" class="cat-close" aria-label="Cerrar">&times;</button>
  </header>

  <?php if (empty($rubros)): ?>
    <div class="cat-empty"><p>No hay rubros registrados todavía.</p></div>
  <?php else: ?>
    <div class="cat-grid">
      <?php foreach ($rubros as $r):
        $id = (int)($r['id_rubro'] ?? 0);
        $nombre = (string)($r['nombre'] ?? 'Rubro');
        $desc = (string)($r['descripcion'] ?? '');
        $img = trim((string)($r['imagen'] ?? ''));
        if ($img === '' || !is_file(dirname(__DIR__, 2) . '/' . ltrim(str_replace('\\', '/', $img), '/'))) {
          $img = 'src/media/logo/og-image.png';
        }
        $planRefId = (string)($r['id_plan_def'] ?? '');
        $planInfo = ($planRefId !== '' && isset($planMap[$planRefId])) ? $planMap[$planRefId] : $defaultPlan;
      ?>
      <article class="cat-card">
        <div class="cat-imgfill">
          <img src="<?php echo h(url($img)); ?>" alt="<?php echo h($nombre); ?>" loading="lazy" decoding="async" width="600" height="400">
          <div class="cat-overlay">
            <div class="cat-overlay__text">
              <h4 class="cat-ttl"><?php echo h($nombre); ?></h4>
              <?php if ($desc !== ''): ?><p class="cat-desc small"><?php echo h($desc); ?></p><?php endif; ?>
              <div class="cat-planline">
                <button class="btn-contratar"
                        data-rubro="<?php echo h((string)$id); ?>"
                        data-nombre="<?php echo h($nombre); ?>"
                        data-plan-id="<?php echo h((string)($planInfo['id_membership'] ?? '')); ?>"
                        data-plan-nombre="<?php echo h((string)($planInfo['nombre'] ?? 'Plan')); ?>">
                  Comenzar (<?php echo h((string)($planInfo['moneda'] ?? 'UYU')); ?> <?php echo number_format((float)($planInfo['precio'] ?? 0), 0, ',', '.'); ?>)
                </button>
              </div>
            </div>
          </div>
        </div>
      </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
