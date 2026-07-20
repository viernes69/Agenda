<?php
session_start();
?>

<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Contenido Completo de SESSION</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <style>
    body {
      font-family: monospace;
      background: #f0f0f0;
      padding: 2rem;
    }
    pre {
      background: #fff;
      padding: 1rem;
      border-radius: 10px;
      box-shadow: 0 0 10px rgba(0,0,0,0.1);
      overflow-x: auto;
    }
    h2 {
      text-align: center;
    }
  </style>
</head>
<body>

  <h2>Contenido de $_SESSION</h2>
  <pre><?php print_r($_SESSION); ?></pre>

</body>
</html>
