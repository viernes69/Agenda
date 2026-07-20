<?php
declare(strict_types=1);

date_default_timezone_set('America/Montevideo');

function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }

$message = '';
$error = '';
$slugOut = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $slug = strtolower(trim((string)($_POST['slug'] ?? '')));
    $name = trim((string)($_POST['name'] ?? $slug));
    $slugOut = $slug;

    if ($slug === '' || !preg_match('/^[a-z0-9-]{3,30}$/', $slug)) {
        $error = 'Slug invalido. Use 3-30 caracteres: a-z, 0-9 y guiones.';
    } elseif (in_array($slug, ['api','admin','register','private','src','template'], true)) {
        $error = 'Slug reservado. Elija otro nombre.';
    } else {
        $root = __DIR__;
        $template = $root . DIRECTORY_SEPARATOR . 'template';
        $target = $root . DIRECTORY_SEPARATOR . $slug;

        if (!is_dir($template)) {
            $error = 'No se encontro la carpeta "template".';
        } elseif (file_exists($target)) {
            $error = 'La carpeta del negocio ya existe: ' . h($slug);
        } else {
            $ok = true;
            $rii = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($template, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($rii as $srcPath => $info) {
                $rel = substr($srcPath, strlen($template));
                $dstPath = $target . $rel;
                if ($info->isDir()) {
                    if (!is_dir($dstPath) && !mkdir($dstPath, 0777, true)) { $ok = false; $error = 'No se pudo crear directorio: ' . $dstPath; break; }
                } else {
                    $dstDir = dirname($dstPath);
                    if (!is_dir($dstDir) && !mkdir($dstDir, 0777, true)) { $ok = false; $error = 'No se pudo crear directorio: ' . $dstDir; break; }
                    if (!copy($srcPath, $dstPath)) { $ok = false; $error = 'Error copiando archivo: ' . $srcPath; break; }
                }
            }

            if ($ok) {
                $tenantDbPath = $target . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'db' . DIRECTORY_SEPARATOR . 'database.php';
                $db = @include $tenantDbPath;
                if (!is_array($db)) { $db = []; }

                $v = fn($k,$d='') => (string)($_POST[$k] ?? $d);
                $b = fn($k) => isset($_POST[$k]) && (string)$_POST[$k] !== '' && $_POST[$k] !== '0';
                $i = fn($k,$d=0) => (int)($_POST[$k] ?? $d);
                $baseUrl = 'https://agendas.appsuy.net/' . $slug;

                $days = ['lunes','martes','miercoles','jueves','viernes','sabado','domingo'];
                $openAll = $b('open_all');
                $start = $v('open_start','09:00');
                $end = $v('open_end','20:00');
                $sunOpen = $b('sun_open');
                $horarios = [ 'timezone' => $v('timezone','America/Montevideo') ];
                foreach ($days as $dname) {
                    $isSun = ($dname === 'domingo');
                    $abierto = $openAll ? true : (!$isSun);
                    if ($isSun) { $abierto = $sunOpen; }
                    $horarios[$dname] = [ 'abierto' => $abierto, 'inicio' => $abierto ? $start : '', 'fin' => $abierto ? $end : '' ];
                }
                $horarios['feriados'] = [];

                $db['info_barberia'] = [
                    'nombre' => ($name !== '' ? $name : ucfirst($slug)),
                    'slug' => $slug,
                    'slogan' => $v('slogan','Tu corte, tu estilo'),
                    'descripcion' => $v('descripcion',''),
                    'rubro' => 'barberia',
                    'razon_social' => $v('razon_social',''),
                    'rut_ruc' => $v('rut_ruc',''),
                    'contacto' => [
                        'telefono' => $v('telefono',''),
                        'whatsapp' => $v('whatsapp',''),
                        'email' => $v('email',''),
                        'website' => $v('website', $baseUrl),
                    ],
                    'direccion' => [
                        'pais' => $v('pais','UY'),
                        'region' => $v('region',''),
                        'ciudad' => $v('ciudad',''),
                        'calle' => $v('calle',''),
                        'numero' => $v('numero',''),
                        'referencia' => $v('referencia',''),
                        'codigo_postal' => $v('codigo_postal',''),
                        'map_url' => $v('map_url',''),
                        'google_place_id' => $v('google_place_id',''),
                    ],
                    'horarios' => $horarios,
                    'reservas' => [
                        'slot_minutos' => $i('slot_minutos',30),
                        'anticipacion_minutos' => $i('anticipacion_minutos',60),
                        'max_dias_adelante' => $i('max_dias_adelante',30),
                        'politica_cancelacion_horas' => $i('politica_cancelacion_horas',12),
                        'requiere_login' => $b('requiere_login'),
                        'max_reservas_por_dia_por_cliente' => $i('max_reservas_por_dia_por_cliente',2),
                        'permitir_doble_turno' => $b('permitir_doble_turno'),
                    ],
                    'moneda' => [ 'codigo' => $v('moneda_codigo','UYU'), 'simbolo' => $v('moneda_simbolo','$'), 'separador_decimal' => ',', 'separador_miles' => '.' ],
                    'locale' => 'es_UY',
                    'formatos' => [ 'fecha' => 'Y-m-d', 'hora' => 'H:i' ],
                    'fiscal' => [ 'iva_porcentaje' => 22.0, 'comprobante' => 'ticket' ],
                    'mercado_pago' => [
                        'enabled' => $b('mp_enabled'),
                        'modo' => $v('mp_modo','test'),
                        'public_key' => $v('mp_public_key',''),
                        'access_token' => $v('mp_access_token',''),
                        'integrator_id' => $v('mp_integrator_id',''),
                        'statement_descriptor' => $v('mp_statement_descriptor', strtoupper($slug)),
                        'country' => $v('mp_country','UY'),
                        'currency' => $v('mp_currency','UYU'),
                        'auto_capture' => true,
                        'success_url' => $baseUrl . '/pago/success',
                        'failure_url' => $baseUrl . '/pago/failure',
                        'pending_url' => $baseUrl . '/pago/pending',
                        'notification_url' => 'https://agendas.appsuy.net/api/webhooks/mercadopago',
                        'allowed_payment_methods' => [],
                    ],
                    'branding' => [ 'tema' => 'default', 'color_primario' => '#0ea5e9', 'color_secundario' => '#f59e0b', 'logo' => 'src/img/logo.png', 'logo_dark' => 'src/img/logo-dark.png', 'favicon' => 'src/img/favicon.png', 'hero_img' => 'src/img/hero.jpg' ],
                    'redes' => [ 'instagram' => '', 'facebook' => '', 'tiktok' => '', 'twitter' => '', 'youtube' => '', 'google_maps' => '' ],
                    'seo' => [ 'title' => ($name ?: $slug) . ' | Agenda online', 'description' => 'Reservar turnos y servicios', 'keywords' => ['barberia','agenda'], 'og_image' => 'src/img/og-image.jpg', 'robots' => 'index,follow' ],
                    'analytics' => [ 'google_analytics_id' => '', 'facebook_pixel_id' => '' ],
                    'notificaciones' => [ 'email_from' => 'no-reply@' . $slug . '.com', 'email_from_name' => ($name ?: ucfirst($slug)), 'smtp' => [ 'enabled' => false, 'host' => '', 'port' => 587, 'user' => '', 'pass' => '', 'encryption' => 'tls' ], 'whatsapp' => [ 'enabled' => false, 'number' => '', 'provider' => 'meta' ] ],
                    'legales' => [ 'terminos_url' => '/terminos', 'privacidad_url' => '/privacidad', 'reembolsos_url' => '/reembolsos' ],
                    'features' => [ 'productos' => true, 'puntos' => true, 'pagos_online' => $b('mp_enabled'), 'carrito' => true, 'qrs' => true ],
                ];

                $code = '<?php return ' . var_export($db, true) . ";\n";
                if (false === file_put_contents($tenantDbPath, $code, LOCK_EX)) {
                    $error = 'No se pudo escribir database.php del negocio.';
                } else {
                    $message = 'Negocio creado: ' . h($name) . ' (' . h($slug) . ')';
                }
            }
        }
    }
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Agendas - Registrar barberia</title>
  <link rel="icon" type="image/png" sizes="32x32" href="/agenda/src/img/favicon/favicon.png">
  <link rel="apple-touch-icon" href="/agenda/src/img/favicon/favicon.png">
  <style>
    body{font-family:system-ui,Segoe UI,Roboto,Arial,sans-serif;margin:0;padding:24px;background:#0b0f14;color:#e8edf3}
    .card{max-width:920px;margin:0 auto;background:#121824;border:1px solid #223047;border-radius:12px;padding:24px}
    label{display:block;margin:12px 0 6px;color:#a9bfd1}
    input[type=text]{width:100%;padding:10px 12px;border-radius:8px;border:1px solid #2a3a4f;background:#0f1420;color:#e8edf3}
    .row{display:flex;gap:12px;flex-wrap:wrap}
    .row>div{flex:1 1 240px}
    .actions{margin-top:16px}
    button{background:#3b82f6;border:0;color:#fff;padding:10px 16px;border-radius:8px;cursor:pointer}
    .msg{margin-top:16px;padding:12px;border-radius:8px}
    .ok{background:#0e3b22;border:1px solid #1a6e3d}
    .err{background:#3b0e0e;border:1px solid #6e1a1a}
    a{color:#8ab4ff}
    h2{margin-top:24px}
    small{color:#8aa5ba}
  </style>
  <script>
    function suggestSlug(){
      const name = document.getElementById('name').value.trim().toLowerCase();
      if(!name) return;
      let slug = name.normalize('NFD').replace(/[\u0300-\u036f]/g,'');
      slug = slug.replace(/[^a-z0-9-]+/g,'-').replace(/^-+|-+$/g,'').replace(/--+/g,'-');
      if(slug.length>=3) document.getElementById('slug').value = slug.substring(0,30);
    }
  </script>
  </head>
<body>
  <div class="card">
    <h1>Registrar nueva barberia</h1>
    <p>Se copiara el template y se personalizara <code>src/db/database.php</code> con informacion basica del negocio y Mercado Pago.</p>
    <?php if ($message): ?>
      <div class="msg ok"><?php echo $message; ?> - Ir a <a href="/<?php echo h($slugOut); ?>/" target="_blank">/<?php echo h($slugOut); ?>/</a></div>
    <?php endif; ?>
    <?php if ($error): ?>
      <div class="msg err"><?php echo $error; ?></div>
    <?php endif; ?>
    <form method="post">
      <h2>Identidad</h2>
      <div class="row">
        <div>
          <label for="name">Nombre del negocio</label>
          <input type="text" id="name" name="name" placeholder="Ej: La Barberia UY" onblur="suggestSlug()" required>
        </div>
        <div>
          <label for="slug">Slug/carpeta (a-z, 0-9, -)</label>
          <input type="text" id="slug" name="slug" placeholder="Ej: labarberiauy" pattern="[a-z0-9-]{3,30}" required>
        </div>
      </div>
      <div class="row">
        <div>
          <label for="slogan">Slogan</label>
          <input type="text" id="slogan" name="slogan" placeholder="Tu corte, tu estilo">
        </div>
        <div>
          <label for="descripcion">Descripcion corta</label>
          <input type="text" id="descripcion" name="descripcion" placeholder="Servicios de corte y barba">
        </div>
      </div>

      <h2>Contacto</h2>
      <div class="row">
        <div><label for="telefono">Telefono</label><input type="text" id="telefono" name="telefono"></div>
        <div><label for="whatsapp">WhatsApp</label><input type="text" id="whatsapp" name="whatsapp"></div>
        <div><label for="email">Email</label><input type="text" id="email" name="email"></div>
        <div><label for="website">Website</label><input type="text" id="website" name="website" placeholder="https://agendas.appsuy.net/{slug}"></div>
      </div>

      <h2>Direccion</h2>
      <div class="row">
        <div><label for="pais">Pais</label><input type="text" id="pais" name="pais" value="UY"></div>
        <div><label for="region">Region</label><input type="text" id="region" name="region" placeholder="Departamento/Estado"></div>
        <div><label for="ciudad">Ciudad</label><input type="text" id="ciudad" name="ciudad"></div>
      </div>
      <div class="row">
        <div><label for="calle">Calle</label><input type="text" id="calle" name="calle"></div>
        <div><label for="numero">Numero</label><input type="text" id="numero" name="numero"></div>
        <div><label for="codigo_postal">Codigo postal</label><input type="text" id="codigo_postal" name="codigo_postal"></div>
      </div>

      <h2>Horarios</h2>
      <div class="row">
        <div><label for="timezone">Zona horaria</label><input type="text" id="timezone" name="timezone" value="America/Montevideo"></div>
        <div><label for="open_start">Inicio base</label><input type="text" id="open_start" name="open_start" value="09:00"></div>
        <div><label for="open_end">Fin base</label><input type="text" id="open_end" name="open_end" value="20:00"></div>
      </div>
      <div class="row">
        <div><label><input type="checkbox" name="open_all" checked> Abrir de Lunes a Sabado</label></div>
        <div><label><input type="checkbox" name="sun_open"> Domingo abierto</label></div>
      </div>

      <h2>Reservas</h2>
      <div class="row">
        <div><label for="slot_minutos">Duracion turno (min)</label><input type="text" id="slot_minutos" name="slot_minutos" value="30"></div>
        <div><label for="anticipacion_minutos">Anticipacion minima (min)</label><input type="text" id="anticipacion_minutos" name="anticipacion_minutos" value="60"></div>
        <div><label for="max_dias_adelante">Max dias adelante</label><input type="text" id="max_dias_adelante" name="max_dias_adelante" value="30"></div>
      </div>

      <h2>Mercado Pago</h2>
      <div class="row">
        <div><label><input type="checkbox" name="mp_enabled"> Habilitar pagos</label></div>
        <div><label for="mp_modo">Modo</label><input type="text" id="mp_modo" name="mp_modo" value="test"><small> test o live</small></div>
      </div>
      <div class="row">
        <div><label for="mp_public_key">Public Key</label><input type="text" id="mp_public_key" name="mp_public_key"></div>
        <div><label for="mp_access_token">Access Token</label><input type="text" id="mp_access_token" name="mp_access_token"></div>
        <div><label for="mp_integrator_id">Integrator ID</label><input type="text" id="mp_integrator_id" name="mp_integrator_id"></div>
        <div><label for="mp_statement_descriptor">Descriptor</label><input type="text" id="mp_statement_descriptor" name="mp_statement_descriptor" placeholder="<?php echo isset($_POST['slug'])? strtoupper(h($_POST['slug'])):''; ?>"></div>
      </div>

      <div class="actions">
        <button type="submit">Crear barberia</button>
      </div>
    </form>
  </div>
</body>
</html>
