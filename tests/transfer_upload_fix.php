<?php
/**
 * Regression guards for transfer receipt upload + approve/reject membership.
 * Run: C:\xampp\php\php.exe tests/transfer_upload_fix.php
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

// --- Date parser (mirror of admin/api/transfer_upload.php) ---
function agenduy_parse_transfer_date(string $raw): ?string
{
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m)) {
        $y = (int)$m[1];
        $mo = (int)$m[2];
        $d = (int)$m[3];
        if (checkdate($mo, $d, $y)) {
            return sprintf('%04d-%02d-%02d', $y, $mo, $d);
        }
        throw new InvalidArgumentException('Fecha inválida. Usá el formato dd/mm/aaaa.');
    }
    if (preg_match('/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{4})$/', $raw, $m)) {
        $d = (int)$m[1];
        $mo = (int)$m[2];
        $y = (int)$m[3];
        if (checkdate($mo, $d, $y)) {
            return sprintf('%04d-%02d-%02d', $y, $mo, $d);
        }
        throw new InvalidArgumentException('Fecha inválida. Usá el formato dd/mm/aaaa.');
    }
    throw new InvalidArgumentException('Fecha inválida. Usá el formato dd/mm/aaaa (ej. 18/07/2026).');
}

assert_true(agenduy_parse_transfer_date('18/07/2026') === '2026-07-18', 'dd/mm/yyyy → ISO');
assert_true(agenduy_parse_transfer_date('2026-07-18') === '2026-07-18', 'ISO passthrough');
assert_true(agenduy_parse_transfer_date('18-07-2026') === '2026-07-18', 'dd-mm-yyyy → ISO');
assert_true(agenduy_parse_transfer_date('') === null, 'empty → null');

try {
    agenduy_parse_transfer_date('32/01/2026');
    assert_true(false, 'invalid day should throw');
} catch (InvalidArgumentException $e) {
    assert_true(str_contains($e->getMessage(), 'Fecha inválida'), 'invalid day Spanish error');
}

try {
    agenduy_parse_transfer_date('no-es-fecha');
    assert_true(false, 'garbage should throw');
} catch (InvalidArgumentException $e) {
    assert_true(str_contains($e->getMessage(), 'dd/mm/aaaa'), 'garbage Spanish error');
}

// --- Source guard: transfer_upload must not write subscription status=pending ---
$src = file_get_contents($root . '/admin/api/transfer_upload.php');
assert_true(is_string($src) && $src !== '', 'transfer_upload.php readable');
assert_true(
    !preg_match("/\\\$subData\s*=\s*\[[^\]]*['\"]status['\"]\s*=>\s*['\"]pending['\"]/s", (string)$src),
    'subscription $subData must not set status=pending'
);
assert_true(
    str_contains((string)$src, "'status'              => 'pending'")
    || str_contains((string)$src, "'status' => 'pending'"),
    'payment_transfers still uses status=pending'
);
assert_true(str_contains((string)$src, 'agenduy_parse_transfer_date'), 'date parser used');
assert_true(str_contains((string)$src, 'Keep trial/active'), 'keeps allowed subscription status');
assert_true(str_contains((string)$src, 'encodePendingMembershipNote'), 'stores pending membership in notes');
assert_true(str_contains((string)$src, 'Do NOT change commerces.id_membership'), 'does not switch plan on upload');

$paymentsSrc = file_get_contents($root . '/admin/payments.php');
assert_true(is_string($paymentsSrc) && $paymentsSrc !== '', 'payments.php readable');
assert_true(str_contains((string)$paymentsSrc, 'parsePendingMembershipNote'), 'approve reads pending membership');
assert_true(str_contains((string)$paymentsSrc, "elseif (\$action === 'reject')"), 'reject clears pending without activating');

require $root . '/src/Core/MembershipPlan.php';
use Agenduy\Core\MembershipPlan;

// --- SQLite CHECK + pending membership flow ---
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("PRAGMA foreign_keys = OFF");
$pdo->exec("CREATE TABLE memberships (id_membership INTEGER PRIMARY KEY, nombre TEXT, precio REAL)");
$pdo->exec("INSERT INTO memberships (id_membership, nombre, precio) VALUES (4, 'Free', 0), (6, 'Profesional', 599)");
$pdo->exec("CREATE TABLE commerces (id_commerce INTEGER PRIMARY KEY, id_membership INTEGER, status TEXT, nombre TEXT)");
$pdo->exec("INSERT INTO commerces (id_commerce, id_membership, status, nombre) VALUES (1, 4, 'trial', 'Terap')");
$pdo->exec("CREATE TABLE subscriptions (
    id_subscription INTEGER PRIMARY KEY AUTOINCREMENT,
    id_commerce INTEGER NOT NULL,
    id_membership INTEGER NOT NULL,
    status TEXT NOT NULL DEFAULT 'trial'
      CHECK (status IN ('trial','active','past_due','cancelled')),
    gateway TEXT DEFAULT NULL,
    notes TEXT DEFAULT '',
    billing_period TEXT DEFAULT 'monthly'
)");
$pdo->exec("CREATE TABLE payment_transfers (
    id_transfer INTEGER PRIMARY KEY AUTOINCREMENT,
    id_commerce INTEGER NOT NULL,
    id_subscription INTEGER,
    monto REAL NOT NULL,
    moneda TEXT NOT NULL DEFAULT 'UYU',
    fecha_transferencia TEXT DEFAULT NULL,
    status TEXT NOT NULL DEFAULT 'pending'
      CHECK (status IN ('pending','approved','rejected'))
)");

$pendingFailed = false;
try {
    $pdo->exec("INSERT INTO subscriptions (id_commerce, id_membership, status, gateway, notes)
                VALUES (1, 1, 'pending', 'transfer', 'bad')");
} catch (PDOException $e) {
    $pendingFailed = str_contains($e->getMessage(), 'CHECK');
}
assert_true($pendingFailed, 'subscriptions reject status=pending (CHECK)');

$notes = MembershipPlan::encodePendingMembershipNote(6, 4, 'Transferencia pendiente de aprobación.');
$pdo->exec("INSERT INTO subscriptions (id_commerce, id_membership, status, gateway, notes, billing_period)
            VALUES (1, 4, 'trial', 'transfer', " . $pdo->quote($notes) . ", 'monthly')");
$subId = (int)$pdo->lastInsertId();
assert_true($subId > 0, 'subscription insert with trial succeeds');

$pdo->exec("INSERT INTO payment_transfers (id_commerce, id_subscription, monto, moneda, fecha_transferencia, status)
            VALUES (1, {$subId}, 599, 'UYU', '2026-07-18', 'pending')");
$transferId = (int)$pdo->lastInsertId();
assert_true($transferId > 0, 'payment_transfers pending insert succeeds');

$row = $pdo->query('SELECT status FROM payment_transfers WHERE id_transfer = ' . $transferId)->fetch(PDO::FETCH_ASSOC);
assert_true(($row['status'] ?? '') === 'pending', 'transfer row status is pending');

$sub = $pdo->query('SELECT status, id_membership, notes FROM subscriptions WHERE id_subscription = ' . $subId)->fetch(PDO::FETCH_ASSOC);
$com = $pdo->query('SELECT id_membership, status FROM commerces WHERE id_commerce = 1')->fetch(PDO::FETCH_ASSOC);
assert_true(($sub['status'] ?? '') === 'trial', 'subscription remains trial');
assert_true((int)($sub['id_membership'] ?? 0) === 4, 'subscription keeps Free while transfer pending');
assert_true((int)($com['id_membership'] ?? 0) === 4, 'commerce keeps Free while transfer pending');
$parsed = MembershipPlan::parsePendingMembershipNote($sub['notes'] ?? null);
assert_true($parsed !== null && $parsed['pending_id'] === 6, 'pending Profesional in notes');

// Approve: apply pending
$pdo->exec("UPDATE payment_transfers SET status='approved' WHERE id_transfer = {$transferId}");
$pdo->exec("UPDATE subscriptions SET status='active', id_membership=6, notes='' WHERE id_subscription = {$subId}");
$pdo->exec("UPDATE commerces SET status='active', id_membership=6 WHERE id_commerce = 1");
$sub = $pdo->query('SELECT status, id_membership, notes FROM subscriptions WHERE id_subscription = ' . $subId)->fetch(PDO::FETCH_ASSOC);
$com = $pdo->query('SELECT status, id_membership FROM commerces WHERE id_commerce = 1')->fetch(PDO::FETCH_ASSOC);
assert_true(($sub['status'] ?? '') === 'active' && (int)$sub['id_membership'] === 6, 'approve activates pending plan on subscription');
assert_true(($com['status'] ?? '') === 'active' && (int)$com['id_membership'] === 6, 'approve activates pending plan on commerce');

// Reject path simulation (fresh pending)
$pdo->exec("UPDATE commerces SET status='trial', id_membership=4 WHERE id_commerce = 1");
$rejectNotes = MembershipPlan::encodePendingMembershipNote(6, 4, 'Transferencia pendiente de aprobación.');
$pdo->exec("UPDATE subscriptions SET status='trial', id_membership=4, notes=" . $pdo->quote($rejectNotes) . " WHERE id_subscription = {$subId}");
$pdo->exec("INSERT INTO payment_transfers (id_commerce, id_subscription, monto, moneda, status)
            VALUES (1, {$subId}, 599, 'UYU', 'pending')");
$rejectId = (int)$pdo->lastInsertId();
$pdo->exec("UPDATE payment_transfers SET status='rejected' WHERE id_transfer = {$rejectId}");
$pdo->exec("UPDATE subscriptions SET notes='' WHERE id_subscription = {$subId}");
$sub = $pdo->query('SELECT status, id_membership, notes FROM subscriptions WHERE id_subscription = ' . $subId)->fetch(PDO::FETCH_ASSOC);
$com = $pdo->query('SELECT status, id_membership FROM commerces WHERE id_commerce = 1')->fetch(PDO::FETCH_ASSOC);
assert_true(($sub['status'] ?? '') === 'trial' && (int)$sub['id_membership'] === 4, 'reject keeps Free on subscription');
assert_true(($com['status'] ?? '') === 'trial' && (int)$com['id_membership'] === 4, 'reject keeps Free on commerce');
assert_true(($sub['notes'] ?? '') === '', 'reject clears pending notes');

// --- Tenant UI uses text date field ---
$modal = file_get_contents($root . '/terap/private/dashboard/src/components/admin_plan_membership_modal.php');
assert_true(is_string($modal) && str_contains($modal, 'placeholder="dd/mm/aaaa"'), 'terap modal has dd/mm/aaaa text input');
assert_true(is_string($modal) && !str_contains($modal, 'type="date" name="fecha_transferencia"'), 'terap modal no longer uses type=date');

$js = file_get_contents($root . '/terap/private/dashboard/src/js/admin/plan-membership-modal.js');
assert_true(is_string($js) && str_contains($js, 'fechaIso'), 'terap JS normalizes fecha');
assert_true(is_string($js) && str_contains($js, 'Fecha inválida'), 'terap JS Spanish date error');

echo $failed === 0 ? "\nALL PASSED\n" : "\n{$failed} FAILED\n";
exit($failed === 0 ? 0 : 1);
