<?php
/**
 * Agendarte - Autocompletar datos de cliente al reservar
 *
 * POST /src/API/client_lookup.php
 *   body: { email?, telefono?, slug | id_commerce, _csrf }
 */
declare(strict_types=1);

require __DIR__ . '/../Core/bootstrap.php';

use Agenduy\Core\ClientLookup;
use Agenduy\Core\CSRF;
use Agenduy\Core\Database;
use Agenduy\Core\RateLimiter;
use Agenduy\Core\Security;

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido']);
    exit;
}

RateLimiter::enforce('client_lookup_ip', Security::clientIp(), 60, 40);

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$csrf = $payload['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
if (!CSRF::validate(is_string($csrf) ? $csrf : null, 'public_booking')) {
    http_response_code(419);
    echo json_encode(['ok' => false, 'error' => 'CSRF inválido.']);
    exit;
}

$slug = trim((string)($payload['slug'] ?? ''));
$idCommerce = (int)($payload['id_commerce'] ?? 0);
$db = Database::getInstance();

if ($idCommerce <= 0 && $slug !== '') {
    $row = $db->fetchOne('SELECT id_commerce FROM commerces WHERE slug = :s LIMIT 1', [':s' => $slug]);
    $idCommerce = (int)($row['id_commerce'] ?? 0);
}

if ($idCommerce <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Comercio inválido.']);
    exit;
}

$result = ClientLookup::lookup(
    $idCommerce,
    isset($payload['email']) ? (string)$payload['email'] : null,
    isset($payload['telefono']) ? (string)$payload['telefono'] : null
);

echo json_encode($result, JSON_UNESCAPED_UNICODE);
