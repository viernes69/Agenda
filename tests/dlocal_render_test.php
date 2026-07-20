<?php
/**
 * Test del componente publico de planes.
 * Crea un tenant con 2 planes y verifica que el HTML generado es correcto.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$testSlug = 'dlocal-render-' . substr(md5(uniqid()), 0, 6);
$tenantDir = $root . DIRECTORY_SEPARATOR . $testSlug;
$dbDir = $tenantDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'db';
@mkdir($dbDir, 0777, true);

$db = [
    'info_barberia' => ['nombre' => 'Salon Render Test', 'slug' => $testSlug],
    'dlocal' => ['api_key' => 'k', 'secret_key' => 's', 'sandbox' => true],
    'planes_cliente' => [
        'plan_1' => [
            'id' => 'plan_1', 'name' => 'Basico', 'description' => 'Plan basico',
            'currency' => 'UYU', 'amount' => 1000, 'frequency_type' => 'MONTHLY',
            'free_trial_days' => 7, 'active' => true,
        ],
        'plan_2' => [
            'id' => 'plan_2', 'name' => 'Premium', 'description' => 'Plan premium',
            'currency' => 'UYU', 'amount' => 2500, 'frequency_type' => 'YEARLY',
            'free_trial_days' => 0, 'active' => true,
        ],
        'plan_inactivo' => [
            'id' => 'plan_inactivo', 'name' => 'Inactivo', 'description' => 'No debe mostrarse',
            'currency' => 'UYU', 'amount' => 999, 'frequency_type' => 'MONTHLY',
            'free_trial_days' => 0, 'active' => false,
        ],
    ],
];
file_put_contents($dbDir . DIRECTORY_SEPARATOR . 'database.php', '<?php return ' . var_export($db, true) . ';');

require $root . '/src/Core/bootstrap.php';
require $root . '/src/components/dlocal/plans.php';

$html = AgenduyDlocalPlans::render($testSlug);

$tests = [
    'section_exists'         => str_contains($html, '<section class="dlocal-plans"'),
    'title'                  => str_contains($html, '<h2 class="dlocal-plans__title">Suscripciones</h2>'),
    'plan_basico'            => str_contains($html, 'Basico'),
    'plan_premium'           => str_contains($html, 'Premium'),
    'plan_inactivo_oculto'   => !str_contains($html, 'No debe mostrarse'),
    'amount_basico'          => str_contains($html, 'UYU 1.000,00'),
    'amount_premium'         => str_contains($html, 'UYU 2.500,00'),
    'freq_mes'               => str_contains($html, '/ mes'),
    'freq_anio'              => str_contains($html, '/ año'),
    'trial_badge'            => str_contains($html, '7 días gratis'),
    'csrf_input'             => str_contains($html, 'name="_csrf"'),
    'plan_internal_id_input' => str_contains($html, 'name="plan_internal_id"'),
    'email_input'            => str_contains($html, 'name="customer_email"'),
    'cta_button'             => str_contains($html, 'Suscribirme'),
    'slug_in_form'           => str_contains($html, 'value="' . $testSlug . '"'),
];

// Cleanup
function rrmdir($dir) {
    if (!is_dir($dir)) return;
    foreach (scandir($dir) as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        is_dir($path) ? rrmdir($path) : @unlink($path);
    }
    @rmdir($dir);
}
rrmdir($tenantDir);

echo "=== Test componente publico dlocal/plans ===" . PHP_EOL;
$pass = 0;
$total = count($tests);
foreach ($tests as $name => $ok) {
    if ($ok) $pass++;
    echo ($ok ? '[PASS]' : '[FAIL]') . " $name" . PHP_EOL;
}
echo PHP_EOL . "Total: $pass / $total" . PHP_EOL;
echo PHP_EOL . "HTML de muestra (200 chars):" . PHP_EOL;
echo substr($html, 0, 200) . "..." . PHP_EOL;
exit($pass === $total ? 0 : 1);
