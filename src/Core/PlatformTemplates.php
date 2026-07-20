<?php
declare(strict_types=1);

namespace Agenduy\Core;

/**
 * Plantillas globales de la plataforma (email y UltraMSG).
 * Solo las edita el super admin root desde /admin/plantillas.php.
 */
final class PlatformTemplates
{
    private const SECTION = 'message_templates';
    private const BRANDING_SECTION = 'email_branding';

    /** @var array<string,string>|null */
    private static ?array $sampleVarsCache = null;

    /**
     * @return array<string, array<string, array<string, mixed>>>
     */
    public static function catalog(): array
    {
        return [
            'email' => [
                'appointment_confirmed_client' => [
                    'label' => 'Reserva confirmada (cliente)',
                    'hint' => 'Se envía al cliente cuando confirma una reserva.',
                    'fields' => ['subject', 'body'],
                    'defaults' => [
                        'subject' => 'Reserva confirmada - {negocio}',
                        'body' => "{logo}\n\nHola {cliente}, tu reserva en {negocio} quedó confirmada.\nServicio: {servicio}\nFecha: {fecha}\nHora: {hora}",
                    ],
                ],
                'appointment_confirmed_owner' => [
                    'label' => 'Nueva reserva (dueño del negocio)',
                    'hint' => 'Aviso al administrador del comercio cuando entra una reserva.',
                    'fields' => ['subject', 'body'],
                    'defaults' => [
                        'subject' => 'Nueva reserva - {cliente}',
                        'body' => "Nueva reserva en {negocio}\nCliente: {cliente}\nCelular: {telefono}\nServicio: {servicio}\nFecha: {fecha}\nHora: {hora}",
                    ],
                ],
                'appointment_reminder_24h' => [
                    'label' => 'Recordatorio 24 h (cliente)',
                    'hint' => 'Email de recordatorio un día antes de la cita.',
                    'fields' => ['subject', 'body'],
                    'defaults' => [
                        'subject' => 'Recordatorio de reserva - {negocio}',
                        'body' => 'Recordatorio: mañana tienes {servicio} en {negocio} a las {hora}.',
                    ],
                ],
                'appointment_reminder_2h' => [
                    'label' => 'Recordatorio 2 h (cliente)',
                    'hint' => 'Email de recordatorio dos horas antes de la cita.',
                    'fields' => ['subject', 'body'],
                    'defaults' => [
                        'subject' => 'Tu cita es pronto - {negocio}',
                        'body' => 'Recordatorio: en 2 horas tienes {servicio} en {negocio} ({hora}).',
                    ],
                ],
                'magic_link_admin' => [
                    'label' => 'Acceso al panel (magic link)',
                    'hint' => 'Email con link de acceso para administradores. Usá HTML y {link}.',
                    'fields' => ['subject', 'body'],
                    'defaults' => [
                        'subject' => 'Tu acceso a {from_name}',
                        'body' => '<p>{logo}</p>'
                            . '<p>Hola,</p>'
                            . '<p>Hacé clic para ingresar a tu panel. El link vence en {ttl_minutes} minutos.</p>'
                            . '<p style="margin:1.2rem 0"><a href="{link}" '
                            . 'style="display:inline-block;background:#6d28d9;color:#fff;padding:.75rem 1.2rem;border-radius:8px;text-decoration:none;font-weight:600">Ingresar a Agendarte</a></p>'
                            . '<p style="color:#64748b;font-size:.9rem">Si no pediste este acceso, ignorá este email.</p>',
                    ],
                ],
                'magic_link_client' => [
                    'label' => 'Portal del cliente (magic link)',
                    'hint' => 'Email para que el cliente vea sus reservas. Usá HTML y {link}.',
                    'fields' => ['subject', 'body'],
                    'defaults' => [
                        'subject' => 'Acceso a tus reservas - {negocio}',
                        'body' => '<p>{logo}</p>'
                            . '<p>Hola,</p>'
                            . '<p>Usá este link para ver tus reservas en <strong>{negocio}</strong>.</p>'
                            . '<p style="margin:1.2rem 0"><a href="{link}" '
                            . 'style="display:inline-block;background:#6d28d9;color:#fff;padding:.75rem 1.2rem;border-radius:8px;text-decoration:none;font-weight:600">Ver mis reservas</a></p>',
                    ],
                ],
                'registration_welcome' => [
                    'label' => 'Bienvenida al registrarse',
                    'hint' => 'Email al crear una cuenta de comercio.',
                    'fields' => ['subject', 'body'],
                    'defaults' => [
                        'subject' => 'Bienvenido a Agendarte',
                        'body' => '<p>{logo}</p>'
                            . '<p>Hola {nombre},</p>'
                            . '<p>Creamos tu cuenta para <strong>{negocio}</strong>.</p>'
                            . '<p>Prueba gratis hasta <strong>{trial_end}</strong>.</p>',
                    ],
                ],
            ],
            'ultramsg' => [
                'appointment_confirmed_client' => [
                    'label' => 'Reserva confirmada (cliente)',
                    'hint' => 'WhatsApp al cliente tras confirmar la reserva.',
                    'fields' => ['body'],
                    'defaults' => [
                        'body' => "Hola {cliente}, tu reserva en {negocio} quedó confirmada.\nServicio: {servicio}\nFecha: {fecha}\nHora: {hora}",
                    ],
                ],
                'appointment_confirmed_owner' => [
                    'label' => 'Nueva reserva (dueño)',
                    'hint' => 'WhatsApp al dueño del negocio.',
                    'fields' => ['body'],
                    'defaults' => [
                        'body' => "Nueva reserva en {negocio}\nCliente: {cliente}\nCelular: {telefono}\nServicio: {servicio}\nFecha: {fecha}\nHora: {hora}",
                    ],
                ],
                'appointment_reminder_24h' => [
                    'label' => 'Recordatorio 24 h (cliente)',
                    'hint' => 'WhatsApp un día antes de la cita.',
                    'fields' => ['body'],
                    'defaults' => [
                        'body' => 'Recordatorio: mañana tienes {servicio} en {negocio} a las {hora}.',
                    ],
                ],
                'appointment_reminder_2h' => [
                    'label' => 'Recordatorio 2 h (cliente)',
                    'hint' => 'WhatsApp dos horas antes de la cita.',
                    'fields' => ['body'],
                    'defaults' => [
                        'body' => 'Recordatorio: en 2 horas tienes {servicio} en {negocio} ({hora}).',
                    ],
                ],
            ],
        ];
    }

    /** Placeholders disponibles en la UI del super admin. */
    public static function placeholderHelp(): string
    {
        return '{cliente}, {telefono}, {servicio}, {negocio}, {fecha}, {hora}, {link}, {nombre}, {trial_end}, {from_name}, {ttl_minutes}, {logo}, {cedula}';
    }

    /**
     * @return array<string,string>
     */
    public static function sampleVars(): array
    {
        if (is_array(self::$sampleVarsCache)) {
            return self::$sampleVarsCache;
        }
        $cfg = Database::getInstance()->config();
        self::$sampleVarsCache = [
            'cliente' => 'María García',
            'telefono' => '099 123 456',
            'cedula' => '12345678',
            'servicio' => 'Corte de pelo',
            'negocio' => 'Barbería Centro',
            'fecha' => date('Y-m-d', strtotime('+3 days')),
            'hora' => '10:30',
            'link' => url('demo/?client_token=ejemplo'),
            'nombre' => 'Juan',
            'trial_end' => date('Y-m-d', strtotime('+30 days')),
            'from_name' => (string)($cfg['mail']['from_name'] ?? 'Agendarte'),
            'ttl_minutes' => '20',
            'logo' => self::logoHtml(),
        ];
        return self::$sampleVarsCache;
    }

    /**
     * @return array{logo_url:string,show_logo_in_emails:bool}
     */
    public static function emailBranding(): array
    {
        $defaults = [
            'logo_url' => 'src/media/logo/logo-horizontal.png',
            'show_logo_in_emails' => true,
        ];
        try {
            $row = Database::getInstance()->fetchOne(
                'SELECT config_json FROM platform_settings WHERE section = :s LIMIT 1',
                [':s' => self::BRANDING_SECTION]
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
        return [
            'logo_url' => trim((string)($data['logo_url'] ?? $defaults['logo_url'])),
            'show_logo_in_emails' => !empty($data['show_logo_in_emails']),
        ];
    }

    /**
     * @param array{logo_url?:string,show_logo_in_emails?:bool} $data
     */
    public static function saveEmailBranding(array $data): void
    {
        $clean = [
            'logo_url' => trim((string)($data['logo_url'] ?? '')),
            'show_logo_in_emails' => !empty($data['show_logo_in_emails']),
        ];
        if ($clean['logo_url'] === '') {
            $clean['logo_url'] = 'src/media/logo/logo-horizontal.png';
        }
        self::persistSection(self::BRANDING_SECTION, json_encode($clean, JSON_UNESCAPED_UNICODE));
        self::$sampleVarsCache = null;
    }

    public static function logoHtml(): string
    {
        $branding = self::emailBranding();
        if (empty($branding['show_logo_in_emails'])) {
            return '';
        }
        $rel = ltrim(str_replace('\\', '/', trim((string)$branding['logo_url'])), '/');
        if ($rel === '') {
            return '';
        }
        $root = dirname(__DIR__, 2);
        $abs = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        if (!is_file($abs)) {
            return '';
        }
        $src = url($rel);
        return '<img src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '" alt="Agendarte" style="max-height:52px;width:auto;display:inline-block">';
    }

    /**
     * Vista previa de borrador (admin) o plantilla guardada.
     *
     * @return array<string,mixed>
     */
    public static function previewDraft(string $channel, string $templateKey, string $subject, string $body): array
    {
        $vars = self::sampleVars();
        if ($channel === 'email') {
            $subj = self::substituteVars($subject, $vars);
            $html = self::composeEmailHtml($body, $vars);
            return [
                'channel' => 'email',
                'subject' => $subj,
                'html' => $html,
                'plain' => strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $html)),
            ];
        }

        $text = self::substituteVars($body, $vars);
        return [
            'channel' => 'ultramsg',
            'text' => $text,
            'time' => date('H:i'),
        ];
    }

    /**
     * @return array<string, array<string, array<string, string>>>
     */
    public static function all(): array
    {
        $saved = self::loadRaw();
        $merged = [];
        foreach (self::catalog() as $channel => $templates) {
            $merged[$channel] = [];
            foreach ($templates as $key => $meta) {
                $defaults = (array)($meta['defaults'] ?? []);
                $custom = is_array($saved[$channel][$key] ?? null) ? $saved[$channel][$key] : [];
                $merged[$channel][$key] = [];
                foreach ($defaults as $field => $defaultValue) {
                    $value = trim((string)($custom[$field] ?? ''));
                    $merged[$channel][$key][$field] = $value !== '' ? $value : (string)$defaultValue;
                }
            }
        }
        return $merged;
    }

    /**
     * @param array<string,string> $vars
     */
    public static function render(string $channel, string $templateKey, array $vars, string $field, string $fallback = ''): string
    {
        $all = self::all();
        $text = trim((string)($all[$channel][$templateKey][$field] ?? ''));
        if ($text === '') {
            $catalog = self::catalog();
            $text = trim((string)($catalog[$channel][$templateKey]['defaults'][$field] ?? $fallback));
        }
        if ($text === '') {
            $text = $fallback;
        }
        return self::applyVars($text, $vars);
    }

    /**
     * @param array<string,string> $vars
     */
    public static function renderHtml(string $channel, string $templateKey, array $vars, string $fallback = ''): string
    {
        if (!isset($vars['logo'])) {
            $vars['logo'] = self::logoHtml();
        }
        $html = self::render($channel, $templateKey, $vars, 'body', $fallback);
        return self::composeEmailHtml($html, [], false);
    }

    /**
     * @param array<string,string> $vars
     */
    public static function composeEmailHtml(string $body, array $vars = [], bool $substitute = true): string
    {
        if ($substitute) {
            if (!isset($vars['logo'])) {
                $vars['logo'] = self::logoHtml();
            }
            $inner = self::substituteVars(trim($body), $vars);
        } else {
            $inner = trim($body);
        }
        if ($inner === '') {
            return '';
        }
        if (!str_contains($inner, '<')) {
            $inner = '<p style="margin:0 0 1rem;line-height:1.55">' . nl2br(htmlspecialchars($inner, ENT_QUOTES, 'UTF-8')) . '</p>';
        }
        if (preg_match('/<html[\s>]/i', $inner)) {
            return $inner;
        }
        return self::wrapEmailDocument($inner);
    }

    /**
     * @param array<string, array<string, array<string, string>>> $payload
     */
    public static function save(array $payload): void
    {
        $clean = [];
        foreach (self::catalog() as $channel => $templates) {
            $channelIn = is_array($payload[$channel] ?? null) ? $payload[$channel] : [];
            foreach ($templates as $key => $meta) {
                $fields = (array)($meta['fields'] ?? []);
                $defaults = (array)($meta['defaults'] ?? []);
                $incoming = is_array($channelIn[$key] ?? null) ? $channelIn[$key] : [];
                $entry = [];
                foreach ($fields as $field) {
                    $value = trim((string)($incoming[$field] ?? ''));
                    $default = trim((string)($defaults[$field] ?? ''));
                    if ($value !== '' && $value !== $default) {
                        $entry[$field] = $value;
                    }
                }
                if ($entry !== []) {
                    $clean[$channel][$key] = $entry;
                }
            }
        }

        $db = Database::getInstance();
        $json = json_encode($clean, JSON_UNESCAPED_UNICODE);
        self::persistSection(self::SECTION, $json);
    }

    private static function persistSection(string $section, string $json): void
    {
        $db = Database::getInstance();
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

    private static function wrapEmailDocument(string $innerHtml): string
    {
        return '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"></head><body style="margin:0;padding:0;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif;color:#1f2937">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6"><tr><td align="center" style="padding:28px 12px">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 2px 10px rgba(15,23,42,.08)">'
            . '<tr><td style="padding:28px 24px 24px;font-size:15px;line-height:1.55">' . $innerHtml . '</td></tr></table>'
            . '<p style="margin:16px 0 0;font-size:12px;color:#9ca3af">Agendarte UY · Vista previa</p>'
            . '</td></tr></table></body></html>';
    }

    /**
     * @return array<string, array<string, array<string, string>>>
     */
    private static function loadRaw(): array
    {
        try {
            $row = Database::getInstance()->fetchOne(
                'SELECT config_json FROM platform_settings WHERE section = :s LIMIT 1',
                [':s' => self::SECTION]
            );
        } catch (\Throwable) {
            return [];
        }
        if (!$row) {
            return [];
        }
        $data = json_decode((string)($row['config_json'] ?? ''), true);
        return is_array($data) ? $data : [];
    }

    /**
     * @param array<string,string> $vars
     */
    public static function substituteVars(string $text, array $vars): string
    {
        return self::applyVars($text, $vars);
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
}
