<?php
declare(strict_types=1);

/**
 * Harness mínimo de pruebas (sin PHPUnit).
 * Uso: php tests/run.php
 */

$root = dirname(__DIR__);
require $root . '/src/Core/bootstrap.php';

use Agenduy\Core\CommerceSettings;
use Agenduy\Core\Database;
use Agenduy\Core\Keys;
use Agenduy\Core\NotificationOutbox;
use Agenduy\Core\UltraMsg;

$failures = 0;

function test(string $name, callable $fn): void
{
    global $failures;
    try {
        $fn();
        echo "[PASS] {$name}\n";
    } catch (Throwable $e) {
        $failures++;
        echo "[FAIL] {$name}: {$e->getMessage()}\n";
    }
}

test('Keys::slug genera slug seguro', function () {
    $slug = Keys::slug('Terapeuta Luck!');
    if ($slug === '' || str_contains($slug, ' ')) {
        throw new RuntimeException('slug inválido: ' . $slug);
    }
});

test('CommerceSettings defaults horarios', function () {
    $d = CommerceSettings::defaultsForSection('horarios');
    if (!isset($d['lunes']['abierto'])) {
        throw new RuntimeException('falta lunes');
    }
});

test('UltraMsg normalizePhone', function () {
    $n = UltraMsg::normalizePhone('+598 09 236 5135');
    if ($n !== '598092365135') {
        throw new RuntimeException('normalización incorrecta: ' . $n);
    }
});

test('Tenant migrado existe en SQLite', function () {
    $db = Database::getInstance();
    $row = $db->fetchOne('SELECT id_commerce FROM commerces WHERE slug = :s', [':s' => 'terapeuta-luck']);
    if (!$row) {
        throw new RuntimeException('terapeuta-luck no migrado');
    }
    $settings = $db->fetchValue(
        'SELECT COUNT(*) FROM commerce_settings WHERE id_commerce = :c',
        [':c' => $row['id_commerce']]
    );
    if ((int)$settings < 1) {
        throw new RuntimeException('commerce_settings vacío para terapeuta-luck');
    }
});

test('Outbox idempotente no duplica', function () {
    $key = 'test:idem:' . bin2hex(random_bytes(4));
    $id1 = NotificationOutbox::enqueue(null, 'email', 'test@example.com', 'unit', 's', 'b', [], date('Y-m-d H:i:s'), $key);
    $id2 = NotificationOutbox::enqueue(null, 'email', 'test@example.com', 'unit', 's', 'b', [], date('Y-m-d H:i:s'), $key);
    if ($id1 !== $id2) {
        throw new RuntimeException("ids distintos {$id1} vs {$id2}");
    }
});

test('Availability buildSlots respeta ventanas y busy', function () {
    $slots = \Agenduy\Core\Availability::buildSlots(
        [[9 * 60, 11 * 60]],
        [[10 * 60, 10 * 60 + 30]],
        30,
        30,
        0
    );
    // 09:00, 09:30 libres; 10:00 ocupado; 10:30 no entra (10:30+30=11:00 ok wait - 10:30+30=11:00 <= 11:00 so included if free)
    if ($slots !== ['09:00', '09:30', '10:30']) {
        throw new RuntimeException('slots inesperados: ' . json_encode($slots));
    }
});

test('Availability windowsForDate cerrado domingo', function () {
    $horarios = \Agenduy\Core\CommerceSettings::defaultsForSection('horarios');
    $sunday = new DateTimeImmutable('2026-07-19'); // domingo
    $windows = \Agenduy\Core\Availability::windowsForDate($horarios, $sunday);
    if ($windows !== []) {
        throw new RuntimeException('domingo debería estar cerrado');
    }
    $monday = new DateTimeImmutable('2026-07-20');
    $monWindows = \Agenduy\Core\Availability::windowsForDate($horarios, $monday);
    if ($monWindows === [] || $monWindows[0][0] !== 9 * 60) {
        throw new RuntimeException('lunes debería abrir 09:00');
    }
});

test('Availability calendarForRange solo lun-vie y salta feriado', function () {
    $horarios = \Agenduy\Core\CommerceSettings::defaultsForSection('horarios');
    $horarios['feriados'] = ['2026-07-20']; // lunes
    $from = new DateTimeImmutable('2026-07-18'); // sábado
    $to = new DateTimeImmutable('2026-07-22'); // miércoles
    $cal = \Agenduy\Core\Availability::calendarForRange($horarios, $from, $to);
    if ($cal['open_weekdays'] !== [1, 2, 3, 4, 5]) {
        throw new RuntimeException('open_weekdays inesperados: ' . json_encode($cal['open_weekdays']));
    }
    if ($cal['closed_dates'] !== ['2026-07-20']) {
        throw new RuntimeException('closed_dates inesperados');
    }
    if ($cal['open_dates'] !== ['2026-07-21', '2026-07-22']) {
        throw new RuntimeException('open_dates inesperados: ' . json_encode($cal['open_dates']));
    }
    if ($cal['next_open_date'] !== '2026-07-21') {
        throw new RuntimeException('next_open_date debería ser martes 21');
    }
});

test('Availability API responde slots para terap', function () {
    $db = Database::getInstance();
    $row = $db->fetchOne('SELECT id_commerce FROM commerces WHERE slug = :s', [':s' => 'terap']);
    if (!$row) {
        // terap puede no existir en todos los entornos
        $row = $db->fetchOne('SELECT id_commerce FROM commerces WHERE slug = :s', [':s' => 'terapeuta-luck']);
        if (!$row) {
            throw new RuntimeException('ni terap ni terapeuta-luck migrados');
        }
    }
    // Próximo lunes a partir de hoy
    $date = new DateTimeImmutable('today');
    while ((int)$date->format('w') !== 1) {
        $date = $date->modify('+1 day');
    }
    $result = \Agenduy\Core\Availability::forCommerce((int)$row['id_commerce'], $date->format('Y-m-d'));
    if (empty($result['ok'])) {
        throw new RuntimeException('ok false');
    }
    if (!isset($result['slots']) || !is_array($result['slots'])) {
        throw new RuntimeException('faltan slots');
    }
    if (!isset($result['limits']['max_date'])) {
        throw new RuntimeException('faltan limits');
    }
    if (!isset($result['calendar']['open_dates']) || !is_array($result['calendar']['open_dates'])) {
        throw new RuntimeException('faltan calendar.open_dates');
    }
    if (empty($result['calendar']['next_open_date'])) {
        throw new RuntimeException('faltan calendar.next_open_date');
    }
    if (in_array($date->format('Y-m-d'), $result['calendar']['open_dates'], true) === false) {
        throw new RuntimeException('el lunes consultado debería estar en open_dates');
    }
});

exit($failures > 0 ? 1 : 0);
