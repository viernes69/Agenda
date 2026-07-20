<?php
/**
 * Agenduy - CSRF
 * Tokens CSRF almacenados server-side en SQLite.
 * Se ligan a la sesión PHP y expiran a las N horas.
 */

declare(strict_types=1);

namespace Agenduy\Core;

final class CSRF
{
    public static function generate(string $purpose = 'form', ?int $userId = null): string
    {
        $db = Database::getInstance();
        $cfg = $db->config();
        $lifetimeMin = (int)($cfg['app']['csrf_lifetime_min'] ?? 240);

        $sid = self::currentSessionId();
        $token = bin2hex(random_bytes(32));

        $db->insert('csrf_tokens', [
            'token'      => $token,
            'id_user'    => $userId,
            'id_session' => $sid,
            'purpose'    => $purpose,
            'expires_at' => date('Y-m-d H:i:s', time() + $lifetimeMin * 60),
        ]);

        // Limpieza oportunista: tokens expirados de la misma sesión
        $db->delete('csrf_tokens', 'expires_at < :now', [':now' => date('Y-m-d H:i:s')]);

        return $token;
    }

    /**
     * Valida un token. Por defecto lo invalida tras un uso exitoso (one-time).
     * Con $consume = false el token puede reutilizarse hasta que expire,
     * necesario en formularios multi-intento como el registro público.
     */
    /**
     * Alias usado por APIs del panel comercio (purpose primero, luego token).
     */
    public static function check(string $purpose, ?string $token, bool $consume = true): bool
    {
        return self::validate($token, $purpose, $consume);
    }

    public static function validate(?string $token, string $purpose = 'form', bool $consume = true): bool
    {
        if (!$token) {
            return false;
        }
        $db = Database::getInstance();
        $sid = self::currentSessionId();
        $now = date('Y-m-d H:i:s');

        $row = $db->fetchOne(
            'SELECT id_token FROM csrf_tokens
             WHERE token = :t AND id_session = :s
               AND purpose = :p AND expires_at > :now
             LIMIT 1',
            [':t' => $token, ':s' => $sid, ':p' => $purpose, ':now' => $now]
        );

        if (!$row) {
            return false;
        }

        if ($consume) {
            $db->delete('csrf_tokens', 'id_token = :id', [':id' => $row['id_token']]);
        }
        return true;
    }

    /**
     * Helper para inyectar el input hidden en formularios.
     */
    public static function field(string $purpose = 'form'): string
    {
        $tok = self::generate($purpose, Auth::id());
        return '<input type="hidden" name="_csrf" value="' . htmlspecialchars($tok, ENT_QUOTES, 'UTF-8') . '">';
    }

    public static function checkRequest(string $purpose = 'form'): void
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return;
        }
        $token = $_POST['_csrf']
            ?? $_SERVER['HTTP_X_CSRF_TOKEN']
            ?? null;
        if (!self::validate(is_string($token) ? $token : null, $purpose)) {
            http_response_code(419);
            if (self::wantsJson()) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'error' => 'CSRF token inválido o expirado']);
            } else {
                echo 'CSRF token inválido o expirado. Volvé atrás y reintentá.';
            }
            exit;
        }
    }

    private static function currentSessionId(): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            if (PHP_SAPI === 'cli') {
                return 'cli-' . md5(php_sapi_name() . '|' . getmypid());
            }
            Auth::start();
        }
        return session_id() ?: 'no-session';
    }

    private static function wantsJson(): bool
    {
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        $xrw    = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
        return stripos($accept, 'application/json') !== false
            || strcasecmp($xrw, 'fetch') === 0
            || strcasecmp($xrw, 'xmlhttprequest') === 0;
    }
}
