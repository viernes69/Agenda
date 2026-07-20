<?php
/**
 * Agendarte - API: Login con Google (GIS ID token)
 *
 * POST /admin/api/google_auth.php
 *   body: { credential | id_token, _csrf }
 */
declare(strict_types=1);

$config = require __DIR__ . '/../../src/Core/bootstrap.php';

use Agenduy\Core\Auth;
use Agenduy\Core\CSRF;
use Agenduy\Core\GoogleAuth;

header('Content-Type: application/json; charset=utf-8');

Auth::start();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido']);
    exit;
}

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

if (!GoogleAuth::isEnabled()) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'Google no está configurado.']);
    exit;
}

$token = trim((string)($payload['credential'] ?? $payload['id_token'] ?? ''));
if ($token === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Falta el token de Google.']);
    exit;
}

try {
    $profile = GoogleAuth::verifyIdToken($token);
} catch (Throwable $e) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    exit;
}

$result = Auth::loginWithGoogle($profile, $_SERVER['REMOTE_ADDR'] ?? null);
if (!$result['ok']) {
    $status = !empty($result['needs_register']) ? 404 : 401;
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

$redirect = Auth::dashboardUrl($result['user'] ?? []);
if ($redirect === null) {
    Auth::logout();
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'No hay panel disponible para esta cuenta.']);
    exit;
}

echo json_encode([
    'ok' => true,
    'user' => $result['user'],
    'redirect' => $redirect,
], JSON_UNESCAPED_UNICODE);
