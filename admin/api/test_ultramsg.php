<?php
/**
 * Agendarte - API: Probar envío WhatsApp mediante UltraMsg (super admin)
 *
 * POST /admin/api/test_ultramsg.php
 *   body: { phone, _csrf }
 */
declare(strict_types=1);

$config = require __DIR__ . '/../../src/Core/bootstrap.php';

use Agenduy\Core\Auth;
use Agenduy\Core\CSRF;
use Agenduy\Core\Database;
use Agenduy\Core\UltraMsg;
use Agenduy\Core\ProviderConfig;
use Agenduy\Core\RateLimiter;
use Agenduy\Core\Security;

header('Content-Type: application/json; charset=utf-8');

Auth::start();

if (!Auth::check() || Auth::role() !== 'super_admin') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Acceso denegado.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido.'], JSON_UNESCAPED_UNICODE);
    exit;
}

RateLimiter::enforce('admin_test_ultramsg_ip', Security::clientIp(), 3600, 10);

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$csrf = $payload['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
if (!CSRF::validate(is_string($csrf) ? $csrf : null, 'config_admin', false)) {
    http_response_code(419);
    echo json_encode(['ok' => false, 'error' => 'Token CSRF inválido o expirado. Recargá la página.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$phone = trim((string)($payload['phone'] ?? ''));
if ($phone === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Por favor ingresá un número de WhatsApp de destino.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$normalized = UltraMsg::normalizePhone($phone);
if ($normalized === '' || strlen($normalized) < 8) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'El número ingresado no es válido. Incluí código de país (ej: +598 99 123 456).'], JSON_UNESCAPED_UNICODE);
    exit;
}

$cfg = ProviderConfig::ultraMsgConfig();
if (empty($cfg['enabled'])) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => 'UltraMsg está deshabilitado en la configuración. Marcá "UltraMsg habilitado", guardá y volvé a probar.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (empty($cfg['instance_id']) || empty($cfg['token'])) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => 'Faltan credenciales de UltraMsg (Instance ID o Token). Completalos y guardá.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$testMsg = "👋 *Agendarte UY*\n\n¡Hola! Este es un mensaje de prueba exitoso enviado desde el Panel Super Admin mediante *UltraMsg* (Instancia: " . htmlspecialchars((string)$cfg['instance_id']) . ").\n\nFecha y hora: " . date('d/m/Y H:i:s');

try {
    $sent = UltraMsg::send($normalized, $testMsg);
    
    // Registrar en log de notificaciones
    try {
        Database::getInstance()->insert('notifications_log', [
            'id_commerce' => null,
            'channel' => 'whatsapp',
            'recipient' => $normalized,
            'subject' => 'Prueba UltraMsg (Super Admin)',
            'body' => $testMsg,
            'status' => 'sent',
            'error_message' => null,
            'sent_at' => date('Y-m-d H:i:s'),
        ]);
    } catch (\Throwable $e) {}

    Auth::audit('test_ultramsg', 'platform_settings', null, null, ['recipient' => $normalized]);

    echo json_encode([
        'ok' => true,
        'message' => '¡Mensaje de WhatsApp enviado con éxito al número +' . $normalized . '!',
        'recipient' => '+' . $normalized,
    ], JSON_UNESCAPED_UNICODE);
    exit;
} catch (\Throwable $e) {
    $errorMsg = $e->getMessage();
    
    // Registrar fallo en log
    try {
        Database::getInstance()->insert('notifications_log', [
            'id_commerce' => null,
            'channel' => 'whatsapp',
            'recipient' => $normalized,
            'subject' => 'Prueba UltraMsg (Super Admin)',
            'body' => $testMsg,
            'status' => 'failed',
            'error_message' => $errorMsg,
            'sent_at' => null,
        ]);
    } catch (\Throwable $ign) {}

    http_response_code(502);
    echo json_encode([
        'ok' => false,
        'error' => 'UltraMsg error: ' . $errorMsg,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
