<?php
declare(strict_types=1);

$_SERVER['HTTPS'] = 'on';
$_SERVER['HTTP_HOST'] = 'agendarte.oficiosya.net';
$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__);
$_SERVER['SCRIPT_FILENAME'] = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'login.php';
$_SERVER['SCRIPT_NAME'] = '/admin/login.php';

require dirname(__DIR__) . '/src/Core/helpers.php';

$dashboard = url('admin/index.php');
$urlBase = url_base();

$ok = str_ends_with($dashboard, '/admin/index.php')
    && !str_contains($dashboard, '/admin/admin/');

echo ($ok ? '[PASS]' : '[FAIL]') . ' dashboard=' . $dashboard . ' base=' . $urlBase . PHP_EOL;
exit($ok ? 0 : 1);
