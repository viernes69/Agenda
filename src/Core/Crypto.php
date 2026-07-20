<?php
/**
 * Agenduy - Crypto
 * Cifrado/descifrado simétrico para API keys y secretos.
 * Usa AES-256-GCM con IV aleatorio y auth tag.
 */

declare(strict_types=1);

namespace Agenduy\Core;

use RuntimeException;

final class Crypto
{
    private string $key;

    public function __construct(string $key)
    {
        if (strlen($key) < 32) {
            throw new RuntimeException('La llave de cifrado debe tener al menos 32 caracteres.');
        }
        $this->key = $key;
    }

    public function encrypt(string $plaintext): string
    {
        $iv   = random_bytes(12); // 96 bits recomendado para GCM
        $tag  = '';
        $cipher = openssl_encrypt(
            $plaintext,
            'aes-256-gcm',
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            16
        );
        if ($cipher === false) {
            throw new RuntimeException('No se pudo cifrar el valor.');
        }
        // output: base64(iv . tag . cipher)
        return base64_encode($iv . $tag . $cipher);
    }

    public function decrypt(string $payload): string
    {
        $raw = base64_decode($payload, true);
        if ($raw === false || strlen($raw) < 28) {
            throw new RuntimeException('Payload cifrado inválido.');
        }
        $iv     = substr($raw, 0, 12);
        $tag    = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $plain  = openssl_decrypt(
            $cipher,
            'aes-256-gcm',
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );
        if ($plain === false) {
            throw new RuntimeException('No se pudo descifrar el valor.');
        }
        return $plain;
    }

    /**
     * Genera una key segura (64 chars hex = 256 bits).
     */
    public static function generateKey(int $bytes = 32): string
    {
        return bin2hex(random_bytes($bytes));
    }
}
