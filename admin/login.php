<?php
/**
 * Agendarte - Acceso administrativo (POST desde el index; GET redirige al inicio)
 */
declare(strict_types=1);

$config = require __DIR__ . '/../src/Core/bootstrap.php';

use Agenduy\Core\Auth;
use Agenduy\Core\CSRF;

Auth::start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if (Auth::check()) {
        $destination = Auth::dashboardUrl(Auth::user() ?? []);
        if ($destination !== null) {
            header('Location: ' . $destination);
            exit;
        }
        Auth::logout();
    }
    header('Location: ' . url('/'));
    exit;
}

$csrf = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
if (!CSRF::validate(is_string($csrf) ? $csrf : null, 'admin_login', false)) {
    header('Location: ' . url('/?login_error=csrf'), true, 303);
    exit;
}

$email    = trim((string)($_POST['email'] ?? ''));
$password = (string)($_POST['password'] ?? '');

if ($email === '' || $password === '') {
    header('Location: ' . url('/?login_error=missing'), true, 303);
    exit;
}

$result = Auth::login($email, $password, $_SERVER['REMOTE_ADDR'] ?? null);
if ($result['ok']) {
    $destination = Auth::dashboardUrl($result['user']);
    if ($destination === null) {
        Auth::logout();
        header('Location: ' . url('/?login_error=1'), true, 303);
        exit;
    }
    header('Location: ' . $destination, true, 303);
    exit;
}

header('Location: ' . url('/?login_error=1'), true, 303);
exit;
