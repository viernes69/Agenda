<?php
/**
 * Seed: Tienda de prueba
 *
 * Crea un comercio en modo tienda con productos de ejemplo,
 * para probar el funcionamiento sin registrar con datos reales.
 *
 * USO: php bin/seed-test-store.php
 *
 * Datos creados:
 *   Slug:    mi-tienda
 *   Email:   demo@tienda.uy
 *   Pass:    demo1234
 *   Rubro:   #21 (Tienda)
 *   Plan:    Free (#4)
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/src/Core/bootstrap.php';

use Agenduy\Core\Database;
use Agenduy\Core\CommercePanel;
use Agenduy\Core\CommerceSettings;
use Agenduy\Core\CommerceRegistrar;
use Agenduy\Core\Keys;

$db = Database::getInstance();

// ── 1. Verificar que no exista ──
$existing = $db->fetchOne("SELECT id_commerce FROM commerces WHERE slug = 'mi-tienda'");
if ($existing) {
    echo "⚠  La tienda ya existe (ID #{$existing['id_commerce']}).\n";
    echo "   Email: demo@tienda.uy / Pass: demo1234\n";
    echo "   Slug: mi-tienda\n";
    exit(0);
}

// ── 2. Obtener rubro tienda ──
$rubro = $db->fetchOne("SELECT id_rubro FROM rubros WHERE id_rubro = 21 AND activo = 1");
if (!$rubro) {
    echo "❌  No se encontró el rubro 'Tienda' (#21).\n";
    exit(1);
}

// ── 3. Obtener plan Free ──
$plan = $db->fetchOne("SELECT id_membership FROM memberships WHERE id_membership = 4 AND activo = 1");
if (!$plan) {
    echo "❌  No se encontró el plan Free (#4).\n";
    exit(1);
}

$trialEnd = date('Y-m-d', strtotime('+30 days'));
$pass = 'demo1234';
$hash = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]);

try {
    $db->transaction(function () use ($db, $trialEnd, $hash) {

        // ── 4. Insertar comercio ──
        $idCommerce = (int)$db->insert('commerces', [
            'slug'             => 'mi-tienda',
            'id_rubro'         => 21,
            'id_membership'    => 4,
            'nombre'           => 'Mi Tienda de Prueba',
            'razon_social'     => 'Mi Tienda de Prueba SRL',
            'rut_ruc'          => '123456789012',
            'email'            => 'demo@tienda.uy',
            'telefono'         => '099 111 222',
            'whatsapp'         => '099 111 222',
            'pais'             => 'UY',
            'ciudad'           => 'Montevideo',
            'calle'            => '18 de Julio 1234',
            'slogan'           => 'Todo lo que necesitás, en un solo lugar',
            'descripcion'      => 'Somos una tienda de prueba con productos variados. Hace tu pedido por WhatsApp y coordinamos la entrega.',
            'timezone'         => 'America/Montevideo',
            'status'           => 'active',
            'trial_expires_at' => $trialEnd,
            'serial'           => Keys::serial(),
        ]);
        echo "✓  Comercio creado (ID #{$idCommerce})\n";

        // ── 5. Insertar usuario admin ──
        $idUser = (int)$db->insert('users', [
            'role'          => 'commerce_admin',
            'id_commerce'   => $idCommerce,
            'nombre'        => 'Admin',
            'apellido'      => 'Tienda',
            'cedula'        => '12345678',
            'email'         => 'demo@tienda.uy',
            'telefono'      => '099 111 222',
            'whatsapp'      => '099 111 222',
            'password_hash' => $hash,
            'auth_provider' => 'password',
            'activo'        => 1,
        ]);
        echo "✓  Usuario creado (ID #{$idUser}): demo@tienda.uy\n";

        // ── 6. Insertar suscripción ──
        $db->insert('subscriptions', [
            'id_commerce'          => $idCommerce,
            'id_membership'        => 4,
            'status'               => 'active',
            'gateway'              => 'manual',
            'started_at'           => date('Y-m-d'),
            'trial_expires_at'     => $trialEnd,
            'current_period_start' => date('Y-m-d'),
            'current_period_end'   => $trialEnd,
            'billing_period'       => 'monthly',
            'notes'                => 'Tienda de prueba (seed)',
        ]);
        echo "✓  Suscripción creada\n";

        // ── 7. Configurar settings ──
        CommerceSettings::set($idCommerce, 'horarios', [
            'timezone' => 'America/Montevideo',
            'lunes'    => ['abierto' => true, 'inicio' => '09:00', 'fin' => '19:00', 'descanso_inicio' => '', 'descanso_fin' => ''],
            'martes'   => ['abierto' => true, 'inicio' => '09:00', 'fin' => '19:00', 'descanso_inicio' => '', 'descanso_fin' => ''],
            'miercoles'=> ['abierto' => true, 'inicio' => '09:00', 'fin' => '19:00', 'descanso_inicio' => '', 'descanso_fin' => ''],
            'jueves'   => ['abierto' => true, 'inicio' => '09:00', 'fin' => '19:00', 'descanso_inicio' => '', 'descanso_fin' => ''],
            'viernes'  => ['abierto' => true, 'inicio' => '09:00', 'fin' => '19:00', 'descanso_inicio' => '', 'descanso_fin' => ''],
            'sabado'   => ['abierto' => true, 'inicio' => '10:00', 'fin' => '14:00', 'descanso_inicio' => '', 'descanso_fin' => ''],
            'domingo'  => ['abierto' => false, 'inicio' => '', 'fin' => '', 'descanso_inicio' => '', 'descanso_fin' => ''],
        ]);
        CommerceSettings::set($idCommerce, 'moneda', ['simbolo' => '$', 'codigo' => 'UYU', 'decimales' => 0]);
        CommerceSettings::set($idCommerce, 'tema', ['publico' => 'claro', 'privado' => 'claro']);
        CommerceSettings::set($idCommerce, 'funciones', [
            'tipo_comercio' => 'tienda',
            'productos'     => true,
            'servicios'     => false,
            'barberos'      => false,
            'reservas'      => false,
            'carrito'       => true,
        ]);
        CommerceSettings::set($idCommerce, 'seo', [
            'title'       => 'Mi Tienda de Prueba · Catálogo online',
            'description' => 'Explorá nuestro catálogo y pedí por WhatsApp.',
            'keywords'    => ['tienda', 'catalogo', 'productos'],
            'robots'      => 'index,follow',
        ]);
        CommerceSettings::set($idCommerce, 'notificaciones', [
            'email_enabled'  => false,
            'owner_email'    => 'demo@tienda.uy',
            'whatsapp_enabled' => false,
        ]);
        CommerceSettings::set($idCommerce, 'legal', [
            'terminos' => 'Productos sujetos a disponibilidad. Los precios pueden variar sin previo aviso.',
            'privacidad' => 'Sus datos se usan solo para procesar su pedido.',
        ]);

        // ── 8. Crear database.php local (catálogo) ──
        $localDbPath = CommercePanel::localDatabasePath($idCommerce);
        $localDir = dirname($localDbPath);
        if (!is_dir($localDir)) {
            mkdir($localDir, 0775, true);
        }

        $productos = [
            [
                'ID_Product' => 1,
                'Nombre'     => 'Auriculares Bluetooth',
                'Descripcion'=> 'Auriculares inalámbricos con cancelación de ruido. Batería de 20 horas.',
                'Precio'     => 1590,
                'Tipo'       => 'Electrónica',
                'Img_src'    => '',
                'Puntos'     => 50,
                'Status'     => 'Activo',
            ],
            [
                'ID_Product' => 2,
                'Nombre'     => 'Campera Impermeable',
                'Descripcion'=> 'Campera ligera con capucha, resistente al agua. Ideal para el invierno.',
                'Precio'     => 2490,
                'Tipo'       => 'Ropa',
                'Img_src'    => '',
                'Puntos'     => 80,
                'Status'     => 'Activo',
            ],
            [
                'ID_Product' => 3,
                'Nombre'     => 'Set de Tazas de Cerámica x6',
                'Descripcion'=> 'Juego de 6 tazas de cerámica esmaltada. Capacidad 250ml c/u.',
                'Precio'     => 890,
                'Tipo'       => 'Hogar',
                'Img_src'    => '',
                'Puntos'     => 25,
                'Status'     => 'Activo',
            ],
            [
                'ID_Product' => 4,
                'Nombre'     => 'Mochila Antirrobo',
                'Descripcion'=> 'Mochila urbana con cierre oculto y puerto USB externo. 25 litros.',
                'Precio'     => 1990,
                'Tipo'       => 'Accesorios',
                'Img_src'    => '',
                'Puntos'     => 60,
                'Status'     => 'Activo',
            ],
            [
                'ID_Product' => 5,
                'Nombre'     => 'Velador LED Táctil',
                'Descripcion'=> 'Lámpara de mesa con toque táctil, 3 intensidades y base de carga inalámbrica.',
                'Precio'     => 1290,
                'Tipo'       => 'Hogar',
                'Img_src'    => '',
                'Puntos'     => 40,
                'Status'     => 'Activo',
            ],
            [
                'ID_Product' => 6,
                'Nombre'     => 'Kit de Maquillaje 12 piezas',
                'Descripcion'=> 'Set completo con sombras, rubor, labiales y brochas.',
                'Precio'     => 750,
                'Tipo'       => 'Belleza',
                'Img_src'    => '',
                'Puntos'     => 20,
                'Status'     => 'Activo',
            ],
        ];

        $plantilla = [
            'ID_Product' => null, 'Nombre' => null, 'Descripcion' => null,
            'Precio' => null, 'Tipo' => null, 'Img_src' => null,
            'Puntos' => null, 'Status' => null,
        ];

        $database = [
            'info_barberia' => [
                'ID_Negocio' => $idCommerce,
                'ID_Rubro'   => 21,
                'rubro'      => 'tienda',
                'rubro_nombre' => 'Tienda',
                'nombre'     => 'Mi Tienda de Prueba',
                'slogan'     => 'Todo lo que necesitás, en un solo lugar',
                'descripcion'=> 'Somos una tienda de prueba con productos variados.',
                'email'      => 'demo@tienda.uy',
                'ID_Admin'   => $idUser,
                'contacto'   => [
                    'telefono' => '099 111 222',
                    'whatsapp' => '099 111 222',
                    'email'    => 'demo@tienda.uy',
                ],
                'direccion'  => [
                    'pais'   => 'UY',
                    'ciudad' => 'Montevideo',
                    'calle'  => '18 de Julio 1234',
                ],
                'horarios' => [
                    'lunes'    => ['abierto' => true, 'inicio' => '09:00', 'fin' => '19:00'],
                    'martes'   => ['abierto' => true, 'inicio' => '09:00', 'fin' => '19:00'],
                    'miercoles'=> ['abierto' => true, 'inicio' => '09:00', 'fin' => '19:00'],
                    'jueves'   => ['abierto' => true, 'inicio' => '09:00', 'fin' => '19:00'],
                    'viernes'  => ['abierto' => true, 'inicio' => '09:00', 'fin' => '19:00'],
                    'sabado'   => ['abierto' => true, 'inicio' => '10:00', 'fin' => '14:00'],
                    'domingo'  => ['abierto' => false],
                ],
                'temas' => ['publico' => 'claro', 'privado' => 'claro'],
                'features' => [
                    'tipo_comercio' => 'tienda',
                    'productos' => true,
                    'servicios' => false,
                    'barberos'  => false,
                    'reservas'  => false,
                    'carrito'   => true,
                ],
            ],
            'servicios' => [[
                'ID_Servicio' => null, 'Nombre' => null, 'Duracion' => null,
                'Estado' => null, 'Precio' => null, 'Puntos' => null, 'Img_Link' => null,
            ]],
            'barberos' => [[
                'ID_Barber' => null, 'Nombre' => null, 'Apellido' => null, 'Cedula' => null,
                'Psw' => null, 'Disponibilidad' => null, 'Habilidades' => null,
                'Rol' => null, 'Perfil' => null, 'Comision' => null, 'Status' => null, 'DiasTrabajo' => null,
            ]],
            'clientes' => [[
                'ID_Cliente' => null, 'Nombre' => null, 'Email' => null, 'Telefono' => null,
                'Whatsapp' => null, 'Direccion' => null, 'Fecha_Nacimiento' => null,
                'Fecha_Creacion' => null, 'Ultima_Modificacion' => null,
            ]],
            'productos' => array_merge([$plantilla], $productos),
            'reservas' => [[
                'ID_Reserva' => null, 'ID_Cliente' => null, 'ID_Barber' => null,
                'ID_Servicio' => null, 'Fecha_Reserva' => null, 'Hora_Reserva' => null,
                'Status' => null, 'Observaciones' => null,
            ]],
            'carrito' => [[
                'ID_Carrito' => null, 'ID_Cliente' => null, 'Fecha' => null, 'Hora' => null,
                'ID_Producto + Cantidad' => null, 'Status' => null, 'Dirección' => null,
            ]],
        ];

        $export = "<?php return " . var_export($database, true) . ";\n";
        if (file_put_contents($localDbPath, $export, LOCK_EX) === false) {
            throw new \RuntimeException('No se pudo escribir database.php');
        }
        echo "✓  Catálogo local creado ({$localDbPath})\n";
        echo "   Productos: " . count($productos) . "\n";
    });

    echo "\n✅  TIENDA DE PRUEBA CREADA CON ÉXITO\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "  📍 URL pública:  " . CommercePanel::publicUrlForSlug('mi-tienda') . "\n";
    echo "  🔗 Panel admin:  " . CommercePanel::dashboardUrlForSlug('mi-tienda', 'resumen') . "\n";
    echo "  👤 Email:        demo@tienda.uy\n";
    echo "  🔑 Contraseña:   demo1234\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

} catch (\Throwable $e) {
    echo "❌  Error: " . $e->getMessage() . "\n";
    echo "    En: " . $e->getFile() . ":" . $e->getLine() . "\n";
    exit(1);
}
