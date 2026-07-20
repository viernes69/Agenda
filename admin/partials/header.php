<?php
/**
 * Header común para el panel de super admin.
 * Variables esperadas: $pageTitle, $activeSection
 */
use Agenduy\Core\Auth;

if (!defined('ADMIN_PANEL')) {
    define('ADMIN_PANEL', true);
}

if (!Auth::check() || Auth::role() !== 'super_admin') {
    header('Location: login.php');
    exit;
}

$user = Auth::user();
$active = $activeSection ?? '';
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title><?= htmlspecialchars(($pageTitle ?? 'Panel') . ' · Agendarte Admin', ENT_QUOTES, 'UTF-8') ?></title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="referrer" content="same-origin">
<meta http-equiv="X-Content-Type-Options" content="nosniff">
<meta name="csrf-token" content="<?= htmlspecialchars(\Agenduy\Core\CSRF::generate('api', Auth::id()), ENT_QUOTES, 'UTF-8') ?>">
<link rel="stylesheet" href="assets/css/admin.css">
<link rel="stylesheet" href="<?= htmlspecialchars(\Agenduy\Core\AdminBrand::cssUrl(), ENT_QUOTES, 'UTF-8') ?>">
<link rel="icon" type="image/png" href="<?= htmlspecialchars(\Agenduy\Core\AdminBrand::faviconUrl(), ENT_QUOTES, 'UTF-8') ?>">
<meta name="theme-color" content="#7c3aed">
</head>
<body>
<header class="topbar">
    <div class="topbar__brand">
        <a href="index.php">
            <img src="<?= htmlspecialchars(\Agenduy\Core\AdminBrand::iconUrl(), ENT_QUOTES, 'UTF-8') ?>" alt="" class="topbar__brand-logo" width="34" height="34">
            <span class="topbar__brand-text"><strong>Agendarte</strong> <span class="brand-uy">UY</span> Admin</span>
        </a>
    </div>
    <nav class="topbar__nav" aria-label="Secciones">
        <a href="index.php"        class="<?= $active === 'overview' ? 'is-active' : '' ?>">Resumen</a>
        <a href="commerces.php"    class="<?= $active === 'commerces' ? 'is-active' : '' ?>">Comercios</a>
        <a href="rubros.php"       class="<?= $active === 'rubros' ? 'is-active' : '' ?>">Rubros</a>
        <a href="memberships.php"  class="<?= $active === 'memberships' ? 'is-active' : '' ?>">Membresías</a>
        <a href="subscriptions.php"class="<?= $active === 'subscriptions' ? 'is-active' : '' ?>">Suscripciones</a>
        <a href="keys.php"         class="<?= $active === 'keys' ? 'is-active' : '' ?>">API Keys</a>
        <a href="payments.php"     class="<?= $active === 'payments' ? 'is-active' : '' ?>">Pagos</a>
        <a href="plantillas.php"   class="<?= $active === 'plantillas' ? 'is-active' : '' ?>">Plantillas</a>
        <a href="config.php"       class="<?= $active === 'config' ? 'is-active' : '' ?>">Config</a>
    </nav>
    <div class="topbar__user">
        <span class="topbar__hello">Hola, <?= htmlspecialchars($user['nombre'] ?? 'Admin', ENT_QUOTES, 'UTF-8') ?></span>
        <a href="logout.php" class="btn btn-ghost btn-sm">Salir</a>
    </div>
</header>
<main class="admin-main">
