<?php
declare(strict_types=1);

// Prevent blocking the client by ignoring user abort and removing time limit
ignore_user_abort(true);
set_time_limit(0);

// Close the session and send response immediately so the client HTTP request finishes
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
if (function_exists('fastcgi_finish_request')) {
    @fastcgi_finish_request();
} elseif (!headers_sent()) {
    header("Connection: close");
    header("Content-Length: 0");
    if (ob_get_level() > 0) {
        @ob_end_flush();
    }
    @ob_flush();
    flush();
}

require __DIR__ . '/../../src/Core/bootstrap.php';
use Agenduy\Core\NotificationOutbox;

try {
    $limit = max(1, min(100, (int)($_REQUEST['limit'] ?? 15)));
    $idsRaw = trim((string)($_REQUEST['ids'] ?? ''));
    if ($idsRaw !== '') {
        $ids = array_map('intval', preg_split('/[,\s]+/', $idsRaw, -1, PREG_SPLIT_NO_EMPTY) ?: []);
        NotificationOutbox::processIds($ids, $limit);
    } else {
        NotificationOutbox::processDue($limit);
    }
} catch (\Throwable $e) {
    error_log('[async_cron] error: ' . $e->getMessage());
}
