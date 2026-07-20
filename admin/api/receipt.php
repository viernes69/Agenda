<?php
/**
 * Agenduy - Super Admin: servir comprobante de transferencia
 *
 * GET /admin/api/receipt.php?id={id_transfer}
 *
 * Auth-gated: solo super_admin. No expone storage/ al público.
 */
declare(strict_types=1);

$config = require __DIR__ . '/../../src/Core/bootstrap.php';

use Agenduy\Core\Auth;
use Agenduy\Core\Database;

Auth::start();
if (!Auth::check() || Auth::role() !== 'super_admin') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Forbidden';
    exit;
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Invalid id';
    exit;
}

$db = Database::getInstance();
$row = $db->fetchOne(
    'SELECT comprobante_path FROM payment_transfers WHERE id_transfer = :id',
    [':id' => $id]
);
if (!$row || empty($row['comprobante_path'])) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found';
    exit;
}

$cfg = $db->config();
$baseDir = realpath((string)$cfg['uploads']['base_dir']);
$receiptsRoot = $baseDir !== false
    ? realpath($baseDir . DIRECTORY_SEPARATOR . trim((string)$cfg['uploads']['receipts_dir'], "/\\"))
    : false;

if ($baseDir === false || $receiptsRoot === false) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Storage unavailable';
    exit;
}

$rel = str_replace('\\', '/', (string)$row['comprobante_path']);
$prefix = 'storage/uploads/';
if (str_starts_with($rel, $prefix)) {
    $rel = substr($rel, strlen($prefix));
}
$rel = ltrim($rel, '/');
if ($rel === '' || str_contains($rel, '..')) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found';
    exit;
}

$candidate = $baseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
$real = realpath($candidate);

$norm = static function (string $path): string {
    return strtolower(str_replace('\\', '/', $path));
};

if (
    $real === false
    || !is_file($real)
    || !str_starts_with($norm($real), $norm($receiptsRoot) . '/')
) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found';
    exit;
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = (string)($finfo->file($real) ?: '');
$allowed = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
if (!in_array($mime, $allowed, true)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Forbidden';
    exit;
}

$size = filesize($real);
header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline; filename="' . basename($real) . '"');
header('Cache-Control: private, no-store');
if ($size !== false) {
    header('Content-Length: ' . (string)$size);
}
readfile($real);
exit;
