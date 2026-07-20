<?php
/**
 * Agendarte - API: Probar envío SMTP (super admin)
 *
 * POST /admin/api/test_mail.php
 *   body: { email, _csrf }
 */
declare(strict_types=1);

$config = require __DIR__ . '/../../src/Core/bootstrap.php';

use Agenduy\Core\Auth;
use Agenduy\Core\CSRF;
use Agenduy\Core\Mail;
use Agenduy\Core\ProviderConfig;
use Agenduy\Core\RateLimiter;
use Agenduy\Core\Security;

header('Content-Type: application/json; charset=utf-8');

Auth::start();

if (!Auth::check() || Auth::role() !== 'super_admin') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Acceso denegado'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido'], JSON_UNESCAPED_UNICODE);
    exit;
}

RateLimiter::enforce('admin_test_mail_ip', Security::clientIp(), 3600, 10);

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$csrf = $payload['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
if (!CSRF::validate(is_string($csrf) ? $csrf : null, 'config_admin', false)) {
    http_response_code(419);
    echo json_encode(['ok' => false, 'error' => 'CSRF inválido. Recargá la página.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$email = strtolower(trim((string)($payload['email'] ?? '')));
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Email inválido.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$diag = ProviderConfig::mailDiagnostics();
if (!$diag['ok']) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => 'SMTP incompleto. Completá host, usuario, contraseña y verificá composer install.',
        'diagnostics' => $diag,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$subject = 'Prueba SMTP - Agendarte';
$body = '<p>Este es un email de prueba desde la configuración SMTP de Agendarte.</p>'
    . '<p>Si lo recibiste, el envío está funcionando correctamente.</p>';

$sent = Mail::send($email, $subject, $body);
if (!$sent) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => Mail::lastError() ?? 'No se pudo enviar el email de prueba.',
        'diagnostics' => $diag,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'ok' => true,
    'message' => 'Email de prueba enviado a ' . $email . '. Revisá bandeja y spam.',
    'diagnostics' => $diag,
], JSON_UNESCAPED_UNICODE);
