<?php
date_default_timezone_set('America/Montevideo');
session_start();
require_once __DIR__ . '/Autoload.php';
require_once __DIR__ . '/mail_helpers.php';

use PHPMailer\PHPMailer\PHPMailer;

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$inputRaw = file_get_contents('php://input');
$input = [];
if ($inputRaw) {
    $decoded = json_decode($inputRaw, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        $input = $decoded;
    }
}

$params = array_merge($_GET, $_POST, $input);
$action = strtolower((string)($params['action'] ?? 'get'));

function json_response($data, int $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function db_find_product($id) {
    try {
        $list = AutoloadDB::all('productos');
    } catch (Throwable $e) {
        return null;
    }
    foreach ($list as $p) {
        if ((string)($p['ID_Product'] ?? '') === (string)$id) return $p;
    }
    return null;
}

function ensure_cart() {
    if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
        $_SESSION['cart'] = [ 'items' => [], 'totals' => ['quantity' => 0, 'amount' => 0.0] ];
    }
}

function recompute_totals() {
    ensure_cart();
    $qty = 0; $amount = 0.0;
    foreach ($_SESSION['cart']['items'] as $it) {
        $qty += (int)($it['cantidad'] ?? 0);
        $amount += (float)($it['subtotal'] ?? 0);
    }
    $_SESSION['cart']['totals'] = [ 'quantity' => $qty, 'amount' => $amount ];
}

function current_cliente_id() {
    if (empty($_SESSION['cliente']) || !is_array($_SESSION['cliente'])) {
        return null;
    }
    $session = $_SESSION['cliente'];
    foreach (['cliente_id', 'ID_Cliente', 'id_cliente'] as $key) {
        if (isset($session[$key]) && $session[$key] !== null && $session[$key] !== '') {
            return $session[$key];
        }
    }
    return null;
}

function pending_orders_count($clienteId) {
    if ($clienteId === null || $clienteId === '') {
        return 0;
    }
    try {
        $orders = AutoloadDB::all('carrito');
    } catch (Throwable $e) {
        return 0;
    }
    $count = 0;
    foreach ($orders as $order) {
        if ((string)($order['ID_Cliente'] ?? '') !== (string)$clienteId) {
            continue;
        }
        $status = strtolower(trim((string)($order['Status'] ?? '')));
        if ($status === 'pendiente') {
            $count++;
        }
    }
    return $count;
}

function format_money($amount): string {
    $number = (float)$amount;
    return '$ ' . number_format($number, 2, ',', '.');
}

function send_order_email(array $orderRow, array $cartSnapshot, array $cliente, string $address, bool $pickup): void {
    try {
        $infoBarberia = AutoloadDB::getConfigSection('info_barberia');
    } catch (Throwable $e) {
        error_log('[cart] No se pudo obtener info_barberia: ' . $e->getMessage());
        return;
    }

    $targetEmail = trim((string)($infoBarberia['contacto']['email'] ?? $infoBarberia['email'] ?? ''));
    if ($targetEmail === '') {
        agenduy_mail_log('[cart] No hay correo configurado en info_barberia; omitiendo notificación de pedido.');
        return;
    }

    $mailConfig = agenduy_mail_get_config();
    $host = trim((string)($mailConfig['host'] ?? ''));
    $username = trim((string)($mailConfig['username'] ?? ''));
    $password = (string)($mailConfig['password'] ?? '');
    if ($host === '' || $username === '' || $password === '') {
        agenduy_mail_log('[cart] Configuración SMTP incompleta; no se enviará el correo de pedido.');
        return;
    }
    if (!agenduy_mail_require_phpmailer()) {
        return;
    }

    $orderId = $orderRow['ID_Carrito'] ?? $orderRow['ID'] ?? '';
    $fecha = trim((string)($orderRow['Fecha'] ?? date('Y-m-d')));
    $hora = trim((string)($orderRow['Hora'] ?? date('H:i:s')));
    $estado = trim((string)($orderRow['Status'] ?? 'Pendiente'));

    $clienteNombre = trim((string)($cliente['nombre'] ?? $cliente['Nombre'] ?? 'Cliente'));
    $clienteApellido = trim((string)($cliente['apellido'] ?? $cliente['Apellido'] ?? ''));
    $clienteTelefono = trim((string)($cliente['telefono'] ?? $cliente['Telefono'] ?? ''));
    $clienteEmail = trim((string)($cliente['email'] ?? $cliente['Email'] ?? ''));
    $clienteFullName = trim($clienteNombre . ' ' . $clienteApellido) ?: $clienteNombre ?: 'Cliente';

    $itemsLines = [];
    $totalAmount = 0;
    if (isset($cartSnapshot['items']) && is_array($cartSnapshot['items'])) {
        foreach ($cartSnapshot['items'] as $item) {
            $nombre = trim((string)($item['Nombre'] ?? 'Producto'));
            $cantidad = (int)($item['cantidad'] ?? 1);
            $subtotal = (float)($item['subtotal'] ?? 0);
            $totalAmount += $subtotal;
            $itemsLines[] = sprintf('- %s x%d - %s', $nombre, $cantidad, format_money($subtotal));
        }
    }
    if (!$itemsLines && isset($orderRow['ID_Producto + Cantidad'])) {
        $itemsLines[] = (string)$orderRow['ID_Producto + Cantidad'];
    }
    if ($totalAmount <= 0 && isset($cartSnapshot['totals']['amount'])) {
        $totalAmount = (float)$cartSnapshot['totals']['amount'];
    }

    $deliveryLine = $pickup ? 'Retiro en el local' : $address;

    $bodyLines = [
        'Tienes un nuevo pedido.',
        '',
        'Pedido #' . ($orderId !== '' ? $orderId : '(sin ID)'),
        'Estado: ' . ($estado !== '' ? $estado : 'Pendiente'),
        'Fecha y hora: ' . trim($fecha . ' ' . $hora),
        '',
        'Cliente: ' . $clienteFullName,
        'Teléfono: ' . ($clienteTelefono !== '' ? $clienteTelefono : 'Sin teléfono'),
        'Correo: ' . ($clienteEmail !== '' ? $clienteEmail : 'Sin correo'),
        'Dirección: ' . ($deliveryLine !== '' ? $deliveryLine : 'Sin dirección'),
        '',
        'Productos:',
    ];
    if ($itemsLines) {
        $bodyLines = array_merge($bodyLines, $itemsLines);
    } else {
        $bodyLines[] = '- Sin detalle disponible';
    }
    $bodyLines[] = '';
    $bodyLines[] = 'Total: ' . format_money($totalAmount);

    $subject = 'Tienes un nuevo pedido';
    $fromEmail = trim((string)($mailConfig['from_email'] ?? $username));
    $fromName = trim((string)($mailConfig['from_name'] ?? 'Agenduy Notificaciones'));
    $port = (int)($mailConfig['port'] ?? 465);
    $timeout = max(5, (int)($mailConfig['timeout'] ?? 15));
    $encryption = strtolower(trim((string)($mailConfig['encryption'] ?? 'ssl')));

    $mailer = new PHPMailer(true);
    try {
        $mailer->CharSet = 'UTF-8';
        $mailer->isSMTP();
        $mailer->Host = $host;
        $mailer->SMTPAuth = true;
        $mailer->Username = $username;
        $mailer->Password = $password;
        $mailer->Port = $port;
        $mailer->Timeout = $timeout;
        if ($encryption === 'tls') {
            $mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        }

        $mailer->setFrom($fromEmail !== '' ? $fromEmail : $username, $fromName);
        $mailer->addAddress($targetEmail);

        $body = implode(PHP_EOL, $bodyLines);
        $mailer->isHTML(false);
        $mailer->Subject = $subject;
        $mailer->Body = $body;
        $mailer->AltBody = $body;

        $mailer->send();
        agenduy_mail_log(sprintf('[cart] Correo enviado a %s para pedido #%s.', $targetEmail, $orderId ?: '?'));
    } catch (Throwable $e) {
        $message = '[cart] No se pudo enviar notificación de pedido: ' . $e->getMessage();
        error_log($message);
        agenduy_mail_log($message);
    }
}

switch ($action) {
    case 'add':
        if (empty($_SESSION['cliente'])) {
            json_response(['ok' => false, 'error' => 'No autenticado'], 401);
        }
        $productId = (string)($params['product_id'] ?? '');
        $qty = (int)($params['qty'] ?? 1);
        if ($productId === '' || $qty <= 0) {
            json_response(['ok' => false, 'error' => 'Datos invalidos'], 400);
        }
        if ($qty > 10) $qty = 10;
        $product = db_find_product($productId);
        if (!$product) {
            json_response(['ok' => false, 'error' => 'Producto no encontrado'], 404);
        }
        ensure_cart();
        $price = (float)($product['Precio'] ?? 0);
        $name = (string)($product['Nombre'] ?? ('Producto ' . $productId));
        $existingQty = isset($_SESSION['cart']['items'][$productId]) ? (int)$_SESSION['cart']['items'][$productId]['cantidad'] : 0;
        $newQty = max(1, min(10, $existingQty + $qty));
        $_SESSION['cart']['items'][$productId] = [
            'ID_Product' => $productId,
            'Nombre' => $name,
            'Precio' => $price,
            'cantidad' => $newQty,
            'subtotal' => $price * $newQty,
        ];
        recompute_totals();
        $clienteId = current_cliente_id();
        json_response([
            'ok' => true,
            'cart' => $_SESSION['cart'],
            'pending_orders' => pending_orders_count($clienteId),
        ]);
        break;

    case 'clear':
        unset($_SESSION['cart']);
        ensure_cart();
        $clienteId = current_cliente_id();
        json_response([
            'ok' => true,
            'cart' => $_SESSION['cart'],
            'pending_orders' => pending_orders_count($clienteId),
        ]);
        break;

    case 'checkout':
        if (empty($_SESSION['cliente']) || !is_array($_SESSION['cliente'])) {
            json_response(['ok' => false, 'error' => 'No autenticado'], 401);
        }
        ensure_cart();
        $cart = $_SESSION['cart'];
        if (empty($cart['items']) || !is_array($cart['items'])) {
            json_response(['ok' => false, 'error' => 'Carrito vacío'], 400);
        }
        $addressRaw = trim((string)($params['address'] ?? ''));
        $pickup = filter_var($params['pickup'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($pickup) {
            $address = 'Pasa a retirar';
        } else {
            if ($addressRaw === '') {
                json_response(['ok' => false, 'error' => 'Dirección requerida'], 400);
            }
            $address = $addressRaw;
        }
        $cliente = $_SESSION['cliente'];
        $clienteId = $cliente['cliente_id'] ?? $cliente['ID_Cliente'] ?? null;
        if ($clienteId === null || $clienteId === '') {
            json_response(['ok' => false, 'error' => 'Cliente inválido en sesión'], 400);
        }
        $pairs = [];
        foreach ($cart['items'] as $productId => $item) {
            $qty = (int)($item['cantidad'] ?? 0);
            if ($qty <= 0) continue;
            $pairs[] = '(' . $productId . ' + ' . $qty . ')';
        }
        if (!$pairs) {
            json_response(['ok' => false, 'error' => 'No hay productos válidos en el carrito'], 400);
        }
        $record = [
            'ID_Cliente' => (int)$clienteId,
            'Dirección' => $address,
            'ID_Producto + Cantidad' => implode(', ', $pairs),
            'Hora' => date('H:i:s'),
            'Fecha' => date('Y-m-d'),
            'Status' => 'Pendiente',
        ];
        try {
            $row = AutoloadDB::insert('carrito', $record);
            send_order_email($row, $cart, $cliente, $address, $pickup);
        } catch (Throwable $e) {
            json_response(['ok' => false, 'error' => 'No se pudo guardar el pedido'], 500);
        }
        unset($_SESSION['cart']);
        ensure_cart();
        json_response([
            'ok' => true,
            'data' => $row,
            'cart' => $_SESSION['cart'],
            'pending_orders' => pending_orders_count($clienteId),
        ]);
        break;

    case 'get':
    default:
        ensure_cart();
        $clienteId = current_cliente_id();
        json_response([
            'ok' => true,
            'cart' => $_SESSION['cart'],
            'pending_orders' => pending_orders_count($clienteId),
        ]);
}
?>
