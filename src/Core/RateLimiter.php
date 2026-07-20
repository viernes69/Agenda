<?php
declare(strict_types=1);

namespace Agenduy\Core;

/**
 * Rate limiting simple por bucket (SQLite).
 */
final class RateLimiter
{
    /**
     * @throws RuntimeException cuando se supera el límite (ya respondió 429)
     */
    public static function enforce(string $action, string $identifier, int $windowSeconds, int $maxAttempts): void
    {
        if (self::attempt($action, $identifier, $windowSeconds, $maxAttempts)) {
            return;
        }

        if (!headers_sent()) {
            http_response_code(429);
            header('Content-Type: application/json; charset=utf-8');
            header('Retry-After: ' . max(1, $windowSeconds));
        }
        echo json_encode([
            'ok' => false,
            'error' => 'Demasiados intentos. Esperá un momento e intentá de nuevo.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function attempt(string $action, string $identifier, int $windowSeconds, int $maxAttempts): bool
    {
        $action = strtolower(trim($action));
        $identifier = trim($identifier);
        if ($action === '' || $identifier === '') {
            return true;
        }

        $windowSeconds = max(1, $windowSeconds);
        $maxAttempts = max(1, $maxAttempts);
        $bucket = $action . ':' . hash('sha256', $identifier);
        $now = time();
        $db = Database::getInstance();

        return (bool)$db->transaction(static function (Database $db) use ($bucket, $now, $windowSeconds, $maxAttempts): bool {
            $row = $db->fetchOne(
                'SELECT hits, window_start FROM rate_limits WHERE bucket = :b LIMIT 1',
                [':b' => $bucket]
            );

            if (!$row) {
                $db->insert('rate_limits', [
                    'bucket'        => $bucket,
                    'hits'          => 1,
                    'window_start'  => $now,
                ]);
                return true;
            }

            $windowStart = (int)($row['window_start'] ?? 0);
            $hits = (int)($row['hits'] ?? 0);
            if ($windowStart <= 0 || ($now - $windowStart) >= $windowSeconds) {
                $db->update('rate_limits', [
                    'hits'         => 1,
                    'window_start' => $now,
                ], 'bucket = :b', [':b' => $bucket]);
                return true;
            }

            if ($hits >= $maxAttempts) {
                return false;
            }

            $db->update('rate_limits', [
                'hits' => $hits + 1,
            ], 'bucket = :b', [':b' => $bucket]);
            return true;
        });
    }
}
