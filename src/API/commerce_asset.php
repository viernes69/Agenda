<?php
declare(strict_types=1);

use Agenduy\Core\CommerceStorage;

require_once dirname(__DIR__) . '/Core/bootstrap.php';

$storedPath = ltrim(str_replace('\\', '/', trim((string)($_GET['p'] ?? ''))), '/');
if (!preg_match('#^commerce-assets/([1-9][0-9]*)/(logo|services|products|barbers)/([^/]+)$#', $storedPath, $matches)) {
    http_response_code(404);
    exit;
}

$filename = (string)$matches[3];
$extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
$contentTypes = [
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'gif' => 'image/gif',
    'webp' => 'image/webp',
    'avif' => 'image/avif',
];
if (!isset($contentTypes[$extension])) {
    http_response_code(404);
    exit;
}

$commerceId = (int)$matches[1];
$absolute = CommerceStorage::absolutePath($commerceId, '', $storedPath);
if ($absolute === null || !is_file($absolute)) {
    http_response_code(404);
    exit;
}

$mtime = (int)@filemtime($absolute);
$etag = '"' . sha1($storedPath . '|' . $mtime . '|' . (string)@filesize($absolute)) . '"';
$ifNoneMatch = trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
if ($ifNoneMatch !== '' && hash_equals($etag, $ifNoneMatch)) {
    header('ETag: ' . $etag);
    header('Cache-Control: public, max-age=604800');
    http_response_code(304);
    exit;
}

header('Content-Type: ' . $contentTypes[$extension]);
header('Content-Length: ' . (string)filesize($absolute));
header('Cache-Control: public, max-age=604800');
header('ETag: ' . $etag);
header('X-Content-Type-Options: nosniff');
if ($mtime > 0) {
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
    exit;
}

$handle = fopen($absolute, 'rb');
if ($handle === false) {
    http_response_code(404);
    exit;
}
fpassthru($handle);
fclose($handle);
