<?php
declare(strict_types=1);

namespace Agenduy\Core;

/**
 * Assets de comercio centralizados en src/media/commerce/{id}/ (servibles por HTTP).
 */
final class CommerceStorage
{
    public const WEB_PREFIX = 'src/media/commerce';

    public static function baseDir(int $idCommerce): string
    {
        $root = dirname(__DIR__, 2);
        return $root . DIRECTORY_SEPARATOR . self::WEB_PREFIX . DIRECTORY_SEPARATOR . (string)$idCommerce;
    }

    public static function kindDir(int $idCommerce, string $kind): string
    {
        $dir = self::baseDir($idCommerce) . DIRECTORY_SEPARATOR . $kind;
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('No se pudo crear el directorio de assets del comercio.');
        }
        return $dir;
    }

    public static function relativePath(int $idCommerce, string $kind, string $filename): string
    {
        $filename = ltrim(str_replace('\\', '/', $filename), '/');
        return self::WEB_PREFIX . '/' . $idCommerce . '/' . $kind . '/' . $filename;
    }

    public static function isCentralPath(string $path): bool
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');
        return str_starts_with($path, self::WEB_PREFIX . '/');
    }

    /**
     * Resuelve ruta relativa almacenada en DB a path absoluto en disco.
     */
    public static function absolutePath(int $idCommerce, string $slug, string $storedPath): ?string
    {
        $storedPath = ltrim(str_replace('\\', '/', trim($storedPath)), '/');
        if ($storedPath === '') {
            return null;
        }

        $root = dirname(__DIR__, 2);

        if (self::isCentralPath($storedPath)) {
            $full = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $storedPath);
            return is_file($full) ? $full : null;
        }

        if ($slug !== '') {
            $legacy = $root . DIRECTORY_SEPARATOR . $slug . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $storedPath);
            if (is_file($legacy)) {
                return $legacy;
            }
        }

        return null;
    }

    /**
     * URL pública del asset o cadena vacía si no existe.
     */
    public static function publicUrl(int $idCommerce, string $slug, string $storedPath): string
    {
        $storedPath = ltrim(str_replace('\\', '/', trim($storedPath)), '/');
        if ($storedPath === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $storedPath)) {
            return $storedPath;
        }

        if (self::isCentralPath($storedPath)) {
            $root = dirname(__DIR__, 2);
            $full = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $storedPath);
            return is_file($full) ? url($storedPath) : '';
        }

        if ($slug !== '') {
            $root = dirname(__DIR__, 2);
            $full = $root . DIRECTORY_SEPARATOR . $slug . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $storedPath);
            if (is_file($full)) {
                return url($slug . '/' . $storedPath);
            }
        }

        return '';
    }

    /**
     * @return array{copied:int, updated:int, errors:list<string>}
     */
    public static function migrateFromTenantFolder(int $idCommerce, string $slug): array
    {
        $stats = ['copied' => 0, 'updated' => 0, 'errors' => []];
        $slug = trim($slug);
        if ($idCommerce <= 0 || $slug === '') {
            $stats['errors'][] = 'id_commerce o slug inválido';
            return $stats;
        }

        $root = dirname(__DIR__, 2);
        $tenantDir = $root . DIRECTORY_SEPARATOR . $slug;
        if (!is_dir($tenantDir)) {
            return $stats;
        }

        $db = Database::getInstance();
        $commerce = $db->fetchOne('SELECT logo FROM commerces WHERE id_commerce = :id', [':id' => $idCommerce]);
        if (!$commerce) {
            $stats['errors'][] = 'Comercio no encontrado';
            return $stats;
        }

        $logoCandidates = ['src/img/logo.jpg', 'src/img/logo.png', 'src/img/logo.webp'];
        foreach ($logoCandidates as $rel) {
            $src = $tenantDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
            if (!is_file($src)) {
                continue;
            }
            $destRel = self::relativePath($idCommerce, 'logo', 'logo.jpg');
            $dest = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $destRel);
            $destDir = dirname($dest);
            if (!is_dir($destDir)) {
                mkdir($destDir, 0775, true);
            }
            if (@copy($src, $dest)) {
                $db->update('commerces', [
                    'logo' => $destRel,
                    'updated_at' => date('Y-m-d H:i:s'),
                ], 'id_commerce = :id', [':id' => $idCommerce]);
                $stats['copied']++;
                $stats['updated']++;
            }
            break;
        }

        $kinds = [
            'services' => ['src/img/services', 'services'],
            'products' => ['src/img/products', 'products'],
            'barbers'  => ['src/img/barbers', 'barbers'],
        ];

        foreach ($kinds as $kind => [$legacySub, $centralKind]) {
            $legacyDir = $tenantDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $legacySub);
            if (!is_dir($legacyDir)) {
                continue;
            }
            foreach (scandir($legacyDir) ?: [] as $file) {
                if ($file === '.' || $file === '..') {
                    continue;
                }
                $src = $legacyDir . DIRECTORY_SEPARATOR . $file;
                if (!is_file($src)) {
                    continue;
                }
                $destRel = self::relativePath($idCommerce, $centralKind, $file);
                $dest = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $destRel);
                $destDir = dirname($dest);
                if (!is_dir($destDir)) {
                    mkdir($destDir, 0775, true);
                }
                if (@copy($src, $dest)) {
                    $stats['copied']++;
                }
            }
        }

        $localDbPath = $tenantDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'db' . DIRECTORY_SEPARATOR . 'database.php';
        if (is_file($localDbPath)) {
            $legacy = @include $localDbPath;
            if (is_array($legacy)) {
                foreach (($legacy['servicios'] ?? []) as $idx => $row) {
                    if ($idx === 0 || !is_array($row)) {
                        continue;
                    }
                    $idLocal = (int)($row['ID_Servicio'] ?? 0);
                    $img = trim((string)($row['Img_Link'] ?? ''));
                    if ($idLocal <= 0 || $img === '' || self::isCentralPath($img)) {
                        continue;
                    }
                    $basename = basename(str_replace('\\', '/', $img));
                    $centralRel = self::relativePath($idCommerce, 'services', $basename);
                    $centralAbs = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $centralRel);
                    if (!is_file($centralAbs)) {
                        continue;
                    }
                    $svc = $db->fetchOne(
                        'SELECT id_service FROM services WHERE id_commerce = :c AND id_local = :l LIMIT 1',
                        [':c' => $idCommerce, ':l' => $idLocal]
                    );
                    if ($svc) {
                        $db->update('services', ['imagen' => $centralRel], 'id_service = :id', [':id' => (int)$svc['id_service']]);
                        $stats['updated']++;
                    }
                }
            }
        }

        return $stats;
    }

    /**
     * @return list<array{id_commerce:int, slug:string, has_central:bool, has_legacy_folder:bool}>
     */
    public static function auditAll(): array
    {
        $db = Database::getInstance();
        $rows = [];
        foreach ($db->fetchAll('SELECT id_commerce, slug FROM commerces ORDER BY slug') as $c) {
            $slug = (string)$c['slug'];
            if (TenantConfig::isIgnoredTenantSlug($slug)) {
                continue;
            }
            $id = (int)$c['id_commerce'];
            $centralDir = self::baseDir($id);
            $legacyDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . $slug;
            $rows[] = [
                'id_commerce'        => $id,
                'slug'               => $slug,
                'has_central'        => is_dir($centralDir) && (bool)glob($centralDir . DIRECTORY_SEPARATOR . '*'),
                'has_legacy_folder'  => is_dir($legacyDir) && is_file($legacyDir . '/src/db/database.php'),
            ];
        }
        return $rows;
    }
}
