<?php
/**
 * PayPal Orders/Billing helpers for SaaS membership checkout.
 */
declare(strict_types=1);

namespace Agenduy\Core;

use RuntimeException;

final class Paypal
{
    /**
     * @return array{return:string,cancel:string}
     */
    public static function returnUrls(string $appUrl, string $slug): array
    {
        $base = rtrim($appUrl, '/');
        if ($base === '' && function_exists('url_base')) {
            $base = rtrim((string)url_base(), '/');
        }
        $slug = trim($slug, '/');
        if ($slug !== '') {
            return [
                'return' => $base . '/' . $slug . '/private/dashboard/admin/?pay=paypal_ok',
                'cancel' => $base . '/' . $slug . '/private/dashboard/admin/?pay=paypal_cancel',
            ];
        }
        return [
            'return' => $base . '/admin/subscriptions.php?ok=1',
            'cancel' => $base . '/admin/subscriptions.php?ok=0',
        ];
    }

    /**
     * Map membership moneda to a PayPal-supported ISO code.
     * UYU and other unsupported codes fall back to USD (same numeric amount).
     *
     * @see https://developer.paypal.com/api/rest/reference/currency-codes/
     */
    public static function currencyCode(string $moneda): string
    {
        $code = strtoupper(trim($moneda));
        static $supported = [
            'AUD', 'BRL', 'CAD', 'CNY', 'CZK', 'DKK', 'EUR', 'HKD', 'HUF', 'ILS',
            'JPY', 'MYR', 'MXN', 'TWD', 'NZD', 'NOK', 'PHP', 'PLN', 'GBP', 'RUB',
            'SGD', 'SEK', 'CHF', 'THB', 'USD',
        ];
        if ($code === '' || !in_array($code, $supported, true)) {
            return 'USD';
        }
        return $code;
    }

    /** Format amount (zero-decimal currencies must not include decimals). */
    public static function amountValue(float $amount, string $currency): string
    {
        $currency = strtoupper($currency);
        if (in_array($currency, ['HUF', 'JPY', 'TWD'], true)) {
            return (string)(int)round($amount);
        }
        return number_format($amount, 2, '.', '');
    }

    public static function accessToken(string $baseUrl, string $clientId, string $secret): string
    {
        $ch = curl_init($baseUrl . '/v1/oauth2/token');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_USERPWD, $clientId . ':' . $secret);
        curl_setopt($ch, CURLOPT_POSTFIELDS, 'grant_type=client_credentials');
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $tok = json_decode((string)$resp, true);
        if ($code >= 400 || empty($tok['access_token'])) {
            throw new RuntimeException('No se pudo autenticar con PayPal.');
        }
        return (string)$tok['access_token'];
    }

    /**
     * @param array<int,array<string,mixed>> $links
     */
    public static function linkHref(array $links, string $rel): ?string
    {
        foreach ($links as $link) {
            if (!is_array($link)) {
                continue;
            }
            if (strcasecmp((string)($link['rel'] ?? ''), $rel) === 0 && !empty($link['href'])) {
                return (string)$link['href'];
            }
        }
        return isset($links[0]['href']) ? (string)$links[0]['href'] : null;
    }

    /**
     * @param array<string,mixed>|null $decoded
     */
    public static function userErrorMessage(?array $decoded): string
    {
        $issue = '';
        $field = '';
        $detail = '';
        if (is_array($decoded) && !empty($decoded['details'][0]) && is_array($decoded['details'][0])) {
            $issue = (string)($decoded['details'][0]['issue'] ?? '');
            $field = (string)($decoded['details'][0]['field'] ?? '');
            $detail = (string)($decoded['details'][0]['description'] ?? '');
        }
        $name = is_array($decoded) ? (string)($decoded['name'] ?? '') : '';
        $msg = is_array($decoded) ? (string)($decoded['message'] ?? '') : '';

        if ($issue === 'CURRENCY_NOT_SUPPORTED' || stripos($detail, 'Currency code is not currently supported') !== false) {
            return 'PayPal no acepta esta moneda. Contactá a soporte o usá otro método de pago.';
        }
        if ($issue === 'INVALID_PARAMETER_SYNTAX'
            && (stripos($field, 'return_url') !== false || stripos($field, 'cancel_url') !== false)) {
            return 'URL de retorno inválida para PayPal. Revisá la configuración del sitio (AGENDUY_URL_BASE).';
        }
        if ($issue === 'INVALID_PARAMETER_SYNTAX') {
            return 'PayPal rechazó un dato del pago (formato inválido). Probá de nuevo o usá otro método.';
        }
        if ($name === 'AUTHENTICATION_FAILURE' || stripos($msg, 'Authentication failed') !== false) {
            return 'Credenciales de PayPal inválidas. Revisá la configuración en Config.';
        }
        if ($detail !== '') {
            if (preg_match('/[A-Za-z]{3,}/', $detail) && !preg_match('/[áéíóúñÁÉÍÓÚÑ]/u', $detail)) {
                return 'PayPal no pudo iniciar el pago. ' . $detail;
            }
            return $detail;
        }
        if ($msg !== '') {
            return $msg;
        }
        return 'Error de PayPal.';
    }

    /**
     * @param array<string,mixed>|object $body
     * @return array<string,mixed>
     */
    public static function json(string $url, string $accessToken, $body): array
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $accessToken,
            'PayPal-Request-Id: ' . uniqid('agenduy_', true),
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $decoded = json_decode((string)$resp, true);
        if ($code >= 400) {
            throw new RuntimeException(self::userErrorMessage(is_array($decoded) ? $decoded : null));
        }
        return is_array($decoded) ? $decoded : [];
    }
}
