<?php
declare(strict_types=1);

namespace Agenduy\Core;

/**
 * Lee/escribe configuración por comercio en commerce_settings.
 */
final class CommerceSettings
{
    public const SECTIONS = [
        'horarios', 'reservas', 'moneda', 'fiscal', 'redes',
        'seo', 'legal', 'notificaciones', 'funciones', 'tema', 'email_plantillas',
    ];

    public static function get(int $idCommerce, string $section, array $defaults = []): array
    {
        $db = Database::getInstance();
        $row = $db->fetchOne(
            'SELECT config_json FROM commerce_settings WHERE id_commerce = :c AND section = :s',
            [':c' => $idCommerce, ':s' => $section]
        );
        if (!$row) {
            return $defaults;
        }
        $data = json_decode((string)$row['config_json'], true);
        return is_array($data) ? array_replace_recursive($defaults, $data) : $defaults;
    }

    public static function set(int $idCommerce, string $section, array $data): array
    {
        $db = Database::getInstance();
        $now = date('Y-m-d H:i:s');
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        $existing = $db->fetchOne(
            'SELECT id_setting FROM commerce_settings WHERE id_commerce = :c AND section = :s',
            [':c' => $idCommerce, ':s' => $section]
        );
        if ($existing) {
            $db->update('commerce_settings', [
                'config_json' => $json,
                'updated_at'  => $now,
            ], 'id_setting = :id', [':id' => $existing['id_setting']]);
        } else {
            $db->insert('commerce_settings', [
                'id_commerce' => $idCommerce,
                'section'     => $section,
                'config_json' => $json,
                'updated_at'  => $now,
            ]);
        }
        return $data;
    }

    public static function merge(int $idCommerce, string $section, array $patch, array $defaults = []): array
    {
        $current = self::get($idCommerce, $section, $defaults);
        return self::set($idCommerce, $section, array_replace_recursive($current, $patch));
    }

    public static function defaultsForSection(string $section): array
    {
        return match ($section) {
            'horarios' => [
                'timezone' => 'America/Montevideo',
                'lunes' => ['abierto' => true, 'inicio' => '09:00', 'fin' => '18:00', 'descanso_inicio' => '', 'descanso_fin' => ''],
                'martes' => ['abierto' => true, 'inicio' => '09:00', 'fin' => '18:00', 'descanso_inicio' => '', 'descanso_fin' => ''],
                'miercoles' => ['abierto' => true, 'inicio' => '09:00', 'fin' => '18:00', 'descanso_inicio' => '', 'descanso_fin' => ''],
                'jueves' => ['abierto' => true, 'inicio' => '09:00', 'fin' => '18:00', 'descanso_inicio' => '', 'descanso_fin' => ''],
                'viernes' => ['abierto' => true, 'inicio' => '09:00', 'fin' => '18:00', 'descanso_inicio' => '', 'descanso_fin' => ''],
                'sabado' => ['abierto' => false, 'inicio' => '', 'fin' => '', 'descanso_inicio' => '', 'descanso_fin' => ''],
                'domingo' => ['abierto' => false, 'inicio' => '', 'fin' => '', 'descanso_inicio' => '', 'descanso_fin' => ''],
                'feriados' => [],
            ],
            'reservas' => [
                'anticipacion_min_horas' => 2,
                'max_dias_adelante' => 60,
                'cancelacion_min_horas' => 24,
                'requiere_login' => false,
            ],
            'moneda' => ['codigo' => 'UYU', 'simbolo' => '$', 'decimales' => 0],
            'seo' => ['title' => '', 'description' => '', 'keywords' => [], 'canonical' => '', 'robots' => 'index,follow', 'og_image' => ''],
            'legal' => ['terminos' => '', 'privacidad' => '', 'cookies' => ''],
            'redes' => ['instagram' => '', 'facebook' => '', 'tiktok' => '', 'whatsapp' => ''],
            'notificaciones' => [
                'whatsapp_enabled' => true,
                'email_enabled' => true,
                'owner_email' => '',
            ],
            'email_plantillas' => [
                'appointment_confirmed_client' => [
                    'subject' => 'Reserva confirmada - {negocio}',
                    'body' => "Hola {cliente}, tu reserva en {negocio} quedó confirmada.\nServicio: {servicio}\nFecha: {fecha}\nHora: {hora}",
                ],
                'appointment_confirmed_owner' => [
                    'subject' => 'Nueva reserva - {cliente}',
                    'body' => "Nueva reserva en {negocio}\nCliente: {cliente}\nCelular: {telefono}\nServicio: {servicio}\nFecha: {fecha}\nHora: {hora}",
                ],
            ],
            'funciones' => ['productos' => true, 'servicios' => true, 'barberos' => true],
            'tema' => ['publico' => 'claro', 'privado' => 'claro'],
            default => [],
        };
    }

    public static function bySlug(string $slug, string $section, array $defaults = []): array
    {
        $db = Database::getInstance();
        $commerce = $db->fetchOne('SELECT id_commerce FROM commerces WHERE slug = :s', [':s' => $slug]);
        if (!$commerce) {
            return $defaults;
        }
        return self::get((int)$commerce['id_commerce'], $section, $defaults ?: self::defaultsForSection($section));
    }
}
