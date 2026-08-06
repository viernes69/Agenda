<?php
declare(strict_types=1);

namespace Agenduy\Core;

/**
 * Busca datos de clientes previos por email o teléfono (por comercio).
 */
final class ClientLookup
{
    /**
     * @return array{ok:bool,found:bool,nombre?:string,email?:string,telefono?:string}
     */
    public static function lookup(int $idCommerce, ?string $email, ?string $phone): array
    {
        $db = Database::getInstance();
        $result = self::doLookup($db, $idCommerce, $email, $phone);

        if ($result['found']) {
            $histEmail = strtolower(trim((string)($result['email'] ?? $email ?? '')));
            if ($histEmail !== '') {
                $result['historial'] = self::getHistorial($db, $idCommerce, $histEmail);
            }
        }
        return $result;
    }

    private static function doLookup(Database $db, int $idCommerce, ?string $email, ?string $phone): array
    {
        if ($idCommerce <= 0) {
            return ['ok' => false, 'found' => false, 'error' => 'Comercio inválido.'];
        }

        $email = strtolower(trim((string)$email));
        $phoneDigits = self::normalizePhoneDigits($phone);

        if ($email === '' && strlen($phoneDigits) < 8) {
            return ['ok' => true, 'found' => false];
        }

        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $fromClient = $db->fetchOne(
                'SELECT nombre, apellido, email, telefono, cedula FROM clients
                 WHERE id_commerce = :c AND lower(trim(email)) = :e
                 ORDER BY updated_at DESC, id_client DESC LIMIT 1',
                [':c' => $idCommerce, ':e' => $email]
            );
            if ($fromClient) {
                return ['ok' => true, 'found' => true] + self::formatRow($fromClient, $email, $phoneDigits);
            }

            $fromAppt = $db->fetchOne(
                'SELECT cliente_nombre AS nombre, cliente_email AS email, cliente_telefono AS telefono
                 FROM appointments
                 WHERE id_commerce = :c AND lower(trim(cliente_email)) = :e
                 ORDER BY id_appointment DESC LIMIT 1',
                [':c' => $idCommerce, ':e' => $email]
            );
            if ($fromAppt && trim((string)($fromAppt['nombre'] ?? '')) !== '') {
                return ['ok' => true, 'found' => true] + self::formatRow($fromAppt, $email, $phoneDigits);
            }
        }

        if (strlen($phoneDigits) >= 8) {
            $suffix = substr($phoneDigits, -8);
            $fromClient = $db->fetchOne(
                "SELECT nombre, apellido, email, telefono, cedula FROM clients
                 WHERE id_commerce = :c
                   AND replace(replace(replace(replace(replace(telefono,' ',''),'-',''),'.',''),'+',''),'(','') LIKE :p
                 ORDER BY updated_at DESC, id_client DESC LIMIT 1",
                [':c' => $idCommerce, ':p' => '%' . $suffix]
            );
            if ($fromClient) {
                return ['ok' => true, 'found' => true] + self::formatRow($fromClient, $email, $phoneDigits);
            }

            $fromAppt = $db->fetchOne(
                "SELECT cliente_nombre AS nombre, cliente_email AS email, cliente_telefono AS telefono
                 FROM appointments
                 WHERE id_commerce = :c
                   AND replace(replace(replace(replace(replace(cliente_telefono,' ',''),'-',''),'.',''),'+',''),'(','') LIKE :p
                 ORDER BY id_appointment DESC LIMIT 1",
                [':c' => $idCommerce, ':p' => '%' . $suffix]
            );
            if ($fromAppt && trim((string)($fromAppt['nombre'] ?? '')) !== '') {
                return ['ok' => true, 'found' => true] + self::formatRow($fromAppt, $email, $phoneDigits);
            }
        }

        return ['ok' => true, 'found' => false];
    }

    private static function getHistorial(Database $db, int $idCommerce, string $email): array
    {
        $historial = ['reservas' => [], 'pedidos' => []];
        try {
            $reservasDb = $db->fetchAll(
                "SELECT a.id_appointment as id, a.fecha, a.hora_inicio as hora, a.status, COALESCE(s.nombre, '') AS servicio
                 FROM appointments a
                 LEFT JOIN services s ON s.id_service = a.id_service
                 WHERE a.id_commerce = :c AND lower(trim(a.cliente_email)) = :e
                 ORDER BY a.fecha DESC, a.hora_inicio DESC LIMIT 20",
                [':c' => $idCommerce, ':e' => $email]
            );
            foreach ($reservasDb as $r) {
                $historial['reservas'][] = $r;
            }
            $pedidosDb = $db->fetchAll(
                "SELECT id_order as id, fecha, status, total
                 FROM commerce_orders
                 WHERE id_commerce = :c AND lower(trim(cliente_email)) = :e
                 ORDER BY fecha DESC LIMIT 20",
                [':c' => $idCommerce, ':e' => $email]
            );
            foreach ($pedidosDb as $p) {
                $historial['pedidos'][] = $p;
            }
        } catch (\Throwable $e) {}
        return $historial;
    }

    private static function normalizePhoneDigits(?string $phone): string
    {
        return preg_replace('/\D+/', '', (string)$phone) ?? '';
    }

    /**
     * @param array<string,mixed> $row
     * @return array{nombre:string,email:string,telefono:string,cedula:string}
     */
    private static function formatRow(array $row, string $preferredEmail, string $preferredPhoneDigits): array
    {
        $nombre = trim((string)($row['nombre'] ?? ''));
        $apellido = trim((string)($row['apellido'] ?? ''));
        if ($apellido !== '' && stripos($nombre, $apellido) === false) {
            $nombre = trim($nombre . ' ' . $apellido);
        }

        $email = strtolower(trim((string)($row['email'] ?? '')));
        if ($email === '' && $preferredEmail !== '') {
            $email = $preferredEmail;
        }

        $telefono = trim((string)($row['telefono'] ?? ''));
        if ($telefono === '' && strlen($preferredPhoneDigits) >= 8) {
            $telefono = $preferredPhoneDigits;
        }

        return [
            'nombre'   => $nombre,
            'email'    => $email,
            'telefono' => $telefono,
            'cedula'   => trim((string)($row['cedula'] ?? '')),
        ];
    }
}
