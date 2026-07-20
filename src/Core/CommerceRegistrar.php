<?php
declare(strict_types=1);

namespace Agenduy\Core;

use InvalidArgumentException;
use RuntimeException;

/**
 * Alta unificada: SQLite + carpeta tenant + legacy mínimo para dashboard.
 */
final class CommerceRegistrar
{
    /**
     * @return array{ok:bool, slug:string, redirect:string, id_commerce:int}
     */
    public static function register(array $payload): array
    {
        $owner = is_array($payload['owner'] ?? null) ? $payload['owner'] : [];
        $business = is_array($payload['negocio'] ?? null) ? $payload['negocio'] : [];
        $schedule = is_array($payload['horarios'] ?? null) ? $payload['horarios'] : [];
        $services = is_array($payload['servicios'] ?? null) ? $payload['servicios'] : [];
        $planId = (int)($payload['planId'] ?? $payload['plan_id'] ?? 0);
        $rubroId = (int)($payload['rubroId'] ?? $business['rubroId'] ?? 0);

        $email = strtolower(trim((string)($owner['email'] ?? '')));
        $pass = (string)($owner['password'] ?? '');
        $name = trim((string)($owner['nombre'] ?? ''));
        $last = trim((string)($owner['apellido'] ?? ''));
        $cedula = trim((string)($owner['cedula'] ?? ''));
        $bizName = trim((string)($business['nombre'] ?? ''));
        $pais = strtoupper(trim((string)($business['pais'] ?? 'UY')));
        $ciudad = trim((string)($business['ciudad'] ?? ''));
        $calle = trim((string)($business['calle'] ?? ''));
        $tel = trim((string)($business['telefono'] ?? ''));
        $rut = trim((string)($business['rut'] ?? ''));
        $tz = trim((string)($schedule['timezone'] ?? 'America/Montevideo'));

        self::assert($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL), 'Email inválido.');
        self::assert(strlen($pass) >= 8, 'La contraseña debe tener al menos 8 caracteres.');
        self::assert($name !== '' && $last !== '' && $cedula !== '', 'Completa los datos del dueño.');
        self::assert($bizName !== '' && $ciudad !== '' && $calle !== '' && $tel !== '', 'Completa los datos del negocio.');
        self::assert($rubroId > 0, 'Selecciona un rubro válido.');
        self::assert(count($services) > 0, 'Agrega al menos un servicio.');

        $db = Database::getInstance();
        $exists = $db->fetchOne('SELECT id_user FROM users WHERE email = :e', [':e' => $email]);
        self::assert($exists === null, 'Ese email ya tiene una cuenta.');

        if ($planId <= 0) {
            $planId = (int)$db->fetchValue('SELECT id_membership FROM memberships WHERE activo = 1 ORDER BY precio ASC, id_membership ASC LIMIT 1');
        }
        $membership = $planId > 0
            ? $db->fetchOne('SELECT * FROM memberships WHERE id_membership = :id AND activo = 1', [':id' => $planId])
            : null;
        self::assert($membership !== null, 'Plan no disponible.');

        $trialDays = max(0, (int)($membership['trial_dias'] ?? 30));
        if ($trialDays <= 0 && (float)$membership['precio'] <= 0) {
            $trialDays = 30;
        }
        $trialEnd = date('Y-m-d', strtotime("+{$trialDays} days"));

        $rootPath = realpath(dirname(__DIR__, 2));
        self::assert($rootPath !== false, 'No se pudo resolver la ruta base.');
        $templateDir = $rootPath . DIRECTORY_SEPARATOR . 'template';
        self::assert(is_dir($templateDir), 'No se encontró la plantilla base.');

        $baseSlug = Keys::slug($bizName);
        $slug = $baseSlug;
        $i = 2;
        while ($db->fetchOne('SELECT id_commerce FROM commerces WHERE slug = :s', [':s' => $slug])) {
            $slug = $baseSlug . '-' . $i++;
        }
        $targetDir = $rootPath . DIRECTORY_SEPARATOR . $slug;

        $idCommerce = 0;
        $idUser = 0;
        $createdDir = null;

        try {
            $db->transaction(function () use (
                &$idCommerce, &$idUser, $db, $slug, $rubroId, $planId, $bizName, $rut, $email, $tel,
                $pais, $ciudad, $calle, $tz, $trialEnd, $trialDays, $name, $last, $cedula, $pass,
                $services, $schedule, $membership
            ) {
                $idCommerce = (int)$db->insert('commerces', [
                    'slug'             => $slug,
                    'id_rubro'         => $rubroId,
                    'id_membership'    => $planId,
                    'nombre'           => $bizName,
                    'razon_social'     => $bizName,
                    'rut_ruc'          => $rut,
                    'email'            => $email,
                    'telefono'         => $tel,
                    'whatsapp'         => $tel,
                    'pais'             => $pais,
                    'ciudad'           => $ciudad,
                    'calle'            => $calle,
                    'timezone'         => $tz,
                    'status'           => 'trial',
                    'trial_expires_at' => $trialEnd,
                    'serial'           => Keys::serial(),
                ]);

                $idUser = (int)$db->insert('users', [
                    'role'          => 'commerce_admin',
                    'id_commerce'   => $idCommerce,
                    'nombre'        => $name,
                    'apellido'      => $last,
                    'cedula'        => $cedula,
                    'email'         => $email,
                    'telefono'      => $tel,
                    'whatsapp'      => $tel,
                    'password_hash' => password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]),
                    'activo'        => 1,
                ]);

                $db->insert('subscriptions', [
                    'id_commerce'          => $idCommerce,
                    'id_membership'        => $planId,
                    'status'               => 'trial',
                    'gateway'              => (float)$membership['precio'] > 0 ? null : 'manual',
                    'started_at'           => date('Y-m-d'),
                    'trial_expires_at'     => $trialEnd,
                    'current_period_start' => date('Y-m-d'),
                    'current_period_end'   => $trialEnd,
                    'notes'                => 'Registro público',
                ]);

                foreach ($services as $svc) {
                    if (!is_array($svc)) continue;
                    $sname = trim((string)($svc['nombre'] ?? ''));
                    if ($sname === '') continue;
                    $db->insert('services', [
                        'id_commerce'  => $idCommerce,
                        'nombre'       => $sname,
                        'duracion_min' => max(15, (int)($svc['duracion'] ?? 30)),
                        'precio'       => (float)($svc['precio'] ?? 0),
                        'estado'       => 'Activo',
                    ]);
                }

                CommerceSettings::set($idCommerce, 'horarios', self::normalizeSchedule($schedule));
                CommerceSettings::set($idCommerce, 'moneda', CommerceSettings::defaultsForSection('moneda'));
                CommerceSettings::set($idCommerce, 'tema', ['publico' => 'claro', 'privado' => 'claro']);
            });

            if (!self::copyDirectory($templateDir, $targetDir)) {
                throw new RuntimeException('No se pudo copiar la carpeta de la plantilla.');
            }
            $createdDir = $targetDir;

            $databasePath = $targetDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'db' . DIRECTORY_SEPARATOR . 'database.php';
            $legacy = @include $databasePath;
            if (!is_array($legacy)) {
                throw new RuntimeException('Plantilla inválida: falta database.php');
            }

            $website = self::buildWebsiteUrl($slug);
            $passwordHash = password_hash($pass, PASSWORD_BCRYPT);
            $legacy = self::customiseLegacyDatabase(
                $legacy,
                ['nombre' => $name, 'apellido' => $last, 'cedula' => $cedula, 'email' => $email, 'password_hash' => $passwordHash, 'id_admin' => $idUser],
                ['nombre' => $bizName, 'rut' => $rut, 'pais' => $pais, 'ciudad' => $ciudad, 'calle' => $calle, 'rubro_id' => $rubroId, 'website' => $website, 'timezone' => $tz, 'id_negocio' => $idCommerce, 'telefono' => $tel],
                $schedule,
                $services,
                $rubroId
            );
            self::writeDatabase($databasePath, $legacy);

            if (session_status() !== PHP_SESSION_ACTIVE) {
                session_start();
            }
            $_SESSION['user'] = [
                'ID_Barber'   => $idUser,
                'Rol'         => 'Admin',
                'FechaInicio' => date('Y-m-d'),
                'HoraInicio'  => date('H:i:s'),
            ];

            Mail::send(
                $email,
                'Bienvenido a Agenduy',
                '<p>Hola ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . ',</p>'
                . '<p>Creamos tu cuenta para <strong>' . htmlspecialchars($bizName, ENT_QUOTES, 'UTF-8') . '</strong>.</p>'
                . '<p>Prueba gratis hasta <strong>' . htmlspecialchars($trialEnd, ENT_QUOTES, 'UTF-8') . '</strong>.</p>',
                null,
                $idCommerce
            );

            return [
                'ok'          => true,
                'slug'        => $slug,
                'redirect'    => self::buildRedirectUrl($slug),
                'id_commerce' => $idCommerce,
                'trial_expires_at' => $trialEnd,
            ];
        } catch (\Throwable $e) {
            if ($createdDir && is_dir($createdDir)) {
                self::deleteDirectory($createdDir);
            }
            if ($idCommerce > 0) {
                try {
                    $db->delete('commerces', 'id_commerce = :id', [':id' => $idCommerce]);
                } catch (\Throwable $ignored) {
                }
            }
            throw $e;
        }
    }

    private static function assert(bool $cond, string $msg): void
    {
        if (!$cond) {
            throw new InvalidArgumentException($msg);
        }
    }

    public static function normalizeSchedule(array $schedule): array
    {
        $defaults = CommerceSettings::defaultsForSection('horarios');
        $out = ['timezone' => trim((string)($schedule['timezone'] ?? $defaults['timezone']))];
        foreach (['lunes','martes','miercoles','jueves','viernes','sabado','domingo'] as $day) {
            $incoming = is_array($schedule[$day] ?? null) ? $schedule[$day] : [];
            $out[$day] = [
                'abierto' => !empty($incoming['abierto']),
                'inicio' => (string)($incoming['inicio'] ?? ''),
                'fin' => (string)($incoming['fin'] ?? ''),
                'descanso_inicio' => (string)($incoming['descanso_inicio'] ?? ''),
                'descanso_fin' => (string)($incoming['descanso_fin'] ?? ''),
            ];
        }
        $out['feriados'] = [];
        return $out;
    }

    private static function copyDirectory(string $source, string $destination): bool
    {
        if (!is_dir($source)) return false;
        if (!is_dir($destination) && !mkdir($destination, 0775, true) && !is_dir($destination)) {
            return false;
        }
        $items = scandir($source);
        if ($items === false) return false;
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $src = $source . DIRECTORY_SEPARATOR . $item;
            $dst = $destination . DIRECTORY_SEPARATOR . $item;
            if (is_dir($src)) {
                if (!self::copyDirectory($src, $dst)) return false;
            } elseif (!copy($src, $dst)) {
                return false;
            }
        }
        return true;
    }

    private static function deleteDirectory(string $directory): void
    {
        if (!is_dir($directory)) return;
        $items = scandir($directory);
        if ($items === false) return;
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $directory . DIRECTORY_SEPARATOR . $item;
            is_dir($path) ? self::deleteDirectory($path) : @unlink($path);
        }
        @rmdir($directory);
    }

    private static function writeDatabase(string $filePath, array $data): void
    {
        $export = "<?php return " . var_export($data, true) . ";";
        if (file_put_contents($filePath, $export, LOCK_EX) === false) {
            throw new RuntimeException('No se pudo escribir database.php del tenant.');
        }
    }

    public static function buildWebsiteUrl(string $slug): string
    {
        return url($slug);
    }

    public static function buildRedirectUrl(string $slug): string
    {
        return url($slug . '/private/dashboard/admin/');
    }

    private static function customiseLegacyDatabase(
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
        $info['horarios'] = self::normalizeSchedule($schedule);
        $descriptor = strtoupper(preg_replace('/[^A-Za-z0-9 ]+/', '', (string)$business['nombre']) ?? '');
        $descriptor = trim(preg_replace('/\s+/', ' ', $descriptor) ?? '');
        if ($descriptor === '') {
            $descriptor = 'TU NEGOCIO';
        }
        if (mb_strlen($descriptor) > 22) {
            $descriptor = rtrim(mb_substr($descriptor, 0, 22));
        }
        if (!isset($info['mercado_pago']) || !is_array($info['mercado_pago'])) {
            $info['mercado_pago'] = [];
        }
        $info['mercado_pago']['statement_descriptor'] = $descriptor;
        $database['info_barberia'] = $info;

        [$servicesTable] = self::buildServicesDataset($services);
        $database['servicios'] = $servicesTable;

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

    private static function buildServicesDataset(array $services): array
    {
        $records = [[
            'ID_Servicio' => null, 'Nombre' => null, 'Duracion' => null, 'Estado' => null,
            'Precio' => null, 'Puntos' => null, 'Img_Link' => null,
        ]];
        $nextId = 1;
        foreach ($services as $service) {
            if (!is_array($service)) continue;
            $name = trim((string)($service['nombre'] ?? ''));
            if ($name === '') continue;
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

    private static function rubroPreset(int $rubroId): array
    {
        $defaults = [
            'slogan' => 'Agenda online para tu negocio.',
            'descripcion' => 'Gestiona turnos, clientes y servicios con Agenduy.',
            'seo' => ['title' => 'Reservas online', 'description' => 'Reserva tu turno online.', 'keywords' => ['reservas','agenda']],
        ];
        $map = [
            9 => ['slogan' => 'Bienestar y belleza a tu medida.', 'descripcion' => 'Tratamientos y servicios de belleza.', 'seo' => ['title' => 'Belleza y estética', 'description' => 'Reserva tratamientos online.', 'keywords' => ['estetica','belleza']]],
            10 => ['slogan' => 'Tu corte perfecto comienza aquí.', 'descripcion' => 'Barbería y peluquería profesional.', 'seo' => ['title' => 'Barbería', 'description' => 'Reserva tu corte online.', 'keywords' => ['barberia','corte']]],
            11 => ['slogan' => 'Tu sonrisa en manos de expertos.', 'descripcion' => 'Atención odontológica integral.', 'seo' => ['title' => 'Odontología', 'description' => 'Reserva tu consulta dental.', 'keywords' => ['odontologia','dental']]],
        ];
        return $map[$rubroId] ?? $defaults;
    }
}
