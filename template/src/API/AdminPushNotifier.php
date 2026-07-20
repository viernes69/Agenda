<?php
declare(strict_types=1);

require_once __DIR__ . '/AdminPushStorage.php';

final class AdminPushNotifier
{
    private static ?array $config = null;

    public static function notifyReservation(array $reservation): void
    {
        $title = 'Nueva reserva';
        $cliente = trim((string)($reservation['Nombre'] ?? $reservation['Cliente'] ?? 'Cliente'));
        $fecha = trim((string)($reservation['Fecha_Reserva'] ?? ''));
        $hora = trim((string)($reservation['Hora_Reserva'] ?? ''));
        $bodyParts = [];
        if ($cliente !== '') {
            $bodyParts[] = $cliente;
        }
        if ($fecha !== '' || $hora !== '') {
            $bodyParts[] = trim($fecha . ' ' . $hora);
        }
        $body = $bodyParts ? implode(' · ', $bodyParts) : 'Tienes una nueva reserva en la agenda.';

        self::broadcast([
            'title' => $title,
            'body' => $body,
            'icon' => '/agenda/src/media/logo/logo.png',
            'badge' => '/agenda/src/media/logo/logo.png',
            'data' => [
                'url' => '/agenda/template/private/dashboard/admin/index.php#reservas',
                'type' => 'reservation',
                'reservation_id' => $reservation['ID_Reserva'] ?? null,
            ],
        ]);
    }

    private static function broadcast(array $payload): void
    {
        $config = self::getConfig();
        if (!$config) {
            return;
        }
        $subscriptions = AdminPushStorage::all();
        if (!$subscriptions) {
            return;
        }

        $autoload = dirname(__DIR__, 3) . '/vendor/autoload.php';
        if (!is_file($autoload)) {
            return;
        }
        require_once $autoload;

        if (!class_exists(\Minishlink\WebPush\WebPush::class)) {
            return;
        }

        $webPush = new \Minishlink\WebPush\WebPush([
            'VAPID' => [
                'subject' => $config['subject'],
                'publicKey' => $config['publicKey'],
                'privateKey' => $config['privateKey'],
            ],
        ]);

        foreach ($subscriptions as $sub) {
            try {
                $subscription = \Minishlink\WebPush\Subscription::create([
                    'endpoint' => $sub['endpoint'],
                    'keys' => $sub['keys'] ?? [],
                    'contentEncoding' => $sub['encoding'] ?? null,
                ]);
                $webPush->queueNotification($subscription, json_encode($payload, JSON_UNESCAPED_UNICODE));
            } catch (Throwable $e) {
                continue;
            }
        }

        foreach ($webPush->flush() as $report) {
            if (!$report->isSuccess()) {
                $endpoint = (string)$report->getRequest()->getUri();
                $status = $report->getResponse() ? $report->getResponse()->getStatusCode() : 0;
                if (in_array($status, [404, 410], true)) {
                    AdminPushStorage::removeByEndpoint($endpoint);
                }
            }
        }
    }

    private static function getConfig(): ?array
    {
        if (self::$config !== null) {
            return self::$config;
        }
        $configFile = __DIR__ . '/../config/push.php';
        $config = @include $configFile;
        if (!is_array($config)) {
            self::$config = null;
            return null;
        }
        $publicKey = trim((string)($config['publicKey'] ?? ''));
        $privateKey = trim((string)($config['privateKey'] ?? ''));
        if ($publicKey === '' || $privateKey === '') {
            self::$config = null;
            return null;
        }
        $subject = trim((string)($config['subject'] ?? 'mailto:soporte@example.com'));
        self::$config = [
            'subject' => $subject,
            'publicKey' => $publicKey,
            'privateKey' => $privateKey,
        ];
        return self::$config;
    }
}
