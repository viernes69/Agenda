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
    public static function lookup(int $idCommerce, ?string $email, ?string $phone, ?string $cedula = null): array
    {
        $db = Database::getInstance();
        $result = self::doLookup($db, $idCommerce, $email, $phone, $cedula);

        if ($result['found']) {
            $histEmail = strtolower(trim((string)($result['email'] ?? $email ?? '')));
            $histCedula = self::normalizeCedula((string)($result['cedula'] ?? $cedula ?? ''));
            $histPhone = self::normalizePhoneDigits((string)($result['telefono'] ?? $phone ?? ''));
            $result['historial'] = self::getHistorial($db, $idCommerce, $histEmail, $histCedula, $histPhone);
        }
        return $result;
    }

    private static function doLookup(Database $db, int $idCommerce, ?string $email, ?string $phone, ?string $cedula): array
    {
        if ($idCommerce <= 0) {
            return ['ok' => false, 'found' => false, 'error' => 'Comercio inválido.'];
        }

        $email = strtolower(trim((string)$email));
        $phoneDigits = self::normalizePhoneDigits($phone);
        $cedulaDigits = self::normalizeCedula($cedula);
        $emailIsValid = $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL);

        if (!$emailIsValid && strlen($phoneDigits) < 8 && strlen($cedulaDigits) < 7) {
            return ['ok' => true, 'found' => false];
        }

        if (strlen($cedulaDigits) >= 7) {
            $fromClient = $db->fetchOne(
                "SELECT nombre, apellido, email, telefono, cedula FROM clients
                 WHERE id_commerce = :c
                   AND replace(replace(trim(cedula),'.',''),'-','') = :ci
                 ORDER BY updated_at DESC, id_client DESC LIMIT 1",
                [':c' => $idCommerce, ':ci' => $cedulaDigits]
            );
            if ($fromClient) {
                return ['ok' => true, 'found' => true] + self::formatRow($fromClient, $email, $phoneDigits, $cedulaDigits);
            }

            $fromAppt = $db->fetchOne(
                "SELECT cliente_nombre AS nombre, cliente_email AS email, cliente_telefono AS telefono, cliente_cedula AS cedula
                 FROM appointments
                 WHERE id_commerce = :c
                   AND replace(replace(trim(cliente_cedula),'.',''),'-','') = :ci
                 ORDER BY id_appointment DESC LIMIT 1",
                [':c' => $idCommerce, ':ci' => $cedulaDigits]
            );
            if ($fromAppt && trim((string)($fromAppt['nombre'] ?? '')) !== '') {
                return ['ok' => true, 'found' => true] + self::formatRow($fromAppt, $email, $phoneDigits, $cedulaDigits);
            }
        }

        if ($emailIsValid) {
            $fromClient = $db->fetchOne(
                'SELECT nombre, apellido, email, telefono, cedula FROM clients
                 WHERE id_commerce = :c AND lower(trim(email)) = :e
                 ORDER BY updated_at DESC, id_client DESC LIMIT 1',
                [':c' => $idCommerce, ':e' => $email]
            );
            if ($fromClient) {
                return ['ok' => true, 'found' => true] + self::formatRow($fromClient, $email, $phoneDigits, $cedulaDigits);
            }

            $fromAppt = $db->fetchOne(
                'SELECT cliente_nombre AS nombre, cliente_email AS email, cliente_telefono AS telefono, cliente_cedula AS cedula
                 FROM appointments
                 WHERE id_commerce = :c AND lower(trim(cliente_email)) = :e
                 ORDER BY id_appointment DESC LIMIT 1',
                [':c' => $idCommerce, ':e' => $email]
            );
            if ($fromAppt && trim((string)($fromAppt['nombre'] ?? '')) !== '') {
                return ['ok' => true, 'found' => true] + self::formatRow($fromAppt, $email, $phoneDigits, $cedulaDigits);
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
                return ['ok' => true, 'found' => true] + self::formatRow($fromClient, $email, $phoneDigits, $cedulaDigits);
            }

            $fromAppt = $db->fetchOne(
                "SELECT cliente_nombre AS nombre, cliente_email AS email, cliente_telefono AS telefono, cliente_cedula AS cedula
                 FROM appointments
                 WHERE id_commerce = :c
                   AND replace(replace(replace(replace(replace(cliente_telefono,' ',''),'-',''),'.',''),'+',''),'(','') LIKE :p
                 ORDER BY id_appointment DESC LIMIT 1",
                [':c' => $idCommerce, ':p' => '%' . $suffix]
            );
            if ($fromAppt && trim((string)($fromAppt['nombre'] ?? '')) !== '') {
                return ['ok' => true, 'found' => true] + self::formatRow($fromAppt, $email, $phoneDigits, $cedulaDigits);
            }
        }

        $global = self::lookupGlobalClient($db, $cedulaDigits, $emailIsValid ? $email : '', $phoneDigits);
        if ($global) {
            return ['ok' => true, 'found' => true] + self::formatRow($global, $email, $phoneDigits, $cedulaDigits);
        }

        return ['ok' => true, 'found' => false];
    }

    private static function lookupGlobalClient(Database $db, string $cedulaDigits, string $email, string $phoneDigits): ?array
    {
        if (strlen($cedulaDigits) < 7) {
            return null;
        }

        if ($email !== '') {
            $row = $db->fetchOne(
                "SELECT nombre, apellido, email, telefono, cedula FROM clients
                 WHERE replace(replace(trim(cedula),'.',''),'-','') = :ci
                   AND lower(trim(email)) = :e
                 ORDER BY updated_at DESC, id_client DESC LIMIT 1",
                [':ci' => $cedulaDigits, ':e' => $email]
            );
            if ($row) {
                return $row;
            }
        }

        if (strlen($phoneDigits) >= 8) {
            $suffix = substr($phoneDigits, -8);
            $row = $db->fetchOne(
                "SELECT nombre, apellido, email, telefono, cedula FROM clients
                 WHERE replace(replace(trim(cedula),'.',''),'-','') = :ci
                   AND replace(replace(replace(replace(replace(telefono,' ',''),'-',''),'.',''),'+',''),'(','') LIKE :p
                 ORDER BY updated_at DESC, id_client DESC LIMIT 1",
                [':ci' => $cedulaDigits, ':p' => '%' . $suffix]
            );
            if ($row) {
                return $row;
            }
        }

        return null;
    }

    private static function getHistorial(Database $db, int $idCommerce, string $email, string $cedula, string $phoneDigits): array
    {
        $historial = ['reservas' => [], 'pedidos' => []];
        try {
            $where = [];
            $params = [':c' => $idCommerce];
            if ($email !== '') {
                $where[] = 'lower(trim(a.cliente_email)) = :e';
                $params[':e'] = $email;
            }
            if (strlen($cedula) >= 7) {
                $where[] = "replace(replace(trim(a.cliente_cedula),'.',''),'-','') = :ci";
                $params[':ci'] = $cedula;
            }
            if (strlen($phoneDigits) >= 8) {
                $where[] = "replace(replace(replace(replace(replace(a.cliente_telefono,' ',''),'-',''),'.',''),'+',''),'(','') LIKE :p";
                $params[':p'] = '%' . substr($phoneDigits, -8);
            }
            if (!$where) {
                return $historial;
            }
            $matchSql = '(' . implode(' OR ', $where) . ')';
            $reservasDb = $db->fetchAll(
                "SELECT a.id_appointment as id, a.fecha, a.hora_inicio as hora, a.status, COALESCE(s.nombre, '') AS servicio
                 FROM appointments a
                 LEFT JOIN services s ON s.id_service = a.id_service
                 WHERE a.id_commerce = :c AND {$matchSql}
                 ORDER BY a.fecha DESC, a.hora_inicio DESC LIMIT 20",
                $params
            );
            foreach ($reservasDb as $r) {
                $historial['reservas'][] = $r;
            }
            if ($email !== '' || strlen($phoneDigits) >= 8) {
                $orderWhere = [];
                $orderParams = [':c' => $idCommerce];
                if ($email !== '') {
                    $orderWhere[] = 'lower(trim(cliente_email)) = :oe';
                    $orderParams[':oe'] = $email;
                }
                if (strlen($phoneDigits) >= 8) {
                    $orderWhere[] = "replace(replace(replace(replace(replace(cliente_telefono,' ',''),'-',''),'.',''),'+',''),'(','') LIKE :op";
                    $orderParams[':op'] = '%' . substr($phoneDigits, -8);
                }
                $pedidosDb = $db->fetchAll(
                    "SELECT id_order as id, fecha, status, total
                     FROM commerce_orders
                     WHERE id_commerce = :c AND (" . implode(' OR ', $orderWhere) . ")
                     ORDER BY fecha DESC LIMIT 20",
                    $orderParams
                );
                foreach ($pedidosDb as $p) {
                    $historial['pedidos'][] = $p;
                }
            }
        } catch (\Throwable $e) {}
        return $historial;
    }

    private static function normalizePhoneDigits(?string $phone): string
    {
        return preg_replace('/\D+/', '', (string)$phone) ?? '';
    }

    private static function normalizeCedula(?string $cedula): string
    {
        return preg_replace('/\D+/', '', (string)$cedula) ?? '';
    }

    /**
     * @param array<string,mixed> $row
     * @return array{nombre:string,email:string,telefono:string,cedula:string}
     */
    private static function formatRow(array $row, string $preferredEmail, string $preferredPhoneDigits, string $preferredCedula): array
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

        $cedula = self::normalizeCedula((string)($row['cedula'] ?? ''));
        if ($cedula === '' && strlen($preferredCedula) >= 7) {
            $cedula = $preferredCedula;
        }

        return [
            'nombre'   => $nombre,
            'email'    => $email,
            'telefono' => $telefono,
            'cedula'   => $cedula,
        ];
    }
}
