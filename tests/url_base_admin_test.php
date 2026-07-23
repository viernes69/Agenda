<?php
declare(strict_types=1);

/**
 * Test para url_base() con admin/login.php como entry point.
 * Simula el entorno del VirtualHost de agendarte.oficiosya.net.
 */

putenv('AGENDUY_URL_BASE=');
putenv('AGENDUY_BASE_PATH=');

$_SERVER['HTTPS'] = 'on';
$_SERVER['HTTP_HOST'] = 'agendarte.oficiosya.net';
$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__);
$_SERVER['SCRIPT_FILENAME'] = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'login.php';
$_SERVER['SCRIPT_NAME'] = '/admin/login.php';
$_SERVER['REQUEST_URI'] = '/admin/login.php';
$_SERVER['REQUEST_SCHEME'] = 'https';
$_SERVER['SERVER_PORT'] = '443';

require dirname(__DIR__) . '/src/Core/helpers.php';

$dashboard = url('admin/index.php');
$urlBase = url_base();

$ok = str_ends_with($dashboard, '/admin/index.php')
    && !str_contains($dashboard, '/admin/admin/')
    && $urlBase === 'https://agendarte.oficiosya.net';

echo ($ok ? '[PASS]' : '[FAIL]') . ' dashboard=' . $dashboard . ' base=' . $urlBase . PHP_EOL;
exit($ok ? 0 : 1);
