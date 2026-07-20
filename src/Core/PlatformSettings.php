<?php
declare(strict_types=1);

namespace Agenduy\Core;

/**
 * Ajustes globales editables por el super admin.
 */
final class PlatformSettings
{
    public const CONTACT_SECTION = 'contact';

    /**
     * @return array<string,mixed>
     */
    public static function get(string $section, array $defaults = []): array
    {
        try {
            $row = Database::getInstance()->fetchOne(
                'SELECT config_json FROM platform_settings WHERE section = :s LIMIT 1',
                [':s' => $section]
            );
        } catch (\Throwable) {
            return $defaults;
        }
        if (!$row) {
            return $defaults;
        }
        $data = json_decode((string)($row['config_json'] ?? ''), true);
        if (!is_array($data)) {
            return $defaults;
        }
        return array_replace($defaults, $data);
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function save(string $section, array $data): void
    {
        $db = Database::getInstance();
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            $json = '{}';
        }

        $existing = $db->fetchOne(
            'SELECT id_setting FROM platform_settings WHERE section = :s LIMIT 1',
            [':s' => $section]
        );
        if ($existing) {
            $db->update('platform_settings', [
                'config_json' => $json,
                'updated_at'  => date('Y-m-d H:i:s'),
            ], 'id_setting = :id', [':id' => (int)$existing['id_setting']]);
            return;
        }

        $db->insert('platform_settings', [
            'section'     => $section,
            'config_json' => $json,
        ]);
    }

    /**
     * @return array{instagram:string,whatsapp:string}
     */
    public static function contactDefaults(): array
    {
        return [
            'instagram' => 'https://instagram.com/agendarte.uy',
            'whatsapp'  => '59892365135',
        ];
    }

    /**
     * @return array{instagram:string,whatsapp:string,instagram_url:string,whatsapp_digits:string,whatsapp_url:string}
     */
    public static function contact(): array
    {
        $raw = self::get(self::CONTACT_SECTION, self::contactDefaults());
        $instagram = trim((string)($raw['instagram'] ?? ''));
        $whatsapp = trim((string)($raw['whatsapp'] ?? ''));
        $digits = self::whatsappDigits($whatsapp);

        return [
            'instagram' => $instagram,
            'whatsapp' => $whatsapp,
            'instagram_url' => self::instagramUrl($instagram),
            'whatsapp_digits' => $digits,
            'whatsapp_url' => $digits !== '' ? 'https://wa.me/' . $digits : '',
        ];
    }

    /**
     * @param array{instagram?:string,whatsapp?:string} $data
     */
    public static function saveContact(array $data): void
    {
        self::save(self::CONTACT_SECTION, [
            'instagram' => trim((string)($data['instagram'] ?? '')),
            'whatsapp'  => trim((string)($data['whatsapp'] ?? '')),
        ]);
    }

    public static function instagramUrl(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $value)) {
            return $value;
        }
        if (str_starts_with($value, 'www.') || str_starts_with(strtolower($value), 'instagram.com/')) {
            return 'https://' . ltrim($value, '/');
        }
        $handle = ltrim($value, '@/');
        return $handle !== '' ? 'https://instagram.com/' . rawurlencode($handle) : '';
    }

    public static function whatsappDigits(string $value): string
    {
        if (preg_match('#(?:wa\.me/|phone=)([0-9+ ]+)#i', $value, $match)) {
            $value = $match[1];
        }
        return preg_replace('/\D+/', '', $value) ?? '';
    }

    public static function whatsappUrl(string $message = ''): string
    {
        $digits = self::contact()['whatsapp_digits'];
        if ($digits === '') {
            return '';
        }
        $url = 'https://wa.me/' . $digits;
        if ($message !== '') {
            $url .= '?text=' . rawurlencode($message);
        }
        return $url;
    }
}
