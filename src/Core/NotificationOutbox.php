<?php
declare(strict_types=1);

namespace Agenduy\Core;

/**
 * Cola idempotente de notificaciones email/WhatsApp.
 */
final class NotificationOutbox
{
    private const MAX_DELIVERY_ATTEMPTS = 3;

    public static function enqueue(
        ?int $idCommerce,
        string $channel,
        string $recipient,
        string $templateKey,
        string $subject,
        string $body,
        array $payload,
        string $scheduledAt,
        string $idempotencyKey
    ): int {
        $db = Database::getInstance();
        $existing = $db->fetchOne(
            'SELECT id_outbox FROM notification_outbox WHERE idempotency_key = :k',
            [':k' => $idempotencyKey]
        );
        if ($existing) {
            return (int)$existing['id_outbox'];
        }

        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($payloadJson)) {
            $payloadJson = '{}';
        }

        $id = (int)$db->insert('notification_outbox', [
            'id_commerce' => $idCommerce,
            'channel' => $channel,
            'recipient' => $recipient,
            'template_key' => $templateKey,
            'subject' => $subject,
            'body' => $body,
            'payload_json' => $payloadJson,
            'scheduled_at' => $scheduledAt,
            'idempotency_key' => $idempotencyKey,
            'status' => 'queued',
        ]);

        self::triggerProcessAsync();
        return $id;
    }

    private static bool $shutdownHandlerRegistered = false;

    public static function triggerProcessAsync(): void
    {
        if (self::$shutdownHandlerRegistered) {
            return;
        }
        self::$shutdownHandlerRegistered = true;

        // Lanzar una petición HTTP no bloqueante (fire and forget) al pseudo-cron
        // Esto procesa la cola de mensajes en segundo plano sin colgar la web del usuario ni generar loops síncronos.
        $url = url('admin/api/async_cron.php');
        if (str_starts_with($url, 'http')) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT_MS, 200); // 200ms timeout para no trancar
            curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            @curl_exec($ch);
            @curl_close($ch);
        }
    }

    public static function enqueueAppointmentNotifications(
        array $appointment,
        array $commerce,
        ?array $service,
        array $extraPayload = []
    ): void {
        $ctx = self::appointmentContextFromRows($appointment, $commerce, $service);
        if ($ctx === null) {
            return;
        }
        $idAppt = (int)$ctx['appointment_id'];
        $ctx['idempotency_prefix'] = 'appt:' . $idAppt;
        self::enqueueAppointmentCreatedFromContext($ctx, array_replace_recursive(self::appointmentPayload($ctx), $extraPayload));
    }

    public static function enqueueAppointmentStatusNotifications(int $idAppointment, string $event, string $reason = ''): void
    {
        $event = self::normalizeAppointmentEvent($event);
        if ($idAppointment <= 0 || $event === null) {
            return;
        }

        $db = Database::getInstance();
        $appointment = $db->fetchOne(
            "SELECT a.*,
                    cl.nombre AS client_nombre_db,
                    cl.apellido AS client_apellido_db,
                    cl.email AS client_email_db,
                    cl.telefono AS client_telefono_db,
                    cl.cedula AS client_cedula_db
             FROM appointments a
             LEFT JOIN clients cl ON cl.id_client = a.id_client
             WHERE a.id_appointment = :id
             LIMIT 1",
            [':id' => $idAppointment]
        );
        if (!$appointment) {
            return;
        }

        $commerce = $db->fetchOne('SELECT * FROM commerces WHERE id_commerce = :c LIMIT 1', [
            ':c' => (int)$appointment['id_commerce'],
        ]);
        if (!$commerce) {
            return;
        }

        $service = null;
        if (!empty($appointment['id_service'])) {
            $service = $db->fetchOne('SELECT * FROM services WHERE id_service = :id LIMIT 1', [
                ':id' => (int)$appointment['id_service'],
            ]);
        }

        $ctx = self::appointmentContextFromRows($appointment, $commerce, $service);
        if ($ctx === null) {
            return;
        }
        $ctx['idempotency_prefix'] = 'appt:' . $idAppointment;
        $ctx['reason'] = $reason;
        self::enqueueAppointmentStatusFromContext($ctx, $event);
    }

    public static function enqueueLocalReservaCreated(
        string $slug,
        array $reservation,
        array $customer = [],
        ?int $serviceId = null
    ): void {
        $ctx = self::localReservationContext($slug, $reservation, $customer, $serviceId);
        if ($ctx === null) {
            return;
        }
        self::enqueueAppointmentCreatedFromContext($ctx, self::appointmentPayload($ctx));
    }

    public static function enqueueLocalReservaStatusNotifications(string $slug, array $previousRow, array $updatedRow): void
    {
        $previous = self::normalizeLocalStatus((string)($previousRow['Status'] ?? ''));
        $next = self::normalizeLocalStatus((string)($updatedRow['Status'] ?? ''));
        if ($next === '' || $next === $previous) {
            return;
        }

        $event = null;
        if (in_array($next, ['finalizado', 'done', 'completed'], true)) {
            $event = 'attended';
        } elseif (in_array($next, ['cancelado', 'cancelled', 'canceled', 'rechazado', 'rejected'], true)) {
            $event = 'cancelled';
        }
        if ($event === null) {
            return;
        }

        $appointmentId = $updatedRow['ID_Appointment'] ?? null;
        if ($appointmentId !== null && $appointmentId !== '' && is_numeric($appointmentId) && (int)$appointmentId > 0) {
            self::enqueueAppointmentStatusNotifications((int)$appointmentId, $event);
            return;
        }

        $ctx = self::localReservationContext($slug, $updatedRow);
        if ($ctx === null) {
            return;
        }
        self::enqueueAppointmentStatusFromContext($ctx, $event);
    }

    public static function enqueueRegistrationNotifications(int $idCommerce, string $email, string $phone, array $vars = []): void
    {
        if ($idCommerce <= 0) {
            return;
        }

        $commerce = Database::getInstance()->fetchOne('SELECT * FROM commerces WHERE id_commerce = :id LIMIT 1', [
            ':id' => $idCommerce,
        ]);
        $slug = trim((string)($commerce['slug'] ?? $vars['slug'] ?? ''));
        $defaults = [
            'nombre' => (string)($vars['nombre'] ?? ''),
            'negocio' => (string)($commerce['nombre'] ?? $vars['negocio'] ?? 'tu negocio'),
            'trial_end' => (string)($commerce['trial_expires_at'] ?? $vars['trial_end'] ?? ''),
            'site_url' => $slug !== '' ? CommercePanel::publicUrlForSlug($slug) : url(''),
            'panel_url' => $slug !== '' ? CommercePanel::dashboardUrlForSlug($slug) : url('admin/login.php'),
            'email' => $email,
            'telefono' => $phone,
        ];
        $tplVars = self::stringVars(array_replace($defaults, $vars));
        $payload = [
            'id_commerce' => $idCommerce,
            'event' => 'registration_welcome',
            'slug' => $slug,
        ];

        $email = strtolower(trim($email));
        self::enqueueEmail(
            $idCommerce,
            $email,
            'registration_welcome',
            $tplVars,
            'Bienvenido a Agendarte',
            "Hola {nombre}, creamos tu cuenta para {negocio}.\nSitio: {site_url}\nPanel: {panel_url}",
            $payload,
            date('Y-m-d H:i:s'),
            "commerce:{$idCommerce}:registration:email"
        );

        self::enqueueWhatsApp(
            $idCommerce,
            $phone,
            'registration_welcome',
            $tplVars,
            "Hola {nombre}, creamos tu cuenta para {negocio} en Agendarte UY.\nSitio: {site_url}\nPanel: {panel_url}",
            $payload,
            date('Y-m-d H:i:s'),
            "commerce:{$idCommerce}:registration:wa"
        );
    }

    public static function enqueueStoreOrderNotifications(
        array $commerce,
        array $order,
        array $items = [],
        array $customer = [],
        string $event = 'created'
    ): void {
        $idCommerce = (int)($commerce['id_commerce'] ?? 0);
        if ($idCommerce <= 0) {
            return;
        }
        $event = strtolower(trim($event)) === 'paid' ? 'paid' : 'created';
        $customer = self::mergeStoreOrderCustomer($commerce, $order, $customer);
        if ($items === []) {
            $items = self::itemsFromOrderPairs((string)($commerce['slug'] ?? ''), $order);
        }

        $vars = self::storeOrderVars($commerce, $order, $items, $customer, $event);
        $orderKey = $vars['pedido'] !== '' ? $vars['pedido'] : substr(hash('sha1', json_encode($order) ?: serialize($order)), 0, 12);
        $templateKey = $event === 'paid' ? 'store_order_paid_owner' : 'store_order_created_owner';
        $label = $event === 'paid' ? 'Pedido pagado' : 'Nuevo pedido';
        $paymentUrl = trim((string)($vars['pago_url'] ?? ''));
        $paymentLine = $paymentUrl !== '' ? "\nLink de pago Mercado Pago: {$paymentUrl}" : '';
        $payload = [
            'order_id' => $orderKey,
            'event' => $event,
            'slug' => (string)($commerce['slug'] ?? ''),
            'items' => $items,
            'checkout_url' => $paymentUrl,
        ];

        $ownerEmail = EmailTemplates::ownerEmail($idCommerce, $commerce);
        self::enqueueEmail(
            $idCommerce,
            $ownerEmail,
            $templateKey,
            $vars,
            "{$label} #{$orderKey} - {$vars['negocio']}",
            "{$label} #{$orderKey} en {$vars['negocio']}\nCliente: {$vars['cliente']}\nCelular: {$vars['telefono']}\nProductos:\n{$vars['productos']}\nTotal: {$vars['total']}{$paymentLine}",
            $payload,
            date('Y-m-d H:i:s'),
            "order:{$idCommerce}:{$orderKey}:{$event}:email:owner"
        );

        $ownerWhatsApp = self::ownerWhatsApp($idCommerce, $commerce);
        self::enqueueWhatsApp(
            $idCommerce,
            $ownerWhatsApp,
            $templateKey,
            $vars,
            "{$label} #{$orderKey} en {$vars['negocio']}\nCliente: {$vars['cliente']}\nCelular: {$vars['telefono']}\nProductos:\n{$vars['productos']}\nTotal: {$vars['total']}{$paymentLine}",
            $payload,
            date('Y-m-d H:i:s'),
            "order:{$idCommerce}:{$orderKey}:{$event}:wa:owner"
        );

        $clientTemplateKey = $event === 'paid' ? 'store_order_paid_client' : 'store_order_created_client';
        $clientLabel = $event === 'paid' ? 'Compra confirmada' : 'Pedido recibido';
        $clientFallback = $event === 'paid'
            ? "Hola {$vars['cliente']}, gracias por tu compra #{$orderKey} en {$vars['negocio']}.\nTu pago fue confirmado.\nProductos:\n{$vars['productos']}\nTotal: {$vars['total']}"
            : "Hola {$vars['cliente']}, recibimos tu pedido #{$orderKey} en {$vars['negocio']}.\nEstado: {$vars['estado']}\nProductos:\n{$vars['productos']}\nTotal: {$vars['total']}";

        self::enqueueEmail(
            $idCommerce,
            (string)$vars['email'],
            $clientTemplateKey,
            $vars,
            "{$clientLabel} #{$orderKey} - {$vars['negocio']}",
            $clientFallback,
            $payload,
            date('Y-m-d H:i:s'),
            "order:{$idCommerce}:{$orderKey}:{$event}:email:client"
        );

        self::enqueueWhatsApp(
            $idCommerce,
            (string)$vars['telefono'],
            $clientTemplateKey,
            $vars,
            $clientFallback,
            $payload,
            date('Y-m-d H:i:s'),
            "order:{$idCommerce}:{$orderKey}:{$event}:wa:client"
        );
    }

    public static function enqueueLocalCartOrderCreated(
        string $slug,
        array $orderRow,
        array $cartSnapshot = [],
        array $customer = [],
        array $extra = []
    ): void {
        $commerce = self::commerceBySlug($slug);
        if (!$commerce) {
            return;
        }

        $items = [];
        if (is_array($cartSnapshot['items'] ?? null)) {
            foreach ($cartSnapshot['items'] as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $items[] = [
                    'id' => (string)($item['ID_Product'] ?? $item['id'] ?? ''),
                    'name' => (string)($item['Nombre'] ?? $item['name'] ?? 'Producto'),
                    'qty' => (int)($item['cantidad'] ?? $item['qty'] ?? 1),
                    'price' => (float)($item['Precio'] ?? $item['price'] ?? 0),
                    'subtotal' => (float)($item['subtotal'] ?? 0),
                ];
            }
        }

        if ($customer === []) {
            $customer = self::localCustomerForOrder($slug, $orderRow);
        }
        $customer = array_replace($customer, $extra);
        self::enqueueStoreOrderNotifications($commerce, $orderRow, $items, $customer, 'created');
    }

    public static function processDue(int $limit = 50): array
    {
        $db = Database::getInstance();
        $now = date('Y-m-d H:i:s');
        $rows = $db->fetchAll(
            "SELECT * FROM notification_outbox
             WHERE status = 'queued' AND scheduled_at <= :now
             ORDER BY scheduled_at ASC LIMIT :lim",
            [':now' => $now, ':lim' => $limit]
        );

        $stats = ['processed' => 0, 'sent' => 0, 'failed' => 0, 'retrying' => 0];
        foreach ($rows as $row) {
            $stats['processed']++;
            $id = (int)$row['id_outbox'];
            $attempts = (int)$row['attempts'] + 1;
            try {
                $ok = self::dispatch($row);
                if ($ok) {
                    $db->update('notification_outbox', [
                        'status' => 'sent',
                        'attempts' => $attempts,
                        'sent_at' => date('Y-m-d H:i:s'),
                        'last_error' => '',
                    ], 'id_outbox = :id', [':id' => $id]);
                    $stats['sent']++;
                } else {
                    $willRetry = self::recordDeliveryFailure($db, $id, $attempts, 'dispatch returned false');
                    $stats[$willRetry ? 'retrying' : 'failed']++;
                }
            } catch (\Throwable $e) {
                $willRetry = self::recordDeliveryFailure($db, $id, $attempts, $e->getMessage());
                $stats[$willRetry ? 'retrying' : 'failed']++;
            }
        }
        return $stats;
    }

    private static function recordDeliveryFailure(Database $db, int $id, int $attempts, string $error): bool
    {
        $willRetry = $attempts < self::MAX_DELIVERY_ATTEMPTS;
        $data = [
            'status' => $willRetry ? 'queued' : 'failed',
            'attempts' => $attempts,
            'last_error' => mb_substr($error, 0, 500, 'UTF-8'),
        ];

        if ($willRetry) {
            $delaySeconds = min(900, 60 * max(1, $attempts));
            $data['scheduled_at'] = date('Y-m-d H:i:s', time() + $delaySeconds);
        }

        $db->update('notification_outbox', $data, 'id_outbox = :id', [':id' => $id]);
        return $willRetry;
    }

    private static function enqueueAppointmentCreatedFromContext(array $ctx, array $payload): void
    {
        $idCommerce = (int)$ctx['id_commerce'];
        $prefix = (string)$ctx['idempotency_prefix'];
        $vars = self::appointmentVars($ctx);
        $now = date('Y-m-d H:i:s');

        self::enqueueEmail(
            $idCommerce,
            (string)$ctx['client_email'],
            'appointment_confirmed_client',
            $vars,
            'Reserva recibida - ' . $vars['negocio'],
            "Hola {$vars['cliente']}, recibimos tu reserva #{$vars['id_reserva']} en {$vars['negocio']}.\nServicio: {$vars['servicio']}\nFecha: {$vars['fecha']}\nHora: {$vars['hora']}",
            $payload,
            $now,
            "{$prefix}:email:client:confirm"
        );

        self::enqueueWhatsApp(
            $idCommerce,
            (string)$ctx['client_phone'],
            'appointment_confirmed_client',
            $vars,
            "Hola {$vars['cliente']}, recibimos tu reserva #{$vars['id_reserva']} en {$vars['negocio']}.\nServicio: {$vars['servicio']}\nFecha: {$vars['fecha']}\nHora: {$vars['hora']}",
            $payload,
            $now,
            "{$prefix}:wa:client:confirm"
        );

        $ownerEmail = EmailTemplates::ownerEmail($idCommerce, (array)$ctx['commerce']);
        self::enqueueEmail(
            $idCommerce,
            $ownerEmail,
            'appointment_confirmed_owner',
            $vars,
            'Nueva reserva - ' . $vars['cliente'],
            "Nueva reserva #{$vars['id_reserva']} en {$vars['negocio']}\nCliente: {$vars['cliente']}\nCelular: {$vars['telefono']}\nServicio: {$vars['servicio']}\nFecha: {$vars['fecha']}\nHora: {$vars['hora']}",
            $payload,
            $now,
            "{$prefix}:email:owner:confirm"
        );

        self::enqueueWhatsApp(
            $idCommerce,
            self::ownerWhatsApp($idCommerce, (array)$ctx['commerce']),
            'appointment_confirmed_owner',
            $vars,
            "Nueva reserva #{$vars['id_reserva']} en {$vars['negocio']}\nCliente: {$vars['cliente']}\nCelular: {$vars['telefono']}\nServicio: {$vars['servicio']}\nFecha: {$vars['fecha']}\nHora: {$vars['hora']}",
            $payload,
            $now,
            "{$prefix}:wa:owner:confirm"
        );

        $reminderAt = self::appointmentReminderAt($ctx);
        if ($reminderAt !== null) {
            self::enqueueEmail(
                $idCommerce,
                (string)$ctx['client_email'],
                'appointment_reminder_2h',
                $vars,
                'Tu cita es pronto - ' . $vars['negocio'],
                "Hola {$vars['cliente']}, te recordamos que en 2 horas tenes {$vars['servicio']} en {$vars['negocio']}.\nFecha: {$vars['fecha']}\nHora: {$vars['hora']}",
                $payload,
                $reminderAt,
                "{$prefix}:email:client:2h"
            );
            self::enqueueWhatsApp(
                $idCommerce,
                (string)$ctx['client_phone'],
                'appointment_reminder_2h',
                $vars,
                "Hola {$vars['cliente']}, te recordamos que en 2 horas tenes {$vars['servicio']} en {$vars['negocio']}.\nFecha: {$vars['fecha']}\nHora: {$vars['hora']}",
                $payload,
                $reminderAt,
                "{$prefix}:wa:client:2h"
            );
        }
    }

    private static function enqueueAppointmentStatusFromContext(array $ctx, string $event): void
    {
        $idCommerce = (int)$ctx['id_commerce'];
        $prefix = (string)$ctx['idempotency_prefix'];
        $vars = self::appointmentVars($ctx);
        $payload = self::appointmentPayload($ctx);
        $now = date('Y-m-d H:i:s');

        if (in_array($event, ['attended', 'cancelled'], true)) {
            self::cancelRemindersByPrefix($prefix, $event);
        }

        if ($event === 'attended') {
            self::enqueueEmail(
                $idCommerce,
                (string)$ctx['client_email'],
                'appointment_attended_client',
                $vars,
                'Gracias por venir - ' . $vars['negocio'],
                "Hola {$vars['cliente']}, gracias por venir a {$vars['negocio']}.",
                $payload + ['event' => 'attended'],
                $now,
                "{$prefix}:email:client:attended"
            );
            self::enqueueWhatsApp(
                $idCommerce,
                (string)$ctx['client_phone'],
                'appointment_attended_client',
                $vars,
                "Hola {$vars['cliente']}, gracias por venir a {$vars['negocio']}.",
                $payload + ['event' => 'attended'],
                $now,
                "{$prefix}:wa:client:attended"
            );
            return;
        }

        if ($event === 'cancelled') {
            self::enqueueEmail(
                $idCommerce,
                (string)$ctx['client_email'],
                'appointment_cancelled_client',
                $vars,
                'Reserva cancelada - ' . $vars['negocio'],
                "Hola {$vars['cliente']}, tu reserva #{$vars['id_reserva']} en {$vars['negocio']} fue cancelada.",
                $payload + ['event' => 'cancelled'],
                $now,
                "{$prefix}:email:client:cancelled"
            );
            self::enqueueWhatsApp(
                $idCommerce,
                (string)$ctx['client_phone'],
                'appointment_cancelled_client',
                $vars,
                "Hola {$vars['cliente']}, tu reserva #{$vars['id_reserva']} en {$vars['negocio']} fue cancelada.",
                $payload + ['event' => 'cancelled'],
                $now,
                "{$prefix}:wa:client:cancelled"
            );

            $ownerEmail = EmailTemplates::ownerEmail($idCommerce, (array)$ctx['commerce']);
            self::enqueueEmail(
                $idCommerce,
                $ownerEmail,
                'appointment_cancelled_owner',
                $vars,
                'Reserva cancelada - ' . $vars['cliente'],
                "Se cancelo la reserva #{$vars['id_reserva']} en {$vars['negocio']}.\nCliente: {$vars['cliente']}\nFecha: {$vars['fecha']}\nHora: {$vars['hora']}",
                $payload + ['event' => 'cancelled'],
                $now,
                "{$prefix}:email:owner:cancelled"
            );
            self::enqueueWhatsApp(
                $idCommerce,
                self::ownerWhatsApp($idCommerce, (array)$ctx['commerce']),
                'appointment_cancelled_owner',
                $vars,
                "Reserva cancelada #{$vars['id_reserva']} en {$vars['negocio']}\nCliente: {$vars['cliente']}\nFecha: {$vars['fecha']}\nHora: {$vars['hora']}",
                $payload + ['event' => 'cancelled'],
                $now,
                "{$prefix}:wa:owner:cancelled"
            );
        }
    }

    private static function enqueueEmail(
        int $idCommerce,
        string $recipient,
        string $templateKey,
        array $vars,
        string $fallbackSubject,
        string $fallbackBody,
        array $payload,
        string $scheduledAt,
        string $idempotencyKey
    ): ?int {
        $recipient = strtolower(trim($recipient));
        if ($recipient === '' || !filter_var($recipient, FILTER_VALIDATE_EMAIL) || !self::channelEnabled($idCommerce, 'email')) {
            return null;
        }
        $subject = EmailTemplates::render($idCommerce, $templateKey, $vars, 'subject', $fallbackSubject);
        $bodyText = EmailTemplates::render($idCommerce, $templateKey, $vars, 'body', $fallbackBody);
        $bodyText = self::appendStorePaymentLink($bodyText, $vars, $templateKey);
        $bodyText = self::appendAppointmentCalendarLink($bodyText, $vars, $templateKey);
        return self::enqueue(
            $idCommerce,
            'email',
            $recipient,
            $templateKey,
            $subject,
            EmailTemplates::renderHtmlFromText($bodyText, $vars),
            $payload,
            $scheduledAt,
            $idempotencyKey
        );
    }

    private static function enqueueWhatsApp(
        int $idCommerce,
        string $recipient,
        string $templateKey,
        array $vars,
        string $fallbackBody,
        array $payload,
        string $scheduledAt,
        string $idempotencyKey
    ): ?int {
        $recipient = trim($recipient);
        if (self::phoneDigits($recipient) === '' || !self::channelEnabled($idCommerce, 'whatsapp')) {
            return null;
        }
        $body = PlatformTemplates::render('ultramsg', $templateKey, $vars, 'body', $fallbackBody);
        $body = self::appendStorePaymentLink($body, $vars, $templateKey);
        return self::enqueue(
            $idCommerce,
            'whatsapp',
            $recipient,
            $templateKey,
            '',
            $body,
            $payload,
            $scheduledAt,
            $idempotencyKey
        );
    }

    private static function appendStorePaymentLink(string $body, array $vars, string $templateKey): string
    {
        if (!str_starts_with($templateKey, 'store_order_')) {
            return $body;
        }
        $paymentUrl = trim((string)($vars['pago_url'] ?? ''));
        if ($paymentUrl === '' || str_contains($body, $paymentUrl)) {
            return $body;
        }
        return rtrim($body) . "\nLink de pago Mercado Pago: " . $paymentUrl;
    }

    private static function appendAppointmentCalendarLink(string $body, array $vars, string $templateKey): string
    {
        if ($templateKey !== 'appointment_confirmed_client') {
            return $body;
        }
        $calendarUrl = trim((string)($vars['google_calendar_url'] ?? $vars['calendar_url'] ?? ''));
        if ($calendarUrl === '' || str_contains($body, $calendarUrl)) {
            return $body;
        }
        if (str_contains($body, '<')) {
            $safeUrl = htmlspecialchars($calendarUrl, ENT_QUOTES, 'UTF-8');
            return rtrim($body) . "\n\n"
                . '<p style="margin:1rem 0 0"><a href="' . $safeUrl . '" '
                . 'style="display:inline-block;background:#6d28d9;color:#fff;padding:.72rem 1rem;border-radius:8px;text-decoration:none;font-weight:700">'
                . 'Agregar a Google Calendar</a></p>';
        }
        return rtrim($body) . "\n\nAgregar a Google Calendar: " . $calendarUrl;
    }

    private static function appointmentContextFromRows(array $appointment, array $commerce, ?array $service): ?array
    {
        $idCommerce = (int)($commerce['id_commerce'] ?? $appointment['id_commerce'] ?? 0);
        $idAppointment = (int)($appointment['id_appointment'] ?? 0);
        if ($idCommerce <= 0 || $idAppointment <= 0) {
            return null;
        }

        $dbClientName = trim((string)($appointment['client_nombre_db'] ?? '') . ' ' . (string)($appointment['client_apellido_db'] ?? ''));
        $name = trim((string)($appointment['cliente_nombre'] ?? '')) ?: ($dbClientName ?: 'Cliente');
        $email = trim((string)($appointment['cliente_email'] ?? '')) ?: trim((string)($appointment['client_email_db'] ?? ''));
        $phone = trim((string)($appointment['cliente_telefono'] ?? '')) ?: trim((string)($appointment['client_telefono_db'] ?? ''));
        $cedula = trim((string)($appointment['cliente_cedula'] ?? $appointment['client_cedula_db'] ?? ''));
        $slug = trim((string)($commerce['slug'] ?? ''));
        $duration = self::appointmentDurationMinutes($appointment, $service);

        return [
            'id_commerce' => $idCommerce,
            'appointment_id' => $idAppointment,
            'local_reservation_id' => (int)($appointment['local_reservation_id'] ?? 0),
            'idempotency_prefix' => 'appt:' . $idAppointment,
            'commerce' => $commerce,
            'slug' => $slug,
            'business_name' => trim((string)($commerce['nombre'] ?? 'Negocio')) ?: 'Negocio',
            'client_name' => $name,
            'client_email' => $email,
            'client_phone' => $phone,
            'client_cedula' => $cedula,
            'service_name' => trim((string)($service['nombre'] ?? $appointment['servicio_nombre'] ?? 'Servicio')) ?: 'Servicio',
            'fecha' => trim((string)($appointment['fecha'] ?? '')),
            'hora' => self::normalizeTimeLabel((string)($appointment['hora_inicio'] ?? '')),
            'hora_fin' => self::normalizeTimeLabel((string)($appointment['hora_fin'] ?? '')),
            'duration_min' => $duration,
            'notas' => trim((string)($appointment['notas'] ?? '')),
            'timezone' => trim((string)($commerce['timezone'] ?? 'America/Montevideo')) ?: 'America/Montevideo',
            'location' => self::commerceLocationLabel($commerce),
            'site_url' => $slug !== '' ? CommercePanel::publicUrlForSlug($slug) : url(''),
            'panel_url' => $slug !== '' ? CommercePanel::dashboardUrlForSlug($slug, 'reservas') : url('admin/login.php'),
            'cancel_url' => $slug !== '' ? CommercePanel::publicUrlForSlug($slug) . '?cancel_reserva=' . rawurlencode((string)$idAppointment) : '',
        ];
    }

    private static function localReservationContext(
        string $slug,
        array $reservation,
        array $customer = [],
        ?int $serviceId = null
    ): ?array {
        $commerce = self::commerceBySlug($slug);
        if (!$commerce) {
            return null;
        }

        try {
            $localDb = TenantLocalDb::read($slug);
        } catch (\Throwable) {
            $localDb = [];
        }

        $clientId = self::numericValue($reservation['ID_Cliente'] ?? null);
        $serviceId = $serviceId ?? self::numericValue($reservation['ID_Servicio'] ?? null);
        $client = $clientId > 0 ? self::localRowById((array)($localDb['clientes'] ?? []), 'ID_Cliente', $clientId) : [];
        $service = $serviceId > 0 ? self::localRowById((array)($localDb['servicios'] ?? []), 'ID_Servicio', $serviceId) : [];

        $name = self::firstNonEmpty($customer, ['display_name', 'cliente_nombre', 'nombre', 'Nombre']);
        if ($name === '') {
            $name = trim(self::firstNonEmpty($client, ['Nombre']) . ' ' . self::firstNonEmpty($client, ['Apellido']));
        }
        $email = self::firstNonEmpty($customer, ['cliente_email', 'email', 'Email']);
        if ($email === '') {
            $email = self::firstNonEmpty($client, ['Email', 'email']);
        }
        $phone = self::firstNonEmpty($customer, ['cliente_telefono', 'telefono', 'Telefono']);
        if ($phone === '') {
            $phone = self::firstNonEmpty($client, ['Telefono', 'telefono']);
        }
        $cedula = self::firstNonEmpty($customer, ['cedula', 'Cedula']);
        if ($cedula === '') {
            $cedula = self::firstNonEmpty($client, ['Cedula', 'cedula']);
        }

        $idAppt = self::numericValue($reservation['ID_Appointment'] ?? null);
        $localId = self::numericValue($reservation['ID_Reserva'] ?? null);
        $displayId = $idAppt > 0 ? $idAppt : $localId;
        if ($displayId <= 0) {
            $displayId = (int)abs(crc32($slug . serialize($reservation)));
        }

        return [
            'id_commerce' => (int)$commerce['id_commerce'],
            'appointment_id' => $idAppt,
            'local_reservation_id' => $localId,
            'idempotency_prefix' => 'localres:' . $slug . ':' . $displayId,
            'commerce' => $commerce,
            'slug' => $slug,
            'business_name' => trim((string)($commerce['nombre'] ?? 'Negocio')) ?: 'Negocio',
            'client_name' => trim($name) ?: 'Cliente',
            'client_email' => trim($email),
            'client_phone' => trim($phone),
            'client_cedula' => trim($cedula),
            'service_name' => self::firstNonEmpty($service, ['Nombre', 'nombre']) ?: 'Servicio',
            'fecha' => trim((string)($reservation['Fecha_Reserva'] ?? '')),
            'hora' => self::normalizeTimeLabel((string)($reservation['Hora_Reserva'] ?? '')),
            'hora_fin' => self::normalizeTimeLabel(self::firstNonEmpty($reservation, ['Hora_Fin', 'Hora_Fin_Reserva', 'hora_fin'])),
            'duration_min' => self::appointmentDurationMinutes($reservation, $service),
            'notas' => '',
            'timezone' => trim((string)($commerce['timezone'] ?? 'America/Montevideo')) ?: 'America/Montevideo',
            'location' => self::commerceLocationLabel($commerce),
            'site_url' => CommercePanel::publicUrlForSlug($slug),
            'panel_url' => CommercePanel::dashboardUrlForSlug($slug, 'reservas'),
            'cancel_url' => CommercePanel::publicUrlForSlug($slug) . '?cancel_reserva=' . rawurlencode((string)$displayId),
        ];
    }

    private static function appointmentVars(array $ctx): array
    {
        $id = (int)($ctx['appointment_id'] ?? 0);
        if ($id <= 0) {
            $id = (int)($ctx['local_reservation_id'] ?? 0);
        }
        $calendarUrl = self::appointmentCalendarUrl($ctx);
        return self::stringVars([
            'cliente' => $ctx['client_name'] ?? 'Cliente',
            'telefono' => $ctx['client_phone'] ?? '',
            'email' => $ctx['client_email'] ?? '',
            'cedula' => $ctx['client_cedula'] ?? '',
            'servicio' => $ctx['service_name'] ?? 'Servicio',
            'negocio' => $ctx['business_name'] ?? 'Negocio',
            'fecha' => $ctx['fecha'] ?? '',
            'hora' => $ctx['hora'] ?? '',
            'id_reserva' => $id > 0 ? (string)$id : '',
            'notas' => $ctx['notas'] ?? '',
            'site_url' => $ctx['site_url'] ?? '',
            'panel_url' => $ctx['panel_url'] ?? '',
            'cancel_url' => $ctx['cancel_url'] ?? '',
            'google_calendar_url' => $calendarUrl,
            'calendar_url' => $calendarUrl,
            'logo' => PlatformTemplates::logoHtml(),
        ]);
    }

    private static function appointmentPayload(array $ctx): array
    {
        return [
            'appointment_id' => (int)($ctx['appointment_id'] ?? 0) ?: null,
            'local_reservation_id' => (int)($ctx['local_reservation_id'] ?? 0) ?: null,
            'slug' => (string)($ctx['slug'] ?? ''),
            'cancel_url' => (string)($ctx['cancel_url'] ?? ''),
            'google_calendar_url' => self::appointmentCalendarUrl($ctx),
        ];
    }

    private static function appointmentCalendarUrl(array $ctx): string
    {
        $start = self::appointmentDateTime($ctx);
        if ($start === null) {
            return '';
        }
        $end = self::appointmentEndDateTime($ctx, $start);
        $id = (int)($ctx['appointment_id'] ?? 0);
        if ($id <= 0) {
            $id = (int)($ctx['local_reservation_id'] ?? 0);
        }
        $description = "Reserva en " . (string)($ctx['business_name'] ?? 'Negocio') . "\n"
            . "Servicio: " . (string)($ctx['service_name'] ?? 'Servicio') . "\n";
        if ($id > 0) {
            $description .= "Numero de reserva: " . $id . "\n";
        }
        if (trim((string)($ctx['cancel_url'] ?? '')) !== '') {
            $description .= "Cancelar: " . (string)$ctx['cancel_url'] . "\n";
        }

        return IcsHelper::googleLink([
            'title' => 'Reserva: ' . (string)($ctx['service_name'] ?? 'Servicio') . ' - ' . (string)($ctx['business_name'] ?? 'Negocio'),
            'description' => trim($description),
            'start' => $start,
            'end' => $end,
            'location' => (string)($ctx['location'] ?? ''),
        ]);
    }

    private static function appointmentDateTime(array $ctx): ?\DateTimeImmutable
    {
        $date = self::normalizeCalendarDate((string)($ctx['fecha'] ?? ''));
        $time = self::normalizeCalendarTime((string)($ctx['hora'] ?? ''));
        if ($date === '' || $time === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable($date . ' ' . $time, self::timezone((string)($ctx['timezone'] ?? '')));
        } catch (\Throwable) {
            return null;
        }
    }

    private static function appointmentEndDateTime(array $ctx, \DateTimeImmutable $start): \DateTimeImmutable
    {
        $endTime = self::normalizeCalendarTime((string)($ctx['hora_fin'] ?? ''));
        if ($endTime !== '') {
            try {
                $end = new \DateTimeImmutable($start->format('Y-m-d') . ' ' . $endTime, $start->getTimezone());
                if ($end > $start) {
                    return $end;
                }
            } catch (\Throwable) {
                // Fallback to duration below.
            }
        }
        $duration = max(5, (int)($ctx['duration_min'] ?? 30));
        return $start->modify('+' . $duration . ' minutes');
    }

    private static function normalizeCalendarDate(string $date): string
    {
        $date = trim($date);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date)) {
            return $date;
        }
        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $date, $m)) {
            return $m[3] . '-' . $m[2] . '-' . $m[1];
        }
        return '';
    }

    private static function normalizeCalendarTime(string $time): string
    {
        $time = self::normalizeTimeLabel($time);
        if (preg_match('/^(\d{2}):(\d{2})$/', $time)) {
            return $time . ':00';
        }
        if (preg_match('/^(\d{2}):(\d{2}):(\d{2})$/', $time)) {
            return $time;
        }
        return '';
    }

    private static function timezone(string $timezone): \DateTimeZone
    {
        $timezone = trim($timezone) ?: 'America/Montevideo';
        try {
            return new \DateTimeZone($timezone);
        } catch (\Throwable) {
            return new \DateTimeZone('America/Montevideo');
        }
    }

    private static function appointmentDurationMinutes(array $appointment, ?array $service): int
    {
        $rows = [$service ?: [], $appointment];
        foreach ($rows as $row) {
            foreach (['duracion_min', 'duration_min', 'Duracion', 'duracion', 'duration'] as $key) {
                if (isset($row[$key]) && is_numeric($row[$key]) && (int)$row[$key] > 0) {
                    return max(5, (int)$row[$key]);
                }
            }
        }
        return 30;
    }

    private static function commerceLocationLabel(array $commerce): string
    {
        $parts = [];
        foreach (['calle', 'ciudad', 'pais'] as $key) {
            $value = trim((string)($commerce[$key] ?? ''));
            if ($value !== '' && !in_array($value, $parts, true)) {
                $parts[] = $value;
            }
        }
        return implode(', ', $parts);
    }

    private static function appointmentReminderAt(array $ctx): ?string
    {
        $fecha = trim((string)($ctx['fecha'] ?? ''));
        $hora = trim((string)($ctx['hora'] ?? ''));
        if ($fecha === '' || $hora === '') {
            return null;
        }
        try {
            $tz = new \DateTimeZone((string)($ctx['timezone'] ?? 'America/Montevideo'));
            $dt = new \DateTimeImmutable($fecha . ' ' . $hora, $tz);
            return $dt->modify('-2 hours')->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            error_log('[NotificationOutbox] reminder schedule failed: ' . $e->getMessage());
            return null;
        }
    }

    private static function cancelRemindersByPrefix(string $prefix, string $reason): void
    {
        try {
            Database::getInstance()->update(
                'notification_outbox',
                [
                    'status' => 'cancelled',
                    'last_error' => 'cancelled after appointment ' . $reason,
                ],
                "status = 'queued' AND template_key LIKE 'appointment_reminder_%' AND idempotency_key LIKE :prefix",
                [':prefix' => $prefix . ':%']
            );
        } catch (\Throwable $e) {
            error_log('[NotificationOutbox] cancel reminders failed: ' . $e->getMessage());
        }
    }

    private static function storeOrderVars(array $commerce, array $order, array $items, array $customer, string $event): array
    {
        $orderId = self::orderId($order);
        $slug = trim((string)($commerce['slug'] ?? ''));
        $address = self::firstNonEmpty($customer, ['direccion', 'address']);
        if ($address === '') {
            $address = self::firstNonEmptyNormalized($order, ['direccion']);
        }
        $status = self::firstNonEmpty($order, ['Status', 'status', 'Payment_Status']);
        if ($status === '') {
            $status = $event === 'paid' ? 'Pagado' : 'Pendiente';
        }
        $products = self::formatOrderItems($items, (string)($order['currency'] ?? 'UYU'));
        $total = self::orderTotalLabel($order, $items, (string)($order['currency'] ?? 'UYU'));
        $client = self::firstNonEmpty($customer, ['cliente_nombre', 'nombre', 'Nombre', 'display_name']);
        if ($client === '') {
            $client = 'Cliente';
        }
        $paymentUrl = self::firstNonEmpty($customer, ['pago_url', 'payment_url', 'checkout_url']);
        if ($paymentUrl === '') {
            $paymentUrl = self::firstNonEmpty($order, ['pago_url', 'payment_url', 'checkout_url']);
        }

        return self::stringVars([
            'pedido' => $orderId > 0 ? (string)$orderId : '',
            'negocio' => trim((string)($commerce['nombre'] ?? 'Negocio')) ?: 'Negocio',
            'cliente' => $client,
            'telefono' => self::firstNonEmpty($customer, ['cliente_telefono', 'telefono', 'Telefono']),
            'email' => self::firstNonEmpty($customer, ['cliente_email', 'email', 'Email']) ?: self::firstNonEmpty($order, ['payer_email']),
            'direccion' => $address,
            'pago_url' => $paymentUrl,
            'productos' => $products !== '' ? $products : '- Sin detalle disponible',
            'total' => $total,
            'estado' => $status,
            'site_url' => $slug !== '' ? CommercePanel::publicUrlForSlug($slug) : url(''),
            'panel_url' => $slug !== '' ? CommercePanel::dashboardUrlForSlug($slug, 'reservas') : url('admin/login.php'),
        ]);
    }

    private static function itemsFromOrderPairs(string $slug, array $order): array
    {
        $raw = self::firstNonEmpty($order, ['ID_Producto + Cantidad', 'items', 'productos']);
        if ($raw === '') {
            $raw = self::firstNonEmptyNormalized($order, ['idproductocantidad', 'productos']);
        }
        if ($raw === '') {
            return [];
        }
        $catalog = [];
        if ($slug !== '') {
            try {
                $catalog = TenantLocalDb::productIndex($slug);
            } catch (\Throwable) {
                $catalog = [];
            }
        }

        $items = [];
        if (preg_match_all('/\(?\s*([0-9]+)\s*\+\s*([0-9]+)\s*\)?/', $raw, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $pid = (string)$match[1];
                $qty = (int)$match[2];
                $product = $catalog[$pid] ?? ['name' => 'Producto ' . $pid, 'price' => 0];
                $price = (float)($product['price'] ?? 0);
                $items[] = [
                    'id' => $pid,
                    'name' => (string)($product['name'] ?? ('Producto ' . $pid)),
                    'qty' => $qty,
                    'price' => $price,
                    'subtotal' => $price * $qty,
                ];
            }
        }
        return $items;
    }

    private static function localCustomerForOrder(string $slug, array $orderRow): array
    {
        $clientId = self::numericValue($orderRow['ID_Cliente'] ?? null);
        if ($clientId <= 0) {
            return [];
        }
        try {
            $localDb = TenantLocalDb::read($slug);
        } catch (\Throwable) {
            return [];
        }
        $client = self::localRowById((array)($localDb['clientes'] ?? []), 'ID_Cliente', $clientId);
        if ($client === []) {
            return [];
        }
        $name = trim(self::firstNonEmpty($client, ['Nombre']) . ' ' . self::firstNonEmpty($client, ['Apellido']));
        return [
            'nombre' => $name,
            'telefono' => self::firstNonEmpty($client, ['Telefono']),
            'email' => self::firstNonEmpty($client, ['Email']),
        ];
    }

    private static function mergeStoreOrderCustomer(array $commerce, array $order, array $customer): array
    {
        $slug = trim((string)($commerce['slug'] ?? ''));
        if ($slug === '') {
            return $customer;
        }

        $needsName = self::firstNonEmpty($customer, ['cliente_nombre', 'nombre', 'Nombre', 'display_name']) === '';
        $needsPhone = self::firstNonEmpty($customer, ['cliente_telefono', 'telefono', 'Telefono']) === '';
        $needsEmail = self::firstNonEmpty($customer, ['cliente_email', 'email', 'Email']) === '';
        if (!$needsName && !$needsPhone && !$needsEmail) {
            return $customer;
        }

        $local = self::localCustomerForOrder($slug, $order);
        if ($needsName && trim((string)($local['nombre'] ?? '')) !== '') {
            $customer['nombre'] = (string)$local['nombre'];
        }
        if ($needsPhone && trim((string)($local['telefono'] ?? '')) !== '') {
            $customer['telefono'] = (string)$local['telefono'];
        }
        if ($needsEmail && trim((string)($local['email'] ?? '')) !== '') {
            $customer['email'] = (string)$local['email'];
        }
        return $customer;
    }

    private static function formatOrderItems(array $items, string $currency): string
    {
        $lines = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $name = self::firstNonEmpty($item, ['name', 'Nombre', 'title']);
            if ($name === '') {
                $name = 'Producto';
            }
            $qty = (int)($item['qty'] ?? $item['cantidad'] ?? $item['quantity'] ?? 1);
            $price = (float)($item['price'] ?? $item['Precio'] ?? $item['unit_price'] ?? 0);
            $subtotal = (float)($item['subtotal'] ?? 0);
            if ($subtotal <= 0 && $price > 0 && $qty > 0) {
                $subtotal = $price * $qty;
            }
            $line = '- ' . $name . ' x' . max(1, $qty);
            if ($subtotal > 0) {
                $line .= ' - ' . self::formatMoney($subtotal, $currency);
            }
            $lines[] = $line;
        }
        return implode("\n", $lines);
    }

    private static function orderTotalLabel(array $order, array $items, string $currency): string
    {
        $raw = self::firstNonEmpty($order, ['Total', 'total', 'amount']);
        $total = is_numeric($raw) ? (float)$raw : 0.0;
        if ($total <= 0) {
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $subtotal = (float)($item['subtotal'] ?? 0);
                if ($subtotal <= 0) {
                    $qty = (int)($item['qty'] ?? $item['cantidad'] ?? $item['quantity'] ?? 1);
                    $price = (float)($item['price'] ?? $item['Precio'] ?? $item['unit_price'] ?? 0);
                    $subtotal = $price * max(1, $qty);
                }
                $total += $subtotal;
            }
        }
        return $total > 0 ? self::formatMoney($total, $currency) : 'A coordinar';
    }

    private static function formatMoney(float $amount, string $currency): string
    {
        $currency = strtoupper(trim($currency)) ?: 'UYU';
        return $currency . ' ' . number_format($amount, 2, ',', '.');
    }

    private static function orderId(array $order): int
    {
        foreach (['ID_Carrito', 'local_order_id', 'order_id', 'id_order', 'id'] as $key) {
            if (isset($order[$key]) && is_numeric($order[$key]) && (int)$order[$key] > 0) {
                return (int)$order[$key];
            }
        }
        foreach ($order as $key => $value) {
            if (strpos((string)$key, 'ID_') === 0 && is_numeric($value) && (int)$value > 0) {
                return (int)$value;
            }
        }
        return 0;
    }

    private static function channelEnabled(int $idCommerce, string $channel): bool
    {
        $settings = CommerceSettings::get(
            $idCommerce,
            'notificaciones',
            CommerceSettings::defaultsForSection('notificaciones')
        );

        if ($channel === 'email') {
            return !array_key_exists('email_enabled', $settings)
                || filter_var($settings['email_enabled'], FILTER_VALIDATE_BOOLEAN);
        }

        if ($channel === 'whatsapp') {
            $top = !array_key_exists('whatsapp_enabled', $settings)
                || filter_var($settings['whatsapp_enabled'], FILTER_VALIDATE_BOOLEAN);
            $nested = true;
            if (is_array($settings['whatsapp'] ?? null) && array_key_exists('enabled', $settings['whatsapp'])) {
                $nested = filter_var($settings['whatsapp']['enabled'], FILTER_VALIDATE_BOOLEAN);
            }
            return $top && $nested;
        }

        return true;
    }

    private static function ownerWhatsApp(int $idCommerce, array $commerce): string
    {
        $settings = CommerceSettings::get(
            $idCommerce,
            'notificaciones',
            CommerceSettings::defaultsForSection('notificaciones')
        );
        $redes = CommerceSettings::get($idCommerce, 'redes', CommerceSettings::defaultsForSection('redes'));
        $waCfg = is_array($settings['whatsapp'] ?? null) ? $settings['whatsapp'] : [];
        $candidates = [
            (string)($waCfg['number'] ?? ''),
            (string)($waCfg['to'] ?? ''),
            (string)($redes['whatsapp'] ?? ''),
            (string)($commerce['whatsapp'] ?? ''),
            (string)($commerce['telefono'] ?? ''),
        ];
        foreach ($candidates as $candidate) {
            if (self::phoneDigits($candidate) !== '') {
                return trim($candidate);
            }
        }
        return '';
    }

    private static function commerceBySlug(string $slug): ?array
    {
        $slug = trim($slug, '/');
        if ($slug === '') {
            return null;
        }
        return Database::getInstance()->fetchOne('SELECT * FROM commerces WHERE slug = :s LIMIT 1', [':s' => $slug]);
    }

    private static function localRowById(array $rows, string $idKey, int $id): array
    {
        foreach ($rows as $idx => $row) {
            if ($idx === 0 || !is_array($row)) {
                continue;
            }
            if (isset($row[$idKey]) && is_numeric($row[$idKey]) && (int)$row[$idKey] === $id) {
                return $row;
            }
        }
        return [];
    }

    private static function normalizeAppointmentEvent(string $event): ?string
    {
        $event = self::normalizeLocalStatus($event);
        return match ($event) {
            'attended', 'atendido', 'finalizado', 'done', 'completed' => 'attended',
            'cancelled', 'canceled', 'cancelado', 'rechazado', 'rejected' => 'cancelled',
            default => null,
        };
    }

    private static function normalizeLocalStatus(string $status): string
    {
        $status = strtolower(trim($status));
        $status = str_replace(["\xc3\xa1", "\xc3\xa9", "\xc3\xad", "\xc3\xb3", "\xc3\xba"], ['a', 'e', 'i', 'o', 'u'], $status);
        return preg_replace('/\s+/', ' ', $status) ?? $status;
    }

    private static function normalizeTimeLabel(string $time): string
    {
        $time = trim(str_replace('.', ':', $time));
        if (preg_match('/^(\d{2}:\d{2}):\d{2}$/', $time, $matches)) {
            return $matches[1];
        }
        if (preg_match('/^\d{2}:\d{2}$/', $time)) {
            return $time;
        }
        return $time;
    }

    private static function firstNonEmpty(array $row, array $keys): string
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row)) {
                $value = trim((string)$row[$key]);
                if ($value !== '') {
                    return $value;
                }
            }
        }
        return '';
    }

    private static function firstNonEmptyNormalized(array $row, array $targets): string
    {
        $targets = array_map(static fn($target) => preg_replace('/[^a-z0-9]+/', '', strtolower((string)$target)) ?: '', $targets);
        foreach ($row as $key => $value) {
            $normalized = strtolower((string)$key);
            $normalized = preg_replace('/[^a-z0-9]+/', '', $normalized) ?: '';
            if (in_array($normalized, $targets, true)
                || ($normalized === 'direccin' && in_array('direccion', $targets, true))) {
                $value = trim((string)$value);
                if ($value !== '') {
                    return $value;
                }
            }
        }
        return '';
    }

    private static function numericValue(mixed $value): int
    {
        return is_numeric($value) ? (int)$value : 0;
    }

    private static function phoneDigits(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        return strlen($digits) >= 7 ? $digits : '';
    }

    private static function stringVars(array $vars): array
    {
        $out = [];
        foreach ($vars as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $out[(string)$key] = (string)($value ?? '');
            }
        }
        return $out;
    }

    private static function dispatch(array $row): bool
    {
        $channel = (string)$row['channel'];
        $recipient = (string)$row['recipient'];
        $subject = (string)$row['subject'];
        $body = (string)$row['body'];
        $idCommerce = isset($row['id_commerce']) ? (int)$row['id_commerce'] : null;

        if ($channel === 'email') {
            $attachments = [];
            $payload = json_decode((string)($row['payload_json'] ?? ''), true);
            foreach ((array)($payload['attachments'] ?? []) as $att) {
                $data = base64_decode((string)($att['data_b64'] ?? ''), true);
                if (is_string($data) && $data !== '') {
                    $attachments[] = [
                        'name' => (string)($att['name'] ?? 'archivo'),
                        'data' => $data,
                        'mime' => (string)($att['mime'] ?? 'application/octet-stream'),
                    ];
                }
            }
            $sent = Mail::send($recipient, $subject, $body, strip_tags($body), $idCommerce, $attachments);
            if (!$sent) {
                throw new \RuntimeException(Mail::lastError() ?: 'No se pudo enviar el email.');
            }
            return true;
        }
        if ($channel === 'whatsapp') {
            try {
                $ok = UltraMsg::send($recipient, $body);
                self::logWhatsApp($idCommerce, $recipient, $body, $ok ? 'sent' : 'failed', $ok ? null : 'UltraMsg deshabilitado o sin credenciales');
                return $ok;
            } catch (\Throwable $e) {
                self::logWhatsApp($idCommerce, $recipient, $body, 'failed', $e->getMessage());
                throw $e;
            }
        }
        return false;
    }

    private static function logWhatsApp(?int $idCommerce, string $recipient, string $body, string $status, ?string $error): void
    {
        try {
            Database::getInstance()->insert('notifications_log', [
                'id_commerce' => $idCommerce,
                'channel' => 'whatsapp',
                'recipient' => $recipient,
                'subject' => '',
                'body' => $body,
                'status' => $status,
                'error_message' => $error,
                'sent_at' => $status === 'sent' ? date('Y-m-d H:i:s') : null,
            ]);
        } catch (\Throwable $e) {
            error_log('[NotificationOutbox.logWhatsApp] ' . $e->getMessage());
        }
    }
}
