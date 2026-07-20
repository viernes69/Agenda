<?php
/**
 * Agenduy - API: Registrar pedido público (carrito → tenant database.php)
 *
 * POST /admin/api/cart_order.php
 *   body JSON/form: {
 *     slug, items:[{id, qty}], cliente_nombre?, cliente_email?, cliente_telefono?,
 *     address?, note?, appointment_id?, _csrf
 *   }
 *
 * Escribe Status=Pendiente en la tabla local `carrito` que lee el admin del tenant.
 */
declare(strict_types=1);

$config = require __DIR__ . '/../../src/Core/bootstrap.php';

use Agenduy\Core\CSRF;
use Agenduy\Core\Database;
use Agenduy\Core\TenantLocalDb;

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido']);
    exit;
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw ?: '', true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$csrf = $payload['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
if (!CSRF::validate(is_string($csrf) ? $csrf : null, 'public_booking', false)) {
    http_response_code(428);
    echo json_encode([
        'ok' => false,
        'error' => 'csrf_retry',
        'csrf' => CSRF::generate('public_booking'),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $db = Database::getInstance();
    $slug = trim((string)($payload['slug'] ?? ''));
    if ($slug === '') {
        throw new InvalidArgumentException('Falta slug del comercio.');
    }

    $commerce = $db->fetchOne('SELECT * FROM commerces WHERE slug = :s', [':s' => $slug]);
    if (!$commerce) {
        throw new RuntimeException('Comercio no encontrado.');
    }
    if (in_array((string)($commerce['status'] ?? ''), ['cancelled', 'suspended'], true)) {
        throw new RuntimeException('Este comercio no está aceptando pedidos.');
    }
    if (!TenantLocalDb::exists($slug)) {
        throw new RuntimeException('Base local del comercio no disponible.');
    }

    $itemsRaw = $payload['items'] ?? [];
    if (!is_array($itemsRaw) || $itemsRaw === []) {
        throw new InvalidArgumentException('El pedido no tiene productos.');
    }

    $catalog = TenantLocalDb::productIndex($slug);
    if ($catalog === []) {
        throw new RuntimeException('Este comercio no tiene productos configurados.');
    }

    $qtyByProduct = [];
    foreach ($itemsRaw as $item) {
        if (!is_array($item)) {
            continue;
        }
        $pid = trim((string)($item['id'] ?? $item['ID_Product'] ?? ''));
        $qty = (int)($item['qty'] ?? $item['cantidad'] ?? 0);
        if ($pid === '' || $qty <= 0) {
            continue;
        }
        if (!isset($catalog[$pid])) {
            throw new InvalidArgumentException('Producto no válido en el catálogo.');
        }
        $qty = min(20, $qty);
        $qtyByProduct[$pid] = ($qtyByProduct[$pid] ?? 0) + $qty;
        if ($qtyByProduct[$pid] > 20) {
            $qtyByProduct[$pid] = 20;
        }
    }
    if ($qtyByProduct === []) {
        throw new InvalidArgumentException('No hay productos válidos en el pedido.');
    }

    $pairs = [];
    foreach ($qtyByProduct as $pid => $qty) {
        $pairs[] = '(' . $pid . ' + ' . $qty . ')';
    }

    $clienteNombre = trim((string)($payload['cliente_nombre'] ?? ''));
    $clienteEmail = trim((string)($payload['cliente_email'] ?? ''));
    $clienteTelefono = trim((string)($payload['cliente_telefono'] ?? ''));
    if ($clienteEmail !== '' && !filter_var($clienteEmail, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Email inválido.');
    }

    $address = trim((string)($payload['address'] ?? ''));
    if ($address === '') {
        $address = 'Coordinar por WhatsApp';
    }
    $note = trim((string)($payload['note'] ?? ''));
    $appointmentId = trim((string)($payload['appointment_id'] ?? ''));
    $extraBits = [];
    if ($note !== '') {
        $extraBits[] = $note;
    }
    if ($appointmentId !== '') {
        $extraBits[] = 'Reserva #' . $appointmentId;
    }
    if ($clienteNombre !== '') {
        $extraBits[] = $clienteNombre;
    }
    if ($clienteTelefono !== '') {
        $extraBits[] = $clienteTelefono;
    }
    if ($extraBits) {
        $address = $address . ' · ' . implode(' · ', $extraBits);
        if (mb_strlen($address, 'UTF-8') > 240) {
            $address = mb_substr($address, 0, 237, 'UTF-8') . '...';
        }
    }

    $clienteId = TenantLocalDb::findOrCreateCliente($slug, $clienteNombre, $clienteTelefono, $clienteEmail);

    $record = [
        'ID_Cliente' => $clienteId,
        'Dirección' => $address,
        'ID_Producto + Cantidad' => implode(', ', $pairs),
        'Hora' => date('H:i:s'),
        'Fecha' => date('Y-m-d'),
        'Status' => 'Pendiente',
    ];

    $row = TenantLocalDb::insert($slug, 'carrito', $record);
    $orderId = $row['ID_Carrito'] ?? null;

    echo json_encode([
        'ok' => true,
        'order_id' => ($orderId !== null && is_numeric($orderId)) ? (int)$orderId : null,
        'status' => 'Pendiente',
        'client_id' => $clienteId,
    ], JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[cart_order] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
