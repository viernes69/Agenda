<?php
declare(strict_types=1);

use Agenduy\Core\Auth;
use Agenduy\Core\CSRF;
use Agenduy\Core\CommerceSettings;
use Agenduy\Core\CommerceStorage;
use Agenduy\Core\Database;

require_once dirname(__DIR__) . '/Core/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

try {
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $action = strtolower(trim((string)($_REQUEST['action'] ?? '')));
    if ($method === 'GET' && in_array($action, ['state', 'version'], true)) {
        $slug = trim((string)($_GET['slug'] ?? ''), '/');
        $commerce = commerceByPublicSlug($slug);
        $commerceId = (int)$commerce['id_commerce'];
        $content = CommerceSettings::get($commerceId, 'public_content', CommerceSettings::defaultsForSection('public_content'));
        respond(publicContentState($commerceId, (string)$commerce['slug'], $content, $action === 'state'));
    }

    Auth::start();
    if (!Auth::check() || (Auth::role() !== Auth::ROLE_LOCAL && Auth::role() !== 'super_admin')) {
        respond(['ok' => false, 'error' => 'Sesion de administrador requerida.'], 401);
    }

    if ($method !== 'POST') {
        respond(['ok' => false, 'error' => 'Metodo no permitido.'], 405);
    }

    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['_csrf'] ?? null;
    if (!CSRF::validate(is_string($token) ? $token : null, 'public_site_edit', false)) {
        respond(['ok' => false, 'error' => 'Token invalido o expirado.'], 419);
    }

    $slug = trim((string)($_POST['slug'] ?? $_GET['slug'] ?? ''), '/');
    if ($slug === '') {
        $json = jsonPayload();
        $slug = trim((string)($json['slug'] ?? ''), '/');
    }
    $commerce = commerceForOwner($slug);
    $commerceId = (int)$commerce['id_commerce'];

    $content = CommerceSettings::get($commerceId, 'public_content', CommerceSettings::defaultsForSection('public_content'));
    if (!is_array($content['text'] ?? null)) {
        $content['text'] = [];
    }
    if (!is_array($content['images'] ?? null)) {
        $content['images'] = [];
    }
    if (!is_array($content['hidden'] ?? null)) {
        $content['hidden'] = [];
    }
    if (!is_array($content['custom'] ?? null)) {
        $content['custom'] = [];
    }

    $action = strtolower(trim((string)($_POST['action'] ?? '')));
    if ($action === '') {
        $json = jsonPayload();
        $action = strtolower(trim((string)($json['action'] ?? '')));
    }

    if ($action === 'save_text') {
        $json = jsonPayload();
        $key = normalizeKey((string)($json['key'] ?? $_POST['key'] ?? ''));
        $value = normalizeText((string)($json['value'] ?? $_POST['value'] ?? ''));
        $content['text'][$key] = $value;
        touchPublicContent($content, 'text');
        CommerceSettings::set($commerceId, 'public_content', $content);
        respond([
            'ok' => true,
            'type' => 'text',
            'key' => $key,
            'value' => $value,
            'version' => (string)($content['version'] ?? ''),
        ]);
    }

    if ($action === 'save_image') {
        $key = normalizeKey((string)($_POST['key'] ?? ''));
        if (!isset($_FILES['image']) || !is_array($_FILES['image'])) {
            throw new InvalidArgumentException('Falta la imagen.');
        }
        $stored = saveImage($commerceId, $key, $_FILES['image']);
        $content['images'][$key] = $stored;
        touchPublicContent($content, 'image');
        CommerceSettings::set($commerceId, 'public_content', $content);
        $url = CommerceStorage::publicUrl($commerceId, (string)$commerce['slug'], $stored);
        respond([
            'ok' => true,
            'type' => 'image',
            'key' => $key,
            'path' => $stored,
            'url' => appendRevision($url, (string)($content['version'] ?? '')),
            'version' => (string)($content['version'] ?? ''),
        ]);
    }

    if ($action === 'toggle_visibility') {
        $json = jsonPayload();
        $key = normalizeKey((string)($json['key'] ?? $_POST['key'] ?? ''));
        if ($key === '') {
            throw new InvalidArgumentException('Falta la clave del elemento.');
        }
        $currentHidden = !empty($content['hidden'][$key]);
        $nextHidden = !$currentHidden;
        if ($nextHidden) {
            $content['hidden'][$key] = true;
        } else {
            unset($content['hidden'][$key]);
        }
        touchPublicContent($content, 'visibility');
        CommerceSettings::set($commerceId, 'public_content', $content);
        respond([
            'ok' => true,
            'type' => 'visibility',
            'key' => $key,
            'hidden' => $nextHidden,
            'version' => (string)($content['version'] ?? ''),
        ]);
    }

    if ($action === 'add_element') {
        $json = jsonPayload();
        $section = normalizeKey((string)($json['section'] ?? $_POST['section'] ?? 'general'));
        $type = strtolower(trim((string)($json['type'] ?? $_POST['type'] ?? 'filter')));
        $contentVal = trim((string)($json['content'] ?? $_POST['content'] ?? ''));
        $metaVal = trim((string)($json['meta'] ?? $_POST['meta'] ?? ''));

        if ($contentVal === '') {
            throw new InvalidArgumentException('El contenido del elemento es obligatorio.');
        }

        if (!isset($content['custom'][$section]) || !is_array($content['custom'][$section])) {
            $content['custom'][$section] = [];
        }

        $elementId = 'elem_' . substr(str_replace('.', '', (string)microtime(true)), -6);
        $newElement = [
            'id' => $elementId,
            'type' => $type, // 'filter', 'title', 'subtitle', 'button', 'stat'
            'content' => $contentVal,
            'meta' => $metaVal,
            'created_at' => date('Y-m-d H:i:s'),
        ];
        $content['custom'][$section][] = $newElement;
        touchPublicContent($content, 'custom');
        CommerceSettings::set($commerceId, 'public_content', $content);
        respond([
            'ok' => true,
            'type' => 'add_element',
            'section' => $section,
            'element' => $newElement,
            'version' => (string)($content['version'] ?? ''),
        ]);
    }

    if ($action === 'delete_element') {
        $json = jsonPayload();
        $section = normalizeKey((string)($json['section'] ?? $_POST['section'] ?? ''));
        $elementId = trim((string)($json['id'] ?? $_POST['id'] ?? ''));

        if (isset($content['custom'][$section]) && is_array($content['custom'][$section])) {
            $content['custom'][$section] = array_values(array_filter(
                $content['custom'][$section],
                static fn($el): bool => is_array($el) && ($el['id'] ?? '') !== $elementId
            ));
            touchPublicContent($content, 'custom');
            CommerceSettings::set($commerceId, 'public_content', $content);
        }
        respond([
            'ok' => true,
            'type' => 'delete_element',
            'section' => $section,
            'id' => $elementId,
            'version' => (string)($content['version'] ?? ''),
        ]);
    }

    throw new InvalidArgumentException('Accion no soportada.');
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], $e instanceof InvalidArgumentException ? 400 : 500);
}

/**
 * @return array<string,mixed>
 */
function jsonPayload(): array
{
    static $payload = null;
    if ($payload !== null) {
        return $payload;
    }
    $raw = (string)file_get_contents('php://input');
    $decoded = $raw !== '' ? json_decode($raw, true) : [];
    $payload = is_array($decoded) ? $decoded : [];
    return $payload;
}

/**
 * @return array<string,mixed>
 */
function commerceForOwner(string $slug): array
{
    $isSuperAdmin = Auth::check() && Auth::role() === 'super_admin';
    if ($isSuperAdmin) {
        if ($slug === '') {
            throw new InvalidArgumentException('Falta el slug del comercio.');
        }
        return commerceByPublicSlug($slug);
    }

    $commerceId = (int)Auth::commerceId();
    if ($commerceId <= 0) {
        throw new RuntimeException('Cuenta sin comercio asignado.');
    }
    $commerce = Database::getInstance()->fetchOne(
        'SELECT * FROM commerces WHERE id_commerce = :id LIMIT 1',
        [':id' => $commerceId]
    );
    if (!$commerce) {
        throw new RuntimeException('Comercio no encontrado.');
    }
    $ownedSlug = trim((string)($commerce['slug'] ?? ''), '/');
    if ($slug !== '' && $ownedSlug !== '' && !hash_equals($ownedSlug, $slug)) {
        throw new RuntimeException('No autorizado para este comercio.');
    }
    return $commerce;
}

/**
 * @return array<string,mixed>
 */
function commerceByPublicSlug(string $slug): array
{
    if ($slug === '') {
        throw new InvalidArgumentException('Falta el comercio.');
    }
    $commerce = Database::getInstance()->fetchOne(
        'SELECT * FROM commerces WHERE slug = :s LIMIT 1',
        [':s' => $slug]
    );
    if (!$commerce) {
        throw new InvalidArgumentException('Comercio no encontrado.');
    }
    return $commerce;
}

function normalizeKey(string $key): string
{
    $key = strtolower(trim($key));
    if (!preg_match('/^[a-z0-9][a-z0-9_.-]{1,90}$/', $key)) {
        throw new InvalidArgumentException('Campo invalido.');
    }
    return $key;
}

function normalizeText(string $value): string
{
    $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    if (mb_strlen($value, 'UTF-8') > 700) {
        throw new InvalidArgumentException('El texto es demasiado largo.');
    }
    return $value;
}

/**
 * @param array<string,mixed> $file
 */
function saveImage(int $commerceId, string $key, array $file): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('No se pudo subir la imagen.');
    }
    $maxBytes = 5 * 1024 * 1024;
    if ((int)($file['size'] ?? 0) <= 0 || (int)($file['size'] ?? 0) > $maxBytes) {
        throw new InvalidArgumentException('La imagen debe pesar menos de 5 MB.');
    }
    $tmpPath = (string)($file['tmp_name'] ?? '');
    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
        throw new InvalidArgumentException('Archivo no valido.');
    }
    $info = @getimagesize($tmpPath);
    if (!$info) {
        throw new InvalidArgumentException('El archivo no es una imagen valida.');
    }
    $type = (int)($info[2] ?? 0);
    $ext = '';
    if ($type === IMAGETYPE_JPEG) {
        $ext = 'jpg';
    } elseif ($type === IMAGETYPE_PNG) {
        $ext = 'png';
    } elseif ($type === IMAGETYPE_GIF) {
        $ext = 'gif';
    } elseif (defined('IMAGETYPE_WEBP') && $type === IMAGETYPE_WEBP) {
        $ext = 'webp';
    }
    if ($ext === '') {
        throw new InvalidArgumentException('Formato no soportado. Usa JPG, PNG, GIF o WebP.');
    }

    $dir = CommerceStorage::kindDir($commerceId, 'site');
    $safeKey = preg_replace('/[^a-z0-9_-]+/', '-', str_replace('.', '-', $key)) ?: 'image';
    $filename = trim($safeKey, '-') . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
    $dest = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file($tmpPath, $dest)) {
        throw new RuntimeException('No se pudo guardar la imagen.');
    }
    @chmod($dest, 0644);
    return CommerceStorage::relativePath($commerceId, 'site', $filename);
}

function touchPublicContent(array &$content, string $type): void
{
    $content['version'] = bin2hex(random_bytes(8));
    $content['updated_at'] = date(DATE_ATOM);
    $content['latest_type'] = $type === 'image' ? 'image' : 'text';
}

/**
 * @return array<string,mixed>
 */
function publicContentState(int $commerceId, string $slug, array $content, bool $includeContent): array
{
    $version = publicContentVersion($content);
    $payload = [
        'ok' => true,
        'version' => $version,
        'latest_type' => (string)($content['latest_type'] ?? ''),
        'updated_at' => (string)($content['updated_at'] ?? ''),
    ];
    if (!$includeContent) {
        return $payload;
    }

    $texts = is_array($content['text'] ?? null) ? $content['text'] : [];
    $images = is_array($content['images'] ?? null) ? $content['images'] : [];
    $imageUrls = [];
    foreach ($images as $key => $path) {
        if (!is_scalar($path)) {
            continue;
        }
        $url = CommerceStorage::publicUrl($commerceId, $slug, (string)$path);
        if ($url !== '') {
            $imageUrls[(string)$key] = appendRevision($url, $version);
        }
    }
    $payload['text'] = $texts;
    $payload['images'] = $imageUrls;
    return $payload;
}

function publicContentVersion(array $content): string
{
    $stored = trim((string)($content['version'] ?? ''));
    if ($stored !== '') {
        return $stored;
    }
    return sha1(json_encode([
        'text' => $content['text'] ?? [],
        'images' => $content['images'] ?? [],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
}

function appendRevision(string $url, string $version): string
{
    if ($url === '' || $version === '') {
        return $url;
    }
    return $url . (str_contains($url, '?') ? '&' : '?') . 'rev=' . rawurlencode($version);
}

function respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
