<?php
/**
 * src/API/Autoload.php
 * API universal para base array (database.php) con flock + escritura atómica.
 * - Uso programático (métodos estáticos)
 * - Uso HTTP (si se accede directamente)
 */

namespace App\API;

class Autoload
{
// ====== CONFIG (ajustada a tu estructura) ======
public const DB_PATH       = __DIR__ . '/../db/database.php';   // src/API -> src/db
public const LOCK_PATH     = __DIR__ . '/../db/database.lock';  // lock al lado de database.php
public const BACKUP_DIR    = __DIR__ . '/../db/backups';        // backups dentro de src/db
public const ENABLE_BACKUP = true;

    // Seguridad básica
    public const API_KEY     = '9f1c0a7f5c5b4a6b8d7e2a1fcd3e8b90d4a6c2e7f19a3b5c7d8e0f1a2b3c4d5e';

    /** @var string[]|'*' Tablas permitidas o '*' para todas */
    public static $ALLOWED_TABLES = ['rubros','info_negocios','admins','planes','suscripciones','mercado_pago'];
    // public static $ALLOWED_TABLES = '*';

    // ====== CORE ======

    public static function read(): array
    {
        if (!file_exists(self::DB_PATH)) {
            throw new \RuntimeException('database not found');
        }
        $db = include self::DB_PATH;
        if (!is_array($db)) {
            throw new \RuntimeException('database malformed');
        }
        return $db;
    }

    public static function write(array $db): bool
    {
        $db = self::applyAutoMigrations($db);

        // Lock dedicado
        $lfp = fopen(self::LOCK_PATH, 'c+');
        if (!$lfp) return false;
        if (!flock($lfp, LOCK_EX)) { fclose($lfp); return false; }

        try {
            $export = "<?php return " . var_export($db, true) . ";";
            $tmp    = self::DB_PATH . '.tmp';

            $fp = fopen($tmp, 'c+');
            if (!$fp) { flock($lfp, LOCK_UN); fclose($lfp); return false; }
            if (!flock($fp, LOCK_EX)) { fclose($fp); flock($lfp, LOCK_UN); fclose($lfp); return false; }

            ftruncate($fp, 0);
            fwrite($fp, $export);
            fflush($fp);
            flock($fp, LOCK_UN);
            fclose($fp);

            if (self::ENABLE_BACKUP && file_exists(self::DB_PATH)) {
                if (!is_dir(self::BACKUP_DIR)) @mkdir(self::BACKUP_DIR, 0775, true);
                @copy(self::DB_PATH, self::BACKUP_DIR . '/database_' . date('Ymd_His') . '.php');
            }

            $ok = @rename($tmp, self::DB_PATH);
            flock($lfp, LOCK_UN);
            fclose($lfp);
            return $ok;
        } catch (\Throwable $e) {
            flock($lfp, LOCK_UN);
            fclose($lfp);
            return false;
        }
    }

    private static function applyAutoMigrations(array $db): array
    {
        if (isset($db['rubros']) && is_array($db['rubros'])) {
            $db['rubros'] = self::ensureRubrosHaveUrls($db['rubros']);
        }
        if (isset($db['info_negocios']) && is_array($db['info_negocios'])) {
            $db['info_negocios'] = self::ensureInfoNegociosHaveUrls($db['info_negocios']);
        }
        return $db;
    }

    private static function ensureRubrosHaveUrls(array $rubros): array
    {
        $used = [];
        foreach ($rubros as $index => $row) {
            if (!is_array($row)) {
                $rubros[$index] = $row;
                continue;
            }
            $urlRaw = trim((string)($row['URL'] ?? ''));
            $slug = $urlRaw !== '' ? trim($urlRaw, '/') : '';
            if ($slug === '') {
                $name = (string)($row['Nombre'] ?? '');
                $type = (string)($row['Tipo'] ?? '');
                $fallback = 'rubro-' . ((string)($row['ID_Rubro'] ?? ($index + 1)));
                $slug = self::slugify($name !== '' ? $name : $type, $fallback);
            } else {
                $slug = self::slugify($slug, 'rubro-' . ((string)($row['ID_Rubro'] ?? ($index + 1))));
            }
            $uniqueSlug = $slug;
            $suffix = 2;
            while (in_array($uniqueSlug, $used, true)) {
                $uniqueSlug = $slug . '-' . $suffix;
                $suffix++;
            }
            $used[] = $uniqueSlug;
            $row['URL'] = '/' . $uniqueSlug;
            $rubros[$index] = $row;
        }
        return $rubros;
    }

    private static function ensureInfoNegociosHaveUrls(array $businesses): array
    {
        $used = [];
        foreach ($businesses as $index => $row) {
            if (!is_array($row)) {
                $businesses[$index] = $row;
                continue;
            }
            $urlRaw = trim((string)($row['URL'] ?? ''));
            $slug = $urlRaw !== '' ? trim($urlRaw, '/') : '';
            if ($slug === '') {
                $name = (string)($row['nombre'] ?? $row['Nombre'] ?? '');
                $fallback = 'negocio-' . ((string)($row['ID_Negocio'] ?? ($index + 1)));
                $slug = self::slugify($name !== '' ? $name : $fallback, $fallback);
            } else {
                $slug = self::slugify($slug, 'negocio-' . ((string)($row['ID_Negocio'] ?? ($index + 1))));
            }
            $uniqueSlug = $slug;
            $suffix = 2;
            while (in_array($uniqueSlug, $used, true)) {
                $uniqueSlug = $slug . '-' . $suffix;
                $suffix++;
            }
            $used[] = $uniqueSlug;
            $row['URL'] = '/' . $uniqueSlug;
            $businesses[$index] = $row;
        }
        return $businesses;
    }

    private static function slugify(string $value, string $fallback = 'rubro'): string
    {
        $value = trim($value);
        if ($value === '') {
            return $fallback;
        }
        $normalized = @iconv('UTF-8', 'ASCII//TRANSLIT', $value);
        if ($normalized === false) {
            $normalized = $value;
        }
        $slug = strtolower((string)preg_replace('/[^a-zA-Z0-9]+/', '-', $normalized));
        $slug = trim($slug, '-');
        return $slug !== '' ? $slug : $fallback;
    }

    public static function allowed(string $table): bool
    {
        if (self::$ALLOWED_TABLES === '*') return true;
        return in_array($table, (array)self::$ALLOWED_TABLES, true);
    }

    /** "tabla.k1.k2" => ['tabla',['k1','k2']] */
    public static function splitPath(string $path): array
    {
        $parts = array_values(array_filter(explode('.', $path), fn($p) => $p !== ''));
        if (!$parts) return [null, []];
        $table = array_shift($parts);
        return [$table, $parts];
    }

    /** Obtiene referencia a una clave anidada; crea intermedios si $createMissing */
    public static function &getRef(array &$root, array $parts, bool $createMissing = false)
    {
        $ref =& $root;
        foreach ($parts as $p) {
            if (!is_array($ref)) {
                $null = null;
                return $null;
            }
            if (!array_key_exists($p, $ref)) {
                if ($createMissing) {
                    $ref[$p] = [];
                } else {
                    $null = null;
                    return $null;
                }
            }
            $ref =& $ref[$p];
        }
        return $ref;
    }

    /** Merge profundo para patch */
    public static function mergeDeep(array $a, array $b): array
    {
        foreach ($b as $k => $v) {
            if (is_array($v) && isset($a[$k]) && is_array($a[$k])) {
                $a[$k] = self::mergeDeep($a[$k], $v);
            } else {
                $a[$k] = $v;
            }
        }
        return $a;
    }

    // ====== OPERACIONES PROGRAMÁTICAS ======

    /** Lista tablas (respetando whitelist) */
    public static function tables(): array
    {
        $db = self::read();
        $names = array_keys($db);
        if (self::$ALLOWED_TABLES !== '*') {
            $names = array_values(array_filter($names, fn($t) => self::allowed($t)));
        }
        return $names;
    }

    /** Lee tabla o subclave (path "tabla" o "tabla.k1.k2") */
    public static function get(string $path)
    {
        [$table, $sub] = self::splitPath($path);
        if (!$table) throw new \InvalidArgumentException('missing path');
        if (!self::allowed($table)) throw new \RuntimeException("table '$table' not allowed");

        $db = self::read();
        if (!array_key_exists($table, $db)) throw new \RuntimeException("table '$table' not found");

        if (!$sub) return $db[$table];
        $tmp = $db[$table];
        $ref =& self::getRef($tmp, $sub, false);
        if ($ref === null) throw new \RuntimeException('path not found');
        return $ref;
    }

    /** Reemplaza tabla o subclave (crea si no existe) */
    public static function set(string $path, $value): bool
    {
        [$table, $sub] = self::splitPath($path);
        if (!$table) throw new \InvalidArgumentException('missing path');
        if (!self::allowed($table)) throw new \RuntimeException("table '$table' not allowed");

        $db = self::read();

        if (!array_key_exists($table, $db) && $sub) {
            $db[$table] = [];
        }

        if (!$sub) {
            if (!is_array($value)) throw new \InvalidArgumentException('table value must be array/object');
            $db[$table] = $value;
        } else {
            $ref =& self::getRef($db[$table], $sub, true);
            $ref = $value;
        }

        return self::write($db);
    }

    /** Mezcla (patch) sobre tabla o subclave (crea si no existe) */
    public static function patch(string $path, array $patch): bool
    {
        [$table, $sub] = self::splitPath($path);
        if (!$table) throw new \InvalidArgumentException('missing path');
        if (!self::allowed($table)) throw new \RuntimeException("table '$table' not allowed");

        $db = self::read();

        if (!array_key_exists($table, $db)) {
            $db[$table] = $sub ? [] : [];
        }

        if (!$sub) {
            if (!is_array($db[$table])) $db[$table] = [];
            $db[$table] = self::mergeDeep($db[$table], $patch);
        } else {
            $ref =& self::getRef($db[$table], $sub, true);
            if (!is_array($ref)) $ref = [];
            $ref = self::mergeDeep($ref, $patch);
        }

        return self::write($db);
    }

    /** Elimina tabla completa o una subclave */
    public static function delete(string $path): bool
    {
        [$table, $sub] = self::splitPath($path);
        if (!$table) throw new \InvalidArgumentException('missing path');
        if (!self::allowed($table)) throw new \RuntimeException("table '$table' not allowed");

        $db = self::read();
        if (!array_key_exists($table, $db)) throw new \RuntimeException("table '$table' not found");

        if (!$sub) {
            unset($db[$table]);
        } else {
            $parent = $sub;
            $last = array_pop($parent);
            $pref =& self::getRef($db[$table], $parent, false);
            if ($pref === null || !is_array($pref) || !array_key_exists($last, $pref)) {
                throw new \RuntimeException('path not found');
            }
            unset($pref[$last]);
        }

        return self::write($db);
    }

    // ====== HTTP (opcional) ======

    public static function runHttp(): void
    {
        // CORS básico (opcional)
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Headers: Content-Type, X-API-Key');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            exit;
        }
        header('Access-Control-Allow-Origin: *');
        header('Content-Type: application/json; charset=utf-8');

        // Auth simple
        $key = $_GET['api_key'] ?? ($_SERVER['HTTP_X_API_KEY'] ?? '');
        if (!hash_equals(self::API_KEY, (string)$key)) {
            http_response_code(401);
            echo json_encode(['ok'=>false,'error'=>'unauthorized']); return;
        }

        $action = $_GET['action'] ?? $_POST['action'] ?? 'tables';
        $raw    = file_get_contents('php://input');
        $body   = json_decode($raw, true);
        if (!is_array($body)) $body = [];
        $path   = $_GET['path'] ?? $_POST['path'] ?? ($body['path'] ?? '');

        try {
            switch ($action) {
                case 'tables':
                case 'list':
                    $out = self::tables();
                    header('ETag: "'. md5(json_encode($out)) .'"');
                    echo json_encode(['ok'=>true,'tables'=>$out], JSON_UNESCAPED_UNICODE); return;

                case 'get':
                    if (!$path) throw new \InvalidArgumentException('missing path');
                    $val = self::get($path);
                    header('ETag: "'. md5(json_encode($val)) .'"');
                    echo json_encode(['ok'=>true,'path'=>$path,'value'=>$val], JSON_UNESCAPED_UNICODE); return;

                case 'set':
                    if (!$path) throw new \InvalidArgumentException('missing path');
                    $value = $body['value'] ?? ($_POST['value'] ?? null);
                    if ($value === null && isset($_POST['value'])) {
                        $tmp = json_decode((string)$_POST['value'], true);
                        if (json_last_error() === JSON_ERROR_NONE) $value = $tmp;
                    }
                    if ($value === null) throw new \InvalidArgumentException('missing value');
                    self::set($path, $value);
                    echo json_encode(['ok'=>true,'path'=>$path,'value'=>$value], JSON_UNESCAPED_UNICODE); return;

                case 'patch':
                    if (!$path) throw new \InvalidArgumentException('missing path');
                    $patch = $body['patch'] ?? ($_POST['patch'] ?? null);
                    if ($patch === null && isset($_POST['patch'])) {
                        $tmp = json_decode((string)$_POST['patch'], true);
                        if (json_last_error() === JSON_ERROR_NONE) $patch = $tmp;
                    }
                    if (!is_array($patch)) throw new \InvalidArgumentException('patch must be object');
                    self::patch($path, $patch);
                    echo json_encode(['ok'=>true,'path'=>$path,'patched'=>$patch], JSON_UNESCAPED_UNICODE); return;

                case 'delete':
                    if (!$path) throw new \InvalidArgumentException('missing path');
                    self::delete($path);
                    echo json_encode(['ok'=>true,'path'=>$path,'deleted'=>true], JSON_UNESCAPED_UNICODE); return;

                default:
                    http_response_code(400);
                    echo json_encode(['ok'=>false,'error'=>'unknown action']); return;
            }
        } catch (\Throwable $e) {
            http_response_code(400);
            echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
        }
    }
}

// Si se accede directamente por HTTP, ejecutar router:
if (php_sapi_name() !== 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    Autoload::runHttp();
}
