<?php
/**
 * Agenduy - API: crear checkout Mercado Pago para pedidos de tienda.
 *
 * POST /admin/api/cart_mercadopago.php
 *   body JSON/form: { slug, items:[{id, qty}], cliente_nombre?, cliente_email?,
 *                     cliente_telefono?, address?, note?, _csrf }
 */
declare(strict_types=1);

$config = require __DIR__ . '/../../src/Core/bootstrap.php';

use Agenduy\Core\CommerceRegistrar;
use Agenduy\Core\CommerceSettings;
use Agenduy\Core\CSRF;
use Agenduy\Core\Database;
use Agenduy\Core\MembershipPlan;
use Agenduy\Core\MercadoPago;
use Agenduy\Core\TenantLocalDb;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Metodo no permitido']);
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

$db = Database::getInstance();
$orderId = 0;
$paymentRowId = 0;
$slug = '';

try {
    $slug = trim((string)($payload['slug'] ?? ''));
    if ($slug === '') {
        throw new InvalidArgumentException('Falta slug del comercio.');
    }

    $commerce = $db->fetchOne(
        'SELECT c.*, r.tipo AS rubro_tipo, r.nombre AS rubro_nombre
         FROM commerces c
         LEFT JOIN rubros r ON r.id_rubro = c.id_rubro
         WHERE c.slug = :s',
        [':s' => $slug]
    );
    if (!$commerce) {
        throw new RuntimeException('Comercio no encontrado.');
    }
    if (in_array((string)($commerce['status'] ?? ''), ['cancelled', 'suspended'], true)) {
        throw new RuntimeException('Este comercio no esta aceptando pedidos.');
    }
    if (!TenantLocalDb::exists($slug)) {
        throw new RuntimeException('Base local del comercio no disponible.');
    }

    $localDb = TenantLocalDb::read($slug);
    $legacyInfo = is_array($localDb['info_barberia'] ?? null) ? $localDb['info_barberia'] : [];
    $commerceId = (int)$commerce['id_commerce'];
    $features = CommerceSettings::get(
        $commerceId,
        'funciones',
        is_array($legacyInfo['features'] ?? null)
            ? $legacyInfo['features']
            : CommerceSettings::defaultsForSection('funciones')
    );
    $businessType = CommerceRegistrar::businessTypeFromFeatures(
        $features,
        (string)($commerce['rubro_tipo'] ?? ''),
        (string)($commerce['rubro_nombre'] ?? '')
    );
    if ($businessType !== 'tienda') {
        throw new RuntimeException('Mercado Pago para carrito esta disponible solo en tiendas.');
    }

    $plan = MembershipPlan::forCommerceId($commerceId);
    if (!MercadoPago::isStoreCheckoutAllowed($plan)) {
        throw new RuntimeException('Mercado Pago para tiendas esta disponible desde el plan Intermedio/Pro.');
    }

    $mp = MercadoPago::commerceConfig($commerceId, $slug);
    if (empty($mp['enabled']) || trim((string)($mp['access_token'] ?? '')) === '') {
        throw new RuntimeException('La tienda no tiene Mercado Pago configurado.');
    }

    $itemsRaw = $payload['items'] ?? [];
    if (!is_array($itemsRaw) || $itemsRaw === []) {
        throw new InvalidArgumentException('El pedido no tiene productos.');
    }

    $maxProducts = $plan ? MembershipPlan::maxProducts($plan) : null;
    if ($maxProducts !== null) {
        $uniqueProductIds = [];
        foreach ($itemsRaw as $item) {
            if (!is_array($item)) {
                continue;
            }
            $pid = trim((string)($item['id'] ?? $item['ID_Product'] ?? ''));
            if ($pid !== '') {
                $uniqueProductIds[$pid] = true;
            }
        }
        if (count($uniqueProductIds) > $maxProducts) {
            throw new InvalidArgumentException('El plan de este comercio permite hasta ' . $maxProducts . ' productos distintos por pedido.');
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
            throw new InvalidArgumentException('Producto no valido en el catalogo.');
        }
        $qty = min(20, $qty);
        $qtyByProduct[$pid] = min(20, ($qtyByProduct[$pid] ?? 0) + $qty);
    }
    if ($qtyByProduct === []) {
        throw new InvalidArgumentException('No hay productos validos en el pedido.');
    }

    $currency = strtoupper(trim((string)($mp['currency'] ?? 'UYU'))) ?: 'UYU';
    $mpItems = [];
    $pairs = [];
    $total = 0.0;
    foreach ($qtyByProduct as $pid => $qty) {
        $product = $catalog[$pid];
        $price = round((float)($product['price'] ?? 0), 2);
        if ($price <= 0) {
            throw new InvalidArgumentException('El producto "' . (string)($product['name'] ?? $pid) . '" no tiene precio valido para cobrar online.');
        }
        $name = trim((string)($product['name'] ?? ('Producto ' . $pid)));
        if ($name === '') {
            $name = 'Producto ' . $pid;
        }
        $mpItems[] = [
            'id' => (string)$pid,
            'title' => mb_substr($name, 0, 120, 'UTF-8'),
            'quantity' => (int)$qty,
            'currency_id' => $currency,
            'unit_price' => $price,
        ];
        $pairs[] = '(' . $pid . ' + ' . (int)$qty . ')';
        $total += $price * (int)$qty;
    }
    $total = round($total, 2);
    if ($total <= 0) {
        throw new InvalidArgumentException('El total del pedido debe ser mayor a cero.');
    }

    $clienteNombre = trim((string)($payload['cliente_nombre'] ?? ''));
    $clienteEmail = strtolower(trim((string)($payload['cliente_email'] ?? '')));
    $clienteTelefono = trim((string)($payload['cliente_telefono'] ?? ''));
    if ($clienteEmail !== '' && !filter_var($clienteEmail, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Email invalido.');
    }

    $address = trim((string)($payload['address'] ?? ''));
    if ($address === '') {
        $address = 'Coordinar entrega o retiro';
    }
    $note = trim((string)($payload['note'] ?? ''));
    $extraBits = [];
    if ($note !== '') {
        $extraBits[] = $note;
    }
    if ($clienteNombre !== '') {
        $extraBits[] = $clienteNombre;
    }
    if ($clienteTelefono !== '') {
        $extraBits[] = $clienteTelefono;
    }
    if ($extraBits !== []) {
        $address .= ' - ' . implode(' - ', $extraBits);
        if (mb_strlen($address, 'UTF-8') > 240) {
            $address = mb_substr($address, 0, 237, 'UTF-8') . '...';
        }
    }

    $clienteId = TenantLocalDb::findOrCreateCliente($slug, $clienteNombre, $clienteTelefono, $clienteEmail);
    $record = [
        'ID_Cliente' => $clienteId,
        'Direccion' => $address,
        'ID_Producto + Cantidad' => implode(', ', $pairs),
        'Hora' => date('H:i:s'),
        'Fecha' => date('Y-m-d'),
        'Status' => 'Pago pendiente',
    ];

    $row = TenantLocalDb::insertCartOrder($slug, $record, [
        'Metodo_Pago' => 'Mercado Pago',
        'Payment_Status' => 'created',
        'Total' => number_format($total, 2, '.', ''),
    ]);
    $orderId = cartOrderId($row);
    if ($orderId <= 0) {
        throw new RuntimeException('No se pudo identificar el pedido local.');
    }

    $externalReference = sprintf('agenduy_store_c%d_o%d_%s', $commerceId, $orderId, bin2hex(random_bytes(6)));
    TenantLocalDb::updateCartOrder($slug, $orderId, [
        'MP_External_Reference' => $externalReference,
    ]);

    $itemsJson = json_encode($mpItems, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
    $paymentRowId = $db->insert('store_order_payments', [
        'id_commerce' => $commerceId,
        'slug' => $slug,
        'local_order_id' => $orderId,
        'external_reference' => $externalReference,
        'status' => 'created',
        'amount' => $total,
        'currency' => $currency,
        'payer_email' => $clienteEmail,
        'items_json' => $itemsJson,
    ]);

    $storeUrl = url($slug . '/');
    $successUrl = preferredReturnUrl($mp, 'success_url', appendQuery($storeUrl, ['mp_order' => $orderId, 'mp_status' => 'success']));
    $failureUrl = preferredReturnUrl($mp, 'failure_url', appendQuery($storeUrl, ['mp_order' => $orderId, 'mp_status' => 'failure']));
    $pendingUrl = preferredReturnUrl($mp, 'pending_url', appendQuery($storeUrl, ['mp_order' => $orderId, 'mp_status' => 'pending']));
    $notificationUrl = appendQuery(url('admin/api/webhook_mercadopago.php'), ['store_slug' => $slug]);

    $preferencePayload = [
        'items' => $mpItems,
        'external_reference' => $externalReference,
        'notification_url' => $notificationUrl,
        'back_urls' => [
            'success' => $successUrl,
            'failure' => $failureUrl,
            'pending' => $pendingUrl,
        ],
        'auto_return' => 'approved',
        'metadata' => [
            'kind' => 'store_order',
            'slug' => $slug,
            'id_commerce' => $commerceId,
            'local_order_id' => $orderId,
        ],
    ];
    $descriptor = statementDescriptor((string)($mp['statement_descriptor'] ?? $commerce['nombre'] ?? ''));
    if ($descriptor !== '') {
        $preferencePayload['statement_descriptor'] = $descriptor;
    }
    $payer = preferencePayer($clienteNombre, $clienteEmail, $clienteTelefono);
    if ($payer !== []) {
        $preferencePayload['payer'] = $payer;
    }

    try {
        $preference = MercadoPago::createPreference($mp, $preferencePayload);
        $preferenceId = trim((string)($preference['id'] ?? ''));
        $checkoutUrl = checkoutUrlFromPreference($preference, !empty($mp['sandbox']));
        if ($preferenceId === '' || $checkoutUrl === '') {
            throw new RuntimeException('Mercado Pago no devolvio una URL de checkout.');
        }

        $db->update('store_order_payments', [
            'preference_id' => $preferenceId,
            'status' => 'pending',
            'checkout_url' => $checkoutUrl,
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id_store_payment = :id', [':id' => $paymentRowId]);
        TenantLocalDb::updateCartOrder($slug, $orderId, [
            'Status' => 'Pago pendiente',
            'Payment_Status' => 'pending',
            'MP_Preference_ID' => $preferenceId,
            'MP_External_Reference' => $externalReference,
            'Total' => number_format($total, 2, '.', ''),
        ]);
    } catch (Throwable $mpError) {
        markStorePaymentRejected($db, $slug, $orderId, $paymentRowId, $mpError->getMessage());
        throw $mpError;
    }

    echo json_encode([
        'ok' => true,
        'order_id' => $orderId,
        'status' => 'pending',
        'preference_id' => $preferenceId,
        'checkout_url' => $checkoutUrl,
        'init_point' => $preference['init_point'] ?? null,
        'sandbox_init_point' => $preference['sandbox_init_point'] ?? null,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

/**
 * @param array<string,mixed> $row
 */
function cartOrderId(array $row): int
{
    if (isset($row['ID_Carrito']) && is_numeric($row['ID_Carrito'])) {
        return (int)$row['ID_Carrito'];
    }
    foreach ($row as $key => $value) {
        if (strpos((string)$key, 'ID_') === 0 && is_numeric($value)) {
            return (int)$value;
        }
    }
    return 0;
}

/**
 * @param array<string,mixed> $mp
 */
function preferredReturnUrl(array $mp, string $key, string $fallback): string
{
    $candidate = trim((string)($mp[$key] ?? ''));
    if ($candidate !== ''
        && filter_var($candidate, FILTER_VALIDATE_URL)
        && stripos($candidate, '/template/') === false
    ) {
        return $candidate;
    }
    return $fallback;
}

/**
 * @param array<string,string|int> $params
 */
function appendQuery(string $url, array $params): string
{
    $separator = str_contains($url, '?') ? '&' : '?';
    return $url . $separator . http_build_query($params);
}

function statementDescriptor(string $value): string
{
    $value = strtoupper(trim($value));
    $value = preg_replace('/[^A-Z0-9 ]+/', '', $value) ?? '';
    $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');
    return mb_substr($value, 0, 22, 'UTF-8');
}

/**
 * @return array<string,mixed>
 */
function preferencePayer(string $name, string $email, string $phone): array
{
    $payer = [];
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $payer['email'] = $email;
    }
    if ($name !== '') {
        $payer['name'] = mb_substr($name, 0, 80, 'UTF-8');
    }
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    if (strlen($digits) >= 8) {
        $payer['phone'] = ['number' => $digits];
    }
    return $payer;
}

function checkoutUrlFromPreference(array $preference, bool $sandbox): string
{
    if ($sandbox && !empty($preference['sandbox_init_point'])) {
        return (string)$preference['sandbox_init_point'];
    }
    return trim((string)($preference['init_point'] ?? $preference['sandbox_init_point'] ?? ''));
}

function markStorePaymentRejected(Database $db, string $slug, int $orderId, int $paymentRowId, string $reason): void
{
    if ($paymentRowId > 0) {
        $db->update('store_order_payments', [
            'status' => 'rejected',
            'status_detail' => mb_substr($reason, 0, 250, 'UTF-8'),
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id_store_payment = :id', [':id' => $paymentRowId]);
    }
    if ($slug !== '' && $orderId > 0 && TenantLocalDb::exists($slug)) {
        TenantLocalDb::updateCartOrder($slug, $orderId, [
            'Status' => 'Pago rechazado',
            'Payment_Status' => 'rejected',
            'MP_Status_Detail' => mb_substr($reason, 0, 250, 'UTF-8'),
        ]);
    }
}
