<?php
declare(strict_types=1);

namespace Agenduy\Core;

use InvalidArgumentException;
use RuntimeException;

/**
 * Onboarding post-registro (especialmente Google): completar datos del negocio.
 */
final class CommerceSetup
{
    private const PLACEHOLDER_PHONE = '099000000';
    private const PLACEHOLDER_STREET = 'Completar en el panel';

    public const DEFAULT_PLACEHOLDER_PHONE = '099000000';
    public const DEFAULT_PLACEHOLDER_STREET = 'Completar en el panel';

    public static function needsOnboarding(array $commerce): bool
    {
        $id = (int)($commerce['id_commerce'] ?? 0);
        if ($id <= 0) {
            return false;
        }

        $settings = CommerceSettings::get($id, 'onboarding', []);
        if (!empty($settings['completed'])) {
            return false;
        }

        if (!empty($settings['google_signup'])) {
            return true;
        }

        $calle = strtolower(trim((string)($commerce['calle'] ?? '')));
        $tel = trim((string)($commerce['telefono'] ?? ''));
        $nombre = strtolower(trim((string)($commerce['nombre'] ?? '')));

        if ($calle !== '' && str_contains($calle, 'completar')) {
            return true;
        }
        if ($tel === self::PLACEHOLDER_PHONE) {
            return true;
        }
        if (str_ends_with($nombre, ' - negocio')) {
            return true;
        }

        return false;
    }

    /**
     * Redirige al wizard central si el dueño entra al dashboard con datos incompletos.
     */
    public static function guardDashboardAccess(): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }

        Auth::start();
        if (!Auth::check() || Auth::role() !== Auth::ROLE_LOCAL) {
            return;
        }

        $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
        $isLegacyDashboard = preg_match('#/private/dashboard/(admin|empleado)/index\.php$#', $script);
        $isCentralCommerce = preg_match('#/admin/commerce_[^/]+\.php$#', $script);
        if (!$isLegacyDashboard && !$isCentralCommerce) {
            return;
        }

        $idCommerce = (int)Auth::commerceId();
        if ($idCommerce <= 0) {
            return;
        }

        $commerce = Database::getInstance()->fetchOne(
            'SELECT * FROM commerces WHERE id_commerce = :id LIMIT 1',
            [':id' => $idCommerce]
        );
        if (!$commerce || !self::needsOnboarding($commerce)) {
            return;
        }

        header('Location: ' . url('admin/commerce_setup.php'));
        exit;
    }

    /**
     * @return array<string,mixed>
     */
    public static function snapshot(int $idCommerce): array
    {
        $db = Database::getInstance();
        $commerce = $db->fetchOne(
            'SELECT c.*, r.nombre AS rubro_nombre
             FROM commerces c
             LEFT JOIN rubros r ON r.id_rubro = c.id_rubro
             WHERE c.id_commerce = :id LIMIT 1',
            [':id' => $idCommerce]
        );
        if (!$commerce) {
            throw new InvalidArgumentException('Comercio no encontrado.');
        }

        $service = $db->fetchOne(
            "SELECT id_service, nombre, duracion_min, precio FROM services
             WHERE id_commerce = :c AND estado = 'Activo'
             ORDER BY id_service ASC LIMIT 1",
            [':c' => $idCommerce]
        );

        $nombre = trim((string)($commerce['nombre'] ?? ''));
        if (str_ends_with(strtolower($nombre), ' - negocio')) {
            $nombre = trim(substr($nombre, 0, -strlen(' - Negocio')));
            if (str_ends_with(strtolower($nombre), ' - negocio')) {
                $nombre = preg_replace('/\s*-\s*negocio\s*$/iu', '', $nombre) ?? $nombre;
            }
        }

        return [
            'nombre'    => $nombre,
            'telefono'  => trim((string)($commerce['telefono'] ?? '')) === self::PLACEHOLDER_PHONE
                ? '' : (string)($commerce['telefono'] ?? ''),
            'whatsapp'  => trim((string)($commerce['whatsapp'] ?? '')) === self::PLACEHOLDER_PHONE
                ? '' : (string)($commerce['whatsapp'] ?? ''),
            'ciudad'    => (string)($commerce['ciudad'] ?? ''),
            'calle'     => str_contains(strtolower((string)($commerce['calle'] ?? '')), 'completar')
                ? '' : (string)($commerce['calle'] ?? ''),
            'rubro'     => (string)($commerce['rubro_nombre'] ?? ''),
            'servicio'  => (string)($service['nombre'] ?? 'Consulta'),
            'slug'      => (string)($commerce['slug'] ?? ''),
        ];
    }

    /**
     * @param array<string,mixed> $payload
     */
    public static function complete(int $idCommerce, array $payload): array
    {
        $nombre = trim((string)($payload['nombre'] ?? ''));
        $telefono = trim((string)($payload['telefono'] ?? ''));
        $whatsapp = trim((string)($payload['whatsapp'] ?? $telefono));
        $ciudad = trim((string)($payload['ciudad'] ?? ''));
        $calle = trim((string)($payload['calle'] ?? ''));
        $servicio = trim((string)($payload['servicio'] ?? ''));

        if ($nombre === '') {
            throw new InvalidArgumentException('Ingresá el nombre de tu negocio.');
        }
        if ($telefono === '' || strlen(preg_replace('/\D+/', '', $telefono) ?? '') < 8) {
            throw new InvalidArgumentException('Ingresá un teléfono válido.');
        }
        if ($ciudad === '') {
            throw new InvalidArgumentException('Ingresá la ciudad.');
        }
        if ($calle === '') {
            throw new InvalidArgumentException('Ingresá la dirección.');
        }

        $db = Database::getInstance();
        $commerce = $db->fetchOne(
            'SELECT * FROM commerces WHERE id_commerce = :id LIMIT 1',
            [':id' => $idCommerce]
        );
        if (!$commerce) {
            throw new InvalidArgumentException('Comercio no encontrado.');
        }

        $slug = trim((string)($commerce['slug'] ?? ''));
        $db->update('commerces', [
            'nombre'     => $nombre,
            'telefono'   => $telefono,
            'whatsapp'   => $whatsapp !== '' ? $whatsapp : $telefono,
            'ciudad'     => $ciudad,
            'calle'      => $calle,
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id_commerce = :id', [':id' => $idCommerce]);

        if ($servicio !== '') {
            $existing = $db->fetchOne(
                "SELECT id_service FROM services WHERE id_commerce = :c AND estado = 'Activo' ORDER BY id_service ASC LIMIT 1",
                [':c' => $idCommerce]
            );
            if ($existing) {
                $db->update('services', [
                    'nombre' => $servicio,
                ], 'id_service = :id', [':id' => (int)$existing['id_service']]);
            }
        }

        $redes = CommerceSettings::get($idCommerce, 'redes', CommerceSettings::defaultsForSection('redes'));
        $redes['whatsapp'] = $whatsapp !== '' ? $whatsapp : $telefono;
        CommerceSettings::set($idCommerce, 'redes', $redes);

        self::syncLegacyDatabase($slug, [
            'nombre'   => $nombre,
            'telefono' => $telefono,
            'whatsapp' => $whatsapp !== '' ? $whatsapp : $telefono,
            'ciudad'   => $ciudad,
            'calle'    => $calle,
            'servicio' => $servicio !== '' ? $servicio : 'Consulta',
        ]);

        CommerceSettings::set($idCommerce, 'onboarding', [
            'completed'    => true,
            'completed_at' => date('c'),
            'google_signup'=> true,
        ]);

        return [
            'ok'       => true,
            'redirect' => CommercePanel::dashboardUrlForSlug($slug, 'resumen'),
            'public'   => url($slug),
        ];
    }

    /**
     * @param array<string,string> $data
     */
    private static function syncLegacyDatabase(string $slug, array $data): void
    {
        if ($slug === '') {
            return;
        }

        $root = realpath(dirname(__DIR__, 2));
        if ($root === false) {
            return;
        }

        $dbPath = $root . DIRECTORY_SEPARATOR . $slug . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'db' . DIRECTORY_SEPARATOR . 'database.php';
        if (!is_file($dbPath)) {
            return;
        }

        $legacy = @include $dbPath;
        if (!is_array($legacy)) {
            return;
        }

        $info = is_array($legacy['info_barberia'] ?? null) ? $legacy['info_barberia'] : [];
        $info['nombre'] = $data['nombre'];
        $info['razon_social'] = $data['nombre'];
        if (!isset($info['contacto']) || !is_array($info['contacto'])) {
            $info['contacto'] = [];
        }
        $info['contacto']['telefono'] = $data['telefono'];
        $info['contacto']['whatsapp'] = $data['whatsapp'];
        if (!isset($info['direccion']) || !is_array($info['direccion'])) {
            $info['direccion'] = [];
        }
        $info['direccion']['ciudad'] = $data['ciudad'];
        $info['direccion']['region'] = $data['ciudad'];
        $info['direccion']['calle'] = $data['calle'];
        $legacy['info_barberia'] = $info;

        if (isset($legacy['servicios'][1]) && is_array($legacy['servicios'][1])) {
            $legacy['servicios'][1]['Nombre'] = $data['servicio'];
        }

        $export = "<?php return " . var_export($legacy, true) . ";";
        if (file_put_contents($dbPath, $export, LOCK_EX) === false) {
            throw new RuntimeException('No se pudo actualizar la configuración local del negocio.');
        }
    }

    public static function markGoogleSignup(int $idCommerce): void
    {
        if ($idCommerce <= 0) {
            return;
        }
        CommerceSettings::set($idCommerce, 'onboarding', [
            'completed'     => false,
            'google_signup' => true,
            'started_at'    => date('c'),
        ]);
    }
}
