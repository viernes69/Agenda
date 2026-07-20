<?php
/**
 * Test end-to-end del webhook dLocal + HMAC + persistencia en tenant DB.
 * Arranca php -S, hace POST al webhook, valida respuesta y BD.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$port = 8766;

// 1) Crear un tenant de prueba
$testSlug = 'dlocal-test-' . substr(md5(uniqid()), 0, 6);
$tenantDir = $root . DIRECTORY_SEPARATOR . $testSlug;
$dbDir = $tenantDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'db';
@mkdir($dbDir, 0777, true);
$apiKey    = 'TEST_API_KEY_' . bin2hex(random_bytes(8));
$secretKey = 'TEST_SECRET_KEY_' . bin2hex(random_bytes(8));
$initialDb = [
    'info_barberia' => [
        'nombre' => 'Salon de prueba dLocal',
        'slug'   => $testSlug,
    ],
    'dlocal' => [
        'api_key'    => $apiKey,
        'secret_key' => $secretKey,
        'sandbox'    => true,
    ],
    'planes_cliente' => [],
    'suscripciones_cliente' => [],
];
$tmpDbFile = $dbDir . DIRECTORY_SEPARATOR . 'database.php';
file_put_contents($tmpDbFile, '<?php return ' . var_export($initialDb, true) . ';');

echo "=== Test webhook dLocal ===" . PHP_EOL;
echo "Slug: $testSlug" . PHP_EOL;
echo "Tenant dir: $tenantDir" . PHP_EOL;
echo PHP_EOL;

// 2) Arranca php -S embebido
$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];
$cmd = sprintf('"%s" -S 127.0.0.1:%d -t "%s" > NUL 2>&1', 'C:\\xampp\\php\\php.exe', $port, $root);
$proc = proc_open($cmd, $descriptors, $pipes, $root);
if (!is_resource($proc)) {
    fwrite(STDERR, "No pude arrancar php -S\n");
    exit(1);
}
usleep(800000);

function http(string $method, string $url, array $headers = [], ?string $body = null): array
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => $raw];
}

function sign(string $apiKey, string $secretKey, string $body): string
{
    return 'V2-HMAC-SHA256, Signature: ' . hash_hmac('sha256', $apiKey . $body, $secretKey);
}

$webhookUrl = sprintf('http://127.0.0.1:%d/admin/api/webhook_dlocal.php?slug=%s&source=plan', $port, $testSlug);

// Test 1: HMAC valido + external_id existente (pre-registramos la suscripcion)
$subId = 'sub-test-' . bin2hex(random_bytes(4));
$externalId = 'c5_p1234_testabc';
$dbNow = include $tmpDbFile;
$dbNow['suscripciones_cliente'][$subId] = [
    'id'                => $subId,
    'slug'              => $testSlug,
    'plan_internal_id'  => 'plan_test',
    'dlocal_plan_id'    => 1234,
    'plan_token'        => 'PLAN_TOKEN_TEST',
    'external_id'       => $externalId,
    'customer_email'    => 'cliente@test.com',
    'status'            => 'CREATED',
    'created_at'        => date('Y-m-d H:i:s'),
    'updated_at'        => date('Y-m-d H:i:s'),
];
file_put_contents($tmpDbFile, '<?php return ' . var_export($dbNow, true) . ';');

$body = json_encode(['payment_id' => 'DP-TEST-1', 'external_id' => $externalId, 'status' => 'PAID']);
$sig  = sign($apiKey, $secretKey, $body);
$r = http('POST', $webhookUrl, [
    'Content-Type: application/json',
    'Authorization: ' . $sig,
], $body);
echo "Test 1 (HMAC valido + external_id):" . PHP_EOL;
echo "  HTTP: " . $r['code'] . PHP_EOL;
echo "  Body: " . $r['body'] . PHP_EOL;
$json = json_decode($r['body'], true);
$pass1 = ($r['code'] === 200 && ($json['ok'] ?? false) === true && ($json['matched'] ?? false) === true);
echo "  " . ($pass1 ? 'PASS' : 'FAIL') . PHP_EOL;
echo PHP_EOL;

// Verifico que la suscripcion cambio a CONFIRMED en la DB
$dbAfter = include $tmpDbFile;
$updated = $dbAfter['suscripciones_cliente'][$subId] ?? null;
$pass1b = ($updated && ($updated['status'] ?? '') === 'CONFIRMED' && !empty($updated['confirmed_at']));
echo "  DB status ahora: " . ($updated['status'] ?? 'MISSING') . PHP_EOL;
echo "  " . ($pass1b ? 'PASS (DB actualizada)' : 'FAIL (DB no actualizada)') . PHP_EOL;
echo PHP_EOL;

// Test 2: HMAC invalido
$body2 = json_encode(['payment_id' => 'DP-TEST-2', 'external_id' => $externalId, 'status' => 'PAID']);
$r2 = http('POST', $webhookUrl, [
    'Content-Type: application/json',
    'Authorization: V2-HMAC-SHA256, Signature: ' . str_repeat('0', 64),
], $body2);
echo "Test 2 (HMAC invalido):" . PHP_EOL;
echo "  HTTP: " . $r2['code'] . PHP_EOL;
echo "  Body: " . $r2['body'] . PHP_EOL;
$pass2 = ($r2['code'] === 401);
echo "  " . ($pass2 ? 'PASS' : 'FAIL') . PHP_EOL;
echo PHP_EOL;

// Test 3: Sin Authorization
$r3 = http('POST', $webhookUrl, ['Content-Type: application/json'], $body2);
echo "Test 3 (sin Authorization):" . PHP_EOL;
echo "  HTTP: " . $r3['code'] . PHP_EOL;
echo "  Body: " . $r3['body'] . PHP_EOL;
$pass3 = ($r3['code'] === 401);
echo "  " . ($pass3 ? 'PASS' : 'FAIL') . PHP_EOL;
echo PHP_EOL;

// Test 4: Slug inexistente
$webhookNoSlug = sprintf('http://127.0.0.1:%d/admin/api/webhook_dlocal.php?slug=no_existe', $port);
$body4 = json_encode(['payment_id' => 'X']);
$sig4  = sign($apiKey, $secretKey, $body4);
$r4 = http('POST', $webhookNoSlug, [
    'Content-Type: application/json',
    'Authorization: ' . $sig4,
], $body4);
echo "Test 4 (slug inexistente):" . PHP_EOL;
echo "  HTTP: " . $r4['code'] . PHP_EOL;
echo "  Body: " . $r4['body'] . PHP_EOL;
$pass4 = ($r4['code'] === 200 && (json_decode($r4['body'], true)['ok'] ?? false) === false);
echo "  " . ($pass4 ? 'PASS (200 + ok=false para evitar reintentos)' : 'FAIL') . PHP_EOL;
echo PHP_EOL;

// Test 5: external_id no existe en DB
$body5 = json_encode(['payment_id' => 'DP-X', 'external_id' => 'no_existe', 'status' => 'PAID']);
$sig5  = sign($apiKey, $secretKey, $body5);
$r5 = http('POST', $webhookUrl, [
    'Content-Type: application/json',
    'Authorization: ' . $sig5,
], $body5);
echo "Test 5 (external_id no matchea):" . PHP_EOL;
echo "  HTTP: " . $r5['code'] . PHP_EOL;
echo "  Body: " . $r5['body'] . PHP_EOL;
$pass5 = ($r5['code'] === 200 && (json_decode($r5['body'], true)['matched'] ?? null) === false);
echo "  " . ($pass5 ? 'PASS' : 'FAIL') . PHP_EOL;
echo PHP_EOL;

// Test 6: status CANCELLED
$body6 = json_encode(['external_id' => $externalId, 'status' => 'CANCELLED']);
$sig6  = sign($apiKey, $secretKey, $body6);
$r6 = http('POST', $webhookUrl, [
    'Content-Type: application/json',
    'Authorization: ' . $sig6,
], $body6);
echo "Test 6 (status CANCELLED):" . PHP_EOL;
echo "  HTTP: " . $r6['code'] . PHP_EOL;
$dbAfter2 = include $tmpDbFile;
$status6 = $dbAfter2['suscripciones_cliente'][$subId]['status'] ?? 'MISSING';
echo "  DB status: $status6" . PHP_EOL;
$pass6 = ($r6['code'] === 200 && $status6 === 'CANCELLED');
echo "  " . ($pass6 ? 'PASS' : 'FAIL') . PHP_EOL;
echo PHP_EOL;

// Cleanup
proc_terminate($proc, 9);
proc_close($proc);

// Eliminar el tenant de prueba
function rrmdir($dir)
{
    if (!is_dir($dir)) return;
    $items = scandir($dir);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            rrmdir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}
rrmdir($tenantDir);

$total = ($pass1 && $pass1b && $pass2 && $pass3 && $pass4 && $pass5 && $pass6) ? 7 : 0;
echo "=== Resultado: $total/7 tests OK ===" . PHP_EOL;
exit($total === 7 ? 0 : 1);
