<?php
declare(strict_types=1);

$pdo = new PDO('sqlite:' . dirname(__DIR__) . '/storage/agenduy.db');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$c = $pdo->query("SELECT id_commerce, slug, status, id_membership FROM commerces WHERE slug = 'terap'")
    ->fetch(PDO::FETCH_ASSOC);
if (!$c) {
    echo "terap not found\n";
    exit(1);
}
$id = (int)$c['id_commerce'];
$sub = $pdo->query(
    "SELECT id_subscription, status, gateway, gateway_id, notes
     FROM subscriptions WHERE id_commerce = {$id}
     ORDER BY id_subscription DESC LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);

echo "commerce status={$c['status']}\n";
echo 'subscription: ' . json_encode($sub, JSON_UNESCAPED_UNICODE) . "\n";

$allowed = ['trial', 'active', 'past_due', 'cancelled'];
$keep = ($sub && in_array((string)$sub['status'], $allowed, true)) ? (string)$sub['status'] : 'trial';

$pdo->beginTransaction();
try {
    try {
        $pdo->prepare('UPDATE subscriptions SET status = ? WHERE id_commerce = ?')
            ->execute(['pending', $id]);
        echo "BUG: pending write succeeded\n";
        exit(1);
    } catch (Throwable $e) {
        echo 'Old path CHECK fail (expected): '
            . (str_contains($e->getMessage(), 'CHECK') ? 'YES' : $e->getMessage()) . "\n";
    }

    $orderId = 'ORDER-VERIFY-' . bin2hex(random_bytes(3));
    $notes = 'PayPal order pendiente de aprobación: ' . $orderId;
    if ($sub) {
        $pdo->prepare(
            "UPDATE subscriptions
             SET status = ?, gateway = 'paypal', gateway_id = ?, notes = ?, updated_at = datetime('now')
             WHERE id_subscription = ?"
        )->execute([$keep, $orderId, $notes, (int)$sub['id_subscription']]);
    } else {
        $pdo->prepare(
            'INSERT INTO subscriptions
             (id_commerce, id_membership, status, gateway, gateway_id, notes, started_at)
             VALUES (?, ?, ?, ?, ?, ?, datetime(\'now\'))'
        )->execute([$id, (int)$c['id_membership'], $keep, 'paypal', $orderId, $notes]);
    }

    $row = $pdo->query(
        "SELECT status, gateway_id, notes FROM subscriptions
         WHERE id_commerce = {$id} ORDER BY id_subscription DESC LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);
    echo 'Fixed path OK: ' . json_encode($row, JSON_UNESCAPED_UNICODE) . "\n";
    echo "create_order would not 500 on CHECK; approve_link reachable\n";
} finally {
    $pdo->rollBack();
    echo "Rolled back.\n";
}
