<?php
/**
 * Agenduy - API: Slots disponibles (público)
 *
 * GET /admin/api/availability.php?slug=terap&fecha=2026-07-19&id_service=1
 */
declare(strict_types=1);

$config = require __DIR__ . '/../../src/Core/bootstrap.php';

use Agenduy\Core\Availability;
use Agenduy\Core\Database;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido']);
    exit;
}

try {
    $slug = trim((string)($_GET['slug'] ?? ''));
    $fecha = trim((string)($_GET['fecha'] ?? $_GET['date'] ?? ''));
    $idService = (int)($_GET['id_service'] ?? $_GET['service_id'] ?? 0) ?: null;
    $idBarber = (int)($_GET['id_barber'] ?? $_GET['barber_id'] ?? 0) ?: null;

    if ($slug === '') {
        throw new InvalidArgumentException('Falta slug del comercio.');
    }
    if ($fecha === '') {
        $fecha = date('Y-m-d');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
        throw new InvalidArgumentException('Fecha inválida.');
    }

    $db = Database::getInstance();
    $commerce = $db->fetchOne('SELECT id_commerce, status FROM commerces WHERE slug = :s', [':s' => $slug]);
    if (!$commerce) {
        throw new RuntimeException('Comercio no encontrado.');
    }
    if (in_array((string)$commerce['status'], ['cancelled', 'suspended'], true)) {
        throw new RuntimeException('Este comercio no está aceptando reservas.');
    }

    $result = Availability::forCommerce((int)$commerce['id_commerce'], $fecha, $idService, null, $idBarber);
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    $code = $e instanceof InvalidArgumentException ? 400 : 422;
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
