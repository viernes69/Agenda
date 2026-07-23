<?php
declare(strict_types=1);

/**
 * Test del panel central: verifica que la URL publica de un comercio
 * NO incluya segmentos internos del framework (/template/, /private/, /dashboard/).
 *
 * Cubre el bug reportado donde "Comparte este enlace con tus clientes"
 * mostraba una URL incorrecta.
 */

putenv('AGENDUY_URL_BASE=');
putenv('AGENDUY_BASE_PATH=');

// Simular entorno del panel central en agendarte.oficiosya.net
$_SERVER['HTTPS'] = 'on';
$_SERVER['HTTP_HOST'] = 'agendarte.oficiosya.net';
$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__);
$_SERVER['SCRIPT_FILENAME'] = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'commerce_panel.php';
$_SERVER['SCRIPT_NAME'] = '/admin/commerce_panel.php';
$_SERVER['REQUEST_URI'] = '/mi-barberia/private/dashboard/admin/';
$_SERVER['REQUEST_SCHEME'] = 'https';
$_SERVER['SERVER_PORT'] = '443';

require dirname(__DIR__) . '/src/Core/helpers.php';
require_once dirname(__DIR__) . '/src/Core/CommercePanel.php';

$failures = 0;
$pass = function (bool $ok, string $label, string $detail = '') use (&$failures): void {
    if ($ok) {
        echo "[PASS] $label\n";
    } else {
        echo "[FAIL] $label" . ($detail !== '' ? " -> $detail" : '') . "\n";
        $failures++;
    }
};

// 1) URL base en VirtualHost normal (sin subdirectorio)
$urlBase = url_base();
$pass($urlBase === 'https://agendarte.oficiosya.net', 'url_base sin subdirectorio', "got: $urlBase");

// 2) URL publica del comercio: no debe contener segmentos internos
$publicUrl = \Agenduy\Core\CommercePanel::publicUrlForSlug('mi-barberia');
$pass(
    $publicUrl === 'https://agendarte.oficiosya.net/mi-barberia/',
    'URL publica limpia del comercio',
    "got: $publicUrl"
);

// 3) No debe contener /template/ (bug original)
$pass(
    !str_contains($publicUrl, '/template/'),
    'URL publica sin /template/'
);

// 4) No debe contener /private/ ni /dashboard/
$pass(
    !str_contains($publicUrl, '/private/') && !str_contains($publicUrl, '/dashboard/'),
    'URL publica sin /private/ ni /dashboard/'
);

// 5) Safety net: si url_base estuviera contaminado, publicUrlForSlug lo limpia.
//    Simulamos AGENDUY_URL_BASE roto y verificamos que el resultado es correcto.
putenv('AGENDUY_URL_BASE=https://agendarte.oficiosya.net/template/private/dashboard');
$contaminado = \Agenduy\Core\CommercePanel::publicUrlForSlug('mi-barberia');
$pass(
    $contaminado === 'https://agendarte.oficiosya.net/mi-barberia/',
    'Safety net: limpia /template/private/dashboard contaminado',
    "got: $contaminado"
);
putenv('AGENDUY_URL_BASE=');

// 6) Safety net con app en subdirectorio: conserva /data y corta desde /template/.
putenv('AGENDUY_URL_BASE=https://localhost/data/template/private/dashboard');
$subdir = \Agenduy\Core\CommercePanel::publicUrlForSlug('mi-barberia');
$pass(
    $subdir === 'https://localhost/data/mi-barberia/',
    'Safety net: conserva subdirectorio y limpia internos',
    "got: $subdir"
);
$panelSubdir = \Agenduy\Core\CommercePanel::dashboardUrlForSlug('mi-barberia', 'config');
$pass(
    $panelSubdir === 'https://localhost/data/mi-barberia/private/dashboard/admin/#config',
    'Panel central limpio en subdirectorio',
    "got: $panelSubdir"
);
putenv('AGENDUY_URL_BASE=');

echo $failures === 0
    ? "\nTodos los tests pasaron.\n"
    : "\n$failures test(s) fallaron.\n";
exit($failures === 0 ? 0 : 1);
