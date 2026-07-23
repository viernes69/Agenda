<?php
/**
 * Unit-ish checks for membership plan limits.
 * Uso: php tests/membership_limits_test.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/src/Core/bootstrap.php';

use Agenduy\Core\MembershipPlan;

$failures = 0;
function assertTrue(bool $cond, string $msg): void
{
    global $failures;
    if ($cond) {
        echo "[PASS] {$msg}\n";
        return;
    }
    $failures++;
    echo "[FAIL] {$msg}\n";
}

$catalog = MembershipPlan::catalogDefaults();
assertTrue(isset($catalog['Free'], $catalog['Básico'], $catalog['Profesional']), 'catalog has 3 plans');

$free = ['limits' => json_encode($catalog['Free']['limits'], JSON_UNESCAPED_UNICODE)];
$basico = ['limits' => json_encode($catalog['Básico']['limits'], JSON_UNESCAPED_UNICODE)];
$pro = ['limits' => json_encode($catalog['Profesional']['limits'], JSON_UNESCAPED_UNICODE)];

assertTrue(MembershipPlan::maxProducts($free) === 10, 'Free max_products=10');
assertTrue(MembershipPlan::maxServices($free) === 4, 'Free max_services=4');
assertTrue(MembershipPlan::maxAppointmentsMonth($free) === 25, 'Free max_appointments=25');
assertTrue(MembershipPlan::maxProfessionals($free) === 1, 'Free max_professionals=1');
assertTrue(MembershipPlan::maxClients($free) === 25, 'Free max_clients=25');
assertTrue(MembershipPlan::isBasicSettingsOnly($free), 'Free settings basic');
assertTrue(!MembershipPlan::allowsConfigKey($free, 'fiscal'), 'Free blocks fiscal');
assertTrue(MembershipPlan::allowsConfigKey($free, 'redes'), 'Free allows redes');
assertTrue(MembershipPlan::allowsConfigKey($free, 'horarios'), 'Free allows horarios');

assertTrue(MembershipPlan::maxProducts($basico) === 20, 'Basico max_products=20');
assertTrue(MembershipPlan::maxServices($basico) === 8, 'Basico max_services=8');
assertTrue(MembershipPlan::maxAppointmentsMonth($basico) === 100, 'Basico max_appointments=100');
assertTrue(MembershipPlan::maxProfessionals($basico) === 3, 'Basico max_professionals=3');
assertTrue(MembershipPlan::maxClients($basico) === 100, 'Basico max_clients=100');
assertTrue(!MembershipPlan::isBasicSettingsOnly($basico), 'Basico settings full');
assertTrue(MembershipPlan::allowsConfigKey($basico, 'seo'), 'Basico allows seo');

assertTrue(MembershipPlan::maxProducts($pro) === null, 'Pro products unlimited');
assertTrue(MembershipPlan::maxServices($pro) === null, 'Pro services unlimited');
assertTrue(MembershipPlan::maxAppointmentsMonth($pro) === null, 'Pro appointments unlimited');
assertTrue(MembershipPlan::maxProfessionals($pro) === null, 'Pro professionals unlimited');
assertTrue(MembershipPlan::maxClients($pro) === null, 'Pro clients unlimited');
assertTrue(!MembershipPlan::isBasicSettingsOnly($pro), 'Pro settings full');

$payload = MembershipPlan::denialPayload();
assertTrue(str_contains((string)$payload['error'], 'Tu plan no permite realizar esta acción'), 'admin denial message');
assertTrue(str_contains((string)$payload['error'], 'Mejorá tu membresía'), 'admin upgrade hint');

$publicPayload = MembershipPlan::publicAppointmentsMonthFullPayload(['current' => 25]);
assertTrue(str_contains((string)$publicPayload['error'], 'Este comercio no puede recibir más reservas'), 'public quota message');
assertTrue(!str_contains((string)$publicPayload['error'], 'membresía'), 'public message has no membership wording');
assertTrue(($publicPayload['code'] ?? '') === 'PLAN_LIMIT_MAX_APPOINTMENTS_MONTH', 'public quota code');

$processPayload = MembershipPlan::appointmentsMonthProcessDeniedPayload(['current' => 25]);
assertTrue(str_contains((string)$processPayload['error'], 'Alcanzaste el límite de reservas del mes'), 'process denial message');
assertTrue(str_contains((string)$processPayload['error'], 'atender o finalizar'), 'process denial mentions atender/finalizar');
assertTrue(($processPayload['code'] ?? '') === 'PLAN_LIMIT_MAX_APPOINTMENTS_MONTH', 'process denial code');

$localCount = MembershipPlan::countLocalReservasThisMonth([
    ['Fecha_Reserva' => date('Y-m-05')],
    ['Fecha_Reserva' => date('Y-m-d', strtotime('first day of last month'))],
    ['Fecha_Reserva' => ''],
]);
assertTrue($localCount === 1, 'local reservas month count');

$prefix = date('Y-m');
$consumed = MembershipPlan::countLocalReservasConsumedThisMonth([
    ['Fecha_Reserva' => $prefix . '-01', 'Status' => 'Pendiente'],
    ['Fecha_Reserva' => $prefix . '-02', 'Status' => 'Finalizado'],
    ['Fecha_Reserva' => $prefix . '-03', 'Status' => 'Aprobado'],
    ['Fecha_Reserva' => $prefix . '-04', 'Status' => 'Cancelado'],
    ['Fecha_Reserva' => date('Y-m-d', strtotime('first day of last month')), 'Status' => 'Finalizado'],
]);
assertTrue($consumed === 2, 'consumed count excludes pendiente/cancelado/other months');

assertTrue(MembershipPlan::isConsumingAppointmentStatus('Aprobado'), 'aprobado consumes');
assertTrue(MembershipPlan::isConsumingAppointmentStatus('Finalizado'), 'finalizado consumes');
assertTrue(!MembershipPlan::isConsumingAppointmentStatus('Pendiente'), 'pendiente does not consume');
assertTrue(!MembershipPlan::isConsumingAppointmentStatus('Cancelado'), 'cancelado does not consume');

$freePlan = ['limits' => json_encode($catalog['Free']['limits'], JSON_UNESCAPED_UNICODE)];
$overRows = [];
for ($i = 0; $i < 25; $i++) {
    $overRows[] = ['Fecha_Reserva' => $prefix . '-10', 'Status' => 'Finalizado'];
}
assertTrue(
    MembershipPlan::wouldExceedAppointmentsMonthOnProcess(
        $freePlan,
        $overRows,
        ['Status' => 'Pendiente', 'Fecha_Reserva' => $prefix . '-15'],
        'Aprobado'
    ),
    'attend blocked when 25 already consumed'
);
assertTrue(
    !MembershipPlan::wouldExceedAppointmentsMonthOnProcess(
        $freePlan,
        $overRows,
        ['Status' => 'Aprobado', 'Fecha_Reserva' => $prefix . '-15'],
        'Finalizado'
    ),
    'finalize allowed when row already consuming'
);
assertTrue(
    !MembershipPlan::wouldExceedAppointmentsMonthOnProcess(
        $freePlan,
        array_slice($overRows, 0, 24),
        ['Status' => 'Pendiente', 'Fecha_Reserva' => $prefix . '-15'],
        'Aprobado'
    ),
    'attend allowed under quota'
);

// DB upgrade via bootstrap: Free/Basico/Profesional must have settings_tier
$db = Agenduy\Core\Database::getInstance();
foreach (['Free', 'Básico', 'Profesional'] as $name) {
    $row = $db->fetchOne('SELECT * FROM memberships WHERE nombre = :n', [':n' => $name]);
    assertTrue(is_array($row), "DB has plan {$name}");
    if (!is_array($row)) {
        continue;
    }
    $limits = MembershipPlan::limits($row);
    assertTrue(isset($limits['settings_tier']), "{$name} has settings_tier in DB");
    if ($name === 'Free') {
        assertTrue((int)($limits['max_products'] ?? -1) === 10, 'DB Free products=10');
        assertTrue((int)($limits['max_services'] ?? -1) === 4, 'DB Free services=4');
        assertTrue((int)($limits['max_appointments_month'] ?? -1) === 25, 'DB Free appts=25');
        assertTrue((int)($limits['max_professionals'] ?? -1) === 1, 'DB Free professionals=1');
        assertTrue((int)($limits['max_clients'] ?? -1) === 25, 'DB Free clients=25');
    }
    if ($name === 'Básico') {
        assertTrue((int)($limits['max_products'] ?? -1) === 20, 'DB Basico products=20');
        assertTrue((int)($limits['max_services'] ?? -1) === 8, 'DB Basico services=8');
        assertTrue((int)($limits['max_appointments_month'] ?? -1) === 100, 'DB Basico appts=100');
        assertTrue((int)($limits['max_professionals'] ?? -1) === 3, 'DB Basico professionals=3');
        assertTrue((int)($limits['max_clients'] ?? -1) === 100, 'DB Basico clients=100');
    }
    if ($name === 'Profesional') {
        assertTrue(!array_key_exists('max_products', $limits), 'DB Pro no max_products');
        assertTrue(!array_key_exists('max_professionals', $limits), 'DB Pro no max_professionals');
        assertTrue(!array_key_exists('max_clients', $limits), 'DB Pro no max_clients');
        assertTrue(($limits['settings_tier'] ?? '') === 'full', 'DB Pro settings full');
        $feats = MembershipPlan::features($row);
        assertTrue(in_array('Profesionales ilimitados', $feats, true), 'DB Pro features mention professionals');
    }
}

// badge-seed rows must not inflate the monthly quota (terap may have demo seeds).
$terap = $db->fetchOne('SELECT id_commerce FROM commerces WHERE slug = :s', [':s' => 'terap']);
if (is_array($terap)) {
    $cid = (int)$terap['id_commerce'];
    $counted = MembershipPlan::countAppointmentsThisMonth($cid);
    $start = date('Y-m-01 00:00:00');
    $end = date('Y-m-01 00:00:00', strtotime('first day of next month'));
    $seeded = (int)$db->fetchValue(
        'SELECT COUNT(*) FROM appointments
         WHERE id_commerce = :c AND created_at >= :start AND created_at < :end AND notas = :seed',
        [':c' => $cid, ':start' => $start, ':end' => $end, ':seed' => MembershipPlan::APPOINTMENT_NOTA_BADGE_SEED]
    );
    $total = (int)$db->fetchValue(
        'SELECT COUNT(*) FROM appointments
         WHERE id_commerce = :c AND created_at >= :start AND created_at < :end',
        [':c' => $cid, ':start' => $start, ':end' => $end]
    );
    assertTrue($counted === ($total - $seeded), 'countAppointmentsThisMonth excludes badge-seed');
    if ($seeded > 0) {
        assertTrue($counted < $total, 'seeded rows were excluded from quota');
    }
}

exit($failures > 0 ? 1 : 0);
