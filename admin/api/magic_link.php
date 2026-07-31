<?php
/**
 * Agendarte - API: Enviar link mágico al panel (admin/comercio)
 *
 * POST /admin/api/magic_link.php
 *   body: { email, _csrf }
 */
declare(strict_types=1);

$config = require __DIR__ . '/../../src/Core/bootstrap.php';

use Agenduy\Core\Auth;
use Agenduy\Core\CSRF;
use Agenduy\Core\MagicLink;
use Agenduy\Core\RateLimiter;
use Agenduy\Core\Security;

header('Content-Type: application/json; charset=utf-8');

Auth::start();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido']);
    exit;
}

RateLimiter::enforce('admin_magic_ip', Security::clientIp(), 3600, 15);

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$csrf = $payload['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
if (!CSRF::validate(is_string($csrf) ? $csrf : null, 'admin_login', false)) {
    http_response_code(419);
    echo json_encode(['ok' => false, 'error' => 'CSRF inválido.']);
    exit;
}

$email = strtolower(trim((string)($payload['email'] ?? '')));
if ($email !== '') {
    RateLimiter::enforce('admin_magic_email', hash('sha256', $email), 3600, 6);
}
// Registro rápido: si el email no tiene cuenta, se crea al abrir el link.
$result = MagicLink::sendAdminLogin($email, $_SERVER['REMOTE_ADDR'] ?? null, true);

if (!$result['ok']) {
    http_response_code(400);
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode($result, JSON_UNESCAPED_UNICODE);
