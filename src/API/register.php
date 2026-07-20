<?php
declare(strict_types=1);

require __DIR__ . '/../Core/bootstrap.php';

use Agenduy\Core\Auth;
use Agenduy\Core\CommerceRegistrar;
use Agenduy\Core\CSRF;

header('Content-Type: application/json; charset=utf-8');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        throw new RuntimeException('Método no permitido.');
    }

    Auth::start();
    $rawInput = file_get_contents('php://input');
    $payload = json_decode($rawInput !== false ? $rawInput : '', true);
    if (!is_array($payload)) {
        $payload = $_POST;
    }

    $csrf = $payload['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    // Token reutilizable (consume: false): el registro puede fallar por
    // validación y reintentarse sin que el usuario vea errores de CSRF.
    if (!CSRF::validate(is_string($csrf) ? $csrf : null, 'public_booking', false)) {
        // Sesión nueva o token vencido: emitir uno fresco para que el
        // cliente reintente de forma transparente (mismo origen; un sitio
        // externo no puede leer esta respuesta).
        // 428 y no 419: Apache reemplaza los códigos que no conoce por 500.
        http_response_code(428);
        echo json_encode([
            'ok' => false,
            'error' => 'csrf_retry',
            'csrf' => CSRF::generate('public_booking'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $result = CommerceRegistrar::register($payload);
    echo json_encode([
        'ok' => true,
        'slug' => $result['slug'],
        'redirect' => $result['redirect'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    if (http_response_code() < 400) {
        http_response_code($e instanceof InvalidArgumentException ? 400 : 500);
    }
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
