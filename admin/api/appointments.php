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
use Agenduy\Core\CommerceSettings;
use Agenduy\Core\Database;
use Agenduy\Core\Crypto;
use Agenduy\Core\CSRF;
use Agenduy\Core\MagicLink;
use Agenduy\Core\MembershipPlan;
use Agenduy\Core\MercadoPago;
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
    if (!preg_match('/\S+\s+\S+/', $clienteNombre)) {
        throw new InvalidArgumentException('Ingresa tu nombre y apellido.');
    }
    if ($clienteEmail !== '' && !filter_var($clienteEmail, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Email inválido.');
    }
    $phoneDigits = preg_replace('/\D+/', '', $clienteTelefono) ?? '';
    if ($clienteTelefono !== '' && strlen($phoneDigits) < 7) {
        throw new InvalidArgumentException('Teléfono inválido.');
    }
    if ($clienteEmail === '' && strlen($phoneDigits) < 7) {
        throw new InvalidArgumentException('Ingresa un email o un telefono para poder confirmarte la reserva.');
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
    $reservasCfg = CommerceSettings::get(
        (int)$commerce['id_commerce'],
        'reservas',
        CommerceSettings::defaultsForSection('reservas')
    );
    $reservationCheckoutAllowed = MercadoPago::isReservationCheckoutAllowed($plan);
    if (!$reservationCheckoutAllowed) {
        $reservasCfg['mercado_pago_enabled'] = false;
        $reservasCfg['mercado_pago_required'] = false;
    }
    $paymentRequiredBySettings = !empty($reservasCfg['mercado_pago_required']) && $precio > 0;
    $wantsMercadoPago = appointmentMercadoPagoRequested($payload) || $paymentRequiredBySettings;
    $mpConfig = [];
    if ($wantsMercadoPago) {
        if ($waitlist) {
            throw new RuntimeException('No se puede cobrar online porque el comercio alcanzo el limite de reservas del plan.');
        }
        if (empty($reservasCfg['mercado_pago_enabled'])) {
            throw new RuntimeException('El comercio no tiene Mercado Pago habilitado para reservas.');
        }
        if (!$reservationCheckoutAllowed) {
            throw new RuntimeException('Adquierelo con el plan Pro.');
        }
        if ($precio <= 0) {
            throw new InvalidArgumentException('Este servicio no tiene precio para cobrar online.');
        }
        $mpConfig = MercadoPago::commerceConfig((int)$commerce['id_commerce'], $slug);
        if (empty($mpConfig['enabled']) || trim((string)($mpConfig['access_token'] ?? '')) === '') {
            throw new RuntimeException('El comercio no tiene Mercado Pago configurado.');
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
    if ($wantsMercadoPago) {
        $marker = 'mp-payment-pending';
        $apptNotas = $apptNotas !== '' ? ($marker . ' | ' . $apptNotas) : $marker;
    }
    $appointmentStatus = ($waitlist || $wantsMercadoPago) ? 'pending' : 'confirmed';
    $idAppt = (int)$db->insert('appointments', [
        'id_commerce'      => (int)$commerce['id_commerce'],
        'id_client'        => $idClient,
        'id_service'       => $idService,
        'fecha'            => $fecha,
        'hora_inicio'      => $horaInicio,
        'hora_fin'         => $horaFin,
        'cliente_nombre'   => $clienteNombre,
        'cliente_cedula'   => $clienteCedula,
        'cliente_email'    => $clienteEmail,
        'cliente_telefono' => $clienteTelefono,
        'notas'            => $apptNotas,
        'precio'           => $precio,
        'status'           => $appointmentStatus,
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
                'cliente_cedula'   => $clienteCedula,
                'cliente_email'    => $clienteEmail,
                'cliente_telefono' => $clienteTelefono,
                'cliente_avatar'   => $clienteAvatar,
                'status'           => $appointmentStatus,
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

    if ($wantsMercadoPago) {
        try {
            $payment = createAppointmentMercadoPagoCheckout(
                $db,
                $commerce,
                $slug,
                is_array($svc) ? $svc : [],
                $mpConfig,
                $idAppt,
                is_numeric($localReservaId) ? (int)$localReservaId : null,
                $precio,
                $clienteNombre,
                $clienteEmail,
                $clienteTelefono,
                $fecha,
                $horaInicio
            );
        } catch (Throwable $mpError) {
            markAppointmentPaymentSetupFailed(
                $db,
                $slug,
                [
                    'id_appointment' => $idAppt,
                    'id_commerce' => (int)$commerce['id_commerce'],
                    'id_service' => $idService,
                    'fecha' => $fecha,
                    'hora_inicio' => $horaInicio,
                    'cliente_nombre' => $clienteNombre,
                    'cliente_cedula' => $clienteCedula,
                    'cliente_email' => $clienteEmail,
                    'cliente_telefono' => $clienteTelefono,
                    'status' => 'cancelled',
                    'precio' => $precio,
                ],
                $mpError->getMessage()
            );
            throw $mpError;
        }

        echo json_encode([
            'ok' => true,
            'id_appointment' => $idAppt,
            'id_reserva_local' => $localReservaId,
            'status' => 'pending',
            'payment_required' => true,
            'payment_status' => 'pending',
            'checkout_url' => $payment['checkout_url'],
            'preference_id' => $payment['preference_id'],
            'expires_at' => $payment['expires_at'],
            'notificaciones' => 'pending_payment',
            'waitlist' => false,
            'over_plan' => false,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
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

function appointmentMercadoPagoRequested(array $payload): bool
{
    $method = strtolower(trim((string)($payload['payment_method'] ?? $payload['metodo_pago'] ?? '')));
    if (in_array($method, ['mercadopago', 'mercado_pago', 'mp'], true)) {
        return true;
    }
    return appointmentTruthy($payload['pay_online'] ?? $payload['pago_online'] ?? false);
}

function appointmentTruthy(mixed $value): bool
{
    if (is_bool($value)) {
        return $value;
    }
    if (is_numeric($value)) {
        return (int)$value === 1;
    }
    $normalized = strtolower(trim((string)$value));
    return in_array($normalized, ['1', 'true', 'yes', 'si', 'sí', 'on', 'mercadopago', 'mercado_pago'], true);
}

/**
 * @param array<string,mixed> $commerce
 * @param array<string,mixed> $service
 * @param array<string,mixed> $mp
 * @return array{preference_id:string,checkout_url:string,expires_at:string}
 */
function createAppointmentMercadoPagoCheckout(
    Database $db,
    array $commerce,
    string $slug,
    array $service,
    array $mp,
    int $appointmentId,
    ?int $localReservationId,
    float $amount,
    string $clientName,
    string $clientEmail,
    string $clientPhone,
    string $date,
    string $time
): array {
    $commerceId = (int)($commerce['id_commerce'] ?? 0);
    if ($commerceId <= 0 || $appointmentId <= 0) {
        throw new RuntimeException('No se pudo identificar la reserva para Mercado Pago.');
    }

    $currency = strtoupper(trim((string)($mp['currency'] ?? 'UYU'))) ?: 'UYU';
    $serviceName = trim((string)($service['nombre'] ?? 'Reserva'));
    if ($serviceName === '') {
        $serviceName = 'Reserva';
    }
    $externalReference = sprintf('agenduy_appt_c%d_a%d_%s', $commerceId, $appointmentId, bin2hex(random_bytes(6)));
    $expiresAt = date('Y-m-d H:i:s', time() + 1800);

    $paymentRowId = $db->insert('appointment_payments', [
        'id_commerce' => $commerceId,
        'slug' => $slug,
        'id_appointment' => $appointmentId,
        'local_reservation_id' => $localReservationId,
        'external_reference' => $externalReference,
        'status' => 'created',
        'amount' => round($amount, 2),
        'currency' => $currency,
        'payer_email' => strtolower(trim($clientEmail)),
        'expires_at' => $expiresAt,
    ]);

    $publicPath = trim($slug, '/') . '/';
    $successUrl = MercadoPago::preferredCallbackUrl($mp, 'success_url', $publicPath, ['mp_appointment' => $appointmentId, 'mp_status' => 'success']);
    $failureUrl = MercadoPago::preferredCallbackUrl($mp, 'failure_url', $publicPath, ['mp_appointment' => $appointmentId, 'mp_status' => 'failure']);
    $pendingUrl = MercadoPago::preferredCallbackUrl($mp, 'pending_url', $publicPath, ['mp_appointment' => $appointmentId, 'mp_status' => 'pending']);
    $notificationUrl = MercadoPago::callbackUrl($mp, 'admin/api/webhook_mercadopago.php', ['appointment_slug' => $slug]);

    $preferencePayload = [
        'items' => [[
            'id' => 'appointment-' . $appointmentId,
            'title' => mb_substr($serviceName . ' - ' . $date . ' ' . substr($time, 0, 5), 0, 120, 'UTF-8'),
            'quantity' => 1,
            'currency_id' => $currency,
            'unit_price' => round($amount, 2),
        ]],
        'external_reference' => $externalReference,
        'notification_url' => $notificationUrl,
        'expires' => true,
        'expiration_date_from' => date('c'),
        'expiration_date_to' => date('c', strtotime($expiresAt) ?: (time() + 1800)),
        'back_urls' => [
            'success' => $successUrl,
            'failure' => $failureUrl,
            'pending' => $pendingUrl,
        ],
        'auto_return' => 'approved',
        'metadata' => [
            'kind' => 'appointment_payment',
            'slug' => $slug,
            'id_commerce' => $commerceId,
            'id_appointment' => $appointmentId,
            'local_reservation_id' => $localReservationId,
        ],
    ];
    $descriptor = appointmentStatementDescriptor((string)($mp['statement_descriptor'] ?? $commerce['nombre'] ?? ''));
    if ($descriptor !== '') {
        $preferencePayload['statement_descriptor'] = $descriptor;
    }
    $payer = appointmentPreferencePayer($clientName, $clientEmail, $clientPhone);
    if ($payer !== []) {
        $preferencePayload['payer'] = $payer;
    }

    try {
        $preference = MercadoPago::createPreference($mp, $preferencePayload);
        $preferenceId = trim((string)($preference['id'] ?? ''));
        $checkoutUrl = MercadoPago::checkoutUrl($preference, !empty($mp['sandbox']));
        if ($preferenceId === '' || $checkoutUrl === '') {
            throw new RuntimeException('Mercado Pago no devolvio una URL de checkout.');
        }

        $db->update('appointment_payments', [
            'preference_id' => $preferenceId,
            'status' => 'pending',
            'checkout_url' => $checkoutUrl,
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id_appointment_payment = :id', [':id' => $paymentRowId]);

        return [
            'preference_id' => $preferenceId,
            'checkout_url' => $checkoutUrl,
            'expires_at' => $expiresAt,
        ];
    } catch (Throwable $e) {
        $db->update('appointment_payments', [
            'status' => 'rejected',
            'status_detail' => mb_substr($e->getMessage(), 0, 250, 'UTF-8'),
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id_appointment_payment = :id', [':id' => $paymentRowId]);
        throw $e;
    }
}

/**
 * @return array<string,mixed>
 */
function appointmentPreferencePayer(string $name, string $email, string $phone): array
{
    $payer = [];
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $payer['email'] = strtolower(trim($email));
    }
    if (trim($name) !== '') {
        $payer['name'] = mb_substr(trim($name), 0, 80, 'UTF-8');
    }
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    if (strlen($digits) >= 8) {
        $payer['phone'] = ['number' => $digits];
    }
    return $payer;
}

function appointmentStatementDescriptor(string $value): string
{
    $value = strtoupper(trim($value));
    $value = preg_replace('/[^A-Z0-9 ]+/', '', $value) ?? '';
    $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');
    return mb_substr($value, 0, 22, 'UTF-8');
}

/**
 * @param array<string,mixed> $appointment
 */
function markAppointmentPaymentSetupFailed(Database $db, string $slug, array $appointment, string $reason): void
{
    $appointmentId = (int)($appointment['id_appointment'] ?? 0);
    if ($appointmentId > 0) {
        $db->update('appointments', [
            'status' => 'cancelled',
            'notas' => trim('mp-payment-failed | ' . $reason),
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id_appointment = :id', [':id' => $appointmentId]);
    }
    if ($slug !== '' && TenantLocalDb::exists($slug)) {
        try {
            TenantLocalDb::mirrorAppointment($slug, array_replace($appointment, ['status' => 'cancelled']));
        } catch (Throwable $e) {
            error_log('[appointments.api] mirror payment failed: ' . $e->getMessage());
        }
    }
}
