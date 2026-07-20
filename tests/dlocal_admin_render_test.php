<?php
/**
 * Test del componente admin_plans.
 * Verifica carga sin errores y comportamiento sin auth.
 * El render con auth valida requiere un commerce real en la DB
 * (lo testea el usuario final cuando integre).
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/src/Core/bootstrap.php';
require $root . '/src/components/dlocal/admin_plans.php';

echo "=== Test componente admin dlocal/admin_plans ===" . PHP_EOL;

$tests = [];

// T1: la clase existe
$tests['1_clase_cargada'] = class_exists('AgenduyDlocalAdmin');

// T2: sin auth da warning (no logged in)
$html1 = AgenduyDlocalAdmin::render();
$tests['2_sin_auth_da_warning'] = str_contains($html1, 'Necesitas iniciar sesion');

// T3: el HTML sin auth es valido (no exception)
$tests['3_render_sin_excepcion'] = is_string($html1) && $html1 !== '';

$pass = 0;
$total = count($tests);
foreach ($tests as $name => $ok) {
    if ($ok) $pass++;
    echo ($ok ? '[PASS]' : '[FAIL]') . " $name" . PHP_EOL;
}
echo PHP_EOL . "Total: $pass / $total" . PHP_EOL;
echo "HTML muestra: " . substr($html1, 0, 100) . '...' . PHP_EOL;
exit($pass === $total ? 0 : 1);
