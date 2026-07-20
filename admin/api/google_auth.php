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
use Agenduy\Core\CommerceRegistrar;
use Agenduy\Core\Database;
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
if (!$result['ok'] && !empty($result['needs_register'])) {
    try {
        $reg = CommerceRegistrar::registerWithGoogleProfile($profile);
        $user = Database::getInstance()->fetchOne(
            'SELECT * FROM users WHERE email = :e AND activo = 1 LIMIT 1',
            [':e' => strtolower((string)$profile['email'])]
        );
        if (!$user) {
            throw new RuntimeException('No se pudo crear la cuenta.');
        }
        $login = Auth::establishSessionFromRow($user, $_SERVER['REMOTE_ADDR'] ?? null, 'google_register');
        $redirect = Auth::dashboardUrl($login['user'] ?? []) ?? (string)($reg['redirect'] ?? url('/'));
        echo json_encode([
            'ok'         => true,
            'registered' => true,
            'slug'       => $reg['slug'] ?? '',
            'user'       => $login['user'] ?? [],
            'redirect'   => $redirect,
            'message'    => 'Cuenta creada. Completá los datos de tu negocio en el panel.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    } catch (InvalidArgumentException $e) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit;
    } catch (Throwable $e) {
        error_log('[google_auth] auto register: ' . $e->getMessage());
        http_response_code(422);
        $msg = trim($e->getMessage());
        echo json_encode([
            'ok' => false,
            'error' => $msg !== '' ? $msg : 'No se pudo crear la cuenta con Google. Intentá el registro manual.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
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
