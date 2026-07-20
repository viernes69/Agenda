<?php
declare(strict_types=1);

$pdo = new PDO('sqlite:' . dirname(__DIR__) . '/storage/agenduy.db');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$sql = $pdo->query("SELECT sql FROM sqlite_master WHERE name='subscriptions'")->fetchColumn();
echo "SCHEMA CHECK snippet:\n";
echo (preg_match("/CHECK \\(status IN \\([^)]+\\)\\)/", (string)$sql, $m) ? $m[0] : $sql) . "\n\n";

$c = $pdo->query("SELECT id_commerce, slug, status, id_membership FROM commerces WHERE slug = 'terap'")->fetch(PDO::FETCH_ASSOC);
if (!$c) {
    echo "Commerce terap not found\n";
    exit(1);
}
print_r($c);
$id = (int)$c['id_commerce'];
$sub = $pdo->query("SELECT id_subscription, status, gateway, notes FROM subscriptions WHERE id_commerce = {$id} ORDER BY id_subscription DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
echo "Subscription:\n";
print_r($sub ?: ['(none)']);

$stmt = $pdo->prepare("SELECT COUNT(*) FROM payment_transfers WHERE id_commerce = ? AND status = 'pending'");
$stmt->execute([$id]);
echo "Pending transfers: " . $stmt->fetchColumn() . "\n";

$pdo->beginTransaction();
try {
    $pdo->exec("UPDATE subscriptions SET status = 'pending' WHERE id_commerce = {$id}");
    echo "UNEXPECTED: pending update succeeded\n";
    $pdo->rollBack();
    exit(1);
} catch (Throwable $e) {
    echo "CHECK blocks pending: " . (str_contains($e->getMessage(), 'CHECK') ? 'YES' : $e->getMessage()) . "\n";
    $pdo->rollBack();
}

// Simulate fixed path: keep trial, insert pending transfer, rollback
$pdo->beginTransaction();
$keep = ($sub && in_array($sub['status'], ['trial','active','past_due','cancelled'], true)) ? $sub['status'] : 'trial';
$pdo->prepare("UPDATE subscriptions SET status = ?, gateway = 'transfer', notes = ? WHERE id_commerce = ?")
    ->execute([$keep, 'Transferencia pendiente de aprobación.', $id]);
$fecha = '2026-07-18';
$pdo->prepare("INSERT INTO payment_transfers (id_commerce, id_subscription, monto, moneda, fecha_transferencia, status)
               VALUES (?, ?, 299, 'UYU', ?, 'pending')")
    ->execute([$id, $sub['id_subscription'] ?? null, $fecha]);
$newId = (int)$pdo->lastInsertId();
echo "Simulated pending transfer id={$newId} with sub status={$keep} OK\n";
$pdo->rollBack();
echo "Rolled back simulation.\n";
