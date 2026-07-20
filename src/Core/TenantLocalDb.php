<?php
declare(strict_types=1);

namespace Agenduy\Core;

/**
 * Lectura/escritura con flock de {slug}/src/db/database.php (legado AutoloadDB).
 */
final class TenantLocalDb
{
    public static function pathForSlug(string $slug): string
    {
        $slug = trim($slug, '/');
        if ($slug === '' || !preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug)) {
            throw new \InvalidArgumentException('Slug de comercio inválido.');
        }
        $root = dirname(__DIR__, 2);
        return $root . DIRECTORY_SEPARATOR . $slug
            . DIRECTORY_SEPARATOR . 'src'
            . DIRECTORY_SEPARATOR . 'db'
            . DIRECTORY_SEPARATOR . 'database.php';
    }

    public static function lockPathForSlug(string $slug): string
    {
        return self::pathForSlug($slug) . '.lock';
    }

    public static function exists(string $slug): bool
    {
        try {
            return is_file(self::pathForSlug($slug));
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * @return array<string,mixed>
     */
    public static function read(string $slug): array
    {
        $path = self::pathForSlug($slug);
        if (!is_file($path)) {
            throw new \RuntimeException('Base local del comercio no encontrada.');
        }
        $db = @include $path;
        if (!is_array($db)) {
            throw new \RuntimeException('Base local del comercio inválida.');
        }
        return $db;
    }

    /**
     * @template T
     * @param callable(array):T $mutator Recibe $db por referencia vía array; debe devolver [db, result]
     * @return mixed
     */
    public static function mutate(string $slug, callable $mutator)
    {
        $path = self::pathForSlug($slug);
        $lockPath = self::lockPathForSlug($slug);
        $lockDir = dirname($lockPath);
        if (!is_dir($lockDir) && !mkdir($lockDir, 0755, true) && !is_dir($lockDir)) {
            throw new \RuntimeException('No se pudo preparar el lock del comercio.');
        }

        $lockFh = fopen($lockPath, 'c+');
        if ($lockFh === false) {
            throw new \RuntimeException('No se pudo abrir el lock del comercio.');
        }

        try {
            if (!flock($lockFh, LOCK_EX)) {
                throw new \RuntimeException('No se pudo bloquear la base local.');
            }
            clearstatcache(true, $path);
            $db = @include $path;
            if (!is_array($db)) {
                throw new \RuntimeException('Base local del comercio inválida.');
            }

            $result = $mutator($db);
            if (!is_array($result) || !array_key_exists(0, $result) || !is_array($result[0])) {
                throw new \RuntimeException('Mutación de base local inválida.');
            }
            [$nextDb, $payload] = $result;
            self::writeAtomic($path, $nextDb);
            return $payload;
        } finally {
            flock($lockFh, LOCK_UN);
            fclose($lockFh);
        }
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public static function insert(string $slug, string $table, array $data): array
    {
        return self::mutate($slug, static function (array $db) use ($table, $data) {
            if (!isset($db[$table]) || !is_array($db[$table])) {
                throw new \InvalidArgumentException("Tabla no encontrada: {$table}");
            }
            if (!isset($db[$table][0]) || !is_array($db[$table][0])) {
                throw new \RuntimeException("La tabla {$table} no tiene fila plantilla.");
            }
            $template = $db[$table][0];
            $pk = self::primaryKey($template);
            $row = $template;
            foreach ($data as $k => $v) {
                if (array_key_exists($k, $row)) {
                    $row[$k] = $v;
                }
            }
            if ($pk !== null && (!isset($row[$pk]) || $row[$pk] === null || $row[$pk] === '')) {
                $row[$pk] = self::nextId($db[$table], $pk);
            }
            $db[$table][] = $row;
            return [$db, $row];
        });
    }

    /**
     * Busca cliente por email/teléfono o crea uno nuevo. Retorna ID_Cliente o null.
     */
    public static function findOrCreateCliente(
        string $slug,
        string $nombre,
        string $telefono = '',
        string $email = ''
    ): ?int {
        $nombre = trim($nombre);
        $telefono = trim($telefono);
        $email = trim($email);
        if ($nombre === '' && $telefono === '' && $email === '') {
            return null;
        }

        return self::mutate($slug, static function (array $db) use ($nombre, $telefono, $email) {
            if (!isset($db['clientes']) || !is_array($db['clientes']) || !isset($db['clientes'][0])) {
                return [$db, null];
            }

            $emailNorm = $email !== '' ? mb_strtolower($email, 'UTF-8') : '';
            $phoneDigits = $telefono !== '' ? preg_replace('/\D+/', '', $telefono) : '';

            foreach ($db['clientes'] as $idx => $row) {
                if ($idx === 0 || !is_array($row)) {
                    continue;
                }
                $rowEmail = mb_strtolower(trim((string)($row['Email'] ?? '')), 'UTF-8');
                $rowPhone = preg_replace('/\D+/', '', (string)($row['Telefono'] ?? ''));
                $matchEmail = $emailNorm !== '' && $rowEmail !== '' && $rowEmail === $emailNorm;
                $matchPhone = $phoneDigits !== '' && $rowPhone !== '' && $rowPhone === $phoneDigits;
                if ($matchEmail || $matchPhone) {
                    $id = $row['ID_Cliente'] ?? null;
                    return [$db, ($id !== null && is_numeric($id)) ? (int)$id : null];
                }
            }

            $template = $db['clientes'][0];
            $pk = self::primaryKey($template) ?? 'ID_Cliente';
            $row = $template;
            $row[$pk] = self::nextId($db['clientes'], $pk);
            if (array_key_exists('Nombre', $row)) {
                $row['Nombre'] = $nombre !== '' ? $nombre : 'Cliente WhatsApp';
            }
            if (array_key_exists('Telefono', $row)) {
                $row['Telefono'] = $telefono;
            }
            if (array_key_exists('Email', $row)) {
                $row['Email'] = $email;
            }
            if (array_key_exists('Cedula', $row) && ($row['Cedula'] === null || $row['Cedula'] === '')) {
                $row['Cedula'] = '';
            }
            if (array_key_exists('Perfil', $row) && ($row['Perfil'] === null || $row['Perfil'] === '')) {
                $row['Perfil'] = '';
            }
            $db['clientes'][] = $row;
            return [$db, (int)$row[$pk]];
        });
    }

    /**
     * Espeja un appointment central (SQLite) en la tabla local `reservas`
     * que lee el admin del tenant. Idempotente por ID_Appointment.
     *
     * @param array<string,mixed> $appointment Filas de appointments (+ opc. id_local del servicio)
     * @return array{row: ?array<string,mixed>, created: bool, skipped: bool}
     */
    public static function mirrorAppointment(string $slug, array $appointment): array
    {
        if (!self::exists($slug)) {
            return ['row' => null, 'created' => false, 'skipped' => true];
        }

        $appointmentId = (int)($appointment['id_appointment'] ?? 0);
        if ($appointmentId <= 0) {
            throw new \InvalidArgumentException('Falta id_appointment para espejar.');
        }

        $fecha = trim((string)($appointment['fecha'] ?? ''));
        $hora = trim((string)($appointment['hora_inicio'] ?? ''));
        if ($fecha === '' || $hora === '') {
            throw new \InvalidArgumentException('Appointment sin fecha/hora.');
        }
        if (preg_match('/^\d{2}:\d{2}$/', $hora)) {
            $hora .= ':00';
        }

        $clienteNombre = trim((string)($appointment['cliente_nombre'] ?? ''));
        $clienteEmail = trim((string)($appointment['cliente_email'] ?? ''));
        $clienteTelefono = trim((string)($appointment['cliente_telefono'] ?? ''));
        $clienteId = self::findOrCreateCliente($slug, $clienteNombre, $clienteTelefono, $clienteEmail);

        $idLocalService = null;
        if (isset($appointment['id_local']) && is_numeric($appointment['id_local'])) {
            $idLocalService = (int)$appointment['id_local'];
        } elseif (isset($appointment['id_service']) && is_numeric($appointment['id_service'])) {
            $idLocalService = self::resolveLocalServiceId($slug, (int)$appointment['id_service'], (int)($appointment['id_commerce'] ?? 0));
        }

        $idBarber = self::defaultBarberId($slug);
        $localStatus = self::mapCentralStatusToLocal((string)($appointment['status'] ?? 'pending'));

        $precio = null;
        if (isset($appointment['precio']) && is_numeric($appointment['precio']) && (float)$appointment['precio'] > 0) {
            $precio = round((float)$appointment['precio'], 2);
        } elseif ($idLocalService !== null) {
            $precio = self::localServicePrice($slug, $idLocalService);
        }

        return self::mutate($slug, static function (array $db) use (
            $appointmentId,
            $clienteId,
            $idLocalService,
            $idBarber,
            $hora,
            $fecha,
            $localStatus,
            $precio
        ) {
            if (!isset($db['reservas']) || !is_array($db['reservas']) || !isset($db['reservas'][0]) || !is_array($db['reservas'][0])) {
                throw new \RuntimeException('Tabla reservas local no disponible.');
            }

            // Asegurar columnas de enlace / precio (tenants viejos no las tienen).
            foreach (['ID_Appointment' => null, 'Precio' => null] as $col => $default) {
                if (!array_key_exists($col, $db['reservas'][0])) {
                    $db['reservas'][0][$col] = $default;
                    foreach ($db['reservas'] as $i => $row) {
                        if ($i === 0 || !is_array($row)) {
                            continue;
                        }
                        if (!array_key_exists($col, $row)) {
                            $db['reservas'][$i][$col] = $default;
                        }
                    }
                }
            }

            // Si no vino precio útil en el appointment, resolver desde servicios locales.
            $resolvedPrecio = ($precio !== null && $precio > 0) ? $precio : null;
            if ($resolvedPrecio === null && $idLocalService !== null) {
                foreach (($db['servicios'] ?? []) as $si => $svcRow) {
                    if ($si === 0 || !is_array($svcRow)) {
                        continue;
                    }
                    if ((int)($svcRow['ID_Servicio'] ?? 0) === $idLocalService && is_numeric($svcRow['Precio'] ?? null)) {
                        $svcPrice = round((float)$svcRow['Precio'], 2);
                        if ($svcPrice > 0) {
                            $resolvedPrecio = $svcPrice;
                        }
                        break;
                    }
                }
            }

            foreach ($db['reservas'] as $i => $row) {
                if ($i === 0 || !is_array($row)) {
                    continue;
                }
                $existingAppt = $row['ID_Appointment'] ?? null;
                if ($existingAppt !== null && $existingAppt !== '' && (int)$existingAppt === $appointmentId) {
                    $db['reservas'][$i]['Fecha_Reserva'] = $fecha;
                    $db['reservas'][$i]['Hora_Reserva'] = $hora;
                    // No degradar estados terminales locales (Finalizado/Cancelado) con un espejo débil.
                    $currentStatus = strtolower(trim((string)($db['reservas'][$i]['Status'] ?? '')));
                    $incomingStatus = strtolower(trim($localStatus));
                    $terminal = ['finalizado', 'cancelado', 'rechazado'];
                    $incomingTerminal = in_array($incomingStatus, $terminal, true);
                    if ($currentStatus === '' || $incomingTerminal || !in_array($currentStatus, $terminal, true)) {
                        $db['reservas'][$i]['Status'] = $localStatus;
                    }
                    if ($clienteId !== null && array_key_exists('ID_Cliente', $db['reservas'][$i])) {
                        $db['reservas'][$i]['ID_Cliente'] = $clienteId;
                    }
                    if ($idLocalService !== null && array_key_exists('ID_Servicio', $db['reservas'][$i])) {
                        $db['reservas'][$i]['ID_Servicio'] = $idLocalService;
                    }
                    if ($resolvedPrecio !== null && array_key_exists('Precio', $db['reservas'][$i])) {
                        $existingPrecio = $db['reservas'][$i]['Precio'] ?? null;
                        if ($existingPrecio === null || $existingPrecio === '' || !is_numeric($existingPrecio) || (float)$existingPrecio <= 0) {
                            $db['reservas'][$i]['Precio'] = $resolvedPrecio;
                        }
                    }
                    return [$db, ['row' => $db['reservas'][$i], 'created' => false, 'skipped' => false]];
                }
            }

            $template = $db['reservas'][0];
            $pk = self::primaryKey($template) ?? 'ID_Reserva';
            $row = $template;
            $row[$pk] = self::nextId($db['reservas'], $pk);
            if (array_key_exists('ID_Cliente', $row)) {
                $row['ID_Cliente'] = $clienteId;
            }
            if (array_key_exists('ID_Barber', $row)) {
                $row['ID_Barber'] = $idBarber;
            }
            if (array_key_exists('ID_Servicio', $row)) {
                $row['ID_Servicio'] = $idLocalService;
            }
            if (array_key_exists('Hora_Reserva', $row)) {
                $row['Hora_Reserva'] = $hora;
            }
            if (array_key_exists('Fecha_Reserva', $row)) {
                $row['Fecha_Reserva'] = $fecha;
            }
            if (array_key_exists('Status', $row)) {
                $row['Status'] = $localStatus;
            }
            if (array_key_exists('Precio', $row)) {
                $row['Precio'] = $resolvedPrecio;
            }
            $row['ID_Appointment'] = $appointmentId;
            $db['reservas'][] = $row;
            return [$db, ['row' => $row, 'created' => true, 'skipped' => false]];
        });
    }

    /**
     * Empuja Status/fecha/hora de una reserva local hacia appointments (SQLite).
     *
     * @param array<string,mixed> $localRow
     */
    public static function pushReservaToCentral(string $slug, array $localRow): void
    {
        $appointmentId = $localRow['ID_Appointment'] ?? null;
        if ($appointmentId === null || $appointmentId === '' || !is_numeric($appointmentId)) {
            return;
        }
        $appointmentId = (int)$appointmentId;
        if ($appointmentId <= 0) {
            return;
        }

        $db = Database::getInstance();
        $commerce = $db->fetchOne('SELECT id_commerce FROM commerces WHERE slug = :s LIMIT 1', [':s' => $slug]);
        if (!$commerce) {
            return;
        }

        $payload = [
            'status' => self::mapLocalStatusToCentral((string)($localRow['Status'] ?? 'Pendiente')),
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        $fecha = trim((string)($localRow['Fecha_Reserva'] ?? ''));
        if ($fecha !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
            $payload['fecha'] = $fecha;
        }
        $hora = trim((string)($localRow['Hora_Reserva'] ?? ''));
        if ($hora !== '') {
            if (preg_match('/^\d{2}:\d{2}$/', $hora)) {
                $hora .= ':00';
            }
            if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $hora)) {
                $payload['hora_inicio'] = substr($hora, 0, 5);
                // Recalcular hora_fin con duración del servicio (o +30 min).
                $existing = $db->fetchOne(
                    'SELECT id_service FROM appointments WHERE id_appointment = :id AND id_commerce = :c',
                    [':id' => $appointmentId, ':c' => (int)$commerce['id_commerce']]
                );
                $dur = 30;
                if ($existing && !empty($existing['id_service'])) {
                    $svc = $db->fetchOne(
                        'SELECT duracion_min FROM services WHERE id_service = :id',
                        [':id' => (int)$existing['id_service']]
                    );
                    if ($svc && (int)$svc['duracion_min'] > 0) {
                        $dur = (int)$svc['duracion_min'];
                    }
                }
                $payload['hora_fin'] = date('H:i:s', strtotime(substr($hora, 0, 5) . ' +' . $dur . ' minutes'));
            }
        }

        $db->update(
            'appointments',
            $payload,
            'id_appointment = :id AND id_commerce = :c',
            [':id' => $appointmentId, ':c' => (int)$commerce['id_commerce']]
        );
    }

    public static function mapCentralStatusToLocal(string $status): string
    {
        $s = strtolower(trim($status));
        return match ($s) {
            'confirmed', 'aprobado', 'approved', 'confirmado' => 'Aprobado',
            'cancelled', 'canceled', 'cancelado', 'rechazado', 'rejected' => 'Cancelado',
            'completed', 'finalizado', 'done' => 'Finalizado',
            'in_progress', 'en progreso' => 'En progreso',
            default => 'Pendiente',
        };
    }

    public static function mapLocalStatusToCentral(string $status): string
    {
        $s = strtolower(trim($status));
        return match ($s) {
            'aprobado', 'approved', 'confirmed', 'confirmado' => 'confirmed',
            'cancelado', 'cancelled', 'canceled', 'rechazado', 'rejected' => 'cancelled',
            'finalizado', 'completed', 'done' => 'completed',
            'en progreso', 'in_progress' => 'confirmed',
            default => 'pending',
        };
    }

    private static function resolveLocalServiceId(string $slug, int $idService, int $idCommerce = 0): ?int
    {
        if ($idService <= 0) {
            return null;
        }
        try {
            $db = Database::getInstance();
            $params = [':id' => $idService];
            $sql = 'SELECT id_local, id_commerce FROM services WHERE id_service = :id';
            if ($idCommerce > 0) {
                $sql .= ' AND id_commerce = :c';
                $params[':c'] = $idCommerce;
            }
            $sql .= ' LIMIT 1';
            $row = $db->fetchOne($sql, $params);
            if ($row && isset($row['id_local']) && is_numeric($row['id_local']) && (int)$row['id_local'] > 0) {
                return (int)$row['id_local'];
            }
        } catch (\Throwable $e) {
            // best-effort
        }
        return null;
    }

    private static function localServicePrice(string $slug, int $idLocalService): ?float
    {
        if ($idLocalService <= 0) {
            return null;
        }
        try {
            $db = self::read($slug);
        } catch (\Throwable $e) {
            return null;
        }
        foreach (($db['servicios'] ?? []) as $i => $row) {
            if ($i === 0 || !is_array($row)) {
                continue;
            }
            if ((int)($row['ID_Servicio'] ?? 0) !== $idLocalService) {
                continue;
            }
            if (!is_numeric($row['Precio'] ?? null)) {
                return null;
            }
            return round((float)$row['Precio'], 2);
        }
        return null;
    }

    private static function defaultBarberId(string $slug): ?int
    {
        try {
            $db = self::read($slug);
        } catch (\Throwable $e) {
            return null;
        }
        $fallback = null;
        foreach (($db['barberos'] ?? []) as $i => $row) {
            if ($i === 0 || !is_array($row)) {
                continue;
            }
            $id = $row['ID_Barber'] ?? null;
            if ($id === null || $id === '' || !is_numeric($id)) {
                continue;
            }
            $id = (int)$id;
            $rol = strtolower(trim((string)($row['Rol'] ?? '')));
            if ($rol === 'admin' || $rol === 'func') {
                return $id;
            }
            if ($fallback === null) {
                $fallback = $id;
            }
        }
        return $fallback;
    }

    /**
     * @return array<int,array{id:string,name:string,price:float}>
     */
    public static function productIndex(string $slug): array
    {
        $db = self::read($slug);
        $index = [];
        foreach (($db['productos'] ?? []) as $idx => $row) {
            if ($idx === 0 || !is_array($row)) {
                continue;
            }
            $pid = $row['ID_Product'] ?? null;
            if ($pid === null || $pid === '') {
                continue;
            }
            $key = (string)$pid;
            $index[$key] = [
                'id' => $key,
                'name' => trim((string)($row['Nombre'] ?? ('Producto ' . $key))),
                'price' => is_numeric($row['Precio'] ?? null) ? (float)$row['Precio'] : 0.0,
            ];
        }
        return $index;
    }

    /**
     * @param array<string,mixed> $template
     */
    private static function primaryKey(array $template): ?string
    {
        foreach ($template as $k => $_) {
            if (strpos((string)$k, 'ID_') === 0) {
                return (string)$k;
            }
        }
        foreach ($template as $k => $_) {
            return (string)$k;
        }
        return null;
    }

    /**
     * @param list<array<string,mixed>> $tableRows
     */
    private static function nextId(array $tableRows, string $pk): int
    {
        $max = 0;
        foreach ($tableRows as $i => $row) {
            if ($i === 0 || !is_array($row)) {
                continue;
            }
            if (isset($row[$pk]) && is_numeric($row[$pk])) {
                $max = max($max, (int)$row[$pk]);
            }
        }
        return $max + 1;
    }

    /**
     * @param array<string,mixed> $db
     */
    private static function writeAtomic(string $path, array $db): void
    {
        $code = "<?php return " . var_export($db, true) . ";\n";
        $dir = dirname($path);
        $tmp = $dir . DIRECTORY_SEPARATOR . '.database.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, $code, LOCK_EX) === false) {
            throw new \RuntimeException('No se pudo escribir la base local.');
        }
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException('No se pudo actualizar la base local.');
        }
    }
}
