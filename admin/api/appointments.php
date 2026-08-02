<?php
/**
 * Agenduy - API: Crear appointment (público)
 *
 * POST /admin/api/appointments.php
 *   body: { slug, fecha, hora_inicio, cliente_nombre, cliente_email, cliente_telefono,
 *           id_service, notas, _csrf }
 *
 * Hace:
 *  - valida comercio
 *  - crea el appointment
 *  - crea evento en Google Calendar (si hay credenciales)
 *  - encola emails/WhatsApp en notification_outbox (los despacha
 *    bin/process-outbox.php; nada se envía síncronamente aquí)
 */
declare(strict_types=1);

$config = require __DIR__ . '/../../src/Core/bootstrap.php';

use Agenduy\Core\Availability;
use Agenduy\Core\Database;
use Agenduy\Core\Crypto;
use Agenduy\Core\CSRF;
use Agenduy\Core\MagicLink;
use Agenduy\Core\MembershipPlan;
use Agenduy\Core\NotificationOutbox;
use Agenduy\Core\RateLimiter;
use Agenduy\Core\Security;
use Agenduy\Core\TenantLocalDb;

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido']);
    exit;
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);
if (!is_array($payload)) $payload = $_POST;

// CSRF - permitimos tokens generados para propósito 'public_booking'
// o, si viene de un form sin sesión (sitio público), aceptamos un
// token enviado en el body (validado contra la sesión).
// Token reutilizable (consume: false): permite reintentos sin que el
// usuario vea errores de CSRF (mismo patrón que src/API/register.php).
$csrf = $payload['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
if (!CSRF::validate(is_string($csrf) ? $csrf : null, 'public_booking', false)) {
    // Sesión nueva o token vencido: emitir uno fresco para que el
    // cliente reintente de forma transparente.
    // 428 y no 419: Apache reemplaza los códigos que no conoce por 500.
    http_response_code(428);
    echo json_encode([
        'ok'    => false,
        'error' => 'csrf_retry',
        'csrf'  => CSRF::generate('public_booking'),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

RateLimiter::enforce('public_booking_ip', Security::clientIp(), 3600, 40);

try {
    $db = Database::getInstance();
    $slug = trim((string)($payload['slug'] ?? ''));
    if ($slug === '') throw new InvalidArgumentException('Falta slug del comercio.');

    $commerce = $db->fetchOne('SELECT * FROM commerces WHERE slug = :s', [':s' => $slug]);
    if (!$commerce) throw new RuntimeException('Comercio no encontrado.');
    if (in_array($commerce['status'], ['cancelled','suspended'], true)) {
        throw new RuntimeException('Este comercio no está aceptando reservas.');
    }

    $fecha = trim((string)($payload['fecha'] ?? ''));
    $horaInicio = trim((string)($payload['hora_inicio'] ?? ''));
    $clienteNombre = trim((string)($payload['cliente_nombre'] ?? ''));
    $clienteEmail = trim((string)($payload['cliente_email'] ?? ''));
    $clienteTelefono = trim((string)($payload['cliente_telefono'] ?? ''));
    $clienteCedula = MagicLink::normalizeCedula($payload['cliente_cedula'] ?? null);
    $notas = trim((string)($payload['notas'] ?? ''));
    $idService = (int)($payload['id_service'] ?? 0) ?: null;

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) throw new InvalidArgumentException('Fecha inválida.');
    if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $horaInicio)) throw new InvalidArgumentException('Hora inválida.');
    if ($clienteNombre === '') throw new InvalidArgumentException('Falta el nombre del cliente.');
    if ($clienteEmail === '' || !filter_var($clienteEmail, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Email inválido.');
    }
    $phoneDigits = preg_replace('/\D+/', '', $clienteTelefono) ?? '';
    if (strlen($phoneDigits) < 7) {
        throw new InvalidArgumentException('Teléfono inválido.');
    }
    if ($clienteCedula === '') {
        throw new InvalidArgumentException('Ingresá tu cédula para poder cancelar tu reserva si lo necesitás.');
    }

    $horaNormalizada = Availability::normalizeTime($horaInicio);
    if ($horaNormalizada === null) {
        throw new InvalidArgumentException('Hora inválida.');
    }
    $horaInicio = $horaNormalizada;

    // Si hay id_service, traer precio/duración
    $precio = 0.0;
    $horaFin = '';
    $svc = null;
    if ($idService) {
        $svc = $db->fetchOne('SELECT * FROM services WHERE id_service = :id AND id_commerce = :c',
            [':id' => $idService, ':c' => $commerce['id_commerce']]);
        if ($svc) {
            $precio = (float)$svc['precio'];
            $dur = (int)$svc['duracion_min'] > 0 ? (int)$svc['duracion_min'] : 30;
            $horaFin = date('H:i:s', strtotime($horaInicio . ' +' . $dur . ' minutes'));
        }
    }
    // Guardar nombre del servicio para usar después (disponible en todo el scope de la función)
    $svcNombre = ($idService && !empty($svc['nombre'])) ? trim((string)$svc['nombre']) : '';
    if ($horaFin === '') {
        $horaFin = date('H:i:s', strtotime($horaInicio . ' +30 minutes'));
    }

    // Solo aceptar horarios reales del comercio (misma lógica que el select público).
    if (!Availability::isSlotAvailable(
        (int)$commerce['id_commerce'],
        $fecha,
        $horaInicio,
        $idService
    )) {
        throw new InvalidArgumentException('Ese horario no está disponible. Elegí otro de la lista.');
    }

    $plan = MembershipPlan::forCommerceId((int)$commerce['id_commerce']);
    $waitlist = false;
    $maxAppts = null;
    $currentAppts = null;
    if (is_array($plan)) {
        $maxAppts = MembershipPlan::maxAppointmentsMonth($plan);
        if ($maxAppts !== null) {
            $currentAppts = MembershipPlan::countAppointmentsThisMonth((int)$commerce['id_commerce']);
            // Over monthly quota: still accept as pending waitlist; process/finalize blocked in tenant admin.
            if ($currentAppts >= $maxAppts) {
                $waitlist = true;
            }
        }
    }

    // Insertar/actualizar cliente. La cedula queda asociada a la reserva
    // para permitir cancelacion publica sin cuenta.
    $idClient = null;
    if ($clienteEmail !== '' || $clienteCedula !== '' || $clienteTelefono !== '') {
        $existing = null;
        if ($clienteEmail !== '') {
            $existing = $db->fetchOne(
                'SELECT id_client FROM clients WHERE id_commerce = :c AND lower(trim(email)) = :e LIMIT 1',
                [':c' => $commerce['id_commerce'], ':e' => strtolower($clienteEmail)]
            );
        }
        if (!$existing && $clienteCedula !== '') {
            $existing = $db->fetchOne(
                'SELECT id_client FROM clients WHERE id_commerce = :c AND cedula = :ci LIMIT 1',
                [':c' => $commerce['id_commerce'], ':ci' => $clienteCedula]
            );
        }
        if (!$existing && $clienteTelefono !== '') {
            $phoneSuffix = substr(preg_replace('/\D+/', '', $clienteTelefono) ?? '', -8);
            if ($phoneSuffix !== '') {
                $existing = $db->fetchOne(
                    "SELECT id_client FROM clients
                     WHERE id_commerce = :c
                       AND replace(replace(replace(replace(replace(telefono,' ',''),'-',''),'.',''),'+',''),'(','') LIKE :p
                     LIMIT 1",
                    [':c' => $commerce['id_commerce'], ':p' => '%' . $phoneSuffix]
                );
            }
        }
        if ($existing) {
            $idClient = (int)$existing['id_client'];
            $parts = explode(' ', $clienteNombre, 2);
            $patch = ['updated_at' => date('Y-m-d H:i:s')];
            if ($parts[0] !== '') {
                $patch['nombre'] = $parts[0];
                $patch['apellido'] = $parts[1] ?? '';
            }
            if ($clienteCedula !== '') {
                $patch['cedula'] = $clienteCedula;
            }
            if ($clienteEmail !== '') {
                $patch['email'] = $clienteEmail;
            }
            if ($clienteTelefono !== '') {
                $patch['telefono'] = $clienteTelefono;
            }
            $db->update('clients', $patch, 'id_client = :id', [':id' => $idClient]);
        } else {
            $parts = explode(' ', $clienteNombre, 2);
            $idClient = (int)$db->insert('clients', [
                'id_commerce' => (int)$commerce['id_commerce'],
                'nombre'      => $parts[0],
                'apellido'    => $parts[1] ?? '',
                'cedula'      => $clienteCedula,
                'email'       => $clienteEmail,
                'telefono'    => $clienteTelefono,
            ]);
        }
    }

    // Insertar appointment
    $apptNotas = $notas;
    if ($waitlist) {
        $marker = MembershipPlan::APPOINTMENT_NOTA_PLAN_WAITLIST;
        $apptNotas = $apptNotas !== '' ? ($marker . ' | ' . $apptNotas) : $marker;
    }
    $idAppt = (int)$db->insert('appointments', [
        'id_commerce'      => (int)$commerce['id_commerce'],
        'id_client'        => $idClient,
        'id_service'       => $idService,
        'fecha'            => $fecha,
        'hora_inicio'      => $horaInicio,
        'hora_fin'         => $horaFin,
        'cliente_nombre'   => $clienteNombre,
        'cliente_email'    => $clienteEmail,
        'cliente_telefono' => $clienteTelefono,
        'notas'            => $apptNotas,
        'precio'           => $precio,
        'status'           => 'pending',
    ]);

    // Avatar del cliente (registro con Google) para espejarlo en la DB local.
    $clienteAvatar = '';
    if ($idClient) {
        try {
            $avatarRow = $db->fetchOne('SELECT avatar FROM clients WHERE id_client = :id', [':id' => $idClient]);
            $clienteAvatar = trim((string)($avatarRow['avatar'] ?? ''));
        } catch (Throwable $e) {
            $clienteAvatar = '';
        }
    }

    // Espejar en database.php del tenant (admin Reservas / dashboard resumen).
    // Misma estrategia que cart_order.php → carrito local.
    $localReservaId = null;
    try {
        if (TenantLocalDb::ensureExists($slug)) {
            $idLocal = null;
            if ($idService && is_array($svc) && isset($svc['id_local']) && is_numeric($svc['id_local'])) {
                $idLocal = (int)$svc['id_local'];
            }
            $mirror = TenantLocalDb::mirrorAppointment($slug, [
                'id_appointment'   => $idAppt,
                'id_commerce'      => (int)$commerce['id_commerce'],
                'id_service'       => $idService,
                'id_local'         => $idLocal,
                'fecha'            => $fecha,
                'hora_inicio'      => $horaInicio,
                'cliente_nombre'   => $clienteNombre,
                'cliente_email'    => $clienteEmail,
                'cliente_telefono' => $clienteTelefono,
                'cliente_avatar'   => $clienteAvatar,
                'status'           => 'pending',
                'precio'           => $precio,
            ]);
            if (is_array($mirror['row'] ?? null) && isset($mirror['row']['ID_Reserva'])) {
                $localReservaId = $mirror['row']['ID_Reserva'];
                try {
                    $notifier = dirname(__DIR__, 2) . '/template/src/API/AdminPushNotifier.php';
                    if (is_file($notifier)) {
                        require_once $notifier;
                        if (class_exists('AdminPushNotifier')) {
                            AdminPushNotifier::notifyReservation($mirror['row']);
                        }
                    }
                } catch (Throwable $pushError) {
                    error_log('[appointments.api] push local: ' . $pushError->getMessage());
                }
            }
        }
    } catch (Throwable $e) {
        error_log('[appointments.api] mirror local: ' . $e->getMessage());
    }

    // 1) Google Calendar (best-effort, no rompe si falla)
    $googleOk = false;
    $googleErr = '';
    $googleLink = '';
    $icsBase64 = '';
    $icsFilename = '';
    try {
        $rows = $db->fetchAll(
            "SELECT key_name, key_value FROM api_keys
             WHERE provider IN ('google_calendar','google_service_account')
               AND id_commerce = :c AND is_active = 1",
            [':c' => $commerce['id_commerce']]
        );
        $hasCreds = false;
        foreach ($rows as $r) {
            if (in_array($r['key_name'], ['GOOGLE_SERVICE_ACCOUNT_JSON','GOOGLE_CALENDAR_ID'], true)) $hasCreds = true;
        }
        if ($hasCreds) {
            // Llamar al endpoint de google_calendar internamente via curl
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $url = $scheme . '://' . $host . dirname($_SERVER['SCRIPT_NAME']) . '/google_calendar.php?action=create_event';
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
            curl_setopt($ch, CURLOPT_TIMEOUT, 8);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
                'id_commerce'     => $commerce['id_commerce'],
                'id_appointment'  => $idAppt,
            ]));
            $resp = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($code < 400) {
                $googleOk = true;
                $gcal = json_decode((string)$resp, true);
                if (is_array($gcal)) {
                    $googleLink  = (string)($gcal['google_link'] ?? '');
                    $icsBase64   = (string)($gcal['ics'] ?? '');
                    $icsFilename = (string)($gcal['ics_filename'] ?? ('reserva-' . $idAppt . '.ics'));
                }
            } else {
                $googleErr = (string)$resp;
            }
        }
    } catch (Throwable $e) {
        $googleErr = $e->getMessage();
    }

    // 2) Encolar email, WhatsApp y recordatorio. Todo se despacha desde
    // bin/process-outbox.php: NO se envía nada síncronamente en este request
    // para que la respuesta al formulario sea inmediata.
    try {
        $notificationPayload = [];
        if ($icsBase64 !== '') {
            $notificationPayload['attachments'] = [[
                'name' => $icsFilename,
                'data_b64' => $icsBase64,
                'mime' => 'text/calendar; method=PUBLISH; charset=UTF-8',
            ]];
        }
        NotificationOutbox::enqueueAppointmentNotifications(
            [
                'id_appointment'   => $idAppt,
                'local_reservation_id' => (int)($localReservaId ?? 0),
                'fecha'            => $fecha,
                'hora_inicio'      => $horaInicio,
                'hora_fin'         => $horaFin,
                'cliente_nombre'   => $clienteNombre,
                'cliente_email'    => $clienteEmail,
                'cliente_telefono' => $clienteTelefono,
                'cliente_cedula'   => $clienteCedula,
                'notas'            => $notas,
            ],
            $commerce,
            (isset($svc) && is_array($svc)) ? $svc : null,
            $notificationPayload
        );
    } catch (Throwable $e) {
        error_log('[appointments.api] outbox: ' . $e->getMessage());
    }

    echo json_encode([
        'ok'                  => true,
        'id_appointment'      => $idAppt,
        'id_reserva_local'    => $localReservaId,
        'google_calendar_ok'  => $googleOk,
        'google_calendar_err' => $googleErr,
        'notificaciones'      => 'queued',
        'waitlist'            => $waitlist,
        'over_plan'           => $waitlist,
        'max_appointments_month' => $maxAppts,
        'current'             => $currentAppts,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    $code = $e instanceof InvalidArgumentException ? 400 : 422;
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
