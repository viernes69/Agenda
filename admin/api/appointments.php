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
    if ($clienteEmail !== '' && !filter_var($clienteEmail, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Email inválido.');
    }
    if ($clienteEmail !== '' && $clienteCedula === '') {
        throw new InvalidArgumentException('Ingresá tu cédula para poder acceder a tus reservas después.');
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

    // Insertar cliente (idempotente por email + id_commerce)
    $idClient = null;
    if ($clienteEmail !== '') {
        $existing = $db->fetchOne(
            'SELECT id_client FROM clients WHERE id_commerce = :c AND email = :e LIMIT 1',
            [':c' => $commerce['id_commerce'], ':e' => $clienteEmail]
        );
        if ($existing) {
            $idClient = (int)$existing['id_client'];
            if ($clienteCedula !== '') {
                $patch = [
                    'cedula'     => $clienteCedula,
                    'updated_at' => date('Y-m-d H:i:s'),
                ];
                if ($clienteTelefono !== '') {
                    $patch['telefono'] = $clienteTelefono;
                }
                $db->update('clients', $patch, 'id_client = :id', [':id' => $idClient]);
            }
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

    // Espejar en database.php del tenant (admin Reservas / dashboard resumen).
    // Misma estrategia que cart_order.php → carrito local.
    $localReservaId = null;
    try {
        if (TenantLocalDb::exists($slug)) {
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
                'status'           => 'pending',
                'precio'           => $precio,
            ]);
            if (is_array($mirror['row'] ?? null) && isset($mirror['row']['ID_Reserva'])) {
                $localReservaId = $mirror['row']['ID_Reserva'];
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

    // 2) Email al dueño del comercio (nombre del servicio + detalles completos).
    // Se ENCOLA en notification_outbox (no se envía en este request): el envío
    // SMTP síncrono bloqueaba la respuesta ~10-25s. bin/process-outbox.php despacha.
    $owner = $db->fetchOne(
        'SELECT email, nombre FROM users WHERE id_commerce = :c AND role = :r LIMIT 1',
        [':c' => $commerce['id_commerce'], ':r' => 'commerce_admin']
    );
    if ($owner && !empty($owner['email'])) {
        // $svcNombre ya está disponible desde arriba
        $precioLabel = $precio > 0 ? number_format($precio, 2, ',', '.') . ' UYU' : 'A convenir';
        $subject = 'Nueva reserva - ' . $commerce['nombre'];
        $body = '<div style="font-family:Arial,sans-serif;max-width:520px">'
              . '<h2 style="color:#1e293b;margin-bottom:.5rem">Nueva reserva</h2>'
              . '<p style="color:#475569">Hola <strong>' . htmlspecialchars($owner['nombre']) . '</strong>, recibiste una nueva reserva:</p>'
              . '<table style="width:100%;border-collapse:collapse;margin:1rem 0">'
              . '<tr><td style="padding:.4rem 0;color:#64748b;font-size:.9rem">Servicio</td>'
              . '<td style="padding:.4rem 0;font-weight:600">' . ($svcNombre !== '' ? htmlspecialchars($svcNombre) : 'No especificado') . '</td></tr>'
              . '<tr><td style="padding:.4rem 0;color:#64748b;font-size:.9rem">Cliente</td>'
              . '<td style="padding:.4rem 0;font-weight:600">' . htmlspecialchars($clienteNombre) . '</td></tr>'
              . '<tr><td style="padding:.4rem 0;color:#64748b;font-size:.9rem">Fecha</td>'
              . '<td style="padding:.4rem 0">' . htmlspecialchars($fecha) . '</td></tr>'
              . '<tr><td style="padding:.4rem 0;color:#64748b;font-size:.9rem">Hora</td>'
              . '<td style="padding:.4rem 0">' . htmlspecialchars(substr($horaInicio,0,5)) . ' - ' . htmlspecialchars(substr($horaFin,0,5)) . '</td></tr>'
              . ($clienteTelefono !== '' ? '<tr><td style="padding:.4rem 0;color:#64748b;font-size:.9rem">Teléfono</td><td style="padding:.4rem 0"><a href="tel:' . htmlspecialchars($clienteTelefono) . '">' . htmlspecialchars($clienteTelefono) . '</a></td></tr>' : '')
              . ($clienteEmail !== '' ? '<tr><td style="padding:.4rem 0;color:#64748b;font-size:.9rem">Email</td><td style="padding:.4rem 0"><a href="mailto:' . htmlspecialchars($clienteEmail) . '">' . htmlspecialchars($clienteEmail) . '</a></td></tr>' : '')
              . ($precio > 0 ? '<tr><td style="padding:.4rem 0;color:#64748b;font-size:.9rem">Precio</td><td style="padding:.4rem 0;font-weight:600">' . $precioLabel . '</td></tr>' : '')
              . ($notas !== '' ? '<tr><td style="padding:.4rem 0;color:#64748b;font-size:.9rem">Notas</td><td style="padding:.4rem 0">' . nl2br(htmlspecialchars($notas)) . '</td></tr>' : '')
              . '</table>'
              . '<p style="color:#64748b;font-size:.85rem">Ingresá a tu panel → <strong>Reservas</strong> para confirmar, rechazar o reprogramar esta reserva.</p>'
              . '</div>';
        // Misma idempotency key que enqueueAppointmentNotifications: este
        // cuerpo (más completo) gana porque se encola primero.
        NotificationOutbox::enqueue(
            (int)$commerce['id_commerce'],
            'email',
            (string)$owner['email'],
            'appointment_confirmed_owner',
            $subject,
            $body,
            ['appointment_id' => $idAppt],
            date('Y-m-d H:i:s'),
            "appt:{$idAppt}:email:owner:confirm"
        );
    }

    // 3) Email al cliente (con link de Google Calendar si existe)
    if ($clienteEmail !== '') {
        $subject = 'Reserva recibida - ' . $commerce['nombre'];
        $body = '<p>Hola ' . htmlspecialchars($clienteNombre) . ',</p>'
              . '<p>Recibimos tu solicitud de reserva en <strong>' . htmlspecialchars($commerce['nombre']) . '</strong>.</p>'
              . '<ul><li><strong>Fecha:</strong> ' . htmlspecialchars($fecha) . '</li>'
              . '<li><strong>Hora:</strong> ' . htmlspecialchars($horaInicio) . '</li></ul>'
              . ($googleLink !== ''
                    ? '<p style="margin: 1.2rem 0"><a href="' . htmlspecialchars($googleLink, ENT_QUOTES, 'UTF-8') . '" style="display:inline-block; background:#6366f1; color:#fff; padding:.7rem 1.2rem; border-radius:8px; text-decoration:none; font-weight:600">📅 Agregar a Google Calendar</a></p>'
                    : '')
              . '<p style="color:#5b6271; font-size:.9rem">Te adjuntamos un archivo <code>.ics</code> para que puedas agregar la reserva a cualquier calendario (Google, Apple, Outlook). El comercio te confirmará pronto; para cambios o cancelaciones, contactalos directamente.</p>';
        $clientPayload = ['appointment_id' => $idAppt];
        if ($icsBase64 !== '') {
            $clientPayload['attachments'] = [[
                'name'     => $icsFilename,
                'data_b64' => $icsBase64,
                'mime'     => 'text/calendar; method=PUBLISH; charset=UTF-8',
            ]];
        }
        NotificationOutbox::enqueue(
            (int)$commerce['id_commerce'],
            'email',
            $clienteEmail,
            'appointment_confirmed_client',
            $subject,
            $body,
            $clientPayload,
            date('Y-m-d H:i:s'),
            "appt:{$idAppt}:email:client:confirm"
        );
    }

    // 4) Encolar el resto (WhatsApp + recordatorios). Todo se despacha desde
    // bin/process-outbox.php: NO se envía nada síncronamente en este request
    // para que la respuesta al formulario sea inmediata.
    try {
        NotificationOutbox::enqueueAppointmentNotifications(
            [
                'id_appointment'   => $idAppt,
                'fecha'            => $fecha,
                'hora_inicio'      => $horaInicio,
                'cliente_nombre'   => $clienteNombre,
                'cliente_email'    => $clienteEmail,
                'cliente_telefono' => $clienteTelefono,
            ],
            $commerce,
            (isset($svc) && is_array($svc)) ? $svc : null
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
