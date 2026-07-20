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
                        'body' => "Hola {cliente}, tu reserva en {negocio} quedó confirmada.\nServicio: {servicio}\nFecha: {fecha}\nHora: {hora}",
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
                        'body' => '<p>Hola,</p>'
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
                        'body' => '<p>Hola,</p>'
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
                        'body' => '<p>Hola {nombre},</p>'
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
        return '{cliente}, {telefono}, {servicio}, {negocio}, {fecha}, {hora}, {link}, {nombre}, {trial_end}, {from_name}, {ttl_minutes}';
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
        $html = self::render($channel, $templateKey, $vars, 'body', $fallback);
        if ($html === '' || str_contains($html, '<')) {
            return $html;
        }
        return '<p>' . nl2br(htmlspecialchars($html, ENT_QUOTES, 'UTF-8')) . '</p>';
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
        $existing = $db->fetchOne(
            'SELECT id_setting FROM platform_settings WHERE section = :s LIMIT 1',
            [':s' => self::SECTION]
        );
        if ($existing) {
            $db->update('platform_settings', [
                'config_json' => $json,
                'updated_at'  => date('Y-m-d H:i:s'),
            ], 'id_setting = :id', [':id' => (int)$existing['id_setting']]);
        } else {
            $db->insert('platform_settings', [
                'section'     => self::SECTION,
                'config_json' => $json,
            ]);
        }
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
    private static function applyVars(string $text, array $vars): string
    {
        $replacements = [];
        foreach ($vars as $key => $value) {
            $replacements['{' . $key . '}'] = (string)$value;
        }
        return strtr($text, $replacements);
    }
}
