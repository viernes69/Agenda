<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/Core/bootstrap.php';

use Agenduy\Core\Database;

$db = Database::getInstance();
$pdo = $db->pdo();

$sql = $pdo->query(
    "SELECT sql FROM sqlite_master WHERE type='table' AND name='payment_provider_config'"
)->fetchColumn();

$hasGoogle = is_string($sql) && str_contains($sql, "'google_oauth'");
echo 'CHECK google_oauth: ' . ($hasGoogle ? 'OK' : 'MISSING') . PHP_EOL;

$pdo->exec("DELETE FROM payment_provider_config WHERE provider = 'google_oauth'");
$db->insert('payment_provider_config', [
    'provider'    => 'google_oauth',
    'is_enabled'  => 1,
    'config_json' => json_encode(['client_id' => 'test.apps.googleusercontent.com'], JSON_UNESCAPED_UNICODE),
    'notes'       => 'test',
]);
$row = $db->fetchOne("SELECT * FROM payment_provider_config WHERE provider = 'google_oauth'");
$ok = is_array($row) && ($row['provider'] ?? '') === 'google_oauth';

echo ($ok ? '[PASS]' : '[FAIL]') . ' insert google_oauth provider' . PHP_EOL;

$pdo->exec("DELETE FROM payment_provider_config WHERE provider = 'google_oauth'");
exit($hasGoogle && $ok ? 0 : 1);
