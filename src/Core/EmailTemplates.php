<?php
declare(strict_types=1);

namespace Agenduy\Core;

/**
 * Plantillas de email por comercio con placeholders simples.
 */
final class EmailTemplates
{
    /**
     * @param array<string,string> $vars
     */
    public static function render(int $idCommerce, string $templateKey, array $vars, string $field, string $fallback = ''): string
    {
        $templates = CommerceSettings::get(
            $idCommerce,
            'email_plantillas',
            CommerceSettings::defaultsForSection('email_plantillas')
        );
        $tpl = is_array($templates[$templateKey] ?? null) ? $templates[$templateKey] : [];
        $text = trim((string)($tpl[$field] ?? ''));
        if ($text === '') {
            $defaults = CommerceSettings::defaultsForSection('email_plantillas');
            $text = trim((string)(($defaults[$templateKey][$field] ?? $fallback)));
        }
        if ($text === '') {
            $text = $fallback;
        }
        return self::applyVars($text, $vars);
    }

    /**
     * @param array<string,string> $vars
     */
    public static function renderHtmlFromText(string $text, array $vars): string
    {
        $plain = self::applyVars($text, $vars);
        return '<p>' . nl2br(htmlspecialchars($plain, ENT_QUOTES, 'UTF-8')) . '</p>';
    }

    /**
     * @param array<string,string> $vars
     */
    private static function applyVars(string $text, array $vars): string
    {
        $replacements = [];
        foreach ($vars as $key => $value) {
            $replacements['{' . $key . '}'] = (string)$value;
        }
        return strtr($text, $replacements);
    }

    public static function ownerEmail(int $idCommerce, array $commerce): string
    {
        $notif = CommerceSettings::get(
            $idCommerce,
            'notificaciones',
            CommerceSettings::defaultsForSection('notificaciones')
        );
        $configured = strtolower(trim((string)($notif['owner_email'] ?? '')));
        if ($configured !== '' && filter_var($configured, FILTER_VALIDATE_EMAIL)) {
            return $configured;
        }
        $commerceEmail = strtolower(trim((string)($commerce['email'] ?? '')));
        if ($commerceEmail !== '' && filter_var($commerceEmail, FILTER_VALIDATE_EMAIL)) {
            return $commerceEmail;
        }
        $db = Database::getInstance();
        $owner = $db->fetchOne(
            'SELECT email FROM users WHERE id_commerce = :c AND role = :r AND activo = 1 ORDER BY id_user ASC LIMIT 1',
            [':c' => $idCommerce, ':r' => Auth::ROLE_LOCAL]
        );
        $ownerEmail = strtolower(trim((string)($owner['email'] ?? '')));
        return filter_var($ownerEmail, FILTER_VALIDATE_EMAIL) ? $ownerEmail : '';
    }
}
