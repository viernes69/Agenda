<?php
declare(strict_types=1);

namespace Agenduy\Core;

/**
 * Utilidades transversales de seguridad.
 */
final class Security
{
    public static function clientIp(): string
    {
        $remote = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
        if ($remote === '') {
            return '0.0.0.0';
        }

        // Detrás de proxy reverso de confianza (Cloudflare, nginx, etc.)
        $forwarded = trim((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
        if ($forwarded !== '' && self::isTrustedProxy($remote)) {
            $parts = array_map('trim', explode(',', $forwarded));
            $candidate = $parts[0] ?? '';
            if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                return $candidate;
            }
        }

        return $remote;
    }

    public static function isHttpsRequest(): bool
    {
        return (bool)(agenduy_request()['is_https'] ?? false);
    }

    private static function isTrustedProxy(string $remote): bool
    {
        if (in_array($remote, ['127.0.0.1', '::1'], true)) {
            return true;
        }
        if (str_starts_with($remote, '10.') || str_starts_with($remote, '192.168.')) {
            return true;
        }
        return false;
    }
}
