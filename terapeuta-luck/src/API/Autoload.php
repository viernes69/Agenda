<?php
// Simple file-backed array DB API using flock on database.php
// Location: src/db/Autoload.php

class AutoloadDB {
    // Point to the actual database file under src/db
    private const DB_PATH = __DIR__ . '/../db/database.php';

    // Public API helpers
    public static function all(string $table): array {
        if ($table === 'reservas') {
            self::autoCloseExpiredReservas();
        }
        $db = self::readShared();
        return self::rowsWithoutTemplate($db, $table);
    }

    public static function find(string $table, $id) {
        if ($table === 'reservas') {
            self::autoCloseExpiredReservas();
        }
        [$db, $pk] = self::readSharedWithPk($table);
        foreach (self::rowsWithoutTemplate($db, $table) as $row) {
            if (isset($row[$pk]) && (string)$row[$pk] === (string)$id) {
                return $row;
            }
        }
        return null;
    }

    public static function insert(string $table, array $data): array {
        $db = self::readShared();
        $fh = self::openAndLock(true);
        $row = [];
        try {
            self::assertTable($db, $table);
            $template = self::getTemplate($db, $table);
            $pk = self::primaryKey($template);

            $row = $template;
            foreach ($data as $k => $v) {
                if (array_key_exists($k, $row)) {
                    $row[$k] = $v;
                }
            }

            if ($pk && (!isset($row[$pk]) || $row[$pk] === null || $row[$pk] === '')) {
                $row[$pk] = self::nextId($db[$table], $pk);
            }

            $db[$table][] = $row;
            self::writeDb($fh, $db);
        } finally {
            self::unlockAndClose($fh);
        }

        self::afterInsertHook($table, $row);
        return $row;
    }

    private static function afterInsertHook(string $table, array $row): void {
        if ($table !== 'reservas') {
            return;
        }
        $notifierPath = __DIR__ . '/AdminPushNotifier.php';
        if (!is_file($notifierPath)) {
            return;
        }
        require_once $notifierPath;
        if (class_exists('AdminPushNotifier')) {
            try {
                AdminPushNotifier::notifyReservation($row);
            } catch (\Throwable $e) {
                // Silenciar errores de notificación para no afectar la escritura.
            }
        }
    }

    /**
     * Si la reserva local tiene ID_Appointment, refleja Status/fecha/hora en SQLite.
     *
     * @param array<string,mixed> $row
     */
    private static function afterReservaUpdateHook(array $row): void {
        $appointmentId = $row['ID_Appointment'] ?? null;
        if ($appointmentId === null || $appointmentId === '' || !is_numeric($appointmentId)) {
            return;
        }
        try {
            $projectRoot = dirname(__DIR__, 3);
            $bootstrap = $projectRoot . '/src/Core/bootstrap.php';
            if (!is_file($bootstrap)) {
                return;
            }
            require_once $bootstrap;
            if (!class_exists(\Agenduy\Core\TenantLocalDb::class)) {
                return;
            }
            $slug = basename(dirname(__DIR__, 2));
            if ($slug === '' || $slug === 'template') {
                // En template no hay comercio real; el tenant copia este archivo.
                // Igual intentamos con el slug del path (terap, etc.).
            }
            \Agenduy\Core\TenantLocalDb::pushReservaToCentral($slug, $row);
        } catch (\Throwable $e) {
            error_log('[AutoloadDB] push reserva→central: ' . $e->getMessage());
        }
    }

    public static function updateById(string $table, $id, array $data): ?array {
        // Read first without exclusive lock
        $db = self::readShared();
        $fh = self::openAndLock(true);
        try {
            self::assertTable($db, $table);
            $pk = self::primaryKey(self::getTemplate($db, $table));
            if (!$pk) return null;

              $updated = null;
              $previousRow = null;
              foreach ($db[$table] as $i => $row) {
                  if ($i === 0) continue; // skip template
                  if (isset($row[$pk]) && (string)$row[$pk] === (string)$id) {
                      $previousRow = $row;
                      foreach ($data as $k => $v) {
                          if ($k === $pk) continue; // don't allow PK change
                          if (array_key_exists($k, $row)) {
                              $db[$table][$i][$k] = $v;
                          }
                      }
                      $updated = $db[$table][$i];
                      break;
                  }
              }
              if ($updated !== null) {
                  if ($table === 'carrito' && $previousRow !== null) {
                      self::applyCartCompletionPoints($db, $previousRow, $updated);
                  }
                  self::writeDb($fh, $db);
                  if ($table === 'reservas') {
                      self::afterReservaUpdateHook($updated);
                  }
              }
              return $updated;
          } finally {
              self::unlockAndClose($fh);
        }
    }

    public static function deleteById(string $table, $id): bool {
        // Read first without exclusive lock
        $db = self::readShared();
        $fh = self::openAndLock(true);
        try {
            self::assertTable($db, $table);
            $pk = self::primaryKey(self::getTemplate($db, $table));
            if (!$pk) return false;

            $deleted = false;
            foreach ($db[$table] as $i => $row) {
                if ($i === 0) continue;
                if (isset($row[$pk]) && (string)$row[$pk] === (string)$id) {
                    unset($db[$table][$i]);
                    // Keep numeric keys compacted
                    $db[$table] = array_values($db[$table]);
                    $deleted = true;
                    break;
                }
            }
            if ($deleted) {
                self::writeDb($fh, $db);
            }
            return $deleted;
        } finally {
            self::unlockAndClose($fh);
        }
    }

    public static function getConfigSection(string $key): array {
        if ($key === '') {
            return [];
        }
        $db = self::readShared();
        $section = $db[$key] ?? [];
        return is_array($section) ? $section : [];
    }

    public static function updateConfigSection(string $key, array $data): array {
        if ($key === '') {
            throw new InvalidArgumentException('Clave de configuracion invalida.');
        }
        $db = self::readShared();
        $fh = self::openAndLock(true);
        try {
            $current = isset($db[$key]) && is_array($db[$key]) ? $db[$key] : [];
            $updated = self::mergeConfigRecursive($current, $data);
            $db[$key] = $updated;
            self::writeDb($fh, $db);
            return $updated;
        } finally {
            self::unlockAndClose($fh);
        }
    }

    // -------- Internal helpers --------
    private static function readShared(): array {
        $fh = self::openAndLock(false);
        try {
            return self::includeDb();
        } finally {
            self::unlockAndClose($fh);
        }
    }

    private static function readSharedWithPk(string $table): array {
        $fh = self::openAndLock(false);
        try {
            $db = self::includeDb();
            self::assertTable($db, $table);
            $pk = self::primaryKey(self::getTemplate($db, $table));
            return [$db, $pk];
        } finally {
            self::unlockAndClose($fh);
        }
    }

    private static function openAndLock(bool $exclusive) {
        $mode = file_exists(self::DB_PATH) ? ($exclusive ? 'r+' : 'r') : 'c+';
        $fh = @fopen(self::DB_PATH, $mode);
        if (!$fh) {
            throw new RuntimeException('No se puede abrir la base: ' . self::DB_PATH);
        }
        $locked = flock($fh, $exclusive ? LOCK_EX : LOCK_SH);
        if (!$locked) {
            fclose($fh);
            throw new RuntimeException('No se pudo adquirir el bloqueo del archivo.');
        }
        return $fh;
    }

    private static function unlockAndClose($fh): void {
        if (is_resource($fh)) {
            @flock($fh, LOCK_UN);
            @fclose($fh);
        }
    }

    private static function includeDb(): array {
        // Include returns the array structure from database.php
        $data = @include self::DB_PATH;
        if (!is_array($data)) {
            throw new RuntimeException('El archivo de base no retornó un array.');
        }
        return $data;
    }

    private static function writeDb($fh, array $db): void {
        // Generate compact PHP file with var_export
        $code = '<?php return ' . var_export($db, true) . ";\n";
        // Write via the same locked handle
        ftruncate($fh, 0);
        rewind($fh);
        $bytes = fwrite($fh, $code);
        if ($bytes === false) {
            throw new RuntimeException('Error escribiendo la base de datos.');
        }
        fflush($fh);
    }

    private static function assertTable(array $db, string $table): void {
        if (!array_key_exists($table, $db) || !is_array($db[$table])) {
            throw new InvalidArgumentException("Tabla no encontrada: {$table}");
        }
    }

    private static function getTemplate(array $db, string $table): array {
        if (!isset($db[$table][0]) || !is_array($db[$table][0])) {
            throw new RuntimeException("La tabla {$table} no tiene fila plantilla en índice 0.");
        }
        return $db[$table][0];
    }

      private static function primaryKey(array $template): ?string {
        foreach ($template as $k => $_) {
            if (strpos($k, 'ID_') === 0) {
                return $k;
            }
        }
        // fallback: first key
        foreach ($template as $k => $_) {
            return $k;
        }
        return null;
    }

    private static function nextId(array $tableRows, string $pk): int {
        $max = 0;
        foreach ($tableRows as $i => $row) {
            if ($i === 0 || !is_array($row)) continue;
            if (isset($row[$pk]) && is_numeric($row[$pk])) {
                $max = max($max, (int)$row[$pk]);
            }
        }
        return $max + 1;
    }

    private static function mergeConfigRecursive(array $current, array $updates): array {
        foreach ($updates as $key => $value) {
            if (is_array($value)) {
                $current[$key] = self::mergeConfigRecursive(
                    isset($current[$key]) && is_array($current[$key]) ? $current[$key] : [],
                    $value
                );
            } else {
                $current[$key] = $value;
            }
        }
        return $current;
    }

    private static function parseCartItems($raw): array {
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        $result = [];
        if (preg_match_all('/\(?\s*(\d+)\s*\+\s*(\d+)\s*\)?/', $raw, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $pid = (int)$match[1];
                $qty = (int)$match[2];
                if ($pid > 0 && $qty > 0) {
                    $result[] = ['product' => $pid, 'quantity' => $qty];
                }
            }
            return $result;
        }
        $parts = preg_split('/[,;]+/', $raw);
        foreach ($parts as $part) {
            if (preg_match('/(\d+)\s*\+\s*(\d+)/', $part, $match)) {
                $pid = (int)$match[1];
                $qty = (int)$match[2];
                if ($pid > 0 && $qty > 0) {
                    $result[] = ['product' => $pid, 'quantity' => $qty];
                }
            }
        }
        return $result;
    }

    private static function applyCartCompletionPoints(array &$db, array $previousRow, array $updatedRow): void {
        $prevStatus = strtolower(trim((string)($previousRow['Status'] ?? '')));
        $nextStatus = strtolower(trim((string)($updatedRow['Status'] ?? '')));
        if ($nextStatus !== 'finalizado' || $prevStatus === 'finalizado') {
            return;
        }

        $clientIdRaw = $updatedRow['ID_Cliente'] ?? $previousRow['ID_Cliente'] ?? null;
        if ($clientIdRaw === null || $clientIdRaw === '' || !is_numeric($clientIdRaw)) {
            return;
        }
        $clientId = (int)$clientIdRaw;
        if ($clientId <= 0) {
            return;
        }

        $itemsRaw = $updatedRow['ID_Producto + Cantidad'] ?? $previousRow['ID_Producto + Cantidad'] ?? '';
        $items = self::parseCartItems($itemsRaw);
        if (!$items) {
            return;
        }

        if (!isset($db['productos']) || !is_array($db['productos'])) {
            return;
        }

        $productPoints = [];
        foreach ($db['productos'] as $idx => $productRow) {
            if ($idx === 0 || !is_array($productRow)) {
                continue;
            }
            $pid = $productRow['ID_Product'] ?? $productRow['ID'] ?? null;
            if ($pid === null || $pid === '' || !is_numeric($pid)) {
                continue;
            }
            $points = $productRow['Puntos'] ?? $productRow['puntos'] ?? null;
            if ($points === null || $points === '' || !is_numeric($points)) {
                continue;
            }
            $productPoints[(int)$pid] = max(0, (int)$points);
        }
        if (!$productPoints) {
            return;
        }

        $award = 0;
        foreach ($items as $item) {
            $pid = (int)$item['product'];
            $qty = (int)$item['quantity'];
            if ($pid <= 0 || $qty <= 0) {
                continue;
            }
            $points = $productPoints[$pid] ?? 0;
            if ($points <= 0) {
                continue;
            }
            $award += $points * $qty;
        }
        if ($award <= 0) {
            return;
        }

        if (!isset($db['puntos']) || !is_array($db['puntos'])) {
            return;
        }

        $template = self::getTemplate($db, 'puntos');
        $pk = self::primaryKey($template) ?? 'ID_Puntos';

        $foundIndex = null;
        foreach ($db['puntos'] as $idx => $row) {
            if ($idx === 0 || !is_array($row)) {
                continue;
            }
            $rowClient = $row['ID_Client'] ?? $row['ID_cliente'] ?? $row['id_cliente'] ?? null;
            if ($rowClient !== null && (string)$rowClient === (string)$clientId) {
                $foundIndex = $idx;
                break;
            }
        }

        if ($foundIndex !== null) {
            $currentTotal = $db['puntos'][$foundIndex]['Total'] ?? 0;
            $currentTotal = is_numeric($currentTotal) ? (int)$currentTotal : 0;
            $db['puntos'][$foundIndex]['Total'] = $currentTotal + $award;
            if (array_key_exists('Estado', $db['puntos'][$foundIndex])) {
                $status = trim((string)($db['puntos'][$foundIndex]['Estado'] ?? ''));
                if ($status === '') {
                    $db['puntos'][$foundIndex]['Estado'] = 'Activo';
                }
            }
        } else {
            $nextId = self::nextId($db['puntos'], $pk);
            $newRow = $template;
            $newRow[$pk] = $nextId;
            if (array_key_exists('ID_Client', $newRow)) {
                $newRow['ID_Client'] = $clientId;
            }
            if (array_key_exists('Total', $newRow)) {
                $newRow['Total'] = $award;
            }
            if (array_key_exists('Estado', $newRow)) {
                $newRow['Estado'] = 'Activo';
            }
            $db['puntos'][] = $newRow;
        }
    }

    private static function autoCloseExpiredReservas(): void {
        try {
            $db = self::readShared();
        } catch (Throwable $e) {
            error_log('[AutoloadDB] No se pudo leer reservas para auto cierre: ' . $e->getMessage());
            return;
        }

        $updates = self::detectExpiredReservas($db);
        if (!$updates) {
            return;
        }

        $fh = null;
        try {
            $fh = self::openAndLock(true);
            $db = self::includeDb();
            $updates = self::detectExpiredReservas($db);
            if (!$updates) {
                return;
            }
            if (!isset($db['reservas']) || !is_array($db['reservas'])) {
                return;
            }

            foreach ($db['reservas'] as $index => $row) {
                if ($index === 0 || !is_array($row)) {
                    continue;
                }
                $id = $row['ID_Reserva'] ?? null;
                if ($id === null || $id === '') {
                    continue;
                }
                $key = (string)$id;
                if (isset($updates[$key])) {
                    $db['reservas'][$index]['Status'] = $updates[$key];
                }
            }

            self::writeDb($fh, $db);
        } catch (Throwable $e) {
            error_log('[AutoloadDB] No se pudo actualizar estados de reservas: ' . $e->getMessage());
        } finally {
            if ($fh) {
                self::unlockAndClose($fh);
            }
        }
    }

    private static function detectExpiredReservas(array $db): array {
        if (!isset($db['reservas']) || !is_array($db['reservas'])) {
            return [];
        }
        $timezone = self::resolveReservasTimezone($db);
        $now = new DateTimeImmutable('now', $timezone);
        $updates = [];

        foreach ($db['reservas'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = $row['ID_Reserva'] ?? null;
            if ($id === null || $id === '' || (string)$id === '0') {
                continue;
            }
            $status = trim((string)($row['Status'] ?? ''));
            if ($status === '' || strcasecmp($status, 'Pendiente') !== 0) {
                continue;
            }
            $fecha = trim((string)($row['Fecha_Reserva'] ?? ''));
            $hora = trim((string)($row['Hora_Reserva'] ?? ''));
            if ($fecha === '' || $hora === '') {
                continue;
            }

            $scheduled = self::parseReservaDateTime($fecha, $hora, $timezone);
            if (!$scheduled) {
                continue;
            }

            $deadline = $scheduled->modify('+1 hour');
            if ($deadline && $now >= $deadline) {
                $updates[(string)$id] = 'No Atendido';
            }
        }

        return $updates;
    }

    private static function resolveReservasTimezone(array $db): DateTimeZone {
        $default = 'UTC';
        $timezoneId = $default;
        if (isset($db['info_barberia']['horarios']['timezone'])) {
            $candidate = trim((string)$db['info_barberia']['horarios']['timezone']);
            if ($candidate !== '') {
                $timezoneId = $candidate;
            }
        }
        try {
            return new DateTimeZone($timezoneId);
        } catch (Throwable $e) {
            error_log('[AutoloadDB] Timezone invalido "' . $timezoneId . '": ' . $e->getMessage());
            return new DateTimeZone($default);
        }
    }

    private static function parseReservaDateTime(string $fecha, string $hora, DateTimeZone $tz): ?DateTimeImmutable {
        $fecha = trim($fecha);
        $hora = trim($hora);
        if ($fecha === '' || $hora === '') {
            return null;
        }

        $normalizedHora = str_replace('.', ':', $hora);
        if (preg_match('/^\d{2}:\d{2}$/', $normalizedHora)) {
            $normalizedHora .= ':00';
        }

        $candidates = [
            $fecha . ' ' . $normalizedHora,
            $fecha . 'T' . $normalizedHora,
        ];

        foreach ($candidates as $candidate) {
            try {
                $dt = new DateTimeImmutable($candidate, $tz);
                if ($dt !== false) {
                    return $dt;
                }
            } catch (Exception $e) {
                continue;
            }
        }

        return null;
    }

    private static function rowsWithoutTemplate(array $db, string $table): array {
        self::assertTable($db, $table);
        $rows = $db[$table];
        if (isset($rows[0])) unset($rows[0]);
        return array_values($rows);
    }

    /**
     * Enforce membership max_clients on create. null = allowed.
     *
     * @return array<string, mixed>|null denial payload when blocked
     */
    public static function checkClientPlanLimit(): ?array
    {
        $tenantSlug = basename(dirname(__DIR__, 2));
        if ($tenantSlug === '' || $tenantSlug === 'template') {
            return null;
        }
        $projectRoot = dirname(__DIR__, 3);
        $bootstrap = $projectRoot . '/src/Core/bootstrap.php';
        if (!is_file($bootstrap)) {
            return null;
        }
        require_once $bootstrap;
        if (!class_exists(\Agenduy\Core\MembershipPlan::class)) {
            return null;
        }
        try {
            $plan = \Agenduy\Core\MembershipPlan::forCommerceSlug($tenantSlug);
            if (!is_array($plan)) {
                return null;
            }
            $maxClients = \Agenduy\Core\MembershipPlan::maxClients($plan);
            if ($maxClients === null) {
                return null;
            }
            $currentCount = count(self::all('clientes'));
            if ($currentCount < $maxClients) {
                return null;
            }
            return \Agenduy\Core\MembershipPlan::denialPayload('PLAN_LIMIT_MAX_CLIENTS', [
                'max_clients' => $maxClients,
                'current' => $currentCount,
            ]);
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Block Atender/Finalizar when monthly paid appointment quota is exhausted.
     * Cancelar / Reprogramar / Ver stay allowed.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null denial payload when blocked
     */
    public static function checkAppointmentProcessPlanLimit($id, array $data): ?array
    {
        if (!isset($data['Status'])) {
            return null;
        }
        $newStatus = (string)$data['Status'];
        $tenantSlug = basename(dirname(__DIR__, 2));
        if ($tenantSlug === '' || $tenantSlug === 'template') {
            return null;
        }
        $projectRoot = dirname(__DIR__, 3);
        $bootstrap = $projectRoot . '/src/Core/bootstrap.php';
        if (!is_file($bootstrap)) {
            return null;
        }
        require_once $bootstrap;
        if (!class_exists(\Agenduy\Core\MembershipPlan::class)) {
            return null;
        }
        if (!\Agenduy\Core\MembershipPlan::isConsumingAppointmentStatus($newStatus)) {
            return null;
        }
        try {
            $plan = \Agenduy\Core\MembershipPlan::forCommerceSlug($tenantSlug);
            $existing = self::find('reservas', $id);
            $reservas = self::all('reservas');
            if (!\Agenduy\Core\MembershipPlan::wouldExceedAppointmentsMonthOnProcess(
                is_array($plan) ? $plan : null,
                $reservas,
                is_array($existing) ? $existing : null,
                $newStatus
            )) {
                return null;
            }
            $maxAppts = is_array($plan)
                ? \Agenduy\Core\MembershipPlan::maxAppointmentsMonth($plan)
                : null;
            $fecha = trim((string)($existing['Fecha_Reserva'] ?? ''));
            $monthPrefix = (preg_match('/^\d{4}-\d{2}/', $fecha) === 1) ? substr($fecha, 0, 7) : date('Y-m');
            return \Agenduy\Core\MembershipPlan::appointmentsMonthProcessDeniedPayload([
                'max_appointments_month' => $maxAppts,
                'current' => \Agenduy\Core\MembershipPlan::countLocalReservasConsumedThisMonth($reservas, $monthPrefix),
            ]);
        } catch (Throwable $e) {
            return null;
        }
    }
}

// Basic HTTP layer to use as a simple API
if (php_sapi_name() !== 'cli' && realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    header('Content-Type: application/json; charset=utf-8');

    // Merge JSON body into request if provided
    $raw = file_get_contents('php://input');
    if ($raw) {
        $json = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
            $_REQUEST = array_merge($_REQUEST, $json);
        }
    }

    $action = $_REQUEST['action'] ?? 'list';
    $table = $_REQUEST['table'] ?? null;

    try {
        if (!$table) {
            throw new InvalidArgumentException('Falta parámetro table');
        }
        switch ($action) {
            case 'list':
                $data = AutoloadDB::all($table);
                echo json_encode(['ok' => true, 'data' => $data]);
                break;
            case 'get':
                $id = $_REQUEST['id'] ?? null;
                if ($id === null) throw new InvalidArgumentException('Falta parámetro id');
                $row = AutoloadDB::find($table, $id);
                echo json_encode(['ok' => true, 'data' => $row]);
                break;
            case 'insert':
                $data = $_REQUEST['data'] ?? [];
                if (is_string($data)) {
                    $tmp = json_decode($data, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($tmp)) {
                        $data = $tmp;
                    } else {
                        $data = [];
                    }
                }
                if ($table === 'clientes') {
                    $limitDenied = AutoloadDB::checkClientPlanLimit();
                    if ($limitDenied !== null) {
                        http_response_code(403);
                        echo json_encode($limitDenied);
                        break;
                    }
                }
                $row = AutoloadDB::insert($table, is_array($data) ? $data : []);
                echo json_encode(['ok' => true, 'data' => $row]);
                break;
            case 'update':
                $id = $_REQUEST['id'] ?? null;
                if ($id === null) throw new InvalidArgumentException('Falta parámetro id');
                $data = $_REQUEST['data'] ?? [];
                if (is_string($data)) {
                    $tmp = json_decode($data, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($tmp)) {
                        $data = $tmp;
                    } else {
                        $data = [];
                    }
                }
                if ($table === 'reservas' && is_array($data)) {
                    $processDenied = AutoloadDB::checkAppointmentProcessPlanLimit($id, $data);
                    if ($processDenied !== null) {
                        http_response_code(403);
                        echo json_encode($processDenied);
                        break;
                    }
                }
                $row = AutoloadDB::updateById($table, $id, is_array($data) ? $data : []);
                echo json_encode(['ok' => $row !== null, 'data' => $row]);
                break;
            case 'delete':
                $id = $_REQUEST['id'] ?? null;
                if ($id === null) throw new InvalidArgumentException('Falta parámetro id');
                $ok = AutoloadDB::deleteById($table, $id);
                echo json_encode(['ok' => $ok]);
                break;
            default:
                throw new InvalidArgumentException('Acción no soportada: ' . $action);
        }
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
}


