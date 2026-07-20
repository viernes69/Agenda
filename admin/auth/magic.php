<?php
/**
 * Agendarte - Consumir link mágico del panel
 * GET /admin/auth/magic.php?token=...
 */
declare(strict_types=1);

$config = require __DIR__ . '/../../src/Core/bootstrap.php';

use Agenduy\Core\Auth;
use Agenduy\Core\MagicLink;

Auth::start();

$token = trim((string)($_GET['token'] ?? ''));
$result = MagicLink::consume($token, $_SERVER['REMOTE_ADDR'] ?? null);

if (!$result['ok']) {
    $msg = rawurlencode((string)($result['error'] ?? 'Link inválido.'));
    header('Location: ' . url('/?login_error=1&magic=' . $msg));
    exit;
}

$redirect = (string)($result['redirect'] ?? url('/'));
header('Location: ' . $redirect, true, 303);
exit;
