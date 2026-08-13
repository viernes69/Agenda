<?php
declare(strict_types=1);

namespace Agenduy\Core;

/**
 * Snapshot central de configuracion publica/privada del comercio.
 */
final class CommerceConfig
{
    /**
     * @param array<string,mixed> $legacy
     * @return array<string,mixed>
     */
    public static function infoForSlug(string $slug, array $legacy = []): array
    {
        $slug = trim($slug, '/');
        if ($slug === '') {
            return self::ensureThemes($legacy);
        }

        try {
            $commerce = Database::getInstance()->fetchOne(
                'SELECT * FROM commerces WHERE slug = :slug LIMIT 1',
                [':slug' => $slug]
            );
        } catch (\Throwable) {
            return self::ensureThemes($legacy);
        }

        if (!$commerce) {
            return self::ensureThemes($legacy);
        }

        $commerceId = (int)$commerce['id_commerce'];
        $info = array_replace_recursive($legacy, self::commerceInfo($commerce));

        foreach (self::sectionMap() as $section => $legacyKey) {
            $defaults = CommerceSettings::defaultsForSection($section);
            if (isset($info[$legacyKey]) && is_array($info[$legacyKey])) {
                $defaults = array_replace_recursive($defaults, $info[$legacyKey]);
            }
            $info[$legacyKey] = CommerceSettings::get($commerceId, $section, $defaults);
        }

        $mp = [];
        if (is_array($info['mercadopago'] ?? null)) {
            $mp = array_replace_recursive($mp, $info['mercadopago']);
        }
        if (is_array($info['mercado_pago'] ?? null)) {
            $mp = array_replace_recursive($mp, $info['mercado_pago']);
        }
        $mp = array_replace_recursive(
            $mp,
            CommerceSettings::get($commerceId, 'mercado_pago', CommerceSettings::defaultsForSection('mercado_pago')),
            self::mercadopagoPreviews($commerceId)
        );
        $info['mercado_pago'] = $mp;
        $info['mercadopago'] = $mp;

        return self::ensureThemes($info);
    }

    /**
     * @return array<string,string>
     */
    public static function sectionMap(): array
    {
        return [
            'horarios' => 'horarios',
            'reservas' => 'reservas',
            'moneda' => 'moneda',
            'fiscal' => 'fiscal',
            'redes' => 'redes',
            'seo' => 'seo',
            'legal' => 'legales',
            'notificaciones' => 'notificaciones',
            'funciones' => 'features',
            'carrito' => 'carrito',
            'tema' => 'temas',
        ];
    }

    /**
     * @param array<string,mixed> $commerce
     * @return array<string,mixed>
     */
    private static function commerceInfo(array $commerce): array
    {
        $rubroTipo = '';
        $rubroNombre = '';
        try {
            $rubro = Database::getInstance()->fetchOne(
                'SELECT tipo, nombre FROM rubros WHERE id_rubro = :id',
                [':id' => (int)$commerce['id_rubro']]
            );
            $rubroTipo = (string)($rubro['tipo'] ?? '');
            $rubroNombre = (string)($rubro['nombre'] ?? '');
        } catch (\Throwable) {
            // Mantener valores vacios.
        }

        return [
            'ID_Negocio' => (int)$commerce['id_commerce'],
            'ID_Rubro' => (int)$commerce['id_rubro'],
            'rubro' => $rubroTipo,
            'rubro_nombre' => $rubroNombre,
            'nombre' => (string)$commerce['nombre'],
            'email' => (string)$commerce['email'],
            'logo_src' => (string)$commerce['logo'],
            'slogan' => (string)$commerce['slogan'],
            'descripcion' => (string)$commerce['descripcion'],
            'razon_social' => (string)$commerce['razon_social'],
            'rut_ruc' => (string)$commerce['rut_ruc'],
            'contacto' => [
                'telefono' => (string)$commerce['telefono'],
                'whatsapp' => (string)$commerce['whatsapp'],
                'website' => (string)$commerce['website'],
                'email' => (string)$commerce['email'],
            ],
            'direccion' => [
                'pais' => (string)$commerce['pais'],
                'ciudad' => (string)$commerce['ciudad'],
                'calle' => (string)$commerce['calle'],
            ],
        ];
    }

    /**
     * @return array<string,string>
     */
    private static function mercadopagoPreviews(int $commerceId): array
    {
        $rows = Database::getInstance()->fetchAll(
            'SELECT key_name, key_preview FROM api_keys WHERE id_commerce = :c AND provider = :p AND is_active = 1',
            [':c' => $commerceId, ':p' => 'mercadopago']
        );
        $out = [];
        foreach ($rows as $row) {
            $name = (string)$row['key_name'];
            $out[$name] = (string)$row['key_preview'];
            if ($name === 'access_token') {
                $out['accessToken'] = (string)$row['key_preview'];
            }
            if ($name === 'public_key') {
                $out['publicKey'] = (string)$row['key_preview'];
            }
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $info
     * @return array<string,mixed>
     */
    private static function ensureThemes(array $info): array
    {
        if (!isset($info['temas']) || !is_array($info['temas'])) {
            $info['temas'] = ['publico' => 'oscuro', 'privado' => 'oscuro'];
            return $info;
        }
        if (!isset($info['temas']['publico']) || !in_array($info['temas']['publico'], ['oscuro', 'claro'], true)) {
            $info['temas']['publico'] = 'oscuro';
        }
        if (!isset($info['temas']['privado']) || !in_array($info['temas']['privado'], ['oscuro', 'claro'], true)) {
            $info['temas']['privado'] = 'oscuro';
        }
        return $info;
    }
}
