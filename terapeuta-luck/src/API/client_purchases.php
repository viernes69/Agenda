<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

date_default_timezone_set('America/Montevideo');
require_once __DIR__ . '/Autoload.php';

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function purchases_respond($payload, int $code = 200): void {
    while (ob_get_level()) {
        ob_end_clean();
    }
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function purchases_parse_pairs(string $text): array {
    $items = [];
    $pattern = '/\(\s*(\d+)\s*\+\s*(\d+)\s*\)/';
    if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $items[] = [
                'product_id' => (int)$match[1],
                'quantity' => (int)$match[2],
            ];
        }
    }
    return $items;
}

if (!isset($_SESSION['cliente']) || !is_array($_SESSION['cliente'])) {
    purchases_respond(['ok' => false, 'error' => 'No autenticado'], 401);
}

$session = $_SESSION['cliente'];
$clienteId = $session['cliente_id'] ?? $session['ID_Cliente'] ?? null;
if ($clienteId === null || $clienteId === '') {
    purchases_respond(['ok' => true, 'data' => []]);
}

try {
    $carritos = AutoloadDB::all('carrito');
    $productos = AutoloadDB::all('productos');
} catch (Throwable $e) {
    purchases_respond(['ok' => false, 'error' => 'No se pudo leer los datos'], 500);
}

$productMap = [];
foreach ($productos as $producto) {
    $id = $producto['ID_Product'] ?? null;
    if ($id === null) continue;
    $productMap[(int)$id] = [
        'nombre' => (string)($producto['Nombre'] ?? 'Producto'),
        'precio' => isset($producto['Precio']) ? (float)$producto['Precio'] : null,
    ];
}

$result = [];
foreach ($carritos as $carrito) {
    if ((string)($carrito['ID_Cliente'] ?? '') !== (string)$clienteId) {
        continue;
    }
    $itemsRaw = purchases_parse_pairs((string)($carrito['ID_Producto + Cantidad'] ?? ''));
    $items = [];
    foreach ($itemsRaw as $item) {
        $info = $productMap[$item['product_id']] ?? null;
        $items[] = [
            'product_id' => $item['product_id'],
            'quantity' => $item['quantity'],
            'nombre' => $info['nombre'] ?? 'Producto',
            'precio' => $info['precio'],
        ];
    }
    $result[] = [
        'id_carrito' => isset($carrito['ID_Carrito']) ? (int)$carrito['ID_Carrito'] : null,
        'fecha' => (string)($carrito['Fecha'] ?? ''),
        'hora' => (string)($carrito['Hora'] ?? ''),
        'status' => (string)($carrito['Status'] ?? ''),
        'direccion' => (string)($carrito['Dirección'] ?? $carrito['Direccion'] ?? ''),
        'items' => $items,
    ];
}

usort($result, static function ($a, $b) {
    $dateA = strtotime(($a['fecha'] ?? '') . ' ' . ($a['hora'] ?? ''));
    $dateB = strtotime(($b['fecha'] ?? '') . ' ' . ($b['hora'] ?? ''));
    return $dateB <=> $dateA;
});

purchases_respond(['ok' => true, 'data' => $result]);
