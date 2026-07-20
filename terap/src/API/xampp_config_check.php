<?php
header('Content-Type: text/plain');

// Verificar configuración del sistema
echo "=== Configuración del Sistema ===\n";
echo "PHP Version: " . phpversion() . "\n";
echo "OS: " . PHP_OS . "\n";
echo "Server Software: " . $_SERVER['SERVER_SOFTWARE'] . "\n\n";

// Verificar permisos de directorios
echo "=== Permisos de Directorios ===\n";
$directories = [
    'Session Directory' => session_save_path() ?: sys_get_temp_dir(),
    'Document Root' => $_SERVER['DOCUMENT_ROOT'],
    'Current Script Directory' => dirname(__FILE__),
];

foreach ($directories as $name => $path) {
    echo "$name:\n";
    echo "  Path: $path\n";
    echo "  Exists: " . (is_dir($path) ? 'Yes' : 'No') . "\n";
    if (is_dir($path)) {
        echo "  Writable: " . (is_writable($path) ? 'Yes' : 'No') . "\n";
        echo "  Permissions: " . substr(sprintf('%o', fileperms($path)), -4) . "\n";
        echo "  Owner: " . fileowner($path) . "\n";
        echo "  Group: " . filegroup($path) . "\n";
    }
    echo "\n";
}

// Verificar configuración de PHP
echo "=== Configuración PHP ===\n";
$phpSettings = [
    // Configuración de sesiones
    'session.save_handler',
    'session.save_path',
    'session.use_cookies',
    'session.use_only_cookies',
    'session.name',
    'session.auto_start',
    'session.cookie_lifetime',
    'session.cookie_path',
    'session.cookie_domain',
    'session.cookie_secure',
    'session.cookie_httponly',
    'session.use_strict_mode',
    'session.gc_maxlifetime',
    'session.gc_probability',
    'session.gc_divisor',
    
    // Configuración general
    'display_errors',
    'error_reporting',
    'upload_max_filesize',
    'post_max_size',
    'max_execution_time',
    'max_input_time',
    'memory_limit',
    'date.timezone'
];

foreach ($phpSettings as $setting) {
    echo sprintf("%-30s: %s\n", $setting, ini_get($setting));
}

// Verificar estado actual de la sesión
echo "\n=== Estado Actual de la Sesión ===\n";
if (session_status() === PHP_SESSION_ACTIVE) {
    echo "ID de Sesión: " . session_id() . "\n";
    echo "Nombre de Sesión: " . session_name() . "\n";
    if (isset($_SESSION)) {
        echo "Variables de Sesión:\n";
        print_r($_SESSION);
    }
} else {
    echo "No hay sesión activa\n";
}

// Verificar cookies existentes
echo "\n=== Cookies Existentes ===\n";
if (!empty($_COOKIE)) {
    foreach ($_COOKIE as $name => $value) {
        echo "$name: $value\n";
    }
} else {
    echo "No hay cookies\n";
}

// Verificar si hay problemas con el directorio de sesiones
echo "\n=== Diagnóstico del Directorio de Sesiones ===\n";
$sessionPath = session_save_path() ?: sys_get_temp_dir();
if (is_dir($sessionPath)) {
    $diskFree = disk_free_space($sessionPath);
    $diskTotal = disk_total_space($sessionPath);
    
    echo "Espacio libre en disco: " . round($diskFree / 1024 / 1024) . " MB\n";
    echo "Espacio total en disco: " . round($diskTotal / 1024 / 1024) . " MB\n";
    echo "Porcentaje libre: " . round(($diskFree / $diskTotal) * 100, 2) . "%\n";
    
    $sessionFiles = glob($sessionPath . "/sess_*");
    echo "Archivos de sesión encontrados: " . count($sessionFiles) . "\n";
    
    if (!empty($sessionFiles)) {
        echo "Últimos 5 archivos de sesión:\n";
        $recent = array_slice($sessionFiles, -5);
        foreach ($recent as $file) {
            echo basename($file) . " - " . 
                 date("Y-m-d H:i:s", filemtime($file)) . " - " . 
                 filesize($file) . " bytes\n";
        }
    }
} else {
    echo "ERROR: No se puede acceder al directorio de sesiones\n";
}

?>