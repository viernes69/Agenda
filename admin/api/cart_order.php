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
use Agenduy\Core\MembershipPlan;
use Agenduy\Core\NotificationOutbox;
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

    // Verificar límite de productos según el plan del comercio
    $plan = MembershipPlan::forCommerceId((int)$commerce['id_commerce']);
    $maxProducts = $plan ? MembershipPlan::maxProducts($plan) : null;
    if ($maxProducts !== null) {
        $uniqueProductIds = [];
        foreach ($itemsRaw as $item) {
            if (!is_array($item)) continue;
            $pid = trim((string)($item['id'] ?? $item['ID_Product'] ?? ''));
            if ($pid !== '') $uniqueProductIds[$pid] = true;
        }
        $uniqueCount = count($uniqueProductIds);
        if ($uniqueCount > $maxProducts) {
            throw new InvalidArgumentException(
                'El plan de este comercio permite hasta ' . $maxProducts . ' productos distintos por pedido. ' .
                'Este pedido contiene ' . $uniqueCount . ' productos distintos. ' .
                'Mejorá la membresía para incluir más productos.'
            );
        }
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
    $orderItems = [];
    $orderTotal = 0.0;
    foreach ($qtyByProduct as $pid => $qty) {
        $product = $catalog[$pid];
        $price = round((float)($product['price'] ?? 0), 2);
        $pairs[] = '(' . $pid . ' + ' . $qty . ')';
        $orderItems[] = [
            'id' => (string)$pid,
            'name' => trim((string)($product['name'] ?? ('Producto ' . $pid))),
            'qty' => (int)$qty,
            'price' => $price,
            'subtotal' => $price * (int)$qty,
        ];
        $orderTotal += $price * (int)$qty;
    }
    $orderTotal = round($orderTotal, 2);

    $clienteNombre = trim((string)($payload['cliente_nombre'] ?? ''));
    $clienteEmail = trim((string)($payload['cliente_email'] ?? ''));
    $clienteTelefono = trim((string)($payload['cliente_telefono'] ?? ''));
    if ($clienteEmail === '' || !filter_var($clienteEmail, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Email inválido.');
    }

    $phoneDigits = preg_replace('/\D+/', '', $clienteTelefono) ?? '';
    if (strlen($phoneDigits) < 7) {
        throw new InvalidArgumentException('Telefono invalido.');
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

    $row = TenantLocalDb::insertCartOrder($slug, $record, [
        'Metodo_Pago' => 'WhatsApp',
        'Payment_Status' => 'manual',
        'Total' => number_format($orderTotal, 2, '.', ''),
    ]);
    $orderId = $row['ID_Carrito'] ?? null;

    try {
        NotificationOutbox::enqueueStoreOrderNotifications($commerce, $row, $orderItems, [
            'cliente_nombre' => $clienteNombre,
            'cliente_email' => $clienteEmail,
            'cliente_telefono' => $clienteTelefono,
            'direccion' => $address,
        ], 'created');
    } catch (Throwable $e) {
        error_log('[cart_order] outbox: ' . $e->getMessage());
    }

    try {
        $notifier = dirname(__DIR__, 2) . '/template/src/API/AdminPushNotifier.php';
        if (is_file($notifier)) {
            require_once $notifier;
            if (class_exists('AdminPushNotifier')) {
                AdminPushNotifier::notifyOrder($row);
            }
        }
    } catch (Throwable $e) {
        error_log('[cart_order] push: ' . $e->getMessage());
    }

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
