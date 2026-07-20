#!/usr/bin/env php
<?php
declare(strict_types=1);

require __DIR__ . '/../src/Core/bootstrap.php';

use Agenduy\Core\NotificationOutbox;

$limit = isset($argv[1]) ? max(1, (int)$argv[1]) : 50;
$stats = NotificationOutbox::processDue($limit);
echo json_encode(['ok' => true, 'stats' => $stats], JSON_UNESCAPED_UNICODE) . PHP_EOL;
