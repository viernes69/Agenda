<?php
/**
 * Mock server de dLocal Go para tests.
 *
 * Endpoints soportados:
 *   POST   /v1/subscription/plan        -> crea un plan, devuelve plan_token + subscribe_url
 *   GET    /v1/subscription/plan        -> lista vacia
 *   GET    /v1/subscription/plan/{id}  -> devuelve un plan
 *   PATCH  /v1/subscription/plan/{planId}/subscription/{subId}/deactivate
 *   GET    /v1/payments/{id}           -> devuelve un pago
 *
 * Variables de entorno (seteadas por el test):
 *   MOCK_API_KEY, MOCK_SECRET_KEY
 *   MOCK_NEXT_PAYMENT_ID
 *   MOCK_NEXT_PLAN_TOKEN
 *
 * Iniciar: php -S 127.0.0.1:8888 tests/dlocal_mock.php
 */
declare(strict_types=1);

$expectedApi = getenv('MOCK_API_KEY') ?: 'mock_api_key';
$expectedSec = getenv('MOCK_SECRET_KEY') ?: 'mock_secret_key';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$expected = 'Bearer ' . $expectedApi . ':' . $expectedSec;

if ($authHeader !== $expected) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['code' => 3001, 'message' => 'Invalid Credentials.']);
    exit;
}

header('Content-Type: application/json');

if ($method === 'POST' && $path === '/v1/subscription/plan') {
    $body = json_decode((string)file_get_contents('php://input'), true);
    $planId = random_int(1000, 999999);
    $token = 'mockplan_' . bin2hex(random_bytes(8));
    $checkoutBase = getenv('MOCK_CHECKOUT_BASE') ?: 'http://127.0.0.1:8888/checkout';
    echo json_encode([
        'id' => $planId,
        'name' => $body['name'] ?? 'Mock Plan',
        'description' => $body['description'] ?? '',
        'country' => $body['country'] ?? 'UY',
        'currency' => $body['currency'] ?? 'UYU',
        'amount' => (float)($body['amount'] ?? 0),
        'frequency_type' => $body['frequency_type'] ?? 'MONTHLY',
        'frequency_value' => (int)($body['frequency_value'] ?? 1),
        'active' => true,
        'plan_token' => $token,
        'subscribe_url' => $checkoutBase . '/validate/subscription/' . $token,
        'created_at' => date('c'),
    ]);
    exit;
}

if ($method === 'GET' && $path === '/v1/subscription/plan') {
    echo json_encode(['plans' => []]);
    exit;
}

if ($method === 'GET' && preg_match('#^/v1/subscription/plan/(\d+)$#', $path, $m)) {
    echo json_encode([
        'id' => (int)$m[1],
        'plan_token' => 'mockplan_existing',
        'name' => 'Plan existente',
        'active' => true,
    ]);
    exit;
}

if ($method === 'PATCH' && preg_match('#^/v1/subscription/plan/(\d+)/subscription/(\d+)/deactivate$#', $path, $m)) {
    echo json_encode([
        'id' => (int)$m[2],
        'active' => false,
        'status' => 'CANCELLED',
    ]);
    exit;
}

if ($method === 'GET' && preg_match('#^/v1/payments/(.+)$#', $path, $m)) {
    echo json_encode([
        'id' => $m[1],
        'amount' => 1500.00,
        'currency' => 'UYU',
        'status' => 'PAID',
        'approved_date' => date('c'),
        'order_id' => $_GET['order_id'] ?? '',
    ]);
    exit;
}

http_response_code(404);
echo json_encode(['code' => 404, 'message' => 'Not found']);
