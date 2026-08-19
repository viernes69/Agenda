<?php
/**
 * Agenduy - API: Login del comercio
 *
 * POST /admin/api/commerce_auth.php
 *   body: { email, password, _csrf }
 */
declare(strict_types=1);

$config = require __DIR__ . '/../../src/Core/bootstrap.php';

use Agenduy\Core\Auth;
use Agenduy\Core\CSRF;

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido']);
    exit;
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);
if (!is_array($payload)) $payload = $_POST;

$csrf = $payload['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
if (!CSRF::validate(is_string($csrf) ? $csrf : null, 'admin_login')) {
    http_response_code(419);
    echo json_encode(['ok' => false, 'error' => 'CSRF inválido.']);
    exit;
}

$email = strtolower(trim((string)($payload['email'] ?? '')));
$pass  = (string)($payload['password'] ?? '');

$result = Auth::login($email, $pass, $_SERVER['REMOTE_ADDR'] ?? null);
if (!$result['ok']) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => $result['error']]);
    exit;
}

if (($result['user']['role'] ?? '') !== 'commerce_admin') {
    Auth::logout();
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Esta área es para administradores de comercio.']);
    exit;
}

echo json_encode([
    'ok' => true,
    'user' => $result['user'],
    'redirect' => Auth::dashboardUrl($result['user']) ?? \url('admin/commerce_panel.php'),
], JSON_UNESCAPED_UNICODE);
