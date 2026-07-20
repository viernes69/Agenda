<?php
use PHPMailer\PHPMailer\PHPMailer;

if (!function_exists('agenduy_mail_get_config')) {
    function agenduy_mail_get_config(): array {
        static $config = null;
        if ($config !== null) {
            return $config;
        }
        $configPath = __DIR__ . '/../config/mail.php';
        $configData = is_file($configPath) ? include $configPath : [];
        $config = is_array($configData) ? $configData : [];
        return $config;
    }
}

if (!function_exists('agenduy_mail_resolve_autoload_path')) {
    function agenduy_mail_resolve_autoload_path(): ?string {
        $candidates = [
            dirname(__DIR__, 3) . '/vendor/autoload.php',
            dirname(__DIR__, 2) . '/vendor/autoload.php',
        ];
        foreach ($candidates as $candidate) {
            if ($candidate && is_file($candidate)) {
                return $candidate;
            }
        }
        return null;
    }
}

if (!function_exists('agenduy_mail_require_phpmailer')) {
    function agenduy_mail_require_phpmailer(): bool {
        static $available = null;
        if ($available !== null) {
            return $available;
        }
        $autoload = agenduy_mail_resolve_autoload_path();
        if ($autoload) {
            require_once $autoload;
        }
        $available = class_exists(PHPMailer::class);
        if (!$available) {
            agenduy_mail_log('PHPMailer no está disponible. Verifica la instalación de Composer en el servidor.');
        }
        return $available;
    }
}

if (!function_exists('agenduy_mail_log_path')) {
    function agenduy_mail_log_path(): string {
        static $path = null;
        if ($path !== null) {
            return $path;
        }
        $root = dirname(__DIR__, 2);
        $dir = $root . DIRECTORY_SEPARATOR . 'logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $path = $dir . DIRECTORY_SEPARATOR . 'mail.log';
        if (!is_file($path)) {
            @touch($path);
        }
        return $path;
    }
}

if (!function_exists('agenduy_mail_log')) {
    function agenduy_mail_log(string $message): void {
        $timestamp = date('Y-m-d H:i:s');
        $line = sprintf("[%s] %s%s", $timestamp, $message, PHP_EOL);
        @file_put_contents(agenduy_mail_log_path(), $line, FILE_APPEND);
    }
}

if (!function_exists('agenduy_mail_debug_log')) {
    function agenduy_mail_debug_log(string $message): void {
        $root = dirname(__DIR__, 2);
        $dir = $root . DIRECTORY_SEPARATOR . 'logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $path = $dir . DIRECTORY_SEPARATOR . 'mail.debug.log';
        $timestamp = date('Y-m-d H:i:s');
        $line = sprintf("[%s] %s%s", $timestamp, $message, PHP_EOL);
        @file_put_contents($path, $line, FILE_APPEND);
    }
}
