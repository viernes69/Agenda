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
        if ($idCommerce <= 0) {
            return ['ok' => false, 'found' => false, 'error' => 'Comercio inválido.'];
        }

        $email = strtolower(trim((string)$email));
        $phoneDigits = self::normalizePhoneDigits($phone);

        if ($email === '' && strlen($phoneDigits) < 8) {
            return ['ok' => true, 'found' => false];
        }

        $db = Database::getInstance();

        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $fromClient = $db->fetchOne(
                'SELECT nombre, apellido, email, telefono FROM clients
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
                "SELECT nombre, apellido, email, telefono FROM clients
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

    private static function normalizePhoneDigits(?string $phone): string
    {
        return preg_replace('/\D+/', '', (string)$phone) ?? '';
    }

    /**
     * @param array<string,mixed> $row
     * @return array{nombre:string,email:string,telefono:string}
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
        ];
    }
}
