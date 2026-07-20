<?php
/**
 * Agenduy - Wrapper de comercio (compatibilidad)
 * El router genérico está en agenduy.uy/index.php y src/Core/commerce_view.php.
 * Este archivo se mantiene por compatibilidad con URLs viejas que apunten
 * directamente a /{slug}/index.php.
 */
declare(strict_types=1);

$config = require __DIR__ . '/../src/Core/bootstrap.php';
require __DIR__ . '/../src/Core/commerce_view.php';

// El slug se resuelve dinámicamente desde el nombre de la carpeta clonada,
// así el mismo archivo funciona en cualquier instancia sin editarlo.
agenduy_render_commerce(basename(__DIR__));
