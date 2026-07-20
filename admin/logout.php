<?php
/**
 * Agenduy - Logout
 * Cierra la sesión y redirige a login (super admin o commerce según ?next).
 */
declare(strict_types=1);

$config = require __DIR__ . '/../src/Core/bootstrap.php';
use Agenduy\Core\Auth;

Auth::logout();

// Si viene un parámetro `next` válido, redirigir ahí. Si no, al login del super admin.
$next = isset($_GET['next']) ? (string)$_GET['next'] : '';

// Por seguridad, sólo permitir redirects internos que empiecen con /agenduy.uy
if ($next !== '' && strpos($next, '/agenduy.uy/') === 0) {
    header('Location: ' . $next);
} else {
    header('Location: login.php');
}
exit;
