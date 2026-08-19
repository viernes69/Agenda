<?php
/**
 * bin/expire-trials.php
 *
 * Revierte al plan Free los comercios cuyo trial vencio y no pagaron.
 * Configurar como tarea programada (cada hora o diario).
 *
 * Windows Task Scheduler:
 *   Programa:   c:\xampp\php\php.exe
 *   Argumentos: c:\xampp\htdocs\agenda\bin\expire-trials.php
 */
declare(strict_types=1);

$configPath = __DIR__ . '/../src/Core/bootstrap.php';
if (!file_exists($configPath)) {
    fwrite(STDERR, "[expire-trials] Bootstrap no encontrado: {$configPath}\n");
    exit(1);
}
require $configPath;

use Agenduy\Core\Database;

$db  = Database::getInstance();
$now = date('Y-m-d H:i:s');
echo "[expire-trials] Ejecutando: {$now}\n";

$freePlan = $db->pdo()->query(
    "SELECT id_membership FROM memberships
     WHERE LOWER(nombre) IN ('free','gratis','gratuito')
     ORDER BY precio ASC LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);

if (!$freePlan) {
    echo "[expire-trials] ERROR: No se encontro el plan Free. Abortando.\n";
    exit(1);
}
$freePlanId = (int)$freePlan['id_membership'];
echo "[expire-trials] Plan Free ID: {$freePlanId}\n";

$stmt = $db->pdo()->prepare(
    "SELECT c.id_commerce, c.slug, c.nombre, c.trial_expires_at, m.nombre AS plan_nombre
     FROM commerces c
     LEFT JOIN memberships m ON m.id_membership = c.id_membership
     WHERE c.status = 'trial'
       AND c.id_membership != :freeId
       AND c.trial_expires_at IS NOT NULL
       AND datetime(c.trial_expires_at) < datetime('now')"
);
$stmt->execute([':freeId' => $freePlanId]);
$expiredList = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($expiredList)) {
    echo "[expire-trials] No hay trials vencidos. Todo OK.\n";
    exit(0);
}

echo "[expire-trials] " . count($expiredList) . " comercio(s) con trial vencido:\n";

$updCom = $db->pdo()->prepare(
    "UPDATE commerces
     SET id_membership = :freeId,
         status = 'trial',
         trial_expires_at = date('now', '+30 days'),
         updated_at = datetime('now')
     WHERE id_commerce = :id"
);
$updSub = $db->pdo()->prepare(
    "UPDATE subscriptions
     SET id_membership = :freeId,
         status = 'trial',
         trial_expires_at = date('now', '+30 days'),
         current_period_end = date('now', '+30 days'),
         updated_at = datetime('now')
     WHERE id_commerce = :id AND status = 'trial'"
);

foreach ($expiredList as $c) {
    $id   = (int)$c['id_commerce'];
    echo "  -> [{$c['slug']}] \"{$c['nombre']}\" (era: {$c['plan_nombre']}, vencio: {$c['trial_expires_at']})\n";
    $db->pdo()->beginTransaction();
    try {
        $updCom->execute([':freeId' => $freePlanId, ':id' => $id]);
        $updSub->execute([':freeId' => $freePlanId, ':id' => $id]);
        $db->pdo()->commit();
        echo "     Revertido al plan Free (trial 30 dias desde hoy)\n";
    } catch (Throwable $e) {
        $db->pdo()->rollBack();
        echo "     ERROR: " . $e->getMessage() . "\n";
    }
}
echo "[expire-trials] Completado.\n";
