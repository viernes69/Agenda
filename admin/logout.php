<?php
/**
 * Agenduy - Logout
 * Cierra la sesion y redirige al destino correcto segun el rol.
 */
declare(strict_types=1);

$config = require __DIR__ . '/../src/Core/bootstrap.php';

use Agenduy\Core\Auth;
use Agenduy\Core\Security;

function agenduy_logout_safe_redirect(string $next): ?string
{
    $next = trim($next);
    if ($next === '' || preg_match('/[\r\n]/', $next)) {
        return null;
    }
    if (str_starts_with($next, '//')) {
        return null;
    }
    if (preg_match('#^https?://#i', $next)) {
        $target = parse_url($next);
        $base = parse_url(url_base());
        if (!is_array($target) || !is_array($base)) {
            return null;
        }
        $targetHost = strtolower((string)($target['host'] ?? ''));
        $baseHost = strtolower((string)($base['host'] ?? ''));
        return ($targetHost !== '' && $targetHost === $baseHost) ? $next : null;
    }
    return str_starts_with($next, '/') ? $next : null;
}

function agenduy_logout_cache_bust(string $redirect): string
{
    $parts = explode('#', $redirect, 2);
    $base = $parts[0];
    $fragment = isset($parts[1]) ? '#' . $parts[1] : '';
    $sep = str_contains($base, '?') ? '&' : '?';
    return $base . $sep . 'logout=1&_=' . rawurlencode((string)time()) . $fragment;
}

Auth::start();
Security::sendNoStoreHeaders();

$defaultRedirect = url('/');
$next = isset($_GET['next']) ? (string)$_GET['next'] : '';
$redirect = agenduy_logout_safe_redirect($next) ?? $defaultRedirect;
if ($redirect === '') {
    $redirect = url('/');
}
if (Security::isHttpsRequest() && str_starts_with($redirect, 'http://')) {
    $redirect = 'https://' . substr($redirect, 7);
}
$redirect = agenduy_logout_cache_bust($redirect);

Auth::logout();

header('Location: ' . $redirect, true, 303);
exit;
