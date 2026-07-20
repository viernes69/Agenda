<?php
/**
 * Regression: PayPal must not apply paid plan until capture.
 * Run: C:\xampp\php\php.exe tests/paypal_status_fix.php
 */
declare(strict_types=1);

$failed = 0;
function assert_true(bool $cond, string $msg): void
{
    global $failed;
    if ($cond) {
        echo "OK  {$msg}\n";
        return;
    }
    $failed++;
    echo "FAIL {$msg}\n";
}

$root = dirname(__DIR__);
$src = file_get_contents($root . '/admin/api/paypal.php');
assert_true(is_string($src) && $src !== '', 'paypal.php readable');

assert_true(
    !preg_match("/paypalUpsertSubscription\s*\(\s*[^)]*'pending'/s", (string)$src),
    'paypalUpsertSubscription must not be called with status=pending'
);
assert_true(
    !preg_match("/\\\$localStatus\s*=\s*[^;]*'pending'/s", (string)$src),
    'localStatus must not resolve to pending'
);
assert_true(str_contains((string)$src, 'paypalKeepSubscriptionStatus'), 'uses keep-status helper');
assert_true(str_contains((string)$src, "Never write 'pending'"), 'documents CHECK constraint');
assert_true(str_contains((string)$src, 'payment_pending'), 'API still signals payment pending');
assert_true(str_contains((string)$src, 'encodePendingMembershipNote'), 'stores pending membership in notes');
assert_true(str_contains((string)$src, 'effectiveMembershipId'), 'keeps effective membership until capture');
// create_order path must not write id_membership onto commerces before capture
assert_true(
    preg_match(
        "/payment_pending'\s*=>\s*true.*?effective_membership_id/s",
        (string)$src
    ) === 1,
    'create_order response exposes effective vs pending membership'
);
assert_true(
    !preg_match(
        "/Remember intended plan; do not flip commerce status.*?id_membership'\s*=>\s*\\\$idMembership/s",
        (string)$src
    ),
    'create_order no longer sets commerces.id_membership prematurely'
);

require $root . '/src/Core/MembershipPlan.php';
use Agenduy\Core\MembershipPlan;

$note = MembershipPlan::encodePendingMembershipNote(6, 4, 'PayPal order pendiente de aprobación: ORD1');
assert_true(str_contains($note, 'pending_membership_id=6'), 'encode pending id');
assert_true(str_contains($note, 'previous_membership_id=4'), 'encode previous id');
$parsed = MembershipPlan::parsePendingMembershipNote($note);
assert_true(is_array($parsed) && $parsed['pending_id'] === 6 && $parsed['previous_id'] === 4, 'parse pending note');
assert_true(MembershipPlan::clearPendingMembershipNote($note) === '', 'clear pending note');
assert_true(MembershipPlan::parsePendingMembershipNote('sin pending') === null, 'non-pending notes parse null');

// SQLite CHECK mirrors schema: pending rejected on subscriptions
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = OFF');
$pdo->exec('CREATE TABLE memberships (id_membership INTEGER PRIMARY KEY, nombre TEXT, precio REAL)');
$pdo->exec("INSERT INTO memberships (id_membership, nombre, precio) VALUES (4, 'Free', 0), (6, 'Profesional', 599)");
$pdo->exec('CREATE TABLE commerces (id_commerce INTEGER PRIMARY KEY, id_membership INTEGER, status TEXT, nombre TEXT)');
$pdo->exec("INSERT INTO commerces (id_commerce, id_membership, status, nombre) VALUES (1, 4, 'trial', 'Terap')");
$pdo->exec("CREATE TABLE subscriptions (
    id_subscription INTEGER PRIMARY KEY AUTOINCREMENT,
    id_commerce INTEGER NOT NULL,
    id_membership INTEGER NOT NULL,
    status TEXT NOT NULL DEFAULT 'trial'
      CHECK (status IN ('trial','active','past_due','cancelled')),
    gateway TEXT DEFAULT NULL,
    gateway_id TEXT DEFAULT NULL,
    notes TEXT DEFAULT '',
    billing_period TEXT DEFAULT 'monthly'
)");

$pendingFailed = false;
try {
    $pdo->exec("INSERT INTO subscriptions (id_commerce, id_membership, status, gateway, notes)
                VALUES (1, 1, 'pending', 'paypal', 'bad')");
} catch (PDOException $e) {
    $pendingFailed = str_contains($e->getMessage(), 'CHECK');
}
assert_true($pendingFailed, 'subscriptions reject status=pending (CHECK)');

// Simulate create_order: keep Free on commerce + subscription, pending in notes
$notes = MembershipPlan::encodePendingMembershipNote(6, 4, 'PayPal order pendiente de aprobación: ORDER-TEST-1');
$pdo->exec("INSERT INTO subscriptions (id_commerce, id_membership, status, gateway, gateway_id, notes, billing_period)
            VALUES (1, 4, 'trial', 'paypal', 'ORDER-TEST-1', " . $pdo->quote($notes) . ", 'monthly')");
$subId = (int)$pdo->lastInsertId();
assert_true($subId > 0, 'create_order-style insert with trial succeeds');

$row = $pdo->query('SELECT status, notes, gateway_id, id_membership FROM subscriptions WHERE id_subscription = ' . $subId)
    ->fetch(PDO::FETCH_ASSOC);
$com = $pdo->query('SELECT id_membership, status FROM commerces WHERE id_commerce = 1')->fetch(PDO::FETCH_ASSOC);
assert_true(($row['status'] ?? '') === 'trial', 'subscription remains trial while PayPal pending');
assert_true((int)($row['id_membership'] ?? 0) === 4, 'subscription keeps Free membership while pending');
assert_true((int)($com['id_membership'] ?? 0) === 4, 'commerce keeps Free membership while pending');
assert_true(str_contains((string)($row['notes'] ?? ''), 'pending_membership_id=6'), 'pending tracked in notes');
assert_true(($row['gateway_id'] ?? '') === 'ORDER-TEST-1', 'order id stored as gateway_id');

// Simulate capture: apply pending Profesional + active
$pdo->exec("UPDATE subscriptions SET status='active', id_membership=6, notes='' WHERE id_subscription = {$subId}");
$pdo->exec("UPDATE commerces SET status='active', id_membership=6 WHERE id_commerce = 1");
$sub = $pdo->query('SELECT status, notes, id_membership FROM subscriptions WHERE id_subscription = ' . $subId)->fetch(PDO::FETCH_ASSOC);
$com = $pdo->query('SELECT status, id_membership FROM commerces WHERE id_commerce = 1')->fetch(PDO::FETCH_ASSOC);
assert_true(($sub['status'] ?? '') === 'active', 'capture sets subscription active');
assert_true((int)($sub['id_membership'] ?? 0) === 6, 'capture applies pending membership');
assert_true(($sub['notes'] ?? '') === '', 'capture clears notes');
assert_true(($com['status'] ?? '') === 'active', 'capture sets commerce active');
assert_true((int)($com['id_membership'] ?? 0) === 6, 'capture sets commerce membership');

echo $failed === 0 ? "\nALL PASSED\n" : "\n{$failed} FAILED\n";
exit($failed === 0 ? 0 : 1);
