<?php
/**
 * Backfill: copia Precio del servicio local a reservas que no lo tienen.
 * Uso: php storage/_backfill_reserva_precio.php terap
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../src/Core/bootstrap.php';

use Agenduy\Core\TenantLocalDb;

$slug = $argv[1] ?? '';
if ($slug === '' || !preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug)) {
    fwrite(STDERR, "Uso: php storage/_backfill_reserva_precio.php <slug>\n");
    exit(1);
}

if (!TenantLocalDb::exists($slug)) {
    fwrite(STDERR, "Tenant local DB no existe: {$slug}\n");
    exit(1);
}

$result = TenantLocalDb::mutate($slug, static function (array $db) {
    if (!isset($db['reservas'][0]) || !is_array($db['reservas'][0])) {
        throw new RuntimeException('Tabla reservas no disponible.');
    }

    $priceByService = [];
    foreach (($db['servicios'] ?? []) as $i => $row) {
        if ($i === 0 || !is_array($row)) {
            continue;
        }
        $sid = $row['ID_Servicio'] ?? null;
        if ($sid === null || $sid === '' || !is_numeric($sid)) {
            continue;
        }
        if (!is_numeric($row['Precio'] ?? null)) {
            continue;
        }
        $priceByService[(int)$sid] = round((float)$row['Precio'], 2);
    }

    if (!array_key_exists('Precio', $db['reservas'][0])) {
        $db['reservas'][0]['Precio'] = null;
    }

    $updated = 0;
    $skipped = 0;
    foreach ($db['reservas'] as $i => $row) {
        if ($i === 0 || !is_array($row)) {
            continue;
        }
        if (!array_key_exists('Precio', $row)) {
            $db['reservas'][$i]['Precio'] = null;
            $row = $db['reservas'][$i];
        }
        $existing = $row['Precio'] ?? null;
        if ($existing !== null && $existing !== '' && is_numeric($existing) && (float)$existing > 0) {
            $skipped++;
            continue;
        }
        $sid = $row['ID_Servicio'] ?? null;
        if ($sid === null || $sid === '' || !is_numeric($sid)) {
            $skipped++;
            continue;
        }
        $sid = (int)$sid;
        if (!isset($priceByService[$sid])) {
            $skipped++;
            continue;
        }
        $db['reservas'][$i]['Precio'] = $priceByService[$sid];
        $updated++;
    }

    return [$db, ['updated' => $updated, 'skipped' => $skipped]];
});

echo "Done. updated={$result['updated']} skipped={$result['skipped']}\n";
