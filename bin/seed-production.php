<?php
/**
 * Seed completo de base de datos para producción.
 *
 * Puebla la BD con:
 *   - Rubros (categorías de negocio)
 *   - Planes/Membresías (Free, Básico, Profesional)
 *   - Comercio de prueba "Terap" (agenda) + usuario admin
 *   - Comercio de prueba "Mi Tienda" (store) + usuario admin
 *   - Settings, servicios, productos, etc.
 *
 * USO:
 *   CLI:    php bin/seed-production.php
 *   Web:    https://agendarte.oficiosya.net/data/bin/seed-production.php?token=...
 *
 * Es IDEMPOTENTE: verifica existencia antes de insertar.
 * CORRE SOLO EN PRODUCCIÓN o donde la BD esté vacía/parcial.
 */

define('SEED_TOKEN', getenv('SEED_TOKEN') ?: 'cambiar-en-produccion');
define('DEFAULT_TOKEN', 'cambiar-en-produccion');

$isCLI = php_sapi_name() === 'cli';
$br = $isCLI ? "\n" : "<br>\n";

if (!$isCLI) {
    $reqToken = $_GET['token'] ?? '';
    if ($reqToken !== SEED_TOKEN) {
        http_response_code(403);
        echo "Token inválido o no proporcionado.$br";
        echo "Configurá SEED_TOKEN como variable de entorno o editá la línea define() en el script.$br";
        exit;
    }
}

// Confirmación adicional por web: requiere ?confirm=yes
if (!$isCLI && ($_GET['confirm'] ?? '') !== 'yes') {
    echo "⚠️  Este script va a POBLAR la base de datos con datos de prueba.$br";
    echo "Agregá &confirm=yes a la URL para ejecutarlo.$br";
    echo "O ejecutá por CLI: php bin/seed-production.php$br";
    exit;
}

$dbPath = __DIR__ . '/../storage/agenduy.db';
$db = new PDO('sqlite:' . $dbPath);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

echo ($isCLI ? '' : '<pre>');
echo "=== Seed producción: " . date('Y-m-d H:i:s') . " ===" . $br . $br;

$counts = ['rubros' => 0, 'memberships' => 0, 'commerces' => 0, 'users' => 0, 'subscriptions' => 0, 'settings' => 0, 'services' => 0];

// ============================================================
// 1. RUBROS (categorías)
// ============================================================
echo "--- RUBROS ---" . $br;

$rubros = [
    [9,  'Belleza y estética',    'belleza',      'Salones y spas',                                'src/media/carousel/clinicas_estetica.jpg', 30],
    [10, 'Barbería',              'barberia',     'Barberías y peluquerías',                        'src/media/carousel/barberias.jpg', 20],
    [11, 'Odontología',           'odontologia',  'Consultorios dentales',                          'src/media/carousel/dentistas.jpg', 110],
    [12, 'Abogacía',              'abogados',     'Servicios legales y asesoramiento',              'src/media/carousel/abogados.jpg', 10],
    [13, 'Clínica de Estética',   'estetica',     'Servicios de belleza y cuidado personal',        'src/media/carousel/clinicas_estetica.jpg', 40],
    [14, 'Consultorios',          'consultorios', 'Servicios médicos y de salud',                   'src/media/carousel/consultorios.jpg', 60],
    [15, 'Dentistas',             'dentistas',    'Servicios odontológicos y cuidado dental',       'src/media/carousel/dentistas.jpg', 70],
    [16, 'Locales de Eventos',    'eventos',      'Espacios para eventos y celebraciones',          'src/media/carousel/fiestas_eventos.jpg', 100],
    [17, 'Lavaderos',             'lavaderos',    'Servicios de lavado y limpieza de vehículos',    'src/media/carousel/lavaderos.jpg', 90],
    [18, 'Profesores Particulares','profesores',  'Clases y tutorías personalizadas',               'src/media/carousel/profesionales.jpg', 120],
    [19, 'Coaching',              'coaches',      'Coaching personal y profesional',                'src/media/carousel/coaches.jpg', 50],
    [20, 'Emprendedores',         'emprendedores','Asesoría para emprendedores',                    'src/media/carousel/emprendedores.jpg', 80],
    [21, 'Tienda',                'tienda',       'Tiendas y retail con agenda de atención',        'src/media/carousel/emprendedores.jpg', 130],
];

$insertRubro = $db->prepare(
    "INSERT OR IGNORE INTO rubros (id_rubro, nombre, tipo, descripcion, imagen, id_plan_def, activo, orden, created_at, updated_at)
     VALUES (?, ?, ?, ?, ?, NULL, 1, ?, datetime('now'), datetime('now'))"
);

foreach ($rubros as $r) {
    $insertRubro->execute([$r[0], $r[1], $r[2], $r[3], $r[4], $r[5]]);
    if ($insertRubro->rowCount() > 0) $counts['rubros']++;
}
echo "Insertados/omitidos: {$counts['rubros']} nuevos (13 total)" . $br . $br;

// ============================================================
// 2. MEMBERSHIPS (planes)
// ============================================================
echo "--- PLANES ---" . $br;

$memberships = [
    [
        'id' => 4, 'nombre' => 'Free', 'precio' => 0, 'trial' => 30,
        'desc' => 'Ideal para empezar: 1 profesional, hasta 4 servicios, 25 clientes y 25 reservas al mes.',
        'features' => '["Hasta 25 reservas al mes","Hasta 25 clientes","1 profesional","Hasta 4 servicios","Sin productos en la tienda","Configuración básica (nombre, logo, redes)","Agenda online básica","Soporte por email"]',
        'limits' => '{"max_products":0,"max_services":4,"max_appointments_month":25,"max_professionals":1,"max_clients":25,"settings_tier":"basic"}',
    ],
    [
        'id' => 5, 'nombre' => 'Básico', 'precio' => 299, 'trial' => 0,
        'desc' => 'Agenda completa, hasta 3 profesionales, 8 servicios, 6 productos, 100 clientes y 100 reservas al mes.',
        'features' => '["Hasta 100 reservas al mes","Hasta 100 clientes","Hasta 3 profesionales","Hasta 8 servicios","Hasta 6 productos en la tienda","Configuración completa","Agenda y recordatorios","Soporte prioritario"]',
        'limits' => '{"max_products":6,"max_services":8,"max_appointments_month":100,"max_professionals":3,"max_clients":100,"settings_tier":"full"}',
    ],
    [
        'id' => 6, 'nombre' => 'Profesional', 'precio' => 599, 'trial' => 0,
        'desc' => 'Ilimitado: profesionales, clientes, reservas, servicios, productos y configuración completa.',
        'features' => '["Profesionales ilimitados","Clientes, reservas, servicios y productos ilimitados","Configuración completa","Todo lo del plan Básico","Reportes avanzados","Soporte prioritario"]',
        'limits' => '{"settings_tier":"full"}',
    ],
];

$insertMembership = $db->prepare(
    "INSERT OR IGNORE INTO memberships (id_membership, nombre, descripcion, precio, moneda, duracion_dias, trial_dias, activo, features, limits, created_at, updated_at)
     VALUES (?, ?, ?, ?, 'UYU', 30, ?, 1, ?, ?, datetime('now'), datetime('now'))"
);

foreach ($memberships as $m) {
    $insertMembership->execute([$m['id'], $m['nombre'], $m['desc'], $m['precio'], $m['trial'], $m['features'], $m['limits']]);
    if ($insertMembership->rowCount() > 0) $counts['memberships']++;
}
echo "Insertados/omitidos: {$counts['memberships']} nuevos" . $br . $br;

// ============================================================
// 3. USERS (admin)
// ============================================================
echo "--- USERS ---" . $br;

// Super admin
$stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
$stmt->execute(['admin@agenduy.uy']);
if ($stmt->fetchColumn() == 0) {
    $db->prepare("INSERT INTO users (id_user, role, id_commerce, nombre, apellido, email, password_hash, activo, created_at, updated_at)
                  VALUES (11, 'super_admin', NULL, 'Lucas', 'Iglesias', 'admin@agenduy.uy', '" . password_hash('admin123', PASSWORD_BCRYPT) . "', 1, datetime('now'), datetime('now'))")->execute();
    echo "Super admin creado (admin@agenduy.uy / admin123)" . $br;
    $counts['users']++;
} else {
    echo "Super admin ya existe" . $br;
}

echo $br;

// ============================================================
// 4. COMMERCES, USERS, SUBSCRIPTIONS, SETTINGS, SERVICES
// ============================================================

function seedCommerce(PDO $db, array $data, array &$counts, string $br): void {
    $slug = $data['slug'];

    // Verificar si ya existe
    $check = $db->prepare("SELECT COUNT(*) FROM commerces WHERE slug = ?");
    $check->execute([$slug]);
    if ($check->fetchColumn() > 0) {
        echo "Comercio '$slug' ya existe -- skip" . $br;
        return;
    }

    $now = date('Y-m-d H:i:s');

    // Insert commerce
    $db->prepare("INSERT INTO commerces (id_commerce, slug, id_rubro, id_membership, nombre, razon_social, rut_ruc, email, telefono, whatsapp, pais, ciudad, calle, website, slogan, descripcion, logo, timezone, status, trial_expires_at, next_billing_at, serial, created_at, updated_at)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'UY', ?, ?, ?, ?, ?, ?, 'America/Montevideo', ?, ?, ?, ?, ?, ?)")
        ->execute([
            $data['id'], $slug, $data['rubro_id'], $data['membership_id'],
            $data['nombre'], $data['razon_social'], $data['rut'],
            $data['email'], $data['telefono'], $data['whatsapp'],
            $data['ciudad'], $data['calle'], $data['website'],
            $data['slogan'], $data['descripcion'], $data['logo'],
            $data['status'], $data['trial_expires'], $data['next_billing'],
            $data['serial'], $now, $now,
        ]);
    $counts['commerces']++;

    // Insert user (commerce admin)
    $db->prepare("INSERT INTO users (role, id_commerce, nombre, apellido, email, password_hash, activo, created_at, updated_at)
                  VALUES ('commerce_admin', ?, ?, ?, ?, ?, 1, ?, ?)")
        ->execute([
            $data['id'], $data['user_nombre'], $data['user_apellido'],
            $data['email'], $data['password_hash'], $now, $now,
        ]);
    $counts['users']++;

    // Insert subscription
    $db->prepare("INSERT INTO subscriptions (id_commerce, id_membership, status, gateway, billing_period, started_at, trial_expires_at, current_period_start, current_period_end, notes, created_at, updated_at)
                  VALUES (?, ?, ?, ?, 'monthly', ?, ?, ?, ?, ?, ?, ?)")
        ->execute([
            $data['id'], $data['membership_id'], $data['sub_status'],
            $data['gateway'], $data['sub_started'], $data['trial_expires'],
            $data['sub_period_start'], $data['sub_period_end'],
            $data['sub_notes'], $now, $now,
        ]);
    $counts['subscriptions']++;

    // Insert commerce_settings
    foreach ($data['settings'] as $section => $json) {
        $db->prepare("INSERT INTO commerce_settings (id_commerce, section, config_json, updated_at) VALUES (?, ?, ?, ?)")
            ->execute([$data['id'], $section, $json, $now]);
        $counts['settings']++;
    }

    // Insert services
    if (!empty($data['services'])) {
        foreach ($data['services'] as $svc) {
            $db->prepare("INSERT INTO services (id_commerce, nombre, descripcion, duracion_min, precio, estado, imagen, created_at, updated_at)
                          VALUES (?, ?, ?, ?, ?, 'Activo', ?, ?, ?)")
                ->execute([
                    $data['id'], $svc['nombre'], $svc['descripcion'],
                    $svc['duracion'], $svc['precio'], $svc['imagen'],
                    $now, $now,
                ]);
            $counts['services']++;
        }
    }

    echo "Comercio '$slug' creado con todos sus datos" . $br;
}

// --- TERAP (agenda) ---
$terapHash = password_hash('terap123', PASSWORD_BCRYPT);
seedCommerce($db, [
    'id' => 16,
    'slug' => 'terap',
    'rubro_id' => 9,
    'membership_id' => 4,
    'nombre' => 'Terap',
    'razon_social' => 'Terap',
    'rut' => '',
    'email' => 'uimpo@gmail.com',
    'telefono' => '+598 92917870',
    'whatsapp' => '+598 92917870',
    'ciudad' => 'Durazno',
    'calle' => 'washington 991 Durazno uruguay',
    'website' => '',
    'slogan' => 'Bienestar y belleza a tu medida.',
    'descripcion' => 'Tratamientos y servicios de belleza.',
    'logo' => 'src/img/logo.jpg',
    'status' => 'trial',
    'trial_expires' => '2026-08-17 03:12:58',
    'next_billing' => '2026-08-17 03:12:58',
    'serial' => '64B5-EB68-9680-4ABB',
    'user_nombre' => 'lucas',
    'user_apellido' => 'Iglesias De abreu',
    'password_hash' => $terapHash,
    'sub_status' => 'trial',
    'gateway' => 'paypal',
    'sub_started' => date('Y-m-d H:i:s'),
    'sub_period_start' => date('Y-m-d'),
    'sub_period_end' => date('Y-m-d', strtotime('+30 days')),
    'sub_notes' => '',
    'settings' => [
        'horarios' => '{"timezone":"America/Montevideo","lunes":{"abierto":true,"inicio":"17:00","fin":"20:00","descanso_inicio":"","descanso_fin":""},"martes":{"abierto":false},"miercoles":{"abierto":false},"jueves":{"abierto":false},"viernes":{"abierto":false},"sabado":{"abierto":false},"domingo":{"abierto":false}}',
        'moneda' => '{"codigo":"UYU","simbolo":"$","decimales":0}',
        'tema' => '{"publico":"claro","privado":"claro"}',
        'reservas' => '{"anticipacion_minutos":60,"max_dias_adelante":30,"politica_cancelacion_horas":1,"requiere_login":false,"max_reservas_por_dia_por_cliente":1}',
        'seo' => '{"title":"Belleza y estetica","description":"Reserva tratamientos online.","keywords":["estetica","belleza"],"canonical":"","robots":"index,follow","og_image":""}',
        'notificaciones' => '{"whatsapp_enabled":true,"email_enabled":true,"owner_email":"uimpo@gmail.com"}',
        'email_plantillas' => '{"appointment_confirmed_client":{"subject":"Reserva confirmada - {negocio}","body":"Hola {cliente}, tu reserva en {negocio} quedo confirmada.\\nServicio: {servicio}\\nFecha: {fecha}\\nHora: {hora}"},"appointment_confirmed_owner":{"subject":"Nueva reserva - {cliente}","body":"Nueva reserva en {negocio}\\nCliente: {cliente}\\nCelular: {telefono}\\nServicio: {servicio}\\nFecha: {fecha}\\nHora: {hora}"}}',
        'funciones' => '{"tipo_comercio":"servicios","productos":true,"servicios":true,"barberos":true,"reservas":true,"carrito":true}',
    ],
    'services' => [
        ['nombre' => 'iiuhh', 'descripcion' => '', 'duracion' => 60, 'precio' => 888, 'imagen' => 'src/img/services/Servicio_20260718_031354_88ff3d51.jpg'],
        ['nombre' => 'hhhh', 'descripcion' => '', 'duracion' => 30, 'precio' => 88, 'imagen' => 'src/img/services/Servicio_20260718_031407_156c9c37.png'],
    ],
], $counts, $br);

// --- MI TIENDA (store) ---
$tiendaHash = password_hash('demo123', PASSWORD_BCRYPT);
seedCommerce($db, [
    'id' => 17,
    'slug' => 'mi-tienda',
    'rubro_id' => 21,
    'membership_id' => 4,
    'nombre' => 'Mi Tienda de Prueba',
    'razon_social' => 'Mi Tienda de Prueba SRL',
    'rut' => '123456789012',
    'email' => 'demo@tienda.uy',
    'telefono' => '099 111 222',
    'whatsapp' => '099 111 222',
    'ciudad' => 'Montevideo',
    'calle' => '18 de Julio 1234',
    'website' => '',
    'slogan' => 'Todo lo que necesitas, en un solo lugar',
    'descripcion' => 'Somos una tienda de prueba con productos variados. Hace tu pedido por WhatsApp y coordinamos la entrega.',
    'logo' => '',
    'status' => 'active',
    'trial_expires' => '2026-08-21',
    'next_billing' => null,
    'serial' => '73A0-2223-7C7B-18FB',
    'user_nombre' => 'Admin',
    'user_apellido' => 'Tienda',
    'password_hash' => $tiendaHash,
    'sub_status' => 'active',
    'gateway' => 'manual',
    'sub_started' => date('Y-m-d'),
    'sub_period_start' => date('Y-m-d'),
    'sub_period_end' => date('Y-m-d', strtotime('+30 days')),
    'sub_notes' => 'Tienda de prueba (seed)',
    'settings' => [
        'horarios' => '{"timezone":"America/Montevideo","lunes":{"abierto":true,"inicio":"09:00","fin":"19:00","descanso_inicio":"","descanso_fin":""},"martes":{"abierto":true,"inicio":"09:00","fin":"19:00","descanso_inicio":"","descanso_fin":""},"miercoles":{"abierto":true,"inicio":"09:00","fin":"19:00","descanso_inicio":"","descanso_fin":""},"jueves":{"abierto":true,"inicio":"09:00","fin":"19:00","descanso_inicio":"","descanso_fin":""},"viernes":{"abierto":true,"inicio":"09:00","fin":"19:00","descanso_inicio":"","descanso_fin":""},"sabado":{"abierto":true,"inicio":"10:00","fin":"14:00","descanso_inicio":"","descanso_fin":""},"domingo":{"abierto":false}}',
        'moneda' => '{"simbolo":"$","codigo":"UYU","decimales":0}',
        'tema' => '{"publico":"claro","privado":"claro"}',
        'seo' => '{"title":"Mi Tienda de Prueba · Catalogo online","description":"Explora nuestro catalogo y pedi por WhatsApp.","keywords":["tienda","catalogo","productos"],"robots":"index,follow"}',
        'notificaciones' => '{"email_enabled":false,"owner_email":"demo@tienda.uy","whatsapp_enabled":false}',
        'funciones' => '{"tipo_comercio":"tienda","productos":true,"servicios":false,"barberos":false,"reservas":false,"carrito":true}',
        'legal' => '{"terminos":"Productos sujetos a disponibilidad. Los precios pueden variar sin previo aviso.","privacidad":"Sus datos se usan solo para procesar su pedido."}',
    ],
    'services' => [],
], $counts, $br);

// ============================================================
// 5. PLATFORM SETTINGS
// ============================================================
echo $br . "--- PLATFORM SETTINGS ---" . $br;
$stmt = $db->prepare("SELECT COUNT(*) FROM platform_settings");
$stmt->execute();
if ($stmt->fetchColumn() == 0) {
    $db->prepare("INSERT INTO platform_settings (section, config_json, updated_at)
                  VALUES ('general', '{\"site_name\":\"Agendarte UY\",\"support_email\":\"soporte@agendarte.uy\",\"support_phone\":\"+598 92 365 135\",\"currency\":\"UYU\",\"timezone\":\"America/Montevideo\"}', datetime('now'))")->execute();
    echo "Platform settings creados" . $br;
} else {
    echo "Platform settings ya existen" . $br;
}

// ============================================================
// 6. PAYMENT PROVIDER CONFIG
// ============================================================
echo $br . "--- PAYMENT PROVIDER CONFIG ---" . $br;
$stmt = $db->prepare("SELECT COUNT(*) FROM payment_provider_config");
$stmt->execute();
if ($stmt->fetchColumn() == 0) {
    $insertPpc = $db->prepare("INSERT INTO payment_provider_config (provider, is_enabled, config_json, notes, updated_at) VALUES (?, 0, ?, ?, datetime('now'))");
    $insertPpc->execute(['mercadopago', '{"public_key":"","access_token":"","notification_url":""}', 'Configurar desde el panel admin']);
    $insertPpc->execute(['paypal', '{"client_id":"","secret":"","webhook_id":""}', 'Configurar desde el panel admin']);
    $insertPpc->execute(['dlocal', '{"x_login":"","x_trans_key":"","x_secret_key":"","notification_url":""}', 'Configurar desde el panel admin']);
    echo "Payment providers creados" . $br;
} else {
    echo "Payment providers ya existen" . $br;
}

echo $br;
echo "=== Seed completado ===" . $br;
echo json_encode($counts) . $br;
echo ($isCLI ? '' : '</pre>');
