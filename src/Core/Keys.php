<?php
/**
 * Agenduy - Keys
 * Helpers para generar códigos seriales, slugs y keys.
 */

declare(strict_types=1);

namespace Agenduy\Core;

final class Keys
{
    public static function slug(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return 'comercio-' . date('YmdHis');
        }
        $normalized = @iconv('UTF-8', 'ASCII//TRANSLIT', $value);
        if ($normalized === false) {
            $normalized = $value;
        }
        $slug = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $normalized));
        $slug = trim($slug, '-');
        return $slug !== '' ? $slug : 'comercio-' . date('YmdHis');
    }

    public static function serial(): string
    {
        $hex = bin2hex(random_bytes(8)); // 16 hex
        $hex = strtoupper($hex);
        return implode('-', str_split($hex, 4));
    }

    public static function generateApiKey(): string
    {
        // 64 chars hex = 256 bits
        return 'ak_' . bin2hex(random_bytes(32));
    }

    public static function generateClientToken(): string
    {
        return 'ct_' . bin2hex(random_bytes(24));
    }
}
