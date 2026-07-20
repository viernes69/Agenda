<?php
/**
 * CLI: Configurar dLocal en un tenant desde la linea de comando.
 *
 * Uso:
 *   php tests/seed_dlocal.php <slug> <api_key> <secret_key> [sandbox:0|1]
 *
 * Ejemplo:
 *   php tests/seed_dlocal.php la-estetica abc123 def456 1
 *
 * Tambien lee de env vars (DLOCAL_API_KEY, DLOCAL_SECRET_KEY, DLOCAL_SANDBOX)
 * si no se pasan por linea de comandos.
 *
 * NO commitea credenciales. Solo escribe en {slug}/src/db/database.php.
 */
declare(strict_types=1);

require_once __DIR__ . '/../src/Core/bootstrap.php';

use Agenduy\Core\TenantLocalDb;

function out(string $msg): void
{
    fwrite(STDOUT, $msg . PHP_EOL);
}

function fail(string $msg, int $code = 1): never
{
    fwrite(STDERR, "ERROR: $msg" . PHP_EOL);
    exit($code);
}

$slug       = trim((string)($argv[1] ?? getenv('DLOCAL_SEED_SLUG') ?? ''));
$apiKey     = trim((string)($argv[2] ?? getenv('DLOCAL_API_KEY')    ?? ''));
$secretKey  = trim((string)($argv[3] ?? getenv('DLOCAL_SECRET_KEY') ?? ''));
$sandboxRaw = (string)($argv[4] ?? getenv('DLOCAL_SANDBOX') ?? '1');

if ($slug === '' || $apiKey === '' || $secretKey === '') {
    out("Uso: php tests/seed_dlocal.php <slug> <api_key> <secret_key> [sandbox:0|1]");
    out("      o via env vars: DLOCAL_SEED_SLUG, DLOCAL_API_KEY, DLOCAL_SECRET_KEY, DLOCAL_SANDBOX");
    fail('Faltan argumentos obligatorios');
}

if (!preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug)) {
    fail("Slug invalido: $slug");
}

$sandbox = $sandboxRaw === '1' || strtolower($sandboxRaw) === 'true';

if (!TenantLocalDb::exists($slug)) {
    fail("Tenant no encontrado: $slug (path: " . TenantLocalDb::pathForSlug($slug) . ')');
}

out("Configurando dLocal para tenant: $slug");
out("  Modo: " . ($sandbox ? 'sandbox' : 'live'));
out("  API key: " . substr($apiKey, 0, 4) . '…' . substr($apiKey, -4));

TenantLocalDb::mutate($slug, function (array $db) use ($apiKey, $secretKey, $sandbox) {
    $db['dlocal'] = [
        'api_key'    => $apiKey,
        'secret_key' => $secretKey,
        'sandbox'    => $sandbox,
        'updated_at' => date('Y-m-d H:i:s'),
    ];
    return [$db, null];
});

out("OK. Verifico que se leyo correctamente:");

$db = TenantLocalDb::read($slug);
$cfg = is_array($db) && isset($db['dlocal']) ? $db['dlocal'] : null;
if ($cfg === null) {
    fail("No se guardo la config.");
}

out("  api_key: " . substr((string)$cfg['api_key'], 0, 4) . '…' . substr((string)$cfg['api_key'], -4));
out("  sandbox: " . (!empty($cfg['sandbox']) ? 'true' : 'false'));

// Test de conexion contra dLocal (no aborta el seed si falla)
out("");
out("Probando conexion con dLocal...");
try {
    $client = Agenduy\Core\Dlocal::fromConfig(['dlocal' => $cfg]);
    $plans = $client->listPlans();
    out("  Conexion OK. Planes existentes: " . count($plans));
    foreach ($plans as $p) {
        if (!is_array($p)) continue;
        $pid = (int)($p['id'] ?? 0);
        $name = (string)($p['name'] ?? '');
        $cur = (string)($p['currency'] ?? '');
        $amt = (float)($p['amount'] ?? 0);
        $freq = (string)($p['frequency_type'] ?? '');
        $tok = (string)($p['plan_token'] ?? '');
        out("  - #$pid $name $cur $amt / $freq (token=$tok)");
    }
} catch (Throwable $e) {
    out("  Aviso: no se pudo conectar a dLocal: " . $e->getMessage());
    out("  La config quedo guardada, pero las credenciales pueden ser invalidas.");
}

out("");
out("Listo. Webhook URL para este tenant:");
out("  " . rtrim((string)url_base(), '/') . '/admin/api/webhook_dlocal.php?slug=' . rawurlencode($slug) . '&source=plan');
