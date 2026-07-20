<?php
declare(strict_types=1);

final class AdminPushStorage
{
    private const STORAGE_FILE = __DIR__ . '/../db/push_subscribers.json';

    public static function all(): array
    {
        return array_values(self::readRaw());
    }

    public static function save(array $payload): array
    {
        $endpoint = trim((string)($payload['endpoint'] ?? ''));
        if ($endpoint === '') {
            throw new InvalidArgumentException('Endpoint invalido.');
        }
        $id = hash('sha256', $endpoint);
        $records = self::readRaw();
        $records[$id] = [
            'id' => $id,
            'endpoint' => $endpoint,
            'keys' => isset($payload['keys']) && is_array($payload['keys']) ? $payload['keys'] : [],
            'encoding' => isset($payload['encoding']) ? (string)$payload['encoding'] : null,
            'user_agent' => isset($payload['user_agent']) ? (string)$payload['user_agent'] : '',
            'created_at' => isset($records[$id]) ? $records[$id]['created_at'] : date(DATE_ATOM),
            'updated_at' => date(DATE_ATOM),
        ];
        self::writeRaw($records);
        return $records[$id];
    }

    public static function removeByEndpoint(string $endpoint): bool
    {
        $endpoint = trim($endpoint);
        if ($endpoint === '') {
            return false;
        }
        $id = hash('sha256', $endpoint);
        return self::removeById($id);
    }

    public static function removeById(string $id): bool
    {
        $records = self::readRaw();
        if (!isset($records[$id])) {
            return false;
        }
        unset($records[$id]);
        self::writeRaw($records);
        return true;
    }

    private static function readRaw(): array
    {
        $path = self::STORAGE_FILE;
        if (!is_file($path)) {
            return [];
        }
        $contents = @file_get_contents($path);
        if ($contents === false) {
            return [];
        }
        $data = json_decode($contents, true);
        if (!is_array($data)) {
            return [];
        }
        $assoc = [];
        foreach ($data as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = isset($row['id']) ? (string)$row['id'] : null;
            if (!$id) {
                $id = hash('sha256', (string)($row['endpoint'] ?? uniqid('', true)));
            }
            $row['id'] = $id;
            $assoc[$id] = $row;
        }
        return $assoc;
    }

    private static function writeRaw(array $records): void
    {
        $path = self::STORAGE_FILE;
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $tmpPath = $path . '.tmp';
        $json = json_encode(array_values($records), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('No se pudo serializar las suscripciones.');
        }
        if (@file_put_contents($tmpPath, $json, LOCK_EX) === false) {
            throw new RuntimeException('No se pudo escribir el archivo temporal de suscripciones.');
        }
        @rename($tmpPath, $path);
    }
}
