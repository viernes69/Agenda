<?php
date_default_timezone_set('America/Montevideo');
session_start();
require_once __DIR__ . '/Autoload.php';

header('Content-Type: application/json; charset=utf-8');

function respond($data, int $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

if (!isset($_SESSION['cliente']) || empty($_SESSION['cliente']['cliente_id'])) {
    respond(['ok' => false, 'error' => 'No autorizado'], 401);
}

$clienteId = $_SESSION['cliente']['cliente_id'];
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
// Support JSON payloads alongside form-data without breaking file uploads
if ($method === 'POST' && isset($_SERVER['CONTENT_TYPE']) && stripos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) {
    $raw = file_get_contents('php://input');
    $json = json_decode($raw, true);
    if (is_array($json)) {
        foreach ($json as $key => $value) {
            $_POST[$key] = $value;
            $_REQUEST[$key] = $value;
        }
    }
}
$action = strtolower((string)($_POST['action'] ?? $_REQUEST['action'] ?? 'upload_photo'));

switch ($action) {
    case 'upload_photo':
        if ($method !== 'POST') {
            respond(['ok' => false, 'error' => 'Metodo no soportado'], 405);
        }
        if (!isset($_FILES['photo']) || !is_uploaded_file($_FILES['photo']['tmp_name'] ?? '')) {
            respond(['ok' => false, 'error' => 'Archivo no recibido'], 400);
        }
        $file = $_FILES['photo'];
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            respond(['ok' => false, 'error' => 'Error al subir el archivo'], 400);
        }
        if (($file['size'] ?? 0) > 5 * 1024 * 1024) { // 5MB
            respond(['ok' => false, 'error' => 'El archivo es demasiado grande (max 5MB)'], 400);
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);
        $allowed = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
        ];
        if (!isset($allowed[$mime])) {
            respond(['ok' => false, 'error' => 'Formato no soportado'], 400);
        }
        $ext = $allowed[$mime];
        $destDir = realpath(__DIR__ . '/../img/users');
        if ($destDir === false) {
            $destDir = __DIR__ . '/../img/users';
            @mkdir($destDir, 0775, true);
        }
        $fileName = 'User_' . preg_replace('/[^0-9]/', '', (string)$clienteId) . '_' . date('Ymd_His') . '.' . $ext;
        $destPath = rtrim($destDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $fileName;
        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            respond(['ok' => false, 'error' => 'No se pudo guardar el archivo'], 500);
        }
        // Build relative path to store in DB
        $relPath = 'src/img/users/' . $fileName;
        try {
            $updated = AutoloadDB::updateById('clientes', $clienteId, ['Perfil' => $relPath]);
            if (!$updated) {
                throw new RuntimeException('No se pudo actualizar el perfil');
            }
            // Update session snapshot
            $_SESSION['cliente']['perfil'] = $relPath;
        } catch (Throwable $e) {
            respond(['ok' => false, 'error' => $e->getMessage()], 500);
        }
        respond(['ok' => true, 'path' => $relPath]);
        break;

    case 'update_profile':
        if ($method !== 'POST') {
            respond(['ok' => false, 'error' => 'Metodo no soportado'], 405);
        }
        $nombre = trim((string)($_POST['nombre'] ?? $_POST['Nombre'] ?? ''));
        $cedula = trim((string)($_POST['cedula'] ?? $_POST['Cedula'] ?? ''));
        $telefono = trim((string)($_POST['telefono'] ?? $_POST['Telefono'] ?? ''));
        $email = trim((string)($_POST['email'] ?? $_POST['Email'] ?? ''));

        if ($nombre === '' || $cedula === '' || $telefono === '' || $email === '') {
            respond(['ok' => false, 'error' => 'Todos los campos son obligatorios'], 422);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            respond(['ok' => false, 'error' => 'Email invalido'], 422);
        }

        try {
            $updated = AutoloadDB::updateById('clientes', $clienteId, [
                'Nombre' => $nombre,
                'Cedula' => $cedula,
                'Telefono' => $telefono,
                'Email' => $email,
            ]);
            if (!$updated) {
                throw new RuntimeException('No se pudo guardar los cambios');
            }
        } catch (Throwable $e) {
            respond(['ok' => false, 'error' => $e->getMessage()], 500);
        }

        $displayName = $nombre !== '' ? $nombre : ($email !== '' ? $email : $cedula);
        if (!is_array($_SESSION['cliente'])) {
            $_SESSION['cliente'] = [];
        }
        $_SESSION['cliente']['nombre'] = $nombre;
        $_SESSION['cliente']['cedula'] = $cedula;
        $_SESSION['cliente']['telefono'] = $telefono;
        $_SESSION['cliente']['email'] = $email;
        $_SESSION['cliente']['display_name'] = $displayName;
        $_SESSION['cliente']['Nombre'] = $nombre;
        $_SESSION['cliente']['Cedula'] = $cedula;
        $_SESSION['cliente']['Telefono'] = $telefono;
        $_SESSION['cliente']['Email'] = $email;
        $_SESSION['cliente']['cliente_id'] = $clienteId;

        respond([
            'ok' => true,
            'message' => 'Perfil actualizado',
            'data' => [
                'nombre' => $nombre,
                'cedula' => $cedula,
                'telefono' => $telefono,
                'email' => $email,
                'display_name' => $displayName,
            ],
        ]);
        break;

    case 'update_profile_field':
        if ($method !== 'POST') {
            respond(['ok' => false, 'error' => 'Metodo no soportado'], 405);
        }
        $field = strtolower(trim((string)($_POST['field'] ?? '')));
        $value = trim((string)($_POST['value'] ?? ''));

        $allowed = [
            'nombre' => ['column' => 'Nombre', 'label' => 'Nombre'],
            'cedula' => ['column' => 'Cedula', 'label' => 'Cedula'],
            'telefono' => ['column' => 'Telefono', 'label' => 'Telefono'],
            'email' => ['column' => 'Email', 'label' => 'Email'],
        ];
        if (!isset($allowed[$field])) {
            respond(['ok' => false, 'error' => 'Campo no soportado'], 400);
        }
        if ($value === '') {
            respond(['ok' => false, 'error' => 'El valor no puede estar vacio'], 422);
        }
        if ($field === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            respond(['ok' => false, 'error' => 'Email invalido'], 422);
        }

        $column = $allowed[$field]['column'];
        try {
            $updated = AutoloadDB::updateById('clientes', $clienteId, [$column => $value]);
            if (!$updated) {
                throw new RuntimeException('No se pudo guardar el cambio');
            }
        } catch (Throwable $e) {
            respond(['ok' => false, 'error' => $e->getMessage()], 500);
        }

        if (!is_array($_SESSION['cliente'])) {
            $_SESSION['cliente'] = [];
        }
        $_SESSION['cliente'][$field] = $value;
        $upperKey = ucfirst($field);
        $_SESSION['cliente'][$upperKey] = $value;

        $nombreActual = trim((string)($_SESSION['cliente']['nombre'] ?? $_SESSION['cliente']['Nombre'] ?? ''));
        $emailActual = trim((string)($_SESSION['cliente']['email'] ?? $_SESSION['cliente']['Email'] ?? ''));
        $cedulaActual = trim((string)($_SESSION['cliente']['cedula'] ?? $_SESSION['cliente']['Cedula'] ?? ''));

        if ($field === 'nombre') {
            $displayName = $value !== '' ? $value : ($emailActual !== '' ? $emailActual : ($cedulaActual !== '' ? $cedulaActual : 'Cliente'));
            $_SESSION['cliente']['display_name'] = $displayName;
        } elseif ($field === 'email' && ($nombreActual === '')) {
            $_SESSION['cliente']['display_name'] = $value;
        } elseif ($field === 'cedula' && $nombreActual === '' && $emailActual === '') {
            $_SESSION['cliente']['display_name'] = $value;
        }

        respond([
            'ok' => true,
            'message' => $allowed[$field]['label'] . ' actualizado',
            'data' => [
                'field' => $field,
                'value' => $value,
            ],
        ]);
        break;

    default:
        respond(['ok' => false, 'error' => 'Accion no soportada'], 400);
        break;
}
