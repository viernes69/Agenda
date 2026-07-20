<?php
declare(strict_types=1);

require __DIR__ . '/../src/Core/bootstrap.php';

// Compatibilidad con enlaces antiguos: el login vive en el index.
header('Location: ' . url('/'), true, 302);
exit;
