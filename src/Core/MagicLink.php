<?php
declare(strict_types=1);

namespace Agenduy\Core;

use RuntimeException;

final class MagicLink
{
    public const PURPOSE_ADMIN = 'admin_login';
    public const PURPOSE_CLIENT = 'client_portal';

    private const TTL_MINUTES = 20;

    /**
     * Envía link mágico a un usuario del panel (commerce_admin / super_admin).
     */
    public static function sendAdminLogin(string $email, ?string $ip = null): array
    {
        $email = strtolower(trim($email));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'Email inválido.'];
        }

        $db = Database::getInstance();
        $user = $db->fetchOne(
            'SELECT id_user, role, activo FROM users WHERE email = :e LIMIT 1',
            [':e' => $email]
        );
        // Respuesta genérica para no filtrar emails existentes.
        if (!$user || (int)$user['activo'] !== 1) {
            return ['ok' => true, 'message' => 'Si el email existe, te enviamos un link de acceso.'];
        }

        $token = self::createToken($email, self::PURPOSE_ADMIN, null, [], $ip);
        $link = url('admin/auth/magic.php?token=' . rawurlencode($token));
        $cfg = $db->config();
        $fromName = (string)($cfg['mail']['from_name'] ?? 'Agendarte');
        $tplVars = [
            'link' => $link,
            'from_name' => $fromName,
            'ttl_minutes' => (string)self::TTL_MINUTES,
        ];
        $subject = PlatformTemplates::render('email', 'magic_link_admin', $tplVars, 'subject', 'Tu acceso a ' . $fromName);
        $body = PlatformTemplates::renderHtml('email', 'magic_link_admin', $tplVars, '');

        if (!Mail::isConfigured()) {
            error_log('[MagicLink.admin] SMTP no configurado');
            return ['ok' => false, 'error' => 'El envío de emails no está configurado. Contactá al administrador.'];
        }

        if (!Mail::send($email, $subject, $body)) {
            error_log('[MagicLink.admin] fallo SMTP: ' . (Mail::lastError() ?? 'desconocido'));
            return [
                'ok' => false,
                'error' => 'No se pudo enviar el email. Intentá de nuevo más tarde.',
            ];
        }
        return ['ok' => true, 'message' => 'Si el email existe, te enviamos un link de acceso.'];
    }

    public static function normalizeCedula(?string $cedula): string
    {
        $digits = preg_replace('/\D+/', '', (string)$cedula) ?? '';
        return strlen($digits) >= 7 ? $digits : '';
    }

    /**
     * Verifica email + cédula contra clientes o reservas del comercio.
     */
    public static function clientIdentityMatches(int $idCommerce, string $email, string $cedula): bool
    {
        if ($idCommerce <= 0 || $email === '' || $cedula === '') {
            return false;
        }

        $db = Database::getInstance();
        $fromClient = $db->fetchOne(
            'SELECT 1 FROM clients
             WHERE id_commerce = :c AND lower(trim(email)) = :e AND cedula = :ci LIMIT 1',
            [':c' => $idCommerce, ':e' => $email, ':ci' => $cedula]
        );
        if ($fromClient) {
            return true;
        }

        $fromAppt = $db->fetchOne(
            'SELECT 1 FROM appointments a
             INNER JOIN clients cl ON cl.id_client = a.id_client
             WHERE a.id_commerce = :c AND lower(trim(a.cliente_email)) = :e AND cl.cedula = :ci
             LIMIT 1',
            [':c' => $idCommerce, ':e' => $email, ':ci' => $cedula]
        );
        return (bool)$fromAppt;
    }

    /**
     * Link mágico para clientes de un comercio (ver reservas / perfil).
     */
    public static function sendClientPortal(
        string $email,
        int $idCommerce,
        ?string $slug = null,
        ?string $ip = null,
        ?string $cedula = null
    ): array {
        $email = strtolower(trim($email));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'Email inválido.'];
        }
        if ($idCommerce <= 0) {
            return ['ok' => false, 'error' => 'Comercio inválido.'];
        }

        $cedulaNorm = self::normalizeCedula($cedula);
        if ($cedulaNorm === '') {
            return ['ok' => false, 'error' => 'Ingresá tu cédula (solo números, mínimo 7 dígitos).'];
        }

        $generic = ['ok' => true, 'message' => 'Si tus datos coinciden con una reserva, te enviamos un link al email.'];

        if (!self::clientIdentityMatches($idCommerce, $email, $cedulaNorm)) {
            return $generic;
        }

        $token = self::createToken($email, self::PURPOSE_CLIENT, $idCommerce, ['slug' => $slug ?? ''], $ip);
        $path = $slug !== null && $slug !== '' ? ($slug . '/?client_token=' . rawurlencode($token)) : ('?client_token=' . rawurlencode($token));
        $link = url($path);
        $commerce = Database::getInstance()->fetchOne('SELECT nombre FROM commerces WHERE id_commerce = :id', [':id' => $idCommerce]);
        $biz = (string)($commerce['nombre'] ?? 'tu negocio');
        $tplVars = [
            'link' => $link,
            'negocio' => $biz,
        ];
        $subject = PlatformTemplates::render('email', 'magic_link_client', $tplVars, 'subject', 'Acceso a tus reservas - ' . $biz);
        $body = PlatformTemplates::renderHtml('email', 'magic_link_client', $tplVars, '');

        if (!Mail::isConfigured()) {
            error_log('[MagicLink.client] SMTP no configurado');
            return ['ok' => false, 'error' => 'El envío de emails no está configurado. Contactá al negocio.'];
        }

        if (!Mail::send($email, $subject, $body, null, $idCommerce)) {
            error_log('[MagicLink.client] fallo SMTP: ' . (Mail::lastError() ?? 'desconocido'));
        }
        return $generic;
    }

    public static function consume(string $token, ?string $ip = null): array
    {
        $token = trim($token);
        if ($token === '') {
            return ['ok' => false, 'error' => 'Link inválido.'];
        }
        $hash = hash('sha256', $token);
        $db = Database::getInstance();
        $row = $db->fetchOne(
            'SELECT * FROM auth_tokens WHERE token_hash = :h AND used_at IS NULL LIMIT 1',
            [':h' => $hash]
        );
        if (!$row) {
            return ['ok' => false, 'error' => 'Link inválido o ya usado.'];
        }
        if (strtotime((string)$row['expires_at']) < time()) {
            return ['ok' => false, 'error' => 'El link expiró. Pedí uno nuevo.'];
        }

        $db->update('auth_tokens', [
            'used_at' => date('Y-m-d H:i:s'),
        ], 'id_token = :id', [':id' => (int)$row['id_token']]);

        $purpose = (string)$row['purpose'];
        $email = strtolower((string)$row['email']);

        if ($purpose === self::PURPOSE_ADMIN) {
            $user = $db->fetchOne('SELECT * FROM users WHERE email = :e AND activo = 1 LIMIT 1', [':e' => $email]);
            if (!$user) {
                return ['ok' => false, 'error' => 'Cuenta no encontrada.'];
            }
            Auth::establishSessionFromRow($user, $ip);
            return ['ok' => true, 'redirect' => Auth::dashboardUrl(Auth::user() ?? []) ?? url('/')];
        }

        if ($purpose === self::PURPOSE_CLIENT) {
            $idCommerce = (int)($row['id_commerce'] ?? 0);
            Auth::start();
            session_regenerate_id(true);
            $_SESSION['client'] = [
                'id_commerce' => $idCommerce,
                'email'       => $email,
                'login_at'    => time(),
            ];
            $meta = json_decode((string)($row['meta_json'] ?? '{}'), true);
            $slug = is_array($meta) ? trim((string)($meta['slug'] ?? '')) : '';
            if ($slug === '') {
                $commerce = $db->fetchOne('SELECT slug FROM commerces WHERE id_commerce = :id', [':id' => $idCommerce]);
                $slug = trim((string)($commerce['slug'] ?? ''));
            }
            return ['ok' => true, 'redirect' => $slug !== '' ? url($slug) : url('/')];
        }

        return ['ok' => false, 'error' => 'Link inválido.'];
    }

    private static function createToken(
        string $email,
        string $purpose,
        ?int $idCommerce,
        array $meta,
        ?string $ip
    ): string {
        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);
        Database::getInstance()->insert('auth_tokens', [
            'email'       => strtolower($email),
            'token_hash'  => $hash,
            'purpose'     => $purpose,
            'id_commerce' => $idCommerce,
            'meta_json'   => json_encode($meta, JSON_UNESCAPED_UNICODE),
            'expires_at'  => date('Y-m-d H:i:s', time() + self::TTL_MINUTES * 60),
            'ip'          => $ip ?? '',
        ]);
        return $token;
    }
}
