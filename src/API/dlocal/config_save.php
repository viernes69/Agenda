<?php
/**
 * Agenduy - API: Guardar config dLocal del tenant
 *
 * POST /src/API/dlocal/config_save.php
 *   - slug
 *   - _csrf
 *   - api_key
 *   - secret_key  (opcional: si se omite, conserva el actual)
 *   - sandbox (0|1)
 *
 * Persiste la config del gateway en la DB del tenant (`{slug}/src/db/database.php`)
 * bajo la clave `dlocal`. NO se loguea el secret en claro.
 */
declare(strict_types=1);

use Agenduy\Core\Auth;
use Agenduy\Core\CSRF;
use Agenduy\Core\Database;
use Agenduy\Core\TenantLocalDb;

require_once __DIR__ . '/../../Core/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

if (!isset($_SERVER['REQUEST_METHOD']) || strtoupper((string)$_SERVER['REQUEST_METHOD']) !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Metodo no permitido. Usa POST.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    Auth::start();
    if (!Auth::check() || Auth::role() !== 'commerce_admin') {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'No autorizado.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $raw = (string)file_get_contents('php://input');
    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        $payload = $_POST;
    }

    $idCommerce = (int)Auth::commerceId();
    if ($idCommerce <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Cuenta sin comercio asignado.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!CSRF::check('dlocal_config', (string)($payload['_csrf'] ?? ''))) {
        http_response_code(419);
        echo json_encode(['ok' => false, 'error' => 'Sesion expirada, recarga la pagina.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $commerce = Database::getInstance()->fetchOne('SELECT slug FROM commerces WHERE id_commerce = :id', [':id' => $idCommerce]);
    if (!$commerce) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Comercio no encontrado.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $slug = (string)$commerce['slug'];

    $apiKey    = trim((string)($payload['api_key'] ?? ''));
    $secretKey = trim((string)($payload['secret_key'] ?? ''));
    $sandbox   = !empty($payload['sandbox']);

    if (!TenantLocalDb::exists($slug)) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Base local del comercio no encontrada.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Si no envian secret, conservamos el que ya esta guardado.
    if ($secretKey === '') {
        $current = TenantLocalDb::read($slug);
        $currentDlocal = is_array($current) && isset($current['dlocal']) && is_array($current['dlocal'])
            ? $current['dlocal']
            : [];
        if (isset($currentDlocal['secret_key'])) {
            $secretKey = (string)$currentDlocal['secret_key'];
        }
    }

    if ($apiKey === '' || $secretKey === '') {
        throw new InvalidArgumentException('API Key y Secret Key son obligatorios.');
    }

    $config = [
        'api_key'    => $apiKey,
        'secret_key' => $secretKey,
        'sandbox'    => $sandbox,
        'updated_at' => date('Y-m-d H:i:s'),
    ];

    TenantLocalDb::mutate($slug, function (array $db) use ($config) {
        $db['dlocal'] = $config;
        return [$db, null];
    });

    Database::getInstance()->insert('audit_log', [
        'id_user'     => (int)Auth::id(),
        'action'      => 'dlocal_config_save',
        'target_type' => 'commerce',
        'target_id'   => $idCommerce,
        'meta'        => json_encode(['sandbox' => $sandbox], JSON_UNESCAPED_UNICODE),
        'ip'          => substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64),
        'user_agent'  => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
    ]);

    echo json_encode([
        'ok'              => true,
        'message'         => 'Configuracion de dLocal guardada.',
        'sandbox'         => $sandbox,
        'api_key_preview' => substr($apiKey, 0, 4) . '…' . substr($apiKey, -4),
    ], JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[dlocal_config_save] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error interno.'], JSON_UNESCAPED_UNICODE);
}
