#!/usr/bin/env php
<?php
/**
 * Fix SMTP config — switch from broken host to Gmail SMTP.
 * Usage: php bin/fix_smtp_config.php
 */
declare(strict_types=1);

require __DIR__ . '/../src/Core/bootstrap.php';

use Agenduy\Core\Database;
use Agenduy\Core\ProviderConfig;

$db = Database::getInstance();

echo "=== SMTP Config Fix ===\n\n";

// Get current config
$row = $db->fetchOne(
    'SELECT config_json, is_enabled FROM payment_provider_config WHERE provider = :p',
    [':p' => 'smtp']
);

if (!$row) {
    echo "No SMTP config found in database.\n";
    exit(1);
}

$cfg = json_decode((string)$row['config_json'], true);
echo "Current host:       " . ($cfg['host'] ?? '(empty)') . "\n";
echo "Current port:       " . ($cfg['port'] ?? '(empty)') . "\n";
echo "Current encryption: " . ($cfg['encryption'] ?? '(empty)') . "\n";
echo "Username:           " . ($cfg['username'] ?? '(empty)') . "\n";
echo "From email:         " . ($cfg['from_email'] ?? '(empty)') . "\n";
echo "Has password:       " . (!empty($cfg['password']) ? 'YES' : 'NO') . "\n\n";

// Update to Gmail SMTP
$cfg['host'] = 'smtp.gmail.com';
$cfg['port'] = 587;
$cfg['encryption'] = 'tls';

$json = json_encode($cfg, JSON_UNESCAPED_UNICODE);
$db->update('payment_provider_config', [
    'config_json' => $json,
    'updated_at'   => date('Y-m-d H:i:s'),
], 'provider = :p', [':p' => 'smtp']);

echo "✅ Config updated to Gmail SMTP:\n";
echo "   Host: smtp.gmail.com\n";
echo "   Port: 587\n";
echo "   Encryption: TLS\n\n";

if (empty($cfg['password'])) {
    echo "⚠️  No password stored. Set a Gmail App Password before testing.\n";
    echo "   See: https://support.google.com/accounts/answer/185833\n\n";
} else {
    echo "Now run: php bin/test_mail_cli.php your@email.com\n";
}
