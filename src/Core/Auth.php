<?php
/**
 * Agenduy - Auth
 * Maneja login, logout, sesión, roles y protección por CSRF.
 *   role: 'super_admin'   → acceso a /admin/
 *   role: 'commerce_admin'→ solo su comercio
 */

declare(strict_types=1);

namespace Agenduy\Core;

use RuntimeException;

final class Auth
{
    public const ROLE_SUPER  = 'super_admin';
    public const ROLE_LOCAL  = 'commerce_admin';

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $db = Database::getInstance();
        $cfg = $db->config();
        $app = $cfg['app'];
        $sec = $cfg['security'];

        $secureCookie = (bool)$sec['session_secure_cookie'];
        if (!$secureCookie && Security::isHttpsRequest()) {
            $secureCookie = true;
        }

        session_name($app['session_name']);
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => $secureCookie,
            'httponly' => (bool)$sec['session_httponly'],
            'samesite' => $sec['session_samesite'] ?? 'Lax',
        ]);
        session_start();
    }

    public static function login(string $email, string $password, ?string $ip = null): array
    {
        $db = Database::getInstance();
        $cfg = $db->config();
        $appCfg = $cfg['app'];
        $sec = $cfg['security'];

        $email = strtolower(trim($email));
        if (!RateLimiter::attempt('login_ip', Security::clientIp(), 900, 30)) {
            return ['ok' => false, 'error' => 'Demasiados intentos. Intentá más tarde.'];
        }

        $row = $db->fetchOne(
            'SELECT * FROM users WHERE email = :e LIMIT 1',
            [':e' => $email]
        );
        if (!$row) {
            self::logFailure('login_failed_unknown_email', null, $email, $ip);
            return ['ok' => false, 'error' => 'Credenciales inválidas.'];
        }
        if ((int)$row['activo'] !== 1) {
            return ['ok' => false, 'error' => 'Cuenta deshabilitada.'];
        }
        if (!empty($row['locked_until']) && strtotime((string)$row['locked_until']) > time()) {
            return ['ok' => false, 'error' => 'Cuenta bloqueada. Intentá más tarde.'];
        }
        if (!password_verify($password, (string)$row['password_hash'])) {
            $attempts = (int)$row['failed_attempts'] + 1;
            $locked = null;
            if ($attempts >= (int)$sec['login_max_attempts']) {
                $locked = date('Y-m-d H:i:s', time() + (int)$sec['login_lockout_minutes'] * 60);
            }
            $db->update('users', [
                'failed_attempts' => $attempts,
                'locked_until'    => $locked,
            ], 'id_user = :id', [':id' => $row['id_user']]);
            self::logFailure('login_failed_password', (int)$row['id_user'], $email, $ip);
            return ['ok' => false, 'error' => 'Credenciales inválidas.'];
        }

        return self::establishSessionFromRow($row, $ip);
    }

    /**
     * Inicia sesión con Google (usuarios existentes).
     *
     * @param array<string,mixed> $googleProfile Resultado de GoogleAuth::verifyIdToken()
     */
    public static function loginWithGoogle(array $googleProfile, ?string $ip = null): array
    {
        $email = strtolower(trim((string)($googleProfile['email'] ?? '')));
        $googleId = trim((string)($googleProfile['sub'] ?? ''));
        if ($email === '' || $googleId === '') {
            return ['ok' => false, 'error' => 'Perfil de Google incompleto.'];
        }

        $db = Database::getInstance();
        $row = $db->fetchOne(
            'SELECT * FROM users WHERE google_id = :g OR email = :e LIMIT 1',
            [':g' => $googleId, ':e' => $email]
        );
        if (!$row) {
            return [
                'ok' => false,
                'needs_register' => true,
                'profile' => [
                    'email' => $email,
                    'nombre' => (string)($googleProfile['given_name'] ?? ''),
                    'apellido' => (string)($googleProfile['family_name'] ?? ''),
                    'google_id' => $googleId,
                ],
                'error' => 'No hay cuenta con ese Google. Registrate primero.',
            ];
        }
        if ((int)$row['activo'] !== 1) {
            return ['ok' => false, 'error' => 'Cuenta deshabilitada.'];
        }

        if (trim((string)($row['google_id'] ?? '')) === '') {
            $db->update('users', [
                'google_id'     => $googleId,
                'auth_provider' => 'google',
                'updated_at'    => date('Y-m-d H:i:s'),
            ], 'id_user = :id', [':id' => (int)$row['id_user']]);
            $row['google_id'] = $googleId;
        }

        return self::establishSessionFromRow($row, $ip, 'google_login');
    }

    /**
     * @param array<string,mixed> $row Fila completa de users
     */
    public static function establishSessionFromRow(array $row, ?string $ip = null, string $auditAction = 'login'): array
    {
        $db = Database::getInstance();
        $db->update('users', [
            'failed_attempts' => 0,
            'locked_until'    => null,
            'last_login_at'   => date('Y-m-d H:i:s'),
            'last_login_ip'   => $ip ?? '',
        ], 'id_user = :id', [':id' => $row['id_user']]);

        self::start();
        session_regenerate_id(true);
        $_SESSION['user'] = [
            'id'           => (int)$row['id_user'],
            'role'         => (string)$row['role'],
            'id_commerce'  => $row['id_commerce'] !== null ? (int)$row['id_commerce'] : null,
            'nombre'       => (string)$row['nombre'],
            'apellido'     => (string)$row['apellido'],
            'email'        => (string)$row['email'],
            'login_at'     => time(),
        ];
        if ((string)$row['role'] === self::ROLE_LOCAL) {
            $_SESSION['user']['Rol'] = 'Admin';
            $_SESSION['user']['ID_Barber'] = (int)$row['id_user'];
            $_SESSION['user']['Nombre'] = (string)$row['nombre'];
            $_SESSION['user']['Apellido'] = (string)$row['apellido'];
        }
        self::audit($auditAction, 'user', (int)$row['id_user'], $ip);
        return ['ok' => true, 'user' => $_SESSION['user']];
    }

    public static function dashboardUrl(array $user): ?string
    {
        $role = (string)($user['role'] ?? '');
        if ($role === self::ROLE_SUPER) {
            return \url('admin/index.php');
        }
        if ($role !== self::ROLE_LOCAL) {
            return null;
        }

        $commerceId = (int)($user['id_commerce'] ?? 0);
        if ($commerceId <= 0) {
            return null;
        }

        $commerce = Database::getInstance()->fetchOne(
            'SELECT * FROM commerces WHERE id_commerce = :id LIMIT 1',
            [':id' => $commerceId]
        );
        if (!$commerce) {
            return null;
        }
        if (CommerceSetup::needsOnboarding($commerce)) {
            return \url('admin/commerce_setup.php');
        }

        $slug = trim((string)($commerce['slug'] ?? ''));
        if ($slug === '' || !preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug)) {
            return null;
        }

        $panelUrl = CommercePanel::urlForSlug($slug);
        if ($panelUrl !== '') {
            return $panelUrl;
        }

        return CommerceRegistrar::buildCentralDashboardUrl();
    }

    public static function logout(): void
    {
        self::start();
        $uid = self::id();
        if ($uid !== null) {
            self::audit('logout', 'user', $uid);
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }

    public static function check(): bool
    {
        self::start();
        if (empty($_SESSION['user']['id'])) {
            return false;
        }
        // Re-chequear que la cuenta siga activa
        $db = Database::getInstance();
        $row = $db->fetchOne(
            'SELECT activo FROM users WHERE id_user = :id',
            [':id' => $_SESSION['user']['id']]
        );
        if (!$row || (int)$row['activo'] !== 1) {
            self::logout();
            return false;
        }
        return true;
    }

    public static function id(): ?int
    {
        self::start();
        return isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null;
    }

    public static function role(): ?string
    {
        self::start();
        return $_SESSION['user']['role'] ?? null;
    }

    public static function commerceId(): ?int
    {
        self::start();
        return isset($_SESSION['user']['id_commerce']) ? (int)$_SESSION['user']['id_commerce'] : null;
    }

    public static function user(): ?array
    {
        self::start();
        return $_SESSION['user'] ?? null;
    }

    public static function requireRole(string $role): void
    {
        if (!self::check() || self::role() !== $role) {
            http_response_code(403);
            echo 'Acceso denegado.';
            exit;
        }
    }

    public static function requireLogin(): void
    {
        if (!self::check()) {
            $login = self::loginUrl();
            header('Location: ' . $login);
            exit;
        }
    }

    public static function loginUrl(): string
    {
        // Si el script que llama vive bajo /admin/, login es /admin/login.php
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        if (strpos($script, '/admin/') !== false) {
            return '/admin/login.php';
        }
        return '/agenduy.uy/admin/login.php';
    }

    public static function hash(string $plain): string
    {
        return password_hash($plain, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    public static function audit(string $action, string $targetType = '', ?int $targetId = null, ?string $ip = null, array $meta = []): void
    {
        $db = Database::getInstance();
        $db->insert('audit_log', [
            'id_user'     => self::id(),
            'action'      => $action,
            'target_type' => $targetType,
            'target_id'   => $targetId,
            'meta'        => json_encode($meta, JSON_UNESCAPED_UNICODE),
            'ip'          => $ip ?? ($_SERVER['REMOTE_ADDR'] ?? ''),
            'user_agent'  => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        ]);
    }

    private static function logFailure(string $action, ?int $userId, string $email, ?string $ip): void
    {
        $db = Database::getInstance();
        $db->insert('audit_log', [
            'id_user'     => $userId,
            'action'      => $action,
            'target_type' => 'user',
            'target_id'   => $userId,
            'meta'        => json_encode(['email' => $email], JSON_UNESCAPED_UNICODE),
            'ip'          => $ip ?? ($_SERVER['REMOTE_ADDR'] ?? ''),
            'user_agent'  => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        ]);
    }
}
