<?php
/**
 * Agenduy - Logout
 * Cierra la sesion y redirige al destino correcto segun el rol.
 */
declare(strict_types=1);

$config = require __DIR__ . '/../src/Core/bootstrap.php';

use Agenduy\Core\Auth;
use Agenduy\Core\CommercePanel;
use Agenduy\Core\Database;
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

Auth::start();
Security::sendNoStoreHeaders();

$defaultRedirect = url('/');
$user = Auth::user();
if (is_array($user) && (string)($user['role'] ?? '') === Auth::ROLE_LOCAL) {
    $commerceId = (int)($user['id_commerce'] ?? 0);
    if ($commerceId > 0) {
        $commerce = Database::getInstance()->fetchOne(
            'SELECT slug FROM commerces WHERE id_commerce = :id LIMIT 1',
            [':id' => $commerceId]
        );
        $slug = trim((string)($commerce['slug'] ?? ''));
        if ($slug !== '') {
            $defaultRedirect = CommercePanel::publicUrlForSlug($slug);
        }
    }
}

$next = isset($_GET['next']) ? (string)$_GET['next'] : '';
$redirect = agenduy_logout_safe_redirect($next) ?? $defaultRedirect;

Auth::logout();

header('Location: ' . $redirect);
exit;
