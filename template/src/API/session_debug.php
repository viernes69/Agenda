<?php
// Iniciar la sesión
session_start();

// Configurar la salida como HTML
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Debug de Sesión</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
            background: #f5f5f5;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
            border-bottom: 2px solid #eee;
            padding-bottom: 10px;
        }
        .info-block {
            margin: 20px 0;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 4px;
        }
        .session-data {
            white-space: pre-wrap;
            font-family: monospace;
            background: #272822;
            color: #f8f8f2;
            padding: 15px;
            border-radius: 4px;
            overflow-x: auto;
        }
        .meta-info {
            color: #666;
            font-size: 0.9em;
        }
        .empty-notice {
            color: #856404;
            background-color: #fff3cd;
            border: 1px solid #ffeeba;
            padding: 15px;
            border-radius: 4px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Debug de Sesión</h1>
        
        <div class="info-block">
            <h2>Información de la Sesión</h2>
            <div class="meta-info">
                <p><strong>Session ID:</strong> <?php echo session_id(); ?></p>
                <p><strong>Session Name:</strong> <?php echo session_name(); ?></p>
                <p><strong>Session Status:</strong> <?php 
                    switch(session_status()) {
                        case PHP_SESSION_DISABLED:
                            echo "Las sesiones están deshabilitadas";
                            break;
                        case PHP_SESSION_NONE:
                            echo "Las sesiones están habilitadas pero ninguna existe";
                            break;
                        case PHP_SESSION_ACTIVE:
                            echo "Las sesiones están habilitadas y existe una";
                            break;
                    }
                ?></p>
            </div>
        </div>

        <div class="info-block">
            <h2>Contenido de $_SESSION</h2>
            <?php if (empty($_SESSION)): ?>
                <div class="empty-notice">
                    La sesión está vacía
                </div>
            <?php else: ?>
                <div class="session-data">
<?php
echo "[\n";
foreach ($_SESSION as $key => $value) {
    echo "    '$key' => ";
    if (is_array($value)) {
        echo "[\n";
        foreach ($value as $k => $v) {
            echo "        '$k' => " . (is_array($v) ? json_encode($v, JSON_PRETTY_PRINT) : "'$v'") . "\n";
        }
        echo "    ]\n";
    } else {
        echo "'$value'\n";
    }
}
echo "]";
?>
                </div>
            <?php endif; ?>
        </div>

        <div class="info-block">
            <h2>Cookies Actuales</h2>
            <div class="session-data">
<?php print_r($_COOKIE); ?>
            </div>
        </div>

        <div class="info-block">
            <h2>Configuración de Sesión</h2>
            <div class="session-data">
<?php 
$sessionSettings = [
    'session.save_handler',
    'session.save_path',
    'session.use_cookies',
    'session.use_only_cookies',
    'session.name',
    'session.auto_start',
    'session.cookie_lifetime',
    'session.cookie_path',
    'session.cookie_domain',
    'session.cookie_httponly',
    'session.cookie_samesite',
];

foreach ($sessionSettings as $setting) {
    echo "$setting: " . ini_get($setting) . "\n";
}
?>
            </div>
        </div>

        <div class="info-block">
            <p><em>Última actualización: <?php echo date('Y-m-d H:i:s'); ?></em></p>
            <form method="post">
                <button type="submit" name="refresh" style="padding: 8px 15px; cursor: pointer;">Actualizar</button>
                <?php if (!empty($_SESSION)): ?>
                    <button type="submit" name="clear_session" style="padding: 8px 15px; cursor: pointer; margin-left: 10px;">Limpiar Sesión</button>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <?php
    // Manejar la limpieza de la sesión
    if (isset($_POST['clear_session'])) {
        session_unset();
        session_destroy();
        echo "<script>window.location.reload();</script>";
    }
    ?>
</body>
</html>