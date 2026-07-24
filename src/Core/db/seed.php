<?php
/**
 * Agenduy - Seed inicial
 * Se ejecuta una sola vez (o cuando se llame explícitamente)
 * desde /admin/install.php. Idempotente: si los registros ya existen,
 * no los duplica.
 */

declare(strict_types=1);

namespace Agenduy\Core\db;

use Agenduy\Core\Auth;
use Agenduy\Core\Database;
use Agenduy\Core\Keys;

final class Seed
{
    public static function run(?string $superAdminEmail = null, ?string $superAdminPassword = null): array
    {
        $db = Database::getInstance();
        $results = [];

        // 1) Rubros base
        $rubros = [
            ['nombre' => 'Barbería',         'tipo' => 'barberia',     'descripcion' => 'Servicio de peluquería y barbería', 'imagen' => 'src/media/carousel/barberias.jpg'],
            ['nombre' => 'Abogacía',         'tipo' => 'abogados',     'descripcion' => 'Servicios legales y asesoramiento', 'imagen' => 'src/media/carousel/abogados.jpg'],
            ['nombre' => 'Belleza y estética', 'tipo' => 'belleza',    'descripcion' => 'Salones y spas', 'imagen' => 'src/media/carousel/clinicas_estetica.jpg'],
            ['nombre' => 'Clínica de Estética', 'tipo' => 'estetica',  'descripcion' => 'Servicios de belleza y cuidado personal', 'imagen' => 'src/media/carousel/clinicas_estetica.jpg'],
            ['nombre' => 'Consultorios',     'tipo' => 'consultorios', 'descripcion' => 'Servicios médicos y de salud', 'imagen' => 'src/media/carousel/consultorios.jpg'],
            ['nombre' => 'Odontología',      'tipo' => 'odontologia',  'descripcion' => 'Consultorios dentales', 'imagen' => 'src/media/carousel/dentistas.jpg'],
            ['nombre' => 'Dentistas',        'tipo' => 'dentistas',    'descripcion' => 'Servicios odontológicos y cuidado dental', 'imagen' => 'src/media/carousel/dentistas.jpg'],
            ['nombre' => 'Locales de Eventos','tipo' => 'eventos',     'descripcion' => 'Espacios para eventos y celebraciones', 'imagen' => 'src/media/carousel/fiestas_eventos.jpg'],
            ['nombre' => 'Lavaderos',        'tipo' => 'lavaderos',    'descripcion' => 'Servicios de lavado y limpieza de vehículos', 'imagen' => 'src/media/carousel/lavaderos.jpg'],
            ['nombre' => 'Profesores Particulares', 'tipo' => 'profesores', 'descripcion' => 'Clases y tutorías personalizadas', 'imagen' => 'src/media/carousel/profesionales.jpg'],
            ['nombre' => 'Coaching',         'tipo' => 'coaches',      'descripcion' => 'Coaching personal y profesional', 'imagen' => 'src/media/carousel/coaches.jpg'],
            ['nombre' => 'Emprendedores',    'tipo' => 'emprendedores','descripcion' => 'Asesoría para emprendedores', 'imagen' => 'src/media/carousel/emprendedores.jpg'],
            ['nombre' => 'Tienda',           'tipo' => 'tienda',       'descripcion' => 'Tiendas y retail con agenda de atención', 'imagen' => 'src/media/carousel/emprendedores.jpg'],
            ['nombre' => 'Comercio',         'tipo' => 'comercio',     'descripcion' => 'Comercios locales y marketplace', 'imagen' => 'src/media/carousel/emprendedores.jpg'],
        ];
        $ordenSeed = 10;
        foreach ($rubros as $r) {
            $exists = $db->fetchOne(
                'SELECT id_rubro, nombre, descripcion, imagen, activo, orden FROM rubros WHERE tipo = :t',
                [':t' => $r['tipo']]
            );
            if (!$exists) {
                $r['activo'] = 1;
                $r['orden']  = $ordenSeed;
                $db->insert('rubros', $r);
                $ordenSeed += 10;
                continue;
            }
            $ordenSeed += 10;
            // Backfill de seeds viejos/parciales.
            $updates = [];
            if (trim((string)($exists['nombre'] ?? '')) === '') {
                $updates['nombre'] = $r['nombre'];
            }
            if (trim((string)($exists['descripcion'] ?? '')) === '') {
                $updates['descripcion'] = $r['descripcion'];
            }
            if (trim((string)($exists['imagen'] ?? '')) === '') {
                $updates['imagen'] = $r['imagen'];
            }
            if ((int)($exists['orden'] ?? 0) === 0) {
                $updates['orden'] = $ordenSeed - 10;
            }
            if ((int)($exists['activo'] ?? 0) !== 1) {
                $updates['activo'] = 1;
            }
            if ($updates !== []) {
                $db->update('rubros', $updates, 'id_rubro = :id', [
                    ':id' => (int)$exists['id_rubro'],
                ]);
            }
        }
        $results['rubros'] = count($rubros);

        // 2) Catálogo de membresías (Free / Básico / Profesional)
        $prices = ['Free' => 0.0, 'Básico' => 299.0, 'Profesional' => 599.0];
        $trials = ['Free' => 30, 'Básico' => 0, 'Profesional' => 0];
        $catalogDefaults = \Agenduy\Core\MembershipPlan::catalogDefaults();
        $seeded = 0;
        foreach ($catalogDefaults as $nombre => $def) {
            $existsPlan = $db->fetchOne('SELECT id_membership FROM memberships WHERE nombre = :n', [':n' => $nombre]);
            if ($existsPlan) {
                continue;
            }
            $db->insert('memberships', [
                'nombre' => $nombre,
                'descripcion' => (string)($def['descripcion'] ?? ''),
                'precio' => (float)($prices[$nombre] ?? 0),
                'moneda' => 'UYU',
                'duracion_dias' => 30,
                'trial_dias' => (int)($trials[$nombre] ?? 0),
                'activo' => 1,
                'features' => json_encode($def['features'], JSON_UNESCAPED_UNICODE),
                'limits' => json_encode($def['limits'], JSON_UNESCAPED_UNICODE),
                'anual_habilitado' => (int)$def['anual_habilitado'],
                'descuento_anual_pct' => (float)$def['descuento_anual_pct'],
            ]);
            $seeded++;
        }
        // Retirar legado del seed viejo ($800 "Plan Básico") si ya existe el catálogo moderno
        $db->pdo()->exec(
            "UPDATE memberships SET activo = 0, updated_at = datetime('now')
             WHERE activo = 1 AND nombre = 'Plan Básico' AND ABS(precio - 800) < 0.01
               AND EXISTS (SELECT 1 FROM memberships m2 WHERE m2.activo = 1 AND m2.nombre IN ('Free','Básico','Profesional'))"
        );
        $results['memberships'] = $seeded;

        // 3) Super admin inicial
        $email = $superAdminEmail ?: 'admin@agenduy.uy';
        $pwd   = $superAdminPassword ?: 'Agenduy2026!';
        $existsUser = $db->fetchOne('SELECT id_user FROM users WHERE email = :e', [':e' => $email]);
        if (!$existsUser) {
            $db->insert('users', [
                'role'          => 'super_admin',
                'id_commerce'   => null,
                'nombre'        => 'Lucas',
                'apellido'      => 'Admin',
                'cedula'        => '',
                'email'         => $email,
                'telefono'      => '',
                'whatsapp'      => '',
                'password_hash' => password_hash($pwd, PASSWORD_BCRYPT, ['cost' => 12]),
                'activo'        => 1,
            ]);
            $results['super_admin'] = ['email' => $email, 'password' => $pwd];
        } else {
            $results['super_admin'] = ['email' => $email, 'password' => '(ya existía — no se cambió)'];
        }

        // 4) Payment provider config defaults
        $defaultConfigs = [
            'mercadopago' => [
                'is_enabled' => 1,
                'config_json' => json_encode(['public_key' => '', 'access_token' => '', 'sandbox' => true], JSON_UNESCAPED_UNICODE),
            ],
            'paypal' => [
                'is_enabled' => 1,
                'config_json' => json_encode(['client_id' => '', 'secret' => '', 'sandbox' => true], JSON_UNESCAPED_UNICODE),
            ],
            'transfer' => [
                'is_enabled' => 1,
                'config_json' => json_encode([
                    'banco' => '',
                    'titular' => '',
                    'cuenta' => '',
                    'moneda' => 'UYU',
                    'instrucciones' => 'Subí el comprobante para que validemos tu pago.',
                ], JSON_UNESCAPED_UNICODE),
            ],
        ];
        foreach ($defaultConfigs as $provider => $cfg) {
            $exists = $db->fetchOne('SELECT id_config FROM payment_provider_config WHERE provider = :p', [':p' => $provider]);
            if (!$exists) {
                $db->insert('payment_provider_config', [
                    'provider'    => $provider,
                    'is_enabled'  => $cfg['is_enabled'],
                    'config_json' => $cfg['config_json'],
                ]);
            }
        }
        $results['payment_providers'] = count($defaultConfigs);

        return $results;
    }
}
