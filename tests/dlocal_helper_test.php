<?php
declare(strict_types=1);
require __DIR__ . '/../src/Core/bootstrap.php';
require __DIR__ . '/../src/Core/Dlocal.php';

use Agenduy\Core\Dlocal;

$d = new Dlocal('test_key', 'test_secret', true);

echo "=== Smoke test Dlocal helper ===" . PHP_EOL;
echo "Base URL:    " . $d->baseUrl() . PHP_EOL;
echo "Checkout:    " . $d->checkoutBase() . PHP_EOL;
echo "Auth header: " . $d->authHeader() . PHP_EOL;
echo "Subscribe:   " . $d->subscribeUrl('PLAN_abc123', 'juan@test.com', 'ext-1') . PHP_EOL;

$payload = '{"payment_id":"DP-1"}';
$sig     = $d->signWebhookPayload($payload);
echo "Sig:        " . $sig . PHP_EOL;

$ok  = $d->verifyWebhookSignature($payload, 'V2-HMAC-SHA256, Signature: ' . $sig);
$ok2 = $d->verifyWebhookSignature('{"payment_id":"DP-2"}', 'V2-HMAC-SHA256, Signature: ' . $sig);
$ok3 = $d->verifyWebhookSignature($payload, $sig); // firma cruda sin prefijo
echo "Verify self:    " . ($ok  ? 'OK' : 'FAIL') . PHP_EOL;
echo "Verify diff:    " . ($ok2 ? 'OK (BUG)' : 'OK (rejected)') . PHP_EOL;
echo "Verify raw sig: " . ($ok3 ? 'OK' : 'FAIL') . PHP_EOL;

// Cross-check con implementacion de la doc
$apiKey    = 'test_key';
$secretKey = 'test_secret';
$payload2  = '{"payment_id":"DP-1"}';
$expected  = hash_hmac('sha256', $apiKey . $payload2, $secretKey);
echo "Cross-check:    " . ($sig === $expected ? 'OK' : 'FAIL (sig='.$sig.' exp='.$expected.')') . PHP_EOL;

// Error message
$err = Dlocal::userErrorMessage(['code' => 3001, 'message' => 'Invalid Credentials.'], 403);
echo "Err 3001:       " . $err . PHP_EOL;
$err2 = Dlocal::userErrorMessage(['code' => 7000, 'message' => 'internal'], 500);
echo "Err 7000:       " . $err2 . PHP_EOL;
$err3 = Dlocal::userErrorMessage(['code' => 0, 'message' => '', 'errors' => [['message' => 'amount required']]], 422);
echo "Err 422:        " . $err3 . PHP_EOL;

// fromConfig
try {
    $d2 = Dlocal::fromConfig(['dlocal' => ['api_key' => 'k', 'secret_key' => 's', 'sandbox' => false]]);
    echo "fromConfig A:   OK (live=" . ($d2->isSandbox() ? 'no' : 'yes') . ")" . PHP_EOL;
} catch (Throwable $e) {
    echo "fromConfig A:   FAIL " . $e->getMessage() . PHP_EOL;
}
try {
    $d3 = Dlocal::fromConfig(['api_key' => 'k', 'secret_key' => 's']);
    echo "fromConfig B:   OK" . PHP_EOL;
} catch (Throwable $e) {
    echo "fromConfig B:   FAIL" . PHP_EOL;
}
try {
    Dlocal::fromConfig(['dlocal' => ['api_key' => '', 'secret_key' => '']]);
    echo "fromConfig C:   FAIL (deberia haber lanzado)" . PHP_EOL;
} catch (Throwable $e) {
    echo "fromConfig C:   OK (lanzo: " . $e->getMessage() . ")" . PHP_EOL;
}

echo PHP_EOL . "=== Done ===" . PHP_EOL;
