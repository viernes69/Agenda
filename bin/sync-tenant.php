<?php
declare(strict_types=1);

/**
 * Copia archivos corregidos de template/ a un tenant activo.
 * Uso: php bin/sync-tenant.php terapeuta-luck
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$projectRoot = dirname(__DIR__);
$tenant = $argv[1] ?? 'terapeuta-luck';
if (!preg_match('/^[a-z0-9][a-z0-9-]*$/', $tenant)) {
    fwrite(STDERR, "Slug de tenant inválido.\n");
    exit(1);
}

$files = [
    'index.php',
    'src/API/AdminConfig.php',
    'src/API/reservas.php',
    'src/js/admin/barber-session.js',
    'private/session_guard.php',
    'private/dashboard/admin/index.php',
    'private/dashboard/empleado/index.php',
    'private/dashboard/src/php/plan_banner_from_sqlite.php',
    'private/dashboard/src/components/admin_business_info_modal.php',
    'private/dashboard/src/components/admin_config_legales_modal.php',
    'private/dashboard/src/components/admin_config_seo_modal.php',
    'private/dashboard/src/components/admin_product_form_modal.php',
    'private/dashboard/src/components/admin_plan_membership_modal.php',
    'private/dashboard/src/api/reservas_admin.php',
    'private/dashboard/src/api/productos.php',
    'private/dashboard/src/api/barberos.php',
    'private/dashboard/src/api/servicios.php',
    'private/dashboard/src/js/admin/bottom-nav.js',
    'private/dashboard/src/js/admin/admin-auth-guard.js',
    'private/dashboard/src/js/admin/config-cards.js',
    'private/dashboard/src/js/admin/admin-config-legales.js',
    'private/dashboard/src/js/admin/productos-crud.js',
    'private/dashboard/src/js/admin/servicios-crud.js',
    'private/dashboard/src/js/admin/clientes-list.js',
    'private/dashboard/src/js/admin/admin-orders.js',
    'private/dashboard/src/js/admin/plan-membership-modal.js',
    'private/dashboard/src/js/admin/reservas-filter.js',
    'private/dashboard/src/js/admin/reservas-summary-modal.js',
    'private/dashboard/src/components/admin_reservas_summary_modal.php',
    'private/dashboard/src/admin.css',
    'private/dashboard/src/reservas-ledger.css',
    'src/API/Autoload.php',
    'private/dashboard/src/components/admin_reserva_modal.php',
    'private/dashboard/src/js/admin/reserva-modal.js',
    'private/dashboard/src/components/admin_service_modal.php',
    'private/dashboard/src/js/admin/service-modal.js',
];

$changed = 0;
foreach ($files as $relativePath) {
    $source = $projectRoot . '/template/' . $relativePath;
    $destination = $projectRoot . '/' . $tenant . '/' . $relativePath;
    if (!is_file($source)) {
        fwrite(STDERR, "Fuente faltante: {$relativePath}\n");
        exit(1);
    }
    $destDir = dirname($destination);
    if (!is_dir($destDir) && !mkdir($destDir, 0755, true) && !is_dir($destDir)) {
        fwrite(STDERR, "No se pudo crear directorio: {$destDir}\n");
        exit(1);
    }
    if (is_file($destination) && hash_file('sha256', $source) === hash_file('sha256', $destination)) {
        echo "sin cambios: {$relativePath}\n";
        continue;
    }
    if (!copy($source, $destination)) {
        fwrite(STDERR, "No se pudo copiar: {$relativePath}\n");
        exit(1);
    }
    $changed++;
    echo "actualizado: {$relativePath}\n";
}

// CSRF + tenant config snippets are already in tenant admin index from prior work;
// keep admin.css / main.css in sync with theme sources when requested.
if (($argv[2] ?? '') === '--theme-claro' || ($argv[2] ?? '') === '--theme-oscuro') {
    $mode = ($argv[2] === '--theme-claro') ? 'claro' : 'oscuro';
    $privSrc = $projectRoot . '/Private/tools/css/private/css_' . $mode . '.css';
    $pubSrc = $projectRoot . '/Private/tools/css/public/css_' . $mode . '.css';
    $adminCss = $projectRoot . '/' . $tenant . '/private/dashboard/src/admin.css';
    $mainCss = $projectRoot . '/' . $tenant . '/src/css/main.css';
    $templateAdmin = $projectRoot . '/template/private/dashboard/src/admin.css';
    $templateMain = $projectRoot . '/template/src/css/main.css';
    if (is_file($privSrc)) {
        copy($privSrc, $adminCss);
        copy($privSrc, $templateAdmin);
        echo "tema {$mode} aplicado a admin.css\n";
        $changed++;
    }
    if (is_file($pubSrc)) {
        copy($pubSrc, $mainCss);
        copy($pubSrc, $templateMain);
        echo "tema {$mode} aplicado a main.css\n";
        $changed++;
    }
}

echo "Sincronización completa ({$changed} archivo(s) actualizado(s)).\n";
