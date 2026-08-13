<?php
declare(strict_types=1);

namespace Agenduy\Core;

/**
 * Datos y presets para el sitio público central (sin carpeta tenant).
 */
final class CommercePublic
{
    /**
     * @return array{products:list<array<string,mixed>>,service_images:array<int,string>}
     */
    public static function loadLocalCatalog(int $idCommerce, string $slug): array
    {
        $products = [];
        $serviceImages = [];
        $path = self::resolveLocalDatabasePath($idCommerce, $slug);
        if ($path === null) {
            return ['products' => $products, 'service_images' => $serviceImages];
        }
        $localDb = @include $path;
        if (!is_array($localDb)) {
            return ['products' => $products, 'service_images' => $serviceImages];
        }
        foreach (($localDb['servicios'] ?? []) as $idx => $srvRow) {
            if ($idx === 0 || !is_array($srvRow)) {
                continue;
            }
            $sId = (int)($srvRow['ID_Servicio'] ?? 0);
            $sImg = trim((string)($srvRow['Img_Link'] ?? ''));
            if ($sId <= 0 || $sImg === '') {
                continue;
            }
            $serviceImages[$sId] = $sImg;
        }
        foreach (($localDb['productos'] ?? []) as $idx => $prodRow) {
            if ($idx === 0 || !is_array($prodRow)) {
                continue;
            }
            $pName = trim((string)($prodRow['Nombre'] ?? ''));
            $pId = $prodRow['ID_Product'] ?? null;
            if ($pName === '' || $pId === null || $pId === '') {
                continue;
            }
            $products[] = $prodRow;
        }
        return ['products' => $products, 'service_images' => $serviceImages];
    }

    public static function resolveLocalDatabasePath(int $idCommerce, string $slug): ?string
    {
        try {
            $central = CommercePanel::localDatabasePath($idCommerce);
            if (is_file($central)) {
                return $central;
            }
        } catch (\Throwable $e) {
            // Probar legacy.
        }

        $legacyCentral = CommerceStorage::legacyBaseDir($idCommerce) . DIRECTORY_SEPARATOR . 'database.php';
        if (is_file($legacyCentral)) {
            return $legacyCentral;
        }

        $slug = trim($slug, '/');
        if ($slug !== '' && preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug)) {
            $legacy = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . $slug
                . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'db'
                . DIRECTORY_SEPARATOR . 'database.php';
            if (is_file($legacy)) {
                return $legacy;
            }
        }
        return null;
    }

    public static function rubroCoverImage(int $idRubro, string $rubroNombre = ''): string
    {
        $name = self::normalizeLabel($rubroNombre);
        if ($name !== '') {
            if (str_contains($name, 'barber') || str_contains($name, 'peluqu')) {
                return 'src/media/carousel/barberias.jpg';
            }
            if (str_contains($name, 'estet') || str_contains($name, 'belleza')) {
                return 'src/media/carousel/clinicas_estetica.jpg';
            }
            if (str_contains($name, 'odont') || str_contains($name, 'dental') || str_contains($name, 'dent')) {
                return 'src/media/carousel/dentistas.jpg';
            }
            if (str_contains($name, 'evento') || str_contains($name, 'fiesta')) {
                return 'src/media/carousel/fiestas_eventos.jpg';
            }
        }

        $map = [
            9  => 'src/media/carousel/clinicas_estetica.jpg',
            10 => 'src/media/carousel/barberias.jpg',
            11 => 'src/media/carousel/dentistas.jpg',
        ];
        return $rubroNombre === '' && isset($map[$idRubro])
            ? $map[$idRubro]
            : 'src/media/logo/og-image.png';
    }

    /**
     * @param array<string,mixed> $scheduleRaw
     */
    public static function scheduleSummary(array $scheduleRaw): string
    {
        $open = 0;
        $weekendOpen = false;
        foreach (['lunes', 'martes', 'miercoles', 'jueves', 'viernes', 'sabado', 'domingo'] as $day) {
            $row = is_array($scheduleRaw[$day] ?? null) ? $scheduleRaw[$day] : [];
            if (empty($row['abierto'])) {
                continue;
            }
            $open++;
            if ($day === 'sabado' || $day === 'domingo') {
                $weekendOpen = true;
            }
        }
        if ($open <= 0) {
            return 'Consultá nuestros horarios de atención.';
        }
        if ($open === 7) {
            return 'Atendemos todos los días. Elegí el horario que más te convenga.';
        }
        if ($weekendOpen) {
            return "Abrimos {$open} días por semana, incluidos fines de semana.";
        }
        return "Abrimos {$open} días por semana. Consultá los horarios detallados abajo.";
    }

    /**
     * @return list<string>
     */
    public static function highlights(int $idRubro, string $businessType = 'servicios'): array
    {
        if ($businessType === 'tienda') {
            return [
                'Catálogo online de productos',
                'Pedidos y consultas por WhatsApp',
                'Entrega o retiro coordinado con la tienda',
            ];
        }

        $map = [
            9  => ['Tratamientos personalizados', 'Reservas online sin llamadas', 'Recordatorios por email y WhatsApp'],
            10 => ['Profesionales experimentados', 'Reservas online sin llamadas', 'Confirmación inmediata'],
            11 => ['Atención profesional', 'Turnos online las 24 h', 'Recordatorios automáticos'],
        ];
        return $map[$idRubro] ?? [
            'Atención con agenda organizada',
            'Reservas online sin llamadas',
            'Confirmación por email y WhatsApp',
        ];
    }

    private static function normalizeLabel(string $value): string
    {
        $label = function_exists('mb_strtolower') ? mb_strtolower(trim($value), 'UTF-8') : strtolower(trim($value));
        $converted = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $label) : false;
        if (is_string($converted) && $converted !== '') {
            $label = $converted;
        }
        return preg_replace('/[^a-z0-9]+/', ' ', $label) ?? $label;
    }
}
