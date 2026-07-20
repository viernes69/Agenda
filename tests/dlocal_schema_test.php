<?php
declare(strict_types=1);

$dbPath = 'C:\xampp\htdocs\agenduy.uy\storage\agenduy.db';
$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "=== Antes de la migracion ===" . PHP_EOL;
foreach (['subscriptions', 'payment_provider_config', 'api_keys'] as $t) {
    $sql = $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='$t'")->fetchColumn();
    echo "$t: " . ($sql ? 'EXISTS' : 'MISSING') . PHP_EOL;
    if ($sql) {
        echo "  Has dlocal: " . (strpos($sql, "'dlocal'") !== false ? 'YES' : 'NO') . PHP_EOL;
        echo "  " . substr(str_replace(["\n", "\t"], ' ', $sql), 0, 200) . '...' . PHP_EOL;
    }
}
echo PHP_EOL;

// Snapshot de counts
$counts = [];
foreach (['subscriptions', 'payment_provider_config', 'api_keys'] as $t) {
    try {
        $counts[$t] = (int)$pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn();
    } catch (Throwable $e) {
        $counts[$t] = -1;
    }
}
echo "Counts antes: " . json_encode($counts) . PHP_EOL;
echo PHP_EOL;

// Aplicar la migracion via Database
require __DIR__ . '/../src/Core/bootstrap.php';
$config = require __DIR__ . '/../src/Core/config.php';
$db = Agenduy\Core\Database::getInstance($config);

echo "=== Despues de la migracion ===" . PHP_EOL;
foreach (['subscriptions', 'payment_provider_config', 'api_keys'] as $t) {
    $sql = $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='$t'")->fetchColumn();
    echo "$t: " . ($sql ? 'EXISTS' : 'MISSING') . PHP_EOL;
    if ($sql) {
        echo "  Has dlocal: " . (strpos($sql, "'dlocal'") !== false ? 'YES' : 'NO') . PHP_EOL;
    }
}
echo PHP_EOL;
$countsAfter = [];
foreach (['subscriptions', 'payment_provider_config', 'api_keys'] as $t) {
    try {
        $countsAfter[$t] = (int)$pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn();
    } catch (Throwable $e) {
        $countsAfter[$t] = -1;
    }
}
echo "Counts despues: " . json_encode($countsAfter) . PHP_EOL;
echo "OK: " . ($counts === $countsAfter ? 'SI (datos preservados)' : 'NO (datos perdidos!)') . PHP_EOL;
echo PHP_EOL;

// Probar INSERT con dlocal
echo "=== Test INSERT con dlocal ===" . PHP_EOL;
try {
    $pdo->exec("INSERT INTO payment_provider_config (provider, is_enabled, config_json) VALUES ('dlocal', 0, '{\"test\":true}')");
    echo "INSERT dlocal en payment_provider_config: OK" . PHP_EOL;
    $row = $pdo->query("SELECT * FROM payment_provider_config WHERE provider='dlocal'")->fetch(PDO::FETCH_ASSOC);
    echo "  Row: " . json_encode($row) . PHP_EOL;
    $pdo->exec("DELETE FROM payment_provider_config WHERE provider='dlocal'");
    echo "  Cleanup OK" . PHP_EOL;
} catch (Throwable $e) {
    echo "INSERT dlocal FALLO: " . $e->getMessage() . PHP_EOL;
}
