#!/usr/bin/env php
<?php
/**
 * UltraMsg diagnostic — configure credentials and test connection.
 * Usage:
 *   php bin/diagnose_ultramsg.php                          # Show current config
 *   php bin/diagnose_ultramsg.php set <instance_id> <token> # Save credentials
 *   php bin/diagnose_ultramsg.php test <phone>              # Send test message
 */
declare(strict_types=1);

require __DIR__ . '/../src/Core/bootstrap.php';

use Agenduy\Core\Database;
use Agenduy\Core\ProviderConfig;
use Agenduy\Core\UltraMsg;

$db = Database::getInstance();

$action = $argv[1] ?? 'status';

// --- STATUS ---
if ($action === 'status' || $action === '') {
    echo "=== UltraMsg Configuration ===\n\n";

    $row = $db->fetchOne(
        'SELECT config_json, is_enabled FROM payment_provider_config WHERE provider = :p',
        [':p' => 'ultramsg']
    );

    if (!$row) {
        echo "Status: NOT CONFIGURED\n";
        echo "No UltraMsg record in payment_provider_config.\n\n";
        echo "To configure:\n";
        echo "  php bin/diagnose_ultramsg.php set <instance_id> <token>\n\n";
        echo "Get credentials at: https://ultramsg.com/api\n";
        exit(0);
    }

    $cfg = json_decode((string)$row['config_json'], true);
    $enabled = (int)$row['is_enabled'] === 1;
    $instanceId = (string)($cfg['instance_id'] ?? '');
    $token = (string)($cfg['token'] ?? '');

    echo "Enabled:    " . ($enabled ? 'YES' : 'NO') . "\n";
    echo "Instance:   " . ($instanceId !== '' ? $instanceId : '(empty)') . "\n";
    echo "Token:      " . ($token !== '' ? 'SET (' . strlen($token) . ' chars)' : '(empty)') . "\n";

    if (!$enabled) {
        echo "\n⚠️  UltraMsg is disabled. Enable it in Admin → Configuración global.\n";
    }
    if ($instanceId === '' || $token === '') {
        echo "\n⚠️  Missing credentials. Run:\n";
        echo "   php bin/diagnose_ultramsg.php set <instance_id> <token>\n";
    }
    exit(0);
}

// --- SET ---
if ($action === 'set') {
    $instanceId = trim((string)($argv[2] ?? ''));
    $token = trim((string)($argv[3] ?? ''));

    if ($instanceId === '' || $token === '') {
        echo "Usage: php bin/diagnose_ultramsg.php set <instance_id> <token>\n";
        echo "Get credentials at: https://ultramsg.com/api\n";
        exit(1);
    }

    $existing = $db->fetchOne(
        'SELECT id_config FROM payment_provider_config WHERE provider = :p',
        [':p' => 'ultramsg']
    );

    $config = json_encode([
        'instance_id' => $instanceId,
        'token' => $token,
    ], JSON_UNESCAPED_UNICODE);

    $now = date('Y-m-d H:i:s');

    if ($existing) {
        $db->update('payment_provider_config', [
            'is_enabled' => 1,
            'config_json' => $config,
            'updated_at' => $now,
        ], 'provider = :p', [':p' => 'ultramsg']);
    } else {
        $db->insert('payment_provider_config', [
            'provider' => 'ultramsg',
            'is_enabled' => 1,
            'config_json' => $config,
            'updated_at' => $now,
        ]);
    }

    echo "✅ UltraMsg credentials saved and enabled.\n";
    echo "   Instance: {$instanceId}\n";
    echo "   Token:    SET (" . strlen($token) . " chars)\n\n";
    echo "Test with: php bin/diagnose_ultramsg.php test <phone>\n";
    exit(0);
}

// --- TEST ---
if ($action === 'test') {
    $phone = trim((string)($argv[2] ?? ''));

    if ($phone === '') {
        echo "Usage: php bin/diagnose_ultramsg.php test <phone>\n";
        echo "Example: php bin/diagnose_ultramsg.php test +59891234567\n";
        exit(1);
    }

    $cfg = ProviderConfig::ultraMsgConfig();
    echo "=== UltraMsg Connection Test ===\n\n";
    echo "Instance:   " . ($cfg['instance_id'] ?: '(empty)') . "\n";
    echo "Token:      " . ($cfg['token'] ? 'SET' : '(empty)') . "\n";
    echo "Enabled:    " . ($cfg['enabled'] ? 'YES' : 'NO') . "\n\n";

    if (!$cfg['enabled'] || $cfg['instance_id'] === '' || $cfg['token'] === '') {
        echo "❌ UltraMsg not configured. Run:\n";
        echo "   php bin/diagnose_ultramsg.php set <instance_id> <token>\n";
        exit(1);
    }

    // First, test the API connection by fetching instance status
    $statusUrl = sprintf(
        'https://api.ultramsg.com/%s/instance/status?token=%s',
        rawurlencode($cfg['instance_id']),
        rawurlencode($cfg['token'])
    );

    echo "Testing API connection...\n";
    $ch = curl_init($statusUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    $errno = curl_errno($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno !== 0) {            echo "❌ Connection error: {$errno}\n";
        exit(1);
    }

    $statusData = json_decode((string)$response, true);
    echo "HTTP {$httpCode}\n";
    echo "Response: " . json_encode($statusData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

    if ($httpCode === 401 || ($statusData['result'] ?? '') === 'error') {
        echo "❌ Authentication failed. Check your instance_id and token.\n";
        echo "   Get correct credentials at: https://ultramsg.com/api\n";
        exit(1);
    }

    if ($httpCode >= 200 && $httpCode < 300) {
        echo "✅ API connection OK!\n\n";

        // Now send a test message
        echo "Sending test message to {$phone}...\n";
        try {
            $ok = UltraMsg::send($phone, "🔔 Test de notificación — Agendarte UY\nSi recibiste este mensaje, WhatsApp está funcionando correctamente.");
            if ($ok) {
                echo "✅ Test message sent successfully!\n";
                echo "   Check WhatsApp on {$phone}\n";
            } else {
                echo "⚠️  Message returned false. UltraMsg may be disabled or number invalid.\n";
            }
        } catch (\Throwable $e) {
            echo "❌ Error: " . $e->getMessage() . "\n";
        }
    } else {
        echo "⚠️  Unexpected response. Check UltraMsg dashboard for instance status.\n";
    }
    exit(0);
}

echo "Unknown action: {$action}\n";
echo "Usage:\n";
echo "  php bin/diagnose_ultramsg.php                     # Show config status\n";
echo "  php bin/diagnose_ultramsg.php set <inst> <token>  # Save credentials\n";
echo "  php bin/diagnose_ultramsg.php test <phone>        # Test connection\n";
exit(1);
