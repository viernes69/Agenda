<?php
declare(strict_types=1);
$p = dirname(__DIR__) . '/storage/agenduy.db';
if (!is_file($p)) {
    echo "DB missing: $p\n";
    exit(1);
}
$pdo = new PDO('sqlite:' . $p);
foreach ($pdo->query('SELECT id_commerce, slug, nombre, status FROM commerces ORDER BY slug') as $row) {
    echo implode(' | ', $row) . PHP_EOL;
}
