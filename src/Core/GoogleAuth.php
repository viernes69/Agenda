<?php
declare(strict_types=1);

namespace Agenduy\Core;

use RuntimeException;

/**
 * Verificación de Google Identity Services (ID token).
 */
final class GoogleAuth
{
    public static function config(): array
    {
        $row = ProviderConfig::get('google_oauth');
        $cfg = $row['config'];
        return [
            'enabled'     => $row['is_enabled'] && trim((string)($cfg['client_id'] ?? '')) !== '',
            'client_id'   => trim((string)($cfg['client_id'] ?? '')),
            'client_secret' => trim((string)($cfg['client_secret'] ?? '')),
        ];
    }

    public static function clientId(): string
    {
        return self::config()['client_id'];
    }

    public static function isEnabled(): bool
    {
        return self::config()['enabled'];
    }

    /**
     * @return array{sub:string,email:string,email_verified:bool,given_name:string,family_name:string,name:string,picture:string}
     */
    public static function verifyIdToken(string $idToken): array
    {
        $idToken = trim($idToken);
        if ($idToken === '') {
            throw new RuntimeException('Falta el token de Google.');
        }
        $clientId = self::clientId();
        if ($clientId === '') {
            throw new RuntimeException('Google no está configurado en el panel.');
        }

        $url = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . rawurlencode($idToken);
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('No se pudo validar Google.');
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
        ]);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = is_string($raw) ? json_decode($raw, true) : null;
        if ($status >= 400 || !is_array($data)) {
            throw new RuntimeException('Token de Google inválido o expirado.');
        }
        if (isset($data['error_description'])) {
            throw new RuntimeException((string)$data['error_description']);
        }
        if ((string)($data['aud'] ?? '') !== $clientId) {
            throw new RuntimeException('El token de Google no corresponde a esta aplicación.');
        }
        $email = strtolower(trim((string)($data['email'] ?? '')));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Google no devolvió un email válido.');
        }
        if (($data['email_verified'] ?? 'false') !== 'true' && ($data['email_verified'] ?? false) !== true) {
            throw new RuntimeException('Verificá tu email en Google antes de continuar.');
        }

        return [
            'sub'            => (string)($data['sub'] ?? ''),
            'email'          => $email,
            'email_verified' => true,
            'given_name'     => trim((string)($data['given_name'] ?? '')),
            'family_name'    => trim((string)($data['family_name'] ?? '')),
            'name'           => trim((string)($data['name'] ?? '')),
            'picture'        => trim((string)($data['picture'] ?? '')),
        ];
    }
}
