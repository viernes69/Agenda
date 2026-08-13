<?php
declare(strict_types=1);

namespace Agenduy\Core;

use RuntimeException;

/**
 * Cliente HTTP para UltraMsg (documentación: https://docs.ultramsg.com/).
 */
final class UltraMsg
{
    public static function send(string $to, string $body): bool
    {
        $cfg = ProviderConfig::ultraMsgConfig();
        if (!$cfg['enabled']) {
            throw new RuntimeException('UltraMsg esta deshabilitado en la configuracion global.');
        }
        if ($cfg['instance_id'] === '' || $cfg['token'] === '') {
            throw new RuntimeException('Faltan credenciales globales de UltraMsg.');
        }

        $phone = self::normalizePhone($to);
        if ($phone === '') {
            throw new RuntimeException('Número de WhatsApp inválido.');
        }

        $url = sprintf(
            'https://api.ultramsg.com/%s/messages/chat',
            rawurlencode($cfg['instance_id'])
        );

        $payload = http_build_query([
            'token' => $cfg['token'],
            'to'    => $phone,
            'body'  => $body,
        ]);

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('No se pudo iniciar cURL para UltraMsg.');
        }
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            throw new RuntimeException('Error de red al enviar WhatsApp.');
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('UltraMsg respondió con HTTP ' . $status . ': ' . (string)$response);
        }

        return true;
    }

    public static function normalizePhone(string $raw): string
    {
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if ($digits === '') {
            return '';
        }
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }
        return $digits;
    }
}
