<?php
declare(strict_types=1);

namespace Agenduy\Core;

/**
 * Datos locales del panel central (database.php en storage) sin carpeta tenant.
 */
final class CentralCommerceData
{
    /**
     * @param array<string,mixed> $owner
     * @param array<string,mixed> $business
     * @param array<string,mixed> $schedule
     * @param list<array<string,mixed>> $services
     */
    public static function provision(
        int $idCommerce,
        int $idUser,
        array $owner,
        array $business,
        array $schedule,
        array $services,
        int $rubroId
    ): void {
        if (TenantConfig::useLegacyFolders()) {
            return;
        }

        $db = Database::getInstance();
        $commerce = $db->fetchOne('SELECT * FROM commerces WHERE id_commerce = :id', [':id' => $idCommerce]);
        if (!$commerce) {
            return;
        }

        $slug = trim((string)($commerce['slug'] ?? ''));
        $preset = self::rubroPreset($rubroId);
        $website = CommerceRegistrar::buildWebsiteUrl($slug);
        $passwordHash = password_hash(bin2hex(random_bytes(8)), PASSWORD_BCRYPT);

        $templatePath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'template'
            . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'db' . DIRECTORY_SEPARATOR . 'database.php';
        $legacy = is_file($templatePath) ? @include $templatePath : null;
        if (!is_array($legacy)) {
            $legacy = ['info_barberia' => [], 'servicios' => [], 'barberos' => [], 'clientes' => [], 'productos' => [], 'reservas' => [], 'carrito' => []];
        }

        $database = self::buildDatabaseSnapshot(
            $legacy,
            [
                'nombre' => (string)($owner['nombre'] ?? ''),
                'apellido' => (string)($owner['apellido'] ?? ''),
                'cedula' => (string)($owner['cedula'] ?? ''),
                'email' => (string)($owner['email'] ?? $commerce['email'] ?? ''),
                'password_hash' => $passwordHash,
                'id_admin' => $idUser,
            ],
            [
                'nombre' => (string)($business['nombre'] ?? $commerce['nombre'] ?? ''),
                'rut' => (string)($business['rut'] ?? ''),
                'pais' => (string)($business['pais'] ?? $commerce['pais'] ?? 'UY'),
                'ciudad' => (string)($business['ciudad'] ?? $commerce['ciudad'] ?? ''),
                'calle' => (string)($business['calle'] ?? $commerce['calle'] ?? ''),
                'rubro_id' => $rubroId,
                'website' => $website,
                'timezone' => (string)($schedule['timezone'] ?? $commerce['timezone'] ?? 'America/Montevideo'),
                'id_negocio' => $idCommerce,
                'telefono' => (string)($business['telefono'] ?? $commerce['telefono'] ?? ''),
            ],
            $schedule,
            $services,
            $rubroId
        );

        self::writeDatabase($idCommerce, $database);

        $ownerEmail = strtolower(trim((string)($owner['email'] ?? $commerce['email'] ?? '')));
        $db->update('commerces', [
            'slogan' => $preset['slogan'],
            'descripcion' => $preset['descripcion'],
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id_commerce = :id', [':id' => $idCommerce]);

        CommerceSettings::set($idCommerce, 'seo', array_replace_recursive(
            CommerceSettings::defaultsForSection('seo'),
            $preset['seo'] ?? []
        ));
        CommerceSettings::set($idCommerce, 'notificaciones', array_replace_recursive(
            CommerceSettings::defaultsForSection('notificaciones'),
            [
                'email_enabled' => true,
                'owner_email' => $ownerEmail,
                'whatsapp_enabled' => true,
            ]
        ));
        CommerceSettings::set($idCommerce, 'email_plantillas', CommerceSettings::defaultsForSection('email_plantillas'));
    }

    /**
     * @param array<string,mixed> $database
     */
    public static function writeDatabase(int $idCommerce, array $database): void
    {
        $path = CommercePanel::localDatabasePath($idCommerce);
        $export = "<?php return " . var_export($database, true) . ";";
        if (file_put_contents($path, $export, LOCK_EX) === false) {
            throw new \RuntimeException('No se pudo escribir la base local central del comercio.');
        }
    }

    /**
     * @param array<string,mixed> $database
     * @param array<string,mixed> $owner
     * @param array<string,mixed> $business
     * @param array<string,mixed> $schedule
     * @param list<array<string,mixed>> $services
     * @return array<string,mixed>
     */
    private static function buildDatabaseSnapshot(
        array $database,
        array $owner,
        array $business,
        array $schedule,
        array $services,
        int $rubroId
    ): array {
        $preset = self::rubroPreset($rubroId);
        $adminId = (int)($owner['id_admin'] ?? 1);
        $passwordHash = (string)($owner['password_hash'] ?? '');

        $info = is_array($database['info_barberia'] ?? null) ? $database['info_barberia'] : [];
        $info['ID_Negocio'] = (int)($business['id_negocio'] ?? 1);
        $info['ID_Rubro'] = (int)$business['rubro_id'];
        $rubroRow = Database::getInstance()->fetchOne(
            'SELECT tipo, nombre FROM rubros WHERE id_rubro = :id',
            [':id' => (int)$business['rubro_id']]
        );
        $info['rubro'] = (string)($rubroRow['tipo'] ?? '');
        $info['rubro_nombre'] = (string)($rubroRow['nombre'] ?? '');
        $info['nombre'] = (string)$business['nombre'];
        $info['razon_social'] = (string)$business['nombre'];
        $info['ID_Admin'] = $adminId;
        $info['email'] = (string)$owner['email'];
        $info['slogan'] = $preset['slogan'];
        $info['descripcion'] = $preset['descripcion'];
        $info['contacto'] = [
            'website' => (string)$business['website'],
            'telefono' => (string)$business['telefono'],
            'whatsapp' => (string)$business['telefono'],
            'email' => (string)$owner['email'],
        ];
        $info['direccion'] = [
            'pais' => (string)$business['pais'],
            'region' => (string)$business['ciudad'],
            'ciudad' => (string)$business['ciudad'],
            'calle' => (string)$business['calle'],
        ];
        $info['seo'] = $preset['seo'];
        $info['horarios'] = CommerceRegistrar::normalizeSchedule($schedule);
        $info['notificaciones'] = [
            'email_enabled' => true,
            'owner_email' => (string)$owner['email'],
            'whatsapp' => ['enabled' => false, 'number' => '', 'provider' => 'meta'],
        ];
        $info['temas'] = ['publico' => 'claro', 'privado' => 'claro'];
        $database['info_barberia'] = $info;
        $database['servicios'] = self::buildServicesDataset($services)[0];
        $database['barberos'] = [
            [
                'ID_Barber' => null, 'Nombre' => null, 'Apellido' => null, 'Cedula' => null,
                'Psw' => null, 'Disponibilidad' => null, 'Habilidades' => null, 'Rol' => null,
                'Perfil' => null, 'Comision' => null, 'Status' => null, 'DiasTrabajo' => null,
            ],
            [
                'ID_Barber' => $adminId,
                'Nombre' => $owner['nombre'],
                'Apellido' => $owner['apellido'],
                'Cedula' => $owner['cedula'],
                'Psw' => $passwordHash,
                'Disponibilidad' => 'Disponible',
                'Habilidades' => '',
                'Rol' => 'Admin',
                'Perfil' => '',
                'Comision' => null,
                'Status' => 'Online',
                'DiasTrabajo' => '',
            ],
        ];
        return $database;
    }

    /**
     * @param list<array<string,mixed>> $services
     * @return array{0: list<array<string,mixed>>}
     */
    private static function buildServicesDataset(array $services): array
    {
        $records = [[
            'ID_Servicio' => null, 'Nombre' => null, 'Duracion' => null, 'Estado' => null,
            'Precio' => null, 'Puntos' => null, 'Img_Link' => null,
        ]];
        $nextId = 1;
        foreach ($services as $service) {
            if (!is_array($service)) {
                continue;
            }
            $name = trim((string)($service['nombre'] ?? ''));
            if ($name === '') {
                continue;
            }
            $records[$nextId] = [
                'ID_Servicio' => $nextId,
                'Nombre' => $name,
                'Duracion' => max(15, (int)($service['duracion'] ?? 30)),
                'Estado' => 'Activo',
                'Precio' => (float)($service['precio'] ?? 0),
                'Puntos' => null,
                'Img_Link' => '',
            ];
            $nextId++;
        }
        if ($nextId === 1) {
            $records[1] = ['ID_Servicio' => 1, 'Nombre' => 'Servicio', 'Duracion' => 30, 'Estado' => 'Activo', 'Precio' => 0.0, 'Puntos' => null, 'Img_Link' => ''];
        }
        return [$records];
    }

    /**
     * @return array{slogan:string,descripcion:string,seo:array<string,mixed>}
     */
    private static function rubroPreset(int $rubroId): array
    {
        $defaults = [
            'slogan' => 'Agenda online para tu negocio.',
            'descripcion' => 'Gestiona turnos, clientes y servicios con Agendarte UY.',
            'seo' => ['title' => 'Reservas online', 'description' => 'Reserva tu turno online.', 'keywords' => ['reservas', 'agenda']],
        ];
        $map = [
            9 => ['slogan' => 'Bienestar y belleza a tu medida.', 'descripcion' => 'Tratamientos y servicios de belleza.', 'seo' => ['title' => 'Belleza y estética', 'description' => 'Reserva tratamientos online.', 'keywords' => ['estetica', 'belleza']]],
            10 => ['slogan' => 'Tu corte perfecto comienza aquí.', 'descripcion' => 'Barbería y peluquería profesional.', 'seo' => ['title' => 'Barbería', 'description' => 'Reserva tu corte online.', 'keywords' => ['barberia', 'corte']]],
            11 => ['slogan' => 'Tu sonrisa en manos de expertos.', 'descripcion' => 'Atención odontológica integral.', 'seo' => ['title' => 'Odontología', 'description' => 'Reserva tu consulta dental.', 'keywords' => ['odontologia', 'dental']]],
        ];
        return $map[$rubroId] ?? $defaults;
    }
}
