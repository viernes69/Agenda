<?php

use Agenduy\Core\CommerceSettings;
use Agenduy\Core\CommerceConfig;
use Agenduy\Core\CommerceStorage;
use Agenduy\Core\Crypto;
use Agenduy\Core\Database;
use Agenduy\Core\Auth;
use Agenduy\Core\MembershipPlan;
use Agenduy\Core\MercadoPago;

$tenantRoot = dirname(__DIR__, 2);
$projectRoot = dirname($tenantRoot);
require_once $projectRoot . '/src/Core/bootstrap.php';
Auth::start();
require_once __DIR__ . '/Autoload.php';

function adminConfigTenantSlug(): string
{
    global $tenantRoot;
    return \Agenduy\Core\CommercePanel::resolveEffectiveSlug((string)$tenantRoot);
}

header('Content-Type: application/json; charset=utf-8');

$raw = (string)file_get_contents('php://input');
$json = $raw !== '' ? json_decode($raw, true) : null;
if (is_array($json)) {
    $_REQUEST = array_merge($_REQUEST, $json);
}
try {
    assertAdminRequest();
    $action = (string)($_REQUEST['action'] ?? 'config_get');
    $key = (string)($_REQUEST['key'] ?? '');

    if ($action === 'config_get') {
        requireConfigKey($key);
        respond(['ok' => true, 'data' => readConfig($key, adminConfigTenantSlug())]);
    }

    if ($action === 'config_update') {
        requireConfigKey($key);
        $tenantSlug = \Agenduy\Core\CommercePanel::centralSessionSlug();
        if ($tenantSlug === '' || \Agenduy\Core\CommercePanel::isTemplateHost($tenantSlug)) {
            $tenantSlug = adminConfigTenantSlug();
        }
        $plan = MembershipPlan::forCommerceSlug($tenantSlug);
        if (is_array($plan) && !MembershipPlan::allowsConfigKey($plan, $key)) {
            throw new UnexpectedValueException(MembershipPlan::DENIAL_MESSAGE . ' Mejorá tu membresía para continuar.');
        }
        $payload = requestData();
        if ($key === 'reservas') {
            $payload = sanitizeReservationConfigPayload($payload, $plan);
        } elseif ($key === 'info_barberia' && isset($payload['reservas']) && is_array($payload['reservas'])) {
            $payload['reservas'] = sanitizeReservationConfigPayload($payload['reservas'], $plan);
        }
        if ($key === 'email_plantillas') {
            throw new UnexpectedValueException('Las plantillas de email las administra el super admin.');
        }
        if ($key === 'info_barberia') {
            unset($payload['email_plantillas']);
            if (is_array($plan)) {
                foreach (MembershipPlan::blockedInfoSections($plan) as $blocked) {
                    if (isset($payload[$blocked])) {
                        throw new UnexpectedValueException(MembershipPlan::DENIAL_MESSAGE . ' Mejorá tu membresía para continuar.');
                    }
                }
            }
            $commerceRow = commerceBySlug(adminConfigTenantSlug());
            $logoSrc = handleLogoUpload((int)$commerceRow['id_commerce']);
            if ($logoSrc !== null) {
                $payload['logo_src'] = $logoSrc;
            }
            $updated = updateCommerceConfig(adminConfigTenantSlug(), $payload);
        } else {
            $updated = AutoloadDB::updateConfigSection($key, $payload);
        }
        respond(['ok' => true, 'data' => $updated]);
    }

    if ($action === 'apply_theme') {
        $tenantSlug = \Agenduy\Core\CommercePanel::centralSessionSlug();
        if ($tenantSlug === '' || \Agenduy\Core\CommercePanel::isTemplateHost($tenantSlug)) {
            $tenantSlug = adminConfigTenantSlug();
        }
        $plan = MembershipPlan::forCommerceSlug($tenantSlug);
        if (is_array($plan) && !MembershipPlan::isFullSettings($plan)) {
            throw new UnexpectedValueException(MembershipPlan::DENIAL_MESSAGE . ' Mejorá tu membresía para continuar.');
        }
        $themes = applyThemeFiles($tenantRoot, $projectRoot);
        $updated = updateCommerceConfig(adminConfigTenantSlug(), ['temas' => $themes]);
    }

    if ($action === 'platform_payments_get') {
        $paypal = \Agenduy\Core\ProviderConfig::get('paypal');
        $mp = \Agenduy\Core\ProviderConfig::get('mercadopago');
        $transfer = \Agenduy\Core\ProviderConfig::get('transfer');

        respond([
            'ok' => true,
            'data' => [
                'paypal' => [
                    'is_enabled' => !empty($paypal['is_enabled']),
                    'client_id' => (string)($paypal['config']['client_id'] ?? ''),
                    'client_secret' => (string)($paypal['config']['client_secret'] ?? ''),
                    'sandbox' => !empty($paypal['config']['sandbox']),
                ],
                'mercadopago' => [
                    'is_enabled' => !empty($mp['is_enabled']),
                    'access_token' => (string)($mp['config']['access_token'] ?? ''),
                    'public_key' => (string)($mp['config']['public_key'] ?? ''),
                ],
                'transfer' => [
                    'is_enabled' => !empty($transfer['is_enabled']),
                    'banco' => (string)($transfer['config']['banco'] ?? ''),
                    'titular' => (string)($transfer['config']['titular'] ?? ''),
                    'cuenta' => (string)($transfer['config']['cuenta'] ?? ''),
                    'moneda' => (string)($transfer['config']['moneda'] ?? 'UYU'),
                    'instrucciones' => (string)($transfer['config']['instrucciones'] ?? ''),
                ],
            ]
        ]);
    }

    if ($action === 'platform_payments_update') {
        $payload = requestData();
        $userId = (int)($_SESSION['user']['id_user'] ?? 0);

        if (isset($payload['paypal']) && is_array($payload['paypal'])) {
            $p = $payload['paypal'];
            \Agenduy\Core\ProviderConfig::save('paypal', [
                'client_id' => trim((string)($p['client_id'] ?? '')),
                'client_secret' => trim((string)($p['client_secret'] ?? '')),
                'sandbox' => !empty($p['sandbox']),
            ], !empty($p['is_enabled']), $userId, 'Ajustes de PayPal plataforma');
        }

        if (isset($payload['mercadopago']) && is_array($payload['mercadopago'])) {
            $m = $payload['mercadopago'];
            \Agenduy\Core\ProviderConfig::save('mercadopago', [
                'access_token' => trim((string)($m['access_token'] ?? '')),
                'public_key' => trim((string)($m['public_key'] ?? '')),
            ], !empty($m['is_enabled']), $userId, 'Ajustes de Mercado Pago plataforma');
        }

        if (isset($payload['transfer']) && is_array($payload['transfer'])) {
            $t = $payload['transfer'];
            \Agenduy\Core\ProviderConfig::save('transfer', [
                'banco' => trim((string)($t['banco'] ?? '')),
                'titular' => trim((string)($t['titular'] ?? '')),
                'cuenta' => trim((string)($t['cuenta'] ?? '')),
                'moneda' => trim((string)($t['moneda'] ?? 'UYU')),
                'instrucciones' => trim((string)($t['instrucciones'] ?? '')),
            ], !empty($t['is_enabled']), $userId, 'Ajustes de Transferencia Bancaria');
        }

        respond(['ok' => true, 'message' => 'Métodos de pago de suscripciones actualizados correctamente.']);
    }

    throw new InvalidArgumentException('Accion no soportada: ' . $action);
} catch (Throwable $e) {
    $status = $e instanceof UnexpectedValueException ? 403 : 400;
    http_response_code($status);
    respond(['ok' => false, 'error' => $e->getMessage()]);
}

function respond(array $payload): void
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function assertAdminRequest(): void
{
    global $tenantRoot;

    $user = $_SESSION['user'] ?? null;
    if (!is_array($user)) {
        throw new UnexpectedValueException('Sesion de administrador requerida.');
    }

    $centralRole = strtolower(trim((string)($user['role'] ?? '')));
    $legacyRole = strtolower(trim((string)($user['Rol'] ?? $user['rol'] ?? '')));
    $isAdmin = $centralRole === 'commerce_admin' || $centralRole === 'super_admin' || $legacyRole === 'admin';
    if (!$isAdmin) {
        throw new UnexpectedValueException('Sesion de administrador requerida.');
    }

    if ($centralRole === 'commerce_admin') {
        $commerceId = (int)($user['id_commerce'] ?? 0);
        if ($commerceId <= 0) {
            throw new UnexpectedValueException('Cuenta sin comercio asignado.');
        }
        $commerce = Database::getInstance()->fetchOne(
            'SELECT slug FROM commerces WHERE id_commerce = :id LIMIT 1',
            [':id' => $commerceId]
        );
        $ownedSlug = trim((string)($commerce['slug'] ?? ''));
        $tenantSlug = \Agenduy\Core\CommercePanel::centralSessionSlug();
        if ($tenantSlug === '' || \Agenduy\Core\CommercePanel::isTemplateHost($tenantSlug)) {
            $tenantSlug = $ownedSlug;
        }
        if ($ownedSlug !== '') {
            \Agenduy\Core\CommercePanel::bootstrapCentralAccess($commerceId, $ownedSlug);
        }
        if ($ownedSlug === '' || !hash_equals($ownedSlug, $tenantSlug)) {
            throw new UnexpectedValueException('No autorizado para este comercio.');
        }
    }

    $provided = $_SERVER['HTTP_X_CSRF_TOKEN']
        ?? $_REQUEST['_csrf']
        ?? $_REQUEST['csrf_token']
        ?? null;
    $expected = $_SESSION['admin_config_csrf'] ?? null;
    if (!is_string($provided) || !is_string($expected) || !hash_equals($expected, $provided)) {
        throw new UnexpectedValueException('CSRF token invalido o expirado.');
    }
}

function requireConfigKey(string $key): void
{
    if ($key === '') {
        throw new InvalidArgumentException('Falta parametro key');
    }
}

function requestData(): array
{
    $payload = $_REQUEST['data'] ?? [];
    if (is_string($payload)) {
        $payload = json_decode($payload, true);
    }
    return is_array($payload) ? $payload : [];
}

function sanitizeReservationConfigPayload(array $payload, ?array $plan): array
{
    $payload['requiere_login'] = false;

    if (!MercadoPago::isReservationCheckoutAllowed($plan)) {
        $payload['mercado_pago_enabled'] = false;
        $payload['mercado_pago_required'] = false;
        return $payload;
    }

    if (empty($payload['mercado_pago_enabled'])) {
        $payload['mercado_pago_required'] = false;
    }

    return $payload;
}

function commerceBySlug(string $slug): array
{
    $commerce = Database::getInstance()->fetchOne(
        'SELECT * FROM commerces WHERE slug = :slug',
        [':slug' => $slug]
    );
    if (!$commerce) {
        throw new RuntimeException('Comercio no encontrado para el tenant: ' . $slug);
    }
    return $commerce;
}

function readConfig(string $key, string $slug): array
{
    $legacy = AutoloadDB::getConfigSection($key);
    if ($key !== 'info_barberia') {
        return $legacy;
    }
    return CommerceConfig::infoForSlug($slug, $legacy);
}

function mercadopagoPreviews(int $commerceId): array
{
    $db = Database::getInstance();
    $rows = $db->fetchAll(
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

function updateCommerceConfig(string $slug, array $payload): array
{
    $commerce = commerceBySlug($slug);
    $commerceId = (int)$commerce['id_commerce'];
    syncContactChannels($commerceId, $payload);
    $columns = commercePatch($payload);
    if ($columns !== []) {
        $columns['updated_at'] = date('Y-m-d H:i:s');
        Database::getInstance()->update('commerces', $columns, 'id_commerce = :id', [':id' => $commerceId]);
    }
    foreach (sectionMap() as $section => $legacyKey) {
        if (isset($payload[$legacyKey]) && is_array($payload[$legacyKey])) {
            CommerceSettings::merge($commerceId, $section, $payload[$legacyKey]);
        }
    }
    if (isset($payload['ID_Rubro']) && is_numeric($payload['ID_Rubro'])) {
        $rubro = Database::getInstance()->fetchOne(
            'SELECT tipo, nombre FROM rubros WHERE id_rubro = :id AND activo = 1',
            [':id' => (int)$payload['ID_Rubro']]
        );
        if ($rubro) {
            $payload['rubro'] = (string)$rubro['tipo'];
            $payload['rubro_nombre'] = (string)$rubro['nombre'];
        }
    }
    $mpPayloadKey = null;
    if (isset($payload['mercado_pago']) && is_array($payload['mercado_pago'])) {
        $mpPayloadKey = 'mercado_pago';
    } elseif (isset($payload['mercadopago']) && is_array($payload['mercadopago'])) {
        $mpPayloadKey = 'mercadopago';
    }
    if ($mpPayloadKey !== null) {
        storeMercadoPagoSecrets($commerceId, $payload[$mpPayloadKey]);
        // No persistir secretos en claro en el legacy.
        $safe = $payload[$mpPayloadKey];
        foreach (['access_token', 'accessToken', 'token', 'public_key', 'publicKey', 'client_secret', 'integrator_id', 'integratorId'] as $secretKey) {
            if (isset($safe[$secretKey]) && is_string($safe[$secretKey]) && $safe[$secretKey] !== '') {
                $safe[$secretKey] = keyPreview($safe[$secretKey]);
            }
        }
        CommerceSettings::merge($commerceId, 'mercado_pago', $safe, CommerceSettings::defaultsForSection('mercado_pago'));
        $payload['mercado_pago'] = $safe;
        $payload['mercadopago'] = $safe;
    }
    AutoloadDB::updateConfigSection('info_barberia', $payload);
    return readConfig('info_barberia', $slug);
}

/**
 * Sincroniza email/WhatsApp entre comercio, redes públicas y notificaciones.
 *
 * @param array<string,mixed> $payload
 */
function syncContactChannels(int $commerceId, array &$payload): void
{
    $email = trim((string)($payload['email'] ?? ''));
    if ($email === '' && isset($payload['contacto']) && is_array($payload['contacto'])) {
        $email = trim((string)($payload['contacto']['email'] ?? ''));
    }
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $email = strtolower($email);
        $payload['email'] = $email;
        if (!isset($payload['contacto']) || !is_array($payload['contacto'])) {
            $payload['contacto'] = [];
        }
        $payload['contacto']['email'] = $email;
        CommerceSettings::merge($commerceId, 'notificaciones', ['owner_email' => $email]);
    }

    $whatsappRaw = '';
    if (isset($payload['contacto']) && is_array($payload['contacto'])) {
        $whatsappRaw = trim((string)($payload['contacto']['whatsapp'] ?? ''));
    }
    if ($whatsappRaw === '' && isset($payload['redes']) && is_array($payload['redes'])) {
        $whatsappRaw = extractWhatsAppDigits((string)($payload['redes']['whatsapp'] ?? ''));
    }
    $digits = preg_replace('/\D+/', '', $whatsappRaw) ?? '';
    if ($digits === '') {
        return;
    }

    $number = str_starts_with($whatsappRaw, '+') ? '+' . $digits : '+' . $digits;
    if (!isset($payload['contacto']) || !is_array($payload['contacto'])) {
        $payload['contacto'] = [];
    }
    $payload['contacto']['whatsapp'] = $number;
    if (!isset($payload['redes']) || !is_array($payload['redes'])) {
        $payload['redes'] = [];
    }
    $payload['redes']['whatsapp'] = 'https://wa.me/' . $digits;

    $existing = CommerceSettings::get(
        $commerceId,
        'notificaciones',
        CommerceSettings::defaultsForSection('notificaciones')
    );
    $waCfg = is_array($existing['whatsapp'] ?? null) ? $existing['whatsapp'] : [];
    CommerceSettings::merge($commerceId, 'notificaciones', [
        'whatsapp' => array_merge($waCfg, [
            'number' => $number,
            'enabled' => array_key_exists('enabled', $waCfg) ? !empty($waCfg['enabled']) : true,
            'provider' => (string)($waCfg['provider'] ?? 'meta'),
        ]),
    ]);
}

function extractWhatsAppDigits(string $value): string
{
    if (preg_match('#wa\.me/(\d+)#i', $value, $matches)) {
        return (string)$matches[1];
    }
    return preg_replace('/\D+/', '', $value) ?? '';
}

function storeMercadoPagoSecrets(int $commerceId, array $mp): void
{
    $map = [
        'access_token' => $mp['access_token'] ?? $mp['accessToken'] ?? null,
        'public_key'   => $mp['public_key'] ?? $mp['publicKey'] ?? null,
        'client_secret'=> $mp['client_secret'] ?? null,
        'integrator_id'=> $mp['integrator_id'] ?? $mp['integratorId'] ?? null,
    ];
    $db = Database::getInstance();
    $crypto = new Crypto((string)$db->config()['security']['encryption_key']);
    foreach ($map as $name => $value) {
        if (!is_string($value) || trim($value) === '' || isMaskedSecret($value)) {
            continue;
        }
        $value = trim($value);
        $encrypted = $crypto->encrypt($value);
        $preview = keyPreview($value);
        $existing = $db->fetchOne(
            'SELECT id_key FROM api_keys WHERE id_commerce = :c AND provider = :p AND key_name = :n',
            [':c' => $commerceId, ':p' => 'mercadopago', ':n' => $name]
        );
        $row = [
            'key_value'   => $encrypted,
            'key_preview' => $preview,
            'label'       => 'Mercado Pago ' . $name,
            'is_active'   => 1,
            'updated_at'  => date('Y-m-d H:i:s'),
        ];
        if ($existing) {
            $db->update('api_keys', $row, 'id_key = :id', [':id' => $existing['id_key']]);
        } else {
            $db->insert('api_keys', array_merge($row, [
                'id_commerce' => $commerceId,
                'provider'    => 'mercadopago',
                'key_name'    => $name,
            ]));
        }
    }
}

function isMaskedSecret(string $value): bool
{
    return str_contains($value, "\xE2\x80\xA2")
        || str_contains($value, '*')
        || preg_match('/x{4,}/i', $value) === 1;
}

function keyPreview(string $value): string
{
    $len = strlen($value);
    if ($len <= 4) {
        return '••••';
    }
    return '••••' . substr($value, -4);
}

function sectionMap(): array
{
    return CommerceConfig::sectionMap();
}

function commerceInfo(array $commerce): array
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
    } catch (Throwable $e) {
        // ignore
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

function commercePatch(array $payload): array
{
    $map = [
        'nombre' => 'nombre', 'email' => 'email', 'logo_src' => 'logo',
        'slogan' => 'slogan', 'descripcion' => 'descripcion',
        'razon_social' => 'razon_social', 'rut_ruc' => 'rut_ruc',
    ];
    $patch = [];
    foreach ($map as $input => $column) {
        if (array_key_exists($input, $payload) && is_scalar($payload[$input])) {
            $patch[$column] = trim((string)$payload[$input]);
        }
    }
    foreach (['telefono', 'whatsapp', 'website', 'email'] as $field) {
        if (isset($payload['contacto']) && is_array($payload['contacto']) && array_key_exists($field, $payload['contacto'])) {
            $patch[$field] = trim((string)$payload['contacto'][$field]);
        }
    }
    foreach (['pais', 'ciudad', 'calle'] as $field) {
        if (isset($payload['direccion']) && is_array($payload['direccion']) && array_key_exists($field, $payload['direccion'])) {
            $patch[$field] = trim((string)$payload['direccion'][$field]);
        }
    }
    if (array_key_exists('ID_Rubro', $payload) && is_numeric($payload['ID_Rubro'])) {
        $patch['id_rubro'] = (int)$payload['ID_Rubro'];
    }
    return $patch;
}

function applyThemeFiles(string $root, string $projectRoot): array
{
    $public = validThemeMode((string)($_REQUEST['mode_public'] ?? 'oscuro'));
    $private = validThemeMode((string)($_REQUEST['mode_private'] ?? 'oscuro'));
    $toolsBase = themeToolsBase($root, $projectRoot);
    copyTheme($toolsBase . '/private/css_' . $private . '.css', $root . '/private/dashboard/src/admin.css');
    copyTheme($toolsBase . '/public/css_' . $public . '.css', $root . '/src/css/main.css');
    return ['publico' => $public, 'privado' => $private];
}

function validThemeMode(string $mode): string
{
    $mode = strtolower($mode);
    return in_array($mode, ['oscuro', 'claro'], true) ? $mode : 'oscuro';
}

function themeToolsBase(string $root, string $projectRoot): string
{
    foreach ([$root . '/Private/tools/css', $root . '/private/tools/css', $projectRoot . '/Private/tools/css', $projectRoot . '/private/tools/css'] as $candidate) {
        if (is_dir($candidate)) {
            return $candidate;
        }
    }
    throw new RuntimeException('No se encontro la carpeta base de temas.');
}

function copyTheme(string $source, string $destination): void
{
    if (!is_file($source) || !@copy($source, $destination)) {
        throw new RuntimeException('No se pudo aplicar el archivo de tema: ' . basename($source));
    }
}

function handleLogoUpload(int $idCommerce): ?string
{
    if (!isset($_FILES['logo']) || !is_array($_FILES['logo'])) {
        return null;
    }
    $file = $_FILES['logo'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Error al subir el logo.');
    }
    $maxBytes = 2 * 1024 * 1024;
    if (($file['size'] ?? 0) > $maxBytes) {
        throw new RuntimeException('El logo supera el tamaño permitido (2MB).');
    }
    $tmpPath = $file['tmp_name'] ?? '';
    if (!$tmpPath || !is_uploaded_file($tmpPath)) {
        throw new RuntimeException('No se pudo validar el archivo del logo.');
    }
    $imageInfo = @getimagesize($tmpPath);
    if (!$imageInfo) {
        throw new RuntimeException('El archivo de logo no es una imagen valida.');
    }
    $type = $imageInfo[2] ?? 0;

    $destDir = CommerceStorage::kindDir($idCommerce, 'logo');
    $destPath = $destDir . '/logo.png';
    $storedRel = CommerceStorage::relativePath($idCommerce, 'logo', 'logo.png');

    if (!function_exists('imagecreatetruecolor')) {
        if ($type === IMAGETYPE_PNG) {
            if (!move_uploaded_file($tmpPath, $destPath)) {
                throw new RuntimeException('No se pudo guardar el logo.');
            }
            @chmod($destPath, 0644);
            return $storedRel;
        }
        if ($type === IMAGETYPE_JPEG) {
            $jpgPath = $destDir . '/logo.jpg';
            if (!move_uploaded_file($tmpPath, $jpgPath)) {
                throw new RuntimeException('No se pudo guardar el logo.');
            }
            @chmod($jpgPath, 0644);
            return CommerceStorage::relativePath($idCommerce, 'logo', 'logo.jpg');
        }
        throw new RuntimeException('El servidor no soporta el procesamiento del logo.');
    }

    $src = null;
    switch ($type) {
        case IMAGETYPE_JPEG:
            $src = @imagecreatefromjpeg($tmpPath);
            break;
        case IMAGETYPE_PNG:
            $src = function_exists('imagecreatefrompng') ? @imagecreatefrompng($tmpPath) : null;
            break;
        case IMAGETYPE_GIF:
            $src = function_exists('imagecreatefromgif') ? @imagecreatefromgif($tmpPath) : null;
            break;
        case IMAGETYPE_WEBP:
            $src = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmpPath) : null;
            break;
        default:
            $src = null;
    }
    if (!$src) {
        throw new RuntimeException('Formato de imagen no soportado para el logo.');
    }
    $width = imagesx($src);
    $height = imagesy($src);
    $canvas = imagecreatetruecolor($width, $height);
    if (!$canvas) {
        imagedestroy($src);
        throw new RuntimeException('No se pudo procesar el logo.');
    }
    imagealphablending($canvas, false);
    imagesavealpha($canvas, true);
    $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
    imagefill($canvas, 0, 0, $transparent);
    imagecopyresampled($canvas, $src, 0, 0, 0, 0, $width, $height, $width, $height);

    if (!imagepng($canvas, $destPath, 6)) {
        imagedestroy($canvas);
        imagedestroy($src);
        throw new RuntimeException('No se pudo guardar el logo.');
    }
    @chmod($destPath, 0644);
    imagedestroy($canvas);
    imagedestroy($src);

    return $storedRel;
}
