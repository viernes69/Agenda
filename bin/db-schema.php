<?php
$dbPath = __DIR__ . '/../storage/agenduy.db';
if (!file_exists($dbPath)) {
    echo "❌ No encontrada: $dbPath\n";
    exit(1);
}
$db = new PDO('sqlite:' . $dbPath);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
echo "<pre>";
$tables = $db->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
foreach ($tables as $t) {
    echo "\n=== TABLE: $t ===\n";
    $cols = $db->query("PRAGMA table_info($t)")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) {
        echo "  {$c['name']} ({$c['type']})\n";
    }
    $cnt = $db->query("SELECT COUNT(*) FROM $t")->fetchColumn();
    echo "  -- {$cnt} rows --\n";
}
echo "</pre>";
