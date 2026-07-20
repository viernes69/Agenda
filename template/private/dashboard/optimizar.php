<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        throw new RuntimeException('Método no permitido. Usa POST.');
    }

    $baseDir     = dirname(__DIR__, 1); // template/private
    $templateDir = dirname(__DIR__, 2); // template
    $dbPath      = $templateDir . '/src/db/database.php';
    $lockPath    = $templateDir . '/src/db/database.lock';
    $backupDir   = $templateDir . '/src/db/backups';

    if (!file_exists($dbPath)) {
        http_response_code(500);
        throw new RuntimeException('No se encontró el archivo de base de datos.');
    }

    $lockHandle = fopen($lockPath, 'c+');
    if (!$lockHandle) {
        http_response_code(500);
        throw new RuntimeException('No se pudo abrir el candado de la base de datos.');
    }

    if (!flock($lockHandle, LOCK_EX)) {
        fclose($lockHandle);
        http_response_code(500);
        throw new RuntimeException('No se pudo bloquear la base de datos.');
    }

    $db = include $dbPath;
    if (!is_array($db)) {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
        http_response_code(500);
        throw new RuntimeException('La base de datos no devolvió un array válido.');
    }

    $now       = new DateTimeImmutable('now');
    $threshold = $now->modify('-3 months');

    $stats = [
        'reservas' => ['removed' => 0, 'kept' => 0],
        'carrito'  => ['removed' => 0, 'kept' => 0],
    ];

    $filterTable = static function(array $rows, array $dateKeys, DateTimeImmutable $cutoff, string $statsKey) use (&$stats): array {
        $removed = 0;
        $kept    = 0;

        $hasTemplate = isset($rows[0]) && is_array($rows[0]);
        $templateRow = $hasTemplate ? $rows[0] : null;
        if ($hasTemplate) {
            unset($rows[0]);
        }

        $filtered = $hasTemplate && $templateRow !== null ? [$templateRow] : [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                $filtered[] = $row;
                $kept++;
                continue;
            }
            $keep = false;
        $checked = false;
        foreach ($dateKeys as $key) {
            if (!isset($row[$key])) {
                continue;
            }
            $value = trim((string)$row[$key]);
            if ($value === '') {
                continue;
            }
            $checked = true;
            $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value)
                ?: DateTimeImmutable::createFromFormat('Y-m-d', $value)
                ?: DateTimeImmutable::createFromFormat('d/m/Y H:i:s', $value)
                ?: DateTimeImmutable::createFromFormat('d/m/Y', $value);

            if (!$date) {
                continue;
            }
            if ($date >= $cutoff) {
                $keep = true;
                break;
            }
        }
        if (!$keep && !$checked) {
            $keep = true;
        }
        if ($keep) {
            $filtered[] = $row;
            $kept++;
        } else {
            $removed++;
        }
        }

        $stats[$statsKey]['removed'] = $removed;
        $stats[$statsKey]['kept']    = $kept + ($hasTemplate ? 1 : 0);

        return $filtered;
    };

    if (isset($db['reservas']) && is_array($db['reservas'])) {
        $db['reservas'] = $filterTable($db['reservas'], ['Fecha_Reserva'], $threshold, 'reservas');
    }

    if (isset($db['carrito']) && is_array($db['carrito'])) {
        $db['carrito'] = $filterTable($db['carrito'], ['Fecha'], $threshold, 'carrito');
    }

    if (!is_dir($backupDir)) {
        @mkdir($backupDir, 0775, true);
    }
    if (is_dir($backupDir)) {
        @copy($dbPath, $backupDir . '/database_' . $now->format('Ymd_His') . '.php');
    }

    $tmpPath = $dbPath . '.tmp';
    $export  = "<?php return " . var_export($db, true) . ";\n";

    $tmpHandle = fopen($tmpPath, 'c+');
    if (!$tmpHandle) {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
        http_response_code(500);
        throw new RuntimeException('No se pudo crear el archivo temporal.');
    }

    ftruncate($tmpHandle, 0);
    fwrite($tmpHandle, $export);
    fflush($tmpHandle);
    fclose($tmpHandle);

    if (!@rename($tmpPath, $dbPath)) {
        @unlink($tmpPath);
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
        http_response_code(500);
        throw new RuntimeException('No se pudo reemplazar la base de datos.');
    }

    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);

    echo json_encode([
        'ok' => true,
        'message' => 'Optimización completada correctamente.',
        'removed' => $stats,
        'threshold' => $threshold->format('Y-m-d'),
    ]);
} catch (Throwable $e) {
    $code = http_response_code();
    if ($code < 400) {
        http_response_code(500);
    }
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
    ]);
}
