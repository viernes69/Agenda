<?php
declare(strict_types=1);

require_once __DIR__ . '/AdminPushStorage.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Metodo no permitido']);
    exit;
}

$input = file_get_contents('php://input');
$payload = json_decode($input, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Solicitud invalida']);
    exit;
}

$action = strtolower((string)($payload['action'] ?? ''));
try {
    switch ($action) {
        case 'subscribe':
            handleSubscribe($payload);
            break;
        case 'unsubscribe':
            handleUnsubscribe($payload);
            break;
        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Accion no soportada']);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}

function handleSubscribe(array $payload): void
{
    $subscription = $payload['subscription'] ?? null;
    if (!is_array($subscription) || empty($subscription['endpoint'])) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Suscripcion invalida.']);
        return;
    }
    $record = AdminPushStorage::save([
        'endpoint' => $subscription['endpoint'],
        'keys' => $subscription['keys'] ?? [],
        'encoding' => $subscription['encoding'] ?? null,
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
    ]);
    echo json_encode(['ok' => true, 'id' => $record['id']]);
}

function handleUnsubscribe(array $payload): void
{
    $endpoint = isset($payload['endpoint']) ? trim((string)$payload['endpoint']) : '';
    if ($endpoint === '') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Endpoint requerido.']);
        return;
    }
    AdminPushStorage::removeByEndpoint($endpoint);
    echo json_encode(['ok' => true]);
}
