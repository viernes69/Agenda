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
    ob_end_flush();
    @ob_flush();
    flush();
}

require __DIR__ . '/../src/Core/bootstrap.php';
use Agenduy\Core\NotificationOutbox;

try {
    // Process up to 15 notifications in the background
    NotificationOutbox::processDue(15);
} catch (\Throwable $e) {
    error_log('[async_cron] error: ' . $e->getMessage());
}
