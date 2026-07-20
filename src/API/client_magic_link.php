<?php
/**
 * Agendarte - Enviar link mágico a clientes de un comercio
 *
 * POST /src/API/client_magic_link.php
 *   body: { email, slug | id_commerce, _csrf }
 */
declare(strict_types=1);

require __DIR__ . '/../Core/bootstrap.php';

use Agenduy\Core\CSRF;
use Agenduy\Core\Database;
use Agenduy\Core\MagicLink;
use Agenduy\Core\RateLimiter;
use Agenduy\Core\Security;

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido']);
    exit;
}

RateLimiter::enforce('client_magic_ip', Security::clientIp(), 3600, 20);

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

$email = strtolower(trim((string)($payload['email'] ?? '')));
$slug = trim((string)($payload['slug'] ?? ''));
$idCommerce = (int)($payload['id_commerce'] ?? 0);

if ($email !== '') {
    RateLimiter::enforce('client_magic_email', hash('sha256', $email), 3600, 8);
}

$db = Database::getInstance();
if ($idCommerce <= 0 && $slug !== '') {
    $row = $db->fetchOne('SELECT id_commerce, slug FROM commerces WHERE slug = :s LIMIT 1', [':s' => $slug]);
    if ($row) {
        $idCommerce = (int)$row['id_commerce'];
        $slug = (string)$row['slug'];
    }
}

if ($idCommerce <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Comercio inválido.']);
    exit;
}

$result = MagicLink::sendClientPortal($email, $idCommerce, $slug !== '' ? $slug : null, $_SERVER['REMOTE_ADDR'] ?? null);

if (!$result['ok']) {
    http_response_code(400);
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode($result, JSON_UNESCAPED_UNICODE);
