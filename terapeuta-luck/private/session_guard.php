<?php
/**
 * Guard de área privada del tenant.
 * Usa la misma sesión central (AGENDUY_SESSID) que el login unificado.
 */
declare(strict_types=1);

use Agenduy\Core\Auth;
use Agenduy\Core\CommercePanel;
use Agenduy\Core\Database;
use Agenduy\Core\Security;

if (defined('PRIVATE_SESSION_GUARD_LOADED')) {
    return;
}
define('PRIVATE_SESSION_GUARD_LOADED', true);

$projectRoot = dirname(__DIR__, 2);
if (!is_file($projectRoot . '/src/Core/bootstrap.php')) {
    // Fallback si el tenant está anidado de forma inesperada.
    $projectRoot = dirname(__DIR__, 3);
}
require_once $projectRoot . '/src/Core/bootstrap.php';
Auth::start();

if (!function_exists('private_session_normalize_path')) {
    function private_session_normalize_path(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }
}

if (!function_exists('private_session_path_to_url')) {
    function private_session_path_to_url(string $path, string $docRoot): string
    {
        $normalizedRoot = private_session_normalize_path($docRoot);
        $normalizedPath = private_session_normalize_path($path);
        if ($normalizedRoot !== '' && strpos($normalizedPath, $normalizedRoot) === 0) {
            $relative = substr($normalizedPath, strlen($normalizedRoot));
        } else {
            $relative = $normalizedPath;
        }
        return rtrim('/' . ltrim($relative, '/'), '/') . '/';
    }
}

if (!function_exists('private_session_login_url')) {
    function private_session_login_url(): string
    {
        return \url('admin/login.php');
    }
}

$documentRoot = isset($_SERVER['DOCUMENT_ROOT'])
    ? private_session_normalize_path((string)$_SERVER['DOCUMENT_ROOT'])
    : '';
$tenantRoot = private_session_normalize_path(dirname(__DIR__));
$tenantSlug = basename($tenantRoot);
$adminDir = private_session_normalize_path(__DIR__ . '/dashboard/admin');
$employeeDir = private_session_normalize_path(__DIR__ . '/dashboard/empleado');
$publicDir = $tenantRoot;

$publicUrl = private_session_path_to_url($publicDir, $documentRoot);
$adminUrl = private_session_path_to_url($adminDir, $documentRoot);
$employeeUrl = private_session_path_to_url($employeeDir, $documentRoot);
$loginUrl = private_session_login_url();

$currentScript = isset($_SERVER['SCRIPT_FILENAME'])
    ? private_session_normalize_path((string)$_SERVER['SCRIPT_FILENAME'])
    : '';
$isAdminRequest = $currentScript !== '' && strpos($currentScript, $adminDir) === 0;
$isEmployeeRequest = $currentScript !== '' && strpos($currentScript, $employeeDir) === 0;

$sessionCandidates = ['user', 'barbero', 'admin'];
$sessionUser = null;
foreach ($sessionCandidates as $candidate) {
    if (isset($_SESSION[$candidate]) && is_array($_SESSION[$candidate])) {
        $sessionUser = $_SESSION[$candidate];
        break;
    }
}

if (!is_array($sessionUser)) {
    header('Location: ' . $loginUrl);
    exit;
}

Security::sendNoStoreHeaders();

$centralRole = strtolower(trim((string)($sessionUser['role'] ?? '')));
$legacyRole = strtolower(trim((string)($sessionUser['Rol'] ?? $sessionUser['rol'] ?? '')));
$isCommerceAdmin = $centralRole === 'commerce_admin' || $legacyRole === 'admin';
$isSuperAdmin = $centralRole === 'super_admin';

// Super admin no opera el dashboard legacy del tenant por esta vía.
if ($isSuperAdmin) {
    header('Location: ' . \url('admin/index.php'));
    exit;
}

if (!$isCommerceAdmin && $legacyRole === '') {
    $legacyRole = 'func';
}

// Aislamiento: el commerce_admin solo entra a SU tenant.
if ($centralRole === 'commerce_admin') {
    $commerceId = (int)($sessionUser['id_commerce'] ?? 0);
    if ($commerceId <= 0) {
        Auth::logout();
        header('Location: ' . $loginUrl);
        exit;
    }
    $commerce = Database::getInstance()->fetchOne(
        'SELECT slug FROM commerces WHERE id_commerce = :id LIMIT 1',
        [':id' => $commerceId]
    );
    $ownedSlug = trim((string)($commerce['slug'] ?? ''));
    if (CommercePanel::isTemplateHost($tenantSlug) && $ownedSlug !== '') {
        CommercePanel::bootstrapCentralAccess($commerceId, $ownedSlug);
    } elseif ($ownedSlug === '' || !hash_equals($ownedSlug, $tenantSlug)) {
        if ($ownedSlug !== '' && CommercePanel::hasLegacyPanel($ownedSlug)) {
            header('Location: ' . CommercePanel::legacyUrl($ownedSlug));
            exit;
        }
        if ($ownedSlug !== '') {
            header('Location: ' . CommercePanel::centralUrl($ownedSlug));
            exit;
        }
        http_response_code(403);
        header('Location: ' . $loginUrl);
        exit;
    }
    // Garantizar campos legacy para el resto del dashboard.
    $_SESSION['user']['Rol'] = 'Admin';
    if (empty($_SESSION['user']['ID_Barber'])) {
        $_SESSION['user']['ID_Barber'] = (int)($sessionUser['id'] ?? 0);
    }
}

$role = $isCommerceAdmin ? 'admin' : $legacyRole;

if ($role === 'admin') {
    if ($isEmployeeRequest) {
        header('Location: ' . $adminUrl);
        exit;
    }
} else {
    if ($isAdminRequest) {
        header('Location: ' . $employeeUrl);
        exit;
    }
}
