<?php
header('Content-Type: application/json; charset=utf-8');

// Carpeta actual: src/media
$valid = ['mp4','webm','ogg'];
$files = [];

foreach (scandir(__DIR__) as $f) {
  if ($f[0] === '.') continue;                 // ignora . y ..
  if ($f === 'playlist.php') continue;         // ignora este script
  $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
  if (in_array($ext, $valid)) {
    // Ruta pública desde index.php en la raíz -> "src/media/archivo.ext"
    $files[] = "src/media/$f";
  }
}

natcasesort($files); // orden natural (bg1, bg2, bg10)
echo json_encode(array_values($files), JSON_UNESCAPED_SLASHES);
