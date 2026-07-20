<?php
/**
 * Backfill: espeja appointments SQLite → reservas locales del tenant.
 * Uso: php storage/_backfill_appointments.php terap
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../src/Core/bootstrap.php';

use Agenduy\Core\Database;
use Agenduy\Core\TenantLocalDb;

$slug = $argv[1] ?? '';
if ($slug === '' || !preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug)) {
    fwrite(STDERR, "Uso: php storage/_backfill_appointments.php <slug>\n");
    exit(1);
}

if (!TenantLocalDb::exists($slug)) {
    fwrite(STDERR, "Tenant local DB no existe: {$slug}\n");
    exit(1);
}

$db = Database::getInstance();
$commerce = $db->fetchOne('SELECT id_commerce, slug, nombre FROM commerces WHERE slug = :s', [':s' => $slug]);
if (!$commerce) {
    fwrite(STDERR, "Comercio no encontrado: {$slug}\n");
    exit(1);
}

$appts = $db->fetchAll(
    'SELECT a.*, s.id_local
     FROM appointments a
     LEFT JOIN services s ON s.id_service = a.id_service
     WHERE a.id_commerce = :c
     ORDER BY a.id_appointment ASC',
    [':c' => (int)$commerce['id_commerce']]
);

$created = 0;
$updated = 0;
$errors = 0;
foreach ($appts as $appt) {
    try {
        $result = TenantLocalDb::mirrorAppointment($slug, $appt);
        if (!empty($result['created'])) {
            $created++;
            echo "created local for appt #{$appt['id_appointment']}\n";
        } elseif (!empty($result['skipped'])) {
            echo "skipped appt #{$appt['id_appointment']}\n";
        } else {
            $updated++;
            echo "updated local for appt #{$appt['id_appointment']}\n";
        }
    } catch (Throwable $e) {
        $errors++;
        fwrite(STDERR, "error appt #{$appt['id_appointment']}: {$e->getMessage()}\n");
    }
}

echo "Done. created={$created} updated={$updated} errors={$errors} total=" . count($appts) . "\n";
