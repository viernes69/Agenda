<?php
/**
 * Agenduy - API: Google Calendar
 *
 * Inserta/actualiza eventos en Google Calendar del comercio.
 * Usa la API REST de Google con un service account (JWT).
 *
 * POST /admin/api/google_calendar.php?action=create_event
 *   body: { id_commerce, id_appointment }
 *
 * Las credenciales (Service Account JSON y Calendar ID) se leen
 * de api_keys por comercio (provider='google_calendar',
 * key_name='GOOGLE_CALENDAR_ID' / 'GOOGLE_SERVICE_ACCOUNT_JSON').
 *
 * NOTA: las Service Account NO pueden enviar invitaciones por email
 * a attendees sin delegación de dominio (Google Workspace). Por eso
 * no usamos `attendees`; en su lugar, devolvemos `ics` y `google_link`
 * para que el caller los envíe al cliente por email.
 */
declare(strict_types=1);

$config = require __DIR__ . '/../../src/Core/bootstrap.php';

use Agenduy\Core\Database;
use Agenduy\Core\Crypto;
use Agenduy\Core\IcsHelper;

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? $_POST['action'] ?? 'create_event';

try {
    $db = Database::getInstance();
    $encKey = (string)$db->config()['security']['encryption_key'];
    $crypto = new Crypto($encKey);

    $idCommerce = (int)($_POST['id_commerce'] ?? $_REQUEST['id_commerce'] ?? 0);
    $idAppt     = (int)($_POST['id_appointment'] ?? $_REQUEST['id_appointment'] ?? 0);
    if ($idCommerce <= 0 || $idAppt <= 0) {
        throw new InvalidArgumentException('Falta id_commerce o id_appointment.');
    }

    $appt = $db->fetchOne('SELECT * FROM appointments WHERE id_appointment = :a', [':a' => $idAppt]);
    $commerce = $db->fetchOne('SELECT * FROM commerces WHERE id_commerce = :c', [':c' => $idCommerce]);
    if (!$appt || !$commerce) throw new RuntimeException('Datos no encontrados.');

    // Buscar credenciales
    $saJson = '';
    $calendarId = '';
    $rows = $db->fetchAll(
        "SELECT key_name, key_value FROM api_keys
         WHERE provider IN ('google_calendar','google_service_account')
           AND id_commerce = :c AND is_active = 1",
        [':c' => $idCommerce]
    );
    foreach ($rows as $r) {
        $val = $crypto->decrypt((string)$r['key_value']);
        if ($r['key_name'] === 'GOOGLE_SERVICE_ACCOUNT_JSON') $saJson = $val;
        if ($r['key_name'] === 'GOOGLE_CALENDAR_ID')         $calendarId = $val;
    }
    if ($saJson === '' || $calendarId === '') {
        throw new RuntimeException('Faltan credenciales Google Calendar para el comercio.');
    }

    $sa = json_decode($saJson, true);
    if (!is_array($sa) || empty($sa['client_email']) || empty($sa['private_key'])) {
        throw new RuntimeException('Service Account JSON inválido.');
    }

    $accessToken = googleServiceAccountToken($sa);

    $start = $appt['fecha'] . 'T' . substr((string)$appt['hora_inicio'], 0, 5) . ':00';
    $endHour = $appt['hora_fin'] ?: date('H:i:s', strtotime($appt['hora_inicio'] . ' +30 minutes'));
    $end = $appt['fecha'] . 'T' . substr($endHour, 0, 5) . ':00';

    $tz = (string)($commerce['timezone'] ?: 'America/Montevideo');
    $clientName  = (string)($appt['cliente_nombre'] ?? 'Cliente');
    $clientEmail = (string)($appt['cliente_email'] ?? '');
    $serviceName = (string)($appt['service_name'] ?? 'Servicio');
    $title = 'Reserva: ' . $serviceName . ' - ' . ($commerce['nombre'] ?? 'Agenduy');
    $description = "Cliente: {$clientName}\n"
        . "Servicio: {$serviceName}\n"
        . "Comercio: " . ($commerce['nombre'] ?? '') . "\n"
        . (!empty($appt['notas']) ? "Notas: " . $appt['notas'] . "\n" : '')
        . "\nReserva creada desde Agenduy.";

    $event = [
        'summary'     => $title,
        'description' => $description,
        'start' => ['dateTime' => $start, 'timeZone' => $tz],
        'end'   => ['dateTime' => $end,   'timeZone' => $tz],
        'location' => (string)($commerce['calle'] ?? '') . ' ' . (string)($commerce['ciudad'] ?? ''),
    ];

    $url = 'https://www.googleapis.com/calendar/v3/calendars/' . urlencode($calendarId) . '/events';
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json',
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($event, JSON_UNESCAPED_UNICODE));
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $decoded = json_decode((string)$resp, true);
    if ($code >= 400) {
        throw new RuntimeException('Google rechazó el evento: ' . ($decoded['error']['message'] ?? 'error'));
    }

    $db->update('appointments', [
        'google_event_id' => (string)($decoded['id'] ?? ''),
        'updated_at'      => date('Y-m-d H:i:s'),
    ], 'id_appointment = :a', [':a' => $idAppt]);

    // Generar .ics y link "Add to Calendar" para enviar al cliente por email
    $icsEvent = [
        'title'       => $title,
        'description' => $description,
        'start'       => new \DateTimeImmutable($start, new \DateTimeZone($tz)),
        'end'         => new \DateTimeImmutable($end,   new \DateTimeZone($tz)),
        'location'    => $event['location'],
        'organizer_name'  => (string)($commerce['nombre'] ?? 'Agenduy'),
        'organizer_email' => (string)($commerce['email'] ?? 'noreply@agenduy.uy'),
    ];
    $icsContent = IcsHelper::buildIcs(
        $icsEvent,
        'agenduy-' . $idAppt . '@' . preg_replace('/^https?:\/\//', '', url_base())
    );
    $googleLink = IcsHelper::googleLink($icsEvent);

    echo json_encode([
        'ok'           => true,
        'event_id'     => $decoded['id'] ?? null,
        'html_link'    => $decoded['htmlLink'] ?? null,
        'google_link'  => $googleLink,
        'ics'          => base64_encode($icsContent),
        'ics_filename' => 'reserva-' . $idAppt . '.ics',
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    $code = $e instanceof InvalidArgumentException ? 400 : 422;
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

// -----------------------------------------------------------------
// Helpers: Google Service Account JWT
// -----------------------------------------------------------------
function googleServiceAccountToken(array $sa): string
{
    $now = time();
    $header = ['alg' => 'RS256', 'typ' => 'JWT'];
    $claim = [
        'iss'   => $sa['client_email'],
        'scope' => 'https://www.googleapis.com/auth/calendar.events',
        'aud'   => 'https://oauth2.googleapis.com/token',
        'exp'   => $now + 3600,
        'iat'   => $now,
    ];
    $b64 = fn($data) => rtrim(strtr(base64_encode(json_encode($data, JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
    $signingInput = $b64($header) . '.' . $b64($claim);
    $signature = '';
    $key = openssl_pkey_get_private($sa['private_key']);
    if (!$key) throw new RuntimeException('private_key inválida.');
    if (!openssl_sign($signingInput, $signature, $key, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('No se pudo firmar el JWT.');
    }
    $jwt = $signingInput . '.' . rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion'  => $jwt,
    ]));
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $tok = json_decode((string)$resp, true);
    if ($code >= 400 || empty($tok['access_token'])) {
        throw new RuntimeException('No se pudo obtener access_token de Google.');
    }
    return (string)$tok['access_token'];
}
