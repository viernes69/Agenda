<?php
declare(strict_types=1);

namespace Agenduy\Core;

use RuntimeException;

/**
 * Credenciales y llamadas HTTP minimas para Mercado Pago.
 */
final class MercadoPago
{
    private const PROVIDER = 'mercadopago';

    /**
     * Credenciales globales del super admin. Se usan para cobrar membresias.
     *
     * @return array<string,mixed>
     */
    public static function platformConfig(): array
    {
        $global = ProviderConfig::get(self::PROVIDER);
        $cfg = is_array($global['config'] ?? null) ? $global['config'] : [];
        return self::normalizeConfig($cfg, !empty($global['is_enabled']));
    }

    /**
     * Credenciales propias del comercio. Se usan para cobrar pedidos de tienda.
     *
     * @return array<string,mixed>
     */
    public static function commerceConfig(int $commerceId, string $slug = ''): array
    {
        $cfg = [];
        $slug = trim($slug, '/');
        if ($slug !== '' && TenantLocalDb::exists($slug)) {
            try {
                $local = TenantLocalDb::read($slug);
                $info = is_array($local['info_barberia'] ?? null) ? $local['info_barberia'] : [];
                if (is_array($info['mercadopago'] ?? null)) {
                    $cfg = array_replace_recursive($cfg, $info['mercadopago']);
                }
                if (is_array($info['mercado_pago'] ?? null)) {
                    $cfg = array_replace_recursive($cfg, $info['mercado_pago']);
                }
            } catch (\Throwable $e) {
                $cfg = [];
            }
        }

        if ($commerceId > 0) {
            $db = Database::getInstance();
            $crypto = new Crypto((string)$db->config()['security']['encryption_key']);
            $rows = $db->fetchAll(
                'SELECT key_name, key_value FROM api_keys
                 WHERE provider = :p AND id_commerce = :c AND is_active = 1',
                [':p' => self::PROVIDER, ':c' => $commerceId]
            );
            foreach ($rows as $row) {
                $name = strtolower(trim((string)($row['key_name'] ?? '')));
                try {
                    $value = trim($crypto->decrypt((string)($row['key_value'] ?? '')));
                } catch (\Throwable $e) {
                    continue;
                }
                if ($value === '') {
                    continue;
                }
                if (in_array($name, ['mp_access_token', 'access_token', 'accesstoken'], true)) {
                    $cfg['access_token'] = $value;
                } elseif (in_array($name, ['mp_public_key', 'public_key', 'publickey'], true)) {
                    $cfg['public_key'] = $value;
                } elseif (in_array($name, ['mp_integrator_id', 'integrator_id', 'integratorid'], true)) {
                    $cfg['integrator_id'] = $value;
                } elseif ($name === 'client_secret') {
                    $cfg['client_secret'] = $value;
                }
            }
        }

        return self::normalizeConfig($cfg, false);
    }

    /**
     * Tienda puede cobrar online solo en planes Intermedio/Pro.
     */
    public static function isStoreCheckoutAllowed(?array $plan): bool
    {
        if (!is_array($plan)) {
            return false;
        }
        $name = self::normalizeName((string)($plan['nombre'] ?? ''));
        if ($name === '' || $name === 'free' || str_contains($name, 'gratis')) {
            return false;
        }
        if (MembershipPlan::isBasicSettingsOnly($plan)) {
            return false;
        }

        return str_contains($name, 'intermedio')
            || str_contains($name, 'profesional')
            || preg_match('/(^|\s|[-_])pro($|\s|[-_])/', $name) === 1;
    }

    /**
     * @param array<string,mixed> $config
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public static function createPreference(array $config, array $payload): array
    {
        return self::requestJson('POST', '/checkout/preferences', $config, $payload);
    }

    /**
     * @param array<string,mixed> $config
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public static function createPreapproval(array $config, array $payload): array
    {
        return self::requestJson('POST', '/preapproval', $config, $payload);
    }

    /**
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    public static function getPayment(array $config, string $paymentId): array
    {
        $paymentId = trim($paymentId);
        if ($paymentId === '') {
            throw new RuntimeException('Falta payment_id de Mercado Pago.');
        }
        return self::requestJson('GET', '/v1/payments/' . rawurlencode($paymentId), $config);
    }

    public static function paymentStatusToStoreStatus(string $status): string
    {
        return match (strtolower(trim($status))) {
            'approved', 'accredited' => 'approved',
            'authorized', 'pending', 'in_process', 'in_mediation' => 'pending',
            'cancelled', 'canceled' => 'cancelled',
            'refunded' => 'refunded',
            'charged_back' => 'charged_back',
            'rejected' => 'rejected',
            default => 'unknown',
        };
    }

    public static function paymentStatusToLocalCartStatus(string $status): string
    {
        return match (self::paymentStatusToStoreStatus($status)) {
            'approved' => 'Pagado',
            'pending' => 'Pago pendiente',
            'cancelled' => 'Pago cancelado',
            'refunded' => 'Reembolsado',
            'charged_back' => 'Contracargo',
            'rejected' => 'Pago rechazado',
            default => 'Pago en revision',
        };
    }

    /**
     * @param array<string,mixed> $cfg
     * @return array<string,mixed>
     */
    private static function normalizeConfig(array $cfg, bool $enabledDefault): array
    {
        $accessToken = self::cleanSecret(
            $cfg['access_token'] ?? $cfg['accessToken'] ?? $cfg['MP_ACCESS_TOKEN'] ?? ''
        );
        $publicKey = self::cleanSecret(
            $cfg['public_key'] ?? $cfg['publicKey'] ?? $cfg['MP_PUBLIC_KEY'] ?? ''
        );
        $integratorId = self::cleanSecret(
            $cfg['integrator_id'] ?? $cfg['integratorId'] ?? $cfg['MP_INTEGRATOR_ID'] ?? ''
        );
        $mode = strtolower(trim((string)($cfg['modo'] ?? $cfg['mode'] ?? '')));
        $sandbox = array_key_exists('sandbox', $cfg)
            ? self::truthy($cfg['sandbox'])
            : ($mode !== 'live' && $mode !== 'prod' && $mode !== 'production');
        $enabled = array_key_exists('enabled', $cfg) ? self::truthy($cfg['enabled']) : $enabledDefault;

        $currency = strtoupper(trim((string)($cfg['currency'] ?? $cfg['moneda'] ?? 'UYU')));
        if ($currency === '') {
            $currency = 'UYU';
        }

        return array_replace($cfg, [
            'enabled' => $enabled,
            'sandbox' => $sandbox,
            'modo' => $sandbox ? 'test' : 'live',
            'access_token' => $accessToken,
            'public_key' => $publicKey,
            'integrator_id' => $integratorId,
            'currency' => $currency,
            'statement_descriptor' => trim((string)($cfg['statement_descriptor'] ?? '')),
        ]);
    }

    private static function cleanSecret(mixed $value): string
    {
        if (!is_scalar($value)) {
            return '';
        }
        $value = trim((string)$value);
        if ($value === '' || self::isMaskedSecret($value)) {
            return '';
        }
        return $value;
    }

    private static function isMaskedSecret(string $value): bool
    {
        return str_contains($value, '*')
            || str_contains($value, "\xE2\x80\xA2")
            || preg_match('/x{4,}/i', $value) === 1;
    }

    private static function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int)$value === 1;
        }
        $value = strtolower(trim((string)$value));
        return in_array($value, ['1', 'true', 'yes', 'si', 'on', 'enabled', 'activo', 'live', 'test'], true);
    }

    private static function normalizeName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return '';
        }
        $name = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
        return strtr($name, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u',
            'ñ' => 'n', 'Ñ' => 'n',
        ]);
    }

    /**
     * @param array<string,mixed>|null $payload
     * @return array<string,mixed>
     */
    private static function requestJson(string $method, string $path, array $config, ?array $payload = null): array
    {
        $accessToken = trim((string)($config['access_token'] ?? ''));
        if ($accessToken === '') {
            throw new RuntimeException('Falta ACCESS_TOKEN de Mercado Pago.');
        }

        $base = rtrim((string)(getenv('AGENDUY_MP_API_BASE') ?: 'https://api.mercadopago.com'), '/');
        $url = $base . '/' . ltrim($path, '/');
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $accessToken,
        ];
        $integratorId = trim((string)($config['integrator_id'] ?? ''));
        if ($integratorId !== '') {
            $headers[] = 'X-Integrator-Id: ' . $integratorId;
        }

        if (function_exists('curl_init')) {
            return self::requestWithCurl($method, $url, $headers, $payload);
        }

        return self::requestWithStream($method, $url, $headers, $payload);
    }

    /**
     * @param list<string> $headers
     * @param array<string,mixed>|null $payload
     * @return array<string,mixed>
     */
    private static function requestWithCurl(string $method, string $url, array $headers, ?array $payload): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('No se pudo iniciar la conexion con Mercado Pago.');
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        $response = curl_exec($ch);
        $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException('Error contacting MP: ' . $error);
        }

        return self::decodeResponse((string)$response, $statusCode);
    }

    /**
     * @param list<string> $headers
     * @param array<string,mixed>|null $payload
     * @return array<string,mixed>
     */
    private static function requestWithStream(string $method, string $url, array $headers, ?array $payload): array
    {
        $body = $payload !== null ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
        $context = stream_context_create([
            'http' => [
                'method' => strtoupper($method),
                'header' => implode("\r\n", $headers),
                'content' => $body,
                'timeout' => 20,
                'ignore_errors' => true,
            ],
        ]);
        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            throw new RuntimeException('Error contacting MP.');
        }
        $statusCode = 0;
        foreach (($http_response_header ?? []) as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', (string)$header, $matches)) {
                $statusCode = (int)$matches[1];
                break;
            }
        }
        return self::decodeResponse((string)$response, $statusCode);
    }

    /**
     * @return array<string,mixed>
     */
    private static function decodeResponse(string $response, int $statusCode): array
    {
        $response = preg_replace('/^\xEF\xBB\xBF/', '', $response) ?? $response;
        $response = trim($response);
        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Mercado Pago devolvio una respuesta invalida.');
        }
        if ($statusCode >= 400) {
            $message = trim((string)($decoded['message'] ?? $decoded['error'] ?? 'Mercado Pago rechazo la solicitud.'));
            if (isset($decoded['cause'][0]['description'])) {
                $message .= ': ' . (string)$decoded['cause'][0]['description'];
            }
            throw new RuntimeException($message);
        }
        return $decoded;
    }
}
