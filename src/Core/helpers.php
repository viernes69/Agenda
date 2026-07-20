<?php
/**
 * Agenduy - Helpers de entorno
 *
 * Detectan automáticamente la URL base, el host, el esquema y los paths
 * del sistema. Se usan desde cualquier punto de la app.
 *
 *   url_base()    -> url_base() o 'http://localhost/agenduy.uy'
 *   base_path()    -> '/agenduy.uy' (sub-path si está en un subdir)
 *   url()         -> url_base() . path
 *   asset_url()   -> url_base() . asset
 *   current_slug() -> 'la-estetica' si la URL es /agenduy.uy/la-estetica/
 */

declare(strict_types=1);

if (!function_exists('agenduy_request')) {
    /**
     * Detecta datos del request actual.
     */
    function agenduy_request(): array
    {
        static $cached = null;
        if ($cached !== null) return $cached;

        // Scheme
        $scheme = 'http';
        if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
            $scheme = 'https';
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
            $scheme = strtolower(explode(',', (string)$_SERVER['HTTP_X_FORWARDED_PROTO'])[0]) === 'https' ? 'https' : 'http';
        } elseif (!empty($_SERVER['REQUEST_SCHEME'])) {
            $scheme = strtolower((string)$_SERVER['REQUEST_SCHEME']);
        } elseif (!empty($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) {
            $scheme = 'https';
        }

        // Host
        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
        // Quitar puerto si está en el host
        if (strpos($host, ':') !== false) {
            $parts = explode(':', $host);
            $host = $parts[0];
        }

        // Base path: directorio de la app (contiene index.php + carpeta admin/)
        $scriptFile = str_replace('\\', '/', (string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
        $basePath = '';

        $docRoot = rtrim(str_replace('\\', '/', (string)($_SERVER['DOCUMENT_ROOT'] ?? '')), '/');
        if ($scriptFile !== '' && $docRoot !== '' && str_starts_with($scriptFile, $docRoot)) {
            $dir = dirname($scriptFile);
            while (strlen($dir) >= strlen($docRoot)) {
                $indexFile = $dir . '/index.php';
                $adminDir = $dir . '/admin';
                if (is_file($indexFile) && is_dir($adminDir)) {
                    $rel = substr($dir, strlen($docRoot));
                    $rel = trim(str_replace('\\', '/', $rel), '/');
                    $basePath = $rel === '' ? '' : '/' . $rel;
                    break;
                }
                if ($dir === $docRoot) {
                    break;
                }
                $parent = dirname($dir);
                if ($parent === $dir) {
                    break;
                }
                $dir = $parent;
            }
        }

        // Fallback legacy: primer segmento del script (subdirectorio tipo /agenduy.uy/)
        $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
        if ($basePath === '' && $scriptName !== '') {
            $relative = ltrim(str_replace('\\', '/', dirname($scriptName)), '/');
            if ($relative !== '' && $relative !== '.') {
                $parts = explode('/', $relative);
                $internalDirs = ['admin', 'src', 'storage', 'bin', 'tests', 'auth', 'api'];
                while ($parts !== []) {
                    $last = strtolower((string)end($parts));
                    if (in_array($last, $internalDirs, true)) {
                        array_pop($parts);
                        continue;
                    }
                    break;
                }
                if ($parts !== []) {
                    $basePath = '/' . $parts[0];
                }
            }
        }

        // Override manual vía env
        $envBase = getenv('AGENDUY_BASE_PATH');
        if ($envBase !== false && $envBase !== '') {
            $basePath = $envBase;
        }

        $cached = [
            'scheme'   => $scheme,
            'host'     => $host,
            'port'     => $_SERVER['SERVER_PORT'] ?? null,
            'base_path'=> $basePath,
            'url_base' => $scheme . '://' . $host . $basePath,
            'is_https' => $scheme === 'https',
            'is_local' => in_array($host, ['localhost', '127.0.0.1', '::1'], true)
                            || strpos($host, 'localhost') !== false
                            || strpos($host, '.local') !== false
                            || strpos($host, '192.168.') === 0,
        ];
        return $cached;
    }
}

if (!function_exists('url_base')) {
    /**
     * URL base del sistema. Auto-detecta localhost vs producción.
     * Override con env: AGENDUY_URL_BASE
     */
    function url_base(): string
    {
        $env = getenv('AGENDUY_URL_BASE');
        if ($env !== false && $env !== '') {
            return rtrim($env, '/');
        }
        return agenduy_request()['url_base'];
    }
}

if (!function_exists('base_path')) {
    function base_path(): string
    {
        return agenduy_request()['base_path'];
    }
}

if (!function_exists('url')) {
    /**
     * Une url_base() con un path. Acepta paths con o sin slash inicial.
     * Si el path empieza con http:// o https://, se devuelve tal cual.
     */
    function url(string $path = ''): string
    {
        if ($path === '') return url_base();
        if (preg_match('#^https?://#i', $path)) return $path;
        $path = ltrim($path, '/');
        return url_base() . ($path !== '' ? '/' . $path : '');
    }
}

if (!function_exists('asset_url')) {
    /**
     * URL a un asset (css, js, img) relativo a la raíz.
     */
    function asset_url(string $path): string
    {
        return url($path);
    }
}

if (!function_exists('current_slug')) {
    /**
     * Detecta el slug del comercio desde la URL.
     * /agenduy.uy/la-estetica/admin/  -> 'la-estetica'
     * /agenduy.uy/                   -> null
     * /agenduy.uy/admin/             -> null
     */
    function current_slug(): ?string
    {
        $req = (string)($_SERVER['REQUEST_URI'] ?? '');
        if ($req === '') return null;
        $req = strtok($req, '?');
        $req = str_replace('\\', '/', (string)$req);
        $req = '/' . ltrim($req, '/');

        $bp = base_path();
        if ($bp !== '' && strpos($req, $bp) === 0) {
            $req = substr($req, strlen($bp));
        }
        $req = '/' . ltrim($req, '/');

        $parts = array_values(array_filter(explode('/', $req)));
        if (empty($parts)) return null;

        $first = strtolower($parts[0]);
        if (in_array($first, ['admin', 'src', 'storage', 'private', 'index.php', 'login.php', 'logout.php', 'api'], true)) {
            return null;
        }
        return $parts[0] ?: null;
    }
}

if (!function_exists('agenduy_env')) {
    function agenduy_env(): string
    {
        $env = getenv('AGENDUY_ENV');
        if ($env !== false && $env !== '') return $env;
        return agenduy_request()['is_local'] ? 'development' : 'production';
    }
}
