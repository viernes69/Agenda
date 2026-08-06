#!/usr/bin/env php
<?php
/**
 * CLI email test — verifies PHPMailer + SMTP end-to-end.
 * Usage: php bin/test_mail_cli.php [email@example.com]
 */
declare(strict_types=1);

require __DIR__ . '/../src/Core/bootstrap.php';

use Agenduy\Core\Mail;
use Agenduy\Core\ProviderConfig;

if (!isset($argv[1]) || $argv[1] === '') {
    echo "Usage: php bin/test_mail_cli.php email@example.com\n";
    exit(1);
}
$to = $argv[1];

echo "=== Email Test (CLI) ===\n\n";

// 1. Check diagnostics
$diag = ProviderConfig::mailDiagnostics();
echo "SMTP enabled:      " . ($diag['enabled'] ? 'YES' : 'NO') . "\n";
echo "SMTP configured:   " . ($diag['configured'] ? 'YES' : 'NO') . "\n";
echo "PHPMailer present: " . ($diag['phpmailer'] ? 'YES' : 'NO') . "\n";
echo "Host:              " . ($diag['host'] ?: '(empty)') . "\n";
echo "Username:          " . ($diag['username'] ?: '(empty)') . "\n";
echo "From:              " . ($diag['from_email'] ?: '(empty)') . "\n";
echo "Has password:      " . ($diag['has_password'] ? 'YES' : 'NO') . "\n";

if (!$diag['enabled']) {
    echo "\n❌ SMTP is disabled. Enable it in Admin → Configuración global.\n";
    exit(1);
}
if (!$diag['phpmailer']) {
    echo "\n❌ PHPMailer not installed. Run: composer install\n";
    exit(1);
}
if (!$diag['configured']) {
    echo "\n❌ SMTP incomplete. Check host, username, and password.\n";
    echo "   Hint: run php bin/fix_smtp_config.php to switch to Gmail SMTP.\n";
    exit(1);
}

// 2. Send test email
echo "\nSending test email to: {$to}\n";

$subject = 'Prueba SMTP - Agendarte (' . date('Y-m-d H:i:s') . ')';
$html = '<h2>✅ Email de prueba exitoso</h2>'
    . '<p>Este email fue enviado desde <b>bin/test_mail_cli.php</b>.</p>'
    . '<p>Fecha: ' . date('d/m/Y H:i:s') . '</p>'
    . '<p>Si lo recibiste, PHPMailer + SMTP funcionan correctamente.</p>';

$sent = Mail::send($to, $subject, $html);

if ($sent) {
    echo "✅ Email enviado exitosamente a {$to}\n";
    echo "   Revisá bandeja de entrada y spam.\n";
    exit(0);
} else {
    $err = Mail::lastError() ?? 'Unknown error';
    echo "❌ Error al enviar: {$err}\n";
    exit(1);
}
