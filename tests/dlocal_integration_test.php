<?php
/**
 * Test integral end-to-end (sin arrancar servers - los arranca el wrapper .ps1).
 *
 * Asume que:
 *   - Mock dLocal Go escucha en 127.0.0.1:8889
 *   - App server (php -S) escucha en 127.0.0.1:8888, root = C:\xampp\htdocs\agenduy.uy
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$mockPort = 8889;
$appPort = 8888;

// 1) Crear tenant de prueba
$testSlug = 'dlocal-int-' . substr(md5(uniqid()), 0, 6);
$tenantDir = $root . DIRECTORY_SEPARATOR . $testSlug;
$dbDir = $tenantDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'db';
@mkdir($dbDir, 0777, true);

// Keys compartidas con el mock server (definidas en dlocal_run_integration.ps1)
$apiKey    = 'shared_test_api_key';
$secretKey = 'shared_test_secret_key';
$initialDb = [
    'info_barberia' => ['nombre' => 'Salon de prueba integral', 'slug' => $testSlug],
    'dlocal' => [
        'api_key'       => $apiKey,
        'secret_key'    => $secretKey,
        'sandbox'       => true,
        'base_url'      => 'http://127.0.0.1:' . $mockPort,
        'checkout_base' => 'http://127.0.0.1:' . $mockPort . '/checkout',
    ],
    'planes_cliente' => [],
    'suscripciones_cliente' => [],
];
$tmpDbFile = $dbDir . DIRECTORY_SEPARATOR . 'database.php';
file_put_contents($tmpDbFile, '<?php return ' . var_export($initialDb, true) . ';');

echo "Tenant: $testSlug" . PHP_EOL;
echo "Mock:   http://127.0.0.1:$mockPort" . PHP_EOL;
echo "App:    http://127.0.0.1:$appPort" . PHP_EOL;
echo PHP_EOL;

// 2) Pre-cargar suscripcion para el test del webhook
$subInternalId = 'sub-int-' . bin2hex(random_bytes(4));
$externalId = 'c1_p' . random_int(100, 9999) . '_test';
$dbNow = include $tmpDbFile;
$dbNow['suscripciones_cliente'][$subInternalId] = [
    'id' => $subInternalId,
    'slug' => $testSlug,
    'plan_internal_id' => 'plan_test',
    'dlocal_plan_id' => 1,
    'external_id' => $externalId,
    'customer_email' => 'cliente@test.com',
    'status' => 'CREATED',
    'created_at' => date('Y-m-d H:i:s'),
    'updated_at' => date('Y-m-d H:i:s'),
];
file_put_contents($tmpDbFile, '<?php return ' . var_export($dbNow, true) . ';');

function http(string $method, string $url, array $headers = [], ?string $body = null): array
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => $raw];
}

$tests = [];

// T1: create_plan sin auth -> 401
$r = http('POST', "http://127.0.0.1:$appPort/src/API/dlocal/create_plan.php", ['Content-Type: application/json'],
    json_encode(['name' => 'Test', 'description' => 'Test', 'amount' => 100, 'currency' => 'UYU']));
$tests['1_create_plan_sin_auth'] = ['expect' => 401, 'actual' => $r['code'], 'pass' => $r['code'] === 401];

// T2: subscribe sin CSRF -> 428
$r = http('POST', "http://127.0.0.1:$appPort/src/API/dlocal/subscribe.php", ['Content-Type: application/json'],
    json_encode(['slug' => $testSlug, 'plan_internal_id' => 'x', 'customer_email' => 'c@t.com']));
$tests['2_subscribe_sin_csrf'] = ['expect' => 428, 'actual' => $r['code'], 'pass' => $r['code'] === 428];

// T3: helper contra el mock
require $root . '/src/Core/bootstrap.php';
$dlocal = new Agenduy\Core\Dlocal($apiKey, $secretKey, true, 'http://127.0.0.1:' . $mockPort, 'http://127.0.0.1:' . $mockPort . '/checkout');
$resp = $dlocal->createPlan([
    'name' => 'Test Plan', 'description' => 'Test', 'country' => 'UY', 'currency' => 'UYU',
    'amount' => 1500, 'frequency_type' => 'MONTHLY', 'frequency_value' => 1,
    'notification_url' => 'http://example.com/webhook',
    'success_url' => 'http://example.com/success',
    'back_url' => 'http://example.com/back', 'error_url' => 'http://example.com/error',
]);
$tests['3_helper_create_plan'] = [
    'pass' => isset($resp['plan_token']) && isset($resp['subscribe_url']) && isset($resp['id']),
    'resp' => $resp,
];

// T4: HMAC sign + verify
$body = json_encode(['payment_id' => 'DP-TEST']);
$sig = $dlocal->signWebhookPayload($body);
$tests['4_helper_hmac'] = [
    'pass' => $dlocal->verifyWebhookSignature($body, 'V2-HMAC-SHA256, Signature: ' . $sig),
];

// T5: retrieve payment desde el mock
$payment = $dlocal->request('GET', '/v1/payments/DP-INT-1');
$tests['5_helper_retrieve_payment'] = [
    'pass' => ($payment['id'] ?? null) === 'DP-INT-1' && ($payment['status'] ?? null) === 'PAID',
    'resp' => $payment,
];

// T6: webhook end-to-end
$webhookBody = json_encode(['payment_id' => 'DP-WEBHOOK-1', 'external_id' => $externalId, 'status' => 'PAID']);
$webhookSig  = $dlocal->signWebhookPayload($webhookBody);
$webhookUrl  = "http://127.0.0.1:$appPort/admin/api/webhook_dlocal.php?slug=$testSlug&source=plan";
$r = http('POST', $webhookUrl, [
    'Content-Type: application/json',
    'Authorization: V2-HMAC-SHA256, Signature: ' . $webhookSig,
], $webhookBody);
$webhookJson = json_decode($r['body'], true);
$tests['6_webhook_matched'] = [
    'pass'  => $r['code'] === 200 && ($webhookJson['matched'] ?? false) === true,
    'code'  => $r['code'],
    'body'  => $r['body'],
];

// T7: suscripcion del tenant ahora esta CONFIRMED
$dbAfter = include $tmpDbFile;
$statusFinal = $dbAfter['suscripciones_cliente'][$subInternalId]['status'] ?? 'MISSING';
$tests['7_db_status_confirmed'] = [
    'pass' => $statusFinal === 'CONFIRMED',
    'status' => $statusFinal,
];

// T8: webhook con external_id no existente -> matched=false (no actualiza)
$webhookBody2 = json_encode(['payment_id' => 'DP-X', 'external_id' => 'no_existe', 'status' => 'PAID']);
$webhookSig2  = $dlocal->signWebhookPayload($webhookBody2);
$r = http('POST', $webhookUrl, [
    'Content-Type: application/json',
    'Authorization: V2-HMAC-SHA256, Signature: ' . $webhookSig2,
], $webhookBody2);
$webhookJson2 = json_decode($r['body'], true);
$tests['8_webhook_unmatched'] = [
    'pass' => $r['code'] === 200 && ($webhookJson2['matched'] ?? null) === false,
    'body' => $r['body'],
];

// T9: webhook con HMAC invalido -> 401
$r = http('POST', $webhookUrl, [
    'Content-Type: application/json',
    'Authorization: V2-HMAC-SHA256, Signature: ' . str_repeat('0', 64),
], $webhookBody);
$tests['9_webhook_bad_hmac'] = ['pass' => $r['code'] === 401];

// Cleanup del tenant
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

// Reporte
echo PHP_EOL . "=== Resultados ===" . PHP_EOL;
$pass = 0;
$total = count($tests);
foreach ($tests as $name => $t) {
    $ok = $t['pass'] ?? false;
    if ($ok) $pass++;
    echo ($ok ? '[PASS]' : '[FAIL]') . " $name" . PHP_EOL;
    if (!$ok) {
        echo "  " . json_encode(array_diff_key($t, ['pass' => true]), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    }
}
echo PHP_EOL . "Total: $pass / $total" . PHP_EOL;
exit($pass === $total ? 0 : 1);
