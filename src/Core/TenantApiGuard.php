<?php
declare(strict_types=1);

namespace Agenduy\Core;

/**
 * Protege APIs JSON del dashboard tenant (admin/empleado).
 */
final class TenantApiGuard
{
    /**
     * @return array{session:array<string,mixed>,role:string,slug:string}
     */
    public static function requireStaff(string $tenantRoot): array
    {
        Auth::start();

        $slug = CommercePanel::bootstrapStaffContext($tenantRoot);
        if ($slug === '' || CommercePanel::isTemplateHost($slug)) {
            self::deny(403, 'Acceso denegado.');
        }

        $session = null;
        foreach (['user', 'barbero', 'admin'] as $key) {
            if (isset($_SESSION[$key]) && is_array($_SESSION[$key])) {
                $session = $_SESSION[$key];
                break;
            }
        }

        if ($session === null) {
            self::deny(401, 'No autorizado.');
        }

        $centralRole = strtolower(trim((string)($session['role'] ?? '')));
        $legacyRole = strtolower(trim((string)($session['Rol'] ?? $session['rol'] ?? '')));

        if ($centralRole === Auth::ROLE_SUPER) {
            self::deny(403, 'Acceso denegado.');
        }

        if ($centralRole === Auth::ROLE_LOCAL) {
            $commerceId = (int)($session['id_commerce'] ?? 0);
            if ($commerceId <= 0) {
                self::deny(401, 'No autorizado.');
            }
            $commerce = Database::getInstance()->fetchOne(
                'SELECT slug FROM commerces WHERE id_commerce = :id LIMIT 1',
                [':id' => $commerceId]
            );
            $ownedSlug = trim((string)($commerce['slug'] ?? ''));
            if ($ownedSlug === '' || !hash_equals($ownedSlug, $slug)) {
                self::deny(403, 'Acceso denegado.');
            }

            return [
                'session' => $session,
                'role'    => 'admin',
                'slug'    => $slug,
            ];
        }

        if (in_array($legacyRole, ['admin', 'func'], true)) {
            return [
                'session' => $session,
                'role'    => $legacyRole,
                'slug'    => $slug,
            ];
        }

        self::deny(401, 'No autorizado.');
    }

    private static function deny(int $code, string $message): void
    {
        if (!headers_sent()) {
            http_response_code($code);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
