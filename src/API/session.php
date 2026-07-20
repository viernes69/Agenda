<?php
// Proxy shim to support requests that target /agenda/src/API/... instead of /agenda/template/src/API
// This avoids 404s when some client scripts use different base paths or when browser cache contains old references.

$target = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'template' . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'API' . DIRECTORY_SEPARATOR . 'session.php';
if (file_exists($target)) {
    require_once $target;
    exit;
}
http_response_code(404);
echo 'Not Found';
