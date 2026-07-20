<?php
declare(strict_types=1);

namespace Agenduy\Core;

/**
 * Cola idempotente de notificaciones email/WhatsApp.
 */
final class NotificationOutbox
{
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

        return (int)$db->insert('notification_outbox', [
            'id_commerce'     => $idCommerce,
            'channel'         => $channel,
            'recipient'       => $recipient,
            'template_key'    => $templateKey,
            'subject'         => $subject,
            'body'            => $body,
            'payload_json'    => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'scheduled_at'    => $scheduledAt,
            'idempotency_key' => $idempotencyKey,
            'status'          => 'queued',
        ]);
    }

    public static function enqueueAppointmentNotifications(array $appointment, array $commerce, ?array $service): void
    {
        $idCommerce = (int)$commerce['id_commerce'];
        $fecha = (string)$appointment['fecha'];
        $hora = (string)$appointment['hora_inicio'];
        $cliente = (string)$appointment['cliente_nombre'];
        $tel = (string)$appointment['cliente_telefono'];
        $email = (string)$appointment['cliente_email'];
        $svcName = (string)($service['nombre'] ?? 'Servicio');
        $bizName = (string)($commerce['nombre'] ?? 'Negocio');
        $bizEmail = (string)($commerce['email'] ?? '');
        $bizWhatsapp = (string)($commerce['whatsapp'] ?? $commerce['telefono'] ?? '');
        $idAppt = (int)$appointment['id_appointment'];

        $clientMsg = "Hola {$cliente}, tu reserva en {$bizName} quedó confirmada.\n"
            . "Servicio: {$svcName}\nFecha: {$fecha}\nHora: {$hora}";

        if ($email !== '') {
            self::enqueue(
                $idCommerce,
                'email',
                $email,
                'appointment_confirmed_client',
                "Reserva confirmada - {$bizName}",
                '<p>' . nl2br(htmlspecialchars($clientMsg, ENT_QUOTES, 'UTF-8')) . '</p>',
                ['appointment_id' => $idAppt],
                date('Y-m-d H:i:s'),
                "appt:{$idAppt}:email:client:confirm"
            );
        }

        if ($tel !== '') {
            self::enqueue(
                $idCommerce,
                'whatsapp',
                $tel,
                'appointment_confirmed_client',
                '',
                $clientMsg,
                ['appointment_id' => $idAppt],
                date('Y-m-d H:i:s'),
                "appt:{$idAppt}:wa:client:confirm"
            );
        }

        $ownerMsg = "Nueva reserva en {$bizName}\n"
            . "Cliente: {$cliente}\nCelular: {$tel}\n"
            . "Servicio: {$svcName}\nFecha: {$fecha}\nHora: {$hora}";

        if ($bizEmail !== '') {
            self::enqueue(
                $idCommerce,
                'email',
                $bizEmail,
                'appointment_confirmed_owner',
                "Nueva reserva - {$cliente}",
                '<p>' . nl2br(htmlspecialchars($ownerMsg, ENT_QUOTES, 'UTF-8')) . '</p>',
                ['appointment_id' => $idAppt],
                date('Y-m-d H:i:s'),
                "appt:{$idAppt}:email:owner:confirm"
            );
        }

        if ($bizWhatsapp !== '') {
            self::enqueue(
                $idCommerce,
                'whatsapp',
                $bizWhatsapp,
                'appointment_confirmed_owner',
                '',
                $ownerMsg,
                ['appointment_id' => $idAppt],
                date('Y-m-d H:i:s'),
                "appt:{$idAppt}:wa:owner:confirm"
            );
        }

        $tz = (string)($commerce['timezone'] ?? 'America/Montevideo');
        try {
            $dt = new \DateTimeImmutable("{$fecha} {$hora}", new \DateTimeZone($tz));
            $remind24 = $dt->modify('-1 day')->format('Y-m-d H:i:s');
            $remind2 = $dt->modify('-2 hours')->format('Y-m-d H:i:s');
            $reminderMsg = "Recordatorio: mañana tienes {$svcName} en {$bizName} a las {$hora}.";

            if ($tel !== '') {
                self::enqueue(
                    $idCommerce,
                    'whatsapp',
                    $tel,
                    'appointment_reminder_24h',
                    '',
                    $reminderMsg,
                    ['appointment_id' => $idAppt],
                    $remind24,
                    "appt:{$idAppt}:wa:client:24h"
                );
                self::enqueue(
                    $idCommerce,
                    'whatsapp',
                    $tel,
                    'appointment_reminder_2h',
                    '',
                    "Recordatorio: en 2 horas tienes {$svcName} en {$bizName} ({$hora}).",
                    ['appointment_id' => $idAppt],
                    $remind2,
                    "appt:{$idAppt}:wa:client:2h"
                );
            }
            if ($email !== '') {
                self::enqueue(
                    $idCommerce,
                    'email',
                    $email,
                    'appointment_reminder_24h',
                    "Recordatorio de reserva - {$bizName}",
                    '<p>' . htmlspecialchars($reminderMsg, ENT_QUOTES, 'UTF-8') . '</p>',
                    ['appointment_id' => $idAppt],
                    $remind24,
                    "appt:{$idAppt}:email:client:24h"
                );
            }
        } catch (\Throwable $e) {
            error_log('[NotificationOutbox] reminder schedule failed: ' . $e->getMessage());
        }
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

        $stats = ['processed' => 0, 'sent' => 0, 'failed' => 0];
        foreach ($rows as $row) {
            $stats['processed']++;
            $id = (int)$row['id_outbox'];
            try {
                $ok = self::dispatch($row);
                $db->update('notification_outbox', [
                    'status'   => $ok ? 'sent' : 'failed',
                    'attempts' => (int)$row['attempts'] + 1,
                    'sent_at'  => $ok ? date('Y-m-d H:i:s') : null,
                    'last_error' => $ok ? '' : 'dispatch returned false',
                ], 'id_outbox = :id', [':id' => $id]);
                $stats[$ok ? 'sent' : 'failed']++;
            } catch (\Throwable $e) {
                $db->update('notification_outbox', [
                    'status'     => 'failed',
                    'attempts'   => (int)$row['attempts'] + 1,
                    'last_error' => $e->getMessage(),
                ], 'id_outbox = :id', [':id' => $id]);
                $stats['failed']++;
            }
        }
        return $stats;
    }

    private static function dispatch(array $row): bool
    {
        $channel = (string)$row['channel'];
        $recipient = (string)$row['recipient'];
        $subject = (string)$row['subject'];
        $body = (string)$row['body'];
        $idCommerce = isset($row['id_commerce']) ? (int)$row['id_commerce'] : null;

        if ($channel === 'email') {
            // Adjuntos opcionales (ej. .ics) guardados en payload_json al encolar.
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
            return Mail::send($recipient, $subject, $body, strip_tags($body), $idCommerce, $attachments);
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
                'id_commerce'   => $idCommerce,
                'channel'       => 'whatsapp',
                'recipient'     => $recipient,
                'subject'       => '',
                'body'          => $body,
                'status'        => $status,
                'error_message' => $error,
                'sent_at'       => $status === 'sent' ? date('Y-m-d H:i:s') : null,
            ]);
        } catch (\Throwable $e) {
            error_log('[NotificationOutbox.logWhatsApp] ' . $e->getMessage());
        }
    }
}
