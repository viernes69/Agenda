<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

date_default_timezone_set('America/Montevideo');
require_once __DIR__ . '/Autoload.php';

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function client_profile_respond($payload, int $code = 200): void {
    while (ob_get_level()) {
        ob_end_clean();
    }
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function client_profile_find_by_cedula(string $cedula): ?array {
    $needle = trim($cedula);
    if ($needle === '') return null;
    try {
        $clientes = AutoloadDB::all('clientes');
    } catch (Throwable $e) {
        error_log('[client_profile] Error leyendo clientes: ' . $e->getMessage());
        return null;
    }
    foreach ($clientes as $cliente) {
        $value = trim((string)($cliente['Cedula'] ?? ''));
        if ($value !== '' && strcasecmp($value, $needle) === 0) {
            return $cliente;
        }
    }
    return null;
}

function client_profile_load(array $session): ?array {
    $cliente = null;
    $id = $session['cliente_id'] ?? $session['ID_Cliente'] ?? null;
    if ($id !== null && $id !== '') {
        try {
            $cliente = AutoloadDB::find('clientes', $id);
        } catch (Throwable $e) {
            error_log('[client_profile] Error buscando cliente por ID: ' . $e->getMessage());
        }
    }
    if (!$cliente && !empty($session['cedula'])) {
        $cliente = client_profile_find_by_cedula($session['cedula']);
    }
    if (!$cliente) {
        return null;
    }

    $perfil = $cliente['Perfil'] ?? '';
    if ($perfil !== '') {
        $perfil = str_replace('\\', '/', (string)$perfil);
    }

    $nombre = trim((string)($cliente['Nombre'] ?? ''));
    $apellido = trim((string)($cliente['Apellido'] ?? ''));
    $display = trim(($nombre . ' ' . $apellido));
    if ($display === '') {
        $display = $nombre ?: ($apellido ?: ($session['display_name'] ?? $session['nombre'] ?? 'Cliente'));
    }

    $startedAt = isset($session['session_started_at']) ? (int)$session['session_started_at'] : time();
    $expiresAt = isset($session['expires_at']) ? (int)$session['expires_at'] : ($startedAt + 24 * 60 * 60);

    $reservas = [];
    if (!empty($session['reservas']) && is_array($session['reservas'])) {
        $reservas = $session['reservas'];
    } else {
        try {
            $reservas = AutoloadDB::all('reservas');
            $reservas = array_values(array_filter($reservas, static function ($r) use ($cliente) {
                return (string)($r['ID_Cliente'] ?? '') === (string)($cliente['ID_Cliente'] ?? '');
            }));
        } catch (Throwable $e) {
            $reservas = [];
        }
    }

    return [
        'cliente_id' => isset($cliente['ID_Cliente']) ? (int)$cliente['ID_Cliente'] : null,
        'nombre' => $nombre,
        'apellido' => $apellido,
        'cedula' => trim((string)($cliente['Cedula'] ?? '')),
        'telefono' => trim((string)($cliente['Telefono'] ?? '')),
        'email' => trim((string)($cliente['Email'] ?? '')),
        'perfil' => $perfil,
        'display_name' => $display,
        'session_started_at' => $startedAt,
        'expires_at' => $expiresAt,
        'reservas' => $reservas,
    ];
}

if (!isset($_SESSION['cliente']) || !is_array($_SESSION['cliente'])) {
    client_profile_respond(['ok' => false, 'error' => 'No autenticado'], 401);
}

$profile = client_profile_load($_SESSION['cliente']);
if (!$profile) {
    client_profile_respond(['ok' => false, 'error' => 'Cliente no encontrado'], 404);
}

$_SESSION['cliente'] = array_merge($_SESSION['cliente'], $profile);

client_profile_respond(['ok' => true, 'data' => $profile]);
