<?php
/**
 * Vista previa de plantillas globales (super admin).
 */
declare(strict_types=1);

require __DIR__ . '/../../src/Core/bootstrap.php';

use Agenduy\Core\Auth;
use Agenduy\Core\CSRF;
use Agenduy\Core\PlatformTemplates;

header('Content-Type: application/json; charset=utf-8');

Auth::start();
if (!Auth::check() || Auth::role() !== 'super_admin') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'No autorizado']);
    exit;
}

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
if (!CSRF::validate(is_string($csrf) ? $csrf : null, 'plantillas_admin')) {
    http_response_code(419);
    echo json_encode(['ok' => false, 'error' => 'CSRF inválido']);
    exit;
}

$channel = (string)($payload['channel'] ?? '');
$templateKey = (string)($payload['template_key'] ?? '');
$subject = (string)($payload['subject'] ?? '');
$body = (string)($payload['body'] ?? '');

$catalog = PlatformTemplates::catalog();
if (!isset($catalog[$channel][$templateKey])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Plantilla inválida']);
    exit;
}

$preview = PlatformTemplates::previewDraft($channel, $templateKey, $subject, $body);
echo json_encode(['ok' => true, 'preview' => $preview], JSON_UNESCAPED_UNICODE);
